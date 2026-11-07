---
version: alpha
name: The Floor
description: Dot.Pulse interface system. A trading floor for intentions, where two lines meet at the reasons they share.
colors:
  chalk: "#EEF0F5"
  sheet: "#FFFFFF"
  raised: "#F6F7FA"
  ink: "#0B0E1A"
  ink-2: "#2A3045"
  muted: "#4B5266"
  line: "#CDD2DE"
  cobalt: "#1F3CFF"
  cobalt-deep: "#1328B8"
  signal: "#FFD400"
  signal-ink: "#4A3A00"
  good: "#0E7A4B"
  warn: "#9A5B00"
  bad: "#B3261E"
typography:
  display:
    fontFamily: Archivo
    fontSize: 3.5rem
    fontWeight: 800
    lineHeight: 0.95
    letterSpacing: -0.03em
  title:
    fontFamily: Archivo
    fontSize: 1.5rem
    fontWeight: 700
    lineHeight: 1.1
    letterSpacing: -0.02em
  ui:
    fontFamily: Archivo
    fontSize: 0.9375rem
    fontWeight: 400
    lineHeight: 1.5
  small:
    fontFamily: Archivo
    fontSize: 0.8125rem
    fontWeight: 600
    lineHeight: 1.35
  mono:
    fontFamily: JetBrains Mono
    fontSize: 0.75rem
    fontWeight: 500
    lineHeight: 1.2
    letterSpacing: 0.01em
rounded:
  sm: 4px
  md: 10px
  lg: 18px
spacing:
  xs: 4px
  sm: 8px
  md: 16px
  lg: 24px
  xl: 48px
components:
  button-primary:
    backgroundColor: "{colors.cobalt}"
    textColor: "{colors.sheet}"
    rounded: "{rounded.md}"
    padding: 12px
  button-signal:
    backgroundColor: "{colors.signal}"
    textColor: "{colors.ink}"
    rounded: "{rounded.md}"
    padding: 12px
  button-quiet:
    backgroundColor: "{colors.raised}"
    textColor: "{colors.ink}"
    rounded: "{rounded.md}"
    padding: 12px
  beat:
    backgroundColor: "{colors.sheet}"
    textColor: "{colors.ink}"
    rounded: "{rounded.lg}"
    padding: 20px
---

## Concept card

**Idea.** Pulse is a trading floor for intentions. Everything a person or a business wants (sell this, find that, hire her, get hired, partner on this) is a signal on the floor. Pulse introduces the ones that fit, and always says why.

**Metaphor.** Two lines running toward each other and meeting. In the interface your line is cobalt, theirs is ink, and every reason the two fit is a node where the lines touch. A match with three reasons has three nodes. A match with none does not exist.

**Signature: the sync meter.** On every beat (a card on the floor) a short two-line figure shows the match: your line, their line, a yellow node per shared reason, each node labelled with the plain sentence that is its reason ("You need bookkeeping, they offer bookkeeping", "Both in Gauteng"). Tapping a node removes that input from your recommendations. The figure is never decoration: it draws from the real `reasons[]` of the match and from nothing else. A beat without a match shows no meter.

**Emotion.** Confident, quick, a little bossy, warm underneath. A place that has made up its mind about you being worth introducing.

**Motion verb.** Click into place. Things travel briefly and lock: a short ease-out, a 2 px overshoot at most, then still. The lines draw toward each other and the nodes snap on in order. Nothing bounces twice, nothing loops.

**Screenshot.** The Floor at 1440 px: a cobalt masthead with the mode dial (All, Trade, Work, Local, Learn) in yellow, a single column of beats on chalk, one beat opened to show its sync meter with three yellow nodes and their sentences, and a rail of verbs on the left.

## Anchors (what the concept depends on)

1. Pulse's product is the explainable match. The research and the brief both say recommendations must be explainable, and silence after effort is the commonest complaint in the category. The meter makes the reason the first thing seen.
2. Dot.Pulse's own name: a pulse is two beats in sync. Two lines meeting is the smallest honest picture of that.
3. The ecosystem's gold and ink: the InfoDot membership signal stays a yellow, so the member feels the same material across platforms.

## What the concept decides

| Decision | Rule |
|---|---|
| Layout | One floor. A left rail of verbs (Floor, Trade, Work, Lines, You) and a compose button; a centre column of beats at 680 px; a right column for context (filters, who is open, your door) from 1200 px. Phones: a bottom bar of the same verbs and a floating compose. |
| The mode dial | A segmented control in the masthead: All, Trade, Work, Local, Learn. It changes the query, not the page. Its selected segment is yellow. |
| Beats | A beat is a card with the kind (a mono word: OFFER, NEED, ROLE, TALENT, ASK, EVENT), a heading that states the thing, who it comes from with their honest verification, the sync meter when there is a match, and two actions: Knock (a contextual request) and Save. No like count, no follower count. |
| Ending | The floor ends: "That's the round. You are caught up." with the time the next round is ready. |
| States | Colour never carries meaning alone. Verified is a ladder of named checks with dates; claimed is labelled claimed. Loading is a line drawing in, not a spinner. |
| Voice | Short, bossy, kind. "Say what you want." "Knock." "They said yes." "Not now is a fine answer." Sentence case. No exclamation marks, no "supercharge", no "unlock". |
| Empty states | Honest and actionable: "Nothing matches yet. Say what you need and the floor will find it." |
| Motion | Beats enter in order with a 24 px rise and 40 ms stagger, max six. Meters draw once on first view. Under reduced motion all state changes are instant and meters are drawn complete. |

## Colors

Chalk `#EEF0F5` is the ground, cool rather than cream. Sheet `#FFFFFF` is a beat. Ink `#0B0E1A` carries text and the "their" line. Cobalt `#1F3CFF` is the field: masthead, primary buttons, "your" line, links; white on cobalt is 7.6:1. Signal yellow `#FFD400` is rare and always means "this fits" or "selected": match nodes, the selected mode, the compose button, the InfoDot membership mark; text on yellow is ink. Muted `#4B5266` is secondary text and passes AA on chalk and on sheet. Good `#0E7A4B`, warn `#9A5B00`, bad `#B3261E` are for states and always come with an icon and a word.

Dark theme keeps the roles: ground `#0B0E1A`, beat `#141A2E`, ink `#EEF0F5`, cobalt lifted to `#7A8DFF` for text and lines, `#2B45FF` for fills, signal unchanged.

## Typography

Archivo, a variable grotesk with a width axis. Display and titles use width 112 to 125 and weight 700 to 800, set tight (-0.02 to -0.03em), used large and rarely. Interface text uses width 100 at 15 px, small labels 13 px at 600. JetBrains Mono at 12 px for kind words, numbers, times and prices with tabular figures; never for decoration. Fonts are self hosted in `public/fonts/` and mirrored to the docroot on deploy.

## Layout

Rail 232 px, floor 680 px, context column 320 px, 24 px gutters; below 1200 px the context column folds into a drawer; below 860 px the rail becomes a bottom bar of five and the floor is full width with 16 px gutters. Masthead 64 px. Beats are 20 px padded with 12 px between. Tap targets at least 44 px on phones.

## Elevation

Flat. A beat has a 1 px line border and sits on chalk; hover raises it by a 4 px shadow at 8 percent ink. Floating things (compose, drawer, menus) use one stronger shadow. No glass, no blur, no gradient washes.

## Shapes

Radius 4 for chips, 10 for buttons and fields, 18 for beats. Lines in the meter are 3 px with round caps; nodes are 14 px circles with a 2 px ink ring; an unverified claim is a hollow ring.

## Components

Button primary (cobalt), signal (yellow, for the one action that moves things forward), quiet. Mode dial. Beat. Sync meter. Verification ladder (named checks in order with dates, claimed items hollow). Door badge (open to knocks, until when). Knock sheet (the contextual request, at most four fields, no links). Line row (conversation with context title, state, last activity). Quote slip. Outcome bar (both sides confirm: Won, Hired, Partnered, Not this time). Notification row. Digest card. Consent row (plain sentence, one toggle).

## Do and do not

Do show why. Do end the round. Do label claims as claims. Do give every stranger contact a stated reason. Do let one yellow thing lead on a screen. Do not add counters of vanity (followers, likes), fake urgency, fake scarcity, fake "N people viewing", decorative pulse animations, gradient blobs or glass. Do not use yellow as decoration. Do not animate anything that is not a state change or a first draw of real data.
