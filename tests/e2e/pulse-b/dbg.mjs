// usage: node dbg.mjs <userKey> <path>   (server must be running: REUSE=1 node run.mjs s99-hold)
import { launch, person, settle } from './lib.mjs';
import { readFileSync } from 'node:fs';
const seed = JSON.parse(readFileSync(new URL('./out/seed.json', import.meta.url), 'utf8'));
const b = await launch(); const p = await person(b, 'http://127.0.0.1:8791', { userId: seed.users[process.argv[2]].id });
await p.page.goto(process.argv[3]); await settle(p.page);
console.log((await p.page.locator('main').innerText()).slice(0, 1500)); console.log(p.errors);
await b.close();
