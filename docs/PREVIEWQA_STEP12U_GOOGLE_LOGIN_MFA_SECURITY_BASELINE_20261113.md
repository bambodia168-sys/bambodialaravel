# Step 12U — Google sign-in and MFA staged, PreviewQA remains DENY ALL

**Date:** 2026-11-13. **Cloudflare account:** Bambodia, active zone bambodia.com.
**Scope:** preview-qa.bambodia.com only. No connector installation or public route.

## Authorized live changes and independent verification

The owner instructed proceeding with Google sign-in and MFA preparation
for their previously supplied sole administrator Gmail email. The
email is deliberately REDACTED from this repository and exists only
in the exact-email selector of Cloudflare's reusable policy.

After a read-only preflight confirmed one active Deny Everyone Access
policy, a separate single-email Allow policy STAGED / NOT ATTACHED,
zero configured IdPs and no Tunnel/DNS, the following changes were
successfully made through the connected Cloudflare account API:

- Org allowed MFA authenticators: TOTP, biometrics, security keys.
- Org MFA session duration: 1h.
- Org MFA enforcement for all apps: false, protecting unrelated
  Bambodia applications from an unexpected authentication change.
- Unattached, single-email reusable Allow policy updated to explicitly
  require MFA (mfa_disabled=false) with TOTP, biometrics and
  security-key options, and 1h MFA duration.
- Organization auth domain remained
  morning-forest-f298.cloudflareaccess.com.
- Organization deny_unmatched_requests remained false, strict service
  token auth remained on, and end-user app creation remained disabled.

Separate fresh read-only API requests VERIFIED the MFA methods and
duration, exact admin-email selector without revealing the address,
single-email policy still NOT ATTACHED, and active application still
with only one attached DENY Everyone policy. Access IdP count zero;
Cloudflare Tunnel count zero; exact preview DNS records zero.

## Google sign-in cannot be finished without Google Cloud OAuth

Cloudflare's Google provider (personal Gmail) requires a Google Cloud
OAuth client ID and secret. None are currently available from the
Cloudflare account. No OAuth secret was invented or exposed. No
incomplete Google IdP was created.

An authorized owner must create a Google OAuth Web application client
using these exact Google Cloud settings:

    Authorized JavaScript origin:
    https://morning-forest-f298.cloudflareaccess.com

    Authorized redirect URI:
    https://morning-forest-f298.cloudflareaccess.com/cdn-cgi/access/callback

Use Google Auth Platform consent screen/branding for an External
audience, and configure the intended tester or publishing scope.
Google OAuth client ID and client secret must go DIRECTLY into
Cloudflare dashboard > Zero Trust > Integrations > Identity providers
> Add new identity provider > Google. Never paste secrets into ChatGPT,
GitHub issues, repo files, shell history or public logs.

After the Google provider is created, independent acceptance needs to
verify provider login, verified user email, independent MFA enrollment
and challenge, policy ordering, rejection of other Gmail identities,
signed JWT signature/issuer/audience/expiry/JWKS validation, and
authorization under real protected ingress before any publication.
Do not attach the staged Allow or remove Deny Everyone automatically.

## Release status

- Access organization active: YES.
- MFA methods in org: YES; global enforcement: OFF.
- Single admin staged Allow now MFA-enabled: YES.
- Staged Allow attached: NO.
- PreviewQA active app Deny Everyone: YES.
- Google OAuth client and IdP configured: NO.
- Tunnel, public PreviewQA DNS, deployed connector: NO.
- Real JWT/browser acceptance and human security signoff: PENDING.
- GitHub PR #44/#37: DRAFT; main unchanged.
- Preview Visibility withdrawal writes: OFF.
- Real QA HTTP/PHP-FPM, aaPanel and production: UNCHANGED.

Official docs:
https://developers.cloudflare.com/cloudflare-one/integrations/identity-providers/google/
https://developers.cloudflare.com/cloudflare-one/access-controls/access-settings/independent-mfa/
https://developers.cloudflare.com/cloudflare-one/access-controls/policies/mfa-requirements/
