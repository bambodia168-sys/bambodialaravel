# Bambodia QA — private HTTP Unix-socket ingress architecture

**Status: architecture and offline preflight only. No public route has been
created, no Nginx service has been installed, and no QA database was changed.**

## Host evidence (2026-11-12)

The owner confirmed Step 12 read-only discovery on a real aaPanel host:

- Recovered QA app at /var/lib/bambodia-preview-restore-20261112,
  with app-owned private mode 0700 and preview withdrawal writes disabled.
- Private QA FPM Unix socket /run/bambodia-previewqa/php-fpm.sock,
  owned by bambodia_pvrsqa:bambodia_pvrsqa mode 0600.
- An actual connection test denied the shared www account while allowing the
  dedicated QA worker (Step 11 PASS).
- The independently hashed static assets remain private; 11 build files.
- cloudflared is not installed; cloudflared.service is inactive.
- No QA DNS record, aaPanel HTTPS vhost, or public QA HTTP service exists.
- TCP ports 18097/18098 are unoccupied.

## Proposed separated identities

1. Dedicated QA PHP-FPM worker: bambodia_pvrsqa. It alone reads application
   private files (.env, artisan, private media, etc.).
2. Dedicated HTTP ingress worker: bambodia_pvhttp, a new system user with
   no interactive shell. It serves only a vetted static file copy and does
   not have permission to read the Laravel source or private media.
3. Later, dedicated cloudflared connector user: provision separately, only
   after Cloudflare Access is verified and an independently reviewed ingress
   group arrangement has been implemented.

For the planned protected QA ingress, a reviewed change will scope the QA FPM
socket group ONLY to bambodia_pvhttp, mode 0660, and keep the QA worker as
socket owner. Shared www must never join that group and must be unable to
connect; the HTTP ingress user must still not read app/.env.

The separate Nginx HTTP process should listen only on a Unix-domain socket at:

    /run/bambodia-previewqa-http/http.sock

Its parent directory should initially be inaccessible to everyone except the
dedicated ingress process. Do not use a TCP listener or a shared aaPanel
Nginx vhost.

Its document root must contain only independently allowlisted, checksum-
verified static files, copied from the existing synthetic QA's public tree.
Do NOT copy public/index.php, public/.htaccess, symlinks, .env, hidden files,
private media, or arbitrary upload files. The Apache rewrite file at the exact
public/.htaccess path is inspected against its pinned Git blob and deliberately
excluded from any static package; all other hidden files must fail closed. Route non-static requests to the dedicated PHP-FPM
socket using fixed SCRIPT_FILENAME equal to the recovered Laravel front
controller. Reject arbitrary PHP paths, dotfiles, installers and /storage
access at the ingress layer.

## Protect with Cloudflare Access BEFORE publishing

Cloudflare Tunnel supports private HTTP origins addressed as
"unix:/run/bambodia-previewqa-http/http.sock" (see source below). A future
connector must be allowed to connect to the HTTP socket ONLY, never to the
upstream private PHP-FPM socket.

In Cloudflare Zero Trust:

- Create the exact self-hosted application for preview-qa.bambodia.com first.
- Configure a narrow Allow policy for explicitly authorized test identities,
  with no broad Everyone or Bypass policy.
- Record the Access application audience tag (AUD) and team name in the
  operator's private configuration, never in GitHub.
- Configure Tunnel "Protect with Access"; for locally managed tunnels use
  originRequest.access.required=true, teamName and audTag to validate JWTs
  before forwarding to the origin.
- Create hostname route/DNS only after Access policy and tunnel-side JWT
  checks are independently verified.
- Verify anonymous/unauthorized requests denied and authorized test login
  works. Confirm fail-closed origin behavior when Access is unavailable.
- Reconfigure the isolated QA APP_URL to the verified protected HTTPS
  hostname through an independently reviewed migration procedure; it is
  currently http://127.0.0.1:18097 and must not silently change.
- Conduct actual physical iPhone/Android/tablet browser acceptance.

Official references:
https://developers.cloudflare.com/cloudflare-one/access-controls/applications/http-apps/self-hosted-public-app/
https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/routing-to-tunnel/protocols/
https://developers.cloudflare.com/tunnel/reference/origin-parameters/

## Step 12B: offline capability preflight

Run only the pinned source of:

    support/previewqa-step12b-private-unix-ingress-check-20261112.sh

The script checks the exact QA app and FPM identities, immutable static asset
hashes, public-tree file extension/symlink inventory, planned OS account and
systemd/service path collisions, and Nginx syntax with an ephemeral private
candidate configuration. It creates no permanent files, new users, services,
DNS records, Cloudflare applications or tunnel configurations.

Expected output: STEP12B_OFFLINE_PREFLIGHT=PASS.

**Do not treat this as authorization to expose the QA site.**

## Separate production gates

PR #40 — Staff Workspace UI role-label fix — has five CI passes and six
real-browser checks; ready for code review, not merged.
PR #42 — private QA FPM socket hardening — has five CI passes and real aaPanel
Step 11 acceptance; ready for code review, not merged.
PR #37 — Preview Visibility withdrawal/restore — remains draft with writes off.
None of these results authorize a production deployment or enabling payments,
notifications or preview withdrawal status changes.
