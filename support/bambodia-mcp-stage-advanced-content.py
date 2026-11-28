#!/usr/bin/env python3
"""Build a staged Phase 3 controller candidate; never install or modify live source."""
import argparse
import hashlib
from pathlib import Path
import re
import subprocess
import tempfile

READS = ("bambodia_categories", "bambodia_content_summary", "bambodia_media_search",
         "bambodia_draft_validate", "bambodia_draft_preview")
WRITES = ("bambodia_draft_duplicate", "bambodia_content_metadata", "bambodia_media_metadata")
DISPATCH = b"            'bambodia_overview' => $service->overview(),"
DEFINITION = b"        $base[] = [\n            'name' => 'bambodia_analytics',"
OLD_WRITES = b"$writeNames = ['bambodia_create_draft','bambodia_update_draft','bambodia_delete_draft','bambodia_update_own_profile','bambodia_manage_site','bambodia_draft_media'];"
SECURITY_WRITES = b"            'bambodia_manage_site', 'bambodia_draft_media',\n        ];"
GATE = b"(! $token->allows('mcp.write') || ! config('mcp.production_writes_enabled', false))"
CLASS = b"\\App\\Support\\Mcp\\AdvancedContentTools"

def blob(data):
    return hashlib.sha1(b"blob " + str(len(data)).encode() + b"\0" + data).hexdigest()

def stage(source):
    for anchor in (DISPATCH, DEFINITION, OLD_WRITES, SECURITY_WRITES):
        if source.count(anchor) != 1:
            raise ValueError("Unique dispatch, definition and two write-list anchors required.")
    for marker in (GATE, b"private function authorizeDefinitions(", b"private function callTool(",
                   b"private function definitions(", b"bambodia_api_owner", b"bambodia_api_token",
                   b"$definition['securitySchemes']", b"bambodia_accounts_summary",
                   b"OperationalReadTools::definitions()", b"$token->allows('mcp.read')"):
        if marker not in source:
            raise ValueError("Existing OAuth or Phase 2A contract not found.")
    for name in READS + WRITES:
        if re.search(rb"['\"]" + name.encode() + rb"['\"]", source):
            raise ValueError("Phase 3 tool already exists.")
    names = lambda seq: b",".join(b"'" + x.encode() + b"'" for x in seq)
    dispatch = b"            " + b",\n            ".join(b"'" + x.encode() + b"'" for x in READS + WRITES) + b" => app(" + CLASS + b"::class)->call($name, $args, $owner, $token->allows('mcp.read'), $token->allows('mcp.write')),\n"
    definitions = b"        $base = [...$base, ..." + CLASS + b"::definitions((bool) config('mcp.production_writes_enabled', false))];\n"
    new_writes = OLD_WRITES[:-2] + b"," + names(WRITES) + b"];"
    new_security = SECURITY_WRITES.removesuffix(b"        ];") + b"            " + names(WRITES) + b",\n        ];"
    changes = [(DISPATCH, dispatch + DISPATCH), (DEFINITION, definitions + DEFINITION),
               (OLD_WRITES, new_writes), (SECURITY_WRITES, new_security)]
    output = source
    for old, new in changes:
        output = output.replace(old, new, 1)
    for name in WRITES:
        if output.count(b"'" + name.encode() + b"'") != 3:
            raise ValueError("Write tool must appear in dispatch and both authorization lists.")
    restored = output
    for old, new in reversed(changes):
        if restored.count(new) != 1:
            raise ValueError("Ambiguous candidate.")
        restored = restored.replace(new, old, 1)
    if restored != source:
        raise ValueError("Unrelated bytes changed.")
    return output

def self_test():
    source = b"\n".join((DISPATCH, DEFINITION, OLD_WRITES, SECURITY_WRITES, GATE,
        b"private function authorizeDefinitions(", b"private function callTool(",
        b"private function definitions(", b"bambodia_api_owner", b"bambodia_api_token",
        b"$definition['securitySchemes']", b"bambodia_accounts_summary",
        b"OperationalReadTools::definitions()", b"$token->allows('mcp.read')"))
    result = stage(source)
    assert GATE in result and all(x.encode() in result for x in READS + WRITES)
    for bad in (result, source.replace(GATE,b""), source+DISPATCH, source.replace(SECURITY_WRITES,b"")):
        try:
            stage(bad)
        except ValueError:
            continue
        raise AssertionError("Unsafe candidate accepted")
    # Synthetic controller fixture verifies executable PHP registry behavior only.
    # The actual installed OAuth middleware/controller still requires staging acceptance.
    fixture = Path(__file__).with_name("bambodia-mcp-phase3-controller-fixture.php").read_bytes()
    with tempfile.TemporaryDirectory(prefix="bambodia-mcp-phase3-") as tmp:
        candidate = Path(tmp) / "candidate.php"
        candidate.write_bytes(stage(fixture))
        subprocess.run(["php", "-l", str(candidate)], check=True)
        subprocess.run(["php", str(candidate)], check=True)
    print("PASS: additive registry, write lists, existing gate, duplicate and drift rejection")

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument("--source", type=Path)
    p.add_argument("--output", type=Path)
    p.add_argument("--expected-source-blob")
    p.add_argument("--self-test", action="store_true")
    a=p.parse_args()
    if a.self_test:
        self_test()
        return
    if not a.source or not a.output or not a.expected_source_blob:
        p.error("source, new output and independently reviewed source blob required")
    if a.source.is_symlink() or a.output.is_symlink() or a.output.exists() or a.source.resolve()==a.output.resolve():
        p.error("source must not be a symlink; output must be a separate new staging file")
    source=a.source.read_bytes()
    if blob(source)!=a.expected_source_blob:
        p.error("source drift")
    output=stage(source)
    with a.output.open("xb") as f:
        f.write(output)
    print("SOURCE_BLOB="+blob(source))
    print("CANDIDATE_BLOB="+blob(output))
    print("PRODUCTION_FILES_MODIFIED=0")

if __name__ == "__main__":
    main()
