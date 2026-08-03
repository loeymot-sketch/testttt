# RAPPORT FINAL CONSOLIDÉ — Audit FoodKing V1 (2026-04-25)

**Scope V1 retenu** : V1 *opérationnelle minimale* = backend SSOT (prix/branch/status/events) + POS (cash & card) + Borne/Kiosk (TPE confirm) + KDS (PREPARING/PREPARED), sous Echo + outbox + branch isolation stricte. **NF525 fiscal complet (sealed-Z, clôture Z, archivage légal) = HORS V1**. Justification : convergence R2/R3 explicite et invariant AGENTS.md (correctness > scope) ; le fiscal légal est un livrable parallèle « V1 fiscale » à gate humain séparé.

---

## 1) Tableau d'arbitrage

| Thème | Validé (qui) | Contesté (qui) | Tranché (Claude final) | Priorité | Preuve attendue |
|---|---|---|---|---|---|
| `payment-confirm` garde borne/TPE | R1+R2+R3 admis : aucune garde borne | — | **P0 confirmé**. Route `auth:sanctum` only, pas de `kiosk:order`/KioskMachine. | P0 | Test PHPUnit refus non-kiosk ; `routes/api.php:889-895`, `OrderController.php:77-115` |
| KDS transitions terminales | R1 P0 ; R2 valide ; R3 admet | — | **P0 confirmé**. `OrderStateMachine.php:42,49` + `KitchenDisplaySystemOrderService.php:150` autorisent `CANCELED` via service KDS, doc `DEVICE_FLOW.md:21`/`ORDER_FLOW.md:107-109` interdit. | P0 | Test feature Chef → `PREPARING→CANCELED` doit être 422 (effectif probable 202) |
| `OrderStatusRequest` policy par surface | R1 P1 ; R2 réhausse P0 ; R3 admet | — | **P0 confirmé**. `OrderStatusRequest.php:23-31` autorise Chef/POS/Cashier indistinctement. | P0 | Test 403 par rôle/route group |
| `expected_status` client requis | R1 P0 ; R2 conteste → P1 ; R3 admet P1 | R1 surcoté | **P1**. `KdsChangeStatusConcurrencyTest.php:21-80` PASS ; pas de race HTTP passante démontrée. | P1 | Test 409 sur état stale + 422 si paramètre absent |
| `branch_id` LIKE `OrderService::list/show/report` | R1 P1 ; R2 réhausse P0 ; R3 admet | KDS faux positif (déjà `=`) | **P0 confirmé pour OrderService**, retiré pour KDS. `OrderService.php:61-72,133-151` LIKE générique ; KDS `:84-90` exact (`KdsBranchFilterExactTest.php:16-57` PASS). | P0 | Test admin branch 1 vs 10 cross-branch |
| Promo borne `kiosk_promo_code` | R1 P0/P1 ; R2 P0 ; R3 admet P0 | — | **P0 confirmé**. Preview présente (`kioskCart.js:26-37`, `PricingPreviewService.php:66-97`, `PricingPreviewRequest.php:46-48`), checkout absent (`OrderRequest.php:35-68`, `FrontendOrderService.php:216-227`, `PricingRequest.php:90-107`). | P0 | Décision V1 : **soit** câbler bout-en-bout (test preview = checkout), **soit** retirer preview/payload |
| No-op identity side-effects (cashback/loyalty) | R1 P0 (UNVERIFIED) ; R2 NEEDS_EVIDENCE ; R3 prouve et maintient P0 | R2 baissait à NEEDS_EVIDENCE | **P0 confirmé** si V1 expose annulation staff. Preuve R3 : `OrderService.php:1558-1575` déclenche cashback/refund avant save ; `PaymentService.php:31-68` recrédite à chaque appel ; `LoyaltyService.php:27-71` ; `OrderStateMachine::recordTransition:92-94` skip uniquement l'audit. | P0 | Test double cancel/retry → un seul cashback |
| Catch duplicate idempotency POS | R1 P1 (UNVERIFIED) ; R2 « erreur Codex » ; R3 conteste retrait, maintient P1 | R2 erronée | **P1 confirmé**. Precheck scopé `:580-586` + index composite OK ; catch `:1013-1018` reste non scopé, risque résiduel admin `branch_id=0`. | P1 | Test concurrent admin clé identique branch A/B |
| TPE accepted / `payment-confirm` failed | R1 P0 (UNVERIFIED) ; R2 NEEDS_EVIDENCE ; R3 retire le bug front | — | **Faux positif partiel + P1 résiduel**. R3 prouve : `processCardPayment` attend `confirmBackendPayment` qui throw après 3 retries (`KioskPaymentComponent.vue:447-454,562-576`), `confirmPayment:341-390` catch sans nav, **8 tests JS PASS**. Le navigation-bug est faux ; reste **P1 réconciliation** : pas d'état durable « TPE accepté, confirmation serveur en attente/échec » côté opérateur. | P1 | Vitest + Playwright : état explicite + reprise opérateur |
| POS « collect cash » via endpoint KDS | R1 P1 (UNVERIFIED) ; R2 « erreur probable » ; R3 maintient | — | **P1**. URL `axios.post("admin/kds-order/change-status/...")` confirmée `PosComponent.vue:1414-1421`. Devient **P0 dépendant** si la whitelist KDS est livrée sans route POS dédiée. | P1 (P0 conditionnel) | Grep `kds-order/change-status` + route POS dédiée |
| Symétrie `OrderService` / `FrontendOrderService` | R1 effleure ; R2 réhausse P0 ; R3 fournit tableau diff | — | **P0 confirmé**. Écarts R3 : pricing (`PricingRequest::forPos:50-67` manuel/coupon vs `forKiosk:90-107` sans), validations (`PosOrderRequest:47-87` vs `OrderRequest:35-68` promo absente), idempotency catch (POS non scopé `:1013-1018` vs kiosk scopé `FrontendOrderService.php:616-620`), coupons (POS coupon/manual vs kiosk coupon only). Taxes & after-commit alignés. | P0 | Tableau diff livré dans plan + tests miroir |
| NF525 sealed-Z `changeStatus`/`changePaymentStatus` | R1 P1 ; R2 conditionnel ; R3 décision V1=hors fiscal → P2 | — | **P2 (V1 opérationnelle)**. `destroy:1804-1823` scellé, `changeStatus:1489-1656`/`changePaymentStatus:1661-1714` non. Réhausse **P0 V1 fiscale** si scope inclut clôture Z. | P2 (V1 op) / P0 (V1 fiscale) | Test mutation refusée après Z, conditionnel |
| Outbox `dispatched_at` avant broadcast | R1 P1 ; R2 P2 ; R3 confirme P2 | — | **P2**. `DispatchDomainEventsJob.php:140-151` reset en exception + `OutboxRetryFailedCommand.php:21-35` rescue. | P2 | Surveillance + test stuck artificiel |
| `EventContract` frontend parité | R1 P2 ; R2 souligne asymétrie ; R3 maintient P2 | — | **P2**. Backend impose `branch_id`/`correlation_id` (`EventContract.php:81-129`), front laxiste (`eventContract.js:23-45`). | P2 | Test broadcast frontend rejette payload sans `correlation_id` |
| Variation quantity preview | R1 P1 ; R2 P2 ; R3 confirme P2 | R1 surcoté | **P2 UX**. Checkout serveur reste SSOT (`PricingService.php:127-128`), preview perd qty (`PricingPreviewService.php:152-155`). | P2 | Vitest preview = checkout sur multi-qty |
| `OrderTableChanged` outboxé | R1 robuste | — | **OK**, surveillance. | — | — |
| `OrderStatus` enum + after-commit + outbox + branch channel | R1 robuste ; R2 confirme ; R3 confirme | — | **OK acquis**. | — | `DispatchAfterCommitTest.php:54-85` PASS, `IdempotencyBranchScopedTest.php:20-71` PASS |

---

## 2) P0 V1 — ordre d'exécution

> Routing primaire pour **tous P0** : `codex-extension`. Fallback `foodking-complex-implementer` **uniquement si CLI Codex indisponible** (AGENTS.md:111-119).

**P0.1 — `payment-confirm` blindé borne/TPE**
- Objectif : refuser tout `payment-confirm` hors contexte borne (token `kiosk:order` + `KioskMachine` liée + branche exacte + `payment_method` ∈ {CARD,TR} + statut `UNPAID/PENDING`).
- Surfaces : `routes/api.php:889-895`, `app/Http/Controllers/Frontend/OrderController.php:77-115`, middleware kiosk (à créer ou réutiliser KioskMachine resolver).
- Preuve : test PHPUnit `payment-confirm` Sanctum non-kiosk → 403 ; kiosk valide → 200 + transition `PAID`.
- Routing : `codex-extension`.

**P0.2 — KDS whitelist transitions stricte**
- Objectif : `KitchenDisplaySystemOrderService` refuse tout sauf `ACCEPT→PREPARING` et `PREPARING→PREPARED`. Pas de `CANCELED`/`DELIVERED`/`RETURNED` côté KDS.
- Surfaces : `app/Services/KitchenDisplaySystemOrderService.php:150-179`, `app/Domain/Order/OrderStateMachine.php:37-49` (whitelist par appelant ou enum couloir).
- Preuve : test feature rôle Chef appelle `CANCELED` sur `PREPARING` → 422.
- Routing : `codex-extension`.

**P0.3 — `OrderStatusRequest` policy par surface + `expected_status` (couplé P1.1)**
- Objectif : `authorize()` discrimine par route group (POS / KDS / borne) ; couloirs alignés avec `DEVICE_FLOW.md`.
- Surfaces : `app/Http/Requests/OrderStatusRequest.php:23-49`, route groups, policies.
- Preuve : matrice de tests rôle×route×status, 403 hors couloir.
- Routing : `codex-extension`.

**P0.4 — Filtre `branch_id` exact `OrderService::list/show/report`**
- Objectif : remplacer le filtre générique `LIKE` par égalité stricte sur les colonnes ID ; défense en profondeur hors `BranchScope` ; admin `branch_id=0` ne fuit pas branch 1↔10.
- Surfaces : `app/Services/OrderService.php:61-72,133-151`, `app/Models/Concerns/BranchScope.php:27-39`, controllers admin/report.
- Preuve : test cross-branch `OrderService::list(branch=1)` ne renvoie aucune order branch 10 ; idem `show`/`report`.
- Routing : `codex-extension`.

**P0.5 — Promo borne : décision `kiosk_promo_code` (support OU retrait)**
- Objectif : trancher V1. **Recommandation par défaut** : *retirer* preview/payload tant que support complet n'est pas câblé (preview ≠ facturé est une violation SSOT prix). Si support, ajouter règle `OrderRequest`, consommation `FrontendOrderService::store`, décrémentation usage sous transaction.
- Surfaces : `OrderRequest.php:35-68`, `FrontendOrderService.php:216-227`, `PricingRequest.php:90-107`, `kioskCart.js:26-37`, `PricingPreviewService.php:66-97`.
- Preuve : test contractuel preview total === checkout total, ou absence de `kiosk_promo_code` dans payload.
- Routing : `codex-extension` (décision scope = humain avant exec).

**P0.6 — Garde no-op identity avant side-effects financiers**
- Objectif : bloquer cashback/loyalty refund/notifications quand `oldStatus === newStatus` ou retry terminal idempotent.
- Surfaces : `app/Services/OrderService.php:1548-1575`, `app/Services/PaymentService.php:31-68`, `app/Services/LoyaltyService.php:27-71`.
- Preuve : test double cancel/retry → un seul cashback enregistré ; test `PAID→PAID` n'invoque pas `cashBack`.
- Routing : `codex-extension`.

**P0.7 — Symétrie `OrderService` / `FrontendOrderService` (livrable plan)**
- Objectif : tableau diff exhaustif (pricing, validations, idempotency catch, taxes, coupons, after-commit) + alignement des écarts P0 (catch idempotency, coupons/manual côté kiosk si V1 le décide).
- Surfaces : services + requests des deux côtés (refs précises section 1).
- Preuve : tableau dans plan V1 + tests miroir POS/Kiosk sur cas pivots.
- Routing : `codex-extension`.

---

## 3) P1 / P2

### P1 (V1 souhaitable, non bloquant gate)
- **P1.1** `expected_status` obligatoire mutations KDS/POS sensibles → 422 missing, 409 stale (`KitchenDisplaySystemOrderService.php:122-148`).
- **P1.2** TPE *accepted* + backend confirm *failed* : état durable « confirmation serveur échouée » + reprise opérateur (pas seulement toast). Vue front + endpoint reprise.
- **P1.3** Catch duplicate idempotency POS scopé `(branch_id, idempotency_key)` (`OrderService.php:1013-1018`). Test concurrent admin branch=0.
- **P1.4** POS « collect cash » : route POS dédiée remplaçant `admin/kds-order/change-status` (`PosComponent.vue:1414-1421`). **Conditionnel P0** si P0.2 livré sans cette route.

### P2 (dette utile, hors V1)
- **P2.1** Outbox `claimed_at` séparé de `dispatched_at` + monitoring stuck (`DispatchDomainEventsJob.php:65-86`).
- **P2.2** EventContract frontend parité backend (`correlation_id`/`branch_id`) (`eventContract.js:23-45`).
- **P2.3** Variation `quantity` preview (`PricingPreviewService.php:152-155`).
- **P2.4** NF525 sealed-Z `changeStatus`/`changePaymentStatus` (`OrderService.php:1489-1714` vs `:1804-1823`) — **bascule P0 V1 fiscale** si scope inclut clôture Z.
- **P2.5** KDS sync version `status_changed_at` TODO (`KdsSyncService.php:126-142`).
- **P2.6** Store KDS guard `payload.vuex` (`kitchenDisplaySystemOrder.js:20-29`).

---

## 4) Faux positifs écartés

- **« Le front borne navigue vers waiting après `payment-confirm` échoué »** — Faux. R3 prouve que `confirmBackendPayment` throw après 3 retries et `processCardPayment` attend ; 8 tests JS PASS (`KioskPaymentComponent.vue:447-454,562-576`). Reste seulement P1 réconciliation opérateur.
- **« KDS branch filter LIKE actuel »** — Faux. KDS utilise `=` (`KitchenDisplaySystemOrderService.php:84-90`) ; `KdsBranchFilterExactTest.php:16-57` PASS. Le P0 LIKE concerne `OrderService`, pas KDS.
- **« Outbox stuck claim = P0 V1 »** — Faux sans incident prod. Reset exception (`DispatchDomainEventsJob.php:140-151`) + `OutboxRetryFailedCommand.php:21-35`. P2.
- **« DB idempotency branch-scope cassée »** — Faux. Index composite + `IdempotencyBranchScopedTest.php:20-71` PASS.
- **« EventContract frontend faible = P0 »** — Faux. Backend strict, frontend laxiste = P2 parité.
- **« Variation quantity preview = P0 financier »** — Faux. Checkout serveur SSOT (`PricingService.php:127-128`).
- **« `expectedFrom` KDS = P0 race silencieuse »** — Faux. `lockForUpdate` + abort 409 traçé ; `KdsChangeStatusConcurrencyTest.php:21-80` PASS. Manque seulement contrat client = P1.
- **« Catch duplicate idempotency POS = erreur Codex à retirer »** — Faux. Le catch `:1013-1018` est bien non scopé ; precheck scopé ne couvre pas la race concurrente. Reste P1.

---

## 5) Risque résiduel + preuves attendues

| Risque | Pourquoi il reste ouvert | Preuve minimale | Bloquant V1 ? |
|---|---|---|---|
| KDS Chef peut envoyer `CANCELED` sur `PREPARING` | Static proof code+doc convergent, mais test HTTP rôle Chef *non exécuté* dans R3 | Feature test PHPUnit Chef → 422 | **OUI** (P0.2) |
| `payment-confirm` exploité par Sanctum non-kiosk | Static proof aucune garde, exploit non démontré | Test PHPUnit Sanctum non-kiosk → 403 | **OUI** (P0.1) |
| `OrderService::list` LIKE expose cross-branch admin `branch_id=0` | Statique confirmé (`:61-72,133-151`), pas de test cross-branch HTTP | Test admin branch 1 ne voit aucune order branch 10 | **OUI** (P0.4) |
| Cashback double sur retry terminal staff | Code paths confirmés R3 (`OrderService:1558-1575`, `PaymentService:31-68`, `LoyaltyService:27-71`), exploit retry non capturé | Test double cancel/retry idempotent | **OUI** (P0.6) |
| Promo borne preview ≠ checkout | Code confirmé, écart fonctionnel non démontré sur transaction réelle | Test contractuel preview→store | **OUI** (P0.5) — décision scope préalable |
| Symétrie OrderService/FrontendOrderService écarts pricing/coupons | Tableau R3 partiel ; impact financier non mesuré sur tous chemins | Tableau diff complet + tests miroir | **OUI** (P0.7) |
| TPE confirm failed → opérateur sans reprise | Bug navigation faux, mais aucune UX reprise documentée | Vitest état + Playwright reprise | NON (P1.2) |
| Catch duplicate POS non scopé branch | Race admin `branch=0` plausible non démontrée | Test concurrent | NON (P1.3) |
| NF525 sealed-Z status/payment | Décision scope V1 = hors fiscal | Conditionnel | NON V1 op / OUI V1 fiscale |
| Outbox claim stuck après crash process | Aucun incident prod cité | Monitoring + chaos test | NON (P2.1) |

---

## 6) Tests et preuves déjà vus (R1–R3)

| Test | Statut | Source |
|---|---|---|
| `tests/Feature/DispatchAfterCommitTest.php:54-85` | PASS (cité R1) | After-commit dispatch |
| `tests/Feature/Orders/IdempotencyBranchScopedTest.php:20-71` | PASS (cité R1, R3) | Idempotency `(branch,key)` |
| `tests/Feature/KdsBranchFilterExactTest.php:16-57` | PASS (cité R1, R3) | KDS branch `=` strict |
| `tests/Feature/KdsChangeStatusConcurrencyTest.php:21-80` | PASS (R3) | 409 sur modèle stale (preuve `expected_status` = P1) |
| `tests/Feature/KioskPaymentStateMachineTest.php` (≥4 cas dont `test_card_order_stays_pending_until_payment_confirm`, `test_payment_confirm_can_finalize_an_already_paid_but_pending_kiosk_order`) | Cité R3 trace | Couvre lifecycle borne pending→paid mais **pas** garde non-kiosk |
| Vitest `KioskPaymentComponent` — 8 cas | PASS (R3) | Réfute le bug navigation TPE |
| `tests/Feature/KioskPhase7/KioskEventBranchIsolationTest.php` | Cité trace | Branch isolation `kiosk/event` |
| `tests/Feature/SyncComprehensiveTest.php` | Cité trace | Sync KDS/admin |

**Manquent explicitement** (preuves R3 admises non-exécutées) :
- Test HTTP Chef `PREPARING→CANCELED` → 422 attendu.
- Test HTTP `payment-confirm` Sanctum non-kiosk → 403 attendu.
- Test HTTP cross-branch `OrderService::list` admin branch 1 vs 10.
- Test double cancel/retry pour preuve cashback non-rejoué.
- Test contractuel preview borne = checkout (avec `kiosk_promo_code`).
- Suite Playwright V1 (5 scénarios) : non exécutée dans cette boucle.

---

## 7) Décision finale

Justification courte : 6 P0 bloquants V1 sont tranchés et cohérents entre R2 et R3, mais **4 d'entre eux reposent sur preuve statique sans test HTTP/feature exécuté** (KDS Chef CANCELED, `payment-confirm` non-kiosk, `OrderService::list` cross-branch, no-op cashback double). La règle CLAUDE.md §11 (« Real evidence > confidence ; if missing, prefer heal/block/human ») et §8 (« evidence too weak → human gate ») imposent de capturer ces preuves *avant* d'écrire le plan d'implémentation V1, sinon le plan partira sur des P0 conjecturaux. La décision scope NF525 (V1 op vs V1 fiscale) est en outre une décision humaine non encore prise.

`CONSOLIDATED_VERDICT: NEEDS_EVIDENCE`
