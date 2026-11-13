"""Synthetic-only tests for Bambodia MCP recovery preflights.

No production app path or DB is accessed. No backup, chmod or restore occurs.
"""
import importlib.util
import os
from pathlib import Path
import stat
import tempfile
import unittest

REPO = Path(__file__).resolve().parents[2]


def load_module(name, relative):
    spec = importlib.util.spec_from_file_location(name, REPO / relative)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


review = load_module(
    "bambodia_private_review",
    "support/bambodia-mcp-private-file-review-local-20261113.py",
)
approval = load_module(
    "bambodia_recovery_approval",
    "support/bambodia-mcp-offsite-backup-approval-preflight-20261113.py",
)


class PrivateFileReviewTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="bambodia-synthetic-recovery-")
        self.base = Path(self.temp.name)
        # Expose only disposable fixture path for mode-bit traversal tests.
        os.chmod(self.base, 0o755)
        self.root = self.base / "private"
        self.root.mkdir(mode=0o755)
        self.file = self.root / "synthetic.example"
        self.file.write_text("Synthetic fixture; no customer content.", encoding="utf-8")
        self.file.chmod(0o644)

    def tearDown(self):
        self.temp.cleanup()

    def test_detect_one_other_readable_file_and_not_modify_it(self):
        before = self.file.stat()
        stats, hits = review.inspect_private(self.root)
        after = self.file.stat()
        self.assertEqual(1, stats["regular"])
        self.assertEqual(1, stats["other_readable_traversable"])
        self.assertEqual(1, len(hits))
        self.assertEqual(before.st_mode, after.st_mode)
        self.assertEqual(before.st_ino, after.st_ino)

    def test_private_file_not_flagged(self):
        self.file.chmod(0o600)
        stats, hits = review.inspect_private(self.root)
        self.assertEqual(0, stats["other_readable_traversable"])
        self.assertEqual([], hits)

    def test_symlinks_skipped(self):
        (self.root / "link.example").symlink_to(self.file)
        stats, hits = review.inspect_private(self.root)
        self.assertEqual(1, stats["links_skipped"])
        self.assertEqual(1, stats["regular"])
        self.assertEqual(1, len(hits))

    def test_no_traverse_no_other_readable_path(self):
        self.root.chmod(0o700)
        stats, hits = review.inspect_private(self.root)
        self.assertEqual(0, stats["other_readable_traversable"])
        self.assertEqual([], hits)

    def test_fail_closed_on_limit(self):
        with self.assertRaises(review.Blocked):
            review.inspect_private(self.root, max_files=0)

    def test_root_symlink_rejected(self):
        link = self.base / "private-link"
        link.symlink_to(self.root, target_is_directory=True)
        with self.assertRaises(review.Blocked):
            review.inspect_private(link)


class BackupApprovalTests(unittest.TestCase):
    def test_missing_approvals_fail_closed(self):
        self.assertFalse(approval.validate_approval({}))

    def test_bad_local_destination_is_blocked(self):
        config = {"repository": "/www/backup/database", "encryption_engine": "restic"}
        self.assertFalse(approval.validate_approval(config))

    def test_absent_encryption_key_is_blocked(self):
        config = {"repository": "sftp:backup.example.invalid:/private",
                  "encryption_engine": "restic",
                  "restic_password_file": "/root/does-not-exist"}
        self.assertFalse(approval.validate_approval(config))


if __name__ == "__main__":
    unittest.main()
