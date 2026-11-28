#!/usr/bin/env python3
"""Compose all Phase 4 registry extensions, writing only a new staging file."""
import argparse
import importlib.util
from pathlib import Path
import re
import subprocess
import tempfile

ROOT=Path(__file__).parent
def load(name,path):
    spec=importlib.util.spec_from_file_location(name,ROOT/path)
    module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

project=load("phase4c","bambodia-mcp-stage-project-read.py")
commerce=project.commerce
phase3=load("phase3","bambodia-mcp-stage-advanced-content.py")
NAMES=("bambodia_tracking_task_create","bambodia_tracking_task_update",
       "bambodia_contract_milestone_create","bambodia_tracking_comment_create")
CLASS=b"\\App\\Support\\Mcp\\ProjectWriteTools"
names=lambda seq: b",".join(b"'"+x.encode()+b"'" for x in seq)
OLD_WRITES=phase3.OLD_WRITES[:-2]+b","+names(phase3.WRITES)+b"];"
OLD_SECURITY=phase3.SECURITY_WRITES.removesuffix(b"        ];")+b"            "+names(phase3.WRITES)+b",\n        ];"
DISPATCH=b"            "+b",\n            ".join(b"'"+n.encode()+b"'" for n in NAMES)+b" => app("+CLASS+b"::class)->call($name, $args, $owner, $token->allows('mcp.read'), $token->allows('mcp.write')),\n"
DEFINITION=b"        $base = [...$base, ..."+CLASS+b"::definitions((bool) config('mcp.production_writes_enabled', false))];\n"

def stage(source):
    for anchor in (commerce.DISPATCH,commerce.DEFINITION,OLD_WRITES,OLD_SECURITY,project.ADD_DISPATCH,project.ADD_DEFINITION):
        if source.count(anchor)!=1:
            raise ValueError("Unique Phase 4C anchors required")
    before=source.replace(project.ADD_DISPATCH,b"",1).replace(project.ADD_DEFINITION,b"",1)
    if project.stage(before)!=source or phase3.GATE not in source:
        raise ValueError("Phase 4C or WRITE gate mismatch")
    for n in NAMES:
        if re.search(rb"['\"]"+n.encode()+rb"['\"]",source):
            raise ValueError("Project WRITE already present")
    changes=[(commerce.DISPATCH,DISPATCH+commerce.DISPATCH),(commerce.DEFINITION,DEFINITION+commerce.DEFINITION),
             (OLD_WRITES,OLD_WRITES[:-2]+b","+names(NAMES)+b"];"),
             (OLD_SECURITY,OLD_SECURITY.removesuffix(b"        ];")+b"            "+names(NAMES)+b",\n        ];")]
    result=source
    for old,new in changes:
        result=result.replace(old,new,1)
    for n in NAMES:
        if result.count(b"'"+n.encode()+b"'")!=3:
            raise ValueError("Each WRITE must appear in dispatch and both authorization lists")
    restored=result
    for old,new in reversed(changes):
        if restored.count(new)!=1:
            raise ValueError("Ambiguous integration")
        restored=restored.replace(new,old,1)
    if restored!=source:
        raise ValueError("Unrelated bytes changed")
    return result

def self_test():
    p3=phase3.stage((ROOT/"bambodia-mcp-phase3-controller-fixture.php").read_bytes())
    prior=project.stage(commerce.stage(p3))
    candidate=stage(prior)
    for bad in (candidate,prior+commerce.DISPATCH,prior.replace(OLD_SECURITY,b""),prior.replace(phase3.GATE,b"")):
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
        subprocess.run(["php",str(ROOT/"bambodia-mcp-phase4c-write-controller-harness.php"),str(a),str(b)],check=True)
    print("PASS: additive four-tool Project WRITE integration")

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
    prior=project.stage(commerce.stage(source))
    result=stage(prior)
    with a.output.open("xb") as f:
        f.write(result)
    print("SOURCE_BLOB="+commerce.blob(source))
    print("PHASE4C_READ_BLOB="+commerce.blob(prior))
    print("CANDIDATE_BLOB="+commerce.blob(result))
    print("PRODUCTION_FILES_MODIFIED=0")

if __name__=="__main__":
    main()
