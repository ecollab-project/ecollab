"""Disposable MariaDB owner. Never connects to a supplied database or deployed service."""
import importlib.util, json, os, pathlib, pwd, socket, statistics, subprocess, tempfile, time
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_BIN','php')
BASE='87e82284ced2cee4858b673cc5a8619151c019bb'
with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
with tempfile.TemporaryDirectory(prefix='dashboard-perf-') as folder:
    p=pathlib.Path(folder);env=os.environ|{'APP_ENV':'testing','DASHBOARD_PERF_DSN':f'mysql:host=127.0.0.1;port={port};dbname=dashboard_perf_test','APPLICATION_PERF_BASELINE':str(p),'APPLICATION_PERF_EVIDENCE':os.environ.get('APPLICATION_PERF_EVIDENCE',str(p/'evidence.json'))}
    args=[os.environ.get('MARIADB_INSTALL_DB_BIN','mariadb-install-db'),'--no-defaults','--datadir='+str(p/'data'),'--auth-root-authentication-method=normal','--skip-test-db']
    if os.environ.get('MARIADB_BASEDIR'):args.append('--basedir='+os.environ['MARIADB_BASEDIR'])
    with open(p/'mariadb.log','w') as log:
        subprocess.run(args,env=env,stdout=log,stderr=log,check=True,timeout=60)
        daemon=subprocess.Popen([os.environ.get('MARIADBD_BIN','mariadbd'),'--no-defaults','--user='+pwd.getpwuid(os.geteuid()).pw_name,'--datadir='+str(p/'data'),'--bind-address=127.0.0.1','--port='+str(port),'--socket=','--pid-file='+str(p/'db.pid')],env=env,stdout=log,stderr=log)
        try:
            for _ in range(150):
                assert daemon.poll() is None,(p/'mariadb.log').read_text()
                r=subprocess.run([PHP,'-r',f'$d=new PDO("mysql:host=127.0.0.1;port={port}","root","");$d->exec("CREATE DATABASE dashboard_perf_test");'],env=env,capture_output=True)
                if r.returncode==0:break
                time.sleep(.1)
            else:raise AssertionError('Owned database unavailable')
            sql=(ROOT/'tests/fixtures/application-performance.sql').read_text()+(ROOT/'database/migrations/013_peer_matching.sql').read_text()
            sql+='''
INSERT INTO pm_user_study_prefs(user_id,study_style) SELECT seq,IF(MOD(seq,2),'group','solo') FROM seq_1_to_900 WHERE MOD(seq,5)<>0;
INSERT IGNORE INTO pm_subjects(id,name,slug) VALUES(9001,'Fixture A','fixture-a'),(9002,'Fixture B','fixture-b');
INSERT IGNORE INTO pm_interest_tags(id,name,slug) VALUES(9001,'Fixture Interest','fixture-interest');
INSERT IGNORE INTO pm_hobby_tags(id,name,slug) VALUES(9001,'Fixture Hobby','fixture-hobby');
INSERT INTO pm_user_subjects(user_id,subject_id,role,proficiency) SELECT seq,9001,IF(MOD(seq,2),'studying','tutoring'),'intermediate' FROM seq_1_to_900 WHERE MOD(seq,4)<>0;
INSERT INTO pm_user_subjects(user_id,subject_id,role,proficiency) SELECT seq,9002,'both','advanced' FROM seq_1_to_900 WHERE MOD(seq,3)=0;
INSERT INTO pm_user_interests(user_id,interest_id) SELECT seq,9001 FROM seq_1_to_900 WHERE MOD(seq,3)<>0;
INSERT INTO pm_user_hobbies(user_id,hobby_id) SELECT seq,9001 FROM seq_1_to_900 WHERE MOD(seq,7)<>0;
'''
            setup=p/'setup.sql';setup.write_text(sql)
            subprocess.run([PHP,'-r','$d=new PDO(getenv("DASHBOARD_PERF_DSN"),"root","",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$d->exec(file_get_contents($argv[1]));',str(setup)],env=env,cwd=ROOT,check=True,timeout=30)
            source=subprocess.check_output(['git','show',BASE+':services/StudentDashboardService.php'],cwd=ROOT,text=True).replace('class StudentDashboardService','class BaselineStudentDashboardService').replace("dirname(__DIR__) . '/database/config/db.php'",repr(str(ROOT/'database/config/db.php'))).replace("__DIR__ . '/MembershipService.php'",repr(str(ROOT/'services/MembershipService.php')))
            baseline=p/'baseline-service.php';baseline.write_text(source);env['DASHBOARD_BASELINE_CLASS']=str(baseline)
            result=subprocess.run([PHP,str(ROOT/'tests/dashboard-query-profile.php')],env=env,cwd=ROOT,check=True,capture_output=True,text=True,timeout=120)
            query=json.loads(result.stdout)
            def dbsql(statement):
                r=subprocess.run([PHP,'-r','$d=new PDO(getenv("DASHBOARD_PERF_DSN"),"root","",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$d->exec($argv[1]);','--',statement],env=env,capture_output=True,text=True,timeout=30)
                assert r.returncode==0,r.stderr
            dbsql('ALTER TABLE messages DROP INDEX dashboard_unread')
            # Populate the real current workspace API as well as the compatibility endpoint.
            dbsql("ALTER TABLE study_room_sessions ADD channel_id INT DEFAULT 1;CREATE TABLE channel_members(channel_id INT,user_id BIGINT);CREATE TABLE moderation_actions(id INT,server_id INT,target_user_id BIGINT,action_type VARCHAR(20),is_active INT,expires_at DATETIME);CREATE TABLE calendar_events(id INT,user_id BIGINT,title TEXT,description TEXT,event_type VARCHAR(20),starts_at DATETIME,ends_at DATETIME);INSERT INTO calendar_events SELECT seq,1,'Fixture event','Synthetic','personal',NOW(),DATE_ADD(NOW(),INTERVAL 1 HOUR) FROM seq_1_to_100;INSERT INTO student_notes SELECT seq+1,1,CONCAT('Note ',seq+1),'Synthetic note',NOW(),1 FROM seq_1_to_199;INSERT INTO notifications SELECT seq+1,1,'Update','Synthetic body',MOD(seq,2),'N','message','/synthetic',NOW() FROM seq_1_to_99")
            env['DASHBOARD_FIXED_CLOCK']=str(int(time.time()))
            spec=importlib.util.spec_from_file_location('dashboard_http',ROOT/'tests/dashboard-http-compare.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
            evidence=module.exercise(ROOT,p,env,PHP,dbsql);evidence['query_experiments']=query
            print('PASS actual HTTP payload/notification/unread equality, fresh requests/users, additive migration twice; query distributions match')
            print('DASHBOARD_HTTP_SUMMARY '+json.dumps({name:{key:statistics.median(r[key] for r in runs) for key in ['http_ms','php_ms','php_cpu_ms','encode_ms','peak_delta_bytes','payload_bytes']} for name,runs in evidence['http'].items()}),flush=True)
            pathlib.Path(os.environ['DASHBOARD_QUERY_EVIDENCE']).write_text(json.dumps(evidence,indent=2)+'\n')
        finally:
            daemon.terminate();daemon.wait(timeout=15)
