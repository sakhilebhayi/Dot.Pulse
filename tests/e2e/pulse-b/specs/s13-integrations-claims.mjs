// Criterion 13: integrations are real, and every claim the landing page makes is true.
import { launch, person, Spec, axe, shot, settle, sql } from '../lib.mjs';

export default async function (ctx) {
    const s = new Spec('s13', ctx);
    const { users, entities, offers } = ctx.seed;
    const browser = await launch();
    const anon = await person(browser, ctx.base, { spec: s });
    const anele = await person(browser, ctx.base, { userId: users.anele.id, spec: s, ignoreConsole: [/status of 404/] });

    // ---- the landing page
    await anon.page.goto('/');
    await settle(anon.page);
    await shot(anon.page, s, 'landing');
    await axe(anon.page, s, 'landing');
    const text = await anon.page.locator('body').innerText();
    const links = await anon.page.locator('a').evaluateAll((as) => as.map((a) => [a.innerText.trim(), a.getAttribute('href')]));
    s.note('landing links: ' + JSON.stringify(links));
    s.check('every sample on the landing page is labelled as an example', /Example only/.test(text) && (text.match(/Example/g) || []).length >= 3);
    s.check('the sign-in door is InfoDot and nothing else', links.filter(([t]) => /sign in with infodot/i.test(t)).length >= 1 && links.filter(([t]) => /sign in with infodot/i.test(t)).every(([, h]) => /^https:\/\/infodot\.co\.za\/?$/.test(h)), JSON.stringify(links.filter(([t]) => /sign in/i.test(t))));
    s.check('the in-page anchor works', (await anon.page.locator('#match').count()) === 1);
    s.check('no decorative launcher or integration badges', !/Dot\.(Emall|HR|Mines|Files|Notify|Agents|Brain|Memory|Engage|Press)|Powered by|Integrates with|Works with/i.test(text), text.match(/Dot\.\w+/g)?.join(','));
    s.check('no payment, upgrade, boost or sponsored wording anywhere on the front page', !/upgrade|premium|boost|sponsored|promoted|buy credits|pay to|pricing|subscribe/i.test(text), text.match(/upgrade|premium|boost|sponsored|promoted|pricing|subscribe/gi)?.join(','));

    // ---- claim by claim
    s.check('claim "a match with no reasons does not exist": no match row has an empty reason list', Number(sql(ctx.out, "select count(*) from pulse_matches where reasons is null or reasons='[]' or reasons=''")) === 0, sql(ctx.out, "select count(*) from pulse_matches where reasons='[]'"));
    // "Four fields, no links": the sheet has four fields and refuses a link in a first message
    await anele.page.goto(`/b/${entities.plumb.slug}/o/${offers.plumb.slug}`);
    await settle(anele.page);
    await anele.page.getByRole('button', { name: /^(Knock|Ask about this)/i }).first().click();
    await anele.page.locator('dialog[open] #what').waitFor();
    const fields = await anele.page.locator('dialog[open] input[type=text], dialog[open] textarea').count();
    s.check('claim "four fields": the knock sheet has four', fields === 4, `fields: ${fields}`);
    await anele.page.getByLabel('What do you want?').fill('See my site http://bit.ly/abc for details');
    await anele.page.locator('dialog[open] form button[type=submit]').click();
    await anele.page.waitForTimeout(1200);
    const dlg = await anele.page.locator('dialog[open]').innerText();
    s.check('claim "no links in a first message": a link is refused with a reason', !/The line is open|Now they decide/.test(dlg) && /link/i.test(dlg), dlg.slice(0, 300));
    await shot(anele.page, s, 'knock-link-refused');
    await anele.page.keyboard.press('Escape');

    // "who may reach you" and "who may find you"
    await anele.page.goto('/me/settings');
    await settle(anele.page);
    s.check('claim "who may reach you: anyone, through something of yours, by request, or nobody": four choices', (await anele.page.locator('input[name=contactPolicy]').count()) === 4, String(await anele.page.locator('input[name=contactPolicy]').count()));
    await anele.page.goto('/me/discoverability');
    await settle(anele.page);
    s.check('claim "who may find you: off, anonymous, open": three choices', (await anele.page.locator('input[type=radio]').count()) === 3, String(await anele.page.locator('input[type=radio]').count()));

    // "nothing is marked verified that was not"
    await anele.page.goto(`/b/${entities.books.slug}`);
    await settle(anele.page);
    const biz = await anele.page.locator('main').innerText();
    s.check('claim "nothing is marked verified that was not": a business with no InfoDot link is shown as not verified', /not verified|unverified|claimed/i.test(biz) && !/verified business|✔ verified|^verified$/im.test(biz), biz.slice(0, 200));
    s.check('the ladder lists checks by name as checked, claimed or missing', /checked|claimed|missing/i.test(biz));

    // "businesses cannot send marketing through Pulse": no broadcast surface in the manage pages
    const sipho = await person(browser, ctx.base, { userId: users.sipho.id, spec: s });
    let allText = '';
    for (const tab of ['', '/page', '/offers', '/roles', '/team', '/lines', '/verify']) {
        await sipho.page.goto(`/b/${entities.books.slug}/manage${tab}`);
        await settle(sipho.page, 250);
        allText += '\n' + (await sipho.page.locator('main').innerText());
    }
    s.check('claim "businesses cannot send marketing": no broadcast, newsletter or message-all-followers control exists', !/broadcast|newsletter|message (all|your) followers|send (an )?announcement|campaign/i.test(allText), allText.match(/broadcast|newsletter|campaign/gi)?.join(','));
    s.check('claim "free to answer": nothing on the business side asks for money', !/pay|upgrade|plan|billing|credits|invoice/i.test(allText.replace(/pay_text|pay\b.*per/gi, '')), allText.match(/\b(upgrade|billing|credits|invoice)\b/gi)?.join(','));

    s.check('no console errors', anon.errors.length + anele.errors.length + sipho.errors.length === 0, [...anon.errors, ...anele.errors, ...sipho.errors].join(' | '));
    await browser.close();
    return s.result();
}
