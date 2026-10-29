"""Explicit Phase3AD repair scope. Historical guards remain active otherwise."""
import os
import hashlib
import json
import pathlib
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
BASE = 'cb94769a6af87a356a069a2c949b5bd7b5c73e6e'
OTP = '7013114a3a7bc0d183c01fc92e0687f06202d3a7'
ALLOWED = {'services/MessageService.php', 'API/chat/send-message.php', 'API/chat/vote-poll.php', 'database/migrations/068_message_code_poll_types.sql'}

EXPECTED_HASHES = {'services/MessageService.php': 'ccde7647a26f0596e60b0e7ecd98e17decc86089390ae153c82fb1ba08fbfe91', 'API/chat/send-message.php': '6c8e5f6c35e27eab15cac34a4a8bd8cd79a35cfe59025752a6bac30b87a45172', 'API/chat/vote-poll.php': '46a3b3ccca9c5f6efa0dda753a9ce6fdaed054a6330372867825b0d1ec931481', 'database/migrations/068_message_code_poll_types.sql': '90b417fd89d9d510bd34e732809349256c5aa26a0e5d3081693d553460c1c37a'}

def validate():
    if os.environ.get('PHASE3AD_IDENTITY')=='1':
        return __import__('runpy').run_path(str(ROOT/'tests/phase3ad-identity-scope.py'))['validate']()
    def git(*args):
        return subprocess.check_output(['git', *args], cwd=ROOT, text=True).strip()
    assert git('merge-base', BASE, 'HEAD') == BASE, 'Accepted PR100 must be an ancestor'
    paths = git('diff', '--name-only', BASE).splitlines()
    runtime = [p for p in paths if not p.startswith(('tests/', 'docs/', '.github/'))]
    assert set(runtime) == ALLOWED, 'Unauthorized or incomplete runtime delta: ' + repr(runtime)
    for path, expected_hash in EXPECTED_HASHES.items():
        assert hashlib.sha256((ROOT/path).read_bytes()).hexdigest() == expected_hash, 'Unauthorized bytes: ' + path
    # Compare working bytes, so an uncommitted OTP substitution cannot escape.
    expected = subprocess.check_output(['git', 'show', BASE + ':security/forgot-password/OtpService.php'], cwd=ROOT)
    assert (ROOT / 'security/forgot-password/OtpService.php').read_bytes() == expected
    assert git('rev-parse', BASE + ':security/forgot-password/OtpService.php') == OTP
    migrations = sorted(p.name for p in (ROOT / 'database/migrations').glob('*.sql'))
    original = git('ls-tree', '-r', '--name-only', BASE, '--', 'database/migrations').splitlines()
    added = set(migrations) - {pathlib.PurePosixPath(p).name for p in original}
    assert added <= {'068_message_code_poll_types.sql'}, 'Unapproved active migration'
    return {'status': 'PASS', 'base': BASE, 'head': git('rev-parse', 'HEAD'),
            'tree': git('rev-parse', 'HEAD^{tree}'), 'allowed_runtime': sorted(ALLOWED),
            'actual_runtime': runtime, 'otp_blob': OTP, 'working_diff': git('diff', 'HEAD'),
            'hashes': {p: hashlib.sha256((ROOT / p).read_bytes()).hexdigest()
                       for p in sorted(ALLOWED) if (ROOT / p).exists()}}


if __name__ == '__main__':
    result = validate()
    if len(sys.argv) > 1:
        pathlib.Path(sys.argv[1]).write_text(json.dumps(result, indent=2) + '\n')
    print(json.dumps(result))
