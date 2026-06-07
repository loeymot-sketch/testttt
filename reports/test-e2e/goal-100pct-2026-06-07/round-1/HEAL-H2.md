# HEAL-H2 — SEC-XFF-01 — Kiosk auto-login IP allowlist spoofable via X-Forwarded-For

**Date:** 2026-06-07 · **Agent:** HEAL-H2 (P1 security) · **Verdict:** PASS
**Source defect:** `reports/test-e2e/goal-100pct-2026-06-07/round-1/10-SECURITY.md` finding `[P1] SEC-XFF-01`
**Frozen-zone touch:** NONE (verified against `tests/Feature/Sentinels/frozen-zone-sha256-baseline.json`)

---

## 1. The defect (confirmed)

The kiosk auto-login gate in `resources/views/master.blade.php:118-129` injects the machine
credentials (`config('kiosk.spa_payload')` = kiosk username + password) into
`window.foodkingConfig.kioskAutoLogin` only when the requester's IP is in
`KIOSK_AUTO_LOGIN_TRUSTED_IPS`. The comparison is:

```php
$clientIp  = (string) request()->ip();
$ipTrusted = ! empty($trustedIps) && in_array($clientIp, $trustedIps, true);
```

`request()->ip()` is resolved by `App\Http\Middleware\TrustProxies`. That middleware had
`protected $proxies = '*'` (Wave 3 SYNC-ADV3-01) — meaning Laravel/Symfony trusted the
client-supplied `X-Forwarded-For` header from **any** peer. Therefore a remote attacker who
sends `X-Forwarded-For: <a trusted kiosk LAN IP>` made `request()->ip()` return the spoofed
value, passed the allowlist check, and harvested the kiosk machine credentials — then could
mint a `kiosk:order` Sanctum token. Same root cause also let an attacker spoof the rate-limit
key and the audit-log IP (both read `request()->ip()`).

Blast radius is capped by `BlockKioskTokenFromAdminRoutes` (a stolen kiosk token can only
place orders, not reach admin) → P1, not P0. But it is a real bypass of an IP allowlist.

## 2. Root-cause decision (why TrustProxies, not the blade)

The naive fix — swap `request()->ip()` for `REMOTE_ADDR` in the blade — was **rejected**.
In the documented soketi `proxy_pass` topology nginx appends the real client to XFF, so a
REMOTE_ADDR-only gate would break legitimate kiosks behind a loopback proxy and would also
leave the throttle/audit IP spoof unfixed. The correct, comprehensive, scope-minimal fix is
at the trust layer.

**Fix applied** — `app/Http/Middleware/TrustProxies.php`:

```php
- protected $proxies = '*';
+ protected $proxies = ['127.0.0.1', '::1'];
```

(plus a rewritten docblock explaining the SEC-XFF-01 rationale and a CLOUD CUTOVER note to
append a real LB/CDN IP rather than restoring `'*'`.)

**Why this is correct under BOTH topologies present in this repo:**
- nginx → PHP-FPM front controller uses `fastcgi_pass` + `include fastcgi_params` and does
  NOT inject a server-side XFF (`deploy/ansible/templates/nginx-foodking.conf.j2:90-101`,
  `scripts/deploy/nginx.conf.template:197-209`). So the legit kiosk IP is in REMOTE_ADDR.
- nginx → soketi uses `proxy_pass` + `X-Forwarded-For $proxy_add_x_forwarded_for`
  (appends the real client) on loopback.

Symfony rule: if REMOTE_ADDR is NOT a trusted proxy, forwarded headers are ignored and
`request()->ip()` falls back to REMOTE_ADDR.
- **Attacker** (REMOTE_ADDR = external, not loopback) + spoofed `XFF: <kiosk IP>` →
  XFF ignored → `request()->ip()` = attacker IP → allowlist miss → **null payload**. Spoof closed.
- **Legit kiosk** hitting fastcgi directly (REMOTE_ADDR = real kiosk IP) → XFF ignored →
  `request()->ip()` = kiosk IP → allowlist match → **payload served**.
- **Genuine loopback proxy** (REMOTE_ADDR = 127.0.0.1, trusted) → real client resolved from
  XFF chain → **payload served** (forward-compat).
- **Throttle keying** (the reason `'*'` was introduced, SYNC-ADV3-01) is preserved: the
  existing `TrustProxiesThrottleIsolationTest` stays green because its harness arrives from
  loopback (trusted) so its forwarded IPs are still honoured per-source.

`master.blade.php` and `config/kiosk.php` were NOT modified — the gate logic is correct once
`request()->ip()` is trustworthy.

## 3. Evidence — BEFORE vs AFTER (PHPUnit, in-memory SQLite, never touched DB foodking)

New companion test `tests/Feature/Kiosk/KioskAutoLoginIpSpoofTest.php` (the existing
`KioskAutoLoginGateTest` only sets REMOTE_ADDR with no attacker XFF — the spoof vector was
uncovered).

Why a Feature test and not curl on :8766: on `artisan serve` every curl arrives as
REMOTE_ADDR=127.0.0.1 which IS trusted under the fix, so curl `-H "X-Forwarded-For: ..."`
would still appear to "succeed" — a harness artifact. Only `withServerVariables` can set a
non-loopback REMOTE_ADDR to exercise the real attacker case. (Confirmed `TrustProxies` is in
`app/Http/Kernel.php` global middleware so it runs in Feature tests.)

### BEFORE (against `$proxies = '*'`) — `vendor/bin/phpunit --filter KioskAutoLoginIpSpoof`
```
FF.                                                                 3 / 3 (100%)
1) test_xff_spoof_from_untrusted_remote_addr_is_blocked
   Failed asserting that Array ('username' => 'kiosk-test-machine', 'password' => 'test-secret-456') is null.
2) test_legit_kiosk_real_remote_addr_still_served_even_with_junk_xff
   Failed asserting that null is not null.
FAILURES! Tests: 3, Assertions: 7, Failures: 2.
```
- Test 1 fail = the exploit: attacker `REMOTE_ADDR=203.0.113.66` + spoofed `XFF=192.168.1.10`
  RECEIVED the live machine creds. This is SEC-XFF-01 reproduced.
- Test 2 fail = `'*'` also broke legit traffic: real kiosk `REMOTE_ADDR=192.168.1.10` with
  junk `XFF=10.0.0.1` got null (request()->ip() followed the junk XFF).

### AFTER (with `$proxies = ['127.0.0.1','::1']`)
```
...                                                                 3 / 3 (100%)
OK (3 tests, 8 assertions)
```
All three scenarios pass: spoof blocked, legit kiosk served, loopback-proxy resolution works.

## 4. Regression evidence (all green, same in-memory test DB)

| Suite | Result | Meaning |
|---|---|---|
| `KioskAutoLoginGateTest` | OK 6/6 (15 assertions) | existing gate behaviour unchanged |
| `TrustProxiesThrottleIsolationTest` | OK 1/1 (2 assertions) | SYNC-ADV3-01 per-IP throttle keying preserved |
| `TrustHostsTest` | OK 5/5 (25 assertions) | host-spoof defence unaffected |
| `FrozenZoneSha256BaselineSentinelTest` | OK 1/1 (5 assertions) | zero frozen-zone drift |
| `--filter 'RateLimit|HealthController'` | OK 28/28 (162 assertions) | rate-limit + health unaffected |

### Live-topology discriminator (proves REMOTE_ADDR = real client on the OVH box)
`grep -rniE "real_ip_header|set_real_ip_from|cloudflare|CF-Connecting" scripts/deploy/ deploy/ansible/`
→ NO matches in any nginx config directive (only doc/markdown mentions Cloudflare as an
*optional future* CDN, "V1.0.X opt" in `README_DEPLOY.md`). The PHP front controller is served
via `fastcgi_pass` only (no proxy in front of FPM); `proxy_pass` exists solely for soketi
websockets on loopback `127.0.0.1:6001`. Therefore on the live single-box VPS, PHP's
REMOTE_ADDR is the genuine client IP and no `real_ip` rewrite alters it → loopback-only trust
is correct for the deployed topology, not just the test harness.

## 5. Scope / files changed (mine only)
- `app/Http/Middleware/TrustProxies.php` — `$proxies` value + docblock.
- `tests/Feature/Kiosk/KioskAutoLoginIpSpoofTest.php` — new spoof-coverage test.

(Other working-tree changes in `git status` belong to sibling heal agents, not HEAL-H2.)

## 6. Deploy precondition (grep-backed, not a future "if")
**Precondition for this ship:** the live OVH nginx must deliver REMOTE_ADDR = the real client
(no non-loopback edge proxy in front of PHP-FPM, no `real_ip` rewrite). Repo IaC CONFIRMS this
today — grep in §3 found zero `real_ip_header` / `set_real_ip_from` / Cloudflare directives in
any nginx config; PHP is served by `fastcgi_pass` with the only `proxy_pass` being loopback
soketi. So `request()->ip()` = real client on the deployed single-box VPS and the fix is
correct as-is.

Operator actions:
1. `KIOSK_AUTO_LOGIN_TRUSTED_IPS` must list the **real REMOTE_ADDR** of the kiosk machines
   (their LAN/public IP, or `127.0.0.1` if the kiosk browser runs on the same box as nginx).
2. If a **non-loopback** intermediary is ever added (Cloudflare proxied-DNS, an OVH edge LB,
   a Docker-bridge nginx), it WILL break this gate AND re-collapse the throttle unless you
   either add `real_ip_header`/`set_real_ip_from` in nginx OR append that intermediary's real
   IP to `TrustProxies::$proxies`. **Never restore `'*'`.** Re-verify if edge topology changes.

## 7. Verdict
**PASS** — SEC-XFF-01 closed at the root (trust layer), proven by before/after Feature tests
with a non-loopback attacker REMOTE_ADDR, no frozen-zone touch, no regressions across the
kiosk gate, throttle isolation, host-trust, rate-limit and health suites.
