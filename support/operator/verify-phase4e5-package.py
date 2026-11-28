#!/usr/bin/env python3
"""Check the operator's tracked-file pins against actual checkout bytes."""
import hashlib
import pathlib
import re

root = pathlib.Path(__file__).resolve().parents[2]
script = (root / "support/operator/bambodia-phase4e5-isolated-oauth.sh").read_text()

def require(ok, message):
    if not ok:
        raise SystemExit(message)

def variable(name):
    matches = re.findall(r"^" + re.escape(name) + r"=([^\n]+)$", script, re.M)
    require(len(matches) == 1, "Missing or ambiguous pin: " + name)
    return matches[0]

def array(name):
    matches = re.findall(r"^" + re.escape(name) + r"=\(\n(.*?)^\)", script, re.M | re.S)
    require(len(matches) == 1, "Missing or ambiguous array: " + name)
    return matches[0].split()

def check(path, pin):
    require(re.fullmatch(r"[0-9a-f]{40}", pin) is not None, "Invalid Git pin length or characters: " + path)
    file = root / path
    require(file.is_file() and not file.is_symlink(), "Missing tracked source: " + path)
    data = file.read_bytes()
    blob = hashlib.sha1(b"blob " + str(len(data)).encode() + b"\0" + data).hexdigest()
    require(blob == pin, "Tracked package fingerprint mismatch: " + path)

paths, pins = array("ADDITIONS"), array("BLOBS")
require(len(paths) == len(pins) == 9 and len(set(paths)) == 9, "Unexpected additive file inventory")
for path, pin in zip(paths, pins):
    check(path, pin)
downloads = re.findall(r"^\s*download ([\w./-]+) ([0-9a-f]+) ", script, re.M)
require(len(downloads) == 9 and len({p for p, _ in downloads}) == 9, "Unexpected literal download inventory")
for path, pin in downloads:
    check(path, pin)
check(variable("PATCH"), variable("PATCH_BLOB"))
check(variable("AUDIT"), variable("AUDIT_CANDIDATE"))
check("support/operator/BambodiaPhase4E5IsolatedOAuthTest.php", variable("HARNESS_BLOB"))
require('download "$PATCH" "$PATCH_BLOB"' in script, "Preparation does not use shared pin")
require('check_blob "$stage/verified/$(basename "$PATCH")" "$PATCH_BLOB"' in script, "Acceptance does not use shared pin")
print("PHASE4E5_TRACKED_PACKAGE_FINGERPRINTS=PASS")
print("TRACKED_PACKAGE_FILES_VERIFIED=21")
