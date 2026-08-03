# Ultra-review système A — BORNE (kiosk)

HEAD audité : `61e9ea7b7` (working-tree AS-IS). Verify-before-report strict, lecture seule.

## Verdict : GREEN (production-perfect V1 LOCAL)

Aucun nouveau défaut réel P0/P1/P2. Tous les invariants borne confirmés par lecture code.

## Invariants confirmés (file:line)

1. **Auth machine Sanctum kiosk:order** — `MenuController.php:37`, `UpsellController.php:32`,
   `PaymentReconcileController.php:87` : `tokenCan('kiosk:order')`. Route order-store
   `routes/api.php:1334-1341` sous `auth:sanctum` + enforcement d'ability délégué à
   `OrderRequest::authorize()` (commentaire 1323-1333 : middleware route rejeté car
   CheckAbilities 401 sur guard-auth ; gap d'origine fermé côté FormRequest).

2. **buildCartItem distribue les viandes sur TOUS les attrs Viande N** — `KioskWizardComponent.vue:1778-1813`
   (frozen, read-only) : slots par unité, match par nom sous l'attribut cible, aplatissement
   objet→tableau `Object.values(item.variations).flat()` (fix 2026-06-30). Correct.

3. **Preview SSOT — 0 prix client, 422 silencieux** — `PricingPreviewController.php:20-22, 47, 62-68` :
   FormRequest strip prix/total/discount, `branch_id` lu depuis `KioskMachine`, aucune persistance,
   `InvalidArgumentException(422)` pour compo incomplète (renvoyé 422, pas d'event/log erreur).

4. **Plan B PENDING_COUNTER + COUNTER_DEFERRED** — `FrontendOrderService.php:219-221, 290-291` :
   `isCounterDeferredKioskCash` (KIOSK/TAKEAWAY + CASH_ON_DELIVERY) → `PaymentStatus::PENDING_COUNTER`
   + `PosPaymentMethod::COUNTER_DEFERRED`, auto-accept. Card/TR restent UNPAID (gate dispatch 250).

5. **Totaux client unset** — `FrontendOrderService.php:271` unset total/subtotal/discount ;
   création avec total/subtotal/discount = 0 (l.292-294) puis recalcul SSOT.

6. **Aucun bypass PricingService** — branche SSOT `:301-320` (`calculateOrder`) ET fallback
   `:321-379` recalculent TOUJOURS depuis prix DB (`$itemPrice = $dbItem->price` l.379,
   item/variation introuvable → `InvalidArgumentException(422)` l.373-378, 388-393, guard
   cross-item l.397-399). Le flag `pricing.use_ssot_service` (non atteignable en prod) ne crée
   PAS de chemin prix-client. Confirmé non-bypassable.

7. **Dispatch KDS immédiat cash / différé card** — `FrontendOrderService.php:250` truth-table :
   cash comptoir dispatche (cuisine démarre pendant que le client fait la queue), card/TR
   diffèrent jusqu'à `finalizePaidKioskOrder` (anti ghost-order KDS).

8. **File offline cash-only** — `kioskCart.js:768-794` : erreur réseau + paiement électronique
   → `KIOSK_OFFLINE_ELECTRONIC_PAYMENT_REFUSED` (rejet). Cash seul mis en file, champs financiers
   (quote/subtotal/discount/total/delivery) strippés avant replay, `branch_id` re-résolu serveur.

9. **Guard propriété ticket ESC/POS** — `OrderController.php:87-100` : escpos exige machine +
   `user_id == auth` + `branch_id == kioskMachine.branch_id` sinon 403. Pas de fuite cross-borne.

## Note (non P — défense-profondeur, déjà couvert par archi)

- `OrderController::show` (l.71-79) n'a pas de check `user_id` explicite ; l'isolation repose sur
  `BranchScope` global (kiosk branch>0 scopé). Une borne pourrait lire une commande d'une autre
  borne de LA MÊME branche (V1 = 1 branche, 1 poste → surface nulle). Pas un défaut V1 ;
  cloud/multi-poste = backlog. Non reporté comme finding.

## Preuves
git HEAD 61e9ea7b7 ; grep/Read cités ci-dessus ; frozen KioskWizardComponent lu read-only.
