# Step 12S — Access exact-host deny-everyone baseline

**2026-11-13 — Access enabled, application denied to all, NO public routing.**
This records actual Bambodia Cloudflare control-plane API changes and
independent GET-based re-verification. It is not a separate human
security signoff, public browser test, JWT acceptance or connector
deployment.

## Owner-authorized changes performed

The owner explicitly instructed enabling Cloudflare Access and
configuring a deny-by-default, exact-host protected PreviewQA
application **before** any public Tunnel route.

Prior GET verification found Cloudflare Access disabled on the
Bambodia account owning active bambodia.com (Cloudflare API error
access.api.error.not_enabled), no account Tunnels, and no exact/wildcard
PreviewQA DNS records.

The Zero Trust organization was then CREATED successfully:
- Name: Bambodia
- Auto-generated auth domain: morning-forest-f298.cloudflareaccess.com
- deny_unmatched_requests: false (avoid account-wide disruption to
  existing public bambodia.com and unrelated services)
- disable_end_user_app_creation: true
- allow_authenticate_via_warp: false
- strict_service_token_auth: true

One **reusable** Access policy was created:
- Name: Bambodia PreviewQA — Deny Everyone (Prepublication)
- Decision: deny
- Include selector: everyone
- No Allow, Bypass or Service Auth selectors added

One **self-hosted** Access application was created:
- Name: Bambodia PreviewQA — Blocked Until Security Acceptance
- Exact hostname: preview-qa.bambodia.com
- Attached reusable Deny Everyone policy, precedence 1
- Session duration 1h, app launcher hidden, WARP authentication off
- Binding cookie and HttpOnly cookie requested
- Cloudflare assigned an app audience tag (value withheld from
  this repo record; JWT acceptance remains unverified)

## Independent fresh READ-ONLY verification

After the writes, independent Cloudflare API GETs confirmed:
1. Access org exists with the settings above.
2. Exact self-hosted app GET has one attached deny policy.
3. App-specific policy list: exactly ONE deny policy for everyone.
4. Reusable policy GET: exact same deny policy, reusable true.
5. Full Bambodia Access application list: ONE app (PreviewQA).
6. Account Tunnel list: ZERO.
7. Exact DNS records preview-qa.bambodia.com: ZERO.
8. Wildcard DNS records *.bambodia.com: ZERO.

This confirms stored control-plane configuration ONLY. It does not
prove a browser request was denied at the Cloudflare edge, because
there is no public route/connector/DNS through which to test.
Cloudflare Access applications default-deny without an Allow policy;
the explicit reusable Deny Everyone provides further restriction.

## Closed and OPEN release boundaries

- No cloudflared installed, no Tunnel, no public hostname route/DNS,
  no TLS changes, no tokens/credentials read or created.
- No aaPanel, production, QA HTTP/PHP-FPM, firewall, shared www
  permissions, private socket access, GitHub main merge or Preview
  Visibility withdrawal writes changed.
- Draft PR #44 and PR #37 remain DRAFT.

The following remain OPEN before authorized-user access or publication:
- Exact identities / Identity Provider / MFA allowlist, approved by
  owner and independently reviewed; do not invent or permit Everyone.
- Access policy ordering, negative browser/API authorization and
  all-path coverage verified on a guarded route before release.
- Actual least-privilege cloudflared systemd unit, identity and
  root/private credential delivery accepted by a separate reviewer.
- Real signed Access JWT enforcement using verified account team,
  application AUD, RS256 signature, issuer/audience/expiry, key
  rotation and failure cases.
- Automated fresh-socket namespace recovery after real QA origin
  replacement, bounds/quarantine, secret-free logs and emergency
  disable/withdrawal procedures.
- Explicit external security and publication signoff.

**Release status: ACCESS CONFIGURED DENY ALL; CONNECTOR INSTALL AND
PUBLICATION STILL BLOCKED.**

Official Cloudflare references:
- https://developers.cloudflare.com/cloudflare-one/access-controls/applications/http-apps/self-hosted-public-app/
- https://developers.cloudflare.com/cloudflare-one/access-controls/policies/policy-management/
- https://developers.cloudflare.com/tunnel/reference/origin-parameters/
