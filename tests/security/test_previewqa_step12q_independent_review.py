"""Synthetic-only negative tests for Step12Q independent architecture review.

The fictional team/audience below are NOT Cloudflare account records, and
passing these tests cannot establish a live Access policy or JWT validation.
"""
from __future__ import annotations

import copy
import pathlib
import sys
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "support"))

from previewqa_step12q_independent_review import (  # noqa: E402
    CANONICAL_HOST,
    MOUNTED_SOCKET,
    ORIGIN_SOCKET,
    review,
)

SYNTHETIC_TEAM = "synthetic-qa-review-only"
SYNTHETIC_AUD = "0" * 32


def fictional_architecture():
    """Illustrative proposal shape, not a real connector configuration."""
    return {
        "identity": {
            "dynamic_user": True, "run_as_root": False,
            "shares_www_uid": False, "socket_parent_mode": "0700",
            "read_only_single_socket_bind": True,
            "original_socket": ORIGIN_SOCKET,
            "mounted_socket": MOUNTED_SOCKET,
            "can_read_laravel_env": False, "can_connect_fpm": False,
            "no_public_tcp_listener": True,
        },
        "service": {
            "no_new_privileges": True, "private_tmp": True,
            "protect_home": True, "strict_filesystem": True,
            "no_capabilities": True, "dedicated_credential_delivery": True,
            "address_families": ["AF_UNIX", "AF_INET", "AF_INET6"],
            "credentials_in_unit_or_repository": False,
            "shared_aa_panel_service": False,
        },
        "lifecycle": {
            "origin_readiness_gate": True, "recheck_socket_inode": True,
            "fresh_namespace_on_rebind": True,
            "restart_after_new_socket_ready": True,
            "stale_mount_negative_test": True,
            "fail_closed_on_ambiguity": True,
            "quarantine_on_exhaustion": True,
            "no_tcp_fallback": True,
            "remove_old_namespace_before_recovery": True,
            "max_restarts_per_window": 2,
        },
        "access": {
            "application_before_route": True, "default_deny": True,
            "mfa_required": True, "explicit_identity_allowlist": True,
            "all_paths_protected": True, "deny_unauthorized": True,
            "bypass_policies": [], "allow_everyone": False,
            "wildcard_hostname": False, "max_session_hours": 4,
        },
        "ingress": {
            "hostname": CANONICAL_HOST,
            "service": "unix:" + MOUNTED_SOCKET,
            "httpHostHeader": CANONICAL_HOST,
            "access": {"required": True,
                       "teamName": SYNTHETIC_TEAM, "audTag": [SYNTHETIC_AUD]},
            "catch_all_service": "http_status:404",
            "path_restricted_access_rules": False,
            "other_hostname_routes": [],
        },
        "jwt": {
            "input_header": "Cf-Access-Jwt-Assertion",
            "algorithms": ["RS256"], "verify_signature": True,
            "verify_issuer": True, "verify_audience": True,
            "verify_expiration": True, "verify_not_before": True,
            "reject_missing_token": True, "reject_kid_unknown": True,
            "fail_closed_when_jwks_unavailable": True,
            "key_source": "rotating_cloudflare_jwks",
            "hardcoded_public_cert": False, "trust_header_only": False,
            "issuer": f"https://{SYNTHETIC_TEAM}.cloudflareaccess.com",
            "audience": SYNTHETIC_AUD,
        },
    }


class IndependentDesignReview(unittest.TestCase):
    def test_fictional_design_pass_is_never_release_approval(self):
        verdict = review(fictional_architecture())
        self.assertEqual(verdict.blockers, ())
        self.assertEqual(verdict.design_status, "DESIGN_CONFORMS_SYNTHETICALLY")
        self.assertEqual(verdict.release_status, "RELEASE_BLOCKED_EXTERNAL_ATTESTATION_REQUIRED")
        self.assertGreaterEqual(len(verdict.required_live_evidence), 10)

    def test_missing_entire_sections_all_block(self):
        for part in ("identity", "service", "lifecycle", "access", "ingress", "jwt"):
            with self.subTest(part=part):
                proposal = fictional_architecture()
                proposal.pop(part)
                self.assertTrue(review(proposal).blockers)

    def test_connector_privilege_and_socket_isolation_negative_cases(self):
        cases = [
            ("identity", "dynamic_user", False, "UNPRIVILEGED_DYNAMIC_UID_REQUIRED"),
            ("identity", "run_as_root", True, "ROOT_CONNECTOR_FORBIDDEN"),
            ("identity", "shares_www_uid", True, "SHARED_WWW_IDENTITY_FORBIDDEN"),
            ("identity", "socket_parent_mode", "0755", "ORIGINAL_0700_PARENT_MUST_REMAIN"),
            ("identity", "read_only_single_socket_bind", False, "SINGLE_SOCKET_BIND_REQUIRED"),
            ("identity", "original_socket", "/tmp/other.sock", "ORIGIN_SOCKET_MISMATCH"),
            ("identity", "mounted_socket", "/run/unrelated.sock", "PRIVATE_MOUNT_TARGET_REQUIRED"),
            ("identity", "can_read_laravel_env", True, "LARAVEL_ENV_MUST_BE_DENIED"),
            ("identity", "can_connect_fpm", True, "FPM_SOCKET_MUST_BE_DENIED"),
            ("identity", "no_public_tcp_listener", False, "PUBLIC_TCP_LISTENER_FORBIDDEN"),
            ("service", "no_new_privileges", False, "SERVICE_HARDENING_MISSING_NO_NEW_PRIVILEGES"),
            ("service", "strict_filesystem", False, "SERVICE_HARDENING_MISSING_STRICT_FILESYSTEM"),
            ("service", "no_capabilities", False, "SERVICE_HARDENING_MISSING_NO_CAPABILITIES"),
            ("service", "credentials_in_unit_or_repository", True, "CREDENTIALS_IN_UNIT_OR_GIT_FORBIDDEN"),
            ("service", "shared_aa_panel_service", True, "EXISTING_SHARED_SERVICE_RECONFIGURATION_FORBIDDEN"),
        ]
        for section, key, value, expected in cases:
            with self.subTest(section=section, key=key):
                proposal = fictional_architecture()
                proposal[section][key] = value
                self.assertIn(expected, review(proposal).blockers)

    def test_lifecycle_stale_inode_and_restart_negative_cases(self):
        flags = (
            "origin_readiness_gate", "recheck_socket_inode",
            "fresh_namespace_on_rebind", "restart_after_new_socket_ready",
            "stale_mount_negative_test", "fail_closed_on_ambiguity",
            "quarantine_on_exhaustion", "no_tcp_fallback",
            "remove_old_namespace_before_recovery",
        )
        for flag in flags:
            with self.subTest(flag=flag):
                proposal = fictional_architecture()
                proposal["lifecycle"][flag] = False
                self.assertIn("LIFECYCLE_CONTROL_MISSING_" + flag.upper(),
                              review(proposal).blockers)
        for budget in (0, -1, 4, "2", None):
            with self.subTest(budget=budget):
                proposal = fictional_architecture()
                proposal["lifecycle"]["max_restarts_per_window"] = budget
                self.assertIn("BOUNDED_RESTART_BUDGET_REQUIRED", review(proposal).blockers)

    def test_access_policy_bypass_negative_cases(self):
        cases = [
            ("application_before_route", False, "ACCESS_CONTROL_MISSING_APPLICATION_BEFORE_ROUTE"),
            ("default_deny", False, "ACCESS_CONTROL_MISSING_DEFAULT_DENY"),
            ("mfa_required", False, "ACCESS_CONTROL_MISSING_MFA_REQUIRED"),
            ("explicit_identity_allowlist", False, "ACCESS_CONTROL_MISSING_EXPLICIT_IDENTITY_ALLOWLIST"),
            ("all_paths_protected", False, "ACCESS_CONTROL_MISSING_ALL_PATHS_PROTECTED"),
            ("deny_unauthorized", False, "ACCESS_CONTROL_MISSING_DENY_UNAUTHORIZED"),
            ("bypass_policies", ["health-bypass"], "ACCESS_BYPASS_FORBIDDEN"),
            ("allow_everyone", True, "ACCESS_ALLOW_EVERYONE_FORBIDDEN"),
            ("wildcard_hostname", True, "ACCESS_WILDCARD_FORBIDDEN"),
            ("max_session_hours", 24, "ACCESS_SESSION_REVIEW_REQUIRED"),
        ]
        for key, value, expected in cases:
            with self.subTest(key=key):
                proposal = fictional_architecture()
                proposal["access"][key] = value
                self.assertIn(expected, review(proposal).blockers)

    def test_tunnel_routes_and_jwt_enforcement_negative_cases(self):
        cases = [
            (("ingress", "hostname"), "*.bambodia.com", "CANONICAL_HOST_REQUIRED"),
            (("ingress", "service"), "http://localhost:8888", "PRIVATE_UNIX_ORIGIN_REQUIRED"),
            (("ingress", "httpHostHeader"), "localhost", "PRESERVE_CANONICAL_HOST"),
            (("ingress", "catch_all_service"), "hello_world", "DENY_ALL_CATCH_ALL_REQUIRED"),
            (("ingress", "path_restricted_access_rules"), True, "PATH_SCOPED_ACCESS_RULES_FORBIDDEN"),
            (("ingress", "other_hostname_routes"), ["anything"], "OTHER_HOSTNAME_ROUTES_FORBIDDEN"),
            (("jwt", "input_header"), "CF_Authorization", "JWT_ASSERTION_HEADER_REQUIRED"),
            (("jwt", "algorithms"), ["HS256", "none"], "PIN_RS256_ALGORITHM"),
            (("jwt", "verify_signature"), False, "VERIFY_JWT_SIGNATURE"),
            (("jwt", "verify_issuer"), False, "JWT_CHECK_MISSING_VERIFY_ISSUER"),
            (("jwt", "verify_audience"), False, "JWT_CHECK_MISSING_VERIFY_AUDIENCE"),
            (("jwt", "verify_expiration"), False, "JWT_CHECK_MISSING_VERIFY_EXPIRATION"),
            (("jwt", "verify_not_before"), False, "JWT_CHECK_MISSING_VERIFY_NOT_BEFORE"),
            (("jwt", "reject_kid_unknown"), False, "JWT_CHECK_MISSING_REJECT_KID_UNKNOWN"),
            (("jwt", "fail_closed_when_jwks_unavailable"), False,
             "JWT_CHECK_MISSING_FAIL_CLOSED_WHEN_JWKS_UNAVAILABLE"),
            (("jwt", "key_source"), "embedded_key", "REMOTE_ROTATING_JWKS_REQUIRED"),
            (("jwt", "hardcoded_public_cert"), True, "HARDCODED_CERTIFICATE_FORBIDDEN"),
            (("jwt", "trust_header_only"), True, "UNSIGNED_HEADER_TRUST_FORBIDDEN"),
            (("jwt", "issuer"), "https://wrong.cloudflareaccess.com", "JWT_TEAM_ISSUER_MISMATCH"),
            (("jwt", "audience"), "another-application", "JWT_APP_AUDIENCE_MISMATCH"),
        ]
        for (section, key), value, expected in cases:
            with self.subTest(section=section, key=key):
                proposal = fictional_architecture()
                proposal[section][key] = value
                self.assertIn(expected, review(proposal).blockers)

    def test_missing_required_cloudflared_token_validation(self):
        for value in (False, None, "true"):
            proposal = fictional_architecture()
            proposal["ingress"]["access"]["required"] = value
            self.assertIn("CLOUDFLARED_ACCESS_REQUIRED", review(proposal).blockers)

    def test_missing_or_multiple_audience_tags_fails(self):
        for bad in ([], ["a", "b"], ["<AUD>"], None):
            proposal = fictional_architecture()
            proposal["ingress"]["access"]["audTag"] = bad
            self.assertIn("EXACT_ONE_APP_AUDIENCE_REQUIRED", review(proposal).blockers)

    def test_no_real_token_or_policy_attestation_possible_here(self):
        for _ in range(10):
            proposal = copy.deepcopy(fictional_architecture())
            outcome = review(proposal)
            self.assertEqual(outcome.release_status,
                             "RELEASE_BLOCKED_EXTERNAL_ATTESTATION_REQUIRED")

    def test_nonmapping_rejected(self):
        with self.assertRaises(TypeError):
            review("this is not a design")


if __name__ == "__main__":
    unittest.main()
