#!/usr/bin/env python3
"""Add six READ entries to the exact reviewed Phase 3 OAuth overlay, staging only."""
import argparse
import hashlib
from pathlib import Path
import re
import subprocess
import tempfile
import importlib.util

NAMES = ("bambodia_orders_list", "bambodia_order_get", "bambodia_transactions_summary",
         "bambodia_receipt_get", "bambodia_licenses_list", "bambodia_license_get")
EXPECTED = "832c692c12ef23e3584cc820512619383822c406"
DISPATCH = b"            'bambodia_overview' => $service->overview(),"
DEFINITION = b"        $base[] = [\n            'name' => 'bambodia_analytics',"
CLASS = b"\\App\\Support\\Mcp\\CommerceReadTools"
ADD_DISPATCH = b"            " + b",\n            ".join(b"'" + n.encode() + b"'" for n in NAMES) + b" => app(" + CLASS + b"::class)->call($name, $args, $owner, $token->allows('mcp.read')),\n"
ADD_DEFINITION = b"        $base = [...$base, ..." + CLASS + b"::definitions()];\n"

def blob(data):
    return hashlib.sha1(b"blob " + str(len(data)).encode() + b"\0" + data).hexdigest()

def stage(source):
    for anchor in (DISPATCH, DEFINITION):
        if source.count(anchor) != 1:
            raise ValueError("Unique anchors required")
    for marker in (b"AdvancedContentTools::definitions(", b"OperationalReadTools::definitions()",
                   b"bambodia_media_metadata", b"private function authorizeDefinitions(",
                   b"$token->allows('mcp.write')", b"mcp.production_writes_enabled",
                   b"bambodia_api_owner", b"bambodia_api_token", b"$definition['securitySchemes']"):
        if marker not in source:
            raise ValueError("Existing Phase 3 authorization structure missing")
    for name in NAMES:
        if re.search(rb"['\"]" + name.encode() + rb"['\"]", source):
            raise ValueError("Commerce tool already present")
    result = source.replace(DISPATCH, ADD_DISPATCH+DISPATCH, 1).replace(DEFINITION, ADD_DEFINITION+DEFINITION, 1)
    if result.replace(ADD_DISPATCH,b"",1).replace(ADD_DEFINITION,b"",1) != source:
        raise ValueError("Unrelated source changed")
    return result

def self_test():
    root=Path(__file__).parent
    spec=importlib.util.spec_from_file_location("phase3",root/"bambodia-mcp-stage-advanced-content.py")
    phase3=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(phase3)
    original=phase3.stage((root/"bambodia-mcp-phase3-controller-fixture.php").read_bytes())
    candidate=stage(original)
    for bad in (candidate, original+DISPATCH, original.replace(b"AdvancedContentTools::definitions(",b"missing(")):
        try:
            stage(bad)
        except ValueError:
            continue
        raise AssertionError("Unsafe candidate accepted")
    with tempfile.TemporaryDirectory() as tmp:
        a=Path(tmp)/"before.php"; b=Path(tmp)/"after.php"
        a.write_bytes(original); b.write_bytes(candidate)
        for f in (a,b):
            subprocess.run(["php","-l",str(f)],check=True)
        harness=root/"bambodia-mcp-phase4b-controller-harness.php"
        subprocess.run(["php",str(harness),str(a),str(b)],check=True)
    print("PASS: exact additive READ integration and drift rejection")

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
    if blob(source)!=EXPECTED:
        p.error("Source drift: exact accepted Phase 3 controller required")
    result=stage(source)
    with a.output.open("xb") as f:
        f.write(result)
    print("SOURCE_BLOB="+blob(source))
    print("CANDIDATE_BLOB="+blob(result))
    print("PRODUCTION_FILES_MODIFIED=0")

if __name__=="__main__":
    main()
