# AGENT EDGE-CASES — Audit RUPTURE EXTRA / SUPPLEMENT (POS + Kiosk)
**Date** : 2026-05-08
**Agent** : EDGE-CASES (rôle GSTACK Edge cases hunter hostile)
**Sujet** : P1 ORCHESTRATOR `pos-5/extra-oos-not-marked-ui` (16 extras, 0 marked OOS)
**Question métier user** : "rupture de supplément → la commande passe, on prévient juste que ce supplément est en rupture (jambon, fromage qui n'est plus en stock → écrit 'en rupture' dessus)"

---

## TL;DR — Verdict double (révisé après preuve screenshot)

> **CORRECTION 2026-05-08 (post-screenshot)** : la première version de cet audit déclarait POS=PRESENT
> en se basant uniquement sur `ItemComponent.vue`. La preuve screenshot
> (`pos-5-step-03-wizard-with-oos-extra.png`) prouve que **le wizard rendu dans
> le test n'est PAS `ItemComponent.vue` mais `public/js/pos-wizard.js`** (Vanilla JS, wizard
> protégé/frozen — cf MEMORY 2026-05-06 "Wizard popup POS protégé"). Ce wizard ne lit
> JAMAIS `is_available` / `unavailable_reason` (`grep` confirme 0 occurrence).
> Le P1 ORCHESTRATOR est donc **VRAI** sur les items wizardés, et **PARTIELLEMENT FAUX** :
> - `ItemComponent.vue` (items SANS wizard custom) → FEATURE PRESENT
> - `pos-wizard.js` (items AVEC wizard custom — ex Tacos M) → FEATURE MISSING

| Surface | Renderer | Verdict | Sévérité |
|---------|----------|---------|----------|
| **POS — items sans wizard custom** | `ItemComponent.vue` (Vue) | **FEATURE PRESENT** | conforme V1 |
| **POS — items avec wizard custom** | `public/js/pos-wizard.js` (Vanilla JS, frozen) | **FEATURE MISSING** | **P1 vrai** mais conflit frozen-zone |
| **Kiosk (client)** | `KioskWizard*Component.vue` + steps | **FEATURE MISSING** | **P1 vrai** — surface où l'exigence user est la plus critique |

Le P1 ORCHESTRATOR est **MIXTE** :
1. POS-5 wizard = vrai gap pour items wizardés (Tacos, Burger, Salade, etc.)
2. Test regex ENG-only était quand même problématique pour `ItemComponent.vue` runtime FR (test-debt résiduel)
3. Kiosk gap parallèle indépendant (pré-existant à ce P1)

**GO/NO-GO V1 sur cette exigence métier user** :
- **NO-GO** pour kiosk (surface client-facing — exigence user directe)
- **NO-GO conditionnel** pour POS wizard frozen (exigence métier vs zone protégée — escalade humaine requise)
- **GO** pour POS items non-wizardés (ItemComponent.vue conforme)

---

## 1. POS — Verdict mixte (clarification)

> **Important** : POS a DEUX renderers de wizard distincts. Identification du renderer
> actif pour un item donné = lookup heuristique sur `data.itemAttributes / data.variations / data.extras`
> + matching wizard recipe dans `pos-wizard.js`. Items "wizardés" (Tacos, Burger, Salade, Assiette)
> passent par `pos-wizard.js`. Items "simples" (Frites, Boisson, dessert) passent par `ItemComponent.vue`.

### 1.A `ItemComponent.vue` (Vue, fallback non-wizard) — **FEATURE PRESENT**

### 1.1 Évidence frontend POS

**Fichier** : `resources/js/components/admin/pos/ItemComponent.vue`

| Ligne | Élément | Comportement OOS |
|-------|---------|------------------|
| 191-216 | Bloc extras item | `<div :title="modifierUnavailableReason(extra)" :style="isModifierUnavailable(extra) ? 'opacity:.5;' : ''">` |
| 200-202 | Badge texte rupture | `<span v-if="isModifierUnavailable(extra)" class="block text-[10px] font-semibold text-danger">{{ $t('pos.item_86_d') }}</span>` |
| 211-213 | Bouton `+` désactivé | `:disabled="isModifierUnavailable(extra)"` (caissier ne peut pas augmenter quantité) |
| 545-553 | Méthode `isModifierUnavailable` | Vrai si `is_available === false` ou `status ∈ {0,2,10}` |
| 563-565 | Méthode `modifierUnavailableReason` | Retourne `modifier.unavailable_reason` (ex: `"ingredient_rupture"`) |
| 666-684 | `setExtraQuantity` | Refuse d'augmenter quantité si extra unavailable (sauf décrément) |

Le **bouton `+` est disabled** mais l'extra reste visible avec opacity .5 + texte FR "Épuisé". **La commande passe sans cet extra** car le caissier ne peut pas l'ajouter via le `+` (pattern UX cohérent avec exigence user).

### 1.2 Évidence backend (ChoiceAvailabilityResolver invoké runtime)

Tinker direct sur item 363 + branch 1 + extra 172 toggled OFF :
```json
{
  "extras_172_state": {"is_available": false, "unavailable_reason": "ingredient_rupture"}
}
```
Source : `app/Services/Stock/ChoiceAvailabilityResolver.php:284-293` (méthode `availabilityForExtra`).
Injection dans payload : `app/Http/Resources/ItemResource.php:57-61`.

### 1.3 Bundle production confirme

`public/js/pos-shell.js:8566-8582` → la build webpack contient bien `isModifierUnavailable(extra)` + le `<span>` rupture. **Pas de désynchro source/build**.

### 1.4 Pourquoi ORCHESTRATOR a vu `oosMarkedCount=0`

Test : `tests/e2e/mega-parcours-e2e-2026-05-08.spec.js:750-765` :
```js
const oosMarked = extras.filter((e) =>
  /rupture|indispo|unavailable|sold.?out|86/i.test(e.textContent + ' ' + e.className)
);
```

| Texte runtime FR (`pos.item_86_d` dans `fr.json:126`) | "**Épuisé**" |
| Regex tokens cherchés | `rupture`, `indispo`, `unavailable`, `sold.?out`, `86` |
| Match ? | **NON** — "Épuisé" ne contient aucun token regex |
| className utilisée par template | `text-danger` (Tailwind) | → ne matche pas non plus |

**Conclusion sur ItemComponent.vue** : la feature EST PRÉSENTE et le runtime FR rendrait "Épuisé" + opacity .5 + bouton `+` disabled. Le test ORCHESTRATOR aurait raté ce marqueur même si l'item ciblé utilisait `ItemComponent.vue` (regex ENG-only). Mais ce n'est PAS le path utilisé pour Tacos M (item 363) — voir 1.B.

### 1.B `public/js/pos-wizard.js` (Vanilla JS, wizard protégé) — **FEATURE MISSING**

#### Preuve screenshot
`tests/e2e/screenshots/mega-parcours-2026-05-08/pos-5-step-03-wizard-with-oos-extra.png` montre :
- Modal centrée "Menu (Frites + Boisson)" avec disclosure `+ Suppléments ▼` collapsed
- Section "Aperçu ticket" preview style ticket
- Boutons "Annuler" / "Ajouter au panier" (pas le pattern Tailwind d'`ItemComponent.vue` qui utilise `pos-v5-item-add-cta`)

→ Pattern unique de `pos-wizard.js` (Vanilla JS shim) — confirmé par `resources/views/admin-pos-v4.blade.php:35,136` qui charge `js/pos-wizard.css` + `js/pos-wizard.js`.

#### Preuve absence OOS handling
Recherche brute `is_available|unavailable_reason|isAvailable|in.*stock|out.*stock|sold.*out` dans `public/js/pos-wizard.js` → **0 occurrence**. Le wizard construit chaque extra ligne 624-637 :
```js
data.extras.forEach(function (ex) {
    var obj = {
        id: ex.id,
        name: ex.name,
        price: price,
        currencyPrice: ex.currency_price || fmtPrice(price),
        thumb: ex.thumb || null
    };
    // is_available / unavailable_reason → JAMAIS lus
});
```

#### Conséquence comportementale
Sur Tacos M (item 363) avec extra 172 (Salade) `is_available=false` :
1. Backend renvoie `is_available=false, unavailable_reason="ingredient_rupture"` ✓
2. `pos-wizard.js` reçoit la donnée et construit l'objet `{id: 172, name: "Salade", price: ..., currencyPrice: ...}` — **drop is_available**
3. UI rendue : tile Salade visible, cliquable, **aucun marqueur visuel rupture**
4. Caissier peut ajouter Salade → wizard accepte → submit POS rejette 422 backend → confusion

C'est **exactement le scénario que l'exigence user vise à éviter**.

#### Conflit frozen-zone
`docs/policies/FROZEN_ZONES.md` (et MEMORY 2026-05-06 `feedback_wizard_popup_pos_protected.md`) classe ce wizard comme protégé. **Modification requiert escalade utilisateur** ("design wizard caisse INTERDIT de modification").

Cependant : l'exigence user 2026-05-07 sur rupture extra **est elle-même un changement métier** qui contredit le frozen status précédent → conflit de priorités à arbitrer humainement.

### 1.C Test ORCHESTRATOR — Évaluation finale

Le P1 `pos-5/extra-oos-not-marked-ui` est **VRAI** pour ce wizard spécifique :
- Le wizard rendu dans le test est `pos-wizard.js`
- `pos-wizard.js` n'a aucune logique OOS
- Donc `oosMarkedCount=0` est un finding fondé

Test-debt secondaire : la regex ENG-only causerait quand même un faux-négatif si un item non-wizardé était testé. Patch regex utile pour robustesse mais **ne change pas le verdict** sur cette case spécifique.

---

## 2. KIOSK — FEATURE MISSING (vrai P1 user-facing)

C'est **la surface visée par la citation user**. L'exigence ("le client va ajouter un jambon un fromage qui n'est plus en stock") est **client-facing → kiosk est la cible centrale**, pas le POS.

### 2.1 Étapes wizard kiosk auditées

| Fichier | OOS check `is_available` ? | Badge rupture ? |
|---------|---------------------------|-----------------|
| `KioskStepSupplementsComponent.vue` | ❌ aucun | ❌ aucun |
| `KioskStepSauceComponent.vue` | ❌ aucun | ❌ aucun |
| `KioskStepViandeComponent.vue` | ❌ aucun | ❌ aucun |
| `KioskStepGarnituresComponent.vue` | ❌ aucun | ❌ aucun |
| `KioskStepPainComponent.vue` | ❌ aucun | ❌ aucun |
| `KioskStepTailleComponent.vue` | ❌ aucun | ❌ aucun |
| `KioskStepMenuComponent.vue` | ❌ aucun | ❌ aucun |
| `KioskStepGenericChoicesComponent.vue:96` | ✅ check `is_available === false` | ⚠️ classe CSS `unavailable` mais pas de texte localisé visible |

Source : recherche `grep -n "unavailable\|OOS\|rupture\|is_available\|isOos\|isOOS"` sur tous les `KioskStep*.vue` (cf preuves dans la conversation).

### 2.2 Helper `partitionKioskExtras` perd l'info

`resources/js/helpers/kioskExtrasPartition.js:69-99` :
```js
const row = { id: e.id, name: e.name || '', price, raw: e };
```
Le row exposé aux step components ne contient PAS `is_available` / `unavailable_reason` directement (uniquement via `raw.is_available`, qui n'est lu nulle part).

Conséquence : même si le backend `NormalItemResource.php:48` injecte correctement `is_available` + `unavailable_reason` (avec `surface=kiosk`, vérifié source), le frontend kiosk **ignore le champ**.

### 2.3 Pas de `KsExtraOOSBadge` ni équivalent

Le DS kiosk (`resources/js/components/frontend/kiosk/ds/`) contient :
- `KsAllergenBadge.vue` (allergènes, EAA 2025)
- `KsBadge.vue` (générique)
- pas de `KsExtraOOSBadge.vue` ni `KsRuptureBadge.vue`

Le pattern allergens (visible mid-wizard, badge inline) est **un précédent valide** à reproduire pour rupture extra : c'est un signal sécurité-sanitaire que le client doit voir au moment du choix, pas après.

### 2.4 Scénario kiosk runtime cassé (à validation user)

Avec extra 172 (Salade) `is_available=false` côté backend :
1. Client ouvre Tacos M dans kiosk
2. Atteint étape Supplements
3. Voit "Salade +1.00€" comme normal (aucun signal visuel rupture)
4. Tape pour l'ajouter → ajout silencieux dans `localSelections.supplements[172] = 1`
5. Ajout au panier → backend rejette 422 à submit (`assertSelectionsOrderable` lance "Supplément ID 172 indisponible")
6. **UX dégradée** : client surpris, doit relire le ticket pour comprendre, scénario erratique sur petit écran tactile

C'est exactement **l'inverse de la promesse user** ("le client va ajouter un jambon... ça va être écrit dessus en rupture").

---

## 3. Plan de fix scope-minimal — Kiosk

### 3.1 Budget LOC honnête

**Ce N'EST PAS ≤30 LOC.** Le scope honnête est **~60-100 LOC** réparties sur 4-6 fichiers (helper + au minimum 4 step components Sauce/Viande/Garnitures/Supplements). Bypass de l'orchestrator inline-edit exception (≤30 LOC) → requiert **plan Codex** ou **commit séparé "kiosk extras OOS V1.x"** avec sentinels.

### 3.2 Étape A — Helper `partitionKioskExtras` (8 LOC)

**Fichier** : `resources/js/helpers/kioskExtrasPartition.js`

Propager `is_available` + `unavailable_reason` :
```js
const row = {
  id: e.id,
  name: e.name || '',
  price,
  raw: e,
  is_available: e.is_available !== false,
  unavailable_reason: e.unavailable_reason || null,
};
```

### 3.3 Étape B — Step components (~12 LOC × 4 = ~50 LOC)

Pour chaque step concerné (Supplements, Sauce, Viande, Garnitures, optionnel Pain/Taille/Menu si pertinent métier), pattern uniforme calqué sur ItemComponent.vue POS :

```vue
<div
  v-for="supplement in supplementList"
  :key="supplement.id"
  class="kiosk-supplement-row"
  :class="{
    selected: supplementCount(supplement.id) > 0,
    'kiosk-variation--disabled':
      !supplementFilterAllowed(supplement) || supplement.raw?.is_available === false,
    'is-out-of-stock': supplement.raw?.is_available === false,
  }"
  :aria-disabled="(!supplementFilterAllowed(supplement) || supplement.raw?.is_available === false) ? 'true' : 'false'"
  :title="supplementOosTooltip(supplement) || supplementFilterTooltip(supplement)"
  @click="selectFromCard(supplement.id)"
>
  ...
  <span v-if="supplement.raw?.is_available === false" class="kiosk-supplement-oos-badge">
    {{ $t('pos.item_86_d') }}
  </span>
  ...
</div>
```

Et dans `methods` :
```js
supplementOosTooltip(s) {
  return s?.raw?.is_available === false
    ? (s.raw?.unavailable_reason || this.$t('pos.item_86_d'))
    : '';
},
setSupplementCount(id, count) {
  const s = this.supplementList.find((x) => x.id === id);
  if (s && (!this.supplementFilterAllowed(s) || s.raw?.is_available === false)) return;
  // ... reste inchangé
}
```

### 3.4 Étape C — DS option (optional, ~30 LOC)

Créer `resources/js/components/frontend/kiosk/ds/KsExtraOOSBadge.vue` réutilisable (pattern KsAllergenBadge), pour cohérence kiosk DS et factoring du badge sur les 4 steps. Pas bloquant V1 mais améliore la maintenance.

### 3.5 i18n — clé déjà existante

**Pas de nouvelle clé i18n requise** : `pos.item_86_d` existe en FR ("Épuisé"), EN ("Sold out"), AR ("نفذ") — réutilisable. Optional : créer `kiosk.wizard.extra_oos_short` si tonalité kiosk-spécifique souhaitée.

### 3.6 Sentinel anti-régression (~20 LOC)

`tests/js/sentinels/kioskExtrasOosBadgeStructure.spec.js` qui :
- mount KioskStepSupplementsComponent avec un supplement mocké `raw.is_available=false`
- assert présence `.is-out-of-stock` ou `.kiosk-supplement-oos-badge`
- assert `aria-disabled="true"`

Pattern existant : `tests/js/sentinels/kdsInflightOosMarkerStructure.spec.js` (déjà dans le repo, à copier-paster).

---

## 4. Test-debt secondaire (à fixer mais pas bloquant V1)

**Fichier** : `tests/e2e/mega-parcours-e2e-2026-05-08.spec.js:750-765`

Regex actuel `/rupture|indispo|unavailable|sold.?out|86/i` est ENG-only. Patch :
```js
const oosMarked = extras.filter((e) => {
  const text = (e.textContent || '').toLowerCase();
  const cls = (e.className || '').toString().toLowerCase();
  const aria = e.getAttribute('aria-disabled') === 'true';
  return /rupture|indispo|unavailable|sold.?out|épuis|نفذ|86|text-danger|out-of-stock/i.test(text + ' ' + cls)
    || aria;
});
```

Sans ce fix, le faux-négatif POS-5 se reproduira à chaque run E2E FR.

---

## 5. Sources file:line (preuves vérifiables)

### POS frontend (DEUX renderers distincts)
**Renderer A — `ItemComponent.vue` (Vue, items simples sans wizard custom — FEATURE PRESENT) :**
- `resources/js/components/admin/pos/ItemComponent.vue:191-216` — bloc extras OOS handling
- `resources/js/components/admin/pos/ItemComponent.vue:545-553` — `isModifierUnavailable`
- `resources/js/components/admin/pos/ItemComponent.vue:563-565` — `modifierUnavailableReason`
- `resources/js/components/admin/pos/ItemComponent.vue:200-202` — badge texte FR "Épuisé"
- `public/js/pos-shell.js:8566-8582` — bundle compilé (preuve désynchro absente)

**Renderer B — `pos-wizard.js` (Vanilla JS, items wizardés Tacos/Burger/Salade — FEATURE MISSING) :**
- `public/js/pos-wizard.js:624-637` — construction extras objects (drop `is_available`)
- `public/js/pos-wizard.js` (entier) — `grep is_available|unavailable_reason` = 0 occurrence
- `resources/views/admin-pos-v4.blade.php:35,136` — chargement de `pos-wizard.css` + `pos-wizard.js`
- Screenshot preuve : `tests/e2e/screenshots/mega-parcours-2026-05-08/pos-5-step-03-wizard-with-oos-extra.png` (modal "Menu (Frites + Boisson)" + "Suppléments ▼" + "Aperçu ticket")

### Backend
- `app/Services/Stock/ChoiceAvailabilityResolver.php:284-293` — `availabilityForExtra`
- `app/Services/Ingredients/IngredientAvailabilityService.php:23-71` — toggle cascade par nom
- `app/Http/Resources/ItemResource.php:47-61` — injection `is_available` extras (POS)
- `app/Http/Resources/NormalItemResource.php:39-50` — injection `is_available` extras (Kiosk)
- `app/Models/ItemExtra.php:15-25` — fillable `is_available`, `unavailable_reason`

### Kiosk frontend (gap)
- `resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue:1-457` — pas de check OOS
- `resources/js/components/frontend/kiosk/steps/KioskStepSauceComponent.vue` — pas de check OOS
- `resources/js/components/frontend/kiosk/steps/KioskStepViandeComponent.vue` — pas de check OOS
- `resources/js/components/frontend/kiosk/steps/KioskStepGarnituresComponent.vue` — pas de check OOS
- `resources/js/components/frontend/kiosk/steps/KioskStepGenericChoicesComponent.vue:15,96,217` — seul step avec OOS handling (CSS .unavailable)
- `resources/js/helpers/kioskExtrasPartition.js:69-99` — perd `is_available` du raw extra
- `resources/js/components/frontend/kiosk/ds/` — pas de `KsExtraOOSBadge.vue`

### i18n
- `resources/js/languages/fr.json:126` — `pos.item_86_d` = "Épuisé"
- `resources/js/languages/en.json:126` — `pos.item_86_d` = "Sold out"
- `resources/js/languages/ar.json:78` — `pos.item_86_d` = "نفذ"

### Test (false-negative source)
- `tests/e2e/mega-parcours-e2e-2026-05-08.spec.js:750-765` — regex ENG-only sur runtime FR
- `tests/e2e/screenshots/mega-parcours-2026-05-08/findings.json:325-339` — finding `extra-oos-not-marked-ui` P1

### Données runtime (vérifiées tinker)
- Item 363 (Tacos M) contient extras 172-194 — Salade au rang 172
- `ChoiceAvailabilityResolver->snapshotForItem($item, 1, "pos")` pour extra 172 OFF → `{is_available:false, unavailable_reason:"ingredient_rupture"}`
- Cascade `IngredientAvailabilityService->toggle("extra", 172, false, ...)` propage par nom + group_label

---

## 6. Limitations honnêtes

0. **Première version de cet audit avait inversé le verdict POS.** J'ai initialement déclaré POS=PRESENT en lisant uniquement `ItemComponent.vue` sans vérifier QUEL renderer était actif dans le test ORCHESTRATOR. La preuve screenshot a corrigé l'erreur. Leçon : pour valider un "FEATURE PRESENT", il faut prouver que le code lu **est exécuté** dans le scénario testé, pas juste qu'il existe quelque part.
1. **Test runtime kiosk non fait** : je n'ai pas vérifié runtime visuel (Playwright) du wizard kiosk avec un extra OOS — l'évidence est uniquement statique (lecture code + grep). Mais le code est sans ambiguïté : aucun consommateur de `e.is_available` dans les step components nommés.
2. **Étape Pain/Taille/Menu** : ces étapes utilisent typiquement des **variations** (pas extras). Le gap variations OOS n'est pas couvert ici — investigation séparée requise. Pour le scope user (jambon/fromage = extras), Sauce/Viande/Garnitures/Supplements sont les 4 cibles primaires.
3. **Wizard kiosk frozen-zone partielle** : `KioskWizardComponent.vue` est explicitement marqué non-frozen (cf MEMORY `feedback_kiosk_wizard_not_protected.md` 2026-05-06). Mais les step components individuels n'ont pas de mention spécifique → assume modifiables sous validation.
4. **Bundle kiosk-shell.js** : pas vérifié runtime mais la modification source-only requiert un `npm run prod` après fix (modifié dans status: `M public/js/kiosk-shell.js` — fix nécessitera nouveau bundle).
5. **Cascade toggle** : `IngredientAvailabilityService` cascade par nom — si un extra "Salade" existe dans plusieurs items (ce qu'il fait, vu dans le code), un toggle global affecte tous. Bonne nouvelle : 1 fix kiosk couvrira tous les items.
6. **Locale ENG en POS** : si un caissier sélectionne EN, le texte runtime devient "Sold out" — la regex ORCHESTRATOR matcherait. Donc le P1 POS-5 est observable uniquement en runtime FR (default project locale, à confirmer config).

---

## 7. Verdict final pour V1 release

| Question | Réponse |
|----------|---------|
| Est-ce que la commande peut passer SANS l'extra OOS ? | ✅ OUI (POS et Kiosk — backend OK, parent item pas bloqué) |
| Extra OOS visuellement marqué côté caissier — items non-wizardés (`ItemComponent.vue`) ? | ✅ OUI ("Épuisé" rouge + bouton `+` disabled + opacity .5) |
| Extra OOS visuellement marqué côté caissier — items wizardés (`pos-wizard.js`) ? | ❌ **NON** — wizard frozen ne lit jamais `is_available` |
| Extra OOS visuellement marqué côté client (Kiosk) ? | ❌ **NON** — exigence user **non remplie** sur kiosk |
| Backend rejette commande avec extra OOS ? | ✅ OUI (`assertSelectionsOrderable` → 422 "Supplément ID X indisponible") |
| Le P1 ORCHESTRATOR `pos-5/extra-oos-not-marked-ui` est-il fondé ? | ✅ OUI — sur l'item testé (Tacos M, wizardé) ; faux-négatif uniquement pour items non-wizardés |
| Le P1 vrai à corriger pour V1 ? | ✅ OUI — **kiosk + pos-wizard.js** (sous condition d'arbitrage frozen-zone) |

**Recommandation orchestrator finale** :

1. **Kiosk** (priorité absolue user-facing) : **HEAL** scope ~60-100 LOC, sentinel + e2e probe.
2. **POS pos-wizard.js** (frozen-zone) : **ESCALADE HUMAIN** — l'exigence user contredit la décision 2026-05-06 "wizard popup POS protégé". Choix utilisateur :
   - Option A : maintenir frozen → rupture extra non marquée côté caissier (UX dégradée)
   - Option B : autoriser modification scope-minimal (`pos-wizard.js` ligne 624-637 + render extra row + ligne tooltip) → ~30-50 LOC + sentinel + retest visuel manuel par user
   - Option C : migrer Tacos/Burger/Salade vers `ItemComponent.vue` Vue (refactor lourd, hors V1)
3. **Test-debt** : patch regex ENG-only `mega-parcours-e2e-2026-05-08.spec.js:754` pour matcher `épuis` + `[aria-disabled="true"]` (≤10 LOC).
4. **POS items non-wizardés** : conforme V1, pas d'action.

**Décision Claude (orchestrator)** : pas de GO V1 release sans arbitrage human sur le point #2 (frozen-zone vs exigence user). Le `block` est temporaire jusqu'à clarification — pas une dégradation du wizard ni une fuite vers production sans cette feature.
