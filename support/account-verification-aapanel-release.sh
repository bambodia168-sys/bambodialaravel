#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 077
REPO="bambodia168-sys/bambodialaravel"
HEAD="88a1c2cb2a5f44aa255f65d4b08cd4f2ddc1ac38"
SITE="/www/wwwroot/bambodia-staging-qa"
PHP="/www/server/php/85/bin/php"
MODE=--preflight
if [[ $# == 1 ]]; then MODE="$1"; fi
[[ $# -le 1 && ( "$MODE" == "--preflight" || "$MODE" == "--apply" ) ]]
[[ "$(id -u)" == 0 ]]
for cmd in curl git python3 tar flock runuser install; do command -v "$cmd" >/dev/null; done
echo "=== Account verification release 202: $MODE ==="
test -f "$SITE/artisan"
test -f "$SITE/vendor/autoload.php"
test -x "$PHP"
test ! -L "$SITE"
grep -Eq 'root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public;' /www/server/panel/vhost/nginx/bambodia.com.conf
grep -Eq "^APP_ENV=['\"]?production(['\"]?)$" "$SITE/.env"
exec 9>/run/lock/bambodia-account-overview.lock
flock -n 9
exec 8>/run/lock/bambodia-creator-performance.lock
flock -n 8
WORK="$(mktemp -d /root/bambodia-verification-stage.XXXXXXXX)"
BACKUP=""
APPLYING=0
finish() {
  code=$?
  trap - EXIT
  if [[ "$APPLYING" == 1 ]]; then
    echo "ROLLBACK: restoring original files" >&2
    while IFS= read -r path; do rm -f -- "$SITE/$path" || true; done < "$WORK/new"
    tar -C "$SITE" -xpf "$BACKUP/original-code.tar" || true
    tac "$WORK/created-dirs" | while IFS= read -r dir; do rmdir -- "$SITE/$dir" || true; done
    (cd "$SITE" && runuser -u www -- "$PHP" artisan route:clear --no-ansi) || true
    if [[ "$ROUTE_CACHED" == YES ]]; then (cd "$SITE" && runuser -u www -- "$PHP" artisan route:cache --no-ansi) || true; fi
    (cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi) || true
    echo "RETAIN BACKUP=$BACKUP"
  fi
  rm -rf -- "$WORK"
  exit "$code"
}
trap finish EXIT
fetch() { curl -fLsS --retry 2 --connect-timeout 12 --max-time 90 "$1" -o "$2"; }
API="https://api.github.com/repos/$REPO"
fetch "$API/pulls/202" "$WORK/pr.json"
for run in 45962329137 45962329084 45962329052 45962329091 45962329072; do
  fetch "$API/actions/runs/$run" "$WORK/$run.json"
done
RELEASE="$(python3 - "$WORK" "$HEAD" "$MODE" <<'PY'
import json, pathlib, re, sys
work, head, mode = pathlib.Path(sys.argv[1]), sys.argv[2], sys.argv[3]
pr = json.loads((work/'pr.json').read_text())
if pr['head']['sha'] != head or pr['base']['ref'] != 'main':
    sys.exit('STOP: pull request source changed')
ready = True
for run in (45962329137,45962329084,45962329052,45962329091,45962329072):
    data=json.loads((work/f'{run}.json').read_text())
    print(f"{data['name']}: {data['status']} / {data['conclusion']}",file=sys.stderr)
    if data['head_sha'] != head or data['status'] != 'completed' or data['conclusion'] != 'success':
        ready=False
merged = pr.get('merged') is True
print(f'All CI successful: {ready}; PR merged: {merged}',file=sys.stderr)
if mode == '--apply' and not (ready and merged):
    sys.exit('STOP: CI or merge gate not satisfied. No production files changed.')
release=pr.get('merge_commit_sha') if merged and ready else head
if not isinstance(release,str) or not re.fullmatch(r'[0-9a-f]{40}',release):
    sys.exit('STOP: Invalid release SHA')
print(release)
PY
)"
[[ "$RELEASE" == "13ecf15b055a541608a12fea1eafe21e49544a0e" ]]
mkdir -p "$WORK/target"
touch "$WORK/new" "$WORK/previous" "$WORK/changed" "$WORK/observed" "$WORK/created-dirs"
cat > "$WORK/manifest" <<'MANIFEST'
app/Http/Controllers/Auth/EmailVerificationController.php|NEW|4ab611ef6c44c9b8f48807971b77f42bfe9f7104
resources/views/auth/verify-email.blade.php|NEW|da14592867483a5bf161751fecf936168e3f90d9
resources/views/auth/email-verified.blade.php|NEW|70d78aed91e19d4b0ce631e2059a30e06d020d13
public/css/account-email-verification.css|NEW|6b5f8d52777006f1ad8f1f5f0cd17f35d07050cb
app/Http/Controllers/CommerceCartController.php|e004935f525b1671b4477da9d9fed6b6656b4909|c78017abb86d77c9a9661555d0c30483025cac57
app/Notifications/CheckoutVerification.php|7d96c52658216ce1cad5d6dac69e38ae71ec1e11|aee9c8ddb8face00e9fd70ab1afe51bd31f3ecd1
app/Http/Controllers/Auth/AuthController.php|25d72b2c4aa0470176e11756018c8260f727f5f3|96f0994d93d165fc1194b2b181899b25781374bc
app/Http/Controllers/AccountNotificationController.php|ff559dc2dc8cca537fe3a362bb6f547af4e874d4|0149fb089a15efd7b18c94100510f774ecd0adde
app/Http/Controllers/CommerceOrderController.php|d6c9a27087ffb1e6b51f81cd109c9dd8853f77ed|8c2a76edb2221dac0aeb47b406c1dcf70ed32a0f
app/Support/EmailTemplates.php|1635cbd04700eaaa652f2ade9d6463507ecc47c5|82db3305c474b8860a3ecad4ca81ec784db7e2fb
app/Support/SubmissionNotifications.php|7e85471e17da67953a9031bb03d31ba2031f4e8d|7b527c3b6e8d3a501f287efd9547643615fc844c
resources/views/account/security.blade.php|4eb0fbf195b4ca05fc9d25c578465f5bdc7a79a2|3e0ba32646f1408d53d5c57823c796916e6ddb63
resources/views/commerce/cart.blade.php|a847270db9177c82d7a3b98f4aaf2ab4c3c7f8ef|630b5a25e14006e5b2447ad66ab30e031302ceff
resources/views/commerce/verify-email.blade.php|b5ea4564ab14d04865d656d0ebbf673e17fd1baa|c39b7f8583441e22a0b5ebb689347630f159daf2
routes/web.php|b2710d815ac31f8157d8deece57126ac898e69ac|6e52a7a139ad4ce84f66c3a451c461d5242e98cb
MANIFEST
while IFS='|' read -r path old new; do
  target="$SITE/$path"
  [[ -d "$(dirname "$target")" && ! -L "$(dirname "$target")" ]]
  [[ ! -L "$target" ]]
  mkdir -p "$WORK/target/$(dirname "$path")"
  fetch "https://raw.githubusercontent.com/$REPO/$RELEASE/$path" "$WORK/target/$path"
  [[ "$(git hash-object "$WORK/target/$path")" == "$new" ]]
  if [[ "$path" == routes/web.php ]]; then
    fetch "https://raw.githubusercontent.com/$REPO/5737a8cab2b8c59c4abf530fdf2ab5f2c5401185/routes/web.php" "$WORK/routes.base"
    [[ "$(git hash-object "$WORK/routes.base")" == "$old" ]]
    python3 - "$WORK/routes.base" "$WORK/target/$path" "$target" <<'PYROUTE'
import pathlib,sys
base,approved,live=map(pathlib.Path,sys.argv[1:])
b,a,l=base.read_bytes(),approved.read_bytes(),live.read_bytes()
anchor=b"use App\\Http\\Controllers\\Auth\\AuthController;\n"
imported=anchor+b"use App\\Http\\Controllers\\Auth\\EmailVerificationController;\n"
prefix=b"Route::middleware('auth')->prefix('checkout')->name('checkout.')->group(function (): void {"
extra=a[a.index(b'// Account email verification is independent'):a.index(prefix)]
old=b"->middleware('throttle:3,1')->name('verification.send');"
new=b"->middleware('throttle:3,1,email-verification:')->block()->name('verification.send');"
def patch(data):
    for before,after in ((anchor,imported),(prefix,extra+prefix),(old,new)):
        if data.count(before)!=1: sys.exit("STOP: route compatibility anchor missing or ambiguous")
        data=data.replace(before,after,1)
    return data
if patch(b)!=a: sys.exit("STOP: reviewed route delta differs from expected")
if l.count(imported)==1 and l.count(extra+prefix)==1 and l.count(new)==1:
    for before,after in ((imported,anchor),(extra+prefix,prefix),(new,old)):
        l=l.replace(before,after,1)
approved.write_bytes(patch(l))
print("PASS: exact approved route changes; unrelated production routes preserved")
PYROUTE
    old="$(git hash-object "$target")"
    new="$(git hash-object "$WORK/target/$path")"
  fi
  printf '%s|%s|%s\n' "$path" "$old" "$new" >> "$WORK/effective-manifest"
  if [[ -f "$target" ]]; then
    actual="$(git hash-object "$target")"
    if [[ "$actual" == "$new" ]]; then
      echo "ALREADY INSTALLED: $path"
      continue
    fi
    [[ "$actual" == "$old" ]] || { echo "STOP: incompatible live file $path ($actual)" >&2; exit 1; }
    echo "$path" >> "$WORK/previous"
  else
    [[ "$old" == NEW && ! -e "$target" ]]
    actual=NEW
    echo "$path" >> "$WORK/new"
  fi
  echo "$path" >> "$WORK/changed"
  printf '%s|%s\n' "$path" "$actual" >> "$WORK/observed"
  echo "PASS: compatible $path"
done < "$WORK/manifest"
mv "$WORK/effective-manifest" "$WORK/manifest"
while IFS='|' read -r path old new; do
  if [[ "$path" == *.php && "$path" != *.blade.php ]]; then "$PHP" -l "$WORK/target/$path"; fi
done < "$WORK/manifest"
echo "PASS: approved PHP syntax"
for file in app/Http/Controllers/Creator/ListingController.php app/Http/Controllers/Creator/ProductController.php app/Http/Controllers/BuilderController.php app/Http/Controllers/JobBoardController.php; do
  grep -Fq 'SubmissionNotifications::send(' "$SITE/$file" || { echo "STOP: live submission handler missing notification dispatch: $file" >&2; exit 1; }
  echo "PASS: submission notification call present: $file"
done
(cd "$SITE" && runuser -u www -- "$PHP" -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo "MAIL_TRANSPORT_CONFIGURED=".(App\Support\EmailOutbox::deliveryReady()?"YES":"NO").PHP_EOL;
foreach (["marketplace.submission.received","marketplace.review.requested"] as $event) {
    $override=App\Models\EmailTemplate::query()->find($event);
    echo $event." saved_template_override=".($override && $override->enabled !== null ? ($override->enabled ? "enabled":"disabled"):"default").PHP_EOL;
    echo $event." last_7_days_bell_count=".App\Models\AccountNotification::query()->where("event",$event)->where("created_at",">=",now()->subDays(7))->count().PHP_EOL;
    $counts=App\Models\EmailDelivery::query()->where("event",$event)->where("created_at",">=",now()->subDays(7))->selectRaw("status, COUNT(*) as total")->groupBy("status")->pluck("total","status");
    foreach($counts as $status=>$total) echo "delivery_".$status."=".$total.PHP_EOL;
}
')
(cd "$SITE" && runuser -u www -- "$PHP" artisan schedule:list --no-ansi) > "$WORK/schedule"
grep -F 'mail:deliver-platform' "$WORK/schedule" || echo "WARNING: email delivery task not found in schedule"
(cd "$SITE" && runuser -u www -- "$PHP" artisan route:list --json) > "$WORK/routes.before.json"
cat > "$WORK/protected" <<'PROTECTED'
resources/views/layouts/workspace.blade.php|8e19e8eec7b6ae97c3c4889c3f4235d2d628bd93
public/css/workspace-glass-sidebar.css|968442d116189eaa0127a2b1a9a91799d1083c91
public/js/workspace-glass-sidebar.js|3f778ab237840e763dea7a78750e5edefeb56aae
resources/views/account/studio.blade.php|eab0ca6accc64a81b88df708ba312c62814a4344
resources/views/account/partials/studio-performance.blade.php|7c5f314b955365a76215e11007ddac38e9c89fd8
public/css/creator-studio-analytics.css|c6b6df3dab0ba9843cf4a9fb0737a7d04dc943ec
public/js/creator-studio-analytics.js|ac2c82e555319ee89fd0e12845944fdf9558a6c9
PROTECTED
# Preserve live deployment-specific controller and route variants as found.
for file in app/Http/Controllers/AccountController.php app/Http/Controllers/DeveloperController.php app/Http/Controllers/Dashboard/DashboardController.php app/Support/UserManagementAccess.php app/Support/MarketplaceManagement.php public/css/customer-account-overview.css public/css/role-dashboard-overview.css public/js/role-dashboard-overview.js resources/views/account/index.blade.php resources/views/dashboard/index.blade.php resources/views/developer/index.blade.php app/Http/Controllers/Creator/ListingController.php app/Http/Controllers/Creator/ProductController.php app/Http/Controllers/BuilderController.php app/Http/Controllers/JobBoardController.php routes/console.php; do
  test -f "$SITE/$file"
  printf '%s|%s\n' "$file" "$(git hash-object "$SITE/$file")" >> "$WORK/protected"
done
verify_protected() {
  while IFS='|' read -r file hash; do
    [[ "$(git hash-object "$SITE/$file")" == "$hash" ]] || { echo "STOP: protected file differs: $file" >&2; return 1; }
  done < "$WORK/protected"
}
verify_protected
echo "PASS: Creator Studio, existing dashboards, shared V4 workspace and submission entry points intact"
if [[ "$MODE" == "--preflight" ]]; then
  echo "PREFLIGHT=PASS (no live files changed; CI and merge still required for apply)"
  exit 0
fi
if [[ ! -s "$WORK/changed" ]]; then
  echo "BAMBODIA_VERIFICATION_DEPLOYMENT=ALREADY_INSTALLED"
  exit 0
fi
BACKUP="$(mktemp -d /root/bambodia-verification-backup.XXXXXXXX)"
tar -C "$SITE" -cpf "$BACKUP/original-code.tar" -T "$WORK/previous"
cp "$WORK/new" "$BACKUP/new-files"
echo "BACKUP=$BACKUP"
while IFS='|' read -r path actual; do
  if [[ "$actual" == NEW ]]; then
    [[ ! -e "$SITE/$path" && ! -L "$SITE/$path" ]]
  else
    [[ ! -L "$SITE/$path" && "$(git hash-object "$SITE/$path")" == "$actual" ]]
  fi
done < "$WORK/observed"
ROUTE_CACHED="$(cd "$SITE" && runuser -u www -- "$PHP" -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; echo $app->routesAreCached()?"YES":"NO";')"
APPLYING=1
while IFS= read -r path; do
  tmp="$(mktemp "$(dirname "$SITE/$path")/.reference-dashboard.XXXXXXXX")"
  install -o www -g www -m 0644 "$WORK/target/$path" "$tmp"
  mv -f -- "$tmp" "$SITE/$path"
  echo "INSTALLED: $path"
done < "$WORK/changed"
(cd "$SITE" && runuser -u www -- "$PHP" artisan route:clear --no-ansi)
if [[ "$ROUTE_CACHED" == YES ]]; then (cd "$SITE" && runuser -u www -- "$PHP" artisan route:cache --no-ansi); fi
(cd "$SITE" && runuser -u www -- "$PHP" artisan view:clear --no-ansi)
(cd "$SITE" && runuser -u www -- "$PHP" artisan route:list --json) > "$WORK/routes.after.json"
python3 - "$WORK/routes.before.json" "$WORK/routes.after.json" <<'PY'
import json,sys
before,after=[json.load(open(p)) for p in sys.argv[1:]]
key=lambda r:(r.get("domain"),r["method"],r["uri"],r.get("name"),r.get("action"))
if not set(map(key,before)).issubset(set(map(key,after))):
    sys.exit("STOP: existing route missing or action changed")
names={r.get("name") for r in after}
required={"verification.notice","verification.send","verification.verify","verification.verified","checkout.verification","checkout.verify","developer.mcp.index","developer.oauth.index"}
if not required.issubset(names): sys.exit("STOP: missing verification or integration routes")
print("PASS: new account verification routes and all existing route identities retained")
PY
while IFS='|' read -r path old new; do
  [[ "$(git hash-object "$SITE/$path")" == "$new" ]]
done < "$WORK/manifest"
verify_protected
echo "PASS: Creator Studio, existing dashboards, shared V4 workspace and submission entry points unchanged"
for asset in css/account-email-verification.css; do
  curl -4 -fsS --connect-timeout 10 --max-time 25 --resolve bambodia.com:443:127.0.0.1 "https://bambodia.com/$asset?v=1" -o "$WORK/live.asset"
  [[ "$(git hash-object "$WORK/live.asset")" == "$(git hash-object "$SITE/public/$asset")" ]]
  echo "PASS: live $asset matches approved release"
done
APPLYING=0
echo "BAMBODIA_VERIFICATION_DEPLOYMENT=SUCCESS"
echo "RELEASE=$RELEASE"
echo "BACKUP=$BACKUP"
echo "Approved email verification and notification changes installed; unrelated routes and dashboards preserved; no migrations. SMTP delivery and real submission notification receipt require production acceptance."
