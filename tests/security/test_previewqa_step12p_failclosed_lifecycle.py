"""Isolated, deterministic security regression for Step12P (never contacts host)."""
from __future__ import annotations

import dataclasses
import itertools
import pathlib
import sys
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "support"))

from previewqa_step12p_failclosed_lifecycle import (  # noqa: E402
    LifecycleFacts,
    PublicationDecision,
    RuntimeDecision,
    evaluate,
)


def approved(**changes):
    return dataclasses.replace(
        LifecycleFacts(
            origin_socket_exists=True,
            origin_socket_healthy=True,
            origin_socket_inode=117593,
            mounted_socket_inode=117593,
            connector_healthy=True,
            connector_identity_reviewed=True,
            private_service_approved=True,
            cloudflare_access_policy_verified=True,
            access_jwt_validation_verified=True,
            connector_credential_storage_approved=True,
            hostname_and_dns_authorized=True,
            independent_security_acceptance=True,
            explicit_public_release_approval=True,
            recovery_restarts_in_window=0,
            recovery_restart_limit=2,
        ),
        **changes,
    )


class LifecycleContractTest(unittest.TestCase):
    def test_default_fail_closed(self):
        decision = evaluate(LifecycleFacts())
        self.assertEqual(decision.publication, PublicationDecision.DENY)
        self.assertEqual(decision.runtime, RuntimeDecision.HOLD_ORIGIN_UNAVAILABLE)

    def test_current_qa_with_origin_but_no_cloudflare_is_not_ready(self):
        decision = evaluate(LifecycleFacts(
            origin_socket_exists=True,
            origin_socket_healthy=True,
            origin_socket_inode=117593,
        ))
        self.assertEqual(decision.publication, PublicationDecision.DENY)
        self.assertEqual(decision.runtime, RuntimeDecision.HOLD_IDENTITY_UNREVIEWED)

    def test_unavailable_origin_preempts_actions(self):
        for missing in (
            {"origin_socket_exists": False},
            {"origin_socket_healthy": False},
            {"origin_socket_inode": None},
        ):
            with self.subTest(missing=missing):
                decision = evaluate(approved(**missing))
                self.assertEqual(decision.publication, PublicationDecision.DENY)
                self.assertEqual(decision.runtime, RuntimeDecision.HOLD_ORIGIN_UNAVAILABLE)

    def test_identity_and_access_require_separate_review(self):
        missing_identity = evaluate(approved(connector_identity_reviewed=False))
        self.assertEqual(missing_identity.runtime, RuntimeDecision.HOLD_IDENTITY_UNREVIEWED)
        missing_access = evaluate(approved(access_jwt_validation_verified=False))
        self.assertEqual(missing_access.runtime, RuntimeDecision.HOLD_ACCESS_UNVERIFIED)
        self.assertEqual(missing_access.publication, PublicationDecision.DENY)

    def test_no_mount_requires_fresh_namespace(self):
        decision = evaluate(approved(mounted_socket_inode=None))
        self.assertEqual(decision.runtime, RuntimeDecision.CREATE_FRESH_NAMESPACE)
        self.assertEqual(decision.publication, PublicationDecision.DENY)

    def test_stale_mount_recycles_and_denies(self):
        decision = evaluate(approved(mounted_socket_inode=117592))
        self.assertEqual(decision.runtime, RuntimeDecision.RECYCLE_STALE_NAMESPACE)
        self.assertEqual(decision.publication, PublicationDecision.DENY)

    def test_budget_exhaustion_blocks_automatic_recovery(self):
        decision = evaluate(approved(mounted_socket_inode=117592,
                                     recovery_restarts_in_window=2))
        self.assertEqual(decision.runtime, RuntimeDecision.QUARANTINE_RESTART_BUDGET)
        self.assertEqual(decision.publication, PublicationDecision.DENY)

    def test_unhealthy_private_runtime_is_not_published(self):
        decision = evaluate(approved(connector_healthy=False))
        self.assertEqual(decision.runtime, RuntimeDecision.RECYCLE_UNHEALTHY_CONNECTOR)
        self.assertEqual(decision.publication, PublicationDecision.DENY)

    def test_missing_any_publication_gate_denies_even_if_runtime_healthy(self):
        protected = (
            "connector_identity_reviewed",
            "private_service_approved",
            "cloudflare_access_policy_verified",
            "access_jwt_validation_verified",
            "connector_credential_storage_approved",
            "hostname_and_dns_authorized",
            "independent_security_acceptance",
            "explicit_public_release_approval",
        )
        for missing in protected:
            with self.subTest(missing=missing):
                decision = evaluate(approved(**{missing: False}))
                self.assertEqual(decision.publication, PublicationDecision.DENY)

    def test_preexisting_exposure_demands_withdrawal_without_gates(self):
        decision = evaluate(approved(public_route_already_exposed=True,
                                     access_jwt_validation_verified=False))
        self.assertEqual(decision.publication,
                         PublicationDecision.DENY_AND_WITHDRAW_IF_EXPOSED)

    def test_all_gates_only_eligible_for_independent_approval(self):
        decision = evaluate(approved())
        self.assertEqual(decision.runtime, RuntimeDecision.HEALTHY_PRIVATE_RUNTIME)
        self.assertEqual(decision.publication,
                         PublicationDecision.ELIGIBLE_FOR_INDEPENDENT_APPROVAL)

    def test_negative_gate_matrix(self):
        fields = (
            "cloudflare_access_policy_verified",
            "access_jwt_validation_verified",
            "connector_credential_storage_approved",
            "hostname_and_dns_authorized",
            "independent_security_acceptance",
            "explicit_public_release_approval",
        )
        for bits in itertools.product((False, True), repeat=len(fields)):
            facts = approved(**dict(zip(fields, bits)))
            outcome = evaluate(facts)
            if all(bits):
                self.assertEqual(outcome.publication,
                                 PublicationDecision.ELIGIBLE_FOR_INDEPENDENT_APPROVAL)
            else:
                self.assertEqual(outcome.publication, PublicationDecision.DENY)

    def test_invalid_or_ambiguous_inputs_fail(self):
        for args in (
            {"origin_socket_inode": 0},
            {"origin_socket_inode": -4},
            {"mounted_socket_inode": 0},
            {"recovery_restart_limit": 0},
            {"recovery_restarts_in_window": -1},
        ):
            with self.subTest(args=args):
                with self.assertRaises(ValueError):
                    evaluate(approved(**args))


if __name__ == "__main__":
    unittest.main()
