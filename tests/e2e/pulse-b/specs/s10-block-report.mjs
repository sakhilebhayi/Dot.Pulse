// Criterion 10: block and report. Hidden both ways, lines closed, not in Floor/search/digest; reports reach a moderator,
// confirmed spam shrinks the knock budget, the author is told; budget exhaustion message.
import { launch, person, Spec, axe, shot, settle, mailTo, artisan, sql } from '../lib.mjs';

const hourNow = () => new Intl.DateTimeFormat('en-GB', { hour: '2-digit', hourCycle: 'h23', timeZone: 'Africa/Johannesburg' }).format(new Date()) + ':00';

async function openSheet(p, url) {
    await p.page.goto(url);
    await settle(p.page);
    await p.page.getByRole('button', { name: /^(Knock|Ask about this)/i }).first().click();
    await p.page.getByLabel('What do you want?').waitFor();
}
async function knock(p, url, what) {
    await openSheet(p, url);
    await p.page.getByLabel('What do you want?').fill(what);
    await p.page.locator('dialog[open] form button[type=submit]').click();
    await p.page.locator('dialog[open] .fl-sheet__lead').waitFor({ timeout: 8000 });
}
const budgetText = async (p, url) => {
    await openSheet(p, url);
    const t = await p.page.locator('dialog[open]').innerText();
    await p.page.keyboard.press('Escape');
    return (t.match(/one of your (\d+) first contacts/) || [])[1];
};
const lineIdFor = (out, a) => sql(out, `select id from pulse_conversations where initiator_id=${a} order by id desc limit 1`);

export default async function (ctx) {
    const s = new Spec('s10', ctx);
    s.ctx.out = ctx.out;
    const { users, offers, entities } = ctx.seed;
    const browser = await launch();
    const mk = (k) => person(browser, ctx.base, { userId: users[k].id, spec: s, ignoreConsole: [/status of 404/] });
    const [anele, sipho, spam, naledi, mod, jabu] = await Promise.all(['anele', 'sipho', 'spam', 'extra', 'mod', 'jabu'].map(mk));
    const booksOffer = `/b/${entities.books.slug}/o/${offers.book.slug}`;
    const plumbOffer = `/b/${entities.plumb.slug}/o/${offers.plumb.slug}`;

    // ---- A. spam: a line, a message, a report, a moderator confirms, the knock budget shrinks
    const budget0 = await budgetText(spam, plumbOffer);
    s.check('knock budget is stated in plain words before a knock', !!budget0, 'no "N first contacts" text');
    await knock(spam, booksOffer, 'Cheap geysers wholesale, call me');
    const spamLine = lineIdFor(ctx.out, users.spam.id);
    await spam.page.goto(`/lines/${spamLine}`);
    await settle(spam.page);
    s.note('spam thread: ' + spamLine + ' ' + (await spam.page.locator('main').innerText()).slice(0, 600));
    await spam.page.locator("#ln-body").fill('Buy now buy now great deals');
    await spam.page.getByRole('button', { name: 'Send' }).click();
    await spam.page.waitForTimeout(800);

    await sipho.page.goto(`/lines/${spamLine}`);
    await settle(sipho.page);
    await shot(sipho.page, s, 'thread-before-report');
    await sipho.page.getByRole('button', { name: 'Report this message' }).first().click();
    await sipho.page.waitForTimeout(500);
    await sipho.page.locator('.ln-reportbox select').selectOption({ label: 'Spam' }).catch(async () => { await sipho.page.locator('.ln-reportbox select').selectOption({ index: 1 }); });
    await sipho.page.locator('.ln-reportbox').getByRole('button', { name: 'Send report' }).click();
    await sipho.page.getByText('Reported. A moderator will look.').first().waitFor({ timeout: 8000 });
    s.check('report a message: reporter is told it went to a moderator', true);
    // the line itself
    await sipho.page.reload();
    await sipho.page.locator('.ln-tools').getByRole('button', { name: 'Report' }).click();
    await sipho.page.locator('.ln-tools select').selectOption({ index: 1 });
    await sipho.page.locator('.ln-tools').getByRole('button', { name: 'Send report' }).click();
    await sipho.page.getByText('Reported. A moderator will look.').first().waitFor({ timeout: 8000 });
    s.check('report a line', Number(sql(ctx.out, "select count(*) from pulse_reports where reportable_type like '%onversation%'")) === 1, sql(ctx.out, 'select reportable_type from pulse_reports'));
    s.check('report reached the moderation table twice (message + line)', Number(sql(ctx.out, 'select count(*) from pulse_reports')) === 2, sql(ctx.out, 'select group_concat(reportable_type) from pulse_reports'));

    // report a listing (offer)
    await anele.page.goto(`/b/${entities.kopano.slug}/r/${ctx.seed.roles.dispatch.slug}`);
    await settle(anele.page);
    await anele.page.getByRole('button', { name: 'Report', exact: true }).first().click();
    await shot(anele.page, s, 'report-listing-form');
    const sel = anele.page.locator('select').filter({ hasText: /spam/i }).last();
    await anele.page.locator('form[aria-label^="Report"] select, .biz-report select, [role=dialog] select, select').last().selectOption({ index: 1 }).catch(() => {});
    await anele.page.getByRole('button', { name: 'Send report' }).click().catch(() => {});
    await anele.page.waitForTimeout(800);
    s.check('report a listing reaches the table', Number(sql(ctx.out, "select count(*) from pulse_reports where reportable_type like '%Role%'")) === 1, sql(ctx.out, 'select group_concat(reportable_type) from pulse_reports'));

    // moderator
    await mod.page.goto('/moderation');
    await settle(mod.page);
    await shot(mod.page, s, 'moderation-queue');
    await axe(mod.page, s, 'moderation');
    await mod.page.locator('.pk-tab', { hasText: /Reports|Messages/ }).first().click();
    await mod.page.waitForTimeout(500);
    s.note('moderation tabs: ' + (await mod.page.locator('.pk-tab').allInnerTexts()).join(' | '));
    // open the messages and reviews tab
    await mod.page.locator('.pk-tab', { hasText: /Messages and reviews|Lines/ }).first().click();
    await mod.page.waitForTimeout(600);
    await shot(mod.page, s, 'moderation-lines-tab');
    const confirmBtn = mod.page.getByRole('button', { name: 'Confirm report' }).first();
    s.check('moderator sees the reported message/line with a Confirm report', await confirmBtn.isVisible().catch(() => false));
    await confirmBtn.click().catch(() => {});
    await mod.page.waitForTimeout(800);
    await confirmBtn.click().catch(() => {});
    await mod.page.waitForTimeout(800);
    const penalty = sql(ctx.out, `select penalty from pulse_knock_budgets where user_id=${users.spam.id}`);
    s.check('confirmed spam shrinks the sender budget', Number(penalty) >= 1, `penalty=${penalty}`);
    // budget visible to the sender dropped
    sql(ctx.out, 'select 1');
    const budget1 = await budgetText(spam, plumbOffer);
    s.check('the sender sees a smaller allowance', Number(budget1) < Number(budget0), `${budget0} -> ${budget1} row=${sql(ctx.out, `select spent,bonus,penalty from pulse_knock_budgets where user_id=${users.spam.id}`)}`);
    await sipho.page.goto('/me/notifications');
    s.check('reporter is told the outcome in plain words', /report/i.test(await sipho.page.locator('.me-list').innerText().catch(() => '')), await sipho.page.locator('.me-section').first().innerText());

    // ---- B. listing removed by a moderator: the author is told why
    await mod.page.goto('/moderation');
    await mod.page.locator('.pk-tab', { hasText: /Reports/ }).first().click();
    await mod.page.waitForTimeout(600);
    const removeBtn = mod.page.getByRole('button', { name: /^Remove role/i }).first();
    s.check('moderator can remove a reported role', await removeBtn.isVisible().catch(() => false));
    if (await removeBtn.isVisible().catch(() => false)) {
        await removeBtn.click();
        await mod.page.getByLabel(/Why it is being removed/).fill('Breaks the rule on misleading prices');
        await mod.page.getByRole('button', { name: 'Confirm removal' }).click();
        await mod.page.waitForTimeout(1000);
        const thandi = await mk('thandi');
        await thandi.page.goto('/me/notifications');
        const txt = await thandi.page.locator('.me-list').innerText().catch(() => '');
        s.check('the author is told what was removed and why, in plain words', /removed/i.test(txt) && /misleading prices/i.test(txt), txt.slice(0, 400));
    }

    // ---- C. block a business page: hidden both ways, line closed, not in Floor/search/digest
    const nal = naledi;
    await nal.page.goto('/me/notifications');
    await settle(nal.page);
    // Naledi opts in to a digest and knocks Moyo Plumbing, then blocks it
    await nal.page.locator('label[for=digest]').click();
    await nal.page.locator('#digestHour').selectOption(hourNow());
    await nal.page.getByRole('button', { name: 'Save notification settings' }).click();
    await nal.page.getByText('Notification settings saved.').first().waitFor();
    await knock(nal, plumbOffer, 'Quote for a new geyser please');
    const nalLine = lineIdFor(ctx.out, users.extra.id);
    await nal.page.goto('/floor');
    await settle(nal.page);
    s.check('before the block: Moyo Plumbing is on the floor', (await nal.page.locator('body').innerText()).includes('Moyo Plumbing'));
    await nal.page.goto(plumbOffer);
    await nal.page.getByRole('button', { name: 'Block', exact: true }).click();
    await nal.page.locator('[role=alertdialog]').waitFor();
    await shot(nal.page, s, 'block-confirm');
    await nal.page.locator('[role=alertdialog]').getByRole('button', { name: 'Block', exact: true }).click();
    await nal.page.waitForTimeout(1000);
    await nal.page.goto('/floor');
    await settle(nal.page);
    s.check('after the block: not on the floor', !(await nal.page.locator('body').innerText()).includes('Moyo Plumbing'));
    await nal.page.goto('/search?q=geyser');
    await settle(nal.page);
    s.check('after the block: not in search', !(await nal.page.locator('main').innerText()).includes('Moyo Plumbing'), (await nal.page.locator('main').innerText()).slice(0, 300));
    await nal.page.goto(plumbOffer);
    s.check('the blocked page is gone for the blocker (404)', /not found|couldn.t find|404/i.test(await nal.page.locator('body').innerText()), (await nal.page.locator('body').innerText()).slice(0, 120));
    s.check('open lines with the page closed', sql(ctx.out, `select status from pulse_conversations where id=${nalLine}`) === 'closed', sql(ctx.out, `select status from pulse_conversations where id=${nalLine}`));
    artisan(ctx.root, 'pulse:digest');
    s.check('the digest does not carry the blocked page', !mailTo(ctx.root, users.extra.email).some((m) => /Moyo Plumbing/.test(m.body)), 'digest mentions blocked page');
    // hidden both ways: the page's owner cannot find Naledi's need/person either way is covered by privacy; check that Sipho's lines list no longer lets Naledi message
    await sipho.page.goto(`/lines/${nalLine}`);
    await settle(sipho.page);
    s.check('the page owner cannot keep writing on a closed line', (await sipho.page.locator('#ln-body').count()) === 0 || (await sipho.page.locator('#ln-body').isDisabled()));
    // unblock is listed in privacy
    await nal.page.goto('/me/privacy');
    await settle(nal.page);
    s.check('blocked page is listed in Privacy with a way to undo', /Moyo Plumbing/.test(await nal.page.locator('#pv-blocks').locator('..').innerText()) && (await nal.page.getByRole('button', { name: /Unblock/ }).count()) > 0);
    await shot(nal.page, s, 'privacy-blocks');

    // ---- D. block a person (needs both ways): Sipho blocks Anele's need from the need page
    await sipho.page.goto('/floor');
    await settle(sipho.page);
    const sawNeed = (await sipho.page.locator('body').innerText()).includes('Need a bookkeeper');
    s.check('before the block: the seller whose offer fits sees the need on the floor', sawNeed);
    await sipho.page.goto(`/need/${ctx.seed.needs.anele.id}`);
    await settle(sipho.page);
    const blockBtn = sipho.page.getByRole('button', { name: 'Block', exact: true }).first();
    if (await blockBtn.isVisible().catch(() => false)) {
        await blockBtn.click();
        await sipho.page.locator('[role=alertdialog]').waitFor();
        await sipho.page.locator('[role=alertdialog]').getByRole('button', { name: 'Block', exact: true }).click();
        await sipho.page.waitForTimeout(1000);
        await sipho.page.goto('/floor');
        await settle(sipho.page);
        s.check('blocked person\'s need is gone from the blocker\'s floor', !(await sipho.page.locator('body').innerText()).includes('Need a bookkeeper'));
        await anele.page.goto('/floor');
        await settle(anele.page);
        const body = await anele.page.locator('body').innerText();
        s.check('hidden both ways: the blocked person no longer sees the blocker\'s pages', !body.includes('Moyo Books') && !body.includes('Moyo Plumbing'), body.slice(0, 200));
        await anele.page.goto(booksOffer);
        s.check('hidden both ways: blocked person gets a 404 on the blocker\'s offer', /not found|couldn.t find|404/i.test(await anele.page.locator('body').innerText()));
    } else {
        s.check('need page offers Block to a seller', false, 'no Block button on the need page');
    }

    // ---- E. mute a topic from the reason a beat was shown for
    const jb = await mk('thandi'); // an employer who is shown the bookkeeping talent beat
    await jabu.page.goto('/floor');
    await settle(jabu.page);
    const before = await jabu.page.locator('body').innerText();
    const stop = jabu.page.getByRole('button', { name: 'Stop using this' }).first();
    s.check('a beat shows a removable reason', await stop.isVisible().catch(() => false), before.slice(0, 300));
    if (await stop.isVisible().catch(() => false)) {
        const topicsBefore = Number(sql(ctx.out, `select count(*) from pulse_mutes where user_id=${users.jabu.id}`));
        await stop.click();
        await jabu.page.waitForTimeout(1200);
        s.check('muting a reason writes a mute', Number(sql(ctx.out, `select count(*) from pulse_mutes where user_id=${users.jabu.id}`)) === topicsBefore + 1);
        await jabu.page.goto('/me/privacy');
        await settle(jabu.page);
        const mutesText = await jabu.page.locator('#pv-mutes').locator('..').innerText();
        s.check('the mute is listed in Privacy with an Unmute', !/not muted anything/.test(mutesText) && (await jabu.page.getByRole('button', { name: /Unmute/ }).count()) > 0, mutesText.slice(0, 200));
        await shot(jabu.page, s, 'privacy-mutes');
        await jabu.page.getByRole('button', { name: /Unmute/ }).first().click();
        await jabu.page.waitForTimeout(800);
        s.check('unmute removes it', Number(sql(ctx.out, `select count(*) from pulse_mutes where user_id=${users.jabu.id}`)) === topicsBefore);
    }

    // ---- F. report a community post
    await naledi.page.goto('/community');
    await settle(naledi.page);
    await naledi.page.getByRole('button', { name: /Share something/ }).click();
    await naledi.page.locator('#livewire-pulse-create-post-body').fill('Does anyone know a reliable courier for Soweto deliveries?');
    await naledi.page.getByRole('button', { name: 'Publish' }).click();
    await naledi.page.waitForTimeout(1500);
    const postId = sql(ctx.out, 'select id from pulse_posts order by id desc limit 1');
    s.check('a post was published through the UI', !!postId);
    if (postId) {
        await anele.page.goto(`/posts/${postId}`);
        await settle(anele.page);
        await anele.page.getByRole('button', { name: 'Report', exact: true }).first().click();
        await anele.page.locator('form select').last().selectOption({ index: 1 });
        await anele.page.getByRole('button', { name: 'Send report' }).click();
        await anele.page.waitForTimeout(1000);
        s.check('report a post reaches the table', Number(sql(ctx.out, "select count(*) from pulse_reports where reportable_type like '%Post%'")) === 1);
        await mod.page.goto('/moderation');
        await mod.page.locator('.pk-tab', { hasText: /Reports/ }).first().click();
        await mod.page.waitForTimeout(700);
        s.check('the post report is in the moderation queue', /courier/i.test(await mod.page.locator('main').innerText()));
    }

    // ---- G. knock budget exhausted
    await openSheet(anele, `/b/${entities.kopano.slug}`);
    await anele.page.keyboard.press('Escape');
    sql(ctx.out, `update pulse_knock_budgets set spent=99 where user_id=${users.anele.id}`);
    await anele.page.context().clearCookies().catch(() => {});
    await anele.page.goto(`/dev/login-b/${users.anele.id}?to=/b/${entities.kopano.slug}`);
    await settle(anele.page);
    await anele.page.getByRole('button', { name: /^(Knock|Ask about this|Say what)/i }).first().click().catch(() => {});
    await anele.page.waitForTimeout(800);
    const exhausted = await anele.page.locator('dialog[open]').innerText().catch(() => '');
    await shot(anele.page, s, 'budget-exhausted');
    s.check('exhausted budget says so in plain words with a refill date', /used this week|first contacts|refill/i.test(exhausted) && /refill|\d+ \w{3}/i.test(exhausted), exhausted.slice(0, 300));

    s.check('no console errors', [anele, sipho, spam, naledi, mod, jabu].every((p) => p.errors.length === 0), [anele, sipho, spam, naledi, mod, jabu].flatMap((p) => p.errors).join(' | '));
    await browser.close();
    return s.result();
}
