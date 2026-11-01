"""Read-only reconciliation of immutable hosted evidence and later derived reports.

Never execute artifact contents or rewrite the input archives. The allowlist is
limited to summary hash maps; every other changed byte blocks reconciliation.
"""
import argparse
import gzip
import hashlib
import json
import pathlib
import stat
import tarfile
import zipfile

HEAD = 'f9e63ff750c0a6c6209810e1e37167105875d371'
TREE = 'a8502d5da68184d3aa2504231e8083ad88df156f'
ZIP_SHA = 'e40036c33bbd1706c3f4441523f6353d8112413753788bd9452e5b5ed49f93e2'
TAR_SHA = '7784ec90949873335eaf53052330c52dc58a84d9087072edfceb434848bbee87'
HASH_FIELDS = {'recomputed-summary.json': 'raw_sha256',
               'integration-summary.json': 'file_sha256',
               'phase3af-summary.json': 'raw_sha256'}


def digest(data):
    return hashlib.sha256(data).hexdigest()


def safe(name):
    p = pathlib.PurePosixPath(name)
    assert not p.is_absolute() and '..' not in p.parts
    assert '\\' not in name and ':' not in name
    return name


def references(data, records):
    result = []
    for name, expected in data.items():
        actual = digest(records[name]) if name in records else None
        result.append({'path': name, 'expected_sha256': expected,
                       'actual_sha256': actual, 'matches_snapshot': expected == actual,
                       'kind': 'derived_summary' if name in HASH_FIELDS else 'source_record'})
    return result


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('hosted_zip', type=pathlib.Path)
    p.add_argument('published_tar', type=pathlib.Path)
    p.add_argument('output', type=pathlib.Path)
    args = p.parse_args()
    assert digest(args.hosted_zip.read_bytes()) == ZIP_SHA, 'Unexpected hosted ZIP'
    assert digest(args.published_tar.read_bytes()) == TAR_SHA, 'Unexpected derived archive'
    original, derived = {}, {}
    with zipfile.ZipFile(args.hosted_zip) as z:
        for item in z.infolist():
            name = safe(item.filename)
            assert not stat.S_ISLNK(item.external_attr >> 16)
            if not item.is_dir():
                assert name not in original, 'Duplicate ZIP member'
                original[name] = z.read(item)
    with tarfile.open(args.published_tar, 'r:gz') as t:
        for item in t.getmembers():
            name = safe(item.name)
            assert item.isfile(), 'Unexpected non-file TAR member'
            assert name not in derived, 'Duplicate TAR member'
            derived[name] = t.extractfile(item).read()
    source = json.loads(original['phase3af-source.json'])
    assert source['head'] == HEAD and source['tree'] == TREE
    changed = [n for n in sorted(set(original) & set(derived)) if original[n] != derived[n]]
    assert set(changed) == set(HASH_FIELDS), ('Unexpected changed records', changed)
    changes, chain = [], {}
    for name, field in HASH_FIELDS.items():
        before, after = json.loads(original[name]), json.loads(derived[name])
        original_refs = before.pop(field)
        later_refs = after.pop(field)
        assert before == after, 'Non-hash summary payload changed: ' + name
        before_refs = references(original_refs, original)
        after_refs = references(later_refs, derived)
        assert all(r['matches_snapshot'] for r in before_refs), 'Original hash chain mismatch'
        # Derived reporters hash each other, and sometimes themselves, before
        # downstream writes. Do not certify these maps as a current-file manifest.
        assert all(r['matches_snapshot'] for r in after_refs if r['kind'] == 'source_record')
        chain[name] = {'original': before_refs, 'post_recomputation': after_refs}
        changes.append({'path': name, 'allowed_changed_field': field,
                        'original_sha256': digest(original[name]),
                        'post_recomputation_sha256': digest(derived[name]),
                        'non_hash_payload_equal': True,
                        'added_hash_keys': sorted(set(later_refs) - set(original_refs)),
                        'removed_hash_keys': sorted(set(original_refs) - set(later_refs)),
                        'changed_hash_keys': sorted(k for k in set(original_refs) & set(later_refs)
                                                   if original_refs[k] != later_refs[k])})
    args.output.mkdir(parents=True, exist_ok=True)
    for name in HASH_FIELDS:
        (args.output / ('original-' + name + '.gz')).write_bytes(gzip.compress(original[name], mtime=0))
    manifests = {
        'original_hosted_zip': {n: {'bytes': len(b), 'sha256': digest(b)} for n, b in sorted(original.items())},
        'post_recomputation_archive': {n: {'bytes': len(b), 'sha256': digest(b)} for n, b in sorted(derived.items())}}
    report = {'status': 'PASS — provenance reconciled; environment gate unaffected',
              'head': HEAD, 'tree': TREE, 'hosted_zip_sha256': ZIP_SHA,
              'published_derived_tar_sha256': TAR_SHA,
              'input_archives_modified': False, 'changed_records': changes,
              'reference_checks': chain, 'manifests': manifests,
              'qualification': 'Four derived-summary snapshot references are stale relative to the final derived archive; all source-record references match. Original hosted summaries and their references are intact. Use these independent manifests for byte identity, not mutually hashing derived summaries.',
              'environment': 'BLOCKED — selected VPS not inspected or authorized for operations'}
    (args.output / 'provenance-ledger.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps({'status': report['status'], 'changed_records': len(changes),
                      'original_reference_counts': {n: len(v['original']) for n, v in chain.items()},
                      'derived_stale_references': sum(not r['matches_snapshot'] for v in chain.values() for r in v['post_recomputation'])}))


if __name__ == '__main__':
    main()
