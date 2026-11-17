# Step 12M v2 — corrected synthetic Unix socket permissions

**Prepared on draft PR #44 only. No real-host cleanup audit or v2 probe
has yet been accepted.**

## Why the first synthetic probe blocked

The real aaPanel Step12M --check succeeded, but the first runtime
client never reported an HTTP 200 response:

    BLOCKED_STEP12M_MOCK_REBIND=transient_mock_client_first_response_not_200

The failure happened *before* original synthetic socket unlink/rebind.
It did not establish any result about a long-running bind mount.

The initial script set umask 077 globally and created its root-owned
synthetic Unix pathname listener with socket.bind without a subsequent
chmod. On Linux this produces a socket file mode 0700, unlike the
accepted real dedicated QA HTTP socket (mode 0666 inside a separate
private 0700 parent). A dynamic UID that receives a bind-mounted socket
may not have permission to connect to a root-owned mode 0700 inode.

This is a probable failure cause; the systemd mount setup or stdout
handoff may also be involved, and the actual journal classification
requires the separately prepared real-host read-only failure audit.

## Remediation implemented but not activated

The original immutable Step12M source is deliberately preserved as
historical evidence. The replacement is a **new versioned script**:

    support/previewqa-step12m-v2-disposable-socket-rebind-20261113.sh

It preserves the former two-stage interface but uses new success
markers to prevent an old and new probe being confused.

- The source mock socket is still under a random **root-private 0700**
  parent /run/bambodia-step12m.* directory.
- After only the synthetic socket is bound, Python sets **that one
  socket file** to mode 0666 and validates the inode's mode is exactly
  0666. This matches the established real QA HTTP socket file mode.
- The 0700 source parent is validated before the mock server starts,
  so the root-only synthetic socket pathname is *not* available to
  shared www or other unrelated OS users.
- The transient DynamicUser service's private bind mount gives it
  access to the socket inode through its *own* RuntimeDirectory.
- The ephemeral mock client now reports a controlled
  BLOCKED_MOCK_CLIENT_INITIAL_SOCKET_ERRNO marker if the initial
  private Unix socket connection fails. The coordinator classifies
  first-response failures rather than silently treating them as
  reconnection problems.
- Synthetic source socket unlink/rebind is attempted **only after**
  that initial HTTP 200 has been verified. The second connection
  remains an observation, not acceptance of an actual connector.
- Real QA HTTP/FPM services, account groups, socket mode, parent 0700,
  Cloudflare, production and public routes are never changed.

Regression:
    tests/security/previewqa-step12m-v2-socket-parity-smoke.sh

The synthetic CI test extracts and executes only the Python mock
Server class with umask 077, asserting socket 0666 and parent 0700,
and separately syntax-checks the embedded dynamic client. CI still
cannot establish actual systemd/host namespace behavior.

## Required sequential acceptance gates

1. First run the **separate read-only cleanup/forensics audit**:
   support/previewqa-step12m-failed-probe-readonly-audit-20261113.sh

   It must verify the failed transient unit and RuntimeDirectory are
   absent, random root-private mock temp directories removed, actual
   QA services active and disabled at boot, real HTTP/FPM sockets
   isolated, synthetic DB and withdrawal writes OFF. If BLOCKED, do
   not run any corrected probe; diagnose state.

2. Check all GitHub CI workflows and source/blob integrity for a pinned
   v2 commit, then run *only*:
       bash SCRIPT_V2 --check
   No unit or synthetic listener is created by this mode.

3. Only after preflight and independent review, separately opt in to
   v2 --probe with its explicitly scoped synthetic-only approval.
   The test may launch a time-limited transient DynamicUser service,
   but no real QA Nginx/FPM restart, external connector or route.

A successful mock rebind **observation** still does not establish
cloudflared resilience, App Access policy, JWT issuer/audience checks,
origin authentication, public DNS, TLS or production release readiness.

PR #44 and PR #37 remain draft, Preview Visibility withdrawals OFF,
and all production/shared aaPanel services unchanged.
