# PROJECT_BRAIN.md
— FoodKing Single Source of Truth (read at session start, update at end)

> Bootstrap : 2026-05-09 post iter1-14 cycle complet
> Lu et mis à jour automatiquement par Claude (cf. CLAUDE.md §5 LOOP).
> Ne pas éditer manuellement les sections §2-§5 (auto-managed).

---

## §1 NORTH STAR — Vision long-terme (immuable sauf owner gate)

### V1 — Restaurant SaaS opérationnel (en cours, V1 GO-LIVE imminent)
Plateforme restaurant fast-food complète :
- **POS** Caisse (commande staff + cash + card + ticket-restaurant)
- **Kiosk** Borne client (Vue 3 wizard, paiement card, FR-lock)
- **KDS** Kitchen Display System (cuisine, Echo + polling fallback)
- **OSS** Order Status Screen (clients en attente)
- **Admin** Dashboard (catalogue, stock, orders, reports, fiscal Z)
- **Sync** cross-surface (Outbox + Pusher + polling 5s fallback)

### V1.0.1 — Hardening sprint (8j-agent budget owner Q4=A)
- FormRequest authz refactor 88 endpoints
- Password policy min:12 + complexity
- Sanctum TTL 8h → 1h sensitive ops
- API key versioning
- 6 listeners idempotency restants (Catalog/Coupon/Availability×3/Table)
- Observability SLI metrics + KDS overflow flag UI

### V1.x — Post-V1 (backlog priorisé)
- F-016b stock dashboard UI (Q3=A 5-7j, 90% backend déjà existant)
- 17 advisories security composer triage (1 CRITICAL phpspreadsheet RCE)
- Laravel 9 → 10 → 11 migration (track séparé)
- Spatie permissions 5 → 6 (track séparé)
- ESLint v10 setup + Vue plugin
- Saga pattern Order + Payment + Stock orchestration
- Stripe webhook idempotency (parité SenangPay iter11)

### Goals immuables
- Production-grade correctness, coherence, reliability, quality
- NF525 compliance absolue (audit chain HMAC + 6y retention)
- Multi-tenant branch isolation absolue
- Pricing SSOT backend authoritative
- Visual + technical evidence à chaque livraison

---

## §2 CURRENT STATE — Auto-managed

- **Branche** : `cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27`
- **HEAD** : `cce7a6f30` (post iter14 V1.0.1 sprint delivery)
- **Last update** : 2026-05-09
- **Domaines production-ready** : 16 / 16 ✅
- **Tests** : 705/705 PHPUnit verts + 16/16 E2E Playwright PASS
- **Frozen-zones** : 0 lines diff vs main (4 files protected)

---

## §3 LAST DONE — Auto-managed

**iter14 V1.0.1 hardening sprint** (commits `1ddc642a6` + `179d4e377` +
`3150992a7` + `cce7a6f30`) :
- SPECIALIST-1 — i18n cleanup 5 raw strings + OSS a11y landmarks
  WCAG 2.1 (7 fichiers, 6 keys × 3 locales = 18 entrées)
- SPECIALIST-2 — Listener idempotency `firstOrCreate` pattern + UNIQUE
  migration `idempotency_key` sur `domain_events` (4 listeners)
- SPECIALIST-3 — Fiscal orphan retry GATE-FZH-ALLOC + Z-close pre-check
  + cron `foodking:fiscal:retry-alloc` + nouvelle migration
  `fiscal_alloc_error_at` + 4 tests verts

Tests cumulatifs : 705/705 PHPUnit verts (filter Outbox|Persist|
DomainEvent|Fiscal|FinalizePaid|ZReport|FiscalSequence|Order).
E2E Playwright : 12/12 core (POS+Kiosk+KDS) + 4/4 auth+admin = 16/16 PASS.
Captures visuelles : kiosk idle confirmé branding intact + admin login OK.

---

## §4 NEXT TO DO — Auto-managed (brain-written)

> Section vide actuellement. Sera remplie au prochain `/ultraplan` ou
> `/ultrareview` invoqué par l'utilisateur.

**État actuel** : V1 ship-ready après owner action items §6.
Pas de plan en attente d'exécution.

---

## §5 BACKLOG — Priorisé (lu par /ultraplan pour orienter le plan)

### P0 (CRITICAL pre-merge V1) — fermés ✅
- ~~SenangPay webhook idempotency~~ → iter11 webhook_events table
- ~~OrderItem manque BranchScope~~ → iter11
- ~~z_reports DELETE non-bloqué~~ → iter11 trigger MySQL

### P1 (V1.0.1 sprint, partiellement fermés iter12-14)
- ✅ ~~OrderPayment + KioskMachine BranchScope~~ → iter12
- ✅ ~~OrderService::changeStatus race~~ → iter13 lockForUpdate
- ✅ ~~Stock listener escalation~~ → iter12+13
- ✅ ~~Stale daily quota cron~~ → iter13
- ✅ ~~Listener idempotency 4 listeners~~ → iter14
- ✅ ~~Fiscal orphan retry GATE-FZH-ALLOC~~ → iter14
- ✅ ~~i18n + OSS a11y WCAG 2.1~~ → iter14
- ⏳ FormRequest authz refactor 88 endpoints (1-2j)
- ⏳ Password min:12 + complexity (0.5j)
- ⏳ Sanctum TTL 8h → 1h sensitive ops (0.5j)
- ⏳ API key versioning (1j)
- ⏳ 6 listeners idempotency restants (0.5j)

### P2 (Observabilité V1.0.1)
- Latency SLI metrics (kiosk.payment_confirm + outbox_dispatch_p95)
- KDS limit-50 overflow flag UI
- `/api/sync/status` monitoring endpoint
- Frontend correlation_id dedup cache 120s
- Admin polling 60s → 10s adaptive si WS down
- Reconcile audit double-pay log

### V1.x post-V1
- F-016b stock dashboard UI (Q3=A)
- 17 advisories security composer (1 CRITICAL phpspreadsheet RCE)
- Laravel 9 → 10 → 11 migration
- Spatie 5 → 6
- ESLint v10 + Vue plugin setup
- Saga pattern Order + Payment + Stock
- Stripe webhook idempotency (parité SenangPay)
- Migration `2026_05_09_010000` squash dans `2026_05_08_050000`
  (avant prod V1)

---

## §6 DECISIONS LOG — Owner-validated gates (immuables)

Cette section est **append-only**. Toute décision validée par l'owner
y est enregistrée pour éviter la dérive et le re-questioning.

### iter6 — Owner replies
- **Q1=A** FR-lock V1 conservé (multi-locale UI désactivé v-if=false)
- **Q2=B** Migration archive-then-delete recoverable (au lieu de DELETE direct)
- **Q3=main** PR base branch = main

### iter7 — Owner replies
- **Q-A=B** Sub-agents ultra-audit avant apply (pas apply direct)
- **Q-B=A** MySQL DELETE triggers (driver-conditional, SQLite skip)
- **Q-C=A** webhook_events table UNIFIÉE (Stripe + SenangPay parity)
- **Q-D=skip** Vitest CI workflow (deferred post-V1)

### iter11 — Owner Q1-Q4
- **Q1=A** Signer 5 GATED migrations
- **Q2=A** DATA-004 fix pre-merge (+1j)
- **Q3=A** F-016b dashboard V1.x post-merge (5-7j backend déjà 90% ready)
- **Q4=A** Budget V1.0.1 ~8j-agent

### Architecture immuables
- Single-agent Claude Code session (pas de split brain/executor)
- 2 fichiers seulement : `CLAUDE.md` + `PROJECT_BRAIN.md`
- Slash commands natifs `/ultraplan`, `/ultrareview`, `/review`,
  `/security-review` (pas de custom à recréer)
- Visual test mandatoire à chaque modif frontend (Playwright + Read screenshot)
- Self-correction loop max 3 fois avant escalation user

---

## §7 VERIFICATION CHECKLIST — 16 domaines production-ready

| # | Domaine | Status | Iteration |
|---|---|---|---|
| 1 | Architecture event-driven (Outbox + Pusher + polling 5s) | ✅ | iter11 |
| 2 | Multi-tenant BranchScope (11 models scoped) | ✅ | iter11+12 |
| 3 | Pricing SSOT NF525 (composition_snapshot frozen) | ✅ | iter10 baseline |
| 4 | Fiscal hash chain + DELETE triggers MySQL | ✅ | iter11 |
| 5 | Idempotency dual-layer + webhook_events unifié | ✅ | iter11 |
| 6 | Order state machine + lockForUpdate races | ✅ | iter13 |
| 7 | Sanctum kiosk:order single-ability strict | ✅ | iter12 |
| 8 | Stock concurrency + listener escalation | ✅ | iter12+13 |
| 9 | Daily quota stale reset cron | ✅ | iter13 |
| 10 | Cash audit F-003 chain-signed | ✅ | iter10 baseline |
| 11 | Allergen FR + composition_snapshot | ✅ | iter10 baseline |
| 12 | Production guards AppServiceProvider | ✅ | iter10 baseline |
| 13 | Polling fallback KDS 5s (banner Mode secours) | ✅ | iter10 baseline |
| 14 | i18n + a11y OSS WCAG 2.1 | ✅ | iter14 |
| 15 | Listener idempotency firstOrCreate + UNIQUE | ✅ | iter14 |
| 16 | Fiscal orphan retry GATE-FZH-ALLOC | ✅ | iter14 |

---

## §8 DRIFT ALERTS — Auto-managed

> Si Claude détecte une dérive de direction (15-20° du NORTH STAR),
> il append ici avec timestamp + cause + recommandation.

Aucun drift détecté actuellement (post iter14).

---

## §9 OWNER ACTION ITEMS — Pre-merge V1

Avant merge `cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27` → `main` :

1. ✅ **Push origin DONE** (commits iter11-14 sur `cce7a6f30`)
2. ⏳ **Backup prod** : `mysqldump foodking_prod > pre-V1-backup-2026-05-09.sql`
3. ⏳ **migrate --pretend staging** (5 nouvelles migrations) :
   - `2026_05_09_010000_fix_order_ratings_unique_key.php`
   - `2026_05_09_120000_create_webhook_events_table.php`
   - `2026_05_09_160000_add_z_reports_delete_trigger_immutability.php`
   - `2026_05_09_180000_add_idempotency_key_to_domain_events.php`
   - `2026_05_09_200000_add_fiscal_alloc_error_at_to_orders.php`
4. ⏳ **Triage 17 advisories security composer** (1 CRITICAL `phpspreadsheet` RCE)
5. ⏳ **Smoke test live** post-deploy (Chrome MCP captures)
6. ⏳ **Coordinate** avec autre agent (PR #12 PHP 8.3 fix si conflit ouvert)
7. ⏳ **Merge → main** après validation

---

— *PROJECT_BRAIN.md à jour. Prêt pour la prochaine session Claude Code.
Lu automatiquement à chaque démarrage selon CLAUDE.md §5 étape 1.*
