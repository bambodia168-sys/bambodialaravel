#!/usr/bin/env python3
"""Stage the exact read-only MCP registry extension. Never edit the live source."""
import argparse
import hashlib
from pathlib import Path
import re

TOOLS = (
    "bambodia_accounts_summary",
    "bambodia_orders_summary",
    "bambodia_review_queue",
    "bambodia_support_summary",
    "bambodia_notifications_summary",
    "bambodia_email_delivery_summary",
)

DISPATCH_ANCHOR = b"            'bambodia_overview' => $service->overview(),"
DEFINITION_ANCHOR = b"        $base[] = [\n            'name' => 'bambodia_analytics',"
DISPATCH = (
    b"            " + b",\n            ".join(b"'" + name.encode() + b"'" for name in TOOLS)
    + b" => app(\\App\\Support\\Mcp\\OperationalReadTools::class)->call($name, $args, $owner, $token->allows('mcp.read')),\n"
)
DEFINITIONS = b"        $base = [...$base, ...\\App\\Support\\Mcp\\OperationalReadTools::definitions()];\n"

def stage(source: bytes) -> bytes:
    # Refuse unexpected controller structure, partial installations or duplicate tools.
    if source.count(DISPATCH_ANCHOR) != 1 or source.count(DEFINITION_ANCHOR) != 1:
        raise ValueError("Expected unique existing MCP dispatch and definition anchors.")
    for token in (
        b"private function callTool(", b"private function definitions(",
        b"$token->allows('mcp.write')", b"mcp.production_writes_enabled",
        b"bambodia_api_owner", b"bambodia_api_token",
    ):
        if token not in source:
            raise ValueError("Expected existing MCP authorization structure not found.")
    for name in TOOLS:
        if re.search(rb"['\"]" + name.encode() + rb"['\"]", source):
            raise ValueError("Operational tool already exists; reconcile before retrying.")
    output = source.replace(DISPATCH_ANCHOR, DISPATCH + DISPATCH_ANCHOR, 1)
    output = output.replace(DEFINITION_ANCHOR, DEFINITIONS + DEFINITION_ANCHOR, 1)
    if output.replace(DISPATCH, b"", 1).replace(DEFINITIONS, b"", 1) != source:
        raise ValueError("Unrelated controller bytes changed.")
    return output

def blob(data: bytes) -> str:
    return hashlib.sha1(b"blob " + str(len(data)).encode() + b"\0" + data).hexdigest()

def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    if args.source.resolve() == args.output.resolve() or args.output.exists() or args.output.is_symlink():
        raise SystemExit("STOP: output must be a new staging file distinct from source.")
    source = args.source.read_bytes()
    try:
        output = stage(source)
    except ValueError as exc:
        raise SystemExit(f"STOP: {exc}") from exc
    with args.output.open("xb") as stream:
        stream.write(output)
    print("SOURCE_BLOB=" + blob(source))
    print("CANDIDATE_BLOB=" + blob(output))
    print("PASS: six read-only dispatch entries and definitions staged; existing bytes preserved.")
    print("PRODUCTION_FILES_MODIFIED=0")

if __name__ == "__main__":
    main()
