// Criterion 5: contextual conversations. A need carries its context into three providers' lines, each answers on a
// quote slip, the person compares and accepts one, the two talk, both confirm the outcome, and the blind reviews are
// revealed together.
import { send } from '../lib/ui.mjs';

const day = (n) => new Date(Date.now() + n * 864e5).toISOString().slice(0, 10);

export default {
  id: '05-contextual-conversations',
  title: 'A need becomes three quote slips, a conversation with context, a both-confirmed outcome and a revealed blind review',
  async run(j) {
    const sandile = await j.as('sandile');
    const sid = j.userId('sandile');

    // --- the need ---
    await j.go(sandile, '/need/new');
    await sandile.locator('#need-title').fill('Replace a kitchen tap and fix a dripping basin');
    await sandile.locator('#need-details').fill('Old mixer tap in a rented flat in Johannesburg. The basin trap also drips.');
    await sandile.locator('#need-tag').fill('plumbing');
    await j.settle(sandile);
    const tagOption = sandile.getByRole('option', { name: /^Plumbing/ }).first();
    if (await tagOption.count()) await tagOption.click();
    await sandile.locator('#need-budget').fill('R800 to R2,000');
    await j.snap(sandile, 'need-form-filled');
    await sandile.getByRole('button', { name: 'Post it' }).click();
    await j.settle(sandile, 600);
    const need = await j.until(() => j.one(`select * from pulse_needs where user_id = ? and title like 'Replace a kitchen tap%'`, sid), 'the need was saved');
    if (!need) return;
    j.check('the need is open', need.status === 'open', need.status);

    // --- ask three businesses for quotes, from the need ---
    await j.go(sandile, `/need/${need.id}`);
    await sandile.getByRole('button', { name: 'Ask for quotes' }).click();
    await j.settle(sandile);
    const names = ['Zephyr Hollow Plumbing', 'Granite & Gull Builders', 'Copper Kettle Plumbing'];
    const box = (name) => sandile.locator('label.ln-pick__row', { hasText: name }).locator('input[type=checkbox]');
    for (const n of names) await box(n).check();
    await j.settle(sandile, 400);
    const others = sandile.locator('label.ln-pick__row:not(.is-on) input[type=checkbox]');
    const nOthers = await others.count();
    j.check('once three are picked the other businesses cannot be ticked (cap of three)', nOthers > 0 && (await others.first().isDisabled()), `${nOthers} others`);
    await j.snap(sandile, 'quote-request-three-picked', { full: false });
    await sandile.getByRole('button', { name: 'Ask 3 businesses' }).click();
    await j.settle(sandile, 800);
    const lines = j.db(`select * from pulse_conversations where initiator_id = ? and kind = 'quote' and context_id = ?`, sid, need.id);
    j.check('three lines share one quote group, each carrying the need as context', lines.length === 3 && new Set(lines.map((l) => l.quote_group)).size === 1 && lines.every((l) => l.context_type?.toLowerCase().includes('need')), `${lines.length} lines`);
    j.check('they wait as requests for the businesses to answer', lines.every((l) => l.status === 'requested'));
    if (lines.length !== 3) return;
    const group = lines[0].quote_group;

    // --- each provider answers on a slip ---
    const slips = [
      { who: 'naledi', page: 'Zephyr Hollow Plumbing', amount: '1450', max: '1800', inc: 'Supply and fit a new mixer tap and replace the basin trap.', exc: 'The tap itself if you prefer a premium brand.' },
      { who: 'gideon', page: 'Granite & Gull Builders', amount: '1900', max: '', inc: 'Tap, trap, labour and a compliance check.', exc: 'Any tiling repairs.' },
      { who: 'thuli', page: 'Copper Kettle Plumbing', amount: '1250', max: '', inc: 'Standard mixer tap, trap and labour.', exc: 'Call-out outside Johannesburg.' },
    ];
    const pages = {};
    for (const s of slips) {
      const eid = j.entityId(s.page);
      const line = lines.find((l) => Number(l.entity_id) === Number(eid));
      const p = await j.as(s.who);
      pages[s.who] = { p, line };
      await j.go(p, '/lines');
      j.check(`${s.page} sees the request with the need as its context`, /Replace a kitchen tap/.test(await j.text(p)));
      await j.go(p, `/lines/${line.id}`);
      const t = await j.text(p);
      j.check(`${s.page}'s thread shows the request card and the need`, /Replace a kitchen tap/.test(t) && /Johannesburg/.test(t));
      await p.locator('#slip-amount').fill(s.amount);
      if (s.max) await p.locator('#slip-amount-max').fill(s.max);
      await p.locator('#slip-earliest').fill(day(2));
      await p.locator('#slip-included').fill(s.inc);
      await p.locator('#slip-excluded').fill(s.exc);
      await p.locator('#slip-valid').fill(day(14));
      if (s.who === 'naledi') await j.snap(p, 'provider-slip-form-filled', { full: false });
      await p.getByRole('button', { name: 'Send the quote' }).click();
      await j.settle(p, 700);
      const q = j.one(`select * from pulse_quotes where pulse_conversation_id = ?`, line.id);
      j.check(`${s.page}'s slip is stored and the line opened`, q && Number(q.amount_cents) === Number(s.amount) * 100 && j.value(`select status from pulse_conversations where id = ?`, line.id) === 'open', JSON.stringify(q));
    }

    // --- compare and accept one ---
    await j.go(sandile, `/lines/quotes/${group}`);
    const cmp = await j.text(sandile);
    j.check('the comparison shows all three slips side by side with prices and what is not included', names.every((n) => cmp.includes(n)) && /R1\s?250/.test(cmp) && /R1\s?450/.test(cmp) && /R1\s?900/.test(cmp) && /Not included/.test(cmp));
    await j.snap(sandile, 'quote-comparison');
    const copperQuote = j.value(`select id from pulse_quotes where pulse_conversation_id = ?`, pages.thuli.line.id);
    await sandile.locator(`button[wire\\:click="acceptQuote(${copperQuote})"]`).click();
    await j.settle(sandile, 800);
    j.check('accepting stores the slip as accepted', j.value(`select status from pulse_quotes where id = ?`, copperQuote) === 'accepted');
    const siblingStates = j.db(`select status from pulse_quotes where id != ? and pulse_conversation_id in (${lines.map((l) => l.id).join(',')})`, copperQuote).map((r) => r.status);
    j.check('the other slips are no longer open for acceptance', siblingStates.every((s) => s !== 'accepted'), siblingStates.join(','));
    await j.snap(sandile, 'quote-accepted');

    // --- the conversation with context ---
    const line = pages.thuli.line;
    await j.go(sandile, `/lines/${line.id}`);
    await send(j, sandile, 'Thanks, Copper Kettle. Thursday morning works if you can bring the tap.');
    const thuli = pages.thuli.p;
    await j.go(thuli, `/lines/${line.id}`);
    j.check('the provider sees the message under the need as context', /Thursday morning works/.test(await j.text(thuli)) && /Replace a kitchen tap/.test(await j.text(thuli)));
    await send(j, thuli, 'Thursday at 9 works. I will bring a standard mixer and the trap.');
    await j.go(sandile, `/lines/${line.id}`);
    j.check('the person sees the answer', /Thursday at 9 works/.test(await j.text(sandile)));
    await j.snap(sandile, 'conversation-with-context', { full: false });

    // --- the outcome needs both sides ---
    const confirmed = () => j.value(`select count(*) from pulse_conversations where id = ? and outcome != 'none' and outcome_initiator_at is not null and outcome_recipient_at is not null`, line.id);
    await j.go(thuli, `/lines/${line.id}`);
    await thuli.getByRole('button', { name: 'Won' }).click();
    await j.settle(thuli, 700);
    j.check('one side marking it done is not yet a result', confirmed() === 0 && j.value(`select outcome from pulse_conversations where id = ?`, line.id) === 'won');
    await j.go(sandile, '/b/copper-kettle-plumbing');
    j.check('so the business page does not yet show a confirmed result', /No result confirmed by both sides yet/.test(await j.text(sandile)));
    await j.go(sandile, `/lines/${line.id}`);
    await j.snap(sandile, 'outcome-waiting-for-confirm', { full: false });
    await sandile.getByRole('button', { name: /^Yes, confirm/ }).click();
    await j.settle(sandile, 700);
    j.check('when the other side confirms, both timestamps are stored', confirmed() === 1);
    await j.go(sandile, '/b/copper-kettle-plumbing');
    j.check('and now the business page shows one confirmed result, from the data', /1 result that both sides confirmed/.test(await j.text(sandile)));

    // --- blind reviews: neither is shown until both are written ---
    const reviewsPublic = async (p) => { await j.go(p, '/b/copper-kettle-plumbing'); return /Quick and tidy|Clear about the job/.test(await j.text(p)); };
    await j.go(sandile, `/lines/${line.id}`);
    await j.snap(sandile, 'review-form', { full: false });
    await sandile.locator('label:has(input[name=rating][value="5"])').click();
    await sandile.locator('#review-body').fill('Quick and tidy. The quote was the price on the day.');
    await sandile.getByRole('button', { name: 'Write the review' }).click();
    await j.settle(sandile, 700);
    const mine = j.one(`select * from pulse_reviews where conversation_id = ? and user_id = ?`, line.id, sid);
    j.check('her review is stored, five stars, earned from the confirmed outcome', mine && Number(mine.rating) === 5 && Number(mine.is_verified) === 1, JSON.stringify(mine));
    j.check('and it is hidden (not yet revealed) while the other side has not written theirs', mine && mine.revealed_at === null);
    j.check('nobody sees it on the business page yet', !(await reviewsPublic(thuli)));
    await j.go(thuli, `/lines/${line.id}`);
    j.check('the provider is not shown her words before writing their own', !/Quick and tidy/.test(await j.text(thuli)));
    await j.snap(thuli, 'provider-review-before-writing', { full: false });
    await thuli.locator('label:has(input[name=rating][value="5"])').click();
    await thuli.locator('#review-body').fill('Clear about the job and ready on time.');
    await thuli.getByRole('button', { name: 'Write the review' }).click();
    await j.settle(thuli, 700);
    const both = j.db(`select revealed_at from pulse_reviews where conversation_id = ?`, line.id);
    j.check('when both are written both are revealed together', both.length === 2 && both.every((r) => r.revealed_at), JSON.stringify(both));
    await j.go(thuli, `/lines/${line.id}`);
    j.check('each side now reads the other review', /Quick and tidy/.test(await j.text(thuli)));
    await j.go(sandile, `/lines/${line.id}`);
    j.check('and the other way round', /Clear about the job/.test(await j.text(sandile)));
    await j.snap(sandile, 'reviews-revealed', { full: false });
    await j.go(sandile, '/b/copper-kettle-plumbing');
    j.check('the business page now shows the review, labelled as earned', /Quick and tidy/.test(await j.text(sandile)));
    await j.snap(sandile, 'business-page-with-review');
  },
};
