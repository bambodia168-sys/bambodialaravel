# Bambodia Step 12C — private Unix-only QA HTTP ingress

Status: source implementation prepared for isolated host acceptance. **Not
installed by this pull request. No public route or Cloudflare Access is created.**

## Goal and trust boundaries

After Step12B offline preflight PASS, create a separate, minimal Nginx HTTP
origin for the existing synthetic QA, **accessible only over a Unix socket**.
This is not the shared aaPanel Nginx service and has no TCP listener.

- Laravel code, private media and .env: /var/lib/bambodia-preview-restore-20261112,
  owned bambodia_pvrsqa mode 0700, unchanged.
- PHP-FPM socket: /run/bambodia-previewqa/php-fpm.sock,
  owner bambodia_pvrsqa, proposed group bambodia_pvhttp and mode 0660.
  Shared www is not a member of the private group and must fail a real
  Unix-socket connection test. The dedicated FPM worker remains the only
  account executing Laravel code.
- HTTP process: separate system user bambodia_pvhttp, no interactive shell,
  with a dedicated systemd service, no boot enablement, no public TCP listener.
- HTTP socket: /run/bambodia-previewqa-http/http.sock under a private HTTP
  RuntimeDirectory mode 0700, inaccessible to shared www.
- Static files: a 43-file byte-compared, extension-allowlisted separate copy
  from the synthetic QA public directory. index.php, root .htaccess,
  all hidden files, hidden directories and private uploads are excluded.
- Private HTTP Nginx config: /etc/bambodia-previewqa-http/nginx.conf,
  owned root and readable only by the dedicated HTTP group. A fixed FastCGI
  SCRIPT_FILENAME prevents passing user-controlled filenames to PHP-FPM.
  Direct PHP paths, dotfiles and /storage are denied at the HTTP layer.
- No DNS, no public Cloudflare Tunnel, no shared aaPanel Nginx config changes,
  no public HTTPS certificate, and no production application or database changes.

## Verified host baseline required

The operator must already have independent Step 12B offline PASS, Step 11
dedicated FPM socket private, 11 hashed private build files, 45 existing
public-tree files including exactly 43 allowlisted static files, and six
synthetic QA users in database bambodia_previewrestore_20261112. The
application's APP_URL is still http://127.0.0.1:18097. Preview
withdrawal writes remain **disabled**.

## Operator workflow

Fetch support/previewqa-step12c-private-http-install-20261112.sh from an
immutable pinned GitHub commit and compare its Git blob SHA. Check Bash
syntax, then run:

    bash SCRIPT --check

This preflight creates removable root-private temporary test files only. It
checks exact source identity, Linux users, FPM ownership, static allowlist,
future service collisions, candidate FPM syntax, and standalone Nginx
configuration with private temporary PID/listen/temporary-body paths. It
**does not start or install** the service.

Only after independent review and the preflight passes, an authorized
operator may separately run:

    BAMBODIA_QA_PRIVATE_HTTP_INSTALL=APPROVE_QA_LOCAL_UNIX_ONLY \
      bash SCRIPT --apply

A successful apply installs a separate user, static mirror, root-private
FPM configuration backup, dedicated HTTP config and systemd unit. It restarts
**only** bambodia-previewqa-fpm.service, then starts the dedicated QA HTTP
service. It checks real Unix connections for shared www denial and private
QA HTTP GET /up and /login status 200. Both QA services remain disabled
at boot. This apply is intentionally **one-time only** and is not a generic
upgrade script.

Expected success marker: STEP12C=PASS. Source integrity, --check, and
actual host acceptance are separate facts.

## Fail-closed recovery

If --check reports BLOCKED, do not run --apply.

If --apply reports BLOCKED partway through, the trap stops **only the two
dedicated QA services**. It leaves backup/config/static staging for controlled
forensics and intentionally does **not** roll permissions back to the old
shared-www configuration. Do not run the same --apply again; inspect
journalctl -u bambodia-previewqa-http.service,
journalctl -u bambodia-previewqa-fpm.service and the root-private
/root/Bambodia-PreviewQA-HTTP-Ingress-20261112/php-fpm.conf backup.
Leave shared production services untouched. Restore the independent
localhost Artisan QA browser path separately if necessary.

## External HTTPS is explicitly out of scope

The HTTP Unix socket is inside a private runtime directory and cannot be
used by a Cloudflare connector until the connector identity and socket
authorization are separately reviewed. Create and independently verify
Cloudflare Access with a narrow operator allowlist **before** any Tunnel
hostname route or DNS creation. Configure tunnel-side validation of Access
tokens and enforce fail-closed denial of unauthorized connections. Change
the isolated app's APP_URL only after protected HTTPS acceptance is ready.
Physical iPhone/Android/tablet tests and production authorization remain
separate.

Other pull requests:
- #37 Preview Visibility: draft, withdrawal writes OFF.
- #40 Staff Workspace: passed five CI workflows and six real browser tests,
  ready for review but not merged or deployed to production.
- #42 Dedicated private FastCGI socket: passed CI and live aaPanel Step11,
  ready for review but not merged.
- #43 Standalone HTTP ingress offline design: Step12B PASS, draft.
