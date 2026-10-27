"""Owned, bounded read/write HTTP evidence. Slots are not students."""
import concurrent.futures,gzip,hashlib,json,math,pathlib,statistics,subprocess,threading,time
READS=['dashboard','history','notifications','settings']
MIX=['dashboard','history','send','read_mark','dashboard','history','send','read_mark','edit','delete']
def quantile(xs,p):
 xs=sorted(xs);return xs[max(0,math.ceil(len(xs)*p)-1)]if xs else None
def shape(node):
 if isinstance(node,list):return [shape(v)for v in node]
 if not isinstance(node,dict):return node
 keep={'select_id','table_name','access_type','key','used_key_parts','ref','join_type','buffer_type','using_index','sort_key','query_block','nested_loop','table','subqueries','materialized','read_sorted_file','filesort','expression_cache','block-nl-join','temporary_table'}
 return {k:shape(v)for k,v in node.items()if k in keep}
def run(port,cookies,folder,sql,request,process_usage,dbpid,env,target,operation):
 root=pathlib.Path(__file__).resolve().parents[1];out={'schema_flavour':env['PHASE3W_SCHEMA'],'index_state':env['PHASE3W_INDEX'],'cells':[],'plan_checkpoints':[],'scope':'Owned loopback read/write synthetic controls. Not production capacity.'};offset=0;watched={};signatures={};update_counts={u:0 for u in range(1,11)}
 def persist():target.with_suffix('.partial.json').write_text(json.dumps(out))
 def records():
  nonlocal offset
  with (folder/'requests.jsonl').open()as f:f.seek(offset);lines=f.readlines();offset=f.tell()
  return [json.loads(s)for s in lines]
 def locks():return sql("SELECT w.requesting_trx_id,w.blocking_trx_id,r.trx_mysql_thread_id waiting_thread,r.trx_query waiting_query,b.trx_query blocking_query,l.lock_table,l.lock_index,l.lock_type,l.lock_mode,l.lock_data FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id",True)
 def counters():return {r['Variable_name']:int(r['Value'])for r in sql("SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_row_lock_waits','Innodb_row_lock_time','Innodb_deadlocks','Innodb_buffer_pool_reads','Created_tmp_disk_tables','Com_insert','Com_update','Com_delete')",True)}
 def storage():
  path=folder.parent/'data'/'classroom_perf_test'/'messages.ibd'
  try:
   st=path.stat();return {'file_bytes':st.st_size,'allocated_bytes':st.st_blocks*512,'note':'Tablespace includes free extents; not equivalent to live index pages.'}
  except OSError as e:return {'status':'BLOCKED','reason':str(e)}
 def stats():return {'messages_tablespace':storage(),'tables':sql("SELECT TABLE_NAME,TABLE_ROWS,DATA_LENGTH,INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('messages','channels','server_members','message_reads') ORDER BY TABLE_NAME",True),'indexes':{t:sql('SHOW INDEX FROM '+t,True)for t in ['messages','channels','server_members','message_reads']},'persistent':sql("SELECT table_name,index_name,stat_name,stat_value,sample_size,last_update FROM mysql.innodb_index_stats WHERE database_name=DATABASE() AND table_name IN ('messages','channels','server_members','message_reads') ORDER BY table_name,index_name,stat_name",True)}
 def analyze():
  rows=sql('ANALYZE TABLE messages,channels,server_members,users,message_reads',True);assert len(rows)==5 and all(r['Msg_type']=='status'and r['Msg_text']=='OK'for r in rows),rows
  return rows
 # Minimal schema patches are explicit owned fixture additions needed by existing write APIs.
 if env['PHASE3W_SCHEMA']=='minimal':sql('ALTER TABLE moderation_actions ADD reason TEXT,ADD created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;ALTER TABLE messages ADD deleted_at DATETIME NULL,MODIFY is_deleted INT DEFAULT 0')
 sql('UPDATE servers SET member_count=1000+id;UPDATE channels SET position=id')
 out['schema_before_index']={t:sql('SHOW CREATE TABLE '+t,True)for t in ['users','servers','channels','server_members','messages','message_reads']}
 ddl_start=time.monotonic()
 present=bool(sql("SHOW INDEX FROM messages WHERE Key_name='dashboard_unread'",True))
 if env['PHASE3W_INDEX']=='absent'and present:sql('ALTER TABLE messages DROP INDEX dashboard_unread')
 if env['PHASE3W_INDEX']=='present'and not present:sql((root/'database/migrations/067_dashboard_unread_covering.sql').read_text())
 out['index_setup_seconds']=time.monotonic()-ddl_start
 out['schema_after_index']={t:sql('SHOW CREATE TABLE '+t,True)for t in ['messages','message_reads']}
 out['schema_inventory']={next(iter(r.values())):None for r in sql('SHOW TABLES',True)}
 for t in out['schema_inventory']:out['schema_inventory'][t]=sql('SHOW CREATE TABLE `'+t.replace('`','``')+'`',True)
 import re
 canonical={t:re.sub(r'AUTO_INCREMENT=\d+','AUTO_INCREMENT=<counter>',next(v for k,v in rows[0].items()if k.startswith('Create')))for t,rows in out['schema_inventory'].items()}
 out['schema_sha256']=hashlib.sha256(json.dumps(canonical,sort_keys=True).encode()).hexdigest()
 sql('CREATE TABLE w_http_baseline AS SELECT * FROM messages')
 out['baseline_counts']=sql('SELECT COUNT(*) messages,COUNT(DISTINCT channel_id) channels,COUNT(DISTINCT sender_id) senders FROM messages',True)[0]
 # Unique owned rows for edits/deletes prevent ambiguous last-writer expectations.
 sql("INSERT INTO messages(id,channel_id,sender_id,content,content_type,is_deleted,created_at) SELECT 300000+seq,MOD(FLOOR((seq-1)/100),3)+1,FLOOR((seq-1)/100)+1,CONCAT('Anchor ',seq),'text',0,'2020-01-01' FROM seq_1_to_1000;INSERT INTO messages(id,channel_id,sender_id,content,content_type,is_deleted,created_at) VALUES(299999,1,1,'Lock control','text',0,'2020-01-01')")
 out['workload_counts']=sql('SELECT COUNT(*) messages FROM messages',True)[0]
 untouched='users,servers,channels,server_members,message_attachments,direct_messages,notifications'
 out['untouched_before']=sql('CHECKSUM TABLE '+untouched,True)
 def job(name,uid,rid):
  channel=uid%3 or 3;body=None;method='GET'
  if name=='send':path='/API/chat/send-message.php';method='POST';body={'channel_id':channel,'content':'phase3w:'+rid,'content_type':'text'}
  elif name in ['edit','delete']:
   update_counts[uid]+=1;assert update_counts[uid]<=100,'Owned update target exhaustion';mid=300000+(uid-1)*100+update_counts[uid]
   path='/API/chat/'+('edit-message.php'if name=='edit'else'delete-message.php');method='POST';body={'message_id':mid}
   if name=='edit':body['content']='phase3w:'+rid
  else:path,method,body=operation(name,uid)
  return {'id':rid,'op':name,'uid':uid,'path':path,'method':method,'body':body}
 def call(j):
  start=time.monotonic()
  try:
   r=request(port,j['path'],cookies[j['uid']],j['method'],j['body'],j['id']);payload=json.loads(r['body']);ok=r['status']==(201 if j['op']=='send'else 200)and payload.get('success',payload.get('ok'))is True
   if j['op']in ['send','edit']and ok:ok=payload['message']['content']==j['body']['content']and int(payload['message']['sender_id'])==j['uid']
   return j|{'ms':r['ms'],'status':r['status'],'success':ok,'bytes':len(r['body']),'payload':payload,'start_monotonic':start,'end_monotonic':time.monotonic()}
  except Exception as e:return j|{'ms':8000,'success':False,'error':str(e),'start_monotonic':start,'end_monotonic':time.monotonic()}
 def checkpoint(label,metrics,results,stable):
  ids={r['id']for r in results if r['uid']==1}
  for m in metrics:
   if m['id']not in ids:continue
   for q in m['queries']:
    if 'SUM((SELECT COUNT(*) FROM messages'in q['sql']or('FROM messages m JOIN users'in q['sql']and 'LIMIT :limit'in q['sql']):
     key=hashlib.sha256(q['sql'].encode()).hexdigest();watched.setdefault(key,q)
  assert len(watched)>=2,('Missing unread/history telemetry',label)
  checks=[];transitions=[]
  for key,q in watched.items():
   assert q['bindings_replayable'];plan=sql('EXPLAIN FORMAT=JSON '+q['sql'],True,bindings=q['bindings']);parsed=json.loads(next(iter(plan[0].values())));topology=shape(parsed);digest=hashlib.sha256(json.dumps(topology,sort_keys=True).encode()).hexdigest();checks.append({'query':q,'explain':parsed,'topology_sha256':digest})
   if stable:
    if key in signatures and signatures[key]!=digest:transitions.append(key)
    signatures.setdefault(key,digest)
  out['plan_checkpoints'].append({'label':label,'observations':checks,'transitions':transitions});persist();assert not transitions,('Plan transition; comparison stopped',label)
 def cell(label,slots,jobs=None,seconds=None):
  records();before=counters();statuses=[];wait_samples=[];stop=threading.Event()
  def monitor():
   tick=0
   while not stop.is_set():
    try:
     s=json.loads(request(port,'/fpm-status?json&full')['body']);s['observed_monotonic']=time.monotonic();s['database_process']=process_usage(dbpid,'mariadbd');s['worker_processes']={str(p['pid']):process_usage(p['pid'],'php-fpm')for p in s.get('processes',[])};statuses.append(s)
     if tick%5==0:wait_samples.append({'observed_monotonic':time.monotonic(),'waits':locks()})
    except Exception as e:statuses.append({'observer_error':str(e)})
    tick+=1;stop.wait(.1)
  thread=threading.Thread(target=monitor,daemon=True);thread.start();start=time.monotonic();offered=refused=peak=0;lags=[];active_lock=threading.Lock();active=0
  def tracked(j):
   nonlocal active,peak
   with active_lock:active+=1;peak=max(peak,active)
   try:return call(j)
   finally:
    with active_lock:active-=1
  try:
   with concurrent.futures.ThreadPoolExecutor(max_workers=slots)as pool:
    if seconds is None:futures=[pool.submit(tracked,j)for j in jobs];offered=len(futures);scheduled=time.monotonic()-start
    else:
     deadline=start+seconds;next_at=start;futures=[]
     while time.monotonic()<deadline:
      if any(f.done()and not f.result()['success']for f in futures):break
      lags.append(max(0,(time.monotonic()-next_at)*1000))
      if sum(not f.done()for f in futures)>=slots:refused+=1
      else:
       name=MIX[offered%len(MIX)];uid=1+(offered//len(MIX))%10;futures.append(pool.submit(tracked,job(name,uid,label+'-'+str(offered))))
      offered+=1;next_at=max(next_at+1/60,time.monotonic()+.0001);time.sleep(max(0,next_at-time.monotonic()))
     scheduled=time.monotonic()-start
    results=[f.result()for f in futures]
  finally:stop.set();thread.join(timeout=9)
  elapsed=time.monotonic()-start;after=counters();metrics=records();ids={r['id']for r in results};metrics=[m for m in metrics if m['id']in ids];errors=[r for r in results if not r['success']];sqlerrors=[q for m in metrics for q in m['queries']if not q['success']]
  row={'label':label,'request_slots':slots,'actual_active_peak':peak,'distinct_accounts':len(set(r['uid']for r in results)),'nominal_offered_rps':60 if seconds else None,'offered':offered,'admitted':len(results),'cap_refused':refused,'completed':len(results),'scheduled_seconds':scheduled,'elapsed_seconds':elapsed,'drain_observer_seconds':elapsed-scheduled,'scheduler_lag_ms':lags,'successful_rps':sum(r['success']for r in results)/elapsed,'latency_ms':{k:quantile([r['ms']for r in results],p)for k,p in [('p50',.5),('p95',.95),('p99',.99)]},'requests':results,'metrics':metrics,'errors':errors,'sql_errors':sqlerrors,'missing_metrics':len(results)-len(metrics),'db_counter_delta':{k:after[k]-before[k]for k in before},'status_samples':statuses,'lock_samples':wait_samples,'fpm_queue_peak':max([s.get('listen queue',0)for s in statuses]+[0])}
  row['operations']={}
  for name in set(r['op']for r in results):
   rr=[r for r in results if r['op']==name];ii={r['id']for r in rr};mm=[m for m in metrics if m['id']in ii];row['operations'][name]={'n':len(rr),'latency_ms':{k:quantile([r['ms']for r in rr],p)for k,p in [('p50',.5),('p95',.95),('p99',.99)]},'php_cpu_total_ms':sum(m['cpu_ms']for m in mm),'php_peak_bytes_max':max(m['peak_bytes']for m in mm),'sql_count':sum(len(m['queries'])for m in mm),'sql_total_ms':sum(q['ms']for m in mm for q in m['queries']),'response_bytes':sum(r['bytes']for r in rr)}
  out['cells'].append(row);persist();print('PHASE3W',label,'completed',len(results),'refused',refused,'errors',len(errors),'queue',row['fpm_queue_peak'],flush=True)
  assert not errors and not sqlerrors and row['missing_metrics']==0,(label,errors,sqlerrors);assert max(r['ms']for r in results)<7500,'Request near timeout';assert offered==len(results)+refused
  checkpoint(label,metrics,results,not label.startswith('initial'));return row
 # Permission failures are expected preflight checks, not benchmark request errors.
 preflight=[]
 for name,uid,path,method,body,want in [
  ('nonmember_send',81,'/API/chat/send-message.php','POST',{'channel_id':1,'content':'Denied fixture','content_type':'text'},403),
  ('cross_sender_edit',1,'/API/chat/edit-message.php','POST',{'message_id':300101,'content':'Denied fixture'},403),
  ('cross_sender_delete',1,'/API/chat/delete-message.php','POST',{'message_id':300101},403)]:
  r=request(port,path,cookies[uid],method,body,'preflight-'+name);preflight.append({'case':name,'expected_status':want,'actual_status':r['status'],'body':json.loads(r['body'])});assert r['status']==want,preflight[-1]
 sql('UPDATE channels SET is_private=1 WHERE id=1')
 try:
  for name,path,method,body in [('private_send','/API/chat/send-message.php','POST',{'channel_id':1,'content':'Denied fixture','content_type':'text'}),('private_cursor','/API/chat/get-messages.php?channel_id=1&read=1','GET',None)]:
   r=request(port,path,cookies[1],method,body,'preflight-'+name);preflight.append({'case':name,'expected_status':403,'actual_status':r['status'],'body':json.loads(r['body'])});assert r['status']==403,preflight[-1]
 finally:sql('UPDATE channels SET is_private=0 WHERE id=1')
 out['permission_preflight']={'status':'PASS','cases':preflight,'metrics':records()}
 out['statistics_initial']=stats()
 cell('initial-survey',1,[job(name,1,'initial-'+str(i)+'-'+name)for i in range(3)for name in READS])
 out['analyze_table_result']=analyze();out['statistics_analyzed']=stats()
 corpus=cell('static-corpus',1,[job(name,uid,'corpus-'+str(uid)+'-'+name)for uid in range(1,11)for name in READS]);out['static_responses']={str(r['uid'])+':'+r['op']:r['payload']for r in corpus['requests']}
 cell('analyzed-survey',1,[job(name,1,'analyzed-'+str(i)+'-'+name)for i in range(3)for name in READS])
 history=next(q for m in corpus['metrics']for q in m['queries']if 'FROM messages m JOIN users'in q['sql']and 'LIMIT :limit'in q['sql']);assert any(b['parameter']==':limit'and b['pdo_type']==1 and b['source']=='bindValue'for b in history['bindings']);out['history_replay']={'status':'PASS','query':history,'explain':sql('EXPLAIN '+history['sql'],True,bindings=history['bindings'])}
 for repeat in range(2):
  for slots in [25,100]:cell('read-burst-'+str(slots)+'-'+str(repeat),slots,[job(['dashboard','history'][i%2],1+(i//2)%10,'burst-'+str(repeat)+'-'+str(slots)+'-'+str(i))for i in range(slots*3)])
 # Explicitly induced row lock, outside benchmark cells; validates attribution, not old waits.
 holder=subprocess.Popen([env.get('PHP_BIN','php8.3'),str(root/'tests/phase3w-lock-holder.php')],env=env,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True);assert holder.stdout.readline().strip()=='LOCK_READY'
 try:
  with concurrent.futures.ThreadPoolExecutor(max_workers=1)as pool:
   future=pool.submit(call,{'id':'lock-control','op':'edit','uid':1,'path':'/API/chat/edit-message.php','method':'POST','body':{'message_id':299999,'content':'Lock control complete'}});seen=[]
   try:
    for _ in range(12):
     rows=locks()
     if rows:seen=rows;break
     time.sleep(.05)
   finally:holder.stdin.write('release\n');holder.stdin.flush()
   response=future.result(timeout=8)
  out['lock_control']={'induced':True,'request':response,'waits':seen,'scope':'Intentional control excluded from natural cell deltas; does not identify old Phase3V waits.'};assert response['success']and any('messages'in r['lock_table']and r['lock_index']=='PRIMARY'for r in seen),out['lock_control']
 finally:
  if holder.poll()is None:holder.stdin.close();holder.wait(timeout=5)
 out['lock_control']['metrics']=records()
 for slots in [25,100]:cell('mixed-steady-'+str(slots),slots,seconds=int(env.get('PHASE3W_SECONDS','30')))
 # Independent final-state checks, derived from operation logs rather than table checksums for writes.
 inserts=[];updates=[];deletes=[];cursor={}
 for c in out['cells']:
  for r in c['requests']:
   if not r['success']:continue
   if r['op']=='send':inserts.append({'id':int(r['payload']['message']['id']),'uid':r['uid'],'channel':r['uid']%3 or 3,'content':r['body']['content']})
   if r['op']=='edit':updates.append({'id':r['body']['message_id'],'content':r['body']['content'],'uid':r['uid']})
   if r['op']=='delete':deletes.append({'id':r['body']['message_id'],'uid':r['uid']})
   if r['op']=='read_mark':
    ids=[int(m['id'])for m in r['payload']['messages']]
    if ids:cursor[(r['uid'],r['uid']%3 or 3)]=max(cursor.get((r['uid'],r['uid']%3 or 3),0),max(ids))
 changed=sql('SELECT id,channel_id,sender_id,content,is_edited,is_deleted,deleted_at FROM messages WHERE id>=299999 ORDER BY id',True);byid={int(r['id']):r for r in changed}
 for r in inserts:assert byid[r['id']]['content']==r['content']and int(byid[r['id']]['sender_id'])==r['uid']and int(byid[r['id']]['channel_id'])==r['channel']and int(byid[r['id']]['is_deleted'])==0,r
 for r in updates:assert byid[r['id']]['content']==r['content']and int(byid[r['id']]['sender_id'])==r['uid']and int(byid[r['id']]['is_edited'])==1,r
 for r in deletes:assert int(byid[r['id']]['is_deleted'])==1 and byid[r['id']]['deleted_at']is not None and int(byid[r['id']]['sender_id'])==r['uid'],r
 actual_cursors=sql("SELECT user_id,conversation_id,last_message_id FROM chat_read_cursors WHERE scope='channel' ORDER BY user_id,conversation_id",True);assert {(int(r['user_id']),int(r['conversation_id'])):int(r['last_message_id'])for r in actual_cursors}==cursor,(actual_cursors,cursor)
 legacy=sql('SELECT * FROM message_reads ORDER BY user_id,channel_id',True);assert len(legacy)==len(cursor)and all(r['last_read_at']=='2026-10-24 00:00:00'for r in legacy),legacy
 total=sql('SELECT COUNT(*) n FROM messages',True)[0]['n'];assert int(total)==int(out['workload_counts']['messages'])+len(inserts)
 out['write_verification']={'status':'PASS','inserted':inserts,'edited':updates,'deleted':deletes,'final_changed_rows':changed,'actual_cursors':actual_cursors,'legacy_reads':legacy,'total_messages':total,'invariants':'Unique owned writes exactly match logs; no hard delete; per-account cursor equals maximum acknowledged ID.'}
 out['untouched_after']=sql('CHECKSUM TABLE '+untouched,True);assert out['untouched_before']==out['untouched_after'];out['statistics_after_workload']=stats();persist()
 # Reset only the owned diagnostic fixture after preserving verified final state.
 sql('DELETE FROM messages;INSERT INTO messages SELECT * FROM w_http_baseline;DELETE FROM message_reads;DELETE FROM chat_read_cursors;DROP TABLE w_http_baseline')
 native=subprocess.run([env.get('PHP_BIN','php8.3'),str(root/'tests/phase3w-native.php')],env=env,capture_output=True,text=True,timeout=180);out['native_profile']={'exit_code':native.returncode,'stdout':native.stdout,'stderr':native.stderr};persist();assert native.returncode==0,native.stderr
 return out
