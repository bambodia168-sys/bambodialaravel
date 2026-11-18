import fcntl
import json
import os
import re
import shutil
import stat
import subprocess as sp
import sys
import tempfile
import time
from pathlib import Path

APP = Path('/www/wwwroot/bambodia-staging-qa')
VHOST = Path('/www/server/panel/vhost/nginx/bambodia.com.conf')
PHP = '/www/server/php/85/bin/php'
URL = 'https://bambodia.com'
REPO = 'https://github.com/bambodia168-sys/bambodialaravel.git'
BASE = 'aa20c38d41489741be1117b375ba8216491d6d8d'
RELEASE = 'a2c62f76f178acdd35105e669ddc005f6863dc9f'
TREE = '68f71992b9092d67e81e121102867bf72c445f1e'
COLLECTION = 'app/Support/CommerceCollection.php'
ROUTES = 'routes/web.php'
FILES = [
    COLLECTION,
    *[f'app/Http/Controllers/{name}Controller.php' for name in (
        'CommerceCart', 'CommerceOrder', 'CommercePayment', 'CommercePaymentMethod',
        'CommerceSettlement', 'Dashboard/FeatureManager',
    )],
    'routes/console.php',
    ROUTES,
    'public/js/commerce-payment-poll.js',
    *[f'resources/views/commerce/{name}.blade.php' for name in ('cart', 'create', 'methods', 'payments', 'show')],
]
REFERENCE_FILES = ['tests/Feature/CommercePaymentsTest.php', 'tests/browser/commerce-pilot.mjs', 'docs/commerce-payments.md']
PROCESSING_ROUTE = 'dashboard.payment-methods.processing'


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def git(repo, *args):
    return sp.check_output(['git', *args], cwd=repo)


def artisan(*args):
    return sp.check_output(['runuser', '-u', 'www', '--', PHP, 'artisan', *args, '--no-interaction'], cwd=APP)


def app_health():
    for path, expected in [('/', '200'), ('/login', '200'), ('/dashboard/payment-methods', '302')]:
        status = sp.check_output(['curl', '-sS', '--max-time', '15', '-o', '/dev/null', '-w', '%{http_code}',
                                  f'{URL}{path}?payment_controls_release={time.time_ns()}']).decode()
        require(status == expected, f'Unexpected HTTP status for {path}: {status}')


def install(path, contents, metadata):
    fd, name = tempfile.mkstemp(prefix='.bambodia-payment-controls-', dir=path.parent)
    temp = Path(name)
    try:
        with os.fdopen(fd, 'wb') as handle:
            handle.write(contents)
        os.chown(temp, metadata.st_uid, metadata.st_gid)
        temp.chmod(stat.S_IMODE(metadata.st_mode))
        temp.replace(path)
    finally:
        temp.unlink(missing_ok=True)


def checked_source(work):
    repo = work / 'source'
    repo.mkdir()
    sp.run(['git', 'init', '--quiet', str(repo)], check=True)
    sp.run(['git', 'fetch', '--quiet', '--depth=2', REPO, RELEASE], cwd=repo, check=True, timeout=120)
    identity = git(repo, 'rev-parse', 'FETCH_HEAD', 'FETCH_HEAD^1', 'FETCH_HEAD^{tree}').decode().split()
    require(identity == [RELEASE, BASE, TREE], 'Fetched commit differs from the reviewed release')
    actual = git(repo, 'diff', '--name-only', BASE, RELEASE).decode().splitlines()
    require(set(actual) == set(FILES + REFERENCE_FILES), 'Unexpected changed repository paths')
    return repo


def build_plan(work, repo):
    plan = {}
    for name in FILES:
        path = APP / name
        require(path.parent.is_dir() and path.resolve() == path
                and (path.is_file() or (name == COLLECTION and not path.exists())), f'Unexpected live file: {name}')
        current = path.read_bytes() if path.exists() else None
        baseline = git(repo, 'show', f'{BASE}:{name}') if name != COLLECTION else None
        incoming = git(repo, 'show', f'{RELEASE}:{name}')
        if current not in (baseline, incoming):
            require(baseline is not None, f'Unexpected existing live file: {name}')
            (work / 'base').write_bytes(baseline)
            (work / 'incoming').write_bytes(incoming)
            merged = sp.run(['git', 'merge-file', '-p', str(path), str(work / 'base'), str(work / 'incoming')],
                            capture_output=True)
            require(merged.returncode == 0, f'Live changes overlap the release in {name}')
            incoming = merged.stdout
            print(f'PRESERVED_LIVE_EDITS={name}', flush=True)
        if name.endswith('.php'):
            lint = work / 'lint.php'
            lint.write_bytes(incoming)
            sp.run([PHP, '-l', str(lint)], check=True, stdout=sp.DEVNULL)
        metadata = path.stat() if path.exists() else (APP / 'app/Support/CommerceOffers.php').stat()
        plan[name] = (path, current, incoming, metadata)
    return plan


def payment_state():
    probe = r"""
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$flag = App\Models\FeatureFlag::query()->where('key', 'commerce_payment_collection')->value('enabled');
echo json_encode([
    'workspaces' => (bool) config('commerce.enabled'),
    'collection' => (bool) config('commerce.enabled') && (bool) ($flag ?? config('commerce.payments_enabled')),
    'live_gateways' => (bool) config('commerce.live_gateways'),
    'has_override' => $flag !== null,
], JSON_THROW_ON_ERROR);
"""
    return json.loads(sp.check_output(['runuser', '-u', 'www', '--', PHP, '-r', probe], cwd=APP))


def installed_bytes(path):
    return path.read_bytes() if path.exists() else None


def main(apply):
    require(os.geteuid() == 0, 'Run in the aaPanel root shell')
    config = VHOST.read_text()
    require(re.search(r'^\s*server_name\s+bambodia\.com\s*;', config, re.M)
            and re.search(r'^\s*root\s+' + re.escape(str(APP / 'public')) + r'\s*;', config, re.M),
            'Bambodia production document root differs from the established deployment target')
    require((APP / 'storage/app/installed.lock').is_file() and not (APP / 'storage/framework/down').exists(),
            'Bambodia is not running normally')

    work = Path(tempfile.mkdtemp(prefix='bambodia-payment-controls.', dir='/root'))
    plan = build_plan(work, checked_source(work))
    previous_state = payment_state()
    require(previous_state['workspaces'], 'Bambodia commerce workspaces are unavailable')
    app_health()
    print('BAMBODIA_PAYMENT_CONTROLS_PREFLIGHT=PASS', flush=True)
    if not apply:
        return

    for name, (path, current, incoming, metadata) in plan.items():
        require(installed_bytes(path) == current, f'Concurrent edit in {name}')
        if current is None:
            continue
        backup = work / 'backup' / name
        backup.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(path, backup)
        require(backup.read_bytes() == current and path.read_bytes() == current, f'Concurrent edit in {name}')
    print(f'BACKUP={work / "backup"}', flush=True)

    cache_routes = any((APP / 'bootstrap/cache').glob('routes-*.php'))
    changed = []
    try:
        for name in FILES:
            path, current, incoming, metadata = plan[name]
            require(installed_bytes(path) == current, f'Concurrent edit in {name}')
            if current != incoming:
                install(path, incoming, metadata)
                changed.append(name)
            if name == ROUTES and cache_routes:
                artisan('route:cache')
        artisan('view:cache')
        routes = json.loads(artisan('route:list', '--json', '--path=dashboard/payment-methods'))
        controls = [item for item in routes if item.get('name') == PROCESSING_ROUTE]
        require(len(controls) == 1 and controls[0]['method'] == 'PATCH'
                and any(m.endswith(':super_admin') for m in controls[0]['middleware']),
                'Expected one super-admin payment controls route')
        require(all(path.read_bytes() == incoming for path, _, incoming, _ in plan.values()),
                'Installed files differ from the validated plan')
        require(payment_state() == previous_state, 'Payment settings changed during deployment')
        app_health()
    except BaseException:
        for name in reversed(changed):
            path, current, incoming, metadata = plan[name]
            if current is None:
                path.unlink(missing_ok=True)
            else:
                install(path, current, metadata)
        if cache_routes:
            artisan('route:cache')
        artisan('view:cache')
        print('BAMBODIA_PAYMENT_CONTROLS_ROLLBACK=PASS', flush=True)
        raise
    print('PAYMENT_COLLECTION=' + ('ENABLED' if previous_state['collection'] else 'PAUSED'), flush=True)
    print('LIVE_GATEWAYS=' + ('ENABLED' if previous_state['live_gateways'] else 'DISABLED'), flush=True)
    print('BAMBODIA_PAYMENT_CONTROLS_LIVE=PASS', flush=True)


if __name__ == '__main__':
    os.umask(0o077)
    require(sys.argv[1:] in (['preflight'], ['apply']), 'Use preflight or apply')
    with open('/run/lock/bambodia-marketplace-transfer.lock', 'a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        main(sys.argv[1] == 'apply')
