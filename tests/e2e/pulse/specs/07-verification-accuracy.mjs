// Criterion 7: verification status accuracy. The ladder shows only what is real, claims are labelled as claims, and
// nothing anywhere says "verified business".
export default {
  id: '07-verification-accuracy',
  title: 'The verification ladder only shows what is real; claims are labelled; nothing says "verified business"',
  async run(j) {
    const viewer = await j.as('una');
    const pages = j.db(`select id, slug, name, verification_status, registration_number, website, public_phone, infodot_business_ref from pulse_entities order by id`);

    for (const e of pages) {
      await j.go(viewer, `/b/${e.slug}`);
      const body = await j.text(viewer);
      const rungState = async (label) => {
        const li = viewer.locator('li, div', { hasText: label }).filter({ hasText: /Source:/ }).last();
        return (await li.innerText().catch(() => '')).replace(/\s+/g, ' ');
      };
      j.check(`${e.name}: never says "verified business" or "verified by InfoDot"`, !/verified business|Verified by InfoDot/i.test(body));
      const outcomes = j.value(`select count(*) from pulse_conversations where entity_id = ? and outcome != 'none' and outcome_initiator_at is not null and outcome_recipient_at is not null`, e.id);
      const regRung = await rungState('Registration number');
      j.check(`${e.name}: the registration rung matches the data (${e.registration_number ? 'claimed' : 'not given'})`, e.registration_number ? /Claimed|Not checked|not checked/i.test(regRung) : /No registration number given/.test(regRung), regRung.slice(0, 140));
      const infoRung = await rungState('InfoDot team member');
      j.check(`${e.name}: the InfoDot rung is "Checked" only when a company is linked`, e.infodot_business_ref ? /Checked/.test(infoRung) : !/Checked/.test(infoRung), infoRung.slice(0, 140));
      const resultsRung = await rungState('Confirmed results');
      j.check(`${e.name}: the confirmed-results rung matches the real count (${outcomes})`, outcomes > 0 ? new RegExp(`${outcomes} result`).test(resultsRung) : /No result confirmed by both sides yet/.test(resultsRung), resultsRung.slice(0, 140));
      const pill = e.verification_status === 'infodot_member' ? 'InfoDot team member' : e.verification_status === 'claimed' ? 'Details claimed, not verified' : 'Not verified';
      j.check(`${e.name}: the status pill says "${pill}"`, body.includes(pill));
    }
    await j.go(viewer, '/b/zephyr-hollow-plumbing');
    await j.snap(viewer, 'ladder-infodot-member');
    await j.go(viewer, '/b/granite-gull-builders');
    await j.snap(viewer, 'ladder-claimed');
    await j.go(viewer, '/b/copper-kettle-plumbing');
    await j.snap(viewer, 'ladder-unverified');

    // The same honesty on the Floor and in search (the places a customer first meets a business).
    await j.go(viewer, '/search?q=plumbing');
    const found = await j.text(viewer);
    j.check('search labels a claimed business "Not checked", an InfoDot-linked one "InfoDot member"', /Claims a registration number\. Not checked\./.test(found) && /InfoDot member/.test(found) && !/verified business/i.test(found));
    await j.snap(viewer, 'search-trust-labels', { full: false });
    await j.go(viewer, '/search?q=bakery');
    const bakery = await viewer.locator('article', { has: viewer.getByRole('heading', { name: 'Marmalade Moon Bakery', exact: true }) }).first().innerText();
    j.check('a business that only claimed a website is not said to claim a registration number', /Details claimed\. Not checked\./.test(bakery) && !/registration number/.test(bakery), bakery.replace(/\s+/g, ' ').slice(0, 200));

    // --- the owner changes what is real, and the ladder follows ---
    const rudo = await j.as('rudo');
    rudo.on('dialog', (d) => d.accept());
    await j.go(rudo, '/b/tally-fox-bookkeeping/manage/verify');
    await j.snap(rudo, 'verify-tab-linked');
    await rudo.getByRole('button', { name: 'Unlink' }).click();
    await j.settle(rudo, 700);
    const after = j.one(`select verification_status, infodot_business_ref from pulse_entities where slug = 'tally-fox-bookkeeping'`);
    j.check('unlinking the InfoDot company removes the checked rung: the status falls back to claimed', after.verification_status === 'claimed' && !after.infodot_business_ref, JSON.stringify(after));
    await j.go(viewer, '/b/tally-fox-bookkeeping');
    const t = await j.text(viewer);
    j.check('the public page now shows the claim label and no InfoDot membership', /Details claimed, not verified/.test(t) && !/InfoDot team member\s+Checked/.test(t.replace(/\n/g, ' ')));

    // A claim stays a claim: Paper Kite adds a registration number.
    const lwazi = await j.as('lwazi');
    await j.go(lwazi, '/b/paper-kite-studio/manage/verify');
    await lwazi.locator('#vf-number').fill('2018/987654/07');
    await lwazi.getByRole('button', { name: 'Save as a claim' }).click();
    await j.settle(lwazi, 700);
    await j.go(viewer, '/b/paper-kite-studio');
    const kite = await j.text(viewer);
    j.check('a typed registration number is shown as claimed and not checked', /Claimed/i.test(kite) && /Pulse has not checked it/.test(kite));
    j.check('and the status is still not "verified"', !/verified business|Verified by InfoDot/i.test(kite) && j.value(`select verification_status from pulse_entities where slug = 'paper-kite-studio'`) === 'claimed');
    await j.snap(viewer, 'claim-added-still-claimed');

    // The only path to a verified state is InfoDot sending it: there is no control for a business to mark itself verified.
    await j.go(lwazi, '/b/paper-kite-studio/manage/verify');
    const verifyTab = await j.text(lwazi);
    j.check('the owner has no way to mark the page verified, and the tab explains what InfoDot does and does not confirm', !/Mark as verified|Verify me|Get verified/i.test(verifyTab) && /What InfoDot confirms, and what it does not/.test(verifyTab));
  },
};
