# Step 12G — fail-closed origin Host-header restriction, QA only

Status: **offline source and preflight prepared, NOT installed**.

## Acceptance basis

On 2026-11-13, the owner ran pinned Step12F GET-only audit on the real aaPanel
host with final STEP12F_LOCAL_INGRESS_AUDIT=PASS. Socket access remained
private through bambodia_pvhttp's 0700 RuntimeDirectory; shared www and
nobody could not connect, HTTP account could not read Laravel .env, /up and
/login passed 200+nosniff, protected files returned 404, unauthenticated
/account and /dashboard redirected 302, and both QA services remained active
but disabled at boot. Synthetic QA database and Preview Visibility withdrawal
writes remained OFF. No public hostname/Tunnel or TLS route has been created.

An additional observation is a security issue for a future origin connector:

    UNRECOGNIZED_HOST_HEALTH_HTTP=200

The private origin currently accepts unexpected Host header values. The
socket is not a public network listener, but host validation is still a
separate defense before considering a Cloudflare Tunnel.

## Proposed exact guard

Only the isolated file /etc/bambodia-previewqa-http/nginx.conf should gain
the following server-context directive after the existing server_name:

    if ($http_host !~* "^preview-qa[.]bambodia[.]com$") {
        return 421;
    }

The canonical request Host must be exactly preview-qa.bambodia.com
(case-insensitive). A missing Host, localhost, IP address, unrelated host,
or Host with a port will be rejected with HTTP 421 rather than reaching
Laravel/FPM. This is intentional fail-closed behavior for a later controlled
connector; a tunnel origin Host header must be reviewed before publication.

Nginx's server-context if with a return directive is used solely to reject
requests; it does not rewrite routes or attempt authentication.

## Read-only source-preparation preflight

The source script is
support/previewqa-step12g-origin-host-guard-preflight-20261113.sh.

After pinning the immutable commit and verified Git blob, run bash -n and
then bash SCRIPT. No --apply flag is supported. The script confirms that:

- The exact pinned synthetic app/source, 43-file static-only root and
  dedicated PHP-FPM socket remain isolated.
- Both QA services are active but disabled at boot, no HTTP systemd
  drop-ins exist, and HTTP config contains only the expected private Unix
  listener and fixed Laravel FastCGI entrypoint.
- Previously backed-up FPM/HTTP configuration is still root-private.
- The existing origin continues to report 200 for an unknown Host header
  before any repair is installed (to distinguish other changes).
- An ephemeral Nginx config containing the canonical Host guard passes
  syntax validation as the unprivileged bambodia_pvhttp account with an
  isolated temporary Unix listener, PID and body/temp paths.

The script leaves installed HTTP/FPM services and configs unchanged. It
does not add a public listener, touch shared aaPanel Nginx, create DNS or
Cloudflare Access, or enable Preview Visibility writes.

Expected completion: STEP12G_HOST_GUARD_PREFLIGHT=PASS.

## After independent acceptance

Only after the read-only check and code/security review should a *separate*
guarded recovery/install action be developed. It must back up the dedicated
QA Nginx config, deploy only this Host restriction, restart only the
dedicated QA HTTP service, retain QA FPM unchanged, test canonical origin
GET /up and /login return 200, unknown/missing Host return 421, guest
/account and /dashboard redirect, and private paths continue returning
404. Rollback must fail closed with the HTTP QA service stopped on error.

**Do not publish preview-qa.bambodia.com** or create a Tunnel, DNS record,
TLS endpoint or public route. Cloudflare Access policies, validation of
Access JWT audience/identity at the origin, tunnel credentials and physical
browser/device acceptance remain independent gates. No production/main
deployment is authorized; PR #44 remains draft and PR #37 status
withdrawal writes remain OFF.
