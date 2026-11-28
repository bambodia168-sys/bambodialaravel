#!/usr/bin/env python3
"""Compose Invoice READ and WRITE additions on the pinned 38-tool overlay; staging only."""
import argparse
import importlib.util
from pathlib import Path
import re
import subprocess
import tempfile

ROOT=Path(__file__).parent
spec=importlib.util.spec_from_file_location("invoice_read",ROOT/"bambodia-mcp-stage-invoice-read.py")
read=importlib.util.module_from_spec(spec)
spec.loader.exec_module(read)
prior=read.prior
commerce=read.commerce
NAMES=("bambodia_invoice_draft_create","bambodia_invoice_draft_update","bambodia_invoice_draft_discard")
CLASS=b"\\App\\Support\\Mcp\\InvoiceWriteTools"
OLD_WRITES=prior.OLD_WRITES[:-2]+b","+prior.names(prior.NAMES)+b"];"
OLD_SECURITY=prior.OLD_SECURITY.removesuffix(b"        ];")+b"            "+prior.names(prior.NAMES)+b",\n        ];"
DISPATCH=b"            "+b",\n            ".join(b"'"+n.encode()+b"'" for n in NAMES)+b" => app("+CLASS+b"::class)->call($name, $args, $owner, $token->allows('mcp.read'), $token->allows('mcp.write')),\n"
DEFINITION=b"        $base = [...$base, ..."+CLASS+b"::definitions((bool) config('mcp.production_writes_enabled', false))];\n"

def stage(source):
    for anchor in (read.DISPATCH,read.DEFINITION,commerce.DISPATCH,commerce.DEFINITION,OLD_WRITES,OLD_SECURITY):
        if source.count(anchor)!=1:
            raise ValueError("Unique accepted READ and WRITE anchors required")
    before=source.replace(read.DISPATCH,b"",1).replace(read.DEFINITION,b"",1)
    if read.stage(before)!=source: raise ValueError("Invoice READ overlay mismatch")
    for n in NAMES:
        if re.search(rb"['\"]"+n.encode()+rb"['\"]",source): raise ValueError("Already integrated")
    changes=[(commerce.DISPATCH,DISPATCH+commerce.DISPATCH),(commerce.DEFINITION,DEFINITION+commerce.DEFINITION),
             (OLD_WRITES,OLD_WRITES[:-2]+b","+prior.names(NAMES)+b"];"),
             (OLD_SECURITY,OLD_SECURITY.removesuffix(b"        ];")+b"            "+prior.names(NAMES)+b",\n        ];")]
    result=source
    for old,new in changes: result=result.replace(old,new,1)
    for n in NAMES:
        if result.count(b"'"+n.encode()+b"'")!=3: raise ValueError("Missing dispatch or WRITE gate")
    restored=result
    for old,new in reversed(changes):
        if restored.count(new)!=1: raise ValueError("Ambiguous change")
        restored=restored.replace(new,old,1)
    if restored!=source: raise ValueError("Unrelated bytes changed")
    return result

def self_test():
    fixture=(ROOT/"bambodia-mcp-phase3-controller-fixture.php").read_bytes()
    base=read.stage(prior.stage(prior.project.stage(commerce.stage(prior.phase3.stage(fixture)))))
    candidate=stage(base)
    for bad in (candidate,base+commerce.DISPATCH,base.replace(OLD_SECURITY,b""),base.replace(read.DEFINITION,b"")):
        try: stage(bad)
        except ValueError: continue
        raise AssertionError("Unsafe overlay accepted")
    with tempfile.TemporaryDirectory() as tmp:
        a=Path(tmp)/"before.php";b=Path(tmp)/"after.php"
        a.write_bytes(base);b.write_bytes(candidate)
        for f in (a,b): subprocess.run(["php","-l",str(f)],check=True)
        subprocess.run(["php",str(ROOT/"bambodia-mcp-phase4e3-controller-harness.php"),str(a),str(b)],check=True)
    print("INVOICE_WRITE_ADDITIVE_OVERLAY=PASS")

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument("--source",type=Path);p.add_argument("--output",type=Path);p.add_argument("--self-test",action="store_true")
    a=p.parse_args()
    if a.self_test: self_test();return
    if not a.source or not a.output or a.source.is_symlink() or a.output.exists() or a.output.is_symlink() or a.source.resolve()==a.output.resolve():
        p.error("Require separate new staging output")
    source=a.source.read_bytes()
    if commerce.blob(source)!=read.EXPECTED: p.error("Exact approved 38-tool overlay required")
    intermediate=read.stage(source);result=stage(intermediate)
    with a.output.open("xb") as f: f.write(result)
    print("SOURCE_BLOB="+commerce.blob(source))
    print("READ_CANDIDATE_BLOB="+commerce.blob(intermediate))
    print("WRITE_CANDIDATE_BLOB="+commerce.blob(result))
    print("PRODUCTION_FILES_MODIFIED=0")

if __name__=="__main__": main()
