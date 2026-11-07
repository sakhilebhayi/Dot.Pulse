import { launch, person, settle } from './lib.mjs';
import { readFileSync } from 'node:fs';
const seed = JSON.parse(readFileSync(new URL('./out/seed.json', import.meta.url), 'utf8'));
const b = await launch(); const p = await person(b, 'http://127.0.0.1:8791', { userId: seed.users[process.argv[2]].id });
await p.page.goto(process.argv[3]); await settle(p.page);
console.log(await p.page.evaluate((sel)=>[...document.querySelectorAll(sel)].map(e=>e.tagName+'.'+e.className+' :: '+e.innerText.slice(0,40).replace(/\n/g,' / ')), process.argv[4]));
await b.close();
