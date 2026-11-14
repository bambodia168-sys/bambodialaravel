"""Synthetic Cloudflare R2 approval preflight tests; no real account, bucket or credentials."""
import importlib.util
from pathlib import Path
from unittest import TestCase
from unittest.mock import patch
import tempfile

REPO=Path(__file__).resolve().parents[2]
SPEC=importlib.util.spec_from_file_location(
    "bambodia_r2_approval",
    REPO/"support/bambodia-mcp-r2-approval-readonly-20261114.py",
)
r2=importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(r2)

ACCOUNT="a"*32  # fake account identifier — synthetic unit tests only
BUCKET="example-test-only-bucket"  # never used for actual storage
REPO_URL="s3:https://"+ACCOUNT+".r2.cloudflarestorage.com/"+BUCKET+"/isolated-test"

class R2PreflightTests(TestCase):
    def test_r2_uri_does_not_assume_any_real_bucket_or_keys(self):
        p=r2.parse_r2_repository(REPO_URL)
        self.assertEqual(p["account"],ACCOUNT)
        self.assertEqual(p["bucket"],BUCKET)
        self.assertEqual(p["jurisdiction"],"global")
        self.assertEqual(p["prefix_segments"],1)

    def test_jurisdiction_endpoint_parsed_only_when_exact(self):
        for jurisdiction in ("eu","us","fedramp"):
            uri="s3:https://"+ACCOUNT+"."+jurisdiction+".r2.cloudflarestorage.com/"+BUCKET+"/recovery"
            self.assertEqual(r2.parse_r2_repository(uri)["jurisdiction"],jurisdiction)

    def test_rejects_unapproved_or_unsafe_repository(self):
        bad=[
            "", "s3:http://"+ACCOUNT+".r2.cloudflarestorage.com/"+BUCKET+"/data",
            "s3:https://account:password@"+ACCOUNT+".r2.cloudflarestorage.com/"+BUCKET+"/data",
            "s3:https://127.0.0.1/"+BUCKET+"/data",
            "s3:https://"+ACCOUNT+".r2.cloudflarestorage.com/"+BUCKET,
            "s3:https://"+ACCOUNT+".r2.cloudflarestorage.com/"+BUCKET+"/../private",
            "s3:https://"+ACCOUNT+".r2.cloudflarestorage.com/"+BUCKET+"/data/",
            "s3:https://"+ACCOUNT+".r2.cloudflarestorage.com/"+BUCKET+"/data?x=1",
            "s3:https://"+ACCOUNT+".r2.cloudflarestorage.com/"+BUCKET+"/data#fragment",
            "s3:https://"+ACCOUNT+".r2.cloudflarestorage.com/"+BUCKET+"/data\n",
            "s3:https://"+ACCOUNT+".other-cloud.example/"+BUCKET+"/data",
            "s3:https://"+ACCOUNT+".r2.cloudflarestorage.com/"+BUCKET+"/data%2Fetc",
        ]
        for uri in bad:
            with self.subTest(uri=uri[:80]):
                with self.assertRaises((r2.Blocked,ValueError)):
                    r2.parse_r2_repository(uri)

    def test_missing_approval_is_always_incomplete(self):
        checks,repo=r2.evaluate({})
        self.assertIsNone(repo)
        self.assertFalse(all(checks.values()))
        self.assertFalse(checks["r2_endpoint"])
        self.assertFalse(checks["r2_s3_credential_file"])
        self.assertFalse(checks["restic_key_file"])

    def test_manifest_flags_do_not_fabricate_credential_presence(self):
        fake={x:True for x in (*r2.GENERIC_APPROVALS,*r2.R2_APPROVALS)}
        fake.update({
            "rpo_minutes":60,"rto_minutes":240,"retention_days":30,
            "encryption_engine":"restic","repository":REPO_URL,
            "r2_account_id":ACCOUNT,"r2_bucket_name":BUCKET,"r2_jurisdiction":"global",
            "restic_password_file":"/root/does-not-exist",
            "r2_credentials_file":"/root/does-not-exist",
        })
        checks,_=r2.evaluate(fake)
        self.assertFalse(checks["restic_key_file"])
        self.assertFalse(checks["r2_s3_credential_file"])
        self.assertFalse(all(checks.values()))

    def test_mismatched_account_bucket_or_jurisdiction_is_blocked(self):
        fake={"repository":REPO_URL,"r2_account_id":ACCOUNT,
              "r2_bucket_name":BUCKET,"r2_jurisdiction":"global"}
        for key,value in (
            ("r2_account_id","b"*32),
            ("r2_bucket_name","another-fake"),
            ("r2_jurisdiction","eu"),
        ):
            a=dict(fake)
            a[key]=value
            checks,_=r2.evaluate(a)
            self.assertFalse(checks["r2_endpoint"])

    def test_bool_not_valid_for_rpo_rto_or_retention(self):
        fake={"rpo_minutes":True,"rto_minutes":False,"retention_days":True}
        checks,_=r2.evaluate(fake)
        self.assertFalse(checks["rpo_minutes"])
        self.assertFalse(checks["rto_minutes"])
        self.assertFalse(checks["retention_days"])

    def test_root_only_manifest_is_missing_by_default(self):
        with patch.object(r2,"APPROVAL",Path("/root/this-test-manifest-does-not-exist.json")):
            self.assertIsNone(r2.read_manifest())

    def test_no_live_network_or_backup_action_in_source(self):
        body=(REPO/"support/bambodia-mcp-r2-approval-readonly-20261114.py").read_text()
        for forbidden in (
            "import requests","import boto3","subprocess.","os.system(",
            "os.chmod(","os.chown(","restic backup","restic init",
            "cloudflare.request","aws s3","mysqldump --",
        ):
            self.assertNotIn(forbidden,body)
