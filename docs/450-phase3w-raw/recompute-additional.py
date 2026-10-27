import collections,gzip,hashlib,json,pathlib,statistics,sys
folder=pathlib.Path(sys.argv[1]);summary=json.loads(pathlib.Path(sys.argv[2]).read_text());cohorts=collections.defaultdict(set);writeq=collections.defaultdict(list);overcap=[];natural=[];uncaptured=0;checkpoint_count=observation_count=0
for info in json.loads((folder/'comparison.json').read_text())['runs']:
 d=json.loads(gzip.decompress((folder/(info['file']+'.gz')).read_bytes()));s=d['phase3w'];cohorts[(info['schema'],info['index'])].add(s['schema_sha256']);assert s['write_verification']['status']=='PASS';assert s['untouched_before']==s['untouched_after']
 for c in s['cells']:
  samples=[{'sample_index':i,'reported_queue':v['listen queue'],'observed_monotonic':v.get('observed_monotonic')}for i,v in enumerate(c['status_samples'])if v.get('listen queue',0)>c['request_slots']]
  if samples:overcap.append({'file':info['file'],'cell':c['label'],'slots':c['request_slots'],'actual_active_peak':c['actual_active_peak'],'samples':samples})
  if c['db_counter_delta']['Innodb_row_lock_waits']:natural.append({'file':info['file'],'cell':c['label'],'delta':c['db_counter_delta'],'sampled_waits':[p for p in c['lock_samples']if p['waits']]})
  for m in c['metrics']:
   for q in m['queries']:
    uncaptured+=sum(not b.get('captured',False)for b in q.get('bindings',[]));name=None;text=q['sql']
    if text.startswith('INSERT INTO messages '):name='message_insert'
    elif text.startswith('UPDATE messages SET content='):name='message_edit'
    elif text.startswith('UPDATE messages SET is_deleted='):name='message_soft_delete'
    elif text.startswith('INSERT INTO message_reads '):name='legacy_read_cursor'
    elif text.startswith('INSERT INTO chat_read_cursors('):name='high_water_cursor'
    if name and c['label'].startswith('mixed-steady-'):writeq[(info['schema'],info['index'],name)].append(q['ms'])
 checkpoint_count+=len(s['plan_checkpoints']);observation_count+=sum(len(p['observations'])for p in s['plan_checkpoints'])
assert uncaptured==0
assert all(len(v)==1 for v in cohorts.values()),cohorts
qrows=[]
for key,xs in writeq.items():
 ys=sorted(xs);p=lambda f:ys[max(0,__import__('math').ceil(len(ys)*f)-1)];qrows.append({'schema':key[0],'index':key[1],'statement':key[2],'n':len(xs),'p50_ms':p(.5),'p95_ms':p(.95),'p99_ms':p(.99),'total_ms':sum(xs)})
summary|={'query_write_timings':qrows,'natural_lock_waits':natural,'queue_over_cap_observations':overcap,'queue_freshness':'PARTIAL: some status queue samples exceed the cell in-flight cap. Stale/cross-boundary reporting is plausible, not experimentally isolated. Do not treat these readings as exact per-cell instantaneous queues.','schema_identity_checks':[{'schema':k[0],'index':k[1],'sha256':next(iter(v)),'independent_pools_equal':True}for k,v in cohorts.items()],'uncaptured_sampled_bindings':uncaptured,'plan_checkpoint_count':checkpoint_count,'plan_observation_count':observation_count}
pathlib.Path(sys.argv[3]).write_text(json.dumps(summary,indent=2)+'\n');print('Checkpoints',checkpoint_count,'observations',observation_count,'uncaptured',uncaptured,'queue-over-cap cells',len(overcap),'natural wait cells',len(natural));print('Writes',qrows)
