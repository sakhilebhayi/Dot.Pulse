import { launch, person, settle } from './lib.mjs';
import { readFileSync } from 'node:fs';
const seed = JSON.parse(readFileSync(new URL('./out/seed.json', import.meta.url), 'utf8'));
const b = await launch(); const p = await person(b, 'http://127.0.0.1:8791', { userId: seed.users.lerato.id });
await p.page.goto('/me/privacy'); await settle(p.page);
console.log(await p.page.locator('section:has(#pv-ledger)').count());
console.log((await p.page.locator('section:has(#pv-ledger)').first().innerText()).slice(0,200));
console.log(await p.page.getByRole('button', {name:/Stop employers/}).count());
await b.close();
