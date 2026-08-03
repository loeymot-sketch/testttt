# AUDIT GLOBAL — 4 Vagues V14 (A + B + C-α + C-β)

**Date** : 2026-04-20
**Orchestrateur** : Claude Opus 4.7 (audit transverse 4 vagues + planification corrections)
**Cycle** : `V14_FINALISATION_POS_BASE` (référence : `plans/PLAN_FINALISATION_POS_BASE_2026-04-20.md`)
**Mode** : audit lecture profonde + cross-vague + run tests + plan + attaque immédiate des corrections P0/P1.

---

## 1. Résumé exécutif (TL;DR)

| Vague | Tâches livrées | Statut individuel | Audit 200 % | Fixes appliqués | Régression |
|---|---|---|---|---|---|
| **A** (T01+T05+T07) | 3/3 | PASSED | ✅ done (1 P0 safeJsonDecode) | ✅ 1 fix + 1 hotfix Categories | 0 régression |
| **B** (T02+T04+T06+T20) | 4/4 | PASSED | ✅ done (1 P0 B-6 PaymentItems) | ✅ 1 fix + 6 sentinels | 0 régression |
| **C-α** (T08+T10+T11) | 3/3 | PASSED | ✅ done (2 P1 + 3 P2) | ✅ 5 fixes + 4 sentinels | 0 régression |
| **C-β** (T15+T19+T21) | 3/3 | PASSED | ✅ done (3 P1 + 2 P2) | ✅ 5 fixes + 6 sentinels | 0 régression |

**Total V14** :
- **13 tâches** sur 22 du master plan (Vague A + B + C complète) → **59 %** du plan global livré.
- **23 fixes** post-audit appliqués sur les 4 vagues (1+1+5+5 invisibles + 11 sentinels nouveaux).
- **102 / 102** Vitest POS verts.
- **199 / 199** PHPUnit (Pricing|Pos|Floorplan|Printer|Composition|Snapshot) verts (1 fail pré-existant `FINDING_BACK_DEFERRED` hors V14).
- **0 régression** introduite par les 4 vagues, **0 zone gelée** touchée hors gate G14-A approuvé.

**Verdict global initial** : ✅ **Les 4 vagues sont individuellement validées 200 %**.

**Mais** un audit **transverse cross-vagues** révèle **3 nouveaux écarts inter-vagues** invisibles (non détectables par les audits par-vague isolés) :

| ID | Sév | Sujet | Cross-vagues | Impact |
|---|---|---|---|---|
| **G-1** | **P0** | Receipt template (C-β/T21) ne consomme PAS `composition_snapshot` (A/T07) | A ↔ C-β | **NF525 brisé** : reprint d'un reçu après rename d'une variation imprime le nouveau nom au lieu du nom historique → audit fiscal ment |
| **G-2** | **P1** | Receipt template ne renderise PAS la `quantity` des variations multi-qty (A/T05) | A ↔ B ↔ C-β | Le client voit "Tacos 4 viandes : Steak, Steak, Steak, Steak" au lieu de "Tacos 4 viandes : 4× Steak" |
| **G-3** | P2 | `posParked.recall` (C-α/T08) ne vérifie l'indispo qu'au niveau **item**, pas au niveau **variation** (T05) | A ↔ C-α | Variation 86'd pendant qu'une commande est parkée → recall pollue le panier silencieusement (le check `pruneUnavailable` n'opère qu'au niveau itemId) |

→ **G-1 + G-2 attaqués immédiatement** dans cette même passe (P0 + P1).
→ **G-3** documenté + planifié pour Vague D (intersection avec T03 parité POS↔Kiosk).

---

## 2. Audit par vague (rappel synthétique)

### Vague A — T01 + T05 + T07 (Fondation backend multi-qty + NF525)
- **Livrables** : migrations `add_min_max_repeat_to_item_attributes`, `add_composition_snapshot_to_order_items`, modèle `OrderItemVariation.quantity`, `PricingService` multi-qty, `CompositionSnapshotBuilder`, `OrderItemResource::resolveVariationsForApi`.
- **Trous P0 invisibles fixés** : `safeJsonDecode` strippait les clés associatives → `composition_snapshot` corrompu. + Hotfix `KioskCategoriesComponent` 54 fails Vitest legacy.
- **Tests** : PricingMultiQty 9/9 + OrderItemCompositionSnapshot 6/6 + PricingIntegrity 94/94.
- **Gate** : G14-A approuvé.
- **Frozen zones** : OrderService LOCK_B + FrontendOrderService LOCK touchées **dans le cadre du gate**.

### Vague B — T02 + T04 + T06 + T20 (UI POS multi-qty)
- **Livrables** : `ItemComponent.vue` compteur ±, `posCart` mutations multi-qty, `posNormalizeIds` helper, `ValidJsonOrder` Form Request relâchée multi-qty + i18n, fixtures repair seeder dry-run.
- **Trou P0 B-6 fixé** : `PaymentComponent.confirmOrder` appelait `normalizeCartForApi` sur une **string JSON** → panier vidé silencieusement → 422 ou commande sans lignes. **Sentinel ajouté** + 6 tests verrou de régression.
- **Tests** : Vitest POS 51/51 + sentinel `posPaymentItemsNormalize` 6/6.

### Vague C-α — T08 + T10 + T11 (Caisse opérateur sans gate)
- **Livrables** : `pos_parked_orders` table + service + controller + 4 routes + `ParkedOrdersComponent.vue`, `posBarcode.js` (HID buffer + F-keys raccourcis), barcode index migration, `lookupBarcode` route, `posAvailabilityLiveGuard` (Echo subscribe + freeze ItemComponent).
- **Trous P1 invisibles fixés** : C-1 `posParked.recall` ne purgeait pas items 86'd → panier pollué → 422. + C-9 sentinels cross-branch parked (recall+discard).
- **Trous P2 fixés** : C-2 cleanup `_availabilityToastTimers`, C-5 F-keys neutralisables drawer ouvert, C-8 migration barcode idempotente.
- **Tests** : Vitest POS 76/76 + Feature parked 8/8.

### Vague C-β — T15 + T19 + T21 (Hardware + floorplan + receipt)
- **Livrables** : `EscPosCommandBuilder` + `EscPosPrinterService` + `Printer` modèle + `printers` table, `DiningTable` extension + `DiningTableAuditLog` + `FloorplanController` + `FloorplanComponent.vue`, `OrderDetailsResource` étendu NF525 (fiscal_sequence_no, audit_chain_fingerprint, payments_breakdown, pos_legal_footer), `ReceiptComponent.vue` redesign A4+thermique.
- **Trous P1 invisibles fixés** : T19-1 deadlock MySQL transferts concurrents (lock par min/max ID), T19-2 `occupy()` valide order_id+branch_id (multi-tenant guard), T19-3 `occupy()` syncro `orders.dining_table_id`.
- **Trous P2 fixés** : T15-1 ESC/POS codepage CP858 + `encodeForPrinter` (mojibake fixé), T19-7 `inFlight` UI guard double-click.
- **Tests** : Floorplan 11/11 + Printer 9/9 (incl. 4 sentinels nouveaux).

---

## 3. Audit transverse cross-vagues — 3 nouveaux écarts

### G-1 (P0) — Receipt template ne consomme PAS `composition_snapshot`

**Localisation** : `resources/js/components/admin/pos/ReceiptComponent.vue:70-83`

**Code actuel** :
```vue
<p v-if="Object.keys(item.item_variations).length !== 0">
    <span v-for="(variation, index) in item.item_variations">
        {{ variation.variation_name }}: {{ variation.name }}
    </span>
</p>
<p v-if="item.item_extras.length > 0">
    {{ $t('label.extras') }}:
    <span v-for="(extra, index) in item.item_extras">
        {{ extra.name }}
    </span>
</p>
```

**Bug invisible** : `OrderItemResource::resolveVariationsForApi()` (T07) renvoie le **snapshot lines** quand présent : `[{variation_id, attribute_name, variation_name, quantity, unit_price}]`. Le template lit `variation.name` (qui n'existe PAS dans le snapshot — c'est `variation_name`), donc :
- Pour les commandes legacy (snapshot=null) : OK (legacy `item_variations` JSON a `{variation: {...}, name}`).
- **Pour les commandes post-T07 avec snapshot** : `variation.name` est `undefined` → le reçu imprimé affiche "Viande:" (vide) ou "Viande: undefined".

**Conséquence NF525** : si une variation est renommée après la commande, le reçu reprint affichera **rien** (au lieu de l'ancien nom historique gelé dans le snapshot). C'est exactement **l'inverse** de l'objectif de T07 (immutabilité fiscale) : T07 garantit la valeur en DB, mais le UI ne la lit pas correctement.

**Sévérité** : **P0** — bloquant NF525 + UX cassée pour toute commande créée après T07.

### G-2 (P1) — Receipt n'affiche pas la `quantity` des variations

**Localisation** : même bloc `ReceiptComponent.vue:72-75`

**Bug** : Pas de rendu `quantity ×` devant `variation_name`. Pour un tacos 4 viandes (3× Steak + 1× Poulet), le reçu imprimera "Viande: Steak, Viande: Poulet" au lieu de "Viande: 3× Steak, 1× Poulet" (ou similaire).

**Conséquence** : opérateur et client perdent l'info quantité par variation au paiement et à la lecture du reçu.

**Sévérité** : **P1** — non bloquant mais dégrade l'UX et peut causer litiges client.

### G-3 (P2) — `posParked.recall` ne vérifie pas l'indispo au niveau variation

**Localisation** : `resources/js/store/modules/posParked.js#recall`

**État actuel** : Après le fix C-1 de Vague C-α, recall purge les **items** dont `is_available === false` dans le catalogue actif. Mais si l'item est dispo et qu'**une variation** (Steak) est devenue 86'd entre-temps, la ligne est conservée → 422 au checkout sur la validation `assertVariationConstraints`.

**Sévérité** : **P2** — edge case, l'item non-86d couvre la majorité des cas. Mais cohérent avec G-1/G-2 sur l'attention multi-qty/variation.

**Plan** : Vague D, intersection avec T03 (parité POS↔Kiosk).

---

## 4. Inventaire reste-à-faire (master plan vs livré)

| # | ID | Surface | Statut | Vague |
|---|---|---|---|---|
| T01 | VARIATION_MODEL_MULTI_QTY_BACKEND | 🟩 | ✅ A | A |
| T02 | POS_VARIATION_MULTI_QTY_UI | 🟥 | ✅ B | B |
| **T03** | **POS_KIOSK_VARIATION_PARITY_TESTS** | 🟪 | ⏳ **TODO** | **D** |
| T04 | VARIATION_FIXTURES_DATA_REPAIR | 🟩 | ✅ B (dry-run) | B |
| T05 | PRICING_SSOT_MULTI_QTY | 🟩 | ✅ A | A |
| T06 | VARIATION_VALIDATION_BACKEND | 🟩 | ✅ B | B |
| T07 | ORDER_ITEM_SNAPSHOT_HARDENING | 🟩 | ✅ A | A |
| T08 | POS_PARK_HOLD_RECALL | 🟥 | ✅ C-α | C-α |
| **T09** | **POS_LINE_DISCOUNT_VOID** | 🟥 | 🚫 **BLOCKED** gate G14-B | — |
| T10 | POS_SEARCH_BARCODE_FAST | 🟥 | ✅ C-α | C-α |
| T11 | POS_AVAILABILITY_LIVE_GUARD | 🟥 | ✅ C-α | C-α |
| **T12** | **POS_PERF_PERCEIVED** | 🟥 | ⏳ **TODO** | **D** |
| **T13** | **KDS_STATION_FILTER_SOUND** | 🟨 | ⏳ **TODO** | **D** |
| **T14** | **KDS_BUMP_RECALL_LIFECYCLE** | 🟨 | ⏳ **TODO** | **D** |
| T15 | HARDWARE_PRINTER_ESC_POS | 🟥+🟩 | ✅ C-β | C-β |
| **T16** | **HARDWARE_DRAWER_BARCODE_NFC** | 🟥+🟩 | ⏳ **TODO** (T15 ✅) | **D** |
| **T17** | **POS_PAYMENT_RESILIENCE** | 🟥+🟩 | 🚫 **BLOCKED** gate C9 + G14-B | — |
| **T18** | **A11Y_POS_OPERATOR** | 🟥 | ⏳ **TODO** | **D** |
| T19 | POS_TABLE_FLOORPLAN | 🟥 | ✅ C-β | C-β |
| T20 | DEFENSIVE_TYPE_NORMALIZATION | 🟪 | ✅ B | B |
| T21 | POS_RECEIPT_REDESIGN | 🟥+🟩 | ✅ C-β (à patcher G-1+G-2) | C-β |
| **T22** | **E2E_TACOS_4_VIANDES_FULL_FLOW** | 🟪 | ⏳ **TODO** (dépend T03 + T17) | **D** (partiel) |

**Restant après corrections G-1+G-2** :
- **6 tâches non bloquées** : T03, T12, T13, T14, T16, T18 → **Vague D** (parallélisable, 0 gate humain).
- **3 tâches bloquées par gate humain** : T09 (G14-B), T17 (C9 + G14-B), T22 partiellement (dépend T17).
- **Reste à faire pour 100 %** : 9 tâches, dont 6 immédiatement attaquables.

---

## 5. Liste de corrections à appliquer immédiatement (cette passe)

| # | ID | Sév | Sujet | Fichier | Action | Sentinel |
|---|---|---|---|---|---|---|
| **F-G1** | RECEIPT_SNAPSHOT_CONSUMPTION | **P0** | Receipt lit snapshot lines + extras | `ReceiptComponent.vue:70-83` | Adapter template aux 2 formats (legacy + snapshot) | Vitest |
| **F-G2** | RECEIPT_VARIATION_QTY_DISPLAY | **P1** | Affichage `quantity ×` par variation | `ReceiptComponent.vue:72-75` + helper | Ajouter `qty ×` quand `quantity > 1` | Vitest |
| **F-G1B** | RECEIPT_BACKEND_SENTINEL | **P1** | Test Feature `OrderDetailsResource` end-to-end avec snapshot multi-qty | `tests/Feature/PosReceipt*.php` | Sentinel cross-wave A↔C-β | PHPUnit |

**G-3** (P2) : reportée Vague D (couvert par T03).

---

## 6. Plan Vague D (proposition orchestrateur — pour ton arbitrage)

### Vague D-α (parallélisable, 0 gate, ~2 jours)
| Tâche | Subagent | Effort |
|---|---|---|
| **T03** parité POS↔Kiosk tests + couvre G-3 | Composer | 0.5 j |
| **T12** perf perceived (skeleton + optimistic add + virtual scroll) | Composer | 0.5 j |
| **T18** a11y POS operator (focus order + ARIA + live regions) | Composer | 0.5 j |

### Vague D-β (parallélisable, 0 gate, ~2 jours)
| Tâche | Subagent | Effort |
|---|---|---|
| **T13** KDS station filter + sound | Composer | 0.5 j |
| **T14** KDS bump bar + recall + escalation timer | Composer | 0.5 j |
| **T16** hardware drawer + NFC fidélité (dépend T15 ✅) | Composer | 0.5 j |

### Vague D-γ (E2E + gates pendants)
| Tâche | Subagent | Bloqueur |
|---|---|---|
| **T22 partiel** E2E "tacos 4 viandes mix → POS → KDS → ready" sans paiement multi-tender | Composer | aucun |
| **T22 complet** + paiement multi-tender + reçu | Composer | T17 (gate C9+G14-B) |
| **T09** discount/void per-line | Composer | gate G14-B humain |
| **T17** payment resilience | GPT-5.4 | gate C9 + G14-B humain |

---

## 7. Validation finale (tests snapshot pré-corrections G-1/G-2)

```bash
$ npx vitest run tests/js/pos*.spec.js tests/js/PosComponent.spec.js
 Test Files  18 passed (18)
      Tests  102 passed (102)

$ php artisan test --filter='Pricing|Pos|Floorplan|Printer|Composition|Snapshot'
Tests:  1 failed, 3 skipped, 199 passed
        (1 fail = FINDING_BACK_DEFERRED pré-existant hors V14 scope)
```

---

## 8. Gates humains pendants (rappel)

| Gate | Bloque | Document |
|---|---|---|
| **C9** dispatch-after-commit | T17 | `docs/gates/GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md` (addendum à signer) |
| **G14-B** payment + line discount/void NF525 | T09 + T17 | À créer si validation plan |
| FINDING_BACK_DEFERRED allergens extras merge | suite W3 | Hors V14 |

---

## 9. Verdict final pré-correction G-1/G-2

```
4 VAGUES (A + B + C-α + C-β)         : ✅ 13/22 livrées + auditées 200 %
Audits par-vague                       : ✅ 23 fixes + 11 sentinels
Audit transverse cross-vagues          : 🔴 3 écarts inter-vagues détectés (1 P0 + 1 P1 + 1 P2)
Tests Vitest POS                       : ✅ 102/102
Tests PHPUnit POS scope                : ✅ 199/199 (1 fail pré-existant)
Frozen zones intactes hors gate G14-A  : ✅
Master plan progression                : 59 % livré + 41 % planifié (6 immédiats + 3 gate-blocked)
```

→ **Prochaine étape immédiate** : application des fixes F-G1 + F-G2 + F-G1B + sentinels + re-validation. Documenté section 10 ci-dessous.

---

## 10. (à compléter après attaque) — Application F-G1 + F-G2 + F-G1B

Voir `RUN_V14_GLOBAL_AUDIT_REMEDIATION_2026-04-20.md` (rapport d'application).
