import { launch, person, settle } from './lib.mjs';
import { readFileSync } from 'node:fs';
const seed = JSON.parse(readFileSync(new URL('./out/seed.json', import.meta.url), 'utf8'));
const b = await launch(); const p = await person(b, 'http://127.0.0.1:8791', { userId: seed.users[process.argv[2]].id });
await p.page.goto(process.argv[3]); await settle(p.page);
await p.page.getByRole('button', { name: /^(Knock|Ask about this)/i }).first().click();
await p.page.waitForTimeout(1000);
console.log(await p.page.locator('dialog[open]').innerText());
await b.close();
