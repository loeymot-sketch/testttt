# W0-A — Décision `pricing_ssot` × `ItemComponent.vue::totalPriceSetup()`

**Cycle** : POS_V4_IMPL_EXEC_FINAL_2026-04-26  
**Phase** : W0 (préparation)  
**Auteur** : Claude terminal (orchestrateur)  
**Date** : 2026-04-26  
**Statut** : **DRAFT — pending human gate sign-off (Tech Lead + Backend owner)**  
**Lien plan** : `plans/PLAN_POS_V4_IMPL_EXEC_FINAL_2026-04-26.md` §G1 + amend HYPERREVIEW Claude L8/§7

---

## 1. Trouvaille (preuve code, lecture seule)

Fichier : `resources/js/components/admin/pos/ItemComponent.vue`  
Lignes : **734–770** (méthode `totalPriceSetup()`)

Extrait littéral (lignes 759–770) :

```
this.temp.item_variation_total = item_variation_total;
this.temp.item_extra_total = item_extra_total;
var catalogBase =
    parseFloat(this.item.offer.length > 0 ? this.item.offer[0].convert_price : this.item.convert_price) || 0;
if (!this.usePricedCartBase) {
    this.temp.convert_price = catalogBase;
}
var baseUnit = this.usePricedCartBase ? parseFloat(this.temp.convert_price) || 0 : catalogBase;
this.temp.total_price = parseFloat(
    (baseUnit + this.temp.item_variation_total + this.temp.item_extra_total) * this.temp.quantity +
        item_addon_total
);
```

Le bloc accumule côté client :
- `item_variation_total` = Σ(`itemVariation.convert_price` × `selectedVariation.quantity`)
- `item_extra_total` = Σ(`itemExtra.convert_price` × `selectedExtra.quantity`)
- `item_addon_total` = Σ(`addon.total_price` × `addon.quantity`)
- `total_price` = (`baseUnit` + variations + extras) × `quantity` + addons

Méthodes appelantes (mêmes fichiers) : `quantityUp` (l.772), `quantityIncrement` (l.779), `quantityDecrement` (l.787), `addonQuantityUp` (l.795), `addonQuantityIncrement` (l.808), `changeVariation` (autour l.660+), `changeExtra` (l.731). Soit **≥ 7 entrées** dans le pipeline.

---

## 2. Statut invariant

| Invariant | Lecture | Verdict |
|---|---|---|
| `pricing_ssot` (Backend = SSOT prix) | Ce bloc *calcule* `total_price` côté Vue à partir de `convert_price` (champ backend). | **Violation conditionnelle**. La SSOT backend est respectée pour les unités (`convert_price` vient toujours de l'API) mais l'**aggrégation** (`× quantity`, `+ extras`, `+ variations`) est exécutée frontend. |
| `commit_before_dispatch` | `totalPriceSetup` est synchrone, sans dispatch. | OK. |
| `branch_id` | Aucun. | N/A direct. |
| `order_status` | Aucun. | N/A direct. |

**Risque opérationnel** :
- Si l'API `convert_price` change (TVA, devise, promo backend), le calcul reste cohérent **tant que** Vue ne fait que multiplier/sommer. **Tant que** = invariant respecté **conditionnellement**.
- Si jamais on ajoute une règle (remise, taxe, conversion devise) **dans `totalPriceSetup`**, c'est rupture stricte de SSOT.

---

## 3. Trois options décisionnelles

| ID | Option | Coût | Risque | Quand |
|---|---|---|---|---|
| **D1 — CONSERVER avec garde** | Garder le bloc tel quel + **garde CI** : tout ajout de `*` `/` `-` `+` autre que `× quantity` ou `+ Σ` lève une alerte. Garde grep CI : `rg "this\.temp\.total_price\s*=" ItemComponent.vue` doit retourner exactement 1 occurrence. | 1h | Bas — garde-fou statique uniquement | Maintenir avant W2 (merge ItemComponent) |
| **D2 — ISOLER en composable backend-fed** | Extraire dans `composables/usePricedItemPreview.js` qui appelle un endpoint `POST /api/admin/pricing/preview` (subtotal, taxes, total signés backend). UI affiche la réponse, ne calcule plus. | 2 jours backend + 1 jour front | Moyen — change le contrat affichage live (latence ajout panier) | Recommandé en lot dédié post POS v4 |
| **D3 — BLOQUER POS v4 jusqu'à refactor** | Refactorer en endpoint preview avant tout merge ItemComponent. | 3-4 jours, bloque W2 | Élevé en planning, faible en correctness | À éviter sauf décision président |

---

## 4. Recommandation orchestrateur (Claude terminal)

**D1 + plan D2 différé** :
1. **Maintenant (W0)** : adopter D1 — garde CI ajoutée à `package.json` script `pos:lint:pricing` :
   ```bash
   ! grep -E "this\.temp\.total_price\s*=\s*parseFloat" \
        resources/js/components/admin/pos/ItemComponent.vue \
     | wc -l | awk '{exit ($1 == 1) ? 0 : 1}'
   ```
   → exit 0 si exactement 1 assignation, 1 sinon.
2. **W2 (merge ItemComponent)** : critère G3 ajouté = `pos:lint:pricing` doit passer ; toute nouvelle règle de prix → STOP S1.
3. **Lot dédié post POS v4 (cycle séparé)** : ouvrir `tasks/T-POS-PRICING-PREVIEW-API.md` pour D2 (endpoint backend signé + composable Vue). Aligne avec `OrderService / FrontendOrderService symétrie`.

Justification : POS v4 est **template + style + namespace** (script gelé). Refactor pricing = changement de contrat applicatif → hors périmètre. La garde CI capture toute régression future sans bloquer W2.

---

## 5. Conditions de mise en œuvre

| Pré-requis | Statut | Bloquant ? |
|---|---|---|
| Tech Lead lit ce fichier et signe `[D1]` ou `[D2]` ou `[D3]` | À FAIRE | OUI — sans signature, W2 ne peut pas merger ItemComponent |
| Backend owner confirme que `convert_price` est bien recalculé serveur sur changement TVA/devise/promo | À FAIRE | OUI — si recalcul absent, D1 reste fragile |
| Garde CI `pos:lint:pricing` ajoutée à `package.json` (script + appel pre-commit / GH Actions) | À FAIRE | OUI pour D1 |
| Ouvrir `tasks/T-POS-PRICING-PREVIEW-API.md` (D2 différé) | À FAIRE post-signature | NON pour W2 mais OUI pour roadmap Q3 |

---

## 6. Sign-off (à remplir par humains)

```
[ ] D1 — CONSERVER avec garde CI       Signé par: ___________ Date: ___
[ ] D2 — ISOLER en backend-fed         Signé par: ___________ Date: ___
[ ] D3 — BLOQUER refactor d'abord      Signé par: ___________ Date: ___

Tech Lead    : ___________________  Date : ___
Backend owner: ___________________  Date : ___
```

Sans signature de cette section, **STOP S1** s'applique au merge `ItemComponent.vue` (cf. HYPERREVIEW §10).

---

## 7. Trace
- `EXECUTE_DELEGATION: claude-terminal`
- `AUDIT_CHANNEL: claude-terminal`
- Lecture seule appliquée — aucun `.vue` modifié par la production de ce document.
- À ingérer dans `memory/episodes/12_decisions_log.jsonl` après sign-off (entry `pos_v4_pricing_ssot_decision`).
