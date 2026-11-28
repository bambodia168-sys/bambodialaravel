#!/usr/bin/env bash
# Phase 4D.2A read-only release preparation. NO merge, migration, apply,
# production writes, database access, restart or rollback execution.
set -Eeuo pipefail
set +x
umask 077
REPO=bambodia168-sys/bambodialaravel
HEAD=f8855a6f7aeaea2086cc566ff9ad056181fea37b
BASE=7b895041c6e8c847616661b2fed58ffcdb8967a5
TREE=fab3c6c0611b8f54955673cd2e110ef23994bcf7
SITE=/www/wwwroot/bambodia-staging-qa
VHOST=/www/server/panel/vhost/nginx/bambodia.com.conf
PHP=/www/server/php/85/bin/php
STAGE=/var/tmp/bambodia-phase4-review.H49cOiGy
MANIFEST=608457a44dcdf87cb5438881d6fb102b151ce8408cd7adcac72637b51e43d434
CTRL=app/Http/Controllers/Api/BambodiaMcpController.php
OLD=832c692c12ef23e3584cc820512619383822c406
NEW=a302fa364f1f52b6320b77688b7595b91894ca13
HARNESS=9a94202d38443257cb26d42561a1183406b53289
fail(){ echo "PHASE4D2A_BLOCKED=$*" >&2; exit 1; }
[[ $# == 1 && "$1" == --prepare ]] || fail PREPARE_ONLY
[[ $(id -u) == 0 && -x "$PHP" ]] || fail ROOT_OR_PHP_REQUIRED
for cmd in git curl python3 sha256sum realpath stat find cmp df; do command -v "$cmd" >/dev/null || fail "MISSING_$cmd"; done
[[ -d "$SITE" && ! -L "$SITE" && $(realpath "$SITE") == "$SITE" && -f "$SITE/artisan" ]] || fail SITE_CHANGED
[[ -f "$VHOST" && ! -L "$VHOST" ]] || fail VHOST_UNSAFE
grep -Eq 'root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public;' "$VHOST" || fail VHOST_CHANGED
[[ -d "$STAGE" && ! -L "$STAGE" && $(stat -c '%u:%a' "$STAGE") == 0:700 ]] || fail STAGE_UNSAFE
[[ -f "$STAGE/manifest.sha256" && ! -L "$STAGE/manifest.sha256" && $(sha256sum "$STAGE/manifest.sha256"|cut -d' ' -f1) == "$MANIFEST" ]] || fail MANIFEST_DRIFT
[[ -z $(find "$STAGE/source" "$STAGE/candidate" "$STAGE/verified" -type l -print -quit) ]] || fail STAGE_SYMLINK
(cd "$STAGE"; find source candidate verified -type f -print0 | sort -z | xargs -0 sha256sum | cmp -s - manifest.sha256) || fail STAGE_INVENTORY_DRIFT
echo "REVIEWED_STAGE_MANIFEST=PASS"
WORK=$(mktemp -d /root/bambodia-phase4d2-preparation.XXXXXXXX)
chmod 700 "$WORK"
echo "RELEASE_PACKAGE=$WORK"
mkdir "$WORK/github" "$WORK/evidence" "$WORK/source" "$WORK/rollback"
api="https://api.github.com/repos/$REPO"
api_get(){ curl -fLsS --retry 2 --connect-timeout 15 --max-time 60 "$api/$1" -o "$2"; }
repo_gate(){
  api_get commits/main "$WORK/github/main.json"
  for pr in 211 212 213; do api_get "pulls/$pr" "$WORK/github/pr-$pr.json"; done
  for sha in bd662887a7408698c2903f8e9baebc723e477336 7f46cbd6f8a86e31efdce42cd3796ca38523a0bc "$HEAD"; do
    api_get "commits/$sha" "$WORK/github/commit-$sha.json"
  done
  for id in 46111479203 46111479202 46111479331 46111479201 46111479209 46113659834 46113659831 46113659832 46113659829 46113659833 46115262086 46115262094 46115262091 46115262121 46115262118; do
    api_get "actions/runs/$id" "$WORK/github/run-$id.json"
  done
  python3 - "$WORK/github" <<'PY'
import json,pathlib,sys
w=pathlib.Path(sys.argv[1])
def read(f): return json.loads((w/f).read_text())
def gate(ok,msg):
    if not ok: raise SystemExit("PHASE4D2A_BLOCKED="+msg)
base='7b895041c6e8c847616661b2fed58ffcdb8967a5'
gate(read('main.json')['sha']==base,'MAIN_DRIFT')
names=['Bambodia Quality','Bambodia MySQL Installer Acceptance','Bambodia Operational Acceptance','Bambodia Browser Emulation Acceptance','Bambodia Isolated Authenticated Journeys']
rows=[
(211,'main','feature/mcp-phase4b-commerce-read-20261128','bd662887a7408698c2903f8e9baebc723e477336','49b7e8380effee8ea91f91adc4ffca8516c7f07d',[46111479203,46111479202,46111479331,46111479201,46111479209]),
(212,'feature/mcp-phase4b-commerce-read-20261128','feature/mcp-phase4c-project-read-20261128','7f46cbd6f8a86e31efdce42cd3796ca38523a0bc','fda3ef2481445a1bdfe69ac168615c1dc3ff6695',[46113659834,46113659831,46113659832,46113659829,46113659833]),
(213,'feature/mcp-phase4c-project-read-20261128','feature/mcp-phase4c-project-write-20261128','f8855a6f7aeaea2086cc566ff9ad056181fea37b','fab3c6c0611b8f54955673cd2e110ef23994bcf7',[46115262086,46115262094,46115262091,46115262121,46115262118])]
for n,base_ref,head_ref,head,tree,runs in rows:
    p=read(f'pr-{n}.json')
    gate(p['state']=='open' and not p['merged'] and p['draft'] and p['mergeable'] is True and p['head']['sha']==head and p['base']['sha']==base and p['base']['ref']==base_ref and p['head']['ref']==head_ref,'PR_DRIFT_'+str(n))
    c=read(f'commit-{head}.json')
    gate(c['sha']==head and c['commit']['tree']['sha']==tree,'TREE_DRIFT_'+str(n))
    for rid,name in zip(runs,names):
        r=read(f'run-{rid}.json')
        gate(r['id']==rid and r['name']==name and r['head_sha']==head and r['event']=='pull_request' and r['status']=='completed' and r['conclusion']=='success','CI_GATE_'+str(rid))
    print(f'PR_{n}_CI=PASS workflows=5')
    base=head
print('STACKED_RELEASE_GATE=PASS workflows=15')
PY
}
repo_gate
blob(){ [[ -f "$1" && ! -L "$1" && $(git hash-object "$1") == "$2" ]] || fail "BLOB_MISMATCH_$1"; }
blob "$STAGE/source/$CTRL" "$OLD"
blob "$STAGE/source/tests/Feature/BambodiaPhase4IsolatedOAuthTest.php" "$HARNESS"
for pair in 'baseline-qa.log|OK (1 test, 108 assertions)' 'candidate-qa.log|OK (18 tests, 1477 assertions)' 'rollback-qa.log|OK (1 test, 106 assertions)'; do
  log=$STAGE/$(echo "$pair"|cut -d'|' -f1)
  summary=$(echo "$pair"|cut -d'|' -f2-)
  [[ -f "$log" && ! -L "$log" && $(stat -c '%u' "$log") == 0 ]] || fail QA_LOG_UNSAFE
  [[ $(grep -Fxc "$summary" "$log") == 1 ]] || fail QA_SUMMARY_MISMATCH
  if grep -Eq '^(FAILURES!|ERRORS!|Tests:.*Failures:)' "$log"; then fail QA_LOG_FAILURE; fi
  printf '%s: %s\n' "$(basename "$log")" "$summary"
  sha256sum "$log" >>"$WORK/evidence/qa-log.sha256"
done
test ! -e /var/tmp/bambodia-phase4-runtime.pUo2fmWH && test ! -L /var/tmp/bambodia-phase4-runtime.pUo2fmWH || fail QA_RUNTIME_REMAINS
echo "ACTUAL_OAUTH_LOG_SUMMARIES=PASS tests=20 assertions=1691"
echo "ISOLATED_RUNTIME_REMOVED=YES"
# Check the complete reviewed source snapshot against live source, including
# nested symlink checks. No Laravel bootstrap or production DB connection.
source_gate(){
  python3 - "$STAGE/source" "$SITE" <<'PY'
import hashlib,pathlib,sys
stage,live=map(pathlib.Path,sys.argv[1:])
n=0
for f in sorted(stage.rglob('*')):
    if not f.is_file(): continue
    r=f.relative_to(stage)
    if r.parts[0]=='tests' or str(r)=='phpunit.xml': continue
    p=live/r
    if not p.is_file() or any(x.is_symlink() for x in [p,*p.parents]):
        raise SystemExit('PHASE4D2A_BLOCKED=SOURCE_PATH_DRIFT_'+str(r))
    if hashlib.sha256(f.read_bytes()).digest()!=hashlib.sha256(p.read_bytes()).digest():
        raise SystemExit('PHASE4D2A_BLOCKED=SOURCE_BYTES_DRIFT_'+str(r))
    n+=1
print('QA_LIVE_SOURCE_FILES_MATCH='+str(n))
PY
  blob "$SITE/$CTRL" "$OLD"
}
source_gate
# A complete allowlist, not a repository checkout.
cat >"$WORK/source-blobs.txt" <<'EOF'
a302fa364f1f52b6320b77688b7595b91894ca13 app/Http/Controllers/Api/BambodiaMcpController.php
a9e5cf8e596769ca0cc90f9ca6ee4721710b9d5c app/Support/Mcp/CommerceReadTools.php
84036ebe85a7c906911a343720dc8b5e43b6e1b2 app/Support/Mcp/ProjectReadTools.php
40159625dff463d6911e9f61cfe542dd61b05497 app/Support/Mcp/ProjectStateVersion.php
7c31cf5acd15669a2fbf5b718d13298fdb34ca4c app/Support/Mcp/ProjectWriteTools.php
982b0ca4816ffe5ae69b1961c34c18fa276bc08f database/migrations/2026_11_28_100000_create_mcp_project_operations.php
EOF
python3 - "$STAGE/candidate" "$WORK/source-blobs.txt" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1]); expected={l.split(' ',1)[1] for l in pathlib.Path(sys.argv[2]).read_text().splitlines()}
actual={str(x.relative_to(p)) for x in p.rglob('*') if x.is_file()}
if actual!=expected: raise SystemExit('PHASE4D2A_BLOCKED=CANDIDATE_FILE_SET_DRIFT')
PY
while read -r expected path; do
  blob "$STAGE/candidate/$path" "$expected"
  if [[ "$path" != "$CTRL" ]]; then
    [[ ! -e "$SITE/$path" && ! -L "$SITE/$path" ]] || fail NEW_FILE_ALREADY_PRESENT
  fi
  mkdir -p "$WORK/source/$(dirname "$path")"
  install -m 600 "$STAGE/candidate/$path" "$WORK/source/$path"
  blob "$WORK/source/$path" "$expected"
  "$PHP" -n -l "$WORK/source/$path"
done <"$WORK/source-blobs.txt"
cp -a -- "$SITE/$CTRL" "$WORK/rollback/controller.original.php"
blob "$WORK/rollback/controller.original.php" "$OLD"
stat -c 'CONTROLLER_OWNER=%u:%g MODE=%a' "$SITE/$CTRL" >"$WORK/rollback/controller-metadata.txt"
protected_capture(){
  (cd "$SITE"
    for f in .env storage/oauth-private.key storage/oauth-public.key bootstrap/cache/config.php routes/web.php bootstrap/app.php app/Providers/AppServiceProvider.php config/mcp.php composer.json composer.lock app/Http/Middleware/AuthenticateMcpToken.php app/Support/Mcp/McpAccessGrant.php app/Support/Mcp/McpResource.php app/Support/Mcp/BambodiaPassportAccessToken.php app/Http/Middleware/RequireMcpOAuthResource.php app/Http/Middleware/EnsureMcpOAuthConsentAdmin.php app/Http/Controllers/Api/BambodiaOAuthRegisterController.php app/Support/Mcp/AdvancedContentTools.php app/Support/Mcp/OperationalReadTools.php; do
      if [[ -e "$f" || -L "$f" ]]; then
        [[ -f "$f" && ! -L "$f" ]] || exit 1
        sha256sum "$f"
      elif [[ "$f" != storage/oauth-private.key && "$f" != storage/oauth-public.key && "$f" != bootstrap/cache/config.php ]]; then exit 1
      else printf '%s absent\n' "$f" >&2; fi
    done
  ) >"$1" 2>"$2" || fail PROTECTED_CAPTURE
}
protected_capture "$WORK/evidence/protected.sha256" "$WORK/evidence/protected-absent.txt"
sha256sum "$VHOST" >"$WORK/evidence/vhost.sha256"
cp "$STAGE/manifest.sha256" "$WORK/evidence/accepted-stage.sha256"
printf '%s\n' "$HEAD" >"$WORK/evidence/candidate-commit.txt"
printf '%s\n' "$TREE" >"$WORK/evidence/candidate-tree.txt"
source_gate
protected_capture "$WORK/protected-final.sha256" "$WORK/protected-final-absent.txt"
cmp -s "$WORK/protected-final.sha256" "$WORK/evidence/protected.sha256" || fail PROTECTED_SOURCE_DRIFT
cmp -s "$WORK/protected-final-absent.txt" "$WORK/evidence/protected-absent.txt" || fail PROTECTED_PRESENCE_DRIFT
sha256sum --check --status "$WORK/evidence/vhost.sha256" || fail VHOST_DRIFT
rm -- "$WORK/protected-final.sha256" "$WORK/protected-final-absent.txt"
echo "PROTECTED_SOURCE_AND_CONFIGURATION_UNCHANGED=PASS"
# Recheck only mutable refs, conserving the public API request quota.
api_get commits/main "$WORK/github/main-final.json"
for pr in 211 212 213; do api_get "pulls/$pr" "$WORK/github/pr-$pr-final.json"; done
python3 - "$WORK/github" <<'PY'
import json,pathlib,sys
w=pathlib.Path(sys.argv[1])
def read(name): return json.loads((w/name).read_text())
if read('main-final.json')['sha']!=read('main.json')['sha']:
    raise SystemExit('PHASE4D2A_BLOCKED=MAIN_DRIFT_DURING_PREPARATION')
for n in (211,212,213):
    a=read(f'pr-{n}.json'); b=read(f'pr-{n}-final.json')
    def state(p): return (p['head']['sha'],p['base']['sha'],p['head']['ref'],p['base']['ref'],p['state'],p['merged'],p['draft'],p['mergeable'])
    if state(a)!=state(b):
        raise SystemExit('PHASE4D2A_BLOCKED=PR_DRIFT_DURING_PREPARATION_'+str(n))
print('FINAL_BRANCH_TIPS_UNCHANGED=PASS')
PY
printf '%s\n' "$HEAD" >"$WORK/PREPARE_PASS"
(cd "$WORK"; find github evidence source rollback -type f -print0 | sort -z | xargs -0 sha256sum > package.sha256; sha256sum source-blobs.txt PREPARE_PASS >> package.sha256; sha256sum --check --status package.sha256)
echo "RELEASE_PACKAGE_MANIFEST_SHA256=$(sha256sum "$WORK/package.sha256"|cut -d' ' -f1)"
echo "EXACT_SOURCE_PACKAGE=PASS files=6"
echo "PHASE4D2A_PREPARATION=PASS"
echo "DATABASE_BACKUP=NOT_STARTED"
echo "PUBLIC_HEALTH_PROBE=DEFERRED_TO_AUTHORIZED_PREFLIGHT"
echo "PRODUCTION_DATABASE_ACCESSED=NO"
echo "PRODUCTION_FILES_MODIFIED=0"
echo "PRODUCTION_DEPLOYMENT=NOT_STARTED"
echo "MERGE=NOT_PERFORMED"
