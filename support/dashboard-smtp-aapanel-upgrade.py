#!/usr/bin/env python3
"""Install the standalone SMTP dashboard release on the established aaPanel site."""

import argparse
import fcntl
import json
import os
import re
import shutil
import stat
import subprocess
import tempfile
import time
from pathlib import Path


APP = Path('/www/wwwroot/bambodia-staging-qa')
VHOST = Path('/www/server/panel/vhost/nginx/bambodia.com.conf')
PHP = '/www/server/php/85/bin/php'
BASE = 'c270f1dcf801c18b1125dee6707e8dffafd74fb9'
SOURCE = 'https://github.com/bambodia168-sys/bambodialaravel.git'
BRANCH = 'feat/dashboard-smtp-production'
MIGRATION = 'database/migrations/2026_11_17_150000_create_mail_delivery_settings.php'
BACKUP_SCRIPT = 'support/builder-studio-db-backup.sh'
ADDED = (
    'app/Http/Controllers/Dashboard/SmtpSettingsController.php',
    'app/Models/MailDeliverySetting.php',
    'app/Support/DashboardMailManager.php',
    MIGRATION,
    'public/css/smtp-settings.css',
    'resources/views/dashboard/smtp-settings.blade.php',
)
MODIFIED = (
    'app/Http/Controllers/Dashboard/PolicyManagerController.php',
    'app/Models/AuditEvent.php',
    'app/Providers/AppServiceProvider.php',
    'resources/views/dashboard/site-settings.blade.php',
    'resources/views/layouts/workspace.blade.php',
    'routes/web.php',
)


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def output(*args, **kwargs):
    return subprocess.check_output(args, **kwargs)


def artisan(*args):
    subprocess.run(
        ('runuser', '-u', 'www', '--', PHP, 'artisan', *args, '--no-interaction'),
        cwd=APP, check=True,
    )


def target(name):
    path = APP / name
    require(path.resolve() == path, f'Unexpected path or symlink: {name}')
    return path


def write(target_file, data, metadata, mode):
    descriptor, name = tempfile.mkstemp(prefix='.bambodia-smtp-', dir=target_file.parent)
    temporary = Path(name)
    try:
        with os.fdopen(descriptor, 'wb') as handle:
            handle.write(data)
        os.chown(temporary, metadata.st_uid, metadata.st_gid)
        temporary.chmod(mode)
        temporary.replace(target_file)
    finally:
        temporary.unlink(missing_ok=True)


def verify_host():
    require(os.geteuid() == 0 and os.access(PHP, os.X_OK), 'Run from Bambodia aaPanel as root')
    config = VHOST.read_text()
    require(re.search(r'^\s*server_name\s+bambodia\.com\s*;', config, re.M)
            and re.search(r'^\s*root\s+' + re.escape(str(APP / 'public')) + r'\s*;', config, re.M),
            'Nginx does not identify the expected Bambodia web root')
    require(target('storage/app/installed.lock').is_file() and not target('storage/framework/down').exists(),
            'Bambodia must be in its normal installed state')
    require(not target('bootstrap/cache/config.php').exists()
            and not list(target('bootstrap/cache').glob('routes-*.php')),
            'Clear a verified stale route/config cache before applying this release')
    transport = target('vendor/symfony/mailer/Transport/Smtp/EsmtpTransportFactory.php')
    require("'require_tls'" in transport.read_text(), 'Installed SMTP transport cannot enforce TLS')


def runtime():
    return json.loads(output('runuser', '-u', 'www', '--', PHP, cwd=APP, input=rb"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$schema = Illuminate\Support\Facades\Schema::class;
$db = Illuminate\Support\Facades\DB::class;
$installed = $schema::hasTable('mail_delivery_settings');
echo json_encode([
    'installed' => $installed,
    'initialized' => $installed && $db::table('mail_delivery_settings')->where('id', 1)->exists(),
    'active' => $installed && $db::table('mail_delivery_settings')->where('id', 1)->whereNotNull('active')->exists(),
    'route' => Illuminate\Support\Facades\Route::has('dashboard.smtp.index'),
    'manager' => get_class($app->make('mail.manager')),
    'driver' => $app->make('mail.manager')->getDefaultDriver(),
], JSON_THROW_ON_ERROR);
"""))


def http_checks():
    for path, expected in (
        ('/', '200'),
        ('/dashboard/system-settings', '302'),
        ('/dashboard/system-settings/smtp', '302'),
        ('/css/smtp-settings.css', '200'),
    ):
        status = output(
            'curl', '-sS', '--max-time', '15', '-o', '/dev/null', '-w', '%{http_code}',
            f'https://bambodia.com{path}?smtp_rollout={time.time_ns()}',
        ).decode()
        require(status == expected, f'{path} returned HTTP {status}, expected {expected}')


def prepare(repo, stage, sha):
    originals = {}
    for name in (*ADDED, *MODIFIED):
        current = target(name)
        copy = stage / name
        copy.parent.mkdir(parents=True, exist_ok=True)
        if name in MODIFIED:
            require(current.is_file(), f'Missing live file: {name}')
            shutil.copy2(current, copy)
            originals[name] = copy.read_bytes()
        else:
            require(not current.exists(), f'File already exists: {name}')
    patch = output('git', '-C', str(repo), 'diff', '--binary', BASE, sha, '--', *ADDED, *MODIFIED)
    subprocess.run(('git', 'apply', '--check', '--whitespace=error'), cwd=stage, input=patch, check=True)
    subprocess.run(('git', 'apply', '--whitespace=error'), cwd=stage, input=patch, check=True)
    for name in (*ADDED, *MODIFIED):
        staged = stage / name
        if staged.suffix == '.php' and not name.endswith('.blade.php'):
            output(PHP, '-l', str(staged))
    return originals


def apply(repo, stage, before, originals):
    backup_tool = stage / 'backup-db.sh'
    backup_tool.write_bytes(output('git', '-C', str(repo), 'show', f'{BASE}:{BACKUP_SCRIPT}'))
    backup_path = output('bash', str(backup_tool), cwd=APP).decode().strip()
    print(f'DATABASE_BACKUP={backup_path}', flush=True)
    backup = Path(tempfile.mkdtemp(prefix='bambodia-smtp-files.', dir='/root'))
    print(f'FILE_BACKUP={backup}', flush=True)
    for name in MODIFIED:
        saved = backup / name
        saved.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(target(name), saved)
        require(saved.read_bytes() == originals[name], f'Live file changed during deployment: {name}')
        original_metadata = target(name).stat()
        os.chown(saved, original_metadata.st_uid, original_metadata.st_gid)

    applied = []
    try:
        for name in (*ADDED, *MODIFIED):
            destination = target(name)
            saved = backup / name
            require(destination.read_bytes() == saved.read_bytes() if name in MODIFIED
                    else not destination.exists(), f'Live file changed during deployment: {name}')
            metadata = (saved if name in MODIFIED else target('artisan')).stat()
            write(destination, (stage / name).read_bytes(), metadata,
                  stat.S_IMODE(metadata.st_mode) if name in MODIFIED else 0o644)
            applied.append(name)
            if name == MIGRATION:
                artisan('migrate', '--path=' + MIGRATION, '--force')
        artisan('view:cache')
        after = runtime()
        require(after['initialized'] and not after['active'] and after['route']
                and after['manager'] == 'App\\Support\\DashboardMailManager'
                and after['driver'] == before['driver'], 'SMTP settings or mail fallback verification failed')
        http_checks()
    except (Exception, KeyboardInterrupt):
        # Keep the additive database table and migration if it reached MySQL.
        for name in reversed(applied):
            saved = backup / name
            if name in MODIFIED:
                metadata = saved.stat()
                write(target(name), saved.read_bytes(), metadata, stat.S_IMODE(metadata.st_mode))
            elif name != MIGRATION:
                target(name).unlink(missing_ok=True)
        artisan('view:clear')
        print('SMTP_APPLICATION_FILES_RESTORED', flush=True)
        raise
    print('BAMBODIA_SMTP_LIVE=PASS', flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('release')
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--check', action='store_true')
    mode.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    require(re.fullmatch(r'[0-9a-f]{40}', args.release), 'Provide the verified GitHub release SHA')
    os.umask(0o077)
    verify_host()
    with open('/root/bambodia-smtp-rollout.lock', 'a') as lock, tempfile.TemporaryDirectory(
            prefix='bambodia-smtp-release.', dir='/root') as temporary:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        before = runtime()
        require(not before['installed'] and not before['route'], 'SMTP Setup is already present or partly installed')
        work = Path(temporary)
        repo, stage = work / 'repo', work / 'overlay'
        subprocess.run(
            ('git', 'clone', '--quiet', '--depth=2', '--single-branch', '--branch', BRANCH, SOURCE, str(repo)), check=True,
        )
        require(output('git', '-C', str(repo), 'rev-parse', 'HEAD').decode().strip() == args.release,
                'Release SHA differs')
        require(output('git', '-C', str(repo), 'rev-parse', 'HEAD^').decode().strip() == BASE,
                'Release parent differs from the verified production baseline')
        originals = prepare(repo, stage, args.release)
        print('BAMBODIA_SMTP_PREFLIGHT=PASS', flush=True)
        if args.apply:
            apply(repo, stage, before, originals)


if __name__ == '__main__':
    main()
