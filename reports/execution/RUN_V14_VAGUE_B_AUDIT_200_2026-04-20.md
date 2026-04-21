# RUN REPORT — Vague B (V14) — AUDIT 200% + REMÉDIATION

**Date** : 2026-04-20
**Cycle** : V14 — Multi-quantité variations & extras
**Scope Vague B** : T02 (UI POS multi-qty) + T20 (defensive type normalization) + T06 (FormRequest validation) + T04 (fixtures repair dry-run)
**Auditeur** : Claude (orchestrateur)
**Verdict global** : ✅ **PASSED après fix critique HOLE B-6**

---

## 1. Périmètre audité

| Tâche | Fichier RUN | Subagent | Statut subagent |
|---|---|---|---|
| T02+T20 fused | `RUN_V14_T02_T20_POS_UI_MULTI_QTY_FUSED_2026-04-20.md` | `foodking-complex-implementer` (GPT-5.4) | PASSED |
| T06 | `RUN_V14_T06_FORM_REQUEST_MULTI_QTY_2026-04-20.md` | `foodking-routine-implementer` (Composer 2) | PASSED |
| T04 | `RUN_V14_T04_FIXTURES_REPAIR_DRY_RUN_2026-04-20.md` | `foodking-routine-implementer` (Composer 2) | PASSED |

L'audit 200% qui suit a été conduit en mode "lecture profonde" pour détecter les angles invisibles, indirects, et les ruptures contractuelles silencieuses qu'aucun test unitaire ne couvrait avant cette passe.

---

## 2. Audit 200% — 10 angles couverts

| # | Angle audité | Trouvé | Sévérité |
|---|---|---|---|
| B-1 | Cohérence types `quantity` côté UI (number vs string) | OK — `normalizeQuantity` partout | — |
| B-2 | Lazy-migration legacy `{variations:{...}, names:{}}` → array | OK — `normalizeVariationEntries` couvre les 2 formats | — |
| B-3 | Form Request `ValidJsonOrder` accepte le nouveau format multi-qty | OK — règles assouplies + i18n traduit | — |
| B-4 | Fixtures seeder dry-run (audit `min_select`/`max_select`/`allow_repeat`) | OK — rapport généré, aucune mutation | — |
| B-5 | Idempotence client (header `Idempotency-Key`) intacte malgré mutation payload | OK — header indépendant du body | — |
| **B-6** | **`PaymentComponent.confirmOrder` appelle `normalizeCartForApi` sur une string JSON** | **TROU CRITIQUE** | **P0 — bloquant** |
| B-7 | `pos_line_addons` multi-qty backward-compat | OK — `normalizeVariationsPayload(normalizeVariationEntries(...))` |  — |
| B-8 | Snapshot NF525 reçoit bien `quantity` après fix B-6 | OK — propagé depuis `PricingService` | — |
| B-9 | Round-trip POS → backend → DB → reload commande | OK après B-6 | — |
| B-10 | Régression suite POS Vitest complète | OK — 9 fichiers / 51 tests verts | — |

---

## 3. HOLE B-6 — Détail forensique

### 3.1 Symptôme

Lors de la confirmation d'un paiement POS depuis `PaymentComponent.vue`, le panier soumis au backend était silencieusement vidé (array vide), ce qui déclenchait soit :

- un `422 ValidJsonOrder` (la règle exige un JSON string non vide d'items),
- soit, dans certains chemins anciens, une commande créée sans aucune ligne.

Ce bug **n'apparaissait dans aucun test existant** car :
1. Les tests Vitest de `posCart.spec.js` testaient `normalizeCartForApi` directement avec un array.
2. Les tests Feature PHP envoyaient déjà du JSON valide pré-construit.
3. Le chemin réel (PosComponent → JSON.stringify → PaymentComponent → normalizeCartForApi) n'était couvert par aucune sentinelle.

### 3.2 Chaîne de responsabilité

```startLine:1474:1478:resources/js/components/admin/pos/PosComponent.vue
            this.checkoutProps.form.items = JSON.stringify(this.checkoutProps.form.items);
```

```startLine:183:202:resources/js/store/modules/posCart.js
export function normalizeCartForApi(items) {
    return (Array.isArray(items) ? items : []).map((item) => {
        ...
    });
}
```

`PosComponent.orderSubmit` stringifie `form.items` **avant** d'ouvrir la modal de paiement. `PaymentComponent.confirmOrder` appelle ensuite `normalizeCartForApi(this.$props.props.form.items)` — qui reçoit donc une **string**, échoue le `Array.isArray`, et retourne `[]`. Le panier est vidé silencieusement.

### 3.3 Correctif appliqué

Dans `resources/js/components/admin/pos/PaymentComponent.vue`, le `confirmOrder` parse maintenant la string si nécessaire, normalise, puis re-stringifie pour préserver le contrat backend (`ValidJsonOrder` exige une string JSON) :

```javascript
const __rawItems = this.$props.props.form.items;
let __itemsArray;
if (typeof __rawItems === "string") {
    try { __itemsArray = JSON.parse(__rawItems) || []; }
    catch (_e) { __itemsArray = []; }
} else if (Array.isArray(__rawItems)) {
    __itemsArray = __rawItems;
} else {
    __itemsArray = [];
}
const __normalized = normalizeCartForApi(__itemsArray);
this.$props.props.form.items = (typeof __rawItems === "string")
    ? JSON.stringify(__normalized)
    : __normalized;
```

**Garanties** :
- ✅ Backward-compat : si un appel hérité passe un array, on retourne un array.
- ✅ Nouveau path canonique : si on reçoit une string (path POS réel), on retourne une string.
- ✅ Robustesse : JSON malformé / string vide → `"[]"` au lieu de crash.
- ✅ Multi-qty (T05/T20) préservé bout-en-bout.

### 3.4 Sentinel test

Nouveau fichier : `tests/js/posPaymentItemsNormalize.spec.js` — **6/6 tests verts**.

| # | Test | But |
|---|---|---|
| 1 | `keeps cart non-empty when form.items is a JSON string` | Reproduit le path POS réel |
| 2 | `preserves multi-qty in variations + extras` | Garantit T05/T20 contract |
| 3 | `still works when an array is passed (back-compat)` | Aucun appel ancien cassé |
| 4 | `returns "[]" on malformed JSON input` | Robustesse |
| 5 | `returns "[]" on empty string` | Robustesse |
| 6 | `regression: raw normalizeCartForApi(string) DOES return []` | Verrou de régression — prouve que le bug existait |

```bash
$ npx vitest run tests/js/posPaymentItemsNormalize.spec.js
 Test Files  1 passed (1)
      Tests  6 passed (6)
```

### 3.5 Non-régression POS complète

```bash
$ npx vitest run tests/js/posCart.spec.js tests/js/posCartScoped.spec.js \
    tests/js/posCartPrune.spec.js tests/js/posCartPruneScoped.spec.js \
    tests/js/posVariationMultiQty.spec.js tests/js/posNormalizeIds.spec.js \
    tests/js/posDineInFlag.spec.js tests/js/PosComponent.spec.js \
    tests/js/posPaymentItemsNormalize.spec.js

 Test Files  9 passed (9)
      Tests  51 passed (51)
```

Aucune régression. La suite couvre désormais le path PaymentComponent.

---

## 4. Bilan Vague B post-remédiation

| Critère | État |
|---|---|
| T02 — UI POS multi-qty | ✅ |
| T20 — Defensive type normalization | ✅ |
| T06 — FormRequest backend (validation + i18n) | ✅ |
| T04 — Fixtures dry-run (audit-only, zéro mutation) | ✅ |
| **B-6 — Path PaymentComponent réel** | ✅ **fixé + sentinellé** |
| Round-trip bout-en-bout (UI → API → DB → reload) | ✅ |
| Backward-compat appels legacy | ✅ |
| Suite Vitest POS | ✅ 51/51 |

---

## 5. Fichiers touchés par la remédiation B-6

- `resources/js/components/admin/pos/PaymentComponent.vue` — fix `confirmOrder`
- `tests/js/posPaymentItemsNormalize.spec.js` — sentinel (nouveau)
- `reports/execution/RUN_V14_VAGUE_B_AUDIT_200_2026-04-20.md` — ce rapport

---

## 6. Risques résiduels & follow-up

| Risque | Statut | Action |
|---|---|---|
| Autres call-sites de `normalizeCartForApi` qui recevraient une string | À auditer en V15 (chemin offline / sync) | TODO V15 — grep + sentinel par call-site |
| Ré-stringification double si un futur dev rajoute un `JSON.stringify` aval | Improbable, contrat documenté | Ajouter doc inline ✅ fait |
| Snapshot NF525 sur lignes annulées (refund) | Hors-scope V14 | Couvert en V13 (cycle précédent) |

---

## 7. Verdict

**Vague B — V14 (T02+T04+T06+T20) : ✅ COMPLÉTÉE 200%**

Toutes les tâches subagents sont PASSED, **et** le trou critique invisible B-6 (qui aurait silencieusement cassé toutes les commandes POS payées en production) est :

1. Diagnostiqué (root cause documentée),
2. Corrigé (path canonique + back-compat),
3. Sentinellé (6 tests nouveaux + verrou de régression),
4. Non-régressé (51/51 tests POS verts).

Le cycle V14 dans son ensemble (Vague A + Vague B) est désormais cohérent bout-en-bout : modèle DB ↔ pricing SSOT ↔ snapshot NF525 ↔ FormRequest ↔ UI POS ↔ payload paiement ↔ DB persist.
