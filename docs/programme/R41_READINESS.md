# R-41 Dot.Pulse: readiness report

Brief: `requirements/dot-pulse-transformation.md`. Plan: `R41_PLAN.md`. Audit: `R41_AUDIT.md`. Contract: `R41_DOMAIN_SPEC.md` and `R41_SERVICES_NOTES.md`. Design: `DESIGN.md` ("The Floor"). Written 2026-11-08.

## What Dot.Pulse is now

A business social platform built around one idea: **everything a person or business wants is a signal, Pulse introduces the signals that fit, and always says why.** Nothing reaches anyone without a yes.

| Area | What exists |
|---|---|
| The Floor | One discovery surface with a mode dial (All, Trade, Work, Local, Learn). A round of at most 20 beats with a diversity rule and an end ("That's the round"), a quiet "Seen earlier" strip, save, dismiss, "stop using this reason". Every beat shows its **sync meter**: your line and theirs meeting at the real reasons that match. Role-aware empty states |
| Businesses | Pages for sellers, employers or both: offers (product or service, price modes, quotes), roles (apply on Pulse or externally), hiring signal that lapses after 60 days, team with invitations, response stats from real rows. Drafts, held-for-review states with the reason, a verification ladder built **only from real data** (InfoDot team membership, email confirmed; everything else is labelled "claimed"; never "verified business") |
| People | Onboarding (18 and over, private defaults), individual and professional profiles, discoverability off / anonymous / open with a live preview of what an employer sees, "hide from this employer", open-to, contact policy |
| Needs, doors | "Looking for" needs (need, supplier, collaborator, investor, mentor, intro) and 24 hour / 1 week doors |
| Matching and search | Explainable, rule-based matching on declared data only (no special personal information or proxies), place hierarchy from a built-in gazetteer (753 places, approximate centroids, never a user's precise location), search over signals and pages with human reasons |
| Lines | Consented conversations: knock with a four-field card and no links in a first message, accept / "not now" / ignore, context card, messages, quote slips from up to three providers side by side, outcomes that **count only when both sides confirm**, blind earned reviews |
| Trust and safety | Knock budget, contact policies, blocks and mutes (both ways), reports into one moderation queue (posts, listings, reports, lines and reviews), local rules moderation that works without a model key, ring check on repeated mutual confirmations, k-anonymity for anonymous talent (cohort of at least 5), audit log that cannot be edited |
| Privacy | Consent ledger with one-tap withdraw, notification preferences (private by default, digest opt-in), export and erase with confirmation, retention purge (`pulse:retention`), documented in `R41_PRIVACY.md` |
| Operator | Real measured metrics only, each with its definition; "No data yet" instead of zeros; only both-confirmed outcomes counted; extended aggregate metrics for Dot.Analytics (inert until its three settings exist) |
| Design | The Floor system: cobalt, signal yellow, ink on cool chalk; Archivo and JetBrains Mono self-hosted; kit of about 30 components; light and dark; phone bottom bar; axe-core clean |

## Evidence

| Check | Result |
|---|---|
| PHPUnit (SQLite) | 1701 tests, 9543 assertions, 7 skipped |
| PHPUnit (PostgreSQL 16, the production engine) | 1701 tests, 8 skipped, all pass |
| Acceptance A (criteria 1 to 8, 11, 12) | 10 of 10 PASS in real Chrome, 314 checks: `R41_ACCEPTANCE_A.md`, `tests/e2e/pulse/` |
| Acceptance B (criteria 9, 10, 13 to 17, 19, 20) | PASS, about 320 checks, 180 page loads across 5 widths: `R41_ACCEPTANCE_B.md`, `tests/e2e/pulse-b/` |
| Security and privacy | 25 findings fixed (5 high, 12 medium, 8 low) plus the hardening round; route sweep over every role; `composer audit` clean; `npm audit` no high or critical: `R41_SECURITY.md` |
| Performance | Targets met on PostgreSQL with 20k people, 34k signals, 60k lines, 464k messages; `pulse:match` tick 254 s to 76 s; `pulse:bench` included: `R41_PERFORMANCE.md` |
| Outside design jury | Round 1: 6.8, which found five real bugs (all fixed). Round 2: 7.3 (Design 7.3, Usability 7.0, Creativity 7.6, Content 8.0), no blocking bug. A third pass of fixes (round 2 list) shipped afterwards and was **not re-scored** by a jury |

Cross-module seam test (`tests/Feature/Seam`) walks one story through all modules: offer, need, match, knock, line, messages, both-confirmed outcome.

## Not verified, and why

- **The real InfoDot hand-off.** Covered with a fake client and a real cross-site POST in the browser run; the live round trip needs the service secret (checked after deploy below).
- **Safari, Firefox, real phones.** Everything was driven in Chrome; phone widths were emulated.
- **Real mail.** Digest and notification mail were read from the log mailer. A real SMTP check is an owner step.
- **Jury re-score.** The last polish round (validation voice, Lines default tab, Floor revisit, card densities, reasons, business page layout) was checked with screenshots and axe, not by a fresh jury.
- **Search at scale.** Search is a LIKE scan: fine to about 100k signals.

## Honest limits (by design)

Matching is rule based and explainable, not learned. Verification stops at what InfoDot exposes today (team membership); InfoDot does not expose business verification to other platforms. Real time is short polling (30 s badge, 10 s open thread). New listings reach other people's Floor at publish (immediate match) and then every 10 minutes. A full recompute of 20k people takes about 25 minutes and runs off-peak. An anonymous card still shows up to 8 skills, the experience band and a free-text headline; the cohort rule protects against singling out but a distinctive headline can still identify someone, so the profile form warns about it. A ring spread across many accounts passes the pair check (there is no device signal).

## Owner list

1. **Production `.env`**: `APP_ENV=production`, `APP_URL=https://pulse.infodot.co.za`, `SESSION_SECURE_COOKIE=true`, `SANCTUM_STATEFUL_DOMAINS` includes the host (the deploy no longer depends on it), `ECOSYSTEM_SECRET_PULSE` / the InfoDot service secret set, a real mailer for the digest and invitations, leave `SECURITY_CSP_REPORT_ONLY` unset (the CSP is enforced).
2. **Privacy policy and terms**: the Jetstream views are restyled but not routed; the text is legal work. `R41_PRIVACY.md` lists exactly what Pulse holds and for how long, for the reviewer.
3. **InfoDot**: ask InfoDot to add a `verification` object to the redeem `teams[]` (Pulse already reads it if present, dormant and tested) so a verified business can be shown honestly.
4. **Dot.Analytics**: set `ANALYTICS_INGEST_URL`, `ANALYTICS_INGEST_SECRET`, `ANALYTICS_COMPANY_UUID`, and add the extra Pulse metric keys to its catalogue (it drops keys it does not list).
5. **Optional model second opinion** for moderation: `ANTHROPIC_API_KEY`; without it local rules decide.
6. **POPIA review** of retention numbers (`config/pulse.php` `retention`) and the age declaration approach with counsel.
7. **Cross-browser pass** on Safari and a real iPhone.
8. Items the build deliberately left: Matcher does not yet use the `suppliers` and `learning` open-to values; entity page runs 45 queries; Floor Livewire snapshot is 23 kB; undo toasts; community feed restyle depth.

## Deploy notes

New migrations run on deploy (schema, indexes, columns widened for PostgreSQL such as `verification_status`). The deploy workflow now runs `pulse:places:seed` after migrating (idempotent). Schedule additions, all `withoutOverlapping` with expiring locks because the host has one shared crontab: `pulse:index` (hourly), `pulse:match` (10 minutes), `pulse:digest` (hourly), `pulse:expire` (hourly), `pulse:lines:sweep` (hourly), `pulse:retention` (daily 03:40 UTC). Fonts and images are mirrored into the docroot by the workflow and smoke-checked.

## Post-deploy checks

1. `/` (landing), `/login`, `/up` answer 200; `/floor`, `/search`, `/lines` redirect guests to `/login`.
2. A font under `/fonts/` and the built CSS answer 200; the response carries an enforced Content-Security-Policy with a nonce.
3. `GET /auth/ecosystem` redirects to `/login`; a `POST /auth/ecosystem` without InfoDot's origin is refused.
4. Sign in through InfoDot: lands on onboarding (new) or the Floor (existing, with profile), and `/api/pulse/pulse-check` returns 200 in the same session (the Sanctum host fix).
5. Migrations applied; `pulse_places` has 753 rows; `php artisan schedule:list` shows the new commands.
6. Create a need, a page with an offer; they appear after publish; knock between two accounts; accept; message.

## Deployed

Merged as PR #30 (`78cc0a0`) and deployed on 2026-11-08 by Deploy to Production run 42429767626 (green, including the live smoke check). Eleven migrations ran on the host (`2026_11_08_100000` to `2026_11_10_110000`), the gazetteer loaded 753 places, and the scheduler cron lines were refreshed.

Checked live after the deploy: `/`, `/login`, `/up`, `/robots.txt` answer 200; `/floor`, `/search`, `/lines`, `/business/new` redirect guests to `/login`; the Archivo font and the built CSS answer 200 with the right type; the response carries an enforced Content-Security-Policy with a nonce, HSTS, nosniff, referrer and permissions policies; `GET /auth/ecosystem` redirects to `/login` and a `POST` without InfoDot's origin is refused; the development-only routes (`/dev/login/*`, `/dev/kit`) answer 404.

**Not checked live** (needs a real InfoDot sign-in, so it is the first thing to try): the hand-off lands on onboarding or the Floor, `/api/pulse/pulse-check` answers 200 in that session, and a first need, page and offer round trip. `/sitemap.xml` does not exist (it was never promised).
