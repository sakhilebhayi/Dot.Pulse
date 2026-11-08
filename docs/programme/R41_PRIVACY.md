# Dot.Pulse: what we hold about you, why, and for how long

For people. The privacy page (`/me/privacy`) can link here. The numbers below are settings in `config/pulse.php` (blocks `// --- retention ---` and `// --- privacy ---`), changed with environment variables, not code. A daily job, `pulse:retention`, applies them at 03:40 UTC. Written 2026-11-08; a lawyer should read it before it is shown as the privacy notice.

## The short version

- You choose whether employers can find you: off, anonymous or by name. Anonymous means skills, experience band, region and headline, never your name, photo, email or exact place.
- An employer is shown an anonymous card only when at least 5 people look the same on skills and region. Smaller groups are never shown and never counted out loud.
- Nobody on Pulse can tell whether an email address has an account. Invitations to a team are answered by the person, on their own page.
- You can download everything we hold about you, and erase it, from `/me/privacy`.

## What is held, why, and how long

| What | Why we hold it | How long | Then |
|---|---|---|---|
| Account: name, email, InfoDot link | Sign in through InfoDot; show your name where you chose to be named | Until you erase your data or delete your account | Erased |
| Profile: headline, skills, place, experience, open-to, work history | To match you and to show your card on your terms | Until you change or erase it. Off removes you from search at once | Erased |
| Age declaration (18 or over) | Pulse is for adults; no one under 18 is found or contacted | With the account | Erased |
| Signals (the index row for each offer, need, role, card, post) | So the right people see the right thing | While live. Expired or removed signals: 90 days | Deleted, with their matches and saves |
| Matches and dismissals | Show "why you are seeing this" and stop showing what you dismissed | Live matches until the signal ends. Dismissals: 90 days | Deleted |
| Needs (what you are looking for) | Match you to sellers | Until it ends (30 days unless renewed). The words you wrote about it: 90 days after it ends | Details and budget text cleared, title kept |
| Doors (24 hour or week-long notes) | Let strangers knock once | Until they close; the row is removed with its signal at 90 days | Deleted |
| Requests that were declined or ignored | So a sender is not told twice and a refusal is respected | 90 days | Deleted with their message |
| Conversations (lines) and messages | The thread you and the other side agreed to have | Open lines: as long as they are open. Closed or expired lines: messages are erased after 24 months | Message text replaced by "This message was deleted." The line, its outcome and its dates stay |
| Outcomes and reviews | The result both sides confirmed, so Pulse counts only real results | While the other side's record needs it. Reviews you wrote are unlinked from your name if you erase | Kept, pseudonymous |
| Notifications | Tell you what happened | Unread: until you read them. Read: 12 months | Deleted |
| Audit log (who did what, when) | Security, abuse handling and proof of consent | The event is kept. After 24 months your identity (actor id and ip hash) is removed from the row | Rows are never deleted; they stop being about you |
| Reports you filed or that were filed about you | Moderation | Until a moderator resolves them. Messages with an open report are not purged | Kept for moderators; your name is removed on erasure |
| Team invitations | Let a page invite you | 14 days. The address is stored only as a keyed hash plus a masked hint. Deleted 30 days after the invitation ends | Deleted |
| Consent and preferences (contact policy, digest, quiet hours, mutes, blocks you made) | Honour your choices | Until you change them or erase | Erased. Blocks made against you stay, so a blocked person cannot come back by deleting their account |
| Photos you uploaded (avatar, message photos) | Show them where you chose | Until you remove them or erase | Files deleted from disk |

Pulse never sells data, never lets a business message you marketing through the platform, and does not decide anything about you by machine alone: a person always decides in hiring.

## Anonymous professionals

- The card shows skills (the first three are what the 5-person rule counts), experience band, region, headline. It carries no owner id, no profile id, only the start of the week you became findable.
- While you are anonymous you do not open doors or publish needs under your name, because they would let someone join them to your card. Switching to anonymous closes the ones you have. Community posts stay allowed and carry only the handle you posted under, never a link to your card.
- Hide from your employer: name an employer on a job and that employer's pages never see you and never count you in a cohort.

## Getting your data out

`/me/privacy`, "Download my data". A JSON file with your account, profile, consent, preferences, mutes, saves, needs, doors, pages, lines with messages, reviews, reports, posts, comments, communities, photos metadata, notifications, team invitations and the activity log. Limited to 3 downloads in 10 minutes. Signed in with a password or through InfoDot, you also confirm by typing the phrase shown on the page. Where mail is configured, Pulse emails a 6 digit code that lasts 10 minutes and you enter it too.

## Erasing your data

`/me/privacy`, "Erase my data". Type the phrase shown. Where mail is configured, enter the 6 digit code Pulse emails you (10 minutes). Erasure removes the profile text, discoverability, cards, needs, doors, saves, mutes, matches, preferences and Pulse notifications; blanks your messages; closes your open lines; removes your posts, comments, reactions, follows and memberships; deletes photos you uploaded; unlinks your reviews from your name; takes you out of the audit log. Pages where you are the only owner are listed for you to hand over first. It does not delete the login itself: that stays with account deletion.

## Who to ask

The operator of your Pulse install. Contact details belong on the privacy page.
