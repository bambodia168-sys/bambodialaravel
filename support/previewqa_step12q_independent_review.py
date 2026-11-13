"""Independent Step 12Q design-only security reviewer.

No host, Cloudflare, filesystem or network access. Never deploys/authorizes
a connector. Proposed configuration is *untrusted hypothetical input*; a
structural PASS is not validation of real Cloudflare settings or JWT tokens.
"""
from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Mapping

CANONICAL_HOST = "preview-qa.bambodia.com"
ORIGIN_SOCKET = "/run/bambodia-previewqa-http/http.sock"
MOUNTED_SOCKET = "/run/bambodia-previewqa-connector/http.sock"
ACCESS_HEADER = "Cf-Access-Jwt-Assertion"


@dataclass(frozen=True)
class DesignReview:
    blockers: tuple[str, ...]
    required_live_evidence: tuple[str, ...]

    @property
    def design_status(self) -> str:
        return "DESIGN_BLOCKED" if self.blockers else "DESIGN_CONFORMS_SYNTHETICALLY"

    @property
    def release_status(self) -> str:
        # This pure model never sees authoritative host/account attestation.
        return "RELEASE_BLOCKED_EXTERNAL_ATTESTATION_REQUIRED"


REQUIRED_LIVE_EVIDENCE = (
    "authorized Cloudflare zone and hostname ownership",
    "real self-hosted Access application matching the exact hostname",
    "Access deny-by-default allowlist, MFA, sessions and absence of bypass",
    "unauthorized and authorized Cloudflare edge request acceptance",
    "real teamName and single Access Application Audience tag verified out of band",
    "cloudflared Access JWT validation on actual connector or origin equivalent",
    "RS256 signature, issuer, audience, expiry, nbf, kid and rotating JWKS negatives",
    "least-privilege connector service and secret-delivery independent host QA",
    "socket unlink/rebind fresh-namespace recovery under repeated faults",
    "credential rotation, outage withdrawal and logs without tokens",
    "separate physical browser/device acceptance",
    "independent security signoff and explicit public-release approval",
)


def _get(data: Mapping[str, Any], *names: str) -> Any:
    value: Any = data
    for name in names:
        if not isinstance(value, Mapping):
            return None
        value = value.get(name)
    return value


def review(design: Mapping[str, Any]) -> DesignReview:
    """Validate the *shape* of a proposed design; never treat it as evidence."""
    failures: list[str] = []

    def require(test: bool, code: str) -> None:
        if not test:
            failures.append(code)

    if not isinstance(design, Mapping):
        raise TypeError("review needs a mapping, not a serialized secret/config")

    identity = _get(design, "identity")
    require(isinstance(identity, Mapping), "IDENTITY_REVIEW_REQUIRED")
    require(_get(design, "identity", "dynamic_user") is True, "UNPRIVILEGED_DYNAMIC_UID_REQUIRED")
    require(_get(design, "identity", "run_as_root") is False, "ROOT_CONNECTOR_FORBIDDEN")
    require(_get(design, "identity", "shares_www_uid") is False, "SHARED_WWW_IDENTITY_FORBIDDEN")
    require(_get(design, "identity", "socket_parent_mode") == "0700", "ORIGINAL_0700_PARENT_MUST_REMAIN")
    require(_get(design, "identity", "read_only_single_socket_bind") is True, "SINGLE_SOCKET_BIND_REQUIRED")
    require(_get(design, "identity", "original_socket") == ORIGIN_SOCKET, "ORIGIN_SOCKET_MISMATCH")
    require(_get(design, "identity", "mounted_socket") == MOUNTED_SOCKET, "PRIVATE_MOUNT_TARGET_REQUIRED")
    require(_get(design, "identity", "can_read_laravel_env") is False, "LARAVEL_ENV_MUST_BE_DENIED")
    require(_get(design, "identity", "can_connect_fpm") is False, "FPM_SOCKET_MUST_BE_DENIED")
    require(_get(design, "identity", "no_public_tcp_listener") is True, "PUBLIC_TCP_LISTENER_FORBIDDEN")

    sandbox = _get(design, "service")
    require(isinstance(sandbox, Mapping), "CONNECTOR_UNIT_REVIEW_REQUIRED")
    for prop in ("no_new_privileges", "private_tmp", "protect_home",
                 "strict_filesystem", "no_capabilities", "dedicated_credential_delivery"):
        require(_get(design, "service", prop) is True, "SERVICE_HARDENING_MISSING_" + prop.upper())
    families = _get(design, "service", "address_families")
    require(isinstance(families, list) and
            {"AF_UNIX", "AF_INET", "AF_INET6"}.issubset(set(x for x in families if isinstance(x, str))),
            "AF_UNIX_AND_OUTBOUND_INET_REVIEW_REQUIRED")
    require(_get(design, "service", "credentials_in_unit_or_repository") is False,
            "CREDENTIALS_IN_UNIT_OR_GIT_FORBIDDEN")
    require(_get(design, "service", "shared_aa_panel_service") is False,
            "EXISTING_SHARED_SERVICE_RECONFIGURATION_FORBIDDEN")

    lifecycle = _get(design, "lifecycle")
    require(isinstance(lifecycle, Mapping), "RESTART_DESIGN_REQUIRED")
    for prop in ("origin_readiness_gate", "recheck_socket_inode", "fresh_namespace_on_rebind",
                 "restart_after_new_socket_ready", "stale_mount_negative_test",
                 "fail_closed_on_ambiguity", "quarantine_on_exhaustion",
                 "no_tcp_fallback", "remove_old_namespace_before_recovery"):
        require(_get(design, "lifecycle", prop) is True,
                "LIFECYCLE_CONTROL_MISSING_" + prop.upper())
    budget = _get(design, "lifecycle", "max_restarts_per_window")
    require(type(budget) is int and 1 <= budget <= 3, "BOUNDED_RESTART_BUDGET_REQUIRED")

    access = _get(design, "access")
    require(isinstance(access, Mapping), "ACCESS_APP_DESIGN_REQUIRED")
    for prop in ("application_before_route", "default_deny", "mfa_required",
                 "explicit_identity_allowlist", "all_paths_protected", "deny_unauthorized"):
        require(_get(design, "access", prop) is True, "ACCESS_CONTROL_MISSING_" + prop.upper())
    require(_get(design, "access", "bypass_policies") == [], "ACCESS_BYPASS_FORBIDDEN")
    require(_get(design, "access", "allow_everyone") is False, "ACCESS_ALLOW_EVERYONE_FORBIDDEN")
    require(_get(design, "access", "wildcard_hostname") is False, "ACCESS_WILDCARD_FORBIDDEN")
    session = _get(design, "access", "max_session_hours")
    require(type(session) is int and 1 <= session <= 8, "ACCESS_SESSION_REVIEW_REQUIRED")

    ingress = _get(design, "ingress")
    require(isinstance(ingress, Mapping), "EXACT_INGRESS_DESIGN_REQUIRED")
    require(_get(design, "ingress", "hostname") == CANONICAL_HOST, "CANONICAL_HOST_REQUIRED")
    require(_get(design, "ingress", "service") == "unix:" + MOUNTED_SOCKET, "PRIVATE_UNIX_ORIGIN_REQUIRED")
    require(_get(design, "ingress", "originRequest", "httpHostHeader") == CANONICAL_HOST, "PRESERVE_CANONICAL_HOST")
    require(_get(design, "ingress", "originRequest", "access", "required") is True, "CLOUDFLARED_ACCESS_REQUIRED")
    require(_get(design, "ingress", "catch_all_service") == "http_status:404", "DENY_ALL_CATCH_ALL_REQUIRED")
    require(_get(design, "ingress", "path_restricted_access_rules") is False,
            "PATH_SCOPED_ACCESS_RULES_FORBIDDEN")
    require(_get(design, "ingress", "other_hostname_routes") == [], "OTHER_HOSTNAME_ROUTES_FORBIDDEN")
    team = _get(design, "ingress", "originRequest", "access", "teamName")
    aud = _get(design, "ingress", "originRequest", "access", "audTag")
    require(isinstance(team, str) and team.strip() != "" and
            "<" not in team and ">" not in team, "EXPLICIT_TEAM_NAME_REQUIRED")
    require(isinstance(aud, list) and len(aud) == 1 and
            isinstance(aud[0], str) and aud[0].strip() != "" and
            "<" not in aud[0] and ">" not in aud[0], "EXACT_ONE_APP_AUDIENCE_REQUIRED")

    jwt = _get(design, "jwt")
    require(isinstance(jwt, Mapping), "JWT_VALIDATION_DESIGN_REQUIRED")
    require(_get(design, "jwt", "input_header") == ACCESS_HEADER, "JWT_ASSERTION_HEADER_REQUIRED")
    require(_get(design, "jwt", "algorithms") == ["RS256"], "PIN_RS256_ALGORITHM")
    require(_get(design, "jwt", "verify_signature") is True, "VERIFY_JWT_SIGNATURE")
    for prop in ("verify_issuer", "verify_audience", "verify_expiration",
                 "verify_not_before", "reject_missing_token", "reject_kid_unknown",
                 "fail_closed_when_jwks_unavailable"):
        require(_get(design, "jwt", prop) is True, "JWT_CHECK_MISSING_" + prop.upper())
    require(_get(design, "jwt", "key_source") == "rotating_cloudflare_jwks",
            "REMOTE_ROTATING_JWKS_REQUIRED")
    require(isinstance(team, str) and
            _get(design, "jwt", "jwks_url") ==
            f"https://{team}.cloudflareaccess.com/cdn-cgi/access/certs",
            "JWT_JWKS_ENDPOINT_MISMATCH")
    for control in ("validate_kid_against_jwks", "refresh_keys_on_rotation"):
        require(_get(design, "jwt", control) is True, "JWT_CONTROL_MISSING_" + control.upper())
    require(_get(design, "jwt", "hardcoded_public_cert") is False,
            "HARDCODED_CERTIFICATE_FORBIDDEN")
    require(_get(design, "jwt", "trust_header_only") is False,
            "UNSIGNED_HEADER_TRUST_FORBIDDEN")
    require(isinstance(team, str) and
            _get(design, "jwt", "issuer") == f"https://{team}.cloudflareaccess.com",
            "JWT_TEAM_ISSUER_MISMATCH")
    require(isinstance(aud, list) and len(aud) == 1 and
            _get(design, "jwt", "audience") == aud[0], "JWT_APP_AUDIENCE_MISMATCH")

    return DesignReview(tuple(sorted(set(failures))), REQUIRED_LIVE_EVIDENCE)
