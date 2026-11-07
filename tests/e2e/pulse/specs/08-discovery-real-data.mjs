// Criterion 8: discovery is based on real data. The reasons drawn on the Floor are the stored match reasons, taking a
// reason away changes what the person is shown, changing the data changes the results, and someone unrelated is not
// shown the item (and cannot open it by address either).
const cards = (page) => page.evaluate(() => [...document.querySelectorAll('article.pk-beat')].map((a) => ({
  title: a.querySelector('.pk-beat__title')?.textContent.trim(),
  reasons: [...a.querySelectorAll('.pk-meter__reason > span:not(.pk-meter__tick)')].map((s) => s.textContent.trim()),
})));

export default {
  id: '08-discovery-real-data',
  title: 'The reasons shown are the real match reasons; removing one changes results; an unrelated person is not shown the item',
  async run(j) {
    const stored = (uid, title) => {
      const row = j.one(`select m.reasons from pulse_matches m join pulse_signals s on s.id = m.signal_id where m.user_id = ? and s.title = ?`, uid, title);
      return row ? JSON.parse(row.reasons).map((r) => r.text) : null;
    };

    // --- 1. what is drawn is what is stored, for three different people ---
    for (const who of ['naledi', 'sandile', 'tendai']) {
      const p = await j.as(who);
      await j.go(p, '/floor');
      const shown = await cards(p);
      const uid = j.userId(who);
      let compared = 0;
      let bad = [];
      for (const c of shown) {
        const real = stored(uid, c.title);
        if (!real) { bad.push(`${c.title}: no stored match`); continue; }
        compared++;
        const same = c.reasons.length === real.length && c.reasons.every((r) => real.includes(r));
        if (!same) bad.push(`${c.title}: drawn ${JSON.stringify(c.reasons)} stored ${JSON.stringify(real)}`);
      }
      j.check(`${who}: every card on the Floor has a stored match, and its drawn reasons equal the stored reasons (${compared} cards)`, shown.length > 0 && bad.length === 0, bad.slice(0, 2).join(' || '));
      if (who === 'naledi') await j.snap(p, 'floor-reasons-naledi', { full: false });
    }

    // --- 2. the unrelated person is not shown it, and cannot open it ---
    const una = await j.as('una');
    const need = j.one(`select id from pulse_needs where title = 'Burst geyser needs replacing this week'`);
    const needSignal = j.one(`select id from pulse_signals where kind = 'need' and subject_id = ?`, need.id);
    await j.go(una, '/floor');
    const unaCards = await cards(una);
    j.check('the unrelated person has no plumbing card on her Floor', !unaCards.some((c) => /geyser|plumb|drain/i.test(c.title)), unaCards.map((c) => c.title).join(' | '));
    j.check('and has no stored match for the plumbing need', j.value(`select count(*) from pulse_matches where user_id = ? and signal_id = ?`, j.userId('una'), needSignal.id) === 0);
    j.check('opening the matched-only need by address is a 404 for her', await j.status(una, `/need/${need.id}`) === 404);
    j.check('and so is its signal page', await j.status(una, `/s/${needSignal.id}`) === 404);
    await j.snap(una, 'unrelated-floor', { full: false });
    const naledi = await j.as('naledi');
    await j.go(una, '/search?q=bookkeeping');
    j.check('and search does not show her a need that was matched only to others', !(await j.text(una)).includes('Bookkeeper for a small shop'));
    j.check('the person it does fit can open the same need', await j.status(naledi, `/need/${need.id}`) === 200);

    // --- 3. removing a reason changes what is shown ---
    const sandile = await j.as('sandile');
    const sid = j.userId('sandile');
    const withNeedOffer = () => j.db(`select s.title, m.reasons from pulse_matches m join pulse_signals s on s.id = m.signal_id where m.user_id = ? and m.dismissed_at is null and m.reasons like '%need_offer%'`, sid).length;
    const before = withNeedOffer();
    await j.go(sandile, '/floor');
    const beforeCards = await cards(sandile);
    j.check('before: Sandile sees plumbing offers with the reason "You need plumbing, they offer plumbing"', beforeCards.some((c) => c.reasons.some((r) => /You need plumbing/.test(r))) && before > 0, `${before} stored`);
    await sandile.getByRole('button', { name: /Stop using this reason: You need plumbing, they offer plumbing/ }).first().click();
    await j.settle(sandile, 900);
    j.note('cards before click: ' + JSON.stringify(beforeCards.map((c) => c.title)));
    const mute = j.db(`select * from pulse_mutes where user_id = ?`, sid);
    j.check('removing the reason stores a mute for that input (plumbing), for her only', mute.length >= 1 && mute.some((m) => /plumbing/.test(JSON.stringify(m))), JSON.stringify(mute));
    const afterCards = await cards(sandile);
    j.check('after: no card on her Floor still uses the plumbing reason', !afterCards.some((c) => c.reasons.some((r) => /You need plumbing/.test(r))), JSON.stringify(afterCards.map((c) => c.reasons)).slice(0, 200));
    // Matches already seen and off screen are left to the ten-minute matching run (by design); on screen they are redrawn at once.
    await j.snap(sandile, 'floor-after-removing-reason', { full: false });
    j.tick();
    j.check('after the next matching run no live stored match carries the removed reason, and it does not come back', withNeedOffer() === 0, `${withNeedOffer()} still live`);
    await j.go(sandile, '/floor');
    j.check('and a fresh Floor load agrees', !(await cards(sandile)).some((c) => c.reasons.some((r) => /You need plumbing/.test(r))));
    j.check('the mute did not touch anyone else: Naledi keeps her reasons', j.value(`select count(*) from pulse_mutes where user_id = ?`, j.userId('naledi')) === 0);

    // --- 4. changing the data changes the results ---
    const needMatchesBefore = j.value(`select count(*) from pulse_matches where signal_id = ?`, needSignal.id);
    j.check('the geyser need is matched to the plumbers who fit it', needMatchesBefore >= 1, String(needMatchesBefore));
    sandile.on('dialog', (d) => d.accept());
    await j.go(sandile, `/need/${need.id}`);
    await sandile.getByRole('button', { name: 'Close it' }).click();
    await j.settle(sandile, 900);
    j.check('closing the need stores it as closed', j.value(`select status from pulse_needs where id = ?`, need.id) === 'closed');
    j.check('its signal is no longer live', j.value(`select status from pulse_signals where id = ?`, needSignal.id) !== 'live');
    await j.go(naledi, '/search?q=geyser');
    j.check('the plumber no longer finds the closed need in search', !(await j.text(naledi)).includes('Burst geyser needs replacing this week'));
    j.tick();
    j.check('and, matching run or not, the plumber can no longer open it (a 404, not a stale page)', await j.status(naledi, `/need/${need.id}`) === 404 && await j.status(naledi, `/s/${needSignal.id}`) === 404);
  },
};
