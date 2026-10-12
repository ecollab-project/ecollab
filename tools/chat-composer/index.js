import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { EditorState } from '@tiptap/pm/state';
import { Markdown } from '@tiptap/markdown';
import createDOMPurify from 'dompurify';
import { Marked } from 'marked';

const escape = text => String(text).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const parser = new Marked({gfm:true,breaks:true,renderer:{html:({text})=>escape(text)}});
const purify = createDOMPurify(window);
export function renderMessage(text) {
  // Keep raw HTML literal, exclude images/iframes, and permit only message formatting.
  const safe = purify.sanitize(parser.parse(String(text || '')), {
    ALLOWED_TAGS:['p','br','strong','em','del','s','code','pre','ul','ol','li','blockquote','a'],
    ALLOWED_ATTR:['href','title','start'], ALLOW_DATA_ATTR:false, ALLOW_ARIA_ATTR:false
  });
  const template = document.createElement('template'); template.innerHTML = safe;
  template.content.querySelectorAll('a').forEach(link => {
    try {
      const url = new URL(link.getAttribute('href'), window.location.href);
      if (!['https:','http:','mailto:'].includes(url.protocol)) link.removeAttribute('href');
      else {link.target='_blank';link.rel='noopener noreferrer';}
    } catch (_) {link.removeAttribute('href');}
  });
  // Highlight mentions in text nodes only, never inside URLs or code.
  const walker = document.createTreeWalker(template.content, NodeFilter.SHOW_TEXT);
  const nodes=[];let node;while ((node=walker.nextNode())) nodes.push(node);
  for (const textNode of nodes) {
    if (textNode.parentElement?.closest('a,pre,code')) continue;
    const text=textNode.textContent, matches=[...text.matchAll(/@([A-Za-z0-9_]+(?: [A-Za-z0-9_]+)?)/g)];
    if (!matches.length) continue;
    const fragment=document.createDocumentFragment();let from=0;
    for (const match of matches) {fragment.append(document.createTextNode(text.slice(from,match.index)));const span=document.createElement('span');span.className='mention';span.textContent=match[0];fragment.append(span);from=match.index+match[0].length;}
    fragment.append(document.createTextNode(text.slice(from)));textNode.replaceWith(fragment);
  }
  return template.innerHTML;
}

// Keep existing @username notification matching intact without changing code examples.
function normalizeMentions(markdown) {
  let fence=null;
  return markdown.split('\n').map(line=>{
    const mark=line.match(/^\s*(`{3,}|~{3,})/);
    if(mark){if(!fence)fence=mark[1];else if(mark[1][0]===fence[0] && mark[1].length>=fence.length)fence=null;return line;}
    if(fence)return line;
    return line.replace(/(`+)[^\n]*?\1|@(?:[A-Za-z0-9_]|\\_)+/g,part=>part.startsWith('@')?part.replace(/\\_/g,'_'):part);
  }).join('\n');
}
const instances=new Map();
const limits=4000;
const controls=[
  ['Bold','B','bold','toggleBold'],['Italic','I','italic','toggleItalic'],
  ['Strikethrough','S','strike','toggleStrike'],['Inline code','<>','code','toggleCode'],
  ['Bullet list','•','bulletList','toggleBulletList'],['Numbered list','1.','orderedList','toggleOrderedList'],
  ['Quote','❝','blockquote','toggleBlockquote'],['Code block','{ }','codeBlock','toggleCodeBlock']
];
function attach(input) {
  if (instances.has(input) || !input.isConnected) return;
  const isChannel=input.id==='chatInputField';
  const wrapper=document.createElement('div');wrapper.className='ec-composer';
  const toolbar=document.createElement('div');toolbar.className='ec-composer-toolbar';toolbar.setAttribute('role','toolbar');toolbar.setAttribute('aria-label','Message formatting');
  const surface=document.createElement('div');surface.className='ec-composer-surface';
  const footer=document.createElement('div');footer.className='ec-composer-footer';
  const hint=document.createElement('span');hint.textContent='Enter to send · Shift+Enter for a new line';
  const counter=document.createElement('span');counter.className='ec-composer-count';counter.setAttribute('aria-live','off');footer.append(hint,counter);
  wrapper.append(toolbar,surface,footer);input.insertAdjacentElement('afterend',wrapper);
  let value=input.value,editor;
  const nativeFocus=input.focus;
  const valueDescriptor=Object.getOwnPropertyDescriptor(input,'value');
  const nativeDisabled=Object.getOwnPropertyDescriptor(input.constructor.prototype,'disabled');
  const buttons=[];
  const serialize=()=>editor.isEmpty?'':normalizeMentions(editor.getMarkdown());
  const update=()=>{
    value=serialize();counter.textContent=value.length ? value.length.toLocaleString()+' / '+limits.toLocaleString() : '';
    wrapper.classList.toggle('ec-composer-too-long',value.length>limits);
    for (const [button,active] of buttons) {button.setAttribute('aria-pressed',String(editor.isActive(active)));button.disabled=input.disabled;}
    const undoButton=toolbar.querySelector('[aria-label="Undo"]');if(undoButton)undoButton.disabled=input.disabled;
    surface.querySelector('.tiptap > p:first-child')?.setAttribute('data-placeholder',input.placeholder || 'Message…');
  };
  const send=()=>{if(input.disabled)return false;
    if(!canSend(input))return true;
    isChannel?window.sendMessage?.():window.sendDmMessage?.();return true;};
  editor=new Editor({
    element:surface,
    extensions:[StarterKit.configure({heading:false,horizontalRule:false,link:false,underline:false}),Markdown],
    content:value,contentType:'markdown',
    editorProps:{attributes:{role:'textbox','aria-label':isChannel?'Channel message':'Private or group message','aria-multiline':'true'},
      handleKeyDown:(_view,event)=>{
        if(event.isComposing || event.keyCode===229)return false;
        const inBlock=editor.isActive('codeBlock') || editor.isActive('bulletList') || editor.isActive('orderedList') || editor.isActive('blockquote');
        if(event.key==='Enter' && (event.ctrlKey || event.metaKey)){event.preventDefault();return send();}
        if(isChannel && !inBlock){window.handleKeyDown?.(event);if(event.defaultPrevented)return true;}
        if(event.key==='Enter' && !event.shiftKey && !inBlock){event.preventDefault();return send();}
        return false;
      }
    },
    onUpdate:()=>{update();input.dispatchEvent(new Event('input',{bubbles:true}));},
    onSelectionUpdate:()=>update()
  });
  for(const [label,icon,active,command] of controls){const button=document.createElement('button');button.type='button';button.className='ec-composer-tool';button.textContent=icon;button.title=label;button.setAttribute('aria-label',label);button.setAttribute('aria-pressed','false');
    button.addEventListener('mousedown',e=>e.preventDefault());button.addEventListener('click',()=>{if(!input.disabled)editor.chain().focus()[command]().run();update();});toolbar.append(button);buttons.push([button,active]);}
  const undo=document.createElement('button');undo.type='button';undo.className='ec-composer-tool';undo.textContent='↶';undo.title='Undo';undo.setAttribute('aria-label','Undo');undo.addEventListener('mousedown',e=>e.preventDefault());undo.addEventListener('click',()=>{if(!input.disabled)editor.chain().focus().undo().run();});toolbar.append(undo);
  input.classList.add('ec-composer-mirror');
  Object.defineProperty(input,'value',{configurable:true,get:()=>value,set:next=>{value=String(next??'');editor.commands.setContent(value,{contentType:'markdown',emitUpdate:false});
    // Channel changes, draft restoration and send resets must not retain another chat's undo history.
    const state=editor.state;editor.view.updateState(EditorState.create({schema:state.schema,doc:state.doc,plugins:state.plugins}));update();}});
  Object.defineProperty(input,'disabled',{configurable:true,get(){return nativeDisabled.get.call(input);},set(next){nativeDisabled.set.call(input,next);editor.setEditable(!next,false);buttons.forEach(([b])=>b.disabled=!!next);undo.disabled=!!next;}});
  input.focus=()=>{if(!input.disabled)editor.commands.focus('end');};
  instances.set(input,{editor,wrapper,valueDescriptor,nativeFocus,update});editor.setEditable(!input.disabled,false);update();
}
function detach(input,state){state.editor.destroy();state.wrapper.remove();delete input.value;delete input.disabled;if(state.valueDescriptor)Object.defineProperty(input,'value',state.valueDescriptor);input.focus=state.nativeFocus;input.classList.remove('ec-composer-mirror');instances.delete(input);}
export function canSend(input){if(!instances.has(input))return true;if(input.value.length<=limits)return true;window.showToast?.('Message is too long. Maximum 4,000 characters including formatting.','error');return false;}
function insert(input,text){const state=instances.get(input);if(!state)return false;state.editor.chain().focus().insertContent({type:'text',text:String(text)}).run();return true;}
function mention(input,username){const state=instances.get(input);if(!state)return false;const editor=state.editor;const pos=editor.state.selection.from;const before=editor.state.doc.textBetween(0,pos,'\n');const match=before.match(/(?:^|\s)@[^\s@]*$/);if(!match)return false;const length=match[0].trimStart().length;editor.chain().focus().insertContentAt({from:pos-length,to:pos},{type:'text',text:'@'+username+' '}).run();return true;}
function scan(){for(const [input,state] of instances){if(!input.isConnected)detach(input,state);else{state.editor.setEditable(!input.disabled,false);state.update();}}
  for(const id of ['chatInputField','dmInputField']){const input=document.getElementById(id);if(input)attach(input);}}
export function start(){if(!window.ECOLLAB?.richComposerEnabled || window.EcollabComposer)return;
  window.EcollabComposer={render:renderMessage,canSend,insert,mention,textBefore(input){const e=instances.get(input)?.editor;return e?e.state.doc.textBetween(0,e.state.selection.from,'\n'):null;},getEditor:input=>instances.get(input)?.editor};
  scan();let queued=false;const observer=new MutationObserver(records=>{if(records.every(r=>r.target.closest?.('.ec-composer')))return;if(queued)return;queued=true;queueMicrotask(()=>{queued=false;scan();});});
  observer.observe(document.body,{subtree:true,childList:true,attributes:true,attributeFilter:['disabled','placeholder']});
  window.addEventListener('pagehide',event=>{if(event.persisted)return;observer.disconnect();for(const [input,state] of [...instances])detach(input,state);});
}
start();
