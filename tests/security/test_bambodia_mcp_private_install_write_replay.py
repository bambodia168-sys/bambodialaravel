"""Safety tests for MCP-only private replay of accepted installer write_atomic."""
import hashlib
import importlib.util
from pathlib import Path
from unittest import TestCase
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
FILE=ROOT/"support/bambodia-mcp-private-install-write-replay-20261114.py"
SPEC=importlib.util.spec_from_file_location("mcp_private_install_write_replay",FILE)
mod=importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(mod)

class McpWriteReplaySafetyTests(TestCase):
    def test_pinned_original_installer_is_github_blob(self):
        self.assertEqual(mod.OPERATOR_BLOB,
            "75a2db25e1ea2ad5d06c25771b86f4f75e64d93a")
        with patch.object(mod,"OPERATOR",Path("/nonexistent/old-installer.py")):
            with self.assertRaisesRegex(mod.Stop,"PINNED_OLD_INSTALLER_MISSING"):
                mod.load_original_installer()

    def test_sha256_and_git_object_identity(self):
        raw=b"synthetic new Laravel class"
        self.assertEqual(mod.sha(raw),hashlib.sha256(raw).hexdigest())
        self.assertEqual(mod.blob(raw),hashlib.sha1(
            f"blob {len(raw)}\0".encode()+raw).hexdigest())

    def test_read_only_live_source_and_private_write_destinations(self):
        s=FILE.read_text()
        self.assertIn("installer.safe_patch_inventory()",s)
        self.assertIn("installer.LIVE=clone",s)
        self.assertIn('dir="/root"',s)
        self.assertIn('for rel in entries:',s)
        self.assertIn('installer.write_atomic(',s)
        self.assertIn('installer.guarded_path(clone,rel)',s)
        self.assertIn('MCP_WRITE_REPLAY_LIVE_ROOT=UNCHANGED',s)
        for forbidden in (
            "artisan down","artisan up","artisan migrate",
            "composer update","composer install","passport:keys",
            "subprocess.run(", "os.system(", "shell=True",
            "os.rename(LIVE","os.replace(LIVE",
            "write_atomic(LIVE", "shutil.rmtree(LIVE",
            'read_file(LIVE/".env"', 'BAMBODIA_MCP_ENABLED=true',
        ):
            self.assertNotIn(forbidden,s)

    def test_operator_copy_guard_fails_closed(self):
        with patch.object(mod.os,"geteuid",return_value=1001):
            # "load" must not install anything even if the parent process
            # accidentally executes the script unprivileged.
            with self.assertRaisesRegex(mod.Stop,"ROOT_REQUIRED"):
                mod.replay()

    def test_unsupported_cli_option_does_not_run_replay(self):
        with patch.object(mod.sys,"argv",["mcp-write-replay","--install"]), \
             patch.object(mod,"replay") as run:
            self.assertEqual(mod.main(),64)
            run.assert_not_called()
