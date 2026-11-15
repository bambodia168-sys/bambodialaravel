set -Eeuo pipefail
umask 077

REPO=bambodia168-sys/bambodialaravel
COMMIT=1792e262cadb919e0307daae7e100e81abbfe5a6
APP=/www/wwwroot/bambodia-staging-qa
PHP=/www/server/php/85/bin/php
MODEL=app/Models/LicenseDefinition.php
VIEW=resources/views/dashboard/partials/license-definitions.blade.php

test "$(id -u)" -eq 0
cd "$APP"
test -f artisan
WORK="$(mktemp -d /root/bambodia-layout-license.XXXXXXXX)"
trap 'rm -rf -- "$WORK"' EXIT

fetch_replacement() {
  local path="$1" before="$2" after="$3" actual
  test "$(realpath -- "$path")" = "$APP/$path"
  actual="$(git hash-object -- "$path")"
  if [[ "$actual" == "$after" ]]; then return 0; fi
  if [[ "$actual" != "$before" ]]; then
    printf 'LAYOUT_LICENSE_SOURCE_CONFLICT=%s %s\n' "$path" "$actual" >&2
    return 1
  fi
  mkdir -p "$WORK/$(dirname "$path")"
  gh api -H 'Accept: application/vnd.github.raw+json' \
    "repos/$REPO/contents/$path?ref=$COMMIT" > "$WORK/$path"
  test "$(git hash-object -- "$WORK/$path")" = "$after"
}

fetch_replacement "$MODEL" 9b3dadf8f6e56a8c3016ee159281d102e18c46ce cc611d9ece884f82ac13dbee2d9c5074755e2067
fetch_replacement "$VIEW" 4729e35dbdd6eec6b2c97527e33cc0ea1251dd3a 7c1926018a7cb7dea365d168377fa79579f0ca7d

if [[ -f "$WORK/$MODEL" || -f "$WORK/$VIEW" ]]; then
  if [[ -f "$WORK/$MODEL" ]]; then "$PHP" -l "$WORK/$MODEL"; fi
  BACKUP="$(mktemp -d /root/bambodia-layout-license-backup.XXXXXXXX)"
  cp -a --parents -- "$MODEL" "$VIEW" "$BACKUP/"
  printf 'BACKUP=%s\n' "$BACKUP"

  for FILE in "$MODEL" "$VIEW"; do
    if [[ -f "$WORK/$FILE" ]]; then
      test "$(git hash-object -- "$FILE")" = "$(git hash-object -- "$BACKUP/$FILE")"
      chown --reference="$FILE" -- "$WORK/$FILE"
      chmod --reference="$FILE" -- "$WORK/$FILE"
      mv -fT -- "$WORK/$FILE" "$FILE"
    fi
  done
fi

runuser -u www -- "$PHP" artisan view:clear
printf 'LAYOUT_LICENSE_FIX_DEPLOYED=YES\n'

runuser -u www -- "$PHP" <<'PHP_CHECK'
<?php

use App\Models\LicenseDefinition;
use App\Models\ProductFileVersion;
use App\Models\ProductLicense;
use Illuminate\Contracts\Console\Kernel;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$license = LicenseDefinition::query()->where('public_id', '184752080')->first();
if (! $license || ! $license->isLayoutTest()) {
    echo "LAYOUT_LICENSE_IDENTITY_MISMATCH\n";
    exit(1);
}
if ($license->status === 'archived') {
    echo "LAYOUT_LICENSE_ALREADY_ARCHIVED=184752080\n";
    exit(0);
}
if ($license->canArchiveLayoutTest()) {
    echo "LAYOUT_LICENSE_ARCHIVE_AVAILABLE=184752080\n";
    exit(0);
}

$ids = $license->listings()->select('marketplace_listings.id');
echo json_encode([
    'archive_available' => false,
    'regular_products' => $license->listings()->pluck('public_id')->all(),
    'extended_products_count' => $license->extendedListings()->count(),
    'issued_definition_count' => $license->issuedLicenses()->count(),
    'linked_product_issued_count' => ProductLicense::query()->whereIn('marketplace_listing_id', $ids)->count(),
    'linked_product_release_count' => ProductFileVersion::query()->whereIn('marketplace_listing_id', $ids)->count(),
], JSON_THROW_ON_ERROR), "\n";
exit(1);
PHP_CHECK
