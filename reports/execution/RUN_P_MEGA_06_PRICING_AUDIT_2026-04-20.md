# RUN_P_MEGA_06 — Audit pricing client/serveur cohérence

**Date** : 2026-04-20  
**Cycle** : P-MEGA  
**Tâche** : P-MEGA-06 (vague 2 — pricing SSOT)  
**Verdict** : **AUDIT CLOSED — DIVERGENCES IDENTIFIÉES → HUMAN GATE pour fix**  
**Mode** : audit lecture-seule, aucun code modifié

## Périmètre audité

- Front : `resources/js/helpers/kioskPricing.js` (`calculateKioskRunningTotal`)
- Front SSOT preview : `resources/js/helpers/kioskPricingPreview.js` (Phase 9.1.3)
- Front cart-line builder : `KioskWizardComponent.buildCartItem()`
- Serveur : `app/Services/Pricing/PricingService.php` (`calculateOrder`)
- Sérialisation : `app/Models/.../sanitizeKioskOrderItem` côté Vuex (whitelist)

## Architecture en place

- ✅ `kioskPricingPreview` est cablé dans `KioskWizardComponent.mounted()` + watcher `selections deep` (debounce 400ms, abort previous via axios CancelToken)
- ✅ Whitelist stricte : seuls `item_id, instruction, quantity, item_variations[].id, item_extras[].id` partent au serveur
- ✅ Serveur valide chaque variation/extra appartient bien à l'item (cross-item guard) avec `enforceCrossItemGuards`
- ✅ Serveur charge les prix réels depuis BD (`(float) $dbVar->price`, `(float) $dbExt->price`), JAMAIS du payload client
- ✅ Tests `PricingServiceTest.php` couvrent les chemins serveur

## Divergences identifiées (du moins critique au plus critique)

### Divergence #1 — **Sauces "extra" non envoyées au serveur** ⚠️ CRITIQUE

**Contexte** : Le client autorise N sauces ; la 1ère est gratuite, les suivantes sont à `getKioskExtraSauceUnitPrice(item)` (par défaut 0.50€).

**Code client** `kioskPricing.js` lignes 78-81 :
```js
const sauceOrder = selections.sauceOrder || [];
if (sauceOrder.length > 1) {
  total += (sauceOrder.length - 1) * extraSauceUnitPrice;
}
```

**Code client `buildCartItem`** lignes 966-974 (extrait) :
```js
if (this.selections.sauceOrder.length > 0) {
  const firstSauceKey = this.selections.sauceOrder[0];
  const sauceAttr = this.kioskSauceAttribute(item);
  const variation = sauceAttr ? this.kioskFindSauceVariation(item, firstSauceKey) : null;
  if (sauceAttr && variation) {
    allVariations[sauceAttr.id] = variation.id;  // SEULE LA 1ERE
  }
}
```

**Conséquence** : sauces 2, 3, 4… ne sont **PAS** dans `item_variations[]` ni `item_extras[]`. Le serveur ne les voit pas. `verifiedTotalPrice` serveur = subtotal SANS surcharge sauces extras.

**Impact financier** : pour un kiosk où 30% des clients prennent 2 sauces (typique tacos), perte = `30% × 0,50€ × volume_jour`. Sur 200 commandes/jour = ~30€/jour de revenu silencieusement perdu.

### Divergence #2 — **Menu addon (full/frites/boisson) non envoyé au serveur** ⚠️ CRITIQUE

**Code client** `kioskPricing.js` ligne 103 :
```js
total += getKioskMenuAddonPrice(item, selections.menuChoice);
```

`getKioskMenuAddonPrice` calcule `fullPrice * ratio` (full=1, frites=0.6, drink=0.4 par défaut, configurable via `window.foodkingConfig.kioskMenuPricing`).

**Code client `buildCartItem`** ligne 1041 :
```js
const menuAddonPrice = getKioskMenuAddonPrice(item, this.selections.menuChoice);
const itemVariationTotal = sauceVariationSurcharge + fritesSauceSurcharge + menuAddonPrice;
```

→ `menuAddonPrice` ajouté à `itemVariationTotal` (champ client-only, pas envoyé au serveur).
→ AUCUNE entrée correspondante dans `item_variations[]` ou `item_extras[]`.

**Conséquence** : le serveur sous-facture **TOUT le menu addon**, soit potentiellement 30-60% du prix d'un menu (frites + boisson). Plat à 9€ + menu full à 4€ → serveur facture 9€.

**Impact financier** : massif. Sur un kiosk où 50% prennent un menu, perte = `50% × 4€ × volume_jour`. Sur 200 commandes/jour = ~400€/jour.

### Divergence #3 — **Sauces frites surcharge non envoyée**

Idem mécanisme que #1 mais pour `selections.fritesSauceOrder`. Code client (ligne 84-86) :
```js
if ((selections.menuChoice === 'full' || selections.menuChoice === 'frites') && frySauces.length > 1) {
  total += (frySauces.length - 1) * extraSauceUnitPrice;
}
```

Aucune entrée correspondante côté serveur.

### Divergence #4 — **Premier prix sauce variation potentiellement non gratuit BD**

`getKioskExtraSauceUnitPrice` (lignes 30-43) fait un fallback sur 0.50€ si aucune variation sauce n'a `price > 0`. Mais il prend **la première variation avec prix > 0** comme unit. Si l'admin met une sauce à 0.30€ et une autre à 0.80€, le client choisit 0.30€ alors que la business rule peut être 0.80€.

→ Convention floue. Côté serveur, chaque sauce a son propre prix BD ; mais comme les sauces 2+ ne sont pas envoyées, ce n'est même pas vérifié.

### Divergence #5 (mineure) — **Viandes payantes : OK, aligné** ✅

Le client met les viandes payantes dans `normalizedExtras` (1 entrée par unité, ligne 1021-1029 de buildCartItem). Le serveur boucle sur `item_extras[]` et somme leurs prix BD. **Cohérent** — sauf si la convention "1 entrée par unité" diverge avec ce que le serveur attend (le serveur somme `(float) $dbExt->price` pour chaque entrée, donc 3 entrées même extra ID = 3× le prix). À condition que le helper `partitionKioskExtras` ne dédupe pas, ça marche.

**Verrouillage manquant** : aucun test E2E ne vérifie que 3 entrées du même extra ID donnent bien 3× le prix (vs 1× via dedupe accidentel). À ajouter.

### Divergence #6 (architecture) — **Pas de check d'égalité side-by-side**

Aucun test E2E **comparatif** n'existe pour vérifier `calculateKioskRunningTotal(item, selections) === server.preview(payload).total` sur un panier réel. Le `kioskPricingPreview` est utilisé pour afficher un total serveur quand dispo, mais s'il échoue, fallback `runningTotalLocal`. Donc la divergence est **masquée** : le client AFFICHE soit le serveur soit le local, sans flag visuel sur la divergence.

## Synthèse divergences

| # | Divergence | Sévérité | Impact $ estimé/jour |
|---|---|:---:|---:|
| 1 | Sauces extras non envoyées | 🔴 | ~30€ |
| 2 | **Menu addon non envoyé** | 🔴🔴🔴 | **~400€** |
| 3 | Sauces frites extras non envoyées | 🟠 | ~10€ |
| 4 | Convention floue prix sauce | 🟡 | <1€ |
| 5 | Viandes payantes (OK aligné) | ✅ | 0 |
| 6 | Aucun monitoring divergence | 🟠 | n/a |

**Total estimé** : ~440€/jour/kiosk de revenu silencieusement perdu côté serveur.

## Vérification empirique sur panier-type

**Panier** : 1× Tacos XL 3 viandes (9€), 3 sauces (1 incluse + 2 extra à 0,50€), menu full (+ ratio 1× addon prix, 4€), 1 supplément cheddar (+0,80€), qty 1.

| Calcul | Détail | Total |
|---|---|---:|
| Client `calculateKioskRunningTotal` | 9 + (2 × 0,50) + 4 + 0,80 | **14,80€** |
| Serveur `PricingService` | 9 + 0 (1 sauce variation à 0€) + 0,80 (cheddar extra) + 0 (menu invisible) + 0 (sauces extras invisibles) | **9,80€** |
| **Écart** | | **−5,00€** silent |

À la caisse : le serveur émet une commande à **9,80€** alors que le client a affiché **14,80€**. L'utilisateur paie 9,80€ — donc soit le client paie l'écran qu'il a vu (logique commande), soit le serveur recompute et l'utilisateur découvre un écart. Selon la branche `payment_method`, le résultat varie.

## Remédiations proposées (HUMAN GATE)

### Option A — **Envoyer toutes les sauces dans item_variations[]** (recommandée)

`buildCartItem` doit envoyer **toutes** les variations sauces choisies (pas juste la première). Le serveur boucle déjà sur `item_variations[]` et somme correctement. Implication : la BD sauce variations doit avoir le bon `price` (la 1ère à 0€ si gratuite, les autres au prix unit).

### Option B — **Stocker menu addon comme un extra spécial**

Côté BD : ajouter une colonne `menu_addon_choice` à `item_extras` (ou créer une table séparée), seedée par les ratios actuels. `buildCartItem` push un faux extra `{id: menuAddon.id, name: 'Menu full'}` selon le menuChoice. PricingService voit l'extra et somme.

Variante : envoyer `selections.menuChoice` en clair via une nouvelle clé `menu_choice` dans le payload, et faire calculer côté serveur (avec les ratios depuis config server-side).

### Option C — **Server-driven ratios + menu_choice clé**

La plus propre : ajouter au DTO PricingRequest un champ `menu_choice` par item, et un service `KioskMenuPricingResolver` qui lit `kiosk_menu_pricing` config DB (pas window.foodkingConfig). Le client envoie juste `menu_choice: 'full'`, le serveur calcule.

### Bonus — Monitoring divergence

Ajouter dans `KioskWizardComponent.refreshServerPreviewTotal` un log :
```js
if (this.serverPreviewTotal != null && Math.abs(this.serverPreviewTotal - this.runningTotalLocal) > 0.01) {
  kioskAnalytics.track('kiosk.pricing.divergence', {
    item_id: this.resolvedItem.id,
    local: this.runningTotalLocal,
    server: this.serverPreviewTotal,
    diff: this.serverPreviewTotal - this.runningTotalLocal,
  });
}
```

→ permet de mesurer en prod combien de paniers divergent et de quel montant.

## Tests à prévoir avant implémentation

- `tests/js/kioskPricingDivergenceContract.spec.js` — pour chaque scénario panier (50 cas), assertion **exact equality** entre `calculateKioskRunningTotal` et `(buildCartItem → PricingService.calculateOrder).finalTotal`.
- `tests/Feature/Pricing/KioskMenuAddonPricingTest.php` — couvre option choisie (A/B/C).
- `tests/Browser/KioskPricingDivergenceMonitoring.php` — Playwright : assert le total cart = total payment = total serveur.

## LOC estimées

| Option | LOC |
|---|---:|
| A (sauces only) | ~80 |
| B (menu via extras) | ~150 + migration BD |
| C (menu_choice + resolver) | ~250 + migration + DTO |
| Monitoring + tests | +180 |

## Demande de gate

→ **Statut** : `GATE_HUMAN_REQUIRED` zone pricing SSOT (Frozen Zone).

Décisions requises de l'utilisateur :
1. **Quelle option ?** A (chirurgical) | B (BD étendue) | C (server-driven, le + propre)
2. **Backfill nécessaire ?** Si option B/C, ratios actuels en BD ?
3. **Période de migration** : double-write client + server, puis cutover ?
4. **Activer monitoring divergence en prod** dès maintenant (sans fix) pour mesurer l'impact réel ?

Aucun commit code, juste ce rapport. Tests Vitest baseline reste 521/521.
