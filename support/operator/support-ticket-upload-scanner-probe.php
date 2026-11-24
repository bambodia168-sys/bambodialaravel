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
    // Known-clean 2x2 RGB PNG: re-encoded and independently PNG-verified.
    // An earlier probe fixture had an invalid IDAT checksum; never use
    // malformed sample data when determining production scanner readiness.
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGMUabrEwMDAxMDAwMDAAAAQTAFs/wbMlQAAAABJRU5ErkJggg==',
        true
    );
    $size = is_string($png) ? @getimagesizefromstring($png) : false;
    if (! is_array($size) || ($size['mime'] ?? '') !== 'image/png'
        || ($size[0] ?? 0) !== 2 || ($size[1] ?? 0) !== 2
        || file_put_contents($source, $png) !== strlen($png)) {
        throw new RuntimeException('Could not verify the harmless PNG scan fixture.');
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

    // If screening failed, classify Symfony Process errors using the same
    // harmless temporary PNG. Do not print actual user files or credentials.
    if (($temporary['status'] ?? '') === 'error') {
        $binary = (string) config('vault.scanner_binary', '');
        if ($binary !== '' && is_file($binary) && is_executable($binary)) {
            $timeout = max(10, min(180, (int) config('vault.scanner_timeout_seconds', 90)));
            $proc = new \Symfony\Component\Process\Process([
                $binary, '--no-summary', '--infected', '--stdout', $source,
            ]);
            $proc->setTimeout($timeout);
            $started = microtime(true);
            try {
                $proc->run();
                echo 'Diagnostic ClamAV exit code: ', (string) $proc->getExitCode(), PHP_EOL;
                $stderr = trim($proc->getErrorOutput());
                if ($stderr !== '') {
                    // This probe scans generated harmless bytes, never customer content.
                    echo 'Diagnostic stderr: ', mb_substr(str_replace($source, '[sample]', $stderr), 0, 500), PHP_EOL;
                }
            } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $error) {
                echo 'Diagnostic: ClamAV exceeded the configured ', $timeout, '-second timeout', PHP_EOL;
            } catch (\Symfony\Component\Process\Exception\ProcessSignaledException $error) {
                echo 'Diagnostic: ClamAV process was terminated by signal', PHP_EOL;
            } catch (\Throwable $error) {
                echo 'Diagnostic process exception: ', $error::class, PHP_EOL;
            }
            echo 'Diagnostic elapsed seconds: ', number_format(microtime(true) - $started, 1), PHP_EOL;
        }
    }

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
