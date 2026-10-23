const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),cp=require('node:child_process'),{performance}=require('node:perf_hooks');
const {JSDOM}=require('../tools/chat-delivery/node_modules/jsdom');
const baseline='9d5ded1af8ee55364fa87d9c9a05327ffb9193c9';
const old=cp.execFileSync('git',['show',baseline+':assets/js/student/dashboard.js'],{encoding:'utf8'}),current=fs.readFileSync('assets/js/student/dashboard.js','utf8');
const section=source=>source.slice(source.indexOf('const dashboardLive='),source.indexOf('// ═══ DASHBOARD → CHAT'));
const items=Array.from({length:100},(_,i)=>({id:i+1,name:'Server '+i,description:'Synthetic description',tags:'study',tag_labels:'Study, Database',member_count:30,online_count:4,people_count:20,channel_count:5,server_role:'member'}));
const files=items.slice(0,20).map(s=>({id:s.id,file_name:'fixture'+s.id,file_path:'/synthetic/'+s.id,mime_type:'text/plain',server_name:s.name,uploader:'Fixture',file_size:1024}));
async function run(source,isNew){
 const dom=new JSDOM('<div class="dash-header"></div><div id="serverList"></div><div id="coursesPageList"></div><div id="filesList"></div><canvas id="actChart"></canvas>',{url:'https://ecollab.test'}),w=dom.window;
 let hidden=false,fetches=0,interval,visibilityListeners=0,updates=0,release;
 Object.defineProperty(w.document,'hidden',{get:()=>hidden});
 const originalAdd=w.document.addEventListener.bind(w.document);w.document.addEventListener=(event,...rest)=>{if(event==='visibilitychange')visibilityListeners++;return originalAdd(event,...rest);};
 const context=vm.createContext({window:w,document:w.document,console,URL,Date,setInterval:fn=>(interval=fn,1),clearInterval:()=>{},openModal:()=>{},joinServer:()=>{},fetch:async()=>{fetches++;if(release)return await new Promise(r=>release=r);return{ok:true,status:200,json:async()=>({success:true,role:'student',data:{recommended_servers:items,courses:items,files,activity_chart:[1,2,3,4,5,6,7]}})};}});
 w.document.querySelector('canvas')._c={data:{datasets:[{data:[0,0,0,0,0,0,0]}]},update:()=>updates++};vm.runInContext(section(source),context);
 const render=()=>{context.renderLiveServers(items);context.renderLiveCourses(items);context.renderLiveFiles(files);};render();
 const snapshotBytes=Buffer.byteLength(JSON.stringify(items))*2+Buffer.byteLength(JSON.stringify(files));
 const first=w.document.querySelector('#serverList').firstElementChild;first.querySelector('button').focus();
 let changes=0;const observer=new w.MutationObserver(records=>changes+=records.length);for(const id of ['serverList','coursesPageList','filesList'])observer.observe(w.document.getElementById(id),{childList:true});
 const start=performance.now();for(let i=0;i<20;i++)render();const elapsed=performance.now()-start;await Promise.resolve();observer.disconnect();
 if(isNew){assert.equal(changes,0);assert.equal(w.document.querySelector('#serverList').firstElementChild,first);assert.equal(w.document.activeElement,first.querySelector('button'));}
 const changed=items.map(x=>({...x}));changed[0].name='Updated';context.renderLiveServers(changed);assert.equal(w.document.querySelector('.srv-name').textContent,'Updated');context.renderLiveServers([]);assert.equal(w.document.querySelector('#serverList').children.length,0);context.renderLiveServers(items);
 await context.refreshStudentDashboardLive();await context.refreshStudentDashboardLive();if(isNew)assert.equal(updates,1,'unchanged chart does not update');
 hidden=true;const before=fetches;for(let i=0;i<4;i++)await context.refreshStudentDashboardLive();const hiddenFetches=fetches-before;if(isNew)assert.equal(hiddenFetches,0);
 hidden=false;w.document.dispatchEvent(new w.Event('visibilitychange'));context.startStudentDashboardLive();context.startStudentDashboardLive();await new Promise(r=>setImmediate(r));if(isNew)assert.equal(visibilityListeners,1);
 // Shared pending refresh remains deduplicated.
 release=()=>{};const count=fetches;const pending=context.refreshStudentDashboardLive();await context.refreshStudentDashboardLive();assert.equal(fetches,count+1);release({ok:false,status:503});await pending;
 const html=['serverList','coursesPageList','filesList'].map(id=>w.document.getElementById(id).innerHTML);
 w.close();return{unchanged_refreshes:20,top_level_mutation_records:changes,render_ms:elapsed,hidden_interval_requests:hiddenFetches,visibility_listeners:visibilityListeners,serialized_snapshot_utf8_bytes:isNew?snapshotBytes:0,html};
}
(async()=>{const before=await run(old,false),after=await run(current,true);assert.deepEqual(after.html,before.html,'list content/actions remain equivalent');delete before.html;delete after.html;const evidence={scope:'JSDOM/VM, synthetic100 servers+100 courses+20files; not layout/paint/mobile timing',baseline,before,after};console.log(JSON.stringify(evidence,null,2));if(process.env.APPLICATION_BROWSER_EVIDENCE)fs.writeFileSync(process.env.APPLICATION_BROWSER_EVIDENCE,JSON.stringify(evidence,null,2)+'\n');console.log('PASS list equivalence, changed/empty results, retained nodes/focus, hidden-tab pause, chart/request/listener deduplication');})().catch(e=>{console.error(e);process.exit(1);});
