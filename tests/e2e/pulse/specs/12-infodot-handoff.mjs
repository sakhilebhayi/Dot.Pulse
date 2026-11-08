// Criterion 12: InfoDot authentication. The POST /auth/ecosystem hand-off (InfoDot's launch page posting a one-time
// token) lands the person on Pulse with a working session: Livewire calls and /api/pulse/pulse-check succeed.
// InfoDot's redeem endpoint is replaced by App\Services\InfoDot\FakeIdentityClient (bound only when the runner sets
// PULSE_E2E_FAKE_INFODOT=1 under a local environment). The launch page itself is served by the test at infodot.co.za
// through request interception, so the browser sends a real cross-site POST with the Origin header InfoDot's page would.

export default {
  id: '12-infodot-handoff',
  title: 'The InfoDot hand-off lands on Pulse with a session that keeps working (Livewire calls and the polling endpoint)',
  async run(j) {
    const launch = async (page, token, { redirect, origin = 'https://infodot.co.za' } = {}) => {
      const form = `<!doctype html><meta charset=utf-8><meta name="referrer" content="origin"><title>InfoDot launch</title><body><form id="f" method="post" action="${j.base}/auth/ecosystem">
        <input type="hidden" name="token" value="${token}">${redirect ? `<input type="hidden" name="redirect" value="${redirect}">` : ''}<button type="submit">Open Pulse</button></form></body>`;
      await page.context().route(`${origin}/launch*`, (route) => route.fulfill({ contentType: 'text/html', body: form }));
      await page.goto(`${origin}/launch`);
      await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.getByRole('button', { name: 'Open Pulse' }).click()]);
      await j.settle(page);
    };
    const livewireCalls = [];
    const track = (page) => page.on('response', (r) => { if (r.url().includes('/livewire') && r.request().method() === 'POST') livewireCalls.push(r.status()); });

    // --- a person InfoDot vouches for who has no account here yet ---
    const ctx = await j.newContext();
    const page = await ctx.newPage();
    j.watch(page, 'new-person');
    track(page);
    const email = 'zinzi.newcomer@example.test';
    await launch(page, `e2e-valid:${email}:Zinzi Newcomer:company`);
    j.check('the hand-off signs her in and lands inside Pulse (the welcome flow for a first visit)', /\/welcome-in$/.test(new URL(page.url()).pathname), page.url());
    const user = j.one(`select * from users where email = ?`, email);
    j.check('an account was provisioned from InfoDot, email already confirmed', user && user.email_verified_at, JSON.stringify(user && { id: user.id, v: user.email_verified_at }));
    j.check('with a personal team and the InfoDot company synced as a team', j.value(`select count(*) from teams where user_id = ? and personal_team = 1`, user.id) === 1 && j.value(`select count(*) from teams where infodot_team_uuid = 'e2e00000-0000-4000-8000-000000000001'`) === 1);
    await j.snap(page, 'after-handoff-welcome');

    // the session works for page loads, the polling endpoint and Livewire
    const check = await page.evaluate(async () => { const r = await fetch('/api/pulse/pulse-check', { headers: { Accept: 'application/json' } }); return { status: r.status, body: await r.text() }; });
    j.check('GET /api/pulse/pulse-check answers 200 for her session (not "Unauthenticated")', check.status === 200 && !/Unauthenticated/i.test(check.body), `${check.status} ${check.body.slice(0, 120)}`);
    j.check('and returns counts', /"?(lines|notifications|requests)/i.test(check.body) || /\{/.test(check.body), check.body.slice(0, 160));

    // walk the seven-step welcome flow (every step is a Livewire call)
    const before = livewireCalls.length;
    for (let step = 0; step < 9 && new URL(page.url()).pathname === '/welcome-in'; step++) {
      await j.settle(page, 350);
      const h = (await page.locator('main h1').first().innerText()).trim();
      if (step === 0) await j.snap(page, 'welcome-flow-age', { full: false });
      if (/18 or over/.test(h)) await page.locator('main label', { hasText: 'I am 18 or over' }).click();
      if (/Where are you based/.test(h)) {
        await page.locator('#placeQuery').pressSequentially('Johannesburg', { delay: 25 });
        const hit = page.getByText('Johannesburg, Gauteng', { exact: true }).first();
        await hit.waitFor({ timeout: 6000 });
        await hit.click();
      }
      const last = page.locator('main button', { hasText: /^Finish and go to the Floor$/ });
      if (await last.count()) { await last.click(); await j.settle(page, 900); break; }
      await page.locator('main button', { hasText: /^Continue$/ }).click();
    }
    j.check('every welcome-flow Livewire call succeeded', livewireCalls.length - before >= 5 && livewireCalls.slice(before).every((s) => s === 200), `${livewireCalls.slice(before)}`);
    j.check('the flow ends: onboarded and age confirmed in the database, and she is on the Floor', j.value(`select count(*) from pulse_profiles where user_id = ? and onboarded_at is not null and age_confirmed_at is not null`, user.id) === 1 && new URL(page.url()).pathname === '/floor', page.url());
    await j.go(page, '/floor');
    j.check('the Floor loads for her', /floor/i.test(await page.title()) && (await j.text(page)).length > 200);
    await j.snap(page, 'floor-new-person');
    const after = await page.evaluate(async () => (await fetch('/api/pulse/pulse-check', { headers: { Accept: 'application/json' } })).status);
    j.check('the polling endpoint still answers 200 after onboarding', after === 200, String(after));

    // --- an existing, onboarded person: lands on the Floor; Livewire calls keep working ---
    const ctx2 = await j.newContext();
    const p2 = await ctx2.newPage();
    j.watch(p2, 'ayesha');
    const calls2 = [];
    p2.on('response', (r) => { if (r.url().includes('/livewire') && r.request().method() === 'POST') calls2.push(r.status()); });
    await launch(p2, 'e2e-valid:ayesha@demo.pulse.test:Ayesha Brightwater');
    j.check('an existing person lands on the Floor', new URL(p2.url()).pathname === '/floor', p2.url());
    j.check('and no second account was made for her', j.value(`select count(*) from users where email = 'ayesha@demo.pulse.test'`) === 1);
    const saveBtn = p2.getByRole('button', { name: /^Save/ }).first();
    if (await saveBtn.count()) {
      await saveBtn.click();
      await j.settle(p2, 700);
      j.check('a Livewire action (Save) works straight after the hand-off', calls2.length > 0 && calls2.every((s) => s === 200) && j.value(`select count(*) from pulse_saves where user_id = ?`, j.userId('ayesha')) === 1, `${calls2}`);
    } else {
      j.check('the Floor offered something to Save to prove a Livewire call', false, 'no Save button');
    }
    const lines = await p2.evaluate(async () => (await fetch('/api/pulse/pulse-check', { headers: { Accept: 'application/json' } })).status);
    j.check('pulse-check answers 200 for her too', lines === 200, String(lines));
    await j.snap(p2, 'floor-after-handoff', { full: false });

    // --- a deep link is honoured only when it is a path on this app ---
    const ctx3 = await j.newContext();
    const p3 = await ctx3.newPage();
    j.watch(p3, 'deeplink');
    await launch(p3, 'e2e-valid:rudo@demo.pulse.test:Rudo Pennyworth', { redirect: '/lines' });
    j.check('a deep link to /lines is followed', new URL(p3.url()).pathname === '/lines', p3.url());
    const ctx4 = await j.newContext();
    const p4 = await ctx4.newPage();
    j.watch(p4, 'openredirect');
    await launch(p4, 'e2e-valid:mandla@demo.pulse.test:Mandla Thorncastle', { redirect: '//evil.example/steal' });
    j.check('an open-redirect attempt lands on the Floor instead', new URL(p4.url()).host === new URL(j.base).host && new URL(p4.url()).pathname === '/floor', p4.url());

    // --- refusals: the plain login page, never a signed-in session ---
    for (const [label, token, opts] of [
      ['an unknown token', 'e2e-nope', {}],
      ['a post from another site', 'e2e-valid:attacker@example.test:Attacker', { origin: 'https://evil.example' }],
    ]) {
      const c = await j.newContext();
      const p = await c.newPage();
      j.watch(p, 'refused');
      await launch(p, token, opts);
      const who = await j.status(p, '/api/pulse/pulse-check');
      j.check(`${label}: lands on the login page with a plain message, no session`, new URL(p.url()).pathname === '/login' && [302, 401, 403].includes(who), `${p.url()} pulse-check ${who}`);
      j.check(`${label}: no account was created for the attacker`, j.value(`select count(*) from users where email = 'attacker@example.test'`) === 0);
      if (label === 'an unknown token') await j.snap(p, 'refused-login', { full: false });
    }
    const c5 = await j.newContext();
    const p5 = await c5.newPage();
    j.watch(p5, 'down');
    await launch(p5, 'e2e-down');
    j.check('when InfoDot is down she is told so on the login page', new URL(p5.url()).pathname === '/login' && /unavailable/i.test(await j.text(p5)), p5.url());
    const legacy = await p5.goto(`${j.base}/auth/ecosystem?token=e2e-valid:legacy@example.test:Legacy`);
    j.check('the old GET link with a token in the URL is never honoured', new URL(p5.url()).pathname === '/login' && j.value(`select count(*) from users where email = 'legacy@example.test'`) === 0, p5.url());
  },
};
