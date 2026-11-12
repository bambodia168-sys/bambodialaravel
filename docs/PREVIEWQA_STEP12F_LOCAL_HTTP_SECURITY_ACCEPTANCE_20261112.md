# Step 12F — independent LOCAL Unix-socket ingress acceptance

Status: audit source prepared; awaiting a real aaPanel result. All HTTP
requests are GET-only over a private Unix socket. No DNS, Cloudflare Tunnel,
TLS or public QA endpoint is configured.

## Accepted precondition

On 2026-11-12 the owner reported live Step12E PASS on aaPanel. Two isolated
QA-only services active but disabled at boot; Nginx loaded installed resty.core,
startup logs go to stderr, shared www was denied access to dedicated sockets,
private /up and /login returned 200, private PHP/Apache/storage/installer
paths returned 404, withdrawal writes remained OFF and there was no public
route. The Step12E source was pinned to GitHub commit
8d6844ed33cac90eb6c01e7a28b852ee12b0dacb.

## What Step12F checks

The operator script is:
support/previewqa-step12f-local-ingress-audit-20261112.sh

- Immutable synthetic QA revision, private Laravel .env permissions, 43-file
  static-only copy with no symlinks, index.php or .htaccess.
- Both dedicated QA services active but disabled at boot, systemd isolation
  and Unix-only Nginx; exact private FPM socket config.
- Private HTTP Unix socket mode and ownership, FPM socket mode 0660, real
  denied connections as shared www, and HTTP account unable to read .env.
- Laravel testing mode and six synthetic users in the isolated QA database,
  preview status withdrawals disabled and zero preview visibility actions.
- GET-only via the private Unix socket: /up and /login must return 200 with
  X-Content-Type-Options nosniff; blocked paths return 404; unauthenticated
  /account and /dashboard must redirect or deny access.
- Reports how the private origin handles an unrecognized Host header. A 200
  response indicates a separate origin host guard will be needed before a
  public Cloudflare route; it is not evidence of authorized public access.
- No service restarts, Linux account changes, configuration changes or
  POST/credentialed requests. HTTP handling can still create normal
  ephemeral session data. Headers are stored in a root-private temporary
  directory and removed, not printed.

Expected marker: STEP12F_LOCAL_INGRESS_AUDIT=PASS

A BLOCKED result means local security acceptance has NOT passed. Do not
loosen permissions or enable public ingress to bypass a blocked check.

## External publication remains separate

Step12F PASS is not a review of Cloudflare Access, JWT audience checks,
physical mobile browsers, TLS, origin host enforcement, or public URLs.
preview-qa.bambodia.com remains unpublished. The isolated QA APP_URL still
points to http://127.0.0.1:18097 and must not be changed silently.

PR #44 remains draft, PR #37 withdrawal writes remain OFF, and no merge to
production main or deployment is authorized.