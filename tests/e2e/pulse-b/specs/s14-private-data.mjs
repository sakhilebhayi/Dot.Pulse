// Criterion 14: private data stays protected. Two browser contexts: an employer and an anonymous professional.
import { launch, person, Spec, axe, shot, settle, mailLog, sql } from '../lib.mjs';
import { readFileSync } from 'node:fs';

export default async function (ctx) {
    const s = new Spec('s14', ctx);
    const { users, secrets } = ctx.seed;
    const browser = await launch();
    const employer = await person(browser, ctx.base, { userId: users.thandi.id, spec: s, ignoreConsole: [/status of 404/] });
    const pro = await person(browser, ctx.base, { userId: users.lerato.id, spec: s });

    // Everything the employer's browser ever receives, for scanning.
    const bodies = [];
    employer.page.on('response', async (r) => {
        try {
            const ct = r.headers()['content-type'] || '';
            if (/json|html|text|javascript/.test(ct) && !/\.js$|\/build\//.test(r.url())) bodies.push({ url: r.url(), text: await r.text() });
        } catch { /* redirects and aborted bodies */ }
    });
    const needles = ['Lerato', 'Zwane', 'Anonsecret', secrets.lerato_email, 'Secretfreight', `/u/${users.lerato.id}`, 'lerato.private'];
    const scan = (label, text) => {
        const hit = needles.filter((n) => text.includes(n));
        s.check(`no identity leak in ${label}`, hit.length === 0, `found ${hit.join(', ')}`);
    };

    // 1. The talent card on the employer's floor
    await employer.page.goto('/work');
    await settle(employer.page);
    await shot(employer.page, s, 'employer-work-floor');
    await axe(employer.page, s, 'employer /work');
    s.check('the anonymous card is shown', /Anonymous professional/.test(await employer.page.locator('main').innerText()));
    scan('the rendered text', await employer.page.locator('body').innerText());
    scan('the page source (DOM, including wire:snapshot attributes)', await employer.page.content());
    const raw = await (await employer.context.request.get('/work')).text();
    scan('view-source of /work (raw response)', raw);
    scan('the /floor view', await (await employer.context.request.get('/floor')).text());
    await employer.page.goto('/search?q=logistics');
    await settle(employer.page);
    scan('search results', await employer.page.content());
    await employer.page.goto(`/u/${users.lerato.id}`);
    s.check('her profile address is a 404 for an employer', /not found|couldn.t find|404/i.test(await employer.page.locator('body').innerText()), (await employer.page.locator('body').innerText()).slice(0, 100));

    // 2. The employer invites her; she accepts from her side
    // (the Floor shows a beat once; the detail page is where an invitation is sent from afterwards)
    const talentSignal = ctx.seed.signals.find((x) => x.kind === 'talent' && x.owner_id === users.lerato.id).id;
    await employer.page.goto(`/s/${talentSignal}`);
    await settle(employer.page);
    scan('the talent detail page', await employer.page.content());
    await shot(employer.page, s, 'employer-talent-detail');
    await axe(employer.page, s, 'talent detail');
    await employer.page.getByRole('button', { name: /^Invite/ }).first().click();
    await employer.page.getByLabel('What do you want?').fill('Dispatch coordinator role, Johannesburg depot');
    await employer.page.locator('dialog[open] form button[type=submit]').click();
    await employer.page.locator('dialog[open] .fl-sheet__lead').waitFor({ timeout: 8000 });
    scan('the invite sheet and its reply', await employer.page.content());

    await pro.page.goto('/lines');
    await settle(pro.page);
    await shot(pro.page, s, 'pro-lines-invite');
    s.check('she sees the invitation with the employer named', /Kopano Logistics/.test(await pro.page.locator('main').innerText()));
    await pro.page.getByRole('button', { name: 'Accept' }).first().click();
    await pro.page.waitForTimeout(1200);
    const line = sql(ctx.out, 'select id from pulse_conversations order by id desc limit 1');
    await pro.page.goto(`/lines/${line}`);
    await settle(pro.page);
    await pro.page.locator('#ln-body').fill('Thanks. Tell me about the hours and the team.');
    await pro.page.getByRole('button', { name: 'Send' }).click();
    await pro.page.waitForTimeout(1000);
    s.check('she is told the employer sees only an anonymous card', /anonymous card/i.test(await pro.page.locator('main').innerText()));

    // 3. What the employer sees on the thread, in the notification list and by mail
    await employer.page.goto(`/lines/${line}`);
    await settle(employer.page);
    await shot(employer.page, s, 'employer-thread');
    await axe(employer.page, s, 'employer thread');
    s.check('the thread shows her only as an alias, marked anonymous', /Professional [A-Z0-9]{4}/.test(await employer.page.locator('main').innerText()) && /anonymous/i.test(await employer.page.locator('main').innerText()));
    scan('the thread page source', await employer.page.content());
    await employer.page.goto('/me/notifications');
    await settle(employer.page);
    scan('the notification list', await employer.page.content());
    const mails = mailLog(ctx.root).filter((m) => m.to.includes(users.thandi.email));
    s.note(`mails to the employer: ${mails.map((m) => m.subject).join(' | ')}`);
    scan('every email to the employer', mails.map((m) => m.raw).join('\n'));
    await employer.page.goto('/lines');
    await settle(employer.page);
    scan('the employer inbox', await employer.page.content());
    // the Livewire update calls the employer's browser made
    const all = bodies.map((b) => b.text).join('\n');
    scan(`all ${bodies.length} network responses the employer's browser received`, all);

    // 4. Consent ledger: withdraw changes behaviour
    await pro.page.goto('/me/privacy');
    await settle(pro.page);
    await shot(pro.page, s, 'pro-privacy-ledger');
    await axe(pro.page, s, 'me/privacy');
    await pro.page.locator('section:has(#pv-ledger)').getByRole('button').first().waitFor({ timeout: 8000 }).catch(() => {});
    const ledger = (await pro.page.locator('section:has(#pv-ledger)').first().textContent()) || '';
    s.check('the ledger names what she said yes to', /find you|anonymous|reveal/i.test(ledger), ledger.slice(0, 300));
    const withdraw = pro.page.locator('section:has(#pv-ledger)').getByRole('button', { name: /Stop employers/i }).first();
    s.check('a one-tap withdraw exists', await withdraw.isVisible().catch(() => false));
    if (await withdraw.isVisible().catch(() => false)) {
        await withdraw.click();
        await pro.page.waitForTimeout(1200);
        // the Floor shows each beat once, so ask the detail page whether the employer can still open the card
        await employer.page.goto(`/s/${talentSignal}`);
        s.check('the talent card address is a 404 for the employer once she withdraws', /not found|couldn.t find|404/i.test(await employer.page.locator('body').innerText()), (await employer.page.locator('body').innerText()).slice(0, 150));
        s.check('withdrawing switches discoverability off', sql(ctx.out, `select discoverability from pulse_profiles where user_id=${users.lerato.id}`) === 'off', sql(ctx.out, `select discoverability from pulse_profiles where user_id=${users.lerato.id}`));
        await employer.page.goto('/work');
        await settle(employer.page);
        s.check('and she disappears from the employer floor', !/Anonymous professional/.test(await employer.page.locator('main').innerText()));
    }

    // 5. Export end to end
    await pro.page.goto('/me/privacy');
    await settle(pro.page);
    const exportStatus = [];
    pro.page.on('response', (r) => { if (/privacy\/export/.test(r.url())) exportStatus.push(r.status()); });
    const dl = pro.page.waitForEvent('download', { timeout: 10000 }).catch(() => null);
    await pro.page.getByRole('link', { name: 'Download my data' }).click();
    const d = await dl;
    s.check('the download link delivers a file', !!d, `export responses: ${exportStatus.join(',')} url ${pro.page.url()}`);
    if (!d) { await browser.close(); return s.result(); }
    const path = await d.path();
    const json = readFileSync(path, 'utf8');
    let parsed = null;
    try { parsed = JSON.parse(json); } catch { /* reported below */ }
    s.check('export is valid JSON with her own data', !!parsed && /Logistics coordinator/.test(json), json.slice(0, 120));
    s.check('export does not contain other people\'s email addresses', !/thandi@e2e|sipho@e2e|anele@e2e/.test(json));
    s.check('export keeps her own work history', /Secretfreight/.test(json));
    s.note(`export filename: ${d.suggestedFilename()}, ${json.length} bytes, top-level keys: ${parsed ? Object.keys(parsed).join(',') : 'n/a'}`);

    // 6. Erase end to end
    await pro.page.getByRole('button', { name: 'Erase my Pulse data' }).click();
    await pro.page.locator('input[name=confirmation]').fill('erase my data');
    await pro.page.getByRole('button', { name: 'Erase and sign me out' }).click();
    await pro.page.waitForTimeout(2500);
    s.check('erase signs her out', /login|\/$/.test(new URL(pro.page.url()).pathname) || /Sign in/i.test(await pro.page.locator('body').innerText()), pro.page.url());
    const prof = sql(ctx.out, `select coalesce(headline,'') || '|' || coalesce(bio,'') || '|' || coalesce(skills,'') from pulse_profiles where user_id=${users.lerato.id}`);
    s.check('her headline, bio and skills are gone', !/Logistics|freight|dispatch/i.test(prof), prof);
    s.check('her talent signal is gone', Number(sql(ctx.out, `select count(*) from pulse_signals where owner_id=${users.lerato.id} and (status!='removed' or title!='Removed')`)) === 0);
    s.check('the other side is not left with her words', Number(sql(ctx.out, `select count(*) from pulse_messages where user_id=${users.lerato.id} and body like '%hours and the team%'`)) === 0, sql(ctx.out, `select body from pulse_messages where user_id=${users.lerato.id}`));

    // 7. Under 18
    const minor = await person(browser, ctx.base, { userId: users.minor.id, spec: s });
    await minor.page.goto('/floor');
    await settle(minor.page);
    s.check('an unconfirmed person is sent to the welcome flow, not the floor', /welcome-in/.test(minor.page.url()), minor.page.url());
    await shot(minor.page, s, 'age-gate');
    await minor.page.getByRole('button', { name: /Next|Continue|Go on/i }).first().click();
    await minor.page.waitForTimeout(800);
    s.check('the age gate does not let them through without the declaration', /welcome-in/.test(minor.page.url()) && /18/.test(await minor.page.locator('main').innerText()));
    s.check('and shows a specific error beside the field', /confirm|18 or over/i.test(await minor.page.locator('#adult-error, .pk-field__error').first().innerText().catch(() => '')), await minor.page.locator('main').innerText());
    await axe(minor.page, s, 'age gate');
    await minor.page.goto('/lines');
    s.check('other pages stay behind the gate', /welcome-in/.test(minor.page.url()), minor.page.url());
    const mp = sql(ctx.out, `select coalesce(age_confirmed_at,'none') from pulse_profiles where user_id=${users.minor.id}`);
    s.check('no age confirmation is stored for them', ['none', ''].includes(mp), `value: ${mp}`);

    s.check('no console errors', [employer, pro, minor].every((p) => p.errors.length === 0), [employer, pro, minor].flatMap((p) => p.errors).join(' | '));
    await browser.close();
    return s.result();
}
