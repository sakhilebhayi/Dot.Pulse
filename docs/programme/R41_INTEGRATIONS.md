# Dot.Pulse integration investigation (read-only), 2026-11-07

## How this was checked, and caveats

- Local clones under /Users/sakhilebhayi/Dot are STALE. InfoDot local main is 153 commits behind origin/main and its fetched remote refs stop at PR #148 (29 Oct). R-20, R-29 and R-33 are NOT in the local clone. They are on GitHub main (PRs #180 R-20, #185/#188/#189 R-29, #190-#196 R-33). I read GitHub main with read-only `gh api` calls. Dot.Pulse local is 31 behind, Dot.Emall 42, Dot.HR 38, Dot.Agents 27, Dot.Memory 25. Several clones sit on feature branches. All findings below come from GitHub default branches unless stated.
- Dot.Analytics: its GitHub default branch is `feature/ecosystem-sso` (140 ahead, 5 behind `main`); deploys run from it. `main` is stale and has no `/api/ingest/snapshots`.
- Light unauthenticated GETs against public hosts only (status codes, public HTML/JSON). No app was run, no .env read, nothing modified. Fetched copies of source files sit in the scratchpad subfolders infodot/, pulse/, plat/.
- "Live" = deploy workflow ran successfully recently AND the host answered 200 today. It does NOT prove the host's .env holds integration secrets (cannot be seen).

## 1. InfoDot

### 1a. What a "business" is
- A business is a **Jetstream team** (`teams` table, non-personal: `personal_team = false`). It has `uuid`, `code`, `industry`, `name`, members in `team_user` with roles owner/admin/manager/member (wire value for older platforms: owner/admin/editor; the real role is in `infodot_role`).
- R-29 identity: `team_profiles` (trading_name, description, industry, category, business_type, products_services, location, regions, website, contact_email, contact_phone, registration_number/country, established_on, size, links, `visibility` public|members|private, `public_contact`, `accepts_enquiries`, `restricted_at`), plus logo/cover/gallery attachments. Default visibility is `private`; profiles that were already filled in were set to `members`.
- R-33 directory: `business_categories` (19 seeded), `business_category_team`, `business_services`, `business_projects`, `business_locations`, `business_announcements`, collections, features (paid/featured placement), insights, enquiries and Inbox (`enquiries`, `enquiry_messages`), reports, investigations, restrictions, appeals, relationships between businesses, identity-protection (`verified_identities`, `identity_conflicts`).
- Public pages (HTML only): `GET /businesses` (directory, filters q, category, location, region, size, type, verified), `GET /business/{team:uuid}` (page opens without sign-in only if visibility=public), `GET /sitemap.xml`. Authenticated: edit, message/inbox, save, report, standing, appeals, insights.
- **No JSON/API for any of it**: no JSON-LD found in the business page view, no `/api/business*` route. Routes in `routes/api.php` are only the ones listed in 1b. Live check today: `/businesses` 200, sitemap 200 but contains zero `/business/` entries (no public business listed in the sitemap right now).

### Verification (R-29 Part B) - see the dedicated section further down.

### 1b. Server to server API that exists (InfoDot `routes/api.php` on main)
| Route | Auth | Purpose | Usable by Pulse |
|---|---|---|---|
| `POST /api/ecosystem/redeem` | `Authorization: Bearer <ECOSYSTEM_SECRET_PULSE value>` + body `{token, platform}` | Proves a single-use sign-in token, returns user + teams | Yes (Pulse already uses it) |
| `POST /api/ecosystem/token` | Sanctum user token | Mint a handoff token for a signed-in user | No (user-facing) |
| `POST /api/ecosystem/resources`, `DELETE /api/ecosystem/resources/{uuid}` | same Bearer service secret | Register pointers to objects; hard-coded authority: only `files => [file]`, `docs => [document]` | No. Pulse has no authority entry |
| `GET /api/knowledge/candidates` | HMAC (Brain secret) | Brain pulls Verified-library candidates | No |
| `POST /api/billing/ticket-events`, `POST /api/seminars/livekit-webhook` | signed callbacks | Billing / LiveKit | No |
| `GET /user` | Sanctum | current user | No |
| OIDC: `/.well-known/openid-configuration`, `/oauth/authorize`, `/oauth/token`, `/oauth/userinfo`, `/oauth/jwks`, `/oauth/end-session` | OAuth2 code + PKCE S256 | R-20 identity provider | See 1c |

- **No endpoint exposes business data, verification status, business directory, or a list of members.** Strict answer: nothing exists.
- Auth method for service calls: per-platform secret `ECOSYSTEM_SECRET_<KEY>` (key `pulse` is in the allow-list in `config/ecosystem.php`), compared with `hash_equals` against the Bearer token. The redeem call is NOT HMAC-signed; it is a bearer secret over HTTPS. Registry check: platform must be `active` in `config/ecosystem.php` (Pulse is `active => true`).
- Rate limit: `throttle:ecosystem-redeem` = 60 per minute per IP. (Also used by the resources routes.) Token endpoint `oauth-token` has its own limiter.
- InfoDot to platform direction (signed webhooks, HMAC-SHA256 over `"{timestamp}.{body}"`, headers `X-InfoDot-Timestamp`, `X-InfoDot-Signature: sha256=...`): membership events (`member.removed`, `member.role_changed`, `team.deleted`) to `ECOSYSTEM_MEMBERSHIP_PLATFORMS` (default `files,docs` only) at `POST {platform}/api/ecosystem/membership`; header search to `ECOSYSTEM_SEARCH_PLATFORMS` (default `files,docs`) at `/api/ecosystem/search`. Pulse implements neither endpoint (its `routes/api.php` has only `/user`), and is not in either list.
- Launch: `GET /go/{platform}` (auth + verified, throttle 30/min). It renders a page that POSTs a 60 s single-use token to `{platform}/auth/ecosystem`, with optional `?redirect=/path` (validated path only). Platforms flagged `sso => false` (mines, charts) just get a plain redirect to their front door, no sign-in.

### 1c. OIDC provider (R-20) - deployed and answering
- Live today: `https://infodot.co.za/.well-known/openid-configuration` 200, `/oauth/jwks` 200.
- Scopes: `openid`, `profile`, `email` only. Claims: `sub` (= users.uuid), `iss`, `aud`, `exp`, `iat`, `nonce`, `name`, `email`, `email_verified`. Userinfo returns `sub`, plus `name` for profile, `email`/`email_verified` for email.
- **No business, team, role or verification claims.** The config comment says platform entitlements are deliberately not scopes. Strictly: no.
- Flow rules: code flow only, PKCE S256 only, RS256, back-channel logout supported. Relying parties must be registered in the `dot_platforms` table (`sso:platform register`). The relying-party client package `packages/dot-sso-client` is incomplete (backlog: SsoController, BackchannelLogoutController etc. absent), and no platform consumes OIDC yet. Pulse cannot use OIDC today without writing its own client.

### 1d. What Dot.Pulse uses today (GitHub main, 858bd9b)
- `app/Services/InfoDot/IdentityClient.php`: `POST {INFODOT_REDEEM_URL}` (default `https://infodot.co.za/api/ecosystem/redeem`) with `Bearer INFODOT_SERVICE_SECRET`, body `{token, platform: "pulse"}`, 5 s timeout. 5xx/429/connection error -> `InfoDotUnavailable`; 4xx -> null.
- `app/Http/Controllers/Auth/EcosystemAuthController.php`: only `POST /auth/ecosystem` (GET is ignored and sent to /login), Origin/Referer must be the InfoDot host, honours the validated `redirect` path.
- `app/Actions/Ecosystem/ProvisionUserFromInfoDot.php`: matches/creates the local user by lower-cased verified email, creates personal team and `PulseProfile` (role `customer`); never reads platform roles from InfoDot.
- `app/Actions/Ecosystem/SyncTeamsFromInfoDot.php`: reads `teams[]` (`uuid`, `name`, `personal`, `role`) and `current_team_uuid`; creates a local team keyed by `teams.infodot_team_uuid` only for owner/admin; membership/role follow InfoDot (owner/admin -> admin, editor -> editor, else viewer); detaches stale ones; switches current team. It reads the wire `role`, not `infodot_role`.
- `php artisan infodot:link-team {team} {uuid} [--promote]` links an existing Pulse team to an InfoDot company uuid.
- Pulse local data: it is "one global network"; content has no company ownership (`config/analytics.php` says so). `PulseProfile.is_verified` exists but is a Pulse-local person flag, not InfoDot business verification.

**Exact redeem response (InfoDot main, `EcosystemRedeemController`):**
```
{ "user":  { "id", "name", "email", "email_verified": true },
  "teams": [ { "id", "uuid", "name", "personal": bool, "role": owner|admin|editor, "infodot_role": owner|admin|manager|member } ],
  "current_team_id": int|null, "current_team_uuid": uuid|null }
```
- Contains NO: business profile, slug, industry, category, logo, website, registration data, verification status, directory visibility, restriction state, member list. `user.id` is InfoDot's numeric id; the stable ecosystem id (`users.uuid`) is NOT in the redeem response (it is the OIDC `sub`).
- Refusal cases (identical 403/401 body `{"error":"invalid_request"}`): wrong secret (401), platform not active, token unknown/wrong audience/expired, user email not verified. Token is deleted on first use.

## InfoDot business verification: exactly what Pulse can read today

**Short answer: nothing, programmatically.** There is no API, no claim, no redeem field and no webhook that carries business verification.

What exists inside InfoDot (R-29 Part B, live on main):
- Table `business_verifications`: one row per application, per `team_id`. `status` values: `pending` (Submitted), `under_review`, `info_required`, `verified`, `rejected` (Not approved), `suspended`, `revoked`, `expired` (Lapsed), `withdrawn`, plus internal `superseded` (never shown). Category: registered_company | sole_proprietor | other_entity.
- Evidence: registered name, trading name, registration number (encrypted), country, website, official email (confirmed by a 15-minute emailed code, 5 attempts, must be on the website's own domain, free-mail domains such as gmail.com refused), phone and address (encrypted), applicant role, declaration, and up to 6 private evidence documents. Reviewer-confirmed `checks`: `registration_document`, `official_email`, `representative_authority`, `operating_presence`. Approval needs >= 2 confirmed checks, one of them a document review.
- Who sets it: an InfoDot **moderator** (`User::isModerator()`) who is not a member of that business, through the reviewer desk `/moderation/verifications` (actions: review, info, approve, reject, suspend, reinstate, revoke, reconfirm). Applicants cannot set status. All changes are append-only `verification_events`. No company registry (CIPC) or SMS check exists; the config says so and the badge never claims either.
- Validity: 12 months (`expires_at`), renewal reminder 30 days before, daily expiry sweep (`SweepBusinessVerifications`). Renaming or changing the registration number/country/website of a verified business sends it back for review. Evidence of rejected/withdrawn applications is purged after 365 days.
- "Verified" = `Team::isVerified()` -> newest approved row, status `verified` and `expires_at` in the future (`isCurrent()`). Only that state shows the badge.
- Where it surfaces: the business page trust card ("Verified since <date>, review due <month>", "Not verified", "InfoDot is reviewing...") and the directory `verified=1` filter. HTML only, and only for businesses whose profile visibility is `public` (page opens without sign-in) or `members` (InfoDot-signed-in users). Private businesses are not visible at all.

What Pulse can actually do today:
1. Know that a person belongs to an InfoDot company (uuid, name, role) from the redeem response. Nothing about that company's verification.
2. Scrape the public HTML of `https://infodot.co.za/business/{uuid}` for public businesses. This is possible but fragile, unauthenticated, unsupported and not a contract; not recommended. (Pulse has the company uuid from the redeem response, so it could build the URL.)
3. Deep link a user to the business page: `https://infodot.co.za/business/{team uuid}` (works for public pages without sign-in).

What InfoDot would have to add (on their side): either `verification: {status, verified_at, expires_at, checks}` plus `profile_visibility` per team inside the redeem `teams[]`, or a signed read endpoint (for example `POST /api/ecosystem/business` with the existing Bearer service secret or HMAC, returning only public-safe fields for a team uuid), plus a webhook event for status changes. Must return only `verified` and its date for businesses that chose public visibility; never evidence or registration numbers.

## 2. Other platforms

Live signal: last deploy.yml run on default branch + host answers 200 + `active` flag in InfoDot `config/ecosystem.php`. DOT_BACKLOG.md (compiled 2026-11-05) does not state live/not-live per platform. Platform "live" below does not mean secrets are configured.

| Platform | Live | API for Pulse | Endpoints | Auth | Tenant model | Verdict for Pulse |
|---|---|---|---|---|---|---|
| **Dot.Pulse** (self) | yes. Deploy 2026-10-28 ok, host 200. No security headers (backlog) | n/a | Only `GET /user` (Sanctum). `POST /auth/ecosystem`. No `/api/ecosystem/membership` receiver | n/a | Own DB; Jetstream teams mapped by `infodot_team_uuid` | n/a |
| **InfoDot** | yes, 200; R-20, R-29, R-33 deployed | partial: redeem only | see 1b | Bearer `ECOSYSTEM_SECRET_PULSE` | teams (companies) | usable now for sign-in and team sync; needs work for business verification |
| **Dot.Emall** | yes. Deploy 2026-10-31 ok, 200; active=true | no | `routes/api.php` has only `GET /user` (Sanctum). Public storefront HTML: `/products`, store pages | Sanctum user token only | Team (+ `Store`, `Product`, orders, payments); seller store per team | no (no machine API). Deep link to storefront possible |
| **Dot.HR** | yes. Deploy 2026-11-01 ok, 200; active=true | no | `routes/api.php` only `GET /user`. Public HTML job board `GET /jobs`, `GET /jobs/{vacancy}` (throttle 120/min); `POST /jobs/{vacancy}/apply` needs a signed-in user; `GET /people/{handle}` professional profile | Sanctum user token only | Team (`HasTeamScope`, `infodot_team_uuid`) | needs work on their side for data; deep link usable (see below) |
| **Dot.Engage** | yes. Deploy 2026-11-07 ok, 200 | no | `routes/api.php` only `GET /user`. Contracts/signing are web routes | Sanctum | Team (`HasTeamScope`) | no |
| **Dot.Mines** (folder `mines`, repo Dot.Mines) | yes. Deploy 2026-10-28 ok, 200; `sso => false` in registry | no for Pulse's needs | Real versioned API `/api/v1/*` and unversioned `/api/*` (+ `/api/openapi.json`): machines, operators, geofences, alerts, integrations, reports, notifications, fuel, maintenance, webhooks, sync. All mining-fleet telemetry | Sanctum user token + `token.ability`, `ensure_team`, `throttle:api` | Team | no (wrong domain; per-user tokens; own sign-in, `/go/mines` just opens front door with NO SSO) |
| **Dot.Analytics** | yes but deploy of 2026-11-07 FAILED (previous 11-03 ok), host 200. Runs from branch `feature/ecosystem-sso`, not `main` | yes (write-only ingest) | `POST /api/ingest/snapshots` (throttle `analytics-ingest-edge`, per-platform rate limit 120/min default, body cap 64 KB). Also user-token `/api/v1/*` (platforms, metrics, reports) | HMAC-SHA256 over `"{timestamp}.{raw body}"`, headers `X-InfoDot-Timestamp`, `X-InfoDot-Signature: sha256=...`; secret `ANALYTICS_INGEST_SECRET_PULSE` on Analytics = `ANALYTICS_INGEST_SECRET` on Pulse. v1 API is Sanctum | InfoDot company uuid (`company_uuid` in body; unknown company -> 404) | usable now in code; needs owner action (set env on both sides + provide a company uuid). See section 3 |
| **Dot.Files** | yes. Deploy 2026-10-31 ok, 200 | partial | `POST /api/platform/files/{store,update,fetch,meta,list,trash}` (throttle 60-120/min); `POST /api/ecosystem/membership`, `/api/ecosystem/search` are InfoDot-facing | HMAC, headers `X-Dot-Platform`, `X-Dot-Timestamp`, `X-Dot-Signature: sha256=hmac("{ts}.{body}")`, 300 s window, secret `DOT_SERVICE_SECRET_<KEY>` | `team_uuid` (InfoDot company uuid); a platform sees only files it filed itself | needs work on their side: Files' `platform_secrets` has no `pulse` entry (and its own registry copy has `pulse` `active => false`), so Pulse cannot call it today |
| **Dot.Notify** | yes. Deploy 2026-11-01 ok, 200. PR #31 unmerged | partial | `POST /api/ingest/events` (body cap 64 KB, 120/min per platform+company; 202 new, 200 replay) | HMAC, same `X-InfoDot-Timestamp`/`X-InfoDot-Signature` scheme, secret `NOTIFY_INGEST_SECRET_<KEY>` (key list comes from the registry, so `pulse` is accepted once the env is set) | `company_uuid`; recipient by `recipient.email` or `user_email`; idempotency key required | needs work: (1) no `pulse` section in `config/notify_events.php` (catalogue has tasks, hr, auction, ehail, farms, billing); (2) the company must already have an active rule for the event, otherwise status `no_rule` and nothing is sent; (3) Pulse has no Notify client (backlog). Email channel works; SMS and push have no provider |
| **Dot.Agents** | yes. Deploy 2026-11-06 ok (earlier same-day failure), 200 | partial | `POST /api/v1/deployments/{id}/runs`, `GET /api/v1/runs/{run}`, `POST /api/v1/workflows/{id}/runs`, `GET /api/v1/agents`, skills/deployments CRUD, `POST /api/hooks/{uuid}` (signed webhook trigger); OpenAPI in `docs/openapi.yaml` | Bearer API key made per business in the Dot.Agents UI ("Business, API keys"), abilities read/write/run/approve, expires in 90 days; webhook uses HMAC `X-Dot-Timestamp`/`X-Dot-Signature` (5 min) | Business/organisation (`org.context`) | needs work: a generic "text task" API does not exist. It runs a business's configured agents; runs started via the API "need a person before it changes anything". Keys are manual, per business, not service-to-service |
| **Dot.Brain** | no. No web app; host `brain.infodot.co.za` does not resolve. A repository of docs, ADRs and Node services (brand-engine, guardian-trigger, autonomy-score) run by GitHub workflows; no deploy.yml | no | none (no `routes/` of its own) | n/a | n/a | no |
| **Dot.Memory** | yes (internal bridge service, hidden from users). Deploy 2026-10-31 ok, 200 | no for Pulse | `/api/intelligence/*`, `/api/ops/*`, `/api/guardian/health` (Sanctum guardian service token); `/api/participation/*` (HMAC, keyed by InfoDot user UUID, opt-in people only); `/api/knowledge-recommendations/*` (HMAC, one secret per consumer: infodot, brain) | Sanctum service token or HMAC per consumer | user uuid / records about contributions | no. Pulse is not a registered consumer; purpose-built for Brain/InfoDot |
| **Dot.Tasks** | yes. Deploy 2026-11-06 ok, 200 | no | `routes/api.php` only `GET /user` | Sanctum | Team | no (it is a Notify event source, not an API) |
| **Dot.Projects** | yes. Deploy 2026-11-06 ok, 200 | no | `routes/api.php` only `GET /user` | Sanctum | Team (`HasTeamScope`) | no |

### Specific questions
- **Deep links that work after InfoDot sign-in.** Mechanism: InfoDot `GET /go/{platform}?redirect=/path` posts a token to `{platform}/auth/ecosystem`, and the destination validates `redirect` as a plain absolute path. Receivers honouring the `redirect` field in their EcosystemAuthController (checked in code): Pulse, Emall, HR, Engage, Notify, Tasks, Projects, Files, Analytics (branch feature/ecosystem-sso), Agents (via HandlesEcosystemHandoff). Dot.Memory shows no `redirect` handling and is internal (never launched for a person). Dot.Mines (and Charts) are `sso => false`: `/go/mines` just opens the front door, no sign-in, no path. Dot.Brain is not launchable. Nobody has click-tested signed-in handoffs live (backlog: "Sign in on production and walk each platform... can't be done from here"), so "works" is code-level, not user-verified. Best Pulse candidates: Dot.HR `https://infodot.co.za/go/hr?redirect=/jobs/{id}` (and `/jobs` is also publicly reachable without sign-in), Emall storefront and seller pages.
- **Dot.HR recruitment data models:** yes. `Vacancy` (title, description, responsibilities, requirements, skills, work_type, arrangement, location, pay_basis/min/max, openings, closes_on, status draft|published|paused|closed), `Application`, `ApplicationEvent`, `ApplicationNote`, `Interview`, `Offer`, `HiringStep`, `HiringCost`, `Position`, `ProfessionalProfile`, `ProfileEntry`, `ProfileSkill`, `InternalInvitation`, candidates controller. **No API for it**: no endpoint returns vacancies or candidates as JSON. Only the public HTML job board and the web UI exist. Pulse cannot consume vacancies today.
- **Dot.Mines employment or business listings:** no. The models are machines, operators (machine operators with qualifications/medical/training), mine areas, fuel, production, compliance, feed items. No vacancy, job or business-listing model or route.
- **Dot.Notify send API:** partial. `POST /api/ingest/events` is an event ingest that routes through the company's own rules; it is not a free "send this message" API. See table.
- **Dot.Agents text-task API:** no generic one. It starts runs of a business's configured agents or workflows; see table.

## 3. Existing server to server patterns and Analytics ingest status

Patterns in use:
| Pattern | Used by | Config key | Scheme |
|---|---|---|---|
| Bearer service secret | satellite -> InfoDot redeem and resources | InfoDot `ECOSYSTEM_SECRET_<KEY>`; satellite `INFODOT_SERVICE_SECRET` | `Authorization: Bearer <secret>`, `hash_equals` |
| HMAC webhook, InfoDot-style | InfoDot -> Files/Docs membership + search; Pulse/Notify/others -> Analytics; platforms -> Notify | Analytics `ANALYTICS_INGEST_SECRET_<KEY>`; Notify `NOTIFY_INGEST_SECRET_<KEY>`; per-platform `ECOSYSTEM_SECRET_<KEY>` for InfoDot webhooks | `X-InfoDot-Timestamp` + `X-InfoDot-Signature: sha256=hmac_sha256("{ts}.{raw body}", secret)` |
| HMAC, Dot-style | platforms -> Files; webhook triggers on Agents | Files `DOT_SERVICE_SECRET_<KEY>` | `X-Dot-Platform`, `X-Dot-Timestamp`, `X-Dot-Signature` (same formula), 300 s window |
| HMAC signed pull | Brain -> InfoDot candidates; InfoDot -> Memory participation and knowledge-recommendations | `KNOWLEDGE_*`, participation secrets | HMAC, one secret per consumer |
| Sanctum personal token | user/API-key integrations (Agents, Mines, Memory guardian) | n/a | Bearer token |
| OIDC (R-20) | InfoDot as IdP; no consumer yet | `dot_platforms` table | code + PKCE S256 |

Dot.Pulse -> Dot.Analytics (`app/Services/Analytics/*`, `config/analytics.php`, `app/Jobs/SendAnalyticsSnapshot.php`):
- Code is on Pulse main and complete: `php artisan pulse:publish-metrics [--dry-run]` scheduled daily 00:10 UTC; queued job; `POST ANALYTICS_INGEST_URL` (must end `/api/ingest/snapshots`) with HMAC signature made at send time; 5 s timeout; circuit breaker (3 failures -> 300 s pause); response handling: 2xx done, 429/5xx/no connection retry, 401/403/404/422 give up and log.
- Payload: `{platform: "pulse", company_uuid, taken_at: <UTC day start>, period: "day", metrics: {posts_7d, active_users_7d, held_posts}}`. Aggregate counts only. Platform-wide numbers filed under ONE InfoDot company uuid (`ANALYTICS_COMPANY_UUID`, the operator's own).
- Publishing is OFF unless all three of `ANALYTICS_INGEST_URL`, `ANALYTICS_INGEST_SECRET`, `ANALYTICS_COMPANY_UUID` are set. `.env.example` has them blank. DOT_BACKLOG.md: "Integration secrets incl. ANALYTICS_INGEST_URL ... deferred by you until all platforms are done" and "Confirm one visible metric from each publisher (Not verified)". So status: wired, not switched on as far as the repo shows; the host .env was not read.
- Analytics side: `pulse` is in the metric catalogue with exactly those three metrics, and `ANALYTICS_INGEST_SECRET_PULSE` is in its `.env.example`. That exists on branch `feature/ecosystem-sso` (the deployed one), not on `main`. Latest Analytics deploy (2026-11-07 04:38 UTC) failed; the one before (2026-11-03) succeeded. Backlog also lists Analytics PR #41 as needing merge+deploy.
- Pulse has NO Notify client (backlog "Pulse has no Notify client, so its events reach nobody outside the app") and no security headers middleware.

## Bottom line for Pulse
- Usable now: sign-in and company membership via redeem (already built); Analytics snapshot publishing (needs secrets and a company uuid on both ends, and a healthy Analytics deploy); deep links into InfoDot business pages and `/go/hr?redirect=/jobs...`.
- Needs work on the other side: business verification (InfoDot must expose it; zero machine access today); Notify (catalogue + company rule + Pulse client); Files (add `pulse` to `platform_secrets`); HR vacancies (no API, only HTML board); Agents (manual per-business keys; not a text API).
- No: Emall, Engage, Mines, Tasks, Projects, Memory, Brain (no suitable machine API for Pulse's purposes).
- Not verifiable from the repos: whether `ECOSYSTEM_SECRET_PULSE` / `INFODOT_SERVICE_SECRET` are set on the live hosts; whether Pulse has ever completed a live redeem.

## Decision for R-41 (added by the orchestrator)

1. InfoDot exposes NO business verification to other platforms. Pulse never shows "verified business". The ladder may show: "InfoDot team member" (checked, source: redeem `teams[]`, date: last sign-in) when the entity is linked to an InfoDot team the user belongs to; everything else (registration number, website, address, phone) is "claimed". If a future redeem `teams[]` carries a `verification` object, `VerificationService::refreshFromInfoDot` reads it (code path present, dormant, tested with a fake payload); owner item: ask InfoDot to add it.
2. Real integrations used: InfoDot sign-in and team sync (existing), Dot.Analytics ingest (existing publisher, inert until the three env values are set; owner item), deep links to other Dot platforms only through `/go/{platform}` for platforms the integrations audit marks live, and only where Pulse actually uses them (no decorative launcher). Everything else is not claimed.
