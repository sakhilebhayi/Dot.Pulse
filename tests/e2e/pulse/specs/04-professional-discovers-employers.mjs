// Criterion 4: a professional sets discoverability to anonymous, sees the preview, sees employers and roles on the
// Floor with reasons, applies to a role, and reveals who they are on their own terms.
import { beat, knock } from '../lib/ui.mjs';

export default {
  id: '04-professional-discovers-employers',
  title: 'A professional goes anonymous, sees employers and roles with reasons, applies, and reveals identity on their own terms',
  async run(j) {
    const tendai = await j.as('tendai');
    const tid = j.userId('tendai');
    const wildfig = j.entityId('Wildfig Talent Works');

    // --- discoverability: anonymous, with a preview of exactly what employers see ---
    await j.go(tendai, '/me/discoverability');
    j.check('she starts open (by name)', j.value(`select discoverability from pulse_profiles where user_id = ?`, tid) === 'open');
    await tendai.locator('label', { hasText: 'Anonymously' }).first().click();
    await j.settle(tendai, 600);
    j.check('the choice is stored as anonymous', j.value(`select discoverability from pulse_profiles where user_id = ?`, tid) === 'anonymous');
    const prev = await j.text(tendai);
    j.check('the preview shows the headline, skills and region an employer would see', /Bookkeeper and payroll practitioner/.test(prev) && /bookkeeping/i.test(prev));
    const previewBox = tendai.locator('section, div').filter({ has: tendai.getByRole('heading', { name: 'What an employer sees in anonymous mode' }) }).last();
    const previewText = await previewBox.innerText();
    j.check('the preview does not contain her name or email', !/Tendai|Ashcombe|@demo\.pulse\.test/.test(previewText), previewText.slice(0, 200));
    await j.snap(tendai, 'discoverability-anonymous-preview');

    // The employer's side of the same fact: the talent signal is anonymous and carries no name.
    const sig = j.one(`select * from pulse_signals where kind = 'talent' and owner_id = ?`, tid);
    j.check('her talent signal is flagged anonymous', sig && Number(sig.anonymous) === 1, JSON.stringify(sig && { anonymous: sig.anonymous }));
    j.check('and its public text never contains her name', sig && !/Tendai|Ashcombe/.test(`${sig.title} ${sig.excerpt}`), sig && sig.title);

    // --- the Floor shows employers and roles with reasons ---
    j.tick();
    await j.go(tendai, '/floor');
    const floor = await j.text(tendai);
    j.check('the Floor shows the employer with a skills reason (passive career discovery)', /Wildfig Talent Works/.test(floor) && /building a team and want your skills: bookkeeping/i.test(floor));
    const roleCard = beat(tendai, 'Junior bookkeeper');
    j.check('the matching role is shown with its reason', (await roleCard.count()) > 0 && /Your skills fit this role/.test(await roleCard.innerText()));
    await j.snap(tendai, 'floor-employers-and-roles');
    // The reasons on screen are exactly the stored match reasons.
    const stored = j.one(`select m.reasons from pulse_matches m join pulse_signals s on s.id = m.signal_id where m.user_id = ? and s.kind = 'role' and s.title = 'Junior bookkeeper'`, tid);
    const codes = JSON.parse(stored.reasons).map((r) => r.text);
    j.check('every reason on the card is a stored match reason', codes.every((t) => floor.includes(t)), codes.join(' | '));

    // --- apply to a role ---
    await j.go(tendai, '/work');
    const apply = beat(tendai, 'Junior bookkeeper').getByRole('button', { name: 'Apply' });
    await knock(j, tendai, apply, { what: 'Bookkeeper with six years of month-end and VAT, available from December' });
    const app = await j.until(() => j.one(`select * from pulse_conversations where kind = 'application' and initiator_id = ?`, tid), 'the application was stored');
    if (!app) return;
    const roleId = j.value(`select id from pulse_roles where entity_id = ? and title = 'Junior bookkeeper'`, wildfig);
    j.check('the application carries the role as its context', app.context_type?.toLowerCase().includes('role') && Number(app.context_id) === Number(roleId), `${app.context_type}/${app.context_id}/${roleId}`);
    j.check('it goes to the employer page', Number(app.entity_id) === Number(wildfig));

    // --- the employer answers it ---
    const ayesha = await j.as('ayesha');
    await j.go(ayesha, '/lines');
    const inbox = await j.text(ayesha);
    j.check('the employer sees the application against the role', /Junior bookkeeper/.test(inbox));
    await j.snap(ayesha, 'employer-inbox-application');

    // --- an invitation to someone anonymous; she chooses whether to say who she is ---
    await j.go(ayesha, '/work');
    const talent = ayesha.locator('article', { hasText: 'Anonymous professional' }).first();
    j.check('the employer sees her only as an anonymous professional', (await talent.count()) > 0 && !/Tendai|Ashcombe/.test(await j.text(ayesha)));
    if (!(await talent.count())) return;
    await knock(j, ayesha, talent.getByRole('button', { name: 'Invite' }), { what: 'We would like to talk to you about our finance team', where: 'Johannesburg, hybrid' });
    const invite = await j.until(() => j.one(`select * from pulse_conversations where kind = 'invite' and recipient_id = ?`, tid), 'the invitation reached her');
    if (!invite) return;

    await j.go(tendai, '/lines');
    await tendai.getByRole('button', { name: /^Requests/ }).click();
    await j.settle(tendai);
    const reqText = await j.text(tendai);
    j.check('she sees an invitation from the employer page, with the context', /Wildfig Talent Works/.test(reqText));
    await j.snap(tendai, 'professional-inbox-invitation');
    await tendai.locator(`button[wire\\:click="accept(${invite.id})"]`).click();
    await j.settle(tendai, 600);
    j.check('accepting opens the line but she is still anonymous', j.one(`select status, identity_hidden from pulse_conversations where id = ?`, invite.id).status === 'open' && Number(j.value(`select identity_hidden from pulse_conversations where id = ?`, invite.id)) === 1);

    await j.go(ayesha, `/lines/${invite.id}`);
    j.check('before she reveals, the employer cannot see her name', !/Tendai|Ashcombe/.test(await j.text(ayesha)));
    await j.snap(ayesha, 'employer-thread-anonymous', { full: false });

    await j.go(tendai, `/lines/${invite.id}`);
    await tendai.getByRole('button', { name: 'Show who I am' }).first().click();
    await j.settle(tendai, 400);
    await j.snap(tendai, 'professional-reveal-confirm', { full: false });
    await tendai.getByRole('button', { name: 'Show who I am' }).last().click();
    await j.settle(tendai, 600);
    j.check('revealing is stored', Number(j.value(`select identity_hidden from pulse_conversations where id = ?`, invite.id)) === 0);

    await j.go(ayesha, `/lines/${invite.id}`);
    const after = await j.text(ayesha);
    j.check('after she chooses to reveal, the employer sees her name', /Tendai Ashcombe/.test(after));
    j.check('the reveal was her own act and is on the record', j.value(`select count(*) from pulse_messages where pulse_conversation_id = ? and body like '%revealed who they are%'`, invite.id) === 1);
    await j.snap(ayesha, 'employer-thread-revealed', { full: false });
  },
};
