#!/usr/bin/env python3
"""Add four invoice READ dispatch/definition entries to the approved overlay; never install."""
import argparse
import importlib.util
from pathlib import Path
import re
import subprocess
import tempfile

ROOT = Path(__file__).parent
spec = importlib.util.spec_from_file_location("phase4c_write", ROOT/"bambodia-mcp-stage-project-write.py")
prior = importlib.util.module_from_spec(spec)
spec.loader.exec_module(prior)
commerce = prior.commerce
EXPECTED = "a302fa364f1f52b6320b77688b7595b91894ca13"
NAMES = ("bambodia_invoices_list","bambodia_invoice_get","bambodia_invoices_summary","bambodia_invoice_source_preview")
CLASS = b"\\App\\Support\\Mcp\\InvoiceReadTools"
DISPATCH = b"            " + b",\n            ".join(b"'"+n.encode()+b"'" for n in NAMES) + b" => app("+CLASS+b"::class)->call($name, $args, $owner, $token->allows('mcp.read')),\n"
DEFINITION = b"        $base = [...$base, ..."+CLASS+b"::definitions()];\n"

def stage(source):
    for anchor in (commerce.DISPATCH, commerce.DEFINITION, prior.DISPATCH, prior.DEFINITION,
                   prior.project.ADD_DISPATCH, prior.project.ADD_DEFINITION, commerce.ADD_DISPATCH, commerce.ADD_DEFINITION):
        if source.count(anchor)!=1:
            raise ValueError("Unique accepted Phase 4C anchors required")
    if prior.phase3.GATE not in source or b"private function authorizeDefinitions(" not in source:
        raise ValueError("Existing authorization gate missing")
    for name in NAMES:
        if re.search(rb"['\"]"+name.encode()+rb"['\"]",source):
            raise ValueError("Invoice READ already present")
    result=source.replace(commerce.DISPATCH,DISPATCH+commerce.DISPATCH,1).replace(commerce.DEFINITION,DEFINITION+commerce.DEFINITION,1)
    if result.replace(DISPATCH,b"",1).replace(DEFINITION,b"",1)!=source:
        raise ValueError("Unrelated source bytes changed")
    return result

def self_test():
    fixture=(ROOT/"bambodia-mcp-phase3-controller-fixture.php").read_bytes()
    base=prior.stage(prior.project.stage(commerce.stage(prior.phase3.stage(fixture))))
    candidate=stage(base)
    for bad in (candidate,base+commerce.DISPATCH,base.replace(prior.DEFINITION,b""),base.replace(prior.phase3.GATE,b"")):
        try:
            stage(bad)
        except ValueError:
            continue
        raise AssertionError("Unsafe candidate accepted")
    with tempfile.TemporaryDirectory() as tmp:
        a=Path(tmp)/"before.php"; b=Path(tmp)/"after.php"
        a.write_bytes(base); b.write_bytes(candidate)
        for f in (a,b): subprocess.run(["php","-l",str(f)],check=True)
        subprocess.run(["php",str(ROOT/"bambodia-mcp-phase4e2b-controller-harness.php"),str(a),str(b)],check=True)
    print("INVOICE_READ_ADDITIVE_OVERLAY=PASS")

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument("--source",type=Path);p.add_argument("--output",type=Path);p.add_argument("--self-test",action="store_true")
    a=p.parse_args()
    if a.self_test:
        self_test();return
    if not a.source or not a.output or a.source.is_symlink() or a.output.exists() or a.output.is_symlink() or a.source.resolve()==a.output.resolve():
        p.error("Require separate new staging output")
    source=a.source.read_bytes()
    if commerce.blob(source)!=EXPECTED:
        p.error("Exact approved Phase 4C WRITE overlay required")
    result=stage(source)
    with a.output.open("xb") as f: f.write(result)
    print("SOURCE_BLOB="+commerce.blob(source))
    print("CANDIDATE_BLOB="+commerce.blob(result))
    print("PRODUCTION_FILES_MODIFIED=0")

if __name__=="__main__":
    main()
