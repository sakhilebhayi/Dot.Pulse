# Privacy, visibility and moderation

Dot.Pulse is one global social network. This page is the exact statement of
what is public to every signed-in user, what is private, and who can
moderate what. If code and this page disagree, the code is wrong or this
page is out of date: fix one of them.

## Visibility is defined once

`Community::isVisibleTo($user)` is the rule. `PulsePost::scopeVisibleTo()`
is the same rule written as SQL for lists. Every listing of posts goes
through the scope: the home feed, the Following tab, a community page, a
profile, trending hashtags, the hashtag filter and the dashboard total.

| Community `visibility` | Visible to |
|---|---|
| `public` | every signed-in user |
| `private`, `enterprise` (anything not `public`) | members, and the creator |

For a viewer who cannot see a private community: its page and its posts'
pages are **404** (not 403, so existence is not confirmed by the error), its
posts are absent from every list and from trending, and its hashtags do not
count. The directory still lists its **name, industry and member count** so a
person can find it and ask to join. Nothing else about it is shown.

Posts with no community are public to every signed-in user.

## Joining

- **Public**: one click, immediate.
- **Private**: "Request to join". A manager (the creator, or a member with
  the `admin` or `moderator` role) approves or denies it. Managers are
  notified of a request; the requester is notified of the decision (without
  being told which manager decided). After a denial the same person cannot
  ask again for `PULSE_JOIN_REQUEST_COOLDOWN_DAYS` days.
- There is no invite-by-link yet.
- Posting into any community requires membership.

## Post states

| `status` | Meaning | Visible to |
|---|---|---|
| `pending` | The automated check is running | Author |
| `flagged` | Held for a human: the check was unsure, said "reject", or could not run | Author, responsible moderators |
| `published` | Live | Anyone who can see the community |
| `removed` | A moderator removed it; the row and the log stay | Author, responsible moderators |
| (soft deleted) | The author deleted it | Nobody |

Only `published` posts can be reacted to, commented on or have a solution
marked. Editing a live post sends the new words back through the automated
check (the post is `pending` again until it decides).

## Automated check (fails closed)

New posts are checked by `App\Jobs\ModeratePost` in a queue worker, not in the
web request: 10 second HTTP timeout, 3 tries, backoff 10s then 30s.

A post is published **only** on an explicit `approved` verdict. Every other
outcome holds it for a human:

- no `ANTHROPIC_API_KEY` (held immediately),
- timeout, connection error, non-2xx answer, or an answer that is not valid
  JSON with a `moderation_status` (held after the third failed try),
- `flagged` or `rejected` verdicts (held; a "reject" never removes on its own).

If the worker is not running at all, `pulse:sweep-pending` (scheduled every
five minutes) holds posts that have been pending for 15 minutes. Held posts
show "Automated review unavailable" in the queue, and the author sees
"Waiting for a moderator" on the dashboard and the post page.

Post text is untrusted input to the model: it is wrapped in delimiters with an
instruction to ignore directions inside it. That reduces, not removes, prompt
injection. The human queue is the control that matters.

## Who can moderate what

| Role | Source | Scope |
|---|---|---|
| Platform moderator / admin | `pulse_profiles.role` of `moderator` or `admin` | All held posts and all reports, including profile reports |
| Community moderator | `community_memberships.role` of `moderator` or `admin`, or being the creator | Held posts and reports for that community only |

Every action re-checks the specific post, comment or profile against the
moderator's scope (`PulsePostPolicy::moderate`, `CommunityPolicy`). Removing
content never deletes the row; it writes a `pulse_moderation_logs` entry
(`approve`, `reject`, `remove`, `dismiss_report`, `review_report`, `flag`)
with the moderator and reason.

## Reports

Any signed-in user can report a post, a comment or a profile they can see and
did not write: reason from a fixed list plus an optional note (required for
"Something else"). One report per person per target (a unique index backs it)
and at most `PULSE_LIMIT_REPORT` per minute.

The queue groups reports per target with a count and the reasons. Moderators
see the notes, never the reporter. Resolving removes the post or comment (a
reason is required and logged); dismissing leaves it. Each reporter is told
once that the report was reviewed and whether action was taken; the author is
told only if their content was removed. Neither message names anyone.

## Limits

Per user per minute: 20 posts, 30 comments and replies, 60 reactions, 10
reports, 10 membership requests. Over the limit the action fails with HTTP
429 and a `Retry-After` header; the page shows a short notice.

## Counters

`reactions_count`, `comments_count`, `members_count` and hashtag
`posts_count` are moved by atomic increments inside transactions, and only
when a row really changed. `php artisan pulse:recount --dry-run` compares
them with the rows and exits 1 on drift; without `--dry-run` it repairs them.

## Not covered

- Direct messages, polls, media, events and the marketplace have tables but no
  code, so they have no privacy rules yet.
- Comments are not run through the automated check (only posts are).
- Private means hidden from other users, not encrypted at rest.
- SSO (`/auth/ecosystem`) is unchanged by this work.
