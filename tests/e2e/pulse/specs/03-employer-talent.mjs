// Criterion 3: an employer presents the organisation, sets a hiring signal, publishes a role, sees the talent
// cohort rule (the count is hidden under 5) and invites an anonymous professional.
import { beat, knock } from '../lib/ui.mjs';

export default {
  id: '03-employer-talent',
  title: 'An employer presents the organisation, sets a hiring signal, publishes a role, meets the cohort rule and invites an anonymous professional',
  async run(j) {
    const ayesha = await j.as('ayesha');
    const slug = 'wildfig-talent-works';
    const entity = j.one(`select * from pulse_entities where slug = ?`, slug);

    // --- present the organisation ---
    await j.go(ayesha, `/b/${slug}/manage/page`);
    await ayesha.locator('#tagline').fill('A small finance team that lets you leave on time, and learn on the job.');
    await ayesha.locator('#culture_values').fill('Calm, Curious, Kind');
    await ayesha.locator('#culture_perks').fill('Hybrid work, Study leave, Birthday off');
    await j.snap(ayesha, 'employer-page-editor');
    await ayesha.getByRole('button', { name: 'Save changes' }).click();
    await j.settle(ayesha, 600);
    j.check('the new tagline is stored', j.value(`select tagline from pulse_entities where id = ?`, entity.id).includes('learn on the job'));
    const visitor = await j.as('ibrahim');
    await j.go(visitor, `/b/${slug}`);
    const pub = await j.text(visitor);
    j.check('the public page presents the culture and what they offer people', pub.includes('Study leave') && pub.includes('Calm'));
    j.check('the public page shows the hiring signal honestly', /hiring|building a team|meet/i.test(pub));
    await j.snap(visitor, 'employer-public-page');

    // --- the cohort rule: hidden under 5, a band at 5 ---
    await j.go(ayesha, `/b/${slug}/manage/roles`);
    const before = await j.text(ayesha);
    j.check('with fewer than 5 matching professionals no headcount is shown', !/professionals match/i.test(before));
    j.check('and the page explains that a count appears only at 5 or more', /at least 5/i.test(before));
    await j.snap(ayesha, 'hiring-signal-cohort-hidden');

    // Skill list stays "bookkeeping, payroll"; Tendai (open) is the one real match so far. Add three more: four is still hidden.
    j.addPeople('cohort', 3, { skills: ['bookkeeping', 'excel'], disc: 'anonymous', headline: 'Bookkeeper with VAT experience' });
    await j.go(ayesha, `/b/${slug}/manage/roles`);
    j.check('four matching professionals is still hidden', !/professionals match/i.test(await j.text(ayesha)));
    j.addPeople('cohortb', 1, { skills: ['bookkeeping'], disc: 'anonymous', headline: 'Bookkeeper, month-end specialist' });
    await j.go(ayesha, `/b/${slug}/manage/roles`);
    const five = await j.text(ayesha);
    j.check('at five the page shows a band, "5 to 9", never an exact number', /5 to 9 professionals match/.test(five), (five.match(/\d+[^.]{0,20}professionals match/) || ['none'])[0]);
    j.check('nobody is named', !/Extra cohort/.test(five));
    await j.snap(ayesha, 'hiring-signal-cohort-band');

    // --- the hiring signal ---
    await ayesha.locator('#hs-signal').selectOption('building');
    await ayesha.locator('#hs-skills').fill('bookkeeping, payroll, vat returns');
    await ayesha.getByRole('button', { name: 'Save signal' }).click();
    await j.settle(ayesha, 600);
    const saved = j.one(`select hiring_signal, hiring_skills, hiring_signal_until from pulse_entities where id = ?`, entity.id);
    j.check('the signal is stored as building with its skills and a lapse date', saved.hiring_signal === 'building' && /vat/.test(saved.hiring_skills) && saved.hiring_signal_until, JSON.stringify(saved));

    // --- publish a role ---
    await ayesha.getByRole('button', { name: 'New role' }).click();
    await j.settle(ayesha);
    await ayesha.locator('#role-title').fill('Credit controller');
    await ayesha.locator('#role-summary').fill('Chase invoices politely and keep the debtors book honest.');
    await ayesha.locator('#role-description').fill('Part of the finance team. You will contact clients, record promises to pay and report weekly.');
    await ayesha.locator('#role-skills').fill('bookkeeping, credit control');
    await ayesha.locator('#role-pay').fill('R22,000 to R28,000');
    await ayesha.locator('#role-seniority').selectOption('mid');
    await j.snap(ayesha, 'new-role-form');
    await ayesha.getByRole('button', { name: 'Save and publish' }).click();
    await j.settle(ayesha, 600);
    const role = await j.until(() => j.one(`select * from pulse_roles where entity_id = ? and title = 'Credit controller'`, entity.id), 'the role was saved');
    if (!role) return;
    j.check('the role is live', role.status === 'live' && role.moderation === 'approved', `${role.status}/${role.moderation}`);
    await j.go(visitor, `/b/${slug}/r/${role.slug}`);
    j.check('the role page is visible to professionals', (await visitor.getByRole('heading', { name: 'Credit controller' }).count()) > 0);

    // --- invite an anonymous professional ---
    j.tick();
    await j.go(ayesha, '/work');
    const talentBeats = ayesha.locator('article', { hasText: 'Anonymous professional' });
    const n = await talentBeats.count();
    j.check('the Work floor shows anonymous professionals with skills and a region, no names', n >= 1 && !/Extra cohort/.test(await j.text(ayesha)), `${n} talent beats`);
    await j.snap(ayesha, 'work-floor-talent');
    if (n === 0) return;
    const first = talentBeats.first();
    const reasonText = (await first.innerText()).replace(/\s+/g, ' ');
    j.check('each talent beat says why (skills in common with the hiring signal)', /skills you are hiring for|hiring/i.test(reasonText), reasonText.slice(0, 160));
    await knock(j, ayesha, first.getByRole('button', { name: 'Invite' }), {
      what: 'We are building a finance team and would like to talk about a bookkeeping role',
      when: 'Next two weeks',
      where: 'Johannesburg, hybrid',
    });
    const invite = await j.until(() => j.one(`select * from pulse_conversations where kind = 'invite' and initiator_id = ?`, j.userId('ayesha')), 'the invitation was stored');
    if (!invite) return;
    j.check('the invitation is sent as the employer page and identity stays hidden', Number(invite.initiator_entity_id) === Number(entity.id) && Number(invite.identity_hidden) === 1, `${invite.initiator_entity_id}/${invite.identity_hidden}`);
    j.check('it waits as a request for the professional to answer', invite.status === 'requested', invite.status);
    const body = await j.text(ayesha);
    j.check('the employer is never shown who the professional is', !/Extra cohort|@demo\.pulse\.test/.test(body));
    await j.go(ayesha, '/lines');
    await ayesha.getByRole('button', { name: /^Sent/ }).click();
    await j.settle(ayesha);
    const sent = await j.text(ayesha);
    j.check('the sent line names the professional only as anonymous', /To Professional [0-9A-Z]{4}/.test(sent) && !/Extra cohort/.test(sent));
    await j.snap(ayesha, 'invite-sent-lines');
  },
};
