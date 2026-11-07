import { launch, person, settle } from './lib.mjs';
import { readFileSync } from 'node:fs';
const seed = JSON.parse(readFileSync(new URL('./out/seed.json', import.meta.url), 'utf8'));
const b = await launch(); const p = await person(b, 'http://127.0.0.1:8791', { userId: seed.users.sipho.id });
await p.page.goto('/lines/' + seed.rich.line); await settle(p.page);
try { await p.page.getByRole('button', { name: 'Send', exact: true }).first().click({ timeout: 3000 }); console.log('clicked ok'); } catch (e) { console.log(String(e).slice(0, 700)); }
await p.page.goto('/b/moyo-books/manage/verify'); await settle(p.page);
try { await p.page.getByRole('button', { name: 'Save as a claim', exact: true }).first().click({ timeout: 3000 }); console.log('claim ok'); } catch (e) { console.log(String(e).slice(0, 700)); }
await b.close();
