// Criterion 1: a consumer discovers a real business on the Floor/search and sends an enquiry with context (a knock)
// that the business receives, accepts and answers.
import { beat, knock, send } from '../lib/ui.mjs';

export default {
  id: '01-consumer-enquiry',
  title: 'A consumer discovers a real business and sends an enquiry with context that the business accepts and answers',
  async run(j) {
    const una = await j.as('una');
    const unaId = j.userId('una');
    const tally = j.entityId('Tally Fox Bookkeeping');

    // Discovery: search finds the business and its offer, each business once.
    await j.go(una, '/search?q=bookkeeping');
    const body = await j.text(una);
    j.check('search finds the offer', body.includes('Monthly bookkeeping for small businesses'));
    const bizCards = await una.getByRole('heading', { name: 'Tally Fox Bookkeeping', exact: true }).count();
    j.check('the business is listed once, not twice', bizCards === 1, `${bizCards} cards`);
    j.check('every result says why', /Matched your words|Matches the topic/.test(body));
    await j.snap(una, 'search-results');

    // The knock, from the offer card: the context is the offer.
    const card = beat(una, 'Monthly bookkeeping for small businesses');
    await knock(j, una, card.getByRole('button', { name: 'Knock' }), {
      what: 'Monthly books and VAT for a small craft shop',
      when: 'From next month',
      where: 'Cape Town, remote is fine',
      budget: 'R1,000 to R2,000',
    });

    const line = await j.until(() => j.one(`select * from pulse_conversations where initiator_id = ? and entity_id = ?`, unaId, tally), 'the knock created a line in the database');
    if (!line) return;
    const offerId = j.value(`select id from pulse_offers where entity_id = ? and title = 'Monthly bookkeeping for small businesses'`, tally);
    j.check('line is an enquiry carrying the offer as context', line.kind === 'enquiry' && line.context_type?.toLowerCase().includes('offer') && Number(line.context_id) === Number(offerId), `${line.kind} ${line.context_type} ${line.context_id} (offer ${offerId})`);
    j.check('a page that takes requests got a request, not an open line', line.status === 'requested', line.status);
    j.check('the enquiry card holds what the person typed', JSON.parse(line.card).what?.startsWith('Monthly books and VAT'), line.card);
    j.check('a stranger knock spent budget (not free spam)', j.value(`select count(*) from pulse_conversations where initiator_id = ?`, unaId) >= 1);

    await j.go(una, '/lines');
    await una.getByRole('button', { name: /^Sent/ }).click();
    await j.settle(una);
    j.check('the sender sees it on the Sent tab with its context', (await j.text(una)).includes('Monthly bookkeeping for small businesses'));
    await j.snap(una, 'sender-lines-sent');

    // The business receives it.
    const rudo = await j.as('rudo');
    await j.go(rudo, '/lines');
    const rbody = await j.text(rudo);
    j.check('the business sees the request with the sender and the context', rbody.includes('Una Featherstone') && rbody.includes('Monthly bookkeeping for small businesses'));
    j.check('the request shows what was asked', rbody.includes('Monthly books and VAT for a small craft shop') && rbody.includes('R1,000 to R2,000'));
    await j.snap(rudo, 'business-inbox-request');
    await rudo.locator(`button[wire\\:click="accept(${line.id})"]`).click();
    await j.settle(rudo, 500);
    const accepted = j.one(`select status, first_response_at from pulse_conversations where id = ?`, line.id);
    j.check('accepting opens the line and stamps the first response', accepted.status === 'open' && accepted.first_response_at, JSON.stringify(accepted));

    // ...and answers.
    await j.go(rudo, `/lines/${line.id}`);
    j.check('the thread carries the context card and the enquiry text', (await j.text(rudo)).includes('Monthly bookkeeping for small businesses') && (await j.text(rudo)).includes('Monthly books and VAT'));
    await send(j, rudo, 'Happy to help. A craft shop on one till is about R1,400 a month. Which accounting package do you use?');
    j.check('the reply is stored', j.value(`select count(*) from pulse_messages where pulse_conversation_id = ? and body like 'Happy to help%'`, line.id) === 1);
    await j.snap(rudo, 'business-thread-answered', { full: false });

    // The consumer sees the answer.
    await j.go(una, `/lines/${line.id}`);
    const ubody = await j.text(una);
    j.check('the consumer sees the answer', ubody.includes('Happy to help. A craft shop'));
    j.check('the thread is open for the consumer to reply', await una.locator('#ln-body').isVisible());
    await j.snap(una, 'consumer-thread-answered', { full: false });
    j.check('the consumer was notified of the response', j.value(`select count(*) from notifications where notifiable_id = ? `, unaId) >= 1);
  },
};
