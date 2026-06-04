# SCORING MATRIX — FoodKing Le Cayenne V1 Gap-Hunt 2026-05-25

**Phase C aggregation** | Branch `heal/cms-pr1-quickwins-2026-05-18` | 152 raw → **71 deduped master gaps**

**By severity** : P0=14 · P1=31 · P2=21 · P3=5
**Owner explicit** : 23 / 71 (32%) · **Frozen-zone touch required** : 3 / 71

Scoring formula : `score = (value_to_owner × 2) − effort_days`
* value_to_owner 1-5 (BLOCKER-DAILY=5, NICE=1)
* effort_days from XS=0.25, S=1, M=2, L=4, XL=8

---

## TOP 30 RANKED BY SCORE

| Rank | ID | Title | System | Sev | Score | Value | Effort | Owner-Cited | Frozen |
|-----:|----|-------|--------|----:|------:|------:|-------:|:------------|:-------|
| 1 | MASTER-GAP-002 | KDS recall/undo after wrong bump (owner mandate verbatim) | KDS | P0 | **10** | 5 | M | YES | YES |
| 2 | MASTER-GAP-001 | Refund UI button — backend NF525-ready, NO trigger | POS | P0 | **9** | 5 | S | YES | NO |
| 3 | MASTER-GAP-005 | Owner export PDF/Excel cloture du soir (1-click) | Admin | P1 | **9** | 5 | S | YES | NO |
| 4 | MASTER-GAP-022 | Chef→cashier shortage signal channel | Cross | P0 | **9** | 5 | S | YES | NO |
| 5 | MASTER-GAP-003 | Customer SMS notification when PRET (kiosk source==10 guard) | Kiosk | P0 | **8** | 5 | M | NO | NO |
| 6 | MASTER-GAP-009 | Phone-number search in POS cashier surfaces | POS | P1 | **8** | 4 | S(0.5) | NO | NO |
| 7 | MASTER-GAP-046 | Stock 'il reste 3 portions' alert (backend latent) | Stock | P0 | **8** | 5 | M | YES | NO |
| 8 | MASTER-GAP-020 | PENDING_COUNTER zombies pollute KDS | Cross | P1 | **7.75** | 4 | XS | NO | NO |
| 9 | MASTER-GAP-068 | Kiosk is_rush UI banner (orphan signal) | Kiosk | P1 | **5.75** | 3 | XS | NO | NO |
| 10 | MASTER-GAP-004 | 10-min fiscal Z dead-zone cross-day | Cross | P0 | **7** | 5 | M | NO | YES |
| 11 | MASTER-GAP-006 | Daily incident note (panne TPE, etc.) | Admin | P1 | **7** | 4 | S | YES | NO |
| 12 | MASTER-GAP-007 | Compare J vs J-1 / S-1 deltas | Admin | P1 | **7** | 4 | S | YES | NO |
| 13 | MASTER-GAP-017 | NF525 chain verify on-demand UI button | Inspector | P0 | **7** | 4 | S | NO | NO |
| 14 | MASTER-GAP-018 | Past-order replay fiscal context (audit_fingerprint orphan) | Inspector | P0 | **7** | 4 | S | NO | NO |
| 15 | MASTER-GAP-027 | POS cart line chef-instruction input | POS | P1 | **7** | 4 | S | NO | NO |
| 16 | MASTER-GAP-047 | Stock quick-toggle 'rupture du jour' from POS/KDS | Stock | P1 | **7** | 4 | S | YES | NO |
| 17 | MASTER-GAP-048 | Stock quantity remaining visible on KDS card | Stock | P1 | **7** | 4 | S | YES | NO |
| 18 | MASTER-GAP-008 | Top items du jour avec chart | Admin | P1 | **6** | 4 | M | YES | NO |
| 19 | MASTER-GAP-010 | Customer history at-the-till (Spatie gate) | POS | P1 | **6** | 4 | M | NO | NO |
| 20 | MASTER-GAP-011 | Multi-cashier same drawer relais | Cash | P0 | **6** | 4 | M | NO | NO |
| 21 | MASTER-GAP-012 | Cash skim / sortie coffre mid-shift | Cash | P1 | **6** | 4 | M | NO | NO |
| 22 | MASTER-GAP-015 | NF525 audit_logs UI search (currently WRONG table) | Inspector | P0 | **6** | 4 | M | NO | NO |
| 23 | MASTER-GAP-016 | NF525 fiscal archive UI (CLI-only) | Inspector | P0 | **6** | 4 | M | NO | NO |
| 24 | MASTER-GAP-021 | OrderCancelled broadcast → KDS chef silent radio | Cross | P0 | **6** | 4 | M | NO | NO |
| 25 | MASTER-GAP-028 | POS allergen observation at counter | POS | P1 | **6** | 4 | M | NO | NO |
| 26 | MASTER-GAP-030 | POS parked orders shift handover | POS | P1 | **6** | 4 | M | NO | NO |
| 27 | MASTER-GAP-056 | tx/h chart ORPHAN — CustomerStatsComponent not mounted | Admin | P1 | **5.9** | 3 | XS | NO | NO |
| 28 | MASTER-GAP-044 | KDS step ladder CTA dynamic label | KDS | P1 | **5.75** | 3 | XS | NO | NO |
| 29 | MASTER-GAP-041 | KDS actor identity exposed in drawer | KDS | P0 | **5.5** | 3 | S(0.5) | NO | NO |
| 30 | MASTER-GAP-014 | Z-report reprint PDF caissier | Inspector | P1 | **5** | 3 | M | NO | NO |

---

## OWNER-EXPLICIT GAPS (verified absent — 14/14 owner Q1-Q14 cross-checked)

These were specifically requested by the owner during prior sessions and are **CONFIRMED ABSENT** in V1 today:

| ID | Owner Verbatim | Status | Sev |
|----|----------------|--------|----:|
| MASTER-GAP-002 | "écran cuisine je peux pas y accéder aux archives parce que je peux par exemple avoir fait valider une commande par erreur avec rapidité" | ABSENT — Wave V removed 3s undo + Wave X3 deferred V1.0.2 | P0 |
| MASTER-GAP-001 | "Refund / Annulation correctrice" (implicit S2-P3 + S7-P3) | ABSENT_AT_UI_LEVEL_BACKEND_READY (route admin/pos-order/{order}/refund-with-counter-entry has no Vue consumer) | P0 |
| MASTER-GAP-005 | "Export PDF/Excel comptable" + "même endroit en base" | ABSENT — libraries installed + used 9 controllers, cash-overview not wired | P1 |
| MASTER-GAP-006 | "Note incident journalier" | ABSENT — no daily_notes/incident_logs table, no UI | P1 |
| MASTER-GAP-007 | "Compare J vs J-1, J vs S-1" | ABSENT — DashboardService.realtimeReport returns only absolute, no delta | P1 |
| MASTER-GAP-008 | "Top items du jour graphique" | ABSENT — MostPopularItems is lifetime + plain ul/li without chart | P1 |
| MASTER-GAP-022 | "Chef signale manque temporaire au caisse" | ABSENT — KdsOrderCard no button, no reverse channel | P0 |
| MASTER-GAP-046 | "Alerte automatique il reste 3 portions avant rupture" | PARTIAL_BACKEND_LATENT — listener exists but gated/log-only | P0 |
| MASTER-GAP-047 | "Quick toggle rupture du jour sans aller admin" | ABSENT — no temporary_today reason, no midnight reset | P1 |
| MASTER-GAP-048 | "Quantité restante visible KDS card" | ABSENT — KdsOrderCard zero stock indicator | P1 |
| MASTER-GAP-057 | "Predict demande basé jour semaine / predict demain base historique" | ABSENT — no Forecast service anywhere | P2 |

**Also verified ABSENT in audit (Q-equivalent)** : COGS/food-cost ratio, DLC expiry, supplier purchase orders, customer feedback/review/rating.

---

## TOP 5 P0 MUST-HEAL V1

Ordered by score (priority for V1 ship readiness).

1. **MASTER-GAP-002 KDS recall/undo (owner-mandate)** — score 10
   * Owner verbatim cited "secondaire" but classified as V1.0.2 backlog by chef
   * Compensating-action path = no frozen-zone touch (preferred)
   * OR LOCK_KDS_RECALL plan + owner countersign for gated reverse transition
   * Effort 2d, frequency 2-8 fois/jour rush hour

2. **MASTER-GAP-001 POS refund UI button** — score 9
   * Backend NF525-ready (sealed-parent guard + mirror order + audit-chain APPEND + sentinel-tested)
   * UI button + `pos-refund` Spatie permission ring-fence (currently no permission middleware on route — security gap)
   * Effort 1d, frequency multiple per shift wrong-item / card-cleared

3. **MASTER-GAP-022 Chef→cashier shortage signal** — score 9
   * Cross-validated by 2 independent sub-agents (S3-P1 + S6-P1)
   * Reuses existing AvailabilityService::toggle SSOT + broadcast ItemAvailabilityChanged
   * Effort 1d, frequency 2-5 incidents per rush
   * Daily blocker for owner-mentioned workflow

4. **MASTER-GAP-046 Stock '3 portions' alert (backend latent)** — score 8
   * Backend support EXISTS (stock_levels.threshold_low + NotifyStockLowOnStockLevelChanged listener)
   * Flag `FK_CATALOG_STOCK_LOW_ALERT_ENABLED=false` default
   * StockLevelChanged dispatched ONLY on cross-zero — decrement 5→4→3 never triggers
   * Listener is log-only — no Mail/Notification/UI push
   * Fix: activate flag + add ChoiceBoundaryProximityEvent on threshold_low
   * Effort 2d

5. **MASTER-GAP-003 Customer SMS notif when PRET** — score 8
   * Hardcoded `if ($this->order->source == 10) return` in OrderSmsNotificationBuilder.php:30-33 blocks 80%+ Le Cayenne volume
   * Phone deja capture via Loyalty flow
   * Per-status feature flag override (notification_alerts table existing) preserve PENDING/ACCEPT mute + enable PREPARED
   * Effort 2d

**Note on P0s NOT in Top 5** (still V1 ship-critical):
* MASTER-GAP-004 10-min Z dead-zone → score 7 — NF525 invariant breakable (math-broken), but rare event (close window). LOCK_FISCAL plan needed.
* MASTER-GAP-015/016/017/018 NF525 inspector accessibility (audit_logs UI, archive UI, chain-verify button, past-order fiscal context) → all score 6-7. Bundle as "Inspector Self-Service Wave" 2-3 dev-days. Required if owner accepts no on-site dev during fiscal control.
* MASTER-GAP-021 OrderCancelled broadcast → KDS silent radio → score 6. EventContract slot orphan (declared, no emitter). Heal cascade silent on Wave V.
* MASTER-GAP-039/040/041 KDS NF525 audit chain leg (transitions not signed + updated_at proxy + actor anonymous) → 5-5.5.
* MASTER-GAP-042/043 KDS newbie safety (confirmation manquante + help drawer) → 4.
* MASTER-GAP-061 Customer feedback/review collection → score 3 (P0 because owner Q14 retention mentioned, but ABSENT entire — SpinBoost separate not integrated).
* MASTER-GAP-011 Multi-cashier same drawer relais → score 6 (P0 operational for restaurant 8h+ shifts).

---

## TOP 10 P1 → V1.0.1 candidates

Sequencing recommended after V1 ship.

| Rank | ID | Title | Score | Effort | Why V1.0.1 |
|-----:|----|-------|------:|-------:|:-----------|
| 1 | MASTER-GAP-005 | Owner export PDF/Excel cloture | 9 | 1d | Quick-win, libs installed |
| 2 | MASTER-GAP-009 | Phone-number search POS | 8 | 0.5d | Single haystack extension |
| 3 | MASTER-GAP-020 | PENDING_COUNTER zombies cleanup | 7.75 | 0.25d | 1 line + 1 test |
| 4 | MASTER-GAP-068 | Kiosk is_rush UI banner | 5.75 | 0.25d | Orphan binding |
| 5 | MASTER-GAP-006 | Daily incident note | 7 | 1d | Audit/insurance |
| 6 | MASTER-GAP-007 | Compare J vs J-1 / S-1 | 7 | 1d | Owner signal-detection |
| 7 | MASTER-GAP-027 | POS chef-instruction cart input | 7 | 1d | Schema already exists |
| 8 | MASTER-GAP-047 | Stock 'rupture du jour' quick-toggle | 7 | 1d | Owner cited |
| 9 | MASTER-GAP-048 | Stock remaining on KDS card | 7 | 1d | Owner cited |
| 10 | MASTER-GAP-056 | tx/h chart orphan mount | 5.9 | 0.1d | 5min XS fix |

---

## P2 V1.0.X BACKLOG

Defer to roadmap after V1.0.1 hardening.

* MASTER-GAP-019 pickup_code QR token (kiosk→POS handover NF525 attribution)
* MASTER-GAP-029 POS livreur coordination strip
* MASTER-GAP-031 POS session-lock / PIN-pause
* MASTER-GAP-034 POS my-orders today summary chip
* MASTER-GAP-037 KDS source filter (TAKEAWAY vs KIOSK vs DELIVERY vs POS)
* MASTER-GAP-038 KDS priorisation manuelle (orders.priority enum)
* MASTER-GAP-049 Stock max_daily_qty UI consumer (dead-code backend)
* MASTER-GAP-050 Stock threshold_low input UI (Mission 1 dropped)
* MASTER-GAP-051 Stock daily waste/loss report (ENUM extension)
* MASTER-GAP-054 Stock historique consommation chart
* MASTER-GAP-055 Owner anomaly detect (refund spike)
* MASTER-GAP-057 Predict tomorrow forecast (simple moving average)
* MASTER-GAP-063 Customer RGPD delete-account / data-export
* MASTER-GAP-066 POS order-level customer_message field
* MASTER-GAP-067 Kiosk party-size selector
* MASTER-GAP-069 Kiosk packaging hint
* MASTER-GAP-070 Kiosk small UX cluster (back-from-upsell + cancel immediate + global note + scheduled order)

---

## P3 V2 SaaS REFACTOR

Architectural refactors not justifiable for V1 Local.

* MASTER-GAP-052 DLC expiry alert / batch tracking (requires stock_movements batch rework)
* MASTER-GAP-053 Replenishment suggestion (requires target_stock + supplier model)
* MASTER-GAP-060 Customer reorder self-service (kiosk wizard FROZEN — LOCK needed)
* MASTER-GAP-061 Customer feedback/review/rating/NPS (SpinBoost SaaS integration)
* MASTER-GAP-062 Loyalty points expiry policy (P1 but defer until SaaS multi-tenant)
* MASTER-GAP-064 SMS gateway failover / DLR observability (V2 multi-tenant SLA)
* MASTER-GAP-065 POS recall/refire shortcut for chef-side complaint
* MASTER-GAP-071 Global cmd-k / spotlight admin search

---

## CROSS-CUTTING PATTERNS

Identified during dedup:

1. **Orphan signals** (backend produces, store stocks, ZERO UI consumer)
   * is_rush (KioskMenuService → kioskMenu.js → 0 binding)
   * audit_fingerprint (OrderDetailsResource computes → no Vue reads)
   * CustomerStatsComponent (computed by service, not mounted in DashboardComponent)
   * max_daily_qty (backend full, no frontend consumer post Mission 1)
   * threshold_low (column exists, UI dropped Mission 1)
   * preparation_time (Order model, kiosk waiting doesn't bind)
   * **Recommendation** : monthly producer-consumer audit chore + lint rule

2. **EventContract drift** — BROADCAST_MAP slots declared without emitter
   * 'OrderCancelled' declared at EventContract.php:39 + REQUIRED_PAYLOAD_KEYS at :60 but ZERO Persist*ToOutbox emitter
   * **Recommendation** : Sentinel `BroadcastMapEmitterCoverageSentinelTest` (V1.0.2)

3. **Time-attribution divergence** (3 different keys for same order across services)
   * created_at (ZReportService aggregate window)
   * paid_at (ZReportCashEnrichmentService payments)
   * business_date (Order.business_date)
   * **Recommendation** : unify window key on business_date (P0 — MASTER-GAP-004)

4. **NF525 BACKEND COMPLETE, ADMIN UI SHALLOW** — 6/6 inspector questions land on absent or partial without surfacing
   * audit_logs HMAC chained but no UI search
   * FiscalVerifyChainCommand + FiscalArchiveCommand CLI-only
   * AuditTrailComponent reads ActionLog (wrong table) — actively misleads
   * **Recommendation** : Bundle MASTER-GAP-015 + 16 + 17 + 18 as "Inspector Self-Service Wave V1.0.1" (~2-3 dev-days, 0 frozen-zone)

5. **Kiosk customer journey end-to-end critically underbuilt**
   * Zero SMS (source==10 hardcoded guard)
   * Zero feedback/review collection
   * Zero customer reorder self-service
   * Zero tracking URL/QR
   * Loyalty points expire forever
   * **Recommendation** : Customer Retention Wave V1.0.2 (~5 dev-days)

---

## VERDICT

V1 Le Cayenne LOCAL has **strong backend foundations** (NF525 chain, multi-tenant BranchScope, K2 race protections, ItemAvailabilityChanged cascade, idempotency) but **shallow operator UI surfaces** for the 4 main personas:

* **Cashier** : missing refund button, phone search, customer history, incident note → **5 P0/P1 gaps**
* **Chef** : missing recall/undo, chef→cashier signal, stock visibility, ETA push → **6 P0/P1 gaps**
* **Owner** : missing PDF export, J-vs-J-1, top-items chart, daily note → **5 P0/P1 gaps**
* **Inspector** : 6 of 6 ordinary questions un-answerable without dev shell → **6 P0 gaps**

**14 P0 gaps total** of which **3 are V1 SHIP BLOCKERS** : MASTER-GAP-002 (KDS undo, owner mandate) + MASTER-GAP-001 (refund UI, daily blocker) + MASTER-GAP-004 (Z dead-zone NF525 invariant).

**Estimated total heal effort** :
* **Top 5 P0** ≈ 8 dev-days
* **Top 10 P1 V1.0.1** ≈ 8 dev-days
* **All 14 P0s** ≈ 18 dev-days
* **Full P0 + P1 (45 gaps)** ≈ 60 dev-days (3 dev-months)

V1 minimum viable production ship requires Top 5 P0 (8d) + Inspector Self-Service Wave (3d) = **11 dev-days**.
