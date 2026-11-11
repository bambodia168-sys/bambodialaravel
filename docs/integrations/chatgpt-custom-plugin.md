# ChatGPT custom MCP connection information — Bambodia

This is the *proposed* connection configuration for the Bambodia Laravel MCP
implementation in the draft pull request. It is **not a statement that the
production endpoint is live**. Do not add it in a real workspace until an
independently tested deployment and authentication integration are verified.

## Paste these values into ChatGPT Plugins

| Field | Value |
|---|---|
| Plugin name | Bambodia Manager |
| Description | Manage Bambodia marketplace drafts, approved-media links, own creator profile, SEO and social settings, and read operational reports with role-restricted tools. |
| Developer | Bambodia |
| Website | https://bambodia.com |
| MCP endpoint | https://bambodia.com/mcp |
| Transport | Streamable HTTP (JSON-RPC 2.0) |
| Authentication | **Development only:** existing Bambodia hashed Bearer developer token; **browser sign-in/approval:** not implemented yet (needs OAuth 2.1) |
| User permissions | mcp.read for searches and reports; add mcp.write only for reviewed draft mutations |
| Version | 0.1.0 foundation |

Do **not** guess OAuth authorization, token, registration, discovery or
redirect URLs. OAuth configuration must be created by the chosen identity
provider and verified against the real deployed server.

The current code uses no email/password login inside MCP itself.
A super-admin can create a short-lived developer token at the existing
Bambodia /developer page after confirming their password; its secret is shown
once. ChatGPT's custom MCP-connection UI may require OAuth instead of raw
Bearer headers, depending on the workspace and supported authentication
options. Never paste tokens into ChatGPT messages, GitHub or URLs.

## Tool inventory, after deploy

Read-only:

- bambodia_overview — capabilities/limits
- bambodia_list — list/search ten results per page across product, project,
  service, freelance, job, post, page, policy, profile, media, SEO, social, audit
- bambodia_get — inspect a single record
- bambodia_analytics — aggregate statuses/media review/audit counts (1-90 days)

Write with mcp.read + mcp.write:

- bambodia_create_draft — create an unpublished product/project/service/freelance,
  job, post, page or policy draft
- bambodia_update_draft — edit allowed fields of an unpublished draft
- bambodia_delete_draft — delete draft after exact-title confirmation
- bambodia_update_own_profile — only the connected administrator's own creator
  profile headline, biography, website and public visibility
- bambodia_manage_site — SEO/social-link create, edit, delete; live-record changes
  require confirm_live_change=true; delete requires exact title/label
- bambodia_draft_media — attach/detach existing approved, owned media for
  unpublished drafts; no arbitrary remote uploads or review bypass

## How to create a ChatGPT custom connection (when deployment is verified)

1. Open ChatGPT on the web. Go to **Plugins**, select **+**, then
   **Add custom MCP server**, if your plan/workspace permits it.
2. Set the name to Bambodia Manager and the server URL to
   https://bambodia.com/mcp; select Streamable HTTP.
3. Select the authentication mode supported by your *deployed* Bambodia
   server. To get the requested browser-based administrator approval,
   implement and use OAuth 2.1 with consent, revocation and refresh tokens.
4. Review the risk warning and consent to testing. Select **Create as a plugin**,
   inspect the discovered tools and keep write permissions restricted.
5. Install the plugin, start a new chat and select @Bambodia Manager.
   Try a read-only query first (e.g. "List Bambodia services").
6. Test write tools only against disposable drafts, with explicit confirmation.
   Do not approve public legal notices, deployments or irreversible changes via
   this development connector.

OpenAI's currently documented full write-enabled MCP connections are for
Business/Enterprise/Edu workspaces. Availability depends on your account/workspace
and can change. Check the current help documentation before attempting setup:
https://help.openai.com/en/articles/12584461-connecting-custom-mcp-servers-in-chatgpt

## Required production readiness

- Draft PR CI must pass at its **latest commit**, not an earlier commit.
- Run PHP tests, installer tests, backups and isolated-host acceptance.
- Install the new application code safely; verify php artisan route:list
  --path=mcp shows the endpoint.
- Verify initialized JSON-RPC request works using a revocable read-only token
  and confirm revoked/expired or wrong-scope credentials fail.
- Publish OAuth discovery, consent and token endpoints with a maintained provider
  before using "Sign in with Bambodia" in ChatGPT.
- Confirm the exact URL and HTTPS certificate from outside the server.
- Confirm tool list/permissions in ChatGPT after successful authorization.

GitHub code/design changes are handled separately through ChatGPT's already
connected GitHub integration, with feature branches, pull requests and CI.
The Bambodia website MCP server intentionally does not expose arbitrary
source-code editing, shell execution, database SQL, or deployment commands.
