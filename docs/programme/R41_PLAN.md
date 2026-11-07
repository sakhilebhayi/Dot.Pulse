# R-41 Dot.Pulse: plan and decisions

Brief: `requirements/dot-pulse-transformation.md`. Audit: `R41_AUDIT.md`. Research: the market study and the integration audit (see `R41_RESEARCH.md` and `R41_INTEGRATIONS.md` once written). Written 2026-11-08.

## The product in one paragraph

Dot.Pulse is where intentions meet. Everything a person or a business wants to happen (sell this, buy that, hire her, get hired, partner on this, ask a question) is a **signal**. Pulse keeps an index of live signals, understands what each person offers and needs, and **syncs** the ones that fit: it introduces needs to offers, roles to talent, and collaborators to each other, always with the reason shown. Nothing happens to anyone without a yes: interest becomes a contextual request that the other side can accept, ignore, decline quietly or block. Every conversation carries the thing that started it (the offer, the role, the need), and can end in a confirmed outcome that is the only thing Pulse counts as a result.

## Decisions

| # | Decision | Why |
|---|---|---|
| D1 | The unit of the product is the **signal**, a row in `pulse_signals` pointing at a real subject (offer, need, role, talent profile, post, event, business page). Discovery queries the index, never the source tables | One indexed, rankable, diversifiable surface for every kind of opportunity; kinds keep their own tables and rules |
| D2 | Business identity is an **entity** (a business page) that people belong to with a role; a person keeps one individual or professional profile. An entity is a seller, an employer or both | Profile types in the brief without a second identity system; InfoDot stays the only login |
| D3 | **Needs** are first class. A consumer can say "I am looking for X near Y" and sellers whose offers match see it; the person is never exposed to strangers that do not match | This is the inversion of advertising the brief asks for: the customer does not have to search, the seller does not have to advertise |
| D4 | **Talent is discoverable only on the professional's terms**: off, anonymous (employers see skills, experience band, region and headline, not the name), or open. An employer can invite an anonymous person; the person chooses whether to reveal themselves | Passive career discovery and POPIA data minimisation in one feature |
| D5 | **Passive career discovery on the employer side**: an employer page can signal `building` or `active` hiring with the skills it wants before any vacancy exists; matching professionals see the employer | The brief's "meet an employer before it advertised" |
| D6 | **Matches are explainable by construction**: the matcher returns `{score, reasons[]}` where each reason is a code and a plain sentence ("You need bookkeeping in Gauteng, they offer bookkeeping in Gauteng"). The sync meter in the UI draws exactly those reasons | Recommendations must be explainable; no black box |
| D7 | **No endless feed.** A mode of the Floor is a stack of at most 20 beats built from fresh matches with a diversity rule (no more than 3 of a kind or entity in a row); when it is finished the person is told they are caught up | Retention by value, not screen time |
| D8 | **Contact is consented**: every profile and entity has a contact policy (`anyone`, `context`, `requests`, `nobody`). `context` means "only people who reach me through something of mine". A first message from a stranger is a *request* with its context; the recipient accepts (it becomes a conversation), ignores, declines quietly (the sender is not told) or blocks | Unwanted interactions are prevented, not cleaned up |
| D9 | **Conversations carry context** (`context_type`, `context_id`, a title snapshot), support text, attachments, structured quote requests and quotes, and an outcome that **both sides confirm**. Only confirmed outcomes count as results in the metrics. A review of a business can only be written after a confirmed outcome with that business | No fake successes, no fake reviews |
| D10 | **Verification is shown only from real InfoDot data**; claims a business makes about itself (a registration number, a website) are shown as claims. See `R41_INTEGRATIONS.md` for exactly what InfoDot provides | The brief: never fabricate verification |
| D11 | **Location is declared, coarse and optional**: places come from a built in gazetteer (countries, South African provinces and cities, major world cities); the app stores a place, never a coordinate of a person; nearby means same city, then region, then country, then anywhere | No fabricated or precise location |
| D12 | **Moderation works without a model**: a deterministic first pass (link density, repeated text, shouting, blocked terms, new account velocity, contact details in public text) approves low risk content, holds the rest for a human; a model, when one is configured, may add a second opinion. The old behaviour (everything held without a key) is replaced | The audit's biggest production defect |
| D13 | **Notifications are preferences first**: categories, in app or email, instant or daily or weekly, quiet hours; a daily digest is sent only when it holds something real. Dot.Notify has no send API, so channels are in app and email | Honest delivery |
| D14 | **Real time is short polling** where a page needs it (conversations, requests), not websockets | The host has no daemon |
| D15 | **Metrics are computed from real rows**: active people by kind, enquiries, requests accepted, response rate and time, confirmed outcomes, abuse reports; published to Dot.Analytics only if its ingest exists | The brief's "measure what matters" without invention |
| D16 | Design: **"The Floor"**. A trading floor for intentions. Cobalt field, chalk ground, signal yellow for "synced", ink for everything else; heavy expanded grotesk for display; mono for numbers. A bold confident voice. Layout is a single live floor with a mode dial (Trade, Work, Local, Learn) and a rail of verbs, not a sidebar of menus around a feed. The signature is the **sync meter**: your line and theirs meeting at the reasons that match | Own identity; the signature encodes real data and never decorates |
| D17 | Stack stays: Livewire 3, Blade, Alpine (bundled with Livewire), hand written CSS with tokens next to the existing Tailwind for Jetstream pages; no new JS framework | Preserve the working architecture |
| D18 | **Knock budget.** Every account has a weekly allowance of first contacts to strangers. It refills when people reply, shrinks when messages are reported, starts smaller for new accounts, cannot be bought. First messages cannot contain links | Spam becomes costly without a paywall (research idea 9) |
| D19 | **Enquiry cards.** An enquiry is generated from the thing it is about and carries at most four fields (what, how many or when, where, budget band), so nobody answers "is it available" again | Research idea 2 |
| D20 | **Quote slips capped at three providers.** A need that asks for quotes goes to at most three matched sellers; each replies on a standard slip (price or range, earliest date, included, excluded). Sellers pay nothing to see a request | Research idea 5 |
| D21 | **Close the loop.** Every request, enquiry, application and quote ends answered, declined (short honest reason list) or expired. Response rate and time are shown on entities; an employer's hiring signal expires unless renewed | Research idea 12 |
| D22 | **Earned reviews.** A review exists only after a conversation outcome both sides confirmed; both are written blind and revealed together | Research idea 11 |
| D23 | **Talent counts need a cohort.** An employer sees how many professionals match an anonymous hiring signal only when at least 5 do; never shown to the professional's current employer name when they listed it as excluded | Research idea 4, POPIA re-identification risk |
| D24 | **Consent ledger.** Every permission Pulse relies on (contact policy grants, discoverability, digest) is visible to the person in plain words with one tap to withdraw. Platform notifications are transactional or opted into; businesses cannot send marketing through Pulse | POPIA s69 |
| D25 | **18 and over only.** Declared at onboarding; no profiles or targeting of minors; no matching on protected grounds or their proxies; matching never auto-rejects anyone in hiring, a human always decides | POPIA ss34-35 and s71, Employment Equity Act |
| D26 | **Safe handover guidance** on trade conversations: pay inside your own bank app, never trust a screenshot, meet in public, share the arrangement with one trusted contact. Worded as guidance, never as protection | Research idea 14 |
| D27 | **Open doors**: a person or entity can open a door for 24 hours or a week with a stated purpose; strangers may knock once with a context card; the owner accepts or lets it lapse; contact details unlock only on accept | Research idea 1 |

## Phases and who builds them

| Phase | Work |
|---|---|
| 1 | Audit, research, integration audit, this plan, the schema (`2026_11_08_100000_pulse_business_schema.php`) |
| 2 | Design system and shell (identity, tokens, layout, navigation, component kit, landing and sign-in) |
| 3 | Domain: entities, offers, roles, needs, signals, places, media; discovery and matching; conversations, requests, quotes, outcomes, privacy, preferences, notifications, moderation |
| 4 | Product UI on the kit: the Floor, search, business pages and offer management, employer pages and roles, professional profiles and discoverability, Lines (conversations), settings and notification controls, the digest, operator dashboard |
| 5 | End to end tests (the 20 acceptance criteria), security and privacy review, performance, design review and polish |
| 6 | Merge, deploy, verify live, readiness report |

## What cannot be finished by building alone

A model key for the optional second opinion. Verification beyond what InfoDot exposes. Real integrations with Dot.Emall, Dot.HR, Dot.Mines, Dot.Notify, Dot.Files (see the integration audit). POPIA review of the privacy text by a lawyer.
