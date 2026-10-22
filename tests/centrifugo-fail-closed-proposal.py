"""Build/test an explicitly supplied, disposable upstream experiment; never deploy it.
The production broker binary/configuration and application sources are not modified.
"""
import hashlib, os, pathlib, shutil, subprocess, tempfile
ROOT = pathlib.Path(__file__).resolve().parents[1]
source = pathlib.Path(os.environ['CENTRIFUGO_PROPOSAL_SOURCE_DIR']).resolve()
go = os.environ['GO_BIN']
expected = {
    'refresh_handler.go': '1976c41706b6a66f5a3242ffa9d88e8b0e97f59475ca2a49c555b76201e09e26',
    'refresh_handler_test.go': '74139ff9a656b753e4fb5abb628c751581d0e9cee30d6c43997fc733160a6fad',
}
for name, digest in expected.items():
    assert hashlib.sha256((source/'internal/proxy'/name).read_bytes()).hexdigest() == digest, 'Unexpected upstream source: '+name
with tempfile.TemporaryDirectory(prefix='centrifugo-proposal-') as folder:
    work = pathlib.Path(folder)/'source'
    shutil.copytree(source, work, ignore=shutil.ignore_patterns('.git'))
    subprocess.run(['patch', '-p1', '--batch', '--forward', '-i', str(ROOT/'tests/proposals/centrifugo-6.9.6-fail-closed.patch')], cwd=work, check=True)
    env = os.environ | {'GOTOOLCHAIN': 'local', 'CGO_ENABLED': '0'}
    # All upstream proxy tests, including original tests with changed error semantics.
    subprocess.run([go, 'test', '-p', '2', './internal/proxy'], cwd=work, env=env, check=True, timeout=600)
    binary = pathlib.Path(folder)/'centrifugo'
    subprocess.run([go, 'build', '-p', '2', '-ldflags=-X github.com/centrifugal/centrifugo/v6/internal/build.Version=6.9.6-phase3e-proposal', '-o', str(binary), '.'], cwd=work, env=env, check=True, timeout=600)
    print('EXPERIMENTAL broker SHA256='+hashlib.sha256(binary.read_bytes()).hexdigest(), flush=True)
    subprocess.run(['python3', '-u', str(ROOT/'tests/centrifugo-proxy-regression.py')], cwd=ROOT,
        env=env | {'CENTRIFUGO_BIN': str(binary), 'TEST_FAIL_CLOSED_PROPOSAL': '1'}, check=True, timeout=120)
