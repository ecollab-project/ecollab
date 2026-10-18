import http from 'k6/http';
import {sleep, check} from 'k6';
import exec from 'k6/execution';
import {SharedArray} from 'k6/data';
import {Counter, Rate, Trend} from 'k6/metrics';
import {number, validateAccounts} from './plan.js';
const target=JSON.parse(open(__ENV.TARGET_FILE||'./feature-target.json'));
if(!/^capacity-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$/.test(target.run_id||'')||!['ai','code-run','code-grade'].includes(target.mode))throw new Error('Prepare a feature-capacity fixture first');
if(!/^https:\/\/[a-z0-9.:-]+\/?$/i.test(target.base_url||'')&&!/^http:\/\/(localhost|127\.0\.0\.1):[0-9]+$/.test(target.base_url||''))throw new Error('Invalid HTTPS target');
const accounts=new SharedArray('feature accounts',()=>{const a=JSON.parse(open('./accounts.json'));validateAccounts(a,target);return a;});
const programs=JSON.parse(open('./programs.json')), languages=['c','cpp','python','java'];
const users=number(__ENV,'VUS',2,2,300);if(users>accounts.length)throw new Error('Each user requires a distinct synthetic account');
const ramp=number(__ENV,'RAMP_SECONDS',120,5,600),deadline=number(__ENV,'DEADLINE_SECONDS',180,10,600);
const slo=number(__ENV,'P95_LIMIT_MS',target.mode==='ai'?30000:target.mode==='code-grade'?60000:30000,100,600000);
const turns=number(__ENV,'AI_TURNS',2,1,3),poll=number(__ENV,'POLL_SECONDS',2,1,10);
const auth=new Rate('feature_auth_ok'),completed=new Rate('feature_completed_ok'),correct=new Rate('feature_correct_ok');
const attempted=new Counter('feature_attempted'),finished=new Counter('feature_finished'),rejected=new Counter('feature_rejected');
const latency=new Trend('feature_completion_ms',true),skew=new Trend('feature_launch_skew_ms',true),queueWait=new Trend('feature_queue_observed_ms',true);
export const options={summaryTrendStats:['avg','med','p(95)','p(99)','max'],noCookiesReset:true,
  scenarios:{feature:{executor:'per-vu-iterations',vus:users,iterations:1,maxDuration:(ramp+deadline+30)+'s'}},
  thresholds:{feature_auth_ok:['rate==1'],feature_completed_ok:['rate>=0.99'],feature_correct_ok:['rate>=0.99'],feature_finished:['count>='+Math.ceil(users*.99)],feature_completion_ms:['p(95)<'+slo],feature_launch_skew_ms:['max<5000']},
  systemTags:['status','method','name','scenario','expected_response','check']};
export function setup(){return {launch_at:Date.now()+ramp*1000};}
function request(path,name,csrf='',body=null,method=body===null?'GET':'POST'){
  const response=http.request(method,target.base_url.replace(/\/$/,'')+path,body===null?null:JSON.stringify(body),{timeout:'120s',redirects:0,tags:{name},headers:{'Content-Type':'application/json','X-CSRF-Token':csrf}});
  let data={};try{data=response.json();}catch(_){}
  return {status:response.status,data,ok:response.status>=200&&response.status<300&&data.success!==false&&!data.error};
}
function login(account){
  let r=request('/API/auth/csrf-token.php','csrf');let csrf=r.data.token||'';
  r=request('/API/auth/login.php','login',csrf,{identifier:account.email,password:account.password,remember:false});
  if(!r.ok||r.data.success!==true||r.data.otp_required)return null;
  r=request('/API/auth/csrf-token.php','csrf');return r.ok&&typeof r.data.token==='string'&&r.data.token?r.data.token:null;
}
function reject(status){rejected.add(1,{status:String(status||0)});}
export default function(config){
  const account=accounts[exec.vu.idInTest-1];let csrf,sessionId=null;let success=false,valid=false,started=null;
  try{
    csrf=login(account);auth.add(Boolean(csrf));if(!csrf){reject('auth');return;}
    let prepared;
    const language=languages[(exec.vu.idInTest-1)%languages.length];
    if(target.mode==='ai'){
      prepared=request('/API/ai/sessions.php','ai-session',csrf,{title:target.run_id});
      sessionId=prepared.data.session?.id;if(!prepared.ok||!sessionId){reject(prepared.status);return;}
    }else if(target.mode==='code-grade'){
      const tid=target.task_ids?.[users+':'+language];if(!Number.isSafeInteger(tid)||tid<1)throw new Error('Missing grading fixture');
      prepared=request('/API/code-lab/index.php','attempt-start',csrf,{action:'start',server_id:target.server_id,task_id:tid});
      if(!prepared.ok||prepared.data.data?.attempt?.state!=='in_progress'){reject(prepared.status);return;}
    }
    const wait=config.launch_at-Date.now();if(wait>0)sleep(wait/1000);
    skew.add(Math.max(0,Date.now()-config.launch_at));attempted.add(1);started=Date.now();
    if(target.mode==='ai'){
      const fact='CAPACITY_'+account.id;let r;
      for(let turn=0;turn<turns;turn++){
        const prompt=turn===0?target.run_id+' Synthetic study test. My study label is '+fact+'. Reply with exactly that label.':'What study label did I specify earlier? Reply with only that exact label.';
        r=request('/API/ai/message.php','ai-message',csrf,{session_id:sessionId,prompt});
        if(!r.ok||typeof r.data.message?.content!=='string'||!new RegExp('\\b'+fact+'\\b').test(r.data.message.content)){reject(r.status);return;}
      }
      if(Date.now()-started>deadline*1000){reject('deadline');return;}
      success=true;valid=true;
    }else{
      let r;
      if(target.mode==='code-run')r=request('/API/code-lab/index.php','code-run',csrf,{action:'run',server_id:target.server_id,language,code:(language==='python'?'# '+target.run_id+'\n':'/* '+target.run_id+' */\n')+programs[language],stdin:'1000\n'});
      else r=request('/API/code-lab/index.php','code-submit',csrf,{action:'submit',server_id:target.server_id,task_id:target.task_ids[users+':'+language],code:(language==='python'?'# '+target.run_id+'\n':'/* '+target.run_id+' */\n')+programs[language],version:Number(prepared.data.data.attempt.version)});
      if(!r.ok){reject(r.status);return;}
      const id=target.mode==='code-run'?r.data.data?.job_id:r.data.data?.grade_job?.id;
      if(!id){reject('missing-job');return;}
      let firstRunning=false;
      while(Date.now()-started<deadline*1000){
        r=request('/API/code-lab/index.php?action=job&server_id='+target.server_id+'&job_id='+id,'code-job',csrf);
        const job=r.data.data;
        if(!r.ok||!job){reject(r.status);return;}
        if(!firstRunning&&job.state!=='queued'){queueWait.add(Date.now()-started);firstRunning=true;}
        if(job.state==='failed'){reject('worker');return;}
        if(job.state==='done'){
          if(Date.now()-started>deadline*1000){reject('deadline');return;}
          success=true;valid=target.mode==='code-run'?job.result?.status==='Finished'&&String(job.result.stdout||'').trim()==='168':Number(job.result?.score)===100&&Number(job.result?.total)===2&&Number(job.result?.passed)===2;
          break;
        }
        sleep(poll);
      }
      if(!success)reject('deadline');
    }
  }catch(_){reject('runner-error');}
  finally{
    completed.add(success);correct.add(success&&valid);
    if(success){finished.add(1);latency.add(Date.now()-started);}
    if(sessionId&&csrf)request('/API/ai/session.php','ai-session-cleanup',csrf,{session_id:sessionId},'DELETE');
  }
}
export function handleSummary(data){
  const values=name=>data.metrics[name]?.values||{};let failed=[];
  for(const[name,m]of Object.entries(data.metrics))for(const[t,v]of Object.entries(m.thresholds||{}))if(!v.ok)failed.push(name+': '+t);
  const completedCount=Number(values('feature_finished').count||0),attemptedCount=Number(values('feature_attempted').count||0);
  const whole=Number(values('iterations').count||0)===users&&attemptedCount===users;
  const passed=whole&&completedCount>=Math.ceil(users*.99)&&!failed.length;
  const report={revision:'feature-capacity-1',kind:target.mode,run_id:target.run_id,sample_id:target.run_id+'-'+Date.now(),fixture_id:target.fixture_id,configured_users:users,profile:{ramp_seconds:ramp,deadline_seconds:deadline,p95_limit_ms:slo,ai_turns:target.mode==='ai'?turns:null,program:'prime-sieve-1000',grading_cases:target.mode==='code-grade'?2:null},configured_provider:target.configured_provider||null,full_workload_completed:whole,capacity_pass:passed,attempted:attemptedCount,finished:completedCount,completed:values('feature_completed_ok'),correct:values('feature_correct_ok'),authentication:values('feature_auth_ok'),rejections:values('feature_rejected'),completion_ms:values('feature_completion_ms'),observed_queue_ms:values('feature_queue_observed_ms'),launch_skew_ms:values('feature_launch_skew_ms'),http:values('http_req_duration'),requests:values('http_reqs'),failed_thresholds:failed,scope:target.mode==='ai'?'Authenticated Jarred session API; fixed synthetic fact recall, not general accuracy.':'Authenticated Code Lab API including queue wait and real compiler/grader completion.'};
  return {[__ENV.REPORT_FILE||'results-'+target.mode+'-'+users+'.json']:JSON.stringify(report,null,2),stdout:'\n'+target.mode+' '+users+' users: '+(passed?'workload passed':'capacity NOT established')+'. Inspect JSON and require exit code 0.\n'};
}
