# R-41 Dot.Pulse: product and technical audit

Brief: `requirements/dot-pulse-transformation.md`. Audited 2026-11-08 on `858bd9b` (main). The code was read, the baseline suite was run (362 tests, 355 passed, 7 skipped) and the live deployment was probed (`pulse.infodot.co.za` answers 200; the deploy workflow last ran green on 2026-10-28).

## What exists

Laravel 13, Livewire 3, Jetstream teams, Sanctum, Blade with Tailwind 3 (a committed dark theme in the Dot gold), Reverb for realtime nudges, Postgres in production and SQLite under test, a database queue run from cron. About 370 tracked files. Dot.Pulse is "a general social network": posts of 17 types, communities (public and private), follows, one reaction per person, threaded comments with solutions, hashtags and trending, database notifications, a moderation queue with reports, an Anthropic based first pass, InfoDot sign-in through a POST handoff, a daily snapshot publisher for Dot.Analytics. The README is honest: tables for polls, media, events, direct messages, badges, reviews, a marketplace and a knowledge graph exist and "none of them has any UI or application code".

## Classification

| # | Feature | Class | Evidence |
|---|---|---|---|
| 1 | InfoDot sign-in and team sync | 1 fully functional | POST handoff, origin check, redeem call, tests |
| 2 | Posts (text only, 17 types) | 2 | Work, but a showcase, a job and an announcement all render as the same text post; no media, no link previews, no entity (business) author |
| 3 | Moderation first pass | 5 broken in production | It fails CLOSED by design, and the host has no Anthropic key, so every new post is held for a human forever. Nothing ever publishes on its own |
| 4 | Moderation queue, reports, per community moderators | 1 | Works, tested |
| 5 | Communities (public, private, join requests) | 1 | Works, tested |
| 6 | Follows (people only) | 2 | No follow for businesses |
| 7 | Reactions, comments, solutions | 1 | Works |
| 8 | Hashtags and trending | 1 | Works |
| 9 | Home feed | 3 | A filtered list of 30 posts ordered by a score every post gets as 0.5 and then by time: a plain timeline, no discovery, no end |
| 10 | Notifications | 2 | Database notifications for follows, membership, reports; no preferences, no email, no digest, no channels |
| 11 | Realtime (Reverb) | 4 | `BROADCAST_CONNECTION=reverb` by default; the shared host has no websocket daemon, so it is a null connection there and the pages simply do not live update |
| 12 | Profiles | 3 | Headline, bio, 12 skills. No profile types, no business identity, no availability, no experience, no privacy control, `is_verified` is a column nothing sets or reads |
| 13 | Business identity (pages, offers, roles) | 6 missing | Not modelled at all. `role` on a profile is an enum label (customer, business, enterprise...) with no behaviour |
| 14 | Verification | 6 missing | No verification flow, no InfoDot link |
| 15 | Direct messages, conversations | 4 | Tables only, no code |
| 16 | Events, polls, reviews, badges, marketplace, knowledge graph | 4 | Tables only, no code |
| 17 | Search | 6 missing | Scout is installed and nothing uses it |
| 18 | Discovery, recommendations | 6 missing | The only suggestions are "suggested users" (see below) |
| 19 | Suggested users | 2 | A list of people not yet followed; no reasons |
| 20 | Media uploads | 6 missing | No upload path at all |
| 21 | Location | 6 missing | A free text `location` string; nothing uses it |
| 22 | Blocking, muting | 6 missing | Reports exist, blocks do not |
| 23 | Privacy controls | 6 missing | Everything about everyone is visible to every signed in user |
| 24 | Analytics publishing | 3 | Builds a snapshot and publishes only when three settings exist; Dot.Analytics has no live ingest route, so it never sends |
| 25 | Responsive shell | 2 | Off canvas drawer under 860 px; one 415 line layout file with inline styles |
| 26 | Design | 2 | A competent dark Tailwind social shell in the Dot gold; the generic social layout the brief rejects (sidebar, feed of cards) |
| 27 | Tests | 2 | 362 tests, good on authorisation and moderation; nothing on discovery, commerce or hiring because none exists |

## Technical weaknesses

1. The product has no model of what business is: no business identity, no offering, no opening, no need, no conversation. Everything the brief asks for sits on tables that were created and never used.
2. The moderation default stops the whole product on a host with no model key.
3. There is no way to move from seeing something to talking about it.
4. The feed ranks nothing: there is no signal to rank by, no reason shown, no end.
5. Everything is visible to everyone signed in, with no contact control, no block and no mute. That is not acceptable for a place where strangers meet to trade and hire (POPIA, section 69 on unsolicited electronic communications).
6. No uploads, so a seller cannot show a product.
7. Realtime depends on a websocket server the host does not have.

## What is good and must be kept

InfoDot sign-in, communities and their privacy rules, follows, reactions, comments, hashtags, reports and the moderation queue, the rate limiter (`PulseThrottle`), the health probes, the analytics publisher, the test conventions and fixtures (`tests/Concerns/BuildsPulseFixtures.php`).
