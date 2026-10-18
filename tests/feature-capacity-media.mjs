import {createHmac} from 'node:crypto';
import {spawn} from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const directory=fs.mkdtempSync(path.join(os.tmpdir(),'ecollab-real-media-'));
try{
  const run='capacity-20261018-000000-1234abcd',room='ecollab-'+run+'-0';
  const token=id=>{const header=Buffer.from(JSON.stringify({alg:'HS256',typ:'JWT'})).toString('base64url'),body=Buffer.from(JSON.stringify({iss:'devkey',sub:'user-'+id,iat:Math.floor(Date.now()/1000),nbf:Math.floor(Date.now()/1000)-5,exp:Math.floor(Date.now()/1000)+600,video:{roomJoin:true,room,canPublish:true,canSubscribe:true}})).toString('base64url');return header+'.'+body+'.'+createHmac('sha256','secret').update(header+'.'+body).digest('base64url');};
  const target={mode:'media',run_id:run,fixture_id:'k6-20261018-000000-1234abcd',livekit_url:process.env.TEST_LIVEKIT_URL||'ws://127.0.0.1:7880',room_size:2,expires_at:Math.floor(Date.now()/1000)+590,media:[1,2].map((id,slot)=>({id,slot,room,token:token(id)}))};
  fs.writeFileSync(path.join(directory,'feature-target.json'),JSON.stringify(target));
  for(const media of['voice','video','screen']){
    let output='';const code=await new Promise((resolve,reject)=>{const child=spawn(process.execPath,[path.join(root,'tools/feature-capacity/media-capacity.mjs')],{cwd:directory,env:{...process.env,VUS:'2',MEDIA_PROFILE:media,RAMP_SECONDS:'1',HOLD_SECONDS:'10',REPORT_FILE:'result.json'}});child.stdout.on('data',d=>output+=d);child.stderr.on('data',d=>output+=d);child.on('error',reject);child.on('close',resolve);});
    const report=JSON.parse(fs.readFileSync(path.join(directory,'result.json'),'utf8'));
    assert.equal(code,0,JSON.stringify({output,report}));assert.equal(report.capacity_pass,true,JSON.stringify(report));
    assert.equal(report.participants.length,2);assert(report.participants.every(p=>p.samples.length>=2&&p.samples.every(s=>s.audio_alive)));
    if(media!=='voice')assert(report.participants.every(p=>p.samples.every(s=>s.video_alive)));
    assert(!JSON.stringify(report).includes(target.media[0].token),'Reports never contain tokens');
    console.log('PASS: real LiveKit + Chromium '+media+' RTP, quality measurements, full hold and cleanup');
  }
}finally{fs.rmSync(directory,{recursive:true,force:true});}
