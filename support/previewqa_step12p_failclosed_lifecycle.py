"""Step 12P advisory-only, deterministic QA connector lifecycle contract.

No subprocesses, network access, Cloudflare API, file mutations or host actions.
All observations are caller supplied. This module neither deploys nor authorizes
a connector; it makes safe decision *recommendations* for independent review.
"""
from __future__ import annotations

from dataclasses import dataclass
from enum import Enum


class RuntimeDecision(str, Enum):
    HOLD_ORIGIN_UNAVAILABLE = "hold_origin_unavailable"
    HOLD_IDENTITY_UNREVIEWED = "hold_identity_unreviewed"
    HOLD_ACCESS_UNVERIFIED = "hold_access_unverified"
    QUARANTINE_RESTART_BUDGET = "quarantine_restart_budget"
    CREATE_FRESH_NAMESPACE = "create_fresh_namespace"
    RECYCLE_STALE_NAMESPACE = "recycle_stale_namespace"
    RECYCLE_UNHEALTHY_CONNECTOR = "recycle_unhealthy_connector"
    HEALTHY_PRIVATE_RUNTIME = "healthy_private_runtime"


class PublicationDecision(str, Enum):
    DENY = "deny_publication"
    DENY_AND_WITHDRAW_IF_EXPOSED = "deny_and_withdraw_if_exposed"
    ELIGIBLE_FOR_INDEPENDENT_APPROVAL = "eligible_for_independent_approval"


@dataclass(frozen=True)
class LifecycleFacts:
    """Read-only facts; never filled by accessing the machine in this module."""

    origin_socket_exists: bool = False
    origin_socket_healthy: bool = False
    origin_socket_inode: int | None = None
    mounted_socket_inode: int | None = None
    connector_healthy: bool = False
    connector_identity_reviewed: bool = False
    private_service_approved: bool = False
    cloudflare_access_policy_verified: bool = False
    access_jwt_validation_verified: bool = False
    connector_credential_storage_approved: bool = False
    hostname_and_dns_authorized: bool = False
    independent_security_acceptance: bool = False
    explicit_public_release_approval: bool = False
    recovery_restarts_in_window: int = 0
    recovery_restart_limit: int = 2
    public_route_already_exposed: bool = False


@dataclass(frozen=True)
class AdvisoryDecision:
    runtime: RuntimeDecision
    publication: PublicationDecision
    rationale: str


def evaluate(facts: LifecycleFacts) -> AdvisoryDecision:
    """Fail closed: no runtime recommendation is an executable authorization.

    Runtime lifecycle and public publication are distinct:
    even a healthy private connector does not imply public Access approval.
    """
    if facts.origin_socket_inode is not None and facts.origin_socket_inode <= 0:
        raise ValueError("origin socket inode must be a positive integer")
    if facts.mounted_socket_inode is not None and facts.mounted_socket_inode <= 0:
        raise ValueError("mounted socket inode must be a positive integer")
    if facts.recovery_restart_limit <= 0 or facts.recovery_restarts_in_window < 0:
        raise ValueError("invalid recovery restart budget")

    public_gates = (
        facts.origin_socket_exists,
        facts.origin_socket_healthy,
        facts.connector_healthy,
        facts.connector_identity_reviewed,
        facts.private_service_approved,
        facts.cloudflare_access_policy_verified,
        facts.access_jwt_validation_verified,
        facts.connector_credential_storage_approved,
        facts.hostname_and_dns_authorized,
        facts.independent_security_acceptance,
        facts.explicit_public_release_approval,
        facts.origin_socket_inode is not None,
        facts.mounted_socket_inode is not None,
        facts.origin_socket_inode == facts.mounted_socket_inode,
        facts.recovery_restarts_in_window < facts.recovery_restart_limit,
    )
    if all(public_gates):
        publication = PublicationDecision.ELIGIBLE_FOR_INDEPENDENT_APPROVAL
    elif facts.public_route_already_exposed:
        publication = PublicationDecision.DENY_AND_WITHDRAW_IF_EXPOSED
    else:
        publication = PublicationDecision.DENY

    if not facts.origin_socket_exists or not facts.origin_socket_healthy:
        return AdvisoryDecision(
            RuntimeDecision.HOLD_ORIGIN_UNAVAILABLE, publication,
            "Fail closed: private Unix origin absent or unhealthy; no TCP fallback.",
        )
    if not facts.connector_identity_reviewed or not facts.private_service_approved:
        return AdvisoryDecision(
            RuntimeDecision.HOLD_IDENTITY_UNREVIEWED, publication,
            "Dedicated, least-privilege connector identity/service unapproved.",
        )
    if not facts.cloudflare_access_policy_verified or not facts.access_jwt_validation_verified:
        return AdvisoryDecision(
            RuntimeDecision.HOLD_ACCESS_UNVERIFIED, publication,
            "Access policy and signed JWT validation need independent verification.",
        )
    if facts.recovery_restarts_in_window >= facts.recovery_restart_limit:
        return AdvisoryDecision(
            RuntimeDecision.QUARANTINE_RESTART_BUDGET, publication,
            "Recovery restart budget exhausted; do not retry automatically.",
        )
    if facts.origin_socket_inode is None:
        return AdvisoryDecision(
            RuntimeDecision.HOLD_ORIGIN_UNAVAILABLE, publication,
            "Current origin socket inode not confirmed.",
        )
    if facts.mounted_socket_inode is None:
        return AdvisoryDecision(
            RuntimeDecision.CREATE_FRESH_NAMESPACE, publication,
            "No private mount present: new namespace only after origin readiness.",
        )
    if facts.mounted_socket_inode != facts.origin_socket_inode:
        return AdvisoryDecision(
            RuntimeDecision.RECYCLE_STALE_NAMESPACE, publication,
            "Stale AF_UNIX bind mount: recycle isolated service and rebind current inode.",
        )
    if not facts.connector_healthy:
        return AdvisoryDecision(
            RuntimeDecision.RECYCLE_UNHEALTHY_CONNECTOR, publication,
            "Origin and mount match, but private connector health is not verified.",
        )
    return AdvisoryDecision(
        RuntimeDecision.HEALTHY_PRIVATE_RUNTIME, publication,
        "Private runtime appears healthy; public publication remains separately gated.",
    )
