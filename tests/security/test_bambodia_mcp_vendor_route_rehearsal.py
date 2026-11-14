"""Synthetic safety tests for MCP-only PHP 8.5 vendor/route rehearsal."""
import importlib.util
import json
from pathlib import Path
from unittest import TestCase
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
SCRIPT=ROOT/"support/bambodia-mcp-vendor-route-rehearsal-20261114.py"
SPEC=importlib.util.spec_from_file_location("mcp_vendor_route_rehearsal",SCRIPT)
gate=importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(gate)

class VendorRouteRehearsalTests(TestCase):
    @staticmethod
    def lock(rows):
        return json.dumps({"packages":[{"name":k,"version":v}
              for k,v in rows.items()],"packages-dev":[]}).encode()

    def test_six_changed_dependencies_are_exact(self):
        old={k:v[0] for k,v in gate.EXACT_CHANGES.items()}
        new={k:v[1] for k,v in gate.EXACT_CHANGES.items()}
        new["laravel/mcp"]="v1.2.0"
        changed,additions,removed=gate.compare(self.lock(old),self.lock(new))
        self.assertEqual(changed,gate.EXACT_CHANGES)
        self.assertEqual(additions,{"laravel/mcp":"v1.2.0"})
        self.assertFalse(removed)

    def test_changed_unknown_or_removed_package_detected(self):
        old={"laravel/framework":"v13.40.0","example/other":"v1.0"}
        new={"laravel/framework":"v13.42.0"}
        changed,additions,removed=gate.compare(self.lock(old),self.lock(new))
        self.assertEqual(removed,{"example/other"})
        self.assertNotEqual(changed,gate.EXACT_CHANGES)

    def test_required_installed_package_versions(self):
        entries=[{"name":"laravel/mcp","version":"v1.2.0"},
                 {"name":"laravel/passport","version":"v13.11.0"}]
        self.assertEqual(gate.installed_versions(json.dumps({"packages":entries}).encode()),
                         {"laravel/mcp":"v1.2.0","laravel/passport":"v13.11.0"})

    def test_exact_oauth_routes_required(self):
        rows=[{"uri":p,"method":method} for p,method in gate.ROUTES]
        self.assertTrue(gate.validate_routes(rows))
        self.assertFalse(gate.validate_routes(rows[:-1]))
        self.assertFalse(gate.validate_routes({"routes":rows}))

    def test_read_only_rehearsal_denies_nonroot(self):
        with patch.object(gate.os,"geteuid",return_value=1001):
            with self.assertRaises(gate.Stop):
                gate.inspect()

    def test_no_live_writes_or_installs(self):
        text=SCRIPT.read_text()
        for forbidden in ("composer install","composer update","artisan migrate",
                          "passport:keys","os.system(","shell=True","os.replace(",
                          "os.chmod(","os.chown(","write_bytes(", "write_text(",
                          "shutil.copy", "BAMBODIA_MCP_ENABLED=true","restic"):
            self.assertNotIn(forbidden,text)
