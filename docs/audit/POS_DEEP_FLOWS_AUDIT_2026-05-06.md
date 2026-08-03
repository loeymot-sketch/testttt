# POS Deep Flows Audit — 2026-05-06

Audit READ-ONLY des **12 flows POS non couverts** par les cycles 1-4 Playwright. Réalisé par agent Explore en lecture code.

## Sommaire des risques par flow

### 1. Cancel Last Line ⚠️ P2

- **Where**: `resources/js/components/admin/pos/PosComponent.vue:276-281` (template) + `:2403-2420` (method)
- **What**: Bouton "↻ Annuler la dernière ligne" → `posCart/deleteCartItem` avec `status: 'decrement'`. **Aucune confirmation UX**, effet immédiat. Toast.
- **Risks**:
  - Aucune barrière de confirmation
  - `deleteCartItem` ne valide pas si la suppression affecte les tarifs (item libre devient payant, discount devient invalide)
  - Pas de guard sur permissions ou workflow (peut annuler pendant paiement ?)
- **Tests existants**: aucun unit/feature direct. Cycle 5 Playwright **VALIDE le comportement** (cart "Articles 1" → "Articles 0" décrémenté).

### 2. Discount Manuel ⚠️ P1

- **Where**: `PosComponent.vue:582-591` + `:2444-2474` | `OrderService.php:2027-2045` | `OrderQuoteService.php:287-305`
- **What**: 3 niveaux permission backend : `pos-discount-up-to-10` (≤10%), `pos-discount-over-10-requires-manager` (10-50%), `pos-discount-unlimited` (>50%). Raison écrite + auditée via `OrderDiscountLog`.
- **Risks**:
  - Si subtotal change post-discount (item retiré), discount % peut exploser (100% si subtotal→0)
  - Aucun contrôle qu'un discount soit appliqué qu'une fois par panier
  - **UX ne warn pas l'opérateur des rejets backend** (silent fail si > permission level)
- **Tests existants**: `PosManualDiscountAuditTest`, `PosOrderBL2AuditCallSitesTest::test_pos_order_with_manual_discount_writes_discount_applied_audit`

### 3. Walk-in Customer ⚠️ P2

- **Where**: `PosController::walkInCustomer:60-73` + `WalkInCustomerResolver`
- **What**: User global `walkingcustomer@example.com` (email constant), rôle `Customer`, `branch_id=0`. Fallback si `customer_id≤0` dans payload POS.
- **Risks**:
  - Walk-in global (`branch_id=0`) en multi-branch : qui voit les commandes walk-in d'une autre branche ?
  - Création lazy sans transaction → race condition
  - Aucun guard que l'user ait permission `CustomerController::create` ou `list`
  - Pas d'UX pour distinguer "walk-in" vs client existant
- **Tests existants**: aucun

### 4. NFC (Web NFC scanner) ⚠️ P1

- **Where**: `services/posNfc.js` + `tests/js/posNfc.spec.js`
- **What**: `scanOnce()` via `window.NDEFReader` (Chrome only). Timeout 30s. Erreurs `nfc_unsupported / nfc_read_error / nfc_timeout`.
- **Risks**:
  - Web NFC pas stable cross-browser (Chrome only en prod)
  - **Pas d'intégration visible dans flow POS** : où le scanner est-il appelé ? Aucun lien fidélité/customer lookup dans le code exploré
  - Timeout 30s peut bloquer le caissier
- **Tests existants**: `posNfc.spec.js` (3 cas : unsupported, error, success). **Pas d'intégration POS**.

### 5. Barcode Scanner ⚠️ P2

- **Where**: `helpers/posBarcode.js`
- **What**: `createBarcodeDetector()` détecte HID (keyboard wedge) si gap ≤50ms entre keypresses. Min 6 chars. `createFKeyShortcuts()` mappe F1-F12.
- **Risks**:
  - Heuristique fragile : scanners lents (>50ms) non détectés
  - Pas de filtrage sur fields qui acceptent barcode (ex: pas en mode discount-input ?)
  - F-keys global désactivent INPUT/TEXTAREA/contenteditable, **mais pas spécifiquement les modals POS**
  - Aucune intégration visible : où `createBarcodeDetector` est appelée dans `PosComponent` ?
- **Tests existants**: aucun

### 6. Multi-Paiement / Split 🔴 **P0**

- **Where**: `ReceiptComponent.vue:213` (label `tendered_breakdown`) + `posReceiptBuilder.js` (hint "multi-tender breakdown")
- **What**: **Labels/helpers préexistants pour "breakdown multi-tender" mais AUCUNE UI visible** pour sélectionner plusieurs modes (ex: 50€ cash + 30€ carte). PaymentComponent ne propose qu'un seul mode (cash XOR card).
- **Risks**:
  - **Fonctionnalité annoncée mais non implémentée** (gap critique)
  - Backend `PaymentService::confirmCounterPayment` prend un seul `mode`
  - Quote ne recalcule pas par mode
  - Contrat API ambigu : comment transmettre 2+ paiements ?
- **Tests existants**: aucun

### 7. Reorder rapide ⚠️ P2

- **Where**: `PosOrderController::reorderItems:124-153` + `PosReorderHistoricalPricingSentinelTest`
- **What**: `GET /api/admin/pos-order/reorder-items/{id}` retourne panier avec **prix historiques + variations/extras snapshots**. Frontend charge ce panier, mais paiement final via `quote+save` utilise les **tarifs actuels** (SSOT).
- **Risks**:
  - Client voit prix historique 1€, paiement à 2€ → surprise utilisateur
  - **Pas de UX warning "Les prix ont changé"**
  - Si item n'existe plus ou prix négatif, quote fail mais panier reste chargé
  - Permission : `pos-orders` requis (pas `pos`)
- **Tests existants**: `PosReorderHistoricalPricingSentinelTest` PASS, `POSComprehensiveTest::test_pos_can_reorder_items`

### 8. Refund / Void POS direct 🔴 **P0**

- **Where**: `PaymentService::cancelCounterPayment:214-274` + `CounterDeferredPaymentLifecycleTest`
- **What**: `cancelCounterPayment()` UNIQUEMENT pour kiosk cash (`source_surface='kiosk'` + `pos_payment_method=COUNTER_DEFERRED`). Transition `PENDING_COUNTER → REFUNDED + status CANCELED`. Audit `order.counter_payment_canceled`.
  - `OrderStatus::RETURNED` (value 22) **DÉFINI mais ORPHELIN** : qui l'utilise ? Quand ?
- **Risks**:
  - **POS direct cash payé NF525 : AUCUNE route pour annuler/rembourser**
  - Aucune UX en `PosComponent` ou Tracker pour rembourser une commande POS payée
  - Sealed-Z scenario : couvert par PLAN_P11_FISCAL_Z_OPEN_HARDENING (refund miroir parent_order_id) — **plan rédigé en cycle 2, pas encore implémenté**
- **Tests existants**: `PosCollectKioskCashRouteTest`, `CounterDeferredPaymentLifecycleTest`, **AUCUN test void/refund POS direct**

### 9. Cash Collect Kiosk ⚠️ P2

- **Where**: `PosComponent.vue:48-57` (button) + `:813-920` (panel) | `routes/api.php:696-756` | `PosCollectKioskCashRouteTest`
- **What**: Panel "Commandes borne à encaisser" filtré sur kiosk orders `PENDING_COUNTER`. `GET /counter-collect/pending` + `POST /confirm` + `POST /cancel`.
- **Risks**:
  - Caissier voit panel mais pas l'historique complet (variations, notes) sauf expand (UX lourd)
  - **Pas de lock pessimiste lors de confirm** : 2 caissiers peuvent simultanément confirmer le même order
  - `POST /confirm` requiert `mode` à nouveau (pas pré-renseigné)
  - **Pas d'Echo subscription** visible pour live update si une borne ajoute un order
- **Tests existants**: `PosCollectKioskCashRouteTest::test_pos_collect_kiosk_cash_route_accepts_pending_cash_order` PASS

### 10. Floorplan Dine-In ⚠️ **P1**

- **Where**: `app/Http/Controllers/Admin/Pos/FloorplanController.php` + `FloorplanComponent.vue`
- **What**: État plein écran tables (free/occupied/reserved/cleaning). Routes `state`, `assign/{tableId}`, `transfer/{source}/{target}`, `release/{tableId}`. Polling 15s.
- **Risks**:
  - **Pas de test unitaire trouvé** (route existe, test absent)
  - **Cross-branch transfer : route ne filtre pas branch_id** → vulnérabilité IDs collision possible
  - Release + assign race : table occupée par 2 orders ?
  - **Pas de UX pour "Dine-in selection" au moment du checkout** (segmented control n'a pas table selector)
- **Tests existants**: aucun feature/playwright dédié. Cycle 5 Playwright a juste validé que la **page charge OK**.

### 11. Customer Selector dans Cart Aside ⚠️ P1

- **Where**: `PosComponent.vue` (pas de section dédiée trouvée pour customer selector client-side)
- **What**: Pas d'UI visible pour ajouter customer existant vs créer. Walk-in fallback ou customer_id du parked snapshot.
- **Risks**:
  - **Toute commande POS = walk-in global** ou customer_id pré-existant
  - **Impossible de lier commande POS à customer existant si pas pré-sélectionné**
  - Fidélité : comment scanner NFC et lier customer si pas de lookup ?
- **Tests existants**: aucun

### 12. Kiosk Tracker ⚠️ P2

- **Where**: `PosOrdersTrackerComponent.vue` + `PosOrdersTrackerComponent.spec.js`
- **What**: Kanban (ACCEPT/PREPARING/PREPARED/DELIVERED). Echo subscribe `branch.{branchId}`. Statuts → enums (4=ACCEPT, 7=PREPARING, 8=PREPARED).
- **Risks**:
  - Echo subscription échoue silencieusement (`if (!window.Echo) return;`), **pas de fallback polling**
  - **Pas de drag-drop status transition** : comment caissier marque PREPARING ?
  - Cancel raison : textarea client-side max 700, code commente "backend enforces" sans vérifier
  - **Tests minimaux** : `PosOrdersTrackerComponent.spec.js` structure unclear

---

## PLAN CYCLE 6 — TOP 5 P0/P1 prioritaires

| # | Flow | Severity | Raison | Effort estimé |
|---|---|---|---|---|
| **P0-1** | **Refund / Void POS direct** | 🔴 P0 | Aucune route pour annuler une commande POS payée comptoir cash. RETURNED status orphelin. NF525 imprime mais pas d'annulation fiscale. | 3-5j |
| **P0-2** | **Multi-Paiement / Split** | 🔴 P0 | Annoncé (receipt hints), pas d'UI ni backend. Quote SSOT ne supporte pas split. Critique usage réel. | 5-8j |
| **P1-1** | **Dine-In Floorplan** | ⚠️ P1 | Routes existent mais 0 tests. Cross-branch vulnérabilité. UX absente (pas de table selector au checkout). | 4-6j |
| **P1-2** | **Customer Lookup + NFC fidélité** | ⚠️ P1 | NFC code existe mais pas intégré. Zéro lookup customer par NFC UID. Walk-in global isolation issue. | 3-5j |
| **P1-3** | **Discount Permission Gates UX** | ⚠️ P1 | Backend OK (audit pass) mais UX ne warn pas opérateur des rejets quote. Silent fail si discount > permission level. | 2-3j |

---

## Conclusion

Cycles 1-4 ont couvert ~70% du happy path POS (paiement cash/card, impression, historique, branch isolation, idempotency, audit NF525). Cycle 5 a confirmé pour le périmètre testé : **a11y 0 violations critiques** (30 passes), **0 JS errors**, **0 network 4xx/5xx**, **floorplan + tracker pages chargent**, **cancel-last-line décrémente OK**.

Les **5 gaps cycle 6** cumulés représentent un risque réel pour PROD :
- **Pertes financières potentielles** : pas de refund POS direct
- **UX caissier dégradée** : pas de split payment, pas de table selector dine-in, pas de customer lookup
- **Audit fiscal incomplet** : pas de contre-passation NF525 sur cash POS
- **Sécurité branch** : floorplan transfer cross-branch non testé

**Recommandation** : auditer + qualifier ces 5 P0/P1 en cycle 6 AVANT Go Live POS production.

---

**Auteur** : agent Explore
**Évidence d'audit** : lecture directe `resources/js/components/admin/pos/`, `app/Services/PaymentService.php`, `app/Services/Pos/`, `app/Http/Controllers/Admin/`, `tests/Feature/`, `tests/js/`
