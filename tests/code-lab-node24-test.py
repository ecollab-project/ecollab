"""Offline profile compatibility checks. Real execution requires isolated staging CI."""
import importlib.util
import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
path = ROOT / 'deploy/code-lab/piston/node24/profile.py'
spec = importlib.util.spec_from_file_location('node24_profile', path)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


def base():
    profiles = [
        {'id': id_, 'name': name, 'language': lang, 'version': ver, 'filename': file}
        for id_, name, lang, ver, file in [
            (10001, 'C', 'c', '10.2.0', 'main.c'),
            (10002, 'C++', 'c++', '10.2.0', 'main.cpp'),
            (10003, 'Python', 'python', '3.12.0', 'main.py'),
            (10004, 'Java', 'java', '15.0.2', 'Main.java'),
            (10005, 'JavaScript', 'javascript', '20.11.1', 'main.js'),
            (10006, 'Compiled Java', 'ecollab-java-compiled', '1.0.0', 'Main.java'),
        ]
    ]
    return {'token': 'a' * 64, 'profiles': profiles}


class Node24ProfileTests(unittest.TestCase):
    available = [{'language': 'ecollab-node24', 'version': '24.22.1'}]

    def test_add_without_mutating_existing_profiles(self):
        original = base()
        result = module.upgrade(original, self.available)
        self.assertEqual(result['profiles'][:6], original['profiles'])
        self.assertEqual(len(original['profiles']), 6)
        self.assertEqual(result['profiles'][6], module.NODE24)
        self.assertEqual(result['token'], original['token'])

    def test_idempotent(self):
        once = module.upgrade(base(), self.available)
        self.assertEqual(module.upgrade(once, self.available), once)

    def test_runtime_missing_fails_closed(self):
        with self.assertRaises(ValueError):
            module.upgrade(base(), [])

    def test_legacy_version_changed_fails_closed(self):
        changed = base()
        changed['profiles'][4]['version'] = '24.22.1'
        with self.assertRaises(ValueError):
            module.upgrade(changed, self.available)

    def test_node24_mapping_cannot_be_rebound(self):
        changed = module.upgrade(base(), self.available)
        changed['profiles'][6]['language'] = 'javascript'
        with self.assertRaises(ValueError):
            module.upgrade(changed, self.available)

    def test_duplicate_id_fails_closed(self):
        changed = base()
        changed['profiles'].append(dict(changed['profiles'][0]))
        with self.assertRaises(ValueError):
            module.upgrade(changed, self.available)


if __name__ == '__main__':
    unittest.main()
