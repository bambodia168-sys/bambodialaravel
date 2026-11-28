#!/usr/bin/env python3
"""Verify a pinned baseline manifest, allowing only one exact controller change."""
import argparse
import hashlib
import pathlib
import re
import sys
import tempfile
import unittest

CONTROLLER = 'app/Http/Controllers/Api/BambodiaMcpController.php'
OLD = '832c692c12ef23e3584cc820512619383822c406'
NEW = 'a302fa364f1f52b6320b77688b7595b91894ca13'


def require(ok, code):
    if not ok:
        raise ValueError(code)


def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode('ascii') + b'\0' + data).hexdigest()


def verify(root, manifest, phase, count=653, old=OLD, new=NEW):
    require(phase in ('before', 'after'), 'INVALID_PHASE')
    entries = {}
    for line in manifest.read_text().splitlines():
        m = re.fullmatch(r'([0-9a-f]{64})  (.+)', line)
        require(m is not None, 'INVALID_MANIFEST_LINE')
        digest, name = m.groups()
        if name.startswith('./'):
            name = name[2:]
        require(all(part not in ('', '.', '..') for part in name.split('/')) and not any(c in name for c in '\\\r\n\t'), 'UNSAFE_MANIFEST_PATH')
        require(name not in entries, 'DUPLICATE_NORMALIZED_PATH')
        entries[name] = digest
    require(len(entries) == count and CONTROLLER in entries, 'MANIFEST_INVENTORY_DRIFT')
    require(root.is_dir() and root.resolve() == root, 'UNSAFE_ROOT')
    checked = 0
    for name, digest in entries.items():
        p = root / name
        require(p.is_file() and not any(x.is_symlink() for x in (p, *p.parents)), 'SOURCE_PATH_DRIFT:' + name)
        data = p.read_bytes()
        if name == CONTROLLER:
            require(blob(data) == (old if phase == 'before' else new), 'CONTROLLER_BLOB_DRIFT')
            if phase == 'after':
                continue
        require(hashlib.sha256(data).hexdigest() == digest, 'SOURCE_BYTES_DRIFT:' + name)
        checked += 1
    require(checked == count - (phase == 'after'), 'SOURCE_COUNT_DRIFT')
    return checked


class Regression(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = pathlib.Path(self.tmp.name).resolve() / 'live'
        self.ctrl = self.root / CONTROLLER
        self.ctrl.parent.mkdir(parents=True)
        self.ctrl.write_bytes(b'old controller')
        self.other = self.root / 'unchanged.php'
        self.other.write_bytes(b'unchanged')
        self.manifest = self.root.parent / 'manifest'
        self.lines = [hashlib.sha256(p.read_bytes()).hexdigest() + '  ./' + str(p.relative_to(self.root)) for p in (self.ctrl, self.other)]
        self.manifest.write_text('\n'.join(self.lines) + '\n')

    def check(self, phase):
        return verify(self.root, self.manifest, phase, 2, blob(b'old controller'), blob(b'new controller'))

    def test_baseline(self):
        self.assertEqual(self.check('before'), 2)

    def test_exact_controller_exclusion(self):
        self.ctrl.write_bytes(b'new controller')
        self.assertEqual(self.check('after'), 1)

    def test_unrelated_drift(self):
        self.ctrl.write_bytes(b'new controller')
        self.other.write_bytes(b'drift')
        with self.assertRaisesRegex(ValueError, 'SOURCE_BYTES_DRIFT'):
            self.check('after')

    def test_candidate_fingerprint(self):
        self.ctrl.write_bytes(b'wrong controller')
        with self.assertRaisesRegex(ValueError, 'CONTROLLER_BLOB_DRIFT'):
            self.check('after')

    def test_duplicate_alias(self):
        self.manifest.write_text('\n'.join(self.lines + [self.lines[0].replace('  ./', '  ')]) + '\n')
        with self.assertRaisesRegex(ValueError, 'DUPLICATE_NORMALIZED_PATH'):
            self.check('after')

    def test_missing_controller(self):
        self.manifest.write_text(self.lines[1] + '\n')
        with self.assertRaisesRegex(ValueError, 'MANIFEST_INVENTORY_DRIFT'):
            self.check('after')

    def test_no_prefix(self):
        self.manifest.write_text('\n'.join(line.replace('  ./', '  ') for line in self.lines) + '\n')
        self.ctrl.write_bytes(b'new controller')
        self.assertEqual(self.check('after'), 1)

    def test_symlink_rejected(self):
        self.other.unlink()
        self.other.symlink_to(self.ctrl)
        with self.assertRaisesRegex(ValueError, 'SOURCE_PATH_DRIFT'):
            self.check('before')

    def test_traversal_rejected(self):
        self.manifest.write_text('\n'.join([self.lines[0], self.lines[1].replace('  ./', '  ../')]) + '\n')
        with self.assertRaisesRegex(ValueError, 'UNSAFE_MANIFEST_PATH'):
            self.check('before')


if __name__ == '__main__':
    if sys.argv[1:] == ['--self-test']:
        unittest.main(argv=[sys.argv[0]], verbosity=2)
    else:
        parser = argparse.ArgumentParser()
        parser.add_argument('root', type=pathlib.Path)
        parser.add_argument('manifest', type=pathlib.Path)
        parser.add_argument('phase', choices=['before', 'after'])
        args = parser.parse_args()
        try:
            n = verify(args.root, args.manifest, args.phase)
        except (ValueError, OSError) as exc:
            print('MANIFEST_VERIFICATION_BLOCKED=' + (str(exc) if isinstance(exc, ValueError) else 'FILE_IO'), file=sys.stderr)
            sys.exit(1)
        print('BASELINE_SOURCE_HASHES_VERIFIED=' + str(n))
        print('CONTROLLER_FINGERPRINT=PASS')
        print('MANIFEST_NORMALIZATION=PASS')
