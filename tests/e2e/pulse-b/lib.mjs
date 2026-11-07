// Shared helpers for acceptance suite B. Headless Chrome through playwright-core, axe-core, mail log reader.
import { chromium } from 'playwright-core';
import { readFileSync, existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';

const require = createRequire(import.meta.url);
const axeSource = readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');

const CHROME = process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

export async function launch() {
    return chromium.launch({ executablePath: CHROME, headless: true, args: ['--no-sandbox'] });
}

/** Collects check results for a spec. */
export class Spec {
    constructor(name, ctx) {
        this.name = name;
        this.ctx = ctx;
        this.checks = [];
        this.notes = [];
        this.axeFindings = [];
        this.consoleErrors = [];
    }

    check(name, ok, detail = '') {
        this.checks.push({ name, ok: !!ok, detail: ok ? '' : String(detail) });
        console.log(`  ${ok ? 'ok ' : 'XX '} ${name}${ok || !detail ? '' : ' :: ' + String(detail).slice(0, 240)}`);
        return !!ok;
    }

    note(text) { this.notes.push(text); }

    result() {
        return { checks: this.checks, notes: this.notes, axe: this.axeFindings, consoleErrors: this.consoleErrors };
    }
}

/**
 * A person at a browser: own context (own cookies), console and page error capture, optional login as a seeded user.
 * @param {object} o { userId, width, height, scheme: 'light'|'dark', reducedMotion, spec, ignoreConsole: RegExp[] }
 */
export async function person(browser, base, o = {}) {
    const context = await browser.newContext({
        viewport: { width: o.width ?? 1440, height: o.height ?? 900 },
        colorScheme: o.scheme ?? 'light',
        reducedMotion: o.reducedMotion ? 'reduce' : 'no-preference',
        baseURL: base,
        hasTouch: (o.width ?? 1440) < 500,
        isMobile: (o.width ?? 1440) < 500,
        acceptDownloads: true,
    });
    const page = await context.newPage();
    const p = { context, page, base, errors: [], failures: [], requests: [] };
    const ignore = o.ignoreConsole ?? [];
    page.on('console', (m) => {
        if (m.type() === 'error') {
            const text = m.text();
            if (!ignore.some((re) => re.test(text))) { p.errors.push(`${page.url()} :: ${text}`); o.spec?.consoleErrors.push(`${page.url()} :: ${text}`); }
        }
    });
    page.on('pageerror', (e) => { const t = `pageerror ${e.message}`; p.errors.push(`${page.url()} :: ${t}`); o.spec?.consoleErrors.push(`${page.url()} :: ${t}`); });
    page.on('response', (r) => { if (r.status() >= 500) p.failures.push(`${r.status()} ${r.url()}`); });
    if (o.userId) await login(p, o.userId);

    return p;
}

export async function login(p, userId, to = '/lines') {
    await p.page.goto(`/dev/login-b/${userId}?to=${encodeURIComponent(to)}`);
}

export async function settle(page, ms = 400) {
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(ms);
}

/** Run axe on the current page. Records serious/critical violations on the spec. Returns the list. */
export async function axe(page, spec, label, opts = {}) {
    await page.evaluate(axeSource);
    const res = await page.evaluate(async (o) => {
        // eslint-disable-next-line no-undef
        const r = await axe.run(document, { resultTypes: ['violations'], ...(o.rules ? { rules: o.rules } : {}) });
        return r.violations.map((v) => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.slice(0, 4).map((n) => ({ target: n.target.join(' '), html: n.html.slice(0, 160), summary: n.failureSummary?.slice(0, 200) })) }));
    }, opts);
    const bad = res.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    for (const v of bad) spec.axeFindings.push({ page: label, ...v });
    spec.check(`axe ${label}: zero serious/critical`, bad.length === 0, bad.map((v) => `${v.id} (${v.nodes[0]?.target})`).join('; '));

    return res;
}

export async function shot(page, spec, name, full = true) {
    const file = join(spec.ctx.out, 'shots', `${spec.name}-${name}.png`.replace(/[^a-zA-Z0-9._-]/g, '_'));
    await page.screenshot({ path: file, fullPage: full });

    return file;
}

/** Mail written by the log mailer (MAIL_MAILER=log): parsed from storage/logs/laravel.log. */
export function mailLog(root) {
    const f = join(root, 'storage/logs/laravel.log');
    if (!existsSync(f)) return [];
    const text = readFileSync(f, 'utf8');
    const parts = text.split(/(?=^\[\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\] )/m);
    const out = [];
    for (const part of parts) {
        if (!/^To: /m.test(part) || !/^Subject: /m.test(part)) continue;
        const get = (h) => (part.match(new RegExp(`^${h}: (.*)$`, 'm')) || [])[1] ?? '';
        // Split the MIME message into its parts and decode each by its own transfer encoding.
        const [head, ...rest] = part.split(/\r?\n\r?\n/);
        const whole = rest.join('\n\n');
        const chunks = whole.split(/^--[A-Za-z0-9_.=+-]+(?:--)?\s*$/m).filter((c) => c.trim());
        const decoded = (chunks.length ? chunks : [whole]).map((c) => {
            const [h, ...b] = c.replace(/^\s+/, '').split(/\r?\n\r?\n/);
            let t = b.join('\n\n');
            // Laravel's log mailer has already decoded quoted-printable.
            return { type: (h.match(/Content-Type: ([^;\s]+)/i) || [])[1] || 'text/plain', text: t };
        });
        const text = decoded.find((d) => d.type === 'text/plain')?.text ?? '';
        const html = decoded.find((d) => d.type === 'text/html')?.text ?? '';
        const body = decoded.map((d) => d.text).join('\n');
        out.push({ to: get('To'), subject: get('Subject'), headers: head, body, text, html, raw: part });
    }

    return out;
}

export const mailTo = (root, email) => mailLog(root).filter((m) => m.to.includes(email));

/** All visible, interactive things on a page: links, buttons, toggles, tabs, menu items. */
export async function interactives(page) {
    return page.evaluate(() => {
        const vis = (el) => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none'; };
        const sel = 'a, button, [role=button], [role=tab], [role=menuitem], [role=switch], input[type=checkbox], input[type=radio], summary, select, [wire\\:click], [x-on\\:click], [data-pk-dialog], [data-pk-toggle]';
        return [...document.querySelectorAll(sel)].filter(vis).map((el, i) => ({
            tag: el.tagName.toLowerCase(), text: (el.innerText || el.getAttribute('aria-label') || el.value || '').trim().replace(/\s+/g, ' ').slice(0, 80),
            href: el.getAttribute('href'), disabled: el.disabled || el.getAttribute('aria-disabled') === 'true',
            title: el.getAttribute('title'), role: el.getAttribute('role'), id: el.id, i,
            wire: el.getAttributeNames().filter((n) => n.startsWith('wire:')).join(' '),
        }));
    });
}

export function dirnameOf(url) { return dirname(url); }

/** Run an artisan command against the e2e database. Returns stdout. */
export function artisan(root, ...args) {
    const r = spawnSync('php', ['artisan', ...args], { cwd: root, env: process.env, encoding: 'utf8' });

    return (r.stdout || '') + (r.stderr || '');
}

/** One-off PHP against the booted app (read-only probes of what the browser cannot see). */
export function tinker(root, code) {
    const r = spawnSync('php', ['artisan', 'tinker', '--execute', code], { cwd: root, env: process.env, encoding: 'utf8' });

    return (r.stdout || '').trim();
}

export function sql(out, query) {
    const r = spawnSync('sqlite3', ['-cmd', '.timeout 5000', join(out, 'e2e.sqlite'), query], { encoding: 'utf8' });

    return (r.stdout || '').trim();
}
