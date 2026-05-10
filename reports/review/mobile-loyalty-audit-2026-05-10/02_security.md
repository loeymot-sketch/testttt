# AGENT-2-SECURITY — Mobile Loyalty QR Audit (2026-05-10)

Scope: end-to-end audit of the loyalty QR signing chain (mock generator -> client
storage -> Wallet -> backend scan resolver). Hard constraints from upstream
(QR format mismatch, TTL refresh hazards, absent backend HMAC, brute-force
exposure) are addressed explicitly.

Audit posture: READ-ONLY. Every claim cites `file:line`.

Files audited:
- `app/Http/Controllers/Frontend/LoyaltyController.php:575-714` (scan), `:82, :162` (loyalty_code gen)
- `routes/api.php:1201-1207, 1208-1213, 1254-1259`
- `app/Providers/RouteServiceProvider.php:50-150`
- `app/Http/Kernel.php:50, :73`
- `config/sanctum.php:36-51`
- `app/Models/LoyaltyConsent.php:60-68`
- `app/Services/Order/OrderQuoteService.php:72-75` (HMAC reference pattern)
- `app/Services/Fiscal/FiscalSealingService.php:37, :45` (NF525 HMAC pattern)
- `app/Http/Middleware/IdempotencyKeyMiddleware.php:76, :81`
- `mobile/data/loyalty.js:53-58` (generateMockQR)
- `mobile/data/loyalty.js:32-41` (ACCOUNT mock)
- `mobile/api/storage.js:1-73`
- `mobile/screens-main.jsx:846-870` (ScreenLoyalty — currently static QRMock, generator unused)
- `mobile/CONNECTION_PLAN.md:220-233`

---

## §1 — QR format decision (D-A vs D-B)

### Recommendation: **D-A (FK:<loyalty_code> with HMAC alongside, not inside payload)**.

Evidence anchoring the choice:
- Backend `scan()` already accepts `FK:<loyalty_code>` and bare alphanum
  (`LoyaltyController.php:611-613, :620`). Mock can ship today and resolve
  green against the production endpoint without any backend change.
- `LECAY-LOYALTY-{user_id}-{hmac_short}` (`mobile/data/loyalty.js:57`) is
  not understood by the backend — `scan()` only matches `loyalty_code`
  (`:620`) and E.164 phone (`:624`). It would always fall through to
  `customer_not_found` (`:633`).
- `LECAY-LOYALTY-12345-<hmac>` exposes the **raw integer `user.id`**
  (`mobile/data/loyalty.js:33,57`). That is the autoincrement primary
  key — enumerable, leaking population size and registration order.
  `loyalty_code` (`:82, :162`: `strtoupper(substr(md5(uniqid()),0,8))`)
  is a non-sequential opaque token. PII discipline favours `loyalty_code`.
- Phase 6 backend endpoint signs the payload + expiry as a detached
  signature returned in JSON (see §4). The QR string itself stays
  `FK:<loyalty_code>` so paper/Wallet fallback (no signature available)
  still resolves on the existing scanner.

### Risk table

| Risk | D-A (FK:<loyalty_code>) | D-B (LECAY-LOYALTY-*) |
|---|---|---|
| Backend compat today | Native. `scan()` strips `FK:` at `LoyaltyController.php:611` | Requires new parser + Phase 6 dependency before any mock works |
| PII / enumeration | `loyalty_code` is md5-truncated, non-sequential | `user_id` is integer PK — enumeration + size disclosure |
| Forward HMAC | Detached signature, payload unchanged | HMAC embedded; rotating key requires QR rotation in Wallet pass |
| Replay window | TTL on detached signature (independent of payload) | TTL tied to embedded HMAC; payload identity = secret identity |
| Paper / printed card | Same QR works against scanner unsigned (legacy mode) | Embedded HMAC short-circuits paper workflows entirely |
| Brute-force surface | 36^8 keyspace via `/scan` (mitigated by throttle:20,1 + UPPER index) | Adds `user.id` enumeration as second axis |
| Phase 6 migration | Pure additive: add signature header, scanner ignores if absent | Forced cutover. Pre-Phase-6 mock QRs reject as `customer_not_found` |

D-A is the only forward-compatible path. **D-A required.**

---

## §2 — Threat model

### T1 — Replay attack (high)
Scenario: Attacker captures the QR (screenshot, OCR from POS camera,
overshoulder) and pastes it at a different kiosk minutes later to harvest
points or trigger redemptions tied to the victim.

Current mitigation: **none**. `scan()` performs a raw lookup
(`LoyaltyController.php:620`) and does not consider timestamps, nonces,
or signatures. The `customer_token` returned (`:640`) is server-side
ephemeral, but the QR input itself is treated as a long-lived bearer.

Proposed mitigation: detached signature with `expires_at` enforced
backend-side (see §4). `scan()` becomes signature-aware:
- if `signature + expires_at` present, verify HMAC over
  `loyalty_code|expires_at|user_id`. Reject expired or invalid sigs.
- if absent (paper/wallet legacy), keep current behaviour but require
  cashier consent flag (POS-side) before any high-value redemption.

Implementation: extend `scan()` request to accept optional `signature`,
`expires_at` (validator at `LoyaltyController.php:586-589`). New helper
`verifyLoyaltySignature(string $code, string $sig, int $exp): bool`
using `hash_hmac('sha256', $code.'|'.$exp, config('app.key'))`
mirroring `app/Services/Order/OrderQuoteService.php:75`.

### T2 — MITM on QR endpoint (medium)
Scenario: Captive-WiFi / TLS-stripped proxy between mobile and Phase 6
`/qr/sign` endpoint substitutes signature.

Current mitigation: TLS termination at the LB.
Proposed mitigation:
- Force `https` scheme check in the Phase 6 signing controller
  (analogous to `IdempotencyKeyMiddleware.php` defence-in-depth).
- Pin Sanctum bearer-token requirement (`auth:sanctum` already on
  loyalty routes `api.php:1208`), reject `127.0.0.1`/`localhost`
  unless `APP_DEBUG=true`.
- Bind the signature to the requesting user's id: signing payload
  includes `user.id` from `$request->user()`, not from request body
  (prevents impersonation if token leaks).

### T3 — Clipboard leak (high probability, medium impact)
Scenario: User screenshots their QR; another app silently reads
clipboard (iOS pre-14 quietly, Android shared clipboard, third-party
share extensions).

Current mitigation: **none** — no clipboard copy in
`mobile/screens-main.jsx:858-870`, which is good. But any future
"Copier mon code" affordance would leak it.

Proposed mitigation:
- Never put `loyalty_code` in clipboard. Only `FK:<code>` is acceptable
  in clipboard for QR-failure manual entry; treat that as an explicit
  user gesture with toast "Code copié pour 30 sec" and clear via
  `navigator.clipboard.writeText('')` after 30s.
- Mobile spec: no `userSelect: 'text'` on the loyalty card render
  (`screens-main.jsx:858-868`). Currently the card uses default
  selection — recommend CSS `user-select: none` on the QR container.

### T4 — Brute-force on `/loyalty/scan` (medium probability, low single-token impact, high aggregate impact)
Concrete numbers in §5. Current throttle is the only mitigation.

### T5 — Screenshot share (high probability, persistent impact)
Scenario: User screenshots their loyalty card and posts on Instagram or
sends via WhatsApp. Anyone with the screenshot can:
- present it to a kiosk to scrape the cashier-side display (`display_name`,
  `loyalty_balance_points`, `declared_allergens` at
  `LoyaltyController.php:657-659` — minimal PII but still PII).
- trigger redemptions if cashier doesn't ID-check.

Current mitigation: **none in mock**. `mobile/screens-main.jsx:858-868`
hardcodes `#FK-12345 · IKYES B.` next to the QR — that string itself,
once shared, is enough to identify and resolve. The render also lacks
any "do not share" affordance.

Proposed mitigation:
1. **TTL the rendered QR** (4-min refresh, see §3). A shared screenshot
   self-invalidates within 5 min.
2. Add small caption: "Code dynamique - se renouvelle toutes les 5 min".
3. Phase 6: server-side anomaly detection if the same `signature` is
   presented from > 2 distinct IPs within 5 min → log + flag.
4. Optionally, mask `#FK-12345` in the rendered card (show only last 4
   chars). Modify `screens-main.jsx:866`.

---

## §3 — TTL refresh implementation hazards

The mock currently generates one QR per render with `Math.floor(Date.now()/1000)`
(`loyalty.js:55`) but `ScreenLoyalty` (`screens-main.jsx:862`) doesn't even
call `generateMockQR` — it uses `<QRMock>` (static). So when refresh is
introduced, the pitfalls below apply.

### Pitfall 1 — `setInterval` leak (React unmount)
Mounting `ScreenLoyalty` again (back-nav, re-route) without cleanup
re-creates the timer; the previous timer keeps firing in a stale
closure, calling `setState` on an unmounted component (memory leak
warning + potential ghost render after re-mount).

### Pitfall 2 — Tab visibility (background TTL drift)
If the tab is backgrounded, `setInterval` is throttled to ~1Hz minimum
or paused entirely on iOS Safari ≥ 14. Resume: the displayed QR may
be expired but UI shows it.

### Pitfall 3 — Device clock skew
TTL must be **server-anchored** (the response carries `expires_at`),
not `Date.now()`. Phone clock skew of >5 min is common on flight
mode / freshly powered devices.

### Pitfall 4 — Race between sign request and TTL expiry
If the sign request takes 3s and TTL is 5min, the QR is effectively
valid for ~4m57s. Tighten only to refresh-at-`expires_at - 60s` and
re-trigger if user re-foregrounds the tab with <30s remaining.

### Correct pattern skeleton (React hooks)

```jsx
function useLoyaltyQR() {
  const [qr, setQr] = useState(null);
  const [expiresAt, setExpiresAt] = useState(0);
  const inflightRef = useRef(null);

  const refresh = useCallback(async () => {
    if (inflightRef.current) return inflightRef.current;
    const p = api.post('/api/v1/frontend/loyalty/qr/sign')
      .then(r => {
        setQr(r.payload);
        setExpiresAt(r.expires_at * 1000); // server-anchored ms
        return r;
      })
      .finally(() => { inflightRef.current = null; });
    inflightRef.current = p;
    return p;
  }, []);

  useEffect(() => {
    let cancelled = false;
    let timer = null;

    const tick = async () => {
      if (cancelled) return;
      const remaining = expiresAt - Date.now();
      if (remaining < 60_000) await refresh();
      timer = setTimeout(tick, Math.max(15_000, remaining - 60_000));
    };

    const onVisibility = () => {
      if (document.visibilityState === 'visible') tick();
    };

    tick();
    document.addEventListener('visibilitychange', onVisibility);
    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
      document.removeEventListener('visibilitychange', onVisibility);
    };
  }, [expiresAt, refresh]);

  return { qr, expiresAt, refresh };
}
```

Key points:
- `setTimeout` chained (not `setInterval`) so we can re-schedule based
  on remaining TTL.
- `inflightRef` guard against double-fetch on rapid re-renders.
- `visibilitychange` listener forces a tick on foreground.
- `cancelled` flag + cleanup return path prevent stale closures.

---

## §4 — HMAC chain proposal

### V0 (mock) — forward-compatible structure

Current generator (`mobile/data/loyalty.js:54-58`):
```js
const fakeHmac = btoa(`${userId}:${ts}`).replace(/=/g, '').slice(0, 16);
return `LECAY-LOYALTY-${userId || ACCOUNT.user_id}-${fakeHmac}`;
```

Problems:
- `btoa` is base64, not a hash — trivially reversible.
- Payload embeds `user_id` not `loyalty_code` (§1 D-A violation).
- No separation between QR string and signature.
- Doesn't match what backend `scan()` parses.

Proposed V0 mock:
```js
async function generateMockQR(loyaltyCode = ACCOUNT.member_number.replace('FK-', '')) {
  const ts = Math.floor(Date.now() / 1000);
  const exp = ts + 300; // 5 min
  // V0 mock signature — production replaces with /qr/sign call.
  const enc = new TextEncoder();
  const data = enc.encode(`${loyaltyCode}|${exp}`);
  const buf = await crypto.subtle.digest('SHA-256', data);
  const hex = Array.from(new Uint8Array(buf))
    .map(b => b.toString(16).padStart(2, '0'))
    .join('');
  return {
    payload: `FK:${loyaltyCode}`,   // what gets QR-rendered
    signature: hex.slice(0, 32),    // 128-bit truncated, mock-only
    expires_at: exp,
  };
}
```

Trade-off: `crypto.subtle` is async (Promise), requires `await` /
hook integration. `btoa` is sync. The async cost (~1ms) is negligible
and the same shape is needed for production anyway, so paying it now
avoids a refactor later. The SHA-256 mock is **NOT cryptographically
secure** (no secret key, anyone can recompute) — it's purely to
exercise the wire format. The TTL+detached-sig structure is what we
want forward-compat for, not the secret.

### Phase 6 — backend endpoint `/api/v1/frontend/loyalty/qr/sign`

Route definition (proposed addition near
`routes/api.php:1208-1213`):

```php
Route::prefix('loyalty')->name('loyalty.auth.')->middleware(['auth:sanctum'])->group(function () {
    // ... existing ...
    Route::post('/qr/sign', [LoyaltyController::class, 'signQr'])
        ->middleware('throttle:20,1')
        ->name('qr.sign');
});
```

Controller signature (proposed addition to `LoyaltyController.php`):

```php
public function signQr(Request $request): JsonResponse
{
    $user = $request->user();
    if (! $user || ! $user->loyalty_code) {
        return response()->json(['status' => false, 'message' => 'No loyalty code'], 404);
    }
    $expiresAt = now()->addMinutes(5)->timestamp;
    $payload   = 'FK:'.$user->loyalty_code;
    $signBase  = $user->loyalty_code.'|'.$expiresAt.'|'.$user->id;
    $signature = hash_hmac('sha256', $signBase, (string) config('app.key'));

    return response()->json([
        'status' => true,
        'data'   => [
            'payload'    => $payload,        // QR-rendered string
            'signature'  => $signature,       // 64-char hex
            'expires_at' => $expiresAt,       // unix seconds
        ],
    ]);
}
```

Wire format (response):
```
{
  "status": true,
  "data": {
    "payload":    "FK:A1B2C3D4",
    "signature":  "ab12cd34...",  // hex_64
    "expires_at": 1747000000
  }
}
```

Wire format (verification request — extend `scan()`):
```
POST /api/v1/frontend/loyalty/scan
{
  "method":     "qr",
  "raw_data":   "FK:A1B2C3D4",
  "signature":  "ab12cd34..." | null,
  "expires_at": 1747000000   | null
}
```

`scan()` behaviour:
- If `signature` + `expires_at` both present: enforce
  `hash_hmac_equals(...)` and `expires_at >= now()`. On mismatch ->
  `error_code: 'qr_invalid_signature'`. On expiry -> `error_code: 'qr_expired'`.
- If absent: fall back to current behaviour (paper/Wallet legacy).
  POS-side may surface a UI badge "non signé" so cashier judges.

Key rotation: derive a sub-secret from `config('app.key')` via
`hash_hmac('sha256', 'loyalty.qr.v1', config('app.key'))` and version
the constant — switch to `loyalty.qr.v2` for rotation. Identical
pattern is used by NF525 fiscal services
(`app/Services/Fiscal/FiscalSealingService.php:37,:45`).

Validation cost: SHA-256 on ~30B input is microsecond-scale; throttle
`20,1` (`api.php:1258`) is sufficient given Sanctum auth already
narrows the abuser pool.

---

## §5 — Backend scan() vulnerabilities

### 5.1 — Sanctum ability check
`LoyaltyController.php:579` enforces `tokenCan('kiosk:order')`. **Confirmed
correct.** This is the same gate used on `/kiosk-event`
(`api.php:1218`) and matches CLAUDE.md §9 Sanctum invariants. Pre-auth
lookups in this method are unnecessary — `auth:sanctum` middleware
(`api.php:1258`) already enforces token presence.

### 5.2 — Rate limit
**Throttle is present**: `api.php:1258` declares
`throttle:20,1` on `/loyalty/scan` — 20 req/min per **route-default key
(user id for authenticated, IP for guest)**, see `RouteServiceProvider.php:54-58`.

Caveats:
- The default `'api'` limiter keys by `user.id` for authenticated
  requests (`RouteServiceProvider.php:57`). One Sanctum `kiosk:order`
  token == one bucket. The kiosk token is a **shared hardware
  credential** — *all* kiosks at a branch share it if provisioned
  via the same machine login. Attacker who steals a kiosk token gets
  20 req/min globally, not per IP.
- 20 req/min is fine for legitimate scan rate (1 every few seconds
  during peak), but it's **not** designed for brute-force.

### 5.3 — Brute-force feasibility on 8-char loyalty_code

Generation: `strtoupper(substr(md5(uniqid()), 0, 8))`
(`LoyaltyController.php:82, :162`).

**Real keyspace is hex (0-9, A-F), not full alphanum**:
- 16^8 = 4,294,967,296 (~4.3 billion), not 36^8 (~2.8 trillion).
- This is because md5 output is hex.

Population (assumed 100k users at scale): coverage = 100,000 / 4.3B ≈
**0.0023%** of keyspace is valid.

Online attack scenarios (Sanctum kiosk:order token in hand, no
distributed sockpuppets):
- At throttle:20/min → 1,200/hour → **28,800/day**.
- Expected hits per day: 28,800 × 0.0023% ≈ **0.66 valid codes/day**.
- To enumerate 100 codes: ~150 days at full saturation.

Online attack with N stolen tokens (e.g., 10 branches' kiosks):
- 10 × 28,800 = 288,000 attempts/day → ~**6.6 codes/day**.
- 100 codes in ~15 days. **Below acceptable threshold for a casual attack;
  feasible for a motivated insider** (e.g., disgruntled franchisee).

Mitigations ranked by leverage:
1. **Increase keyspace to alphanum** — change `:82, :162` to
   `Str::upper(Str::random(8))` (Base36 / 62). 62^8 = 218 trillion,
   coverage = 100k / 218T ≈ 5×10^-9%. Brute-force becomes infeasible
   even with 1M req/day. Trivial change, zero downstream impact since
   the column is `varchar` and lookup is case-folded.
2. **Stricter throttle on `/scan`** — drop to `throttle:10,1`
   (`api.php:1258`). Halves attack throughput at low cost (legitimate
   kiosk usage rarely exceeds 5/min).
3. **Token rotation** — `loyalty_code` should rotate periodically
   (e.g., on suspicious access pattern) but never expose old values
   without "phone+code" 2-factor (current `check()` at `:72-77`
   accepts either, so it's a single factor).
4. **Phase 6 HMAC signature** (§4) — fully closes online brute-force
   because attacker would need a valid signature, which requires the
   key.

**Compounding factor: `check()` endpoint (`api.php:1203`, throttle:10/1) ALSO
accepts `loyalty_code`** (`LoyaltyController.php:72`) and returns 404
on miss / 200 on hit. Attacker doesn't need `/scan` — `/check` is a
faster oracle (returns the user's name + points on hit at `:91-94`).
That's a **PII leak** worse than `/scan`. Throttle of `10,1` per user
helps but kiosk token = global bucket.

**P0 fix**: change loyalty_code generation to `Str::upper(Str::random(8))`
on next deploy. Existing codes stay valid; new ones gain 50,000× more
keyspace. No DB migration needed.

---

## §6 — Apple Wallet / Google Wallet implications

### Static QR (current path) — replay forever
A Wallet pass embeds an immutable QR image at provisioning time.
- iOS PassKit: `barcode.message` is set at pass creation, requires
  APNs push to refresh via pass-update endpoint.
- Google Wallet: similar; `linkedOfferIds` + barcode field require
  notification push to re-render.

If we ship a static `FK:<loyalty_code>` to Wallet:
- TTL refresh is **impossible** (Wallet pass is opaque to the app).
- Loss/theft of the phone = bearer-token access until `loyalty_code`
  is rotated server-side.
- Same QR appears on every device that re-installs the same pass.

### Dynamic refresh — Wallet pass update push
PassKit supports pass update via `webServiceURL`. The pattern:
1. Each pass has a unique `passTypeIdentifier` + `serialNumber`
   (mapped to `user.id` or `loyalty_code`).
2. Pass declares `webServiceURL: https://api.lecayenne.fr/wallet/passes/`.
3. Server periodically pushes update notifications via APNs (Apple)
   or FCM (Google) — Wallet then re-fetches the pass.
4. Refresh cadence: hourly is realistic; sub-5-min refresh hammers
   APNs quotas and isn't supported reliably.

### Recommendation
Hybrid:
- **Wallet pass carries `FK:<loyalty_code>`** (unsigned, paper-equivalent).
  In-Wallet QR is treated as low-trust by backend.
- **High-value redemptions** (>= 5€ discount, free item ≥ 10€ value)
  require the dynamic QR from the mobile **app**, not the Wallet pass.
  Backend `scan()` returns `requires_app_confirmation: true` flag when
  signature is missing AND amount-sensitive redemption is contemplated.
- **POS UI** displays badge "Carte Wallet - confirmation app requise"
  when scanning an unsigned QR for high-value paths.

This avoids re-architecting around APNs push refresh while still
gating monetary risk on the signed path.

Cite: no current Wallet integration exists in the repo as of
`grep -rn 'Wallet\|passkit\|PassKit' app/` (empty result). Phase 6
will be the first.

---

## §7 — Top 5 mitigations ranked by impact/effort

| # | Mitigation | Impact | Effort | When | File:line |
|---|---|---|---|---|---|
| 1 | **Fix QR generator to D-A format** — `generateMockQR` returns `{ payload: 'FK:<code>', signature, expires_at }`; ScreenLoyalty consumes it. | High (unblocks all forward work) | XS (1 file) | V0 now | `mobile/data/loyalty.js:54-58`, `mobile/screens-main.jsx:862` |
| 2 | **Loyalty_code keyspace upgrade** — `Str::upper(Str::random(8))` replaces `substr(md5(uniqid()),0,8)`. 50,000× brute-force resistance. | High | XS (2 lines) | Backend pre-Phase-6 | `app/Http/Controllers/Frontend/LoyaltyController.php:82, :162` |
| 3 | **Phase 6 `/qr/sign` endpoint + scan() signature verification** — HMAC keyed off `config('app.key')`, mirrors `OrderQuoteService:75` pattern. | High (closes replay, MITM, brute-force) | M (1 controller method + scan extension + 1 route + tests) | Phase 6 | new in `LoyaltyController.php` after `:714`; new route at `routes/api.php:~1213` |
| 4 | **TTL refresh hook done right** — server-anchored `expires_at`, `visibilitychange` listener, chained `setTimeout`, in-flight guard. | Medium (UX correctness + leak prevention) | S | V0 mock + Phase 6 | new `mobile/hooks/useLoyaltyQR.js`; consumed by `screens-main.jsx:846` |
| 5 | **Tighten `/check` throttle + add `/scan` signature** — `/check` lowered to `throttle:5,1` (it's an oracle), `/scan` accepts optional sig. | Medium (closes PII oracle) | XS | Phase 6 | `routes/api.php:1203, :1258` |

Bonus (not in top 5 but cheap):
- **CSS `user-select: none` on QR card container** — `screens-main.jsx:860`.
- **Mask member number** — show `#FK-***-12345` instead of full
  `#FK-12345` — `screens-main.jsx:866`.
- **Wallet badge "carte non-signée"** in POS when scan returns no
  signature flag — POS-side change, out of mobile scope.

---

## Verdict

**Recommend D-A** for QR format (FK:<loyalty_code> with detached signature).

The most concerning finding is **Mitigation #2 (md5-hex 16^8 keyspace)**:
a 4.3-billion keyspace combined with a `/check` PII oracle is below
acceptable threshold given that Sanctum kiosk:order tokens are shared
hardware credentials and create a single throttle bucket. This is a
**P0** that ships independently of the mobile work — recommend opening
a backend ticket immediately.

The next most concerning is **absent backend HMAC**: a stolen QR
(screenshot, photo, OCR from POS feed) is a forever-bearer until the
user's `loyalty_code` is rotated. Phase 6 (`/qr/sign` + signature
verification in `scan()`) closes this and is the only mitigation that
fully addresses replay across all transport paths (paper, Wallet,
screenshot, OCR).

End of audit.
