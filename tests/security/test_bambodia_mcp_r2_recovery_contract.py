"""Synthetic-only R2/MySQL/media recovery contract validation.

Never reads real app files, connects to MySQL/R2, creates credentials,
initializes Restic, uploads backups or restores.
"""
import importlib.util
from pathlib import Path
import tempfile
from unittest import TestCase
from unittest.mock import patch

REPO=Path(__file__).resolve().parents[2]
SPEC=importlib.util.spec_from_file_location(
    "bambodia_r2_recovery_contract",
    REPO/"support/bambodia-mcp-r2-recovery-execution-readiness-readonly-20261114.py",
)
gate=importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(gate)


class RecoveryContractTests(TestCase):
    def test_empty_contract_is_blocked(self):
        checks=gate.evaluate({},check_files=False)
        self.assertFalse(all(checks.values()))
        self.assertFalse(checks["bucket_name"])
        self.assertFalse(checks["jurisdiction"])
        self.assertFalse(checks["mysql_strategy"])
        self.assertFalse(checks["media_scope"])

    def test_boolean_values_cannot_fake_numeric_recovery_objectives(self):
        checks=gate.check_objectives(
            {"rpo_minutes":True,"rto_minutes":False,"retention_days":True}
        )
        self.assertFalse(any(checks.values()))

    def test_wrong_bucket_jurisdiction_and_strategy_fail(self):
        checks=gate.evaluate({
            "r2_bucket_name":"bambodia",
            "r2_jurisdiction":"eu",
            "mysql_backup_strategy":"unreviewed"
        },check_files=False)
        self.assertFalse(checks["bucket_name"])
        self.assertFalse(checks["jurisdiction"])
        self.assertFalse(checks["mysql_strategy"])

    def test_all_declared_contract_not_independent_evidence(self):
        m={k:True for k in (*gate.BOOL_FIELDS,*gate.SPECIFIC_FIELDS)}
        m.update({
            "rpo_minutes":120,"rto_minutes":360,"retention_days":30,
            "r2_bucket_name":"bambodia-backup",
            "r2_jurisdiction":"global",
            "encryption_engine":"restic",
            "mysql_backup_strategy":"single_transaction",
            "recovery_runbook_version":1,
            "restic_password_file":"/root/fake-restic-secret",
            "r2_credentials_file":"/root/fake-s3-credentials",
            "mysql_defaults_file":"/root/fake-mysql-client",
        })
        with patch.object(gate,"check_media_scope",return_value=True):
            checks=gate.evaluate(m,check_files=False)
        self.assertTrue(all(checks.values()))
        # Manifest booleans are only declarations, NEVER execution authorization.

    def test_media_scope_exact_two_root_and_disallows_symlinks(self):
        with tempfile.TemporaryDirectory() as t:
            app=Path(t)/"app"
            first=app/"storage/app/private"
            second=app/"storage/app/public"
            first.mkdir(parents=True)
            second.mkdir(parents=True)
            with patch.object(gate,"LIVE_APP",app),patch.object(gate,"EXPECTED_MEDIA",(str(first),str(second))):
                self.assertTrue(gate.check_media_scope([str(second),str(first)]))
                self.assertFalse(gate.check_media_scope([str(first)]))
                self.assertFalse(gate.check_media_scope([str(first),str(second),"/etc"]))
                self.assertFalse(gate.check_media_scope(["/etc",str(second)]))
                second.rmdir()
                second.symlink_to(first,target_is_directory=True)
                self.assertFalse(gate.check_media_scope([str(second),str(first)]))

    def test_recovery_tool_has_no_network_or_backup_execution(self):
        contents=(REPO/"support/bambodia-mcp-r2-recovery-execution-readiness-readonly-20261114.py").read_text()
        for forbidden in (
            "import subprocess", "import socket", "import requests",
            "import boto3", "cloudflare.request(", "os.system(",
            "os.chmod(", "os.chown(", "subprocess.run(",
            "restic init", "mysqldump --",
        ):
            self.assertNotIn(forbidden,contents)

    def test_reject_malformed_manifest(self):
        with self.assertRaises(gate.Blocked):
            gate.evaluate([],check_files=False)


    def test_versioned_reference_cannot_be_mistaken_for_approval(self):
        import json
        file=REPO/"docs/integrations/bambodia-mcp-r2-recovery-approval-template-20261114.json"
        sample=json.loads(file.read_text())
        self.assertEqual(sample["purpose"],"REFERENCE_ONLY_DO_NOT_USE_AS_APPROVED_MANIFEST")
        self.assertEqual(sample["r2_bucket_name"],"bambodia-backup")
        self.assertEqual(sample["r2_jurisdiction"],"global")
        self.assertIsNone(sample["rpo_minutes"])
        self.assertIsNone(sample["rto_minutes"])
        self.assertIsNone(sample["retention_days"])
        for name in (*gate.BOOL_FIELDS,*gate.SPECIFIC_FIELDS):
            self.assertIs(sample[name],False)
        for name in ("r2_account_id","repository","r2_credentials_file",
                     "restic_password_file","mysql_defaults_file"):
            self.assertEqual(sample[name],"")
        self.assertEqual(sample["local_media_roots"],[])
        checks=gate.evaluate(sample,check_files=False)
        self.assertFalse(all(checks.values()))
