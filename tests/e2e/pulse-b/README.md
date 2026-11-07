# Acceptance suite B (R-41, Dot.Pulse)

Real-browser journeys for acceptance criteria 9, 10, 13 to 17, 19 and 20 of the R-41 brief. Local only: it is never deployed and is not part of the PHPUnit suite.

## Run

```sh
cd tests/e2e/pulse-b
npm install              # playwright-core and axe-core only
node run.mjs             # every spec, in file order
node run.mjs s09 s14     # only specs whose file name starts with these
SERVE=1 node run.mjs     # just boot a fresh seeded app on :8791 (for poking with dbg*.mjs)
```

Needs Chrome (`/Applications/Google Chrome.app`; override with `CHROME_PATH`), PHP, `sqlite3` and `lsof`. The public assets must be built first (`npm run build` at the repo root).

For every spec the runner: deletes `out/e2e.sqlite`, migrates, seeds the gazetteer, clears the file cache, runs `seed.php`, starts `php artisan serve --port=8791` (output to `out/server.log`), runs the spec, and stops the server. Environment is forced: `APP_ENV=local`, `APP_DEBUG=false`, SQLite, file sessions and cache, sync queue, `log` mailer (mail is read from `storage/logs/laravel.log`), no broadcaster, no Analytics settings, no model key. Your `.env` is not touched.

## Layout

| File | What |
|---|---|
| `seed.php` | people, pages, offers, a role, a need, matches, a line, a community, built through the real services. Writes `out/seed.json` |
| `lib.mjs` | browser, `person()` (own cookies, console capture), axe, screenshots, mail log reader, `sql()` against the run's database |
| `specs/s09-notifications.mjs` | criterion 9 |
| `specs/s10-block-report.mjs` | criterion 10 |
| `specs/s13-integrations-claims.mjs` | criterion 13 and the landing page claims |
| `specs/s14-private-data.mjs` | criterion 14 |
| `specs/s15-responsive.mjs` | criterion 15 (1440, 1024, 768, 390, 360, light and dark) |
| `specs/s16-crawl.mjs` | criterion 16 (every control on every page) |
| `specs/s17-gaps.mjs` | criterion 17: doors, saved, follow, quotes |
| `specs/s19-errors.mjs` | criterion 19 |
| `specs/s20-journeys.mjs` | criterion 20 |

Signing in uses `GET /dev/login-b/{id}?to=/path`, registered in `routes/web.php` inside a `// --- dev-b ---` block only when the environment is `local` or `testing`. It does not exist in production. `/dev/boom-b` (same block) throws on purpose to show the 500 page.

Every spec records console errors (a failure) and runs axe on the pages it visits (zero serious or critical). Screenshots are in `out/shots/`.

## Gotchas learned

- `artisan serve` forks the real server: kill the process group and whatever listens on the port, or the next run answers from an old process.
- The dev server must not write to a pipe: stdout goes to a file or the PHP notice ends up inside JSON responses.
- The Floor shows each beat once and marks it seen, so a person's first page after signing in matters: `login()` lands on `/lines`.
- The file cache outlives the database (per user id counters such as the knock allowance): the runner clears it for every spec.
- Laravel's log mailer writes already decoded quoted-printable text.
