"""Own one disposable MariaDB process for extended real-broker/CLI-worker tests.
Requires an explicitly supplied expendable MariaDB datadir, never host discovery.
"""
import os, pathlib, pwd, socket, subprocess, tempfile, time
ROOT = pathlib.Path(__file__).resolve().parents[1]
for key in ['MARIADBD_BIN', 'MARIADB_TEST_DATADIR', 'PHP_BIN']:
    assert os.environ.get(key), 'Explicit local fixture dependency required: '+key
with tempfile.TemporaryDirectory(prefix='broker-revocation-db-') as folder:
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0)); port = s.getsockname()[1]
    env = os.environ | {'TEST_EXTENDED_REVOCATION': '1',
        'BROKER_TEST_DSN': f'mysql:host=127.0.0.1;port={port};dbname=ecollab_broker_test'}
    with open(pathlib.Path(folder)/'maria.log', 'w') as log:
        server = subprocess.Popen([env['MARIADBD_BIN'], '--no-defaults',
            '--user='+pwd.getpwuid(os.geteuid()).pw_name, '--datadir='+env['MARIADB_TEST_DATADIR'],
            '--bind-address=127.0.0.1', '--port='+str(port), '--socket=',
            '--pid-file='+str(pathlib.Path(folder)/'maria.pid'), '--skip-grant-tables'], env=env, stdout=log, stderr=log)
        try:
            for _ in range(150):
                assert server.poll() is None, 'Disposable DB exited'
                try:
                    with socket.create_connection(('127.0.0.1', port), .1): break
                except OSError: time.sleep(.05)
            else: raise AssertionError('Disposable DB unavailable')
            subprocess.run([env['PHP_BIN'], '-r', '$d=new PDO("mysql:host=127.0.0.1;port='+str(port)+'","root","");$d->exec("DROP DATABASE IF EXISTS ecollab_broker_test;CREATE DATABASE ecollab_broker_test");'], env=env, check=True)
            subprocess.run(['python3', '-u', str(ROOT/'tests/centrifugo-proxy-regression.py')], cwd=ROOT, env=env, check=True, timeout=350)
        finally:
            server.terminate(); server.wait(timeout=15)
