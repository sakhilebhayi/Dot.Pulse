// node run.mjs [spec ids...]   e.g. `node run.mjs 1 5 12`. Builds a seeded SQLite database, then for each spec copies
// it, boots `php artisan serve` against the copy, runs the journey, and writes out/results.json and screenshots.
import { readdirSync, copyFileSync, writeFileSync, mkdirSync, rmSync } from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { Journey, OUT_DIR, buildBaseDb, launchBrowser, startServer } from './lib/harness.mjs';

const only = process.argv.slice(2).filter((a) => /^\d+$/.test(a)).map(Number);
const specDir = path.join(path.dirname(new URL(import.meta.url).pathname), 'specs');
const files = readdirSync(specDir).filter((f) => f.endsWith('.mjs')).sort().filter((f) => only.length === 0 || only.includes(Number(f.split('-')[0])));

mkdirSync(OUT_DIR, { recursive: true });
console.log('Building the seeded database ...');
const baseDb = buildBaseDb();
const browser = await launchBrowser();
const results = [];

for (const f of files) {
  const spec = (await import(pathToFileURL(path.join(specDir, f)).href)).default;
  const outDir = path.join(OUT_DIR, spec.id);
  rmSync(outDir, { recursive: true, force: true });
  mkdirSync(outDir, { recursive: true });
  const dbFile = path.join(outDir, 'journey.sqlite');
  copyFileSync(baseDb, dbFile);
  const server = await startServer(dbFile, path.join(outDir, 'server.log'));
  console.log(`\n== ${spec.id}: ${spec.title}  (${server.base})`);
  const j = new Journey({ id: spec.id, title: spec.title, base: server.base, dbFile, browser, outDir });
  try {
    await spec.run(j);
  } catch (e) {
    j.check('journey ran to the end without an exception', false, (e.stack || e.message).split('\n').slice(0, 4).join(' / '));
    try { const p = j.contexts[0]?.pages()[0]; if (p) await p.screenshot({ path: path.join(outDir, 'failure.png'), fullPage: true }); } catch {}
  }
  const r = j.finish();
  writeFileSync(path.join(outDir, 'result.json'), JSON.stringify(r, null, 2));
  results.push(r);
  await j.close();
  await server.stop();
  console.log(`   => ${r.passed ? 'PASS' : 'FAIL'} (${r.checks.filter((c) => c.ok).length}/${r.checks.length} checks)`);
}

await browser.close();
writeFileSync(path.join(OUT_DIR, 'results.json'), JSON.stringify(results, null, 2));
console.log('\nSummary');
for (const r of results) console.log(`  ${r.passed ? 'PASS' : 'FAIL'}  ${r.id}  ${r.title}`);
process.exit(results.every((r) => r.passed) ? 0 : 1);
