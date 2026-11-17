"""Synthetic-only verified MCP runtime staging security checks."""
import importlib.util
import io
from contextlib import redirect_stdout
from pathlib import Path
from unittest import TestCase
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
SOURCE=ROOT/"support/bambodia-mcp-minimal-runtime-stage-20261114.py"
SPEC=importlib.util.spec_from_file_location("bambodia_mcp_runtime_stage",SOURCE)
m=importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(m)

class McpMinimalRuntimeStageTest(TestCase):
    def test_scope_is_exact_mcp_oauth_runtime_only(self):
        names=m.PATCH
        self.assertEqual(len(names),len(set(names)))
        self.assertTrue(set(m.CRITICAL).issubset(names))
        for name in names:
            self.assertFalse(name.startswith("/"))
            self.assertNotIn("..",Path(name).parts)
            self.assertNotIn("recovery",name.lower())
            self.assertNotIn("r2",name.lower())
            self.assertNotIn(".env",name.lower())
            self.assertNotIn("preview",name.lower())
            self.assertNotIn("home.blade.php",name)
            self.assertNotIn("public/build",name)
            self.assertNotIn("storage/",name)
        self.assertTrue(any("oauth_access_tokens" in n for n in names))
        self.assertTrue(any("config/mcp.php" in n for n in names))

    def test_no_nonroot_staging_or_live_writes(self):
        with patch.object(m.os,"geteuid",return_value=1000):
            with self.assertRaises(m.Stop):
                m.inventory()
        s=SOURCE.read_text()
        for forbidden in (
            "os.system(", "subprocess.run(", "composer install",
            "php artisan migrate", "php artisan passport:keys",
            "BAMBODIA_MCP_ENABLED=true", "BACKUP_BUCKET",
            "os.replace(", "LIVE.mkdir(", "shutil.copytree(LIVE",
        ):
            self.assertNotIn(forbidden,s)

    def test_missing_candidate_blocks_without_stage(self):
        with patch.object(m,"private_root",return_value=False):
            with patch.object(m.os,"geteuid",return_value=0):
                with self.assertRaises(m.Stop):
                    m.inventory()
