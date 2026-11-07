import { launch, person } from './lib.mjs';
const base='http://127.0.0.1:8791';
const b=await launch(); const p=await person(b,base,{userId:1});
await p.page.goto('/floor');
console.log(await p.page.evaluate(async()=>{const r=await fetch('/api/pulse/pulse-check',{headers:{Accept:'application/json'}});return [r.status,r.url,(await r.text()).slice(0,300)]}));
console.log(await p.page.evaluate(async()=>{const r=await fetch('/api/pulse/pulse-check');return [r.status,r.url,r.headers.get('content-type'),(await r.text()).slice(0,200)]}));
await b.close();
