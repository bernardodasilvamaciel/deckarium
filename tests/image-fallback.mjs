import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';
import assert from 'node:assert/strict';
class TestImage {
  constructor(kind,alt) { this.dataset={};this.alt=alt;this.complete=true;this.naturalWidth=0;this.classList={contains:value=>value===kind}; }
  replaceWith(value) { this.replacement=value; }
}
const mana=new TestImage('mana-symbol','G/U');
const set=new TestImage('set-icon','Coleção');
const listeners=[];
const document={
  addEventListener(type,fn){if(type==='error')listeners.push(fn);},
  querySelector(){return null;},
  querySelectorAll(selector){return selector==='img'?[mana,set]:[];},
  createElement(tag){return {tag,append(...children){this.children=children;}};}
};
runInNewContext(readFileSync('app/assets/app.js','utf8'),{document,HTMLImageElement:TestImage,window:{addEventListener(){}}});
assert.equal(mana.replacement.tag,'span');
assert.equal(mana.replacement.textContent,'G/U');
assert.equal(set.replacement,undefined);
const late=new TestImage('mana-symbol','W/P');
listeners.forEach(fn=>fn({target:late}));
assert.equal(late.replacement.textContent,'W/P');
assert(!late.replacement.className.includes('placeholder'));
console.log('PASS: símbolos indisponíveis viram texto, tanto no carregamento quanto em erros posteriores.');
