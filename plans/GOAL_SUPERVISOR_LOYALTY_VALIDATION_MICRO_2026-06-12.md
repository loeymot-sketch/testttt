# GOAL SUPERVISEUR — Validation totale fidélité + micro-audit dashboard + plan smart « tout le reste »
2026-06-12 · branche `heal/clients-next-2026-06-10` HEAD `53701a21d` · worktree `cms-gestion-2026-06-10` · ultracode ON

## §0 Préambule
- **§0.1 Working tree** : propre (tracked). Untracked résiduels : `.env.e2e` (gitignoré, TIME_FORMAT=H:i), 2 PNG de sessions passées, `reports/test-e2e/cms-e2e-2026-06-10/` — laissés en place, hors scope.
- **§0.2 Harnais** : `:8767` / `foodking_e2e` (clone jetable, APP_ENV=e2e). ⛔ JAMAIS :8765/`foodking` (DB opérante NF525). Lib Playwright : `reports/test-e2e/petits-systemes-2026-06-11/lib.cjs` (Authorization forcée Bearer ; token Sanctum jetable minté/révoqué par vague).
- **§0.3 Anti-duplication (supervisor)** : déjà convergé ailleurs = dashboard-deep 06-08 (~58 pages, branche pre-cloud-exec), petits-systèmes 06-11 (16 pages secondaires, CETTE branche), UI/UX caisse+borne (release/v1), ultra-audit W1-W6 (release/v1). Le micro-audit ici = **lentille « micro/caché/non-optimisé »** sur CETTE branche (pas un re-test bouton-par-bouton) ; les findings dupliqués avec les lots A-H release/v1 sont MARQUÉS dédupliqués, pas re-fixés.
- **§0.4 Pipeline par tâche** : `ultra-audit-profond` (réf. unique). Adversarial OBLIGATOIRE : chaque finding passe un verify indépendant (refuter) avant d'exister ; chaque heal passe un RED dispute avant DONE.
- **§0.5 Convergence** : 2 cycles consécutifs P0+P1=0 ET ensembles de findings identiques. Rejets durs : raw label, console error, frozen-diff ≠ 0, « almost works ».

## §1 Map principal (ancres vérifiées 2026-06-12 via find/grep — sortie réelle en session)

### Système F — FIDÉLITÉ (cœur du goal)
**Backend (vérifié `find app -iname "*loyalty*"`)** :
`app/Services/LoyaltyService.php` · `app/Services/Loyalty/{PosRedemptionService,LoyaltyQrSigner,LoyaltyQrInvalidException}.php` · `app/Services/LoyaltySetupService.php` · `app/Http/Controllers/Frontend/LoyaltyController.php` · `app/Http/Controllers/Admin/{PosLoyaltyController,LoyaltySetupController}.php` · `app/Listeners/{AwardLoyaltyPointsOnDelivery,ClawbackLoyaltyPointsOnRefund,PersistLoyaltyBalanceChangedToOutbox}.php` · `app/Events/LoyaltyBalanceChanged.php` · `app/Models/{LoyaltyTransaction,LoyaltyConsent}.php` · `app/Console/Commands/SetLoyaltyRatesCommand.php` · `config/loyalty.php`
**Frontend** : `resources/js/components/admin/pos/PosLoyaltyRedeemModal.vue` · `admin/settings/LoyaltySetup/LoyaltySetupComponent.vue` · `frontend/kiosk/KioskLoyaltyComponent.vue` · `helpers/posLoyaltyMainCta.js` · `store/modules/loyaltySetup.js`
**Routes (vérifié routes/api.php)** : `:347` loyalty-setup admin · `:1020` POST /{order}/redeem-loyalty (LOCK_POS_LOYALTY_REDEEM_UI) · `:1425+` frontend /loyalty/* (register public throttlé, opt-in :1491, scan :1498, qr signé)
**Dispatch L2 (4 fichiers vérifiés)** : Frontend/LoyaltyController, AwardLoyaltyPointsOnDelivery, PosRedemptionService, LoyaltyService (refund+clawback) — 6 sites au total.
**Tests existants (vérifié `find tests -iname "*loyalty*"`)** : `tests/Feature/Loyalty/{LoyaltyRateSsotTest,LoyaltyBalanceChangedOutboxTest,LoyaltyBalanceThrottleParityTest,LoyaltyClawbackOnRefundSentinelTest,LoyaltyRefundPointsIdempotentTest,PosCustomerActiveStatus5LoyaltyTest,PosLoyaltyAccrualRealPathTest}.php` · `tests/Feature/{LoyaltyApiTest,KioskLoyaltyDoubleRedeemRefusedTest,KioskLoyaltyLedgerAtomicTest,OrderCancellationLoyaltyTest}.php` · `tests/Feature/KioskPhase1/{LoyaltyConsentTest,LoyaltyOptInEndpointTest}.php` · `tests/Feature/Pos/PosLoyaltyRedeemTest.php` · `tests/Feature/Refund/{RefundLoyaltyTryCatchHardenedSentinelTest,RefundWithCounterEntryRefundsLoyaltyPointsTest}.php` · `tests/Feature/Sentinels/{LoyaltyRateParitySentinelTest,LoyaltyQrSigningSentinelTest}.php` · `tests/Feature/Configuration/EnvExampleHasLoyaltyQrSecretSentinelTest.php` · Vitest `tests/js/{posLoyaltyLiveBalance,posLoyaltyRedeemModal,posLoyaltyMainPageCta,kioskLoyaltyConsentWiring}.spec.js`
**Miroirs** : `mobile/data/loyalty.js` + `/Users/1millnonstop/Downloads/web/data/loyalty.js` (sentinel parité 3/3).

### Système D — DASHBOARD ADMIN (micro-lentille)
**40 routes SPA vérifiées** (`grep path: resources/js/router/`) : dashboard, items(+composer/studio), ingredients(+attribute|extra|addon), stock/rupture, pos-orders(+tracker), online-orders, table-orders, historique, encaissement, cash-overview, cash-sessions-report, credit-balance-report, delivery-boy(s)(+cash-sessions), waiters, kds, order-status-screen, coupons, offers, messages, subscribers, push-notifications, administrators, employees, chefs, customers, transactions, sales-report, items-report, observability(+outbox), profile×2, settings (~20 onglets dont loyaltySetup, orderSetup, kioskSetup, branch, company, currency, mail, license…), demo/wizard-launcher, categories/:id/composer.
**Composants** : 37 dossiers `resources/js/components/admin/*` (vérifié ls).

## §3 Système F — Décomposition (4 sub-systèmes)

### Sub F.1 — Ledger & Earn (la vérité comptable des points)
**Anchors** : LoyaltyService.php, AwardLoyaltyPointsOnDelivery.php, LoyaltyTransaction.php, Frontend/LoyaltyController.php (register/welcome)
**Tasks** :
- T-F.1.1 Cohérence ledger↔solde : SUM(LoyaltyTransaction deltas) == users.loyalty_points pour TOUT user de la DB e2e (script DB) + invariant jamais négatif.
  • test: tests/Feature/Loyalty/LoyaltyRateSsotTest.php + (script SQL session, preuve dans rapport)
- T-F.1.2 Earn réel : commande caisse phone-keyed status=5 → +round(total×1) ; doubles livraisons ≠ double accrual (idempotence event).
  • test: tests/Feature/Loyalty/PosLoyaltyAccrualRealPathTest.php + PosCustomerActiveStatus5LoyaltyTest.php
- T-F.1.3 Welcome +25 : register 2× même phone → 1 seul bonus ; ledger type earn 'Bonus de bienvenue'.
  • test: tests/Feature/LoyaltyApiTest.php + (vérif idempotence TO BE CREATED si absente: tests/Feature/Loyalty/LoyaltyWelcomeIdempotentTest.php)
- T-F.1.4 Throttle/abus : register/check/scan throttlés (api.php :1426) ; enumeration phone impossible.
  • test: tests/Feature/Loyalty/LoyaltyBalanceThrottleParityTest.php

### Sub F.2 — Redeem & Reversal (sortie de points, argent réel)
**Anchors** : PosRedemptionService.php, PosLoyaltyController.php (api :1020), PosLoyaltyRedeemModal.vue, KioskLoyaltyComponent.vue, ClawbackLoyaltyPointsOnRefund.php, OrderCancellation path
**Tasks** :
- T-F.2.1 Redeem POS : min 100, linéaire 100=1€, plafond ≤ solde ET ≤ total commande ; remise visible historique (sous-total−remise=total).
  • test: tests/Feature/Pos/PosLoyaltyRedeemTest.php · visual: /admin/historique détail
- T-F.2.2 Double-redeem refusé (idempotency + race) kiosk et POS.
  • test: tests/Feature/KioskLoyaltyDoubleRedeemRefusedTest.php + KioskLoyaltyLedgerAtomicTest.php
- T-F.2.3 Refund/clawback : refund → re-crédit idempotent ; clawback earned points sur annulation ; jamais double-reversal.
  • test: tests/Feature/Loyalty/{LoyaltyRefundPointsIdempotentTest,LoyaltyClawbackOnRefundSentinelTest}.php + tests/Feature/Refund/RefundWithCounterEntryRefundsLoyaltyPointsTest.php + OrderCancellationLoyaltyTest.php
- T-F.2.4 NF525-adjacence : la remise fidélité passe par le chemin remise backend (PricingService SSOT intouché, frozen-diff 0) ; composition_snapshot intact ; Z-report bucketing remise cohérent (lecture seule, pas de modif fiscale).
  • test: grep/lecture PosRedemptionService + tests/Feature/Pos/PosLoyaltyRedeemTest.php (assertions totaux)

### Sub F.3 — Sync L2 & Setup (temps réel + administration)
**Anchors** : LoyaltyBalanceChanged.php, PersistLoyaltyBalanceChangedToOutbox.php, app/Enums/EventType.php (all() ⚠️), resources/js/services/eventContract.js, PosLoyaltyRedeemModal.vue (subscribe), LoyaltySetupComponent.vue, SetLoyaltyRatesCommand.php
**Tasks** :
- T-F.3.1 PREUVE LIVE 2-ONGLETS : modal POS ouvert (onglet A) + mouvement points (onglet B/tinker) → solde A rafraîchi SANS reload (soketi+worker e2e UP). Capture avant/après.
  • test: tests/js/posLoyaltyLiveBalance.spec.js (unit) + preuve browser (captures session)
- T-F.3.2 Chaîne outbox complète : dispatch → domain_events row → DispatchDomainEventsJob → 0 final failure ; EventType::all() contient loyalty.balance_changed (sentinel).
  • test: tests/Feature/Loyalty/LoyaltyBalanceChangedOutboxTest.php + tests/Feature/EventContractTest (filter EventContract)
- T-F.3.3 Setup round-trip : page admin Fidélité change 1→2 pts/€ → resource/config refléteNT live → remettre 1 ; garde min/validation côté LoyaltySetupRequest.
  • test: (TO BE CREATED si absent: tests/Feature/Loyalty/LoyaltySetupRoundTripTest.php) · visual: /admin/settings loyaltySetup
- T-F.3.4 Dégradation : soketi down → modal ne crashe pas, solde lookup reste juste (poll/refetch au réouvrir).
  • test: tests/js/posLoyaltyLiveBalance.spec.js (cas branchId=0) + inspection WebSocketService fallback

### Sub F.4 — QR, parité & consentement
**Anchors** : LoyaltyQrSigner.php, config/loyalty.php, LoyaltyConsent.php, api.php :1491/:1498, miroirs mobile/web
**Tasks** :
- T-F.4.1 QR signé : mint→scan OK ; plaintext FK:<code> rejeté (accept_legacy_plaintext=false) ; TTL expiré rejeté ; tampering rejeté.
  • test: tests/Feature/Sentinels/LoyaltyQrSigningSentinelTest.php + EnvExampleHasLoyaltyQrSecretSentinelTest.php
- T-F.4.2 Parité barème 3 sources (backend fallbacks + 2 miroirs) — déjà sentinel, re-run + valeurs DB seedées 1/100/100 à l'écran.
  • test: tests/Feature/Sentinels/LoyaltyRateParitySentinelTest.php · visual: /admin/settings loyaltySetup
- T-F.4.3 Consentement RGPD : opt-in explicite requis avant earn kiosk ; opt-out applique (plus d'accrual) ; consent ledger.
  • test: tests/Feature/KioskPhase1/{LoyaltyConsentTest,LoyaltyOptInEndpointTest}.php

## §4 Système D — Micro-audit dashboard (lentille, pas re-test)
**Lentille micro/caché/non-optimisé** (par page) : ① console/network 0 erreur au load ET après 1 interaction ; ② pagination/tri/recherche réels (pas no-op) ; ③ empty-state + error-state propres FR ; ④ N+1/latence (>2s au load = finding) ; ⑤ i18n résiduel EN/raw labels ; ⑥ boutons morts/dupliqués ; ⑦ formats FR (€, dates, 24h) ; ⑧ a11y évident (focus, labels) ; ⑨ data véridique (counts vs DB).
**Batches** (fan-out read-only, captures+DOM+console quartet) :
- D-B1 cœur gestion : dashboard, items, ingredients(×4 types), stock/rupture, categories-composer
- D-B2 commandes/argent : pos-orders(+tracker), online-orders, table-orders, historique, encaissement, cash-overview, cash-sessions-report, credit-balance-report, transactions
- D-B3 rapports/équipe : sales-report, items-report, administrators, employees, chefs, customers, delivery-boys(+cash-sessions), waiters
- D-B4 settings : ~20 onglets (company, site, branch, bornes, orderSetup, kioskSetup, currency, loyaltySetup, mail, notification, license, language, cookies, analytic…)
- D-B5 divers : observability(+outbox), profile×2, demo/wizard-launcher, kds, order-status-screen (smoke — déjà couverts ailleurs)
**Acceptance** : findings JSON par page (sév+preuve file:line/capture+repro) ; dédup vs lots A-H release/v1 + dashboard-deep 06-08 marquée.

## §5 Plan smart « tout le reste » (sortie de Wave 6)
Synthèse priorisée P0→P3 croisant : findings F+D de ce GOAL · backlog BRAIN §2 (gates UI/UX caisse-borne, heal W4-W6 G2/G5/G6/G7, INT-2, PRINT-1, Z-GAP-1 OVH) · fragmentation branches (CONSOLIDATED_STATE 06-09 : rien sur main, spines divergents) · data-ops owner (TIME_FORMAT, VAT, images) · disque 29Go. Livrable : `plans/PLAN_NEXT_BEST_2026-06-12.md` (≤15KB), revu par panel adversarial (3 juges : valeur-resto / risque-NF525 / coût-intégration) avant écriture finale.

## §A Armée d'agents
Workflow (ultracode) : lanes F.1-F.4 = agents finders read-only (code+DB e2e+harnais UI) → **chaque finding → verify adversarial indépendant (refuter, default-refuted)** ; D-B1..B5 = 1 agent/batch capture+lentille → verify léger sur P1+. Implémentation heals = orchestrateur SEUL (jamais 2 writers). RED dispute après chaque commit heal. Rapports persistés disque `reports/test-e2e/loyalty-validation-2026-06-12/` (survit aux interrupts).

## §X Vagues
| W | Scope | Parallélisme | Checkpoint |
|---|---|---|---|
| 1 | Preflight : :8767 up, token, suites loyalty PHPUnit+Vitest baseline ×1, soketi/worker e2e up | seq | suites 0 fail (sinon heal d'abord) |
| 2 | Lanes F.1-F.4 fan-out + adversarial verify | parallèle (read-only) | findings vérifiés, 0 halluciné |
| 3 | Heals F (TDD) + re-test + visual + RED dispute | seq (writer unique) | P0+P1 F = 0 |
| 4 | Micro-audit D-B1..B5 fan-out | parallèle (read-only) | quartet complet/page, dédup marquée |
| 5 | Heals D quick-wins (≤30min/item) + defer list | seq | P0+P1 D = 0 ou défendu |
| 6 | Plan smart + panel 3 juges | parallèle juges | plan ≤15KB adversarialement revu |
| 7 | Convergence ×2 (re-run suites + spot-checks UI), BRAIN/memory, commits | seq | 2 cycles identiques propres |
**Interrupt-resume** : commits `wip(WN)` + manifest `reports/test-e2e/loyalty-validation-2026-06-12/INTERRUPT_*.md` + BRAIN §2.

## §G Owner gates
| Gate | Description | WHO | WHAT | WHERE | Status |
|---|---|---|---|---|---|
| G-1 | TIME_FORMAT `.env` opérant h:i A→H:i | Owner physique | édit 1 ligne + restart serveurs | `.env:69` | PENDING (prouvé sur e2e) |
| G-2 | Push branche `heal/clients-next-2026-06-10` | Owner | ordre push explicite | remote | PENDING |
| G-3 | Purge 29Go worktrees `.claude/worktrees/` (30 dirs) | Owner (arbitre quelles sessions mortes) | liste + rm validé | disque Data 100% | PENDING |
| G-4 | Barème fidélité définitif (D11 défaut 1/100/100 appliqué-divulgué) | Owner | confirmation ou autre valeur via page admin/commande artisan | tools/decisions D11 | PENDING |
**Aucune vague 1-7 n'est bloquée par G-1..G-4** (tout tourne en local/e2e).

## §F Règle finale
DONE = fidélité attestée bout-en-bout (ledger, earn, redeem, reversal, sync live 2-onglets, QR, parité, consentement) avec preuves exécutées + micro-audit 40 pages quartet + heals P0/P1 verts ×2 cycles + plan smart adversarialement revu + BRAIN/memory à jour + frozen-diff 0 + commits explicites. Pas de « presque ».
