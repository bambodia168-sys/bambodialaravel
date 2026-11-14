"""Private failed-cutover reproduction: synthetic, no production mutation."""
import importlib.util
import json
from pathlib import Path
import tempfile
from unittest import TestCase
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
SRC=ROOT/"support/bambodia-mcp-private-live-overlay-repro-20261114.py"
SPEC=importlib.util.spec_from_file_location("mcp_private_overlay_repro",SRC)
m=importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(m)

class PrivateOverlayCutoverReproTests(TestCase):
    def test_source_changed_allowlist_is_mcp_only(self):
        self.assertEqual(len(m.CHANGED),10)
        self.assertIn("bootstrap/app.php",m.CHANGED)
        self.assertIn("routes/web.php",m.CHANGED)
        self.assertFalse(any("backup" in p or "r2" in p or "public/build" in p
                             for p in m.CHANGED))

    def test_no_live_writes_database_scripts_or_copied_credentials(self):
        s=SRC.read_text()
        for pattern in (
            "artisan migrate", "passport:keys", "composer install",
            "composer update", "restic", "os.system(", "shell=True",
            "BAMBODIA_MCP_ENABLED=true", "BAMBODIA_MCP_WRITES_ENABLED=true",
            'copytree(LIVE,"storage"', 'copytree(LIVE,"vendor"',
            "shutil.rmtree(LIVE)", "os.replace(LIVE",
        ):
            self.assertNotIn(pattern,s)

    def test_error_classifier_never_displays_raw_exception_content(self):
        self.assertEqual(m.sanitize_php_error(
            b"SQLSTATE[42S02] unknown table credentials SECRET123"),
            "DB_SCHEMA_OR_CONNECTION")
        self.assertEqual(m.sanitize_php_error(
            b"Fatal error: Call to undefined method Foo::token() PRIVATE"),
            "API_COMPATIBILITY")
        self.assertEqual(m.sanitize_php_error(b"PRIVATE KEY=secret"),"NONDISCLOSING_PHP_FAILURE")

    def test_oauth_routes_and_mcp_are_required(self):
        good=[{"uri":p,"method":method} for p,method in m.EXPECT]
        self.assertTrue(m.validate_routes(good))
        self.assertFalse(m.validate_routes(good[:-1]))
        with self.assertRaises(m.Stop):
            m.validate_routes({})

    def test_symlink_and_parent_traversal_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            base=Path(directory)
            (base/"safe").mkdir()
            (base/"safe/file.php").write_text("<?php")
            self.assertEqual(m.read(base,"safe/file.php"),b"<?php")
            (base/"link").symlink_to(base/"safe",target_is_directory=True)
            with self.assertRaises(m.Stop):
                m.read(base,"link/file.php")
            with self.assertRaises(m.Stop):
                m.read(base,"../etc/passwd")

    def test_private_reproduction_denies_nonroot(self):
        with patch.object(m.os,"geteuid",return_value=1000):
            with self.assertRaises(m.Stop):
                m.checked_originals()

    def test_synthetic_env_forces_testing_and_false_mcp_flags(self):
        s=SRC.read_text()
        self.assertIn('"APP_ENV":"testing"',s)
        self.assertIn('"DB_CONNECTION":"sqlite"',s)
        self.assertIn('"DB_DATABASE":":memory:"',s)
        self.assertIn('"BAMBODIA_MCP_ENABLED":"false"',s)
        self.assertIn('"BAMBODIA_MCP_WRITES_ENABLED":"false"',s)
        self.assertNotIn('".env",temp',s)
