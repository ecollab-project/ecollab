export function integer(raw,name,fallback,min,max){const value=Number(raw??fallback);if(!Number.isSafeInteger(value)||value<min||value>max)throw Error(name+' must be '+min+'..'+max);return value;}
export function percentile(samples,p){if(!samples.length)return null;const a=[...samples].sort((a,b)=>a-b);return a[Math.min(a.length-1,Math.ceil(a.length*p)-1)];}
export function distribution(samples){return {count:samples.length,avg:samples.length?samples.reduce((a,b)=>a+b,0)/samples.length:null,p95:percentile(samples,.95),max:samples.length?Math.max(...samples):null};}
export function validateMediaTarget(target){
  if(target.mode!=='media'||!/^capacity-\d{8}-\d{6}-[a-f0-9]{8}$/.test(target.run_id||'')||!/^k6-\d{8}-\d{6}-[a-f0-9]{8}$/.test(target.fixture_id||''))throw Error('Use a prepared media fixture');
  const url=new URL(target.livekit_url);if(url.protocol!=='wss:'&&!(url.protocol==='ws:'&&['127.0.0.1','localhost'].includes(url.hostname)))throw Error('LiveKit requires WSS');
  if(url.username||url.password||url.hash||url.search)throw Error('Invalid LiveKit URL');
  if(!Number.isSafeInteger(target.room_size)||target.room_size<2||target.room_size>50)throw Error('Room size must be 2..50');
  const ids=new Set();if(!Array.isArray(target.media))throw Error('Missing media tokens');
  for(const [index,u]of target.media.entries()){
    if(!Number.isSafeInteger(u.id)||u.id<1||ids.has(u.id)||u.slot!==index||typeof u.token!=='string'||!u.room.startsWith('ecollab-'+target.run_id+'-'))throw Error('Invalid media account');ids.add(u.id);
    const claims=JSON.parse(Buffer.from(u.token.split('.')[1]||'','base64url').toString());
    if(claims.sub!=='user-'+u.id||claims.video?.room!==u.room||!claims.exp)throw Error('Token does not match the synthetic participant');
  }
}
export function evaluateMedia(report){
  const failed=[];const all=report.participants||[];const profile=report.profile||{};
  if(all.length!==report.configured_users)failed.push('participant coverage');
  if(new Set(all.map(p=>p.id)).size!==all.length)failed.push('duplicate participants');
  if(report.full_hold!==true)failed.push('full hold not confirmed');
  if(report.generator?.cpu_p95==null||report.generator.cpu_p95>85)failed.push('generator CPU saturation or missing measurement');
  if((report.generator?.event_loop_p95_ms??Infinity)>100)failed.push('generator event loop overloaded');
  if((report.generator?.minimum_free_bytes??0)<268435456)failed.push('generator memory pressure');
  let good=0;let audio=[];let video=[];let jitter=[];let loss=[];let rtt=[];
  const expectedSamples=Math.floor(profile.hold_seconds/5);
  const coverage={expected_samples:expectedSamples*report.configured_users,observed_samples:0,jitter_samples:0,loss_samples:0,rtt_samples:0,participants:[]};
  const measured=(value,max=Infinity)=>Number.isFinite(value)&&value>=0&&value<=max;
  for(const p of all){
    let ok=p.joined&&p.closed_early!==true&&p.error!==true;
    if((p.samples||[]).length<Math.floor(profile.hold_seconds/5)*.8)ok=false;
    const metrics={slot:p.slot,samples:(p.samples||[]).length,jitter_samples:0,loss_samples:0,rtt_samples:0};
    for(const s of p.samples||[]){
      if(!s.audio_alive)ok=false;
      if(profile.media!=='voice'&&!s.video_alive)ok=false;
      if(s.error||s.closed_early)ok=false;
      if(measured(s.max_jitter_ms)){jitter.push(s.max_jitter_ms);metrics.jitter_samples++;}
      if(measured(s.max_loss_pct,100)){loss.push(s.max_loss_pct);metrics.loss_samples++;}
      if(measured(s.rtt_ms)){rtt.push(s.rtt_ms);metrics.rtt_samples++;}
      if(s.min_audio_kbps!=null)audio.push(s.min_audio_kbps);
      if(s.min_video_fps!=null)video.push(s.min_video_fps);
    }
    coverage.observed_samples+=metrics.samples;
    for(const key of['jitter_samples','loss_samples','rtt_samples'])coverage[key]+=metrics[key];
    coverage.participants.push(metrics);
    if(ok)good++;
  }
  if(good<Math.ceil(report.configured_users*.99))failed.push('media continuity below 99%');
  // Missing telemetry fails coverage, never masquerades as a dropped audio track.
  // Every scheduled participant sample must still carry each quality reading.
  for(const[key,label]of[['jitter_samples','jitter'],['loss_samples','packet loss'],['rtt_samples','RTT']]){
    if(coverage.participants.some(p=>p.samples!==expectedSamples||p[key]!==expectedSamples))failed.push(label+' measurement coverage incomplete');
  }
  if(jitter.length&&percentile(jitter,.95)>30)failed.push('jitter p95 over 30ms');
  if(loss.length&&percentile(loss,.95)>2)failed.push('packet loss p95 over 2%');
  if(rtt.length&&percentile(rtt,.95)>300)failed.push('RTT p95 over 300ms');
  return {capacity_pass:failed.length===0,failed_thresholds:failed,healthy_participants:good,measurement_coverage:coverage,jitter_ms:distribution(jitter),packet_loss_pct:distribution(loss),rtt_ms:distribution(rtt),audio_kbps:distribution(audio),video_fps:distribution(video)};
}

export function mediaEncoding(quality,media){
  if(!['app-default','economy'].includes(quality))throw Error('MEDIA_QUALITY must be app-default or economy');
  const screen=media==='screen',economy=quality==='economy';
  return {width:screen?(economy?1280:1920):(economy?640:1280),height:screen?(economy?720:1080):(economy?360:720),fps:economy?(screen?5:15):30,max_bitrate:screen?(economy?2000000:6000000):(economy?400000:1700000),min_fps:economy?(screen?2:8):15};
}

