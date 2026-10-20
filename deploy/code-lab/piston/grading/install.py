"""Apply a small, fail-closed extension only to the reviewed pinned Piston source."""
import hashlib
import pathlib
import re
import subprocess

BASE = pathlib.Path(__file__).resolve().parent
HASHES = {
    'job.js': '163a68e17bc577eaf165391f460b976a6555105351b1658f86d331bc12eccd24',
    'api/v2.js': 'f7b155d34b3ba7d48358334a0c4c7497c518e09ab58a186040050f1652d99c48',
}


def extend(original, name):
    original = re.sub(r'^[ \t]*// ECOLLAB_GRADE_V1_BEGIN\n.*?^[ \t]*// ECOLLAB_GRADE_V1_END\n', '', original, flags=re.M | re.S)
    if hashlib.sha256(original.encode()).hexdigest() != HASHES[name]:
        raise RuntimeError('Unreviewed Piston source: ' + name)
    anchor = '    async execute(box, event_bus = null) {' if name == 'job.js' else 'module.exports = router;'
    if original.count(anchor) != 1:
        raise RuntimeError('Unsupported Piston extension point')
    fragment = (BASE / ('job-method.js' if name == 'job.js' else 'route.js')).read_text().rstrip('\n') + '\n'
    return original.replace(anchor, fragment + anchor)


def install(root=pathlib.Path('/piston_api/src')):
    updates = {name: extend((root / name).read_text(), name) for name in HASHES}
    for name, content in updates.items():
        path = root / name
        path.write_text(content)
        subprocess.run(['node', '--check', str(path)], check=True, capture_output=True)


if __name__ == '__main__':
    install()
