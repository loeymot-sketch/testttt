# Audit adversaire — LOGIQUE paiement caisse (fractionné / rendu monnaie)

Date : 2026-07-11 · Branche `pos/category-first-caisse-2026-06-23` · HEAD `baaae7e82`
Méthode : lecture code + `tinker` (persistance réelle en transaction rollback, aucune donnée live modifiée).
Config live vérifiée : `split_payment.enabled=true`, `max_tranches=12`, `pos.simulation_hardware=true`, `walkin_route_to_counter=false`, `IDEMPOTENCY_MIDDLEWARE_ENABLED=true`.

Fichiers cibles : `SplitPaymentService.php`, `PaymentService.php`, `PaymentManagerService.php`,
`OrderService::posOrderStore`, `routes/api.php` counter-collect, `OrderDetailsResource`,
`OrderReceiptEscPosRenderer` (renderer/resource = lecture seule). `PaymentComponent.vue` /
`pos-wizard.js` = FROZEN, non ouverts (aucun fix proposé ne les touche).

---

## VERDICT : cœur monétaire SAIN. 2 P2 réels + 2 P3. Aucun P0/P1.

Somme=total, arrondi cents, sous-paiement rejeté, idempotence, statut/fiscal : **corrects et prouvés**.
Les défauts trouvés sont bornés (≤1€) ou d'intégrité d'affichage, pas de perte d'argent tiroir ni de double-paiement.

---

## FINDINGS

### F1 — [P2] Encaissement comptoir CASH n'exige PAS `reçu >= total` (asymétrie vs vente directe)
- **Repro** : `POST /counter-collect/{order}/confirm` (routes/api.php:867-878) valide `received: nullable|numeric|min:0` uniquement. `PaymentService::confirmCounterPayment` (PaymentService.php:352-354) pose `pos_received_amount = received ?? total` **sans garde `received >= total`**. La commande passe `PAID`, alloue le n° fiscal, et `recordCashOrderMovement` crédite le tiroir de `order->total` (pas de `received`).
- **Asymétrie prouvée** : le chemin vente DIRECTE caisse REJETTE `reçu < total` en 422 (OrderService.php:1129-1137). Le chemin comptoir ne le fait pas.
- **Impact** : un caissier peut valider PAID une commande en saisissant un « reçu » inférieur au total ; le tiroir est crédité du total complet → dérive de caisse possible. Nécessite une mésaisie caissier (l'UI calcule normalement le reçu). Cash, donc HORS « TPE simulé » — c'est bien une garde backend manquante, pas du by-design.
- **Fix (non-frozen)** : ajouter dans `confirmCounterPayment`, pour `mode===CASH`, `if received !== null && round(received*100) < round(total*100) → ValidationException`, miroir exact de OrderService:1129.

### F2 — [P2] Split : `change_amount` d'une tranche persisté depuis le client SANS recalcul serveur
- **Repro (tinker, prouvé)** : `persistTranches` avec tranche cash `amount=8.50, tendered=10.00, change=99.00` → **stocké `change_amount=99.00`** (réel = tendered−amount = 1.50). Seul `tendered >= amount` est validé (SplitPaymentService.php:104) ; `change` est repris verbatim (l.230-231, `$t['change'] ?? $t['change_amount'] ?? 0`).
- **Exposition** : `OrderDetailsResource::buildPaymentsBreakdown` l.191 renvoie le `change_amount` stocké pour les tranches split → le reçu à l'ÉCRAN (ReceiptComponent) peut afficher un RENDU faux. Le ticket ESC/POS physique n'est PAS touché (renderer `payments()` recalcule via `cash_back_amount`, OrderReceiptEscPosRenderer.php:548-575). Tiroir/fiscal intacts (tiroir crédité = `amount`, correct).
- **Sévérité P2** : intégrité d'affichage reçu seulement, aucun impact argent tiroir/fiscal.
- **Fix (non-frozen, SplitPaymentService)** : pour CASH, forcer `change = max(0, round(tendered - amount, 2))`, ignorer la valeur client.

### F3 — [P3, borné by-design] Tolérance de surpaiement 1€ appliquée à la SOMME des `amount`, tous modes
- **Repro (tinker, prouvé)** : total 10,00 → somme 10,99 **acceptée**, 11,50 rejetée ; TR 8€ pour total 7€ **accepté** (surplus 1€ non rendu). Donc `sum(order_payments.amount)` peut dépasser `order.total` de ≤1,00€, surplus non tracé.
- **Nuance** : légitime pour CASH (« gardez la monnaie »/pas de rendu fait) et TR (titres-restaurant : rendu monnaie interdit par la loi FR → surplus conservé = comportement réel). Pour CARD/MOBILE, autorise un sur-débit borné ≤1€ sans mécanisme de rendu.
- **Note réconciliation** : crée des états `sum(payments) != order.total` ; tout rapprochement supposant l'égalité doit tolérer ≤1€.
- **Action** : documenté comme tolérance intentionnelle. Surveiller ; envisager de restreindre la tolérance aux modes CASH+TR.

### F4 — [P3] `cash_back_amount` non borné ≥0 dans `OrderDetailsResource:120`
- `pos_received_amount - total` sans `max(0,…)` (le chemin split-fallback l.204 clampe, lui). Couplé à F1 (sous-tender comptoir), un rendu NÉGATIF peut apparaître dans le payload API. Cosmétique.

### F5 — [P3, info] Split multi-tender non détaillé sur le ticket ESC/POS fiscal
- `payments()` (renderer:548-575) imprime UNE ligne depuis `pos_payment_method`/`pos_received_amount` legacy, pas les N tranches. Le vrai détail vit dans `order_payments` + audit-log HMAC (enregistrement NF525 complet), donc intégrité fiscale OK ; ticket imprimé = simplification. Probablement by-design.

---

## COMPORTEMENTS CORRECTS VÉRIFIÉS (verts)

- **Somme >= total** : sous-paiement rejeté (`3+4=7 vs 10` → REJET), rollback transaction parente → aucun PAID prématuré.
- **Arrondi cents-safe** : total 7,43 = 3,72+3,71 accepté exact ; 3,71+3,71=7,42 rejeté ; 3,72+3,72=7,44 dans tolérance. Comparaison en `(int) round(x*100)` → zéro dérive flottante.
- **Idempotence** : create protégé par `IdempotencyKeyMiddleware` (enabled) ; `confirmCounterPayment` lock+idempotent : même caissier = no-op 200, autre caissier = 409 `PaymentAlreadyCollectedException` (PaymentService.php:279-312). Pas de double-paiement.
- **Rendu single-tender recalculé serveur** : `cash_back = pos_received_amount − total` (Resource:120) ; vente directe CASH rejette `reçu < total` (422).
- **CASH exige `tendered`, `tendered >= amount`** ; tiroir crédité de `amount` (pas `tendered`) → correct.
- **Statut/fiscal** : POS non-différé → `PAID` + n° fiscal à la création ; différé → `PENDING_COUNTER`, fiscal alloué seulement à l'encaissement (pas de n° fiscal prématuré sur non-payé).
- **Mixte cash+card+TR** persiste chaque tranche avec mode/amount/terminal_id corrects ; TR sans tendered/change → aucun rendu sur TR (conforme loi FR).

## BY-DESIGN (ne pas traiter comme bug)
- `simulation_hardware=true` : tranches CASH enregistrent `OrderPayment` + audit MAIS pas de `cash_movement` (pas de tiroir physique câblé). Attendu.
- TR sans rendu monnaie : conforme.
