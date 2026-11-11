<?php
/**
 * Browser-QA Step 23A follow-up: local read-only Laravel database count diagnosis.
 * Intentionally does not authenticate, authorize, create users/clients or emit PII.
 * Run only as www against the separately pinned browser-QA checkout.
 */
declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = '/var/lib/bambodia-oauth-browser-qa';

$blocked = static function (string $reason): never {
    fwrite(STDERR, "QA_COUNT_DIAG_BLOCKED={$reason}\n");
    exit(1);
};

try {
    if (! is_file($root . '/vendor/autoload.php') || ! is_file($root . '/artisan')) {
        $blocked('MISSING_BROWSER_QA_RUNTIME');
    }
    require $root . '/vendor/autoload.php';
    $app = require $root . '/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    if (config('app.env') !== 'staging' ||
        config('app.debug') !== false ||
        config('app.url') !== 'https://oauth-browser-qa.invalid' ||
        config('database.default') !== 'mysql' ||
        config('database.connections.mysql.host') !== '127.0.0.1' ||
        config('database.connections.mysql.database') !== 'bambodia_oauthqa_browser' ||
        config('database.connections.mysql.username') !== 'bambodiaqa_browser') {
        $blocked('UNEXPECTED_LARAVEL_ENV_OR_DATABASE');
    }

    $tables = [
        'users',
        'oauth_clients',
        'oauth_auth_codes',
        'oauth_access_tokens',
        'oauth_refresh_tokens',
        'oauth_device_codes',
        'audit_events',
    ];

    $connection = DB::connection('mysql');
    if ($connection->getDatabaseName() !== 'bambodia_oauthqa_browser') {
        $blocked('ACTUAL_CONNECTION_DATABASE_MISMATCH');
    }

    echo "LARAVEL_RUNTIME=ISOLATED_BROWSER_QA\n";
    foreach ($tables as $table) {
        // Table names are code constants, never user-provided.
        $count = $connection->table($table)->count();
        echo 'LARAVEL_' . strtoupper($table) . '=' . $count . "\n";
    }

    $expectedUser = $connection->table('users')
        ->where('email', 'oauth-admin@oauth-browser-qa.invalid')
        ->where('role', 'super_admin')
        ->where('account_status', 'active')
        ->count();

    $expectedClient = $connection->table('oauth_clients')
        ->where('name', 'Bambodia Browser QA PKCE Client')
        ->count();

    echo 'EXPECTED_QA_ADMIN_MATCHES=' . $expectedUser . "\n";
    echo 'EXPECTED_QA_CLIENT_MATCHES=' . $expectedClient . "\n";
    echo "USER_IDENTITIES=NOT_DISPLAYED\n";
    echo "OAUTH_SECRETS=NOT_DISPLAYED\n";
    echo "SQL_WRITES=NONE\n";
    echo "QA_COUNT_DIAGNOSTIC=PASS\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'QA_COUNT_DIAG_EXCEPTION_TYPE=' . get_class($exception) . "\n");
    exit(1);
}
