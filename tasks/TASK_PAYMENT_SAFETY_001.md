# TASK_PAYMENT_SAFETY_001 — Paiement & Fidélité Safety

## Meta
- **Priority**: P0 (CRITICAL — risque financier)
- **PRIMARY_MODEL**: claude-sonnet-4-5-20250514
- **TEST_STRATEGY**: local-validation + playwright-critical-flow
- **DEPENDS_ON**: (none)
- **BLOCKS**: (none — indépendante)

## Constats couverts
| ID | Severity | Titre |
|----|----------|-------|
| F-03 | CRITICAL | TPE timeout → risque double-débit |
| F-04 | CRITICAL | Annulation commande ne rembourse pas les points fidélité |
| F-06 | MAJOR | Rendu monnaie non affiché (paiement cash POS) |

## Contexte

Deux constats critiques touchent la sécurité financière :
1. **F-03** : `PaymentController` attend la réponse TPE sans timeout. Si le réseau est lent, le caissier peut relancer → double-débit. Aucun mécanisme d'idempotency sur le paiement carte.
2. **F-04** : `OrderService::cancelOrder()` change le statut CANCELLED mais n'appelle jamais `LoyaltyService::refundPoints()`. Points perdus définitivement.
3. **F-06** : Le composant POS `PaymentComponent.vue` calcule le montant reçu mais n'affiche pas la monnaie à rendre.

## Scope

### SUBSYSTEMS_TOUCHED
- `app/Http/Controllers/Api/PaymentController.php` — timeout + idempotency
- `app/Services/OrderService.php` — ⚠️ FROZEN ZONE — ajout refund loyalty
- `app/Services/LoyaltyService.php` — méthode refundPoints()
- `resources/js/components/admin/pos/PaymentComponent.vue` — affichage monnaie
- `tests/Feature/PaymentControllerTest.php` — nouveaux tests
- `tests/Feature/OrderCancellationTest.php` — test refund points

### Hors scope
- Modification du driver de paiement (Stripe, terminal physique)
- Modification de la logique de calcul des points fidélité (barème)
- UI kiosk paiement (couvert par TASK_KIOSK_RELIABILITY_001)

## Étapes d'exécution

### E1 — TPE idempotency (F-03) ⚠️ GATE POSSIBLE
1. Ajouter un `payment_idempotency_key` (UUID) généré côté frontend avant soumission
2. Backend : vérifier si `payment_idempotency_key` existe déjà dans `payments` table
3. Si doublon → retourner le résultat existant (pas de nouveau débit)
4. Timeout explicite sur l'appel TPE : 30s max, puis erreur claire "Paiement en attente, ne pas relancer"
5. UI : désactiver le bouton "Payer" pendant le traitement, spinner visible

### E2 — Refund loyalty on cancel (F-04) ⚠️ FROZEN ZONE
1. Dans `OrderService::cancelOrder()`, après le changement de statut :
   ```php
   if ($order->loyalty_points_used > 0) {
       app(LoyaltyService::class)->refundPoints($order);
   }
   ```
2. `LoyaltyService::refundPoints()` : créditer les points, loguer la transaction
3. Test : créer commande avec points → annuler → vérifier solde restauré
4. **GATE REQUISE** : modification de OrderService.php (frozen zone)

### E3 — Affichage monnaie POS (F-06)
1. Dans `PaymentComponent.vue`, après saisie du montant reçu :
   ```
   Monnaie à rendre : {{ (amountReceived - orderTotal).toFixed(2) }} €
   ```
2. Afficher uniquement si `amountReceived > orderTotal`
3. Style : vert, gros caractères, bien visible pour le caissier

## Validation attendue

- [ ] `php artisan test` — 0 failures
- [ ] Test spécifique : double soumission paiement → une seule transaction
- [ ] Test spécifique : cancel order avec points → points remboursés
- [ ] POS : paiement cash → monnaie affichée correctement
- [ ] POS : bouton payer désactivé pendant traitement TPE

## Invariants
- Le calcul de prix reste backend-only (pas de modification)
- Les points fidélité sont toujours calculés par LoyaltyService (pas de shortcut frontend)
- OrderService frozen zone : uniquement l'ajout de l'appel refundPoints

## Gate
- **Gate requise** : OUI — modification de OrderService.php (frozen zone)
- Trigger : E2 (refund loyalty)
- Decision : ajout d'un appel LoyaltyService::refundPoints() dans cancelOrder()
- Risque : faible (ajout, pas modification de logique existante)
