import * as Y from 'yjs';
import { Awareness, applyAwarenessUpdate, removeAwarenessStates } from 'y-protocols/awareness';

// JSON transport is canonicalized by PHP; this produces the standard Yjs awareness frame.
export function awarenessFrame(entry) {
  const bytes=[];
  const uint=value=>{while(value>127){bytes.push((value&127)|128);value=Math.floor(value/128);}bytes.push(value);};
  uint(1);uint(entry.client_id);uint(entry.clock);
  const state=new TextEncoder().encode(JSON.stringify(entry.state));uint(state.length);
  return Uint8Array.from([...bytes,...state]);
}
export function createWhiteboardPresence(config, {send, changed}) {
  const doc=new Y.Doc(), awareness=new Awareness(doc);
  let joined=false, disposed=false, pointerTimer=null, pendingPointer=null, selected='';
  const scope={channel_id:Number(config.channelId),whiteboard_id:Number(config.whiteboardId)};
  const render=()=>changed(awareness.getStates(),awareness.clientID,joined);
  const transmit=()=>{
    if(!joined||disposed)return;
    send({type:'wb_presence',...scope,client_id:awareness.clientID,clock:awareness.meta.get(awareness.clientID).clock,state:awareness.getLocalState()});
  };
  awareness.on('update',(_changes,origin)=>{if(origin!=='remote')transmit();});
  awareness.on('change',render);
  awareness.setLocalState({pointer:null,button:'up',selectedElementIds:{},status:document.hidden?'idle':'active'});
  const visibility=()=>awareness.setLocalStateField('status',document.hidden?'idle':'active');
  const blur=()=>{awareness.setLocalStateField('pointer',null);awareness.setLocalStateField('status','idle');};
  const focus=()=>awareness.setLocalStateField('status','active');
  const exit=()=>awareness.setLocalState(null);
  const disconnected=()=>{joined=false;removeAwarenessStates(awareness,[...awareness.getStates().keys()].filter(id=>id!==awareness.clientID),'remote');render();};
  document.addEventListener('visibilitychange',visibility);window.addEventListener('blur',blur);window.addEventListener('focus',focus);window.addEventListener('pagehide',exit);window.addEventListener('ecollab:realtime-disconnected',disconnected);
  return {
    receive(message){
      if(Number(message.whiteboard_id)!==scope.whiteboard_id)return false;
      if(message.type==='wb_joined'){
        joined=true;
        // A reconnect starts with the server's current room snapshot, not stale cached cursors.
        const previous=[...awareness.meta.keys()].filter(id=>id!==awareness.clientID);
        removeAwarenessStates(awareness,previous,'remote');
        for(const id of previous)awareness.meta.delete(id);
        for(const entry of message.presence||[])applyAwarenessUpdate(awareness,awarenessFrame(entry),'remote');
        transmit();return false;
      }
      if(message.type==='wb_presence'){
        const entry=message.presence;
        if(entry&&entry.client_id!==awareness.clientID)applyAwarenessUpdate(awareness,awarenessFrame(entry),'remote');
        return true;
      }
      if(message.type==='wb_presence_remove'){removeAwarenessStates(awareness,message.client_ids||[],'remote');return true;}
      return false;
    },
    pointer({pointer,button}){
      if(disposed)return;
      pendingPointer={pointer,button};
      if(pointerTimer)return;
      pointerTimer=setTimeout(()=>{pointerTimer=null;const state=awareness.getLocalState();if(state)awareness.setLocalState({...state,...pendingPointer});},100);
    },
    selection(ids){const next=JSON.stringify(ids||{});if(next!==selected){selected=next;awareness.setLocalStateField('selectedElementIds',ids||{});}},
    refresh:render,
    dispose(){if(disposed)return;clearTimeout(pointerTimer);awareness.setLocalState(null);disposed=true;document.removeEventListener('visibilitychange',visibility);window.removeEventListener('blur',blur);window.removeEventListener('focus',focus);window.removeEventListener('pagehide',exit);window.removeEventListener('ecollab:realtime-disconnected',disconnected);awareness.destroy();doc.destroy();}
  };
}
