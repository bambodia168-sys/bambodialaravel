# Step 12E — QA-only Nginx Lua runtime recovery

**Status: source prepared, not executed on the aaPanel host.**

## Evidence

Step12C was a one-time guarded install. It created the private HTTP OS account,
static mirror and private root backup, but its new Nginx failed to start. The
fail-closed handler stopped the two dedicated QA services.

Owner's journal at 2026-11-12 15:09:07 UTC:

- The private Nginx could not open the shared aaPanel default
  /www/server/nginx/logs/error.log (permission denied).
- The private Nginx could not load Lua module resty.core from its default
  library path; it exited with status 1.

Owner's Step12D on-host read-only discovery:

- /www/server/nginx/lib/lua/resty/core.lua exists, is readable by
  bambodia_pvhttp and needs no new download.
- The shared aaPanel Nginx uses Lua path
  /www/server/nginx/lib/lua/?.lua;; in its http context.
- Nginx 1.30.3 supports command-line -e stderr.
- Both dedicated QA services are stopped and disabled at boot.
- The private QA FastCGI socket group remains bambodia_pvhttp and mode 0660.
  Shared www does not belong to the group.

## Proposed QA-only correction

The guarded script at
support/previewqa-step12e-private-nginx-lua-recovery-20261112.sh:

1. Adds the already-tested directive
       lua_package_path "/www/server/nginx/lib/lua/?.lua;;";
   immediately after the http block opening in the dedicated QA Nginx config.
2. Adds -e stderr to the ExecStart command for only
   bambodia-previewqa-http.service.

This does not change main aaPanel Nginx, its log file permissions, any
production PHP-FPM, public DNS/TLS, Cloudflare, or the QA FPM configuration.
Never disable the resty.core load or install a different Lua dependency.

## Step12E --check

Fetch the exact immutable GitHub source/blob, check bash -n, then run:

    bash SCRIPT --check

It verifies isolated synthetic QA revision and database identity (six users,
default-off preview writes), private app/static permissions, the stopped
services, group boundaries, expected private Unix-only Nginx listener and
fixed Laravel upstream, and exact old service configuration. It constructs
an ephemeral test config under a randomized private /run directory, checks
Lua loading and logging using the dedicated unprivileged OS account, and
removes all temporary artifacts. It does not start any service.

Expected marker: STEP12E_CHECK=PASS. This does not mean live acceptance.

## Controlled --apply (only after separate acceptance)

Once checked and reviewed, the operator may explicitly authorize:

    BAMBODIA_QA_LUA_RECOVERY=APPROVE_PRIVATE_QA_REPAIR_ONLY \
      bash SCRIPT --apply

This is a one-time operation. It creates root-private backups of the two
existing QA-only files at:

    /root/Bambodia-PreviewQA-Lua-Recovery-20261112

It updates those files, reloads systemd unit definitions, starts ONLY the
two dedicated QA services, and tests private Unix sockets, shared-www access
denial, private HTTP /up and /login status 200, and blocked private paths
returning 404. Both services remain disabled at boot.

If a failure occurs after modifying installed files, the script stops the
two dedicated QA services, retains the backup and refuses a blind rerun.
No rollback to shared-www FPM permissions is automatic.

## Production release boundary

No public QA DNS, TLS, Cloudflare Tunnel, shared aaPanel changes, or
production deployment. Cloudflare Access must be verified before any
published route. The existing HTTP service has only a private Unix socket;
there is no TCP listener. Preview Visibility withdrawals remain disabled.
PR #44 stays draft until on-host service acceptance. Other pull requests
remain governed by their separate review gates.
