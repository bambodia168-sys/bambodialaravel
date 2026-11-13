# Step 12K — disposable, unprivileged Unix-socket mount rehearsal

**State: source prepared; neither the preflight nor the disposable
probe has run on aaPanel. No cloudflared binary, Access app, Tunnel,
credentials, public DNS or TLS endpoint is created.**

## Starting evidence

On 2026-11-13 the owner ran pinned Step12J on the actual aaPanel host.
The result was STEP12J_CONNECTOR_IDENTITY_CAPABILITY_INVENTORY=COMPLETE.

- systemd 249 on Ubuntu 22.04, /run backed by tmpfs, cgroup v2.
- systemd parser accepted a candidate BindReadOnlyPaths unit.
- HTTP socket remained inside a 0700 bambodia_pvhttp directory.
- Shared www could not connect; synthetic QA retained disabled
  Preview Visibility withdrawals.
- cloudflared absent: executable, service, and named account.
- No namespace bind mount and no cross-UID socket connection were tested.
- No production, public DNS, Cloudflare, QA service or TLS modifications.

## Isolated test design

A *transient systemd service* may be started solely by the explicit
owner-approved --probe mode of:

    support/previewqa-step12k-dynamic-user-unix-socket-probe-20261113.sh

The transient process has DynamicUser=yes, RuntimeDirectoryMode=0700,
NoNewPrivileges, a private mount namespace, strict filesystem
protection, AF_UNIX-only address families, no capabilities and a bounded
runtime. It binds the existing HTTP socket into a service-private path:

    source:
      /run/bambodia-previewqa-http/http.sock
    inside the probe service:
      /run/bambodia-previewqa-socketprobe-20261113/http.sock

The source remains in its existing private 0700 directory. The system
service manager performs the bind during namespace setup; no global
mount, useradd/groupadd, chmod, QA restart or persistent unit is required.

The disposable process must prove it is not UID 0; cannot read Laravel
.env or connect to the original FPM/source socket paths; but can send
one unauthenticated GET /up to the *private mounted HTTP socket* with
the canonical Host and receive HTTP 200. It prints only acceptance
markers, no response body or credentials.

systemd-run --wait --collect requests transient-unit collection even
on failure. The script has cleanup that stops **only its own specifically
named transient probe unit** if it was started. A failed or incomplete
cleanup remains a BLOCKED result and must be investigated before any
repeat. Both existing QA units must remain active and disabled at boot.

## Sequential gates

1. Pin exact GitHub commit and Git blob; lint script with bash -n.
2. Run only: bash SCRIPT --check.
   This confirms existing QA-only service/socket boundaries, same pinned
   six-user synthetic database, Preview Visibility writes OFF,
   cloudflared absent, no probe-name collision and parser acceptance.
   It creates only a root-private temporary parser test file, not a
   service or a mount. Completion:
       STEP12K_DISPOSABLE_CONNECTIVITY_PREFLIGHT=PASS
3. After independent review, run opt-in with:
       BAMBODIA_QA_SOCKET_PROBE=APPROVE_DISPOSABLE_DYNAMIC_USER_ONLY \
         bash SCRIPT --probe
   This repeats all preflight checks and attempts one transient service
   with private mount namespace. If unsupported, refuse and diagnose:
   never loosen parent permissions or add a shared group as a fallback.
   Expected successful marker:
       STEP12K_DYNAMIC_USER_SOCKET_REHEARSAL=PASS

A successful probe only demonstrates short-lived socket connectivity
and isolation. It does NOT validate cloudflared installation,
restarts/reconnections after socket inode replacement, Access JWT
verification, Cloudflare account authority, DNS, public HTTPS,
the tunnel's credential protection, or mobile-device behavior.

## References

systemd 249 man pages:
- https://www.freedesktop.org/software/systemd/man/249/systemd.exec.html
- https://www.freedesktop.org/software/systemd/man/249/systemd-run.html

BindReadOnlyPaths creates **per-unit** mount namespaces, not changes to
the host's global mount table. DynamicUser and RuntimeDirectory only
bound the identity and lifetime of the disposable service. Both the
host's systemd/kernel behavior and the actual Unix connect operation
require independent real-server acceptance.

## Closed release gates

Preserve the 0700 original socket parent and all root-only backups.
Keep shared www denied. Do not install cloudflared, access any connector
tokens, create public TCP listeners, publish QA DNS/TLS or activate
Preview Visibility writes. No production main merge/deployment.
Draft PR #44 remains draft; PR #37 stays draft.
