"""Prevent accidental promotion of nondeployable systemd/Cloudflare specimens."""
from __future__ import annotations

import pathlib
import sys
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "support"))
from previewqa_step12r_review_fixture_audit import validate_review_fixtures  # noqa:E402

UNIT = ROOT / "support/review-only/previewqa-cloudflared.service.review-only"
INGRESS = ROOT / "support/review-only/previewqa-cloudflared-ingress.yml.review-only"


class ReviewFixtureTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.unit = UNIT.read_text()
        cls.ingress = INGRESS.read_text()

    def test_checked_in_fixtures_intentionally_cannot_deploy(self):
        self.assertEqual(validate_review_fixtures(self.unit, self.ingress), ())
        self.assertIn("ExecStart=/usr/bin/false", self.unit)
        self.assertIn("INVALID_TUNNEL_UNPROVISIONED", self.ingress)
        self.assertNotIn("WantedBy=", self.unit)

    def test_security_mutations_of_service_are_rejected(self):
        cases = [
            ("ExecStart=/usr/bin/false", "ExecStart=/usr/bin/cloudflared"),
            ("DynamicUser=yes", "DynamicUser=no"),
            ("User=bambodia_pvconnector", "User=www"),
            ("User=bambodia_pvconnector", "User=root"),
            ("ProtectSystem=strict", "ProtectSystem=off"),
            ("ProtectHome=yes", "ProtectHome=no"),
            ("NoNewPrivileges=yes", "NoNewPrivileges=no"),
            ("PrivateTmp=yes", "PrivateTmp=no"),
            ("CapabilityBoundingSet=", "CapabilityBoundingSet=CAP_DAC_OVERRIDE"),
            ("RuntimeDirectoryMode=0700", "RuntimeDirectoryMode=0755"),
            ("BindReadOnlyPaths=/run/bambodia-previewqa-http/http.sock:"
             "/run/bambodia-previewqa-connector/http.sock",
             "BindReadOnlyPaths=/run/bambodia-previewqa-http:/run/bambodia-previewqa-connector"),
            ("LoadCredential=tunnel.json:/etc/bambodia-previewqa-connector/"
             "UNPROVISIONED_REVIEW_ONLY",
             "Environment=TUNNEL_TOKEN=test"),
            ("Restart=no", "Restart=always"),
            ("RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6",
             "RestrictAddressFamilies=AF_UNIX"),
            ("RefuseManualStart=yes", "RefuseManualStart=no"),
            ("ConditionPathExists=/etc/bambodia-previewqa-connector/"
             "REVIEW_APPROVED_BUT_NOT_PROVISIONED", "ConditionPathExists=/"),
        ]
        for old, new in cases:
            with self.subTest(old=old, new=new):
                self.assertIn(old, self.unit)
                issues = validate_review_fixtures(self.unit.replace(old, new), self.ingress)
                self.assertNotEqual(issues, ())

    def test_accidentally_installable_unit_is_rejected(self):
        changed = self.unit.replace(
            "[Install]\n", "[Install]\nWantedBy=multi-user.target\n"
        )
        self.assertIn("REVIEW_UNIT_MUST_HAVE_NO_INSTALL_TARGET",
                      validate_review_fixtures(changed, self.ingress))

    def test_extra_network_routes_and_bypasses_rejected(self):
        cases = [
            ("required: true", "required: false"),
            ("teamName: INVALID_ACCESS_NOT_ENABLED", "teamName: live-team"),
            ("- INVALID_APPLICATION_AUDIENCE", "- live-audience-tag"),
            ("service: unix:/run/bambia", "service: http://127.0.0.1:8080"),
            ("- service: http_status:404", "- service: hello_world"),
            ("hostname: preview-qa.bambodia.com", "hostname: *.bambodia.com"),
            ("httpHostHeader: preview-qa.bambodia.com", "httpHostHeader: localhost"),
        ]
        for old, new in cases:
            with self.subTest(old=old):
                proposal = self.ingress.replace(old, new)
                self.assertNotEqual(validate_review_fixtures(self.unit, proposal), ())

    def test_extra_policy_line_and_credential_literal_rejected(self):
        modified = self.ingress + "\n  - hostname: bypass.example.com\n    service: http://localhost:80\n"
        self.assertIn("INGRESS_MUST_STAY_NONDEPLOYABLE_EXACT_HOST_ACCESS_ONLY",
                      validate_review_fixtures(self.unit, modified))
        modified = self.unit.replace("Restart=no", "Restart=no\nEnvironment=CF_TOKEN=abc")
        self.assertIn("UNREVIEWED_SERVICE_FIELD_ENVIRONMENT",
                      validate_review_fixtures(modified, self.ingress))


if __name__ == "__main__":
    unittest.main()
