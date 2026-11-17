#!/usr/bin/env python3
"""Apply the scoped Payment Methods popup release to Bambodia."""

import argparse
import fcntl
import os
import re
import shutil
import subprocess
import tempfile
import time
from pathlib import Path


APP = Path('/www/wwwroot/bambodia-staging-qa')
VHOST = Path('/www/server/panel/vhost/nginx/bambodia.com.conf')
PHP = '/www/server/php/85/bin/php'
SOURCE = 'https://github.com/bambodia168-sys/bambodialaravel.git'
BRANCH = 'feat/payment-method-popup'
BASE = '864a8477e3efc1709ffcd9241b0d371dce5d76e5'
FILES = (
    'app/Models/CommercePaymentMethod.php',
    'app/Http/Controllers/CommercePaymentMethodController.php',
    'resources/views/commerce/methods.blade.php',
)


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def output(*args, **kwargs):
    return subprocess.check_output(args, **kwargs)


def artisan(command):
    subprocess.run(('runuser', '-u', 'www', '--', PHP, 'artisan', command, '--no-interaction'),
                   cwd=APP, check=True)


def prepare(repo, stage, sha):
    originals = {}
    for name in FILES:
        live, copy = APP / name, stage / name
        require(live.is_file() and live.resolve() == live, f'Unexpected live file: {name}')
        copy.parent.mkdir(parents=True, exist_ok=True)
        originals[name] = live.read_bytes()
        copy.write_bytes(originals[name])
    patch = output('git', '-C', str(repo), 'diff', '--binary', '--unified=2', BASE, sha, '--', *FILES)
    require(patch.count(b'\ndiff --git ') == len(FILES) - 1, 'Release diff misses a payment-method file')
    for options in (('--check',), ()):
        subprocess.run(('git', 'apply', '--whitespace=error', *options),
                       cwd=stage, input=patch, check=True)
    for name in FILES[:2]:
        output(PHP, '-l', str(stage / name))
    return originals


def replace(path, contents, original):
    fd, name = tempfile.mkstemp(prefix='.payment-methods-', dir=path.parent)
    temporary = Path(name)
    try:
        with os.fdopen(fd, 'wb') as handle:
            handle.write(contents)
        meta = original.stat()
        os.chown(temporary, meta.st_uid, meta.st_gid)
        temporary.chmod(meta.st_mode & 0o777)
        temporary.replace(path)
    finally:
        temporary.unlink(missing_ok=True)


def install(stage, originals):
    backup = Path(tempfile.mkdtemp(prefix='bambodia-payment-popup-files.', dir='/root'))
    for name, contents in originals.items():
        source, saved = APP / name, backup / name
        saved.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(source, saved)
        require(saved.read_bytes() == contents, f'Live file changed after preflight: {name}')
        meta = source.stat()
        os.chown(saved, meta.st_uid, meta.st_gid)
    print(f'FILE_BACKUP={backup}', flush=True)
    applied = []
    try:
        for name in FILES:
            current = APP / name
            require(current.read_bytes() == originals[name], f'Live file changed during install: {name}')
            replace(current, (stage / name).read_bytes(), backup / name)
            applied.append(name)
        artisan('view:cache')
        for path, expected in (('/', '200'), ('/dashboard/payment-methods', '302'),
                               ('/dashboard/commerce-operations', '302')):
            code = output('curl', '-fsS', '--max-time', '15', '-o', '/dev/null', '-w', '%{http_code}',
                          f'https://bambodia.com{path}?payment_popup_release={time.time_ns()}').decode()
            require(code == expected, f'{path} returned {code}, expected {expected}')
    except (Exception, KeyboardInterrupt):
        for name in reversed(applied):
            saved = backup / name
            replace(APP / name, saved.read_bytes(), saved)
        artisan('view:clear')
        print('PAYMENT_METHOD_FILES_RESTORED', flush=True)
        raise
    print('BAMBODIA_PAYMENT_POPUP_LIVE=PASS', flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('release')
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--check', action='store_true')
    mode.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    require(re.fullmatch(r'[0-9a-f]{40}', args.release), 'Provide the verified release SHA')
    require(os.geteuid() == 0 and os.access(PHP, os.X_OK), 'Run in the authorized Bambodia aaPanel console')
    config = VHOST.read_text()
    require(re.search(r'^\s*server_name\s+bambodia\.com\s*;', config, re.M)
            and re.search(r'^\s*root\s+' + re.escape(str(APP / 'public')) + r'\s*;', config, re.M),
            'Bambodia nginx web root differs')
    require((APP / 'storage/app/installed.lock').is_file() and not (APP / 'storage/framework/down').exists(),
            'Bambodia must be operating normally')
    os.umask(0o077)
    with open('/root/bambodia-payment-popup-rollout.lock', 'a') as lock, tempfile.TemporaryDirectory(
            prefix='bambodia-payment-popup-release.', dir='/root') as work:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        repo, stage = Path(work) / 'repo', Path(work) / 'overlay'
        subprocess.run(('git', 'clone', '--quiet', '--depth=2', '--single-branch', '--branch', BRANCH,
                        SOURCE, str(repo)), check=True)
        require(output('git', '-C', str(repo), 'rev-parse', 'HEAD', 'HEAD^').decode().split() == [args.release, BASE],
                'Release SHA or production baseline differs')
        originals = prepare(repo, stage, args.release)
        print('BAMBODIA_PAYMENT_POPUP_PREFLIGHT=PASS', flush=True)
        if args.apply:
            install(stage, originals)


if __name__ == '__main__':
    main()
