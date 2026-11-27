#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 077
REPO=bambodia168-sys/bambodialaravel
HEAD=9363182c53cb82d4d43a184875ad1545c51354f3
BASE=13ecf15b055a541608a12fea1eafe21e49544a0e
SITE=/www/wwwroot/bambodia-staging-qa
PHP=/www/server/php/85/bin/php
FILE=app/Support/SubmissionNotifications.php
OLD=7b527c3b6e8d3a501f287efd9547643615fc844c
NEW=f451d9d2c6fe66e21381663178b0cfd5fd9ef46a
MODE=--preflight
if [[ $# == 1 ]]; then MODE="$1"; fi
[[ $# -le 1 && ( "$MODE" == --preflight || "$MODE" == --apply ) ]]
[[ "$(id -u)" == 0 ]]
[[ -f "$SITE/artisan" && -x "$PHP" && ! -L "$SITE" ]]
grep -Eq 'root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public;' /www/server/panel/vhost/nginx/bambodia.com.conf
grep -Eq "^APP_ENV=['\"]?production(['\"]?)$" "$SITE/.env"
exec 9>/run/lock/bambodia-account-overview.lock
flock -n 9
exec 8>/run/lock/bambodia-creator-performance.lock
flock -n 8
WORK="$(mktemp -d /root/bambodia-review-notice-stage.XXXXXXXX)"
BACKUP=""
APPLYING=0
finish() {
  code=$?
  trap - EXIT
  if [[ "$APPLYING" == 1 ]]; then
    echo "ROLLBACK: restoring original notification helper" >&2
    tar -C "$SITE" -xpf "$BACKUP/original-code.tar" || { echo "ROLLBACK FAILED: $BACKUP" >&2; code=1; }
  fi
  rm -rf -- "$WORK"
  exit "$code"
}
trap finish EXIT
fetch() { curl -fLsS --retry 2 --connect-timeout 12 --max-time 90 "$1" -o "$2"; }
echo "=== Administrator review notification release 203: $MODE ==="
API="https://api.github.com/repos/$REPO"
fetch "$API/pulls/203" "$WORK/pr.json"
for run in 45967286066 45967286074 45967286100 45967286109 45967286144; do
  fetch "$API/actions/runs/$run" "$WORK/$run.json"
done
RELEASE="$(python3 - "$WORK" "$HEAD" "$MODE" <<'PY'
import pathlib,json,re,sys
work,head,mode=pathlib.Path(sys.argv[1]),sys.argv[2],sys.argv[3]
pr=json.loads((work/'pr.json').read_text())
if pr['head']['sha']!=head or pr['base']['ref']!='main':
    sys.exit('STOP: reviewed PR changed')
ready=True
for run in (45967286066,45967286074,45967286100,45967286109,45967286144):
    data=json.loads((work/f'{run}.json').read_text())
    print(f"{data['name']}: {data['status']} / {data['conclusion']}",file=sys.stderr)
    ready=ready and data['head_sha']==head and data['status']=='completed' and data['conclusion']=='success'
merged=pr.get('merged') is True
print(f"All CI successful: {ready}; PR merged: {merged}",file=sys.stderr)
if mode=='--apply' and not (ready and merged):
    sys.exit('STOP: CI or merge gate not satisfied; no files installed')
release=pr.get('merge_commit_sha') if ready and merged else head
if not isinstance(release,str) or not re.fullmatch(r'[0-9a-f]{40}',release):
    sys.exit('STOP: invalid release SHA')
print(release)
PY
)"
[[ -d "$SITE/app/Support" && ! -L "$SITE/app/Support" && -f "$SITE/$FILE" && ! -L "$SITE/$FILE" ]]
fetch "https://raw.githubusercontent.com/$REPO/$RELEASE/$FILE" "$WORK/approved.php"
[[ "$(git hash-object "$WORK/approved.php")" == "$NEW" ]]
LIVE="$(git hash-object "$SITE/$FILE")"
[[ "$LIVE" == "$OLD" || "$LIVE" == "$NEW" ]] || { echo "STOP: incompatible live notification helper: $LIVE"; exit 1; }
"$PHP" -l "$WORK/approved.php"
echo "PASS: release helper verified"
for file in app/Models/AccountNotification.php app/Support/EmailOutbox.php app/Support/EmailTemplates.php; do
  [[ -f "$SITE/$file" ]]
  printf 'LIVE_BLOB %s %s\n' "$file" "$(git hash-object "$SITE/$file")"
done
if grep -Fq 'EmailOutbox::queue(' "$SITE/app/Models/AccountNotification.php"; then
  echo "NOTIFICATION_EMAIL_QUEUE_CALL=PRESENT"
else
  echo "NOTIFICATION_EMAIL_QUEUE_CALL=MISSING"
fi
(cd "$SITE" && runuser -u www -- "$PHP" -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo "MAIL_TRANSPORT_CONFIGURED=".(App\Support\EmailOutbox::deliveryReady()?"YES":"NO").PHP_EOL;
$reviewers=App\Models\User::query()->whereIn("role",["admin","super_admin"])->where("account_status","active")->get(["id","role","account_status"]);
echo "ELIGIBLE_REVIEWERS=".$reviewers->filter(fn($u)=>App\Support\UserManagementAccess::allows($u,"marketplace_review"))->count().PHP_EOL;
foreach (["marketplace.submission.received","marketplace.review.requested"] as $event) {
  echo "EVENT=".$event.PHP_EOL;
  echo "TEMPLATE_ENABLED=".((App\Support\EmailOutbox::template($event)["enabled"]??false)?"YES":"NO").PHP_EOL;
  $notes=App\Models\AccountNotification::query()->where("event",$event)->where("created_at",">=",now()->subDays(7));
  echo "BELL_LAST_7_DAYS=".$notes->count().PHP_EOL;
  $mail=App\Models\EmailDelivery::query()->where("event",$event)->where("created_at",">=",now()->subDays(7));
  echo "EMAIL_ROWS_LAST_7_DAYS=".$mail->count().PHP_EOL;
  foreach ($mail->selectRaw("status, COUNT(*) as total")->groupBy("status")->pluck("total","status") as $status=>$total) echo "EMAIL_".$status."=".$total.PHP_EOL;
}
')
(cd "$SITE" && runuser -u www -- "$PHP" artisan schedule:list --no-ansi) > "$WORK/schedule"
grep -F 'mail:deliver-platform' "$WORK/schedule" || echo "WARNING: scheduled email task missing"
if [[ "$MODE" == --preflight ]]; then
  echo "PREFLIGHT=PASS; PRODUCTION_FILES_MODIFIED=0"
  exit 0
fi
if [[ "$LIVE" == "$NEW" ]]; then
  echo "BAMBODIA_REVIEW_NOTICES_DEPLOYMENT=ALREADY_INSTALLED"
  exit 0
fi
BACKUP="$(mktemp -d /root/bambodia-review-notice-backup.XXXXXXXX)"
tar -C "$SITE" -cpf "$BACKUP/original-code.tar" "$FILE"
echo "BACKUP=$BACKUP"
[[ "$(git hash-object "$SITE/$FILE")" == "$OLD" ]]
APPLYING=1
TMP="$(mktemp "$SITE/app/Support/.submission-notices.XXXXXXXX")"
install -o www -g www -m 0644 "$WORK/approved.php" "$TMP"
mv -f -- "$TMP" "$SITE/$FILE"
"$PHP" -l "$SITE/$FILE"
[[ "$(git hash-object "$SITE/$FILE")" == "$NEW" ]]
APPLYING=0
echo "BAMBODIA_REVIEW_NOTICES_DEPLOYMENT=SUCCESS"
echo "RELEASE=$RELEASE"
echo "BACKUP=$BACKUP"
echo "Only SubmissionNotifications.php installed. No historical replay, permissions changes, migrations or server restarts. Test a new real submission and confirm both bell notices and inbox delivery."
