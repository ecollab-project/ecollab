"""Disposable MariaDB owner. Never connects to a supplied database or deployed service."""
import json, os, pathlib, pwd, socket, subprocess, tempfile, time
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_BIN','php')
BASE='9d5ded1af8ee55364fa87d9c9a05327ffb9193c9'
with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
with tempfile.TemporaryDirectory(prefix='ecollab-perf-') as folder:
    p=pathlib.Path(folder);env=os.environ|{'APP_ENV':'testing','APPLICATION_PERF_DSN':f'mysql:host=127.0.0.1;port={port};dbname=application_perf_test','APPLICATION_PERF_BASELINE':str(p),'APPLICATION_PERF_EVIDENCE':os.environ.get('APPLICATION_PERF_EVIDENCE',str(p/'evidence.json'))}
    args=[os.environ.get('MARIADB_INSTALL_DB_BIN','mariadb-install-db'),'--no-defaults','--datadir='+str(p/'data'),'--auth-root-authentication-method=normal','--skip-test-db']
    if os.environ.get('MARIADB_BASEDIR'):args.append('--basedir='+os.environ['MARIADB_BASEDIR'])
    with open(p/'mariadb.log','w') as log:
        subprocess.run(args,env=env,stdout=log,stderr=log,check=True,timeout=60)
        daemon=subprocess.Popen([os.environ.get('MARIADBD_BIN','mariadbd'),'--no-defaults','--user='+pwd.getpwuid(os.geteuid()).pw_name,'--datadir='+str(p/'data'),'--bind-address=127.0.0.1','--port='+str(port),'--socket=','--pid-file='+str(p/'db.pid')],env=env,stdout=log,stderr=log)
        try:
            for _ in range(150):
                assert daemon.poll() is None,(p/'mariadb.log').read_text()
                r=subprocess.run([PHP,'-r',f'$d=new PDO("mysql:host=127.0.0.1;port={port}","root","");$d->exec("CREATE DATABASE application_perf_test");'],env=env,capture_output=True)
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
            subprocess.run([PHP,'-r','$d=new PDO(getenv("APPLICATION_PERF_DSN"),"root","",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$d->exec(file_get_contents($argv[1]));',str(setup)],env=env,cwd=ROOT,check=True,timeout=30)
            for name in ['StudentDashboardService','PeerMatchingService','JarredGroupRecommendationService','AdminDashboardService','FacilitatorDashboardService']:
                source=subprocess.check_output(['git','show',BASE+':services/'+name+'.php'],cwd=ROOT,text=True)
                source=source.replace('class '+name,'class Baseline'+name).replace("dirname(__DIR__) . '/database/config/db.php'",repr(str(ROOT/'database/config/db.php'))).replace("__DIR__ . '/MembershipService.php'",repr(str(ROOT/'services/MembershipService.php'))).replace("dirname(__DIR__).'/database/config/db.php'",repr(str(ROOT/'database/config/db.php'))).replace("dirname(__DIR__).'/services/PeerMatchingService.php'",repr(str(ROOT/'services/PeerMatchingService.php')))
                (p/(name+'.php')).write_text(source)
            subprocess.run([PHP,str(ROOT/'tests/application-performance.php')],env=env,cwd=ROOT,check=True,timeout=120)
        finally:
            daemon.terminate();daemon.wait(timeout=15)
