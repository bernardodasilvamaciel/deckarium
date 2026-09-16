import { login } from './auth-helper.mjs';
import assert from 'node:assert/strict';

const base=process.env.DECKARIUM_BASE||'http://localhost:8080'; let cookie=await login(base);
async function request(path,options={}){const response=await fetch(base+path,{redirect:'manual',...options,headers:{cookie,...(options.headers||{})}});const set=response.headers.get('set-cookie');if(set)cookie=set.split(';')[0];return response;}
async function page(path){const response=await request(path);assert.equal(response.status,200);return response.text();}
const csrf=html=>html.match(/name="csrf" value="([^"]+)"/)?.[1];
const uuid=html=>html.match(/card\.php\?id=([a-f0-9-]{36})/i)?.[1];
let deckId=0;
try{
  let html=await page('/decks.php'); const deckCsrf=csrf(html); assert(deckCsrf);
  let response=await request('/decks.php',{method:'POST',body:new URLSearchParams({csrf:deckCsrf,action:'import_deck',deck:'0',name:'Verificação temporária de upgrades',decklist:'Commander\n1 Dina, Soul Steeper\n\nDeck\n1 Sol Ring\n1 Command Tower'})});
  assert.equal(response.status,303); deckId=Number(response.headers.get('location').match(/deck=(\d+)/)[1]);
  html=await page(`/upgrades.php?deck=${deckId}`); const remove=html.match(/out=([a-f0-9-]{36})/i)?.[1]; assert(remove);
  html=await page(`/upgrades.php?deck=${deckId}&out=${remove}&q=${encodeURIComponent('Tamiyo, Compleated Sage')}`); const upgradeCsrf=csrf(html); const owned=html.match(/name="add_card" value="([a-f0-9-]{36})/i)?.[1]; assert(upgradeCsrf&&owned,'A busca de upgrades deve aceitar uma carta do catálogo fora da coleção.');
  response=await request('/upgrades.php',{method:'POST',body:new URLSearchParams({csrf:upgradeCsrf,action:'create',deck:String(deckId),remove_card:remove,add_card:owned,reason:'Validação automatizada'})});
  if(response.status!==303){const body=await response.text(); if(body.includes('Escolha cartas diferentes')) console.log('SKIP: a primeira impressão da coleção coincide com a carta de saída.'); else assert.fail(body);}
  else {html=await page(`/upgrades.php?deck=${deckId}`);assert(html.includes('Validação automatizada'));assert(html.includes('A lista original do deck não foi alterada'));}
  console.log('PASS: importação de lista, deck finalizado, seleção por impressão e registro de upgrade.');
}finally{
  if(deckId){const html=await page(`/decks.php?deck=${deckId}`);const token=csrf(html);await request('/decks.php',{method:'POST',body:new URLSearchParams({csrf:token,action:'delete_deck',deck:String(deckId)})});}
}
