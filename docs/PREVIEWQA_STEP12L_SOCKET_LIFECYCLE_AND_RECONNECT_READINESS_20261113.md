# Step 12L — Unix socket lifecycle and reconnect readiness

**Status: source prepared; no real-host Step12L audit yet.**

## Accepted preceding milestone

On 2026-11-13, the operator ran Step12K from source commit
e1b1f4e9edec48c9fd8f5f129e910cc24ff472f1, exact verified Git
blob 46c6ad2666c89232aa6a121e91fff71a7b8b617e.

The real aaPanel host returned STEP12K_DYNAMIC_USER_SOCKET_REHEARSAL=PASS.
A one-shot systemd DynamicUser process reached the dedicated QA origin
over a unit-private bind-mounted Unix socket, without a root UID, direct
origin-path access, Laravel .env access or FPM socket access. The
transient service and runtime directory were collected. The origin's
0700 parent, shared www denial, synthetic QA database and disabled
Preview Visibility withdrawal writes remained intact.

All five CI workflows passed on the same immutable Step12K commit.

The test did **not** prove that a long-lived bind mount remains usable
when the origin Nginx service restarts and recreates its listening
socket. It did not install cloudflared or configure public routing.

## Why lifecycle is a distinct gate

An AF_UNIX socket bound at a filesystem pathname is represented by a
filesystem object. When Nginx restarts, the old socket pathname may be
unlinked and rebound to a new object. A bind mount scoped to another
service may refer to the previous socket inode and must not be assumed
to redirect automatically to the replacement.

The eventual connector design must specify whether it can reconnect
after both:
- Nginx reload or worker rotation (potentially without replacing the
  listening socket inode), and
- a full Nginx stop/start or crash recovery (potentially replacing it).

This matters even though the current QA services are disabled at boot.
Do not reboot, reload or restart either existing QA service as a test.

## Step12L read-only audit

Source:
    support/previewqa-step12l-unix-socket-lifecycle-readonly-20261113.sh

The script verifies:
1. The exact pinned synthetic QA source and six-user isolated database;
   Preview Visibility writes remain OFF.
2. QA Nginx and PHP-FPM active but disabled at boot, private 0700 socket
   parent, unexpected Host rejected with 421, and shared www denied.
3. Root-only original Host-guard backup, dedicated configs/units and
   existing FPM socket policy.
4. HTTP pathname socket inode/device/link count/mode **as observational
   identifiers only**, and systemd Restart/RuntimeDirectoryPreserve/Type.
5. The prior Step12K transient unit/runtime directory remain absent.
6. No real cloudflared executable/account/unit has appeared since
   the earlier readiness acceptance.

This script reads host state only. It does not run any systemd probe,
create mounts or users, stop/start/restart services, change permissions,
perform Nginx reloads, read credentials or publish DNS.

Expected:
    STEP12L_SOCKET_LIFECYCLE_INVENTORY=PASS

The output also clearly states:
    BIND_MOUNT_SOCKET_INODE_REBIND_AFTER_RESTART=NOT_TESTED
    DYNAMIC_USER_LONG_RUNNING_RECONNECT=NOT_TESTED

A passing read-only audit is NOT evidence that reconnection works.

## Next independent acceptance

After Step12L real-host results are reviewed, design a separately
approved **disposable mock** Nginx-like Unix socket recreation test.
It must create its own private temporary socket and transient
connector-client namespaces, test a synthetic socket inode replacement,
and clean up without touching the actual QA HTTP/FPM services,
Nginx config, production, root backups or any public endpoint.

Only if a safe mechanism demonstrates reconnection after mock socket
replacement should an actual long-lived connector architecture be
reviewed. Alternatives include a stable socket-activation handoff or
a connector restart dependency on the origin service, each requiring
operational and independent security tests.

## Publication gates still closed

Do not install cloudflared yet. No Access application/policy has been
verified; no signed JWT, issuer/audience or credential controls have
passed. No DNS, TLS, Cloudflare Tunnel, public QA hostname or production
main merge is authorized. Draft PR #44 and draft PR #37 remain open;
Preview Visibility withdrawal writes stay disabled. Preserve root-only
backups and private socket parent mode 0700.
