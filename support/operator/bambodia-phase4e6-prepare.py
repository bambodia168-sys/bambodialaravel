#!/usr/bin/env python3
"""Source-only production release preparation; no apply/deploy mode."""
import argparse, difflib, hashlib, json, os, pathlib, re, shutil, stat, subprocess, tempfile
ROOT=pathlib.Path("/www/wwwroot/bambodia-staging-qa")
REPO="bambodia168-sys/bambodialaravel"
BASE="c1db97f9798cdd0e9291fb943d5a1a697c2b9f48"
APP="fd91345ed14fea36ed62f60d0cf43d3dc26024f2"
OP="aafab0d6bcc387fb1b464b9d827e8ff3fc2344b6"
STAGE=pathlib.Path("/var/tmp/bambodia-phase4e5-review.h8f9V45B")
STAGE_HASH="8e1321863391d2b442d812dc3784d1963aae53902d89b2dd997eed5239242e1d"
PHP="/www/server/php/85/bin/php"
def need(ok,msg):
    if not ok: raise SystemExit("PHASE4E6A_BLOCKED="+msg)
def blob(b): return hashlib.sha1(b"blob "+str(len(b)).encode()+b"\0"+b).hexdigest()
def sha(b): return hashlib.sha256(b).hexdigest()
def safe(root,rel,exists=True):
    p=pathlib.Path(rel)
    need(not p.is_absolute() and ".." not in p.parts and p.parts,"UNSAFE_RELATIVE_PATH")
    cur=root
    for part in p.parts:
        cur=cur/part
        need(not cur.is_symlink(),"SYMLINK_REFUSED:"+rel)
    if exists: need(cur.is_file(),"MISSING_FILE:"+rel)
    return cur
def pinned(data,h,label):
    need(isinstance(h,str) and re.fullmatch(r"[a-f0-9]{40}",h) and blob(data)==h,"BLOB_MISMATCH:"+label)
    return data
def gitbytes(root,ref,path):
    return subprocess.check_output(["git","-C",str(root),"show",ref+":"+path])
def download(ref,path):
    need(ref in (BASE,APP),"REF_NOT_APPROVED")
    return subprocess.check_output(["curl","-fLsS","--retry","3","--connect-timeout","15","--max-time","90",
       "https://raw.githubusercontent.com/"+REPO+"/"+ref+"/"+path])
def patch(base,candidate,live,path):
    b=base.splitlines(keepends=True); c=candidate.splitlines(keepends=True)
    edits=difflib.SequenceMatcher(None,b,c,autojunk=False).get_opcodes()
    # Pinned live routes contain additional OAuth routes; only exact native
    # hunks may be changed. Each match includes two unchanged context lines.
    out=live
    for tag,i,j,k,l in reversed(edits):
        if tag=="equal": continue
        left=b[max(0,i-2):i];right=b[j:min(len(b),j+2)]
        old=b"".join(left+b[i:j]+right);new=b"".join(left+c[k:l]+right)
        need(old and out.count(old)==1,"AMBIGUOUS_OR_MISSING_PATCH_ANCHOR:"+path)
        need(old!=new,"EMPTY_PATCH:"+path)
        out=out.replace(old,new,1)
    return out
def route_patch(base,candidate,live):
    path="routes/web.php"
    b=base.splitlines(keepends=True); c=candidate.splitlines(keepends=True)
    edits=[(tag,i,j,k,l) for tag,i,j,k,l in difflib.SequenceMatcher(None,b,c,autojunk=False).get_opcodes() if tag!="equal"]
    need(len(edits)==3,"ROUTE_INSERTION_GROUPS")
    anchors=(
        b"use App\\Http\\Controllers\\CommerceOrderController;\n",
        b"    Route::get('/account/policy-receipts', [PolicyAcknowledgementController::class,'index'])->name('account.policy-receipts');\n",
        b"    Route::middleware('role:freelancer,super_admin')->prefix('studio')->group(function (): void {\n",
    )
    pairs=[]
    for (tag,i,j,k,l),anchor,count in zip(edits,anchors,(1,2,6)):
        addition=b"".join(c[k:l])
        need(tag=="insert" and i>0 and b[i-1]==anchor and len(c[k:l])==count and b"MarketplaceInvoiceController" in addition,"UNEXPECTED_ROUTE_EDIT")
        pairs.append((anchor,addition))
    def apply(source):
        need(b"MarketplaceInvoiceController" not in source,"INVOICE_ROUTES_ALREADY_PRESENT")
        out=source
        for anchor,addition in pairs:
            need(out.splitlines(keepends=True).count(anchor)==1 and out.count(anchor)==1,"ROUTE_ANCHOR_NOT_UNIQUE")
            need(addition not in out,"ROUTE_INSERTION_ALREADY_PRESENT")
            out=out.replace(anchor,anchor+addition,1)
        return out
    need(apply(base)==candidate,"TRACKED_ROUTE_RECONSTRUCTION")
    out=apply(live)
    restored=out
    for anchor,addition in reversed(pairs):
        need(restored.count(anchor+addition)==1,"ROUTE_REVERSE_ANCHOR")
        restored=restored.replace(anchor+addition,anchor,1)
    need(restored==live and len(out.splitlines())-len(live.splitlines())==9,"ROUTE_REVERSE_MISMATCH")
    return out
def load_manifest(p):
    m=json.loads(p.read_text())
    need((m.get("repo"),m.get("base"),m.get("application"),m.get("operator"),m.get("oauth_stage_manifest"),m.get("oauth_stage"))==(REPO,BASE,APP,OP,STAGE_HASH,str(STAGE)),"MANIFEST_IDENTITY")
    f=m["files"];need(len(f)==20 and len({x["path"] for x in f})==20,"FILE_INVENTORY")
    need(sum(x["mode"]=="add" for x in f)==14 and sum(x["mode"]=="patch" for x in f)==4 and sum(x["mode"]=="accepted_overlay" for x in f)==2,"FILE_MODE_INVENTORY")
    need(m["flags"]=={"INVOICES_ENABLED":False,"INVOICES_DRAFT_WRITES_ENABLED":False},"FEATURE_POLICY")
    reviewed={"routes/web.php":("375dc03d5d0cca5e4e584f997501d5b528bca29a","d622b26ff1dffaee4620f1c3ed59432d4d5384f3","exact_invoice_route_insertions"),"resources/views/layouts/workspace.blade.php":("378f2411d646bce1a9c1c227109627fd9d7ac58f","f357adb0b0ccb6e824614b9e195d5b7df6502601","exact_context_patch")}
    for e in f:
        if e["path"] in reviewed: need((e["live"],e["staged"],e["strategy"])==reviewed[e["path"]],"RECONCILED_PIN_MISMATCH")
    return m
def selftest(m,root):
    for e in m["files"]:
        path=e["path"]
        if e["mode"]=="accepted_overlay": continue
        c=pinned(gitbytes(root,APP,path),e["candidate"],path)
        if e["mode"]=="patch":
            b=pinned(gitbytes(root,BASE,path),e["base"],path)
            need(patch(b,c,b,path)==c,"PATCH_RECONSTRUCTION:"+path)
            if path=="routes/web.php":
                # Simulate unrelated appended overlay bytes and prove retention.
                extra=b"\n// Synthetic unrelated OAuth overlay sentinel\n"
                need(patch(b,c,b+extra,path)==c+extra,"OVERLAY_RETENTION")
                need(route_patch(b,c,b)==c,"TARGETED_ROUTE_RECONSTRUCTION")
                cart=b"use App\\Http\\Controllers\\CommerceCartController;\n"
                review=b"use App\\Http\\Controllers\\MarketplaceOrderReviewController;\n"
                need(b.count(review+cart)==1,"IMPORT_REORDER_FIXTURE")
                drift=b.replace(review+cart,cart+review,1)+extra
                need(route_patch(b,c,drift)==c.replace(review+cart,cart+review,1)+extra,"TARGETED_ROUTE_DRIFT_RETENTION")
                try: route_patch(b,c,drift.replace(b"use App\\Http\\Controllers\\CommerceOrderController;\n",b"",1))
                except SystemExit: pass
                else: need(False,"MISSING_TARGETED_ANCHOR_ACCEPTED")
                try: patch(b,c,b"",path)
                except SystemExit: pass
                else: need(False,"MISSING_ANCHOR_ACCEPTED")
    print("RELEASE_TRACKED_INVENTORY=18/18")
    print("NATIVE_PATCH_RECONSTRUCTION=4/4")
    print("RELEASE_OVERLAY_SELF_TEST=PASS")
def prepare(m):
    need(os.geteuid()==0,"ROOT_REQUIRED")
    os.umask(0o077)
    need(ROOT.is_dir() and ROOT.resolve()==ROOT and STAGE.is_dir() and STAGE.resolve()==STAGE,"ROOT_OR_STAGE_IDENTITY")
    s=STAGE.stat();need(s.st_uid==0 and stat.S_IMODE(s.st_mode)==0o700,"STAGE_PERMISSIONS")
    need(sha(safe(STAGE,"manifest.sha256").read_bytes())==STAGE_HASH,"OAUTH_STAGE_MANIFEST")
    for name in ("source","candidate","verified"):
        need(not (STAGE/name).is_symlink(),"STAGE_SYMLINK")
        need(not any(p.is_symlink() for p in (STAGE/name).rglob("*")),"STAGE_SYMLINK")
    def check_manifest(root,manifest):
        for line in manifest.read_text().splitlines():
            match=re.fullmatch(r"([0-9a-f]{64})  (.+)",line)
            need(match is not None,"UNEXPECTED_MANIFEST_FORMAT")
            p=safe(root,match[2]);need(sha(p.read_bytes())==match[1],"MANIFEST_DRIFT:"+match[2])
    check_manifest(STAGE,safe(STAGE,"manifest.sha256"))
    check_manifest(ROOT,safe(STAGE,"verified/live-source.sha256"))
    work=pathlib.Path(tempfile.mkdtemp(prefix="bambodia-phase4e6-review.",dir="/var/tmp"))
    print("RELEASE_STAGE="+str(work),flush=True)
    records=[]
    for e in m["files"]:
        path=e["path"];mode=e["mode"]
        dest=safe(work,"candidate/"+path,False);dest.parent.mkdir(parents=True,exist_ok=True)
        livepath=safe(ROOT,path,False)
        before=None
        if mode=="add":
            need(not livepath.exists(),"ADDITIVE_PATH_ALREADY_EXISTS:"+path)
            out=pinned(download(APP,path),e["candidate"],path)
            old=safe(STAGE,"candidate/"+path,False)
            if old.exists(): need(old.read_bytes()==out,"OAUTH_ACCEPTED_ADDITION_DIFFERS:"+path)
        else:
            before=pinned(safe(ROOT,path).read_bytes(),e["live"],path)
            saved=safe(work,"baseline/"+path,False);saved.parent.mkdir(parents=True,exist_ok=True);saved.write_bytes(before)
            if mode=="accepted_overlay":
                need(safe(STAGE,"source/"+path).read_bytes()==before,"OAUTH_BASELINE_DRIFT:"+path)
                out=pinned(safe(STAGE,"candidate/"+path).read_bytes(),e["staged"],path)
            else:
                b=pinned(download(BASE,path),e["base"],path)
                c=pinned(download(APP,path),e["candidate"],path)
                need(patch(b,c,b,path)==c,"PATCH_RECONSTRUCTION:"+path)
                out=route_patch(b,c,before) if path=="routes/web.php" else patch(b,c,before,path)
                if before==b: need(out==c,"EXACT_CANDIDATE_MISMATCH:"+path)
                if path=="resources/views/layouts/workspace.blade.php":
                    need(patch(c,b,out,path)==before,"WORKSPACE_REVERSIBILITY")
                    for name in (b"studio.invoices.index",b"account.invoices.index"):
                        need(before.count(name)==0 and out.count(name)==1,"WORKSPACE_INVOICE_NAVIGATION")
                if path in ("routes/web.php","resources/views/layouts/workspace.blade.php"):
                    pinned(out,e["staged"],"RECONCILED:"+path)
        dest.write_bytes(out)
        if path.endswith(".php") and not path.endswith(".blade.php"):
            subprocess.run([PHP,"-l",str(dest)],check=True,stdout=subprocess.DEVNULL)
        records.append({"path":path,"mode":mode,"before_blob":blob(before) if before is not None else None,
             "after_blob":blob(out),"after_sha256":sha(out),"before_sha256":sha(before) if before is not None else None})
        print("RELEASE_FILE="+path+" BEFORE="+(blob(before) if before is not None else "ABSENT")+" AFTER="+blob(out))
    check_manifest(ROOT,safe(STAGE,"verified/live-source.sha256"))
    for e in records:
        p=safe(ROOT,e["path"],False)
        need(not p.exists() if e["before_blob"] is None else blob(p.read_bytes())==e["before_blob"],"LIVE_DRIFT:"+e["path"])
    (work/"release.json").write_text(json.dumps({"application":APP,"operator":OP,"oauth_stage_manifest":STAGE_HASH,"files":records,"flags":m["flags"],"schema_preflight":"NOT_RUN","deployment":"NOT_AUTHORIZED"},indent=2)+"\n")
    files=sorted(p for p in work.rglob("*") if p.is_file())
    (work/"manifest.sha256").write_text("".join(sha(p.read_bytes())+"  "+str(p.relative_to(work))+"\n" for p in files))
    print("RELEASE_MANIFEST_SHA256="+sha((work/"manifest.sha256").read_bytes()))
    print("RELEASE_FILES=20 ADD=14 PATCH=4 ACCEPTED_OVERLAY=2")
    print("RELEASE_SOURCE_PREPARATION=PASS")
    print("PRODUCTION_FILES_MODIFIED=0")
    print("PRODUCTION_DATABASE_ACCESSED=NO")
    print("PRODUCTION_DEPLOYMENT=NOT_STARTED")
if __name__=="__main__":
    parser=argparse.ArgumentParser()
    parser.add_argument("manifest",type=pathlib.Path)
    group=parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--prepare",action="store_true")
    group.add_argument("--self-test",type=pathlib.Path)
    args=parser.parse_args()
    m=load_manifest(args.manifest)
    if args.prepare: prepare(m)
    else: selftest(m,args.self_test)
