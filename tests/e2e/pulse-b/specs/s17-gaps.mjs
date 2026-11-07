// Criterion 17: workflows that had PHPUnit coverage but no real-browser coverage: open doors, saved beats, following a business,
// and the quote journey (ask for quotes, the slip, the comparison, accept).
import { launch, person, Spec, axe, shot, settle, sql } from '../lib.mjs';

export default async function (ctx) {
    const s = new Spec('s17', ctx);
    const { users, entities, offers, needs } = ctx.seed;
    const browser = await launch();
    const ig = [/status of 404/];
    const mk = (k) => person(browser, ctx.base, { userId: users[k].id, spec: s, ignoreConsole: ig });
    const [anele, sipho, extra, jabu] = await Promise.all(['anele', 'sipho', 'extra', 'jabu'].map(mk));

    // ---- doors: Sipho opens a door, a stranger knocks once, the owner sees it, the door closes
    await sipho.page.goto('/door');
    await settle(sipho.page);
    await shot(sipho.page, s, 'door-before');
    await axe(sipho.page, s, 'door');
    await sipho.page.getByLabel('What are you open to?').fill('Looking for a baker for Saturday');
    await sipho.page.locator('label.fl-radio', { hasText: '24 hours' }).click();
    await sipho.page.getByRole('button', { name: 'Open the door' }).click();
    await sipho.page.waitForTimeout(1200);
    s.check('a door was opened for a stated purpose', Number(sql(ctx.out, `select count(*) from pulse_doors where owner_id=${users.sipho.id} and status='open'`)) === 1, sql(ctx.out, 'select status from pulse_doors'));
    await shot(sipho.page, s, 'door-open');
    const doorSignal = sql(ctx.out, `select id from pulse_signals where kind='door' order by id desc limit 1`);
    s.check('the open door is a signal on the Floor', !!doorSignal);
    if (doorSignal) {
        await anele.page.goto(`/s/${doorSignal}`);
        await settle(anele.page);
        await anele.page.getByRole('button', { name: /^(Knock|Ask about this)/i }).first().click();
        await anele.page.getByLabel('What do you want?').fill('I bake wedding cakes, can supply Saturday');
        await anele.page.locator('dialog[open] form button[type=submit]').click();
        await anele.page.locator('dialog[open] .fl-sheet__lead').waitFor({ timeout: 8000 });
        s.check('a stranger can knock on an open door once', Number(sql(ctx.out, `select count(*) from pulse_conversations where door_id is not null`)) === 1);
        await anele.page.keyboard.press('Escape');
    }
    await sipho.page.goto('/door');
    await sipho.page.locator('[data-pk-dialog^="close-door"]').first().click();
    await sipho.page.locator('dialog[open]').getByRole('button', { name: 'Close the door' }).click();
    await sipho.page.waitForTimeout(1000);
    s.check('the door can be closed', Number(sql(ctx.out, `select count(*) from pulse_doors where owner_id=${users.sipho.id} and status='open'`)) === 0);

    // ---- safe handover guidance: hide it, it stays hidden for that line in this browser
    if (ctx.seed.rich?.line) {
        await sipho.page.goto(`/lines/${ctx.seed.rich.line}`);
        await settle(sipho.page);
        const h = sipho.page.locator('.ln-handover h2');
        s.check('handover guidance shows on a trade line', await h.isVisible());
        await sipho.page.getByRole('button', { name: 'Got it, hide this' }).click();
        await sipho.page.waitForTimeout(300);
        s.check('"Got it, hide this" hides it and offers to show it again', !(await h.isVisible()) && (await sipho.page.getByRole('button', { name: 'Show handover guidance' }).isVisible()));
        await sipho.page.reload();
        await settle(sipho.page);
        s.check('and it stays hidden after a reload', !(await sipho.page.locator('.ln-handover h2').isVisible()));
    }

    // ---- saved beats
    await extra.page.goto(`/b/${entities.books.slug}/o/${offers.book.slug}`);
    await settle(extra.page);
    await extra.page.getByRole('button', { name: 'Save', exact: true }).click();
    await extra.page.waitForTimeout(800);
    await extra.page.goto('/saved');
    await settle(extra.page);
    s.check('a saved offer shows on the Saved page', /bookkeeping/i.test(await extra.page.locator('main').innerText()));
    await shot(extra.page, s, 'saved');
    // ---- follow a business
    await extra.page.goto(`/b/${entities.plumb.slug}`);
    await extra.page.getByRole('button', { name: 'Follow', exact: true }).click();
    await extra.page.waitForTimeout(800);
    s.check('following a business is stored and the button says so', Number(sql(ctx.out, `select count(*) from pulse_entity_followers where user_id=${users.extra.id}`)) === 1 && (await extra.page.getByRole('button', { name: 'Following' }).count()) === 1);

    // ---- quotes: Anele asks Moyo Plumbing (its offer takes quote requests) from the offer page
    await anele.page.goto(`/b/${entities.plumb.slug}/o/${offers.plumb.slug}`);
    await settle(anele.page);
    const ask = anele.page.getByRole('button', { name: /^Ask for (a )?quotes?$/i });
    s.check('the offer page offers Ask for quotes', await ask.first().isVisible().catch(() => false), (await anele.page.locator('main').innerText()).slice(0, 200));
    if (await ask.first().isVisible().catch(() => false)) {
        await ask.first().click();
        await anele.page.getByLabel('What do you need quoted').fill('Geyser repair this week');
        s.check('the page says who the request goes to and that they pay nothing', /goes to Moyo Plumbing/.test(await anele.page.locator('main').innerText()) && /pay nothing/.test(await anele.page.locator('main').innerText()));
        await anele.page.getByRole('button', { name: 'Send request' }).click();
        await anele.page.waitForTimeout(1500);
        {
            const group = sql(ctx.out, `select quote_group from pulse_conversations where quote_group is not null order by id desc limit 1`);
            s.check('the quote request went out as a group', !!group, anele.page.url());
            if (group) {
                const line = sql(ctx.out, `select id from pulse_conversations where quote_group='${group}' order by id limit 1`);
                const owner = sql(ctx.out, `select initiator_id from pulse_conversations where id=${line}`);
                // the business answers on the standard slip
                await sipho.page.goto(`/lines/${line}`);
                await settle(sipho.page);
                await shot(sipho.page, s, 'quote-request-seller');
                const slip = sipho.page.locator('#slip-amount');
                s.check('the seller is given the standard slip', (await slip.count()) === 1, (await sipho.page.locator('main').innerText()).slice(0, 200));
                if (await slip.count()) {
                    await slip.fill('2500');
                    await sipho.page.locator('#slip-earliest').fill(new Date(Date.now() + 5 * 864e5).toISOString().slice(0, 10));
                    await sipho.page.locator('#slip-included').fill('Monthly reconciliation and VAT return');
                    await sipho.page.locator('#slip-valid').fill(new Date(Date.now() + 14 * 864e5).toISOString().slice(0, 10)).catch(() => {});
                    await sipho.page.getByRole('button', { name: /Send (the )?quote|Send/i }).last().click();
                    await sipho.page.waitForTimeout(1500);
                    s.check('the quote was stored', Number(sql(ctx.out, 'select count(*) from pulse_quotes')) === 1, sql(ctx.out, 'select count(*) from pulse_quotes'));
                    await anele.page.goto(`/lines/quotes/${group}`);
                    await settle(anele.page);
                    await shot(anele.page, s, 'quote-compare');
                    await axe(anele.page, s, 'quote compare');
                    s.check('the buyer sees the quote side by side with its price and what is included', /2 ?500/.test(await anele.page.locator('main').innerText()) && /VAT return/.test(await anele.page.locator('main').innerText()), (await anele.page.locator('main').innerText()).slice(0, 300));
                    const accept = anele.page.getByRole('button', { name: /^Accept/ }).first();
                    if (await accept.isVisible().catch(() => false)) {
                        await accept.click();
                        await anele.page.waitForTimeout(1200);
                        s.check('accepting a quote is recorded', Number(sql(ctx.out, "select count(*) from pulse_quotes where status='accepted'")) === 1, sql(ctx.out, 'select status from pulse_quotes'));
                    } else s.check('the buyer can accept a quote', false, 'no Accept button');
                }
            }
        }
    }

    s.check('no console errors', [anele, sipho, extra, jabu].every((p) => p.errors.length === 0), [anele, sipho, extra, jabu].flatMap((p) => p.errors).join(' | '));
    await browser.close();
    return s.result();
}
