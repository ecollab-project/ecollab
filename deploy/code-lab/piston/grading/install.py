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


# Reversible launch patches: only live runs get the PTY helper inside isolate.
DIR_LINE='                '+chr(96)+'--dir=$'+'{this.runtime.pkgdir}'+chr(96)+',\n'
COMMAND="                '/bin/bash',\n                path.join(this.runtime.pkgdir, file),\n                ...args,\n"
LIVE_PATCHES={
 'dir':(DIR_LINE,DIR_LINE+"                ...(this.ecollab_live ? ['--dir=/ecollab-grade:noexec'] : []),\n"),
 'command':(COMMAND,"                ...(this.ecollab_live && file === 'run'\n                    ? ['/usr/bin/python3', '/ecollab-grade/terminal.py', '/bin/bash', path.join(this.runtime.pkgdir, file), ...args]\n                    : ['/bin/bash', path.join(this.runtime.pkgdir, file), ...args]),\n")
}


def extend(original, name):
    original = re.sub(r'^[ \t]*// ECOLLAB_GRADE_V1_BEGIN\n.*?^[ \t]*// ECOLLAB_GRADE_V1_END\n', '', original, flags=re.M | re.S)
    if name=='job.js':
        for key,(old,new) in LIVE_PATCHES.items():
            original=re.sub(r'^[ \t]*// ECOLLAB_LIVE_PATCH_BEGIN:'+key+r'\n.*?^[ \t]*// ECOLLAB_LIVE_PATCH_END:'+key+r'\n',lambda _:old,original,flags=re.M|re.S)
    if hashlib.sha256(original.encode()).hexdigest() != HASHES[name]:
        raise RuntimeError('Unreviewed Piston source: ' + name)
    anchor = '    async execute(box, event_bus = null) {' if name == 'job.js' else 'module.exports = router;'
    if original.count(anchor) != 1:
        raise RuntimeError('Unsupported Piston extension point')
    fragment = (BASE / ('job-method.js' if name == 'job.js' else 'route.js')).read_text().rstrip('\n') + '\n'
    original=original.replace(anchor, fragment + anchor)
    if name=='job.js':
        for key,(old,new) in LIVE_PATCHES.items():
            if original.count(old)!=1:raise RuntimeError('Unsupported PTY patch: '+key)
            original=original.replace(old,'                // ECOLLAB_LIVE_PATCH_BEGIN:'+key+'\n'+new+'                // ECOLLAB_LIVE_PATCH_END:'+key+'\n')
    return original


def install(root=pathlib.Path('/piston_api/src')):
    updates = {name: extend((root / name).read_text(), name) for name in HASHES}
    for name, content in updates.items():
        path = root / name
        path.write_text(content)
        subprocess.run(['node', '--check', str(path)], check=True, capture_output=True)


if __name__ == '__main__':
    install()
