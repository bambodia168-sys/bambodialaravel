#!/usr/bin/env python3
"""Release Bambodia settlement reporting and the existing checkout SMTP check."""

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
SOURCE = 'https://github.com/bambodia168-sys/bambodialaravel.git'
BRANCH = 'feat/live-settlement-statements'
BASE = '4c79cee0539428e040b06fba85f5e08d009d71f4'
CHECKOUT = 'a2b545c4028c33208ace1d3ce808a6914bf6e891'
CHECKOUT_CONTROLLER = 'app/Http/Controllers/CommerceCartController.php'
ADDED = (
    'app/Http/Controllers/CommerceSettlementController.php',
    'app/Models/CommerceOrderOperation.php',
    'app/Support/CommerceSettlementReport.php',
    'public/css/commerce-settlements.css',
    'resources/views/commerce/operations/index.blade.php',
)
MODIFIED = (
    'app/Models/MarketplaceOrder.php',
    'resources/views/layouts/workspace.blade.php',
    'routes/web.php',
)


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def output(*args, **kwargs):
    return subprocess.check_output(args, **kwargs)


def target(name):
    path = APP / name
    require(path.resolve() == path, f'Unexpected path or symlink: {name}')
    return path


def artisan(command):
    subprocess.run(('runuser', '-u', 'www', '--', PHP, 'artisan', command, '--no-interaction'),
                   cwd=APP, check=True)


def verify_host():
    require(os.geteuid() == 0 and os.access(PHP, os.X_OK), 'Run from Bambodia aaPanel as root')
    config = VHOST.read_text()
    require(re.search(r'^\s*server_name\s+bambodia\.com\s*;', config, re.M)
            and re.search(r'^\s*root\s+' + re.escape(str(APP / 'public')) + r'\s*;', config, re.M),
            'Bambodia nginx does not identify the expected web root')
    require(target('storage/app/installed.lock').is_file() and not target('storage/framework/down').exists(),
            'Bambodia must be in its normal installed state')
    require(not target('bootstrap/cache/config.php').exists() and not list(target('bootstrap/cache').glob('routes-*.php')),
            'Unexpected route or config cache')


def runtime():
    return json.loads(output('runuser', '-u', 'www', '--', PHP, cwd=APP, input=rb"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$route = Illuminate\Support\Facades\Route::class;
$schema = Illuminate\Support\Facades\Schema::class;
echo json_encode([
    'orders' => (bool) config('commerce.enabled'),
    'payments' => (bool) config('commerce.payments_enabled'),
    'paid_operations' => (bool) config('commerce.operations_enabled'),
    'live_gateways' => (bool) config('commerce.live_gateways'),
    'checkout' => $route::has('checkout') && $route::has('checkout.verification'),
    'dashboard_smtp' => $app->make('mail.manager')->getDefaultDriver() === 'dashboard-smtp',
    'settlement_ledger' => $schema::hasTable('commerce_order_operations'),
    'routes' => [
        $route::has('dashboard.commerce-operations'),
        $route::has('dashboard.commerce-operations.statement'),
        $route::has('studio.commerce-operations'),
        $route::has('studio.commerce-operations.statement'),
    ],
    'payment_schema' => $schema::hasColumns('commerce_payments', ['status', 'live_mode', 'settlement_reference']),
], JSON_THROW_ON_ERROR);
"""))


def prepare(repo, stage, sha):
    previous = {}
    for name in (*ADDED, *MODIFIED, CHECKOUT_CONTROLLER):
        live = target(name)
        copy = stage / name
        copy.parent.mkdir(parents=True, exist_ok=True)
        if name in ADDED:
            require(not live.exists(), f'Release path already exists: {name}')
        else:
            require(live.is_file(), f'Missing production file: {name}')
            previous[name] = live.read_bytes()
            copy.write_bytes(previous[name])

    patch = output('git', '-C', str(repo), 'diff', '--binary', '--unified=1', BASE, sha, '--', *ADDED, *MODIFIED)
    checkout_patch = output('git', '-C', str(repo), 'diff', '--unified=1', CHECKOUT+'^', CHECKOUT, '--', CHECKOUT_CONTROLLER)
    require(checkout_patch.count(b'\n+        if (') == 1 and b"getDefaultDriver()" in checkout_patch,
            'Checkout SMTP patch does not match the verified single-line change')
    for changes in (patch, checkout_patch):
        subprocess.run(('git', 'apply', '--check', '--whitespace=error'), cwd=stage, input=changes, check=True)
        subprocess.run(('git', 'apply', '--whitespace=error'), cwd=stage, input=changes, check=True)
    for name in (*ADDED, *MODIFIED, CHECKOUT_CONTROLLER):
        staged = stage / name
        if staged.suffix == '.php' and not name.endswith('.blade.php'):
            output(PHP, '-l', str(staged))
    return previous


def replace(path, contents, metadata, mode):
    fd, name = tempfile.mkstemp(prefix='.settlement-release-', dir=path.parent)
    temporary = Path(name)
    try:
        with os.fdopen(fd, 'wb') as handle:
            handle.write(contents)
        os.chown(temporary, metadata.st_uid, metadata.st_gid)
        temporary.chmod(mode)
        temporary.replace(path)
    finally:
        temporary.unlink(missing_ok=True)


def verify_http():
    paths = (
        ('/', '200'),
        ('/dashboard/commerce-operations', '302'),
        ('/dashboard/commerce-operations/statement.csv', '302'),
        ('/studio/sales-settlements', '302'),
        ('/studio/sales-settlements/statement.csv', '302'),
        ('/css/commerce-settlements.css', '200'),
    )
    for path, expected in paths:
        status = output('curl', '-sS', '--max-time', '15', '-o', '/dev/null', '-w', '%{http_code}',
                        f'https://bambodia.com{path}?settlement_release={time.time_ns()}').decode()
        require(status == expected, f'{path} returned HTTP {status}, expected {expected}')


def install(stage, previous, before):
    backup = Path(tempfile.mkdtemp(prefix='bambodia-settlement-files.', dir='/root'))
    print(f'FILE_BACKUP={backup}', flush=True)
    for name, contents in previous.items():
        live = target(name)
        require(live.read_bytes() == contents, f'Production file changed during preflight: {name}')
        saved = backup / name
        saved.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(live, saved)
        metadata = live.stat()
        os.chown(saved, metadata.st_uid, metadata.st_gid)

    applied = []
    try:
        # Register routes before exposing links in the shared workspace.
        files = (*ADDED, 'app/Models/MarketplaceOrder.php', CHECKOUT_CONTROLLER,
                 'routes/web.php', 'resources/views/layouts/workspace.blade.php')
        for name in files:
            live = target(name)
            exists = name in previous
            require(live.read_bytes() == previous[name] if exists else not live.exists(),
                    f'Production file changed during install: {name}')
            if not live.parent.exists():
                live.parent.mkdir(mode=0o755)
                live.parent.chmod(0o755)
            metadata = (live if exists else target('artisan')).stat()
            replace(live, (stage / name).read_bytes(), metadata, stat.S_IMODE(metadata.st_mode) if exists else 0o644)
            applied.append(name)
        artisan('view:cache')
        after = runtime()
        require(after == dict(before, routes=[True] * 4), 'Live routes or commerce gates failed verification')
        require(target(CHECKOUT_CONTROLLER).read_bytes() == (stage / CHECKOUT_CONTROLLER).read_bytes(),
                'Checkout SMTP file differs from the verified release')
        verify_http()
    except (Exception, KeyboardInterrupt):
        for name in reversed(applied):
            saved = backup / name
            if name in previous:
                metadata = saved.stat()
                replace(target(name), saved.read_bytes(), metadata, stat.S_IMODE(metadata.st_mode))
            else:
                target(name).unlink(missing_ok=True)
        artisan('view:clear')
        print('SETTLEMENT_FILES_RESTORED', flush=True)
        raise
    print('BAMBODIA_SETTLEMENTS_LIVE=PASS', flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('release')
    options = parser.add_mutually_exclusive_group(required=True)
    options.add_argument('--check', action='store_true')
    options.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    require(re.fullmatch(r'[0-9a-f]{40}', args.release), 'Provide the verified release SHA')
    os.umask(0o077)
    verify_host()
    with open('/root/bambodia-settlement-rollout.lock', 'a') as lock, tempfile.TemporaryDirectory(
            prefix='bambodia-settlement-release.', dir='/root') as directory:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        before = runtime()
        require(before['orders'] and before['checkout'] and before['payment_schema'] and not any(before['routes'])
                and not (before['payments'] or before['paid_operations'] or before['live_gateways']),
                'Unexpected production checkout, settlement routes or payment activation state')
        work = Path(directory)
        repo, stage = work / 'repo', work / 'overlay'
        subprocess.run(('git', 'clone', '--quiet', '--depth=2', '--single-branch', '--branch', BRANCH,
                        SOURCE, str(repo)), check=True)
        require(output('git', '-C', str(repo), 'rev-parse', 'HEAD', 'HEAD^').decode().split() == [args.release, BASE],
                'Release SHA or production baseline differs')
        subprocess.run(('git', '-C', str(repo), 'fetch', '--quiet', '--depth=2', 'origin', CHECKOUT), check=True)
        require(output('git', '-C', str(repo), 'rev-parse', 'FETCH_HEAD').decode().strip() == CHECKOUT,
                'Checkout patch SHA differs')
        previous = prepare(repo, stage, args.release)
        print('BAMBODIA_SETTLEMENT_PREFLIGHT=PASS', flush=True)
        if args.apply:
            install(stage, previous, before)


if __name__ == '__main__':
    main()
