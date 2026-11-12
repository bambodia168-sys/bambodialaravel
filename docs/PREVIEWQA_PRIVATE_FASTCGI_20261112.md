# Bambodia isolated QA: dedicated private FastCGI socket

Status: **prepared, not installed on aaPanel**. Scope: synthetic restored QA
application at /var/lib/bambodia-preview-restore-20261112 only.

## Problem and chosen boundary

A separate PHP-FPM master and Laravel directory mode 0700 do not stop other
applications running as the shared Nginx account **www** from submitting FastCGI
requests when the QA socket is owned by www:www with mode 0660. Those requests
are executed by the privileged QA application worker.

To close this cross-site capability while QA is accessed through an encrypted
SSH tunnel and a loopback-only Laravel test server, restrict the dedicated QA
FPM socket to the application's own OS identity:

- Socket: /run/bambodia-previewqa/php-fpm.sock
- Owner/group: bambodia_pvrsqa:bambodia_pvrsqa
- Mode: 0600
- Worker pool identity: bambodia_pvrsqa
- Main aaPanel FPM: unchanged at /tmp/php-cgi-85.sock
- Service: bambodia-previewqa-fpm.service, not enabled at boot

This **intentionally makes the QA socket inaccessible to the shared www user**.
Do not install a conventional shared-www Nginx FastCGI upstream that points to
this socket; it will fail to connect. A future public QA HTTPS endpoint must
use a separately assessed dedicated ingress OS identity, private proxy, or other
access-protected arrangement. It must not reopen www access as a shortcut.

## Operator procedure

Review support/previewqa-step11-private-socket-20261112.sh from a pinned GitHub
commit and verify its Git blob hash before execution. Execute:

    bash SCRIPT --check

Only after its read-only preflight passes, run:

    BAMBODIA_QA_SOCKET_HARDEN=I_APPROVE_QA_SOCKET_PRIVATE_ONLY bash SCRIPT --apply

The first-time apply writes an immutable private backup of the old dedicated
FPM config to:

    /root/Bambodia-PreviewQA-PrivateSocket-20261112/php-fpm.conf

The script stops and restarts **only** bambodia-previewqa-fpm.service. It does
not change the existing shared FPM pool, Nginx, DNS, TLS, MySQL records or
production deployments.

The acceptance verifies the actual Unix socket accepts the dedicated app user
and denies a connection by www, and the service remains disabled at boot.
Expected conclusion: STEP11=PASS.

The locally served Laravel QA site on 127.0.0.1:18097 remains independent of
the FPM socket and can continue to be reached from an operator's Mac through
an encrypted SSH local tunnel. Keep it bound to loopback and stop it after use.

## Failure handling

If --check fails, do not run --apply.

If --apply reports BLOCKED after modifying the config, do not rerun it or
silently revert to www socket access. The script stops the dedicated QA service
on incomplete apply. Inspect its private log and service state; retain the
backup and restore only after an independent review. No production worker,
database or HTTPS service should be affected by a stopped QA-only FPM.

## Release gates not closed by this script

- No real external HTTPS/Cloudflare Access public QA endpoint exists.
- Origin address and any QA DNS record require owner approval.
- A dedicated, assessed ingress identity is required before public HTTPS.
- Physical mobile/tablet and external-network acceptance remain outstanding.
- Preview visibility withdrawals remain disabled by default.
- Preview visibility PR #37 remains draft pending independent acceptance.
- Staff Workspace PR #40's six-role browser checks and five CI workflows passed;
  it is separate from this QA infrastructure change and not deployed to production.
