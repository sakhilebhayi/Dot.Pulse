# R-41 demo world

`php artisan pulse:demo-seed` builds a fictional South African demo world through the real services (EntityService, OfferService, RoleService, NeedService, ProfileService, LineService, QuoteService, OutcomeService, ReviewService, Doors), so every row obeys the same rules as production data. It refuses to run when `APP_ENV=production` (the command exits with a failure and the seeder throws). It is idempotent: a second run finds what exists and adds nothing.

What it creates: 14 people with distinct roles (three plumbers who each own a page, a baker, a bookkeeper, a Durban logistics operator, an employer building a team, a Cape Town designer, an open professional, an anonymous professional, a learner over 18, a consumer with needs, an unrelated person, a moderator), 8 business pages (seller, employer and both) with 10 offers and 3 roles, hiring signals (building and active), 6 needs, 2 doors, one unanswered request, one open conversation, one quote comparison with 3 slips from 3 providers, and one confirmed result with both blind reviews revealed. Places come from the gazetteer (`pulse:places:seed` runs inside it). Two pages are linked to an InfoDot company their owner belongs to (rung "InfoDot team member"), others hold claims (registration number, website, phone); none is ever "verified". Matches are written by `pulse:match --all` at the end.

Everything is invented: names, businesses (every page says "A fictional demo business"), phone numbers in the 555 range, websites on `.example`, e-mail addresses on `demo.pulse.test`. The demo accounts have a random password nobody knows.

Sign in as anyone with the local-only route `GET /dev/login/{id or email}` (optionally `?to=/lines`), and `GET /dev/logout`. The routes sit in the `// --- dev ---` block of `routes/web.php` and are registered only when the environment is `local` or `testing`; elsewhere they do not exist. The seed command prints a table of every person with their login address.

The end-to-end journeys in `tests/e2e/pulse/` run against a fresh SQLite database seeded this way (see its README).
