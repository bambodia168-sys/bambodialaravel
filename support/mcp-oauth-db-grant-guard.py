#!/usr/bin/env python3
"""Fail-closed validation of isolated Bambodia OAuth MySQL grants.

Usage: mysql SHOW GRANTS FOR CURRENT_USER() | python3 this-file.py QA_DB QA_USER CURRENT_USER
Never print grants: the output may expose host/user details in CI or operator logs.
"""
from __future__ import annotations

import re
import sys

QA_DB = re.compile(r"^bambodia_oauthqa_[a-z0-9_]+$")
QA_USER = re.compile(r"^bambodiaqa_[a-z0-9_]+$")
RESTORE_DB = re.compile(r"^bambodia_oauthqa_restore_[a-z0-9_]+$")
SCOPE = re.compile(
    r"^GRANT\s+.+?\s+ON\s+"
    r"(?P<schema>`[A-Za-z0-9_]+`|[A-Za-z0-9_]+)"
    r"\.(?:\*|`[A-Za-z0-9_]+`|[A-Za-z0-9_]+)\s+TO\s+.+$",
    re.IGNORECASE,
)
USAGE = re.compile(r"^GRANT USAGE ON \*\.\* TO .+$", re.IGNORECASE)


def restricted(db: str, user: str, resolved: str, grants: str) -> bool:
    if not QA_DB.fullmatch(db) or not QA_USER.fullmatch(user):
        return False
    if resolved.split("@", 1)[0] != user or "@" not in resolved:
        return False
    if not grants.strip():
        return False
    for raw in grants.splitlines():
        grant = raw.strip()
        if not grant or "WITH GRANT OPTION" in grant.upper():
            return False
        if USAGE.fullmatch(grant):
            continue
        match = SCOPE.fullmatch(grant)
        if not match:
            return False
        schema = match.group("schema").strip("`")
        if schema != db and not RESTORE_DB.fullmatch(schema):
            return False
    return True


def self_test() -> None:
    db, user = "bambodia_oauthqa_test", "bambodiaqa_test"
    resolved = user + "@localhost"
    usage = f"GRANT USAGE ON *.* TO `{user}`@`localhost`"
    good = usage + f"\nGRANT ALL PRIVILEGES ON `{db}`.* TO `{user}`@`localhost`"
    cases = [
        (True, db, user, resolved, good),
        (True, db, user, resolved, good + f"\nGRANT SELECT ON `bambodia_oauthqa_restore_test`.* TO `{user}`@`localhost`"),
        (False, db, user, resolved, good + f"\nGRANT SELECT ON `bambodia_production`.* TO `{user}`@`localhost`"),
        (False, db, user, resolved, usage + f"\nGRANT ALL PRIVILEGES ON *.* TO `{user}`@`localhost`"),
        (False, db, user, resolved, good + " WITH GRANT OPTION"),
        (False, db, user, "root@localhost", good),
        (False, db, user + "_another", resolved, good),
        (False, db, user, resolved, ""),
        (False, db, user, resolved, "GRANT PROXY ON 'admin'@'localhost' TO 'qa'@'localhost'"),
    ]
    for index, (want, schema, login, current, grants) in enumerate(cases, 1):
        if restricted(schema, login, current, grants) != want:
            raise SystemExit(f"grant guard self-test {index} FAILED")
    print(f"GRANT_GUARD_TESTS={len(cases)} PASS")


if __name__ == "__main__":
    if sys.argv[1:] == ["--self-test"]:
        self_test()
    elif len(sys.argv) == 4:
        if not restricted(sys.argv[1], sys.argv[2], sys.argv[3], sys.stdin.read()):
            print("BLOCKED: MySQL identity or grant scope exceeds isolated QA permissions.", file=sys.stderr)
            raise SystemExit(1)
        print("QA_MYSQL_GRANTS=RESTRICTED")
    else:
        print("Usage: grant-guard.py QA_DB QA_USER CURRENT_USER | --self-test", file=sys.stderr)
        raise SystemExit(64)
