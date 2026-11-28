#!/usr/bin/env bash
# Phase 4E.6 combined isolated acceptance; no deployment mode.
set -Eeuo pipefail
set +x
umask 077
LIVE=/www/wwwroot/bambodia-staging-qa
PHP=/www/server/php/85/bin/php
REPO=bambodia168-sys/bambodialaravel
RELEASE=fd91345ed14fea36ed62f60d0cf43d3dc26024f2
CONTROLLER=app/Http/Controllers/Api/BambodiaMcpController.php
CONTROLLER_BLOB=a302fa364f1f52b6320b77688b7595b91894ca13
PATCH=support/bambodia-mcp-stage-invoice-write.py
PATCH_BLOB=e546fd64a5b21f8ef8aa596c9f0796b3cdd2ef2f
HARNESS_BLOB=34a735f87c58d0f3cee5611307ecfc87771f7cea
AUDIT=app/Models/AuditEvent.php
AUDIT_BASE=478ab1fd7d6ed75028aca3e64ced4a572f286a27
AUDIT_LIVE=430b4d81e53934a78b3c0ed74a087a36a0376566
AUDIT_CANDIDATE=4812c5d412c4711e37237d4e2f83bf0daf98657a
ADDITIONS=(
  app/Models/MarketplaceInvoice.php
  app/Models/MarketplaceInvoiceItem.php
  app/Policies/MarketplaceInvoicePolicy.php
  app/Support/Invoices/InvoiceDraftService.php
  app/Support/Invoices/InvoiceMoney.php
  app/Support/Mcp/InvoiceReadTools.php
  app/Support/Mcp/InvoiceWriteTools.php
  config/invoices.php
  database/migrations/2026_11_28_120000_create_marketplace_invoice_foundation.php
)
BLOBS=(
  a791525667e3ab195c62bce7e42a29817680a678
  629bb895e6d970b5bee54cbc472d7b86f3f071ad
  3f16123ee1794933dbca1a7ada4a54fb2e052fad
  86e043a56bf764952f7d6cf1988b3b7088fc3b09
  d393ddf8dc7f2a471a3404022dc8e98118447da6
  3a1830746923cc7a78a20970143a57ec87119e05
  259f8cfe1c60d261584199cf6483c57fa5636ccc
  7f86f47b819152e628ee999ad88dd3efe54d70d5
  c70ab314663276fa5a715513f7576068835461df
)
MYSQL_BASE=/www/server/mysql

fail(){ echo "PHASE4E6_COMBINED_BLOCKED=$*" >&2; exit 1; }
[[ $(id -u) == 0 ]] || fail ROOT_REQUIRED
[[ -x "$PHP" ]] || fail PHP85_MISSING
for cmd in git curl python3 sha256sum realpath stat find openssl unshare mount setpriv getent cmp; do command -v "$cmd" >/dev/null || fail "MISSING_$cmd"; done
check_blob(){
  local file=$1 expected=$2 got
  [[ -f "$file" && ! -L "$file" ]] || fail "MISSING_OR_SYMLINK_$file"
  got=$(git hash-object --no-filters "$file")
  if [[ "$got" != "$expected" ]]; then
    echo "SOURCE_DRIFT=$file expected=$expected actual=$got" >&2
    fail SOURCE_DRIFT_REVIEW_REQUIRED
  fi
}
download(){
  local path=$1 blob=$2 dest=$3
  mkdir -p "$(dirname "$dest")"
  curl -fLsS --retry 3 --connect-timeout 15 --max-time 120 "https://raw.githubusercontent.com/$REPO/$RELEASE/$path" -o "$dest"
  check_blob "$dest" "$blob"
}
verify_live(){
  [[ -d "$LIVE" && ! -L "$LIVE" && $(realpath "$LIVE") == "$LIVE" && -f "$LIVE/artisan" ]] || fail LIVE_PATH_CHANGED
  check_blob "$LIVE/$CONTROLLER" "$CONTROLLER_BLOB"
  check_blob "$LIVE/app/Support/Mcp/OperationalReadTools.php" 040bd589cf03c6d6c2d51a2fc42e9fd334ff1311
  check_blob "$LIVE/app/Support/Mcp/AdvancedContentTools.php" 96ba1240af97e557d9345847b3b58d1ecf2b788a
  check_blob "$LIVE/database/migrations/2026_11_28_080000_add_description_to_media_assets.php" b96cedca6729eaf36c1d30d0a598c589410955be
  check_blob "$LIVE/app/Support/Mcp/CommerceReadTools.php" a9e5cf8e596769ca0cc90f9ca6ee4721710b9d5c
  check_blob "$LIVE/app/Support/Mcp/ProjectReadTools.php" 84036ebe85a7c906911a343720dc8b5e43b6e1b2
  check_blob "$LIVE/app/Support/Mcp/ProjectStateVersion.php" 40159625dff463d6911e9f61cfe542dd61b05497
  check_blob "$LIVE/app/Support/Mcp/ProjectWriteTools.php" 7c31cf5acd15669a2fbf5b718d13298fdb34ca4c
  check_blob "$LIVE/database/migrations/2026_11_28_100000_create_mcp_project_operations.php" 982b0ca4816ffe5ae69b1961c34c18fa276bc08f
  check_blob "$LIVE/$AUDIT" "$AUDIT_LIVE"
  for path in "${ADDITIONS[@]}"; do
    [[ ! -e "$LIVE/$path" && ! -L "$LIVE/$path" ]] || fail INVOICE_FILES_ALREADY_LIVE
  done
}
reconcile_audit(){
  python3 - "$@" <<'AUDITPY'
import hashlib
import pathlib
import re
import sys

BASE = "478ab1fd7d6ed75028aca3e64ced4a572f286a27"
LIVE = "430b4d81e53934a78b3c0ed74a087a36a0376566"
CANDIDATE = "4812c5d412c4711e37237d4e2f83bf0daf98657a"
LEGACY = frozenset("""
job.owner.archived job.owner.deleted job.owner.draft
job.review.archived job.review.deleted
license.definition.archived license.definition.deleted
listing.review.archived listing.review.deleted
mcp.draft.created mcp.draft.deleted mcp.draft.updated
mcp.media.attached mcp.media.detached
""".split())
INVOICE = (
    "invoice.draft.create", "invoice.draft.update", "invoice.draft.discard",
    "mcp.invoice.draft.create", "mcp.invoice.draft.update", "mcp.invoice.draft.discard",
)
PATTERN = rb"\s*'([a-zA-Z0-9_.-]+)'\s*,"

def require(ok, message):
    if not ok:
        raise ValueError(message)

def blob(data):
    return hashlib.sha1(b"blob "+str(len(data)).encode()+b"\0"+data).hexdigest()

def inspect(data):
    matches = list(re.finditer(rb"public const EVENTS\s*=\s*\[(.*?)\];", data, re.S))
    require(len(matches)==1, "Unique audit allowlist required")
    m=matches[0]
    body=m.group(1)
    events=[x.decode("ascii") for x in re.findall(PATTERN,body)]
    require(not re.sub(PATTERN,b"",body).strip(), "Unknown audit allowlist structure")
    require(len(events)==len(set(events)), "Duplicate audit event")
    return events,data[:m.start(1)]+data[m.end(1):],m

def append(data, additions):
    events,_,m=inspect(data)
    require(not set(events).intersection(additions), "Event already present")
    # Insert before the existing closing indentation, preserving all original bytes.
    end=m.end(1)
    trailing=re.search(rb"\n[ \t]*$",m.group(1))
    require(trailing is not None, "Expected closing indentation")
    pos=m.start(1)+trailing.start()
    inserted=b"".join(b"\n        '"+x.encode("ascii")+b"'," for x in additions)
    result=data[:pos]+inserted+data[pos:]
    require(result[:pos]+result[pos+len(inserted):]==data, "Non-additive edit")
    return result

def merge(base,live,candidate):
    b,bc,_=inspect(base); l,lc,_=inspect(live); c,cc,_=inspect(candidate)
    require(bc==lc==cc, "Non-allowlist source drift")
    require(len(b)==245 and len(l)==259 and len(c)==251, "Unexpected event count")
    require(set(l)==set(b)|LEGACY and not set(b)&LEGACY, "Unexpected legacy additions")
    require(set(c)==set(b)|set(INVOICE) and not set(l)&set(INVOICE), "Unexpected invoice additions")
    require([x for x in l if x in set(b)]==b, "Baseline order changed")
    require([x for x in c if x in set(b)]==b, "Candidate baseline order changed")
    out=append(live,INVOICE)
    o,oc,_=inspect(out)
    require(o==l+list(INVOICE) and len(o)==265 and oc==lc, "Merged audit verification failed")
    return out

def selftest(candidate):
    require(blob(candidate)==CANDIDATE,"Accepted candidate fingerprint mismatch")
    # Reconstruct the tracked base by removing exactly the accepted six lines.
    base=candidate
    for x in INVOICE:
        line=b"        '"+x.encode()+b"',\n"
        require(base.count(line)==1,"Expected candidate event line")
        base=base.replace(line,b"",1)
    require(blob(base)==BASE,"Reconstructed baseline mismatch")
    live=append(base,sorted(LEGACY))
    out=merge(base,live,candidate)
    require(len(inspect(out)[0])==265,"Synthetic merge count")
    for bad in (live+b"\n",append(live,["unexpected.event"]),live.replace(b"'mcp.draft.created'",b"'mcp.draft.updated'"),live.replace(b"'mcp.draft.created',",b"")):
        try:
            merge(base,bad,candidate)
        except ValueError:
            continue
        raise ValueError("Unsafe fixture accepted")
    print("AUDIT_ADDITIVE_OVERLAY_SELF_TEST=PASS")

if sys.argv[1]=="--self-test" and len(sys.argv)==3:
    selftest(pathlib.Path(sys.argv[2]).read_bytes())
elif len(sys.argv)==5:
    paths=list(map(pathlib.Path,sys.argv[1:]))
    require(all(p.is_file() and not p.is_symlink() for p in paths[:3]),"Unsafe input path")
    base,live,candidate=[p.read_bytes() for p in paths[:3]]
    require([blob(x) for x in (base,live,candidate)]==[BASE,LIVE,CANDIDATE],"Exact approved source fingerprints required")
    require(not paths[3].exists() and not paths[3].is_symlink(),"Output must be new")
    out=merge(base,live,candidate)
    with paths[3].open("xb") as f: f.write(out)
    print("AUDIT_LIVE_EVENTS_PRESERVED=259")
    print("AUDIT_INVOICE_EVENTS_ADDED=6")
    print("AUDIT_MERGED_EVENTS=265")
    print("AUDIT_NON_ALLOWLIST_CODE_UNCHANGED=YES")
    print("AUDIT_MERGED_BLOB="+blob(out))
    print("AUDIT_ADDITIVE_OVERLAY=PASS")
else:
    raise SystemExit("Invalid audit reconciliation arguments")
AUDITPY
}
accept(){
  [[ $# == 2 ]] || fail ACCEPT_REQUIRES_STAGE_AND_REVIEWED_MANIFEST_SHA256
  local stage=$1 reviewed=$2 run uid key composer_hash lock_hash path
  [[ "$stage" == /var/tmp/bambodia-phase4e5-review.h8f9V45B && "$reviewed" == 8e1321863391d2b442d812dc3784d1963aae53902d89b2dd997eed5239242e1d ]] || fail OAUTH_STAGE_NOT_APPROVED
  local release_additions=(
    app/Http/Controllers/MarketplaceInvoiceController.php
    app/Models/MarketplaceInvoice.php
    app/Models/MarketplaceInvoiceItem.php
    app/Policies/MarketplaceInvoicePolicy.php
    app/Support/Invoices/InvoiceDraftService.php
    app/Support/Invoices/InvoiceMoney.php
    app/Support/Mcp/InvoiceReadTools.php
    app/Support/Mcp/InvoiceWriteTools.php
    config/invoices.php
    database/migrations/2026_11_28_120000_create_marketplace_invoice_foundation.php
    public/css/native-invoices.css
    resources/views/invoices/index.blade.php
    resources/views/invoices/preview.blade.php
    resources/views/invoices/show.blade.php
  )
  local release_changes=(
    app/Http/Controllers/Api/BambodiaMcpController.php
    app/Models/AuditEvent.php
    public/js/creator-studio-analytics.js
    resources/views/account/studio.blade.php
    resources/views/layouts/workspace.blade.php
    routes/web.php
  )
  [[ "$stage" =~ ^/var/tmp/bambodia-phase4e5-review\.[A-Za-z0-9]+$ && -d "$stage" && ! -L "$stage" && $(stat -c '%u:%a' "$stage") == 0:700 ]] || fail UNSAFE_STAGE
  [[ "$reviewed" =~ ^[a-f0-9]{64}$ && $(sha256sum "$stage/manifest.sha256"|cut -d' ' -f1) == "$reviewed" ]] || fail REVIEWED_MANIFEST_MISMATCH
  [[ -z $(find "$stage/source" "$stage/candidate" "$stage/verified" -type l -print -quit) ]] || fail STAGED_SYMLINK
  (cd "$stage"; find source candidate verified -type f -print0 | sort -z | xargs -0 sha256sum | cmp -s - manifest.sha256) || fail STAGED_FILE_SET_OR_CONTENT_DRIFT
  (cd "$stage"; sha256sum --check --status manifest.sha256) || fail STAGED_SOURCE_DRIFT
  verify_live
  for path in "${ADDITIONS[@]}"; do [[ ! -e "$stage/source/$path" ]] || fail BASELINE_ALREADY_HAS_INVOICE_FILES; done
  check_blob "$stage/verified/$(basename "$PATCH")" "$PATCH_BLOB"
  check_blob "$stage/source/tests/Feature/BambodiaPhase4E5IsolatedOAuthTest.php" "$HARNESS_BLOB"
  (cd "$LIVE"; sha256sum --check --status "$stage/verified/live-source.sha256") || fail LIVE_SOURCE_DRIFT
  check_blob "$stage/source/$CONTROLLER" "$CONTROLLER_BLOB"
  check_blob "$stage/source/$AUDIT" "$AUDIT_LIVE"
  check_blob "$stage/verified/audit-base.php" "$AUDIT_BASE"
  check_blob "$stage/verified/audit-invoice.php" "$AUDIT_CANDIDATE"
  for i in "${!ADDITIONS[@]}"; do check_blob "$stage/candidate/${ADDITIONS[$i]}" "${BLOBS[$i]}"; done
  local release=/var/tmp/bambodia-phase4e6-review.c95j8u1o
  local logs
  [[ -d "$release" && ! -L "$release" && $(stat -c '%u:%a' "$release") == 0:700 ]] || fail UNSAFE_RELEASE_STAGE
  [[ $(sha256sum "$release/manifest.sha256"|cut -d' ' -f1) == 84ff6b23fb598eb712684e08a4224757892460d9d14a5bfa89112f4bdaa535d9 ]] || fail RELEASE_MANIFEST_DRIFT
  [[ -z $(find "$release" -type l -print -quit) ]] || fail RELEASE_SYMLINK
  (cd "$release"; sha256sum --check --status manifest.sha256) || fail RELEASE_CONTENT_DRIFT
  python3 -B - "$release" "$stage" "$LIVE" <<'RELEASEPY'
import hashlib,json,pathlib,sys
release,stage,live=map(pathlib.Path,sys.argv[1:])
expected={"app/Http/Controllers/Api/BambodiaMcpController.php":("a302fa364f1f52b6320b77688b7595b91894ca13","97996e712c04b1cabe2647aecedd56e64f5dcd8c"),"app/Http/Controllers/MarketplaceInvoiceController.php":(None,"2bf546984470f632737f7466ab6808206bc77060"),"app/Models/AuditEvent.php":("430b4d81e53934a78b3c0ed74a087a36a0376566","39b2103391358fcb8bf03af677beb08840fb3cab"),"app/Models/MarketplaceInvoice.php":(None,"a791525667e3ab195c62bce7e42a29817680a678"),"app/Models/MarketplaceInvoiceItem.php":(None,"629bb895e6d970b5bee54cbc472d7b86f3f071ad"),"app/Policies/MarketplaceInvoicePolicy.php":(None,"3f16123ee1794933dbca1a7ada4a54fb2e052fad"),"app/Support/Invoices/InvoiceDraftService.php":(None,"86e043a56bf764952f7d6cf1988b3b7088fc3b09"),"app/Support/Invoices/InvoiceMoney.php":(None,"d393ddf8dc7f2a471a3404022dc8e98118447da6"),"app/Support/Mcp/InvoiceReadTools.php":(None,"3a1830746923cc7a78a20970143a57ec87119e05"),"app/Support/Mcp/InvoiceWriteTools.php":(None,"259f8cfe1c60d261584199cf6483c57fa5636ccc"),"config/invoices.php":(None,"7f86f47b819152e628ee999ad88dd3efe54d70d5"),"database/migrations/2026_11_28_120000_create_marketplace_invoice_foundation.php":(None,"c70ab314663276fa5a715513f7576068835461df"),"public/css/native-invoices.css":(None,"570a170475b0b7c4dd75705e327499e0df352e79"),"public/js/creator-studio-analytics.js":("ac2c82e555319ee89fd0e12845944fdf9558a6c9","bc3f55e18987564b278516bb9de6dc54e401d964"),"resources/views/account/studio.blade.php":("eab0ca6accc64a81b88df708ba312c62814a4344","df8ac3b1bf234dd5b0fc2954ddde3e3444464df4"),"resources/views/invoices/index.blade.php":(None,"275096c75a9c38fc43669427e69a33b42c7dff14"),"resources/views/invoices/preview.blade.php":(None,"56260e9f8b9269e02d1759c038de5d4299b6e589"),"resources/views/invoices/show.blade.php":(None,"98fe06f3ab2b736d5bd50bf46b892ee2abae0054"),"resources/views/layouts/workspace.blade.php":("378f2411d646bce1a9c1c227109627fd9d7ac58f","f357adb0b0ccb6e824614b9e195d5b7df6502601"),"routes/web.php":("375dc03d5d0cca5e4e584f997501d5b528bca29a","d622b26ff1dffaee4620f1c3ed59432d4d5384f3")}
def blob(b): return hashlib.sha1(b"blob "+str(len(b)).encode()+b"\0"+b).hexdigest()
def need(ok,label):
    if not ok: raise SystemExit("COMBINED_RELEASE_BLOCKED="+label)
def safe(root,path):
    p=root
    for part in pathlib.Path(path).parts:
        p=p/part
        need(not p.is_symlink(),"SYMLINK:"+path)
    return p
m=json.loads((release/"release.json").read_text())
need(m["application"]=="fd91345ed14fea36ed62f60d0cf43d3dc26024f2" and m["operator"]=="aafab0d6bcc387fb1b464b9d827e8ff3fc2344b6" and m["oauth_stage_manifest"]=="8e1321863391d2b442d812dc3784d1963aae53902d89b2dd997eed5239242e1d","IDENTITY")
need(m["flags"]=={"INVOICES_ENABLED":False,"INVOICES_DRAFT_WRITES_ENABLED":False},"FLAGS")
need(len(m["files"])==len(expected)==20,"INVENTORY")
need({x["path"]:(x["before_blob"],x["after_blob"]) for x in m["files"]}==expected,"FILE_PINS")
need({str(p.relative_to(release/"candidate")) for p in (release/"candidate").rglob("*") if p.is_file()}==set(expected),"CANDIDATE_FILE_SET")
need({str(p.relative_to(release/"baseline")) for p in (release/"baseline").rglob("*") if p.is_file()}=={p for p,(b,a) in expected.items() if b is not None},"BASELINE_FILE_SET")
for path,(before,after) in expected.items():
    candidate=safe(release,"candidate/"+path)
    need(candidate.is_file() and blob(candidate.read_bytes())==after,"CANDIDATE_PIN:"+path)
    target=safe(live,path)
    if before is None: need(not target.exists(),"LIVE_ADDITION_PRESENT:"+path)
    else:
        baseline=safe(release,"baseline/"+path)
        need(target.is_file() and baseline.read_bytes()==target.read_bytes() and blob(target.read_bytes())==before,"LIVE_BASELINE_PIN:"+path)
        source=safe(stage,"source/"+path)
        if source.exists(): need(source.read_bytes()==baseline.read_bytes(),"OAUTH_SOURCE_MISMATCH:"+path)
    old=safe(stage,"candidate/"+path)
    if old.exists(): need(old.read_bytes()==candidate.read_bytes(),"OAUTH_CANDIDATE_MISMATCH:"+path)
print("COMBINED_RELEASE_INVENTORY=20/20")
print("COMBINED_RELEASE_BASELINE_PINS=PASS")
RELEASEPY
  logs=$(mktemp -d /var/tmp/bambodia-phase4e6-combined-evidence.XXXXXXXX)
  echo "COMBINED_EVIDENCE=$logs"
  [[ -x "$MYSQL_BASE/bin/mysqld" && -x "$MYSQL_BASE/bin/mysql" ]] || fail PRIVATE_MYSQL_BINARY_MISSING
  # Restage from the pinned source and compare exact bytes before execution.
  local reproduced
  reproduced=$(mktemp -d "$logs/recheck.XXXXXXXX")
  PYTHONDONTWRITEBYTECODE=1 python3 "$stage/verified/$(basename "$PATCH")" --source "$stage/source/$CONTROLLER" --output "$reproduced/controller.php"
  cmp -s "$reproduced/controller.php" "$stage/candidate/$CONTROLLER" || fail CONTROLLER_REPRODUCTION_MISMATCH
  reconcile_audit "$stage/verified/audit-base.php" "$stage/source/$AUDIT" "$stage/verified/audit-invoice.php" "$reproduced/audit.php"
  cmp -s "$reproduced/audit.php" "$stage/candidate/$AUDIT" || fail AUDIT_REPRODUCTION_MISMATCH
  rm -- "$reproduced/controller.php" "$reproduced/audit.php"; rmdir "$reproduced"
  "$PHP" -r 'exit(PHP_VERSION_ID>=80500 && extension_loaded("pdo_sqlite") && extension_loaded("pdo_mysql") && extension_loaded("openssl") ? 0 : 1);' || fail PHP_EXTENSIONS
  [[ -f /usr/bin/composer && ! -L /usr/bin/composer ]] || fail COMPOSER_PHP_ENTRY_MISSING
  "$PHP" -r 'exit(preg_match("/(?:\A|\n)<\?php\b/",file_get_contents("/usr/bin/composer",false,null,0,1024))?0:1);' || fail COMPOSER_NOT_PHP
  # Use a numeric ephemeral UID/GID without creating a host account.
  uid=62000
  while getent passwd "$uid" >/dev/null || getent group "$uid" >/dev/null; do uid=$((uid+1)); [[ "$uid" -lt 63000 ]] || fail NO_UNUSED_UID; done
  run=$(mktemp -d /var/tmp/bambodia-phase4e5-runtime.XXXXXXXX)
  cleanup(){
    local rc=$1 runtime=$2
    trap - EXIT
    if [[ ! "$runtime" =~ ^/var/tmp/bambodia-phase4e5-runtime\.[A-Za-z0-9]+$ || -L "$runtime" ]]; then
      echo "ISOLATED_RUNTIME_AND_EPHEMERAL_KEYS_REMOVED=NO" >&2
      exit 1
    fi
    if [[ -e "$runtime" ]]; then
      if ! rm -rf --one-file-system -- "$runtime"; then
        echo "ISOLATED_RUNTIME_AND_EPHEMERAL_KEYS_REMOVED=NO" >&2
        exit 1
      fi
    fi
    if [[ -e "$runtime" || -L "$runtime" ]]; then
      echo "ISOLATED_RUNTIME_AND_EPHEMERAL_KEYS_REMOVED=NO" >&2
      exit 1
    fi
    if [[ "$rc" != 0 ]]; then echo "ACTUAL_OAUTH_ACCEPTANCE=FAILED_OR_BLOCKED"; fi
    echo "ISOLATED_RUNTIME_AND_EPHEMERAL_KEYS_REMOVED=YES"
    exit "$rc"
  }
  # Capture the path now; accept() locals are out of scope at shell EXIT.
  trap 'cleanup "$?" '"$(printf '%q' "$run")" EXIT
  echo "ISOLATED_RUNTIME=$run"
  cp -a "$stage/source/." "$run/"
  mkdir -p "$run"/{bootstrap/cache,storage/app/private,storage/app/public,storage/framework/cache/data,storage/framework/sessions,storage/framework/views,storage/logs,public,.composer-home,.composer-cache,.mask,mysql}
  download tests/Feature/NativeInvoiceFoundationTest.php 7e5a59bb7e1fb92d61ad7bf38beae71148c5e118 "$logs/NativeInvoiceFoundationTest.php"
  python3 -B - "$logs/NativeInvoiceFoundationTest.php" "$run/tests/Feature/BambodiaPhase4E5IsolatedOAuthTest.php" "$run/tests/Feature/BambodiaPhase4E6NativeInvoiceTest.php" <<'NATIVEPY'
import pathlib,sys
source,oauth,dest=map(pathlib.Path,sys.argv[1:])
s=source.read_text(); h=oauth.read_text()
start=h.index("    use RefreshDatabase {")
end=h.index("    private array $issuedIdentities",start)
assert s.count("    use RefreshDatabase;")==1 and s.count("final class NativeInvoiceFoundationTest")==1
s=s.replace("    use RefreshDatabase;",h[start:end].rstrip(),1).replace("final class NativeInvoiceFoundationTest","final class BambodiaPhase4E6NativeInvoiceTest",1)
s=s.replace("        Http::preventStrayRequests();","        Http::preventStrayRequests();\n        \\Illuminate\\Support\\Facades\\Mail::fake();\n        \\Illuminate\\Support\\Facades\\Queue::fake();",1)
dest.write_text(s)
NATIVEPY
  "$PHP" -l "$run/tests/Feature/BambodiaPhase4E6NativeInvoiceTest.php"
  mkdir -p "$run/public/js"
  cp -- "$release/baseline/public/js/creator-studio-analytics.js" "$run/public/js/creator-studio-analytics.js"
  cat > "$run/qa-route-snapshot.php" <<'ROUTEPHP'
<?php
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (getenv('QA_PHASE4E5_ISOLATED')!=='1' || !app()->environment('testing') || config('database.default')!=='sqlite' || config('database.connections.sqlite.database')!==':memory:') exit(90);
$out=[];
foreach($app['router']->getRoutes() as $r){
    $methods=$r->methods();sort($methods);
    $key=json_encode([$methods,$r->getDomain(),$r->uri()],JSON_THROW_ON_ERROR);
    if(isset($out[$key])) exit(91);
    $out[$key]=['name'=>$r->getName(),'uri'=>$r->uri(),'methods'=>$methods,'action'=>$r->getActionName(),
        'middleware'=>$r->gatherMiddleware(),'excluded'=>$r->excludedMiddleware(),'wheres'=>$r->wheres,
        'locks'=>$r->locksFor(),'waits'=>$r->waitsFor()];
}
ksort($out);
echo json_encode(['locales'=>\App\Http\Middleware\SetLocale::SUPPORTED,'routes'=>$out],JSON_THROW_ON_ERROR);
ROUTEPHP
  "$PHP" -l "$run/qa-route-snapshot.php"
  key="base64:$(openssl rand -base64 32)"
  composer_hash=$(git hash-object "$run/composer.json")
  lock_hash=$(sha256sum "$run/composer.lock"|cut -d' ' -f1)
  chown -hR "$uid:$uid" "$run"
  # Always mount-isolate live webroot and common database sockets. PHP tests
  # additionally run without network. No weaker fallback if unshare fails.
  isolated(){
    local network=$1 mode=$2 db=$3; shift 3
    local flags=(--mount --pid --fork --kill-child --mount-proc)
    [[ "$network" == off ]] && flags+=(--net)
    unshare "${flags[@]}" bash -c '
      set -Eeuo pipefail
      run=$1; uid=$2; key=$3; mode=$4; php=$5; db=$6; mysqlbase=$7; shift 7
      mount --make-rprivate /
      for target in /www/wwwroot /tmp /www/server/data /www/server/mysql/data /run; do
        if [[ -d "$target" ]]; then mount -t tmpfs -o mode=1777,nosuid,nodev tmpfs "$target"; fi
      done
      cd "$run"
      exec setpriv --reuid="$uid" --regid="$uid" --clear-groups --no-new-privs env -i PATH=/usr/bin:/bin HOME="$run" \
       APP_ENV=testing APP_DEBUG=false APP_URL=https://bambodia.com APP_KEY="$key" \
       BAMBODIA_MCP_ENABLED=false BAMBODIA_MCP_WRITES_ENABLED=false DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= \
       CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array FILESYSTEM_DISK=local LOG_CHANNEL=stderr \
       COMPOSER_HOME="$run/.composer-home" COMPOSER_CACHE_DIR="$run/.composer-cache" COMPOSER_NO_INTERACTION=1 QA_PHASE4_MODE="$mode" \
       QA_PHASE4E5_ISOLATED=1 QA_PHASE4E5_DB="$db" INVOICES_ENABLED=false INVOICES_DRAFT_WRITES_ENABLED=false \
       bash "$run/qa-isolated-db.sh" "$db" "$mysqlbase" "$php" "$@"
    ' _ "$run" "$uid" "$key" "$mode" "$PHP" "$db" "$MYSQL_BASE" "$@"
  }
  cat >"$run/qa-isolated-db.sh" <<'DBSH'
#!/usr/bin/env bash
set -Eeuo pipefail
set +x
umask 077
db=$1; mysqlbase=$2; php=$3; shift 3
if [[ "$db" == sqlite ]]; then exec "$php" "$@"; fi
[[ "$db" == mysql && "$QA_PHASE4E5_ISOLATED" == 1 && "$APP_ENV" == testing ]] || exit 91
[[ "$PWD" =~ ^/var/tmp/bambodia-phase4e5-runtime\.[A-Za-z0-9]+$ ]] || exit 92
# A completely new private InnoDB instance for every PHPUnit invocation.
# No default config, network listener, shared data, password or production socket.
base="$PWD/mysql"; data="$base/data"; sock="$base/mysql.sock"
[[ -d "$base" && ! -L "$base" && ! -e "$data" && ! -e "$sock" ]] || exit 93
mkdir -m 700 "$data"
pid=
cleanup_db(){
  rc=$?
  trap - EXIT
  if [[ -n "$pid" ]]; then kill -TERM "$pid" 2>/dev/null || true; wait "$pid" || true; fi
  rm -rf --one-file-system -- "$data"
  rm -f -- "$sock" "$base/mysqld.pid" "$base/mysqlx.sock" "$base/mysql.sock.lock" "$base/mysqlx.sock.lock"
  exit "$rc"
}
trap cleanup_db EXIT
"$mysqlbase/bin/mysqld" --no-defaults --basedir="$mysqlbase" --datadir="$data" --initialize-insecure --log-error="$base/init.log"
"$mysqlbase/bin/mysqld" --no-defaults --basedir="$mysqlbase" --datadir="$data" --socket="$sock" \
  --pid-file="$base/mysqld.pid" --log-error="$base/server.log" --skip-networking --mysqlx=OFF --skip-log-bin \
  --tmpdir="$base" --innodb-buffer-pool-size=128M &
pid=$!
ready=no
for _ in $(seq 1 60); do
  if "$mysqlbase/bin/mysql" --no-defaults --protocol=SOCKET --socket="$sock" -uroot -Nse 'SELECT 1' >/dev/null 2>&1; then ready=yes; break; fi
  kill -0 "$pid" || exit 94
  sleep 1
done
[[ "$ready" == yes ]] || exit 95
"$mysqlbase/bin/mysql" --no-defaults --protocol=SOCKET --socket="$sock" -uroot <<'SQL'
CREATE DATABASE bambodia_invoice_oauthqa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'invoice_qa'@'localhost' IDENTIFIED BY '';
GRANT ALL PRIVILEGES ON bambodia_invoice_oauthqa.* TO 'invoice_qa'@'localhost';
SQL
export DB_CONNECTION=mysql DB_DATABASE=bambodia_invoice_oauthqa DB_HOST=localhost DB_SOCKET="$sock" DB_USERNAME=invoice_qa DB_PASSWORD= DB_URL=
"$php" "$@"
DBSH
  chmod 700 "$run/qa-isolated-db.sh"
  chown "$uid:$uid" "$run/qa-isolated-db.sh"
  echo "=== Install locked dev dependencies in private runtime ==="
  isolated on baseline sqlite -d include_path=/usr/share/php:. /usr/bin/composer install --no-scripts --no-plugins --prefer-dist --no-interaction --no-progress >"$logs/composer-qa.log" 2>&1 || fail PRIVATE_COMPOSER_INSTALL
  [[ $(git hash-object "$run/composer.json") == "$composer_hash" && $(sha256sum "$run/composer.lock"|cut -d' ' -f1) == "$lock_hash" ]] || fail COMPOSER_LOCK_DRIFT
  [[ -f "$run/vendor/bin/phpunit" ]] || fail PHPUNIT_MISSING
  echo "=== Check isolated runtime before generating keys ==="
  isolated off baseline sqlite -r '
    require "vendor/autoload.php"; $app=require "bootstrap/app.php";
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config("database.default")!=="sqlite" || config("database.connections.sqlite.database")!==":memory:" ||
        app()->environment()!=="testing" || config("mcp.production_enabled")!==false ||
        config("mcp.production_writes_enabled")!==false || config("mail.default")!=="array" ||
        \App\Support\Mcp\McpResource::uri()!=="https://bambodia.com/mcp") exit(9);
    echo "ISOLATED_SQLITE_MEMORY_AND_DISABLED_MCP=PASS\n";
  '
  isolated off baseline sqlite artisan passport:keys --no-interaction --no-ansi >"$logs/keys-qa.log" 2>&1 || fail EPHEMERAL_PASSPORT_KEYS
  [[ -s "$run/storage/oauth-private.key" && -s "$run/storage/oauth-public.key" ]] || fail QA_KEYS_MISSING
  chmod 600 "$run/storage/oauth-private.key" "$run/storage/oauth-public.key"
  test_run(){
    local mode=$1 db=$2; shift 2
    local log="$logs/$mode-$db-qa.log"
    isolated off "$mode" "$db" vendor/bin/phpunit --colors=never tests/Feature/BambodiaPhase4E5IsolatedOAuthTest.php "$@" >"$log" 2>&1 || { echo "PRIVATE_FAILURE_LOG=$log"; fail "${mode^^}_${db^^}_OAUTH_TESTS"; }
    grep -E '^OK \([0-9]+ tests?, [0-9]+ assertions?\)' "$log" || fail TEST_SUMMARY_MISSING
    echo "${mode^^}_${db^^}_OAUTH_TESTS=PASS"
  }
  for db in sqlite mysql; do test_run baseline "$db" --filter=test_registry_snapshot; done
  echo "BASELINE_REGISTRY=38 READ=25 WRITE=13"
  isolated off baseline sqlite qa-route-snapshot.php >"$logs/baseline-routes.json" 2>"$logs/baseline-routes.stderr" || fail BASELINE_ROUTE_SNAPSHOT
  echo "BASELINE_ROUTE_SNAPSHOT=PASS"
  echo "=== Install exact 20-file candidate into disposable runtime ONLY ==="
  for path in "${release_changes[@]}" "${release_additions[@]}"; do
    install -d -o "$uid" -g "$uid" -m 700 "$run/$(dirname "$path")"
    install -o "$uid" -g "$uid" -m 600 "$release/candidate/$path" "$run/$path"
    cmp -s "$run/$path" "$release/candidate/$path" || fail CANDIDATE_COPY_DRIFT
  done
  # Every candidate parent and PHP file is accessible only to the fixture UID.
  for db in sqlite mysql; do test_run candidate "$db"; done
  echo "CANDIDATE_REGISTRY=45 READ=29 WRITE=16"
  isolated off candidate sqlite qa-route-snapshot.php >"$logs/candidate-routes.json" 2>"$logs/candidate-routes.stderr" || fail CANDIDATE_ROUTE_SNAPSHOT
  python3 -B - "$logs/baseline-routes.json" "$logs/candidate-routes.json" <<'ROUTECHECK'
import json,pathlib,sys
b,c=[json.loads(pathlib.Path(p).read_text()) for p in sys.argv[1:]]
def need(ok,label):
    if not ok: raise SystemExit("COMBINED_ROUTE_BLOCKED="+label)
need(b["locales"]==c["locales"],"LOCALE_DRIFT")
old,new=b["routes"],c["routes"]
need(all(k in new and new[k]==v for k,v in old.items()),"EXISTING_ROUTE_METADATA_DRIFT")
spec={
 "account.invoices.index":("account/invoices",["GET","HEAD"],"accountIndex"),
 "account.invoices.show":("account/invoices/{invoice}",["GET","HEAD"],"accountShow"),
 "studio.invoices.index":("studio/invoices",["GET","HEAD"],"studioIndex"),
 "studio.invoices.preview":("studio/invoices/new",["GET","HEAD"],"preview"),
 "studio.invoices.store":("studio/invoices",["POST"],"store"),
 "studio.invoices.show":("studio/invoices/{invoice}",["GET","HEAD"],"studioShow"),
 "studio.invoices.update":("studio/invoices/{invoice}",["PATCH"],"update"),
 "studio.invoices.discard":("studio/invoices/{invoice}/discard",["POST"],"discard"),
}
expected={}
for name,(uri,methods,action) in spec.items():
    expected[name]=(uri,methods,action,name)
    for locale in b["locales"]:
        expected["localized."+locale+"."+name]=(locale+"/"+uri,methods,action,name)
added=[v for k,v in new.items() if k not in old]
need(len(added)==len(expected) and {v["name"] for v in added}==set(expected),"NEW_ROUTE_INVENTORY")
for v in added:
    uri,methods,action,root=expected[v["name"]]
    need(v["uri"]==uri and v["methods"]==methods and v["action"]=="App\\Http\\Controllers\\MarketplaceInvoiceController@"+action,"NEW_ROUTE_ACTION")
    need("auth" in v["middleware"],"AUTH_MIDDLEWARE")
    if root.startswith("studio."): need("role:freelancer,super_admin" in v["middleware"],"STUDIO_ROLE")
    if root in ("studio.invoices.store","studio.invoices.update"): need("throttle:10,1" in v["middleware"],"WRITE_THROTTLE")
    if root=="studio.invoices.discard": need("throttle:5,1" in v["middleware"],"DISCARD_THROTTLE")
    if "{invoice}" in uri: need("invoice" in v["wheres"] and bool(v["wheres"]["invoice"]),"UUID_CONSTRAINT")
print("EXISTING_ROUTE_METADATA_PRESERVED="+str(len(old)))
print("NATIVE_INVOICE_ROUTES=8/8")
print("LOCALIZED_INVOICE_ROUTES="+str(len(added)-8))
print("COMBINED_ROUTE_AUTHORIZATION_METADATA=PASS")
ROUTECHECK
  isolated off candidate sqlite artisan view:cache --no-interaction --no-ansi >"$logs/view-cache-qa.log" 2>&1 || fail COMBINED_VIEW_COMPILATION
  isolated off candidate sqlite artisan view:clear --no-interaction --no-ansi >>"$logs/view-cache-qa.log" 2>&1 || fail PRIVATE_VIEW_CACHE_CLEAR
  echo "COMBINED_VIEW_COMPILATION=PASS"
  for db in sqlite mysql; do
    log="$logs/native-$db-qa.log"
    isolated off candidate "$db" vendor/bin/phpunit --colors=never tests/Feature/BambodiaPhase4E6NativeInvoiceTest.php >"$log" 2>&1 || { echo "PRIVATE_FAILURE_LOG=$log"; fail "NATIVE_TESTS_$db"; }
    grep -E '^OK \([0-9]+ tests?, [0-9]+ assertions?\)' "$log" || fail NATIVE_TEST_SUMMARY_MISSING
    echo "NATIVE_TESTS_$db=PASS"
  done

  echo "=== Rehearse code rollback and baseline discovery ==="
  for path in "${release_changes[@]}"; do
    install -o "$uid" -g "$uid" -m 600 "$release/baseline/$path" "$run/$path"
    cmp -s "$run/$path" "$release/baseline/$path" || fail ROLLBACK_FILE_MISMATCH
  done
  for path in "${release_additions[@]}"; do rm -- "$run/$path"; done
  isolated off rollback sqlite artisan view:clear --no-interaction --no-ansi >"$logs/rollback-view-clear.log" 2>&1 || fail ROLLBACK_VIEW_CLEAR
  isolated off rollback sqlite qa-route-snapshot.php >"$logs/rollback-routes.json" 2>"$logs/rollback-routes.stderr" || fail ROLLBACK_ROUTE_SNAPSHOT
  cmp -s "$logs/baseline-routes.json" "$logs/rollback-routes.json" || fail ROLLBACK_ROUTE_METADATA_DRIFT
  echo "ROLLBACK_ROUTE_METADATA=PASS"
  for db in sqlite mysql; do test_run rollback "$db" --filter=test_registry_snapshot; done
  echo "ROLLBACK_REGISTRY=38 READ=25 WRITE=13"
  echo "ROLLBACK_CODE_FINGERPRINTS=PASS"
  (cd "$LIVE"; sha256sum --check --status "$stage/verified/live-source.sha256") || fail LIVE_SOURCE_DRIFT
  verify_live
  (cd "$release"; sha256sum --check --status manifest.sha256) || fail RELEASE_FINAL_DRIFT
  for path in "${release_changes[@]}"; do cmp -s "$LIVE/$path" "$release/baseline/$path" || fail FINAL_LIVE_DRIFT; done
  for path in "${release_additions[@]}"; do [[ ! -e "$LIVE/$path" && ! -L "$LIVE/$path" ]] || fail FINAL_LIVE_ADDITION_PRESENT; done
  echo "PHASE4E6_COMBINED_ACCEPTANCE=PASS"
  echo "LIVE_REQUEST_FPM_PROBE=NOT_PERFORMED"
  echo "PRODUCTION_FILES_MODIFIED=0"
  echo "PRODUCTION_DATABASE_ACCESSED=NO"
  echo "PRODUCTION_DEPLOYMENT=NOT_STARTED"
}
mode=${1:-}
shift || true
case "$mode" in
  --accept) accept "$@";;
  *) fail USAGE_ACCEPT_APPROVED_OAUTH_STAGE_MANIFEST;;
esac
