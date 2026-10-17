/* Normal browser clipboard guard, scoped to the Code Lab workspace. Not a secure browser. */
'use strict';
(() => {
 const root=document.getElementById('codeLab'),workspace=document.getElementById('clWorkspace');
 if(!root||!workspace)return;
 const fields=['clCode','clStdin'].map(id=>document.getElementById(id)).filter(Boolean);
 const mime='application/x-ecollab-code-lab',notice=document.getElementById('clIntegrityNotice');
 let saved=null,scope='',lastField=fields[0];
 function announce(message){if(notice)notice.textContent=message;}
 function active(){return !workspace.hidden;}
 function field(node){return fields.includes(node)?node:window.EcollabCodeEditor?.fieldFor(node)||null;}
 function selected(){const target=field(document.activeElement);if(target)return {text:target.value.slice(target.selectionStart,target.selectionEnd),target,start:target.selectionStart,end:target.selectionEnd};const selection=window.getSelection();if(!selection?.rangeCount||!workspace.contains(selection.anchorNode)||!workspace.contains(selection.focusNode))return null;return {text:selection.toString(),target:null};}
 function capture(){const selection=selected();if(!selection?.text)return null;const bytes=new Uint8Array(24);crypto.getRandomValues(bytes);saved={text:selection.text,marker:'ecollab-code-lab:'+Array.from(bytes,n=>n.toString(16).padStart(2,'0')).join(''),scope};return selection;}
 function replace(target,text){if(!target||target.disabled||target.readOnly||!active())return false;const room=(target.maxLength>=0?target.maxLength:100000)-target.value.length+(target.selectionEnd-target.selectionStart);if(text.length>room){announce('Internal clipboard content exceeds this field’s limit.');return false;}target.setRangeText(text,target.selectionStart,target.selectionEnd,'end');target.focus();target.dispatchEvent(new Event('input',{bubbles:true}));return true;}
 function copy(event){if(!active()||!workspace.contains(event.target))return;event.preventDefault();event.stopPropagation();if(!event.clipboardData){announce('Use Copy selection and Paste internal below.');return;}const selection=capture();if(!selection)return;try{event.clipboardData.clearData();event.clipboardData.setData(mime,saved.marker);event.clipboardData.setData('text/plain',saved.marker);}catch{saved=null;announce('Clipboard access failed. Your code was not cut.');return;}if(event.type==='cut'&&selection.target&&!selection.target.readOnly&&!selection.target.disabled)replace(selection.target,'');announce('Copied inside this Code Lab workspace. The system clipboard contains a marker, not your code.');}
 workspace.addEventListener('copy',copy,true);workspace.addEventListener('cut',copy,true);
 workspace.addEventListener('paste',event=>{const target=field(event.target);if(!active()||!target)return;event.preventDefault();event.stopPropagation();const marker=event.clipboardData?.getData(mime)||event.clipboardData?.getData('text/plain');if(!saved||saved.scope!==scope||marker!==saved.marker){announce('External paste blocked. Type your code, or copy from this Code Lab workspace.');return;}if(replace(target,saved.text))announce('Pasted from the internal Code Lab clipboard.');},true);
 workspace.addEventListener('beforeinput',event=>{if(active()&&field(event.target)&&/^insertFrom(?:Paste|Drop|Yank)/.test(event.inputType)){event.preventDefault();event.stopPropagation();announce('Use the internal Code Lab clipboard. External insertion is blocked.');}},true);
 for(const type of ['dragover','drop'])workspace.addEventListener(type,event=>{if(active()&&field(event.target)){event.preventDefault();event.stopPropagation();if(type==='drop')announce('Drag-and-drop into Code Lab is blocked. Type or use the internal clipboard.');}},true);
 fields.forEach(input=>input.addEventListener('focus',()=>{lastField=input;}));
 document.getElementById('clInternalCopy')?.addEventListener('mousedown',event=>event.preventDefault());
 document.getElementById('clInternalCopy')?.addEventListener('click',()=>{if(active()&&capture())announce('Selection copied into Code Lab memory. Use Paste internal.');else announce('Select text in your code, instructions or output first.');});
 document.getElementById('clInternalPaste')?.addEventListener('mousedown',event=>event.preventDefault());
 document.getElementById('clInternalPaste')?.addEventListener('click',()=>{if(!saved||saved.scope!==scope){announce('Copy a selection inside this workspace first.');return;}if(replace(lastField,saved.text))announce('Pasted from Code Lab memory.');});
 function clear(){saved=null;if(notice)notice.textContent='';}
 window.addEventListener('pagehide',clear);
 window.EcollabCodeLabIntegrity={setScope(value){if(scope!==value)clear();scope=value;},clear};
})();
