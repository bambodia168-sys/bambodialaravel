# Bambodia developer API

Version 0.0.1 — read-only endpoints currently implemented.

## Authorization

Access /developer as a Super Admin to issue a credential. A token must be named, scoped and assigned an expiration of 7, 30 or 90 days. You must confirm the current account password to create or revoke a token.

The token is displayed only once. The database stores its 64-character SHA-256 hash and a non-secret identifier prefix, never the original secret. Use a separate secure secrets manager to store it. Never commit credentials to GitHub, store them in frontend JavaScript, or share them in support tickets.

An administrator demoted from Super Admin loses all API access immediately; expired and revoked tokens do not authenticate.

Requests use:

    Authorization: Bearer <TOKEN>
    Accept: application/json

Use HTTPS in production.

## Real endpoints

- GET /api/v1/account: account.read scope; returns the token owner's public ID, name, email, role and locale.
- GET /api/v1/listings: listings.read scope; returns only published listings, 10 per page, with ?page=N pagination.

Responses are JSON. HTTP 200 indicates success, 401 invalid/missing/revoked/expired token, 403 insufficient scope, 429 request rate limit. No mutation endpoints exist. An issued token does not grant any payment, order, license-delivery or marketplace editing access.

## Security operations

Access /security-dashboard as Super Admin to inspect actual audit events in ten-row paginated tables. Events include authentication, authorization-affecting changes, marketplace review, policy publishing, developer token activity and API reads.

The event table stores a rotating daily HMAC fingerprint of the request IP, never the raw IP or request body. Do not log access tokens, passwords or payment data. Records are removed by audit:prune after 90 days **when Laravel's scheduler is configured**. Configure one cron entry to invoke Laravel schedule:run every minute, or use your hosting provider's equivalent scheduler. See INSTALLATION.txt.

API access must remain read-only until new, independently tested workflows introduce additional scopes and endpoints.
