# Dot.Pulse end-to-end journeys (R-41)

Browser journeys that drive the real app in headless Chrome like a person, one spec per acceptance criterion of the brief
(1 to 8, 11 and 12 here). Each spec asserts real outcomes in the SQLite database or in the other party's browser, takes
screenshots at 1440 and 390 wide, runs axe-core (wcag2a and wcag2aa, zero serious or critical allowed) on the pages it
visits, and fails on any console error, page error or 5xx response.

```
cd tests/e2e/pulse
npm install            # playwright-core and axe-core only; Chrome or Chromium must be installed
node run.mjs           # every spec
node run.mjs 1 5       # only specs 1 and 5
```

What the runner does: builds a fresh SQLite file in `out/`, runs the migrations and `php artisan pulse:demo-seed`
(see `docs/programme/R41_DEMO.md`), then for each spec copies that database, boots `php artisan serve` on port 8781 (the
next free port if taken, or `PULSE_E2E_PORT`), runs the journey and stops the server. Results go to `out/results.json` and
`out/<spec>/` (screenshots, `result.json`, `server.log`). `out/` is git-ignored. The exit code is 1 when any spec fails.

Set `PULSE_E2E_CHROME` to a Chrome or Chromium executable if it is not in a standard place.

Signing in uses the local-only `GET /dev/login/{email}` route (exists only under `APP_ENV=local` or `testing`). Criterion 12
(InfoDot hand-off) uses `App\Services\InfoDot\FakeIdentityClient`, which the app binds only when the runner sets
`PULSE_E2E_FAKE_INFODOT=1` and the environment is local or testing; it is never bound in production.
