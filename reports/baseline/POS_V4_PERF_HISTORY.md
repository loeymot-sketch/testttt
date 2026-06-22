# POS V4 — Bundle Performance History

> **Source of truth** for cross-cycle bundle size evolution.
> Updated after every W-level deliverable that touches bundle composition.
> Gzipped sizes via `gzip -c <file> | wc -c` (rounded to KB).

---

## Cycle history (chronological)

| Cycle  | Date       | Action                                                  | `app.js` gz | `vendor.js` gz | `pos-shell.js` gz | `admin-shell.js` gz | New chunks                                  |
|--------|------------|---------------------------------------------------------|-------------|----------------|-------------------|---------------------|---------------------------------------------|
| W0     | 2026-04-26 | Baseline — monolithic `app.js` (no split, no vendor)    | **965**     | n/a            | n/a               | n/a                 | none                                        |
| W1-A   | 2026-04-26 | Lazy POS routes → `pos-shell` chunk                     | 1018 (+53)  | n/a            | **55**            | n/a                 | `pos-shell`                                 |
| W1-B   | 2026-04-26 | Vendor extraction (16 stable deps) + `mix.version()`    | **752**     | **267**        | 55                | n/a                 | `vendor`, `manifest`                        |
| W1-C   | 2026-04-26 | Lazy 25 admin/KDS/OSS/reports route modules (114 SFC)   | **456**     | 267            | 60                | **279**             | `admin-shell`, `admin-kds`, `admin-oss`, `admin-reports` |

### Cumulative gain on `app.js` since W0
- W0 → W1-C: **-509 KB gz (-53 %)**.

---

## Investigation: W0 → W1-A `app.js` delta (+53 KB, "untraced delta")

Identified by Claude in `AUDIT_W1A_CODESPLIT_CLAUDE_2026-04-26.md` as a residual risk.

### Root cause (confirmed at W1-C)
Webpack code-splitting allocates each shared module to **the chunk closest to the entry that needs it**. With only POS lazy-loaded (W1-A), the POS chunk (`pos-shell`) and the still-static admin/kiosk routes shared dozens of dependencies (services, stores, i18n bundles, helpers). Webpack's defaults pushed those shared modules **back into `app.js`** to avoid duplication across `pos-shell` and the static admin chunk.

### Resolution at W1-C
Once admin/KDS/OSS/reports routes were also lazy-loaded, webpack could re-allocate shared modules to the **right** chunk (each surface chunk now owns its dedicated dependencies). Result: `app.js` drops from 752 → 456 KB gz, fully absorbing the W0→W1-A delta and adding -296 KB on top.

### Operational lesson
Partial code splitting (only one surface lazy, rest static) **must not** be evaluated in isolation. The true gain materializes only when the entire route topology converges to a uniform lazy strategy. **Future bundle audits should run after a complete cycle**, not mid-flight.

---

## Surface first-paint cost (post W1-C, gzipped, cold cache)

| Surface             | Boot chain                                  | Total gz |
|---------------------|---------------------------------------------|----------|
| Any boot            | `manifest + vendor + app`                   | **725**  |
| `/admin/dashboard`  | boot (Dashboard is in `app.js`)             | 725      |
| `/admin/pos`        | boot + `pos-shell`                          | **785**  |
| `/admin/kitchen-display-system` | boot + `admin-kds`              | **752**  |
| `/admin/order-status-screen`    | boot + `admin-oss`              | **731**  |
| `/admin/employees` (or any other admin classique sub-route) | boot + `admin-shell` | 1004 |
| `/admin/reports/sales` (or items/credit) | boot + `admin-reports` | 734 |

### KPI alignment
- **W1 KPI target**: POS first-paint < 220 KB gz.
  - Current POS first-paint: **785 KB gz** (includes the entire generic `app.js` shell).
  - Gap: 565 KB. Closing it requires a **dedicated POS entry-point** (`pos-app.js` with its own Vue root), which is W2-level work, **not** route lazy-loading. Recorded as a pending architecture decision.

- **Realistic interim KPI** (achievable with route lazy-loading alone):
  - Generic boot ≤ 750 KB gz: **achieved** (725 KB).
  - Admin first sub-route ≤ 1.1 MB gz: **achieved** (1004 KB).

---

## Cache hit ratio outlook
Each chunk now invalidates independently on `mix.version()` content-hash change:
- `vendor.js` (267 KB) — invalidated only when a vendored library upgrades.
- `app.js` (456 KB) — invalidated on most app code changes.
- `admin-shell.js` (279 KB) — invalidated only when admin classique code changes.
- `pos-shell.js` (60 KB) — invalidated only when POS code changes.
- `admin-kds.js` (26 KB) / `admin-oss.js` (6 KB) — niche surfaces, rarely invalidated.

**Net effect**: a typical POS-only patch invalidates ~516 KB (`app.js` + `pos-shell`) instead of the full 965 KB W0 baseline.

---

## How to update this file

After any cycle that touches bundle composition:

```bash
npm run production
for f in public/js/manifest.js public/js/vendor.js public/js/app.js public/js/admin-shell.js public/js/pos-shell.js; do
  printf "%-40s gz=%6s KB\n" "$f" "$(gzip -c "$f" | wc -c | awk '{printf "%d", $1/1024}')"
done
```

Append a new row to the cycle history table above with the cycle ID, date, action summary, and resulting sizes.
