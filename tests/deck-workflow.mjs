import { login } from './auth-helper.mjs';
import {readFileSync} from 'node:fs';
import assert from 'node:assert/strict';
const base=process.env.DECKARIUM_BASE||'http://localhost:8080'; let cookie=await login(base),csrf='',deck=0;
async function get(path){const r=await fetch(base+path,{headers:{cookie}});cookie=r.headers.get('set-cookie')?.split(';')[0]||cookie;const html=await r.text();assert.equal(r.status,200);assert(!/Fatal error|Parse error|Warning:/.test(html),html.slice(-2000));csrf=html.match(/name="csrf" value="([^"]+)"/)?.[1]||csrf;return html;}
async function post(fields,form){const body=form||new URLSearchParams({...fields,csrf,deck:String(deck)});const r=await fetch(base+'/decks.php',{method:'POST',headers:{cookie},body,redirect:'manual'});assert.equal(r.status,303,await r.text());return r.headers.get('location');}
await get('/decks.php');
if(process.argv[2]){const form=new FormData();form.set('csrf',csrf);form.set('action','import');form.set('collection',new Blob([readFileSync(process.argv[2])],{type:'text/csv'}),'collection.csv');await post({},form);const h=await get('/decks.php');assert(h.includes('cartas importadas'));console.log('Coleção importada.');}
try {
 const redirect=await post({action:'create',name:'Verificação temporária do módulo'});deck=Number(redirect.match(/deck=(\d+)/)[1]);
 let h=await get(`/decks.php?deck=${deck}&choose=1&q=Hearthhull&oracle=`);assert(h.includes('Hearthhull, the Worldseed'));
 const commander=h.match(/name="card" value="([^"]+)"/)[1];await post({action:'commander',card:commander});
 await post({action:'strategy',strategy:'Sacrificar e recuperar terrenos',terms:'sacrifice; land'});
 h=await get(`/decks.php?deck=${deck}&oracle=sacrifice%3Bland&match=all&owned=1&colors=1`);assert(h.includes('Contém “sacrifice”'));assert(h.includes('Contém “land”'));
 h=await get(`/decks.php?deck=${deck}&q=Fabled%20Passage&oracle=sacrifice%3Bxyznooracleterm&match=any`);assert(h.includes('Contém “sacrifice”'));
 h=await get(`/decks.php?deck=${deck}&q=Fabled%20Passage&oracle=sacrifice%3Bxyznooracleterm&match=all`);assert(h.includes('Nenhuma carta corresponde'));
 h=await get(`/decks.php?deck=${deck}&q=Fabled%20Passage&oracle=&type=pirate%3Bland`);assert(h.includes('Adicionar às candidatas'));
 h=await get(`/decks.php?deck=${deck}&q=Fabled%20Passage&oracle=&type=pirate%3Bsacrifice`);assert(h.includes('Adicionar às candidatas'));
 h=await get(`/decks.php?deck=${deck}&q=Fabled%20Passage&oracle=xyznooracleterm&type=pirate%3Bland`);assert(h.includes('Nenhuma carta corresponde'));
 h=await get(`/decks.php?deck=${deck}&q=Fabled%20Passage&oracle=&owned=1`);const card=h.match(/name="card" value="([^"]+)"/)[1];
 await post({action:'add',card,oracle:''});await post({action:'item',card,stage:'review',quantity:'1',role:'Terrenos',notes:'Habilita sacrifício',oracle:''});
 h=await get(`/decks.php?deck=${deck}&view=selection&stage=review`);assert(h.includes('Habilita sacrifício'));
 const moved=await post({action:'move',card,stage:'deck',view:'selection',selection_stage:'review'});assert(moved.includes('view=selection&stage=review'));
 h=await get(`/decks.php?deck=${deck}&view=selection&stage=deck`);assert(h.includes('Habilita sacrifício'));assert(h.includes('selection-art'));
  await post({action:'item',card,stage:'deck',quantity:'1',role:'Terrenos',notes:'Teste de quantidade',oracle:''});
  const shopping=await get(`/decks.php?deck=${deck}&export=shopping`);assert.equal(typeof shopping,'string');
  const exported=await get(`/decks.php?deck=${deck}&export=deck`);assert(exported.includes('1 Fabled Passage'));assert(exported.includes('1 Hearthhull, the Worldseed'));
  const liga=await get(`/decks.php?deck=${deck}&export=liga&liga_scope=all`);assert(liga.includes('Edicao (PTBR)'));assert(liga.includes('Fabled Passage'));
  h=await get(`/decks.php?deck=${deck}&view=selection&stage=deck`);assert(h.includes('Valor estimado das cartas aprovadas'));assert(h.includes('Baixar CSV padrão Liga'));
 // Reject forged writes without changing the persisted deck.
 const bad=await fetch(base+'/decks.php',{method:'POST',headers:{cookie},body:new URLSearchParams({action:'delete_deck',deck:String(deck),csrf:'invalid'})});assert((await bad.text()).includes('Sessão expirada'));
 await post({action:'remove',card,oracle:''});assert(!(await get(`/decks.php?deck=${deck}&export=deck`)).includes('Fabled Passage'));
 console.log('PASS: comandante, estratégia, Oracle AND, coleção, identidade, etapas, quantidades, compras, exportação, CSRF e remoção.');
} finally {if(deck)await post({action:'delete_deck'});}


