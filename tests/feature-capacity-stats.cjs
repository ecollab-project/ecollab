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
 console.log('PASS: selected transport path, legacy fallback, no stale/zero imputation, bounded retry and per-connection RTT coverage');
})().catch(error=>{console.error(error);process.exitCode=1;});
