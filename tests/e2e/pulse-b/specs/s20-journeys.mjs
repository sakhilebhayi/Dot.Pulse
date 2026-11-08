// Criterion 20: meaningful journeys without administrator intervention.
//  A. a brand-new person (InfoDot provisioned, no data): onboard, post a need, get matches, knock, converse, close with an outcome, review.
//  B. a new business owner: create a page, publish an offer, be discoverable; what happens when the content gate holds it.
import { launch, person, Spec, axe, shot, settle, sql, artisan } from '../lib.mjs';

export default async function (ctx) {
    const s = new Spec('s20', ctx);
    const { users, entities, offers } = ctx.seed;
    const browser = await launch();
    const ig = [/status of 404/];
    const nomsa = await person(browser, ctx.base, { userId: users.newbie.id, spec: s, ignoreConsole: ig });
    const sipho = await person(browser, ctx.base, { userId: users.sipho.id, spec: s, ignoreConsole: ig });
    const busi = await person(browser, ctx.base, { userId: users.bizowner.id, spec: s, ignoreConsole: ig });
    const anele = await person(browser, ctx.base, { userId: users.anele.id, spec: s, ignoreConsole: ig });
    const mod = await person(browser, ctx.base, { userId: users.mod.id, spec: s, ignoreConsole: ig });

    // ================= A. the new person
    await nomsa.page.goto('/floor');
    await settle(nomsa.page);
    s.check('a new person is taken to the welcome flow first', /welcome-in/.test(nomsa.page.url()), nomsa.page.url());
    await shot(nomsa.page, s, 'a1-welcome-age');
    await axe(nomsa.page, s, 'welcome age');
    await nomsa.page.locator('.me-confirm').click();
    await nomsa.page.getByRole('button', { name: 'Continue' }).click();
    await nomsa.page.waitForTimeout(600);
    // who they are: for themselves
    await nomsa.page.locator('label.me-choice', { hasText: 'for myself' }).click();
    await nomsa.page.getByRole('button', { name: 'Continue' }).click();
    await nomsa.page.waitForTimeout(600);
    // place
    await nomsa.page.getByLabel(/Your city or region/).fill('Johannesburg');
    await nomsa.page.waitForTimeout(800);
    await shot(nomsa.page, s, 'a2-welcome-place');
    await nomsa.page.locator('.me-result').first().click();
    await nomsa.page.waitForTimeout(500);
    await nomsa.page.getByRole('button', { name: 'Continue' }).click();
    await nomsa.page.waitForTimeout(600);
    // interests, discover, contact, notify: continue with the careful defaults
    for (let i = 0; i < 4; i++) {
        const btn = nomsa.page.getByRole('button', { name: /Continue|Finish and go to the Floor/ });
        const label = await btn.innerText();
        await btn.click();
        await nomsa.page.waitForTimeout(700);
        if (/Finish/.test(label)) break;
    }
    await settle(nomsa.page);
    s.check('onboarding ends on the Floor', /\/floor/.test(nomsa.page.url()), nomsa.page.url());
    await shot(nomsa.page, s, 'a3-floor-first-visit');
    await axe(nomsa.page, s, 'new person floor');
    const first = await nomsa.page.locator('main').innerText();
    s.note(`a new person's first Floor says: ${first.replace(/\s+/g, ' ').slice(0, 300)}`);
    s.check('the first Floor is not a blank page: it says what to do next', /Say what you need|need|offer|door/i.test(first));

    // post a need
    await nomsa.page.goto('/need/new');
    await settle(nomsa.page);
    await nomsa.page.locator('#need-title').fill('Need a plumber to fix a leaking geyser');
    await nomsa.page.locator('#need-details').fill('The geyser in my flat in Johannesburg leaks every morning. Looking for someone who can come this week.');
    await nomsa.page.locator('#need-reach').selectOption('local');
    await nomsa.page.locator('#need-place').fill('Johannesburg');
    await nomsa.page.waitForTimeout(900);
    await nomsa.page.locator('.bz-suggest button').first().click();
    await nomsa.page.getByRole('button', { name: 'Post it' }).click();
    await nomsa.page.waitForTimeout(1500);
    await shot(nomsa.page, s, 'a4-need-posted');
    s.check('the need was posted and is live', sql(ctx.out, `select status || '/' || moderation from pulse_needs where user_id=${users.newbie.id}`) === 'open/approved', sql(ctx.out, `select status || '/' || moderation from pulse_needs where user_id=${users.newbie.id}`));
    s.note('need page after posting: ' + (await nomsa.page.locator('main').innerText()).replace(/\s+/g, ' ').slice(0, 300));

    // matches without anyone running anything
    await nomsa.page.goto('/floor');
    await settle(nomsa.page);
    let floor = await nomsa.page.locator('main').innerText();
    const instant = /Moyo Plumbing|Geyser repair/.test(floor);
    s.check('matches appear on the Floor straight after posting (no administrator, no waiting for a job)', instant, floor.replace(/\s+/g, ' ').slice(0, 300));
    if (!instant) {
        artisan(ctx.root, 'pulse:match');
        await nomsa.page.goto('/floor');
        await settle(nomsa.page);
        floor = await nomsa.page.locator('main').innerText();
        s.check('after the scheduled matcher has run, matches appear', /Moyo Plumbing|Geyser repair/.test(floor), floor.replace(/\s+/g, ' ').slice(0, 300));
    }
    await shot(nomsa.page, s, 'a5-floor-with-matches');
    const why = await nomsa.page.locator('.pk-meter__reason').allInnerTexts();
    s.check('each match says why', why.length > 0, 'no reasons');

    // knock
    await nomsa.page.goto(`/b/${entities.plumb.slug}/o/${offers.plumb.slug}`);
    await settle(nomsa.page);
    await nomsa.page.getByRole('button', { name: /^(Knock|Ask about this)/i }).first().click();
    await nomsa.page.getByLabel('What do you want?').fill('Geyser repair, Johannesburg, this week');
    await nomsa.page.getByLabel(/Where/).first().fill('Johannesburg').catch(() => {});
    await nomsa.page.locator('dialog[open] form button[type=submit]').click();
    await nomsa.page.locator('dialog[open] .fl-sheet__lead').waitFor({ timeout: 8000 });
    s.check('the knock was sent and the person is told what happens next', /decide|open/i.test(await nomsa.page.locator('dialog[open]').innerText()));
    const line = sql(ctx.out, `select id from pulse_conversations where initiator_id=${users.newbie.id} order by id desc limit 1`);
    await sipho.page.goto('/lines');
    await settle(sipho.page);
    await sipho.page.getByRole('button', { name: 'Accept' }).first().click();
    await sipho.page.waitForTimeout(1200);
    await sipho.page.goto(`/lines/${line}`);
    await sipho.page.locator('#ln-body').fill('Hello Nomsa. I can come Thursday morning. Is 08:00 fine?');
    await sipho.page.getByRole('button', { name: 'Send' }).click();
    await sipho.page.waitForTimeout(1000);
    await nomsa.page.goto(`/lines/${line}`);
    await settle(nomsa.page);
    s.check('the new person reads the reply', /Thursday morning/.test(await nomsa.page.locator('main').innerText()));
    await nomsa.page.locator('#ln-body').fill('Yes, 08:00 works. Thank you.');
    await nomsa.page.getByRole('button', { name: 'Send' }).click();
    await nomsa.page.waitForTimeout(1000);
    // close with an outcome: both sides confirm
    await nomsa.page.getByRole('button', { name: /^Won|Hired|The deal/ }).first().click().catch(async () => { await nomsa.page.locator('.ln-outcome .pk-btn--primary').first().click(); });
    await nomsa.page.waitForTimeout(1000);
    await sipho.page.goto(`/lines/${line}`);
    await settle(sipho.page);
    await sipho.page.getByRole('button', { name: /Yes, confirm/ }).click();
    await sipho.page.waitForTimeout(1000);
    s.check('both sides confirmed: the outcome counts', sql(ctx.out, `select outcome from pulse_conversations where id=${line}`) !== 'none' && /confirmed/i.test(await sipho.page.locator('.ln-outcome').innerText()), await sipho.page.locator('.ln-outcome').innerText());
    // review
    await nomsa.page.goto(`/lines/${line}`);
    await settle(nomsa.page);
    await shot(nomsa.page, s, 'a6-thread-confirmed');
    const canReview = await nomsa.page.locator('.ln-reviewform').count();
    s.check('after a confirmed result the person can write a review of the business', canReview > 0);
    if (canReview) {
        await nomsa.page.locator('.ln-rating label').nth(4).click();
        await nomsa.page.locator('.ln-reviewform textarea').fill('Came on the day, fixed the geyser, tidy work.');
        await nomsa.page.locator('.ln-reviewform').getByRole('button', { name: /Send|Submit|Write|Post/ }).click();
        await nomsa.page.waitForTimeout(1000);
        s.check('the review is stored (blind until the other side writes)', Number(sql(ctx.out, 'select count(*) from pulse_reviews')) === 1);
    }

    // ================= B. the new business owner
    await busi.page.goto('/business/new');
    await settle(busi.page);
    await shot(busi.page, s, 'b1-business-form');
    await axe(busi.page, s, 'business/new');
    await busi.page.locator('#name').fill('Busi Bakes').catch(async () => { await busi.page.getByLabel('Business name').fill('Busi Bakes'); });
    await busi.page.getByLabel(/Tagline|In a line/i).first().fill('Fresh bread and wedding cakes in Soweto').catch(() => {});
    await busi.page.getByRole('button', { name: /Create|Save|Publish|Continue/i }).first().click();
    await busi.page.waitForTimeout(1800);
    const slug = sql(ctx.out, `select slug from pulse_entities where created_by=${users.bizowner.id} order by id desc limit 1`);
    s.check('the business page was created', !!slug, busi.page.url());
    const pageState = sql(ctx.out, `select status || '/' || moderation from pulse_entities where created_by=${users.bizowner.id} order by id desc limit 1`);
    s.note(`new page state: ${pageState}`);
    await shot(busi.page, s, 'b2-after-create');
    s.check('a clean new page is live straight away (a normal new seller is not stuck)', pageState === 'live/approved', pageState);
    if (slug) {
        await busi.page.goto(`/b/${slug}/manage/offers`);
        await settle(busi.page);
        await shot(busi.page, s, 'b3-offers-tab');
        const newOffer = busi.page.getByRole('button', { name: /New offer|Add an offer|Add offer|Create/i }).first();
        s.check('the offers tab offers a way to add one', await newOffer.isVisible().catch(() => false), (await busi.page.locator('main').innerText()).slice(0, 200));
        if (await newOffer.isVisible().catch(() => false)) {
            await newOffer.click();
            await busi.page.waitForTimeout(600);
            await busi.page.getByLabel(/Title/i).first().fill('Wedding cakes, made to order');
            await busi.page.getByLabel('One line').fill('Three tier cakes for weddings and parties in Johannesburg.');
            await shot(busi.page, s, 'b4-offer-form');
            await busi.page.getByRole('button', { name: /Publish/i }).first().click();
            await busi.page.waitForTimeout(1800);
        }
        const offerState = sql(ctx.out, `select status || '/' || moderation from pulse_offers where created_by=${users.bizowner.id} order by id desc limit 1`);
        s.note(`new offer state: ${offerState}`);
        s.check('a clean first offer from a new seller goes live without a human', offerState === 'live/approved', offerState);
        await shot(busi.page, s, 'b5-after-publish');
        if (/pending|held/.test(offerState)) {
            s.note('pending copy shown to the owner: ' + (await busi.page.locator('main').innerText()).replace(/\s+/g, ' ').slice(0, 500));
        }
        // discoverable (no administrator: only what the scheduler does anyway happens, and publishing matched at once)
        await anele.page.goto('/search?q=wedding+cakes');
        await settle(anele.page);
        s.check('a customer finds the new business by search', /Busi Bakes|Wedding cakes/.test(await anele.page.locator('main').innerText()), (await anele.page.locator('main').innerText()).slice(0, 200));
        await shot(anele.page, s, 'b6-search-finds');

        // ---- text the gate holds for a human: the owner is told exactly what happens, how long, and a moderator approving it makes it live
        await busi.page.goto(`/b/${slug}/manage/offers`);
        await busi.page.getByRole('button', { name: /New offer|Add an offer|Add offer|Create/i }).first().click();
        await busi.page.waitForTimeout(500);
        await busi.page.getByLabel(/Title/i).first().fill('Fresh scones and muffins');
        await busi.page.getByLabel('One line').fill('Baked every morning. Call me on 082 123 4567 to order.');
        await busi.page.getByRole('button', { name: /Save and publish/i }).first().click();
        await busi.page.waitForTimeout(1800);
        const held = sql(ctx.out, `select status || '/' || moderation from pulse_offers where title='Fresh scones and muffins'`);
        s.check('a phone number in public text is held for a person, not published', /held|pending/.test(held), held);
        const heldCopy = (await busi.page.locator('main').innerText()).replace(/\s+/g, ' ');
        s.note('held copy shown to the owner: ' + heldCopy.slice(0, 700));
        await shot(busi.page, s, 'b7-held-offer');
        s.check('the owner is told why it is held, in plain words', /phone numbers and email addresses/i.test(heldCopy), heldCopy.slice(0, 300));
        s.check('the owner is told what happens next', /a person|moderator|someone will (check|look)/i.test(heldCopy));
        s.check('the owner is told how long it takes', /within|hours|working day|business day|usually/i.test(heldCopy), heldCopy.slice(0, 400));
        // a moderator approves it
        await mod.page.goto('/moderation');
        await mod.page.locator('.pk-tab', { hasText: /Listings/ }).first().click();
        await mod.page.waitForTimeout(700);
        await shot(mod.page, s, 'b8-moderation-listings');
        s.check('the held offer is in the moderator queue with the reason', /Fresh scones/.test(await mod.page.locator('main').innerText()) && /phone numbers/i.test(await mod.page.locator('main').innerText()), (await mod.page.locator('main').innerText()).slice(0, 300));
        await mod.page.getByRole('button', { name: 'Approve', exact: true }).first().click();
        await mod.page.waitForTimeout(1200);
        const approved = sql(ctx.out, `select status || '/' || moderation from pulse_offers where title='Fresh scones and muffins'`);
        s.check('the moderator approving it makes it live', approved === 'live/approved', approved);
        await anele.page.goto('/search?q=scones');
        await settle(anele.page);
        s.check('and the customer can now find it', /scones/i.test(await anele.page.locator('main').innerText()));
        await busi.page.goto('/me/notifications');
        await settle(busi.page);
        s.check('the owner is told it went live', /scones/i.test(await busi.page.locator('.me-list').innerText().catch(() => '')), await busi.page.locator('.me-section').first().innerText());
    }

    s.check('no console errors', [nomsa, sipho, busi, anele, mod].every((p) => p.errors.length === 0), [nomsa, sipho, busi, anele, mod].flatMap((p) => p.errors).join(' | '));
    await browser.close();
    return s.result();
}
