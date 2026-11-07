# R-41 performance: measured, fixed, measured again

Written 2026-11-08 by the performance agent (branch `feat/r41-perf`). Everything below was measured on a production-shaped
synthetic dataset, on both PostgreSQL 16 (what production runs) and SQLite (what the tests run), with the same command
you can run yourself: `php artisan pulse:bench`.

## Verdict

| Target (dev machine, dataset below) | Result on PostgreSQL | Result on SQLite | Met |
|---|---|---|---|
| Floor round p95 < 250 ms | first batch 48.2 ms, later batch 30.6 ms | 25.6 / 20.6 ms | yes (was already met) |
| Search p95 < 300 ms | 72.8 / 98.7 / 23.5 ms (one word, three words, filtered) | 72.3 / 106.9 / 18.8 ms | yes (was already met) |
| Inbox p95 < 150 ms | typical 20 ms, heaviest user (3 700 lines) 12.2 ms | 1.6 / 11.2 ms | yes |
| Thread p95 < 150 ms | typical 6.1 ms, 250 messages 9.3 ms | 1.9 / 5.2 ms | yes |
| Operator dashboard cold < 1.5 s | 107.8 ms (was 810.3 ms); a minute-long cache makes the next look free | 127.9 ms (was 694.1 ms) | yes |
| pulse-check < 30 ms | 1.4 ms through the whole HTTP kernel (was 15.4) | 0.9 ms (was 35.2) | yes |
| pulse:match cycle < 5 min and < 256 MB | 76 s, 60.5 MB peak (was 254 s) | 56 s (was 277 s) | yes, with the limits under "Remaining limits" |
| pulse:index rebuild < 3 min | 43 s | 33 s | yes (unchanged: it was already inside) |
| Mobile LCP < 2.5 s (throttled 4G) | worst case /floor 2.36 s cold and 1.9 to 2.1 s warm; search, a business page and a thread 1.0 to 1.2 s | | yes (the Floor has 0.1 to 0.6 s of margin) |
| CLS < 0.05 | 0.000 on all four pages | | yes |
| JS < 100 kB gzip on first load | 78.0 kB (app 21.0 + Livewire with Alpine 56.9) | | yes |

Most of the Floor, search, thread and public-page numbers were already inside their targets when measured, so the honest
summary of the pass is: the platform was not slow on its reads, but it had one genuine N+1 (the inbox), two missing
indexes that were also integrity holes, a matching run that cost a million queries per tick, an operator page that
hydrated tens of thousands of rows to count them, and one PostgreSQL-only crash. Those are fixed and guarded.

## Dataset and method

**Dataset** (`pulse:bench --seed`, fixed random seed, direct chunked inserts with explicit ids, then `ANALYZE`):
20 000 people and profiles (25 percent professionals, 30 percent of those discoverable), 4 000 business pages (92 percent
live, 4 800 members), 12 000 offers, 4 000 roles, 8 000 needs, 3 000 posts, **34 027 signals** (the index; offers, roles,
needs, posts, pages and 3 000 talent), **102 907 matches** written by the real `Matcher` for about 1 700 sampled people,
**60 000 conversations with 463 977 messages**, 30 000 saves, 40 000 page follows, 30 000 person follows, 5 000 mutes,
2 000 blocks, 40 000 notifications, 20 000 audit rows, 600 reports, the 753 place gazetteer. Five "heavy" people own 15
pages, follow 400 pages and sit on 38 percent of all lines (about 22 800 between them, 3 700 in the heaviest inbox).

**Machine**: Apple M4, 10 cores, 16 GB, PHP 8.5.9, PostgreSQL 16.14 (Homebrew, localhost, default settings), SQLite 3.53.
A laptop with the database on the same machine: use the numbers for ratios and for what scales badly, not as promises for
shared hosting.

**Method**: every scenario runs 15 times (5 for the heavy matcher, 1 for the long commands), rotating over 40 typical
people (people with 20 or more matches) or the five heavy ones, so no single warm row decides the result. p50 and p95 are
wall time; "queries" is the average per run counted with `DB::listen`; peak MB is PHP's real peak after the scenario. The
`http.*` rows go through the full HTTP kernel in process (middleware, auth, session, Livewire page render) and leave out
the network and PHP start-up. The "before" runs used an untouched worktree of trunk with only `PulseBench` added, against a
byte copy of the same database; the "after" runs used a fresh copy plus the two new migrations. Both runs `ANALYZE` first.

## Before and after (every number)

p50 / p95 in milliseconds, with the average number of queries in brackets. Seconds where the row says so.

| scenario | PG before p50 / p95 ms (queries) | PG after p50 / p95 ms (queries) | SQLite before | SQLite after | target p95 |
|---|---|---|---|---|---|
| floor.first | 23.4 / 53.9 (16 q) | 16.6 / 48.2 (16 q) | 24.7 / 118.4 (16 q) | 13.8 / 25.6 (16 q) | 250 ms |
| floor.later | 19.0 / 32.9 (15 q) | 18.4 / 30.6 (15 q) | 14.0 / 25.2 (15 q) | 11.5 / 20.6 (15 q) | 250 ms |
| floor.trade | 19.6 / 31.8 (14 q) | 20.1 / 31.9 (14 q) | 13.4 / 24.2 (14 q) | 11.1 / 20.7 (14 q) |  |
| floor.heavy_user | 33.6 / 58.9 (16 q) | 24.9 / 28.7 (16 q) | 20.4 / 25.9 (16 q) | 14.7 / 17.1 (16 q) |  |
| floor.saved_list | 4.0 / 12.5 (12 q) | 4.5 / 8.3 (12 q) | 1.9 / 5.4 (12 q) | 1.2 / 2.5 (12 q) |  |
| search.one_word | 59.6 / 73.9 (21 q) | 57.8 / 72.8 (21 q) | 74.5 / 138.5 (21 q) | 68.6 / 72.3 (21 q) | 300 ms |
| search.three_words | 97.6 / 103.5 (20 q) | 94.8 / 98.7 (20 q) | 106.3 / 119.7 (20 q) | 100.7 / 106.9 (20 q) | 300 ms |
| search.filtered | 21.0 / 21.9 (18 q) | 17.3 / 23.5 (18 q) | 17.9 / 22.6 (18 q) | 16.2 / 18.8 (18 q) | 300 ms |
| search.no_hit | 23.7 / 24.5 (10 q) | 23.8 / 25.5 (10 q) | 30.2 / 33.4 (10 q) | 30.1 / 31.1 (10 q) |  |
| search.suggest | 12.2 / 12.4 (12 q) | 13.0 / 14.4 (12 q) | 19.9 / 20.8 (12 q) | 20.1 / 20.5 (12 q) |  |
| matcher.forUser.typical | 73.0 / 99.9 (25 q) | 75.7 / 88.8 (25 q) | 89.7 / 96.0 (25 q) | 79.7 / 86.0 (25 q) |  |
| matcher.forUser.heavy | 116.2 / 123.4 (26 q) | 99.2 / 107.9 (26 q) | 109.1 / 122.4 (26 q) | 99.9 / 101.0 (26 q) |  |
| matcher.forSignal.fanout | 904.2 / 1245.0 (3115 q) | 614.9 / 753.9 (203 q) | 1230.9 / 1934.7 (3115 q) | 397.5 / 437.4 (203 q) |  |
| match.cycle | 254.4 s (1071031 q) | 76.4 s (22246 q) | 276.6 s (1074377 q) | 55.9 s (22221 q) | 300 s |
| index.rebuild | 44.0 s (124868 q) | 43.0 s (124868 q) | 31.7 s (124868 q) | 32.7 s (124868 q) | 180 s |
| digest.build_1k | 9.1 s (13969 q) | 5.7 s (9851 q) | 4.1 s (13579 q) | 3.1 s (8674 q) |  |
| digest.command_dry | 208.1 / 208.1 (19 q) | 251.1 / 251.1 (19 q) | 179.1 / 179.1 (19 q) | 176.0 / 176.0 (19 q) |  |
| inbox.requests | 3.6 / 17.2 (4 q) | 1.2 / 6.1 (5 q) | 0.4 / 3.4 (4 q) | 0.3 / 2.0 (5 q) |  |
| inbox.open | 17.7 / 23.6 (7 q) | 10.5 / 20.0 (8 q) | 17.9 / 56.5 (7 q) | 1.0 / 1.6 (8 q) | 150 ms |
| inbox.sent | 7.1 / 7.7 (5 q) | 3.9 / 6.2 (7 q) | 18.2 / 19.7 (5 q) | 0.8 / 1.0 (7 q) |  |
| inbox.closed | 11.0 / 18.5 (6 q) | 5.3 / 13.8 (7 q) | 35.5 / 40.2 (6 q) | 0.8 / 1.5 (7 q) |  |
| inbox.ignored | 3.1 / 15.5 (3 q) | 2.0 / 6.0 (5 q) | 0.4 / 1.6 (3 q) | 0.3 / 0.9 (5 q) |  |
| inbox.all | 15.8 / 22.9 (9 q) | 5.2 / 8.0 (8 q) | 15.8 / 17.8 (9 q) | 1.2 / 2.6 (8 q) | 150 ms |
| inbox.unread | 7.7 / 21.8 (4 q) | 1.7 / 4.2 (6 q) | 8.5 / 17.9 (4 q) | 0.4 / 1.7 (6 q) |  |
| inbox.heavy.requests | 14.3 / 17.5 (19 q) | 8.2 / 10.6 (9 q) | 5.3 / 9.6 (19 q) | 4.1 / 4.7 (9 q) |  |
| inbox.heavy.open | 24.2 / 25.7 (19 q) | 10.6 / 17.4 (9 q) | 20.6 / 22.6 (19 q) | 5.6 / 9.8 (9 q) |  |
| inbox.heavy.all | 25.4 / 25.9 (19 q) | 9.7 / 12.2 (9 q) | 19.2 / 21.3 (19 q) | 7.2 / 11.2 (9 q) | 150 ms |
| inbox.heavy.page50 | 31.7 / 34.5 (35 q) | 13.4 / 15.6 (9 q) | 23.6 / 25.5 (35 q) | 10.5 / 18.4 (9 q) |  |
| thread.typical | 2.0 / 3.3 (6 q) | 2.7 / 6.1 (6 q) | 0.8 / 2.2 (6 q) | 0.8 / 1.9 (6 q) | 150 ms |
| thread.heavy | 5.3 / 6.5 (3 q) | 6.6 / 9.3 (3 q) | 4.4 / 5.2 (3 q) | 4.6 / 5.2 (3 q) | 150 ms |
| operator.people | 9.7 / 10.1 (4 q) | 9.6 / 23.5 (4 q) | 11.1 / 12.0 (4 q) | 10.5 / 24.4 (4 q) |  |
| operator.listings | 6.5 / 6.9 (6 q) | 6.0 / 6.8 (6 q) | 5.5 / 5.8 (6 q) | 5.7 / 10.4 (6 q) |  |
| operator.floor | 1.0 / 12.3 (3 q) | 0.5 / 1.7 (3 q) | 8.9 / 20.6 (3 q) | 0.1 / 0.4 (3 q) |  |
| operator.requests | 18.8 / 25.7 (6 q) | 17.8 / 30.3 (6 q) | 25.1 / 25.5 (6 q) | 25.7 / 26.9 (6 q) |  |
| operator.responses | 445.3 / 466.0 (2 q) | 39.7 / 51.9 (3 q) | 448.2 / 554.7 (2 q) | 42.3 / 59.1 (3 q) |  |
| operator.outcomes | 5.9 / 16.7 (2 q) | 5.4 / 6.0 (2 q) | 5.4 / 22.4 (2 q) | 5.6 / 9.1 (2 q) |  |
| operator.queue | 8.1 / 15.5 (7 q) | 7.4 / 12.9 (7 q) | 6.5 / 12.8 (7 q) | 6.1 / 8.6 (7 q) |  |
| operator.exhausted | 194.3 / 210.2 (21 q) | 1.3 / 3.2 (2 q) | 168.4 / 192.7 (21 q) | 1.0 / 2.6 (2 q) |  |
| operator.series | 9.4 / 26.1 (1 q) | 7.9 / 8.2 (1 q) | 12.0 / 12.1 (1 q) | 12.1 / 13.5 (1 q) |  |
| operator.snapshot.cold | 732.5 / 810.3 (64 q) | 104.2 / 107.8 (46 q) | 678.8 / 694.1 (64 q) | 111.7 / 127.9 (46 q) | 1500 ms |
| operator.snapshot.warm | 725.4 / 735.8 (64 q) | 102.1 / 156.5 (46 q) | 686.9 / 706.0 (64 q) | 111.8 / 164.9 (46 q) |  |
| http.pulse_check | 12.1 / 15.4 (3 q) | 1.0 / 1.4 (2 q) | 31.6 / 35.2 (3 q) | 0.7 / 0.9 (2 q) | 30 ms |
| http.pulse_check_heavy | 12.2 / 13.6 (3 q) | 2.0 / 2.8 (2 q) | 32.5 / 38.3 (3 q) | 2.6 / 3.1 (2 q) |  |
| http.floor | 43.1 / 113.0 (23 q) | 49.3 / 65.5 (23 q) | 35.9 / 61.4 (23 q) | 35.3 / 54.9 (23 q) |  |
| http.search | 111.9 / 143.4 (24 q) | 121.8 / 148.7 (24 q) | 110.5 / 119.0 (24 q) | 101.5 / 107.0 (24 q) |  |
| http.entity_page | 24.9 / 36.0 (45 q) | 25.9 / 34.9 (45 q) | 10.6 / 18.2 (48 q) | 11.0 / 25.5 (48 q) |  |
| http.offer_page | 2.1 / 6.0 (4 q) | 26.7 / 33.5 (47 q) | 10.9 / 18.1 (49 q) | 10.4 / 12.6 (49 q) |  |
| http.signal_show | 22.1 / 30.1 (30 q) | 20.0 / 22.8 (30 q) | 9.9 / 13.3 (31 q) | 9.2 / 13.7 (31 q) |  |
| http.lines | 34.7 / 63.6 (16 q) | 12.3 / 14.7 (22 q) | 23.4 / 28.3 (16 q) | 6.0 / 12.2 (22 q) |  |
| http.line_thread | 10.7 / 30.4 (19 q) | 13.3 / 22.9 (19 q) | 9.3 / 14.8 (19 q) | 7.1 / 13.0 (19 q) |  |
| http.operator | 0.6 / 1.5 (0 q) | 5.2 / 126.4 (11 q) | 676.3 / 693.5 (65 q) | 4.2 / 116.7 (11 q) |  |


Reading notes: single scenarios vary by a few milliseconds from run to run (the first scenario after a long command starts with cold buffers: `inbox.open` on PostgreSQL measured 10.5 ms inside the full run and 3.1 ms on its own). `matcher.forSignal.fanout` is one wide offer against up to 3 000 interested people. `match.cycle` is one
scheduled tick with 400 changed signals and 400 changed profiles (a generous tick; a normal one is a few dozen). The
operator rows after the change run on the uncached path; the dashboard adds a 60 second cache on top. `http.operator` p95
is the first, uncached look.

## What was found and changed

1. **Two columns had no index at all, and one of them was a data integrity hole.** `pulse_profiles.user_id` and
   `pulse_notification_prefs.user_id` were declared `->constrained()->cascadeOnDelete()->unique()`. The trailing `unique()`
   is silently dropped by Laravel, so neither column had any index, on either engine: every profile lookup (matching, the
   Floor, search, the digest) was a sequential scan of 20 000 rows (`EXPLAIN` showed `Seq Scan on pulse_profiles`), and
   nothing stopped a second profile row for a person. New migration `2026_11_08_900000_pulse_perf_indexes.php` creates the
   unique indexes (a plain index plus a log line if a database already holds duplicates, checked first so a PostgreSQL
   migration transaction is never aborted), plus the other missing ones found in `EXPLAIN`: `pulse_entity_members` by user
   (who answers for which page: it was a seq scan on every inbox, badge and Floor request), `pulse_followers` by the followed
   person, `pulse_conversations` by page and date (reply behaviour), `pulse_messages` by line and id (latest message per
   line) and by author (account erasure), `pulse_matches` by seen date, `pulse_signals` and `pulse_profiles` by update time
   (what the match run reads), `pulse_profiles` by place (who a signal can reach). Idempotent, portable, `down()` drops
   exactly what `up()` made, tested up, down, up and twice.
2. **The inbox had an N+1.** `LinePresenter::present` asked `Access::side` which side of the line the viewer is on; for a
   person answering for a business page that is one query per line (27 queries for a page of 20 lines against 10 for 3 in the guard test; 19 for the
   heaviest user in the bench). The inbox now loads the person's pages once and passes them down (9 queries for any page of
   any size). The three line scopes (`visibleTo`, `unreadFor`, `awaitingAnswerFrom`) also took the pages as a sub-select
   inside an OR, which forced a scan of every line; they now take a plain list, and leave the branch out entirely when
   there are no pages (an empty list becomes `0 = 1`, and an OR with a constant stops SQLite using any index: pulse-check
   for an ordinary person went from about 31 ms to 0.7 ms on SQLite).
3. **`pulse:match` cost a million queries a tick.** `Matcher::forSignal` loaded the context of the same people again for
   every signal and wrote one row and one delete per person. New `Matcher::forSignals` handles a group of 25 signals
   together: the interested people of the group are loaded once (11 queries per 200 people), scored against every signal
   of the group, and written in batches; deletes only look for rows that exist. 1 071 031 queries became 22 246 and the
   tick fell from 254 s to 76 s on PostgreSQL. It is exact: the same 15 signals through the old and the new code wrote
   10 370 rows each and the whole `pulse_matches` table had an identical md5 (also guarded by
   `test_forSignals_writes_the_same_rows...`).
4. **`pulse:match` itself had three correctness gaps that were performance risks.** `--all` recomputed only the first
   `--limit` people and signals (it never iterated); a window with more changed signals than `--limit` silently dropped the
   rest because the "since" mark moved anyway; and a run had no time budget. Now: a cache lock (so a manual run and the
   10 minute cron cannot overlap), a time budget (default 240 s, none for `--all`), batches of 50 signals and 100 people with
   flat memory (62 MB peak), and a saved place: a run that runs out of limit or time stores where it stopped and the next one
   carries on, and the mark moves only when the whole window is done. All five pulse schedule entries now use
   `withoutOverlapping(minutes)` with an expiry, so a run killed mid-way cannot hold the shared crontab's slot for the
   default 24 hours.
5. **The operator page hydrated tens of thousands of rows to count them.** `Metrics::responses` loaded up to 20 000
   conversation models (445 ms, 102 MB) and `knockBudgetExhausted` walked every budget row with a user query each (21
   queries, 194 ms). Both are counted in SQL now (3 and 2 queries, 38 ms and 1.4 ms). The whole snapshot went from 732 ms to
   104 ms. `Metrics::cachedSnapshot()` keeps the dashboard's figures for 60 seconds per range, with a version key
   (`Metrics::flush()`) so there is a safe invalidation; the Livewire dashboard uses it.
6. **Reply behaviour was read as 4 000 rows per Floor, search or beat build.** `SignalPack::responsiveEntities` pulled up to
   4 000 conversation rows into PHP to compute a rate per business. It is one grouped SQL statement now (driver specific
   interval arithmetic for PostgreSQL, SQLite and MySQL), exact instead of capped.
7. **The digest built a digest for everyone due.** `pulse:digest` now asks one statement which of a chunk of due people have
   any candidate match, and skips the rest without a build; it loads the people of a chunk once instead of one `find` each.
   Building 1 000 digests dropped from 9.1 s to 5.7 s (13 969 to 9 851 queries) from the reply-behaviour change alone.
8. **Images.** `/media/{id}/{size}` now sends an ETag per variant and answers `If-None-Match` with a 304 after the same
   authorisation check (a paused offer's image is a 404, never a 304), `public, max-age=604800, immutable` for the sized
   derivatives of public listings (`private` for a signed-in viewer), and keeps `private, max-age=300` for message photos and
   avatars. A message photo now reserves its box (`aspect-ratio`) so a late image cannot push the newest message away.
9. **Front end.** The shell loaded a render-blocking Google Fonts stylesheet (Material Symbols) on every signed-in page for
   icons no view uses any more; removed. The Floor's Livewire snapshot dropped five keys no view reads (the knock pre-check,
   topics, the raw timestamp, the subject type and the beat url): 28.8 kB to 23.3 kB on `/floor`. `tailwind.config.js` scanned
   `storage/framework/views`, so the built CSS grew from 159 kB to 180 kB whenever compiled views existed (a developer
   machine, a CI that ran tests first); removed, the build is now 159.12 kB (27.9 kB gzip) every time. `public/.htaccess`
   gains `mod_deflate` and cache headers (hashed build files `immutable` for a year, fonts 30 days), each inside an
   `IfModule` so a host without the module is unaffected. The business page cover has `fetchpriority="high"`.
10. **A PostgreSQL-only bug, found by running the whole suite on PostgreSQL.** `pulse_entities.verification_status` is
    `varchar(12)` and the InfoDot link writes `infodot_member` (14 characters): SQLite does not enforce lengths, PostgreSQL
    does, so on production linking a page to an InfoDot company died with "value too long for type character varying(12)".
    Ten tests failed on PostgreSQL for this one reason. `2026_11_08_900100_pulse_widen_verification_status.php` widens it to
    24 on PostgreSQL and MySQL. The whole suite (1 359 tests) now passes on both engines.
11. **Two things the bench taught about SQLite.** A plain index on a column that is almost all NULL
    (`initiator_entity_id`) makes SQLite's statistics claim 60 000 rows per value and it stops using the other indexes: a
    partial index (`WHERE initiator_entity_id IS NOT NULL`, also created on PostgreSQL) fixes the plan (inbox 54 ms back to
    1 ms). And a date-only index on `pulse_conversations.created_at` made SQLite choose it for a 30 day window that is a
    third of the table and run six times slower, so that index is created on PostgreSQL only.

## Front end, measured

Headless Chrome through Playwright, Pixel-class emulation (390 x 844, device scale 2.625, touch), CPU throttled 4 times,
network throttled to 1.6 Mbit/s down, 750 kbit/s up, 150 ms round trip (the Lighthouse "slow 4G" profile), signed in as a
seeded person on the 20 000 person SQLite copy, served by `php artisan serve` behind a small proxy that gzips text like
`mod_deflate` (the built-in server does not compress). Three runs per page, once with the browser cache on and once with
it off.

| Page | LCP (cache off, 3 runs) | LCP (cache on, 3 runs) | CLS | HTML |
|---|---|---|---|---|
| /floor (20 beats) | 2.36 / 1.19 / 1.18 s | 1.96 / 2.10 / 1.90 s | 0.000 | 299 kB raw, 21 kB on the wire |
| /search?q=plumbing | 1.20 / 1.20 / 1.24 s | 1.06 / 1.01 / 1.02 s | 0.000 | 210 kB raw |
| /b/biz-421 (business page) | 1.17 / 1.12 / 1.13 s | 0.98 / 0.96 / 0.99 s | 0.000 | 46 kB raw |
| /lines/53315 (a thread) | 1.09 / 1.10 / 1.08 s | 0.98 / 0.99 / 1.00 s | 0.000 | 49 kB raw |

Before the front-end changes the same runs gave /floor 1.97 to 2.36 s warm and 1.2 to 2.5 s cold, the other pages within 50
ms, CLS 0.000. In other words the front-end changes did not move LCP in this sandbox. The sandbox has no outbound
network, so the Google Fonts request that was removed failed instantly here; on a real phone it is a blocking round trip
to another origin, which is the reason it was removed, but that benefit is reasoned, not measured.

What dominates `/floor`: its LCP element changes from the intro paragraph (1.04 s) to a beat title (1.28 s) to a reason line
(1.98 s), the moment the last 130 kB of fonts finish arriving behind 78 kB of JavaScript and 28 kB of CSS on a 1.6 Mbit/s
link. Dropping the JetBrains Mono preload brought it to 1.8 s in a trial, but the kind words above the fold are set in it, so
it was left alone. First-load weight: JavaScript 77.96 kB gzipped (app 20.99 kB, which includes Laravel Echo and Pusher even
when no Reverb key is set, plus Livewire with Alpine 56.97 kB), CSS 27.75 kB gzipped; fonts are the two used faces, latin
subset first, preloaded (Archivo latin 90 kB, JetBrains Mono latin 40 kB); the latin-ext face is only fetched when a
character needs it. Layout shift is zero: avatars, covers and gallery images already sat in boxes with fixed sizes or aspect
ratios (a message photo did not, and now does), and the webfonts are preloaded. Chrome's CSS coverage says 88 to 91 percent
of the CSS is unused on any one page: it is one bundle for every area (kit 27 kB, lines 21 kB, legacy 15 kB, me 15 kB, shell
13 kB, business 12 kB, floor 11 kB, public 10 kB, operator 7 kB raw), and the dark theme and media queries count as unused.
Splitting it per area would save about 9 kB gzipped on the Floor; not done, because it touches every layout.

## Remaining limits (what is not fast, why, and the cheapest honest limit)

* **Recomputing one person is 70 to 100 ms** (`forUser`: 600 candidate signals, 28 ms of scoring in PHP, 19 ms building the
  pack, 11 ms for the candidate query). That is PHP, not SQL. It is on no hot path (it runs for people whose own inputs
  changed or who have no matches yet), so a tick of 400 people is about 30 s. A full recompute of all 20 000 people is about
  25 minutes in one process (`pulse:match --all`, unlimited budget): schedule it off peak, never on request.
* **A wide signal costs about 190 microseconds per person it reaches**, capped at 3 000 people per signal by the existing
  `limit(3000)` (unordered, so which 3 000 is arbitrary: a decision for the product owner, left alone). One national offer is
  about 0.6 s; 400 of them in one tick are the 76 s above. A tick that exceeds its budget now saves its place instead of
  running over.
* **`pulse:index` is 43 s for 51 000 subjects**, 125 000 queries (2.4 per subject: a read, and a write only when something
  changed). It scales linearly, so the 3 minute target holds to about 200 000 subjects; past that, load the existing signals
  of a chunk in one query before the loop. Unchanged by this pass.
* **Search is a `LIKE '%word%'` scan** over live signals: 72 to 102 ms p95 at 34 027 signals, linear, so the 300 ms target
  holds to roughly 100 000 signals. The brief's trigger for a normalised search column or tokenised table (p95 above
  300 ms at 20 000 signals) is not met by a factor of three, so none was built.
* **A business page runs 45 queries** (about 30 ms): the verification summary computes the page's statistics three times
  (`EntityView`, `VerificationService::summary` and its ladder). Not changed: each is cheap and the page is far inside any
  target, and the clean fix is to pass the figures down, which touches three modules.
* **The Floor's Livewire snapshot is still 23 kB** and is posted back with every action. The real fix is to keep beat ids
  in the component and the beats in a computed property; that is a refactor of the Floor views, left to the UI owners.
* **gzip is assumed.** The measurements gzip at the proxy. `public/.htaccess` now asks Apache to do it, but I could not
  check that the shared host honours it: look at the response headers once after deploy (HTML 299 kB raw is 21 kB on the
  wire, and 1.5 s of transfer on the slow profile if it is not compressed).
* **SQLite statistics.** On SQLite run `ANALYZE` after bulk loads (the bench does); without statistics SQLite can pick the
  wrong index, as item 11 shows. PostgreSQL's autovacuum does this on its own.

## How to re-run

```
createdb pulse_perf                                # the name must contain "perf" or "bench"
export DB_CONNECTION=pgsql DB_DATABASE=pulse_perf DB_USERNAME=... CACHE_STORE=array
php artisan migrate
php artisan pulse:bench --seed                     # about 2.5 minutes to seed, then the timings (about 6 minutes)
php artisan pulse:bench --only=floor,search,inbox  # groups: floor search matcher match index digest inbox thread operator http
php artisan pulse:bench --scale=0.1 --runs=3       # a quick smoke run at a tenth of the size
php artisan pulse:bench --full-match               # also runs pulse:match --all (about 25 minutes)
php artisan pulse:bench --json=result.json         # write the numbers
dropdb pulse_perf
```

`pulse:bench` refuses to run unless `APP_ENV` is `local` or `testing` (or `--force-i-know`) and refuses a database whose name
does not contain `perf` or `bench` (or an in-memory SQLite) unless `--force-i-know` is also given, because `--seed` wipes
every `pulse_*` table, the notifications and **every user**. The same works on SQLite: `touch /tmp/pulse_perf.sqlite`,
`DB_CONNECTION=sqlite DB_DATABASE=/tmp/pulse_perf.sqlite php artisan migrate`, then the same bench commands.

## What guards this

`tests/Feature/Discovery/PerformanceGuardTest.php` (15 tests), plus changes to `PulseCheckEndpointTest` and
`MediaServiceTest`: the Floor (first and later batch), search, the inbox, pulse-check and the operator snapshot cost the same
number of queries for a small and a large data set (an N+1 fails them; the inbox test failed on the old code with 27 against
10); the visible-lines scope no longer carries a sub-select; the dashboard cache is reused per range and flushable;
`forSignals` costs the same queries for 8 and 48 people, writes the same rows as `forSignal` and drops people who stopped
matching; every pulse command is scheduled `withoutOverlapping` with an expiry of an hour or less; `pulse:match` skips while
the lock is held and saves its place and carries on when it hits its limit; the migration goes up, down, up and twice, and
survives an already duplicated profile table; media ETag, 304 behind authorisation and the cache headers. The whole suite
passes on SQLite (1 359 tests, 7 skipped) and on PostgreSQL (1 359 tests, 8 skipped, the extra one reads `sqlite_master`).
