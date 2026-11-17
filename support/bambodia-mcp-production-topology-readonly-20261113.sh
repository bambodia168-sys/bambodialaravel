#!/usr/bin/env bash
# Bambodia MCP recovery gate 1: live DB/media topology, READ ONLY.
# Does not connect to MySQL, run artisan, make backups, or read media bytes.
set -Eeuo pipefail
set +x
umask 077
[[ "$#" -eq 1 && "$1" == "--inspect" ]] || { echo "Usage: --inspect" >&2; exit 64; }
fail() { printf 'MCP_TOPOLOGY_BLOCKED=%s\n' "$1" >&2; exit 1; }
[[ "$(id -u)" -eq 0 ]] || fail ROOT_REQUIRED
for cmd in python3 sha256sum git; do
 command -v "$cmd" >/dev/null || fail REQUIRED_COMMAND_MISSING
done
APP=/www/wwwroot/bambodia-staging-qa
VHOST=/www/server/panel/vhost/nginx/bambodia.com.conf
[[ -d "$APP" && ! -L "$APP" && -f "$APP/artisan" &&
   -f "$APP/storage/app/installed.lock" && -d "$APP/public" ]] || fail LIVE_APP_CHANGED
[[ -f "$VHOST" && ! -L "$VHOST" ]] || fail VHOST_MISSING
[[ "$(sha256sum "$VHOST" | awk '{print $1}')" == "5dae3552791fa6be8fc23474ad89c91ecacf2fc94fd9ceda7ee34033df5b1116" ]] || fail VHOST_CHANGED
[[ "$(git hash-object "$APP/composer.json")" == "457992551f4f234ad442d9db5ad73c8b54689e7b" ]] || fail COMPOSER_CHANGED
[[ "$(sha256sum "$APP/composer.lock" | awk '{print $1}')" == "ed0a78f5d3bf3a9bf6490a97d5570751fd8c3be905414d27a38d9036e56fef03" ]] || fail LOCK_CHANGED
echo "MCP_TOPOLOGY_LIVE_IDENTITY=PASS"

python3 - "$APP" <<'PY'
from pathlib import Path
import os, re, stat, sys

app = Path(sys.argv[1])
MAX_ENV = 131072
MAX_FILES = 50000
MAX_BYTES = 1 << 52
def emit(key, value):
    print(key + "=" + str(value), flush=True)
def regular(path):
    try:
        s = path.lstat()
        return s if stat.S_ISREG(s.st_mode) else None
    except OSError:
        return None
def env_hints():
    path = app / ".env"
    info = regular(path)
    if info is None or info.st_size > MAX_ENV:
        emit("MCP_ENV_FILE", "UNAVAILABLE_OR_UNSAFE")
        return None
    if info.st_mode & (stat.S_IROTH | stat.S_IWOTH):
        emit("MCP_ENV_FILE", "WORLD_ACCESS_REVIEW_REQUIRED")
        return None
    flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_CLOEXEC", 0)
    try:
        fd = os.open(path, flags)
        try:
            s = os.fstat(fd)
            if not stat.S_ISREG(s.st_mode) or s.st_size > MAX_ENV:
                emit("MCP_ENV_FILE", "RACE_OR_SIZE_BLOCKED")
                return None
            data = os.read(fd, MAX_ENV + 1)
        finally:
            os.close(fd)
    except OSError:
        emit("MCP_ENV_FILE", "PRIVATE_READ_BLOCKED")
        return None
    if len(data) > MAX_ENV:
        emit("MCP_ENV_FILE", "OVERSIZE_BLOCKED")
        return None
    keys = {"DB_CONNECTION", "DB_URL", "DB_DATABASE", "DB_HOST", "DB_SOCKET",
            "FILESYSTEM_DISK", "MEDIA_LIBRARY_DISK", "R2_BUCKET", "AWS_BUCKET",
            "BAMBODIA_MCP_ENABLED", "BAMBODIA_MCP_WRITES_ENABLED"}
    result = {}
    for line in data.decode("utf-8", "replace").splitlines():
        match = re.match(r"^\s*([A-Z][A-Z0-9_]*)\s*=\s*(.*)$", line)
        if match and match.group(1) in keys:
            value = match.group(2).strip()
            if len(value) > 1 and value[0] == value[-1] and value[0] in ("'", '"'):
                value = value[1:-1]
            result[match.group(1)] = value
    del data
    emit("MCP_ENV_FILE", "READ_PRIVATELY_VALUES_MASKED")
    return result
def simple_driver(v, allowed):
    return v.lower() if v.lower() in allowed else "UNSET_OR_OTHER"
def host_category(v):
    v = v.strip().lower()
    if not v: return "UNSET"
    if v in ("127.0.0.1", "::1", "localhost"): return "LOOPBACK"
    if "$"+"{" in v or "$(" in v: return "DYNAMIC_UNRESOLVED"
    return "OTHER_MASKED"
def scan(relative, label):
    root = app / relative
    if root.is_symlink():
        emit(label + "_ROOT", "SYMLINK_NOT_TRAVERSED")
        return
    if not root.is_dir():
        emit(label + "_ROOT", "NOT_FOUND")
        return
    files = size = links = errors = 0
    truncated = False
    for current, folders, names in os.walk(root, topdown=True, followlinks=False):
        safe = []
        for name in folders:
            try:
                if (Path(current) / name).is_symlink(): links += 1
                else: safe.append(name)
            except OSError: errors += 1
        folders[:] = safe
        for name in names:
            try:
                s = (Path(current) / name).lstat()
                if stat.S_ISLNK(s.st_mode): links += 1
                elif stat.S_ISREG(s.st_mode):
                    files += 1
                    size += s.st_size
                    if files >= MAX_FILES or size >= MAX_BYTES:
                        truncated = True
                        break
                else: errors += 1
            except OSError: errors += 1
        if truncated: break
    emit(label + "_ROOT", "PRESENT")
    emit(label + "_FILES_METADATA_ONLY", files)
    emit(label + "_BYTES_METADATA_ONLY", size)
    emit(label + "_LINKS_SKIPPED", links)
    emit(label + "_SCAN_ERRORS", errors)
    emit(label + "_TRUNCATED", "YES" if truncated else "NO")
    if label == "MCP_PRIVATE_MEDIA":
        try:
            emit("MCP_PRIVATE_MEDIA_OTHER_PERMISSIONS",
                 "REVIEW" if root.lstat().st_mode & stat.S_IRWXO else "NONE")
        except OSError:
            emit("MCP_PRIVATE_MEDIA_OTHER_PERMISSIONS", "UNKNOWN")
def disk(label, root):
    try:
        device = "SAME_AS_APP" if app.stat().st_dev == root.stat().st_dev else "DIFFERENT_FROM_APP"
        space = os.statvfs(root)
        emit(label + "_FILESYSTEM", device)
        emit(label + "_FREE_GIB", round(space.f_bavail * space.f_frsize / 1024**3, 2))
    except OSError:
        emit(label + "_FILESYSTEM", "NOT_VERIFIED")
def run():
    hints = env_hints()
    emit("MCP_CONFIG_CACHE", "PRESENT_EFFECTIVE_VALUES_UNVERIFIED" if regular(app / "bootstrap/cache/config.php") else "NOT_FOUND")
    if hints is None:
        emit("MCP_DB_ENV_CLASSIFICATION", "BLOCKED")
    else:
        emit("MCP_DB_ENV_DRIVER", simple_driver(hints.get("DB_CONNECTION", ""), {"mysql", "mariadb", "sqlite", "pgsql", "sqlsrv"}))
        emit("MCP_DB_URL_SET", "YES_PRIVATE_REVIEW" if hints.get("DB_URL") else "NO")
        emit("MCP_DB_NAME_SET", "YES" if hints.get("DB_DATABASE") else "NO")
        emit("MCP_DB_HOST_CLASS", host_category(hints.get("DB_HOST", "")))
        emit("MCP_DB_SOCKET_SET", "YES" if hints.get("DB_SOCKET") else "NO")
        emit("MCP_DEFAULT_STORAGE_ENV", simple_driver(hints.get("FILESYSTEM_DISK", "local"), {"local", "public", "r2", "s3"}))
        emit("MCP_MEDIA_LIBRARY_STORAGE_ENV", simple_driver(hints.get("MEDIA_LIBRARY_DISK", "local"), {"local", "public", "r2", "s3"}))
        emit("MCP_R2_BUCKET_SET", "YES" if hints.get("R2_BUCKET") else "NO")
        emit("MCP_S3_BUCKET_SET", "YES" if hints.get("AWS_BUCKET") else "NO")
        for key, label in (("BAMBODIA_MCP_ENABLED", "MCP_READ_SWITCH_ENV"), ("BAMBODIA_MCP_WRITES_ENABLED", "MCP_WRITE_SWITCH_ENV")):
            emit(label, "EXPLICITLY_OFF" if hints.get(key, "").lower() in ("false", "0") else "NOT_PROVEN_OFF")
    for relative, label in (
        ("storage/app/private", "MCP_PRIVATE_MEDIA"),
        ("storage/app/public", "MCP_PUBLIC_MEDIA"),
        ("public/uploads", "MCP_UPLOADS_MEDIA"),
        ("public/media", "MCP_WEB_MEDIA")
    ): scan(relative, label)
    link = app / "public/storage"
    if link.is_symlink():
        try:
            target = (link.parent / os.readlink(link)).resolve()
            emit("MCP_PUBLIC_STORAGE_LINK", "EXPECTED" if target == (app / "storage/app/public").resolve() else "DIFFERENT_TARGET_MASKED")
        except OSError:
            emit("MCP_PUBLIC_STORAGE_LINK", "UNRESOLVED")
    else:
        emit("MCP_PUBLIC_STORAGE_LINK", "NOT_SYMLINK")
    disk("MCP_APP", app)
    for label, root in (
        ("MCP_DB_BACKUP", Path("/www/backup/database")),
        ("MCP_SITE_BACKUP", Path("/www/backup/site")),
        ("MCP_PRIVATE", app / "storage/app/private"),
        ("MCP_PUBLIC", app / "storage/app/public")
    ):
        if root.is_dir() and not root.is_symlink(): disk(label, root)
        else: emit(label + "_FILESYSTEM", "MISSING_OR_SYMLINK")
    emit("MCP_EFFECTIVE_DB_CONNECTION", "NOT_VERIFIED_NO_DB_ACCESS")
    emit("MCP_CLOUD_STORAGE_CONTENT", "NOT_VERIFIED_NO_API")
    emit("MCP_ENCRYPTED_OFFSITE_BACKUP", "NOT_VERIFIED")
    emit("MCP_ISOLATED_RESTORE", "NOT_PERFORMED")
    emit("MCP_ROLLBACK_REHEARSAL", "NOT_PERFORMED")
    emit("MCP_REAL_HOST_OAUTH", "NOT_TESTED_BY_THIS_AUDIT")
    emit("MCP_PRODUCTION_RELEASE", "BLOCKED")
    emit("MCP_PRODUCTION_FILES_DATABASE_KEYS_NGINX", "UNCHANGED")
    emit("MCP_TOPOLOGY_AUDIT", "READ_ONLY_COMPLETED")
try: run()
except Exception:
    emit("MCP_TOPOLOGY_AUDIT", "BLOCKED_UNEXPECTED_ERROR")
    sys.exit(1)
PY
