#!/usr/bin/env bash
# Bambodia knowledge-base live acceptance; read-only on the installed application.
# Usage: bash support/knowledge-base-live-acceptance.sh --preflight|--accept
set -Eeuo pipefail
umask 077
MODE="${1:---preflight}"
[[ "$MODE" == --preflight || "$MODE" == --accept ]] || { echo 'Use --preflight or --accept' >&2; exit 2; }
APP='/www/wwwroot/bambodia-staging-qa'
PHP='/www/server/php/85/bin/php'
DOMAIN='bambodia.com'
CONF='/www/server/panel/vhost/nginx/bambodia.com.conf'
for cmd in curl grep mktemp runuser; do command -v "$cmd" >/dev/null || { echo "Missing $cmd" >&2; exit 2; }; done
[[ -f "$APP/artisan" && -f "$APP/storage/app/installed.lock" && -x "$PHP" && -f "$CONF" ]] || { echo 'BLOCKED: Installed app identity missing' >&2; exit 2; }
grep -Eq '^[[:space:]]*root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public[[:space:]]*;' "$CONF" || { echo 'BLOCKED: Nginx root mismatch' >&2; exit 2; }
TMP="$(mktemp -d /tmp/bambodia-kb-live.XXXXXXXX)"
trap 'rm -rf -- "$TMP"' EXIT
FAILURES=0
probe() {
  local source="$1" path="$2" kind="$3" status location body headers
  body="$TMP/$(echo "$source-$path" | tr '/?' '__').html"
  headers="$body.headers"
  local opts=(--silent --show-error --compressed --max-time 25 --connect-timeout 8 --output "$body" --dump-header "$headers" --write-out '%{http_code}')
  if [[ "$source" == origin ]]; then opts+=(--resolve "$DOMAIN:443:127.0.0.1"); fi
  if ! status="$(curl "${opts[@]}" "https://$DOMAIN$path")"; then
    echo "FAIL $source $path: connection/TLS failure"; FAILURES=$((FAILURES+1)); return
  fi
  location="$(grep -i '^location:' "$headers" | tail -n 1 | tr -d '\r' || true)"
  echo "HTTP $source $path: $status ${location:+($location)}"
  if [[ "$kind" == public ]]; then
    if [[ "$status" != 200 ]] || ! grep -Fq 'How can we help?' "$body" || ! grep -Fq 'Help categories' "$body"; then
      echo "FAIL: public Support Center must render without login"; FAILURES=$((FAILURES+1))
    else echo "PASS: public Support Center"; fi
  elif [[ "$kind" == private ]]; then
    if [[ ! "$status" =~ ^30[2378]$ ]] || [[ "$location" != *'/login'* ]]; then
      echo "FAIL: private route did not redirect anonymously"; FAILURES=$((FAILURES+1))
    else echo "PASS: anonymous access restricted"; fi
  fi
}
echo '========== EDGE AND NGINX ORIGIN =========='
TOKEN="$(date +%s)"
probe edge "/support-center?knowledge_acceptance=$TOKEN" public
probe origin "/support-center?knowledge_acceptance=$TOKEN" public
probe edge '/support-center/tickets' private
probe origin '/support-center/tickets' private
probe edge '/dashboard/knowledge-base' private
probe origin '/dashboard/knowledge-base' private
echo '========== KNOWLEDGE ARTICLE INVENTORY =========='
if ! COUNTS="$(cd "$APP" && runuser -u www -- "$PHP" -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$model = \App\Models\KnowledgeArticle::class;
$starter = array_column(\App\Support\SupportKnowledge::starterArticles(), "title");
foreach (["draft","published","archived"] as $status) {
    echo strtoupper($status)."=".$model::query()->where("status",$status)->count()."\n";
}
echo "STARTER_TOTAL=".$model::query()->whereIn("title",$starter)->count()."\n";
echo "STARTER_PUBLISHED=".$model::query()->whereIn("title",$starter)->where("status","published")->count()."\n";
')"; then
  echo 'FAIL: knowledge database read'; FAILURES=$((FAILURES+1))
else
  printf '%s\n' "$COUNTS"
  if [[ "$MODE" == --accept ]] && ! grep -Eq '^STARTER_PUBLISHED=9$' <<< "$COUNTS"; then
    echo 'FAIL: nine reviewed starter articles not yet published'; FAILURES=$((FAILURES+1))
  fi
fi
echo '========== RESULT =========='
if (( FAILURES > 0 )); then
  echo "BLOCKED: $FAILURES live acceptance issue(s)."
  echo 'If edge and origin disagree, inspect Cloudflare cache/redirects; otherwise inspect installed app routing.'
  exit 1
fi
echo 'PASS: routing boundary and database inventory acceptance'
if [[ "$MODE" == --preflight ]]; then echo 'Publication readiness: inspect STARTER_PUBLISHED above'; fi
echo 'Separate checks still needed: authenticated admin browser and real-device visual acceptance.'
