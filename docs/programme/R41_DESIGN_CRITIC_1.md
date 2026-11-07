# Dot.Pulse design jury, critic 1

Judged from the browser only. Screenshots: /private/tmp/claude-501/-Users-sakhilebhayi-Dot/e1920f4e-d237-4804-8adb-eb4bdf16c1c4/scratchpad/crit-shots/ (names below are relative to that folder). Personas walked: stranger, 12, 2, 7, 9, 10, 14, 13, 11. Onboarding could not be reached (every seeded user is already onboarded; guessed URLs 404), so it is unjudged.

## Scores (Awwwards weights)

| Category | Score | Evidence |
|---|---|---|
| Design (40%) | 7.0 | Cobalt/ink/yellow palette and the two-line "thread" motif are distinct and consistent (u0-d-l-landing-y0.png, u12-d-l-quotes.png, u10-d-l-disc.png). Held back by repeated big thread panels, inconsistent content columns, mixed button vocabularies. |
| Usability (30%) | 6.0 | Strong forms, inline errors, honest empty states. Hurt by a /operator crash, the floor emptying on refresh, seller manage entry hidden, thread page layout, double-highlighted nav. |
| Creativity (20%) | 7.5 | "Every match shows its reasons" drawn as two crossing lines with yellow nodes; quote slips; "what an employer sees" live card; the "Stop using this" control. |
| Content (10%) | 7.0 | Plain, specific, human ("This line does not meet.", "Nothing happens without a yes."). Leaks: seed text, source codes, ISO timestamps, "1 minutes". |
| Developer view | 6.5 | No horizontal overflow at 390; good ARIA tab names; skip link; 1 h1 per page; dark mode complete. Fixed Compose FAB covers content on mobile; 11px labels; focus not returned after validation error; no scroll lock under the sheet; one 500. |
| **Overall** | **6.8 / 10** | Below the 7.2 to 7.6 Site-of-the-Day band: the idea is a 8, the finish and robustness are a 6. |

**Central idea and signature (one sentence):** Pulse introduces people and businesses only with a visible, dismissable reason, and the cobalt-vs-ink crossing thread with yellow nodes is the signature; the landing and quote slips express it well, but the floor repeats it so often it stops being special.

## What feels generic or templated
- Operator dashboard (u14-d-l-operator.png): equal-width stat-card grid, a mono "Right now / In range" tag on every card, orphaned fourth cards on three rows.
- Business manage Overview (u2-d-l-manage.png): four equal stat tiles, one orphaned.
- Every floor card is the same stack: tag row, title, blurb, avatar row, thread panel, three buttons. Ten in a row reads as one card repeated (u12-d-l-floor.png).
- Stock Jetstream screens: /user/profile (u12-d-l-jet-y0.png) and /community (u12-d-l-compose.png) use different buttons, tabs and spacing from the rest.
- Decorative sidebar icons plus an extra page title in the top bar that duplicates the h1 on every page.
- Native blue radios on /need/new vs yellow-check custom radios elsewhere.

## What is excellent
- Landing copy and structure (u0-d-l-landing.png): four sections, no stock imagery, every example labelled "Example only".
- Quote comparison (u12-d-l-quotes.png): same fields side by side, honest "Price is only one of them."
- Discoverability page with the real anonymous card preview (u10-d-l-disc.png).
- Verification ladder that separates Checked from Claimed (u12-d-l-biz.png).
- Empty states with a next step; inline error on the Knock sheet (u11-d-l-knock-empty.png); 404 in brand voice (u0-d-l-notfound.png).
- Mobile reduces the thread to three yellow-dot reasons, a good adaptation (u11-m-l-floor-y0.png).

## Usability problems found
- /operator returns 500 from the second load onward (first load fine): "Illuminate\Support\Carbon ... incomplete object" at dashboard.blade.php line 1 (u14-m-l-operator.png). The cached snapshot is unserialised without its class.
- Floor is consumed on view: reloading, or opening on a second device, gives "That's the round. You are caught up." with zero cards (u12-m-l-floor-y0.png). Work shows the same message for a user who has never seen anything (u12-d-l-work.png), which is untrue.
- Floor order changed between two loads of the same user.
- Seller (user 2) and employer (7) have no nav entry to their business; "Manage this page" exists only on the public business page. Manage tabs highlight "You" in the sidebar.
- Sidebar double-highlights: "Floor" and "Saved" (u12-d-l-saved-y0.png), "You" and "Open door" (u12-d-l-door.png), "Floor" on a need or offer page.
- Thread page (u12-d-l-thread5-y0.png): context card then composer then messages; the page opens at the top, the quote (the point) is below the fold, the sticky composer covers 190px (about 25% of a phone) and clips "See the live need". "Ctrl or Cmd and Enter sends" shows on touch phones.
- Mobile floor: the yellow Compose FAB sits over "Not for me" and the price (u11-m-l-floor-y500.png).
- Offer page has two different primaries: yellow "Ask about this" and blue "Ask for a quote" (u12-d-l-offer.png). Need page has two yellow primaries plus a full-width red Delete (u12-d-l-need1.png).
- Destructive Delete sits flush beside Archive/Pause/Close in rows (u2-d-l-m-offers.png, u7-d-l-roles.png).
- Two profile editors with overlapping fields (headline, bio, skills): /me/profile and /user/profile.
- After a failed Knock the focus stays on the button, not the invalid field; typed text goes nowhere. Page scrolls behind the open sheet.
- Search shows two search boxes (top bar and page). Every result is a full thread card, which makes scanning slow (u12-d-l-search-plumb.png).
- Business page: 7-rung ladder dominates and the left column is empty under one offer (u12-d-l-biz.png).

## Wrong, misleading or leaking copy
- "created by pulse:demo-seed" in descriptions; "Source: infodot_redeem_teams / pulse_account / business_claim" (u12-d-l-beat5.png); ISO timestamps "2026-11-07T22:44:27+00:00".
- "Usually within 1 minutes"; "Answered 100% of 1" and "On Pulse since ... (0 days)" shown as a checked trust rung; "Time on Pulse: Checked" is not a trust signal.
- "Reach: Near them" (u12-d-l-need1.png); "Copper Kettle Plum" truncated in the thread label.
- Open tab badge says 3 while 4 lines show (u12-d-l-lines-open.png), unread vs total is not explained.
- "Market related" and "R1 200" set in jumbo monospace (u7-d-l-role.png).

## FIX LIST

### P0 (breaks trust or usability)
1. **/operator** 500 on repeat load. Cache plain arrays/strings (ISO dates) not Carbon objects, or allow Carbon in unserialize; add a test that loads the page twice. Also confirm debug pages are off outside local.
2. **Floor / Work empty after view.** Do not drop a beat until it is acted on or 24h old; keep a "Seen earlier" strip with an Undo, and make "caught up" appear only after the user has scrolled the last card. First-visit empty text should read "Nothing fits yet. Add skills or a need to get matches." with a button.
3. **Seller and employer entry.** Add "Your businesses" under "More" (and in the avatar menu) listing each page with Manage; show a "Manage" chip on the business's own floor cards. Highlight the sidebar item that matches the section.
4. **Thread page.** Collapse the context card to a 2-line summary with "What was asked" disclosure, open scrolled to the newest message, anchor the composer at the bottom with a 1-line height that grows, hide the keyboard hint on touch.
5. **Leaked internals.** Replace source codes with plain labels ("Your Pulse account", "What the business told Pulse"), format all dates as "7 Nov 2026, 22:44", strip seed text from the demo data, pluralise ("under a minute").

### P1 (clearly hurts the score)
6. **Floor card weight.** Show the thread only for the first card and for the expanded card; for others show the reasons as a single line of three yellow-dot chips. Cuts card height about 40% and restores the signature's impact.
7. **Mobile Compose FAB.** Move into the bottom nav centre or hide it on the floor; keep 16px clear of card buttons.
8. **Sidebar active state.** One item active at a time; "Open door", "Saved", "Search" get their own state, "You" only on /me/*.
9. **One primary per screen.** Offer page: merge to one yellow "Ask for a quote" with a text link "Ask a question first". Need page: "Ask for quotes" yellow, "It worked out" outline.
10. **Destructive actions.** Move Delete into an overflow menu or separate it by 24px and use outline red; confirm in a sheet that names the item.
11. **Business page hierarchy.** Show offers and roles first; collapse the ladder to a summary line ("2 of 7 checked") with "See the checks" disclosure; drop "Time on Pulse" as a checked rung.
12. **Unify layout.** Pick one main column (e.g. left edge 256px, max 760px) for every screen; today it starts at 256, 324, 366 and 496px (compare u12-d-l-search.png, u12-d-l-lines.png, u12-d-l-me.png).
13. **Replace stock Jetstream pages** (/user/profile, /community, /teams) with the Pulse shell and components, or merge into /me tabs; remove the duplicate profile editor.
14. **Form a11y.** On failed submit move focus to the first invalid field and set aria-invalid; lock page scroll behind the sheet; return focus to Knock on close.
15. **Search results.** Use a compact result row (tag, title, business, one-line "why") with the thread on hover/expand; remove the page's own box or the header box.

### P2 (polish)
16. Hero: the right 55% is empty at 1440; place the example match card there or widen the thread (u0-d-l-landing-y0.png). Ink line on cobalt is low contrast, use a lighter ink or white.
17. Stat tiles: show number first, label second, no orphans (use 2 or 4 columns); "100% of 1" becomes "1 of 1 answered".
18. Monospace large figures ("under an hour", "Market related", "R1 200") to 20px sans with tabular figures; mono only for money digits.
19. Top-bar page name next to the logo duplicates the h1; remove or make it a breadcrumb.
20. Notifications: replace five "Mark read" buttons with a swipe/tap-to-read row and one "Mark all read".
21. Raise 11px nav labels and ladder meta text to 12px+, and tap targets under 44px on mobile (skill remove "x" 28px, "Clear" 32x20, ladder links 16px high).
22. Open tab badge: label it "3 unread" and show total in the list heading.
23. Floor ranking: make the order stable within a round and say why a card is first ("Best match: 3 reasons").
24. Sheet: on desktop centre it with all four corners rounded; add "About: <business>" in every state.
25. Add Delete/Archive undo toasts and a visible "Saved" confirmation on discoverability changes.
