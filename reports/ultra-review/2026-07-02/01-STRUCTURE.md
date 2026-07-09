# 01 — STRUCTURE : Compréhension max FoodKing V1 (ultra-review 2026-07-02)

> Synthèse des 11 lecteurs-cartographes + critic de complétude (workflow `wf_b469939a-18b`,
> 12 agents, 0 err, 1.44M tok). HEAD `594eb92f5`. Anti-hallucination : chaque path/flow ci-dessous
> a été vu file:line par un agent cette session. **Verdict critic = GAPS_MAJOR** (10 zones ratées
> par la 1ʳᵉ décomposition → intégrées aux vagues W2-W5, voir §Gaps).

## Vue d'ensemble
FoodKing = plateforme caisse restaurant **Laravel 9 + Vue 2 (Mix)**, V1 LOCAL mono-poste « Le
Cayenne » (branch_id=1, FR, NF525). 5 systèmes produit + zones partagées. La commande naît en
borne (kiosk) ou caisse (POS), la cuisine (KDS) prépare, le client suit (OSS), l'encaissement se
fait au comptoir (Plan B / model B unifié), la clôture Z scelle la chaîne fiscale.

## Les 5 systèmes + cœur partagé (chaînes réelles)

### 1. BORNE (kiosk)
SPA `/kiosk/*` authentifiée machine (Sanctum `kiosk:order`, 480min). Idle 1080×1920 → catalogue
(`GET /frontend/menu`, cache 60s) → wizard compo (`KioskWizardComponent.vue:1757 buildCartItem`,
distribution multi-viandes) → preview SSOT (`kioskPricingPreview.js`, debounce 400ms, 422
silencieux) → **Plan B** (`FrontendOrderService.php:290-291` PENDING_COUNTER+COUNTER_DEFERRED,
totaux client unset l.271, KDS dispatch immédiat l.250) → écran « payez en caisse » + auto-print
pont ESC/POS `127.0.0.1:9100`. File offline IndexedDB cash-only.

### 2. CAISSE (POS)
SPA `pos-app.js` + wizard **Vanilla frozen** `pos-wizard.js` (299 Ko, VIANDES/ALL_SAUCES
hardcodés l.49-83). `orderSubmit` (`PosComponent.vue:3958`) assemble + ouvre `PaymentComponent.vue`
(frozen V5) → `POST /pos/quote` (re-price SSOT) → `POST /pos` 201. **`walkin_route_to_counter=true`
(config/pos.php:180-202, confirmé live)** : tout walk-in → file counter-collect différée, fiscal
alloué à l'encaissement seul (NF525 gap-free). File counter-collect = closures `routes/api.php:807-944`.

### 3. KDS + OSS
KDS `KitchenDisplaySystemOrderService` : filtre par **status + payment_status board-release (PAS
kds_station)** ; format cuisine symbolique (`G|TACOS|L|Cordon P|BL` prouvé live) ; bump/recall/undo
(recall TTL 60s ancrée `updated_at`). OSS public = **poll** (`PreparingAndReadyComponent.vue:265`,
5s réel — drift vs SYNC_CONTRACT qui dit 60s). TZ Paris-local fragile by-design (3 fenêtres jour).

### 4. WEB+APP storefront
Vitrine client **désactivée côté client seulement** (Vue router v-if) ; `/api/frontend/*` accepte
encore des commandes web guest complètes (`FrontendOrderService` partagé). Delivery fee
(`DeliveryFeeService`, base 4€ owner). Standalone `/Downloads/web` + `mobile/` = hors repo.

### 5. CENTRAL
~100 contrôleurs `Admin/**` : dashboard (Audit Trail NF525 rendu live), catalogue (SSOT items DB),
settings (~26, RBAC `permission:settings`), users, rapports Sales/Items, Fiscal Z/X lecture.
Sidebar `BackendMenuComponent.vue` fail-open si perm inconnue (:243-247).

### Cœur partagé (frozen/critique)
`PricingService::calculateOrder` (SSOT, composition_snapshot figé), `OrderStateMachine`,
`FiscalSequenceService` (alloc gap-free + retry), `ZReportService` (clôture HMAC), `AuditLogService`
(chaîne append-only). **Bus sync** : events plain (non-ShouldBroadcast) → `Persist*ToOutbox` →
`domain_events` → `DispatchDomainEventsJob` → soketi ; canal `private-branch.{id}`
(`channels.php:41`). Dégradation → poll fallback (no data loss). **NF525 CHAIN OK 4 branches
(vérifié live cette session).**

## Zones ratées par la 1ʳᵉ carte (critic GAPS_MAJOR — couvertes W2-W5)
1. **SCHEDULER/CRON** (`app/Console/Kernel.php` ~20 tâches) : `fiscal:close-all-active-branches`
   (L401), `fiscal:open-all` (L446), `backup-daily` (L144) + `backup:verify-restore` (L153),
   `CleanupStalePendingKioskOrders` (purge PENDING borne ↔ file counter-collect), outbox rescue,
   `stock:scan-rupture`, `SloEvaluatorJob`, `stripe:drain-stranded-cpn`. → **9e reviewer OPS (W2)**.
2. **Legacy `/install`** (`routes/web.php:9`, middleware `web` SEUL, POST database/license) →
   guard « installed » à prouver (W4) — sinon reconfig DB prod.
3. **Legacy `/payment/{order}/pay` + gateway success/fail** (`web.php:8-13`, `installed` seul,
   `{order}` devinable, Stripe drain schedulé) → IDOR/forge (W4).
4. **Dine-in / table QR** (`Table/OrderController`, dormant) → pricing SSOT + auth QR (W2/W4).
5. **Pipeline Uber interne** (`UberOrderMapper`→snapshot→KDS, `ProcessWebhookEventJob`) (W2/W3).
6. **Loyalty e2e** (`LoyaltyQrSigner`, `/loyalty/register` public throttlé) (W2/W4).
7. **Composer profiles** (Catalog Studio ; flag `posWizardComposerAware.enabled` : chemin actif ?).
8. **Observability/SLO + customer display + Menu/Stock/Ingredients services**.
9. **Exports/Imports Excel** (18 exports + 2 imports) → authz PII + injection CSV (W4).
10. **Delivery-boy ops + FCM/SMS + `/api/health` (200 public) + middlewares transverses**.

## Garde-fous anti-faux-positifs (imposés aux vagues suivantes)
- 2 fichiers NON-commités (`OrderRequest.php:205-208` guard KIOSK + `DeliveryFeeService` commentaire)
  → auditer le **working-tree**, noter « absent du HEAD/VPS ».
- `PosOrderRequest.php:117` `===` string/int = **connu, laissé exprès** (pas un P0 neuf).
- `env('DEMO')` double-branche (`ItemController:137`) = 1 seul finding (dédupliquer).
- 1 fail Vitest focus-visible = **pré-existant** (pas une régression).
- worktrees `.claude` supprimés + sentinelles F001/F009 = bruit connu.
- **59 items DB / 48 actifs vs 45 CONSTITUTION = drift de DOC + accumulation test-DB**, pas un bug.
- b7/b8/b9 (3 branches, 5 orders) + 127 orders source_surface NULL + 151 PENDING_COUNTER = résidus
  e2e test-DB (source des « 277 alertes SLA / 21 j » du dashboard). Non-bloquant V1 LOCAL.
