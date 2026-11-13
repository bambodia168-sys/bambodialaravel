# Bambodia Manager MCP — Security and production-release gates

Status: **DRAFT / DO NOT DEPLOY**  
Review date: 2026-11-13 (Asia/Phnom_Penh)  
Source: draft PR #46, branch `feature/bambodia-mcp-main-domain-20261112`.  
Canonical resource: `https://bambodia.com/mcp`  
Issuer: `https://bambodia.com`

This is a **preliminary source-level review and operator-reported isolated QA evidence**, **not** an independent security sign-off, production acceptance or authorization to use customer data. Do not interpret CI or localhost tests as a production deployment gate.

## 1. OAuth / MCP source review

| Control | Reviewed implementation / evidence | Release decision |
|---|---|---|
| Issuer and resource audience | `McpResource` uses configured app URL; `AuthenticateMcpToken` requires Passport-validated bearer and exact `iss`, `jti`, first client audience, second `/mcp` resource audience | Preliminary positive; independently test negative issuer/audience and signing-key rotation |
| JWT signing/verification | `BambodiaPassportAccessToken` signs JWT with Passport RSA key (RS256); Passport guard validates token and revocation before audience parsing | Preliminary positive; review real production key custody/permissions/revocation/expiry without exposing private keys |
| PKCE and resource | GET authorization and POST token guard require canonical `resource`; integration QA tests S256 and wrong verifier | Isolated PASS; public endpoint and final OAuth callback not tested |
| Administrator consent | Passport consent view requires active `super_admin`; on POST approval, `EnsureMcpOAuthConsentAdmin` reloads authoritative role/status after session startup | Isolated PASS; independent browser/session/2FA review still required |
| Dynamic client registration callback | Laravel MCP SDK allows a host-based URL prefix; application now adds exact callback-ID URI allowlist | **Changed in draft**, CI/physical host retest required |
| Write permission | `mcp.production_writes_enabled` blocks and hides write tools; new registrations do not receive `mcp.write` eligibility unless write switch is enabled | **Changed in draft**, CI/QA retest required; writes must remain disabled for initial release |
| Token refresh/revoke | Passport refresh rotation, token revocation and per-user connection list; password required to revoke | Isolated PASS; real FPM/DB transaction and key-rotation acceptance pending |
| Error confidentiality | MCP errors return generic text; QA diagnostics emit allowlisted class/path rather than raw exception messages | Preliminary positive; review production log masking and retention |

### Fail-closed exact callback policy

`config/mcp.php` defines `exact_redirect_uris` from a comma-separated operator-only `BAMBODIA_MCP_ALLOWED_CALLBACK_URIS` value, **empty by default**. New client registration rejects any URI not present as an exact string and not shaped `https://chatgpt.com/connector/oauth/{callback_id}`. Multiple URLs are allowed only if each is deliberately approved.

Before changing that setting, obtain the **actual** callback URI from the ChatGPT MCP management page and review it independently. Do not invent a callback ID. The stable callback `https://chatgpt.com/connector_platform_oauth_redirect` is **not authorized** by this interim policy because RFC 9207 authorization-response `iss` signaling has not yet been independently verified. If stable callback support is later needed, review metadata and success/error response issuer identification, add a dedicated design/test gate, then approve a separate change. Do not bypass callback validation.

Do not include QA callback `https://chatgpt.com/connector/oauth/qa-callback` in production settings: it is reserved for disposable tests.

For the initial production rollout, `BAMBODIA_MCP_ENABLED=false` and `BAMBODIA_MCP_WRITES_ENABLED=false` remain mandatory. Registration should be unavailable until the exact callback is approved and a separate read-only activation is explicitly authorized. Existing secrets, Laravel `APP_KEY`, production customer records and old QA keys must never be reused or copied into the release candidate.

## 2. Real aaPanel Nginx / PHP-FPM hosting

Past operator evidence (2026-11-12 to 2026-11-13) identified the **real** `bambodia.com` document root as `/www/wwwroot/bambodia-staging-qa/public`, despite the staging-looking name. Treat it as production. A separate previously accepted vhost SHA256 was `5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116`. Earlier public OAuth resource discovery returned 404 because real production did not contain a complete MCP/Passport release. These historical observations must be rechecked; nothing in an isolated `127.0.0.1` test proves the public proxy.

Read-only operator tool: `support/bambodia-mcp-release-gates-readonly.sh --inspect`. It verifies pinned vhost identity, Nginx syntax and sanitized configuration presence, available PHP versions, installed MCP and Passport packages, and status-only public unauthenticated GETs. It never prints raw Nginx config, reads production `.env`, sends bearer credentials or invokes mutating application endpoints. **Do not interpret an HTTP 200 on metadata alone as authorization/HTTPS/edge readiness**; inspect the content and headers later on a separate authorized release candidate.

Outstanding before release:
- Independently review exact `/.well-known` handling at Nginx and CDN, TLS/Host routing, FastCGI `HTTP_AUTHORIZATION`, PHP-FPM configuration and effective application URL
- Verify real 401 Bearer challenge and issuer/audience under isolated production-equivalent FPM, not only `php -S`
- Confirm CSP/security headers, redirect behavior, rate limits and safe error logging
- Reconcile new code with real live-only marketplace frontend/backend changes; never overwrite with a stale source snapshot

## 3. Production data, backup and rollback

No evidence in this review demonstrates a **fresh, encrypted/restorable backup of the real customer database and private media**, a completed independent restore, or rollback acceptance. These remain **BLOCKED**.

Required authorized acceptance:
1. Record the real site's backup/restore owner and encrypted storage destination; inventory current backup timestamps without exposing filenames or sensitive records in ChatGPT.
2. Create a fresh production-consistent backup using approved operator change control. Preserve customer records, media, current `APP_KEY` and database schema; handle OAuth signing keys separately.
3. Restore to an access-controlled isolated database/media clone and verify integrity and business functionality: record counts, UUID/user ownership, drafts/published content, license status, uploaded media checksums, permission boundaries, language UI, and login. Never attach the clone to public DNS or real customer notifications.
4. Reconcile actual live source drift (previous audit found 39 changed shared files, 2 live-only and 26 staged-only) against the proposed delta overlay. Reconcile backup and rollback to these exact file hashes. No destructive full-source copy.
5. Rehearse application rollback and feature-switch rollback with a test transaction. Do not blindly roll back a production customer database or delete OAuth tables after activation.
6. Obtain written independent reviewer sign-off, with separate operational owner approval.

## 4. Controlled read-only release — NOT AUTHORIZED

A separate, explicit release authorization is required after all blockers above pass. Proposed sequence:
- Approved recoverable release artifact, dependency lock, frontend assets and checksum manifest
- Production source delta reviewed, backed up, and reversible; same-site permissions and PHP-FPM verified
- Deploy with both MCP feature switches OFF, without copying QA secrets/keys/data
- Approve a separate migration operation and generate **new** production Passport signing keys with key-custody controls
- Verify public discovery/401 behavior on a controlled read-only candidate, and record rollback proof
- Only then separately authorize enabling MCP **read access**; **keep write switch OFF**
- Revoke disposable connections and disable MCP immediately if authorization or monitoring acceptance fails

No action in PR #46 is production authorization. Do not merge to `main`, install or enable without these approvals.

## 5. Real ChatGPT connection — NOT ATTEMPTED

Actual linked-account acceptance requires a reviewed callback URI, public HTTPS metadata, approved production issuer and signing keys, enabled read-only MCP, and user-initiated login/consent. Then verify callback `state`, PKCE S256, exact issuer/resource, read-only tools, token expiry, refresh rotation, revocation and post-revocation denial on real HTTPS endpoints. Confirm no write tools appear or execute. After this, a **separate** independent security review and explicit user approval are prerequisites for any write activation.

## Evidence and decisions

- 2026-11-13 physical disposable PHP 8.5 loopback: five GitHub workflows PASS; `CHATGPT_LOOPBACK_INDEPENDENT_HTTP_OAUTH_ACCEPTANCE=PASS`; `PHYSICAL_HTTP_ACCEPTANCE=PASS`; production unchanged. The environment-only `variables_order=EGPCS` override resolved an isolated `MissingAppKeyException`.
- The new exact-callback + initial read-only registration gates and their tests were added **after** that passing acceptance. **Do not reuse the older PASS for the updated source.** Re-run current commit's CI and disposable physical acceptance before accepting this change.
- The host inspector and this report are additive draft-branch artifacts. No real aaPanel hosting data, real OAuth credentials or production backups have been accessed by this review.
- Overall release verdict: **BLOCKED** pending independent security reviewer, operator-host evidence, backup/restore, source-drift reconciliation, rollback rehearsal, and explicit release approval.

References: RFC 9700 OAuth Security BCP; RFC 8707 resource indicators; RFC 9207 issuer identification; RFC 9728 protected-resource metadata; OpenAI's Plugins authentication guidance; Laravel MCP OAuthRegisterController source.

## Physical aaPanel latest security and hosting evidence — 2026-11-13

Operator executed the immutable commit `9286a1757113281e05717e30e21127e3fc95d916` via a hash-verified script. Five CI workflows **PASS**. The disposable PHP 8.5.11 loopback confirmed `QA_EXACT_CALLBACK_AND_SCOPE_GATE=DISPOSABLE_COPY_ONLY`, `PHYSICAL_PHP85_MCP_OAUTH_TESTS=PASS`, `CHATGPT_LOOPBACK_INDEPENDENT_HTTP_OAUTH_ACCEPTANCE=PASS`, and `LATEST_SOURCE_PHYSICAL_OAUTH=PASS`. Discovery/bearer challenge, admin sign-in and consent denial, PKCE read vs write denial, refresh rotation and revocation, and ownership-limited draft operations passed. These tests used generated QA credentials and isolated SQLite data, not production authorization.

The same server's **read-only production** host inspection reported: pinned Nginx vhost integrity PASS; Nginx syntax PASS; PHP 8.4.26 and 8.5.11 available; configured server name/document root, FastCGI PHP upstream and `HTTP_AUTHORIZATION` parameter present somewhere in the effective Nginx configuration. The latter **does not yet prove authorization forwarding for an actual `bambodia.com` request**. Exact root well-known rule NOT PROVEN. Main site returned HTTP 200; root/nested OAuth protected-resource discovery and authorization metadata all HTTP **404**. Production MCP and Passport packages both **absent**. Public OAuth readiness remains **BLOCKED**.

Latest source inventory compared actual live source against the older full staged candidate: **408 shared files, 367 identical, 41 changed, 7 live-only, 26 staged-only**. Newly live-only files include `public/css/footer-two-tone-themes.css`, `public/css/home-hero-aurora.css`, `resources/js/blog-premium-player.js`, `resources/views/components/blog-card-featured-media.blade.php`, and `resources/views/components/blog-premium-native-video.blade.php`, alongside the original header and identity JS files. Other changed files affect public homepage, blog, marketplace UI, dashboard, job/post managers, and one additional Vite JS input. The earlier approved private overlay was built at **39 changed, 2 live-only, 26 staged-only**; its freshness against today's live site is not proven. Do not deploy that overlay unchanged.

The new read-only `support/bambodia-mcp-private-overlay-freshness-readonly.sh --inspect` compares current live code with the **previously captured private overlay**. For the nine MCP-critical paths, it compares to the saved `review/live-critical` originals; for other approved code paths, it compares to the actual private candidate. It identifies files that changed or were added since the private review, without modifying either side. Results still require operator execution and review, followed by a conflict-safe private candidate refresh.

**Gate status:** physical OAuth QA PASS; production hosting NOT READY; live source reconciliation NOT APPROVED; production backup/independent restore NOT VERIFIED; release/rollout/real ChatGPT OAuth NOT AUTHORIZED. No production MCP deployment or activation occurred.
