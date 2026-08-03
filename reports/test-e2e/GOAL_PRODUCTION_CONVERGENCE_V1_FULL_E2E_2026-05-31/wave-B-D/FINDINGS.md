# Wave B (per-system visual) + Wave D (rush) — full-plan execution

## Wave B — visual, 8 surfaces captured + Read + analyzed (JPEG, disk-frugal)
| Surface | Verdict | Notes |
|---|---|---|
| `/kiosk/idle` | ✅ clean | Le Cayenne brand, "Bienvenue !" FR serif, Cayenne orange, light mode, "À emporter" only (V1 dine-in off), no raw labels. 401 `/api/menu` = expected unauth-kiosk headless. |
| `/admin/dashboard` | ✅ clean | KPIs ventes 2796.40€, **45 articles=SSOT**, ticket 16.50€, Kiosk 100% canal, 0 console errors. 133 SLA alerts = MS-02 pile. |
| `/admin/kitchen-display-system` (KDS) | ✅ clean | 4-col cards A0169–A0177, `NOUVELLE`/`EN COURS` FR statuses, dark "Prêt" bump CTAs, honest "LOCAL" sync note, 0 console errors. Empty card bodies = synthetic test-order artifact. |
| `/admin/order-status-screen` (OSS) | ✅ clean | 2-col "En préparation" (magenta) / "Prêt" (green), honest empty state (no PREPARING/PREPARED currently), brand intact. |
| `/admin/pos` | ✅ clean + **discount UI confirmed** | **"Appliquer une réduction fidélité" button visible (Q2 discount UI live, flag ON)**; "À encaisser borne (59)" queue clean totals (no NaN); real product photos+prices (Menu 3€, Sandwich Cayenne 7€); ticket panel. |
| `/admin/historique` | ✅ clean | Unified table: ORIGINE (Borne badge), N° FILE, MONTANT (no NaN), PAIEMENT, **N° FISCAL** ("—" unpaid=correct), STATUT, VOIR; 403 entrées/41 pages. |
| `/admin/observability` | ✅ clean (known P3s only) | **Événements en attente 0 = sync healthy**; dispatched 20 @ p95 1343ms; queue:work/websockets:serve "DOWN" = known OBS-01 false-negative heuristic; 1 Stripe job fail = OBS-02 dev. No new issues. |
| `/admin/stock/rupture` (86) | ✅ clean | "Gestion Produits & Stock" real-time sync note, full category tree (Sauces 11, Viandes, Bols…), EN STOCK toggles + real photos. |

**DOC-DRIFT-01 (P3):** CLAUDE.md §6 + plan §1.3 list `/admin/stock-rupture-dashboard` which **404s** (clean branded 404). Real route = `/admin/stock/rupture`. Stale doc, not a product defect.

Visual verdict: **no regression on any system**; discount UI live on POS; sync healthy; only known P3s. (Full 18×3 viewport matrix not run — 2 surfaces × desktop, scoped for 1.4Gi disk; the unchanged surfaces match the 3h-prior GO-100% capture.)

## Wave D — rush (R2-level concurrent cohort, live HTTP)
- `e2e:stress` harness = self-401 no-op (known MEMORY bug); drove a real cohort via the proven quote→order flow instead.
- **30 concurrent kiosk orders (10 discounted)** → **all HTTP 201**, elapsed **2985ms**.
- Invariants: **30 distinct queue numbers / 0 dup** (queue-collision race-safe — the old "×3 OSS" bug class), 10 discounts applied correctly server-side, **chain CHAIN OK**, **outbox pending = 0** (sync not backlogged under load), z-membership OK.
- All 30 = fiscal-NULL PENDING_COUNTER (kiosk CARD → counter) → cleaned up; **dev DB restored to exact 414/169**.

### Sync latency (D1) + WS degradation (D3)
- soketi WS (`:6001`) is **down in this dev env** (observed: WS connect fail on kiosk idle) → system runs on **polling fallback**, which is healthy (outbox 0 pending, KDS/OSS render). Precise WS push latency not measurable without soketi up (prior cycles measured 6–512ms WS when up). This is the expected dev posture; polling fallback is the proven degradation path.

### R1/R3
- R1 (calm) subsumed by the burst proofs; R3 (200/min) deliberately not run — disproportionate fiscal-numbered DB pollution for the delta; prior cycles proved 117–224-order loads. Documented, not silently skipped.
