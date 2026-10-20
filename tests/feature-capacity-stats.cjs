'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const context={window:{},performance:{now:()=>10000},setTimeout:resolve=>resolve()};
vm.createContext(context);
vm.runInContext(fs.readFileSync(path.join(__dirname,'../tools/feature-capacity/media-client.js'),'utf8'),context);
const report=rows=>new Map(rows.map(row=>[row.id,row]));
const pair=(id,rtt,extra={})=>({id,type:'candidate-pair',state:'succeeded',currentRoundTripTime:rtt,...extra});
const transport={id:'transport',type:'transport',selectedCandidatePairId:'selected'};
const incoming={id:'audio',type:'inbound-rtp',transportId:'transport',kind:'audio',packetsReceived:100,bytesReceived:5000,packetsLost:0,jitter:.004};
const selected=report([transport,pair('selected',.047),pair('old',2,{nominated:true}),incoming]);
assert.equal(context.capacityTransportRtt(selected).value_ms,47,'Selected path works without nominated flag and ignores old nominated pair');
assert.equal(context.capacityTransportRtt(report([pair('legacy',.06,{nominated:true})])).value_ms,60,'Legacy nominated-pair fallback');
assert.equal(context.capacityTransportRtt(report([transport,pair('selected',0),incoming])).value_ms,0,'Measured zero RTT is valid');
for(const value of[undefined,null,NaN,Infinity,-1]){
 const result=context.capacityTransportRtt(report([transport,pair('selected',value),pair('old',.01,{nominated:true}),incoming]));
 assert.equal(result.value_ms,null,'A missing/invalid selected RTT cannot borrow an unrelated old pair');
}
assert.equal(context.capacityTransportRtt(report([pair('x',.1)])).value_ms,null,'An unselected non-nominated pair is not a measurement');
assert.equal(context.capacityTransportRtt(report([{...transport,selectedCandidatePairId:'gone'},incoming])).value_ms,null,'No fabricated RTT for a missing pair');
(async()=>{
 let calls=0;
 const missing=report([transport,pair('selected',undefined),incoming]);
 context.window.capacityPeerConnections=[{getStats:async()=>++calls===1?missing:selected}];
 context.window.capacityMedia={room:{},previous:new Map([['0:audio',{...incoming,packetsReceived:50,bytesReceived:1000,measuredAt:5000}]])};
 const config={expected_audio:1,media:'voice',encoding:{min_fps:15}};
 let sample=await context.sampleCapacityMedia(config);
 assert.equal(calls,2);assert.equal(sample.rtt_ms,47);assert.equal(sample.rtt_measurements[0].retried,true);assert.equal(sample.audio_alive,true);
 calls=0;context.window.capacityPeerConnections=[{getStats:async()=>{calls++;return missing;}}];
 context.window.capacityMedia.previous=new Map([['0:audio',{...incoming,packetsReceived:50,bytesReceived:1000,measuredAt:5000}]]);
 sample=await context.sampleCapacityMedia(config);
 assert.equal(calls,2,'Only one bounded retry');assert.equal(sample.rtt_ms,null);assert.equal(sample.audio_alive,true,'Missing RTT does not stop observed RTP');
 context.window.capacityPeerConnections=[{getStats:async()=>selected},{getStats:async()=>missing}];
 sample=await context.sampleCapacityMedia(config);
 assert.equal(sample.rtt_ms,null,'One measured connection must not conceal another missing connection RTT');
 // A stalled stream must fail continuity and loss coverage without inventing 100% loss.
 const source={context_state:'running',track_state:'live',enabled:true,muted:false};
 const outgoing={id:'publisher',type:'outbound-rtp',kind:'audio',bytesSent:2000,packetsSent:20};
 const setup=(rows,previous)=>{
   context.window.capacityPeerConnections=[{getStats:async()=>report([transport,pair('selected',.04),...rows])}];
   context.window.capacityMedia={room:{remoteParticipants:new Map([['remote',{identity:'user-42',audioTrackPublications:new Map([['track',{trackSid:'pub42',isSubscribed:true,isMuted:false,track:{mediaStreamTrack:{id:'remote-track',readyState:'live'}}}]])}]])},
     audioContext:{state:'running'},audioTrack:{readyState:'live',enabled:true,muted:false},
     previous:new Map(previous.map(row=>['0:'+row.id,{...row,measuredAt:5000}]))};
 };
 const stalled={...incoming,trackIdentifier:'remote-track',ssrc:42};
 setup([stalled,outgoing],[stalled,{...outgoing,bytesSent:1000,packetsSent:10}]);
 sample=await context.sampleCapacityMedia(config);
 assert.equal(sample.audio_alive,false);assert.equal(sample.max_loss_pct,null);
 assert.equal(sample.audio_streams.find(s=>s.direction==='inbound').reason,'no byte progress');
 assert.equal(sample.audio_streams.find(s=>s.direction==='inbound').participant,'user-42');
 assert.equal(sample.audio_streams.find(s=>s.direction==='outbound').bytes_delta,1000);
 assert.equal(sample.remote_audio_tracks[0].publication_sid,'pub42');
 assert.equal(JSON.stringify(sample.audio_source),JSON.stringify(source));
 assert(Number.isFinite(sample.sampled_at_ms));
 setup([{...stalled,packetsLost:10}],[stalled]);
 sample=await context.sampleCapacityMedia(config);
 assert.equal(sample.max_loss_pct,100,'Positive loss with zero received packets remains genuine measured 100% loss');
 const flowing={...incoming,id:'flowing',packetsReceived:200,bytesReceived:10000};
 setup([stalled,flowing],[stalled,{...flowing,packetsReceived:100,bytesReceived:5000}]);
 sample=await context.sampleCapacityMedia({...config,expected_audio:2});
 assert.equal(sample.max_loss_pct,null,'A flowing stream must not hide unmeasured loss on another stream');
 setup([{...stalled,bytesReceived:20,packetsReceived:1}],[stalled]);
 sample=await context.sampleCapacityMedia(config);
 assert.equal(sample.audio_alive,false);assert.equal(sample.max_loss_pct,null);
 assert.equal(sample.audio_streams[0].reason,'counters reset');
 setup([flowing],[{...flowing,packetsReceived:100,bytesReceived:5000}]);
 sample=await context.sampleCapacityMedia(config);
 assert.equal(sample.audio_alive,true);assert.equal(sample.max_loss_pct,0);
 assert.equal(sample.audio_streams.length,0,'Normal inbound diagnostics omitted to bound report size');
 assert.equal(sample.remote_audio_tracks,undefined);
 console.log('PASS: selected transport path, legacy fallback, no stale/zero imputation, bounded retry and per-connection RTT coverage');
 console.log('PASS: absent packet progress, measured all-loss, mixed streams, counter reset, source/publication and publisher diagnostics');
})().catch(error=>{console.error(error);process.exitCode=1;});
