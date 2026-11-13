# Step 12Q — independent pre-connector architecture/security review

**Review date:** 2026-11-13  
**Scope:** Bambodia PreviewQA local Unix-origin and draft PR #44's proposed
connector identity, service lifecycle, Cloudflare Access and JWT controls.  
**Disposition:** **BLOCK REAL CONNECTOR INSTALLATION AND BLOCK ALL PUBLICATION.**  
**Type:** Source/documentation desk review plus synthetic negative tests.
It is **not** an external security attestation, live Cloudflare account
inspection, actual JWT verification, or real connector acceptance.

## Materials and scope boundaries

Reviewed repo design:
- docs/PREVIEWQA_STEP12J_CONNECTOR_IDENTITY_AND_ACCESS_FIRST_DESIGN_20261113.md
- docs/PREVIEWQA_STEP12O_FAIL_CLOSED_RECOVERY_READINESS_20261113.md
- docs/PREVIEWQA_STEP12P_FAILCLOSED_CONNECTOR_LIFECYCLE_CONTRACT_20261113.md
- support/previewqa_step12p_failclosed_lifecycle.py
- support/previewqa-step12o-post-recovery-independent-readonly-audit-20261113.sh
- GitHub issue #35 and draft PR #44, including verified on-host Step12N/12O
  acceptance markers furnished by the operator.

Current aaPanel evidence at Step12O:
- QA HTTP and PHP-FPM active but disabled at boot;
- isolated synthetic six-user database; Preview Visibility withdrawals OFF;
- Host preview-qa.bambodia.com /up HTTP 200, unknown Host HTTP 421;
- Unix HTTP socket (inode 117593, device 26) mode 0666 *inside* an
  ingress-owned 0700 directory; FPM socket independently restricted;
- shared www denied access and Laravel .env not exposed to ingress user;
- prior Step12K/M/N synthetic transient services and test paths absent;
- real cloudflared binary, service and OS identity absent;
- real Nginx restart recovery, Access/JWT, DNS/TLS not tested.

The Step12M v2 and Step12N real-host synthetic tests established that
a pathname AF_UNIX bind mount can become STALE (errno111) when its
source inode changes, while a new DynamicUser mount namespace can
access the replacement synthetic socket (HTTP 200). Neither test
restarted the real QA Nginx service.

**No assumption is made about the user's current Cloudflare zone,
teamName, AUD tag, application, policies, credentials or account access.**

## Executive finding matrix

| ID | Severity | Domain | Independent finding | Release disposition |
| --- | --- | --- | --- | --- |
| Q-01 | Critical | Access | No authenticated evidence of an Access application, policy, identity allowlist, MFA, session settings or bypass-rule inventory for the exact hostname | BLOCK |
| Q-02 | Critical | JWT | No implemented connector-side/origin-side RS256 validation shown working against actual authorized Access tokens, issuer, audience, expiration, unknown kid or key rotation | BLOCK |
| Q-03 | High | Identity | No real cloudflared binary, dedicated OS service/identity, credential delivery, outbound-network scope or filesystem sandbox has been installed and independently reviewed | BLOCK |
| Q-04 | High | Recovery | Recovery after real origin socket replacement, crash loops, rapid restarts and an automatic fail-closed controller is untested; Step12N is a synthetic one-shot observation | BLOCK |
| Q-05 | High | Ingress | No installed, account-reviewed exact-host ingress rule, private UNIX service, origin Host override, per-route required Access validation or catch-all rejection | BLOCK |
| Q-06 | High | Secrets | No approved token provisioning, service-private read scope, rotation, revocation, rollback or leak-free audit procedure | BLOCK |
| Q-07 | Medium | Review model | Step12P booleans are caller supplied. Code can describe desired state but cannot attest Cloudflare facts or exercise any JWT signature check. Must never be wired directly to a deploy operation | BLOCK if used as approval |
| Q-08 | Medium | Path routing | Cloudflare 2026.10/2026.11 URL normalization is not universally on by default; path-scoped Access/ingress rules can have canonicalization ambiguities. Prefer one exact-host rule protecting ALL paths and test adversarial encodings | BLOCK unsafe path rules |
| Q-09 | High | Fail-close | No demonstrated emergency public-route withdrawal and origin-bypass prevention under missing Access, unknown team/audience, unavailable certs or stale socket | BLOCK |
| Q-10 | Medium | Host guard | HTTP 421 on unknown Host is working but Host filtering is **not authentication**; only a complementary origin-level constraint | No substitute for Q-01/Q-02 |

There are no claimed public release approvals. These are **open
security/operational controls**, not confirmed vulnerabilities or
evidence that a live publicly reachable service is exposed.

## 1. Connector operating-system identity — reviewed design

**Conceptual finding:** A distinct unprivileged DynamicUser service
with a private per-unit socket bind is preferable to running cloudflared
as root, as www or as the private Nginx worker UID. The accepted Step12K
proved a disposable unprivileged client could connect through a
private socket bind while being denied the original 0700 directory,
Laravel env and FPM socket. This does not approve a persistent service.

Required before real connector installation:
- Reviewer-approved systemd unit and executable digest/version; service
  cannot read the source origin directory or arbitrary aaPanel app files.
- Binding of exactly one HTTP socket file to a connector-private
  RuntimeDirectory (0700), no original parent permission changes, no
  shared www group membership or TCP bridge.
- No root process; bound capabilities empty; NoNewPrivileges,
  ProtectSystem=strict, ProtectHome, PrivateTmp; only necessary outbound
  Cloudflare network access and no publicly bound local port.
- Credentials provided via a dedicated, reviewed service-private
  mechanism, never argv/environment echoed in logs or checked into Git.
  Validate ownership/mode, file descriptors, credential rotation and
  behavior when missing.
- Negative host tests for www/nobody, Laravel .env, PHP-FPM, unrelated
  sockets, credential exposure, and direct public ingress.

**Status:** design direction accepted for further rehearsal; actual
systemd connector unit and privilege tests NOT ACCEPTED.

## 2. Fresh mount and restart orchestration

A per-unit bind may remain attached to an old socket inode after
pathname unlink/rebind. Synthetic observation:
old bound socket => ECONNREFUSED (111); newly created namespace =>
HTTP 200. The production lifecycle controller must not infer health
from the process being active or the source pathname merely existing.

Required before real connector installation:
- On origin socket loss, reject new connections; do not switch to a
  TCP port, loosen file access or keep presenting a stale connector.
- Detect and independently verify actual effective mount/connection
  failure; avoid blindly trusting inode equality from unrelated
  mount namespaces or a single health flag.
- Wait for the replacement origin socket and canonical /up HTTP 200,
  then recycle the connector into a NEW per-unit mount namespace.
- Bound and log restart attempts; set cooldown/quarantine; do not
  restart shared aaPanel or production units.
- Test startup order, origin reboot, crash loop, rapid consecutive
  rebinds, in-flight connections, stale mount, missing credentials,
  journald and an emergency withdrawal plan.
- Test fail-closed behavior when the origin cannot return, and ensure
  service disablement leaves no published bypass.

**Status:** synthetic concept supported; actual automatic lifecycle
runtime not built or accepted.

## 3. Cloudflare Access and ingress — sequence mandatory

Cloudflare documentation warns that publishing a Tunnel route before
creating the Access application could expose the service. Therefore:
1. Confirm administrator authority for the exact zone and intended
   preview-qa.bambodia.com hostname.
2. Configure a self-hosted Access application and an **explicit
   identity allowlist** using a reviewed IdP/MFA/session policy, with
   denied public users and no Bypass or Allow Everyone exceptions.
3. Independently review policy order, bypass routes and all-path
   coverage. Capture nonsecret evidence through an authorized reviewer.
4. For a **locally managed** candidate, require one exact-host ingress
   rule with origin service \`unix:/run/bambodia-previewqa-connector/http.sock\`
   (or separately reviewed private path), explicit
   \`originRequest.httpHostHeader=preview-qa.bambodia.com\`, and:
       originRequest.access.required=true
       originRequest.access.teamName=<verified account team>
       originRequest.access.audTag=[<exact approved application AUD>]
   Require a final \`service: http_status:404\` catch-all.
   **This is a design requirement, NOT a ready-to-use config.**
5. Avoid path-scoped Allow/Bypass rules; exact-host policy must protect
   every path, including /up, login, assets and encoded variations.
   Test dot segments, double slashes, backslashes and semicolons with
   the reviewed cloudflared version and normalization settings.
6. Validate ingress matching and deny results before DNS publication.
   Never create public routing as part of the review itself.

Cloudflare \`cloudflared\` documentation explicitly supports the
\`originRequest.access\` block, including required, teamName and audTag.
A top-level JWT string header without signature checks is insufficient.

**Status:** policy/ingress plan reviewed only; no Access application,
public route or Tunnel exists to inspect.

## 4. JWT verification — independent contract

Cloudflare recommends the \`Cf-Access-Jwt-Assertion\` header.
For actual JWT acceptance, enforce all of:
- Token exists; only expected asymmetric RS256 algorithm accepted;
  reject algorithm none/HS256, malformed token and tampered payload.
- Signature checked against the matching \`kid\` in the Cloudflare
  account's JWKS at
  \`https://<verified-team>.cloudflareaccess.com/cdn-cgi/access/certs\`.
  Do not hardcode the currently active PEM as the only key.
- Enforce exact expected
  \`iss=https://<verified-team>.cloudflareaccess.com\`, expected
  application \`aud\` (including array-valued audience claims),
  \`exp\` and \`nbf\`, with documented safe clock skew.
- Unknown \`kid\`, expired token, future \`nbf\`, bad signature,
  wrong team or AUD, deleted/rotated key and JWKS failure fail closed.
  Refresh signing keys and test previous/current key overlap.
- Test cookie omission and assertion-header authentication explicitly.
  A bearer JWT should not be logged.
- If \`cloudflared\` enforces Access, independently verify it denies
  invalid assertions before forwarding requests to the private Unix
  origin. If an application-layer validator is additionally selected,
  review its actual implementation and use cryptographic fixtures.
- Access identity authorization is separate from signature validity:
  a correctly signed token for a different application or a user
  outside the policy must never be accepted.

Cloudflare's 2026-11-02 JWT documentation describes a roughly 6-week
key rotation and 7-day previous-key overlap; lifecycle acceptance must
recheck those published requirements at implementation time.

**Status:** no actual JWT verifier, account signing keys, or
positive/negative live tests independently accepted.

## 5. Offline, independent review checks added

Pure static review source:
    support/previewqa_step12q_independent_review.py

Synthetic test suite:
    tests/security/test_previewqa_step12q_independent_review.py

The reviewer validates a proposed **fictional design shape** for
one unprivileged connector identity, socket-only mount, fail-closed
recovery, Access-before-route policy, one exact-host/catch-all ingress
with \`originRequest.access\` and Host override, and token validation
properties including issuer/audience, RS256 and remote JWKS.
A fictional fully conforming shape can only return
\`DESIGN_CONFORMS_SYNTHETICALLY\` and
\`RELEASE_BLOCKED_EXTERNAL_ATTESTATION_REQUIRED\`.
Bad variants must fail review.

This is **NOT a JWT library, cloudflared config linter, executable
systemd unit, real Cloudflare policy inspection, or release authorizer**.
It contains no account IDs, tokens, real AUD tag or connector credentials.

## 6. Decision and mandatory remaining signoffs

**Independent desk-review disposition: BLOCK INSTALL / BLOCK PUBLICATION.**

No real connector installation can be accepted until the reviewed
systemd/credential implementation, Access app/policy/JWT design,
negative crypto tests, and fail-closed restart/error containment are
ready and independently reviewed. Public publication has additional
requirements: verified Access-first protection, authorized DNS/TLS,
real connector no-bypass acceptance, physical browser/device tests,
emergency withdrawal verification and explicit owner/security signoff.

This review's own code/testing is not a substitute for a different
independent human reviewer and real-host/Cloudflare account evidence.
Do not invent teamName, AUD tag, policy status, JWT or credential data.

## Official documentation consulted (2026-11-13)

- https://developers.cloudflare.com/cloudflare-one/access-controls/applications/http-apps/self-hosted-public-app/
- https://developers.cloudflare.com/cloudflare-one/access-controls/applications/http-apps/authorization-cookie/validating-json/
- https://developers.cloudflare.com/tunnel/reference/origin-parameters/
- https://developers.cloudflare.com/tunnel/features/locally-managed-tunnels/configuration-file/
- https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/routing-to-tunnel/protocols/
- https://www.freedesktop.org/software/systemd/man/249/systemd.exec.html

## Safeguards

PR #44 remains draft; PR #37 remains draft. Preview Visibility withdrawal
writes OFF. No production/main merge, live QA service restart,
credential access, cloudflared installation, public DNS/TLS/Tunnel,
Access control-plane mutation or shared aaPanel service changes.
