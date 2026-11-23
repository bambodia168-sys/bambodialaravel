#!/usr/bin/env bash
# Isolated Baray code deployment. Keeps the payment method hidden and retains all other settings.
set -Eeuo pipefail
umask 077
MODE="$1"
REF="$2"
SITE=/www/wwwroot/bambodia-staging-qa
PHP=/www/server/php/85/bin/php
REPO=bambodia168-sys/bambodialaravel
[[ "$MODE" == "--preflight" || "$MODE" == "--apply" ]] || { echo "Use --preflight or --apply"; exit 2; }
[[ "$REF" =~ ^[0-9a-f]{40}$ ]] || { echo "A pinned commit SHA is required"; exit 2; }
[[ "$(id -u)" == 0 ]] || { echo "Run with sudo"; exit 2; }
[[ -f "$SITE/artisan" && -f "$SITE/.env" && -d "$SITE/vendor" ]] || { echo "Unexpected Bambodia path"; exit 2; }
grep -Eq "^APP_ENV=['\"]?production(['\"]?)$" "$SITE/.env" || { echo "Not the expected production environment"; exit 2; }
grep -Eq 'root[[:space:]]+/www/wwwroot/bambodia-staging-qa/public;' /www/server/panel/vhost/nginx/bambodia.com.conf || { echo "Unexpected Nginx root"; exit 2; }
for cache in packages.php services.php; do
  runuser -u www -- test -r "$SITE/bootstrap/cache/$cache" || { echo "Cache permissions need repair: $cache"; exit 2; }
done
http() { curl -4 -sS --connect-timeout 8 --max-time 18 --resolve bambodia.com:443:127.0.0.1 -o /dev/null -w '%{http_code}' https://bambodia.com/; }
[[ "$(http)" == "200" ]] || { echo "Bambodia is not healthy before deployment"; exit 2; }
STAGE="$(mktemp -d /root/bambodia-baray-stage.XXXXXXXX)"
BACKUP=""
INSTALLED=0
DB_TOUCHED=0
restore() {
  code=$?
  trap - EXIT
  if [[ "$INSTALLED" == "1" ]]; then
    echo "Deployment failed: restoring original application files..." >&2
    if [[ "$DB_TOUCHED" == "1" ]]; then
      (cd "$SITE" && runuser -u www -- "$PHP" -r '
        require "vendor/autoload.php";
        $app=require "bootstrap/app.php";
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $db=\Illuminate\Support\Facades\DB::connection();
        if ($db->table("commerce_payments")->where("provider","baray")->exists()) exit(3);
        $db->transaction(function () use ($db) {
            $db->table("commerce_payment_methods")->where("provider","baray")->delete();
            $db->table("commerce_gateway_settings")->where("provider","baray")->delete();
            $db->table("migrations")->where("migration","2026_11_23_220000_add_baray_gateway")->delete();
        });
      ') || echo "WARNING: Baray database rollback needs manual review" >&2
    fi
    tar -C "$SITE" -xpf "$BACKUP/runtime-files.tar" || true
    while IFS= read -r file; do rm -f -- "$SITE/$file"; done < "$STAGE/new-files.list"
    (cd "$SITE" && runuser -u www -- "$PHP" artisan route:clear --no-ansi && runuser -u www -- "$PHP" artisan view:clear --no-ansi) || true
  fi
  rm -rf -- "$STAGE"
  exit "$code"
}
trap restore EXIT
cat > "$STAGE/manifest" <<'FILES'
app/Http/Controllers/CommercePaymentController.php|a76c6d55c38fc4b8b2f1f9daa9f929a4fc6ed890|0447095f09c3313127045a083b1ddecbaa408603
app/Http/Controllers/CommercePaymentMethodController.php|9b5bb53da570443158c7420dbeb2aaf83a4d0de6|3ab1cb07f4ca55b7bb0da56433d17eb470d82f97
app/Models/CommerceGatewaySetting.php|182dd1a0caf6263f717621b7712ab66d6ffc3dfb|8a3197ed447dd53b963f7e99c9b03036fcb56974
app/Models/CommercePaymentMethod.php|a780e62e8b13683e1aa3de6808ad58034372d65d|fb3e12d940969977c26faf8a474d0136a7c89840
app/Support/BarayGateway.php|NEW|fe97a93b1e877701fdb18c77b366a7f165a343e8
app/Support/CommerceGateway.php|e5c4091ca6b208da1b482d988189c707be0acba3|a959ff20e229490b1f69954a32360e54a87269e0
config/commerce.php|ebc89ffaa2df3d904f8f97ada50bb077615efb06|b386cc8498d179da54d2090e4f614a79303552d0
database/migrations/2026_11_23_220000_add_baray_gateway.php|NEW|151921723a8e774f3c29a32ad576a13a94b9fc0d
resources/views/commerce/methods.blade.php|2ec579651085a2b7de1eb08681ddcb2ec167daf2|fa67929c0a4bc4eb0df8fdfbb8457c385e01ba68
resources/views/commerce/payments.blade.php|ebf71f5e62ac465d7e0eb5ce4092e9bbc97a13ed|34145c52ab3abca8ce3285a6879555f757aacfef
resources/views/components/payment-method-icon.blade.php|48453895b7c6cee9f61d31606eb5c4a6289872d7|286555bcecd18b33373aefd69c7b2e5d5d9674fe
routes/api.php|b7da9e33d4818cf200917b97155930065f4def0b|71670d50be94bc3cf6d803a2e54253f3d43422b7
FILES
: > "$STAGE/existing-files.list"
: > "$STAGE/new-files.list"
while IFS='|' read -r file before after; do
  [[ ! -L "$SITE/$file" ]] || { echo "STOP: symlink at $file"; exit 1; }
  if [[ "$before" == "NEW" ]]; then
    [[ ! -e "$SITE/$file" ]] || { echo "STOP: new file already exists: $file"; exit 1; }
    echo "$file" >> "$STAGE/new-files.list"
  else
    [[ -f "$SITE/$file" ]] || { echo "STOP: missing live file: $file"; exit 1; }
    actual="$(git hash-object "$SITE/$file")"
    [[ "$actual" == "$before" ]] || { echo "STOP: live file drift: $file ($actual). No changes applied."; exit 1; }
    echo "$file" >> "$STAGE/existing-files.list"
  fi
  mkdir -p "$STAGE/$(dirname "$file")"
  curl -fsSL --retry 2 --connect-timeout 8 --max-time 45 \
    "https://raw.githubusercontent.com/$REPO/$REF/$file" -o "$STAGE/$file"
  actual="$(git hash-object "$STAGE/$file")"
  [[ "$actual" == "$after" ]] || { echo "STOP: downloaded file SHA mismatch: $file"; exit 1; }
  if [[ "$file" == *.php && "$file" != *.blade.php ]]; then
    "$PHP" -l "$STAGE/$file" >/dev/null || { echo "STOP: invalid PHP at $file"; exit 1; }
  fi
done < "$STAGE/manifest"
echo "PASS: reviewed files match, PHP parses, and Bambodia origin is HTTP 200."
if [[ "$MODE" == "--preflight" ]]; then echo "No production files changed."; exit 0; fi
BACKUP="$(mktemp -d /root/bambodia-baray-backup.XXXXXXXX)"
tar -C "$SITE" -cpf "$BACKUP/runtime-files.tar" -T "$STAGE/existing-files.list"
echo "Backup: $BACKUP"
INSTALLED=1
while IFS='|' read -r file before after; do
  dest="$SITE/$file"
  mkdir -p "$(dirname "$dest")"
  tmp="$(mktemp "$(dirname "$dest")/.baray-stage.XXXXXXXX")"
  install -o www -g www -m 0644 "$STAGE/$file" "$tmp"
  mv -f "$tmp" "$dest"
  runuser -u www -- test -r "$dest" || exit 1
done < "$STAGE/manifest"
(cd "$SITE" &&
  runuser -u www -- "$PHP" artisan route:clear --no-ansi &&
  runuser -u www -- "$PHP" artisan view:clear --no-ansi)
DB_TOUCHED=1
(cd "$SITE" && runuser -u www -- "$PHP" artisan migrate --path=database/migrations/2026_11_23_220000_add_baray_gateway.php --force --no-ansi)
(cd "$SITE" && runuser -u www -- "$PHP" -r '
  require "vendor/autoload.php";
  $app=require "bootstrap/app.php";
  $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  $db=\Illuminate\Support\Facades\DB::connection();
  $method=$db->table("commerce_payment_methods")->where("provider","baray")->first();
  if (! $method || (bool)$method->available || $db->table("commerce_gateway_settings")->where("provider","baray")->count() !== 2) exit(1);
  echo "PASS: Baray hidden, both environment slots created.\n";
')
[[ "$(http)" == "200" ]] || { echo "Site not HTTP 200; triggering rollback"; exit 1; }
for cache in packages.php services.php; do
  runuser -u www -- test -r "$SITE/bootstrap/cache/$cache" || exit 1
done
INSTALLED=0
echo "SUCCESS: Baray Sandbox/Production UI code deployed. Site HTTP 200."
echo "Baray remains HIDDEN; no API keys configured or live payments enabled."
echo "Backup retained: $BACKUP/runtime-files.tar"
