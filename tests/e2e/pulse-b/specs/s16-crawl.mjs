// Criterion 16: no fake activity, no fake metrics, no non-functional controls. Crawls every page as the people who can reach it,
// finds every visible link, button, toggle, tab and menu item, activates each one once in a representative state and asserts
// that it does something real (navigates, changes state, opens a dialog, sends a request) or is disabled with a stated reason.
import { launch, person, Spec, axe, shot, settle, interactives, sql } from '../lib.mjs';
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';

const SKIP_CLICK = /log ?out|sign out|erase|delete|unblock|withdraw|take (this page )?off|close it|archive|decline|not now|ignore|block|report|remove|stop using|not for me|just remove|seen it|wrong place|clear/i;
const FAKE = [
    [/lorem ipsum|dolor sit amet/i, 'lorem ipsum'],
    [/\b\d+\s+(people|users|members|others|professionals|businesses)\s+(are\s+)?(viewing|online|watching|looking at this|active now)/i, 'fake "N people viewing"'],
    [/\b(\d+)\s+(sold|bought)\s+(today|this week|in the last)/i, 'fake sales counter'],
    [/only\s+\d+\s+left|limited (time )?offer|hurry|selling fast/i, 'artificial urgency'],
    [/coming soon|todo|tbd|placeholder|john doe|jane doe|example\.com/i, 'placeholder text'],
    [/\bundefined\b|\bNaN\b|\[object Object\]|\{\{|@php|@if\b/, 'template or JS leakage'],
];

export default async function (ctx) {
    const s = new Spec('s16', ctx);
    const { users, entities, offers, roles, needs, rich, signals } = ctx.seed;
    const browser = await launch();
    const ig = [/status of (404|403|419|429|500)/];
    const P = async (k) => person(browser, ctx.base, { userId: users[k].id, spec: s, ignoreConsole: ig });
    const anon = await person(browser, ctx.base, { spec: s, ignoreConsole: ig });
    const offerSignal = signals.find((x) => x.kind === 'offer').id;
    const talentSignal = signals.find((x) => x.kind === 'talent').id;
    const lineId = rich?.line;

    const plan = [
        ['anon', null, ['/', '/login', '/forgot-password', `/b/${entities.books.slug}`, `/b/${entities.kopano.slug}`]],
        ['anele', 'anele', ['/floor', '/trade', '/work', '/local', '/learn', `/s/${offerSignal}`, '/search?q=bookkeeping', '/saved', '/door', '/lines', '/me/profile', '/me/discoverability', '/me/settings', '/me/notifications', '/me/privacy', '/need/new', `/need/${needs.anele.id}`, `/need/${needs.anele.id}/edit`, '/business/new', '/community', `/c/${rich?.community}`, `/posts/${rich?.post}`, `/u/${users.sipho.id}`, '/user/profile', `/b/${entities.books.slug}`, `/b/${entities.books.slug}/o/${offers.book.slug}`, `/b/${entities.kopano.slug}/r/${roles.dispatch.slug}`]],
        ['sipho', 'sipho', ['/lines', lineId ? `/lines/${lineId}` : '/lines', `/b/${entities.books.slug}/manage`, `/b/${entities.books.slug}/manage/page`, `/b/${entities.books.slug}/manage/offers`, `/b/${entities.books.slug}/manage/roles`, `/b/${entities.books.slug}/manage/team`, `/b/${entities.books.slug}/manage/lines`, `/b/${entities.books.slug}/manage/verify`, '/welcome-in']],
        ['thandi', 'thandi', ['/work', `/s/${talentSignal}`, `/b/${entities.kopano.slug}/manage/roles`]],
        ['mod', 'mod', ['/moderation', '/operator', '/floor']],
        ['newbie', 'newbie', ['/welcome-in']],
    ];

    const findings = { dead: [], broken: [], unwired: [], inert: [], fake: [], disabled: [], pages: [] };
    const seen = new Set();
    const sig = (c) => [c.tag, c.text, c.href, c.wire, c.role, c.id].join('|');
    const people = {};

    for (const [actor, key, paths] of plan) {
        const p = actor === 'anon' ? anon : (people[actor] ??= await P(key));
        for (const path of paths) {
            if (/undefined/.test(path)) continue;
            let res;
            try { res = await p.page.goto(path); } catch (e) { findings.broken.push(`${actor} ${path}: ${e.message.slice(0, 60)}`); continue; }
            await settle(p.page, 300);
            const status = res?.status();
            findings.pages.push(`${actor} ${path} -> ${status}`);
            console.log(`  .. ${actor} ${path} ${status}`);
            if (status >= 400) { findings.broken.push(`${actor} ${path}: HTTP ${status}`); continue; }

            // fake activity, fake metrics, leaked templates
            const text = await p.page.locator('body').innerText();
            for (const [re, what] of FAKE) { const m = text.match(re); if (m) findings.fake.push(`${actor} ${path}: ${what}: "${m[0]}"`); }

            // links: dead anchors, broken internal targets
            const links = await p.page.evaluate(() => [...document.querySelectorAll('a')].filter((a) => a.offsetParent !== null || a.getClientRects().length).map((a) => ({ href: a.getAttribute('href'), text: (a.innerText || a.getAttribute('aria-label') || '').trim().slice(0, 50), blank: a.target === '_blank', rel: a.rel })));
            for (const l of links) {
                if (l.href === null) { findings.dead.push(`${actor} ${path}: <a> without href "${l.text}"`); continue; }
                if (l.href === '#' || l.href === '' || /^javascript:/i.test(l.href)) { findings.dead.push(`${actor} ${path}: dead link "${l.text}" href="${l.href}"`); continue; }
                if (l.href.startsWith('#')) { if (!(await p.page.locator(l.href).count())) findings.dead.push(`${actor} ${path}: anchor "${l.text}" points at missing ${l.href}`); continue; }
                if (l.blank && !/noopener/.test(l.rel)) findings.fake.push(`${actor} ${path}: target=_blank without rel=noopener "${l.text}"`);
                if (/^(mailto:|tel:)/.test(l.href)) continue;
                const key2 = 'link|' + l.href;
                if (seen.has(key2)) continue;
                seen.add(key2);
                if (/^https?:\/\//.test(l.href) && !l.href.startsWith(ctx.base)) { findings.disabled.push(`external ${l.href} ("${l.text}") on ${path}`); continue; }
                if (/logout|\/export|\/media\//.test(l.href)) continue;
                const r = await p.context.request.get(l.href.startsWith('http') ? l.href : ctx.base + l.href, { maxRedirects: 5 }).catch(() => null);
                if (!r || r.status() >= 400) findings.broken.push(`${actor} ${path}: link "${l.text}" -> ${l.href} answers ${r?.status()}`);
            }

            // controls
            const controls = (await interactives(p.page)).filter((c) => c.tag !== 'a');
            for (const c of controls) {
                if (c.disabled && !(c.title || c.text)) findings.disabled.push(`${actor} ${path}: disabled control with no label`);
                if (c.disabled) {
                    const reason = await p.page.evaluate((i) => { const el = document.querySelectorAll('a, button, [role=button], [role=tab], [role=menuitem], [role=switch], input[type=checkbox], input[type=radio], summary, select, [wire\\:click], [x-on\\:click], [data-pk-dialog], [data-pk-toggle]')[i]; if (!el) return 'gone'; const d = el.getAttribute('aria-describedby'); return (el.title || (d && document.getElementById(d)?.innerText) || el.closest('form,fieldset,div')?.innerText || '').slice(0, 80); }, c.i);
                    if (!reason || reason === 'gone') findings.disabled.push(`${actor} ${path}: disabled "${c.text}" with no stated reason`);
                    continue;
                }
                const k = sig(c);
                if (seen.has(k) || SKIP_CLICK.test(c.text)) continue;
                seen.add(k);
                console.log(`     click ${c.tag} "${c.text.slice(0, 40)}"`);
                const idx = c.i;
                const before = await p.page.evaluate(() => ({ url: location.href, len: document.body.innerHTML.length, dialogs: document.querySelectorAll('dialog[open]').length, expanded: document.querySelectorAll('[aria-expanded=true]').length, theme: document.documentElement.dataset.theme || '', toasts: document.querySelectorAll('.pk-toast').length, checked: document.querySelectorAll(':checked').length, active: document.activeElement?.tagName + document.activeElement?.id }));
                let reqs = 0;
                const onReq = (r) => { if (!/_boost|pulse-check/.test(r.url())) reqs++; };
                p.page.on('request', onReq);
                const popup = p.page.context().waitForEvent('page', { timeout: 450 }).catch(() => null);
                // Resolve the very element interactives() listed (same selector, same visibility filter, same order) and activate it.
                const handle = await p.page.evaluateHandle((i) => {
                    const sel = 'a, button, [role=button], [role=tab], [role=menuitem], [role=switch], input[type=checkbox], input[type=radio], summary, select, [wire\\:click], [x-on\\:click], [data-pk-dialog], [data-pk-toggle]';
                    const vis = (el) => { const r = el.getBoundingClientRect(); const st = getComputedStyle(el); return r.width > 0 && r.height > 0 && st.visibility !== 'hidden' && st.display !== 'none'; };
                    return [...document.querySelectorAll(sel)].filter(vis)[i] || null;
                }, idx);
                const el = handle.asElement();
                let didClick = false;
                let blockedByValidation = false;
                try {
                    if (el) {
                        const info = await el.evaluate((e) => ({ type: e.tagName.toLowerCase() === 'button' ? (e.type || 'submit') : '', invalid: !!(e.form && !e.form.checkValidity()), tag: e.tagName.toLowerCase(), inputType: e.type }));
                        blockedByValidation = info.type === 'submit' && info.invalid;
                        if (info.tag === 'select') { await el.selectOption({ index: 1 }).catch(async () => { await el.focus(); }); didClick = true; }
                        else { await el.click({ timeout: 2500, force: info.tag === 'input' }); didClick = true; }
                    }
                } catch (e) {
                    // The harness's own index can drift after a re-render: fall back to the accessible name before calling it unwired.
                    try { await p.page.getByRole('button', { name: c.text, exact: true }).first().click({ timeout: 2500 }); didClick = true; } catch { /* reported below */ }
                }
                await p.page.waitForTimeout(450);
                const popped = await popup;
                p.page.off('request', onReq);
                const after = await p.page.evaluate(() => ({ url: location.href, len: document.body.innerHTML.length, dialogs: document.querySelectorAll('dialog[open]').length, expanded: document.querySelectorAll('[aria-expanded=true]').length, theme: document.documentElement.dataset.theme || '', toasts: document.querySelectorAll('.pk-toast').length, checked: document.querySelectorAll(':checked').length, active: document.activeElement?.tagName + document.activeElement?.id })).catch(() => before);
                const changed = popped || reqs > 0 || JSON.stringify(before) !== JSON.stringify(after);
                if (!didClick) findings.unwired.push(`${actor} ${path}: "${c.text}" could not be activated`);
                else if (!changed && !blockedByValidation) findings.inert.push(`${actor} ${path}: "${c.text}" (${c.tag}${c.wire ? ' ' + c.wire : ''}) did nothing visible`);
                // a fresh page for the next control, only when this one changed something
                if (changed || (await p.page.evaluate(() => location.pathname + location.search)) !== path) {
                    await p.page.goto(path);
                    await settle(p.page, 200);
                }
            }
            await axe(p.page, s, `${actor} ${path}`.slice(0, 80));
        }
    }

    writeFileSync(join(ctx.out, 'crawl-findings.json'), JSON.stringify(findings, null, 2));
    s.note(`pages crawled: ${findings.pages.length}; unique controls tested: ${seen.size}`);
    s.check(`no dead links (href="#", empty, javascript:, missing anchors): ${findings.dead.length}`, findings.dead.length === 0, findings.dead.join(' | '));
    s.check(`no broken internal links or pages: ${findings.broken.length}`, findings.broken.length === 0, findings.broken.join(' | '));
    s.check(`every control can be activated: ${findings.unwired.length} could not`, findings.unwired.length === 0, findings.unwired.join(' | '));
    s.check(`every activated control does something real: ${findings.inert.length} inert`, findings.inert.length === 0, findings.inert.join(' | '));
    s.check(`no fake activity, placeholder text, urgency or template leakage: ${findings.fake.length}`, findings.fake.length === 0, findings.fake.join(' | '));
    s.check(`disabled controls always say why: ${findings.disabled.filter((x) => !x.startsWith('external')).length} do not`, findings.disabled.filter((x) => !x.startsWith('external')).length === 0, findings.disabled.join(' | '));
    s.note('external links: ' + findings.disabled.filter((x) => x.startsWith('external')).join(' | '));
    s.check('no console errors', [anon, ...Object.values(people)].every((p) => p.errors.length === 0), [anon, ...Object.values(people)].flatMap((p) => p.errors).slice(0, 6).join(' | '));
    await browser.close();
    return s.result();
}
