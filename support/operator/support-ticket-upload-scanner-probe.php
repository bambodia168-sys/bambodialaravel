<?php
/**
 * Read-only with respect to production tickets/DB.
 * Exercises the configured ClamAV scanner on a benign temporary upload and an
 * identical copied file in Laravel's private Support Ticket storage location.
 *
 * Run as site account:
 * cd /www/wwwroot/bambodia-staging-qa &&
 * runuser -u www -- /www/server/php/85/bin/php /tmp/support-ticket-upload-scanner-probe.php
 */
declare(strict_types=1);

$site = '/www/wwwroot/bambodia-staging-qa';
if (PHP_SAPI !== 'cli' || ! is_file($site.'/vendor/autoload.php')) {
    fwrite(STDERR, "STOP: Unexpected CLI environment\n");
    exit(2);
}

require $site.'/vendor/autoload.php';
$app = require $site.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$disk = \Illuminate\Support\Facades\Storage::disk('local');
$scanner = app(\App\Support\VaultMalwareScanner::class);
$source = tempnam(sys_get_temp_dir(), 'bambodia-support-upload-');
$relative = 'support-ticket-attachments/_diagnostic/'.\Illuminate\Support\Str::uuid();

if ($source === false) {
    fwrite(STDERR, "STOP: Unable to allocate upload probe\n");
    exit(2);
}
$exit = 1;

try {
    // Known harmless 1x1 PNG fixture; not customer content, no sensitive bytes.
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aR3sAAAAASUVORK5CYII=',
        true
    );
    if (! is_string($png) || file_put_contents($source, $png) !== strlen($png)) {
        throw new RuntimeException('Could not create harmless scan fixture.');
    }
    if (! $disk->put($relative, $png)) {
        throw new RuntimeException('Cannot write to private support-ticket storage.');
    }

    $copy = $disk->path($relative);
    if (! is_file($copy) || ! is_readable($copy)
        || ! hash_equals(hash_file('sha256', $source), hash_file('sha256', $copy))) {
        throw new RuntimeException('Private upload copy cannot be read or hash does not match.');
    }

    $temporary = $scanner->scanFile($source);
    $private = $scanner->scanFile($copy);

    foreach ([
        'Original PHP temporary upload' => $temporary,
        'Saved private attachment' => $private,
    ] as $name => $result) {
        echo $name, ': ', $result['status'] ?? 'error', PHP_EOL;
        echo '  ', $result['note'] ?? 'No scanner note provided', PHP_EOL;
    }

    echo 'SHA-256 identity: PASS', PHP_EOL;

    if (($temporary['status'] ?? '') !== 'clean') {
        echo 'STOP: scanner cannot verify even the original temporary file; do not deploy the upload fix.', PHP_EOL;
        $exit = 2;
    } elseif (($private['status'] ?? '') !== 'clean') {
        echo 'DIAGNOSIS: original upload scans clean, but saved private copy is inaccessible to scanner.', PHP_EOL;
        $exit = 0;
    } else {
        echo 'PASS: scanner accepts both paths. Original screenshot rejection requires further investigation.', PHP_EOL;
        $exit = 0;
    }
} catch (Throwable $e) {
    // Only controlled diagnostics; do not emit stack traces or storage paths.
    fwrite(STDERR, "STOP: ". $e->getMessage() . PHP_EOL);
    $exit = 2;
} finally {
    $disk->delete($relative);
    @unlink($source);
}
exit($exit);
