# Step 12O — post-recovery independent QA audit and fail-closed connector design

**Status: synthetic recovery accepted on real aaPanel; Step12O read-only
post-test audit prepared but not yet executed.**

## Verified Step12M and Step12N results

On 2026-11-13 the operator executed the immutable, explicitly approved
synthetic Step12N dynamic-user fresh mount experiment at commit
935ee0bffe859de6dc6b63f5d388156e1f948e78, Git blob
fe7b7b9352489b45760ed0a525f582f1a31181b5.

The root-private synthetic Unix pathname socket had mode 0666 within a
0700 root-only parent. The original dynamic client first returned HTTP
200. Following unlink/rebind to a distinct socket inode, the replacement
mock source returned HTTP 200, but the existing service-private
BindReadOnlyPaths mount returned errno 111 (ECONNREFUSED). The original
transient unit and RuntimeDirectory were collected. A freshly started
DynamicUser service with a NEW unit-private mount namespace then
connected to the replacement mock socket with HTTP 200, exited, and
was collected. The real QA Nginx/PHP-FPM services were not restarted,
reconfigured or exposed publicly.

The exact Step12N commit passed all five CI workflows; the operator's
actual host log ended with
STEP12N_DISPOSABLE_MOCK_SOCKET_RECOVERY=OBSERVED.

This is a SUCCESSFUL MOCK RECOVERY OBSERVATION, not live connector
or production recovery acceptance.

## Operational inference and risk

A persistent connector using a service-private socket bind mount must
not be assumed to follow pathname socket inode replacement. A
connector process restart in a *new mount namespace*, after the
replacement origin socket exists, is a recovery candidate supported
by synthetic test evidence. This does NOT automatically establish that
the manager will recreate the mount correctly, that ordering is
reliable, or that the connector successfully establishes an
authenticated Cloudflare Tunnel.

The current real isolated QA HTTP service was last observed as
Type=simple, Restart=no, RuntimeDirectoryPreserve=no; these are
read-only inventory facts, not authority to change the service.

## Candidate fail-closed connector lifecycle — design only

1. Keep the origin socket in
   /run/bambodia-previewqa-http/http.sock under its existing
   bambodia_pvhttp-owned 0700 RuntimeDirectory. The socket file is
   mode 0666 but other users cannot traverse its parent.
2. Use a separate, unprivileged connector identity and its own private
   runtime directory and mount namespace. Give it only a read-only
   bind of the single QA HTTP socket, not the directory or PHP-FPM
   socket. Never run it as root or grant it the Laravel .env.
3. Require the origin's *current* socket pathname and readiness gate
   before creating the connector mount namespace. If the pathname
   disappears or the socket inode is replaced, the connector must be
   recycled and a NEW namespace created, not merely reconnect within
   an old, potentially stale mount.
4. Validate lifecycle ordering under repeated synthetic restarts,
   rapid replacement, concurrent pending connections and fail-close
   cleanup. Monitor the current mount's effective socket connectivity,
   not just the original source pathname or systemd ActiveState.
5. Enforce a bounded, reviewed recovery policy with logs that do not
   reveal credentials. On any ambiguous state, fail closed and keep
   the public route disabled rather than exposing an alternate TCP
   origin or broadening filesystem permissions.
6. Before any Cloudflare publication, establish an Access-first
   self-hosted application/policy with deny-by-default authorization;
   independently verify its expected audience/team, signed JWT
   validation, token refresh/revocation, permission restrictions,
   logging, emergency shutdown and rollback.
7. Independently test authorized and unauthorized browsers, negative
   and outage cases, TLS, Cloudflare origin restrictions and deployment
   behavior. No live connector or DNS route may be enabled merely
   because this synthetic socket test succeeded.

Systemd dependency directives and restart rules are **design
candidates**, not implementation instructions for this change. No
persistent connector service, Cloudflare account action, tunnel token,
shared aaPanel config, DNS record or Nginx/PHP-FPM restart is approved.

## Step12O independent read-only post-test audit

Source:
support/previewqa-step12o-post-recovery-independent-readonly-audit-20261113.sh

This audit will:
- Validate pinned six-user isolated QA source/database, testing mode,
  Preview Visibility withdrawal writes OFF, original backups, dedicated
  QA-only services active but disabled at boot, 0700 origin directory,
  HTTP mode 0666, FPM mode 0660 and shared-www denial.
- Require the Step12K transient unit, Step12M v2 old unit, Step12N
  recovery unit and their runtime directories to be absent; require
  all root-private Step12M/Step12N mock temp directories to be gone.
- Run two **guest-only GET /up** requests over the local HTTP Unix
  socket, expecting canonical Host 200 and unknown Host 421.
- Read only the live HTTP socket inode/device identifiers and service
  restart policy (no stop/reload/start).
- Require cloudflared executable, named account and default service
  still absent; preserve all public Access/Tunnel/DNS/TLS gates closed.

Expected final marker:
    STEP12O_POST_SYNTHETIC_RECOVERY_INDEPENDENT_AUDIT=PASS

Audit acceptance does not extend to real Nginx restart behavior,
automatic connector recovery, Access/JWT, remote connectivity,
production release, or Cloudflare account configuration.

## Explicitly closed gates

Do not chmod the origin's 0700 parent, alter www's group membership,
access private credentials, install cloudflared, change shared aaPanel
Nginx/PHP-FPM, modify live QA services, merge draft PR #44 or PR #37,
publish DNS or TLS, or activate Preview Visibility withdrawal writes.

Production, public routing, payments and customer communications remain
unchanged and outside this acceptance.
