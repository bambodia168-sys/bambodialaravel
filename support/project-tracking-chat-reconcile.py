#!/usr/bin/env python3
"""Fail-closed preservation of two independently hotfixed Bambodia live files.

Only the additions independently verified in the approved GitHub old/new blobs
are replayed onto a live file with an explicitly pinned Git-blob SHA.
No code is taken from an unverified working tree or blindly overwritten.
"""
from __future__ import annotations

import argparse
import hashlib
from pathlib import Path

LIVE_SHA = {
    "audit": "93517dabee01c959abc1029e4a7eae974ffedf49",
    "routes": "87a1b68023d2d796be095a732f74effe4360b2e0",
}

AUDIT_ANCHOR = "        'order.tracking.schedule_declined',\n"
AUDIT_ADDITIONS = (
    "        'order.tracking.chat.attachment_uploaded',\n"
    "        'order.tracking.chat.attachment_downloaded',\n"
    "        'order.tracking.one_time.shared',\n"
    "        'order.tracking.one_time.opened',\n"
    "        'order.tracking.one_time.revoked',\n"
)

ROUTES_IMPORT = "use App\\Http\\Controllers\\ProjectTrackingController;\n"
ROUTES_ADD_IMPORT = "use App\\Http\\Controllers\\TrackingConversationController;\n"
ROUTES_ANCHOR = (
    "    Route::get('/tracking/orders/{reference}', [ProjectTrackingController::class, 'show'])\n"
    "        ->where('reference', OrderReference::ROUTE)->name('tracking.orders.show');\n"
)
ROUTES_ADDITIONS = (
    "    Route::get('/tracking/orders/{reference}/conversation', [TrackingConversationController::class, 'messages'])\n"
    "        ->where('reference', OrderReference::ROUTE)->middleware('throttle:90,1')->name('tracking.conversation.messages');\n"
    "    Route::post('/tracking/orders/{reference}/conversation', [TrackingConversationController::class, 'send'])\n"
    "        ->where('reference', OrderReference::ROUTE)->middleware('throttle:20,1')->name('tracking.conversation.send');\n"
    "    Route::get('/tracking/orders/{reference}/attachments/{attachment}', [TrackingConversationController::class, 'download'])\n"
    "        ->where('reference', OrderReference::ROUTE)->whereUuid('attachment')\n"
    "        ->middleware('throttle:30,1')->name('tracking.conversation.attachment');\n"
    "    Route::post('/tracking/orders/{reference}/one-time', [TrackingConversationController::class, 'shareOnce'])\n"
    "        ->where('reference', OrderReference::ROUTE)->middleware('throttle:5,1')->name('tracking.one-time.share');\n"
    "    Route::post('/tracking/orders/{reference}/one-time/{note}/open', [TrackingConversationController::class, 'openOnce'])\n"
    "        ->where('reference', OrderReference::ROUTE)->whereUuid('note')\n"
    "        ->middleware('throttle:6,1')->name('tracking.one-time.open');\n"
    "    Route::post('/tracking/orders/{reference}/one-time/{note}/revoke', [TrackingConversationController::class, 'revokeOnce'])\n"
    "        ->where('reference', OrderReference::ROUTE)->whereUuid('note')\n"
    "        ->middleware('throttle:6,1')->name('tracking.one-time.revoke');\n"
)


def sha1_git_blob(payload: bytes) -> str:
    return hashlib.sha1(b"blob " + str(len(payload)).encode("ascii") + b"\0" + payload).hexdigest()


def inject(original: str, anchor: str, addition: str) -> str:
    if original.count(anchor) != 1:
        raise ValueError("STOP: Expected exact insertion anchor is missing or repeated")
    if addition in original:
        raise ValueError("STOP: Approved addition is already present in an unexpected position")
    return original.replace(anchor, anchor + addition, 1)


def reconcile(kind: str, live: str, base: str, approved: str) -> str:
    if kind == "audit":
        # The exact approved git diff must consist of the five event registrations.
        if inject(base, AUDIT_ANCHOR, AUDIT_ADDITIONS) != approved:
            raise ValueError("STOP: Approved audit code contains unexpected changes")
        for event in (
            "order.tracking.chat.attachment_uploaded",
            "order.tracking.chat.attachment_downloaded",
            "order.tracking.one_time.shared",
            "order.tracking.one_time.opened",
            "order.tracking.one_time.revoked",
        ):
            if event in live:
                raise ValueError("STOP: Live audit registry already has an unexpected event")
        candidate = inject(live, AUDIT_ANCHOR, AUDIT_ADDITIONS)
        if candidate.count(AUDIT_ADDITIONS) != 1:
            raise ValueError("STOP: Audit merge did not produce the intended additions")
        return candidate

    if kind == "routes":
        expected = inject(base, ROUTES_IMPORT, ROUTES_ADD_IMPORT)
        expected = inject(expected, ROUTES_ANCHOR, ROUTES_ADDITIONS)
        if expected != approved:
            raise ValueError("STOP: Approved routing code contains unexpected changes")
        if "TrackingConversationController" in live:
            raise ValueError("STOP: Existing live routing already references tracking conversation controller")
        # Exact anchor occurs only in the authenticated group. Never add public endpoints.
        candidate = inject(live, ROUTES_IMPORT, ROUTES_ADD_IMPORT)
        candidate = inject(candidate, ROUTES_ANCHOR, ROUTES_ADDITIONS)
        for name in (
            "tracking.conversation.messages",
            "tracking.conversation.send",
            "tracking.conversation.attachment",
            "tracking.one-time.share",
            "tracking.one-time.open",
            "tracking.one-time.revoke",
        ):
            if candidate.count("->name('" + name + "')") != 1:
                raise ValueError("STOP: Unexpected duplicate route name " + name)
        return candidate

    raise ValueError("STOP: Unexpected file kind")


def self_test() -> None:
    for kind in ("audit", "routes"):
        if kind == "audit":
            base = "start\n" + AUDIT_ANCHOR + "end\n"
            approved = inject(base, AUDIT_ANCHOR, AUDIT_ADDITIONS)
        else:
            base = ROUTES_IMPORT + "other imports\n" + ROUTES_ANCHOR + "other routes\n"
            approved = inject(inject(base, ROUTES_IMPORT, ROUTES_ADD_IMPORT), ROUTES_ANCHOR, ROUTES_ADDITIONS)
        live = "# important unrelated site patch\n" + base + "# another unrelated site patch\n"
        merged = reconcile(kind, live, base, approved)
        assert merged.startswith("# important unrelated site patch\n")
        assert merged.endswith("# another unrelated site patch\n")
        assert reconcile(kind, base, base, approved) == approved
        try:
            reconcile(kind, merged, base, approved)
        except ValueError:
            pass
        else:
            raise AssertionError("Duplicate re-application was not rejected")
        try:
            reconcile(kind, "missing anchors", base, approved)
        except ValueError:
            pass
        else:
            raise AssertionError("Missing anchor was not rejected")
        try:
            reconcile(kind, live, base, approved + "\nunsupported")
        except ValueError:
            pass
        else:
            raise AssertionError("Unexpected approved changes were not rejected")
    print("PASS: Exact-context merge preserves site changes, refuses duplication and mismatches")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--self-test", action="store_true")
    parser.add_argument("--kind", choices=("audit", "routes"))
    parser.add_argument("--live", type=Path)
    parser.add_argument("--base", type=Path)
    parser.add_argument("--approved", type=Path)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()

    if args.self_test:
        self_test()
        return
    if not all((args.kind, args.live, args.base, args.approved, args.output)):
        parser.error("--kind, --live, --base, --approved and --output are all required")
    if args.output.exists():
        raise SystemExit("STOP: Refusing to overwrite an existing candidate")
    if args.output.is_symlink() or args.live.is_symlink():
        raise SystemExit("STOP: Symlink is not an approved source or output")

    live = args.live.read_bytes()
    if sha1_git_blob(live) != LIVE_SHA[args.kind]:
        raise SystemExit("STOP: The live file changed since the operator inventory")
    base = args.base.read_text(encoding="utf-8")
    approved = args.approved.read_text(encoding="utf-8")
    candidate = reconcile(args.kind, live.decode("utf-8"), base, approved)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(candidate, encoding="utf-8")
    print("PASS: Exact-context live " + args.kind + " changes preserved, approved additions staged")


if __name__ == "__main__":
    main()
