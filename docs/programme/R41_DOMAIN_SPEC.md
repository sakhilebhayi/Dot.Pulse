# R-41 Dot.Pulse: domain contract (backend services, routes, ownership)

Read with `R41_PLAN.md` (decisions D1-D27), `DESIGN.md` (The Floor) and the migration `2026_11_08_100000_pulse_business_schema.php`. This file is the contract between agents. Changing a signature means changing every consumer in the same commit and saying so in the commit message.

Conventions: PHP 8.3 compatible, Laravel 13. Services live in `App\Services\Pulse\*`, are plain classes resolved from the container, and take/return models or arrays. New columns are strings, not DB enums. SQL must run on SQLite (tests) and PostgreSQL (production): no JSON operators beyond `whereJsonContains`, no `ILIKE` (use `LOWER(col) LIKE`), no MySQL-only functions. Every write that matters appends `AuditLog::record(...)`. Time comes from `now()` so tests can freeze it. Every service method that mutates takes the acting `User` as its first argument and authorises through a policy or an explicit check; no controller or Livewire component re-implements authorisation.

## 1. Kinds, states, vocabularies (used everywhere)

| Thing | Values |
|---|---|
| signal.kind | `offer need role talent news ask event collab business` |
| signal.status | `live paused expired removed held` (held = awaiting moderation) |
| signal.reach | `local region national global` |
| entity.verification_status | `unverified claimed infodot_member` + (only if InfoDot data permits, see integration notes) `infodot_verified` |
| entity.contact_policy / profile.contact_policy | `anyone context requests nobody` |
| profile.discoverability | `off anonymous open` |
| conversation.kind | `dm enquiry quote application intro collab invite` |
| conversation.status | `requested open declined closed spam` |
| outcome | `none won lost hired partnered` (each side confirms; counted only when both confirm) |
| mode (Floor dial) | `all trade work local learn`. trade = offer, need, business, collab with trade topics; work = role, talent, collab; local = place-scoped any kind; learn = ask, event, news |
| match reason codes | `need_offer` `offer_need` `skill_role` `role_talent` `topic` `place_city` `place_region` `place_country` `remote` `follows` `mutual_context` `fresh` `responsive` |

A reason is `{code, text, weight}`; `text` is a plain sentence that can be shown verbatim ("You need bookkeeping, they offer bookkeeping"). `score` is the sum of weights clamped 0..100. A match with no reasons is not stored.

## 2. Services (namespace `App\Services\Pulse`)

### 2.1 Entities and listings (agent D1) `Entities\*`

```
EntityService
  create(User $by, array $data): PulseEntity            // owner membership; slug unique; audit
  update(User $by, PulseEntity $e, array $data): PulseEntity
  addMember(User $by, PulseEntity $e, User $u, string $role): void   // role owner|admin|editor|responder
  removeMember(User $by, PulseEntity $e, User $u): void              // never removes the last owner
  follow(User $u, PulseEntity $e): void ; unfollow(...)
  archive(User $by, PulseEntity $e): void                              // retires its signals
  stats(PulseEntity $e): array                                         // real counts: offers, roles, response rate, median response seconds, confirmed outcomes
OfferService   create(User, PulseEntity, array): PulseOffer ; update ; pause ; publish ; archive
RoleService    create(User, PulseEntity, array): PulseRole  ; update ; pause ; publish ; close
NeedService    create(User, array): PulseNeed ; update ; close ; reopen        // a person's "looking for"
SignalIndexer
  syncSubject(Model $subject): ?PulseSignal      // upsert the pulse_signals row for an offer/need/role/profile(talent)/post/entity; computes title, excerpt, topics, skills, place_id, reach, quality; sets status from subject status + moderation; idempotent
  retire(Model $subject): void
  rebuild(): int                                  // command pulse:index
MediaService
  store(User $by, UploadedFile $f, string $purpose): PulseMedia-like row id   // images only (jpeg png webp), max 5 MB, re-encoded, EXIF stripped, 3 sizes; purposes logo cover offer gallery avatar message
  url(int $mediaId, string $size): string         // signed or auth-checked route, never a guessable public path for message media
VerificationService
  ladder(PulseEntity $e): array                   // ordered rungs [{key,label,state: checked|claimed|missing, at, source, detail}] built ONLY from real data
  claimRegistration(User $by, PulseEntity $e, string $number): void   // stored as claim, labelled "claimed"
  refreshFromInfoDot(PulseEntity $e): void        // see R41_INTEGRATIONS.md
ProfileService  (professional profile)
  update(User $by, array $data): PulseProfile ; setDiscoverability(User, string) ; setOpenTo(User, array) ; completeOnboarding(User)
  card(PulseProfile $p, ?User $viewer): array     // applies discoverability + contact policy; anonymous profiles return headline, skills, experience band, region, no name
```

Policies: `PulseEntityPolicy` (view, update, manageMembers, respond), `PulseOfferPolicy`, `PulseRolePolicy`, `PulseNeedPolicy`. Route model binding by slug for entities and offers.

### 2.2 Discovery (agent D2) `Discovery\*`

```
Matcher
  forUser(User $u): int                            // recompute matches for one user; returns rows written
  forSignal(PulseSignal $s): int                   // new/changed signal: match against interested users
  explain(PulseMatch $m): array                    // reasons[]
Floor
  round(User $u, string $mode = 'all', ?string $cursor = null): array
     // returns ['beats' => [Beat...], 'next' => cursor|null, 'caught_up' => bool, 'next_round_at' => ?Carbon, 'counts' => ['new' => n]]
     // at most 20 beats, no more than 3 consecutive of one kind or one entity, excludes blocked/muted/dismissed/saved-and-seen, honours mode
  dismiss(User $u, PulseSignal $s, ?string $reason): void ; saveForLater(User, PulseSignal): void ; unsave(...) ; savedList(User): Collection
  removeReason(User $u, PulseMatch $m, string $code): void    // user taps a node on the sync meter: mutes that input (topic/place/entity) for this user
Beat (value object)
  signal, subject (offer|need|role|profile|post|entity), kind, title, excerpt, from (name, avatar, entity?, ladder summary), place, reasons[], score, published_at, can_knock (bool + why not), saved (bool)
SearchService
  search(User $u, string $q, array $filters = [], ?string $cursor = null): array   // over signals + entities; filters: kind, mode, place, remote, price band, employment_type; typed-ahead suggestions via suggest($u,$q)
Moderation\LocalRules
  assess(string $text, User $author, array $ctx = []): array   // ['verdict' => 'allow'|'hold'|'block', 'flags' => [...]] deterministic: link density, repeated text, shouting, blocked terms, contact details in public text, account age and velocity
  // Replaces the fail-closed path in AiModerationService: no model key => LocalRules decides; allow => published, hold => queue, block => rejected with a stated reason. If a model is configured it adds a second opinion for 'hold' only.
Digest\DigestBuilder    build(User $u): ?array  // only when there is something real; null otherwise
Digest\DigestSender     send(User $u): bool     // honours prefs and quiet hours; mail via Laravel notification
Places\PlaceRepository  search(string $q): Collection ; find(int $id) ; forUser(User) ; nearbyIds(PulsePlace $p, string $reach): array   // same city, then region, then country
Taxonomy                topics(): array ; skills(): array ; suggest(string $q, string $kind): array   // static curated lists in config/pulse_taxonomy.php
```

Commands (all with `flock`-style overlap protection `->withoutOverlapping()`): `pulse:index` (rebuild signals), `pulse:match` (users with activity or new signals in the last day, every 10 min), `pulse:digest` (hourly, sends to users whose digest hour is now in their timezone), `pulse:expire` (hourly: expire needs/signals/doors/hiring signals), `pulse:places:seed`.

### 2.3 Lines: contact, conversations, trust (agent D3) `Lines\*`

```
ContactGate
  can(User $from, $target, ?Model $context = null): array   // ['allowed'=>bool,'mode'=>'direct'|'request'|'blocked','why'=>string] from target contact policy, blocks, knock budget, age gate, context presence
KnockBudget
  remaining(User $u): int ; spend(User $u): void ; refill(User $u): void ; shrink(User $u, int $by): void ; resetsAt(User $u): Carbon
     // default 7 per week for established accounts, 3 for accounts under 7 days; refills +1 on a reply from a stranger; shrinks on confirmed spam report; stored in cache+DB, no purchase path
LineService   (conversations)
  knock(User $from, array $target, string $kind, array $card, ?Model $context): PulseConversation
     // target = ['user'=>id] or ['entity'=>id]; $card = what, when_or_qty, where, budget_band (max 4 fields, text only, NO links, length-capped); creates status=requested with context snapshot; spends budget; notifies; audit
  accept(User $by, PulseConversation $c): void ; decline(User $by, PulseConversation $c, ?string $reasonCode): void   // quiet decline: sender sees "Not now"
  ignoreQuietly(User $by, PulseConversation $c): void ; close(User $by, PulseConversation $c): void
  send(User $by, PulseConversation $c, string $body, ?int $mediaId = null): PulseMessage   // status must be open; links allowed after accept only; rate limited
  markRead(User $by, PulseConversation $c): void
  inbox(User $u, array $filters): Paginator                      // requests, open, closed; for entities, the responders see the entity's lines
  reveal(User $by, PulseConversation $c): void                   // anonymous talent invite: professional chooses to reveal
  open(User $from, $target): PulseConversation                   // plain dm only when ContactGate says direct
QuoteService
  request(User $from, array $needOrOffer, array $providerEntityIds /* max 3 */, array $details): PulseConversation[]
  respond(User $by, PulseConversation $c, array $slip): PulseQuote    // price/range, earliest date, included, excluded, valid_until
  accept(User $by, PulseQuote $q): void ; decline(...) ; expireStale(): int
OutcomeService
  propose(User $by, PulseConversation $c, string $outcome): void ; confirm(User $by, PulseConversation $c): void ; dispute(...)
  confirmed(PulseConversation $c): bool                          // both sides agree on the same outcome
ReviewService  (earned)
  canReview(User $u, PulseEntity|User $subject): ?PulseConversation ; submit(User $by, PulseConversation $c, array $r): void  // blind until both submit or 14 days; then revealed; one per confirmed outcome
BlockService   block(User, User|PulseEntity) ; unblock ; blocked(User $a, User $b): bool   // blocking hides both ways and closes open lines
MuteService    mute(User, string $kind, string $value) ; unmute ; isMuted(User, ...)
ReportService  report(User $by, Model $subject, string $reason, ?string $note): PulseReport ; moderator actions reuse the existing moderation queue; confirmed spam shrinks the reporter-target knock budget
NotificationPrefs
  get(User): PulseNotificationPref ; update(User, array) ; allowed(User $u, string $category, string $channel): bool ; quietNow(User $u): bool
  categories: lines, matches, outcomes, system, digest
Notifier
  notify(User $u, string $category, array $payload): void       // writes the database notification and, when allowed, queues the mail; transactional only
ConsentLedger
  entries(User $u): array    // plain-language rows built from real settings: discoverability, contact policy, digest, each entity role, each open door; each has a withdraw action
AuditLog
  record(?User $actor, string $action, ?Model $subject, array $meta = []): void   // append only; never updated
Doors  (open doors, D27)  open(User|PulseEntity, string $purpose, string $length /* day|week */): PulseSignal ; close ; knockOnDoor(...) uses LineService::knock
```

### 2.4 Safety text and age gate

`config/pulse.php` gains `safety` (handover guidance strings, shown on trade lines) and `age_min = 18`. Onboarding records `age_confirmed_at` on the profile (add column in the D3 migration if absent). No profile under 18 is ever discoverable.

## 3. Routes (names the UI agents use)

Web (all authenticated unless marked public): `floor` GET `/floor` (home after sign-in; `/dashboard` redirects here), `trade` GET `/trade`, `work` GET `/work`, `local` GET `/local`, `learn` GET `/learn` (these four are the Floor with the mode preselected), `search` GET `/search`, `saved` GET `/saved`, `lines` GET `/lines`, `lines.show` GET `/lines/{conversation}`, `me` GET `/me` (own profile hub), `me.profile`, `me.discover` (discoverability and open-to), `me.settings`, `me.notifications`, `me.privacy` (consent ledger, blocks, mutes, export, delete), `door` GET `/door`, `needs.create` GET `/need/new`, `needs.show` GET `/need/{need}`, `entities.create` GET `/business/new`, `entities.show` GET `/b/{entity:slug}` (public page when entity is public: limited, no member list), `entities.manage` GET `/b/{entity:slug}/manage` (+ `.offers`, `.roles`, `.team`, `.lines`, `.verify`), `offers.show` GET `/b/{entity:slug}/o/{offer:slug}`, `roles.show` GET `/b/{entity:slug}/r/{role}`, `onboarding` GET `/welcome-in`, `operator` GET `/operator` (staff metrics). Existing: `users.show` `/u/{profileUser}`, `communities.show`, `posts.show`, `moderation`. Public: `/` (landing), `/b/{slug}` (see above), `/login`.

JSON (Sanctum session, prefix `/api/pulse`): none required for the web UI; Livewire carries state. A single polling endpoint `GET /api/pulse/pulse-check` returns `{lines_unread, requests, notifications}` for the shell badge (short poll every 30 s while visible).

## 4. File ownership (agents never edit files owned by another agent; ask via the report if needed)

| Agent | Owns |
|---|---|
| shell | `resources/css/**`, `resources/js/app.js`, `resources/js/pulse/**`, `resources/views/layouts/**`, `resources/views/components/pulse/**`, `resources/views/welcome.blade.php`, `resources/views/auth/**`, `resources/views/errors/**`, `resources/views/policy|terms` styling, `tailwind.config.js`, `vite.config.js`, `public/fonts/**`, `app/View/Components/**` for kit components |
| D1 | `app/Services/Pulse/Entities/**`, `app/Models/Pulse{Entity,EntityMember,Offer,Role,Need,Signal,Place?}.php` additions (relations, scopes), `app/Policies/Pulse*`, `database/factories/Pulse*` for its models, `tests/Feature/Entities/**`, migration additions named `2026_11_08_2*` |
| D2 | `app/Services/Pulse/Discovery/**`, `config/pulse_taxonomy.php`, `database/seeders/PulsePlaceSeeder.php`, `app/Console/Commands/Pulse{Index,Match,Digest,Expire}.php`, `routes/console.php` scheduling lines for its commands, `app/Services/AiModerationService.php` + `app/Jobs/ModeratePost.php` (moderation first pass), `tests/Feature/Discovery/**`, migrations `2026_11_08_3*` |
| D3 | `app/Services/Pulse/Lines/**`, `app/Models/Pulse{Conversation,Message,Quote,Block,Mute,Save,NotificationPref,AuditEntry,Report?}.php`, `app/Notifications/Pulse*`, `tests/Feature/Lines/**`, migrations `2026_11_08_4*` |
| UI agents (wave 2) | Livewire components and Blade views for their feature area, named `App\Livewire\Pulse\<Area>\*` and `resources/views/livewire/pulse/<area>/*`; routes appended to `routes/web.php` inside a marked block `// --- <area> ---` |

Shared files (`routes/web.php`, `routes/console.php`, `config/pulse.php`, `app/Providers/*`) are edited only by appending inside your own marked block to keep merges trivial.

## 5. Tests

Every service has feature tests on SQLite. The 362 existing tests (7 skipped) must stay green or be updated with the reason in the commit. Factories for every new model. Authorisation tests: each mutating method is tested for a stranger, a non-owner member, and the owner. Privacy tests: anonymous talent never leaks name/email/avatar in any query result, array, view or notification; a blocked user never appears in any result for the blocker; a held or removed signal never appears in the Floor, search or digest.
