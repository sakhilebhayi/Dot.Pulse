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
| 15 | Mobile and desktop usable | PASS (after fix D15) | `s15-responsive.mjs`, 55 checks, 180 page loads |
| 16 | No fake activity, metrics or dead controls | PASS | `s16-crawl.mjs`, 49 pages, `crawl-findings.json` |
| 17 | Workflows have automated tests | PASS (4 gaps closed) | matrix below, `s17-gaps.mjs` 19 checks |
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
| D15 | 15 | Phone tap targets under 44px (tag remove, place clear, two disclosure rows). | CSS, phone media query. | `s15` |
| D14 | 9 | The transactional email greeted "Hello!" and signed "Regards" (framework default). | "Hi {first name}," and "Dot.Pulse". | `..._the_transactional_mail_greets_the_recipient...` |

## Criterion 15: mobile and desktop (what was driven)

18 page views (landing, sign in, Floor, Work, a beat in full, search, Lines, a thread, profile, notifications, privacy, new need, a business page, an offer, offers management, the moderation queue, the operator page, the welcome flow) at 1440, 1024, 768, 390 and 360 wide, light and dark: 180 loads, each measured for horizontal overflow, clipped text and (phones) tap target size. axe-core ran at 1440 light and 390 dark on every one: zero serious or critical findings.

| Check | Result |
|---|---|
| No horizontal overflow, 180 loads | PASS |
| No clipped text | PASS |
| Tap targets at least 44px on phones | PASS after fix D15 (before: tag remove buttons 28px, "Clear" 32x20, the Filters and "Show the numbers as a table" disclosure rows 28 and 32px) |
| axe, zero serious or critical (light desktop, dark phone) | PASS |
| Bottom bar: five destinations, side rail hidden on phones, navigates | PASS |
| Compose sheet: opens with focus inside, keeps focus while tabbing, Escape closes, focus returns to the button | PASS |
| Account menu opens from the keyboard, Escape closes | PASS |
| Reduced motion: no animation or transition over 50ms, meter drawn complete | PASS |
| Keyboard only: Tab to Knock on the Floor, Enter opens the sheet with focus on its first field, type, Enter sends, Tab to "Open the line", Enter reaches the thread | PASS |
| No console errors | PASS |

D15 (criterion 15): phone tap targets raised to 44px in `resources/css/pulse/me.css` (last block, phone media query). The toast close and action buttons were raised to 44px with D10. Regression: the browser spec (`s15`); a CSS value cannot be asserted from PHPUnit in a meaningful way.

Screenshots to look at: `s15-_floor-390-dark`, `s15-_-390-light`, `s15-_b_moyo_books-390-light`, `s15-_lines-390-dark`, `s15-compose-sheet-390`, `s15-keyboard-focus-knock`. Seen and fine. One cosmetic note, not changed: the sticky composer on a line sits above the thread and, in a full-page screenshot, appears to overlap the handover guidance (a screenshot artefact of a sticky element; in a real viewport it scrolls normally).

## Criterion 16: nothing fake, nothing dead

The crawler (`s16-crawl.mjs`) visited 49 pages as the people who can reach them (signed out, a customer, a seller, an employer, a moderator, a brand new person), collected every visible link, button, toggle, tab, select and disclosure, and:

- fetched every distinct internal link and checked every in-page anchor;
- activated every distinct control once (skipping only sign out, erase, delete, block, report, remove, decline and similar one-way actions, which are exercised on purpose in `s10`, `s14`), in a fresh state, and asserted that it navigated, changed the page, opened a dialog, sent a request, changed a field's value or opened a window; a submit that the browser itself refuses for a missing required field counts as working;
- scanned all visible text for lorem ipsum, "N people viewing", sales counters, artificial urgency, placeholder text and template leakage;
- required every disabled control to carry a stated reason.

| Check | Result |
|---|---|
| Dead links (`href="#"`, empty, `javascript:`, missing anchor) | 0 |
| Broken internal links or pages | 0 |
| Fake activity, placeholder, urgency, template leakage | 0 |
| Disabled controls without a stated reason | 0 |
| Controls that did nothing | 0 confirmed. The final run flagged 5, each reproduced by hand and found working (3 search filter selects, the handover "Got it, hide this", and Send on an empty message, which answers "Write something first."); they are listed in `REVIEWED_WORKING` in the spec with the reason |
| Console errors, axe serious or critical | 0, 0 on all 49 pages |

Numbers on screen: every figure on the Floor sidebar, business pages, operator dashboard and need page is computed from rows (`Operator\Metrics`, `EntityService::stats`, `NeedPage::reach`, `TalentCohort` which shows nothing below a cohort of 5, `ResponseStats`). The "People it fits" and "Have seen it" counters on a need are counts of the author's own match and seen rows. Nothing says "N people viewing". The landing page samples are labelled "Example". `Operator\Metrics` carries no row text or names.

A note on the crawler, said plainly: it is a harness, and in its first full pass it mis-reported some controls (selects whose change is held in the form until Save, a button whose index drifted after a re-render). The harness was changed to detect a select's value, to retry by accessible name, and the five that its before-and-after snapshot still cannot see changing are listed in the spec as reviewed and working. No real defect of the "looks interactive, does nothing" kind was found.

Outside the crawl: leftover Jetstream pages exist at `/user/profile` (the account page the user menu links to) and the team pages; they were crawled and are inside the shell. The API tokens page is not linked from anywhere in the Pulse shell. Mail templates were rendered and read: the digest (HTML and text) and the transactional notice; both are in the Floor's voice, carry a reason to receive them and a way to change it, and no longer carry framework default wording (D2, D14).

## Criterion 17: workflows and their tests

Legend: PHPUnit = test classes under `tests/Feature`; E2E = a Playwright spec in `tests/e2e/pulse-b/specs`. All 20 workflows have both. Gaps found and closed: the four marked new.

| Workflow | PHPUnit | E2E |
|---|---|---|
| InfoDot sign-in, provisioning, team sync | `InfoDotRedeemTest`, `EcosystemSsoPostTest`, `InfoDotTeamSyncTest`, `RegistrationClosedTest`, `AuthenticationTest` | dev sign-in in every spec (the real redeem needs InfoDot's secret) |
| Welcome flow and the 18 and over gate | `Me\OnboardingTest`, `Me\OnboardedGateTest` | `s14`, `s20` |
| Profile, discoverability, public profile | `Me\ProfileEditorTest`, `DiscoverabilityTest`, `PublicProfileTest`, `AnonymousProfessionalSurfacesTest` | `s14`, `s16` |
| Business page, team, verification ladder | `Business\EntityFormTest`, `TeamAndVerifyTest`, `PublicPageTest`, `Entities\EntityServiceTest`, `VerificationServiceTest` | `s20`, `s13`, `s16` |
| Offers and roles (publish, hold, approve) | `Business\OfferManagerTest`, `RoleManagerTest`, `Entities\OfferServiceTest`, `RoleServiceTest`, `ContentGateTest`, `Seam\RealRulesGateTest` | `s20`, `s10` |
| Needs | `Business\NeedTest`, `Entities\NeedServiceTest`, `AcceptanceB\FirstFloorIsNotEmptyTest` (new) | `s20` |
| Content gate, moderation queue, listing decisions | `Business\ModerationReasonTest`, `Operator\QueueTest`, `Pulse\ModerationQueueTest`, `Discovery\LocalRulesTest` | `s20` (hold, approve, told), `s10` |
| Floor, matching, search, saved | `Floor\*`, `Discovery\MatcherTest`, `FloorTest`, `SearchTest`, `Floor\SavedTest` | `s20`, `s16`, `s17` (saved, new) |
| Knock, request, accept, messages | `Lines\LineServiceTest`, `ContactGateTest`, `KnockBudgetTest`, `LinesUi\InboxTest`, `ThreadTest`, `Seam\EndToEndSeamTest`, `KnockSheetTest` | `s09`, `s10`, `s20`, `s15` (keyboard) |
| Open doors | `Lines\DoorsTest`, `Floor\DoorTest` | `s17` (new) |
| Quotes (request, slip, compare, accept) | `Lines\QuoteServiceTest`, `LinesUi\QuotesTest` | `s17` (new) |
| Outcomes and earned reviews | `Lines\OutcomeReviewTest`, `LinesUi\OutcomesReviewsTest` | `s20` |
| Notifications, preferences, digest, unsubscribe | `Lines\NotifierTest`, `Me\NotificationsTest`, `Discovery\DigestTest`, `AcceptanceB\NotificationControlRegressionTest` | `s09` |
| Block, mute, report, knock allowance | `Lines\BlockMuteTest`, `ReportServiceTest`, `Pulse\ReportingTest`, `LinesUi\ReportTest`, `AcceptanceB\BlockHidesBothWaysTest` | `s10` |
| Privacy: consent ledger, export, erase | `Lines\PrivacyTest`, `ConsentLedgerTest`, `Me\PrivacyTest`, `AcceptanceB\PrivacyPageMarkupTest`, `ThrottleCountersAreSeparateTest` | `s14` |
| Anonymous professional never identified | `Lines\AnonymousInviteTest`, `LinesUi\AnonymousNonLeakTest`, `Discovery\HiddenFromEmployerTest` | `s14` (scans every response the employer receives) |
| Follow a business or person | `Pulse\FollowButtonTest`, `NewFollowerNotificationTest` | `s17` (new) |
| Community feed and posts | `Pulse\PulseTest`, `PostAuthorizationTest`, `PostShowTest`, `PrivateCommunityVisibilityTest` | `s10` (publish, report), `s16` |
| Operator dashboard, metrics, Analytics publisher | `Operator\*`, `Analytics\*`, `AcceptanceB\IntegrationsAreRealTest` | `s15`, `s16` (rendered and axe checked; no interaction beyond filters) |
| Errors and graceful failure | `AcceptanceB\LivewireFailureHandlingSourceTest`, `HealthEndpointTest` | `s19` |

Not covered by a real-browser test, and why: reporting a review (needs two confirmed outcomes and the blind period; PHPUnit covers it), the InfoDot redeem round trip (needs the secret), mail delivery (the log mailer is read), uploads of real photographs through the media pipeline beyond the failure path (`Entities\MediaServiceTest`).

New PHPUnit tests this run: 30 (`tests/Feature/AcceptanceB/`, plus extensions of `Discovery\DigestTest`, `Me\NotificationsTest`, `Lines\PrivacyTest`). Suite: SUITE_RESULT.


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
