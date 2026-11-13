# Step 12R — live Cloudflare read-only evidence and external signoff pack

**2026-11-13. Installation decision: BLOCKED. Public publication: BLOCKED.**
This report captures an actual read-only Cloudflare API inspection and
extends the previous synthetic Step12Q design review. It does NOT enable
Access, install cloudflared, create a tunnel, change DNS, read tokens or
restart a host service. Only a separate authorized human reviewer can
approve the eventual real unit and live Cloudflare application/policy.

## Account/zone selection verified (read-only)

The connected Cloudflare API returned two account names, Bambodia and
Ton Samith. The *active* zone named bambodia.com belongs to the
**Bambodia** account. All subsequent queries explicitly selected that
account and the bambodia.com zone. No account identifiers, credentials,
policy membership lists or tokens are committed into this report.

| Cloudflare read operation | Result | Acceptance |
| --- | --- | --- |
| GET /zones?name=bambodia.com | Zone active, owned by Bambodia account | Zone association confirmed |
| GET /accounts/{account_id}/access/apps | HTTP 403, code 9999, access.api.error.not_enabled | **BLOCK: Access is not enabled** |
| GET /accounts/{account_id}/access/policies | HTTP 403, code 9999, access.api.error.not_enabled | **BLOCK: policies cannot be inspected** |
| GET /accounts/{account_id}/cfd_tunnel | Empty list, 0 tunnels returned | No existing account Tunnel to accept |
| GET /zones/{zone_id}/dns_records?name=preview-qa.bambodia.com | Empty list, 0 exact records | No exact preview DNS record |
| GET /zones/{zone_id}/dns_records?name=*.bambodia.com | Empty list, 0 wildcard records | No wildcard DNS record returned |

**Important:** A 403 from Access is not proof that the account has
secure policies. The API explicitly states that Access is not enabled;
application/policy details were *not* available for validation. No
hostname route/credential, live teamName, AUD, JWKS or signed Access JWT
can be accepted from this result. No separate claim about other DNS
mechanisms or all possible public origins is made.

This inspection made only GET requests; no Cloudflare configuration
mutation. Do not enable Access without explicit owner approval and a
reviewed policy plan, and do not publish the hostname until the
application/policy/negative browser acceptance is complete.

## Reviewed code and why it remains non-deployable

New review-only specimen:
    support/review-only/previewqa-cloudflared.service.review-only

It shows a candidate per-unit DynamicUser identity, no root/www
sharing, strict filesystem protection, empty Linux capabilities,
0700 connector RuntimeDirectory, *single socket* BindReadOnlyPaths
to the dedicated QA HTTP socket, and systemd LoadCredential that would
require an independently provisioned root-only credential source.

**Safety latches**: the file is not a .service unit, has no WantedBy,
uses ExecStart=/usr/bin/false, refuses manual start/stop, and has an
unprovisioned ConditionPathExists and LoadCredential path. It does
not contain a cloudflared executable path, credentials, tunnel UUID
or token. It is NOT ready to be installed, started or approved as a
functioning unit. In particular, the real cloudflared binary and
credential location must be independently validated against the
reviewed OS and cloudflared version, then tested on a disposable host.

New placeholder-only ingress specimen:
    support/review-only/previewqa-cloudflared-ingress.yml.review-only

It names the exact preview hostname and a connector-private
unix: socket, preserves the Host, requires
originRequest.access.required=true, uses intentionally invalid
teamName/AUD/tunnel identifiers, and ends in http_status:404.
It is NOT usable with Cloudflare and must never be copied to a live
service configuration.

Systemd and ingress fixture safety checker:
    support/previewqa_step12r_review_fixture_audit.py

Mutation/negative tests:
    tests/security/test_previewqa_step12r_review_fixtures.py

They reject accidental unit enablement, executable start,
root/www identity, expanded filesystem permissions, TCP origin,
additional hostname, disabled Access validation and credential
values in service Environment. Passing checks mean only that the
examples remain deliberately non-deployable.

## Cryptographic JWT acceptance preparation

New offline test:
    tests/security/previewqa-step12r-jwt-crypto.test.mjs

Uses Node's built-in RSA-SHA256 cryptography with **ephemeral fictional
RSA keys generated in memory during CI**. It exercises:
- valid signed RS256 JWT with fictional issuer/application audience;
- missing/malformed token and cookie-only requests;
- algorithm none, HS256 and RS384 rejection;
- wrong issuer or audience despite valid signature;
- payload, header, and signature tampering;
- expiration, future not-before and invalid time types;
- missing or unknown JWKS kid and unavailable key map;
- key-rotation overlap and old-key revocation.

These are **real cryptographic operations against synthetic fixtures**,
but not live Cloudflare assertions, real JWKS fetching/rotation, real
origin JWT verification or an installed cloudflared enforcement path.
No production validator was added to Laravel or any origin.

Cloudflare's official documentation recommends validating the
Cf-Access-Jwt-Assertion signature, issuer and application audience
using the team-specific JWKS. For a locally managed Tunnel it supports
originRequest.access.required, teamName and audTag. Neither a
header's presence nor a passing unit test is real user authorization.

## Automated recovery acceptance status

- Step12M v2 (real aaPanel with disposable synthetic sockets):
  existing private bind mount stale after mock inode replacement,
  connection refused (errno111).
- Step12N (real aaPanel with disposable synthetic sockets):
  newly instantiated private DynamicUser mount namespace connected
  to replacement synthetic socket with HTTP 200, with cleanup.
- Step12O (independent real-host read-only audit): original QA HTTP
  socket inode/device unchanged 117593/26, host guard HTTP 200/421,
  transient units removed, withdrawal writes OFF.
- Step12P (pure fail-closed lifecycle decision model and 64-case
  publication negative matrix): GitHub CI passed.
- Step12Q (offline design review and negatives): GitHub CI passed.
- Step12R: offline fixture and signed JWT negatives are review aids,
  **not** actual connector automatic restart acceptance.

The real Nginx origin has not been restarted in this experiment.
There is no real connector binary, unit, credentials or automatic
lifecycle controller, so no real restart-loop, network outage,
Cloudflare remote policy or token acceptance has been demonstrated.

## Independent review signoff requirements (all OPEN)

| Gate | Evidence that separate reviewer must inspect | State |
| --- | --- | --- |
| Identity | Real pinned cloudflared binary/version/hash, installed unit, nonroot UID, filesystem denial, single private Unix mount | OPEN |
| Secrets | Root-only source, systemd private credential copy, storage/rotation/revocation, no argv/log or Git leakage | OPEN |
| Restart orchestration | Actual dedicated origin restart behavior, bounded fresh-namespace recovery, crash loops, quarantine, rollback, no production service side effects | OPEN |
| Access | Enable Access with explicit owner approval; protected self-hosted application for exact hostname, allowlist/MFA, no bypass, all paths, short sessions | **BLOCK — disabled** |
| JWT | Real team and AUD, signed RS256 with kid/JWKS rotation, invalid token rejection before origin, actual positive/negative account tests | OPEN |
| Routing | Strict exact-host UNIX-only ingress, deny-all catch-all, preserve Host, no public TCP fallback | OPEN |
| Live testing | Authorized vs unauthorized browsers/devices, token expiration/revocation, outage failures, emergency withdrawal | OPEN |
| Release | Separate security reviewer signoff + explicit owner authorization for Access, connector installation, DNS/public route | OPEN |

A separate human reviewer must link their evidence and record scope,
review date, actual binary/unit revisions, Cloudflare account state
and approval independently. This document is not their signature.

**Gate decision:** Step12R review preparation may proceed in draft
GitHub; do not install cloudflared, enable Access, create policy/Tunnel,
publish DNS/TLS, merge PR #44/#37 or restart real QA HTTP/FPM. Keep
Preview Visibility withdrawal writes OFF and production unchanged.

## Official sources

- https://developers.cloudflare.com/cloudflare-one/access-controls/applications/http-apps/self-hosted-public-app/
- https://developers.cloudflare.com/tunnel/reference/origin-parameters/
- https://developers.cloudflare.com/cloudflare-one/access-controls/applications/http-apps/authorization-cookie/validating-json/
- https://www.freedesktop.org/software/systemd/man/249/systemd.exec.html
