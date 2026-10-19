import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {evaluateMedia} from './common.mjs';
export function combineShards(reports){
  if(!reports.length)throw Error('No shards');const first=reports[0];const count=first.shard_count;
  if(reports.length!==count||new Set(reports.map(r=>r.shard_index)).size!==count)throw Error('All unique shards are required');
  const signature=r=>JSON.stringify([r.revision,r.kind,r.run_id,r.fixture_id,r.total_users,r.shard_count,r.start_at_ms,r.hold_start_ms,r.hold_end_ms,r.profile]);
  if(reports.some(r=>signature(r)!==signature(first)))throw Error('Shard workloads or time windows differ');
  if(reports.some(r=>r.process_exit_code!==0||!r.shard_pass||!r.full_hold))throw Error('A shard failed or did not finish');
  const participants=reports.flatMap(r=>r.participants);
  if(participants.length!==first.total_users||new Set(participants.map(p=>p.id)).size!==participants.length||new Set(participants.map(p=>p.slot)).size!==participants.length||participants.some(p=>p.slot<0||p.slot>=first.total_users))throw Error('Missing or duplicate global participants');
  const combined={...first,configured_users:first.total_users,shard_count:1,shard_index:0,participants,requires_aggregation:false,full_hold:true,process_exit_code:0,generator:{cpu_p95:Math.max(...reports.map(r=>r.generator.cpu_p95)),event_loop_p95_ms:Math.max(...reports.map(r=>r.generator.event_loop_p95_ms)),minimum_free_bytes:Math.min(...reports.map(r=>r.generator.minimum_free_bytes))},source_shards:reports.length};
  Object.assign(combined,evaluateMedia(combined));if(!combined.capacity_pass)combined.process_exit_code=1;return combined;
}
export function summarize(reports){
  const groups=new Map();
  for(const r of reports){
    if(r.revision!=='feature-capacity-1'||r.requires_aggregation)continue;
    const profile={...r.profile};delete profile.total_users;delete profile.expected_submissions;
    const key=JSON.stringify([r.kind,profile,r.configured_provider||null]);
    if(!groups.has(key))groups.set(key,{kind:r.kind,profile,configured_provider:r.configured_provider||null,levels:{}});
    const group=groups.get(key),level=group.levels[r.configured_users]||={passed:0,failed:0,run_ids:[]};
    // Never turn two copies of one result into repeated validation.
    if(level.run_ids.includes(r.sample_id||r.run_id))continue;level.run_ids.push(r.sample_id||r.run_id);
    if(r.capacity_pass===true&&r.process_exit_code===0)level.passed++;else level.failed++;
  }
  return [...groups.values()].map(g=>({...g,highest_repeat_validated_tested_load:Math.max(0,...Object.entries(g.levels).filter(([_,v])=>v.passed>=2&&v.failed===0).map(([n])=>Number(n)))}));
}
if(process.argv[1]&&path.resolve(process.argv[1])===fileURLToPath(import.meta.url)){
  const args=process.argv.slice(2),aggregate=args[0]==='--aggregate';if(aggregate)args.shift();
  if(!args.length)throw Error('Supply result JSON files, or --aggregate followed by every shard JSON file');
  const reports=args.map(p=>JSON.parse(fs.readFileSync(p,'utf8').replace(/^\uFEFF/,'')));
  if(aggregate){const r=combineShards(reports),out='results-media-combined-'+r.run_id+'.json';fs.writeFileSync(out,JSON.stringify(r,null,2));console.log(out);if(!r.capacity_pass)process.exitCode=1;}
  else console.log(JSON.stringify({scope:'Highest repeat-validated TESTED load per exact profile. Two independent successful runs and zero failures required. Untested loads and mixed workloads remain unestablished.',profiles:summarize(reports)},null,2));
}

