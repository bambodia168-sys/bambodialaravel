# Step 12I — Cloudflare Access-first private Tunnel readiness

**Status: read-only discovery source prepared; no Cloudflare deployment, public
DNS, TLS or Access application creation has been authorized.**

## Accepted local-only baseline

On 2026-11-13 the owner ran integrity-pinned Step12H on the real aaPanel
server and reported STEP12H_INDEPENDENT_ORIGIN_HOST_AUDIT=PASS.

The independent audit validated that:
- Installed dedicated Nginx config equals its original root-only backup plus
  the exact canonical Host restriction; one private Unix HTTP listener only.
- Dedicated QA HTTP and FPM are running but disabled at boot; socket parent
  directory remains owner bambodia_pvhttp mode 0700.
- Shared www and nobody cannot connect to private HTTP/FPM sockets.
- Canonical /up and /login return 200; unknown, IP, localhost, port-qualified
  and missing Host headers return 421; guest account/dashboard redirect.
- Sensitive paths return 404; six-user synthetic QA database verified and
  Preview Visibility withdrawal writes are OFF.
- No public DNS, Tunnel or TLS endpoint was created by the audit.

All five GitHub CI workflows for the immutable Step12H audit commit succeeded.

## Cloudflare documentation verified before design

Official Cloudflare materials:
- Cloudflare Tunnel supports a Unix socket origin service such as
  unix:/run/bambodia-previewqa-http/http.sock:
  https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/routing-to-tunnel/protocols/
- Cloudflare recommends creating the self-hosted Access application and
  access policy BEFORE publishing a Tunnel route:
  https://developers.cloudflare.com/cloudflare-one/access-controls/applications/http-apps/self-hosted-public-app/
- A locally managed cloudflared ingress rule can set originRequest.access
  required=true with a teamName and approved audience tag, so cloudflared
  validates the Access JWT before forwarding to the origin:
  https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/configure-tunnels/origin-parameters/
- For application-side validation, the JWT's signature, issuer and audience
  need validation against Cloudflare's rotating signing keys, not merely
  checking that the header exists:
  https://developers.cloudflare.com/cloudflare-one/access-controls/applications/http-apps/authorization-cookie/validating-json/

## Important Unix access boundary

The origin socket file may be mode 0666, but its systemd RuntimeDirectory
is owned by bambodia_pvhttp with mode 0700. This is why shared www and
nobody cannot connect. A different cloudflared Unix account would not be
able to traverse this directory automatically.

Do not chmod the socket parent to 0755/0777, add shared www to the
bambodia_pvhttp group, change the FPM socket's access policy, or bind an
HTTP port as a shortcut. Any future connector identity, filesystem
namespacing or dedicated socket handoff needs a separately reviewed,
least-privilege design and its own independent acceptance.

The current canonical Host-header guard requires exactly
preview-qa.bambodia.com. Future connector configuration must preserve
this header, without weakening the guard, and must route only through
the private Unix origin. Use a final ingress catch-all that rejects
other hostnames.

## Read-only Step12I host discovery

The script is
support/previewqa-step12i-cloudflare-access-readiness-readonly-20261113.sh.

It checks the accepted Step12H local service/socket/config and synthetic
data/write gates, verifies canonical and unexpected Host behavior with
GET-only Unix HTTP requests, and only then inventories whether the
cloudflared binary, its default systemd service and a named cloudflared
OS account exist.

It does not inspect Tunnel credentials, account IDs, team domain,
audience tags, private keys, token files, existing connector config
contents or public DNS. It does not call the Cloudflare control plane.
An existing cloudflared.service may be owned by other projects and must
not be restarted/reconfigured as part of Bambodia QA.

The script emits STEP12I_ACCESS_FIRST_READINESS_DISCOVERY=PASS when its
local read-only discovery completes; this is NOT confirmation of any
Cloudflare Access application/policy nor approval for publication.
Requests are GET-only, but application GET handlers may perform ordinary
temporary session activity.

## Closed release gates

Before any public QA route, separately:
1. Verify ownership/authorization for the desired Cloudflare zone and
   hostname without disclosing credentials in GitHub or chat.
2. Create an explicitly approved, identity-restricted Cloudflare Access
   self-hosted application; test deny-by-default behavior.
3. Verify connector JWT validation, expected team and AUD tag, token
   signature/issuer and signing-key rotation policy.
4. Review dedicated connector Unix identity and socket traversal access,
   without loosening shared-www isolation or the 0700 private directory.
5. Approve an isolated Tunnel service with no public TCP listener, a
   deny-all default route, a canonical origin Host, fail-closed recovery,
   and staged no-DNS acceptance before publication.
6. Independently verify unauthorized and authorized browser/device
   sessions, public TLS/redirects, direct-origin bypass resistance, logs
   without tokens, and emergency withdrawal procedures.
7. Preserve synthetic data and disabled Preview Visibility writes until
   further explicit approval.

PR #44 remains draft and no merge/deploy to production main is authorized.
All root-only backups are to be preserved. PR #37 stays draft and
withdrawal writes remain OFF.
