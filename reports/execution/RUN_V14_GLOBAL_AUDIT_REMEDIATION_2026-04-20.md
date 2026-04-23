# RUN — V14 Audit Global 4 Vagues + Remédiation cross-vagues

EXECUTE_DELEGATION: (orchestrateur Claude + sub-agents explore / complex / routine — rétro-signature B17 2026-04-23)

**TASK_ID:** `V14_GLOBAL_AUDIT_REMEDIATION_2026-04-20`
**Date:** 2026-04-20
**Cycle:** V14 — `FINALISATION_POS_BASE` (4 vagues A + B + C-α + C-β fermées + audit transverse + corrections)
**Statut final:** **PASSED — 1 P0 cross-vagues fixé + 1 P1 cross-vagues fixé + 1 sentinel cross-wave A↔C-β + 0 régression**

---

## 1. Contexte

Cet audit fait suite aux 4 audits par-vague :

| Vague | Rapport |
|---|---|
| A (T01+T05+T07) | `RUN_V14_T05_T07_FUSED_PRICING_SNAPSHOT_2026-04-20.md` |
| B (T02+T04+T06+T20) | `RUN_V14_VAGUE_B_AUDIT_200_2026-04-20.md` |
| C-α (T08+T10+T11) | `RUN_V14_VAGUE_C_ALPHA_AUDIT_200_2026-04-20.md` |
| C-β (T15+T19+T21) | `RUN_V14_VAGUE_C_BETA_AUDIT_200_REMEDIATION_2026-04-20.md` |

Chacune était PASSED individuellement après remédiation. **Mais** cet audit transverse a cherché les écarts inter-vagues (interactions silencieuses qu'aucun audit isolé ne pouvait voir).

Rapport complet : `reports/audit-orchestration/AUDIT_GLOBAL_4_VAGUES_V14_2026-04-20.md`.

---

## 2. Findings cross-vagues

### G-1 (P0) — Receipt template ne consomme pas `composition_snapshot`
**Vagues impliquées** : A (T07 snapshot) ↔ C-β (T21 receipt)

**Bug invisible** : `OrderItemResource::resolveVariationsForApi()` (T07) renvoie `[{variation_id, attribute_name, variation_name, quantity, unit_price}]` quand le snapshot existe. Le template lisait `variation.name` — qui n'existe PAS dans cette shape — donc le reçu imprimait `Viande:` (vide) ou `undefined` pour toute commande post-T07.

**Conséquence NF525** : reprint d'un reçu après rename d'une variation imprimait `undefined` au lieu de l'ancien nom historique gelé en BD → l'objectif d'immutabilité fiscale T07 était brisé en sortie UI.

### G-2 (P1) — Pas de `quantity ×` par variation au rendu
**Vagues impliquées** : A (T05 multi-qty) ↔ B (T02 UI multi-qty) ↔ C-β (T21 receipt)

**Bug** : pour un tacos 4 viandes (3× Steak + 1× Poulet), le reçu imprimait `Viande: Steak, Viande: Poulet` (sans la quantité par variation). La quantité multi-qty livrée par les vagues A et B disparaissait au moment du print.

### G-3 (P2) — `posParked.recall` ne checke pas l'indispo au niveau variation
**Vagues impliquées** : A (T05) ↔ C-α (T08 parked)
**Statut** : reportée Vague D (intersection avec T03 parité POS↔Kiosk).

---

## 3. Fixes appliqués

### F-G1 (P0) + F-G2 (P1) — Receipt template snapshot-aware + multi-qty

#### Helpers ajoutés
**`resources/js/helpers/posReceiptBuilder.js`** :
- `normalizeReceiptVariations(raw)` — accepte les 3 shapes (snapshot post-T07 array, legacy array `{id, variation_name, name}`, very legacy keyed-object `{attrId: {variation_name, name}}`) et retourne `[{label, name, quantity}]`.
- `normalizeReceiptExtras(raw)` — idem pour extras (snapshot `{extra_id, name, quantity}` + legacy `{name}`).

#### Template adapté
**`resources/js/components/admin/pos/ReceiptComponent.vue`** :
- Imports : `normalizeReceiptVariations`, `normalizeReceiptExtras` depuis `posReceiptBuilder`.
- Méthodes : `receiptVariationsFor(item)`, `receiptExtrasFor(item)`.
- Bloc variations / extras : iteration via les helpers, ajoute `<template v-if="quantity > 1">{{ quantity }}× </template>` devant le nom.

**Garanties** :
- ✅ NF525 immutability : si `composition_snapshot` présent, le reçu lit le nom historique (ne suit jamais une rename ultérieure de variation).
- ✅ Backward-compat : commandes legacy (snapshot=null) rendues identiquement à la version pré-V14.
- ✅ Defensive : `null`, `undefined`, garbage → `[]` (pas de crash template).
- ✅ Multi-qty : `3× Steak` rendu correctement quand snapshot porte `quantity > 1`.

### F-G1B (P1) — Sentinels cross-wave A↔C-β

#### Frontend (Vitest)
**`tests/js/posReceiptBuilder.spec.js`** : **+6 tests** (5 → 11)
1. `normalizeReceiptVariations reads snapshot lines (T07 NF525 immutability)` — verrou shape snapshot post-T07
2. `normalizeReceiptVariations reads legacy item_variations shape` — verrou backward-compat array legacy
3. `normalizeReceiptVariations reads keyed-object legacy shape` — verrou very legacy keyed-object
4. `normalizeReceiptVariations returns [] on null/undefined/garbage` — verrou défensif
5. `normalizeReceiptVariations preserves quantity > 1 (multi-qty)` — verrou G-2 multi-qty
6. `normalizeReceiptExtras reads snapshot + legacy + null` — verrou extras

#### Backend (PHPUnit)
**`tests/Feature/PosReceiptFiscalExposureTest.php`** : **+1 test cross-wave** (4 → 5)
- `test_order_item_resource_returns_snapshot_lines_for_receipt_consumption` :
  - Crée un `OrderItem` avec `composition_snapshot` peuplé (3× Steak + 1× Poulet + 2× Cheddar).
  - Vérifie que `OrderItemResource` expose `item_variations` au format snapshot (clés `attribute_name`, `variation_name`, `quantity`).
  - Verrou de contrat : tout futur refactor qui briserait cette shape ferait échouer ce test → la régression NF525 ne pourrait pas atteindre prod.

---

## 4. Tests — résultats finaux

### Vitest POS suite complète (18 fichiers)
```bash
$ npx vitest run tests/js/pos*.spec.js tests/js/PosComponent.spec.js
 Test Files  18 passed (18)
      Tests  108 passed (108)
```
**Avant fixes** : 102/102. **Après fixes G-1/G-2** : **108/108** (+6 sentinels).

### PHPUnit Pricing|Pos|Floorplan|Printer|Composition|Snapshot|Receipt
```bash
$ php artisan test --filter='Pricing|Pos|Floorplan|Printer|Composition|Snapshot|Receipt'
Tests:  1 failed, 3 skipped, 200 passed
```
**Avant fixes** : 199 passed. **Après fixes** : **200 passed** (+1 sentinel cross-wave).

L'unique échec restant (`OrderAllergenSnapshotComposedTest::it_includes_extras_codes_into_snapshot_when_extras_present`) est documenté `FINDING_BACK_DEFERRED` (pré-existant, hors V14, intersection avec W3 allergens extras merge — gate humain dédié hors scope).

---

## 5. Fichiers livrés

### Code applicatif
- `resources/js/helpers/posReceiptBuilder.js` — +2 helpers (normalize variations + extras)
- `resources/js/components/admin/pos/ReceiptComponent.vue` — template + script (imports + 2 méthodes)

### Tests
- `tests/js/posReceiptBuilder.spec.js` — +6 sentinels (5 → 11)
- `tests/Feature/PosReceiptFiscalExposureTest.php` — +1 sentinel cross-wave (4 → 5)

### Reporting
- `reports/audit-orchestration/AUDIT_GLOBAL_4_VAGUES_V14_2026-04-20.md` — audit transverse complet + plan Vague D
- `reports/execution/RUN_V14_GLOBAL_AUDIT_REMEDIATION_2026-04-20.md` — ce rapport

---

## 6. Vague D — plan proposé (pour arbitrage humain)

### Vague D-α (parallélisable, 0 gate, ~2 jours)
| Tâche | Subagent | Couvre |
|---|---|---|
| **T03** parité POS↔Kiosk + couvre G-3 | Composer | finding G-3 inclus |
| **T12** perf perceived (skeleton, optimistic add, virtual scroll) | Composer | UX caisse |
| **T18** a11y POS operator (focus order, ARIA, live regions) | Composer | conformité |

### Vague D-β (parallélisable, 0 gate, ~2 jours)
| Tâche | Subagent | Dépend |
|---|---|---|
| **T13** KDS station filter + sound | Composer | — |
| **T14** KDS bump bar + recall + escalation | Composer | — |
| **T16** hardware drawer + NFC fidélité | Composer | T15 ✅ |

### Vague D-γ (gates pendants — ne PAS lancer sans humain)
| Tâche | Bloqueur |
|---|---|
| **T22** E2E tacos 4 viandes complet | T17 (gate C9 + G14-B) |
| **T09** discount/void per-line NF525 | gate G14-B humain |
| **T17** payment resilience | gate C9 + G14-B humain |

---

## 7. Verdict

**STATUS: CLOSED — PASSED — AUDIT GLOBAL 4 VAGUES COMPLET**

- **4 vagues** (A + B + C-α + C-β) **individuellement** validées 200 % (rapports antérieurs).
- **Audit transverse** : 3 écarts inter-vagues détectés (1 P0 + 1 P1 + 1 P2).
- **2 fixes critiques** (G-1 + G-2) appliqués dans cette même passe.
- **7 sentinels** ajoutés (6 Vitest + 1 PHPUnit cross-wave A↔C-β).
- **Régression** : aucune.
- **Restant** : G-3 (P2) + 6 tâches non bloquées (T03, T12, T13, T14, T16, T18) + 3 tâches gate-bloqued (T09, T17, T22).

---

*Fin RUN_V14_GLOBAL_AUDIT_REMEDIATION_2026-04-20.*
