# RUN — V14 #12 — T21 POS Receipt Redesign (NF525)

**TASK_ID:** `V14_12_T21_POS_RECEIPT_REDESIGN`  
**Date:** 2026-04-20  
**Statut:** **PASSED** (périmètre T21 ; régressions documentées hors scope inchangées)

## Schéma DB — `branches`

| Colonne        | Type            | Nullable |
|----------------|-----------------|----------|
| `siret`        | string(14)      | oui      |
| `vat_intra`    | string(16)      | oui      |
| `register_id`  | string(32)      | oui      |
| `legal_footer` | string(255)     | oui      |

Migration : `database/migrations/2026_04_20_210000_add_fiscal_identity_to_branches.php` (idempotente `Schema::hasColumn`, rollback `dropColumn` gardé).

## `OrderDetailsResource::toArray()` — champs ajoutés

- `fiscal_sequence_no`
- `audit_chain_fingerprint` — `substr(hash('sha256', current_hash|id), 0, 12)` sur le dernier `audit_logs` avec `resource = 'order'` et `resource_id = order.id` ; jamais le hash complet ni une clé HMAC ; `null` si pas de ligne / pas de `current_hash` / exception (schéma métier : pas de colonne `signature`, utilisation de `current_hash`).
- `pos_register_id`, `pos_siret`, `pos_vat_intra`, `pos_legal_footer` — depuis `branch`
- `operator_name` — depuis `user.name`
- `payments_breakdown` — si `Order::payments()` existe et retourne des lignes, mapping ; sinon synthèse unique `pos_payment_method` + `pos_received_amount` + monnaie (`max(received - total, 0)`), sans création de relation.

## Helper FE `resources/js/helpers/posReceiptBuilder.js`

1. `formatPaymentsBreakdown(order)` — priorité `payments_breakdown[]`, sinon repli `pos_payment_method` + montants encaissés / monnaie.
2. `buildNf525Footer(order)` — lignes `fiscal_ticket_no`, `audit_fingerprint`, `legal_mentions` si données présentes.
3. `receiptWidthClass(paperWidthMm)` — `receipt-80mm` si `>= 76`, sinon `receipt-58mm` (défaut 58).

## Tests

| Suite | Résultat |
|--------|----------|
| PHPUnit `--filter='Branch\|Receipt\|Pos\|Order'` | **380 passed**, **3 failed** (préexistants : `DispatchAfterCommitTest` ×2, `OrderAllergenSnapshotComposedTest` ×1 — non traités T21) |
| Nouveaux Feature `BranchFiscalIdentityTest` | **3/3** |
| Nouveaux Feature `PosReceiptFiscalExposureTest` | **4/4** |
| Régression `PosReceiptTaxLinesTest` | **vert** |
| Vitest `tests/js/posReceiptBuilder.spec.js` | **5/5** |
| Vitest `tests/js/PosComponent.spec.js` (régression) | **1/1** |
| **Vitest ciblés total** | **6/6** |

Aucune nouvelle régression identifiée au-delà des 3 échecs déjà connus sur le filtre ci-dessus.

## Fichiers créés ou modifiés

**Créés**

- `database/migrations/2026_04_20_210000_add_fiscal_identity_to_branches.php`
- `app/Services/Receipt/ReceiptDataService.php`
- `resources/js/helpers/posReceiptBuilder.js`
- `tests/Feature/Branch/BranchFiscalIdentityTest.php`
- `tests/Feature/PosReceiptFiscalExposureTest.php`
- `tests/js/posReceiptBuilder.spec.js`

**Modifiés**

- `app/Models/Branch.php`
- `app/Http/Resources/OrderDetailsResource.php`
- `resources/js/components/admin/pos/ReceiptComponent.vue`
- `resources/js/languages/fr.json`, `en.json`, `ar.json` (8 clés `label.*` chacun)

**Nombre total : 13 fichiers** (6 créés + 7 modifiés).

## Intégration future T15 (ESC/POS)

Le reçu HTML s’appuie sur `posReceiptBuilder` pour le détail paiements et le pied NF525 ; un cycle ultérieur pourra réutiliser ces mêmes fonctions pour générer des commandes ESC/POS (ex. `EscPosCommandBuilder`) sans dupliquer la logique d’assemblage.

## TODOs résiduels

1. **Relation `Order::payments()`** : à introduire dans un cycle dédié si persistance multi-tender réelle en base ; jusqu’alors repli synthétique unique.
2. **Traductions AR** : revue par locuteur natif (clés ajoutées de façon cohérente avec le ton existant).
3. **Empreinte audit** : requête basée sur `resource = 'order'` + `resource_id` ; si d’autres variantes de `resource` existent en prod pour le même order, enrichissement futur possible (hors T21).

## Conformité contraintes T21

- Aucune écriture dans `audit_logs`, `FiscalSequenceService`, `OrderService`, `PricingService`, `PaymentService`.
- Pas de calcul prix/TVA nouveau ; lecture des champs existants uniquement.
- Aucun touch : `PosComponent.vue`, `PaymentComponent.vue`, `ItemComponent.vue`, `app/Services/Hardware/*`, `posPrinter.js`, `kioskPrinter.js`, `PosOrderReceiptComponent.vue`.

---

`EXECUTE_DELEGATION: foodking-routine-implementer`
