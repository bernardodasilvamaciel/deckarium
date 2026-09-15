import assert from 'node:assert/strict';
const base='http://localhost:8080'; let cookie='',csrf='',deck=0;
async function get(path){const r=await fetch(base+path,{headers:{cookie}});cookie=r.headers.get('set-cookie')?.split(';')[0]||cookie;const h=await r.text();assert.equal(r.status,200);assert(!/Fatal error|Parse error|Warning:/.test(h));csrf=h.match(/name="csrf" value="([^"]+)"/)?.[1]||csrf;return h;}
async function post(fields){const r=await fetch(base+'/decks.php',{method:'POST',headers:{cookie},body:new URLSearchParams({csrf,deck:String(deck),...fields}),redirect:'manual'});assert.equal(r.status,303,await r.text());return r.headers.get('location');}
const count=h=>Number(h.match(/(\d+) cartas · maior sinergia/)[1]);
const cards=h=>[...h.matchAll(/<article class="synergy-card">([\s\S]*?)<\/article>/g)].map(m=>m[1]);
await get('/decks.php');
try {
 deck=Number((await post({action:'create',name:'Teste temporário de sinergia'})).match(/deck=(\d+)/)[1]);
 let h=await get(`/decks.php?deck=${deck}&choose=1&q=Edward%20Kenway&oracle=`);
 const commander=h.match(/name="card" value="([^"]+)"/)[1];await post({action:'commander',card:commander});
 h=await get(`/decks.php?deck=${deck}`);
 assert(count(h)>18,'Este teste requer recomendações de Edward Kenway já em cache.');
 const all=count(h),first=cards(h);assert.equal(first.length,18);
 const owned=await get(`/decks.php?deck=${deck}&outside=0`);assert(count(owned)<all);assert(cards(owned).every(c=>!c.includes('Fora da coleção')));
 const second=cards(await get(`/decks.php?deck=${deck}&synergy_page=2`));
 const names=cs=>cs.map(c=>c.match(/<h3>(.*?)<\/h3>/)[1]);assert(!names(second).some(n=>names(first).includes(n)));
 const external=first.find(c=>c.includes('Fora da coleção'));assert(external);
 const card=external.match(/name="card" value="([^"]+)"/)[1];
 const redirect=await post({action:'add',card,origin:'synergy',outside:'1',synergy_page:'1'});assert(redirect.endsWith('#synergy'));
 h=await get(redirect);assert(cards(h).find(c=>c.includes(card)).includes('Já em candidatas'));
 h=await get(`/decks.php?deck=${deck}&view=selection`);assert(h.includes('data-card-preview'));assert(h.includes(card));
 console.log('PASS: sinergias externas/coleção, paginação sem duplicatas, inclusão nas candidatas e bloqueio de repetição.');
} finally {if(deck)await post({action:'delete_deck'});}
