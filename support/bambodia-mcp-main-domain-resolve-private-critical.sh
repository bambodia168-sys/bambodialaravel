#!/usr/bin/env bash
# Reconcile only the verified, private Bambodia MCP overlay.
# NO production source, database, vhost, .env, keys, or Nginx changes.
set -Eeuo pipefail
set +x
umask 077

[[ "$#" -eq 1 && "$1" == "--resolve" ]] || {
  echo "Usage: bash script --resolve" >&2
  exit 64
}

OVERLAY="/root/Bambodia-MCP-Main-Overlay.ApguAhIY"
STAGE="/root/Bambodia-MCP-Main-Source.tpE9k1zN"
LIVE="/www/wwwroot/bambodia-staging-qa"
VHOST="/www/server/panel/vhost/nginx/bambodia.com.conf"
fail() { echo "MCP_PRIVATE_CRITICAL_RECONCILIATION_BLOCKED=$*" >&2; exit 1; }

[[ "$(id -u)" -eq 0 ]] || fail ROOT_REQUIRED
for bin in python3 git sha256sum stat; do
  command -v "$bin" >/dev/null || fail "MISSING_$bin"
done
for dir in "$OVERLAY" "$STAGE"; do
  [[ -d "$dir" && ! -L "$dir" && "$(stat -c '%u:%a' "$dir")" == "0:700" ]] ||
    fail PRIVATE_WORKSPACE_MISSING_OR_UNSAFE
done
[[ -f "$LIVE/artisan" && -f "$LIVE/storage/app/installed.lock" && ! -L "$LIVE" ]] ||
  fail LIVE_INSTALL_CHANGED
[[ -f "$VHOST" && ! -L "$VHOST" ]] || fail LIVE_VHOST_MISSING
[[ "$(sha256sum "$VHOST" | cut -d' ' -f1)" == "5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116" ]] ||
  fail LIVE_VHOST_CHANGED
[[ "$(git hash-object "$LIVE/composer.json")" == "457992551f4f234ad442d9db5ad73c8b54689e7b" ]] ||
  fail LIVE_COMPOSER_CHANGED
[[ "$(sha256sum "$LIVE/composer.lock" | cut -d' ' -f1)" == "ed0a78f5d3bf3a9bf6490a97d5570751fd8c3be905414d27a38d9036e56fef03" ]] ||
  fail LIVE_LOCK_CHANGED
[[ "$(sha256sum "$OVERLAY/source/composer.lock" | cut -d' ' -f1)" == "4dd3f77f0758b741fb8372e38a94edceb486a42b5bfedcd1f6d41feb601c2ce8" ]] ||
  fail PRIVATE_LOCK_CHANGED
[[ -f "$OVERLAY/source/vendor/autoload.php" ]] || fail PRIVATE_VENDOR_MISSING

python3 - "$OVERLAY" "$STAGE" "$LIVE" <<'PY'
import difflib
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

overlay, stage, live = map(Path, sys.argv[1:])
source, original = overlay / "source", stage / "source"
review = overlay / "review"
route = "routes/web.php"
layout = "resources/views/layouts/workspace.blade.php"
controller = "app/Http/Controllers/Api/BambodiaMcpController.php"
tools = "app/Support/Mcp/BambodiaTools.php"
pins = {
    route: "588447000aa0ae36c48d0eb5f7c7f30a808264d4",
    controller: "e4ab6ce81b02001232ced297dbb401318d4c164b",
    tools: "0fd9df1e07d735b86c47e3434ea57e622e6d8d48",
}
expected_diffs = {controller: (223, 262, 84, {"authorizeDefinitions"}),
                  tools: (242, 261, 44, {"ownedDraftQuery"})}

def fail(reason):
    raise SystemExit("MCP_PRIVATE_CRITICAL_RECONCILIATION_BLOCKED=" + reason)

def safe_file(p):
    if not p.is_file() or p.is_symlink():
        fail("UNSAFE_OR_MISSING_FILE_" + str(p.relative_to(overlay) if p.is_relative_to(overlay) else p))
    return p.read_bytes()

def blob(data):
    return hashlib.sha1(b"blob " + str(len(data)).encode() + b"\0" + data).hexdigest()

def sha(data):
    return hashlib.sha256(data).hexdigest()

# Pin all three MCP source files to the tested draft branch and the prepared stage.
staged = {}
for rel, pin in pins.items():
    staged[rel] = safe_file(original / rel)
    if blob(staged[rel]) != pin or (rel != route and safe_file(source / rel) != staged[rel]):
        fail("STAGED_SOURCE_DRIFT_" + rel)
    if safe_file(review / "live-critical" / rel) != safe_file(live / rel):
        fail("LIVE_CRITICAL_CHANGED_" + rel)

if blob(safe_file(review / "base" / route)) != "8d609196893732d2e7a4d064f3209d58cd532114":
    fail("GITHUB_MAIN_ROUTE_BASE_DRIFT")
if sha(safe_file(source / layout)) != "01154b97c1418441ddf0fcf60e50e81bbf5208a9265ae428550c2c7a2749beda":
    fail("PRIVATE_LAYOUT_NOT_RECONCILED")
if sha(safe_file(review / "live-critical" / layout)) != "91f74a9e6f01de6b8c5f58fa9c137245d72216c2bac588d943490059588c7164":
    fail("LIVE_LAYOUT_REVIEW_DRIFT")
if safe_file(review / "live-critical" / layout) != safe_file(live / layout):
    fail("LIVE_LAYOUT_CHANGED")

# Replay the actual three-way merge from immutable inputs; require the exact
# one-conflict artifact previously reviewed by the operator.
cmd = ["git", "merge-file", "-p", "--diff3", "-L", "LIVE",
       "-L", "ORIGINAL_MAIN", "-L", "MCP_RELEASE",
       str(review / "live-critical" / route),
       str(review / "base" / route), str(original / route)]
replay = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False)
if replay.returncode != 1 or replay.stdout != safe_file(review / "merge-conflicts" / route):
    fail("ROUTE_CONFLICT_ARTIFACT_DRIFT")
lines = replay.stdout.splitlines(keepends=True)
markers = [b"<<<<<<< LIVE\n", b"||||||| ORIGINAL_MAIN\n", b"=======\n", b">>>>>>> MCP_RELEASE\n"]
indices = []
for marker in markers:
    found = [i for i, line in enumerate(lines) if line == marker]
    if len(found) != 1:
        fail("ROUTE_CONFLICT_MARKER_COUNT_INVALID")
    indices.append(found[0])
a, b, c, d = indices
if not a < b < c < d:
    fail("ROUTE_CONFLICT_MARKERS_OUT_OF_ORDER")
legacy = b"".join(lines[a + 1:b])
release = b"".join(lines[c + 1:d])
prefix, suffix = b"".join(lines[:a]), b"".join(lines[d + 1:])
legacy_route = b"->middleware(['api.token:mcp.read', 'throttle:30,1'])"
oauth_route = b"->middleware(['mcp.auth', 'throttle:30,1'])"
if (legacy.count(b"Route::post('/mcp'") != 1 or legacy_route not in legacy or
        release.count(b"Route::post('/mcp'") != 1 or oauth_route not in release or
        b"Mcp::oauthRoutes(registration: false)" not in release or
        release.count(b"oauth-protected-resource") != 2 or
        b"Route::post('/oauth/register'" not in release):
    fail("ROUTE_AUTHENTICATION_OR_DISCOVERY_UNEXPECTED")

# A git three-way merge may also include nonconflicting whitespace edits
# outside the conflict. Such a reconstruction is not byte-identical to LIVE
# and MUST NOT be used to overwrite the live-first private source.
# Extract only the pinned, reviewed MCP release block, then replace precisely
# the existing legacy MCP route in the exact live route snapshot.
live_route = safe_file(review / "live-critical" / route)
main_route = safe_file(review / "base" / route)
new_mcp_block = release.strip(b"\n")
old_mcp_block = (
    b"// Stateless, admin-token-authenticated MCP endpoint. A browser session is never sufficient\n"
    b"// for access. Only this bearer-token route is exempt from the web CSRF middleware.\n"
    b"Route::post('/mcp', [\\App\\Http\\Controllers\\Api\\BambodiaMcpController::class, 'handle'])\n"
    b"    ->middleware(['api.token:mcp.read', 'throttle:30,1'])\n"
    b"    ->withoutMiddleware([\\Illuminate\\Foundation\\Http\\Middleware\\PreventRequestForgery::class])\n"
    b"    ->name('mcp');"
)
# Confirm the conflict is specifically the legacy route and the new OAuth
# routes. Do not proceed if either side has additional hidden route edits.
if (old_mcp_block not in legacy or
        legacy.strip(b"\n") != old_mcp_block or
        not new_mcp_block.startswith(b"// Native main-domain MCP routes; registered outside locale cloning.") or
        new_mcp_block not in staged[route] or
        staged[route].count(new_mcp_block) != 1 or
        old_mcp_block in staged[route]):
    fail("MCP_CONFLICT_SIDES_NOT_EXACT_EXPECTED_BLOCKS")
if (live_route.count(old_mcp_block) != 1 or
        live_route.count(b"Route::post('/mcp'") != 1 or
        old_mcp_block in main_route or
        new_mcp_block in live_route or
        b"Mcp::oauthRoutes(registration: false)" in live_route or
        b"oauth-protected-resource" in live_route):
    fail("LIVE_LEGACY_ROUTE_NOT_UNIQUELY_IDENTIFIED")
if (live_route.index(old_mcp_block) <
        live_route.index(b"$unprefixedWebRoutes = Route::getRoutes()->getRoutes();")):
    fail("LEGACY_ROUTE_POSITION_NOT_AFTER_LOCALE_CLONING")
if live_route.count(b"$unprefixedWebRoutes = Route::getRoutes()->getRoutes();") != 1:
    fail("LIVE_LOCALE_CLONING_ANCHOR_CHANGED")

merged = live_route.replace(old_mcp_block, new_mcp_block, 1)
# The reverse substitution is an exact byte-for-byte restoration of LIVE.
# This is stricter than relying on a git-generated conflict prefix/suffix:
# every byte outside the single existing MCP block is identical.
if merged.replace(new_mcp_block, old_mcp_block, 1) != live_route:
    fail("UNRELATED_LIVE_ROUTE_BYTES_NOT_PRESERVED")
if (merged.count(b"Route::post('/mcp'") != 1 or
        merged.count(b"->middleware(['mcp.auth', 'throttle:30,1'])") != 1 or
        merged.count(b"Mcp::oauthRoutes(registration: false)") != 1 or
        any(marker in merged for marker in markers) or
        b"api.token:mcp.read" in merged or
        b"/.well-known/oauth-protected-resource/mcp" not in merged or
        b"Route::post('/oauth/register'" not in merged):
    fail("RESOLVED_ROUTE_INVARIANT_FAILED")

# These were live-first exceptions, not unreviewed replacements. Verify the
# entire source diff statistics, all existing methods and the new security gates.
for rel, (n_live, n_stage, n_diff, new_methods) in expected_diffs.items():
    before = safe_file(review / "live-critical" / rel).decode().splitlines()
    after = staged[rel].decode().splitlines()
    diff = list(difflib.unified_diff(before, after, fromfile="LIVE/" + rel,
                                     tofile="MCP/" + rel, n=2, lineterm=""))
    methods = lambda xs: set(re.findall(r"\bfunction\s+([A-Za-z_]\w*)\s*\(", "\n".join(xs)))
    if ((len(before), len(after), len(diff)) != (n_live, n_stage, n_diff) or
            methods(before) - methods(after) or methods(after) - methods(before) != new_methods):
        fail("CLASS_DIFF_CHANGED_" + rel)

c = staged[controller]
t = staged[tools]
if (b"config('mcp.production_enabled', false)" not in c or
        b"config('mcp.production_writes_enabled', false)" not in c or
        b"$token instanceof McpAccessGrant" not in c or
        b"'securitySchemes'" not in c or
        b"private function authorizeDefinitions(" not in c or
        t.count(b"ownedDraftQuery($entity, $actor)->lockForUpdate()") != 2 or
        b"'job' => $query->where('client_id', $actor->id)" not in t or
        b"'post', 'page' => $query->where('author_id', $actor->id)" not in t or
        b"'product', 'project', 'service', 'freelance' => $query->where('user_id', $actor->id)" not in t):
    fail("MCP_CONTROLLER_OR_OWNERSHIP_GATE_MISSING")

# A live-only routing edit is possible. Apply only the resolved route file in
# the private workspace after proving that every non-MCP live byte survives.
existing_route = safe_file(source / route)
if existing_route not in (staged[route], merged):
    fail("PRIVATE_ROUTE_CHANGED_OUTSIDE_THIS_RECONCILIATION")
if existing_route != merged:
    target = source / route
    fd, temp = tempfile.mkstemp(prefix=".mcp-private-route-", dir=target.parent)
    try:
        os.fchmod(fd, target.stat().st_mode & 0o777)
        with os.fdopen(fd, "wb") as out:
            out.write(merged)
        os.replace(temp, target)
    finally:
        if os.path.exists(temp):
            os.unlink(temp)
if safe_file(source / route) != merged:
    fail("PRIVATE_ROUTE_WRITE_VERIFICATION_FAILED")

# Static PHP syntax check only; no artisan execution, database connection,
# OAuth key creation or production configuration changes.
php = Path("/www/server/php/85/bin/php")
if not php.is_file():
    fail("PHP_85_CLI_NOT_FOUND")
for rel in (route, controller, tools):
    lint = subprocess.run([str(php), "-l", str(source / rel)],
                          stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                          check=False)
    if lint.returncode != 0:
        fail("PHP_LINT_FAILED_" + rel)

report = {
    "route_conflicts_resolved": 1,
    "workspace_layout": "LIVE_BYTES_PRESERVED_WITH_PAGE_STYLESHEET_HOOK",
    "routes": "EXACT_LEGACY_MCP_BLOCK_REPLACED; ALL_OTHER_LIVE_BYTES_PRESERVED",
    "controller": "EXACT_PINNED_MCP_RELEASE_WITH_OAUTH_AND_WRITE_GATES",
    "draft_tools": "EXACT_PINNED_MCP_RELEASE_WITH_OWNERSHIP_ENFORCEMENT",
    "source_files_changed": int(merged != staged[route]),
    "private_overlay_only": True,
    "production_activation": False,
    "production_deployment": False,
    "route_sha256": sha(merged),
    "controller_sha256": sha(c),
    "draft_tools_sha256": sha(t),
}
dest = review / "mcp-private-critical-reconciliation.json"
if dest.is_symlink():
    fail("UNSAFE_REPORT_PATH")
serialized = (json.dumps(report, indent=2, sort_keys=True) + "\n").encode()
if dest.exists() and safe_file(dest) != serialized:
    fail("EXISTING_REPORT_MISMATCH")
if not dest.exists():
    fd, temp = tempfile.mkstemp(prefix=".mcp-reconciliation-", dir=review)
    try:
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, "wb") as out:
            out.write(serialized)
        os.replace(temp, dest)
    finally:
        if os.path.exists(temp):
            os.unlink(temp)

print("ROUTES_RECONCILIATION=VERIFIED_EXACT_LIVE_PRESERVATION")
print("CONFLICT_BLOCKS_RESOLVED=1")
print("CONTROLLER_RECONCILIATION=VERIFIED_EXACT_PINNED_RELEASE")
print("DRAFT_OWNERSHIP_ENFORCEMENT=VERIFIED")
print("LIVE_ROUTE_NON_MCP_BYTES=PRESERVED")
print("ROUTE_MERGE_STRATEGY=EXACT_LIVE_BLOCK_REPLACEMENT")
print("MCP_PRIVATE_LAYOUT=VERIFIED")
print("MCP_PRIVATE_RECONCILIATION_REPORT=" + str(dest))
print("PRIVATE_PHP_SYNTAX=PASS")
print("PRIVATE_ROUTE_RECONCILIATION_WRITE=" + ("APPLIED" if merged != staged[route] else "ALREADY_IDENTICAL"))
print("MCP_PRIVATE_RECONCILIATION=PASS")
print("DEPLOYABLE_RELEASE=NO")
print("PRODUCTION_SOURCE_ENV_KEYS_MYSQL=UNCHANGED")
print("PRODUCTION_NGINX=UNCHANGED")
print("MCP_PRODUCTION_ACTIVATION=NOT_STARTED")
PY
