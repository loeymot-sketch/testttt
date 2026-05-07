# Runbook — CSP migration `<meta>` → HTTP header

**Status:** Active (2026-05-07) — RED-R2 §1 P2
**Owners:** Security / GSTACK
**Scope:** Web responses (middleware group `web`), not API.

## 1. Why

The CSP previously delivered via `<meta http-equiv="Content-Security-Policy-Report-Only">` in
`resources/views/master.blade.php` is **ignored by modern browsers** for several critical
directives — notably `frame-ancestors`, `sandbox`, and `report-uri`. RED-R2 §1 flagged this
as P2: defense-in-depth requires an actual HTTP header.

## 2. What ships

- `config/security.php` — single source of truth for mode + directives.
- `app/Http/Middleware/ContentSecurityPolicyHeader.php` — sets the header on every web response.
- Registered in `app/Http/Kernel.php` web middleware group (after `SubstituteBindings` /
  `CorrelationIdMiddleware`).
- The `<meta>` block in `master.blade.php` is preserved as **fallback-only** during the transition
  (rollback safety). Tagged with `FALLBACK ONLY` in the comment block; sentinel
  `tests/js/sentinels/cspMigratedToHttpHeader.spec.js` enforces both invariants.

## 3. Modes

Driven by env `CSP_ENFORCE_MODE`:

| Value          | Header emitted                          | Effect                       |
|----------------|------------------------------------------|------------------------------|
| `report_only`  | `Content-Security-Policy-Report-Only`    | log violations only — DEFAULT |
| `enforce`      | `Content-Security-Policy`                | block violations             |
| `disabled`     | (none)                                   | rollback escape hatch        |

Default = `report_only`. Tightening to `enforce` is a separate cycle (K-9) gated on inline-script
removal (window.foodkingConfig, pos-wizard shim, Dine-In hide style block).

> **Operational note:** unlike the previous kiosk-only `<meta>` setup, the HTTP header now fires
> on **all web surfaces** (admin, POS, kiosk). The `/api/frontend/csp-report` endpoint will
> therefore receive violations from admin/POS too — expect a transient bump in volume on the
> `observability` log channel during the soak. Adjust throttling there if it becomes noisy.

## 4. Activation

```bash
# .env
CSP_ENFORCE_MODE=report_only      # default — safe
php artisan config:cache
```

Smoke check:

```bash
curl -I http://localhost:8000/login
# Expect:  Content-Security-Policy-Report-Only: default-src 'self'; ...
```

## 5. Rollback

If a regression is observed (broken layout, missing assets, Pusher/WS connect failure):

```bash
# .env
CSP_ENFORCE_MODE=disabled
php artisan config:cache
```

The middleware short-circuits and emits no header. The `<meta>` block in `master.blade.php`
remains as a vestigial fallback (kiosk-only, report-only).

## 6. Tests

- `php artisan test --filter=ContentSecurityPolicyHeader`
- `npx vitest run tests/js/sentinels/cspMigratedToHttpHeader.spec.js`

## 7. Future work (out of scope here)

- K-9: migrate inline scripts to nonced scripts; flip to `enforce`.
- K-10: emit `Report-To` header alongside legacy `report-uri`.
- Eventually delete the `<meta>` block once `enforce` has soaked in production.
