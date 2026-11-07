// Criterion 6: control over profile visibility and discovery. The person changes discoverability (off, anonymous,
// open) and their contact policy, and a second browser context (the other party) sees the effect.
export default {
  id: '06-visibility-and-contact-control',
  title: 'Changing discoverability and contact policy changes what the other side can see and do',
  async run(j) {
    const palesa = await j.as('palesa');
    const mandla = await j.as('mandla'); // an employer hiring logistics people
    const pid = j.userId('palesa');
    const setMode = async (label) => {
      await j.go(palesa, '/me/discoverability');
      await palesa.locator('label', { hasText: label }).first().click();
      await j.settle(palesa, 700);
    };
    const talentSignal = () => j.one(`select id, status, anonymous from pulse_signals where kind = 'talent' and owner_id = ?`, pid);
    // First look: the Floor's Work mode. After that the Floor hides what the employer has already been shown this
    // round (by design), so later looks use search, which has no such memory.
    const employerSees = async (viaSearch = true) => { await j.go(mandla, viaSearch ? '/search?q=dispatch+route+planner' : '/work'); return j.text(mandla); };

    // anonymous (the seeded state): the employer sees the skills, not the name
    j.tick();
    let work = await employerSees(false);
    j.check('anonymous: the employer sees the professional by skills', /Dispatch and route planner/.test(work));
    j.check('anonymous: and never the name', !/Palesa|Whitlock/.test(work));
    await j.snap(mandla, 'employer-sees-anonymous-talent', { full: false });

    // off
    await setMode('No one');
    j.check('off: the stored mode is off', j.value(`select discoverability from pulse_profiles where user_id = ?`, pid) === 'off');
    const offSig = talentSignal();
    j.check('off: the talent signal is no longer live', !offSig || offSig.status !== 'live', JSON.stringify(offSig));
    work = await employerSees();
    j.check('off: the employer cannot see her any more, with no matching run in between', !/Dispatch and route planner/.test(work));
    if (offSig) j.check('off: her old signal page is a 404 for the employer', await j.status(mandla, `/s/${offSig.id}`) === 404);
    await j.snap(mandla, 'employer-after-off', { full: false });

    // open
    await setMode('By name');
    j.check('open: the stored mode is open', j.value(`select discoverability from pulse_profiles where user_id = ?`, pid) === 'open');
    j.tick();
    work = await employerSees();
    j.check('open: the employer sees her with her name', /Palesa Whitlock/.test(work));
    await j.snap(mandla, 'employer-sees-open-talent', { full: false });

    // back to anonymous: the name disappears again
    await setMode('Anonymously');
    j.tick();
    work = await employerSees();
    j.check('back to anonymous: the name is gone and the skills remain', !/Palesa|Whitlock/.test(work) && /Dispatch and route planner/.test(work));

    // --- contact policy of a person with an open door (Tendai), seen from two strangers ---
    const tendai = await j.as('tendai');
    const una = await j.as('una');
    const ibrahim = await j.as('ibrahim');
    const tid = j.userId('tendai');
    const door = j.one(`select s.id as sid, d.id as did from pulse_signals s join pulse_doors d on d.signal_id = s.id where d.owner_id = ? and d.status = 'open'`, tid);
    const setPolicy = async (value) => {
      await j.go(tendai, '/me/settings');
      await tendai.locator(`label:has(input[name=contactPolicy][value="${value}"])`).click();
      await j.settle(tendai, 700);
    };
    const knockDoor = async (who, name) => {
      await j.go(who, `/s/${door.sid}`);
      await who.getByRole('button', { name: 'Knock' }).first().click();
      const dialog = who.getByRole('dialog').first();
      await dialog.waitFor();
      await j.settle(who, 400);
      return dialog;
    };

    await setPolicy('nobody');
    j.check('nobody: stored', j.value(`select contact_policy from pulse_profiles where user_id = ?`, tid) === 'nobody');
    let dialog = await knockDoor(una);
    const refusal = await dialog.innerText();
    j.check('nobody: a stranger is told, in words, that they are not taking messages', /not taking new messages/i.test(refusal), refusal.slice(0, 160));
    await j.snap(una, 'knock-refused-nobody', { full: false });
    j.check('nobody: no line was created', j.value(`select count(*) from pulse_conversations where recipient_id = ?`, tid) === 0);

    await setPolicy('anyone');
    dialog = await knockDoor(ibrahim);
    await dialog.locator('#what').fill('Could you look at my month-end checklist?');
    await dialog.locator('button[type=submit]').click();
    await j.settle(ibrahim, 700);
    const direct = j.one(`select status from pulse_conversations where recipient_id = ? and initiator_id = ?`, tid, j.userId('ibrahim'));
    j.check('anyone, directly: the line opens straight away', direct?.status === 'open', JSON.stringify(direct));

    await setPolicy('requests');
    dialog = await knockDoor(una);
    await dialog.locator('#what').fill('Do you have capacity for a small shop next month?');
    await dialog.locator('button[type=submit]').click();
    await j.settle(una, 700);
    const req = j.one(`select status from pulse_conversations where recipient_id = ? and initiator_id = ?`, tid, j.userId('una'));
    j.check('anyone, as a request: the line waits for her answer', req?.status === 'requested', JSON.stringify(req));
    await j.go(tendai, '/lines');
    j.check('and she sees the request in her own inbox', /Do you have capacity for a small shop/.test(await j.text(tendai)));

    // --- a business page's contact policy ---
    const kagiso = await j.as('kagiso');
    const marmalade = j.entityId('Marmalade Moon Bakery');
    await j.go(kagiso, '/b/marmalade-moon-bakery/manage/page');
    await kagiso.locator('#contact_policy').selectOption('nobody');
    await kagiso.getByRole('button', { name: 'Save changes' }).click();
    await j.settle(kagiso, 700);
    j.check('page policy nobody: stored', j.value(`select contact_policy from pulse_entities where id = ?`, marmalade) === 'nobody');
    const lwazi = await j.as('lwazi');
    await j.go(lwazi, '/b/marmalade-moon-bakery');
    await lwazi.getByRole('button', { name: 'Knock' }).first().click();
    await j.settle(lwazi, 400);
    j.check('page policy nobody: the knock sheet says so', /not taking new messages/i.test(await lwazi.getByRole('dialog').first().innerText()));
    await j.snap(lwazi, 'business-knock-refused', { full: false });
  },
};
