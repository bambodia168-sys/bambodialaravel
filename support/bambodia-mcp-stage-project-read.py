#!/usr/bin/env python3
"""Compose Phase 4B and 4C READ-only registry extensions; staging only."""
import argparse
import importlib.util
from pathlib import Path
import re
import subprocess
import tempfile

ROOT = Path(__file__).parent
def load(name, path):
    spec = importlib.util.spec_from_file_location(name, ROOT/path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

commerce = load("phase4b", "bambodia-mcp-stage-commerce-read.py")
NAMES = ("bambodia_tracking_list","bambodia_tracking_get","bambodia_contracts_list","bambodia_milestones_list")
CLASS = b"\\App\\Support\\Mcp\\ProjectReadTools"
ADD_DISPATCH = b"            " + b",\n            ".join(b"'" + n.encode() + b"'" for n in NAMES) + b" => app(" + CLASS + b"::class)->call($name, $args, $owner, $token->allows('mcp.read')),\n"
ADD_DEFINITION = b"        $base = [...$base, ..." + CLASS + b"::definitions()];\n"

def stage(source):
    for anchor in (commerce.DISPATCH, commerce.DEFINITION, commerce.ADD_DISPATCH, commerce.ADD_DEFINITION):
        if source.count(anchor) != 1:
            raise ValueError("Expected unique Phase 4B anchors")
    # Reconstruct and verify the full preceding patch rather than accept partial integration.
    before = source.replace(commerce.ADD_DISPATCH,b"",1).replace(commerce.ADD_DEFINITION,b"",1)
    if commerce.stage(before) != source:
        raise ValueError("Commerce registry mismatch")
    for name in NAMES:
        if re.search(rb"['\"]" + name.encode() + rb"['\"]", source):
            raise ValueError("Project tool already present")
    result = source.replace(commerce.DISPATCH, ADD_DISPATCH+commerce.DISPATCH, 1).replace(commerce.DEFINITION, ADD_DEFINITION+commerce.DEFINITION, 1)
    if result.replace(ADD_DISPATCH,b"",1).replace(ADD_DEFINITION,b"",1) != source:
        raise ValueError("Unrelated source changed")
    return result

def self_test():
    p3=load("phase3","bambodia-mcp-stage-advanced-content.py")
    original=p3.stage((ROOT/"bambodia-mcp-phase3-controller-fixture.php").read_bytes())
    prior=commerce.stage(original)
    candidate=stage(prior)
    for bad in (candidate,prior+commerce.DISPATCH,prior.replace(commerce.ADD_DEFINITION,b"")):
        try:
            stage(bad)
        except ValueError:
            continue
        raise AssertionError("Unsafe candidate accepted")
    with tempfile.TemporaryDirectory() as tmp:
        a=Path(tmp)/"before.php"; b=Path(tmp)/"after.php"
        a.write_bytes(prior); b.write_bytes(candidate)
        for f in (a,b):
            subprocess.run(["php","-l",str(f)],check=True)
        subprocess.run(["php",str(ROOT/"bambodia-mcp-phase4c-controller-harness.php"),str(a),str(b)],check=True)
    print("PASS: additive four-tool Project READ integration")

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument("--source",type=Path); p.add_argument("--output",type=Path)
    p.add_argument("--self-test",action="store_true")
    a=p.parse_args()
    if a.self_test:
        self_test()
        return
    if not a.source or not a.output or a.source.is_symlink() or a.output.exists() or a.output.is_symlink() or a.source.resolve()==a.output.resolve():
        p.error("Require separate new staging output")
    source=a.source.read_bytes()
    if commerce.blob(source)!=commerce.EXPECTED:
        p.error("Exact accepted Phase 3 controller required")
    prior=commerce.stage(source)
    result=stage(prior)
    with a.output.open("xb") as f:
        f.write(result)
    print("SOURCE_BLOB="+commerce.blob(source))
    print("PHASE4B_BLOB="+commerce.blob(prior))
    print("CANDIDATE_BLOB="+commerce.blob(result))
    print("PRODUCTION_FILES_MODIFIED=0")

if __name__=="__main__":
    main()
