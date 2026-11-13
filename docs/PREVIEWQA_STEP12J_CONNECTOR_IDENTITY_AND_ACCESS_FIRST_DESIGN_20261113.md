# Step 12J — connector identity and Access-first architecture review

**Status: design and host capability inventory only. No cloudflared binary,
account, unit, token or public hostname is installed or configured.**

## On-host evidence as of 2026-11-13

Owner executed the integrity-pinned Step12I read-only script on actual
aaPanel. It returned STEP12I_ACCESS_FIRST_READINESS_DISCOVERY=PASS.

- Private origin and Linux socket isolation PASS, canonical Host /up HTTP
  200, unrecognized Host /up HTTP 421.
- Six-user synthetic QA database and Preview Visibility withdrawal writes OFF.
- cloudflared executable NOT installed.
- Default cloudflared.service NOT loaded.
- cloudflared OS user NOT found.
- No Cloudflare Access account policy validated; no team name, AUD tag,
  token, private keys or credentials were retrieved.
- No public DNS, Tunnel or TLS setup; no production or shared aaPanel
  configuration touched.

All five GitHub CI workflows for Step12I passed on the same immutable
commit. Step12E–12H local-origin acceptance also remains verified.

## Authority boundary

Nginx's Unix socket path is:
    /run/bambodia-previewqa-http/http.sock

Its parent RuntimeDirectory is:
    bambodia_pvhttp:bambodia_pvhttp mode 0700

Nginx typically creates its pathname socket with file mode 0666, but
other OS users cannot traverse the 0700 parent. This was independently
verified using both www and nobody account connections.

A new cloudflared OS account, even with a separate group, cannot traverse
the parent. The connector identity MUST NOT be granted access by:
- chmodding the parent from 0700 to 0755, 0710 or 0777;
- adding shared www or general service users to the ingress group;
- exposing an unsecured localhost or public TCP bridge;
- running cloudflared as root, or sharing long-lived credentials with
  Nginx/production processes.

## Candidate architecture A — separately isolated connector identity

The preferred design candidate is a dedicated, unprivileged cloudflared
identity, granted access to **only** the one private Unix socket via a
systemd private mount namespace or an independently reviewed root-managed
socket handoff. A possible systemd BindReadOnlyPaths directive can
bind the socket into a connector-private RuntimeDirectory, leaving the
original origin directory at mode 0700.

**This is a design hypothesis, not a validated functional configuration.**
Parser support alone is NOT evidence that binding a socket works with the
installed kernel/systemd, that AF_UNIX connect succeeds across different
UIDs, that the cloudflared service's filesystem view can reach it, or
that restart/rotation preserves isolation. The next phase must conduct
an authorized disposable dry run with a NON-cloudflared unprivileged
client, without publishing a route or reading any credentials.

Separate tests must prove:
1. The new identity can connect **only** to the intended HTTP socket
   without traversing the original 0700 parent.
2. Shared www and nobody remain unable to connect to either socket.
3. The connector cannot read Laravel .env, FPM socket, MySQL secrets,
   static upload data or general aaPanel configurations.
4. Socket restart does not break the connection or create a stale bind
   mount; fail-closed rollback preserves the old access boundary.
5. Only outbound Cloudflare connector traffic is permitted when an
   actual approved connector exists; no public TCP listening socket.
6. No other application shares connector credentials or service control.

## Candidate B — dedicated service using the existing ingress UID

A separate hardened systemd service running as bambodia_pvhttp can
already traverse the private directory, but it SHARES the identity and
filesystem authority of the private Nginx worker. This increases the
impact of compromise and is therefore NOT the preferred default.
It requires explicit security acceptance and equivalent sandbox,
credential and process separation. Do not implement automatically.

## Access-first control-plane gates

The supported Cloudflare Tunnel origin service URI is:

    unix:/run/bambodia-previewqa-http/http.sock

The actual service URI may differ if a separate mount namespace maps the
socket to a connector-private pathname.

Cloudflare documentation currently recommends creating an authorized
self-hosted Access application BEFORE any public Tunnel route. Publishing
the route without a policy could expose the QA frontend. The approved
policy must deny by default, limit authorized identities, control session
lifetime and record security events without tokens.

Cloudflared originRequest.access should be reviewed with required=true,
the approved teamName, and exactly the authorized Access application's
audTag; do NOT populate these from invented values. Cloudflare also
recommends JWT verification against its issuer, audience, signature and
rotating signing keys for protection against Access bypass.

The origin's Nginx canonical Host restriction only accepts
preview-qa.bambodia.com; any future connector must explicitly preserve
that Host. This guard is not a substitute for Access authorization.

Official references:
- https://developers.cloudflare.com/cloudflare-one/access-controls/applications/http-apps/self-hosted-public-app/
- https://developers.cloudflare.com/tunnel/reference/origin-parameters/
- https://developers.cloudflare.com/api/resources/zero_trust/subresources/tunnels/subresources/cloudflared/
- https://developers.cloudflare.com/cloudflare-one/access-controls/applications/http-apps/authorization-cookie/validating-json/

## Read-only Step12J host discovery

Script:
support/previewqa-step12j-connector-isolation-readonly-20261113.sh

Checks existing isolated systemd and Unix socket boundaries and fails closed
if cloudflared was newly installed/loaded since Step12I. Prints only
systemd version, the /run filesystem type and nonsecret capability flags.
Creates a temporary, root-private systemd UNIT FILE and runs only
systemd-analyze verify on an example BindReadOnlyPaths declaration.

The example unit has ExecStart=/bin/true for parser validation. It is
never loaded or run, no cloudflared binary is required, no mount is
performed, no Linux identities are created, and no credential files or
Cloudflare account details are consulted. A parser PASS is not functional
mount or connectivity acceptance.

Expected completion:
    STEP12J_CONNECTOR_IDENTITY_CAPABILITY_INVENTORY=COMPLETE

A NOT_VERIFIED parser result is a blocker for candidate A until reviewed;
do not change service security settings to bypass it.

## Closed release gates

No public QA DNS, no Cloudflare Tunnel, no TLS publication, no production
merge or release, no shared aaPanel service changes, and no Preview
Visibility withdrawal writes. Preserve root-private backups and keep
draft PR #44 and draft PR #37 open until independent acceptance.
