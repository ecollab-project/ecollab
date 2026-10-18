export function integer(raw,name,fallback,min,max){const value=Number(raw??fallback);if(!Number.isSafeInteger(value)||value<min||value>max)throw Error(name+' must be '+min+'..'+max);return value;}
export function percentile(samples,p){if(!samples.length)return null;const a=[...samples].sort((a,b)=>a-b);return a[Math.min(a.length-1,Math.ceil(a.length*p)-1)];}
export function distribution(samples){return {count:samples.length,avg:samples.length?samples.reduce((a,b)=>a+b,0)/samples.length:null,p95:percentile(samples,.95),max:samples.length?Math.max(...samples):null};}
export function validateMediaTarget(target){
  if(target.mode!=='media'||!/^capacity-\d{8}-\d{6}-[a-f0-9]{8}$/.test(target.run_id||'')||!/^k6-\d{8}-\d{6}-[a-f0-9]{8}$/.test(target.fixture_id||''))throw Error('Use a prepared media fixture');
  const url=new URL(target.livekit_url);if(url.protocol!=='wss:'&&!(url.protocol==='ws:'&&['127.0.0.1','localhost'].includes(url.hostname)))throw Error('LiveKit requires WSS');
  if(url.username||url.password||url.hash||url.search)throw Error('Invalid LiveKit URL');
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
  for(const p of all){
    let ok=p.joined&&p.closed_early!==true&&p.error!==true;
    if((p.samples||[]).length<Math.floor(profile.hold_seconds/5)*.8)ok=false;
    for(const s of p.samples||[]){
      if(!s.audio_alive)ok=false;
      if(profile.media!=='voice'&&!s.video_alive)ok=false;
      if(s.max_jitter_ms!=null)jitter.push(s.max_jitter_ms);else ok=false;
      if(s.max_loss_pct!=null)loss.push(s.max_loss_pct);else ok=false;
      if(s.rtt_ms!=null)rtt.push(s.rtt_ms);else ok=false;
      if(s.min_audio_kbps!=null)audio.push(s.min_audio_kbps);
      if(s.min_video_fps!=null)video.push(s.min_video_fps);
    }
    if(ok)good++;
  }
  if(good<Math.ceil(report.configured_users*.99))failed.push('media continuity below 99%');
  if((percentile(jitter,.95)??Infinity)>30)failed.push('jitter p95 over 30ms');
  if((percentile(loss,.95)??Infinity)>2)failed.push('packet loss p95 over 2%');
  if((percentile(rtt,.95)??Infinity)>300)failed.push('RTT p95 over 300ms');
  return {capacity_pass:failed.length===0,failed_thresholds:failed,healthy_participants:good,jitter_ms:distribution(jitter),packet_loss_pct:distribution(loss),rtt_ms:distribution(rtt),audio_kbps:distribution(audio),video_fps:distribution(video)};
}
