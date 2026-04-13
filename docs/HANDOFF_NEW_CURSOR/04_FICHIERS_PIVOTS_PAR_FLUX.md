# Fichiers pivots par flux — où agir

## 1. Commande kiosk / web client

| Étape | Fichiers |
|-------|----------|
| Routes | `routes/api.php` (groupe `frontend`, throttle) |
| Contrôleur | `app/Http/Controllers/Frontend/OrderController.php` |
| Métier | `app/Services/FrontendOrderService.php` |
| Modèle | `app/Models/FrontendOrder.php` |
| Front | `resources/js/store/modules/kioskCart.js`, `KioskPaymentComponent.vue`, `KioskWaitingComponent.vue`, … |
| Idempotence | Header `X-Idempotency-Key` + lock cache côté service |

## 2. Commande POS / table

| Étape | Fichiers |
|-------|----------|
| Métier principal | `app/Services/OrderService.php` (fichier volumineux) |
| Modèle | `app/Models/Order.php` |
| Front POS | `resources/js/components/admin/pos/PosComponent.vue`, `PaymentComponent.vue` |
| KDS lié | Changements statut doivent continuer à émettre `OrderStatusChanged` |

## 3. Cuisine (KDS)

| Étape | Fichiers |
|-------|----------|
| Service | `app/Services/KitchenDisplaySystemOrderService.php` |
| Contrôleur | `app/Http/Controllers/Admin/KitchenDisplaySystemController.php` |
| UI | `KitchenDisplaySystemComponent.vue` |

## 4. Écran client (OSS)

| Fichiers | `OrderStatusScreenOrderService.php`, `PreparingAndReadyComponent.vue` |

## 5. Menu & catalogue kiosk

| Fichiers | `ItemController` (frontend), `kioskMenu.js`, `KioskAppComponent.vue` (Echo `ItemAvailabilityChanged`), helpers `resources/js/helpers/kiosk*.js` |

## 6. Fidélité

| Fichiers | `Frontend/LoyaltyController.php`, composants `KioskLoyaltyComponent.vue`, listener `AwardLoyaltyPointsOnDelivery` |

## 7. Auth / tokens

| Fichiers | `LoginController.php`, `RefreshTokenController.php`, `KioskMachineLoginController.php`, `ForgotPasswordController.php`, `config/sanctum.php` |

## 8. Config borne

| Fichiers | `config/kiosk.php`, `resources/views/master.blade.php` (injection `foodkingConfig`) |

## 9. Benchmark Splash (produit / UX, hors stack)

| Fichiers | `reports/planning/SPLASH_FOODKING_GAP_ANALYSIS_2026-03-27.md`, `KIOSK_SPLASH_BACKLOG_DEEP_PLAN_2026-03-27.md` |

---

**Astuce** : chercher `[SPLASH`, `[GAP-`, `[PHASE-` dans le code pour traces de décisions historiques.
