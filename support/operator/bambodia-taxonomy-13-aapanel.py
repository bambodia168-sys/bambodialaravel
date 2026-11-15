#!/usr/bin/env python3
"""Apply the tested Bambodia taxonomy files to the existing aaPanel installation."""

import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import tempfile

APP = Path("/www/wwwroot/bambodia-staging-qa")
PHP = "/www/server/php/85/bin/php"
SITE = "https://bambodia.com"
VHOST = Path("/www/server/panel/vhost/nginx/bambodia.com.conf")
REPO = "bambodia168-sys/bambodialaravel"
RELEASE = "d01f6f391746f252eb308836b2a99dcd487a803a"
MIGRATION = "database/migrations/2026_11_15_110000_enable_bambodia_nested_categories.php"
MANIFEST = {'app/Console/Commands/VerifyBambodiaTaxonomyThirteen.php': [None, 'e912dbba8a19b6c792fce4e9daa845a256cae29d'],
    'app/Http/Controllers/BuilderController.php': ['1be8c19478a8258f9e95a7a88f57f9269f929864', '572f0c7c56194863b06c91fc8ec74b3aef1b1f96'],
    'app/Http/Controllers/Dashboard/CategoryManagerController.php': ['48d9b3dbd600702bd2a07671f126f31f6e715866', '8de0a031ec763b55cac3545355ec447df924835f'],
    'app/Http/Controllers/JobBoardController.php': ['a6b6aeaee7cfbd194d8ea7df8107b9d9fd7841a1', '163115d443979977059da0118b1a835955af4ffc'],
    'app/Http/Controllers/Marketplace/ListingController.php': ['ababc5510445710f32f641671b4c27b6420bb2d4', '77c6fa32431a2c04b95986397fd3cb89499833f8'],
    'app/Models/MarketplaceCategory.php': ['2808c92d95d0a9a8d831a959abb51e1a613bb3ea', 'd62e5ed16e7d0a4648476ed0074ebc64b6f6bbb0'],
    'app/Models/MarketplaceJob.php': ['cd49fdd7243e1d351fee64304610677a9db0ddce', 'aefbb7cb28f044fdbb5e7fd54207c345fb666a50'],
    'app/Support/BambodiaTaxonomy.php': [None, 'ec474e5a6111dbfbd25f74f34fa7a2a34914b044'],
    'database/migrations/2026_11_15_110000_enable_bambodia_nested_categories.php': [None, 'b88ac647e878cc4a9b2ba5f7507d8da8cd99cf61'],
    'database/seeders/BambodiaTaxonomyThirteenSeeder.php': [None, 'a7a07b1ef1ecda29c94d0b6ae2db07471caa3b2e'],
    'resources/views/builder/index.blade.php': ['3b33f817c312ac2be4143b13af811d16adbaadd3', '344c8bfde74a214abb415a31ccd29275e192d691'],
    'resources/views/components/job-list-row.blade.php': ['fac01cb6692ea189e473198daef35b20793befdf', '5f3895ab78b03286e5873b479781a7bc0214dd22'],
    'resources/views/dashboard/categories.blade.php': ['9a26da5fb7fb1bb53d1c9a990227181b6add7d8c', 'f22164c1736bb3534425bafd6d579816f69c544c'],
    'resources/views/dashboard/jobs.blade.php': ['78939d48a8f4724e85e59815f2acf19bd5e7e8db', '4064aad3919e85b2daf5c1c20430bb0421eb9bb1'],
    'resources/views/jobs/index.blade.php': ['427d386d0537b857144c8d31b6f9a917b41d0e24', '7dcf497608dc08e3db2bc5ecab70a48725867542'],
    'resources/views/jobs/mine.blade.php': ['f614e8edee7e058a4ed9954426e2b2ce668890d9', '3a222992be059c795ae2092fe58ae16e9fb6c208'],
    'resources/views/jobs/show.blade.php': ['1aee0e3d4bb9536803da2518c7ba28bab6d23d9f', '6ad46f47f3ad91063265292789aca14a45f1bdfd'],
    'resources/views/marketplace/search.blade.php': ['441ab915d9cc6c174b04f8ab04f20ba75533e90f', '9512244bdd713fd569219485b9c3bdc7b52e2438']}


def require(ok, message):
    if not ok:
        raise RuntimeError(message)


def call(args, *, capture=False, stdout=None, quiet=False):
    result = subprocess.run(
        args, cwd=APP, check=False, text=capture,
        stdout=subprocess.PIPE if capture else stdout,
        stderr=subprocess.DEVNULL if quiet else None,
    )
    label = " ".join(args[5:7]) if args[0] == "runuser" and args[5:6] == ["artisan"] else args[0]
    require(result.returncode == 0, f"COMMAND_FAILED={label} (exit {result.returncode})")
    return result.stdout


def php(*args, **kwargs):
    return call(["runuser", "-u", "www", "--", PHP, *args], **kwargs)


def artisan(*args):
    return php("artisan", *args)


def blob(path):
    data = path.read_bytes()
    return hashlib.sha1(f"blob {len(data)}\0".encode() + data).hexdigest()


def safe_target(relative):
    target = APP / relative
    require(target.parent.resolve() == target.parent, f"UNEXPECTED_PARENT={relative}")
    if not target.parent.is_dir():
        require(MANIFEST[relative][0] is None and not target.parent.exists(),
                f"MISSING_PARENT={relative}")
    require(not target.is_symlink(), f"SYMLINK_TARGET={relative}")
    if target.exists():
        require(target.is_file(), f"UNEXPECTED_TARGET={relative}")
    return target


def prepare(staged):
    changes = []
    conflicts = []
    for relative, (before, after) in MANIFEST.items():
        target = safe_target(relative)
        actual = blob(target) if target.exists() else None
        if actual not in (before, after):
            conflicts.append(f"LIVE_FILE_CONFLICT={relative} (blob {actual})")
        elif actual != after:
            changes.append(relative)
    require(not conflicts, "\n".join(conflicts))

    for relative in changes:
        dest = staged / relative
        dest.parent.mkdir(parents=True, exist_ok=True)
        with dest.open("wb") as out:
            call(["gh", "api", "-H", "Accept: application/vnd.github.raw+json",
                  f"repos/{REPO}/contents/{relative}?ref={RELEASE}"], stdout=out)
        require(blob(dest) == MANIFEST[relative][1], f"RELEASE_FILE_HASH_MISMATCH={relative}")
        if not relative.endswith(".blade.php"):
            call([PHP, "-l", str(dest)], capture=True)
    print(f"TAXONOMY_SOURCE_CHECK=PASS ({len(changes)} files to install)", flush=True)
    return changes


def database_config():
    snippet = r"""
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = $app->make('db')->connection();
$parent = $db->table('marketplace_categories')->where('type', 'product')
    ->where('slug', 'plugins-extensions')->first();
$admin = $db->table('users')->where('role', 'super_admin')
    ->where('account_status', 'active')->exists();
$keys = array_flip(['driver', 'unix_socket', 'host', 'port', 'database',
    'username', 'password']);
$conf = array_intersect_key($db->getConfig(), $keys);
$options = $db->getConfig('options') ?? [];
$conf['ssl_ca'] = class_exists('Pdo\\Mysql') ? ($options[Pdo\Mysql::ATTR_SSL_CA] ?? null) : null;

echo json_encode([
    'url' => config('app.url'),
    'maintenance' => $app->isDownForMaintenance(),
    'parent' => $parent && $parent->name === 'Plugins & Extensions' &&
        (bool) $parent->is_active && ($parent->parent_id ?? null) === null,
    'admin' => $admin,
    'db' => $conf,
], JSON_THROW_ON_ERROR);
"""
    try:
        info = json.loads(php("-r", snippet, capture=True, quiet=True))
    except ValueError:
        raise RuntimeError("COULD_NOT_READ_LARAVEL_DATABASE_CONFIGURATION") from None
    require(info["url"].rstrip("/") == SITE, "BAMBODIA_APP_URL_MISMATCH")
    require(not info["maintenance"], "BAMBODIA_ALREADY_IN_MAINTENANCE")
    require(info["parent"], "PLUGINS_EXTENSIONS_PARENT_INVALID")
    require(info["admin"], "ACTIVE_SUPER_ADMIN_MISSING")
    require(info["db"]["driver"] in ("mysql", "mariadb"), "MYSQL_DATABASE_REQUIRED")
    require(re.fullmatch(r"[A-Za-z0-9_][A-Za-z0-9_$-]*", info["db"]["database"]),
            "UNEXPECTED_DATABASE_NAME")
    return info["db"]


def backup_database(config, backup):
    client = shutil.which("mysqldump") or "/www/server/mysql/bin/mysqldump"
    require(os.access(client, os.X_OK), "MYSQLDUMP_MISSING")
    with tempfile.NamedTemporaryFile("w", prefix=".mysql-", dir=backup) as options:
        options.write("[client]\n")
        settings = {"user": config["username"], "password": config["password"] or ""}
        if config.get("unix_socket"):
            settings.update(protocol="SOCKET", socket=config["unix_socket"])
        else:
            settings.update(host=config["host"], port=config["port"])
        if config.get("ssl_ca"):
            settings["ssl-ca"] = config["ssl_ca"]
        for key, value in settings.items():
            value = str(value)
            require("\n" not in value and "\r" not in value, f"INVALID_MYSQL_OPTION={key}")
            escaped = value.replace("\\", "\\\\").replace('"', '\\"')
            options.write(f'{key}="{escaped}"\n')
        options.flush()

        help_text = call([client, "--no-defaults", "--help"], capture=True, quiet=True)
        flags = [f"--defaults-file={options.name}", "--single-transaction", "--skip-lock-tables"]
        for key, flag in [
            ("--no-tablespaces", "--no-tablespaces"),
            ("--set-gtid-purged", "--set-gtid-purged=OFF"),
            ("--column-statistics", "--column-statistics=0"),
        ]:
            if key in help_text:
                flags.append(flag)
        tables = ["marketplace_categories", "marketplace_jobs", "migrations"]
        target = backup / "taxonomy-before.sql"
        with target.open("xb") as out:
            call([client, *flags, config["database"], *tables], stdout=out, quiet=True)
    with target.open("rb") as source:
        exported = {match.group(1).decode() for line in source
                    if (match := re.match(rb"CREATE TABLE \x60([^\x60]+)", line))}
    require(exported >= set(tables), "DATABASE_BACKUP_INCOMPLETE")
    print("TAXONOMY_DATABASE_BACKUP=VERIFIED", flush=True)


def install_file(source, target, metadata):
    missing = []
    parent = target.parent
    while not parent.is_dir():
        missing.append(parent)
        parent = parent.parent
    for directory in reversed(missing):
        directory.mkdir(mode=0o755)
        directory.chmod(0o755)
    fd, tmp = tempfile.mkstemp(prefix=".bambodia-taxonomy-", dir=target.parent)
    try:
        with os.fdopen(fd, "wb") as handle, source.open("rb") as original:
            shutil.copyfileobj(original, handle)
            os.fsync(handle.fileno())
            os.fchown(handle.fileno(), *metadata[:2])
            os.fchmod(handle.fileno(), metadata[2])
        os.replace(tmp, target)
    finally:
        Path(tmp).unlink(missing_ok=True)


def apply(staged, changes, config):
    backup = Path(tempfile.mkdtemp(prefix="bambodia-taxonomy-backup-", dir="/root"))
    print(f"TAXONOMY_BACKUP_DIR={backup}", flush=True)
    before = {}
    installed = []
    maintenance = False
    migration_started = False
    try:
        for relative in changes:
            target = safe_target(relative)
            expected = MANIFEST[relative][0]
            require((blob(target) if target.exists() else None) == expected,
                    f"LIVE_FILE_CHANGED_DURING_PREPARATION={relative}")
            saved = backup / "source" / relative
            if target.exists():
                meta = target.stat()
                saved.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(target, saved)
                require(blob(saved) == expected, f"SOURCE_BACKUP_MISMATCH={relative}")
                before[relative] = (meta.st_uid, meta.st_gid, stat.S_IMODE(meta.st_mode))
            else:
                before[relative] = None

        maintenance = True
        artisan("down", "--retry=30")
        backup_database(config, backup)

        for relative in [MIGRATION, *[p for p in changes if p != MIGRATION]]:
            if relative in before:
                target = safe_target(relative)
                install_file(staged / relative, target, before[relative] or (0, 0, 0o644))
                installed.append(relative)
                require(blob(target) == MANIFEST[relative][1], f"POST_INSTALL_MISMATCH={relative}")
            if relative == MIGRATION:
                migration_started = True
                artisan("migrate", "--path=" + MIGRATION, "--force")

        artisan("view:clear")
        artisan("db:seed", "--class=Database\\Seeders\\BambodiaTaxonomyThirteenSeeder", "--force")
        artisan("bambodia:taxonomy:verify")
    except BaseException:
        failures = []
        for relative in reversed(installed):
            if relative == MIGRATION:
                continue
            try:
                target = safe_target(relative)
                if before[relative] is None:
                    target.unlink()
                else:
                    install_file(backup / "source" / relative, target, before[relative])
            except Exception:
                failures.append(relative)
        if failures:
            print("TAXONOMY_SOURCE_RESTORE_FAILED=" + ",".join(failures), file=sys.stderr)
        elif installed:
            print("TAXONOMY_APPLICATION_FILES_RESTORED", file=sys.stderr)
        if migration_started:
            print("TAXONOMY_SCHEMA_MAY_HAVE_ADVANCED; DATABASE_BACKUP_RETAINED", file=sys.stderr)
        raise
    finally:
        if maintenance:
            artisan("up")


def main():
    require(len(sys.argv) == 2 and sys.argv[1] in ("--check", "--apply"),
            "Usage: python3 operator.py --check|--apply")
    require(os.geteuid() == 0, "ROOT_REQUIRED")
    require(APP.resolve() == APP and (APP / "artisan").is_file(), "BAMBODIA_APP_MISSING")
    require(os.access(PHP, os.X_OK), "PHP_85_MISSING")
    vhost = VHOST.read_text()
    vhosts = re.findall(r"(?m)^\s*server_name\s+([^;]+);", vhost)
    roots = re.findall(r"(?m)^\s*root\s+([^;]+);", vhost)
    require(any("bambodia.com" in value.split() for value in vhosts) and
            str(APP / "public") in [r.strip("'\" ") for r in roots],
            "BAMBODIA_NGINX_ROOT_MISMATCH")
    os.umask(0o077)
    with tempfile.TemporaryDirectory(prefix="bambodia-taxonomy-source-", dir="/root") as staged:
        config = database_config()
        changes = prepare(Path(staged))
        if sys.argv[1] == "--check":
            print("TAXONOMY_PRECHECK=PASS")
            return
        with open("/run/lock/bambodia-taxonomy.lock", "a") as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            require(database_config() == config, "DATABASE_CONFIGURATION_CHANGED")
            for relative in MANIFEST.keys() - set(changes):
                target = safe_target(relative)
                require(target.is_file() and blob(target) == MANIFEST[relative][1],
                        f"LIVE_FILE_CHANGED_DURING_PREPARATION={relative}")
            apply(Path(staged), changes, config)
    print("TAXONOMY_DEPLOYMENT=PASS")


if __name__ == "__main__":
    try:
        main()
    except (OSError, RuntimeError, ValueError) as error:
        print(f"TAXONOMY_DEPLOYMENT_STOPPED={error}", file=sys.stderr)
        sys.exit(1)

