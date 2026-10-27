"""Recalculate Phase 3U summaries from lossless run archives, without rewriting samples."""
import collections,gzip,hashlib,json,pathlib,statistics,sys
folder=pathlib.Path(sys.argv[1]);destination=pathlib.Path(sys.argv[2])
comparison=json.loads((folder/'comparison.json').read_text());runs=[];serial=collections.defaultdict(lambda:collections.defaultdict(list));load=collections.defaultdict(lambda:collections.defaultdict(list));total=errors=sqlerrors=missing=0;admission=collections.Counter();provenance=[]
q=lambda a,p: sorted(a)[max(0,__import__('math').ceil(len(a)*p)-1)] if a else None
for info in comparison['runs']:
 path=folder/(info['file']+'.gz');raw=gzip.decompress(path.read_bytes());assert hashlib.sha256(raw).hexdigest()==info['raw_sha256'];d=json.loads(raw);scope=d['phase3u'];side=info['side'];cache=info['opcache'];provenance.append(d['source_provenance']);cells=[]
 for c in scope['cells']:
  n=len(c['requests']);total+=n;errors+=len(c['errors']);sqlerrors+=len(c['sql_errors']);missing+=c['missing_metrics'];admission[side]+=c['not_admitted_at_cap'];metrics=c['metrics'];times=[r['ms'] for r in c['requests']];processes=[s['database_process'] for s in c['status_samples'] if 'database_process' in s];pss=[s['pss_kib'] for s in processes if s.get('pss_kib') is not None]
  row={k:c[k] for k in ['label','warmup','request_slots','offered_arrivals','not_admitted_at_cap','scheduled_seconds','elapsed_seconds','successful_rps','fpm_queue_peak','fpm_active_peak_including_observer','db_counter_delta']}
  row.update({'completed':n,'errors':len(c['errors']),'sql_errors':len(c['sql_errors']),'missing_metrics':c['missing_metrics'],'drain_and_observer_seconds':c['elapsed_seconds']-c['scheduled_seconds'],'latency_ms':{k:q(times,p) for k,p in [('p50',.5),('p95',.95),('p99',.99)]},'sql_total_ms':sum(t['ms'] for m in metrics for t in m['queries']),'sql_count':sum(len(m['queries']) for m in metrics),'php_cpu_total_ms':sum(m['cpu_ms'] for m in metrics),'php_peak_bytes_max':max(m['peak_bytes'] for m in metrics),'response_bytes_total':sum(r['bytes'] for r in c['requests']),'database_pss_kib_max':max(pss) if pss else None,'unavailable_database_process_samples':sum('unavailable' in p for p in processes),'routes':c['routes']});cells.append(row)
  if c['label'].endswith('-survey'):
   dataset=c['label'].split('-')[0]
   for route,r in c['routes'].items():serial[(cache,dataset,route)][side].append(r['latency_ms']['p50'])
  if '-burst-' in c['label'] or '-steady-' in c['label']:load[(cache,c['label'].split('-repeat-')[0])][side].append(row)
 native=json.loads(d['phase3u_regression']['stdout']);assert native['status']=='PASS'
 runs.append({'file':path.name,'archive_sha256':hashlib.sha256(path.read_bytes()).hexdigest(),'variant':side,'opcache':cache,'runtime':d['runtime'],'resources':d['visible_resources'],'database_version':d['database_version'],'datasets':scope['datasets'],'stored_data_unchanged':scope['stored_data_unchanged'],'native_cases':len(native['cases']),'cells':cells})
serial_rows=[]
for key,sides in serial.items():
 b=statistics.median(sides['baseline']);c=statistics.median(sides['candidate']);serial_rows.append({'cache':key[0],'dataset':key[1],'route':key[2],'base_p50_ms':b,'candidate_p50_ms':c,'change_percent':100*(c/b-1),'per_run_p50_ms':dict(sides)})
loads=[]
for key,sides in load.items():
 item={'cache':key[0],'label':key[1]}
 for side,rows in sides.items():item[side]={'p95_ms_median':statistics.median(r['latency_ms']['p95'] for r in rows),'p95_ms_range':[min(r['latency_ms']['p95'] for r in rows),max(r['latency_ms']['p95'] for r in rows)],'successful_rps_median':statistics.median(r['successful_rps'] for r in rows),'queue_peaks':[r['fpm_queue_peak'] for r in rows],'cap_refusals':[r['not_admitted_at_cap'] for r in rows],'php_cpu_ms_per_request_median':statistics.median(r['php_cpu_total_ms']/r['completed'] for r in rows),'sql_ms_per_request_median':statistics.median(r['sql_total_ms']/r['completed'] for r in rows),'peak_php_bytes_max':max(r['php_peak_bytes_max'] for r in rows),'db_counter_deltas':[r['db_counter_delta'] for r in rows]}
 loads.append(item)
report={'status':'PASS investigation and functional checks, FAIL rejected SQL optimization gate','candidate_disposition':'REJECTED. STRAIGHT_JOIN regressed an analyzed one-channel large fixture. Final application query is restored to the accepted base.','base':comparison['base'],'benchmarked_head':comparison['intended_head'],'driver_head':comparison['harness_head'],'source_provenance':provenance,'http_requests':total,'http_errors':errors,'sql_errors':sqlerrors,'missing_metrics':missing,'not_admitted_at_cap':dict(admission),'response_comparisons':comparison['response_comparisons'],'serial':serial_rows,'load':loads,'runs':runs,'ci':{'status':'PENDING at report publication; final PR body records subsequent exact-head checks'},'capacity':'BLOCKED: synthetic loopback fixture is not representative student capacity','limitations':['Two independent pools per side/cache state; separate cache blocks are time-confounded.','Serial tails use six requests per route per run.','Shared facilitator account and ten student accounts in fixed-arrival mix.','Process counters may be unavailable; inspect each sample rather than infer resource savings.','The first API pass follows authentication/session seeding and is not a fully cold OS/database state.']}
destination.write_text(json.dumps(report,indent=2)+'\n')
print('Requests',total,'errors',errors,'SQL errors',sqlerrors,'cap refusals',dict(admission))
for row in serial_rows:
 if row['route']=='facilitator':print(row['cache'],row['dataset'],round(row['base_p50_ms'],3),round(row['candidate_p50_ms'],3),round(row['change_percent'],2))
