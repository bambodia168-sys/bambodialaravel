# Step 12M — blocked initial synthetic socket connection

**On-host result on 2026-11-13: BLOCKED; socket replacement NOT tested.**

## Verified operator evidence

Owner executed Git SHA
e89ce891ce8f61fe8f164200c1486c20b79aa7bf
and Git blob 677c2ae077541f5ffb182140c15a9b7543f8aba0
for
support/previewqa-step12m-disposable-socket-rebind-20261113.sh.

- SCRIPT_INTEGRITY=PASS.
- Two independent safety preflights succeeded, including pinned six-user
  synthetic database, Preview Visibility writes OFF and intact QA isolation.
- The explicitly approved synthetic-only --probe reported:
  BLOCKED_STEP12M_MOCK_REBIND=transient_mock_client_first_response_not_200
  BLOCKED=synthetic_socket_rebind_rehearsal_failed
- No dynamic initial mock HTTP=200 or mock inode replacement marker was
  emitted. Thus the experiment never reached source socket recreation.
- **This does not prove or disprove stale bind-mount behavior.**
- Cleanup logic stopped only its own transient mock unit, but post-failure
  absence was NOT independently checked on the host.

All five GitHub CI workflows passed on the original test commit. CI syntax
checks did not detect the mock listener's Unix socket permission mismatch.

## Likely cause — synthetic socket permission is not production-equivalent

The original script starts with umask 077. Its Python Server constructor
calls socket.bind(path) but does not chmod the newly created mock socket.
On Linux, AF_UNIX pathname socket mode is affected by umask: under umask
077 the root-owned mock socket is normally mode 0700.

The separately accepted real private Nginx HTTP socket is
bambodia_pvhttp:bambodia_pvhttp mode 0666 and protected by a
bambodia_pvhttp-owned 0700 parent directory. Mode 0666 on the socket
does not permit unrelated users to traverse that original directory.

The synthetic mock parent is root-private 0700. Even after a
root-managed systemd BindReadOnlyPaths mount makes the socket reachable
at a DynamicUser-owned path, a *root-owned mode 0700 socket inode* may
still prevent the DynamicUser client connecting. This is a well-founded
likely explanation, not yet the confirmed on-host error. Transient unit
startup or private-mount setup issues also remain possible.

A local isolated reproduction under umask 077 created mode 0700,
whereas explicit chmod of the synthetic socket (not its private parent)
produced mode 0666. This reproduction was NOT performed on aaPanel.

## Immediate response — independent read-only cleanup check

New file:
support/previewqa-step12m-failed-probe-readonly-audit-20261113.sh

It checks:
1. The immutable synthetic QA source, Laravel env permissions, isolated
   database, six user accounts and Preview Visibility withdrawals OFF.
2. Real QA HTTP/FPM still active but disabled at boot, Nginx Host filter,
   original root-only backup, HTTP socket mode 0666 under private 0700,
   FPM socket 0660, and shared-www denial.
3. The fixed mock transient unit is no longer loaded; its RuntimeDirectory
   and root-private random mock temp directories are absent.
4. Previously collected journal records from this *specific mock unit*
   for permission, mount-setup and EXEC failures. The script reports
   classification flags only and never prints raw logs/command arguments.
5. No systemd commands that modify units, mounts, users or permissions.

Its success marker will be:
    STEP12M_FAILURE_CLEANUP_READONLY_AUDIT=PASS

A clean result does not establish the cause if the journal has no relevant
entries; the operator and developer should separately review the corrected
mock's socket file permission and startup diagnostics.

## Retest decision

Do not rerun the original pinned --probe. After the cleanup audit passes,
prepare a **new** reviewed Git commit with a revised versioned mock,
explicit mock socket permissions 0666 inside its existing root-private
0700 parent, permission checks before starting the dynamic user, and
error-class-only initial connection diagnostics. Validate this in isolated
synthetic CI. Only then offer a separate --check and separately approved
synthetic --probe. Never chmod the real QA socket parent or alter shared
www, production, QA Nginx/FPM, public routes, DNS/TLS or credentials.

Draft PR #44 and PR #37 remain draft; Preview Visibility writes remain
disabled. Cloudflare Access, cloudflared, public QA DNS/TLS and production
release are NOT authorized.
