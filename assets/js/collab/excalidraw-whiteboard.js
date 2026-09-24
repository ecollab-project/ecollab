import React, { useCallback, useEffect, useRef, useState } from "https://esm.sh/react@19";
import { createRoot } from "https://esm.sh/react-dom@19/client";
import { Excalidraw } from "https://esm.sh/@excalidraw/excalidraw@0.18.0?external=react,react-dom";

const cfg = window.ECOLLAB_EXCALIDRAW || {};
const host = document.getElementById("excalidraw-root");
const canEdit = cfg.permission === "edit";
const endpoint = `${window.ECOLLAB?.baseUrl || ""}/API/collaboration/whiteboards.php?workspace_id=${encodeURIComponent(cfg.workspaceId)}&whiteboard_id=${encodeURIComponent(cfg.whiteboardId)}`;

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
  const api=useRef(null), remote=useRef(false), timer=useRef(null), last=useRef("");
  const [initial,setInitial]=useState(null), [status,setStatus]=useState("Loading…");
  useEffect(()=>{loadBoard().then(s=>{last.current=JSON.stringify(s);setInitial(s);setStatus(canEdit?"Saved":"View only")}).catch(e=>setStatus(e.message))},[]);
  const apply=useCallback(msg=>{
    if(Number(msg.whiteboard_id)!==Number(cfg.whiteboardId))return;
    let scene=null;
    if(msg.type==="wb_joined"&&msg.state_json){try{scene=JSON.parse(msg.state_json)}catch{}}
    if(msg.type==="wb_state"&&msg.state_json){try{scene=JSON.parse(msg.state_json)}catch{}}
    if(msg.type==="wb_op"&&msg.op==="excalidraw_scene")scene=msg.scene;
    if(!scene||!Array.isArray(scene.elements)||!api.current)return;
    remote.current=true;
    api.current.updateScene({elements:scene.elements,appState:scene.appState||{}});
    if(scene.files&&api.current.addFiles)api.current.addFiles(Object.values(scene.files));
    last.current=JSON.stringify(scene);
    queueMicrotask(()=>remote.current=false);
  },[]);
  useEffect(()=>{
    const old=window.wbHandleWsMessage;
    window.wbHandleWsMessage=msg=>{apply(msg);if(typeof old==="function")old(msg)};
    window.wbRejoinRoom=()=>window.wsSend?.({type:"wb_join",channel_id:Number(cfg.channelId),whiteboard_id:Number(cfg.whiteboardId)});
    const join=setInterval(()=>{if(window.wsSend?.({type:"wb_join",channel_id:Number(cfg.channelId),whiteboard_id:Number(cfg.whiteboardId)}))clearInterval(join)},500);
    return()=>{clearInterval(join);window.wsSend?.({type:"wb_leave",channel_id:Number(cfg.channelId),whiteboard_id:Number(cfg.whiteboardId)});window.wbHandleWsMessage=old};
  },[apply]);
  const change=useCallback((elements,appState,files)=>{
    if(!canEdit||remote.current)return;
    const scene={format:"excalidraw",version:1,elements:Array.from(elements),appState:{viewBackgroundColor:appState.viewBackgroundColor||"#fff",gridSize:appState.gridSize??null},files:files||{}};
    const raw=JSON.stringify(scene);if(raw===last.current)return;last.current=raw;setStatus("Unsaved");
    window.wsSend?.({type:"wb_op",op:"excalidraw_scene",channel_id:Number(cfg.channelId),whiteboard_id:Number(cfg.whiteboardId),scene});
    clearTimeout(timer.current);timer.current=setTimeout(()=>persist(scene).then(()=>setStatus("Saved")).catch(e=>setStatus(e.message)),900);
  },[]);
  if(!initial)return React.createElement("div",{style:{padding:"30px",color:"#fff"}},status);
  return React.createElement("div",{style:{height:"100%",position:"relative"}},
    React.createElement(Excalidraw,{initialData:initial,excalidrawAPI:x=>api.current=x,onChange:change,viewModeEnabled:!canEdit,isCollaborating:true}),
    React.createElement("div",{style:{position:"absolute",right:"12px",bottom:"12px",zIndex:10,padding:"5px 9px",borderRadius:"8px",background:"#151923",color:"#cbd5e1",fontSize:"11px"}},status)
  );
}
if(host)createRoot(host).render(React.createElement(App));
