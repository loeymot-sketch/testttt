## A) Points ou j'adopte la position de l'orchestrateur

- KDS doit être borné par surface, pas seulement par `OrderStateMachine`: docs `DEVICE_FLOW.md:19-23`, `ORDER_FLOW.md:105-115`; code actuel laisse passer des statuts terminaux via `OrderStateMachine.php:42,49` et `KitchenDisplaySystemOrderService.php:150`.
- `payment-confirm` n’a pas de garde borne/TPE suffisante: route seulement `auth:sanctum` (`routes/api.php:889-895`) et contrôleur limité à ownership utilisateur (`OrderController.php:85-115`). Réponse garde borne: **NON**.
- Promo borne preview/checkout est incohérent: `kiosk_promo_code` sort du front (`kioskCart.js:26-37`) et de la preview (`PricingPreviewService.php:66-97`), mais pas de règle `OrderRequest` (`OrderRequest.php:35-68`) ni de consommation dans `FrontendOrderService` (`:216-227`).
- `OrderStatusRequest::authorize` est trop large pour une request partagée POS/KDS/frontend: Chef/POS/Cashier autorisés indistinctement (`OrderStatusRequest.php:23-31`).
- `OrderService` / `FrontendOrderService` doivent être traités en symétrie explicite avant tout patch lifecycle/pricing (`AGENTS.md:164-170`).
- `OrderService::list` utilise bien `LIKE` sur `branch_id` via filtre générique (`OrderService.php:61-72`, `:133-151`); je remonte ce point en P0 pour surfaces admin/report, avec nuance BranchScope.
- NF525 sealed-Z doit rester conditionnel: P0 si V1 inclut clôture fiscale réelle; P2 si V1 est strictement parcours opérationnel minimal hors clôture.
- `expected_status` client est nécessaire, mais je rejoins Claude: sans scénario de race qui passe malgré `lockForUpdate`, c’est P1, pas P0.

## B) Points ou je rejette ou nuance la contestation (Claude) - preuve

- Je conteste “front TPE continue vers waiting après échec `payment-confirm`”: `processCardPayment` attend `confirmBackendPayment` (`KioskPaymentComponent.vue:447-454`), celui-ci throw après 3 retries (`:562-576`), et `confirmPayment` catch sans navigation (`:341-390`). Tests JS: 8 pass.
- Je nuance: le vrai risque TPE restant n’est pas navigation erronée, mais absence d’état durable “TPE accepté, confirmation serveur échouée” avec reprise opérateur. P1/P0 selon exploitation.
- Je conteste la probable erreur sur POS cash via KDS: c’est exactement `axios.post("admin/kds-order/change-status/...")` (`PosComponent.vue:1414-1421`). La dette est réelle, même si priorité P1/P2 selon refonte route POS.
- Je conteste la correction complète du catch idempotency POS: precheck est scopé (`OrderService.php:580-586`), DB composite existe (`2026_04_18_140003...php:33-36`), mais le catch reste non scopé (`OrderService.php:1013-1018`). P1 + test concurrent, pas retrait.
- Je conteste la baisse forte du no-op side-effects: `OrderStateMachine::recordTransition` skip l’audit identité (`OrderStateMachine.php:92-94`), mais `OrderService::changeStatus` déclenche cashback/refund avant save (`OrderService.php:1558-1575`), et `PaymentService::cashBack` recrédite à chaque appel (`PaymentService.php:31-68`). P0 si endpoint staff exposé en V1.
- Je nuance `branch_id LIKE`: KDS est déjà corrigé/testé (`KitchenDisplaySystemOrderService.php:84-90`, `KdsBranchFilterExactTest.php:16-57`, test PASS). Le P0 restant cible `OrderService::list`, `show`, reports, admin `branch_id=0`.
- Je rejette P0 outbox stuck pour V1: le job remet `dispatched_at=null` en exception (`DispatchDomainEventsJob.php:140-151`) et retry existe (`OutboxRetryFailedCommand.php:21-35`). P2 surveillance sauf incident prod.
- Je nuance EventContract frontend: backend impose `branch_id`/`correlation_id` (`EventContract.php:81-129`), frontend ne valide pas ces champs strictement (`eventContract.js:23-45`). P2, pas P0.
- Je conteste P1 fort sur quantité variation preview comme financier: preview perd `variation.quantity` (`PricingPreviewService.php:152-155`), mais checkout réel garde les quantités (`PricingService.php:127-128`). P2 UX/pricing preview.
- Je nuance NF525: `destroy` est scellé (`OrderService.php:1804-1823`), `changeStatus`/`changePaymentStatus` ne le sont pas (`:1489-1656`, `:1661-1714`). P0 seulement si clôture Z dans V1.
- Je retire mon R1 “UNVERIFIED” sur `FrontendOrderService` promo: vérifié maintenant, `PricingRequest::forKiosk` ne reçoit que `couponId`, pas `kiosk_promo_code` (`FrontendOrderService.php:216-227`, `PricingRequest.php:90-107`).
- Je retire/abaisse mon R1 “TPE front continue”: preuve inverse ci-dessus; conserver seulement “reconciliation manquante”.

## C) Liste consolidee (unique) - P0 pour V1

1. `payment-confirm`: exiger token `kiosk:order`, `KioskMachine` liée, branche exacte, order type/method différés, transaction idempotente.
2. KDS: whitelist stricte `ACCEPT -> PREPARING`, `PREPARING -> PREPARED`; refuser `CANCELED`, `DELIVERED`, `RETURNED`.
3. `OrderStatusRequest`: politique par surface/route, plus `expected_status` obligatoire sur mutations KDS/POS sensibles.
4. `OrderService::list/show/report`: filtre branche exact, défense en profondeur hors seul `BranchScope`, suppression `LIKE` sur colonnes ID.
5. Promo borne: supporter réellement `kiosk_promo_code` au checkout ou retirer preview/payload.
6. No-op lifecycle: bloquer `same status` avant cashback, loyalty refund, audit, notifications.
7. Idempotency POS catch: lookup `(branch_id, idempotency_key)` dans le catch `23000`.
8. TPE accepted / backend confirm failed: état durable et reprise opérateur, pas seulement toast front.
9. Symétrie OrderService/FrontendOrderService: table obligatoire avant patch, surtout prix, taxes, coupons, idempotency, dispatch.
10. NF525 sealed status/payment: P0 seulement si V1 inclut clôture Z; sinon décision explicite “hors V1”.

## D) Plan d'implementation (pas de patch ici) pour V1

1. Auth/payment - écrire test `payment-confirm` non-kiosk refusé: preuve PHPUnit.
2. Auth/payment - ajouter garde ability `kiosk:order` + `KioskMachine`: preuve grep route/controller + test 403.
3. Auth/payment - vérifier branche, order owner machine, méthode CARD/TR, statut UNPAID/PENDING: preuve tests feature.
4. TPE/front - ajouter état “confirmation serveur échouée” sans waiting: preuve Vitest + Playwright borne.
5. KDS/request - ajouter `expected_status` rule: preuve PHPUnit 422 missing, 409 stale.
6. KDS/service - whitelist transitions cuisine: preuve test Chef `PREPARING -> CANCELED` attendu 422.
7. POS cash - remplacer appel `/admin/kds-order/...DELIVERED` par route POS explicite ou service POS: preuve grep `kds-order/change-status`.
8. Branch/list - corriger `OrderService::list` IDs en `=` et branch default actor: preuve test branch 1 vs 10.
9. Branch/show - policy `show` POS/online/table par branche: preuve tests 403 cross-branch.
10. Promo - choisir support ou retrait; si support, passer `kiosk_promo_code` dans request/service: preuve checkout total = preview.
11. Promo - consommer/incrémenter usage promo sous transaction: preuve test limite usage.
12. Pricing preview - préserver `variation.quantity`: preuve Vitest/PHPUnit preview vs checkout.
13. Idempotency POS - scoper catch par branche: preuve test concurrent/admin same key branch A/B.
14. Lifecycle - garde no-op avant effets financiers: preuve test double cancel ne crée qu’un cashback/refund.
15. Payment status - no-op/transaction/state machine paiement si inclus: preuve tests `PAID -> PAID`, `PAID -> UNPAID`.
16. Symmetry - produire table Order/Frontend mise à jour dans plan: preuve revue grep + tests miroir.
17. Events - vérifier dispatch hors transaction/outbox: preuve tests `OrderCreated`, `OrderStatusChanged`, domain_events.
18. E2E - jouer 5 scénarios Playwright max: preuve rapport `reports/antigravity/latest.md`.
19. Gate - si touche frozen/NF525/migrations, bloquer sans gate documenté: preuve `docs/gates/*`.

## E) Ce que le rapport final doit absorber (pour la synthese)

A fusionner absolument:
- `payment-confirm` sans garde borne est P0 confirmé.
- KDS doit être verrouillé par surface, indépendamment de la machine d’état globale.
- `OrderService::list` a un vrai risque `LIKE` sur `branch_id`; KDS n’a plus ce problème.
- Promo borne preview/checkout est P0 tant que support ou retrait n’est pas tranché.
- No-op side-effects peut doubler cashback/loyalty refund; preuve code maintenant vérifiée.
- Symétrie OrderService/FrontendOrderService doit devenir un livrable de plan.
- NF525 sealed-Z doit être une décision de scope V1, pas un P0 automatique.

A exclure:
- “Le front continue vers waiting après `payment-confirm` échoué” - faux dans le code actuel.
- “expectedFrom est un P0 prouvé” - non, P1 tant qu’aucune race passante n’est démontrée.
- “KDS branch LIKE actuel” - faux, KDS utilise `=` et test branch 1/10 passe.
- “Outbox claim est P0 V1” - faux sans incident; retry/reset existe.
- “DB idempotency branch-scope est cassée” - faux, index composite et tests passent.
- “EventContract frontend faible = P0” - faux; P2 parité/robustesse.
- “Variation quantity preview = P0 financier” - faux; checkout serveur reste SSOT.

## F) Reponse obligatoire a la SECTION E de Claude R2

1. `payment-confirm`: **admis**, preuve `routes/api.php:889-895`, `OrderController.php:77-115`; garde borne: **NON**. Implication **P0**.
2. KDS terminal: **needs_evidence** pour test HTTP exact Chef `CANCELED`; static proof: `OrderStatusRequest.php:23-31`, `OrderStateMachine.php:42,49`, `KitchenDisplaySystemOrderService.php:150`. Attendu invariant: 422; effectif probable: 202 si permission. Implication **P0**.
3. `expectedFrom`: **admis** en P1; test existant prouve 409 sur modèle stale (`KdsChangeStatusConcurrencyTest.php:21-80`, PASS), mais pas de race HTTP passante. Implication **P1**.
4. Identity side-effects: **conteste** la baisse; preuve complète `OrderService.php:1558-1575`, `PaymentService.php:31-68`, `LoyaltyService.php:27-71`; `recordTransition` seul skip no-op (`OrderStateMachine.php:92-94`). Implication **P0** si V1 expose annulation staff.
5. Catch idempotency POS: **conteste** le retrait; catch non scopé `OrderService.php:1013-1018`, precheck scopé `:580-586`, index composite `2026_04_18_140003...php:33-36`. Implication **P1**.
6. POS collect cash via KDS: **conteste** l’erreur Codex; preuve `PosComponent.vue:1414-1421`, URL `admin/kds-order/change-status/{id}`. Implication **P1**, devient **P0** si KDS whitelist est patchée sans route POS.
7. Promo borne: **admis**; présence `kioskCart.js:26-37`, preview `PricingPreviewRequest.php:46-48`, `PricingPreviewService.php:66-97`; absence checkout `OrderRequest.php:35-68`, `FrontendOrderService.php:216-227`. Implication **P0**.
8. `branch_id LIKE`: **admis** pour `OrderService`; preuve `OrderService.php:61-72`, `:133-151`; nuance BranchScope `BranchScope.php:27-39`; KDS exact `KitchenDisplaySystemOrderService.php:84-90`. Implication **P0**.
9. Symétrie: **admis**. Tableau:
   `pricing`: POS `PricingRequest::forPos` manuel/coupon (`PricingRequest.php:50-67`) vs kiosk `forKiosk` sans manuel/promo (`:90-107`) - écart.
   `validations`: `PosOrderRequest.php:47-87` vs `OrderRequest.php:35-68` - écart promo.
   `idempotency`: POS catch non scopé (`OrderService.php:1013-1018`) vs kiosk catch scopé (`FrontendOrderService.php:616-620`) - écart.
   `taxes`: deux passent `PricingService` (`OrderService.php:634-651`, `FrontendOrderService.php:216-227`) - globalement aligné.
   `coupons`: POS coupon/manual (`PricingService.php:247-260`) vs kiosk coupon seulement - écart.
   `after-commit`: dispatch hors transaction dans les deux (`OrderService.php:986-993`, `FrontendOrderService.php:597-607`) - aligné à auditer.
   Implication **P0**.
10. NF525 V1: **admis avec décision**: V1 opérationnelle minimale exclut fiscal légal complet; sealed-Z status/payment **P2**. Si orchestrateur/humain inclut clôture Z V1, rehausser **P0** (`OrderService.php:1804-1823` vs `:1489-1714`).
11. Sub-agent fallback: **admis**; cible pour tous P0 complexes = `codex-extension`; fallback `foodking-complex-implementer` seulement si CLI Codex indisponible (`AGENTS.md:111-119`). Implication **P0 process**.
12. E2E V1: **admis**, 5 scénarios max:
   POS cash crée order paid/accepted + outbox `OrderCreated` + Echo branch.
   POS card paid + fiscal/payment state + outbox.
   Borne TPE confirm: pending invisible KDS avant confirm, ACCEPT visible après.
   KDS preparing/prepared: Chef ne peut que ces transitions, Echo OSS/KDS reçu.
   Branch 1 vs 10: POS/KDS/list/show n’exposent jamais branch 10 sur filtre branch 1.
   Implication **P0 validation**.