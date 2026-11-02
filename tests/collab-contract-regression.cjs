'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {JSDOM} = require('jsdom');
const source = fs.readFileSync('assets/js/chat/collab-tools.js', 'utf8');
function setup() {
  const dom = new JSDOM('<div id="collabPane_code"></div><div id="collabPane_calendar"></div>', {runScripts:'outside-only'});
  const w = dom.window;
  w.ECOLLAB = {baseUrl:'/ecollab', currentChannelId:7, csrfToken:'token'};
  w.showToast = () => {};
  w.eval(source+';window.collabFetch=collabFetch;window.loadCalendar=loadCalendar;window.loadCode=loadCode;window.setCalendarMonth=(year,month)=>{_calYear=year;_calMonth=month;};');
  return {dom,w};
}
for (const action of ['get','results']) test('quiz '+action+' transmits quiz ID as a GET parameter', async () => {
  const {dom,w}=setup();
  try {
    let request;
    w.fetch=async (url,opts)=>{request={url,opts};return {ok:true,json:async()=>({ok:true})};};
    await w.collabFetch('quiz',action,{quiz_id:42},'GET');
    const url=new URL(request.url,'https://ecollab.test');
    assert.equal(url.searchParams.get('quiz_id'),'42');
    assert.equal(url.searchParams.get('channel_id'),'7');
    assert.equal(request.opts.body,undefined);
  } finally {dom.window.close();}
});
test('calendar retains the selected date range',async()=>{
  const {dom,w}=setup();
  try {
    let request;
    w.fetch=async url=>{request=new URL(url,'https://ecollab.test');return {ok:true,json:async()=>({ok:true,events:[]})};};
    w.setCalendarMonth(2026,10);
    await w.loadCalendar();
    assert.equal(request.searchParams.get('action'),'list');
    assert.equal(request.searchParams.get('from'),'2026-11-01');
    assert.equal(request.searchParams.get('to'),'2026-11-30');
  }finally{dom.window.close();}
});
test('HTTP failure cannot be reported as successful collaboration response',async()=>{
  const {dom,w}=setup();
  try {
    w.fetch=async()=>({ok:false,status:503,json:async()=>({ok:true})});
    await assert.rejects(w.collabFetch('code','get',{},'GET'),/Request failed/);
  }finally{dom.window.close();}
});
test('first snippet save supplies its returned ID to subsequent run logging',async()=>{
  const {dom,w}=setup();
  try {
    const requests=[];
    w.fetch=async(url,opts)=>{
      const action=new URL(url,'https://ecollab.test').searchParams.get('action');
      requests.push({action,body:opts.body&&JSON.parse(opts.body)});
      const payload=action==='get'?{snippet:{id:null,version:0,code:'console.log("hello")'}}:action==='save'?{snippet_id:81,version:1}:{};
      return {ok:true,json:async()=>({ok:true,...payload})};
    };
    await w.loadCode();
    await w.saveCode();
    await w.runCode();
    assert.equal(requests.find(r=>r.action==='log_run')?.body.snippet_id,81);
  }finally{dom.window.close();}
});
test('a channel change cannot save the previous channel editor into the new channel',async()=>{
 const {dom,w}=setup();
 try{
  let saves=0;
  w.fetch=async(url)=>{const action=new URL(url,'https://ecollab.test').searchParams.get('action');if(action==='save')saves++;return {ok:true,json:async()=>({ok:true,snippet:{id:1,version:1,code:'private old channel'},snippet_id:1,version:2})};};
  await w.loadCode();w.ECOLLAB.currentChannelId=8;w.document.dispatchEvent(new w.Event('channelSwitched'));
  await w.saveCode();
  assert.equal(saves,0,'Old editor must not write into the newly selected channel');
 }finally{w.close();}
});
test('a late snippet load cannot replace the newly selected channel editor',async()=>{
 const {dom,w}=setup();
 try{
  const pending=[];w.fetch=url=>new Promise(resolve=>pending.push({url,resolve}));
  const old=w.loadCode();w.ECOLLAB.currentChannelId=8;const current=w.loadCode();
  pending[1].resolve({ok:true,json:async()=>({ok:true,snippet:{id:8,version:1,title:'Channel 8',code:'new'}})});await current;
  pending[0].resolve({ok:true,json:async()=>({ok:true,snippet:{id:7,version:1,title:'Channel 7',code:'old'}})});await old;
  assert.equal(w.document.getElementById('codeTitle').value,'Channel 8');
 }finally{w.close();}
});
