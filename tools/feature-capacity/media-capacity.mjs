import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {monitorEventLoopDelay} from 'node:perf_hooks';
import {createRequire} from 'node:module';
import {chromium} from 'playwright';
import {integer,distribution,evaluateMedia,validateMediaTarget,mediaEncoding} from './common.mjs';
const root=path.dirname(fileURLToPath(import.meta.url)),require=createRequire(import.meta.url);
const target=JSON.parse(fs.readFileSync(process.env.TARGET_FILE||'feature-target.json','utf8'));validateMediaTarget(target);
const users=integer(process.env.VUS,'VUS',2,2,300),ramp=integer(process.env.RAMP_SECONDS,'RAMP_SECONDS',120,1,600),hold=integer(process.env.HOLD_SECONDS,'HOLD_SECONDS',300,10,1800);
const shardCount=integer(process.env.SHARD_COUNT,'SHARD_COUNT',1,1,20),shardIndex=integer(process.env.SHARD_INDEX,'SHARD_INDEX',0,0,shardCount-1);
const quality=process.env.MEDIA_QUALITY||'app-default';
const media=process.env.MEDIA_PROFILE||'voice';if(!['voice','video','screen'].includes(media))throw Error('MEDIA_PROFILE must be voice, video or screen');
if(users%target.room_size===1)throw Error('Every tested room needs at least two participants; choose a different room size or user count');
if(users>target.media.length)throw Error('Not enough unique synthetic media tokens');
if(shardCount>1&&!process.env.START_AT_MS)throw Error('Distributed generators need the same START_AT_MS and synchronized clocks');
const start=integer(process.env.START_AT_MS,'START_AT_MS',Date.now()+10000,Date.now()+1000,Date.now()+3600000);
const holdStart=start+(ramp+10)*1000,holdEnd=holdStart+hold*1000;
const expiry=Math.min(target.expires_at*1000,...target.media.slice(0,users).map(u=>JSON.parse(Buffer.from(u.token.split('.')[1],'base64url').toString()).exp*1000));
if(holdEnd+30000>expiry)throw Error('Media tokens expire before the full hold. Prepare/copy fresh tokens after installing the runner.');
const encoding=mediaEncoding(quality,media);
const selected=target.media.slice(0,users).filter((_,i)=>i%shardCount===shardIndex);
if(!selected.length)throw Error('Empty generator shard');
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,Math.max(0,ms)));
const cpuSnapshot=()=>os.cpus().reduce((a,c)=>({idle:a.idle+c.times.idle,total:a.total+Object.values(c.times).reduce((x,y)=>x+y,0)}),{idle:0,total:0});
let cpuPrevious=cpuSnapshot(),cpuSamples=[],minimumFree=os.freemem();const loop=monitorEventLoopDelay({resolution:20});loop.enable();
const meter=setInterval(()=>{const current=cpuSnapshot(),total=current.total-cpuPrevious.total;if(total>0)cpuSamples.push(100*(1-(current.idle-cpuPrevious.idle)/total));cpuPrevious=current;minimumFree=Math.min(minimumFree,os.freemem());},1000);
const participants=selected.map(u=>({id:u.id,slot:u.slot,joined:false,closed_early:false,error:false,samples:[]}));
let browser;let fullHold=false;let interrupted=false;const contexts=[];
const profile={media,quality,encoding,measurement_policy:'media-stats-3: complete quality coverage; no fabricated loss; audio flow diagnostics',room_size:target.room_size,total_users:users,ramp_seconds:ramp,hold_seconds:hold,video:media==='voice'?null:`VP8 ${encoding.width}x${encoding.height} ${encoding.fps}fps / max${encoding.max_bitrate}bps`,audio:'Opus max32kbps; every participant publishes non-silent synthetic audio',topology:'all-to-all subscriptions within each room',scope:'LiveKit media plane only; application invite/ringing/answer signaling, real capture devices and subjective quality are not measured'};
const report={revision:'feature-capacity-1',kind:'media',run_id:target.run_id,sample_id:target.run_id+'-'+start,fixture_id:target.fixture_id,configured_users:selected.length,total_users:users,shard_count:shardCount,shard_index:shardIndex,start_at_ms:start,hold_start_ms:holdStart,hold_end_ms:holdEnd,profile,participants};
process.once('SIGINT',()=>{interrupted=true;});
try{
  browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_PATH||undefined,args:['--autoplay-policy=no-user-gesture-required','--disable-background-timer-throttling','--disable-renderer-backgrounding','--disable-backgrounding-occluded-windows']});
  const sdk=require.resolve('livekit-client');
  await Promise.all(selected.map(async(u,index)=>{
    const p=participants[index];
    try{
      const context=await browser.newContext();contexts.push(context);const page=await context.newPage();p.page=page;
      await page.addInitScript(()=>{const Base=window.RTCPeerConnection;window.capacityPeerConnections=[];window.RTCPeerConnection=class extends Base{constructor(...args){super(...args);window.capacityPeerConnections.push(this);}};});
      await page.goto('about:blank');await page.addScriptTag({path:sdk});await page.addScriptTag({path:path.join(root,'media-client.js')});
      const group=Math.floor(u.slot/target.room_size),groupSize=Math.min(target.room_size,users-group*target.room_size);
      p.config={url:target.livekit_url,token:u.token,slot:u.slot,media,encoding,publisher:u.slot%target.room_size===0,expected_audio:groupSize-1};
      const scheduled=start+u.slot/(users-1)*ramp*1000;await sleep(scheduled-Date.now());p.launch_skew_ms=Math.max(0,Date.now()-scheduled);
      await page.evaluate(async config=>{await window.startCapacityMedia(config);},p.config);p.joined=true;p.joined_at=Date.now();
      if(p.joined_at>holdStart)p.error=true;
    }catch(_){p.error=true;}
  }));
  await sleep(holdStart-5000-Date.now());
  // Prime RTP deltas before the measured hold.
  await Promise.all(participants.map(async p=>{if(p.joined)await p.page.evaluate(c=>window.sampleCapacityMedia(c),p.config).catch(()=>{p.error=true;});}));
  await sleep(holdStart-Date.now());
  for(let sample=0;sample<Math.floor(hold/5);sample++){
    if(interrupted)break;
    await sleep(holdStart+(sample+1)*5000-Date.now());
    await Promise.all(participants.map(async p=>{
      if(!p.joined)return;
      try{const s=await p.page.evaluate(c=>window.sampleCapacityMedia(c),p.config);if(s.closed_early)p.closed_early=true;if(s.error)p.error=true;p.samples.push(s);}catch(_){p.error=true;}
    }));
    console.log(media+': '+Math.min(hold,(sample+1)*5)+'/'+hold+'s hold; generator '+(shardIndex+1)+'/'+shardCount+'; '+participants.filter(p=>p.joined&&!p.error&&!p.closed_early).length+'/'+selected.length+' joined');
  }
  if(!interrupted){await sleep(holdEnd-Date.now());fullHold=Date.now()>=holdEnd;}
}catch(_){interrupted=true;console.error('Media runner failed; inspect the private report and generator setup. Tokens are not logged.');}
finally{
  clearInterval(meter);loop.disable();
  await Promise.all(participants.map(async p=>{if(p.page)await p.page.evaluate(()=>window.closeCapacityMedia()).catch(()=>{});delete p.page;delete p.config;}));
  await browser?.close();report.full_hold=fullHold&&!interrupted;
  report.generator={cpu_p95:distribution(cpuSamples).p95,cpu_max:distribution(cpuSamples).max,event_loop_p95_ms:loop.percentile(95)/1000000,minimum_free_bytes:minimumFree};
  Object.assign(report,evaluateMedia(report));
  if(participants.some(p=>(p.launch_skew_ms??Infinity)>5000)){report.capacity_pass=false;report.failed_thresholds.push('generator join launch skew over 5s');}
  if(shardCount>1){report.shard_pass=report.capacity_pass;report.capacity_pass=false;report.requires_aggregation=true;}
  const filename=process.env.REPORT_FILE||'results-media-'+media+'-'+users+'-shard-'+shardIndex+'.json';
  report.process_exit_code=!report.capacity_pass&&!(shardCount>1&&report.shard_pass)?1:0;
  fs.writeFileSync(filename,JSON.stringify(report,null,2),{mode:0o600});
  console.log('Media test ended: '+(report.capacity_pass?'workload passed':shardCount>1&&report.shard_pass?'shard complete; aggregate ALL shards':'capacity NOT established')+'. Report: '+filename);
  if(!report.capacity_pass&&!(shardCount>1&&report.shard_pass))process.exitCode=1;
}

