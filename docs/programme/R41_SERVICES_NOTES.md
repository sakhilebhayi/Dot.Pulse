# R-41 backend as built: deviations from the spec and what the UI must know

Read after `R41_DOMAIN_SPEC.md`. Where this file and the spec differ, this file (and the code) wins.

## Entities (D1) `App\Services\Pulse\Entities`
- Services as in the spec, plus `ListingModeration` (moderator decide/suspend/reinstate), `ContentGate`, `Sanitizer`, `Slugger`.
- `MediaService::store(User, UploadedFile, purpose, ?PulseEntity = null, ?int $conversationId = null): int` (logo/cover/offer/gallery need the entity; message needs the conversation id and a participant); `delete(User,int)`, `canView(PulseMedia, ?User)`. `url()` returns relative `/media/{id}/{thumb|medium|large}`, route `pulse.media`. Table is `pulse_media_files`. A refused read is a 404.
- `OfferService/RoleService/NeedService::delete`; `RoleService::close($by,$role,'closed'|'filled')`; `NeedService::close($by,$need,bool $fulfilled)`; `EntityService::restore`.
- `ProfileService`: `confirmAge`, `setAvatar`, `setContactPolicy`, `completeOnboarding($by, bool $ageConfirmed=false)`, `card($profile, ?$viewer)`.
- `VerificationService`: `ladder`, `summary`, `recompute`, `linkInfoDotCompany($by,$entity,Team)`, `unlinkInfoDotCompany`, `refreshForUser`, `refreshFromInfoDot`. States reachable: unverified, claimed, infodot_member. Never "verified business".
- Publishing: an offer/role/need created without `'publish' => true` is a draft; `publish` makes it live and runs the content gate (rules, then the pending path). Without rules an author who is not an InfoDot company member publishes into `pending` and the signal is held until a moderator decides.
- Signals with no place are indexed as reach `national` (a placeless signal cannot be local).
- Quotas in `config('pulse.entities')`.
- Policies registered by `PulseEntitiesServiceProvider`. Route model binding by slug is done in the route definitions the UI adds.

## Discovery (D2) `App\Services\Pulse\Discovery`
- `Floor::round($user, $mode, $cursor)` returns `['beats' => Beat[], 'next', 'caught_up', 'next_round_at', 'counts']`. It does NOT mark beats seen: call `Floor::markSeen($user, $signalIds)` when they are shown. `next` is an offset cursor; `next_round_at` is set only when `caught_up`.
- `Beat` props are snake_case (`published_at`, `can_knock`, `match_id`, `card`, `url`, `topics`, `toArray()`). `can_knock` is a cheap pre-check; call `ContactGate` at the moment of the knock. Anonymous talent beats have `subject=null`, `from.name=null`, `card={headline,skills,experience_band,region}`.
- A reason is `{code,text,weight,ref}`. `removeReason($user,$match,$code)` mutes by ref (topic, place, entity, user, "remote"); throws for fresh/responsive/mutual_context (the UI offers "remove" only on removable reasons).
- `PlaceRepository::nearbyIds` returns tiers `['city','region','country']`; `flatNearbyIds` flat. `Taxonomy::topics()/skills()/suggest()`. `PlaceRepository::search($q)` for pickers.
- Search results are `{type: signal|entity, score, why[], beat|entity}`; `price_min/price_max` in rand.
- Moderation: `Moderation\ModerationPipeline::review(...)` returns a `ModerationDecision` (`moderation()` approved|held|removed). `LocalRules::assess` also returns `reason` (author-facing) and `score`.
- Digest is opt-in (no prefs row = no digest). Signed route `pulse.digest.unsubscribe`.
- Run `php artisan pulse:places:seed` at deploy (about 900 places); it is not automatic.
- Door signals (`kind=door`, from `Doors::open`) must render in the Floor.

## Lines (D3) `App\Services\Pulse\Lines`
- Read lines only through `LineService::inbox`, `present`, `thread` (arrays, safe to send to the client). `inbox` takes `tab` (requests|open|sent|closed|ignored|all), `kind`, `entity_id`, `unread`, `per_page`; returns a paginator of arrays.
- `knock` targets: `['user'|'entity'|'signal'|'door' => id, 'as_entity' => id]`. Anonymous professionals are addressed only by `signal` and a talent signal accepts only kind `invite`. Throws `LineException` (`->reason`) or `ValidationException` (keys like `card.what`). A page that accepts contact from anyone opens the line immediately; a stricter one creates a request.
- `ContactGate::can` accepts User, PulseEntity, PulseSignal, PulseDoor, PulseOffer, PulseRole or PulseNeed and also returns `code` and `stranger`.
- An expired request is stored as status `closed` with `closed_reason=expired`.
- Static helpers: `AuditLog::record`, `OutcomeService::confirmed/successful/confirmedCount`, `BlockService::blocked/hiddenUserIds/hiddenEntityIds/entityHidden`, `MuteService::isMuted/mutedValues`, `ResponseStats::for`, `PrivacyExport::forUser`, `PrivacyEraser::erase`. The Floor and search already apply the hidden/muted helpers.
- Notification categories are stored `{category: {in_app, email}}`. `NotificationPresenter::present` renders any notification whose data has a `category` key; the bell must switch to it for those.
- Confirmed spam only shrinks the sender's budget if the moderation queue calls `ReportService::resolve` (wire this into the moderation UI).
- A review needs a confirmed positive outcome (won, hired, partnered).
- Polling endpoint `GET /api/pulse/pulse-check`, route name `pulse.check`.
- `pulse:lines:sweep` hourly; `PrivacyExport`/`PrivacyEraser` back the privacy page.

## Shell
- Layout `<x-app-layout title width="wide|floor" :dial>` with slots `header` and `context`; kit components under `resources/views/components/pulse/` (props documented at the top of each file); `pulse_route($name)` returns `#` when a route is not registered; dev pages `/dev/kit` and `/dev/floor` (local/testing only).
- Rail/Compose links point at `#` until their routes exist: UI agents register the route names listed in the spec.

## Integration fixes (feat/r41-fix): what changed and what the UI may now rely on
- Preferences are private by default and reading never writes. `NotificationPrefs::get()` returns an unsaved row; digest off; email off for matches, digest and system; in app on everywhere; email on for lines and outcomes. Stored rows are never rewritten.
- Work history rows hold `employer` (canonical), `org` (kept equal), `hide_from_employer`. Matcher falls back to `org`. `ProfileService::card()` gives the hide flag to the person only.
- `ContentGate::decide()` also returns `reason` and `details`; `stamp()` turns a decision into `moderation`, `moderation_reason`, `moderation_flags`. Approving clears the reason.
- `EntityService::create(..., ['draft' => true])` and `publish()`. `restore()` on a draft publishes it. Draft and archived read differently.
- Hiring signals lapse after `pulse.entities.hiring_signal_days` (60) unless `renewHiringSignal()`; `pulse:expire` switches stale ones off and tells owners and admins. `PulseEntity::hasHiringSignal()` is the check.
- `TalentCohort::countFor()` returns null below 5, otherwise the band floor 5, 10 or 25; `label()` words it.
- Who answers lines: owner, admin, responder (`Access::RESPONDER_ROLES`), also what `PulseEntityPolicy::respond` says. Editors edit listings only.
- `pulse.onboarded` is on every signed-in page except the welcome flow, notifications, privacy and export. A profile with a headline, bio, place or open-to counts as onboarded; older profiles were backfilled. Sign-in lands on the Floor.
- Anonymous professionals: `PulseProfile::isHiddenFrom / isLinkable / publicName / publicHeadline` are the one helper. People lists, suggestions and follow notifications never name, describe or link them. A post or comment they wrote keeps their handle (posting is public by their own act and is not the talent card), is not linked to their page (a 404 for everyone else) and never carries the talent headline. The talent card itself is reached only through signals.
- `LineService::present()` also returns `last_message_side`, `quote_group` (asking side only) and `page` (the viewer's own page). `archive()`, `unarchive()`, `inbox(['archived' => true])`, `inbox(['entity_id' => 'none'])` for lines that are the person's own. A reply in the same second as a read stays unread.
- `ReviewService::submit()` forces pseudonymous for a professional who has not revealed themselves on an anonymous line.
- `PulseNeedPolicy::view` is the only rule for matched needs. Beat urls point at the offer, role (by slug), need, page or post, and fall back to `signals.show`; doors use `signals.show`.
- `PulseProfile::OPEN_TO` gains `suppliers` and `learning`. Matching does not use them yet (only `collab` is read); they are displayed and stored.
- Intentionally not done: a column default change for `pulse_notification_prefs.digest` (every writer sets it, and a SQLite column change is a table rebuild).
