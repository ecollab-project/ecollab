/* Runs inside each real Chromium tab. Synthetic sources use real RTP codecs/transports. */
async function startCapacityMedia(config) {
  const {Room, RoomEvent, Track}=window.LivekitClient;
  const state=window.capacityMedia={room:null,closing:false,closed_early:false,error:false,previous:new Map(),timers:[],audioContext:null};
  const room=state.room=new Room({adaptiveStream:false,dynacast:false});
  room.on(RoomEvent.Disconnected,()=>{if(!state.closing)state.closed_early=true;});
  room.on(RoomEvent.Reconnecting,()=>{state.closed_early=true;});
  room.on(RoomEvent.TrackSubscriptionFailed,()=>{state.error=true;});
  room.on(RoomEvent.TrackSubscribed,track=>{
    const element=track.attach();element.autoplay=true;element.muted=true;
    element.style.cssText='width:16px;height:16px';document.body.append(element);
    element.play().catch(()=>{state.error=true;});
  });
  await room.connect(config.url,config.token,{autoSubscribe:true});
  const audio=state.audioContext=new AudioContext({sampleRate:48000});await audio.resume();
  const oscillator=audio.createOscillator(),gain=audio.createGain(),destination=audio.createMediaStreamDestination();
  oscillator.frequency.value=240+config.slot%300;gain.gain.value=.1;oscillator.connect(gain);gain.connect(destination);oscillator.start();
  await room.localParticipant.publishTrack(destination.stream.getAudioTracks()[0],{source:Track.Source.Microphone,dtx:false,audioPreset:{maxBitrate:32000}});
  if(config.media==='video'||config.media==='screen'&&config.publisher){
    const canvas=document.createElement('canvas');canvas.width=config.media==='screen'?1280:640;canvas.height=config.media==='screen'?720:360;document.body.append(canvas);
    const ctx=canvas.getContext('2d');let frame=0;const fps=config.media==='screen'?5:15;
    const draw=()=>{frame++;ctx.fillStyle='#102a23';ctx.fillRect(0,0,canvas.width,canvas.height);ctx.font='28px sans-serif';ctx.fillStyle='#95f2d1';ctx.fillText('eCollab synthetic '+config.media+' '+frame,20,50);for(let i=0;i<24;i++){ctx.fillStyle=`hsl(${(frame*3+i*15)%360} 60% 50%)`;ctx.fillRect((frame*7+i*91)%canvas.width,(frame*3+i*41)%canvas.height,90,35);}};
    draw();state.timers.push(setInterval(draw,1000/fps));
    const track=canvas.captureStream(fps).getVideoTracks()[0];track.contentHint=config.media==='screen'?'detail':'motion';
    await room.localParticipant.publishTrack(track,{source:config.media==='screen'?Track.Source.ScreenShare:Track.Source.Camera,simulcast:false,videoCodec:'vp8',videoEncoding:{maxBitrate:config.media==='screen'?2000000:400000,maxFramerate:fps}});
  }
  return {joined:true};
}
async function sampleCapacityMedia(config) {
  const state=window.capacityMedia;
  if(!state?.room)return {audio_alive:false,video_alive:false,max_jitter_ms:null,max_loss_pct:null,rtt_ms:null};
  const now=performance.now();let audio=[],video=[],outbound=[],jitters=[],loss=[],rtt=[];
  for(const[pcIndex,pc]of window.capacityPeerConnections.entries()){
    const report=await pc.getStats();
    for(const stat of report.values()){
      if(stat.type==='candidate-pair'&&stat.state==='succeeded'&&stat.nominated&&Number.isFinite(stat.currentRoundTripTime))rtt.push(stat.currentRoundTripTime*1000);
      const codec=report.get(stat.codecId);if(/rtx|red|ulpfec/i.test(codec?.mimeType||''))continue;
      if(!['inbound-rtp','outbound-rtp'].includes(stat.type)||stat.isRemote)continue;
      const key=pcIndex+':'+stat.id,previous=state.previous.get(key);state.previous.set(key,{...stat,measuredAt:now});if(!previous)continue;
      const seconds=(now-previous.measuredAt)/1000;if(seconds<=0)continue;
      const kind=stat.kind||stat.mediaType;
      if(stat.type==='outbound-rtp'&&kind==='video'&&stat.framesEncoded!==undefined){outbound.push((stat.framesEncoded-previous.framesEncoded)/seconds);continue;}
      if(stat.type!=='inbound-rtp')continue;
      const received=Math.max(0,(stat.packetsReceived||0)-(previous.packetsReceived||0));
      const lost=Math.max(0,(stat.packetsLost||0)-(previous.packetsLost||0));
      loss.push(received+lost?100*lost/(received+lost):100);
      if(Number.isFinite(stat.jitter))jitters.push(stat.jitter*1000);
      if(kind==='audio')audio.push(((stat.bytesReceived||0)-(previous.bytesReceived||0))*8/seconds/1000);
      if(kind==='video'&&stat.framesDecoded!==undefined)video.push((stat.framesDecoded-previous.framesDecoded)/seconds);
    }
  }
  const expected=config.expected_audio;
  const expectedVideo=config.media==='video'?expected:config.media==='screen'&&!config.publisher?1:0;
  const fps=config.media==='screen'?2:8;
  return {audio_alive:audio.length===expected&&audio.every(v=>v>=1),
    video_alive:expectedVideo>0?video.length===expectedVideo&&video.every(v=>v>=fps):config.media==='screen'?outbound.length>=1&&outbound.every(v=>v>=fps):true,
    min_audio_kbps:audio.length?Math.min(...audio):null,min_video_fps:video.length?Math.min(...video):outbound.length?Math.min(...outbound):null,
    max_jitter_ms:jitters.length?Math.max(...jitters):null,max_loss_pct:loss.length?Math.max(...loss):null,rtt_ms:rtt.length?Math.max(...rtt):null,
    closed_early:state.closed_early,error:state.error};
}
async function closeCapacityMedia(){const state=window.capacityMedia;if(!state)return;state.closing=true;for(const timer of state.timers)clearInterval(timer);await state.room?.disconnect();await state.audioContext?.close();}
window.startCapacityMedia=startCapacityMedia;window.sampleCapacityMedia=sampleCapacityMedia;window.closeCapacityMedia=closeCapacityMedia;
