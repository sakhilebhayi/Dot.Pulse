// Criterion 11: search, discovery and the feed work end to end, including the end-of-round "caught up" state, empty
// states, saving, "not for me", and the community feed.
// The browser reports a beat as seen once it has been on screen, so a person reading the round scrolls through it.
const readRound = async (page) => {
  const n = await page.locator('article.pk-beat').count();
  for (let i = 0; i < n; i++) {
    await page.locator('article.pk-beat').nth(i).scrollIntoViewIfNeeded();
    await new Promise((r) => setTimeout(r, 450));
  }
  await new Promise((r) => setTimeout(r, 600));
  await page.evaluate(() => window.scrollTo(0, 0));
};

const cards = (page) => page.evaluate(() => [...document.querySelectorAll('article.pk-beat')].map((a) => ({
  title: a.querySelector('.pk-beat__title')?.textContent.trim(),
  kind: a.querySelector('.pk-kind')?.textContent.trim(),
})));

export default {
  id: '11-search-discovery-feed',
  title: 'Search, the Floor (with its caught-up and empty states), saving, dismissing and the community feed work end to end',
  async run(j) {
    // --- the Floor and its round ---
    const una = await j.as('una');
    const uid = j.userId('una');
    await j.go(una, '/floor');
    const first = await cards(una);
    const liveMatches = j.value(`select count(*) from pulse_matches where user_id = ? and dismissed_at is null`, uid);
    j.check('the Floor shows her real matches (and no more than the round allows)', first.length > 0 && first.length <= 20 && first.length <= liveMatches, `${first.length} shown, ${liveMatches} matched`);
    const text1 = await j.text(una);
    j.check('the end of the round says she is caught up and when the next round is', /caught up/i.test(text1) && /next round is ready at \d\d:\d\d/i.test(text1));
    await j.snap(una, 'floor-round-and-caught-up');
    await readRound(una);
    const seen = j.value(`select count(*) from pulse_matches where user_id = ? and seen_at is not null`, uid);
    j.check('what she read is recorded as seen', seen >= first.length, `${seen} seen of ${first.length} shown`);
    await j.go(una, '/floor');
    const second = await cards(una);
    j.check('the next visit is the empty "caught up" state, not the same beats again', second.length === 0 && /caught up/i.test(await j.text(una)), `${second.length} cards`);
    await j.snap(una, 'floor-caught-up-empty');
    j.check('the empty state offers a next step (saved beats, say what you need)', (await una.getByRole('link', { name: /Say what you need/ }).count()) > 0);

    for (const m of ['trade', 'work', 'local', 'learn']) {
      await j.go(una, `/${m}`);
      j.check(`/${m} loads and says what it is for`, (await una.getByRole('heading', { level: 1 }).count()) === 1);
    }
    await j.snap(una, 'mode-learn-empty', { full: false });

    // --- save, unsave, not for me ---
    const ibrahim = await j.as('ibrahim');
    const bid = j.userId('ibrahim');
    await j.go(ibrahim, '/floor');
    const ib = await cards(ibrahim);
    j.check('another person has a Floor of her own', ib.length > 0, `${ib.length} cards`);
    const saveTitle = ib[0].title;
    const article = (t) => ibrahim.locator('article', { has: ibrahim.getByRole('heading', { name: t, exact: true }) }).first();
    await article(saveTitle).getByRole('button', { name: /^Save/ }).click();
    await j.settle(ibrahim, 600);
    const saved = j.value(`select count(*) from pulse_saves where user_id = ?`, bid);
    j.check('Save stores a save for her', saved === 1, String(saved));
    await j.go(ibrahim, '/saved');
    j.check('and it is on the Saved page', (await j.text(ibrahim)).includes(saveTitle));
    await j.snap(ibrahim, 'saved-page');
    // "Not for me" from search results (search has no round memory): the card goes and stays gone.
    await j.go(ibrahim, '/search?q=bookkeeping');
    const target = 'Monthly bookkeeping for small businesses';
    const hasTarget = await ibrahim.getByRole('heading', { name: target, exact: true }).count();
    if (hasTarget) {
      const sigId = j.value(`select id from pulse_signals where title = ?`, target);
      await ibrahim.locator('article', { has: ibrahim.getByRole('heading', { name: target, exact: true }) }).first().getByRole('button', { name: 'Not for me' }).click();
      await ibrahim.getByRole('menuitem', { name: 'Just remove it' }).or(ibrahim.getByRole('button', { name: 'Just remove it' })).first().click();
      await j.settle(ibrahim, 700);
      const dismissed = j.one(`select dismissed_at from pulse_matches where user_id = ? and signal_id = ?`, bid, sigId);
      j.check('"Not for me" is stored as a dismissed match for her', !!dismissed?.dismissed_at, JSON.stringify(dismissed));
      j.check('the card left the page at once', (await ibrahim.getByRole('heading', { name: target, exact: true }).count()) === 0);
      await j.go(ibrahim, '/search?q=bookkeeping');
      j.check('and it does not come back in search', (await ibrahim.getByRole('heading', { name: target, exact: true }).count()) === 0);
    } else {
      j.check('the search showed the bookkeeping offer to dismiss', false, 'not found');
    }

    // --- search ---
    await j.go(ibrahim, '/floor');
    await ibrahim.locator('#mast-q').fill('baking');
    await ibrahim.locator('#mast-q').press('Enter');
    await j.settle(ibrahim);
    j.check('the masthead search goes to the search page with the query', ibrahim.url().includes('/search') && ibrahim.url().includes('baking'), ibrahim.url());
    const found = await cards(ibrahim);
    j.check('results are real live things, and each says why it matched', found.length > 0 && /Matched|Matches/.test(await j.text(ibrahim)), `${found.length} results`);
    const live = new Set(j.db(`select title from pulse_signals where status = 'live'`).map((r) => r.title));
    const ents = new Set(j.db(`select name from pulse_entities where status = 'live'`).map((r) => r.name));
    j.check('every result is a live signal or a live business in the database', found.every((c) => live.has(c.title) || ents.has(c.title)), found.map((c) => c.title).join(' | '));
    await j.snap(ibrahim, 'search-baking');

    await j.go(ibrahim, '/search?q=bookkeeping');
    await ibrahim.locator('details summary').first().click().catch(() => {});
    await ibrahim.locator('#kind').selectOption('role');
    await j.settle(ibrahim, 600);
    const filtered = await cards(ibrahim);
    j.note(`kind filter option 1 shows kinds: ${[...new Set(filtered.map((c) => c.kind))].join(',')}`);
    j.check('a kind filter narrows the results to that kind', filtered.length > 0 && filtered.every((c) => c.kind === 'Role'), [...new Set(filtered.map((c) => c.kind))].join(','));
    await j.snap(ibrahim, 'search-filtered', { full: false });

    await j.go(ibrahim, '/search?q=zzzqqqxx');
    const empty = await j.text(ibrahim);
    j.check('an empty search says nothing was found and offers a next step', /Nothing found for zzzqqqxx/.test(empty) && (await ibrahim.getByRole('link', { name: /Say what you need/ }).count()) > 0);
    await j.snap(ibrahim, 'search-empty');
    await j.go(ibrahim, '/search');
    j.check('search with no query invites a search instead of listing everything', (await cards(ibrahim)).length === 0 && /Search the floor/.test(await j.text(ibrahim)));

    // --- the community feed ---
    await j.go(una, '/community');
    j.check('the community feed starts with an honest empty state', /No posts yet/.test(await j.text(una)));
    await una.getByRole('button', { name: 'Share something with the community' }).click();
    await j.settle(una);
    await una.locator('[id$="-title"]').first().fill('Which accounting package do small shops use?');
    await una.locator('textarea').first().fill('I am starting a small shop in Cape Town and want to pick a package that a bookkeeper can use too. What do you recommend?');
    await una.getByRole('button', { name: 'Publish' }).click();
    await j.settle(una, 800);
    const post = j.one(`select * from pulse_posts where user_id = ? order by id desc limit 1`, uid);
    j.check('the post is stored', !!post, JSON.stringify(post)?.slice(0, 120));
    j.check('and it is visible in her own feed', (await j.text(una)).includes('Which accounting package'));
    await j.snap(una, 'community-feed-with-post');
    const reader = await j.as('rudo');
    await j.go(reader, '/community');
    const seenByOther = (await j.text(reader)).includes('Which accounting package');
    j.check('another person sees it in the feed once it is published (or it is held for review and says so)', seenByOther || post?.status !== 'published', `status ${post?.status}`);
  },
};
