# Step 12M — disposable mock Unix-socket inode rebinding rehearsal

**Status: source prepared, not executed on real aaPanel.**
This is a synthetic lifecycle experiment, not a cloudflared installation,
production deployment, or authorization to restart the dedicated QA origin.

## Accepted predecessor

On 2026-11-13 the operator executed Step12L read-only on the real aaPanel host
with STEP12L_SOCKET_LIFECYCLE_INVENTORY=PASS and integrity-pinned source.

Observed private QA HTTP socket:
- inode 117593, device 26, link count 1, mode 0666
- parent owner bambodia_pvhttp mode 0700
- service Type=simple, Restart=no, RuntimeDirectoryPreserve=no
- both dedicated QA HTTP/FPM services active and disabled at boot
- the Step12K transient DynamicUser unit and runtime directory fully absent
- six-user isolated synthetic DB, Preview Visibility withdrawal writes OFF
- cloudflared binary, systemd unit and OS account absent

All five GitHub CI workflows on the accepted Step12L commit were SUCCESS.

Step12K separately proved that a short-lived DynamicUser service could
connect to the intended origin HTTP socket via a *service-private bind mount*,
but not to the original socket, FPM socket or Laravel .env. That did NOT
prove the same mount remains usable when the source socket inode changes.

## Why a mock experiment is necessary

The real QA Nginx service currently has Restart=no and
RuntimeDirectoryPreserve=no. A full stop/start may unlink and replace the
Unix listening socket, while a private bind mount in a different service
can continue referencing the *old filesystem object*.

The experiment must observe whether an existing unprivileged client
can reconnect through the same mount after a synthetic socket is unlinked
and rebound at the original pathname. It does not assume the result or
alter the actual Nginx/FPM services.

## Test boundaries and method

Source:
    support/previewqa-step12m-disposable-socket-rebind-20261113.sh

1. Read-only preflight checks pinned QA source/database, disabled write
   flag, active and boot-disabled QA HTTP/FPM, canonical Host restriction,
   unchanged 0700 socket parent, shared www denied, previous backups,
   no cloudflared, and absence of the fixed-name mock transient unit.
   It parses an ephemeral systemd DynamicUser+BindReadOnlyPaths example.
2. For explicit opt-in only, the script creates a root-private directory
   with a synthetic Python Unix HTTP listener. This is NOT the real QA
   socket path; it serves only the literal response HTTP 200 to GET /mock.
3. A transient DynamicUser service binds **only** that synthetic socket
   into its RuntimeDirectory in a private mount namespace, with
   AF_UNIX-only, no capabilities, no new Linux account, strict filesystem
   protections, no restart, and max runtime 35 seconds.
4. The client proves nonroot UID, inability to read Laravel .env and
   inability to connect directly to the real QA HTTP/FPM and mock
   source path. It GETs the mounted mock socket and reports HTTP 200.
5. The test coordinator stops the old **synthetic** listener only,
   unlinks its socket pathname, creates a NEW synthetic listener at
   the same source path with a different inode, and independently
   proves the new source listener returns HTTP 200.
6. The existing transient client attempts to reconnect via its
   still-mounted pathname. A connection error or HTTP 200 is recorded
   as an **observation**, not automatically interpreted as release-ready.
7. The transient unit/runtime directory and synthetic listener/socket
   are removed; the script requires QA HTTP/FPM still active and disabled
   at boot, with the original socket directory mode unchanged.

The test has an explicit --check mode and a separately approved --probe
mode; it does not perform the probe merely because its parser passes.

Expected --check completion:
    STEP12M_MOCK_REBIND_PREFLIGHT=PASS

Expected successfully completed **observation** (not a reconnection PASS):
    STEP12M_DISPOSABLE_MOCK_SOCKET_REBIND=OBSERVED

When a stale bound socket is observed, the response should be:
    CONNECTOR_REBIND_CONCERN=STALE_BOUND_SOCKET_REQUIRES_RECOVERY_DESIGN

If the client can reconnect after rebinding, report:
    CONNECTOR_REBIND_CONCERN=UNEXPECTED_SUCCESS_REQUIRES_SEPARATE_REVIEW

Both results require separately reviewed production-grade recovery design.
If any safety gate fails, do not repeat a transient probe automatically.

## Security and external publication gates

Do NOT stop/reload/restart the real
bambodia-previewqa-http.service or bambodia-previewqa-fpm.service.
Do NOT loosen the original 0700 directory, create permanent Linux users,
read Cloudflare credentials or configure public DNS/TLS/Tunnel routes.

There is no Access application/policy, origin JWT verification or
connector identity approval yet. The experiment cannot establish any
of those requirements. Preserve root-only backups, PR #44 and PR #37
remain draft, Preview Visibility withdrawals OFF, no production main
merge/deployment.
