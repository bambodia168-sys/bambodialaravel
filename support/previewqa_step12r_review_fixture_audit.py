"""Offline reviewer for deliberately NON-deployable PreviewQA security specimens.

This is a safety latch for examples, not an installation mechanism, JWT
validator, systemd parser, Cloudflare policy validator or production review.
"""
from __future__ import annotations

import configparser
import io


EXPECTED_MOUNT = (
    "/run/bambodia-previewqa-http/http.sock:"
    "/run/bambodia-previewqa-connector/http.sock"
)


def validate_review_fixtures(unit_text: str, ingress_text: str) -> tuple[str, ...]:
    issues: list[str] = []
    parser = configparser.ConfigParser(interpolation=None, strict=True)
    parser.optionxform = str
    try:
        parser.read_file(io.StringIO(unit_text))
    except (configparser.Error, ValueError):
        return ("INVALID_UNIT_FILE",)
    for name in ("Unit", "Service", "Install"):
        if not parser.has_section(name):
            issues.append("MISSING_SECTION_" + name.upper())
    if issues:
        return tuple(sorted(set(issues)))

    unit = parser["Unit"]
    svc = parser["Service"]
    install = parser["Install"]

    expected = {
        "ExecStart": "/usr/bin/false",
        "DynamicUser": "yes",
        "User": "bambodia_pvconnector",
        "Group": "bambodia_pvconnector",
        "NoNewPrivileges": "yes",
        "ProtectSystem": "strict",
        "ProtectHome": "yes",
        "PrivateTmp": "yes",
        "PrivateDevices": "yes",
        "ProtectKernelTunables": "yes",
        "ProtectKernelModules": "yes",
        "ProtectControlGroups": "yes",
        "CapabilityBoundingSet": "",
        "AmbientCapabilities": "",
        "UMask": "0077",
        "RuntimeDirectory": "bambodia-previewqa-connector",
        "RuntimeDirectoryMode": "0700",
        "RestrictAddressFamilies": "AF_UNIX AF_INET AF_INET6",
        "BindReadOnlyPaths": EXPECTED_MOUNT,
        "LoadCredential": (
            "tunnel.json:/etc/bambodia-previewqa-connector/"
            "UNPROVISIONED_REVIEW_ONLY"
        ),
        "Restart": "no",
    }
    for key, value in expected.items():
        if svc.get(key) != value:
            issues.append("SERVICE_REVIEW_LATCH_" + key.upper())

    for dangerous in ("Environment", "EnvironmentFile", "ExecStartPre",
                      "ExecStartPost", "ExecReload", "RootDirectory",
                      "SupplementaryGroups", "ReadWritePaths", "BindPaths",
                      "Socket", "ListenStream", "ListenDatagram"):
        if dangerous in svc:
            issues.append("UNREVIEWED_SERVICE_FIELD_" + dangerous.upper())
    if unit.get("ConditionPathExists") != (
        "/etc/bambodia-previewqa-connector/REVIEW_APPROVED_BUT_NOT_PROVISIONED"
    ):
        issues.append("ABSENT_APPROVAL_CONDITION_REQUIRED")
    if unit.get("RefuseManualStart") != "yes" or unit.get("RefuseManualStop") != "yes":
        issues.append("MANUAL_UNIT_OPERATIONS_NOT_BLOCKED")
    if len(list(install)) != 0:
        issues.append("REVIEW_UNIT_MUST_HAVE_NO_INSTALL_TARGET")
    if "DO NOT INSTALL" not in unit_text:
        issues.append("REVIEW_ONLY_WARNING_MISSING")

    expected_ingress = [
        "tunnel: INVALID_TUNNEL_UNPROVISIONED",
        "credentials-file: /run/credentials/bambodia-previewqa-connector.service/tunnel.json",
        "ingress:",
        "  - hostname: preview-qa.bambodia.com",
        "    service: unix:/run/bambodia-previewqa-connector/http.sock",
        "    originRequest:",
        "      httpHostHeader: preview-qa.bambodia.com",
        "      access:",
        "        required: true",
        "        teamName: INVALID_ACCESS_NOT_ENABLED",
        "        audTag:",
        "          - INVALID_APPLICATION_AUDIENCE",
        "  - service: http_status:404",
    ]
    # Preserve exact indentation and ordering. Unknown additional properties,
    # alternate hosts, TCP origins or real secrets are not accepted as examples.
    actual_lines = [
        line.rstrip() for line in ingress_text.splitlines()
        if line.strip() and not line.lstrip().startswith("#")
    ]
    if actual_lines != expected_ingress:
        issues.append("INGRESS_MUST_STAY_NONDEPLOYABLE_EXACT_HOST_ACCESS_ONLY")
    if "DO NOT install" not in ingress_text:
        issues.append("INGRESS_REVIEW_ONLY_WARNING_MISSING")
    return tuple(sorted(set(issues)))
