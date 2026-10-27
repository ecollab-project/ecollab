"""Bounded, owned HTTP/FPM read sampler. No external targets or capacity verdict."""
import concurrent.futures, hashlib, json, math, pathlib, re, statistics, threading, time

ROUTES=['dashboard','workspace','navigation','history','dm','threads','notifications','settings','peer','facilitator','facilitator_dashboard']

def quantile(values,q):
    values=sorted(values)
    return values[max(0,math.ceil(len(values)*q)-1)] if values else None

def run(port,cookies,folder,sql,request,process_usage,dbpid,env,target,operation=None):
    # operation is supplied by the owned parent fixture, never by HTTP input.
    assert operation is not None
    stable=False; plan_baseline={}; watched_queries={}
    output={'scope':'Owned serial surveys, repeated finite bursts and bounded fixed-arrival reads through HTTP/FastCGI/native FPM. Not Nginx/TLS, real students or capacity certification.', 'cells':[], 'plans':[], 'responses':{}}
    offset=0
    def records():
        nonlocal offset
        with (folder/'requests.jsonl').open() as f:
            f.seek(offset);lines=f.readlines();offset=f.tell()
        return [json.loads(line) for line in lines]
    records()
    def call(name,uid,rid):
        uid=900 if name in ['facilitator','facilitator_dashboard'] else uid
        if name=='facilitator_dashboard':path='/API/dashboard/live.php';method='GET';body=None
        else:path,method,body=operation(name,uid)
        try:
            r=request(port,path,cookies[uid],method,body,rid)
            payload=json.loads(r['body']);good=r['status']==200 and payload.get('success',payload.get('ok')) is True
            if name in ['workspace','navigation','facilitator'] and good:good=all(v['available'] for k,v in payload['data'].items() if k!='limits')
            # generated_at is the only normalized field, a wall-clock response envelope.
            payload.pop('generated_at',None)
            canonical=json.dumps(payload,sort_keys=True,separators=(',',':'),ensure_ascii=False)
            return {'id':rid,'route':name,'uid':uid,'ms':r['ms'],'status':r['status'],'success':good,'bytes':len(r['body']),'response_sha256':hashlib.sha256(canonical.encode()).hexdigest(),'payload':payload}
        except Exception as e:return {'id':rid,'route':name,'uid':uid,'ms':8000,'success':False,'error':str(e)}
    def counters():return {r['Variable_name']:int(r['Value']) for r in sql("SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_row_lock_waits','Innodb_row_lock_time','Innodb_buffer_pool_reads','Created_tmp_disk_tables')",True)}
    def shape(node):
        if isinstance(node,list):return [shape(n) for n in node]
        if not isinstance(node,dict):return node
        static={'select_id','table_name','access_type','key','used_key_parts','ref','join_type','buffer_type','using_index','first_match','loose_scan','sort_key'}
        containers={'query_block','nested_loop','table','subqueries','materialized','read_sorted_file','filesort','expression_cache','block-nl-join','temporary_table','union_result','query_specifications','duplicates_removal'}
        return {k:shape(v) for k,v in node.items() if k in static or k in containers}
    def snapshot():
        return {'table_stats':sql("SELECT table_name,last_update,n_rows,clustered_index_size,sum_of_other_index_sizes FROM mysql.innodb_table_stats WHERE database_name=DATABASE() ORDER BY table_name",True),
                'indexes':{t:sql('SHOW INDEX FROM '+t,True) for t in ['messages','channels','server_members','message_reads']},'index_stats':sql("SELECT table_name,index_name,last_update,stat_name,stat_value,sample_size FROM mysql.innodb_index_stats WHERE database_name=DATABASE() AND table_name IN ('messages','channels','server_members','message_reads') AND stat_name LIKE 'n_diff_pfx%' ORDER BY table_name,index_name,stat_name",True)}
    def checkpoint(label,metrics,requests):
        uid_by_id={r['id']:r['uid'] for r in requests}
        for m in metrics:
            if uid_by_id.get(m['id']) not in [1,900]:continue
            for query in m['queries']:
                text=query['sql']
                if not any(marker in text for marker in ['SUM((SELECT COUNT(*) FROM messages','COALESCE(activity.messages','COUNT(DISTINCT CASE WHEN ui.user_id','FROM (SELECT id,created_at FROM messages']):continue
                key=hashlib.sha256(json.dumps([text,query['params']],sort_keys=True).encode()).hexdigest();watched_queries[key]=query
        assert len(watched_queries)>=4,('Missing dominant-query plan observations',label,list(watched_queries))
        observations=[];transitions=[]
        for key,query in watched_queries.items():
            plan=sql('EXPLAIN FORMAT=JSON '+query['sql'],True,query['params']);parsed=json.loads(next(iter(plan[0].values())));topology=shape(parsed);digest=hashlib.sha256(json.dumps(topology,sort_keys=True,separators=(',',':')).encode()).hexdigest()
            observations.append({'key':key,'query':query,'plan':parsed,'topology':topology,'sha256':digest})
            if key in plan_baseline and plan_baseline[key]!=digest:transitions.append({'key':key,'previous':plan_baseline[key],'current':digest})
            plan_baseline.setdefault(key,digest)
        output.setdefault('plan_checkpoints',[]).append({'label':label,'observations':observations,'transitions':transitions})
        target.with_suffix('.partial.json').write_text(json.dumps(output))
        assert not transitions,('Tracked plan changed during analyzed read cohort',label,transitions)
    def cell(label,requests,workers,warmup=False,steady_seconds=None):
        sql('TRUNCATE rate_limit_log;TRUNCATE pm_compatibility')
        statuses=[];stop=threading.Event()
        def monitor():
            while not stop.is_set():
                try:
                    row=json.loads(request(port,'/fpm-status?json&full')['body']);row['observed_monotonic']=time.monotonic();row['database_process']=process_usage(dbpid,'mariadbd');row['worker_processes']={str(p['pid']):process_usage(p['pid'],'php-fpm') for p in row.get('processes',[])};statuses.append(row)
                except Exception as e:statuses.append({'unavailable':str(e)})
                stop.wait(.05)
        before=counters();observer=threading.Thread(target=monitor,daemon=True);observer.start();start=time.monotonic()
        arrivals=0;not_admitted=0;scheduler_lag=[]
        try:
            with concurrent.futures.ThreadPoolExecutor(max_workers=workers) as pool:
                if steady_seconds is None:
                    futures=[pool.submit(call,name,uid,label+'-'+str(i)) for i,(name,uid) in enumerate(requests)]
                    arrivals=len(futures);scheduled_seconds=time.monotonic()-start
                else:
                    # Predeclared 100/s fixed-rate mixed reads. Never queue behind the in-flight cap.
                    # Ten student accounts plus owner900; no peer inference/rate-limit workload here.
                    names=[name for name in ROUTES if name!='peer'];futures=[];next_at=time.monotonic();deadline=next_at+steady_seconds
                    while time.monotonic()<deadline:
                        now=time.monotonic();scheduler_lag.append(max(0,(now-next_at)*1000))
                        if sum(not f.done() for f in futures)>=workers:not_admitted+=1
                        else:
                            name=names[arrivals%len(names)];uid=1+(arrivals//len(names))%10
                            futures.append(pool.submit(call,name,uid,label+'-'+str(arrivals)))
                        arrivals+=1;next_at=max(next_at+.01,time.monotonic()+.0001)
                        time.sleep(max(0,next_at-time.monotonic()))
                    scheduled_seconds=time.monotonic()-start
                results=[f.result() for f in futures]
        finally:stop.set();observer.join(timeout=9)
        elapsed=time.monotonic()-start;after=counters();metrics=records();lookup={r['id']:r for r in metrics}
        valid=[lookup[r['id']] for r in results if r['id'] in lookup]
        errors=[r for r in results if not r['success']]
        sqlerrors=[q for m in valid for q in m['queries'] if not q['success']]
        routes={}
        for name in ROUTES:
            rr=[r for r in results if r['route']==name];mm=[lookup[r['id']] for r in rr if r['id'] in lookup]
            if not rr:continue
            routes[name]={'n':len(rr),'latency_ms':{k:quantile([r['ms'] for r in rr],q) for k,q in [('p50',.5),('p95',.95),('p99',.99)]},'php_cpu_total_ms':sum(m['cpu_ms'] for m in mm),'sql_total_ms':sum(q['ms'] for m in mm for q in m['queries']),'statements_total':sum(len(m['queries']) for m in mm),'bytes_total':sum(r.get('bytes',0) for r in rr)}
        for r in results:
            payload=r.pop('payload',None)
            if payload is not None:
                key=label.split('-')[0]+':'+r['route']+':'+str(r['uid'])
                output['responses'].setdefault(key,payload)
        row={'label':label,'scheduled_seconds':scheduled_seconds,'offered_arrivals':arrivals,'not_admitted_at_cap':not_admitted,'scheduler_lag_ms':scheduler_lag,'nominal_offered_rps':100 if steady_seconds else None,'warmup':warmup,'request_slots':workers,'distinct_authenticated_accounts':len(set(r['uid'] for r in results)),'facilitator_account':900,'requests':results,'metrics':valid,'elapsed_seconds':elapsed,'completed_rps':len(results)/elapsed,'successful_rps':sum(r['success'] for r in results)/elapsed,'errors':errors,'sql_errors':sqlerrors,'missing_metrics':len(results)-len(valid),'routes':routes,'db_counter_delta':{k:after[k]-before[k] for k in before},'status_samples':statuses,'fpm_queue_peak':max([s.get('listen queue',0) for s in statuses]+[0]),'fpm_active_peak_including_observer':max([s.get('active processes',0) for s in statuses]+[0])}
        output['cells'].append(row)
        # Preserve partial measurements before any acceptance/stop assertion.
        target.with_suffix('.partial.json').write_text(json.dumps(output))
        print('PHASE3V',label,'requests',len(results),'seconds',round(elapsed,3),'errors',len(errors),'queue_peak',row['fpm_queue_peak'],flush=True)
        assert not errors and not sqlerrors and row['missing_metrics']==0,(label,errors,sqlerrors)
        assert max(r['ms'] for r in results)<7500,'Stop: request approached 8s timeout'
        if stable:checkpoint(label,valid,results)
        return row
    # Avoid unrelated legacy ORDER BY ties when comparing independent DB instances.
    # The application ordering clauses and member-activity tie tests stay unchanged.
    sql('UPDATE servers SET member_count=1000+id;UPDATE channels SET position=id')
    state_tables='users,servers,channels,server_members,messages,message_attachments,notifications,message_reads,direct_messages,dm_messages,threads,thread_attachments'
    output['stored_data_before']=sql('CHECKSUM TABLE '+state_tables,True)
    output['schema_variant']=env.get('PHASE3V_INDEX','legacy')
    assert output['schema_variant'] in ['legacy','existing_067']
    if output['schema_variant']=='existing_067':sql((pathlib.Path(__file__).resolve().parents[1]/'database/migrations/067_dashboard_unread_covering.sql').read_text())
    sql('CREATE TABLE phase3s_all_messages AS SELECT * FROM messages')
    sizes=env.get('PHASE3V_DATASETS','small,large').split(',')
    for dataset in sizes:
        assert dataset in ['small','large']
        stable=False;plan_baseline.clear();watched_queries.clear()
        # Physically rebuild the owned message table; preserve boundary rows.
        sql('TRUNCATE messages;INSERT INTO messages SELECT * FROM phase3s_all_messages'+(' WHERE id<=10000 OR id>100000' if dataset=='small' else ''))
        output.setdefault('datasets',{})[dataset]=sql('SELECT COUNT(*) messages,SUM(is_deleted=0) live_messages,COUNT(DISTINCT sender_id) authors FROM messages',True)[0]
        output.setdefault('statistics_states',{})[dataset]={'initial':snapshot()}
        warm=[(name,1) for name in ROUTES]
        cell(dataset+'-initial-first-api-pass',warm,1,True)
        initial=cell(dataset+'-initial-survey',[(name,1) for _ in range(3) for name in ROUTES],1,True)
        initial_queries=[]
        for m in initial['metrics']:
            for query in m['queries']:
                if any(marker in query['sql'] for marker in ['SUM((SELECT COUNT(*) FROM messages','COALESCE(activity.messages']):
                    if query['sql'] not in [q['query']['sql'] for q in initial_queries]:initial_queries.append({'query':query,'plan':sql('EXPLAIN FORMAT=JSON '+query['sql'],True,query['params'])})
        output['statistics_states'][dataset]['initial_plans_after_samples']=initial_queries
        tables=[next(iter(r.values())) for r in sql('SHOW TABLES',True)]
        output['statistics_states'][dataset]['analyze_table_result']=sql('ANALYZE TABLE '+','.join('`'+t.replace('`','``')+'`' for t in tables),True)
        analyzed=output['statistics_states'][dataset]['analyze_table_result']
        assert len([r for r in analyzed if r['Msg_type']=='status' and r['Msg_text']=='OK'])==len(tables),('ANALYZE TABLE incomplete',analyzed)
        assert not any(r['Msg_type']=='error' for r in analyzed),analyzed
        output['statistics_states'][dataset]['analyzed_before_warmup']=snapshot()
        stable=True
        cell(dataset+'-warm-corpus',[(name,uid) for uid in range(1,11) for name in ROUTES],1,True)
        survey=cell(dataset+'-survey',[(name,1) for _ in range(6) for name in ROUTES],1)
        # Query plans for the largest observed SQL execution costs, with actual bound parameters.
        queries=sorted([q for m in survey['metrics'] for q in m['queries'] if q['sql'].lstrip().upper().startswith('SELECT')],key=lambda q:-q['ms'])
        seen=set()
        for q in queries:
            if q['sql'] in seen:continue
            seen.add(q['sql'])
            if q['params'] is None and re.search(r'(?<!:):[A-Za-z_][A-Za-z_0-9]*|\?',q['sql']):
                output['plans'].append({'dataset':dataset,'query':q,'status':'NOT RUN','reason':'Bound parameters unavailable from execute-only instrumentation; bindValue inputs were not recorded.'})
            else:
                output['plans'].append({'dataset':dataset,'query':q,'status':'PASS','explain':sql('EXPLAIN '+q['sql'],True,q['params'])})
            if len(seen)>=8:break
        if env.get('PHASE3V_SURVEY_ONLY')=='1':
            output['statistics_states'][dataset]['after_measurement']=snapshot()
            continue
        for repetition in range(4 if env.get('PHASE3V_HISTORY_CONTROL')=='1' else 2):
          for actors in [25,100]:
            # At most 100 in-flight requests. Each slot makes one request at a time.
            # Eleven routes rotate across a fixed synthetic mix; facilitator shares owner900.
            jobs=[(ROUTES[i%len(ROUTES)],1+i%actors) for i in range(max(33,actors*3))]
            cell(dataset+'-burst-'+str(actors)+'-repeat-'+str(repetition),jobs,actors)
        if dataset=='large' and env.get('PHASE3V_HISTORY_CONTROL')!='1':
            for actors in [25,100]:
                cell(dataset+'-steady-'+str(actors),[],actors,steady_seconds=int(env.get('PHASE3V_STEADY_SECONDS','10')))
        output['statistics_states'][dataset]['after_measurement']=snapshot()
    output['stored_data_after']=sql('CHECKSUM TABLE '+state_tables,True)
    output['stored_data_unchanged']=output['stored_data_before']==output['stored_data_after']
    assert output['stored_data_unchanged'],'Read workload modified application data'
    return output
