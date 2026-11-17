"""Read-only Laravel package discovery forensic unit tests."""
import importlib.util
from pathlib import Path
from unittest import TestCase
from unittest.mock import patch
import tempfile
import json
import os
import io
from contextlib import redirect_stdout

ROOT=Path(__file__).resolve().parents[2]
SCRIPT=ROOT/"support/bambodia-mcp-package-discovery-readonly-20261114.py"
SPEC=importlib.util.spec_from_file_location("mcp_cache_forensics",SCRIPT)
m=importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(m)

class PackageCacheForensicsTests(TestCase):
    def test_cached_manifest_presence_and_expected_package_names(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            missing=m.cached_status(root/"absent.php",["Laravel\\Mcp\\McpServiceProvider"],m.PACKAGES)
            self.assertFalse(missing["exists"])
            self.assertIsNone(missing["packages"]["laravel/mcp"])
            file=root/"packages.php"
            file.write_text("<?php return ['laravel/mcp' => [], 'laravel/passport' => []];")
            report=m.cached_status(file,[],m.PACKAGES)
            self.assertTrue(report["exists"])
            self.assertTrue(all(report["packages"].values()))
            file.write_text("<?php return ['old/library' => []];")
            older=m.cached_status(file,[],m.PACKAGES)
            self.assertFalse(any(older["packages"].values()))

    def test_provider_detection_handles_escaped_php_backslashes(self):
        with tempfile.TemporaryDirectory() as tmp:
            file=Path(tmp)/"services.php"
            provider="Laravel\\Mcp\\McpServiceProvider"
            file.write_text("<?php return ['providers' => ['Laravel\\\\Mcp\\\\McpServiceProvider']];")
            report=m.cached_status(file,[provider],m.PACKAGES)
            self.assertTrue(report["providers"][provider])

    def test_symlinked_manifest_is_blocked(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            target=root/"some.php"
            target.write_text("<?php return [];")
            link=root/"packages.php"
            link.symlink_to(target)
            with self.assertRaisesRegex(m.Stop,"CACHED_MANIFEST_SYMLINK_UNSAFE"):
                m.cached_status(link,[],m.PACKAGES)

    def test_candidate_vendor_requires_discoverable_providers(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            (root/"composer").mkdir()
            manifest=root/"composer/installed.json"
            rows={"packages":[
                {"name":"laravel/mcp","extra":{"laravel":{"providers":["Laravel\\Mcp\\McpServiceProvider"]}}},
                {"name":"laravel/passport","extra":{"laravel":{"providers":["Laravel\\Passport\\PassportServiceProvider"]}}},
            ]}
            manifest.write_text(json.dumps(rows))
            result=m.get_provider_metadata(root)
            self.assertEqual(len(result),2)
            self.assertIn("Laravel\\Passport\\PassportServiceProvider",result["laravel/passport"])
            rows["packages"][1]["extra"]["laravel"]["providers"]=[]
            manifest.write_text(json.dumps(rows))
            with self.assertRaisesRegex(m.Stop,"MCP_PROVIDER_METADATA_MISSING"):
                m.get_provider_metadata(root)

    def test_no_sensitive_live_file_access_or_production_writes(self):
        source=SCRIPT.read_text()
        for forbidden in (
            "artisan down","artisan up","artisan migrate", "package:discover",
            "os.replace(", "os.rename(", "os.chmod(", ".write_bytes(",
            ".write_text(", "shutil.copy", "shutil.rmtree",
            "subprocess.run(", "os.system(", "shell=True",
            'read_static(LIVE/".env")', 'storage/logs/laravel',
            "MCP_ENABLED=true",
        ):
            self.assertNotIn(forbidden,source)
        self.assertIn('MCP_DISCOVERY_LIVE_FILES=UNCHANGED',source)
        self.assertIn('MCP_DISCOVERY_PRODUCTION_DB=NOT_ACCESSED',source)

    def test_cli_only_inspect_and_root_required(self):
        with patch.object(m.sys,"argv",["test","--install"]), \
             patch.object(m,"audit") as a,redirect_stdout(io.StringIO()):
            self.assertEqual(m.main(),64)
            a.assert_not_called()
        with patch.object(m.os,"geteuid",return_value=1001):
            with self.assertRaisesRegex(m.Stop,"ROOT_REQUIRED"):
                m.audit()
