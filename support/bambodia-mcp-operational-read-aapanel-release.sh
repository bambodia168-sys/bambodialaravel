#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 077
REPO=bambodia168-sys/bambodialaravel
HEAD=89ff984c6e7b268225744d66eff68f14d6fd25d1
BASE=0ebd371a5c99e901e5541a99c1671aa5727482cd
SITE=/www/wwwroot/bambodia-staging-qa
PHP=/www/server/php/85/bin/php
CTRL=app/Http/Controllers/Api/BambodiaMcpController.php
HELPER=app/Support/Mcp/OperationalReadTools.php
STAGER=support/bambodia-mcp-stage-operational-tools.py
MODE="${1:---preflight}"
[[ $# -le 1 && ( "$MODE" == --preflight || "$MODE" == --apply ) ]]
[[ "$(id -u)" == 0 && -x "$PHP" && -f "$SITE/artisan" ]]
grep -Eq 'root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public;' /www/server/panel/vhost/nginx/bambodia.com.conf
grep -Eq "^APP_ENV=['\"]?production(['\"]?)$" "$SITE/.env"
exec 9>/run/lock/bambodia-mcp-operational-release.lock
flock -n 9
exec 8>/run/lock/bambodia-account-overview.lock
flock -n 8
WORK="$(mktemp -d /tmp/bambodia-mcp-operational-stage.XXXXXXXX)"
chgrp www "$WORK"
chmod 0750 "$WORK"
BACKUP=""
APPLYING=0
NEW_HELPER=0
finish() {
  rc=$?
  trap - EXIT
  if [[ "$APPLYING" == 1 ]]; then
    echo "ROLLBACK: restoring original MCP controller" >&2
    if [[ "$NEW_HELPER" == 1 ]]; then rm -f -- "$SITE/$HELPER" || rc=1; fi
    tar -C "$SITE" -xpf "$BACKUP/original-code.tar" || { echo "ROLLBACK FAILED: $BACKUP" >&2; rc=1; }
    echo "RETAIN BACKUP=$BACKUP"
  fi
  rm -rf -- "$WORK"
  exit "$rc"
}
trap finish EXIT
fetch() { curl -fLsS --retry 2 --connect-timeout 12 --max-time 90 "$1" -o "$2"; }
echo "=== Bambodia MCP operational read tools release 209: $MODE ==="
API="https://api.github.com/repos/$REPO"
fetch "$API/pulls/209" "$WORK/pr.json"
for run in 46040739620 46040739894 46040739643 46040739452 46040739471; do
  fetch "$API/actions/runs/$run" "$WORK/$run.json"
done
RELEASE="$(python3 - "$WORK" "$HEAD" "$BASE" "$MODE" <<'PY'
import json,pathlib,re,sys
work,head,base,mode=pathlib.Path(sys.argv[1]),*sys.argv[2:]
pr=json.loads((work/'pr.json').read_text())
if pr['head']['sha']!=head or pr['base']['ref']!='main' or (not pr.get('merged') and pr['base']['sha']!=base):
    sys.exit('STOP: approved PR changed')
ready=True
for run in (46040739620,46040739894,46040739643,46040739452,46040739471):
    data=json.loads((work/f'{run}.json').read_text())
    print(f"{data['name']}: {data['status']} / {data['conclusion']}",file=sys.stderr)
    ready=ready and data['head_sha']==head and data['status']=='completed' and data['conclusion']=='success'
merged=pr.get('merged') is True
print(f'All CI successful: {ready}; PR merged: {merged}',file=sys.stderr)
if mode=='--apply' and not (ready and merged):
    sys.exit('STOP: CI or merge gate not satisfied')
release=pr.get('merge_commit_sha') if merged and ready else head
if not isinstance(release,str) or not re.fullmatch(r'[0-9a-f]{40}',release):
    sys.exit('STOP: invalid release SHA')
print(release)
PY
)"
[[ -f "$SITE/$CTRL" && ! -L "$SITE/$CTRL" && -d "$SITE/app/Support/Mcp" && ! -L "$SITE/app/Support/Mcp" ]]
[[ ! -e "$SITE/$HELPER" && ! -L "$SITE/$HELPER" ]] || { echo "STOP: operational module already exists; reconcile first"; exit 1; }
fetch "https://raw.githubusercontent.com/$REPO/$RELEASE/$HELPER" "$WORK/helper.php"
fetch "https://raw.githubusercontent.com/$REPO/$RELEASE/$STAGER" "$WORK/stager.py"
[[ "$(git hash-object "$WORK/helper.php")" == 040bd589cf03c6d6c2d51a2fc42e9fd334ff1311 ]]
[[ "$(git hash-object "$WORK/stager.py")" == f56df5d0a9752480adf5e43be5524bba79b46d1a ]]
cp -- "$SITE/$CTRL" "$WORK/original.php"
OLD="$(git hash-object "$WORK/original.php")"
python3 "$WORK/stager.py" --source "$WORK/original.php" --output "$WORK/candidate.php"
NEW="$(git hash-object "$WORK/candidate.php")"
"$PHP" -l "$WORK/helper.php"
"$PHP" -l "$WORK/candidate.php"
for file in routes/web.php bootstrap/app.php app/Http/Middleware/AuthenticateMcpToken.php app/Support/Mcp/McpAccessGrant.php app/Support/Mcp/BambodiaTools.php app/Support/Mcp/AdministrationTools.php app/Support/Mcp/DraftMediaTools.php config/mcp.php composer.json composer.lock resources/views/layouts/workspace.blade.php resources/views/dashboard/index.blade.php; do
  [[ -f "$SITE/$file" ]]
  printf '%s|%s\n' "$file" "$(git hash-object "$SITE/$file")" >> "$WORK/protected"
done
verify_protected() {
  while IFS='|' read -r file hash; do
    [[ "$(git hash-object "$SITE/$file")" == "$hash" ]] || { echo "STOP: protected file changed: $file" >&2; return 1; }
  done < "$WORK/protected"
}
cat > "$WORK/probe.php" <<'PHP'
<?php
// Offline tool-schema reflection only; never fabricate an authenticated request,
// issue a token, call an operational tool, or mutate a production record.
[$script,$site,$controller,$helper]=$argv;
chdir($site);
require $site.'/vendor/autoload.php';
if ($helper !== '-') require $helper;
require $controller;
$app=require $site.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$ref=new ReflectionClass(App\Http\Controllers\Api\BambodiaMcpController::class);
$object=$ref->newInstanceWithoutConstructor();
$definitions=$ref->getMethod('definitions');
$authorize=$ref->getMethod('authorizeDefinitions');
$result=[];
foreach ([false,true] as $write) {
    $result[$write?'write':'read']=$authorize->invoke($object,$definitions->invoke($object,$write));
}
echo json_encode($result,JSON_THROW_ON_ERROR);
PHP
chgrp www "$WORK/"*.php
chmod 0640 "$WORK/"*.php
runuser -u www -- "$PHP" "$WORK/probe.php" "$SITE" "$WORK/original.php" - > "$WORK/before.json"
runuser -u www -- "$PHP" "$WORK/probe.php" "$SITE" "$WORK/candidate.php" "$WORK/helper.php" > "$WORK/after.json"
cat > "$WORK/verify.py" <<'PY'
import json,pathlib,sys
work=pathlib.Path(sys.argv[1])
before=json.loads((work/'before.json').read_text())
after=json.loads((work/'after.json').read_text())
old_read={'bambodia_overview','bambodia_list','bambodia_get','bambodia_analytics'}
old_write={'bambodia_draft_media','bambodia_update_own_profile','bambodia_manage_site','bambodia_create_draft','bambodia_update_draft','bambodia_delete_draft'}
new={'bambodia_accounts_summary','bambodia_orders_summary','bambodia_review_queue','bambodia_support_summary','bambodia_notifications_summary','bambodia_email_delivery_summary'}
for mode,expected in [('read',old_read),('write',old_read|old_write)]:
    a={r['name']:r for r in before[mode]}
    b={r['name']:r for r in after[mode]}
    if len(a)!=len(before[mode]) or len(b)!=len(after[mode]) or set(a)!=expected or set(b)!=expected|new:
        sys.exit('STOP: unexpected tool inventory in '+mode)
    if any(a[k]!=b[k] for k in a):
        sys.exit('STOP: existing tool schema or authorization changed in '+mode)
    for k in new:
        d=b[k]
        if d.get('annotations',{}).get('readOnlyHint') is not True or d['inputSchema'].get('additionalProperties') is not False:
            sys.exit('STOP: invalid read-only schema '+k)
        security=d.get('_meta',{}).get('securitySchemes',[])
        if security!=[{'type':'oauth2','scopes':['mcp:use']}]:
            sys.exit('STOP: unexpected OAuth scope for '+k)
    print(f'PASS: {mode} inventory {len(a)} -> {len(b)}; existing schemas and scopes unchanged')
PY
python3 "$WORK/verify.py" "$WORK"
verify_protected
if [[ "$MODE" == --preflight ]]; then
  echo "PREFLIGHT=PASS; PRODUCTION_FILES_MODIFIED=0"
  exit 0
fi
[[ "$(git hash-object "$SITE/$CTRL")" == "$OLD" && ! -L "$SITE/$CTRL" && ! -e "$SITE/$HELPER" && ! -L "$SITE/$HELPER" ]]
BACKUP="$(mktemp -d /root/bambodia-mcp-operational-backup.XXXXXXXX)"
tar -C "$SITE" -cpf "$BACKUP/original-code.tar" "$CTRL"
echo "BACKUP=$BACKUP"
APPLYING=1
NEW_HELPER=1
install_atomic() {
  local src="$1" dst="$2" tmp
  tmp="$(mktemp "$(dirname "$dst")/.mcp-operational.XXXXXXXX")"
  install -o www -g www -m 0644 "$src" "$tmp"
  mv -f -- "$tmp" "$dst"
}
install_atomic "$WORK/helper.php" "$SITE/$HELPER"
install_atomic "$WORK/candidate.php" "$SITE/$CTRL"
[[ "$(git hash-object "$SITE/$CTRL")" == "$NEW" && "$(git hash-object "$SITE/$HELPER")" == 040bd589cf03c6d6c2d51a2fc42e9fd334ff1311 ]]
runuser -u www -- "$PHP" "$WORK/probe.php" "$SITE" "$SITE/$CTRL" "$SITE/$HELPER" > "$WORK/after.json"
python3 "$WORK/verify.py" "$WORK"
verify_protected
APPLYING=0
echo "BAMBODIA_MCP_OPERATIONAL_DEPLOYMENT=SUCCESS"
echo "RELEASE=$RELEASE"
echo "BACKUP=$BACKUP"
echo "Expected server tool inventory: 10 read-only / 16 read+write. Authenticated live discovery and execution still required."
