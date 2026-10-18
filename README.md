<div align="center">

<img src="docs/logo.svg" alt="Dot.Pulse" width="320" />

<br /><br />

**A general social network: posts, communities, follows and reactions.**

<br />

![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white) ![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?style=flat-square&logo=php&logoColor=white) ![Livewire](https://img.shields.io/badge/Livewire-3-FB70A9?style=flat-square) ![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-336791?style=flat-square&logo=postgresql&logoColor=white)

<br /><br />

**Part of the [InfoDot Ecosystem](https://github.com/sakhileb/InfoDot)** &nbsp;·&nbsp; `pulse.infodot.app`

</div>

---

## What is Dot.Pulse?

Dot.Pulse is a general social network. People post, join communities, follow each other, comment and react. It is **not** a business-publishing tool and is not scoped to a company or team.

## What it does today

- **Posts** — plain-text posts of 17 types (discussion, question, announcement, ...), with optional title. No images, video or link previews yet.
- **Communities** — public ones anyone can join with one click; private ones are members-only and join by request (see below).
- **Follows** — follow people. Joining a community is separate from following; there is no "follow a community".
- **Reactions** — one reaction per person per post or comment, from a short emoji allowlist.
- **Comments** — threaded, with edit and delete inside a configurable window and "mark as solution" on posts.
- **Hashtags** — literal `#tags` are indexed when a post goes live; trending is the last 7 days, counted only from posts the viewer can see.
- **Notifications** — new follower, membership requests and decisions, report outcomes, content removed.
- **Moderation** — automated first pass, a held-post queue, user reports, per-community and platform moderators. See [docs/privacy-and-moderation.md](docs/privacy-and-moderation.md).
- **Realtime** — Laravel Reverb nudges open pages to refresh; every channel is private.

Tables exist for polls, media, events, direct messages, badges, reviews, a marketplace and a knowledge graph (`pulse_polls`, `pulse_media`, `pulse_events`, ...). **None of them has any UI or application code yet.**

## Who can see what

Dot.Pulse is one global network, deliberately. Tenancy is not a feature here.

| Thing | Who can see it |
|---|---|
| Profiles, follower counts, the people directory | Every signed-in user |
| Public community, its posts, comments | Every signed-in user |
| Posts with no community | Every signed-in user |
| Private community: name, industry, member count | Every signed-in user (in the directory, so they can ask to join) |
| Private community: page, description, posts, comments, hashtags | Its members (and its creator) only; everyone else gets a 404 |
| A post that is pending, held or removed | Its author and the moderators responsible for it |
| Moderation queue and reports | Platform moderators (everything) and a community's own moderators (that community) |
| Who reported something | Nobody on the platform; reporter ids are stored but never shown or notified |

`team_id` on posts and communities is informational (the author's current team, when they have one). It grants no access and nothing is filtered by team. A user with no team can post.

Nothing is anonymous to the database administrator. Private means private from other users, not encrypted at rest.

## Tech stack

| Layer | Technology |
|---|---|
| Framework | Laravel 13 (see `composer.json`) |
| Language | PHP 8.3+ |
| Frontend | Livewire 3 · Alpine.js · Tailwind (CDN) |
| Database | PostgreSQL (default connection) or SQLite; the app creates its own `users` table, sharing a database with other apps is a deployment choice, not something the code enforces |
| Realtime | Laravel Reverb, private channels only |
| Auth | Jetstream/Fortify sessions; sign-in through InfoDot via `/auth/ecosystem` (signed redeem, see [Ecosystem](#ecosystem)) |
| AI | Anthropic Messages API for the moderation first pass; model from `ANTHROPIC_MODEL` |
| Queue | Laravel queue (`database` by default); **a worker is required**, see below |

Not used, despite older versions of this file: Meilisearch / Laravel Scout (the package is installed but nothing is searchable through it; community search is SQL `LIKE`), Horizon, Redis, S3 or any file upload.

## Quick start

```bash
git clone https://github.com/sakhilebhayi/Dot.Pulse.git
cd Dot.Pulse
cp .env.example .env
composer install
npm install && npm run build
php artisan key:generate
php artisan migrate
php artisan serve
```

In a second and third terminal (or under a process manager in production):

```bash
php artisan queue:work          # runs the automated moderation check on new posts
php artisan schedule:work       # (or a cron running schedule:run every minute)
php artisan reverb:start        # realtime
```

**Without a queue worker nothing is published**: new posts wait in "pending", and after 15 minutes the scheduled `pulse:sweep-pending` moves them to the human moderation queue. That is deliberate (the system fails closed), but it means a deploy needs a worker. Likewise without `ANTHROPIC_API_KEY` every post is held for a human.

### Configuration

| Variable | Default | |
|---|---|---|
| `ANTHROPIC_API_KEY` | empty | Empty means every post is held for human review |
| `ANTHROPIC_MODEL` | `claude-sonnet-4-6` | |
| `ANTHROPIC_TIMEOUT` | `10` | seconds per call |
| `PULSE_EDIT_WINDOW_MINUTES` | `30` | `0` switches editing off |
| `PULSE_LIMIT_POST` / `_COMMENT` / `_REACT` / `_REPORT` / `_JOIN_REQUEST` | 20 / 30 / 60 / 10 / 10 | per user per minute; `0` removes a limit |
| `PULSE_JOIN_REQUEST_COOLDOWN_DAYS` | `7` | after a denied request |
| `INFODOT_SERVICE_SECRET` | empty | Must equal `ECOSYSTEM_SECRET_PULSE` in InfoDot. Empty means InfoDot sign-in is off (only a token already in this database is accepted) |
| `INFODOT_PLATFORM_KEY` | `pulse` | This platform's key in InfoDot |
| `INFODOT_REDEEM_URL` | `https://infodot.co.za/api/ecosystem/redeem` | |
| `INFODOT_REDEEM_TIMEOUT` | `5` | seconds |
| `INFODOT_PROVISION_USERS` | `true` | `false` stops InfoDot creating accounts here; people who already have one still sign in |

### Operations

```bash
php artisan pulse:recount --dry-run   # exits 1 if any counter has drifted
php artisan pulse:recount             # repair members / reactions / comments / hashtag counters
php artisan pulse:sweep-pending       # hold posts stuck in "pending" for a human
php artisan infodot:link-team {team} {uuid} --promote   # link an existing team to its InfoDot company
```

### Tests

```bash
DB_CONNECTION=sqlite DB_DATABASE=:memory: ./vendor/bin/phpunit
```

The suite also passes on PostgreSQL 16.

## Ecosystem

Dot.Pulse is one of the apps in the InfoDot ecosystem.

### Signing in through InfoDot

`GET /auth/ecosystem?token=...` is the sign-in hand-off from the InfoDot hub. Each Dot app has its own database, so Pulse does not look the token up locally: it sends it to InfoDot's redeem endpoint with `Authorization: Bearer INFODOT_SERVICE_SECRET` and `{token, platform: "pulse"}`, and InfoDot answers with the person and their companies.

- The email must be verified by InfoDot (exactly `true`), otherwise the sign-in is refused.
- A person with no account here gets one, in the same state as a self-registered user: random password nobody knows, a personal team, and a `PulseProfile` (role `customer`) so they can post at once. An existing account is matched by email; an unverified local one is adopted and its password replaced.
- Platform roles (`moderator`, `admin` on `PulseProfile`) are never read from InfoDot and never granted by sign-in. An existing moderator keeps theirs.
- InfoDot's companies are synced to Jetstream teams on every sign-in (`teams.infodot_team_uuid`): owners/admins create a company here, members are attached to it, and a company InfoDot stops listing is detached at the next sign-in. Pulse stays one global network: teams are not a visibility boundary.
- InfoDot unreachable or erroring gives 503 (try again); a refused token gives 403. The route is throttled to 10 requests a minute and the session id is regenerated at sign-in. A `redirect` is honoured only if it is a path on this app.
- A token already in this database (shared-database setups) is still accepted first and spent.

Deploying it needs both ends set:

1. In InfoDot: `ECOSYSTEM_SECRET_PULSE` set, and Pulse marked live (InfoDot refuses to redeem for a platform that is not live).
2. Here: `INFODOT_SERVICE_SECRET` (the same value), `INFODOT_PLATFORM_KEY=pulse`, then `php artisan migrate`.
3. For teams that already exist here, link each to its company so sign-in reuses it instead of creating a second one: `php artisan infodot:link-team {team} {uuid} --promote` (`--promote` turns a personal team into the company).

## License

MIT © [SK Digital / BluPin Incorporated](https://github.com/sakhileb)
