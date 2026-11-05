<?php

declare(strict_types=1);

/*
 * Bambodia standalone pre-Laravel installer.
 * This is a short-lived setup entry, not a separate application.
 * The public front controller permanently bypasses it after installed.lock exists.
 */

$base = dirname(__DIR__);
$storage = $base.'/storage/app';
if (! is_dir($storage)) {
    @mkdir($storage, 0750, true);
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000');
}
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/installer',
    'secure' => ! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

function installer_escape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function installer_redirect(int $step): never
{
    header('Location: /installer?step='.$step, true, 303);
    exit;
}

function installer_env_value(string $value): string
{
    if (str_contains($value, "\n") || str_contains($value, "\r") || str_contains($value, '"')) {
        throw new RuntimeException('Unsupported character in environment setting.');
    }
    return '"'.$value.'"';
}

function installer_database(array $database): PDO
{
    $dsn = 'mysql:host='.$database['host'].';port='.$database['port'].';dbname='.$database['name'].';charset=utf8mb4';
    return new PDO($dsn, $database['username'], $database['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function installer_requirements(string $base): array
{
    $checks = [
        'PHP 8.3 or later' => PHP_VERSION_ID >= 80300,
        'Laravel dependencies installed' => is_file($base.'/vendor/autoload.php'),
        'Application directory writable' => is_writable($base),
        'Storage writable' => is_writable($base.'/storage/app'),
        'Cache directory writable' => is_writable($base.'/bootstrap/cache'),
    ];
    foreach (['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'hash', 'mbstring', 'openssl', 'pdo', 'pdo_mysql', 'session', 'tokenizer', 'xml'] as $extension) {
        $checks['PHP extension: '.$extension] = extension_loaded($extension);
    }
    return $checks;
}

$secretFile = $storage.'/installer-access.txt';
if (! is_file($secretFile)) {
    $handle = @fopen($secretFile, 'x');
    if ($handle !== false) {
        fwrite($handle, bin2hex(random_bytes(20)));
        fclose($handle);
        @chmod($secretFile, 0600);
    }
}

$csrf = $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
$checks = installer_requirements($base);
$ready = ! in_array(false, $checks, true);
$error = '';
$step = max(1, min(5, (int) ($_GET['step'] ?? 1)));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    $postedCsrf = (string) ($_POST['_csrf'] ?? '');
    if (! hash_equals($csrf, $postedCsrf)) {
        http_response_code(419);
        $error = 'The session expired. Refresh this page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'access' && $step === 1) {
                if (! $ready) {
                    throw new RuntimeException('Complete all system requirements first.');
                }
                $saved = is_file($secretFile) ? trim((string) file_get_contents($secretFile)) : '';
                if ($saved === '' || ! hash_equals($saved, (string) ($_POST['access'] ?? ''))) {
                    throw new RuntimeException('Invalid installation access code.');
                }
                if (file_exists($base.'/.env')) {
                    throw new RuntimeException('An existing .env file was detected. Review it manually before using a fresh installer.');
                }
                session_regenerate_id(true);
                $_SESSION['verified'] = true;
                installer_redirect(2);
            }
            if (empty($_SESSION['verified'])) {
                installer_redirect(1);
            }
            if ($action === 'database' && $step === 2) {
                $db = [
                    'host' => trim((string) ($_POST['host'] ?? '')),
                    'port' => (string) ($_POST['port'] ?? '3306'),
                    'name' => trim((string) ($_POST['name'] ?? '')),
                    'username' => trim((string) ($_POST['username'] ?? '')),
                    'password' => (string) ($_POST['password'] ?? ''),
                ];
                if (! preg_match('/^[a-zA-Z0-9._-]{1,253}$/', $db['host'])
                    || ! ctype_digit($db['port']) || (int) $db['port'] < 1 || (int) $db['port'] > 65535
                    || ! preg_match('/^[a-zA-Z0-9_]{1,64}$/', $db['name'])
                    || $db['username'] === '' || strlen($db['username']) > 128) {
                    throw new RuntimeException('Enter a valid database host, port, name, and user.');
                }
                $pdo = installer_database($db);
                $existing = $pdo->query('SHOW TABLES')->fetch();
                if ($existing !== false) {
                    throw new RuntimeException('Use a new empty database. The installer never overwrites existing tables.');
                }
                $_SESSION['database'] = $db;
                installer_redirect(3);
            }
            if ($action === 'application' && $step === 3 && ! empty($_SESSION['database'])) {
                $name = trim((string) ($_POST['app_name'] ?? ''));
                $url = trim((string) ($_POST['app_url'] ?? ''));
                $parts = parse_url($url);
                if (mb_strlen($name) < 2 || mb_strlen($name) > 80
                    || $parts === false || ! in_array($parts['scheme'] ?? '', ['https', 'http'], true)
                    || ! preg_match('/^[a-zA-Z0-9.-]+$/', $parts['host'] ?? '')
                    || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                    throw new RuntimeException('Enter a valid application name and absolute URL.');
                }
                $_SESSION['application'] = ['name' => $name, 'url' => rtrim($url, '/')];
                installer_redirect(4);
            }
            if ($action === 'administrator' && $step === 4 && ! empty($_SESSION['application'])) {
                $name = trim((string) ($_POST['admin_name'] ?? ''));
                $email = strtolower(trim((string) ($_POST['admin_email'] ?? '')));
                $password = (string) ($_POST['admin_password'] ?? '');
                if (mb_strlen($name) < 2 || mb_strlen($name) > 120
                    || ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254
                    || strlen($password) < 12 || $password !== (string) ($_POST['confirm_password'] ?? '')) {
                    throw new RuntimeException('Provide a valid administrator name, email, and matching password of at least 12 characters.');
                }
                $_SESSION['administrator'] = ['name' => $name, 'email' => $email, 'password' => $password];
                installer_redirect(5);
            }
            if ($action === 'install' && $step === 5 && ! empty($_SESSION['administrator'])
                && ! empty($_SESSION['application']) && ! empty($_SESSION['database']) && $ready) {
                if (file_exists($base.'/.env') || is_file($storage.'/installed.lock')) {
                    throw new RuntimeException('An existing application configuration was detected; no files were replaced.');
                }
                $db = $_SESSION['database'];
                $appValues = $_SESSION['application'];
                $admin = $_SESSION['administrator'];
                $pdo = installer_database($db);
                if ($pdo->query('SHOW TABLES')->fetch() !== false) {
                    throw new RuntimeException('The selected database must still be empty.');
                }
                $key = 'base64:'.base64_encode(random_bytes(32));
                $environment = implode("\n", [
                    'APP_NAME='.installer_env_value($appValues['name']),
                    'APP_ENV=production',
                    'APP_KEY='.$key,
                    'APP_DEBUG=false',
                    'APP_URL='.installer_env_value($appValues['url']),
                    'APP_LOCALE=en',
                    'APP_FALLBACK_LOCALE=en',
                    'LOG_CHANNEL=stack',
                    'LOG_LEVEL=warning',
                    'DB_CONNECTION=mysql',
                    'DB_HOST='.installer_env_value($db['host']),
                    'DB_PORT='.$db['port'],
                    'DB_DATABASE='.installer_env_value($db['name']),
                    'DB_USERNAME='.installer_env_value($db['username']),
                    'DB_PASSWORD='.installer_env_value($db['password']),
                    'BCRYPT_ROUNDS=12',
                    'CACHE_STORE=file',
                    'SESSION_DRIVER=file',
                    'SESSION_LIFETIME=120',
                    'QUEUE_CONNECTION=sync',
                    'FILESYSTEM_DISK=local',
                    'MAIL_MAILER=log',
                    'MAIL_FROM_ADDRESS=noreply@bambodia.com',
                    'MAIL_FROM_NAME='.installer_env_value($appValues['name']),
                    '',
                ]);
                $temporary = $base.'/.env.tmp.'.bin2hex(random_bytes(6));
                if (file_put_contents($temporary, $environment, LOCK_EX) === false) {
                    throw new RuntimeException('Could not write the environment configuration.');
                }
                @chmod($temporary, 0600);
                if (! @rename($temporary, $base.'/.env')) {
                    @unlink($temporary);
                    throw new RuntimeException('Could not activate the environment configuration.');
                }
                try {
                    require_once $base.'/vendor/autoload.php';
                    $application = require $base.'/bootstrap/app.php';
                    $application->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                    $exitCode = \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
                    if ($exitCode !== 0) {
                        throw new RuntimeException('Database migration did not complete.');
                    }
                    \App\Models\User::query()->create([
                        'name' => $admin['name'],
                        'email' => $admin['email'],
                        'password' => $admin['password'],
                        'role' => 'super_admin',
                    ]);
                    $lockHandle = @fopen($storage.'/installed.lock', 'x');
                    if ($lockHandle === false) {
                        throw new RuntimeException('Could not secure the completed installation.');
                    }
                    fwrite($lockHandle, 'Bambodia 0.0.1 - '.gmdate('c'));
                    fclose($lockHandle);
                    @chmod($storage.'/installed.lock', 0600);
                } catch (Throwable $failure) {
                    @unlink($base.'/.env');
                    throw new RuntimeException('Setup could not be completed. Review file permissions and the database, then restart with a clean database.');
                }
                @unlink($secretFile);
                $_SESSION = [];
                session_destroy();
                header('Location: /login?installed=1', true, 303);
                exit;
            }
            throw new RuntimeException('Complete the steps in the displayed order.');
        } catch (Throwable $failure) {
            $error = $failure->getMessage();
        }
    }
}

if ($step > 1 && empty($_SESSION['verified'])) {
    installer_redirect(1);
}
if ($step > 2 && empty($_SESSION['database'])) {
    installer_redirect(2);
}
if ($step > 3 && empty($_SESSION['application'])) {
    installer_redirect(3);
}
if ($step > 4 && empty($_SESSION['administrator'])) {
    installer_redirect(4);
}
$host = preg_replace('/[^a-zA-Z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'bambodia.com'));
$scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$suggestedUrl = $scheme.'://'.($host ?: 'bambodia.com');
$names = ['Requirements', 'Database', 'Application', 'Administrator', 'Finish'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Bambodia — Installation</title>
  <style>
    :root{color-scheme:dark;--bg:#0a1020;--card:#111c31;--border:#30425d;--text:#f6f8fc;--muted:#a7bad5;--accent:#5cd6af}
    *{box-sizing:border-box}body{margin:0;min-height:100vh;font:15px/1.6 system-ui,-apple-system,sans-serif;color:var(--text);background:radial-gradient(ellipse at 80% 10%,#1e4463 0,transparent 45%),var(--bg);display:grid;place-items:center;padding:24px}
    main{width:min(100%,720px);background:var(--card);border:1px solid var(--border);border-radius:24px;padding:clamp(20px,5vw,44px);box-shadow:0 30px 85px #0007}
    .brand{font-size:22px;font-weight:800;letter-spacing:-.05em}.brand span{color:var(--accent)}.eyebrow{color:var(--accent);font-size:11px;letter-spacing:.16em;text-transform:uppercase;font-weight:800;margin-top:18px}
    h1{font-size:clamp(25px,4vw,38px);letter-spacing:-.045em;line-height:1.16;margin:8px 0}p,.hint{color:var(--muted)}
    .stages{display:flex;gap:6px;flex-wrap:wrap;margin:24px 0 30px}.stages span{font-size:11px;padding:7px 11px;border:1px solid var(--border);border-radius:999px;color:var(--muted)}.stages .active{background:var(--accent);color:#06241b;border-color:var(--accent);font-weight:800}
    label{display:block;margin:14px 0 6px;font-size:13px;font-weight:700}input{font:inherit;display:block;width:100%;color:var(--text);background:#0a1425;border:1px solid var(--border);padding:12px 13px;border-radius:12px}input:focus{outline:2px solid var(--accent);outline-offset:1px}
    button{display:inline-flex;border:0;background:var(--accent);color:#05261b;border-radius:12px;padding:13px 22px;font:inherit;font-weight:800;cursor:pointer;margin-top:24px}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media(max-width:520px){.row{grid-template-columns:1fr}}
    .check{display:flex;justify-content:space-between;gap:10px;padding:9px 0;border-bottom:1px solid var(--border);font-size:13px}.check strong{color:#74e4bc}.check .no{color:#ffa8a8}
    .error{border:1px solid #c46b78;background:#522937;padding:13px;border-radius:12px;margin:16px 0}.hint{font-size:13px;margin-top:12px}code{color:#b8f4d9;overflow-wrap:anywhere}a{color:var(--accent)}
  </style>
</head>
<body>
<main>
  <div class="brand">Bambodia<span>.</span></div>
  <div class="eyebrow">Secure setup · Version 0.0.1</div>
  <h1><?= installer_escape($names[$step - 1]) ?></h1>
  <p>Set up Bambodia on your own domain without running database commands from the terminal.</p>
  <div class="stages">
    <?php foreach ($names as $i => $label): ?>
      <span class="<?= ($i + 1) === $step ? 'active' : '' ?>"><?= ($i + 1).' '.installer_escape($label) ?></span>
    <?php endforeach ?>
  </div>
  <?php if ($error): ?><div class="error" role="alert"><?= installer_escape($error) ?></div><?php endif ?>
  <?php if ($step === 1): ?>
    <?php foreach ($checks as $label => $ok): ?>
      <div class="check"><span><?= installer_escape($label) ?></span><strong class="<?= $ok ? '' : 'no' ?>"><?= $ok ? 'Ready' : 'Required' ?></strong></div>
    <?php endforeach ?>
    <p class="hint">The private installation access code is in <code>storage/app/installer-access.txt</code>. Read it using your hosting file manager. It is not a purchase license or a public API token.</p>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= installer_escape($csrf) ?>">
      <input type="hidden" name="action" value="access">
      <label for="access">Installation access code</label>
      <input id="access" name="access" required autocomplete="off">
      <button <?= $ready ? '' : 'disabled' ?>>Continue to database</button>
    </form>
  <?php elseif ($step === 2): ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= installer_escape($csrf) ?>">
      <input type="hidden" name="action" value="database">
      <div class="row"><div><label for="host">Database host</label><input id="host" name="host" value="127.0.0.1" required></div>
      <div><label for="port">Port</label><input id="port" name="port" inputmode="numeric" value="3306" required></div></div>
      <label for="name">Database name</label><input id="name" name="name" required>
      <label for="username">Database username</label><input id="username" name="username" required>
      <label for="password">Database password</label><input id="password" type="password" name="password" autocomplete="new-password">
      <p class="hint">Use a new empty MySQL database. Existing tables are never deleted.</p>
      <button>Verify connection</button>
    </form>
  <?php elseif ($step === 3): ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= installer_escape($csrf) ?>">
      <input type="hidden" name="action" value="application">
      <label for="app_name">Application name</label><input id="app_name" name="app_name" value="Bambodia" required>
      <label for="app_url">Application URL</label><input id="app_url" type="url" name="app_url" value="<?= installer_escape($suggestedUrl) ?>" required>
      <p class="hint">Review the detected URL. Enable HTTPS on your domain before public registration.</p>
      <button>Continue to administrator</button>
    </form>
  <?php elseif ($step === 4): ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= installer_escape($csrf) ?>">
      <input type="hidden" name="action" value="administrator">
      <label for="admin_name">Administrator name</label><input id="admin_name" name="admin_name" required maxlength="120">
      <label for="admin_email">Administrator email</label><input id="admin_email" type="email" name="admin_email" required>
      <label for="admin_password">Password (12 characters minimum)</label><input id="admin_password" type="password" name="admin_password" required minlength="12">
      <label for="confirm_password">Confirm password</label><input id="confirm_password" type="password" name="confirm_password" required minlength="12">
      <button>Review installation</button>
    </form>
  <?php else: ?>
    <p>Application: <strong><?= installer_escape($_SESSION['application']['name']) ?></strong></p>
    <p>Domain: <strong><?= installer_escape($_SESSION['application']['url']) ?></strong></p>
    <p>Database: <strong><?= installer_escape($_SESSION['database']['name']) ?></strong></p>
    <p>Administrator: <strong><?= installer_escape($_SESSION['administrator']['email']) ?></strong></p>
    <p class="hint">Finishing writes a private environment file, runs database migrations, creates the super administrator, and permanently locks the installer.</p>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= installer_escape($csrf) ?>">
      <input type="hidden" name="action" value="install">
      <button>Install Bambodia</button>
    </form>
  <?php endif ?>
</main>
</body>
</html>
