// Acceptance suite B runner: fresh SQLite DB -> migrate -> places -> seed -> artisan serve :8791 -> specs.
//   node run.mjs                run every spec
//   node run.mjs s09 s14        run specs whose file name starts with these prefixes
//   REUSE=1 node run.mjs ...    keep the database and the seed from the last run (faster iteration)
import { spawn, spawnSync } from 'node:child_process';
import { openSync, existsSync, mkdirSync, readdirSync, rmSync, writeFileSync, readFileSync, truncateSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../../..');
const out = join(here, 'out');
const PORT = 8791;
const BASE = `http://127.0.0.1:${PORT}`;
const db = join(out, 'e2e.sqlite');
const reuse = process.env.REUSE === '1' && existsSync(db) && existsSync(join(out, 'seed.json'));

mkdirSync(join(out, 'shots'), { recursive: true });

const env = {
    ...process.env,
    APP_ENV: 'local', APP_DEBUG: 'false', APP_URL: BASE,
    DB_CONNECTION: 'sqlite', DB_DATABASE: db,
    SESSION_DRIVER: 'file', CACHE_STORE: 'file', QUEUE_CONNECTION: 'sync',
    BROADCAST_CONNECTION: 'null', MAIL_MAILER: 'log', LOG_CHANNEL: 'single', LOG_LEVEL: 'debug',
    ANALYTICS_INGEST_URL: '', ANALYTICS_INGEST_SECRET: '', ANALYTICS_COMPANY_UUID: '',
    ANTHROPIC_API_KEY: '', PHP_CLI_SERVER_WORKERS: '6',
    PULSE_E2E_BASE: BASE, PULSE_E2E_OUT: out,
};

Object.assign(process.env, env); // specs shell out to artisan against the same database

const sh = (cmd, args) => {
    const r = spawnSync(cmd, args, { cwd: root, env, encoding: 'utf8' });
    if (r.status !== 0) {
        console.error(r.stdout, r.stderr);
        throw new Error(`${cmd} ${args.join(' ')} failed`);
    }
    return r.stdout;
};

const logFd = openSync(join(out, 'server.log'), 'w');
let server = null;
// artisan serve forks the real server: kill the whole process group, then whatever still listens on our port.
const stop = () => {
    try { if (server) process.kill(-server.pid, 'SIGTERM'); } catch { /* gone */ }
    const r = spawnSync('lsof', ['-ti', `tcp:${PORT}`, '-sTCP:LISTEN'], { encoding: 'utf8' });
    for (const pid of r.stdout.split('\n').filter(Boolean)) { try { process.kill(Number(pid), 'SIGTERM'); } catch { /* gone */ } }
};
process.on('exit', stop);
process.on('SIGINT', () => { stop(); process.exit(130); });

// Every spec gets a fresh database and a fresh server, so no spec depends on what another did.
async function boot(first) {
    stop();
    if (!reuse || !first) {
        rmSync(db, { force: true });
        rmSync(join(out, 'seed.json'), { force: true });
        writeFileSync(db, '');
        sh('php', ['artisan', 'migrate', '--force']);
        sh('php', ['artisan', 'pulse:places:seed']);
        sh('php', ['artisan', 'cache:clear']); // the file cache outlives the database: stale per-user counters would leak between runs
        sh('php', [join(here, 'seed.php')]);
        if (!existsSync(join(out, 'seed.json'))) throw new Error('seed did not finish');
    }
    try { truncateSync(join(root, 'storage/logs/laravel.log'), 0); } catch { /* none yet */ }
    // stdout/stderr go to a file, not a pipe: a closed pipe makes the dev server's request-line writes fail with a
    // notice that ends up inside JSON responses.
    server = spawn('php', ['artisan', 'serve', `--port=${PORT}`, '--no-reload'], { cwd: root, env, stdio: ['ignore', logFd, logFd], detached: true });
    for (let i = 0; i < 60; i++) {
        try { const r = await fetch(`${BASE}/up`); if (r.ok) return; } catch { /* not yet */ }
        await new Promise((r) => setTimeout(r, 300));
    }
    throw new Error('server did not start');
}

const wanted = process.argv.slice(2);
const specs = readdirSync(join(here, 'specs')).filter((f) => f.endsWith('.mjs')).sort()
    .filter((f) => wanted.length === 0 || wanted.some((w) => f.startsWith(w)));

if (process.env.SERVE === '1') {
    await boot(true);
    console.log(`serving ${BASE} (fresh seeded database). Ctrl-C to stop.`);
    await new Promise(() => {});
}

const report = [];
let firstSpec = true;
for (const f of specs) {
    const t0 = Date.now();
    await boot(firstSpec);
    firstSpec = false;
    console.log(`\n=== ${f}`);
    try {
        const mod = await import(pathToFileURL(join(here, 'specs', f)).href);
        const res = await mod.default({ base: BASE, out, root, seed: JSON.parse(readFileSync(join(out, 'seed.json'), 'utf8')) });
        report.push({ spec: f, ms: Date.now() - t0, ...res });
    } catch (e) {
        console.error(e);
        report.push({ spec: f, ms: Date.now() - t0, crashed: String(e.stack || e) });
    }
}

writeFileSync(join(out, 'report.json'), JSON.stringify(report, null, 2));
stop();

let failed = 0;
for (const r of report) {
    const fails = (r.checks || []).filter((c) => !c.ok);
    failed += fails.length + (r.crashed ? 1 : 0);
    console.log(`${r.crashed ? 'CRASH' : fails.length ? 'FAIL ' : 'ok   '} ${r.spec}  ${(r.checks || []).length - fails.length}/${(r.checks || []).length} checks`);
    for (const c of fails) console.log(`       x ${c.name}${c.detail ? ' :: ' + String(c.detail).slice(0, 300) : ''}`);
}
process.exit(failed ? 1 : 0);
