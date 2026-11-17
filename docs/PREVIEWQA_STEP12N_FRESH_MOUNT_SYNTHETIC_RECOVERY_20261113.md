# Step 12N — private mount namespace recovery after AF_UNIX socket replacement

**Status: synthetic test source prepared in draft PR #44, NOT executed on
the real aaPanel host.** No production deployments, Cloudflare connectors,
public routes, user identities or QA service restarts are authorized.

## Ground truth from Step 12M v2

On 2026-11-13 the operator ran immutable Step12M v2 commit
c0d3dc756aaee921e78332192d87b549a060b71e, source blob
d4d2a885060ebb30ed00229256fee790636b2dbb, with a passing
read-only safety preflight followed by a separately approved synthetic
transient DynamicUser mock probe.

The real aaPanel output showed:

    MOCK_SOURCE_PARENT_MODE=0700
    MOCK_SOURCE_SOCKET_MODE=0666
    MOCK_ORIGINAL_AND_REPLACED_INODES=DIFFERENT
    MOCK_REPLACEMENT_SOURCE_HTTP=200
    DYNAMIC_UID_INITIAL_MOCK_HTTP=200
    BOUND_MOUNT_AFTER_SOURCE_REBIND=CONNECT_ERRNO_111
    CONNECTOR_REBIND_CONCERN=STALE_BOUND_SOCKET_REQUIRES_RECOVERY_DESIGN
    MOCK_TRANSIENT_SERVICE_AND_RUNTIME=COLLECTED
    REAL_QA_SERVICES_UNCHANGED=YES
    STEP12M_V2_DISPOSABLE_MOCK_SOCKET_REBIND=OBSERVED

A previously created per-unit systemd BindReadOnlyPaths mount remained
associated with the obsolete Unix socket inode after synthetic source
unlink/rebind. New connections through that mount got Linux errno 111,
ECONNREFUSED, while direct connections to the replacement mock source
returned 200. This establishes the defect in the **tested mock**, not
behaviour under a real Nginx restart, nor Cloudflare readiness.

All five GitHub CI workflows for the exact Step12M v2 code were SUCCESS.

## Proposed Step12N recovery hypothesis

Starting a NEW unprivileged transient service after the original one
has exited creates a fresh private mount namespace. If it binds the
current synthetic socket pathname after the inode has been replaced,
the new client should receive HTTP 200. This is a candidate process
lifecycle recovery strategy, **not** an approved production service
restart mechanism.

Source:
    support/previewqa-step12n-fresh-mount-recovery-20261113.sh

1. \`--check\` verifies pinned isolated synthetic QA app/database, Preview
   Visibility withdrawals OFF, active but boot-disabled Nginx/FPM,
   canonical Host guard and 0700 socket parent, denied shared-www
   connection, absence of cloudflared and prior fixed-name transient
   test, and parser-only systemd DynamicUser/BindReadOnlyPaths syntax.
   No unit, socket, mount or mock Python server starts.
2. An independent, explicitly approved \`--probe\` creates a disposable
   root-owned 0700 /run/bambodia-step12n.* directory and mock HTTP
   AF_UNIX listener with socket inode mode 0666.
3. It starts a short-lived DynamicUser transient service with a private
   mount of **only the synthetic mock socket**, no capabilities, AF_UNIX
   address-family only, strict filesystem protections and bounded
   runtime. The initial mock HTTP GET must return 200.
4. The mock listener alone is unlinked and rebound at the same source
   pathname, yielding a new inode; the replacement direct source GET
   must return 200. The old namespace must observe ECONNREFUSED (111)
   at the stale bound socket; unexpected behavior blocks the result.
5. The old transient unit and RuntimeDirectory must be collected.
6. A NEW transient instance with the same synthetic service contract
   is launched and binds the **new** source socket inode in a **fresh**
   private mount namespace. It must report
   FRESH_NAMESPACE_NEW_SOCKET_HTTP=200, exit, and be collected.
7. The real QA HTTP/FPM services are verified active and disabled at
   boot, unchanged parent permissions, no Cloudflare/public routes.
   The private synthetic files and listeners are removed.

Expected read-only preflight:
    STEP12N_FRESH_MOUNT_RECOVERY_PREFLIGHT=PASS

Expected completed synthetic observation:
    OLD_NAMESPACE_STALE_MOUNT=ECONNREFUSED
    FRESH_NAMESPACE_NEW_SOCKET_HTTP=200
    FRESH_TRANSIENT_UNIT_AND_RUNTIME=COLLECTED
    CONNECTOR_RECOVERY_MECHANISM=NEW_MOUNT_NAMESPACE_REQUIRED
    STEP12N_DISPOSABLE_MOCK_SOCKET_RECOVERY=OBSERVED

The final marker does **not** prove production readiness. The test
does not install cloudflared, run a persistent connector or restart the
actual QA origin, test loss of remote tunnel connectivity, service
dependency ordering, high availability, readiness checks, fail-close
rollback, JWT/Access auth, credential storage, DNS/TLS or browser devices.

## Operational and safety risks

- The current private QA HTTP unit is Type=simple, Restart=no,
  RuntimeDirectoryPreserve=no, with its Unix socket inside a dedicated
  ingress-owned 0700 parent. Changes to it require a separate
  acceptance and operator approval.
- The connector must not run as root, read Laravel .env, access PHP-FPM,
  share its UID with unrelated services, or open a TCP ingress listener.
- A new mount namespace must be created *after* a replaced origin
  socket inode appears. Restart alone is not sufficient unless the
  connector service is independently verified to build a fresh mount
  and reconnect only to the approved socket.
- Separate systemd dependencies (PartOf, BindsTo, ExecStartPre or
  socket activation) are design candidates, not an authorized change.
- Keep connection outages fail-closed, and review logging to avoid
  emitting tokens, credentials and personally identifiable data.
- Use a disposable, synthetic-only rehearsal before any real origin
  restart tests, and review negative/cleanup markers on failure.

## Release boundaries

Steps 12E–12L have real-host security acceptance. Step12M v2 produced
the synthetic stale-mount observation. Step12N is not executed yet.

PR #44 stays draft, PR #37 stays draft, six-user synthetic QA database
retained, Preview Visibility withdrawals OFF. No main merge or
production upgrade. Cloudflare Access policies, JWT issuer/audience
validation, credentials, Tunnel, public DNS/TLS and actual connector
runtime remain unconfigured and require independent review.
