"""Offline unit tests for MCP install compatibility inspector."""
import importlib.util
from pathlib import Path
import stat
import tempfile
from types import SimpleNamespace
from unittest import TestCase
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
SCRIPT=ROOT/"support/bambodia-mcp-compatibility-review-readonly-20261114.py"
SPEC=importlib.util.spec_from_file_location("bambodia_compat",SCRIPT)
mod=importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(mod)

class CompatibilityInspectionTests(TestCase):
    def test_changed_lines_are_counted_without_printing_source(self):
        added,removed=mod.diff_size(b"alpha\nbeta\n",b"alpha\ngamma\ndelta\n")
        self.assertEqual((added,removed),(2,1))
        self.assertEqual(mod.diff_size(b"a\n",b"a\n"),(0,0))

    def test_unsafe_paths_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            p=Path(d)
            for path in ("../etc/passwd","/etc/passwd","a/../../etc/passwd"):
                with self.assertRaises(mod.Blocked):
                    mod.file_bytes(p,path)

    def test_does_not_follow_source_symlinks(self):
        with tempfile.TemporaryDirectory() as d:
            base=Path(d)
            inside=base/"real"
            inside.mkdir()
            (inside/"safe.php").write_text("<?php")
            (base/"link").symlink_to(inside,target_is_directory=True)
            with self.assertRaises(mod.Blocked):
                mod.file_bytes(base,"link/safe.php")

    def test_missing_source_can_be_classified(self):
        with tempfile.TemporaryDirectory() as d:
            p=Path(d)
            self.assertIsNone(mod.file_bytes(p,"not-there.php",allow_absent=True))
            with self.assertRaises(mod.Blocked):
                mod.file_bytes(p,"not-there.php")

    def test_private_root_mode_requires_root_only(self):
        with tempfile.TemporaryDirectory() as d:
            p=Path(d)
            p.chmod(0o700)
            with patch.object(mod.Path,"lstat",autospec=True,
                    return_value=SimpleNamespace(st_mode=stat.S_IFDIR|0o700,st_uid=0)):
                self.assertTrue(mod.only_root_dir(p))
            with patch.object(mod.Path,"lstat",autospec=True,
                    return_value=SimpleNamespace(st_mode=stat.S_IFDIR|0o755,st_uid=0)):
                self.assertFalse(mod.only_root_dir(p))

    def test_fails_closed_when_root_not_operator(self):
        with patch.object(mod.os,"geteuid",return_value=1000):
            with self.assertRaises(mod.Blocked):
                mod.inspect()

    def test_source_contains_no_live_write_or_backup_actions(self):
        code=SCRIPT.read_text()
        for forbidden in (
            "subprocess.", "os.system(", ".write_bytes(", ".write_text(",
            "os.chmod(", "os.replace(", "shutil.copy", "composer install",
            "artisan migrate", "restic", "r2/buckets",
            "BAMBODIA_MCP_ENABLED=true",
        ):
            self.assertNotIn(forbidden,code)
