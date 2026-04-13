# Review — Post-Stabilisation Readiness

**Date**: 2026-03-31  
**Agent**: Claude (Architect & Reviewer)  
**Verdict**: NEEDS_ANTIGRAVITY

---

## Findings

### 1. Les flux critiques métier sont stabilisés
- state machine kiosk paiement/KDS validée sur suites ciblées
- validation coupon/loyalty stabilisée
- isolation kiosk/admin/branch stabilisée
- CRUD admin critique réaligné sur les contrats réels

### 2. La synchronisation inter-surfaces est cohérente en headless
- `OrderCreated` et `OrderStatusChanged` sont propagés correctement dans les chemins critiques validés
- `OrderService` dispatch maintenant `OrderStatusChanged` sur le path client self-cancel
- `SyncComprehensiveTest.php`, `KDSFlowTest.php`, `KioskPaymentStateMachineTest.php` et `AntiGravityTest.php` sont verts

### 3. La validation globale PHP est désormais un problème de runner, plus de logique
- les suites PHP ciblées majeures passent individuellement
- les lots PHP passent via `scripts/run_php_feature_batches.sh`
- le run monolithique `php artisan test` reste sensible à la mémoire, malgré `memory_limit=512M`

### 4. Le frontend kiosk est plus propre
- `npm test` vert
- `npm run production` OK
- les warnings Vue du wizard ont été réduits par la sécurisation du mixin `kioskFormatPrice`

---

## Residual Risks

### Medium
- `php artisan test` complet n’est pas encore fiable comme unique commande de validation CI locale à cause de la mémoire
- `kiosk_auto=no` sur le runtime local empêche un parcours borne browser réellement autonome sans préparation runtime supplémentaire
- le vrai device flow (TPE, imprimante, tiroir) n’a pas encore été validé en environnement physique

### Low
- `KioskProductListComponent.vue` reste une surface legacy
- il reste une dette documentaire légère autour des contrats admin/HTTP historiques

---

## Validation Performed

### PHP vert ciblé
- `AddressSecurityTest`
- `AdminCrudComprehensiveTest`
- `AntiGravityTest`
- `AntiGravityFinalTest`
- `AntiGravityLoginRedirectionTest`
- `AntiGravityManualTest`
- `BranchScopeTest`
- `KDSFlowTest`
- `KioskScopeIsolationTest`
- `KioskSecurityTest`
- `SecurityComprehensiveTest`
- `SyncComprehensiveTest`
- `PosDiscountTest`
- `PosUITest`
- `LoyaltyApiTest`
- `KioskFrontendComprehensiveTest`
- `FrontendDiscountIntegrityTest`
- `KioskPaymentStateMachineTest`
- `MenuSeederTest`

### Validation par lots
- `bash scripts/run_php_feature_batches.sh all` → OK
- `bash scripts/profile_php_memory.sh` → rapport généré

### Frontend
- `npm test` → **108 passed**
- `npm test -- --run tests/js/KioskWizard.spec.js` → **66 passed**
- `npm run production` → **OK**

### Runtime config observée
- `broadcast=pusher`
- `queue=database`
- `kiosk_auto=no`

---

## Verdict

**NEEDS_ANTIGRAVITY**

Le socle code/tests est désormais robuste et les risques critiques initiaux ont été fortement réduits.  
Le bloc restant avant un vrai verdict production-ready n’est plus une dette logique majeure, mais la validation **browser/device** du tunnel borne réel avec environnement kiosk configuré et périphériques disponibles.

### Anti-Gravity encore recommandé
- Carte validée → `paymentConfirm` → apparition KDS en conditions réelles
- Carte refusée / timeout TPE → absence de ticket fantôme en cuisine
- Cash borne → apparition immédiate KDS + cash drawer
- Loyalty + coupon edge cases sur UI réelle
- Maintenance mode → pas d’auto-login parasite
- Validation broadcast/queue sur l’environnement effectif
