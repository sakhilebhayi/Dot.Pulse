// Criterion 19: graceful errors. Pages in the Floor's voice with a way home; Livewire failures contained and said plainly.
import { launch, person, Spec, axe, shot, settle, artisan } from '../lib.mjs';

const home = async (page) => (await page.locator('a[href="/"], a[href$="/floor"]').count()) > 0 || (await page.getByRole('link', { name: /back to pulse|home|floor/i }).count()) > 0;

async function errorPage(s, p, label, setup, code, titleRe) {
    const status = await setup();
    const text = await p.page.locator('body').innerText();
    s.check(`${label}: status ${code}`, status === code, `got ${status}`);
    s.check(`${label}: says what happened in the Floor's voice`, titleRe.test(text), text.slice(0, 200));
    s.check(`${label}: has a way home`, await home(p.page));
    s.check(`${label}: no stack trace or framework text`, !/Illuminate|vendor\/|Stack trace|Whoops|SQLSTATE|at App\\/.test(text), text.slice(0, 200));
    await shot(p.page, s, `error-${label}`);
    await axe(p.page, s, `error ${label}`);
}

export default async function (ctx) {
    const s = new Spec('s19', ctx);
    const { users } = ctx.seed;
    const browser = await launch();
    const ig = [/status of (404|403|419|429|500|503)/, /Failed to load resource/, /ERR_INTERNET_DISCONNECTED/];
    const anele = await person(browser, ctx.base, { userId: users.anele.id, spec: s, ignoreConsole: ig });
    const anon = await person(browser, ctx.base, { spec: s, ignoreConsole: ig });

    // ---- pages
    await errorPage(s, anele, '404', async () => (await anele.page.goto('/no-such-page-b')).status(), 404, /404|not found|couldn.t find|nothing here|lost/i);
    await errorPage(s, anele, '403', async () => (await anele.page.goto('/operator')).status(), 403, /not yours|403|forbidden|role/i);
    // Pages that cannot be reached by simply navigating are fetched for real (a cross-site POST has no CSRF proof; the
    // export's fourth call in ten minutes) and the exact response is served back into the browser, with the real CSS.
    const showReal = async (p, res) => {
        const body = await res.text();
        await p.page.route('**/__real', (r) => r.fulfill({ status: res.status(), contentType: 'text/html', body }));
        const nav = await p.page.goto('/__real');
        await p.page.unroute('**/__real');
        return nav.status();
    };
    await errorPage(s, anon, '419', async () => showReal(anon, await anon.context.request.post('/login', { headers: { 'sec-fetch-site': 'cross-site', origin: 'http://evil.example' }, form: { email: 'a@b.c', password: 'x' } })), 419, /stale|expired|session/i);
    for (let i = 0; i < 3; i++) await anele.context.request.get('/me/privacy/export');
    await errorPage(s, anele, '429', async () => showReal(anele, await anele.context.request.get('/me/privacy/export')), 429, /slow down|too many|429/i);
    await errorPage(s, anele, '500', async () => (await anele.page.goto('/dev/boom-b')).status(), 500, /broke|dropped|500|wrong/i);
    artisan(ctx.root, 'down');
    await errorPage(s, anon, '503', async () => (await anon.page.goto('/')).status(), 503, /back in a moment|being updated|503/i);
    artisan(ctx.root, 'up');

    // ---- Livewire failures
    const dialogs = [];
    anele.page.on('dialog', async (d) => { dialogs.push(d.message()); await d.dismiss(); });
    const toastText = async () => (await anele.page.locator('.pk-toasts').innerText().catch(() => '')).replace(/\s+/g, ' ');
    const save = async () => { await anele.page.getByRole('button', { name: 'Save notification settings' }).click(); await anele.page.waitForTimeout(1200); };

    await anele.page.goto('/me/notifications');
    await settle(anele.page);
    // offline
    await anele.context.setOffline(true);
    await save();
    let t = await toastText();
    s.check('offline: a plain "no connection" message with what to do', /no connection/i.test(t) && /try again/i.test(t), t);
    s.check('offline: the form is still on the page, untouched', await anele.page.getByRole('button', { name: 'Save notification settings' }).isVisible());
    await shot(anele.page, s, 'livewire-offline');
    await anele.context.setOffline(false);
    await anele.page.evaluate(() => document.querySelectorAll('.pk-toast').forEach((e) => e.remove()));
    await save();
    t = await toastText();
    s.check('back online: the same action now works', /saved/i.test(t), t);

    // a server exception inside a component call: corrupt the snapshot so the server throws
    await anele.page.route('**/livewire/update', async (route) => {
        const body = route.request().postData() || '';
        await route.continue({ postData: body.replace(/"snapshot":"\{/, '"snapshot":"{X') });
    });
    await anele.page.evaluate(() => document.querySelectorAll('.pk-toast').forEach((e) => e.remove()));
    await save();
    t = await toastText();
    s.check('server exception in a component: contained message, not a raw overlay', /broke on our side|try again|gone|reload/i.test(t), t);
    s.check('no Livewire error modal iframe was opened', (await anele.page.locator('#livewire-error, iframe').count()) === 0);
    s.check('the page is still there', await anele.page.getByRole('button', { name: 'Save notification settings' }).isVisible());
    await shot(anele.page, s, 'livewire-500');
    await anele.page.unroute('**/livewire/update');

    // session expiry (real): the cookies are gone, then the person acts. The server answers with a redirect to sign-in.
    await anele.page.evaluate(() => document.querySelectorAll('.pk-toast').forEach((e) => e.remove()));
    await anele.context.clearCookies();
    await save();
    await settle(anele.page);
    s.check('session expiry: no native confirm() box', dialogs.length === 0, dialogs.join(' | '));
    s.check('session expiry: the person lands on the sign-in page, not a raw error', /login/.test(anele.page.url()) || /sign in/i.test(await anele.page.locator('body').innerText()), anele.page.url());
    await shot(anele.page, s, 'session-expired');

    // a genuine 419 answer from the server (a cross-site or stale token): stubbed at the network edge
    const stale = await person(browser, ctx.base, { userId: users.anele.id, spec: s, ignoreConsole: ig });
    const sd = [];
    stale.page.on('dialog', async (d) => { sd.push(d.message()); await d.dismiss(); });
    await stale.page.goto('/me/notifications');
    await settle(stale.page);
    await stale.page.route('**/livewire/update', (r) => r.fulfill({ status: 419, contentType: 'text/html', body: '<h1>Page Expired</h1>' }));
    await stale.page.getByRole('button', { name: 'Save notification settings' }).click();
    await stale.page.waitForTimeout(1200);
    const st = (await stale.page.locator('.pk-toasts').innerText()).replace(/\s+/g, ' ');
    s.check('419: a friendly reload prompt in the Floor voice', /session timed out/i.test(st) && /reload/i.test(st), st);
    s.check('419: no native confirm() box', sd.length === 0, sd.join(' | '));
    s.check('419: the Reload action is a real button that is keyboard reachable', (await stale.page.locator('.pk-toast__action', { hasText: 'Reload' }).count()) === 1);
    s.check('419: the form is not left locked', await stale.page.getByRole('button', { name: 'Save notification settings' }).isEnabled());
    await shot(stale.page, s, 'livewire-419');

    // ---- validation errors are specific and tied to their field
    const a2 = await person(browser, ctx.base, { userId: users.anele.id, spec: s, ignoreConsole: ig });
    await a2.page.goto('/me/notifications');
    await a2.page.locator('#quietStart').fill('22:00');
    await a2.page.getByRole('button', { name: 'Save notification settings' }).click();
    await a2.page.waitForTimeout(1000);
    const err = a2.page.locator('#quietEnd').locator('xpath=ancestor::*[contains(@class,"pk-field")][1]').locator('.pk-field__error');
    s.check('validation: the error is specific', /both quiet hours|set both/i.test(await err.innerText().catch(() => '')), await a2.page.locator('main').innerText().then((x) => x.slice(0, 80)));
    const inv = await a2.page.locator('#quietEnd').getAttribute('aria-invalid');
    const desc = await a2.page.locator('#quietEnd').getAttribute('aria-describedby');
    s.check('validation: the field is marked invalid and described by its error', inv === 'true' && !!desc, `aria-invalid=${inv} aria-describedby=${desc}`);
    await shot(a2.page, s, 'validation-quiet-hours');
    // knock sheet: empty "what"
    await a2.page.goto('/b/moyo-books');
    await settle(a2.page);
    await a2.page.getByRole('button', { name: /^(Knock|Ask about this|Message)/i }).first().click();
    await a2.page.locator('dialog[open] form button[type=submit]').click();
    await a2.page.waitForTimeout(800);
    const what = a2.page.locator('#what');
    s.check('knock sheet: empty field says what to do, tied to the field', /few words/i.test(await a2.page.locator('dialog[open]').innerText()) && (await what.getAttribute('aria-invalid')) === 'true', await a2.page.locator('dialog[open]').innerText());
    await shot(a2.page, s, 'validation-knock');
    // new business page: empty name
    await a2.page.keyboard.press('Escape');
    await a2.page.goto('/business/new');
    await settle(a2.page);
    await a2.page.getByRole('button', { name: /create|save|publish|continue/i }).first().click();
    await a2.page.waitForTimeout(800);
    s.check('business form: error beside the name field', (await a2.page.locator('.pk-field__error, [role=alert]').count()) > 0 && (await a2.page.locator('#name').getAttribute('aria-invalid').catch(() => null)) === 'true', (await a2.page.locator('main').innerText()).slice(0, 200));
    await shot(a2.page, s, 'validation-business');

    // ---- an upload that fails says why
    const a3 = await person(browser, ctx.base, { userId: users.anele.id, spec: s, ignoreConsole: ig });
    await a3.page.goto('/b/moyo-books');
    await settle(a3.page);
    await a3.page.getByRole('button', { name: /^(Knock|Ask about this)/i }).first().click();
    await a3.page.getByLabel('What do you want?').fill('Quote for monthly books');
    await a3.page.locator('dialog[open] form button[type=submit]').click();
    await a3.page.locator('dialog[open] .fl-sheet__lead').waitFor();
    const lid = (await import('../lib.mjs')).sql(ctx.out, `select id from pulse_conversations where initiator_id=${users.anele.id} order by id desc limit 1`);
    const sipho = await person(browser, ctx.base, { userId: users.sipho.id, spec: s, ignoreConsole: ig });
    await sipho.page.goto(`/lines/${lid}`);
    await sipho.page.locator('#ln-body').fill('Happy to quote. What is your monthly turnover?');
    await sipho.page.getByRole('button', { name: 'Send' }).click();
    await sipho.page.waitForTimeout(1000);
    await a3.page.goto(`/lines/${lid}`);
    await settle(a3.page);
    const file = a3.page.locator('input[type=file]');
    if (await file.count()) {
        await file.setInputFiles({ name: 'photo.png', mimeType: 'image/png', buffer: Buffer.from('this is not an image, it is text') });
        await a3.page.waitForTimeout(2500);
        const msg = await a3.page.locator('.ln-composer .pk-field__error').allInnerTexts();
        s.check('a bad upload says why', msg.some((m) => /image|jpeg|png|webp|photo|type|file/i.test(m)), `errors: ${msg.join(' | ')}`);
        await shot(a3.page, s, 'upload-failure');
    } else {
        s.check('the thread offers a photo input once the line is open', false, 'no input[type=file] (line may still be a request)');
    }

    s.check('no console errors beyond the deliberate HTTP failures', [anele, a2, a3, anon].every((p) => p.errors.length === 0), [anele, a2, a3, anon].flatMap((p) => p.errors).join(' | '));
    artisan(ctx.root, 'up');
    await browser.close();
    return s.result();
}
