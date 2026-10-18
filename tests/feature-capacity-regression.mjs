import assert from 'node:assert/strict';
import {evaluateMedia,validateMediaTarget,distribution,mediaEncoding} from '../tools/feature-capacity/common.mjs';
import {combineShards,summarize} from '../tools/feature-capacity/summarize-capacity.mjs';
const samples=()=>Array.from({length:2},()=>({audio_alive:true,video_alive:true,min_audio_kbps:20,min_video_fps:15,max_jitter_ms:3,max_loss_pct:0,rtt_ms:12}));
const make=()=>({revision:'feature-capacity-1',kind:'media',run_id:'capacity-20261018-000000-1234abcd',sample_id:'unique-1',fixture_id:'fixture',configured_users:2,total_users:2,shard_count:1,shard_index:0,profile:{media:'video',hold_seconds:10,room_size:2},full_hold:true,generator:{cpu_p95:20,event_loop_p95_ms:22,minimum_free_bytes:1000000000},participants:[1,2].map((id,i)=>({id,slot:i,joined:true,samples:samples()})),process_exit_code:0});
assert(evaluateMedia(make()).capacity_pass);
for(const mutate of [r=>r.full_hold=false,r=>r.participants.pop(),r=>r.participants[1].id=1,r=>r.participants[0].samples=[],r=>r.participants[0].closed_early=true,r=>r.participants[0].samples[0].audio_alive=false,r=>r.participants[0].samples[0].video_alive=false,r=>r.participants[0].samples[0].max_jitter_ms=null,r=>r.participants[0].samples[0].max_loss_pct=10,r=>r.participants[0].samples[0].rtt_ms=1000,r=>r.generator.cpu_p95=99,r=>r.generator.event_loop_p95_ms=1000]){const r=make();mutate(r);assert(!evaluateMedia(r).capacity_pass);}
const a=make(),b=make();for(const r of[a,b]){r.shard_count=2;r.shard_pass=true;r.configured_users=1;r.start_at_ms=100;r.hold_start_ms=200;r.hold_end_ms=300;}
a.participants=[a.participants[0]];b.participants=[b.participants[1]];b.shard_index=1;
assert(combineShards([a,b]).capacity_pass);
assert.throws(()=>combineShards([a]),/All unique/);assert.throws(()=>combineShards([a,a]),/unique/);
const late=structuredClone(b);late.hold_end_ms=400;assert.throws(()=>combineShards([a,late]),/windows differ/);
const duplicated=structuredClone(b);duplicated.participants[0]=a.participants[0];assert.throws(()=>combineShards([a,duplicated]),/duplicate/);
let r=make();r.capacity_pass=true;let repeat=structuredClone(r);repeat.sample_id='unique-2';
assert.equal(summarize([r,r])[0].highest_repeat_validated_tested_load,0,'Copied report is not independent validation');
assert.equal(summarize([r,repeat])[0].highest_repeat_validated_tested_load,2);
const failed=structuredClone(r);failed.sample_id='unique-3';failed.process_exit_code=1;assert.equal(summarize([r,repeat,failed])[0].highest_repeat_validated_tested_load,0);
const claims={sub:'user-1',exp:Date.now()/1000+600,video:{room:'ecollab-capacity-20261018-000000-1234abcd-0'}};
const target={mode:'media',run_id:'capacity-20261018-000000-1234abcd',fixture_id:'k6-20261018-000000-1234abcd',livekit_url:'wss://livekit.example.test',room_size:2,media:[{id:1,slot:0,room:claims.video.room,token:'x.'+Buffer.from(JSON.stringify(claims)).toString('base64url')+'.x'}]};
validateMediaTarget(target);target.media[0].id=2;assert.throws(()=>validateMediaTarget(target),/match/);
assert.equal(distribution([]).p95,null);
assert.equal(mediaEncoding('app-default','video').height,720);assert.equal(mediaEncoding('app-default','screen').fps,30);assert.equal(mediaEncoding('app-default','screen').max_bitrate,6000000);assert.equal(mediaEncoding('economy','video').height,360);assert.throws(()=>mediaEncoding('fake','video'));
console.log('PASS: media must flow, full hold/coverage, quality thresholds, generator overload, token scoping, shard overlap/deduplication and repeat validation');
