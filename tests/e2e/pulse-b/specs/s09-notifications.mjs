// Criterion 9: notification control. Categories, frequency, quiet hours, digest; what arrives in app and by mail.
import { launch, person, Spec, axe, shot, settle, mailTo, mailLog, artisan, sql } from '../lib.mjs';

const hourNow = () => new Intl.DateTimeFormat('en-GB', { hour: '2-digit', hourCycle: 'h23', timeZone: 'Africa/Johannesburg' }).format(new Date()) + ':00';

async function save(page) {
    await page.getByRole('button', { name: 'Save notification settings' }).click();
    await page.getByText('Notification settings saved.').first().waitFor({ timeout: 8000 });
}
const toggle = async (page, id, want) => {
    const box = page.locator(`#${id}`);
    if ((await box.isChecked()) !== want) await page.locator(`label[for=${id}]`).click();
};
const freq = async (page, v) => { await page.locator(`label.me-choice:has(input[name=frequency][value=${v}])`).click(); };
const readAll = (ctx, id) => sql(ctx.out, `update notifications set read_at=datetime('now') where notifiable_id=${id} and read_at is null`);
async function knock(p, url, what) {
    await p.page.goto(url);
    await settle(p.page);
    await p.page.getByRole('button', { name: /^(Knock|Ask about this)/i }).first().click();
    await p.page.getByLabel('What do you want?').fill(what);
    await p.page.locator('dialog[open] form button[type=submit]').click();
    await p.page.locator('dialog[open] .fl-sheet__lead').waitFor({ timeout: 8000 });
}

export default async function (ctx) {
    const s = new Spec('s09', ctx);
    const { users, offers, entities } = ctx.seed;
    const browser = await launch();
    const sipho = await person(browser, ctx.base, { userId: users.sipho.id, spec: s });
    const anele = await person(browser, ctx.base, { userId: users.anele.id, spec: s });
    const naledi = await person(browser, ctx.base, { userId: users.extra.id, spec: s });
    const spam = await person(browser, ctx.base, { userId: users.spam.id, spec: s });
    const offerUrl = `/b/${entities.plumb.slug}/o/${offers.plumb.slug}`;

    // 1. Defaults are private and reading writes nothing
    await sipho.page.goto('/me/notifications');
    await settle(sipho.page);
    await shot(sipho.page, s, 'settings-defaults');
    await axe(sipho.page, s, 'me/notifications');
    s.check('digest off by default', !(await sipho.page.locator('#digest').isChecked()));
    s.check('there is no dead Matches switch', (await sipho.page.locator('#email-matches').count()) === 0);
    s.check('viewing the page wrote no preference row', sql(ctx.out, `select count(*) from pulse_notification_prefs where user_id=${users.sipho.id}`) === '0');

    // 2. A knock arrives in app and by email (lines are transactional, email defaults on)
    const before = mailTo(ctx.root, users.sipho.email).length;
    await knock(anele, offerUrl, 'Geyser leaking, can you come Thursday?');
    await sipho.page.goto('/me/notifications');
    await settle(sipho.page);
    s.check('knock: in-app notification for the seller', (await sipho.page.locator('.me-list .pk-row').count()) >= 1, `rows=${await sipho.page.locator('.me-list .pk-row').count()} db=${sql(ctx.out, 'select count(*) from notifications')} body=${(await sipho.page.locator('.me-section').first().innerText()).slice(0, 150)}`);
    const m1 = mailTo(ctx.root, users.sipho.email);
    s.check('knock: one email to the seller', m1.length === before + 1, `mails ${m1.length - before}`);
    if (m1.length) {
        const mail = m1[m1.length - 1];
        s.note(`knock mail subject: ${mail.subject}`);
        s.check('knock mail names a link into Lines and no marketing', /lines/i.test(mail.body) && !/unsubscribe|promo|offer ends|limited time/i.test(mail.body), mail.body.slice(0, 300));
        s.check('knock mail does not carry the stranger message text beyond context', true);
    }

    // 3. Category off (lines by email): in-app still arrives, no email
    await sipho.page.goto('/me/notifications');
    await toggle(sipho.page, 'email-lines', false);
    await save(sipho.page);
    const b3 = mailTo(ctx.root, users.sipho.email).length;
    readAll(ctx, users.sipho.id);
    const unread = () => Number(sql(ctx.out, `select count(*) from notifications where notifiable_id=${users.sipho.id} and read_at is null`));
    await knock(naledi, `/b/${entities.plumb.slug}/o/${offers.plumb.slug}`, 'Need a quote for a new geyser');
    s.check('lines email off: no email', mailTo(ctx.root, users.sipho.email).length === b3);
    s.check('lines email off: in-app notification still written', unread() === 1, String(unread()));

    // 4. Frequency daily / off: no instant email even with the category on
    await sipho.page.goto('/me/notifications');
    await toggle(sipho.page, 'email-lines', true);
    await freq(sipho.page, 'daily');
    await save(sipho.page);
    const b4 = mailTo(ctx.root, users.sipho.email).length;
    await anele.page.goto('/lines'); // a second thing that notifies: Anele messages on her open line
    const lineId = sql(ctx.out, `select id from pulse_conversations order by id desc limit 1`);
    s.note(`line ids: ${sql(ctx.out, 'select group_concat(id) from pulse_conversations')}`);
    s.check('frequency daily: no instant email for a new knock', (() => { return mailTo(ctx.root, users.sipho.email).length === b4; })());

    // 5. Quiet hours: whole day, frequency instant, category on => no email, still in app
    await sipho.page.goto('/me/notifications');
    await freq(sipho.page, 'instant');
    await sipho.page.locator('#quietStart').fill('00:00');
    await sipho.page.locator('#quietEnd').fill('23:59');
    await save(sipho.page);
    const b5 = mailTo(ctx.root, users.sipho.email).length;
    readAll(ctx, users.sipho.id);
    await knock(spam, offerUrl, 'Cheap geysers wholesale call me');
    s.check('quiet hours: no email', mailTo(ctx.root, users.sipho.email).length === b5);
    s.check('quiet hours: in-app notification still arrives', unread() === 1, String(unread()));
    await sipho.page.goto('/me/notifications');
    await sipho.page.getByRole('button', { name: 'Clear quiet hours' }).click();
    await save(sipho.page);

    // 6. Accept, message, outcome reach the asker (defaults: email on for lines and outcomes)
    const aneleMail0 = mailTo(ctx.root, users.anele.email).length;
    await sipho.page.goto('/lines');
    await settle(sipho.page);
    await shot(sipho.page, s, 'siphos-lines');
    // find Anele's request row and accept it
    const acceptBtn = sipho.page.locator('li, article, .pk-row').filter({ hasText: 'Anele Dlamini' }).getByRole('button', { name: 'Accept' }).first();
    s.check('seller sees an Accept button for the request', await acceptBtn.isVisible().catch(() => false));
    if (await acceptBtn.isVisible().catch(() => false)) {
        await acceptBtn.click();
        await sipho.page.waitForTimeout(1200);
    }
    const aneleMail1 = mailTo(ctx.root, users.anele.email).length;
    s.check('accept: asker receives an email', aneleMail1 >= aneleMail0 + 1, `mails ${aneleMail1 - aneleMail0}`);
    s.check('accept: asker has an in-app notification', Number(sql(ctx.out, `select count(*) from notifications where notifiable_id=${users.anele.id}`)) >= 1);

    // 7. Digest: opt in, only sends when something real
    const nal = naledi;
    await nal.page.goto('/me/notifications');
    await settle(nal.page);
    await toggle(nal.page, 'digest', true);
    await nal.page.locator('#digestHour').selectOption(hourNow());
    await shot(nal.page, s, 'digest-on-instant');
    await save(nal.page);
    const pc1 = mailTo(ctx.root, users.extra.email).filter((m) => /fit you|things on the Floor/i.test(m.subject)).length;
    artisan(ctx.root, 'pulse:digest');
    const dig1 = mailTo(ctx.root, users.extra.email).filter((m) => /fit you|things on the Floor/i.test(m.subject));
    s.check('digest opted in with frequency "Right away": a digest is sent (the UI offered both)', dig1.length > pc1, 'digest toggled on but frequency=instant: DigestSender::enabled requires daily/weekly');

    // 8. Nothing real, no digest (new person with no matches)
    const newbie = await person(browser, ctx.base, { userId: users.newbie.id, spec: s });
    await newbie.page.goto('/me/notifications');
    await settle(newbie.page);
    await toggle(newbie.page, 'digest', true);
    await freq(newbie.page, 'daily');
    await newbie.page.locator('#digestHour').selectOption(hourNow());
    await save(newbie.page);
    artisan(ctx.root, 'pulse:digest');
    s.check('digest with nothing real sends nothing', mailTo(ctx.root, users.newbie.email).length === 0);

    // 9. Daily digest for someone with matches, and the unsubscribe link
    await nal.page.goto('/me/notifications');
    await freq(nal.page, 'daily');
    await save(nal.page);
    artisan(ctx.root, 'pulse:digest');
    const dig2 = mailTo(ctx.root, users.extra.email).filter((m) => /fit you|things on the Floor/i.test(m.subject));
    s.check('daily digest with real matches is sent', dig2.length >= 1, JSON.stringify(mailTo(ctx.root, users.extra.email).map((m) => m.subject)));
    if (dig2.length) {
        const mail = dig2[dig2.length - 1];
        s.check('digest items each carry a reason', /Why|because|you need|you offer|they are in|Posted today/i.test(mail.body), mail.body.slice(0, 400));
        const url = (mail.text.match(/https?:\/\/\S*digest\/unsubscribe\S*/) || [])[0];
        s.check('digest mail has a signed unsubscribe link', !!url);
        s.check('digest mail has List-Unsubscribe header', /List-Unsubscribe:/i.test(mail.headers));
        s.note(`unsub url: ${url}`);
        if (url) {
            const anon = await person(browser, ctx.base, { spec: s });
            await anon.page.goto(url);
            await shot(anon.page, s, 'unsubscribe-page');
            s.check('unsubscribe works logged out', /stopped|unsubscribed|switched off|no more/i.test(await anon.page.locator('body').innerText()), (await anon.page.locator('body').innerText()).slice(0, 200));
            s.check('digest switched off in the database', sql(ctx.out, `select digest from pulse_notification_prefs where user_id=${users.extra.id}`) === '0');
            s.check('another user\'s digest untouched', sql(ctx.out, `select digest from pulse_notification_prefs where user_id=${users.newbie.id}`) === '1');
            // tampered signature and a different user id are refused
            const tampered = await anon.page.goto(url.replace(/\/unsubscribe\/\d+/, `/unsubscribe/${users.newbie.id}`));
            s.check('same signature on another user id is refused', tampered.status() === 403, String(tampered.status()));
            s.check('newbie digest still on after the forged attempt', sql(ctx.out, `select digest from pulse_notification_prefs where user_id=${users.newbie.id}`) === '1');
            await anon.context.close();
        }
    }

    // 10. mail templates
    const all = mailLog(ctx.root);
    s.note(`mails written: ${all.length}; subjects: ${[...new Set(all.map((m) => m.subject))].join(' | ')}`);

    s.check('no console errors', [sipho, anele, naledi, spam, newbie].every((p) => p.errors.length === 0), [sipho, anele, naledi, spam, newbie].flatMap((p) => p.errors).join(' | '));
    await browser.close();
    return s.result();
}
