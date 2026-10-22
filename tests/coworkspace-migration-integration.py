"""Own a disposable MariaDB; never discover or connect to a deployed database."""
import os, pathlib, pwd, socket, subprocess, tempfile, time
ROOT = pathlib.Path(__file__).resolve().parents[1]
php = os.environ.get('PHP_BIN', 'php')
server_bin = os.environ.get('MARIADBD_BIN', 'mariadbd')
installer = os.environ.get('MARIADB_INSTALL_DB_BIN', 'mariadb-install-db')
with tempfile.TemporaryDirectory(prefix='coworkspace025-') as folder:
    p = pathlib.Path(folder)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
    env = os.environ | {'APP_DEBUG': 'true', 'APP_ENV': 'testing', 'COWORKSPACE_TEST_DSN': f'mysql:host=127.0.0.1;port={port};dbname=coworkspace_025_test'}
    with open(p/'db.log', 'w') as log:
        args = [installer, '--no-defaults', '--datadir='+str(p/'data'), '--auth-root-authentication-method=normal', '--skip-test-db']
        if env.get('MARIADB_BASEDIR'): args.append('--basedir='+env['MARIADB_BASEDIR'])
        subprocess.run(args, env=env, stdout=log, stderr=log, check=True, timeout=60)
        server = subprocess.Popen([server_bin, '--no-defaults', '--user='+pwd.getpwuid(os.geteuid()).pw_name,
            '--datadir='+str(p/'data'), '--bind-address=127.0.0.1', '--port='+str(port), '--socket=',
            '--pid-file='+str(p/'db.pid')], env=env, stdout=log, stderr=log)
        try:
            for _ in range(150):
                assert server.poll() is None, (p/'db.log').read_text()
                probe = subprocess.run([php, '-r', f'$d=new PDO("mysql:host=127.0.0.1;port={port}","root","");$d->query("SELECT 1");'], env=env, capture_output=True)
                if probe.returncode == 0: break
                time.sleep(.1)
            else: raise AssertionError('Disposable MariaDB not ready')
            subprocess.run([php, '-r', f'$d=new PDO("mysql:host=127.0.0.1;port={port}","root","");$d->exec("CREATE DATABASE coworkspace_025_test");'], env=env, check=True)
            test = subprocess.Popen([php, str(ROOT/'tests/coworkspace-migration-regression.php')], env=env, cwd=ROOT, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
            for line in test.stdout:
                print(line, end='', flush=True)
                if line.strip() == 'FIXTURE_STOP_DATABASE':
                    server.terminate(); server.wait(timeout=15)
                    test.stdin.write('continue\n'); test.stdin.flush()
                elif line.strip() == 'FIXTURE_START_DATABASE':
                    server = subprocess.Popen([server_bin, '--no-defaults', '--user='+pwd.getpwuid(os.geteuid()).pw_name,
                        '--datadir='+str(p/'data'), '--bind-address=127.0.0.1', '--port='+str(port), '--socket=',
                        '--pid-file='+str(p/'db.pid')], env=env, stdout=log, stderr=log)
                    for _ in range(150):
                        assert server.poll() is None, 'Restarted disposable MariaDB exited'
                        probe = subprocess.run([php, '-r', f'$d=new PDO("mysql:host=127.0.0.1;port={port}","root","");$d->query("SELECT 1");'], env=env, capture_output=True)
                        if probe.returncode == 0: break
                        time.sleep(.1)
                    else: raise AssertionError('Restarted disposable MariaDB not ready')
                    test.stdin.write('continue\n'); test.stdin.flush()
            assert test.wait(timeout=10) == 0, 'Coworkspace migration tests failed' 
        finally:
            server.terminate(); server.wait(timeout=15)
