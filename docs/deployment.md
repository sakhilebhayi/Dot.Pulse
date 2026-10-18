# Deploying Dot.Pulse

Target: `https://pulse.infodot.co.za`, on the shared cPanel host that also runs the other Dot apps (InfoDot, Dot.Agents and the rest). Everything goes through GitHub Actions, run by hand.

**Status when this was written: nothing is set up.** There is no host, no DNS record and no repository secret for Dot.Pulse, and none of these workflows has ever been run against a host (they were checked with `actionlint`, `bash -n` and a local simulation of the crontab block only). They are inert until the checklist below is done: the first job of `deploy.yml` stops with the names of the missing secrets before anything runs.

| Workflow | Runs | What it does |
|---|---|---|
| `ci.yml` | every PR and push to `main`; called by `deploy.yml` | The PHPUnit suite. It is the release gate. |
| `drift.yml` | manual, **read-only** | Compares every tracked file of `main` with the host's copy (sha256) and prints counts and paths. Run it before the takeover. |
| `deploy.yml` | manual, `confirm=adopt` once, then `confirm=deploy` | See "First deploy" and the header of the file. |
| `server-logs.yml` | manual, read-only | Tails the newest Laravel log and the last output of each cron line. |

## Prerequisites checklist

Do these in order. The pipeline updates a live install; it does not create the host, the database or the `.env`.

### Hosting

- [ ] **DNS**: a record for `pulse.infodot.co.za` pointing at the cPanel host.
- [ ] **cPanel subdomain** `pulse.infodot.co.za` with its document root (`~/pulse.infodot.co.za` by default) and an issued TLS certificate (AutoSSL), so `https://pulse.infodot.co.za` answers.
- [ ] **Layout like the other Dot apps**: the Laravel app in a folder under the document root, with the web server serving its `public/` the way the other apps' folders do. The pipeline finds the app as the one `artisan` within two levels of `~/pulse.infodot.co.za` (set the secret `DEPLOY_PATH` to skip the search). If the document root has its own `build/` folder the pipeline mirrors the new assets into it; if it has none it links one to `<app>/public/build`.
- [ ] **PHP 8.3 or newer** for the subdomain (`composer.json` requires `^8.3`; CI runs 8.4) with at least `mbstring`, `intl`, `bcmath` and `curl` (the extensions CI installs) and `pdo_pgsql` (the production database). The `php` found by a non-interactive SSH session must be that version.
- [ ] On the host: `git`, `composer`, `rsync`, `tar`, `flock` and `crontab`. There is **no Node** on the host, by design: the frontend is built on the runner and synced.
- [ ] **A deploy SSH key pair**: the public half authorised for the cPanel account, the private half stored as `DEPLOY_SSH_KEY`. `DEPLOY_KNOWN_HOSTS` is the host's `ssh-keyscan` line; confirm its fingerprint with the host provider rather than trusting the scan.

### Database

- [ ] A **PostgreSQL** database and role created in cPanel. `.env.example` defaults to `DB_DATABASE=infodot` and `DB_USERNAME=postgres`: replace them with the real names. The rollback copy does **not** contain a Postgres database, so take your own `pg_dump` before the first migration.

### The host `.env`

The pipeline requires `<app>/.env` to exist and never overwrites it. Create it from `.env.example` and set at least the values below. On every run the pipeline forces `APP_DEBUG=false` and `APP_ENV=production`, and warns about an `http://` `APP_URL`.

| Variable | Value | Why |
|---|---|---|
| `APP_KEY` | `base64:` plus 32 random bytes, once (`php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'`) | Generate it **before** the first run, because no `vendor/` exists yet to run `key:generate`. Never change it afterwards: it signs everyone out and breaks anything encrypted. |
| `APP_URL` | `https://pulse.infodot.co.za` | `.env.example` carries `http://pulse.infodot.app`, a dead domain over plain http. |
| `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | the cPanel database | See above. |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `database` (the `.env.example` values) | Their tables come from the migrations. The deploy clears caches with targeted commands, never `optimize:clear`, because that would empty the database cache store. |
| `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | the SMTP account used by the other Dot apps | `.env.example` says `MAIL_MAILER=log`, which silently writes every email to the log file. |
| `LOG_LEVEL` | `warning` | `.env.example` says `debug`. |
| `BROADCAST_CONNECTION` | `log` | No Reverb server runs on this host (see "Known limits"). Pulse broadcasts every new post and comment as a queued job; with `reverb` each one would try to reach a server that is not there and land in `failed_jobs`. The deploy prints a warning while it is still `reverb`. |
| `INFODOT_REDEEM_URL` | `https://infodot.co.za/api/ecosystem/redeem` | Where this app redeems the one-time sign-in token InfoDot hands over. |
| `INFODOT_PLATFORM_KEY` | `pulse` | This app's key in InfoDot's registry. |
| `INFODOT_SERVICE_SECRET` | a long random value, the **same** as `ECOSYSTEM_SECRET_PULSE` in InfoDot's `.env` | Proves who is redeeming. Never commit it; never print it. Without it people cannot sign in through InfoDot. |
| `INFODOT_REDEEM_TIMEOUT`, `INFODOT_PROVISION_USERS` | `5`, `true` | `false` stops InfoDot creating accounts here; people who already have one still sign in. |
| `NOTIFY_*` | not needed | No `NOTIFY_*` variable is read by this app on `main` or on any open PR. |
| `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL`, `ANTHROPIC_TIMEOUT` | the key; `claude-sonnet-4-6`; `10` | AI moderation of every new post runs in a queued job. **Blank key: every new post waits for a human moderator** (never approved by default). |
| `ANALYTICS_INGEST_URL` | e.g. `https://analytics.infodot.co.za/api/ingest/snapshots` | Open PR #9 (publish daily aggregate metrics). Nothing is sent until all of the URL, secret and company uuid are set. |
| `ANALYTICS_INGEST_SECRET` | the same value as `ANALYTICS_INGEST_SECRET_PULSE` in Dot.Analytics' `.env` | Open PR #9. |
| `ANALYTICS_PLATFORM_KEY`, `ANALYTICS_COMPANY_UUID` | `pulse`, the operator's own InfoDot company uuid | Open PR #9: Pulse is one global network, so the numbers are filed under one company. |
| `PULSE_EDIT_WINDOW_MINUTES`, `PULSE_LIMIT_POST`, `PULSE_LIMIT_COMMENT`, `PULSE_LIMIT_REACT`, `PULSE_LIMIT_REPORT`, `PULSE_LIMIT_JOIN_REQUEST`, `PULSE_JOIN_REQUEST_COOLDOWN_DAYS` | optional | Defaults 30, 20, 30, 60, 10, 10, 7. |

### InfoDot (the sign-in hub)

- [ ] **`ECOSYSTEM_SECRET_PULSE`** in InfoDot's `.env`, equal to `INFODOT_SERVICE_SECRET` above.
- [ ] **`'pulse'` in the `service_secrets` list** of InfoDot's `config/ecosystem.php`. Today the list does not name it, so InfoDot refuses this app's redeem call. InfoDot PR #32 ("Redeem: allow service secrets for the 14 remaining platforms") was open when this was written; check that it has been merged and deployed.
- [ ] **Mark the platform live**: set `'active' => true` for `'pulse'` in InfoDot's `config/ecosystem.php` (it is `false` today), but only once the app really answers, so no one is sent to a dead address. InfoDot's `DOT_PULSE_URL` already defaults to `https://pulse.infodot.co.za`.
- [ ] `ECOSYSTEM_TRUSTED_DOMAINS` in InfoDot keeps its default `infodot.co.za`, which covers `pulse.infodot.co.za`.

### Repository secrets (Settings, Secrets and variables, Actions)

| Secret | Value |
|---|---|
| `DEPLOY_HOST` | `pulse.infodot.co.za` |
| `DEPLOY_USER` | the cPanel account user (the one the other Dot apps deploy as) |
| `DEPLOY_SSH_KEY` | the private deploy key |
| `DEPLOY_KNOWN_HOSTS` | the host's `known_hosts` line |
| `DEPLOY_PATH` | optional: the absolute app folder, to skip the search for `artisan` |

## First deploy

1. Finish the checklist. A brand-new, empty subdomain needs a first copy of the code and a `.env` before the pipeline can take over: `git clone https://github.com/sakhilebhayi/Dot.Pulse.git` into a folder under the document root (the repository is public), then create `.env` as above. (An empty folder fails the run before maintenance mode, because there is no `artisan` or `.env` to work with.)
2. Run **Drift Report** and read it. For a fresh clone of `main` it says the host already matches.
3. Run **Deploy to Production** with `confirm=adopt`. It writes a rollback copy, makes the folder a checkout of `main` without deleting anything untracked (`.env`, `storage/`), installs dependencies, ships the build, forces the production settings, **lists** the pending migrations without running them, rebuilds the caches, installs the cron lines, and checks the site. Add `migrate=migrate` only on a database you do not mind migrating at once (a brand-new empty one is fine).
4. Take a `pg_dump`, read the pending-migrations list in the log, then run it again with `confirm=deploy`, which migrates.
   For teams that already exist, link each to its InfoDot company so sign-in reuses it: `php artisan infodot:link-team {team} {uuid} --promote`.
5. Check the log (see "Reading the log"), open `https://pulse.infodot.co.za/login` and sign in through InfoDot.
6. Only then mark the platform live in InfoDot.

Every release after that: **Deploy to Production** with `confirm=deploy` (`gh workflow run deploy.yml -f confirm=deploy`). The CI gate runs first, then the frontend is built, then the host steps run.

A failure **before** maintenance mode (the gate, the build, ssh, the upload, the rollback copy) leaves the live site on the previous release. A failure **after** it starts leaves the site **down on purpose**, in maintenance mode, with the rollback copy's name in the log: read the log, fix the cause and run the same workflow again; it brings the site back up itself. It is never brought back automatically, because the Dot.Agents pipeline's automatic rollback once fired after failures that never touched the server.

## Cron and queue lines

The deploy installs **2 lines** in the account's single crontab, each ending in the marker `# dot-pulse`. The crontab is shared by every Dot app, so the step follows the rules learned when concurrent deploys once wiped it: it takes the shared lock (`~/.dot-crontab.lock`, `flock`), reads the crontab with a retry because `crontab -l` can transiently answer empty (it never writes after an empty read), replaces only the lines carrying its own marker, reads the crontab back, and restores the previous one if another app's line count changed or its own count is wrong.

```
* * * * * cd <app folder> && <php> artisan schedule:run > storage/logs/cron-scheduler.log 2>&1 # dot-pulse
* * * * * cd <app folder> && <php> artisan queue:work --stop-when-empty --tries=3 --max-time=50 > storage/logs/cron-queue.log 2>&1 # dot-pulse
```

`<php>` is the absolute path of `php` on the host (cron has a minimal `PATH`). Each line keeps its last output in `storage/logs/cron-scheduler.log` or `storage/logs/cron-queue.log`.

`schedule:run` runs `routes/console.php`: `pulse:sweep-pending` every five minutes (a dead worker must not hide new posts forever: anything stuck in "pending" is handed to the human moderation queue) and, with open PR #9, `pulse:publish-metrics` at 00:10 UTC.

**Queue worker, the cPanel way.** The account cannot run a daemon, so the worker is started by cron every minute, drains the queue and exits (`--stop-when-empty`, at most 50 seconds, then the next cron run starts a fresh one). That means a queued job normally runs within a minute, a job released for a retry waits for a later cron minute, new code is picked up on the next start without any `queue:restart`, and nothing is processed while the site is in maintenance mode. `QUEUE_CONNECTION=database`; the `jobs` and `failed_jobs` tables come from the migrations. The first worker run handles whatever was queued before it existed: check the size of the `jobs` table before the first `confirm=deploy`. Dot.Pulse queues `App\Jobs\ModeratePost` (AI moderation of every new post: three tries, 10 s and 30 s backoff, 20 s job timeout; with no key, or when the tries run out, the post is held for a human) and, with open PR #9, `App\Jobs\SendAnalyticsSnapshot`. Its broadcast events are queued too, which is why `BROADCAST_CONNECTION` should be `log` here.

First run: the account's line total grows by 2. Every deploy log ends this step with `== crontab: 2 line(s) for # dot-pulse installed, M other line(s) preserved`; **M must never shrink** between runs. Check by hand with `crontab -l | grep '# dot-pulse'`.

## Rollback

- Every run writes `~/dot-pulse-<mode>-<UTC time>.tgz` (the app folder without `vendor/`, `node_modules/`, logs and the build); the newest three are kept. To restore: stop writes (`php artisan down`), `tar xzf` it over the app folder, run `composer install --no-dev`, redeploy to rebuild the frontend, then `php artisan up`.
- **Code**: the pipeline always deploys `origin/main`. To go back, revert the change on `main` and run `confirm=deploy`. The log prints `== Current release: <sha>` before and `== Deployed: <sha>` after.
- **Database**: migrations are not rolled back, and the copy holds no Postgres data. Restore from your own `pg_dump`.
- On `adopt`, host-only commits survive on a local branch `host-before-adopt-<time>`, and host-only migration files are moved to `~/dot-pulse-quarantine-<time>`, never deleted.

## Reading the log

- The Actions run log shows the steps in order: the input and secret check, the CI gate, the build, "Locate the app", the staged upload, "Deploy the code", "Install the scheduler cron lines" and the smoke check. Lines starting `==` are the host script's progress: `== Rollback copy`, `== Current release`, `== Code`, `== Pending migrations`, `== Deployed`, `== crontab: 2 line(s) for # dot-pulse installed, M other line(s) preserved` (M must not shrink), `== Smoke check passed.` An `::error::` line names the cause and says whether the site is still up.
- **Server Logs** (`server-logs.yml`) prints the last 120 lines of the newest `storage/logs/laravel*.log` and the last 10 lines of each `storage/logs/cron-*.log`. It is a fixed command, never a shell.
- The smoke check fetches `/`, `/login`, `/up`, every built entry file from `/build/...`, and sends one harmless `POST /up` (a 405) to prove no debug page is served.

## Known limits

- **Never run `php artisan db:seed` in production**: `DatabaseSeeder` creates `test@example.com` with a known password. There is no reference data to seed.
- **Reverb cannot run under shared cPanel** (a long-running WebSocket process). The pipeline never starts, restarts or reloads it. Dot.Pulse uses Reverb for live feed and comment updates and for private channels. Without it posts, comments and reactions still save and show on the next page load, but nothing updates live. The build on the runner has no `.env`, so `VITE_REVERB_APP_KEY` is empty and the browser code switches Echo off with a console warning instead of retrying a dead socket. Real-time needs Reverb on another host (or a hosted Pusher-protocol service) plus a rebuild that bakes the `VITE_REVERB_*` values in, which this pipeline does not do.
- **The default branch of the repository is `feature/ecosystem-sso`, not `main`.** GitHub lists and dispatches a workflow only if the file exists on the default branch, and `main` and `feature/ecosystem-sso` have diverged (`main` is 36 commits ahead and 28 behind at the time of writing). Decide which branch is production: either make `main` the default branch, or put `.github/workflows/deploy.yml`, `drift.yml`, `server-logs.yml` and `ci.yml` on the default branch as well. The host always deploys `main`.
- The signed-in pages load Tailwind from a CDN at runtime. The smoke check only fetches the guest pages and the built assets, so a CDN problem or a signed-in-only error is not caught by it.
- The first deploy after a long gap migrates everything pending in one go; read the pending list from the `adopt` run first.
- The rollback copy includes `storage/app` (uploads and other files) and `.env`; check the account's disk quota, three copies are kept.
- No backups, no scheduler probe and no Dot.Brain guardian endpoint exist for this app yet.
- `INFODOT_*` redeem only works once InfoDot lists this platform (checklist above).
