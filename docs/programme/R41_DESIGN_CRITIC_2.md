# R41 Design Critic 2: Dot.Pulse (independent juror, browser-only)

Screenshots: /private/tmp/claude-501/-Users-sakhilebhayi-Dot/e1920f4e-d237-4804-8adb-eb4bdf16c1c4/scratchpad/crit2-shots/ (file names cited below; pattern `<user>_<width>_<l|d>_<name>.png`).
Method: Chrome headless, 1440x900 and 390x844, light and dark, personas anon, new, 12, 2, 7, 9, 10, 13, 14. No source read.

## Scores (out of 10)

| Category (weight) | Score | Evidence |
|---|---|---|
| Design (40%) | 7.3 | Landing (anon_1440_l_landing.png) is distinctive: cobalt slab, ink and cobalt strand with yellow nodes, wide Archivo display, mono labels. Interior (12_1440_l_floor.png, 12_1440_d_floor.png) is clean and consistent but reduces to a stack of near-identical white cards. Dark mode is coherent. |
| Usability (30%) | 7.0 | Onboarding (new_1440_l_step2/5.png) is exemplary. Knock sheet with inline error works. Defaults that land on empty tabs, a floor that loses its best moment on revisit, leaked "title field" error, long single-column forms hurt. |
| Creativity (20%) | 7.6 | The strand as "match with reasons" is a real signature; vocabulary (knock, line, door, beat, floor) is owned. Under-exploited past the first card and the landing. |
| Content (10%) | 8.0 | Plain, honest, specific copy ("Claimed by them, not checked", "Nothing happens without a yes"). Minor machine-sounding search reasons and filler reasons. |
| Developer view | 7.5 | No console errors, 71 ms DOMContentLoaded, ~360 KB total assets, landmarks, one h1, lang, reduced-motion CSS present, no horizontal scroll at 390. Native file/date inputs unstyled; header chrome heavy on mobile. |
| **Overall** | **7.3** | (0.4x7.3 + 0.3x7.0 + 0.2x7.6 + 0.1x8.0) |

## Central idea and signature (one sentence)
Pulse is a consent-first matchmaker where every introduction must show its reasons, expressed as two intertwining lines (you, them) that meet at yellow nodes; the design expresses it superbly on the landing page and on the top floor card, then drops it for the rest of the product.

## What is excellent
- Landing page (anon_1440_l_landing.png, anon_390_l_landing_scrolled.png): argument-led sections, honest "Example only" labels, verification ladder explained, no stock imagery, no gradients or glass.
- Onboarding: 7 short steps, one decision each, "Skip this" plus "Nothing here is permanent", defaults safe (new_1440_l_step5.png).
- Discoverability (9_1440_l_disc.png): live preview of exactly what an employer sees in anonymous mode, k-anonymity note, "Hide from your current employer". Best screen in the product.
- Privacy tab "What you have said yes to" with one-tap withdrawal; erase panel lists Gone vs Kept and why (9_1440_l_privacy.png).
- Quotes comparison (12_1440_l_quotes.png): same fields side by side, "Price is only one of them". Operator view admits "No data yet" (14_1440_l_operator.png).
- Error pages in voice ("This line does not meet.", "Not yours to open."), correct 403/404 separation (non-owners get 404 on private pages).
- Knock sheet validates inline (12_1440_l_knock_empty_error.png); "Not for me" asks why; dark mode and 390px are solid.

## What feels generic or templated
- Floor beats are an equal-weight vertical stack of white cards, each with identical chips and identical three buttons (12_1440_l_floor.png, 13_1440_l_floor.png). Only the first card gets the strand.
- Reason chips such as "Posted today." and "They are in Cape Town, in your city." appear on every card; they are tautological, not reasons, and make the strand claim ("always says why") feel thin.
- Every page is a stack of titled white cards with the same radius and 12 px grey microcopy (manage Overview, Offer, Role, Need pages).
- Stat tiles with mono numerals and "label under value" (2_1440_l_manage.png) are dashboard boilerplate; "1 of 1 first messages answered" is a sample of one.
- Community page (12_1440_l_community.png) imports social-network furniture ("Trending", "Your standing", "Your points 0", "#something") that contradicts the rest of the product's voice.
- Right rail on Floor ("What you have open 0 / 0 / 0", "Keys") is filler for new and sparse users.

## Usability problems
1. Lines opens on "Requests" (empty) while "Open (3)" holds all content, and the nav badge says 3 (12_1440_l_lines_open.png; same for the seller, 2_1440_l_m_lines.png).
2. Revisiting the Floor flips the top into "That's the round. You are caught up." and demotes every beat to "Seen earlier" without the strand (13_1440_l_floor_r3.png, 12_390_l_floor_vp.png). Reload twice and the signature disappears and the first screen is text.
3. Leaked internal field name: need form shows "The title field is required." twice (banner and field) though the field is labelled "Say it in a line" (13_1440_l_need_error.png).
4. Empty states offer wrong CTAs: "Work" empty state for a consumer pushes "Say what you need" / "Say what you are open to" (12_1440_l_work.png); employer's caught-up state also says "Say what you need".
5. Save keeps the card on the Floor labelled "Saved" while copy says saved beats "leave the round"; toast "Saved. Find it under Saved." overlaps the next card (12_1440_l_after_notforme.png).
6. Search: autocomplete list stays open over results and offers "Pool building" for "plumbing"; result reasons read machine-made ("Matched your words in the title. Matches the topic plumbing.") (12_1440_l_search_results.png).
7. Sidebar highlights "Floor" on business, offer, role, need and community pages; on mobile "You" is active for business manage. Wayfinding is unreliable.
8. Thread page: safety banner and a collapsed "What was asked" row sit above the conversation; the page opens at the top, not the latest message (12_1440_l_thread5_vp.png). Quotes at 390 stack three long cards, so "compare" becomes scrolling (12_390_l_quotes.png).
9. Business Page tab is a 3,200 px single column with native "Choose File" inputs and only one Save at the bottom (2_1440_l_m_page.png); date input shows native "yyyy/mm/dd" (12_1440_l_neednew.png).
10. Trust copy conflict: public page shows "Not verified" or "Details claimed, not verified" pill next to "1 of 6 checked" (7_1440_l_public.png, 2_1440_l_public.png). Offer page repeats the same sentence as tagline and body (2_1440_l_offer.png).
11. Seed leakage visible to a visitor: "Fictional demo listing", "Fictional demo person", "A fictional demo business" in live descriptions; every beat says "3 min ago".
12. Mobile: three rows of blue chrome (logo row, icon row, mode tabs) before the page title; manage tabs clip at "Team" with no scroll cue; bottom nav overlaps the last stat tile (12_390_l_floor_vp.png, 2_390_l_manage.png).
13. Login: "Open Dot.Pulse from your InfoDot dashboard" contradicts the button "Sign in with InfoDot" that goes to the infodot.co.za home page. Login and 404 content starts at x=130 while the header logo sits at x=25 (anon_1440_l_login.png).
14. Notifications page jams inbox and settings into two 300-380 px columns; fieldset legends straddle borders and "By email" toggles wrap (12_1440_l_bell.png). Notification strings read "...request: About: Monthly bookkeeping".
15. Stray space and mixed date style: "Since 7 Nov 2026 , until 14 Nov 2026, 23:34" (9_1440_l_privacy.png). Strand end label truncated: "Copper Kettle Plum" (12_1440_l_beat15.png).
16. Small targets: "Show how it fits" 32 px high, floor card titles 19 px high link boxes, desktop mode tabs 38 px. Mobile primary buttons are fine.

No error pages, 500s or console errors were hit in any walk.

## FIX LIST (prioritised)

### P0 (trust / correctness)
1. **Need create, error**: replace "The title field is required." with "Say what you need in a line." in both places and show it once (banner or inline). Internal field name is leaking.
2. **Lines, default tab**: open on the first tab that has content (Open when Requests is empty) or make the nav badge deep-link to the tab that holds the count. Applies to /lines and business manage Lines.
3. **Floor revisit**: keep the first card as a strand-bearing beat even in "Seen earlier", or collapse the caught-up block to a one-line banner above the cards. Today the signature vanishes on the second load.

### P1 (clearly hurts score)
4. **Floor cards**: give two beat densities. Top 1-3 get the full strand; the rest drop to a compact row (title, business, one real reason, Knock). Stop repeating identical chips and three buttons per card.
5. **Reasons quality**: never show "Posted today." or bare city match as a reason; fall back to "Nearby, no other reason yet" once, at group level. Strengthens "always says why".
6. **Empty states by persona**: Work and caught-up CTAs should follow role (consumer: "Open to work? Add it to your profile" or hide; employer: "Post a role").
7. **Sidebar active state**: highlight Floor only on floor routes; business pages highlight the business entry; community and need pages get their own state. Fix mobile "You" on manage.
8. **Business Page tab**: split into 3 sections with a sticky Save bar, restyle file inputs as drop zones with preview, use two columns at desktop width.
9. **Search**: close the suggestion list on submit, fix fuzzy suggestions ("Pool building"), rewrite reasons in human words ("Plumbing, in your city").
10. **Mobile header**: merge to one 56 px bar plus a scrollable mode strip; hide the "+" duplicate of Compose behind the bottom nav; add edge fade or chevrons on clipped manage tabs.
11. **Thread page**: collapse the safety banner after first view, open at the latest message, make the composer the only fixed element, show quote cards in a summary table on mobile quotes.
12. **Trust pills**: one verification vocabulary. Replace "Not verified" pill with "Claimed, 1 of 6 checked" everywhere; remove duplicated offer sentence.

### P2 (polish)
13. Strip "Fictional demo ..." text from live copy and vary seeded timestamps; remove "1 of 1" style stats until n >= 5.
14. Community: remove "Your standing / points / Trending" until real, or restyle to the control-room language; align the page title size with other pages (Lines and You use ~40 px, Floor ~26 px).
15. Notifications: separate inbox and settings (two tabs), widen to one column, fix legend borders and toggle wrapping; rewrite "About: X" strings.
16. Fix login copy and left-gutter alignment on login, 404 and 403 pages.
17. Date input and tag input: styled date picker; tag field shows suggestions as promised ("Pick from the list").
18. Toast: anchor bottom-left away from cards, add Undo for Save and "Not for me".
19. Grow tap targets: "Show how it fits" to 40 px, desktop mode tabs 40 px, card title link area.
20. Normalise date/time style: remove stray space before commas, one format ("Sat 14 Nov, 23:34" vs "14 Nov 2026"); fix truncated strand end label by wrapping or abbreviating.
