import { launch, person, settle } from './lib.mjs';
import { readFileSync } from 'node:fs';
const seed = JSON.parse(readFileSync('out/seed.json','utf8'));
const b=await launch(); const p=await person(b,'http://127.0.0.1:8791',{userId:seed.users.anele.id});
await p.page.goto(process.argv[2]); await settle(p.page);
console.log(await p.page.evaluate(()=>[...document.querySelectorAll('button,a')].filter(e=>e.offsetParent).map(e=>e.tagName+':'+e.innerText.trim().slice(0,30)).join(' | ')));
await p.page.screenshot({path:'out/probe.png',fullPage:true});
console.log(p.errors); await b.close();
