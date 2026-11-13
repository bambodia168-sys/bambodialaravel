# Step12T — sole administrator identity staged; no active access granted

**Cloudflare account:** Bambodia; active zone bambodia.com.
**Date:** 2026-11-13.
**State:** identity selector staged as an **unattached reusable** Allow
policy. Application Access remains **Deny Everyone**.

## Owner-supplied identity

The owner supplied one Gmail address for future PreviewQA authorization.
The address is intentionally redacted from the repository and PR issue
comments; the exact value is stored only in the Cloudflare policy.
Do not publish this identity in logs or add it to code fixtures.

## Actual Cloudflare writes

1. A first attempt to create a single-email reusable Allow policy with
   explicit independent-MFA configuration FAILED with HTTP 400
   (Cloudflare code 12130): the organization must have at least one
   allowed authenticator configured for policy-level mfa_config.
   The failed request created no policy.
2. A reusable Allow policy was then successfully created with the exact
   owner-supplied individual email selector and 1h session duration.
   It is named:
   Bambodia PreviewQA — Administrator Email (STAGED, NOT ATTACHED)
3. **No policy was attached to the Access application.**
   No identity provider was configured or invented; no MFA enforcement
   was enabled or represented as verified.

## Independent fresh read-only confirmation

- Exact-host Access app preview-qa.bambodia.com still has **exactly one**
  attached reusable policy:
  Bambodia PreviewQA — Deny Everyone (Prepublication)
  with action DENY and Include Everyone.
- The newly created single-email Allow policy is reusable and exists
  account-wide but **is NOT attached to this or any other application
  by this workflow**.
- Available configured Access identity providers in Bambodia account:
  **zero returned**.
- Account Cloudflare Tunnels: **zero returned**.
- Exact preview-qa.bambodia.com DNS records: **zero returned**.
- No real connector/JWT/browser authorization acceptance performed.
- No Access Allow rule grants anybody access to the PreviewQA application.

This is a strict fail-closed staging step. At a later, independently
reviewed authorization gate: set up a suitable identity provider (e.g.,
Google identity login, subject to owner choice); configure eligible MFA
authenticators and require MFA on the application or policy; verify
sign-in, policy evaluation and absence of broad Allow/Bypass; then
safely reconcile the active Deny Everyone policy with the
single-email Allow policy in one reviewed change. Cloudflare Access
policy decision/precedence semantics must be inspected before that
transition; do not assume attaching an Allow overrides Deny Everyone.

## Remaining security blocks

No identity provider or MFA authentication flow has been accepted.
No authorization to enable the staged Allow policy or revoke Deny All.
No Cloudflare Tunnel/DNS/connector, live systemd unit or signed JWT
acceptance. No aaPanel service restart, production deployment or
Preview Visibility withdrawal writes. Both PR #44 and PR #37 remain
draft until independent release acceptance.
