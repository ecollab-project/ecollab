/* SQL timestamps are UTC; calendar boundaries and clocks use the viewer's local timezone. */
'use strict';
(() => {
 if(window.EcollabTimeline)return;
 function parse(value){if(value instanceof Date)return value;let text=String(value||'').trim();if(/^\d{4}-\d\d-\d\d[ T]\d\d:\d\d(?::\d\d(?:\.\d+)?)?$/.test(text))text=text.replace(' ','T')+'Z';const date=new Date(text);return Number.isNaN(date.getTime())?null:date;}
 const clock=new Intl.DateTimeFormat(undefined,{weekday:'short',hour:'numeric',minute:'2-digit',hour12:true}),calendar=new Intl.DateTimeFormat(undefined,{weekday:'short',month:'short',day:'numeric',year:'numeric'});
 function stamp(value){const d=parse(value);return d?clock.format(d):'';}
 function day(value){const d=parse(value);return d?[d.getFullYear(),d.getMonth()+1,d.getDate()].join('-'):'';}
 function reset(area,first=null){if(area){area.dataset.ecUnreadId=Number(first)>0?String(first):'';tails.delete(area);indices.delete(area);}}
 function atEnd(area){return !area||area.scrollHeight-area.scrollTop-area.clientHeight<80;}
 function canRead(area){return !!area&&!document.hidden&&(!area.checkVisibility||area.checkVisibility())&&atEnd(area);}
 const readTimers=new WeakMap(),observers=new WeakMap(),tails=new WeakMap(),indices=new WeakMap();
 function requestRead(area){if(!canRead(area))return;clearTimeout(readTimers.get(area));readTimers.set(area,setTimeout(()=>{if(canRead(area))window.dispatchEvent(new CustomEvent('ecollab:visible-chat',{detail:{area:area.id}}));},250));}
 document.addEventListener('scroll',event=>{if(['messagesArea','dmMessagesArea'].includes(event.target.id))requestRead(event.target);},true);
 document.addEventListener('visibilitychange',()=>{for(const id of ['messagesArea','dmMessagesArea'])requestRead(document.getElementById(id));});
 function beforeAppend(area,msg){const mine=Number(msg.sender_id)===Number(window.ECOLLAB?.userId);const follow=mine||canRead(area);if(!mine&&!follow&&!area.dataset.ecUnreadId&&Number(msg.id)>0)area.dataset.ecUnreadId=String(msg.id);return follow;}
 function decorate(area){if(!area)return;if(!observers.has(area)){const observer=new MutationObserver(records=>{if(records.some(record=>[...record.removedNodes].some(node=>node.nodeType===1&&node.hasAttribute('data-msg-id'))))decorate(area);});observer.observe(area,{childList:true});observers.set(area,observer);}for(const el of [...area.children])if(el.classList.contains('ec-chat-day')||el.classList.contains('ec-chat-unread'))el.remove();let previous='',unread=false;const first=Number(area.dataset.ecUnreadId),me=Number(window.ECOLLAB?.userId);
  for(const message of [...area.children].filter(el=>el.hasAttribute('data-msg-id'))){const d=parse(message.dataset.messageTimestamp),key=day(d);if(key&&key!==previous){const divider=document.createElement('div');divider.className='ec-chat-day';divider.setAttribute('role','separator');const label=document.createElement('span');label.textContent=calendar.format(d);divider.append(label);area.insertBefore(divider,message);previous=key;}
   if(first>0&&!unread&&Number(message.dataset.msgId)>=first&&Number(message.dataset.senderId)!==me){const divider=document.createElement('div');divider.className='ec-chat-unread';divider.setAttribute('role','separator');divider.setAttribute('aria-label','Unread messages');const label=document.createElement('span');label.textContent='Unread Messages';divider.append(label);area.insertBefore(divider,message);unread=true;}
  }
  indices.set(area,new Map([...area.children].filter(e=>e.hasAttribute('data-msg-id')).map(e=>[e.dataset.msgId,e])));
  tails.set(area,{last:[...area.children].filter(e=>e.hasAttribute('data-msg-id')).at(-1),day:previous,unread});
 }
 function has(area,id){let index=indices.get(area);if(!index){index=new Map([...area.children].filter(e=>e.hasAttribute('data-msg-id')).map(e=>[e.dataset.msgId,e]));indices.set(area,index);}return index.get(String(id))?.parentNode===area;}
 function append(area,message){
  const state=tails.get(area);
  if(!state||message.previousElementSibling!==state.last){decorate(area);return;}
  const date=parse(message.dataset.messageTimestamp),key=day(date);
  if(key&&key!==state.day){const divider=document.createElement('div');divider.className='ec-chat-day';divider.setAttribute('role','separator');const label=document.createElement('span');label.textContent=calendar.format(date);divider.append(label);area.insertBefore(divider,message);state.day=key;}
  const first=Number(area.dataset.ecUnreadId);
  if(first>0&&!state.unread&&Number(message.dataset.msgId)>=first&&Number(message.dataset.senderId)!==Number(window.ECOLLAB?.userId)){const divider=document.createElement('div');divider.className='ec-chat-unread';divider.setAttribute('role','separator');divider.setAttribute('aria-label','Unread messages');const label=document.createElement('span');label.textContent='Unread Messages';divider.append(label);area.insertBefore(divider,message);state.unread=true;}
  state.last=message;indices.get(area)?.set(message.dataset.msgId,message);
 }
 window.EcollabTimeline={parse,stamp,day,reset,canRead,beforeAppend,decorate,append,has,requestRead};
})();
