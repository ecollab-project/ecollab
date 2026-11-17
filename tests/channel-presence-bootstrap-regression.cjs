'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom'),Y=require('yjs'),protocol=require('y-protocols/awareness');
const frame=fs.readFileSync('assets/js/collab/yjs-presence.js','utf8').replace(/^import .*;$/gm,'').replace(/export function/g,'function');
const source=fs.readFileSync('assets/js/chat/yjs-channel-presence.js','utf8').replace(/^import .*;$/gm,'').replace(/export function/g,'function');
const dom=new JSDOM('<div class="chat-header"></div><input id="chatInputField">');
const w=dom.window,sent=[];
w.ECOLLAB={userId:1,currentChannelId:20};w.wsSend=packet=>{sent.push(packet);return true;};
const context=vm.createContext({Y,...protocol,window:w,document:w.document,TextEncoder,Uint8Array,setTimeout,clearTimeout,setInterval:()=>1,clearInterval(){}});
try {
  vm.runInContext(frame,context);vm.runInContext(source,context);
  const first=w.EcollabChatPresence;
  const receive=detail=>w.dispatchEvent(new w.CustomEvent('ecollab:chat-presence',{detail}));
  receive({type:'joined_channel',channel_id:20,presence:[]});
  const id=sent.at(-1).client_id;
  vm.runInContext(source,context);
  assert.equal(w.EcollabChatPresence,first,'duplicate/cache-busted module cannot replace a connection-bound client');
  receive({type:'joined_channel',channel_id:20,presence:[]});
  assert.equal(sent.at(-1).client_id,id);
  w.ECOLLAB.currentChannelId=21;
  const count=sent.length;
  receive({type:'joined_channel',channel_id:20,presence:[]});
  assert.equal(sent.length,count,'stale channel acknowledgements are ignored');
  receive({type:'joined_channel',channel_id:21,presence:[]});
  assert.equal(sent.at(-1).channel_id,21);
  console.log('PASS: single channel-presence provider and stale acknowledgement isolation');
} finally {w.EcollabChatPresence?.dispose();w.close();}
