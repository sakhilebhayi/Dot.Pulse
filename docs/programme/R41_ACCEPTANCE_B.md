# R-41 Dot.Pulse: acceptance B (criteria 9, 10, 13, 14, 15, 16, 17, 19, 20)

Written 2026-11-08 on branch `feat/r41-e2eb`. Everything below was driven through a real headless Chrome 157 against the real application (`php artisan serve`, a fresh SQLite database per spec, the real services, the real `log` mailer). Nothing was mocked inside the app. The harness is `tests/e2e/pulse-b/` (see its README). Agent A's seeder was not used: `tests/e2e/pulse-b/seed.php` builds its own people and listings through the real services.

Run it: `cd tests/e2e/pulse-b && npm install && node run.mjs` (all specs) or `node run.mjs s09 s14` (some). Output goes to `tests/e2e/pulse-b/out/` (gitignored): `report.json`, `crawl-findings.json`, `responsive-findings.json`, `shots/`, `server.log`.

## Result per criterion

| # | Criterion | Result | Evidence |
|---|---|---|---|
| 9 | Notification control | PASS (after 4 fixes) | `s09-notifications.mjs`, 28 checks; screenshots `s09-settings-defaults`, `s09-digest-on-instant`, `s09-unsubscribe-page` |
| 10 | Block and report | PASS (after 4 fixes) | `s10-block-report.mjs`, 33 checks; `s10-moderation-queue`, `s10-block-confirm`, `s10-privacy-blocks`, `s10-budget-exhausted` |
| 13 | Integrations use real APIs | PASS (after 1 fix) | `IntegrationsAreRealTest` (PHPUnit), `s13-integrations-claims.mjs`; landing claims table below |
| 14 | Private data protected | PASS (after 3 fixes) | `s14-private-data.mjs`, 41 checks; `s14-employer-thread`, `s14-pro-privacy-ledger`, `s14-age-gate` |
| 15 | Mobile and desktop usable | see section 15 | `s15-responsive.mjs`, `responsive-findings.json` |
| 16 | No fake activity, metrics or dead controls | see section 16 | `s16-crawl.mjs`, `crawl-findings.json` |
| 17 | Workflows have automated tests | see section 17 | matrix below, `s17-gaps.mjs` |
| 19 | Graceful errors | PASS (after 2 fixes) | `s19-errors.mjs`, 48 checks; `s19-error-*`, `s19-livewire-*` |
| 20 | Journeys without an administrator | PASS (after 2 fixes) | `s20-journeys.mjs`, 28 checks; `s20-a*`, `s20-b*` |

## Defects found by the browser runs, and fixed at the root

Every fix has a PHPUnit regression test (all under `tests/Feature/AcceptanceB/` unless noted). Trunk baseline was 1394 tests, 7 skipped.

| # | Criterion | Defect | Fix | Regression test |
|---|---|---|---|---|
| D1 | all | `pulse-realtime.js` logged `console.error("Laravel Echo not found")` on every page whenever Reverb is not configured (the stated production state: the host has no daemon). Every spec's "zero console errors" failed. | Quiet return; `bootstrap.js` already warns once. | `LivewireFailureHandlingSourceTest::test_realtime_being_unconfigured_is_not_a_console_error` |
| D2 | 9 | The plain text part of the digest escaped `&` as `&amp;`, so its signed unsubscribe link answered 403 for anyone reading plain text. | `{!! !!}` in `mail/pulse/digest-text.blade.php`. | `NotificationControlRegressionTest::test_the_plain_text_digest_carries_a_working_unsubscribe_link` |
| D3 | 9 | A person who turned on "Send me a daily summary" while conversation email was "Right away" (the state onboarding saves) never received a summary: `DigestSender::enabled` and `pulse:digest` required frequency daily or weekly. | Instant people may opt in (daily); only "off" blocks. | `..._the_summary_is_sent_to_someone_whose_conversation_email_is_right_away`, `..._the_digest_command_picks_up_instant_people...`, `..._off_still_means_no_summary` (and `DigestTest` updated) |
| D4 | 9 | A "Matches" switch (in app and by email) in Settings and onboarding controlled nothing: no code sends a match notification, matches live on the Floor and in the opt-in summary. | Row and onboarding toggle removed. | `..._the_settings_page_has_no_matches_switch_because_nothing_sends_one` |
| D5 | 9 | Copy said quiet hours "hold" email and daily/weekly "fold" things into a summary. Neither is true: email in quiet hours or with daily/weekly is not sent, the item waits in the app; the summary lists matches only. | Copy made true. | covered by D3/D4 tests and `s09` |
| D6 | 10 | **Blocking a business page did not hide it.** It closed the lines and wrote the block, but the page stayed on the Floor, in search, in suggestions, in the digest and reachable by URL. A page that blocked a person also stayed visible to them, and the page's people still saw that person's needs. | `ContextLoader` (both loaders) puts blocked pages in the muted-pages set and blocked people into the page members' blocked set; `SearchService::suggest`; `PulseEntityPolicy::view` and `PulseNeedPolicy::view` return 404 both ways (`BlockService::pageHiddenFrom`). | `BlockHidesBothWaysTest` (4 tests) |
| D7 | 10, 14 | "Withdraw" (consent ledger) and "Unmute" buttons carried a literal `@js(...)` handler (Blade directives are not compiled inside a component tag): `Invalid or unexpected token` and the buttons did nothing. | Plain `<button>` with the directive. | `PrivacyPageMarkupTest` |
| D8 | 14 | `throttle:3,10` (data export) and `throttle:120,1` (badge poll) shared one counter per person (a numeric throttle keys by person only). "Download my data" answered 429 on the first click. | Each numeric throttle now has its own prefix. | `ThrottleCountersAreSeparateTest` (2 tests) |
| D9 | 14 | Erase marked talent signals and needs `removed` but left the headline, skills and need text in the rows, and a copy of the talent card in the other side's line. | Words blanked, line snapshot cleared. | `Lines/PrivacyTest` extended |
| D10 | 19 | A failed Livewire call (offline, 5xx, 404) left the form locked for good: Livewire only unlocks on a response that never arrived. Session expiry used the browser's `confirm()` box. A 5xx used the raw error overlay. | `shell.js`: every failure class is said in the Floor's voice (sticky "Reload" action on 419, "No connection" with what to do, "broke on our side" on 5xx), `preventDefault` stops the overlay, the form is unlocked. Toast close/action targets are 44px. | `LivewireFailureHandlingSourceTest` (3 tests) + `s19` |
| D11 | 20 | A new person who posted a need saw "Nothing matches yet" until the ten minute scheduled job ran; the seller whose offer fits did not see the need until then either. | `Matcher::refreshAfter` called on need, offer and role publish and when onboarding finishes. | `FirstFloorIsNotEmptyTest::test_posting_a_need_matches_it...` |
| D12 | 20 | A held listing told its owner "waiting for a human" with no time. | One sentence for every surface (`pulse_review_note()`): who looks, in what order, the target (config `pulse.moderation.review_target`, default one working day, said as a target) and that they are told the moment it is decided. | `FirstFloorIsNotEmptyTest::test_what_a_person_is_told_about_a_held_listing...` |
| D13 | 13 | The Jetstream starter `components/welcome.blade.php` (links to laravel.com, laracasts, tailwindcss.com) was still in the tree. Unused, but decorative and external. | Deleted. | `IntegrationsAreRealTest::test_no_view_links_to_a_site_that_is_not_ours_or_infodots` |
| D14 | 9 | The transactional email greeted "Hello!" and signed "Regards" (framework default). | "Hi {first name}," and "Dot.Pulse". | `..._the_transactional_mail_greets_the_recipient...` |

SECTIONS_15_16_17

## Criterion 9: notification control (what was driven)

Two people in two browser contexts. Defaults: digest off, no preference row written by viewing the page. A knock reaches the seller in the app and by one email (link into Lines, no marketing); turning "By email" off for conversations leaves the in-app notification and sends no email; "Daily" frequency and whole-day quiet hours send no email but write the in-app item; accept, message and outcome reach the asker; the digest is sent only when there is something real (a person with no matches gets none), carries a reason for each item, a signed unsubscribe link and `List-Unsubscribe` headers; the link works logged out, switches off only that person's digest, and the same signature aimed at another user id answers 403. "Match" as an instant event does not exist (see D4).

## Criterion 10: block and report (what was driven)

Reports of a message, a line, a listing (role) and a post reach `pulse_reports` and the moderation queue; a moderator confirming a spam report shrinks the sender's allowance (the sender sees the smaller number in the knock sheet) and the reporter is told; a moderator removing a listing writes a reason the author reads in plain words; blocking a page closes its lines and now hides it everywhere (D6); blocking a person from a need hides both sides; a topic is muted from "Stop using this" on a reason, listed in Privacy, and unmuted (D7); an exhausted knock allowance says so with a refill date. Not driven in the browser: reporting a review (needs two confirmed outcomes and the blind period; covered by `Lines/ReportServiceTest` and `LinesUi/OutcomesReviewsTest`).

## Criterion 13: integrations

Outbound HTTP exists in exactly three classes (guarded by `IntegrationsAreRealTest`):

| Integration | Real? | Behaviour proven |
|---|---|---|
| InfoDot sign-in and team sync (`IdentityClient`, `ProvisionUserFromInfoDot`) | Real (InfoDot's `POST /api/ecosystem/redeem`, bearer secret) | `InfoDotRedeemTest`, `EcosystemSsoPostTest`, `InfoDotTeamSyncTest`; the browser harness signs in through a local-only dev route instead (`/dev/login-b/{id}`, absent outside local and testing) |
| Dot.Analytics publisher (`AnalyticsClient`) | Real | inert unless url, secret and company are all set (`test_it_is_disabled_when_the_url_secret_or_company_is_blank`, `IntegrationsAreRealTest`); HMAC-SHA256 over `timestamp.body` with `Http::fake` (`test_it_posts_the_payload_signed_with_hmac_over_timestamp_dot_raw_body`) |
| Optional model second opinion on moderation (`AiModerationService`) | Real, optional | fails closed, inert without a key (`AiModerationFailClosedTest`) |

There is no `/go/{platform}` route in Pulse (that launcher belongs to InfoDot) and no view links to another Dot platform or names one; a test fails the day either appears. No decorative badges (`s13`).

### Landing page claims, each checked

| Claim | True? | How |
|---|---|---|
| "Introduces people and businesses that fit, and always says why" | Yes | every beat in `s09`, `s20` has reasons; no `pulse_matches` row has an empty reason list (`s13`) |
| "Tap a reason to stop Pulse using it" | Yes | `s10` section E |
| "Four fields, no links" | Yes | `s13` counts four fields and gets a refusal naming the link |
| "Accept, ignore, or say not now" | Yes | `s09`, `s10` |
| "Links and files open up after a yes" | Yes | composer hint and photo input appear only after a reply (`s19` upload) |
| "Block or report at any time" | Yes | `s10` |
| "Both sides confirm the outcome. Only then does Pulse count it." | Yes | `s20` |
| "Who may reach you: anyone, only through something of yours, by request, or nobody" | Yes | four choices (`s13`) |
| "Who may find you: off, anonymous, open" | Yes | three choices (`s13`), non-leak in `s14` |
| "A daily digest goes out only when it holds something real" | Yes, after D3 | `s09` |
| "Verified means checked ... nothing is marked verified that was not" | Yes | unlinked page shows "not verified" and a ladder of checked, claimed, missing (`s13`) |
| "No badge is for sale", "Free to answer", "Never for sale" | Yes | no payment or upgrade wording on any crawled page (`s13`, `s16`) |
| "Businesses cannot send marketing through Pulse" | Yes | no broadcast surface in any manage tab (`s13`) |
| "A fair allowance ... refills when people reply" | Yes | `s10`; refill covered by `Lines/KnockBudgetTest` |
| "Adults only" | Yes | `s14` age gate |
| "Sign in once with InfoDot. The same identity carries across the Dot platforms." | Not verifiable from Pulse | it is a statement about InfoDot's SSO (R-20, `/go/{platform}` on InfoDot). Pulse has no link to another platform. Left as is, flagged under "not verified". |
| Sample business, text and ladder are "Example" | Yes | each sample is labelled (`s13`) |

## Criterion 14: private data (what was driven)

Employer and anonymous professional in two contexts. The scan looked for her name parts, email, current employer name and profile address in: the rendered text and DOM (including every `wire:snapshot` attribute), view-source of `/work` and `/floor`, search, the talent detail page, the invite sheet, the thread, the notification list, the inbox, every email to the employer, and every response body the employer's browser received. Zero hits (`s14`). Her profile address is a 404 for an employer; once she withdraws consent the talent card address is a 404 and she leaves the employer's Floor; the export downloads, is valid JSON and contains no one else's email; erase signs her out and blanks her words (D9); a person who has not declared 18 cannot get past the welcome flow and gets a specific error beside the field.

## Criterion 19: graceful errors (what was driven)

404, 403, 419, 429, 500, 503 each render in the Floor's voice with "Back to Pulse", no stack text, zero serious axe findings. Livewire: offline (a "no connection" message, form intact, the same click works once back online), a server exception inside a component (corrupted snapshot, contained message, no overlay), session expiry (lands on sign-in, no native box), a genuine 419 answer (sticky "Reload" action), validation errors specific and bound to their field (`aria-invalid`, `aria-describedby`), a bad upload says why. Note: with same-origin request verification a browser on this origin can no longer produce a 419 from a stale page; session expiry redirects to sign-in, which is what the run shows. The 419 handler stays for cross-site and proxy cases.

## Criterion 20: journeys (what was driven)

A. A brand-new person (InfoDot provisioned, no profile row): welcome flow, first Floor (not blank), posts a need, sees matches with reasons at once (D11), knocks, the seller accepts, they talk, both confirm an outcome, the person writes a review. B. A new business owner without an InfoDot company: creates a page (live and approved at once, because the rules allow clean text), publishes an offer (live at once) and a customer finds it by search. Text with a phone number is held; the owner is told why, who looks, in what order and the target (D12); a moderator approves it from the Listings tab; it goes live, is found by search and the owner is told. The "fair path" the brief asks for is already how `LocalRules` works (only flagged text waits); no change to the gate was needed. The no-rules fallback (`pending` for anyone without an InfoDot company) stays as the conservative failure mode.

## Not verified

- Real InfoDot sign-in against InfoDot (needs the service secret). The redeem client is covered with `Http::fake` only.
- Delivery of real email (the `log` mailer was read). Rendering in real mail clients.
- Reporting a review in a browser (see criterion 10).
- Safari and Firefox. Real phones. Chrome 157 headless only; "phone" is Chrome emulation.
- The statement that the InfoDot identity carries across the Dot platforms (InfoDot's claim, not Pulse's).
- There is no privacy policy or terms page (the Jetstream `terms` and `policy` views exist but are not routed and nothing links to them). That is owner and legal text (POPIA), not something to invent here.
- Load behaviour (the performance agent's job).
