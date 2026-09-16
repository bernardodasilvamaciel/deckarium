import { login } from './auth-helper.mjs';
import assert from 'node:assert/strict';
const base=process.env.DECKARIUM_BASE||'http://localhost:8080'; let cookie=await login(base),csrf='',deck=0;
async function get(path){const r=await fetch(base+path,{headers:{cookie}});cookie=r.headers.get('set-cookie')?.split(';')[0]||cookie;const h=await r.text();assert.equal(r.status,200);assert(!/Fatal error|Parse error|Warning:/.test(h));csrf=h.match(/name="csrf" value="([^"]+)"/)?.[1]||csrf;return h;}
async function post(fields){const r=await fetch(base+'/decks.php',{method:'POST',headers:{cookie},body:new URLSearchParams({csrf,deck:String(deck),...fields}),redirect:'manual'});assert.equal(r.status,303,await r.text());return r.headers.get('location');}
const cards=h=>[...h.matchAll(/<article class="builder-result"[^>]*>([\s\S]*?)<\/article>/g)].map(m=>m[1]);
await get('/decks.php');
try {
  deck=Number((await post({action:'create',name:'Teste temporário de sinergia'})).match(/deck=(\d+)/)[1]);
  let h=await get(`/decks.php?deck=${deck}`);assert(h.includes('Escolha sua comandante'));assert(!h.includes('Explorar possibilidades'));assert(!h.includes('Leitura do deck'));assert.equal(cards(h).length,24);assert(/Página 1 de \d+/.test(h));
  h=await get(`/decks.php?deck=${deck}&choose=1&availability=all&q=Edward%20Kenway&oracle=&commander_colors%5B%5D=B&commander_colors%5B%5D=R`);assert(cards(h).length>0,'O filtro deve exigir todas as cores marcadas sem excluir comandantes multicoloridos.');
  const commander=h.match(/name="card" value="([^"]+)"/)[1];await post({action:'commander',card:commander});
  h=await get(`/decks.php?deck=${deck}`);
  assert(h.includes('Explorar possibilidades'));assert(h.includes('Leitura do deck'));assert(h.includes('<option value="synergy" selected>Maior sinergia</option>'));assert(/Página 1 de \d+/.test(h));
  const first=cards(h);assert.equal(first.length,24);assert(first.some(c=>c.includes('Sinergia EDHREC')));
  const owned=cards(await get(`/decks.php?deck=${deck}&availability=owned`));assert(owned.every(c=>c.includes('Você possui')));
  const missing=cards(await get(`/decks.php?deck=${deck}&availability=missing`));assert(missing.every(c=>c.includes('Fora da coleção')));
  const second=cards(await get(`/decks.php?deck=${deck}&sort=synergy&page=2`));
  const names=cs=>cs.map(c=>c.match(/<h3>(.*?)<\/h3>/)[1]);assert(!names(second).some(n=>names(first).includes(n)));
  const external=first.find(c=>c.includes('Fora da coleção')&&!c.includes('disabled'));assert(external);
  const card=external.match(/name="card" value="([^"]+)"/)[1];
  const redirect=await post({action:'add',card,sort:'synergy',availability:'all'});assert(redirect.includes('sort=synergy'));assert(redirect.includes('#result-'));
  h=await get(redirect);assert(/Já (?:está em|adicionada ·) candidatas/.test(cards(h).find(c=>c.includes(card))));
  h=await get(`/decks.php?deck=${deck}&view=selection`);assert(h.includes('data-selection-open'));assert(h.includes(card));
  console.log('PASS: escolha imediata de comandante, filtros unificados, sinergia como ordenação, paginação e candidatas.');
} finally {if(deck)await post({action:'delete_deck'});}
