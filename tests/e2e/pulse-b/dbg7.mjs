import { launch, person, settle } from './lib.mjs';
import { readFileSync } from 'node:fs';
const seed = JSON.parse(readFileSync(new URL('./out/seed.json', import.meta.url), 'utf8'));
const b = await launch(); const p = await person(b, 'http://127.0.0.1:8791', { userId: seed.users.sipho.id, width: +process.argv[2]||1440, height: +process.argv[3]||900 });
await p.page.goto('/lines/' + seed.rich.line); await settle(p.page);
const info = await p.page.evaluate(()=>{ const b=[...document.querySelectorAll('button')].find(x=>x.innerText.trim()==='Send'); const r=b.getBoundingClientRect(); const top=document.elementFromPoint(r.x+r.width/2,r.y+r.height/2); return {rect:[r.x,r.y,r.width,r.height], top: top?.outerHTML.slice(0,120), inView: r.y < innerHeight}; });
console.log(info);
await p.page.screenshot({path:'out/dbg7.png'});
await b.close();
