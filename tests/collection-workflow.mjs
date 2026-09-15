import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';

const base='http://localhost:8080'; let cookie=''; const printing=randomUUID();
async function request(path,options={}){const response=await fetch(base+path,{redirect:'manual',...options,headers:{cookie,...(options.headers||{})}});const set=response.headers.get('set-cookie');if(set)cookie=set.split(';')[0];return response;}
async function page(path){const response=await request(path);assert.equal(response.status,200);return response.text();}
const token=html=>html.match(/name="csrf" value="([^"]+)"/)?.[1];

let html=await page('/collection.php'); const csrf=token(html); assert(csrf);
const csv=`Name,Scryfall ID,Quantity\nVerificação temporária,${printing},2\n`;
const form=new FormData();form.set('csrf',csrf);form.set('action','import');form.set('mode','add');form.set('collection',new Blob([csv],{type:'text/csv'}),'collection.csv');
let response=await request('/collection.php',{method:'POST',body:form});assert.equal(response.status,303,await response.text());
html=await page('/collection.php');assert(html.includes('As quantidades foram somadas'));
response=await request('/collection.php',{method:'POST',body:new URLSearchParams({csrf:token(html),action:'delete',card:printing})});assert.equal(response.status,303,await response.text());
html=await page('/collection.php');assert(html.includes('Verificação temporária removida'));
console.log('PASS: importação CSV incremental e exclusão de impressão na coleção.');
