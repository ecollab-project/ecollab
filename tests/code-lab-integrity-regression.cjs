'use strict';
const {JSDOM}=require('jsdom'),fs=require('fs'),assert=require('node:assert/strict');
const dom=new JSDOM('<main id="codeLab"><section id="clWorkspace"><textarea id="clCode">hello world</textarea><textarea id="clStdin" maxlength="20"></textarea><p id="instructions">Write your own program.</p><p id="clIntegrityNotice"></p><button id="clInternalCopy">Copy selection</button><button id="clInternalPaste">Paste internal</button></section><textarea id="author">Author instructions</textarea></main>',{url:'https://example.invalid/modules/code-lab/index.php',runScripts:'outside-only'}),w=dom.window,d=w.document;
w.eval(fs.readFileSync('assets/js/code-lab-integrity.js','utf8'));const guard=w.EcollabCodeLabIntegrity,source=d.getElementById('clCode'),stdin=d.getElementById('clStdin');guard.setScope('task:1');
const data=()=>{const values={};return{values,setData:(k,v)=>{values[k]=v;},getData:k=>values[k]||'',clearData:()=>Object.keys(values).forEach(k=>delete values[k])};};
function clipboard(type,element,content){const e=new w.Event(type,{bubbles:true,cancelable:true});Object.defineProperty(e,'clipboardData',{value:content});element.dispatchEvent(e);return e;}
source.focus();source.setSelectionRange(0,5);const internal=data();assert(clipboard('copy',source,internal).defaultPrevented);assert(!Object.values(internal.values).includes('hello'));assert(internal.getData('text/plain').startsWith('ecollab-code-lab:'));
stdin.focus();assert(clipboard('paste',stdin,internal).defaultPrevented);assert.equal(stdin.value,'hello');
for(const text of ['external answer','hello',internal.getData('text/plain')+'forged']){const external=data();external.setData('text/plain',text);const before=source.value;clipboard('paste',source,external);assert.equal(source.value,before,'External clipboard cannot modify source');}
const fake=data();fake.setData('application/x-ecollab-code-lab','forged');clipboard('paste',source,fake);assert.equal(source.value,'hello world');
const drop=new w.Event('drop',{bubbles:true,cancelable:true});source.dispatchEvent(drop);assert(drop.defaultPrevented);
const before=new w.Event('beforeinput',{bubbles:true,cancelable:true});Object.defineProperty(before,'inputType',{value:'insertFromPaste'});source.dispatchEvent(before);assert(before.defaultPrevented);
const type=new w.Event('beforeinput',{bubbles:true,cancelable:true});Object.defineProperty(type,'inputType',{value:'insertText'});source.dispatchEvent(type);assert(!type.defaultPrevented,'Normal typing remains available');
source.focus();source.setSelectionRange(0,5);clipboard('cut',source,internal);assert.equal(source.value,' world');source.setSelectionRange(0,0);clipboard('paste',source,internal);assert.equal(source.value,'hello world');
source.readOnly=true;source.setSelectionRange(0,5);clipboard('cut',source,internal);assert.equal(source.value,'hello world');source.readOnly=false;
guard.setScope('task:2');const previous=source.value;clipboard('paste',source,internal);assert.equal(source.value,previous,'Clipboard cannot cross task sessions');
source.focus();source.setSelectionRange(0,5);d.getElementById('clInternalCopy').click();stdin.focus();stdin.setSelectionRange(0,stdin.value.length);d.getElementById('clInternalPaste').click();assert.equal(stdin.value,'hello');
const selection=w.getSelection(),range=d.createRange();range.selectNodeContents(d.getElementById('instructions'));selection.removeAllRanges();selection.addRange(range);source.blur();clipboard('copy',d.getElementById('instructions'),internal);assert(!Object.values(internal.values).includes('Write your own program.'));
const author=d.getElementById('author');assert(!clipboard('paste',author,data()).defaultPrevented,'Authoring form untouched');assert(!clipboard('copy',author,data()).defaultPrevented);
d.getElementById('clWorkspace').hidden=true;assert(!clipboard('paste',source,data()).defaultPrevented,'Hidden workspace has no global guard');
dom.window.close();console.log('PASS: external/forged paste and drop blocked; internal copy/cut/paste, read-only protection, typing, authoring isolation and task boundaries verified');
