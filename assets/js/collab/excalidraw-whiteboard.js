import React, { useCallback, useEffect, useRef, useState } from "react";
import { createRoot } from "react-dom/client";
import { Excalidraw } from "https://esm.sh/@excalidraw/excalidraw@0.18.0/dist/dev/index.js?external=react,react-dom";

import {createWhiteboardPresence} from "./yjs-presence.js?v=yjs-presence-1";

const cfg = window.ECOLLAB_EXCALIDRAW || {};
const host = document.getElementById("excalidraw-root");
const canEdit = cfg.permission === "edit";
const endpoint = `${window.ECOLLAB?.baseUrl || ""}/API/collaboration/whiteboards.php?workspace_id=${encodeURIComponent(cfg.workspaceId)}&whiteboard_id=${encodeURIComponent(cfg.whiteboardId)}`;

// Merge per-element revisions instead of replacing a peer's whole scene.
function mergeSceneElements(local, incoming) {
  const map = new Map(local.map(e => [e.id, e]));
  for (const element of incoming) {
    if (!element || typeof element.id !== 'string') continue;
    const previous = map.get(element.id);
    if (!previous || Number(element.version || 0) > Number(previous.version || 0) ||
        (Number(element.version || 0) === Number(previous.version || 0) && Number(element.versionNonce || 0) < Number(previous.versionNonce || 0))) map.set(element.id, element);
  }
  return [...map.values()];
}
function sceneFingerprint(elements, background) {
  return JSON.stringify([elements.map(e => [e.id,e.version,e.versionNonce,!!e.isDeleted]).sort((a,b)=>String(a[0]).localeCompare(String(b[0]))),background||'#fff']);
}

function liveSceneDelta(scene, published, files) {
  return {...scene,elements:scene.elements.filter(element=>published.get(element.id)!==JSON.stringify([element.version,element.versionNonce,!!element.isDeleted])),files:Object.fromEntries(Object.entries(scene.files).filter(([id])=>!files.has(id)))};
}

async function loadBoard() {
  const r = await fetch(endpoint, {credentials:"same-origin"});
  const d = await r.json();
  if (!r.ok || !d.success) throw new Error(d.error || "Unable to load whiteboard");
  const s = d.whiteboard?.state || {};
  return {elements:Array.isArray(s.elements)?s.elements:[], appState:s.appState||{}, files:s.files||{}};
}
async function persist(scene) {
  const r = await fetch(endpoint, {
    method:"POST", credentials:"same-origin",
    headers:{"Content-Type":"application/json","X-CSRF-Token":window.ECOLLAB?.csrfToken||""},
    body:JSON.stringify({workspace_id:cfg.workspaceId,whiteboard_id:cfg.whiteboardId,action:"save",state:scene})
  });
  const d=await r.json(); if(!r.ok||!d.success) throw new Error(d.error||"Save failed");
}
function App(){
  const presence=useRef(null), published=useRef(new Map()), publishedFiles=useRef(new Set());
  const api=useRef(null), timer=useRef(null), broadcast=useRef(null), latest=useRef(null), saving=useRef(false), last=useRef("");
  const getTheme=()=>document.documentElement.dataset.theme==="light"?"light":"dark";
  const [initial,setInitial]=useState(null), [status,setStatus]=useState("Loading…"), [theme,setTheme]=useState(getTheme);
  useEffect(()=>{loadBoard().then(s=>{published.current=new Map(s.elements.map(e=>[e.id,JSON.stringify([e.version,e.versionNonce,!!e.isDeleted])]));publishedFiles.current=new Set(Object.keys(s.files));last.current=sceneFingerprint(s.elements,s.appState.viewBackgroundColor);setInitial(s);setStatus(canEdit?"Saved":"View only")}).catch(e=>setStatus(e.message))},[]);
  useEffect(()=>{
    const sync=()=>setTheme(getTheme());
    window.addEventListener("ecollab:settings-applied",sync);
    const observer=new MutationObserver(sync);
    observer.observe(document.documentElement,{attributes:true,attributeFilter:["data-theme"]});
    sync();
    return()=>{window.removeEventListener("ecollab:settings-applied",sync);observer.disconnect()};
  },[]);
  const apply=useCallback(msg=>{
    if(Number(msg.whiteboard_id)!==Number(cfg.whiteboardId))return;
    if(presence.current?.receive(msg))return;
    let scene=null;
    if(msg.type==="wb_joined"&&msg.state_json){try{scene=JSON.parse(msg.state_json)}catch{}}
    if(msg.type==="wb_state"&&msg.state_json){try{scene=JSON.parse(msg.state_json)}catch{}}
    if(msg.type==="wb_op"&&msg.op==="excalidraw_scene")scene=msg.scene;
    if(!scene||!Array.isArray(scene.elements)||!api.current)return;
    if (Number(msg.user_id) === Number(window.ECOLLAB?.userId)) return;
    const local=api.current.getSceneElementsIncludingDeleted?.()||[];
    const elements=mergeSceneElements(local,scene.elements);
    const background=scene.appState?.viewBackgroundColor||api.current.getAppState?.().viewBackgroundColor||"#fff";
    const fingerprint=sceneFingerprint(elements,background);
    if(fingerprint===last.current)return;
    last.current=fingerprint;
    if(latest.current)latest.current={...latest.current,elements,appState:{...latest.current.appState,viewBackgroundColor:background},files:{...latest.current.files,...scene.files}};
    api.current.updateScene({elements,appState:{viewBackgroundColor:background}});
    if(scene.files&&api.current.addFiles)api.current.addFiles(Object.values(scene.files));

  },[]);
  useEffect(()=>{
    presence.current=createWhiteboardPresence(cfg,{send:message=>window.wsSend?.(message),changed:(states,localId,connected)=>{
      const collaborators=new Map();
      const unique=new Map();
      for(const [clientId,state] of states){
        if(!state.user)continue;
        unique.set(state.user.id,state);
        if(clientId!==localId)collaborators.set(String(clientId),{username:state.user.name,pointer:state.pointer||undefined,button:state.button,selectedElementIds:state.selectedElementIds||{},color:{background:state.user.color,stroke:state.user.color},userState:state.status==='idle'?'idle':'active'});
      }
      api.current?.updateScene({collaborators});
      // Include this authenticated user before the first server echo arrives.
      if(!unique.has(Number(window.ECOLLAB?.userId)))unique.set(Number(window.ECOLLAB?.userId),{user:{name:'You',color:'#a855f7'},status:document.hidden?'idle':'active'});
      const stack=document.getElementById('wbAvStack');
      if(stack){stack.replaceChildren();for(const state of unique.values()){const avatar=document.createElement('span');avatar.className='wb-yjs-avatar';avatar.textContent=(state.user.name||'?').slice(0,1).toUpperCase();avatar.title=state.user.name+' · '+state.status;avatar.style.background=state.user.color;stack.append(avatar);}}
      const label=document.getElementById('wbSessionStatus');if(label)label.textContent=unique.size+' collaborator'+(unique.size===1?'':'s')+(connected?' · live presence':' · connecting…');
    }});
    const old=window.wbHandleWsMessage;
    window.ecollabWhiteboardSave=async()=>{
      if(!canEdit||!api.current)return;
      clearTimeout(timer.current);
      const state=api.current.getAppState();
      const scene={format:'excalidraw',version:1,elements:api.current.getSceneElementsIncludingDeleted(),appState:{viewBackgroundColor:state.viewBackgroundColor,gridSize:state.gridSize??null},files:api.current.getFiles()};
      setStatus('Saving…');try{await persist(scene);setStatus('Saved');}catch(error){setStatus(error.message);throw error;}
    };
    window.wbHandleWsMessage=apply; // Do not also replay Excalidraw state through the legacy canvas renderer.
    window.wbRejoinRoom=()=>window.wsSend?.({type:"wb_join",channel_id:Number(cfg.channelId),whiteboard_id:Number(cfg.whiteboardId)});
    const join=setInterval(()=>{if(window.wsSend?.({type:"wb_join",channel_id:Number(cfg.channelId),whiteboard_id:Number(cfg.whiteboardId)}))clearInterval(join)},500);
    return()=>{presence.current?.dispose();presence.current=null;clearInterval(join);clearTimeout(broadcast.current);clearTimeout(timer.current);window.wsSend?.({type:"wb_leave",channel_id:Number(cfg.channelId),whiteboard_id:Number(cfg.whiteboardId)});window.wbHandleWsMessage=old};
  },[apply]);
  const change=useCallback((elements,appState,files)=>{
    presence.current?.selection(appState.selectedElementIds);
    if(!canEdit)return;
    const scene={format:"excalidraw",version:1,elements:Array.from(elements),appState:{viewBackgroundColor:appState.viewBackgroundColor||"#fff",gridSize:appState.gridSize??null},files:files||{}};
    const raw=sceneFingerprint(scene.elements,scene.appState.viewBackgroundColor);if(raw===last.current)return;last.current=raw;latest.current=scene;setStatus("Unsaved");
    const sendScene=()=>{
      const scene=latest.current;
      const delta=liveSceneDelta(scene,published.current,publishedFiles.current);
      const sent=window.wsSend?.({type:"wb_op",op:"excalidraw_scene",channel_id:Number(cfg.channelId),whiteboard_id:Number(cfg.whiteboardId),scene:delta});
      if(sent){for(const element of delta.elements)published.current.set(element.id,JSON.stringify([element.version,element.versionNonce,!!element.isDeleted]));for(const id of Object.keys(delta.files))publishedFiles.current.add(id);broadcast.current=null;}
      else broadcast.current=setTimeout(sendScene,250);
    };
    if(!broadcast.current)broadcast.current=setTimeout(sendScene,100);
    const save=async()=>{
      if(saving.current){timer.current=setTimeout(save,200);return;}
      saving.current=true;
      const snapshot=latest.current;
      try{await persist(snapshot);if(snapshot===latest.current)setStatus("Saved");}
      catch(e){setStatus(e.message);}
      finally{saving.current=false;}
    };
    clearTimeout(timer.current);timer.current=setTimeout(save,900);
  },[]);
  if(!initial)return React.createElement("div",{style:{padding:"30px",color:"#fff"}},status);
  return React.createElement("div",{style:{height:"100%",position:"relative"}},
    React.createElement(Excalidraw,{initialData:initial,excalidrawAPI:x=>{api.current=x;presence.current?.refresh();},onPointerUpdate:event=>presence.current?.pointer(event),onChange:change,viewModeEnabled:!canEdit,isCollaborating:true,theme}),
    React.createElement("div",{style:{position:"absolute",right:"12px",bottom:"12px",zIndex:10,padding:"5px 9px",borderRadius:"8px",background:"#151923",color:"#cbd5e1",fontSize:"11px"}},status)
  );
}
if(host)createRoot(host).render(React.createElement(App));


