"""Synthetic safety tests: read-only MCP live cutover forensic inspector."""
import importlib.util
import os
from pathlib import Path
import stat
import tempfile
from types import SimpleNamespace
from unittest import TestCase
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
FILE=ROOT/"support/bambodia-mcp-live-cutover-forensics-readonly-20261114.py"
SPEC=importlib.util.spec_from_file_location("bambodia_mcp_cutover_forensics",FILE)
mod=importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(mod)

class McpForensicSafetyTests(TestCase):
    def test_directory_traversal_checks_ownership_and_group(self):
        www_uid=1001
        groups={1002,1003}
        good=SimpleNamespace(st_mode=stat.S_IFDIR|0o750,st_uid=0,st_gid=1003)
        denied=SimpleNamespace(st_mode=stat.S_IFDIR|0o700,st_uid=0,st_gid=1003)
        self.assertTrue(mod.accessible_to_www(good,www_uid,groups))
        self.assertFalse(mod.accessible_to_www(denied,www_uid,groups))
        self.assertTrue(mod.accessible_to_www(good,www_uid,groups,readable=True))
        self.assertFalse(mod.accessible_to_www(good,www_uid,groups,writable=True))

    def test_files_read_permission_is_checked_for_www(self):
        uid=1001;gids={1002}
        permitted=SimpleNamespace(st_mode=stat.S_IFREG|0o640,st_uid=0,st_gid=1002)
        restricted=SimpleNamespace(st_mode=stat.S_IFREG|0o600,st_uid=0,st_gid=1002)
        self.assertTrue(mod.accessible_to_www(permitted,uid,gids,readable=True))
        self.assertFalse(mod.accessible_to_www(restricted,uid,gids,readable=True))

    def test_symlink_and_traversal_blocked(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            (root/"app").mkdir()
            (root/"alias").symlink_to(root/"app",target_is_directory=True)
            self.assertEqual(mod.normalized_path(root,"app/foo.php"),root/"app/foo.php")
            for path in ("../passwd","/etc/passwd","a/../b","./bar.php",""):
                with self.subTest(path=path):
                    with self.assertRaises(mod.Stop):
                        mod.normalized_path(root,path)
            with self.assertRaises(mod.Stop):
                mod.normalized_path(root,"alias/foo.php")

    def test_root_only(self):
        with patch.object(mod.os,"geteuid",return_value=1001):
            with self.assertRaises(mod.Stop):
                mod.audit()

    def test_no_production_writes_commands_or_raw_log_printing(self):
        source=FILE.read_text()
        for unsafe in (
            "shutil.copy", "os.rename(","os.replace(",
            ".write_text(","write_bytes(", "os.chmod(",
            "BAMBODIA_MCP_ENABLED=true",
            "artisan migrate", "artisan:down", "passport:keys",
            "composer install", "composer update", "restic",
            'storage/logs/laravel', 'read(LIVE/".env"',
        ):
            self.assertNotIn(unsafe,source)
        self.assertIn('("ARTISAN_DOWN_HELP",["down","--help","--no-ansi"])',source)
        self.assertIn('("ARTISAN_UP_HELP",["up","--help","--no-ansi"])',source)

    def test_standalone_command_help_only(self):
        fake=SimpleNamespace(returncode=0)
        with patch.object(mod.Path,"is_file",return_value=True), \
             patch.object(mod.os,"access",return_value=True), \
             patch.object(mod.subprocess,"run",return_value=fake) as proc:
            self.assertTrue(mod.www_cli_command("/php",["down","--help"]))
        arguments=proc.call_args.args[0]
        self.assertEqual(arguments[1:4],["-u","www","--"])
        self.assertEqual(arguments[-2:],["down","--help"])
        self.assertIs(proc.call_args.kwargs["stdin"],mod.subprocess.DEVNULL)
