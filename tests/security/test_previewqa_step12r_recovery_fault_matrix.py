"""Offline Step12R fault sequences: NO systemd execution, no network or host I/O."""
from __future__ import annotations

import dataclasses
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


def closed_test_context(**updates):
    defaults = LifecycleFacts(
        origin_socket_exists=True,
        origin_socket_healthy=True,
        origin_socket_inode=117593,
        mounted_socket_inode=117593,
        connector_healthy=True,
        connector_identity_reviewed=True,
        private_service_approved=True,
        cloudflare_access_policy_verified=True,
        access_jwt_validation_verified=True,
        recovery_restarts_in_window=0,
        recovery_restart_limit=2,
        explicit_public_release_approval=False,
        hostname_and_dns_authorized=False,
        independent_security_acceptance=False,
        connector_credential_storage_approved=False,
    )
    return dataclasses.replace(defaults, **updates)


class OfflineRecoveryFaultMatrix(unittest.TestCase):
    def test_unlink_rebind_then_fresh_namespace_sequence(self):
        # Simulate the exact mock lifecycle, but DO NOT attempt service actions.
        states = [
            (closed_test_context(), RuntimeDecision.HEALTHY_PRIVATE_RUNTIME),
            (closed_test_context(origin_socket_exists=False, origin_socket_healthy=False,
                                 origin_socket_inode=None), RuntimeDecision.HOLD_ORIGIN_UNAVAILABLE),
            (closed_test_context(origin_socket_inode=117594, connector_healthy=False,
                                 recovery_restarts_in_window=1),
             RuntimeDecision.RECYCLE_STALE_NAMESPACE),
            (closed_test_context(origin_socket_inode=117594,
                                 mounted_socket_inode=117594,
                                 connector_healthy=True, recovery_restarts_in_window=1),
             RuntimeDecision.HEALTHY_PRIVATE_RUNTIME),
        ]
        for facts, expected in states:
            with self.subTest(expected=expected):
                decision = evaluate(facts)
                self.assertEqual(decision.runtime, expected)
                self.assertEqual(decision.publication, PublicationDecision.DENY)

    def test_restart_budget_and_repeated_rebinds_fail_closed(self):
        for restarts in (2, 3, 5, 100):
            facts = closed_test_context(
                origin_socket_inode=117600 + restarts, mounted_socket_inode=117593,
                connector_healthy=False, recovery_restarts_in_window=restarts
            )
            decision = evaluate(facts)
            self.assertEqual(decision.runtime, RuntimeDecision.QUARANTINE_RESTART_BUDGET)
            self.assertEqual(decision.publication, PublicationDecision.DENY)

    def test_no_mount_new_namespace_is_recommendation_not_installation(self):
        decision = evaluate(closed_test_context(mounted_socket_inode=None))
        self.assertEqual(decision.runtime, RuntimeDecision.CREATE_FRESH_NAMESPACE)
        self.assertEqual(decision.publication, PublicationDecision.DENY)

    def test_unexpected_public_exposure_demands_withdrawal_for_all_faults(self):
        variants = [
            {"origin_socket_exists": False},
            {"origin_socket_healthy": False},
            {"mounted_socket_inode": 117592},
            {"connector_healthy": False},
            {"recovery_restarts_in_window": 2},
        ]
        for changes in variants:
            with self.subTest(changes=changes):
                result = evaluate(closed_test_context(public_route_already_exposed=True, **changes))
                self.assertEqual(
                    result.publication, PublicationDecision.DENY_AND_WITHDRAW_IF_EXPOSED
                )

    def test_identity_or_access_rejection_preempts_recycle(self):
        for flag in ("connector_identity_reviewed", "private_service_approved",
                     "cloudflare_access_policy_verified", "access_jwt_validation_verified"):
            with self.subTest(flag=flag):
                facts = closed_test_context(origin_socket_inode=117594, **{flag: False})
                outcome = evaluate(facts)
                self.assertIn(outcome.runtime, (
                    RuntimeDecision.HOLD_IDENTITY_UNREVIEWED,
                    RuntimeDecision.HOLD_ACCESS_UNVERIFIED,
                ))
                self.assertEqual(outcome.publication, PublicationDecision.DENY)

    def test_live_account_reality_remains_deny_no_access(self):
        # Host evidence has no connector, Access disabled, no Tunnel/DNS.
        facts = LifecycleFacts(
            origin_socket_exists=True, origin_socket_healthy=True,
            origin_socket_inode=117593,
        )
        decision = evaluate(facts)
        self.assertEqual(decision.runtime, RuntimeDecision.HOLD_IDENTITY_UNREVIEWED)
        self.assertEqual(decision.publication, PublicationDecision.DENY)


if __name__ == "__main__":
    unittest.main()
