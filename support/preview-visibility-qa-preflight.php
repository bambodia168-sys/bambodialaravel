#!/usr/bin/env php
<?php
/**
 * Read-only, fail-closed checks for a fresh, disposable Bambodia preview QA install.
 * This does not provision QA, migrate, seed, enable writes, or prove owner consent.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || count($argv) !== 2 || !in_array($argv[1], ['--before-install', '--after-install'], true)) {
    fwrite(STDERR, "Usage: php support/preview-visibility-qa-preflight.php --before-install|--after-install\n");
    exit(2);
}

$mode = $argv[1];
$fail = static function (string $check): never {
    fwrite(STDERR, json_encode(['status' => 'BLOCKED', 'check' => $check], JSON_THROW_ON_ERROR)."\n");
    exit(2);
};
$root = realpath(__DIR__.'/..');
if (!is_string($root) || $root === '/') {
    $fail('app_root');
}

// The existing aaPanel installation is not a disposable acceptance environment.
foreach (['/www/wwwroot/bambodia-staging-qa', '/www/wwwroot/bambodia.com'] as $protected) {
    if ($root === $protected || str_starts_with($root, $protected.'/')) {
        $fail('protected_application_path');
    }
}
if (getenv('BAMBODIA_PREVIEW_QA_ISOLATED') !== '1') {
    $fail('explicit_qa_opt_in');
}
if (!is_file($root.'/.env') || is_link($root.'/.env')
    || !is_file($root.'/vendor/autoload.php') || !is_file($root.'/bootstrap/app.php')) {
    $fail('standalone_laravel_installation');
}

try {
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $configCheck = static function (bool $ok, string $name) use ($fail): void {
        if (!$ok) {
            $fail($name);
        }
    };
    $configCheck((string) config('app.env') === 'testing', 'testing_environment_required');
    $configCheck(config('app.debug') === false, 'app_debug_must_be_off');
    $configCheck(config('preview_visibility.enabled', false) === false, 'withdrawal_switch_must_be_off');

    $url = rtrim((string) config('app.url'), '/');
    $expected = rtrim((string) getenv('BAMBODIA_PREVIEW_QA_EXPECTED_ORIGIN'), '/');
    $host = (string) parse_url($url, PHP_URL_HOST);
    $scheme = (string) parse_url($url, PHP_URL_SCHEME);
    $configCheck($url !== '' && $expected !== '' && hash_equals($expected, $url), 'qa_origin_mismatch');
    $configCheck(!in_array(strtolower($host), ['', 'bambodia.com', 'www.bambodia.com'], true), 'production_origin_forbidden');
    $loopback = in_array(strtolower($host), ['localhost', '127.0.0.1'], true);
    $configCheck(($loopback && in_array($scheme, ['http', 'https'], true)) || $scheme === 'https', 'https_required');

    $configCheck(config('database.default') === 'mysql', 'mysql_connection_required');
    $db = (string) config('database.connections.mysql.database');
    $user = (string) config('database.connections.mysql.username');
    $configCheck(preg_match('/^bambodia_previewqa_[a-z0-9_]{4,30}$/D', $db) === 1, 'dedicated_qa_database_name');
    $configCheck(preg_match('/^pvqa_[a-z0-9_]{3,25}$/D', $user) === 1, 'dedicated_qa_mysql_user');
    $configCheck(config('session.driver') === 'file', 'separate_file_sessions_required');
    $configCheck(config('cache.default') === 'file', 'separate_file_cache_required');
    $configCheck(config('queue.default') === 'sync', 'external_queue_forbidden');
    $configCheck(config('filesystems.default') === 'local', 'external_default_storage_forbidden');
    $configCheck(in_array(config('mail.default'), ['log', 'array'], true), 'outbound_email_forbidden');
    $configCheck((string) config('app.key') !== '', 'unique_app_key_required');
    $configCheck((string) env('MEDIA_LIBRARY_DISK', 'local') === 'local', 'external_media_storage_forbidden');

    $storage = realpath($root.'/storage');
    $configCheck(is_string($storage) && str_starts_with($storage, $root.'/'), 'storage_isolation_required');
    $configCheck(!is_link($root.'/storage'), 'storage_symlink_forbidden');

    $connected = \Illuminate\Support\Facades\DB::selectOne('SELECT DATABASE() AS selected_database');
    $configCheck(($connected->selected_database ?? null) === $db, 'connected_database_mismatch');
    $tables = \Illuminate\Support\Facades\DB::select(
        'SELECT TABLE_NAME AS table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = ?',
        [$db, 'BASE TABLE']
    );
    $names = array_map(static fn (object $row): string => $row->table_name, $tables);

    if ($mode === '--before-install') {
        $configCheck(count($names) === 0, 'new_empty_database_required');
    } else {
        foreach (['migrations', 'users', 'marketplace_listings', 'marketplace_jobs', 'preview_visibility_actions'] as $required) {
            $configCheck(in_array($required, $names, true), 'missing_table_'.$required);
        }
        $columns = \Illuminate\Support\Facades\DB::select(
            'SELECT COLUMN_NAME AS column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ?',
            [$db, 'preview_visibility_actions']
        );
        $available = array_map(static fn (object $row): string => $row->column_name, $columns);
        foreach (['subject_kind', 'subject_id', 'subject_public_id', 'actor_id', 'action', 'from_status', 'to_status', 'review_fingerprint', 'owner_confirmation_reference', 'backup_reference', 'created_at'] as $column) {
            $configCheck(in_array($column, $available, true), 'missing_audit_column_'.$column);
        }
        foreach (['marketplace_listings', 'marketplace_jobs', 'preview_visibility_actions'] as $table) {
            $configCheck(\Illuminate\Support\Facades\DB::table($table)->count() === 0, 'disposable_database_not_empty_'.$table);
        }
        // Bambodia's five-step installer provisions exactly one Super Admin.
        // A migration-only rehearsal may have zero users. Neither may contain
        // customer, staff or creator accounts at this empty-state checkpoint.
        $users = \Illuminate\Support\Facades\DB::table('users')->select('role')->limit(2)->get();
        $configCheck($users->count() <= 1
            && ($users->count() === 0 || $users->first()->role === 'super_admin'),
            'unexpected_qa_user_inventory');
    }

    echo json_encode([
        'status' => 'PASS',
        'mode' => $mode,
        'environment' => 'isolated_qa_attested_not_independently_proven',
        'withdrawal_writes' => 'disabled',
        'database' => 'dedicated_name_and_user_verified',
        'preview_actions' => $mode === '--before-install' ? 'no_existing_tables' : 'schema_verified_no_marketplace_fixtures',
        'warning' => 'Runbook evidence, independent backup restoration, signed owner approvals and physical browser tests are still required.',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
} catch (\Throwable $e) {
    // Never echo database/configuration exception messages (they can contain secrets).
    $fail('application_boot_or_read_only_database_check');
}
