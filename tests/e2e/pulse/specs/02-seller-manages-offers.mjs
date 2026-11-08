// Criterion 2: a seller creates a page, publishes offers, edits, pauses, archives, and sees them appear and disappear
// on the Floor and in search (checked from another person's browser).
export default {
  id: '02-seller-manages-offers',
  title: 'A seller creates a page, publishes, edits, pauses and archives offers, and they appear and disappear for others',
  async run(j) {
    const una = await j.as('una');
    const buyer = await j.as('ibrahim');
    const unaId = j.userId('una');

    // --- create the page ---
    await j.go(una, '/business/new');
    await j.snap(una, 'create-page-form');
    await una.locator('#name').fill('Backbeat Drum Studio');
    await una.locator('#tagline').fill('Drum lessons for grown-ups who always wanted to');
    await una.locator('#about').fill('One-to-one drum lessons in Cape Town for adult beginners. A fictional demo business created for the end-to-end run.');
    await una.locator('#industry').fill('Music tuition');
    await una.locator('#place_query').fill('Cape Town');
    await j.settle(una);
    await una.getByRole('option', { name: 'Cape Town, Western Cape' }).click();
    await j.settle(una);
    await una.locator('#website').fill('https://backbeat.example');
    await una.locator('#registration_number').fill('2024/123456/07');
    await una.getByRole('button', { name: 'Publish page' }).click();
    await j.settle(una, 600);

    const entity = await j.until(() => j.one(`select * from pulse_entities where name = 'Backbeat Drum Studio'`), 'the page was created');
    if (!entity) return;
    j.check('the page is live and approved', entity.status === 'live' && entity.moderation === 'approved', `${entity.status}/${entity.moderation}`);
    j.check('the creator is its owner', j.value(`select role from pulse_entity_members where entity_id = ? and user_id = ?`, entity.id, unaId) === 'owner');
    j.check('what the owner typed about themselves is a claim, never "verified"', entity.verification_status === 'claimed', entity.verification_status);
    j.check('the registration number is stored as a claim', entity.registration_number === '2024/123456/07');

    // --- the page, as the public sees it ---
    await j.go(buyer, `/b/${entity.slug}`);
    const pub = await j.text(buyer);
    j.check('the public page says what is claimed and what is not checked', /claim/i.test(pub) && !/verified business/i.test(pub));
    j.check('the page shows the claim honestly (not shown as checked)', /Not verified|not checked|Claims/i.test(pub));
    await j.snap(buyer, 'public-page-claimed');

    // --- publish an offer ---
    await j.go(una, `/b/${entity.slug}/manage/offers`);
    await una.getByRole('button', { name: 'New offer' }).click();
    await j.settle(una);
    await una.locator('#offer-title').fill('Beginner drum lessons');
    await una.locator('#offer-summary').fill('Six weekly one-hour lessons for adults who have never held sticks.');
    await una.locator('#offer-description').fill('We start with a practice pad and finish with a full song on a real kit. Lessons are in Cape Town.');
    await una.locator('#offer-tags').fill('drumming, music lessons');
    await una.locator('#offer-category').fill('Music lessons');
    await j.snap(una, 'new-offer-form-filled');
    await una.getByRole('button', { name: 'Save and publish' }).click();
    await j.settle(una, 600);
    const offer = await j.until(() => j.one(`select * from pulse_offers where entity_id = ? and title = 'Beginner drum lessons'`, entity.id), 'the offer was saved');
    if (!offer) return;
    j.check('the offer is live and approved', offer.status === 'live' && offer.moderation === 'approved', `${offer.status}/${offer.moderation}`);
    const signalStatus = () => j.value(`select status from pulse_signals where subject_type = 'offer' and subject_id = ?`, offer.id);
    j.check('its signal is live in the discovery index', signalStatus() === 'live', signalStatus());

    const searchHas = async (term, title) => {
      await j.go(buyer, `/search?q=${encodeURIComponent(term)}`);
      return (await buyer.getByRole('heading', { name: title, exact: true }).count()) > 0;
    };
    j.check('another person finds the offer in search', await searchHas('drum', 'Beginner drum lessons'));
    await j.snap(buyer, 'search-finds-new-offer');
    j.tick(); // the scheduler's ten-minute matching run
    await j.go(buyer, '/local');
    j.check('it appears on the Floor for someone in Cape Town after the next matching run', (await j.text(buyer)).includes('Beginner drum lessons'));
    await j.snap(buyer, 'floor-shows-new-offer', { full: false });
    await j.go(buyer, `/b/${entity.slug}/o/${offer.slug}`);
    j.check('its own page is reachable by others while live', (await buyer.getByRole('heading', { name: 'Beginner drum lessons' }).count()) > 0);

    // --- edit ---
    await j.go(una, `/b/${entity.slug}/manage/offers`);
    await una.locator(`button[wire\\:click="edit(${offer.id})"]`).click();
    await j.settle(una);
    await una.locator('#offer-title').fill('Beginner drum lessons for adults');
    await una.getByRole('button', { name: 'Save and publish' }).click();
    await j.settle(una, 600);
    j.check('the edit is stored', j.value(`select title from pulse_offers where id = ?`, offer.id) === 'Beginner drum lessons for adults');
    j.check('the edited title replaces the old one in search', await searchHas('drum', 'Beginner drum lessons for adults') && !(await searchHas('drum', 'Beginner drum lessons')));

    // --- pause ---
    await j.go(una, `/b/${entity.slug}/manage/offers`);
    await una.locator(`button[wire\\:click="pause(${offer.id})"]`).click();
    await j.settle(una, 600);
    j.check('pausing stores status paused', j.value(`select status from pulse_offers where id = ?`, offer.id) === 'paused');
    j.check('the paused offer leaves discovery at once (signal not live)', signalStatus() !== 'live', signalStatus());
    j.check('the paused offer is gone from search', !(await searchHas('drum', 'Beginner drum lessons for adults')));
    j.check('the paused offer page is a 404 for others, not a 403', await j.status(buyer, `/b/${entity.slug}/o/${offer.slug}`) === 404);
    await j.go(buyer, '/local');
    j.check('the paused offer is gone from the Floor at once, with no matching run', !(await j.text(buyer)).includes('Beginner drum lessons for adults'));
    await j.go(una, `/b/${entity.slug}/manage/offers`);
    await j.snap(una, 'offers-manager-paused');

    // --- publish again, then archive ---
    const resume = una.getByRole('button', { name: /^(Publish|Resume|Put back)/ }).first();
    if (await resume.count()) {
      await resume.click();
      await j.settle(una, 600);
      j.check('publishing again makes it live', j.value(`select status from pulse_offers where id = ?`, offer.id) === 'live');
      j.check('and it is back in search', await searchHas('drum', 'Beginner drum lessons for adults'));
    } else {
      j.check('a paused offer can be published again from the manager', false, 'no publish/resume button on a paused offer');
    }
    await j.go(una, `/b/${entity.slug}/manage/offers`);
    await una.locator(`button[wire\\:click="archive(${offer.id})"]`).click();
    await j.settle(una, 600);
    j.check('archiving stores status archived', j.value(`select status from pulse_offers where id = ?`, offer.id) === 'archived');
    j.check('the archived offer is gone from search', !(await searchHas('drum', 'Beginner drum lessons for adults')));
    j.check('and its signal is not live', signalStatus() !== 'live', signalStatus());

    j.check('the archived offer page is a 404 for others', await j.status(buyer, `/b/${entity.slug}/o/${offer.slug}`) === 404);
    await j.go(una, `/b/${entity.slug}/manage/offers`);
    await j.snap(una, 'offers-manager-archived');
  },
};
