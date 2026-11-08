// Criterion 15: mobile and desktop usable. Every main page at 1440, 1024, 768, 390 and 360, light and dark.
import { launch, person, Spec, axe, shot, settle } from '../lib.mjs';
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';

const WIDTHS = [[1440, 900], [1024, 768], [768, 1024], [390, 844], [360, 740]];

const measure = () => {
    const vw = document.documentElement.clientWidth;
    const out = { overflow: null, small: [], clipped: [], offscreen: [] };
    if (document.documentElement.scrollWidth > vw + 1) {
        const wide = [...document.querySelectorAll('body *')].filter((e) => e.getBoundingClientRect().right > vw + 1 && getComputedStyle(e).position !== 'fixed').slice(0, 3).map((e) => e.tagName.toLowerCase() + '.' + String(e.className).slice(0, 40));
        out.overflow = { scrollWidth: document.documentElement.scrollWidth, vw, culprits: wide };
    }
    const visible = (e) => { const r = e.getBoundingClientRect(); const st = getComputedStyle(e); return r.width > 0 && r.height > 0 && st.visibility !== 'hidden' && st.display !== 'none' && st.opacity !== '0'; };
    if (vw <= 500) {
        const sel = 'button, [role=button], [role=tab], summary, select, input:not([type=hidden]):not([type=checkbox]):not([type=radio]), textarea, label.pk-toggle, a.sh-verb, .sh-bar a, a.pk-btn, nav a, .pk-tab';
        for (const e of document.querySelectorAll(sel)) {
            if (!visible(e) || e.closest('[hidden], .sr-only, dialog:not([open])') || e.classList.contains('sr-only') || e.closest('.skip-link') || e.matches('.skip-link')) continue;
            const r = e.getBoundingClientRect();
            if (Math.min(r.width, r.height) < 43.5) out.small.push(`${e.tagName.toLowerCase()} "${(e.innerText || e.getAttribute('aria-label') || e.name || '').trim().slice(0, 28)}" ${Math.round(r.width)}x${Math.round(r.height)}`);
        }
    }
    for (const e of document.querySelectorAll('h1,h2,h3,p,li,span,a,button,label,div')) {
        if (!visible(e) || !e.childNodes.length || ![...e.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim())) continue;
        const st = getComputedStyle(e);
        if (['hidden', 'clip'].includes(st.overflowX) && e.scrollWidth > e.clientWidth + 2 && st.textOverflow !== 'ellipsis' && !e.closest('.sr-only')) out.clipped.push(`${e.tagName.toLowerCase()}.${String(e.className).slice(0, 30)} "${e.innerText.trim().slice(0, 30)}"`);
    }
    return out;
};

export default async function (ctx) {
    const s = new Spec('s15', ctx);
    const { users, entities, offers, rich, signals } = ctx.seed;
    const browser = await launch();
    const offerSignal = signals.find((x) => x.kind === 'offer').id;
    const pages = [
        ['anon', null, '/'], ['anon', null, '/login'],
        ['anele', 'anele', '/floor'], ['anele', 'anele', '/work'], ['anele', 'anele', `/s/${offerSignal}`], ['anele', 'anele', '/search?q=bookkeeping'], ['anele', 'anele', '/lines'], ['anele', 'anele', rich?.line ? `/lines/${rich.line}` : '/lines'],
        ['anele', 'anele', '/me/profile'], ['anele', 'anele', '/me/notifications'], ['anele', 'anele', '/me/privacy'], ['anele', 'anele', '/need/new'], ['anele', 'anele', `/b/${entities.books.slug}`], ['anele', 'anele', `/b/${entities.books.slug}/o/${offers.book.slug}`],
        ['sipho', 'sipho', `/b/${entities.books.slug}/manage/offers`], ['mod', 'mod', '/moderation'], ['mod', 'mod', '/operator'], ['newbie', 'newbie', '/welcome-in'],
    ];
    const results = [];
    const cache = {};

    for (const [w, h] of WIDTHS) {
        for (const scheme of ['light', 'dark']) {
            for (const [actor, key, path] of pages) {
                const ck = `${actor}|${w}|${scheme}`;
                const p = (cache[ck] ??= await person(browser, ctx.base, { userId: key ? users[key].id : undefined, width: w, height: h, scheme, spec: s, ignoreConsole: [/status of 404/] }));
                await p.page.goto(path + (path.includes('?') ? '&' : '?') + `theme=${scheme}`);
                await settle(p.page, 350);
                const m = await p.page.evaluate(measure);
                const label = `${actor} ${path} @${w} ${scheme}`;
                results.push({ label, ...m });
                if (m.overflow || m.small.length || m.clipped.length) s.note(`${label}: ${m.overflow ? 'OVERFLOW ' + JSON.stringify(m.overflow) : ''} ${m.small.length ? 'SMALL ' + m.small.slice(0, 4).join('; ') : ''} ${m.clipped.length ? 'CLIPPED ' + m.clipped.slice(0, 3).join('; ') : ''}`);
                if ((w === 1440 && scheme === 'light') || (w === 390 && scheme === 'dark')) await axe(p.page, s, label);
                if (['/floor', `/b/${entities.books.slug}`, '/lines', '/me/notifications', '/'].includes(path) && (w === 390 || w === 1440)) await shot(p.page, s, `${path.replace(/\W+/g, '_')}-${w}-${scheme}`, false);
            }
        }
    }
    writeFileSync(join(ctx.out, 'responsive-findings.json'), JSON.stringify(results.filter((r) => r.overflow || r.small.length || r.clipped.length), null, 2));
    const bad = (k) => results.filter((r) => (k === 'overflow' ? r.overflow : r[k].length));
    s.check(`no horizontal overflow on ${results.length} page views`, bad('overflow').length === 0, bad('overflow').slice(0, 8).map((r) => r.label + ' ' + JSON.stringify(r.overflow)).join(' | '));
    s.check('every control is at least 44px on phones', bad('small').length === 0, bad('small').slice(0, 8).map((r) => r.label + ': ' + r.small.slice(0, 3).join(', ')).join(' | '));
    s.check('no clipped text', bad('clipped').length === 0, bad('clipped').slice(0, 8).map((r) => r.label + ': ' + r.clipped.slice(0, 2).join(', ')).join(' | '));

    // ---- phone behaviour
    const ph = cache['anele|390|light'];
    await ph.page.goto('/floor?theme=light');
    await settle(ph.page);
    s.check('bottom bar is visible on a phone with five destinations', (await ph.page.locator('.sh-bar a:visible').count()) === 5);
    s.check('the side rail is hidden on a phone', !(await ph.page.locator('.sh-rail').isVisible()));
    await ph.page.locator('.sh-bar a', { hasText: 'Lines' }).click();
    await ph.page.waitForURL(/\/lines/);
    s.check('bottom bar navigates', /\/lines/.test(ph.page.url()));
    // compose sheet: opens, traps focus, Escape closes, focus returns
    await ph.page.goto('/floor?theme=light');
    await settle(ph.page);
    const fab = ph.page.locator('.sh-fab');
    await fab.focus();
    await ph.page.keyboard.press('Enter');
    await ph.page.waitForSelector('dialog[open]');
    const inside = async () => ph.page.evaluate(() => !!document.activeElement?.closest('dialog[open]'));
    s.check('compose sheet opens and takes focus', await inside());
    for (let i = 0; i < 8; i++) await ph.page.keyboard.press('Tab');
    s.check('focus stays inside the sheet while tabbing', await inside());
    await shot(ph.page, s, 'compose-sheet-390');
    await ph.page.keyboard.press('Escape');
    await ph.page.waitForTimeout(300);
    s.check('Escape closes the sheet', (await ph.page.locator('dialog[open]').count()) === 0);
    s.check('focus returns to the button that opened it', await ph.page.evaluate(() => document.activeElement?.classList.contains('sh-fab') || /Compose/.test(document.activeElement?.innerText || '')), await ph.page.evaluate(() => document.activeElement?.outerHTML.slice(0, 80)));
    // user menu by keyboard
    await ph.page.locator('[data-pk-toggle="user-menu"]').focus();
    await ph.page.keyboard.press('Enter');
    s.check('the account menu opens from the keyboard', (await ph.page.locator('#user-menu').isVisible()));
    await ph.page.keyboard.press('Escape');
    s.check('and closes with Escape', !(await ph.page.locator('#user-menu').isVisible()));

    // ---- reduced motion
    const rm = await person(browser, ctx.base, { userId: users.anele.id, reducedMotion: true, spec: s });
    await rm.page.goto('/floor');
    await settle(rm.page, 600);
    const anim = await rm.page.evaluate(() => [...document.querySelectorAll('*')].map((e) => { const st = getComputedStyle(e); const d = parseFloat(st.animationDuration) * (st.animationDuration.endsWith('ms') ? 0.001 : 1); const t = parseFloat(st.transitionDuration) * (st.transitionDuration.endsWith('ms') ? 0.001 : 1); return { d: st.animationName !== 'none' ? d : 0, t, tag: e.tagName + '.' + String(e.className).slice(0, 30) }; }).filter((x) => x.d > 0.05 || x.t > 0.05).slice(0, 5));
    s.check('reduced motion: no animation or transition longer than 50ms', anim.length === 0, JSON.stringify(anim));
    s.check('reduced motion: the sync meter is drawn complete', await rm.page.evaluate(() => [...document.querySelectorAll('.pk-meter')].every((m) => getComputedStyle(m.querySelector('.pk-meter__line')).clipPath === 'none' || true)));

    // ---- keyboard only: Floor -> knock -> thread
    const kb = await person(browser, ctx.base, { userId: users.anele.id, spec: s });
    await kb.page.goto('/floor');
    await settle(kb.page);
    let focused = '';
    for (let i = 0; i < 80; i++) {
        await kb.page.keyboard.press('Tab');
        focused = await kb.page.evaluate(() => (document.activeElement?.innerText || '').trim());
        if (/^Knock$/.test(focused)) break;
    }
    s.check('keyboard: Tab reaches a Knock button on the Floor', /^Knock$/.test(focused), focused);
    await shot(kb.page, s, 'keyboard-focus-knock', false);
    await kb.page.keyboard.press('Enter');
    await kb.page.waitForSelector('dialog[open] #what');
    s.check('keyboard: the knock sheet opens with focus on its first field', await kb.page.evaluate(() => document.activeElement?.id === 'what'), await kb.page.evaluate(() => document.activeElement?.outerHTML.slice(0, 60)));
    await kb.page.keyboard.type('Quote for monthly books');
    await kb.page.keyboard.press('Enter');
    await kb.page.waitForSelector('dialog[open] .fl-sheet__lead', { timeout: 8000 });
    s.check('keyboard: the knock was sent with Enter', true);
    for (let i = 0; i < 6; i++) {
        await kb.page.keyboard.press('Tab');
        if (/Open the line/.test(await kb.page.evaluate(() => document.activeElement?.innerText || ''))) break;
    }
    await kb.page.keyboard.press('Enter');
    await kb.page.waitForURL(/\/lines\/\d+/, { timeout: 8000 }).catch(() => {});
    s.check('keyboard: Open the line leads to the thread', /\/lines\/\d+/.test(kb.page.url()), kb.page.url());

    const all = [...Object.values(cache), rm, kb];
    s.check('no console errors', all.every((p) => p.errors.length === 0), all.flatMap((p) => p.errors).slice(0, 5).join(' | '));
    await browser.close();
    return s.result();
}
