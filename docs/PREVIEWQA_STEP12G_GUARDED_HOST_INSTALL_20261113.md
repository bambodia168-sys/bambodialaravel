# Step 12G — guarded activation of QA-only canonical Host restriction

**Status:** Source prepared, NOT yet executed on the aaPanel server.

## Verified basis

- Real aaPanel Step12F local-only security audit PASS: 43 vetted
  static files, private directory mode 0700, Nginx Unix socket mode 0666
  effectively inaccessible to shared www and nobody, no Laravel .env
  access from the HTTP user, synthetic QA database with six users,
  withdrawal writes disabled, protected paths 404, guest account and
  dashboard redirect 302, and both isolated QA services active but disabled
  at boot.
- Unrecognized Host header still gives HTTP 200 at /up; this is a
  confirmed origin-header acceptance gap before any future network route.
- Real aaPanel Step12G offline candidate preflight PASS with the exact
  canonical Host guard and unprivileged Nginx syntax test:
  origin Host must be preview-qa.bambodia.com.
- All five CI workflows for the accepted Step12G preflight commit passed.

## Guarded one-time installer

File:
support/previewqa-step12g-origin-host-guard-install-20261113.sh

Use the exact pinned Git commit and Git blob, check shell syntax, and run
the **read-only** mode first:

    bash SCRIPT --check

This enforces the same pinned source/service/socket prerequisites as
Step12G, and additionally verifies the isolated synthetic database
and preview withdrawals disabled. It creates only temporary files in
/run under a restricted directory and tests the candidate Nginx
configuration as bambodia_pvhttp.

A separately approved one-time installation can then run:

    BAMBODIA_QA_HOST_GUARD_APPROVAL=APPROVE_PRIVATE_QA_HOST_GUARD_ONLY \
      bash SCRIPT --apply

The apply path makes exactly one QA HTTP configuration change, after
creating a root-only original backup at:

    /root/Bambodia-PreviewQA-Host-Guard-20261113/nginx.conf

The change inserts this server-context rejection immediately after the
existing server_name directive:

    if ($http_host !~* "^preview-qa[.]bambodia[.]com$") {
        return 421;
    }

It deliberately denies localhost/IP, unrecognized host names, missing
Host values and port-qualified canonical Host values. Case-insensitive
canonical hostname spelling is allowed.

The installer stops and starts ONLY
bambodia-previewqa-http.service. The dedicated QA FPM service remains
active and untouched; the shared aaPanel Nginx and production PHP-FPM
services are never restarted. No public listener is added: only the
private Unix HTTP socket remains.

Post-install tests check canonical /up and /login 200, unexpected,
localhost, IP and port-qualified Hosts 421, missing Host rejected,
sensitive files/paths 404, guest /account and /dashboard 302, private
socket owner/mode and denied shared-www connection. Both dedicated
units must remain disabled at boot.

If the activation fails after making changes, the handler stops ONLY
the private HTTP QA service, restores the backed-up original config
and leaves the private QA HTTP service stopped for diagnosis. It does
NOT restore a publicly accessible origin or reconfigure shared PHP-FPM.
It never automatically retries an unsuccessful one-time install.
Do not rerun apply; inspect the backup and dedicated QA Nginx journal
before preparing a separate recovery.

Success marker: STEP12G_HOST_GUARD=PASS.
This marker requires actual on-host acceptance; CI and offline preflight
alone do not establish it.

## External release gates remain closed

No public QA DNS, TLS certificate, Cloudflare Tunnel, Cloudflare Access
configuration or JWT validation was created. Even if Step12G applies,
an Access-first review, explicit operator approval, origin authentication,
and independently checked device/browser journeys are separate requirements.
The isolated application APP_URL is still http://127.0.0.1:18097.

PR #44 remains draft; PR #37 remains draft and Preview Visibility
withdrawal writes stay OFF. No production main merge or deployment.
