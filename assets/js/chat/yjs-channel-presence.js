import * as Y from 'yjs';
import {Awareness,applyAwarenessUpdate,removeAwarenessStates} from 'y-protocols/awareness';
import {awarenessFrame} from '../collab/yjs-presence.js?v=yjs-presence-1';

export function createChannelPresence({send,changed}) {
  let room=null,doc=null,awareness=null,stop=null;
  const publish=()=>{if(room&&awareness)send({type:'chat_presence',channel_id:room,client_id:awareness.clientID,clock:awareness.meta.get(awareness.clientID).clock,state:awareness.getLocalState()});};
  const render=()=>changed(awareness?.getStates()||new Map(),awareness?.clientID,room);
  const clear=(notify=false)=>{clearTimeout(stop);if(!notify)room=null;if(awareness){awareness.setLocalState(null);awareness.destroy();doc.destroy();}awareness=null;doc=null;room=null;render();};
  return {
    receive(message){
      if(message.type==='joined_channel'){
        clear();room=Number(message.channel_id);doc=new Y.Doc();awareness=new Awareness(doc);
        awareness.on('update',(_changes,origin)=>{if(origin!=='remote')publish();});awareness.on('change',render);
        awareness.setLocalState({typing:false,status:document.hidden?'idle':'active'});
        for(const entry of message.presence||[])applyAwarenessUpdate(awareness,awarenessFrame(entry),'remote');
        return;
      }
      if(Number(message.channel_id)!==room||!awareness)return;
      if(message.type==='chat_presence')applyAwarenessUpdate(awareness,awarenessFrame(message.presence),'remote');
      if(message.type==='chat_presence_remove')removeAwarenessStates(awareness,message.client_ids||[],'remote');
    },
    typing(on){if(!awareness)return false;clearTimeout(stop);if(awareness.getLocalState()?.typing!==!!on)awareness.setLocalStateField('typing',!!on);if(on)stop=setTimeout(()=>awareness?.setLocalStateField('typing',false),2500);return true;},
    idle(on){if(awareness){awareness.setLocalStateField('status',on?'idle':'active');if(on)this.typing(false);}},
    disconnect:()=>clear(),dispose:()=>clear(true)
  };
}

if(typeof window!=='undefined'&&document.getElementById('chatInputField')){
  const presence=createChannelPresence({send:message=>window.wsSend?.(message),changed:(states,localId,room)=>{
    const users=new Map(),typing=new Set();
    for(const [id,state] of states){if(!state.user)continue;users.set(state.user.id,state.user);if(id!==localId&&state.typing&&state.status==='active')typing.add(state.user.name);}
    const names=[...typing],indicator=document.getElementById('typingIndicator'),label=document.getElementById('typingText');
    if(indicator)indicator.style.display=names.length?'flex':'none';
    if(label)label.textContent=names.length===1?names[0]+' is typing…':names.length===2?names.join(' and ')+' are typing…':names.length+' people are typing…';
    let badge=document.getElementById('channelPresenceBadge');
    if(!badge){badge=document.createElement('span');badge.id='channelPresenceBadge';badge.style.cssText='font-size:11px;color:var(--text-muted);margin-left:8px';document.querySelector('.chat-header')?.append(badge);}
    if(badge){badge.textContent=room?(users.size+(states.has(localId)&&!users.has(Number(window.ECOLLAB?.userId))?1:0))+' viewing':'';badge.title=[...users.values()].map(u=>u.name).join(', ');}
  }});
  window.EcollabChatPresence=presence;
  window.addEventListener('ecollab:chat-presence',event=>presence.receive(event.detail));
  window.addEventListener('ecollab:realtime-disconnected',()=>presence.disconnect());
  document.addEventListener('visibilitychange',()=>presence.idle(document.hidden));window.addEventListener('blur',()=>presence.idle(true));window.addEventListener('focus',()=>presence.idle(false));window.addEventListener('pagehide',()=>presence.dispose());
  // The module may finish loading after the first channel acknowledgement.
  const join=setInterval(()=>{const channel=Number(window.ECOLLAB?.currentChannelId);if(channel&&window.wsSend?.({type:'join_channel',channel_id:channel}))clearInterval(join);},250);
}
