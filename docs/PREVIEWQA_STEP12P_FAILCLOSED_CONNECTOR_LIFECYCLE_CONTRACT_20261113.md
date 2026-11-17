# Step 12P — fail-closed connector lifecycle contract and release gate

**State: deterministic, advisory-only design and CI regression.**
**No real connector installation, Access application, Tunnel, public DNS, TLS
or production change has been authorized or performed.**

## Accepted real aaPanel evidence

The owner executed the integrity-pinned independent Step12O host audit on
2026-11-13. It completed with:

    STEP12O_POST_SYNTHETIC_RECOVERY_INDEPENDENT_AUDIT=PASS

The report confirmed the current isolated six-user synthetic QA database,
disabled Preview Visibility withdrawals, a canonical-host HTTP 200 and
unrecognized-host HTTP 421 over the dedicated private Unix socket, QA HTTP
and PHP-FPM active but disabled at boot, socket parent 0700 and HTTP socket
file mode 0666, shared www denial, and complete absence of all prior
Step12K, Step12M and Step12N transient mock units/directories.

The real QA HTTP socket inode/device remained 117593/26, matching earlier
read-only observations. cloudflared executable, named account and default
unit remain absent, and the audit performed no restarts or config writes.
All five CI workflows passed for the exact Step12O source commit.

Step12M v2 separately observed the old service-private socket mount return
ECONNREFUSED after the synthetic source pathname was unlinked and rebound.
Step12N observed a NEW DynamicUser namespace/mount reconnect to the new
synthetic socket with HTTP 200. Both results are limited to **mock**
Unix sockets; the real Nginx/PHP-FPM were never restarted.

## Implemented advisory contract

Source:
    support/previewqa_step12p_failclosed_lifecycle.py

Tests:
    tests/security/test_previewqa_step12p_failclosed_lifecycle.py

The model accepts **caller-supplied facts only**. It does not inspect the
server, read secrets, connect to Cloudflare, create network sockets or run
commands. No service imports this model in production.

It produces separate recommendations:
- Runtime: hold absent/unhealthy origin, hold unreviewed connector identity,
  hold missing Access/JWT verification, quarantine exhausted restart budget,
  create a new mount namespace when no mount is available, recycle a stale
  namespace after inode mismatch, recycle an unhealthy private connector,
  or report a healthy private runtime.
- Publication: deny, deny-and-withdraw-if-exposed, or **eligible for
  independent approval** only when every specified security/release gate
  is complete. Even a positive model result is NOT an instruction to
  publish, install a Tunnel, create DNS or activate a service.

The publication gate requires:
1. Origin exists, returns successful private readiness, and its current
   inode matches the private mounted inode.
2. Connector process is healthy, runs in a reviewed independent least-
   privilege identity, and has a separately approved private service.
3. Cloudflare Access self-hosted policy verified to deny unauthorized
   identities; JWT issuer/signature/audience checks independently passed.
4. Connector credential management and rotation controls accepted.
5. Hostname and DNS authority confirmed, independent security acceptance
   passed, explicit public release approval recorded.
6. Restart budget is not exhausted. If the route is already exposed
   while any gate is missing, the advisory result is fail-closed with
   a withdrawal recommendation, not continued service.

**This model is not a Cloudflare Access JWT validator**, credential
manager, lifecycle operator, systemd unit, monitor or production service.
Booleans are intentionally not populated by guessed values or trusted
based merely on a CLI switch. Independent evidence must support them.

## Fault/negative test matrix

The unittest suite includes:
- Current QA state (origin private and healthy, connector uninstalled)
  fails publication.
- Origin missing or unhealthy; missing socket inode.
- Unreviewed connector process identity; Access or JWT not validated.
- No mount, mismatched old/new socket inodes; stale mount recovery.
- Restart budget exhausted; unhealthy connector.
- Unauthorized/public route accidentally exposed and emergency withdrawal
  recommendation.
- Every required publication gate independently missing.
- Full 64-case Access/release negative matrix.
- Invalid socket inode and restart budget values refused.
- Even all flags true only returns eligibility for independent approval,
  not a command that actually publishes or deploys.

## Required implementation architecture before connector installation

Design a separate reviewable service and lifecycle controller only after
the Cloudflare Access-first policy gate and connector identity review.

- The origin remains Unix-only under the private 0700 parent; never
  chmod that directory, add shared www to its group, or bind public TCP.
- A dedicated unprivileged connector may receive only the specific
  HTTP Unix socket in its own private mount namespace. Never give it
  the source directory, PHP-FPM socket, database credentials or .env.
- Restart ordering must ensure the fresh mount namespace is constructed
  after the **new** origin socket inode is available. A long-running old
  namespace is explicitly not assumed to recover on its own.
- Confirm failure state by private socket connectivity, not merely by
  the systemd active state, pathname existence or upstream proxy health.
- Enforce a bounded restart budget with jitter/cooldown and a visible
  quarantine mode. Stop or withdraw any external route on ambiguity.
- Independently test multiple rapid socket replacements, concurrent
  in-flight requests, permissions after restarts, process crash loops,
  token revocation, signed Access JWT expiry/issuer/audience enforcement,
  logs without secrets and emergency rollback.

A synthetic decision model is helpful to reject unsafe state
transitions, but it cannot itself prove the Linux/Cloudflare control-plane
behavior above.

## Release barriers explicitly retained

Draft PR #44 stays draft; PR #37 stays draft. Do not merge to main,
restart real QA Nginx/PHP-FPM, modify production/aaPanel shared services,
install cloudflared, access connector credentials, publish DNS/TLS,
configure public Tunnel/Cloudflare Access without authorization, or
enable Preview Visibility withdrawal writes or other pending features.
Root-private original backups must remain untouched.
