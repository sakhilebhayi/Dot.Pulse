// Shared harness for the Dot.Pulse browser journeys: server lifecycle, people, console/network watching, axe,
// screenshots at 1440 and 390, and direct read access to the SQLite database for asserting real outcomes.
import { chromium } from 'playwright-core';
import { spawn, spawnSync } from 'node:child_process';
import { copyFileSync, existsSync, mkdirSync, readFileSync, rmSync, writeFileSync, createWriteStream } from 'node:fs';
import { createServer } from 'node:net';
import { DatabaseSync } from 'node:sqlite';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const here = path.dirname(fileURLToPath(import.meta.url));
export const E2E_DIR = path.resolve(here, '..');
export const APP_DIR = path.resolve(E2E_DIR, '..', '..', '..');
export const OUT_DIR = path.join(E2E_DIR, 'out');
const require = createRequire(import.meta.url);
const AXE_SOURCE = readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

export function findChrome() {
  const candidates = [
    process.env.PULSE_E2E_CHROME,
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/Applications/Chromium.app/Contents/MacOS/Chromium',
    '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable', '/usr/bin/chromium', '/usr/bin/chromium-browser',
  ].filter(Boolean);
  const found = candidates.find((c) => existsSync(c));
  if (!found) throw new Error('No Chrome or Chromium found. Set PULSE_E2E_CHROME to its executable.');
  return found;
}

function freePort(preferred) {
  return new Promise((resolve) => {
    const tryPort = (port) => {
      const s = createServer();
      s.once('error', () => tryPort(port + 1));
      s.listen(port, '127.0.0.1', () => s.close(() => resolve(port)));
    };
    tryPort(preferred);
  });
}

export function artisan(args, env = {}) {
  const r = spawnSync('php', ['artisan', ...args], { cwd: APP_DIR, env: { ...process.env, ...env }, encoding: 'utf8' });
  if (r.status !== 0) throw new Error(`php artisan ${args.join(' ')} failed:\n${r.stdout}\n${r.stderr}`);
  return r.stdout;
}

export function appEnv(dbFile, port, extra = {}) {
  return {
    APP_ENV: 'local', APP_DEBUG: 'false', APP_URL: `http://127.0.0.1:${port}`,
    DB_CONNECTION: 'sqlite', DB_DATABASE: dbFile, DB_URL: '',
    SESSION_DRIVER: 'database', CACHE_STORE: 'database', QUEUE_CONNECTION: 'sync', BROADCAST_CONNECTION: 'null',
    MAIL_MAILER: 'log', LOG_CHANNEL: 'stderr', LOG_LEVEL: 'warning', BCRYPT_ROUNDS: '4',
    PULSE_ENABLED: 'false', TELESCOPE_ENABLED: 'false', NIGHTWATCH_ENABLED: 'false',
    ANTHROPIC_API_KEY: '', PULSE_E2E_FAKE_INFODOT: '1',
    ...extra,
  };
}

/** Builds the seeded base database once. */
export function buildBaseDb() {
  mkdirSync(OUT_DIR, { recursive: true });
  const dbFile = path.join(OUT_DIR, 'base.sqlite');
  rmSync(dbFile, { force: true });
  writeFileSync(dbFile, '');
  const env = appEnv(dbFile, 0);
  artisan(['migrate', '--force'], env);
  artisan(['pulse:demo-seed'], env);
  return dbFile;
}

export async function startServer(dbFile, logFile, preferredPort = Number(process.env.PULSE_E2E_PORT || 8781)) {
  const port = await freePort(preferredPort);
  const log = createWriteStream(logFile);
  const child = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`, '--no-reload'], {
    cwd: APP_DIR, env: { ...process.env, ...appEnv(dbFile, port) }, stdio: ['ignore', 'pipe', 'pipe'],
  });
  child.stdout.pipe(log, { end: false });
  child.stderr.pipe(log, { end: false });
  const base = `http://127.0.0.1:${port}`;
  for (let i = 0; i < 100; i++) {
    try { const r = await fetch(base + '/up'); if (r.status < 500) break; } catch {}
    await sleep(150);
  }
  return {
    base, port,
    async stop() { child.kill('SIGTERM'); await sleep(200); log.end(); },
  };
}

export class Journey {
  constructor({ id, title, base, dbFile, browser, outDir }) {
    Object.assign(this, { id, title, base, dbFile, browser, outDir });
    this.checks = [];
    this.shots = [];
    this.axeFindings = [];
    this.consoleErrors = [];
    this.notes = [];
    this.contexts = [];
    mkdirSync(outDir, { recursive: true });
  }

  // ---- database (read only, fresh connection per call) -------------------------------------------------
  db(sql, ...params) {
    for (let attempt = 0; ; attempt++) {
      const d = new DatabaseSync(this.dbFile, { readOnly: true });
      try {
        d.exec('PRAGMA busy_timeout = 4000');
        return d.prepare(sql).all(...params);
      } catch (e) {
        if (attempt < 5 && /locked|busy/i.test(e.message)) { Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 300); continue; }
        throw e;
      } finally { d.close(); }
    }
  }
  one(sql, ...params) { return this.db(sql, ...params)[0]; }
  value(sql, ...params) { const r = this.one(sql, ...params); return r ? Object.values(r)[0] : undefined; }
  userId(key) { return this.value('select id from users where email = ?', `${key}@demo.pulse.test`); }
  entityId(name) { return this.value('select id from pulse_entities where name = ?', name); }

  /** Runs a PHP snippet against the journey's database (for set-up the UI has no screen for, e.g. extra people). */
  php(code) {
    const r = spawnSync('php', ['artisan', 'tinker', '--execute', code], { cwd: APP_DIR, env: { ...process.env, ...appEnv(this.dbFile, 0) }, encoding: 'utf8' });
    if (r.status !== 0) throw new Error('tinker failed: ' + r.stdout + r.stderr);
    return r.stdout;
  }

  /** Adds n finished professionals (`<prefix>1`, `<prefix>2`...) through the same service the product uses. */
  addPeople(prefix, n, { skills, place = 'johannesburg-za', disc = 'anonymous', years = 5, headline }) {
    const spec = JSON.stringify({ skills, place, disc, years, headline: headline ?? `${skills[0]} specialist` });
    this.php(`$s = new Database\\Seeders\\PulseDemoSeeder; $spec = json_decode('${spec}', true); for ($i = 1; $i <= ${n}; $i++) { $s->makePerson('${prefix}'.$i, $spec + ['name' => 'Extra ${prefix} '.$i, 'kind' => 'professional', 'policy' => 'context', 'open_to' => ['work']]); }`);
    this.tick();
  }

  /** One tick of the scheduler's matching job (`pulse:match` runs every ten minutes in production). */
  tick() {
    return artisan(['pulse:match'], appEnv(this.dbFile, 0));
  }

  /** HTTP status of a path as this person (their cookies), without a navigation, so an expected 404 is not a console error. */
  async status(page, urlPath) {
    const r = await page.context().request.get(this.base + urlPath, { maxRedirects: 0 });
    return r.status();
  }

  // ---- assertions -------------------------------------------------------------------------------------
  check(name, ok, detail = '') {
    this.checks.push({ name, ok: !!ok, detail: String(detail ?? '') });
    console.log(`    ${ok ? 'ok  ' : 'FAIL'} ${name}${ok || !detail ? '' : '  -> ' + detail}`);
    return !!ok;
  }
  note(text) { this.notes.push(text); }

  async until(fn, name, timeout = 8000, step = 150) {
    const end = Date.now() + timeout;
    let last;
    while (Date.now() < end) {
      try { last = await fn(); if (last) return last; } catch (e) { last = e.message; }
      await sleep(step);
    }
    this.check(name, false, `timed out; last=${typeof last === 'string' ? last.slice(0, 160) : last}`);
    return null;
  }

  // ---- people -----------------------------------------------------------------------------------------
  /** A fresh browser context (own cookies) signed in as a demo person through the local-only dev route. */
  async as(key, { width = 1440, height = 900, to } = {}) {
    const ctx = await this.newContext({ width, height });
    const page = await ctx.newPage();
    this.watch(page, key);
    await page.goto(`${this.base}/dev/login/${encodeURIComponent(key.includes('@') ? key : key + '@demo.pulse.test')}${to ? '?to=' + encodeURIComponent(to) : ''}`, { waitUntil: 'load' });
    page.who = key;
    return page;
  }

  async newContext({ width = 1440, height = 900 } = {}) {
    const ctx = await this.browser.newContext({ viewport: { width, height }, reducedMotion: 'reduce', locale: 'en-ZA', timezoneId: 'Africa/Johannesburg' });
    ctx.setDefaultTimeout(8000);
    this.contexts.push(ctx);
    return ctx;
  }

  watch(page, who = 'anon') {
    page.on('console', (m) => {
      if (m.type() === 'error') this.consoleErrors.push({ who, url: page.url(), text: m.text().slice(0, 300) });
    });
    page.on('pageerror', (e) => this.consoleErrors.push({ who, url: page.url(), text: 'pageerror: ' + e.message.slice(0, 300) }));
    page.on('response', (r) => {
      if (r.status() >= 500) this.consoleErrors.push({ who, url: page.url(), text: `HTTP ${r.status()} ${r.request().method()} ${r.url()}` });
    });
  }

  async go(page, urlPath, opts = {}) {
    const r = await page.goto(this.base + urlPath, { waitUntil: 'load', ...opts });
    await this.settle(page);
    return r;
  }

  /** Lets Livewire finish: no request in flight and the DOM quiet for a moment. */
  async settle(page, ms = 250) {
    try { await page.waitForLoadState('networkidle', { timeout: 5000 }); } catch {}
    await sleep(ms);
  }

  async text(page) { return (await page.locator('body').innerText()).replace(/[ \t]+/g, ' '); }

  // ---- screenshots and axe -----------------------------------------------------------------------------
  /** Screenshot at 1440 and 390 plus an axe-core pass (wcag2a and wcag2aa) of what is on screen. */
  async snap(page, label, { axe = true, full = true } = {}) {
    const slug = `${this.id}-${String(this.shots.length + 1).padStart(2, '0')}-${label.replace(/[^a-z0-9]+/gi, '-').toLowerCase()}`;
    const vp = page.viewportSize();
    const files = [];
    for (const [w, h] of [[1440, 900], [390, 844]]) {
      await page.setViewportSize({ width: w, height: h });
      await sleep(150);
      const file = path.join(this.outDir, `${slug}-${w}.png`);
      await page.screenshot({ path: file, fullPage: full });
      files.push(path.basename(file));
      if (w === 390) {
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        if (overflow > 2) this.check(`${label}: no sideways scroll at 390`, false, `overflow ${overflow}px`);
      }
    }
    await page.setViewportSize(vp ?? { width: 1440, height: 900 });
    await sleep(100);
    this.shots.push({ label, url: page.url(), files });
    if (axe) await this.axe(page, label);
    return files;
  }

  async axe(page, label) {
    try {
      await page.evaluate(AXE_SOURCE);
      const res = await page.evaluate(async () => {
        const r = await window.axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] } });
        return r.violations.map((v) => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.slice(0, 3).map((n) => n.target.join(' ') + ' :: ' + (n.failureSummary || '').split('\n').slice(1, 2).join(' ')) }));
      });
      const bad = res.filter((v) => ['serious', 'critical'].includes(v.impact));
      for (const v of bad) this.axeFindings.push({ label, url: page.url(), ...v });
      this.check(`axe: ${label} has no serious or critical violations`, bad.length === 0, bad.map((v) => `${v.id}[${v.nodes[0]}]`).join('; '));
    } catch (e) {
      this.check(`axe: ${label} ran`, false, e.message);
    }
  }

  finish() {
    this.check('no console errors, page errors or 5xx responses', this.consoleErrors.length === 0, this.consoleErrors.slice(0, 3).map((e) => `${e.who}@${e.url}: ${e.text}`).join(' | '));
    return {
      id: this.id, title: this.title, passed: this.checks.every((c) => c.ok), checks: this.checks, shots: this.shots,
      axe: this.axeFindings, consoleErrors: this.consoleErrors, notes: this.notes,
    };
  }

  async close() { for (const c of this.contexts) { try { await c.close(); } catch {} } }
}

export async function launchBrowser() {
  return chromium.launch({ executablePath: findChrome(), headless: true });
}
