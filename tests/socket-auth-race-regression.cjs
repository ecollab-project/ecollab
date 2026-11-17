'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('assets/js/chat/socket-core.js','utf8');
const sockets=[],timers=[];let resolveFetch,requests=0,fail=false;
class Socket {
  static CONNECTING=0;static OPEN=1;
  constructor(){this.readyState=0;this.sent=[];sockets.push(this);}
  send(value){this.sent.push(JSON.parse(value));}
}
const context=vm.createContext({WebSocket:Socket,console,Promise,Map,Set,
  window:{ECOLLAB:{baseUrl:'https://ecollab.test',wsUrl:'wss://ecollab.test/ws'},addEventListener(){},dispatchEvent(){}},
  document:{readyState:'loading',addEventListener(){}},
  fetch:()=>{requests++;return fail?Promise.reject(Error('offline')):new Promise(resolve=>{resolveFetch=resolve;});},
  setTimeout:fn=>{timers.push(fn);return timers.length;},clearTimeout(){},setInterval(){},clearInterval(){},CustomEvent:class{}});
vm.runInContext(source,context);
(async()=>{
  const first=context.connectWebSocket();
  context.initWebSocket();context.connectWebSocket();
  assert.equal(requests,1,'all boot callers share one token request');
  assert.equal(sockets.length,0,'no socket opens before token fetch finishes');
  resolveFetch({ok:true,json:async()=>({token:'fresh-token'})});await first;
  assert.equal(sockets.length,1);
  sockets[0].readyState=1;sockets[0].onopen();
  assert.deepEqual(sockets[0].sent,[{type:'auth',ws_token:'fresh-token'}]);
  context.initWebSocket();assert.equal(sockets.length,1,'legacy init cannot create another connection');
  sockets[0].onclose({code:1006});fail=true;await context.connectWebSocket();
  assert.equal(sockets.length,1,'failed token acquisition never reuses an old token');
  console.log('PASS: asynchronous startup, legacy init race, single-flight token fetch and fresh-token reconnect');
})().catch(error=>{console.error(error);process.exitCode=1;});
