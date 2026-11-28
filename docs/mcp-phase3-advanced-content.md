# Bambodia MCP Phase 3 — controlled release

This branch adds an independently authorized service, nullable media description column, regression tests and a staging-only controller extension. The installed MCP/OAuth controller is an overlay outside main. Do not replace it with a main-branch copy, run a broad checkout over production, or use the old Phase 2A installer.

## Scope
Five reads and three writes, preserving the existing 16 tools. Draft preview/validation/duplication supports products, projects, services, freelance listings, jobs, posts and pages. Legal policies are aggregate-only because the Policy model lacks an ownership column. Content metadata is limited to post/page excerpt, tags and SEO text. Media search and metadata are restricted to owned approved assets. The description is a new nullable field; alt text can affect public display, so explicit confirmation is required. Duplication copies text and safe classification only, never files, galleries, media, terms, prices, approvals, licenses or fulfillment. No publication, uploads, remote requests or scope changes. Readiness is advisory, not a substitute for native validation/review.

## Acceptance and deployment gates (not an installer)
1. Require passing PR quality, isolated authenticated journeys, MySQL installer, operational and browser acceptance for the exact candidate SHA. Review the new migration and test MySQL plus SQLite. Do not merge or deploy based only on tool discovery.
2. Obtain the actual installed controller as a protected copy (no token, configuration or secrets). Review the existing callTool gate, definitions and authorizeDefinitions. Compare the source Git blob with an independently reviewed hash.
3. Run the new Python staging utility on that copy with --source, --output and --expected-source-blob. It fails closed on unexpected anchors, missing OAuth protections, duplicate tools or drift. PHP-lint the resulting controller and service; review the diff including both write-name arrays. Never copy the staged controller into production during this phase.
4. In an isolated application with separate database and no production credentials, apply only the additive migration and staged service/controller. Verify discovery: 15 READ tools with write feature off (subject to verified existing discovery contract), 24 with write feature on; specifically compare existing 16 names and annotations against baseline with identical config. Validate read-only token execution, missing scope challenge, explicit write authorization, revoked/expired grants, inactive/other roles, foreign owners, wrong types, active records and protected fields. Check audit logs, no secret paths and no unexpected DB writes. Existing controller source is not tracked, so these integration gates cannot be inferred from repository unit tests.
5. Rehearse rollback with a protected database snapshot and exact file backups; preserve existing controller bytes and restore only modified files. The nullable description column is additive; leave it in place during code rollback to avoid data loss. Do not run a blanket migration rollback.
6. Only after separate deployment authorization, use a commit-pinned release manifest, maintenance lock, exact file hashes, verified backup, and an isolated staging acceptance report. Apply only the reviewed files and single migration, then check health, tool discovery and non-mutating reads. No public content changes in deployment. No OAuth reconfiguration or restart unless a verified operational need is separately approved. Production WRITE tests require a separate controlled acceptance request.

## Test commands
```sh
python3 support/bambodia-mcp-stage-advanced-content.py --self-test
php artisan test --filter=McpAdvancedContentToolsTest
```
