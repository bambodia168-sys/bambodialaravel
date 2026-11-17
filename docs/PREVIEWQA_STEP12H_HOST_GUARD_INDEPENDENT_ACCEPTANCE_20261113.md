# Step 12H: independent origin Host-header acceptance

Status: source prepared, **not yet run on aaPanel**.

## Last real aaPanel acceptance

Step12G was activated with a source- and blob-pinned one-time operator command on
2026-11-13. The real server reported STEP12G_HOST_GUARD=PASS, including:

- Canonical Host preview-qa.bambodia.com /up and /login HTTP 200.
- Unknown Host, localhost/IP Host, and canonical with :443 Host: HTTP 421.
- HTTP/1.0 missing Host: HTTP 421.
- Direct PHP, dotfiles, storage and installer paths: HTTP 404.
- Guest /account and /dashboard: HTTP 302.
- Both private QA HTTP/FPM services active and disabled at boot.
- Private socket access denied to other users; private original backup created.
- No production/shared Nginx or FPM change, public DNS, Tunnel/TLS, or enabled
  Preview Visibility withdrawal writes.

This was **installer acceptance**. It is not an independent audit and does
not authorize external publication.

## Step12H independent audit procedure

The auditable operator source is
support/previewqa-step12h-independent-host-guard-audit-20261113.sh.
Use a GitHub commit and verified Git blob to download it into an ephemeral
root-private path, verify bash syntax, and execute it **without arguments**.

The audit only:
1. Reads private service/config/backup and source metadata; verifies exact
   pinned synthetic source, six-user QA database and withdrawal writes OFF.
2. Verifies HTTP and FPM isolated service and socket boundaries, private
   0700 runtime directory and forbidden real connections as shared www and
   nobody (where those accounts exist). Neither service is restarted.
3. Reconstructs the expected Nginx configuration from the root-only original
   backup in /root/Bambodia-PreviewQA-Host-Guard-20261113/nginx.conf,
   adding exactly the reviewed canonical Host restriction, and byte-compares
   it with the installed private config. This detects any other unreviewed
   Nginx configuration edits.
4. Sends unauthenticated GET-only requests over the existing local Unix
   HTTP socket for canonical, uppercase canonical, unexpected, localhost,
   IP-address, port-qualified and missing Host cases. Confirms protected
   paths stay 404 and guest account/dashboard remain redirects.
5. Stores request headers only temporarily within a root-private directory;
   removes that directory on exit. Does not print response headers, session
   cookies or private environment values.

Expected success marker: STEP12H_INDEPENDENT_ORIGIN_HOST_AUDIT=PASS.

If a BLOCKED marker appears, do not modify permissions, restart either
service, rerun any one-time installer, or publish the local origin. Preserve
all root-private backups and inspect the exact failure. Ordinary GET
handling may create ephemeral Laravel session state; no credentials or
POST/PUT/PATCH/DELETE requests are sent by the audit.

## Separate public Access gate

Successful Step12H remains **local-only origin acceptance**, not
Cloudflare/HTTPS publication approval. The following require a separate
Access-first design, explicit owner approval and independently verified
acceptance: Cloudflare Access policy and identity, JWT audience/issuer
validation at the origin, Tunnel credentials/destination restrictions,
TLS/DNS activation, QA canonical URL migration, browser and physical-device
tests, and protection against direct origin bypass.

Keep PR #44 in draft; PR #37 withdrawal writes remain OFF. No merge to
production main or deployment, shared aaPanel service change or public
preview hostname activation is authorized by this audit.
