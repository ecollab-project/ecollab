/* Self-hosted Fluent emoji + Noto animated stickers. Sources: docs/chat-media.md. */
'use strict';
(() => {
 const base=window.ECOLLAB?.baseUrl||'',asset=path=>base+'/assets/media/'+path;
 let catalog,promise,panel,target,anchor,selection,mode='emoji',category='recent',query='',tone=0,generation=0;
 let recent=[];try{const stored=JSON.parse(localStorage.getItem('ec_media_recent')||'[]');if(Array.isArray(stored))recent=stored.filter(x=>typeof x==='string').slice(0,36);}catch{}
 const byEmoji=new Map(),bySticker=new Map(),normalize=s=>s.replace(/\ufe0f/g,'');
 const categories={recent:'Recent',people:'Faces & people',nature:'Nature',foods:'Food & drink',activity:'Activities',places:'Travel',objects:'Objects',symbols:'Symbols',flags:'Flags'};
 const segmenter=typeof Intl.Segmenter==='function'?new Intl.Segmenter(undefined,{granularity:'grapheme'}):null;
 function image(record,animated=false){
  const img=document.createElement('img');img.src=asset(record.image);img.alt='';img.loading='lazy';img.decoding='async';img.draggable=false;
  if(animated){const picture=document.createElement('picture'),still=byEmoji.get(normalize(record.emoji));if(still?.image){const source=document.createElement('source');source.media='(prefers-reduced-motion: reduce)';source.srcset=asset(still.image);picture.append(source);}picture.append(img);return picture;}
  return img;
 }
 function load(){
  if(promise)return promise;
  promise=fetch(asset('catalog.json'),{credentials:'same-origin'}).then(r=>{if(!r.ok)throw Error('Could not load the media library.');return r.json();}).then(data=>{
   catalog=data;for(const e of data.emojis)byEmoji.set(normalize(e.emoji),e);for(const s of data.stickers)bySticker.set(s.id,s);scan(document.body);return data;
  }).catch(error=>{promise=null;throw error;});return promise;
 }
 function remember(value){recent=[value,...recent.filter(x=>x!==value)].slice(0,36);try{localStorage.setItem('ec_media_recent',JSON.stringify(recent));}catch{}}
 function insert(text){
  if(!target?.isConnected||target.disabled||target.readOnly){close();return;}
  if(!window.EcollabComposer?.insert(target,text)){
   const start=selection?.[0]??target.value.length,end=selection?.[1]??start;
   target.value=target.value.slice(0,start)+text+target.value.slice(end);target.focus();target.setSelectionRange(start+text.length,start+text.length);target.dispatchEvent(new Event('input',{bubbles:true}));
  }
  remember(text);close(false);target.focus();
 }
 function position(){
  if(!panel||panel.hidden)return;
  const viewport=window.visualViewport,left=viewport?.offsetLeft||0,top=viewport?.offsetTop||0,width=viewport?.width||innerWidth,height=viewport?.height||innerHeight;
  panel.style.maxHeight=Math.max(120,height-20)+'px';panel.style.width=Math.min(356,width-16)+'px';
  const rect=anchor?.getBoundingClientRect(),size=panel.getBoundingClientRect();
  panel.style.left=Math.max(left+8,Math.min(rect?.right-size.width||left+8,left+width-size.width-8))+'px';
  panel.style.top=Math.max(top+8,Math.min((rect?.top||top+height)-size.height-8,top+height-size.height-8))+'px';
 }
 function close(focus=true){generation++;if(panel)panel.hidden=true;document.getElementById('emojiBtn')?.classList.remove('active');document.getElementById('gifBtn')?.classList.remove('active');if(focus&&anchor?.isConnected)anchor.focus();}
 function build(){
  if(panel)return;
  panel=document.createElement('section');panel.id='ecMediaPicker';panel.className='ec-media-picker';panel.hidden=true;panel.setAttribute('role','dialog');panel.setAttribute('aria-label','Emoji and animated sticker library');
  panel.innerHTML='<header><div class="ec-media-tabs"><button type="button" data-mode="emoji">Emoji</button><button type="button" data-mode="sticker">Animated stickers</button></div><button type="button" data-close aria-label="Close media picker">×</button></header><div class="ec-media-search"><input type="search" aria-label="Search emojis or stickers" placeholder="Search by name…" maxlength="100"><select aria-label="Skin tone"><option value="0">Default tone</option><option value="1">Light</option><option value="2">Medium-light</option><option value="3">Medium</option><option value="4">Medium-dark</option><option value="5">Dark</option></select></div><nav aria-label="Emoji categories"></nav><div class="ec-media-results" role="status" aria-live="polite"></div><div class="ec-media-grid"></div><footer><a href="'+base+'/modules/chat/media-credits.php" target="_blank" rel="noopener">Artwork credits & licenses</a><span>Self-hosted · no API key</span></footer>';
  panel.addEventListener('click',event=>event.stopPropagation());
  panel.querySelector('[data-close]').onclick=()=>close();
  panel.querySelectorAll('[data-mode]').forEach(button=>button.onclick=()=>{mode=button.dataset.mode;query='';panel.querySelector('input').value='';render();});
  panel.querySelector('input').oninput=event=>{query=event.target.value.toLowerCase().trim();render();};
  panel.querySelector('select').onchange=event=>{tone=Number(event.target.value);render();};
  for(const [id,label] of Object.entries(categories)){const button=document.createElement('button');button.type='button';button.textContent=label;button.onclick=()=>{category=id;render();};button.dataset.category=id;panel.querySelector('nav').append(button);}
  panel.addEventListener('keydown',event=>{
   if(event.key==='Escape'){event.stopPropagation();close();}
   if(event.key==='Tab'){const controls=[...panel.querySelectorAll('button,input,select,a')].filter(el=>!el.disabled&&el.getClientRects().length);const first=controls[0],last=controls.at(-1);if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}}
  });document.body.append(panel);
 }
 function render(){
  if(!catalog)return;
  panel.querySelectorAll('[data-mode]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.mode===mode)));
  panel.querySelector('nav').hidden=mode!=='emoji';panel.querySelector('select').hidden=mode!=='emoji';
  panel.querySelectorAll('[data-category]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.category===category)));
  let list=mode==='sticker'?catalog.stickers:catalog.emojis;
  if(query)list=list.filter(e=>(e.name+' '+e.emoji+' '+e.keywords.join(' ')).toLowerCase().includes(query));
  else if(mode==='emoji')list=category==='recent'?recent.filter(x=>!x.startsWith(':ec-sticker:')).map(x=>{const e=byEmoji.get(normalize(x))||catalog.emojis.find(e=>e.skins.includes(x));return e?{...e,chosen:x}:null;}).filter(Boolean):list.filter(e=>e.category===category);
  const grid=panel.querySelector('.ec-media-grid');grid.classList.toggle('ec-media-stickers',mode==='sticker');grid.replaceChildren();
  panel.querySelector('.ec-media-results').textContent=list.length?list.length+' results'+(list.length>160?' · first 160 shown; refine your search':''):(category==='recent'&&!query&&mode==='emoji'?'Your recently used emoji will appear here. Choose a category to start.':'No matching results.');
  for(const record of list.slice(0,160)){
   const button=document.createElement('button');button.type='button';button.title=record.name;button.setAttribute('aria-label',record.name);
   const chosen=record.chosen||record.skins?.[tone]||record.emoji;
   if(mode==='sticker')button.append(image(record,true));else if(record.image&&chosen===record.emoji)button.append(image(record));else button.textContent=chosen;
   button.onmousedown=event=>event.preventDefault();button.onclick=()=>insert(mode==='sticker'?':ec-sticker:'+record.id+':':chosen);grid.append(button);
  }position();
 }
 async function open(kind,input,button){
  if(!input||input.disabled||input.readOnly)return;
  build();if(!panel.hidden&&target===input&&mode===kind){close();return;}
  window.closeAttachMenu?.();window.closeExtrasMenu?.();
  target=input;anchor=button;selection=[input.selectionStart??input.value.length,input.selectionEnd??input.value.length];mode=kind;query='';panel.querySelector('input').value='';panel.hidden=false;panel.querySelector('.ec-media-results').textContent='Loading library…';panel.querySelector('.ec-media-grid').replaceChildren();position();
  const token=++generation;
  try{await load();if(token!==generation||!target.isConnected)return;render();panel.querySelector('input').focus();}catch(error){if(token===generation)panel.querySelector('.ec-media-results').textContent='Library unavailable. Close and try again.';}
 }
 function decorate(node){
  if(!catalog||!node?.isConnected||node.closest('[contenteditable="true"],.ec-media-picker,.ec-media-inline,.ec-media-sticker'))return;
  const walker=document.createTreeWalker(node,NodeFilter.SHOW_TEXT),texts=[];while(walker.nextNode())texts.push(walker.currentNode);
  for(const text of texts){
   if(text.parentElement.closest('code,pre,a,textarea,script,style,[contenteditable="true"],.ec-media-inline,.ec-media-sticker,.dm-message-time'))continue;
   const sticker=/^:ec-sticker:([a-f0-9-]{2,80}):$/.exec(text.textContent.trim()),record=sticker&&bySticker.get(sticker[1]);
   if(record){const wrap=document.createElement('span');wrap.className='ec-media-sticker';wrap.setAttribute('role','img');wrap.setAttribute('aria-label',record.name+' animated sticker');wrap.append(image(record,true));text.replaceWith(wrap);continue;}
   if(!segmenter)continue;
   const fragment=document.createDocumentFragment();let changed=false;
   for(const {segment} of segmenter.segment(text.textContent)){const emoji=byEmoji.get(normalize(segment));if(!emoji?.image){fragment.append(document.createTextNode(segment));continue;}changed=true;const wrap=document.createElement('span');wrap.className='ec-media-inline';wrap.setAttribute('role','img');wrap.setAttribute('aria-label',emoji.name);const unicode=document.createElement('span');unicode.className='ec-media-unicode';unicode.setAttribute('aria-hidden','true');unicode.textContent=segment;wrap.append(unicode,image(emoji));fragment.append(wrap);}
   if(changed)text.replaceWith(fragment);
  }
 }
 const surfaces='.msg-text,.dm-message-bubble,.reaction>span:first-child';
 function scan(root){if(root?.nodeType!==1)return;if(root.matches(surfaces))decorate(root);root.querySelectorAll(surfaces).forEach(decorate);}
 const pending=new Set();let scheduled=false;
 const observer=new MutationObserver(records=>{
  for(const record of records){const parent=record.target.nodeType===1?record.target:record.target.parentElement;if(parent?.closest('.ec-media-inline,.ec-media-sticker,#ecMediaPicker'))continue;const surface=parent?.closest(surfaces);if(surface)pending.add(surface);else for(const node of record.addedNodes)if(node.nodeType===1)pending.add(node);}
  if(!scheduled&&pending.size){scheduled=true;queueMicrotask(()=>{scheduled=false;for(const node of pending)scan(node);pending.clear();});}
  if(panel&&!panel.hidden&&target&&!target.isConnected)close(false);
 });
 function init(){observer.observe(document.body,{childList:true,subtree:true,characterData:true});load().catch(()=>{});}
 document.addEventListener('click',event=>{if(panel&&!panel.hidden&&!panel.contains(event.target)&&!anchor?.contains(event.target))close(false);});
 window.addEventListener('resize',position);window.visualViewport?.addEventListener('resize',position);window.visualViewport?.addEventListener('scroll',position);
 window.EcollabMedia={open,close,load,scan,insertEmoji(emoji){target=document.getElementById('chatInputField');selection=[target?.selectionStart??target?.value.length,target?.selectionEnd??target?.value.length];insert(emoji);}};
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
