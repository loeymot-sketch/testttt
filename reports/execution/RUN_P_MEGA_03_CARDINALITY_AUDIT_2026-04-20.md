# RUN_P_MEGA_03 — Audit cardinality min/max enforcement (sauces / garnitures / suppléments)

**Date** : 2026-04-20  
**Cycle** : P-MEGA  
**Tâche** : P-MEGA-03 (vague 1)  
**Verdict** : **AUDIT CLOSED — HUMAN GATE pour implémentation**  
**Mode** : audit lecture-seule, aucun code modifié

## Hypothèse de bug auditée

Le wizard kiosk autorise actuellement `n` sélections de sauces / suppléments / garnitures sans **aucun cap haut** explicite (juste un cap implicite côté `KioskStepSauceComponent` à 4 sauces visibles). Si l'admin restaurant veut imposer "max 3 sauces gratuites puis +0,50€", "min 1 sauce obligatoire", "menu = 1 boisson + 1 dessert obligatoires", l'UI ne peut pas l'enforcer.

## État BD

`database/migrations/2022_11_17_110541_create_item_attributes_table.php` : table `item_attributes` n'a que `id`, `name`, `status`, `creator_*`, `editor_*`, `timestamps`.

**Aucun champ** :
- `min_select` (cardinality minimum imposée)
- `max_select` (cardinality maximum imposée)
- `allow_repeat` (autoriser sélection multiple d'une même variation)
- `is_required` (groupe obligatoire)

## État Resource serveur

`SimpleItemResource` / `NormalItemResource` exposent les attributes mais ne peuvent rien dériver d'inexistant.

## État Step* front

`KioskStepSauceComponent`, `KioskStepGarnituresComponent`, `KioskStepSupplementsComponent` : aucune notion de min/max consommée. Comportement actuel = "libre service" sauf pour `KioskStepViandeComponent` qui utilise `_tailleMeta.viandeCount` (l'invariant fixé par P-MEGA-01).

## Conséquence opérationnelle

| Use case admin | Couvert ? |
|---|---|
| "Sauce obligatoire" | ❌ Non — utilisateur peut skip |
| "Max 2 sauces gratuites, puis +0,50€" | ❌ Non |
| "1 boisson + 1 dessert si menu" | ⚠️ Partiel (boisson seule via `KioskStepMenuComponent`) |
| "Autoriser même viande × 3 (mix interdit)" | ❌ Non — `_tailleMeta.viandeCount` ne contraint pas le mix |

## Proposition (gate humain requise)

### Migration additive

```php
Schema::table('item_attributes', function (Blueprint $table) {
    $table->unsignedTinyInteger('min_select')->default(0)->after('status');
    $table->unsignedTinyInteger('max_select')->nullable()->after('min_select');
    $table->boolean('allow_repeat')->default(false)->after('max_select');
    $table->boolean('is_required')->default(false)->after('allow_repeat');
});
```

Backfill via observer ou seeder : on peut commencer avec `max_select = NULL` (= illimité, comportement actuel préservé) et les admins remplissent au fur et à mesure. Zéro régression métier.

### Resource

`SimpleItemResource` / `NormalItemResource::transformAttributes()` : ajouter `{ min, max, allowRepeat, required }` à chaque entrée `itemAttributes`.

### Front Step* — couche d'enforcement

Helper réutilisable `kioskCardinality.js` :
```js
export function isWithinCardinality(selections, attribute) {
  const count = Object.values(selections).reduce((s, v) => s + (v || 0), 0);
  if (attribute.max != null && count >= attribute.max) return { canAdd: false, reason: 'max' };
  return { canAdd: true };
}
export function meetsMinCardinality(selections, attribute) {
  const count = Object.values(selections).reduce((s, v) => s + (v || 0), 0);
  return attribute.min == null || count >= attribute.min;
}
```

Chaque Step* utilise ces helpers pour disable le `+` et bloquer "Suivant".

## Tests à prévoir (avant fix)

- `tests/js/kioskCardinality.spec.js` — 12 cas (min=0/max=null, min=1/max=3, allow_repeat true/false, etc.)
- `tests/Feature/ItemAttributeMinMaxTest.php` — la resource expose bien les champs
- `tests/Browser/KioskWizardCardinalityE2E` — Playwright : un kiosk respecte les contraintes saisies en admin

## Risques résiduels

- **Drift admin → front** : si l'admin n'expose pas les champs (UI form CRUD admin pas mis à jour), les contraintes restent invisibles côté admin → couvert par P-MEGA-23.
- **Compat ascendante** : tant que `max_select = NULL` partout, comportement = actuel (pas de régression).

## LOC estimées

- Migration : 12 LOC
- Resource : 30 LOC (transformer)
- Helper + Step* glue : 80 LOC
- Tests : 250 LOC
- Total : ~370 LOC

## Demande de gate

Prérequis pour exec :
- ✅ Confirmer le besoin métier (admin restaurant veut-il piloter ces contraintes ?)
- ✅ Choisir backfill strategy (NULL safe ou défauts agressifs ?)
- ✅ Coordonner avec P-MEGA-23 (admin form CRUD)

→ **Statut** : `GATE_HUMAN_REQUIRED` zone DB schema. Ce rapport documente le scope. Pas de commit code, juste ce rapport.
