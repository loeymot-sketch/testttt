# OSS DEEPER (Wave C) — SYNTHESIS STATUS

- **Branch**: `heal/cms-pr1-quickwins-2026-05-18`
- **HEAD**: `b789e769e9ad9fa895083d9e2d3055fc257cd2c1`
- **Auditor**: master sub-agent Wave C — OSS deeper (parallel with KDS deeper + Loyalty cross-surface)
- **Date**: 2026-05-18
- **Round**: 1
- **Specialists**: 3 (Architect, UX/A11y, RED-team) — all READ-ONLY
- **Anchors**:
  - `app/Http/Controllers/Admin/OrderStatusScreenController.php`
  - `app/Http/Resources/CDSOrderDetailsResource.php` + `CDSPopularItemResource.php`
  - `resources/js/services/OssSyncService.js`
  - `resources/js/store/modules/orderStatusScreenOrder.js`
  - `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue`
  - `resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue`
  - `resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue`
- **DIRTY files (read-only honored)**: `public/js/admin-oss.js`
- **FROZEN files touched**: NONE
- **Scope disjoint from Z-2 OSS fullsys + PO POS×OSS intersection** (verified — no duplicate P0/P1 raised)

---

## VERDICT — CONVERGED, NO HEAL REQUIRED

Wave C explored **6 OSS-specific surfaces** that Z-2 fullsys and PO intersection did not cover:

1. Multi-screen orchestration (twin Echo subs + twin polling on same branch)
2. Audio chime cross-browser compatibility matrix + mute UX
3. Back-of-restaurant visibility (view-distance + animation a11y)
4. Offline / network-blip LAN-only resilience (`navigator.onLine` gate)
5. Public-wall PII surface (final attest)
6. Customer-pickup-confirmation flow (greenfield design note)

**No new P0 or P1 found.** OSS public-wall payload is **PII-CLEAN** (final RED attest). Wave 3b/3c sister-service heals + P0-OSS-01 chime gate + R4 V1.0.2 wake-lock + P1-OSS-01 contrast heal have absorbed every defect class this deeper audit could legitimately surface.

Findings summary: **0 P0 / 0 P1 / 2 P2 / 4 P3 / 4 INFO + RECOMMENDATIONS**. All deferrable to V1.0.2.

---

## 4-LIST

### DEAD-CODE (none)

Nothing detected. Every line of `OrderStatusScreenController.php`, `OssSyncService.js`, `orderStatusScreenOrder.js`, and the 3 Vue components is reachable and consumed by at least one of: admin OSS surface, public TV-wall surface, POS dashboard widget, or Echo broadcast listener.

### SAFE-TO-CONSOLIDATE (none new in Wave C)

PO synthesis already flagged the optional `list()` / `listForBranch()` consolidation (`OrderStatusScreenOrderService.php`) as V1.0.2 with paired symmetry test. Wave C surfaces nothing additional.

### KEEP-AS-IS (verified working — do not touch)

1. `CDSOrderDetailsResource.php:17-24` — minimal public payload `{id, order_serial_no, token, queue_number, order_type, status}`. **PII-CLEAN final attest** (RED OD-RED-03).
2. `CDSPopularItemResource.php:18-25` — item-name (not customer-name), price, thumb only. Public-equivalent to existing `/api/frontend/item/popular-items`.
3. `OrderStatusScreenController.php:75-100` — `publicIndex` branch resolution (?branch_id query → fallback first ACTIVE).
4. `OrderStatusScreenController.php:111-135` — `publicMostPopularItems` mirrors publicIndex branch resolution (Sprint H5-B Z4-P2-04 heal).
5. `routes/api.php:1152-1157` — `throttle:oss-public` (60req/min/IP) on both public endpoints.
6. `RouteServiceProvider.php:145-152` — `oss-public` rate limiter definition.
7. `routes/channels.php:43-70` — token-NAME-based kiosk discriminator (wildcard-token-immune).
8. `OssSyncService.js:34-35 + 122-134 + 430-435` — cadence clamp `[250..60_000]ms` (Wave 3c KDS-ADV3C-08).
9. `OssSyncService.js:162-189` — WS reconnect edge-detector fires `_burstPoll('ws_reconnected')`.
10. `OssSyncService.js:214-252` — visibility-resume burst-poll with 1s throttle.
11. `OssSyncService.js:276-321` — `_poll()` body with 5xx backoff + AbortController.
12. `PreparingAndReadyComponent.vue:317` — chime structural-skip when `authBranchId() <= 0` (P0-OSS-01 Round 2 Impl C).
13. `PreparingAndReadyComponent.vue:127-132` — audio-init listener registration gated on `authBranchId() > 0`.
14. `PreparingAndReadyComponent.vue:186-202` — wake-lock acquire/release (R4 V1.0.2).
15. `PreparingAndReadyComponent.vue:134-138` — `visibilitychange` re-acquire wake-lock.
16. `PreparingAndReadyComponent.vue:355-362` — Echo×poll dedupe via `_echoMarkedReady` one-shot Set.
17. `PreparingAndReadyComponent.vue:23 + 43` — `text-[40px]` for both columns (owner-validated at iter15).
18. `PreparingAndReadyComponent.vue:16 + 36` — `role='region'` + `aria-label` on both column wrappers.
19. `OrderStatusScreenComponent.vue:9` — `<ConnectionStatusBanner suppress-transient />` (iter15 B-003/D-002 — avoid double-banner).
20. `OrderStatusScreenComponent.vue:13` — `role='main'` + `aria-label='label.oss_main_aria'`.
21. `orderStatusScreenOrder.js:29-40` — `authStatus`-branched URL switching (admin/oss-order vs frontend/oss-order).
22. `PopularItemComponent.vue:6` — `role='region'` + `aria-label`.

### RECOMMENDATIONS (V1.0.2 backlog — all non-blocking)

| Id | Severity | Title | LOC est. | Owner |
|---|---|---|---|---|
| **OD-ARC-01** | P2 | Multi-screen coordination (`BroadcastChannel('foodking-oss-coord')`) — elect leader tab per branch_id, followers skip chime + reuse XHR | ~40 LOC | Architect |
| **OD-ARC-02** | P3 | `navigator.onLine === false` short-circuit in `_poll()` + `window.online`/`offline` listeners in `start()` | ~12 LOC | Architect |
| **OD-ARC-04** | RECO | Customer-pickup-confirmation flow — Path A (visual fade) or Path B (POST endpoint + PICKED_UP status). Greenfield design. | TBD | Owner + Architect |
| **OD-UX-01** | P2 | Mute UX toggle in PRÊT column header (operator preference, persisted via localStorage), `_chimeMuted` gate in `_playReadySound` | ~25 LOC | UX |
| **OD-UX-02** | P3 | `docs/OSS_BROWSER_SUPPORT.md` — Chrome/Edge/Firefox/Safari/iOS/Android matrix with gesture-per-session caveats | docs | UX |
| **OD-UX-03** | P3 | `docs/OSS_DISPLAY_PHYSICAL.md` (view-distance) + `foodkingConfig.ossFontScale` parameterization for deeper floors | docs + ~8 LOC | UX |
| **OD-UX-04** | INFO | `@media (prefers-reduced-motion: reduce)` wrapper for `.oss-pop` / `.oss-bounce` / `.oss-flash` animations (WCAG 2.3.3 AAA) | ~6 LOC CSS | UX |
| **OD-RED-02-PROMOTED** | P3 | CSP `frame-ancestors 'self'` + `X-Frame-Options: SAMEORIGIN` on OSS public route — defense-in-depth against clickjack | ~10 LOC middleware | Security |
| **OD-RED-04** | AMBER | Branch enumeration logging (>10 distinct branch_id values per IP / 5min) — **already deferred** per `RouteServiceProvider.php:142-144` | ~15 LOC | Security |

### NEW BLOCKERS (none)

Wave C surfaced ZERO V1 blockers. The 0-P0 / 0-P1 verdict is concurrent with PO synthesis ("VERDICT — CONVERGED, NO HEAL REQUIRED") and Z-2 OSS Round-2 Impl C heal closure. All Wave C findings are P2/P3/INFO/RECO — appropriate for the V1.0.2 backlog.

---

## SPECIALIST REPORTS

- `reports/audit/oss-deeper-2026-05-18/round-1/OD-1/architect.json` — 4 findings: 1 P2 (multi-screen coord) + 1 P3 (navigator.onLine) + 1 INFO (PII attest GREEN) + 1 RECOMMENDATION (pickup-confirm greenfield design). 1180 words.
- `reports/audit/oss-deeper-2026-05-18/round-1/OD-2/ux-a11y.json` — 5 findings: 1 P2 (no-mute UX) + 2 P3 (browser-matrix docs, view-distance docs) + 2 INFO (reduced-motion AAA, portrait-hidden by-design). Cross-browser compatibility matrix included. 1245 words.
- `reports/audit/oss-deeper-2026-05-18/round-1/OD-3/red.json` — 7 attack classes probed: 6 GREEN + 1 P3 promoted (frame-ancestors CSP). PII final attest CONFIRMED clean. 1430 words.

---

## HEAL COMMITS

**None.** Audit converged with zero P0/P1 findings — the chime gate (P0-OSS-01), contrast fix (P1-OSS-01), wake-lock wiring (R4 V1.0.2), Wave 3b TZ-aware bounds, Wave 3c cadence clamp + UTC stale-prune + fail-closed allowlist, Wave 3c source-surface allowlist, and channel-auth token-NAME discriminator collectively cover every defect class Wave C could legitimately raise. The DIRTY anchor (`public/js/admin-oss.js`) was respected as READ-ONLY per mandate.

---

## CONCURRENCE WITH PRIOR REPORTS

- **Z-2 OSS fullsys** (reports/audit/goal-complement-2026-05-18/round-1/Z-2-OSS/): Wave C does NOT re-raise authBranchId() robustness, now('UTC') serialization, cadence clamp scope, chime gate vs Admin (branch_id=0) intent, _burstPoll vs logout race. Those were already attested in Z-2 RED specialist.
- **PO POS×OSS** (reports/audit/intersection-pos-oss-2026-05-18/synthesis/STATUS.md): Wave C does NOT re-raise TZ bounds, fail-closed allowlist, cadence clamp, channel-auth, public-chime structural skip, wake-lock, Echo+poll dedupe. Those are listed in KEEP-AS-IS above with explicit cross-reference.
- **Disjoint scope verified**: Wave C explores multi-screen orchestration, audio mute UX, back-of-restaurant a11y, offline/network-blip resilience, PII final attest, and customer-pickup-confirmation greenfield. None of these surfaces appear in Z-2 or PO 4-lists.

---

## VERDICT

**OSS is SHIPPABLE for V1 Le Cayenne.** Wave C confirms the system is hardened beyond V1 minimum requirements. The 9 V1.0.2 backlog items above (2 P2 + 4 P3 + 3 INFO/RECO/AMBER) represent **opportunities**, not **blockers**. Owner sign-off appropriate for V1 merge to main.
