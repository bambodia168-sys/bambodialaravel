# Bambodia MCP connector — foundation (review candidate)

Status: **not deployed; browser OAuth is not implemented.** This branch adds a small, auditable
MCP-compatible HTTP JSON-RPC endpoint. It reuses Bambodia's existing temporary,
hashed, revocable, super-admin developer tokens. Do not advertise this
branch as a ready-to-connect ChatGPT OAuth integration.

## Endpoint

Once independently reviewed, tested and deployed:

- URL: POST https://bambodia.com/mcp
- Content type: application/json
- Bearer authorization: Authorization: Bearer bmb_...
- Rate limit: 30 requests/minute
- Scope: mcp.read for discovery/read and both mcp.read plus mcp.write for draft operations

Create a token while logged into the **super admin** Developer workspace
at /developer, after password confirmation. Copy it only once and store it
in a secure credential manager. A read-only token should be preferred initially.
Do **not** send access tokens in ChatGPT messages, URLs, GitHub or logs.

Example protocol handshake for an isolated QA server. Replace placeholders
using local environment variables and never paste real secrets into chat:

    curl -i -X POST "$BAMBODIA_QA_URL/mcp" \
      -H "Authorization: Bearer $BAMBODIA_MCP_TOKEN" \
      -H 'Content-Type: application/json' \
      -H 'Accept: application/json, text/event-stream' \
      --data '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"qa","version":"1"}}}'

Then call tools/list. A browser GET may return HTTP 405; MCP uses JSON-RPC POST.
The endpoint supports initialize, ping, notifications/*, tools/list,
tools/call, resources/list, and prompts/list.

## Ten compact tools

| Tool | Permission | Current capability |
|---|---|---|
| bambodia_overview | Read | Supported entities and operational limits |
| bambodia_list | Read | Ten-per-page search across supported entity models |
| bambodia_get | Read | Inspect a record using an explicit field allowlist |
| bambodia_create_draft | Write | Draft product/project/service/freelance/job/post/page/policy |
| bambodia_update_draft | Write | Edit allowlisted fields only while status is draft |
| bambodia_delete_draft | Write | Delete draft with exact-title confirmation |
| bambodia_analytics | Read | Aggregated content, media-review and audit counts |
| bambodia_update_own_profile | Write | Edit administrator's own creator headline, biography, website and visibility |
| bambodia_manage_site | Write | Create/update/delete SEO metadata and social links; live-change confirmation required |
| bambodia_draft_media | Write | Attach/detach approved, owner-controlled Media Library assets to unpublished drafts |

Media and audit events remain read-only. Profile writes are limited to the token owner's creator profile. SEO and social settings have controlled CRUD, with explicit live-change confirmation and exact-title deletion. Product/project/service/freelance share the existing MarketplaceListing
model and are filtered by type; posts/pages share ContentEntry.

Draft writes use existing Eloquent models, identifier allocation, field validation,
HTML sanitization, transactions and audit events. The media tool links only existing
approved assets owned by the token owner; it does not upload or review files. No operation in this stage
can directly approve a listing/job, publish a post, publish a legal policy,
mutate license/entitlement/payment records, upload a file, change security
settings or deploy code.

## Deliberate security boundaries and follow-up work

The goal is a full ChatGPT connector with browser approval and CRUD for
marketplace, profiles, media, scheduling, analytics, security, marketing,
SEO and website/GitHub changes. **This first stage is not that complete
connector.** Next phases must reuse each existing business workflow:

1. Configure browser OAuth 2.1 using a maintained provider, short-lived tokens,
   PKCE, authorized scopes, consent display and revocation. Do not create
   a custom OAuth engine or collect administrator passwords in chat.
2. Expand owner-scoped profile editing beyond basic creator details, add media uploads through existing MIME/ownership/rights/review checks, and consolidate SEO/social validation with the dashboard's existing rules to prevent divergence.
3. Implement scheduling only after publishing queues, timezone behavior,
   cancel/edit semantics and worker acceptance are independently tested.
4. Expose audit/security reports read-only. Never expose shell, SQL execution,
   credential recovery or production deployment actions as MCP tools.
5. Connect GitHub through its existing ChatGPT plugin. Keep code changes
   in branches + pull requests + CI + review, separate from live content edits.
6. Test protocol/client compatibility, admin role restrictions, backups and
   rollback on isolated QA before production use.

## QA and release criteria

- php artisan test --filter=BambodiaMcpTest (includes SEO/social/profile/analytics coverage)
- php artisan test --filter=DeveloperApiTest
- Run all existing isolated-host acceptance workflows.
- Attempt unauthenticated, expired, revoked, wrong-scope and demoted-token calls.
- Confirm no published or pending record can be edited or deleted by draft tools.
- Verify no changes to frontend layout, existing routes or client UI.
- Verify backup/rollback before deploying the endpoint.

Only deploy after a clean review and CI. Developer tokens alone do **not**
implement the requested browser-based administrator approval experience.
