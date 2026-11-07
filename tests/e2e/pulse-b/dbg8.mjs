import { launch, person, settle } from './lib.mjs';
import { readFileSync } from 'node:fs';
const seed = JSON.parse(readFileSync(new URL('./out/seed.json', import.meta.url), 'utf8'));
const b = await launch(); const p = await person(b, 'http://127.0.0.1:8791', { userId: seed.users.anele.id });
await p.page.goto('/b/moyo-plumbing/o/geyser-repair-and-replacement'); await settle(p.page);
await p.page.getByRole('button', { name: /^Ask for (a )?quotes?$/i }).first().click();
await p.page.waitForTimeout(1500);
console.log((await p.page.locator('main').innerText()).slice(0,900));
await b.close();
