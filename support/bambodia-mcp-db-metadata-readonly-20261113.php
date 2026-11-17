<?php
/**
 * Bambodia MCP recovery: aggregate MySQL metadata only.
 * Invoked by pinned root-only wrapper after operator confirmation.
 * Does not run Laravel, select customer rows, change DB state or print secrets.
 */
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '0');
error_reporting(0);

function mcp_emit(string $label, string $value): void
{
    echo $label, '=', $value, PHP_EOL;
}
function mcp_fail(string $code): never
{
    mcp_emit('MCP_DB_METADATA_BLOCKED', $code);
    exit(1);
}
try {
    $app = '/www/wwwroot/bambodia-staging-qa';
    $envFile = $app.'/.env';
    $autoload = $app.'/vendor/autoload.php';
    if (!is_file($envFile) || is_link($envFile) || filesize($envFile) > 131072 ||
        !is_file($autoload) || is_link($autoload) ||
        file_exists($app.'/bootstrap/cache/config.php')) {
        mcp_fail('LIVE_ENV_OR_VENDOR_UNSAFE');
    }
    $st = stat($envFile);
    if (!$st || ($st['mode'] & 0006)) {
        mcp_fail('ENV_OTHER_ACCESS');
    }

    require $autoload;
    if (!class_exists(\Dotenv\Dotenv::class)) {
        mcp_fail('DOTENV_LIBRARY_MISSING');
    }
    \Dotenv\Dotenv::createImmutable($app)->safeLoad();
    $get = static fn (string $name): string => trim((string) ($_ENV[$name] ?? ''));
    $driver = strtolower($get('DB_CONNECTION'));
    if (!in_array($driver, ['mysql', 'mariadb'], true) || $get('DB_URL') !== '') {
        mcp_fail('DB_DRIVER_OR_URL_NOT_APPROVED');
    }
    $host = strtolower($get('DB_HOST'));
    if (!in_array($host, ['127.0.0.1', 'localhost'], true) || $get('DB_SOCKET') !== '') {
        mcp_fail('DB_HOST_OR_SOCKET_NOT_APPROVED');
    }
    $port = $get('DB_PORT') ?: '3306';
    if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        mcp_fail('DB_PORT_INVALID');
    }
    $database = $get('DB_DATABASE');
    if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $database)) {
        mcp_fail('DB_NAME_FORMAT_REQUIRES_PRIVATE_REVIEW');
    }
    $user = $get('DB_USERNAME');
    if ($user === '') {
        mcp_fail('DB_USER_MISSING');
    }
    // Identifiers, passwords, hostnames and DSNs are NEVER printed.
    $dsn = 'mysql:host='.$host.';port='.$port.';dbname='.$database.';charset=utf8mb4';
    $pdo = new PDO($dsn, $user, (string) ($_ENV['DB_PASSWORD'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_LOCAL_INFILE => false,
    ]);
    $actualDb = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!is_string($actualDb) || !hash_equals($database, $actualDb)) {
        mcp_fail('DB_IDENTITY_MISMATCH');
    }
    mcp_emit('MCP_DB_IDENTITY', 'MATCHES_PRIVATE_ENV_VALUE');
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    mcp_emit('MCP_DB_SERVER_FAMILY', stripos($version, 'mariadb') !== false ? 'MARIADB' : 'MYSQL_OR_COMPATIBLE');
    $row = $pdo->query(
        "SELECT COUNT(*) AS objects,
            COALESCE(SUM(CASE WHEN TABLE_TYPE='BASE TABLE' THEN 1 ELSE 0 END),0) AS tables,
            COALESCE(SUM(CASE WHEN TABLE_TYPE='VIEW' THEN 1 ELSE 0 END),0) AS views,
            COALESCE(SUM(CASE WHEN ENGINE='InnoDB' THEN 1 ELSE 0 END),0) AS innodb,
            COALESCE(SUM(CASE WHEN ENGINE='MyISAM' THEN 1 ELSE 0 END),0) AS myisam,
            COALESCE(SUM(CASE WHEN TABLE_TYPE='BASE TABLE' AND
                      (ENGINE IS NULL OR ENGINE NOT IN ('InnoDB','MyISAM')) THEN 1 ELSE 0 END),0) AS other,
            COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH),0) AS bytes_est
         FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()"
    )->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int) $row['tables'] < 1) {
        mcp_fail('NO_BASE_TABLES_OR_METADATA_RESTRICTED');
    }
    foreach ([
        'MCP_DB_OBJECTS' => 'objects',
        'MCP_DB_BASE_TABLES' => 'tables',
        'MCP_DB_VIEWS' => 'views',
        'MCP_DB_INNODB_TABLES' => 'innodb',
        'MCP_DB_MYISAM_TABLES' => 'myisam',
        'MCP_DB_OTHER_ENGINE_TABLES' => 'other',
        'MCP_DB_APPROX_ALLOCATED_BYTES' => 'bytes_est',
    ] as $output => $key) {
        mcp_emit($output, (string) max(0, (int) $row[$key]));
    }
    $struct = $pdo->query(
        "SELECT
         COALESCE(SUM(CASE WHEN TABLE_NAME='migrations' THEN 1 ELSE 0 END),0) AS migrations,
         COALESCE(SUM(CASE WHEN TABLE_NAME='users' THEN 1 ELSE 0 END),0) AS users,
         COALESCE(SUM(CASE WHEN TABLE_NAME='oauth_access_tokens' THEN 1 ELSE 0 END),0) AS oauth_tokens
         FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()"
    )->fetch(PDO::FETCH_ASSOC);
    foreach ([
        'MCP_DB_MIGRATION_TABLE' => 'migrations',
        'MCP_DB_USERS_TABLE' => 'users',
        'MCP_DB_PASSPORT_TOKEN_TABLE' => 'oauth_tokens',
    ] as $output => $key) {
        mcp_emit($output, ((int) ($struct[$key] ?? 0)) > 0 ? 'PRESENT' : 'NOT_FOUND');
    }
    try {
        $binlog = (int) $pdo->query('SELECT @@GLOBAL.log_bin')->fetchColumn();
        mcp_emit('MCP_DB_BINARY_LOGGING', $binlog === 1 ? 'ENABLED' : 'DISABLED');
    } catch (Throwable $ignored) {
        mcp_emit('MCP_DB_BINARY_LOGGING', 'NOT_VERIFIED');
    }
    // A read-only information_schema inventory is NOT backup or recovery validation.
    $pdo = null;
    mcp_emit('MCP_DB_CUSTOMER_RECORDS', 'NOT_QUERIED');
    mcp_emit('MCP_DB_BACKUP', 'NOT_CREATED');
    mcp_emit('MCP_DB_RESTORE', 'NOT_PERFORMED');
    mcp_emit('MCP_DB_PRODUCTION_MUTATION', 'NONE_BY_THIS_AUDIT');
    mcp_emit('MCP_DB_METADATA_AUDIT', 'READ_ONLY_COMPLETED');
    mcp_emit('MCP_PRODUCTION_RELEASE', 'BLOCKED');
} catch (Throwable $ignored) {
    mcp_fail('READ_ONLY_CONNECT_OR_METADATA_QUERY_FAILED');
}
