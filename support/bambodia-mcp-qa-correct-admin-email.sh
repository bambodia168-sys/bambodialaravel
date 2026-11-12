#!/usr/bin/env bash
# One-time QA-only administrator email typo correction.
# Does not change passwords, permissions, session data, OAuth keys, migrations,
# Nginx, production or any other QA database.
set -Eeuo pipefail
set +x
umask 077

[[ "$#" -eq 1 && "$1" == "--correct-email" ]] || {
  echo "Usage: bash this-script --correct-email" >&2; exit 64;
}
APP="/var/lib/bambodia-oauth-https-qa"
PHP="/www/server/php/85/bin/php"
fail() { printf 'QA_ADMIN_EMAIL_BLOCKED=%s\n' "$*" >&2; exit 1; }

[[ "$(id -u)" == 0 ]] || fail ROOT_REQUIRED
[[ -x "$PHP" ]] || fail PHP85_UNAVAILABLE
[[ -d "$APP" && ! -L "$APP" && "$(stat -c '%u:%g' "$APP")" == "0:$(id -g www)" ]] ||
  fail PRIVATE_QA_APPLICATION_OWNER_UNEXPECTED
for file in ".env" "artisan" "vendor/autoload.php" \
    "app/Models/User.php"; do
  [[ -f "$APP/$file" && ! -L "$APP/$file" ]] ||
    fail "QA_SOURCE_MISSING_OR_SYMLINK_$file"
done

echo "========== CORRECT ONLY THE EXISTING ISOLATED QA ADMIN EMAIL =========="
(
  cd "$APP"
  # No credentials are accepted as arguments, echoed or sent to logs.
  env -i HOME=/root PATH=/usr/bin:/bin "$PHP" -d display_errors=stderr -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    if (config("app.env") !== "staging"
        || rtrim((string)config("app.url"),"/") !== "https://oauth-qa.bambodia.com"
        || config("database.default") !== "mysql"
        || \Illuminate\Support\Facades\DB::connection()->getDatabaseName()
           !== "bambodia_oauthqa_https"
        || config("database.connections.mysql.username") !== "bambodiaqa_https"
        || config("database.connections.mysql.host") !== "127.0.0.1") {
        fwrite(STDERR,"QA_ADMIN_EMAIL_BLOCKED=NOT_DEDICATED_QA_APP\n");
        exit(21);
    }

    $old = "tonsamith168@gmail.cm";
    $new = "tonsamith168@gmail.com";
    $outcome = \Illuminate\Support\Facades\DB::transaction(function() use ($old,$new) {
        if (\App\Models\User::query()->count() !== 1) {
            throw new \RuntimeException("EXPECTED_EXACTLY_ONE_QA_ACCOUNT");
        }
        $account = \App\Models\User::query()->lockForUpdate()->first();
        if ($account === null
            || $account->role !== "super_admin"
            || $account->account_status !== "active") {
            throw new \RuntimeException("NOT_EXPECTED_QA_SUPER_ADMIN");
        }
        $email = (string)$account->email;
        if (hash_equals($new,$email)) {
            return "ALREADY_CORRECT_NO_CHANGE";
        }
        if (!hash_equals($old,$email)) {
            throw new \RuntimeException("ORIGINAL_QA_EMAIL_DIFFERENT");
        }
        $account->email = $new;
        $account->save();
        return "CORRECTED";
    }, 1);

    $record = \App\Models\User::query()->sole();
    if (!hash_equals((string)$record->email,$new)
        || $record->role !== "super_admin") {
        fwrite(STDERR,"QA_ADMIN_EMAIL_BLOCKED=POST_UPDATE_VERIFICATION_FAILED\n");
        exit(22);
    }
    echo "QA_ADMIN_EMAIL_RESULT=".$outcome."\n";
    echo "QA_ADMIN_EMAIL=tonsamith168@gmail.com\n";
    echo "QA_ADMIN_PASSWORD=UNCHANGED\n";
    echo "QA_ADMIN_ROLE=UNCHANGED\n";
    echo "QA_ADMIN_EMAIL_VERIFICATION_STATUS=PRESERVED\n";
  '
) || fail CORRECTION_FAILED_REVIEW_BEFORE_RETRY

echo "QA_ADMIN_EMAIL_CORRECTION=PASS"
echo "QA_APP_KEYS_AND_OAUTH_DATA=UNCHANGED"
echo "MIGRATIONS=NOT_RUN"
echo "NGINX=UNCHANGED"
echo "PRODUCTION=UNCHANGED"
