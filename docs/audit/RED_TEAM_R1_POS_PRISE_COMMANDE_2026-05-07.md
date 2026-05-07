# RED TEAM R1 — POS PRISE DE COMMANDE (2026-05-07)

> Document adversaire. Ne remplace pas un rapport blue team — challenge ses conclusions.

## Persona

Auditeur senior sceptique, expert UX restaurant tech, zéro complaisance.
Mission : challenger le verdict "PROD-READY" délivré sur le POS V5 par la blue team
(PASS cycles 1-7 + MEGA A-F, 1573 phpunit + 125+ Playwright + 70+ sentinels).
Hypothèse de travail : **les tests qui passent peuvent masquer des défauts UX/a11y/sec**.

## Méthodologie

- 1 spec Playwright dédiée : `tests/e2e/red-team-r1-pos-prise-commande-2026-05-07.spec.js`
- 16 tests sériels, 1 worker, server local `http://localhost:8000` réutilisé.
- Pour chaque étape : screenshot full-page + DOM probe (testids, ARIA, autocomplete, tokens) + écriture
  durable JSON dans `tests/e2e/screenshots/red-team-r1-pos-2026-05-07/findings.json` et `dom-snapshots.json`.
- Probes adversaires : double-click race, ESC sur modal, network offline, comptage POSTs avec idempotency,
  recherche XSS surface (commit `36695d24f` "v-html sanitized + sentinels tolérantisés").
- Aucun finding inventé. Quand la sonde n'a pas pu valider (ex. : flux ajout-au-panier), c'est
  explicité — pas de gold-plating.

### Évidence durable produite

- **20 screenshots PNG** (full-page) → `tests/e2e/screenshots/red-team-r1-pos-2026-05-07/`
- **2 findings JSON** (`findings.json` 24 entrées + `findings-retry.json` 3 entrées)
- **2 dom-snapshots JSON** (15 probes DOM principales + 4 probes retry strictes)
- Runs : 15/15 + 2/2 tests passants, durée 2,9 min + 1,4 min, 0 erreur infra.

### RETRY ciblée (post-conseil advisor)

Une 2e ronde a été exécutée pour combler la cascade add-to-cart :
`tests/e2e/red-team-r1-pos-retry-add-cart-2026-05-07.spec.js`. Findings additionnels :

- **CONFIRMATION strict wizard a11y** : sonde dédiée sur `.pos-v5-item-modal.active`
  → `{role: null, ariaModal: null, focusInside: false, activeId: "", activeTag: "BUTTON"}`.
  Les findings W1/W2/W3 sont **pleinement défendables**.
- **Nouvelle faille P1** : sur **15 tuiles testées**, aucune n'a son bouton `pos-v5-item-add-cta`
  enabled au chargement du wizard (toutes exigent variations). UX caissier rush : friction max
  systématique.
- **Réfutation honnête W5** : probe stricte `visibleActiveModalsCount: 0` + `roleDialogCount: 0`
  → le "21 nodes" du run principal était du noise (matched composer/wizard classnames non-modaux).
  À retirer des P2.
- **Idempotency probe non-firée** : cart restait vide (canAddToCart=false sur les 15 tuiles), donc
  le double-click sur `pos-payment-confirm` n'a jamais pu être exercé. Reste **non-validé**.

## Limitations honnêtes

Ce que cette ronde n'a **PAS** pu valider et qui doit être levé par d'autres moyens :

- **Step 5 wizard composer** — la sonde a fermé le wizard via Escape pour test, mais n'a **pas
  manipulé** variations / suppléments / sauces (nécessite connaissance du fixture produit). Verdict
  partiel — voir Q-05-* à confirmer manuellement.
- **Step 6/7/9/10 add-to-cart cassé en automation** — la tuile `Menu (Frites + Boisson)` ouvre un
  wizard wraplé, mais le bouton "Ajouter au panier" attendu ne matche pas le sélecteur générique
  `text=/ajouter au panier/i`. Conséquence : panier resté vide (cf. dom-snapshots `step-06-cart-state.itemCount=0`).
  Cela **cascade** sur step 10 (`pos-v5-pay` masqué par `v-if="carts.length > 0"` cf. PosComponent.vue:663)
  — le P0 step 10 dans findings.json n'est PAS un défaut produit, c'est un effet de cascade de la sonde.
- **Step 11-13 cash submit** — n'a jamais réellement soumis (dépend de step 6). Idempotency-Key probe
  donc inerte ce run-ci.
- **Step 14 ticket / Step 15 KDS event** — n'ont pas reçu de paiement réel ; leur valeur informative
  est nulle ce run. Vérification `domain_events` non-faite via tinker (manque de fixture submit OK).
- **TPE physique, impression NF525, Pusher live, scénario multi-caisses concurrentes** — hors-Playwright,
  non testé.

Ces limites n'invalident PAS les findings P0/P1 confirmés ailleurs (a11y wizard, login, offline).

---

## Étape 1 — Login

### Captures
- `step-01-login-empty.png`
- `step-01-login-landed.png`

### Observations dures (DOM probe)

```json
{
  "email":  { "type": "text",     "autocomplete": "",    "label": "Email"    },
  "pass":   { "type": "password", "autocomplete": "off", "label": "Password" },
  "buttons": [
    { "text": "",      "type": "submit" },   // Bouton VIDE en submit !
    { "text": "Login", "type": "submit" }
  ],
  "csrf": null,           // pas de meta csrf-token
  "hasRememberMe": false, // pas de remember me
  "lang": "en"            // mais la SPA est censée être FR par défaut
}
```

Source : `resources/js/components/frontend/auth/LoginComponent.vue:27` →
`<input autocomplete="off" type="password" ...>` — explicit override.

### Critique sévère

- ⚠️ **Faille L1 (P1)** — `autocomplete="off"` sur le password est un **anti-pattern documenté** par
  OWASP / Mozilla : il **désactive les password managers** (1Password, Bitwarden, Apple Keychain).
  En restaurant SaaS, le caissier change de poste régulièrement ; sans gestionnaire, ça **encourage
  les Post-it** sous le clavier. Devrait être `autocomplete="current-password"`.

- ⚠️ **Faille L2 (P2)** — `autocomplete=""` sur l'email + label hardcodé `"Email"` / `"Password"` en
  anglais alors que `<html lang="en">` mais `loginAsPosOperator` utilise `name: /^(login|connexion)$/i`,
  ce qui suggère un i18n bilingue censé être FR par défaut. **Désynchronisation i18n** : le label
  reste anglais. Probable bug `$t('label.email')` non-résolu côté login (ou utilisateur en local en).

- ⚠️ **Faille L3 (RÉFUTÉE après vérif source)** — La sonde a détecté 2 `type="submit"`, mais
  inspection de `LoginComponent.vue:1-67` montre 1 seul submit (ligne 48). Le 2e probablement le
  bouton close de l'alert (ligne 12, `type="button"` correct mais probe de Playwright a peut-être
  inclus un autre élément du shell admin par erreur). **Faux positif assumé — non shippable.**

- ⚠️ **Faille L4 (P2 — softened)** — Pas de `<meta name="csrf-token">` mais cookie `XSRF-TOKEN` est
  présent (axios Laravel le lit automatiquement pour les requêtes JS). Auth = JWT bearer + cookie
  XSRF probablement legacy / redundant. **Pas de risque CSRF immédiat** ; reste P2 pour clarté du
  modèle d'auth.

- ⚠️ **Faille L5 (P2)** — Pas de "remember me" alors que la persistance vuex+correlation_id passe
  déjà en localStorage post-login. UX restaurant : caissier quitte poste → se reconnecter à chaque
  retour est friction.

- ⚠️ **Observation L6 (INFO/positive)** — Token JWT **PAS en localStorage** (probe
  `tokenInLocal=false`), seulement `vuex` + `correlation_id`. Bonne hygiène. À confirmer : vuex
  persisté contient-il des données sensibles (orders en cours, branchId) ?

### Questions au blue team

- ❓ **Q-01-1** : Pourquoi `autocomplete="off"` sur le password (LoginComponent.vue:27) ? Décision
  produit ou héritage template ? Risque : password managers cassés ⇒ Post-it.
- ❓ **Q-01-2** : Le 2e bouton submit vide est-il intentionnel (icône-only) ? Si oui, pourquoi pas
  `type="button"` ? Risque : Enter accidental → soumission via mauvais handler.
- ❓ **Q-01-3** : Pourquoi `<html lang="en">` alors que la SPA est censée être FR pour la France
  (NF525 fiscal) ? L'i18n login est-il vraiment résolu via `$t()` ou hardcodé ?
- ❓ **Q-01-4** : Auth = JWT bearer ou session cookie ? Le cookie `XSRF-TOKEN` présent + l'absence de
  `meta csrf-token` est ambigu.

---

## Étape 2 — Surface POS V5

### Capture
- `step-02-surface-pos-v5.png`

### Observations dures
```json
{
  "shell": true,
  "shellClass": "pos-v5-shell fk-pos-v4 pos-v4-shell velmld-parent",
  "tokens": { "bgApp": "#FFFBF5", "brandRed": "#E8001C", "border": "#EEE6D9", "surface": "" },
  "skipLink": true,
  "lang": "en", "dir": "ltr", "focusable": 150,
  "allTestids": ["pos-cart-stat-chip","pos-tracker-open","pos-no-sale","pos-grand-total",
                 "pos-payment-confirm","receipt-print-kitchen", ...]
}
```

### Critique sévère

- ⚠️ **Faille S1 (P1)** — Token CSS `--pos-v5-surface` **vide** au runtime (autres tokens OK). Soit
  variable jamais déclarée, soit fallback silencieux. À retrouver dans `resources/sass/admin/pos-v5/`.

- ⚠️ **Faille S2 (P2)** — `<html lang="en">` même côté admin POS — confirme i18n drift. NF525 exige
  ticket FR ; si `lang` global influe sur formattage (intl) → risque de tickets formatés en-US
  (virgule décimale, devise mal placée).

- ⚠️ **Faille S3 (P2)** — **150 éléments focusables** détectés sur la surface POS. C'est ÉNORME pour
  un caissier au clavier (Tab tour ~150 frappes pour explorer). Devrait être segmenté en zones
  (catégories / grille / panier) avec focus traps régionaux ou roving tabindex.

- ⚠️ **Faille S4 (P2)** — Console émet **7 warnings** au load surface POS. Bruit anormal. Suggère
  composables non-cleanés ou warnings Vue (props validation, deprecation).

- ⚠️ **Faille S5 (P2)** — Classes mixées **V4 + V5 sur le même shell** :
  `pos-v5-shell fk-pos-v4 pos-v4-shell` + `pos-v4-cart-panel pos-v5-cart`. Migration incomplète,
  styles potentiellement antagonistes. Quelle classe gagne en cas de conflit CSS ?

### Questions au blue team

- ❓ **Q-02-1** : Pourquoi `--pos-v5-surface` est vide ? Token oublié dans la design tokens
  cascade ? Quel composant l'utilise et quel fallback est appliqué ?
- ❓ **Q-02-2** : Les 7 console warnings au load — quels sont-ils ? (Lister via `--reporter=line`).
  Triviaux ou cachent-ils un défaut ?
- ❓ **Q-02-3** : Cohabitation V4/V5 sur le même shell — quel est le plan de désamorçage ?
  Classes V4 sont-elles encore référencées par CSS actif ou pur dead code ?

---

## Étape 3 — Sélection catégorie

### Captures
- `step-03-categories-before.png`
- `step-03-categories-after-click.png`
- `step-03-categories-double-click.png`

### Observations dures
```json
{
  "role": "tab",
  "ariaSelected": "true",
  "tabIndex": 0,
  "text": "Toutes les catégories",
  "classList": "pos-v5-category pos-v4-category-pill is-active"
}
```

### Critique sévère

- ⚠️ **Faille C1 (P2)** — `role="tab"` mais où est le `role="tablist"` parent et
  `role="tabpanel"` cible ? Sans la triade, **screen readers ne suivent pas la navigation**.
  À vérifier : `PosComponent.vue:141-160`.

- ⚠️ **Faille C2 (P2)** — Double-click rapide sur catégorie : pas de désactivation visuelle
  observée (pas de skeleton, pas de loader). Risque : si filtrage est async/lent, l'utilisateur
  re-click et déclenche 2 fetchs.

- ⚠️ **Faille C3 (INFO)** — Catégorie "Toutes les catégories" est sélectionnée par défaut avec
  `is-active`. Bon. Mais **pas d'icône fallback différenciée** quand la catégorie n'a pas d'image
  (cf. `pos-v5-category__visual-fallback` ligne 158) — affiche juste l'initiale. Lisibilité en
  rush ?

### Questions au blue team

- ❓ **Q-03-1** : Si catalogue change (ingrédient OOS), la catégorie filtre-t-elle dynamiquement ?
  L'audit n'a pas testé `IngredientAvailabilityService` live.
- ❓ **Q-03-2** : Combien de catégories max le strip supporte-t-il avant scroll horizontal
  forcé ? Test avec 30+ catégories ?

---

## Étape 4 — Sélection produit + ouverture wizard ⚠️ CRITIQUE A11Y

### Captures
- `step-04-grid.png` (64 tuiles produit)
- `step-04-after-tile-click.png` (wizard ouvert)

### Observations dures
```json
"step-04-tile-0": {
  "text": "Menu (Frites + Boisson)3.00€",
  "role": null, "tagName": "BUTTON",
  "ariaLabel": "Add Menu (Frites + Boisson), 3.00€",
  "imageAlt": "Menu (Frites + Boisson)"
},
"step-04-wizard-state": {
  "count": 21,           // 21 nodes "modal/dialog/composer/wizard" simultanés !
  "samples": [{
    "tag": "DIV",
    "classes": "modal ff-modal pos-v4-item-wizard-modal pos-v5-item-modal active",
    "role": null,
    "ariaModal": null,
    "ariaLabel": null,
    "focusTrapped": false
  }, ...],
  "bodyOverflow": "auto hidden"
}
```

Source : `resources/js/components/admin/pos/ItemComponent.vue:64` →
```vue
<div id="item-variation-modal" ref="itemVariationModal"
     class="modal ff-modal pos-v4-item-wizard-modal pos-v5-item-modal"
     :data-pos-drinks-catalog="drinksCatalogJson">
```

### Critique sévère ⚠️⚠️⚠️

- 🔴 **Faille W1 (P1, A11Y BLOQUANT)** — Wizard popup ouvert **SANS `role="dialog"`**
  (`role: null`). Screen readers (NVDA, JAWS, VoiceOver) ne l'annoncent **PAS comme un dialogue**.
  Caissier malvoyant perd complètement le contexte.

- 🔴 **Faille W2 (P1, A11Y BLOQUANT)** — **SANS `aria-modal="true"`**. Screen readers ne savent pas
  que les éléments derrière sont logiquement inertes. Tab nav fuit derrière le popup.

- 🔴 **Faille W3 (P1, A11Y BLOQUANT)** — **`focusTrapped: false`** : le focus actif n'est PAS dans
  le wizard à l'ouverture. L'utilisateur clavier doit chercher où il est.

- ⚠️ **Faille W4 (P2)** — `bodyOverflow: "auto hidden"` est suspect (deux valeurs concaténées —
  `overflow-x:auto overflow-y:hidden`). Le scroll horizontal de body reste possible quand wizard
  ouvert.

- ⚠️ **Faille W5 (RÉFUTÉE après probe stricte)** — La sonde initiale comptait 21 matches sur
  sélecteur large (`[class*="composer"], [class*="wizard"], .modal`), mais probe stricte
  `.modal.active` + `[role="dialog"]` retourne 0. **Le compte de 21 était du noise** (composer
  step list sidebar, wizard helper classes non-modaux). Pas de leak modal. À retirer.

- ⚠️ **Faille W6 (INFO)** — `aria-label="Add Menu (Frites + Boisson), 3.00€"` sur la tuile = mot
  "Add" en anglais → confirme i18n drift sur les `aria-label` (vs UI texte FR).

### Questions au blue team

- ❓ **Q-04-1** : Comment un caissier malvoyant utilise-t-il le wizard (variations/suppléments)
  sans role/aria-modal/focus trap ? Avez-vous un audit a11y formel sur ItemComponent.vue ?
- ❓ **Q-04-2** : Pourquoi 21 nodes modal/dialog en DOM ? Plusieurs wizards instanciés en parallèle
  (un par item du grid) ou pollution V4 jamais détruite ?
- ❓ **Q-04-3** : Le focus à l'ouverture devrait aller sur le titre ou le 1er bouton du wizard.
  Y a-t-il un `nextTick + el.focus()` quelque part ? Sinon, c'est P1 a11y FERME.

---

## Étape 5 — Wizard composer (interactions)

### Capture
- `step-05-wizard-open.png` / `step-05-wizard-after-esc.png` (non capturé — sonde a perdu le DOM
  modal entre-temps)

### Observations
La sonde n'a **pas trouvé le wizard** ouvert au moment de la 2e tentative
(`step-05-wizard-inputs.found: false`). Hypothèse : Le `await page.waitForTimeout(800)` est trop
court ou la tuile cliquée n'a pas relevé le wizard à ce run-ci.

### Critique sévère (basée sur ce qui A pu être observé step 4)

- ⚠️ **Faille WC1 (P1)** — Pas de probe inputs/variations possible ce run. **Les variations,
  suppléments, sauces, qty stepper, instruction notes ne sont PAS validés** par le red team R1.
- ⚠️ **Faille WC2 (P2)** — Bouton close `lab-close-circle-line font-fill-danger` (ItemComponent.vue:85)
  utilise icône Lab + couleur danger. **Iconographie ambiguë** : fermer ≠ supprimer. Caissier rush
  pourrait croire qu'il annule la commande.

### Questions au blue team

- ❓ **Q-05-1** : Si le wizard contient un champ "instruction client" (textarea libre), est-ce
  XSS-sanitisé côté backend ? Le commit `36695d24f` mentionne `safeHtml()` côté KsThemeToggle ;
  la même logique s'applique-t-elle aux notes commande affichées sur tickets et KDS ?
- ❓ **Q-05-2** : Variations sont-elles backend-priced (référence à `IngredientService` /
  `ChoiceAvailabilityResolver`) ou frontend bypass possible ? Manipulation `addons[addon.id].price`
  côté DOM avant submit ?
- ❓ **Q-05-3** : Le wizard a-t-il un timeout d'inactivité (caissier abandonne → autre client) ?
  Ou reste-t-il ouvert indéfiniment avec données stale ?

---

## Étape 6 — Add to cart

### Capture
- `step-06-cart-with-item.png`

### Observation honnête (limite sonde)

La sonde n'a **PAS** réussi à ajouter au panier via `text=/ajouter au panier/i`. Le wizard
ItemComponent.vue:293 a un footer sticky `pos-add-to-cart-sticky` avec un bouton dont le label
réel devrait être inspecté (cf. ItemComponent.vue:290+).

### Critique sévère

- ⚠️ **Faille A1 (P1, présomption)** — Si le label réel du bouton n'est pas standardisé (FR/EN
  switch, ou icon-only, ou `$t('button.add_to_order')`), le red team E2E a du mal à le sélectionner.
  Cela suggère **fragilité de testabilité** : pas de `data-testid="pos-wizard-add-to-cart"` dédié.

- ⚠️ **Faille A2 (P2)** — Aucun `data-testid` parmi les 9 sample testids de surface POS ne
  correspond à "add-to-cart wizard". L'inventaire testid V5 est lacunaire pour le composant le plus
  cliqué de la journée.

### Questions au blue team

- ❓ **Q-06-1** : Pourquoi pas de `data-testid="pos-wizard-add-to-cart"` (ou équivalent) sur le
  bouton sticky ItemComponent.vue ? Sentinel testabilité manquante ⇒ régression silencieuse possible.
- ❓ **Q-06-2** : Si l'utilisateur double-click sur "Ajouter au panier" très vite, est-ce qu'on
  ajoute 2× la ligne ou 1× ? Le bouton est-il `:disabled` pendant l'animation ?
- ❓ **Q-06-3** : Toast / feedback visuel après ajout (cart pulse, badge update) ? Ou silent ?

---

## Étape 6bis (RETRY) — UX caissier rush ⚠️ NOUVELLE FAILLE

### Capture
- `retry-after-add-attempts.png` (15 wizards ouverts/fermés successivement)

### Observations dures (probe RETRY)

```json
"wizard-a11y-strict": {
  "found": true,
  "role": null, "ariaModal": null, "ariaLabel": null,
  "focusInside": false,
  "activeId": "", "activeTag": "BUTTON"
}
```

(probe ciblée sur `.pos-v5-item-modal.active` — confirme W1/W2/W3 sur le sélecteur strict)

```json
"cart-after-add": { "count": 0, "grand": "Total0.00€", "payVisible": false }
```

Sur **15 tuiles successivement ouvertes**, **0 ne s'ajoute au panier sans interaction additionnelle**.
Source : `ItemComponent.vue:296` →
```vue
<button type="button" :disabled="!canAddToCart" @click.prevent="addToCart"
        class="pos-v5-item-add-cta ..."> ...
```

Le `canAddToCart` est `false` par défaut sur tous les items du seed → **toutes les tuiles
exigent au moins 1 sélection variation/choix avant que le CTA s'active**.

### Critique sévère

- 🔴 **Faille R-Rush1 (P1, UX MÉTIER)** — Aucun produit "tap-and-go" dans le seed. Un caissier
  rush (commande simple, ex: "1 cafe") doit **systématiquement** ouvrir le wizard, scroller des
  variations, faire 1+ click. **Friction inutile** quand le panier devrait pouvoir absorber des
  lignes simples directes. Comparer aux POS pros (Lightspeed, Square) : tap = ajout direct si
  l'item n'a pas de modifiers.

- ⚠️ **Faille R-Rush2 (P2)** — `pos-v5-item-add-cta` n'a **aucun `data-testid`** — confirmé sur
  les 15 tuiles. **Sentinel testabilité manquante** sur le composant le plus cliqué de la journée.
  Régression future invisible aux 1573 phpunit tests qui n'ont pas de couverture E2E sur ce CTA.

- ⚠️ **Faille R-Rush3 (P2)** — Pas d'indicateur visuel "ce produit nécessite des choix" sur la
  tuile elle-même (badge, picto). Le caissier découvre la friction en cliquant — gaspille un tap.

### Questions au blue team

- ❓ **Q-R6-1** : Le seed contient-il un produit sans variations obligatoires (ex: boisson simple,
  cafe) ? Si non, c'est artificiel à des tests ; si oui, pourquoi le wizard s'ouvre quand même ?
- ❓ **Q-R6-2** : Combien de clicks médians pour une commande type "1 burger 1 boisson" ? Métrique
  UX manquante.

---

## Étape 7 — Cart edits (qty, suppr)

### Capture
- `step-07-cart-loaded.png` (panier vide ⇒ items=0, cf cascade)

### Critique sévère (basée sur source PosComponent.vue:451-525)

- ⚠️ **Faille E1 (P1)** — `<li class="pos-v5-cart-item">` (PosComponent.vue:451) a un bouton
  `pos-v5-cart-item__edit` (ligne 468) pour rouvrir le wizard. **Aucune confirmation explicite
  avant suppression** détectée dans le grep. Vérifier : suppr ≠ ressaisir tout le wizard.

- ⚠️ **Faille E2 (P2)** — `data-testid="pos-cancel-last-line"` (ligne 276) existe MAIS implique
  uniquement la **dernière ligne**. Pas de `data-testid="pos-cart-line-delete-N"` indexé. Difficile
  à tester par E2E, donc difficile à protéger.

### Questions au blue team

- ❓ **Q-07-1** : Suppression d'une ligne déclenche-t-elle un toast undo (5 sec) ? UX moderne
  attendue.
- ❓ **Q-07-2** : Si caissier modifie qty d'une ligne avec ingredient OOS entre-temps (Echo event
  reçu), que se passe-t-il ? `IngredientAvailabilityService` synchronise-t-il à la modif ?

---

## Étape 8 — Customer selector

### Capture
- `step-08-customer-selector.png`

### Observations
5 contrôles client trouvés. Inputs `placeholder="Sélectionner un client"` + `placeholder="Nom du
client"` (FR) côtoient un bouton `aria-label="Add Customer"` (EN). **i18n drift confirmé**.

### Critique sévère

- ⚠️ **Faille CS1 (P2)** — `aria-label="Add Customer"` mais placeholder FR. **Cohérence i18n
  cassée** dans la même zone UI.
- ⚠️ **Faille CS2 (P2)** — `placeholder="Sélectionner un client"` sans `<label>` visible (cf.
  attribute label probe = null). Screen readers récitent placeholder qui disparaît à la frappe.
  Conformité WCAG 2.4.6 douteuse.

### Questions au blue team

- ❓ **Q-08-1** : Walk-in (sans client identifié) est-il l'état par défaut, ou faut-il **toujours**
  sélectionner ? Friction inutile si toujours.

---

## Étape 9 — Discount

### Capture
- `step-09-discount.png`

### Observation honnête

`pos-discount-input` non visible — probablement caché tant que panier vide (`v-if`
conditionnel à des items). Sonde n'a pas pu valider le test "discount 999%".

### Critique sévère (basée sur source PosComponent.vue:582-630)

- ⚠️ **Faille D1 (P1)** — Existence de `data-testid="pos-discount-reason-required-flag"` (ligne 612)
  + `pos-discount-reason` (623) suggère un schéma "raison obligatoire". MAIS l'input lui-même
  (ligne 582) n'a **pas de `min`/`max`/`step` HTML5** détecté dans le DOM probe.
  **Validation client-only** = backend doit absolument re-valider. Si bypass en POST direct
  `/api/pos/order` avec discount=999, que se passe-t-il ? **À tester impérativement.**

- ⚠️ **Faille D2 (P0?)** — Sans cap UI explicite, un caissier complice pourrait tester
  `-100%` (gratuit) ou `999%` (?). **Audit fraude interne** : le journal NF525 capture-t-il
  cette manip ? Discount approval workflow (manager PIN) ?

### Questions au blue team

- ❓ **Q-09-1** : Le backend `OrderService` re-valide-t-il `discount <= price * coefficient` ou
  fait-il confiance au payload frontend ?
- ❓ **Q-09-2** : `data-testid="pos-discount-reason-invalid"` existe — quels sont les critères
  de validité (longueur min, blacklist, regex) ? Audit traçabilité fraude.
- ❓ **Q-09-3** : Discount au-delà d'un seuil (5%, 10%) déclenche-t-il un manager PIN modal ?
  Pratique standard SaaS POS.

---

## Étape 10 — Bouton Pay + ouverture modal paiement

### Capture
- `step-10-pre-pay.png`

### Observation honnête (cascade)

`pos-v5-pay` non visible. Source : `PosComponent.vue:663` →
```vue
<div v-if="carts.length > 0" class="flex flex-col gap-2">
  <PosV5Button data-testid="pos-v5-pay" ...>
```

C'est conditionnel à panier non-vide. Cascade depuis step 6 défaillant côté sonde — **pas
un défaut produit**. Le P0 dans findings.json doit être requalifié en INFO.

### Critique sévère (basée sur source)

- ⚠️ **Faille P1 (P2)** — Bouton pay caché (`v-if`) au lieu de désactivé (`:disabled`). UX :
  **caissier ne voit pas le CTA principal** quand panier vide → confusion "où je clique pour
  payer ?". Devrait être visible mais disabled avec tooltip "Ajouter un article d'abord".

- ⚠️ **Faille P2 (P2)** — Texte du bouton : `{{ $t('button.order') }} · {{ grandTotalDisplay }}`.
  Concatène libellé + total avec `·`. **Lisibilité en rush** : caissier ne voit pas distinction
  action/montant.

### Questions au blue team

- ❓ **Q-10-1** : Pourquoi `v-if` plutôt que `:disabled` sur le pay button ? Décision UX
  documentée ?
- ❓ **Q-10-2** : Si network slow et `orderSubmit` met 5 sec, le bouton est-il `:loading` /
  `:aria-busy` ? Caissier va spammer.

---

## Étape 11-13 — Cash + numpad + submit

### Capture
- `step-11-payment-modal-open.png` / `step-13-after-double-submit.png` (cascade — pas de cart)

### Observations source

`PaymentComponent.vue:264` → bouton confirm a :
```vue
:disabled="loading.isActive || (paymentMode === 'multi' && !canConfirmMulti)"
:aria-busy="loading.isActive"
```

**Bonne défense client-side** contre double-submit (commentaire `[AUDIT-P2]` ligne 256).

`store/modules/posOrder.js:187-193` → `X-Idempotency-Key` est bien envoyé dans header.
Middleware backend `app/Http/Middleware/IdempotencyKeyMiddleware.php` existe avec UNIQUE
(branch_id, idempotency_key).

### Critique sévère

- ⚠️ **Faille PM1 (P1)** — `idempotency_key` est généré **côté frontend** :
  `${Date.now()}_${Math.random()...}_${branchId}` (PosComponent.vue:2635). Si **2 onglets
  ouverts** ou **horloge décalée**, collision possible. La protection unique (branch_id, key) côté
  middleware sauve, mais l'expérience utilisateur pourrait devenir "Idempotency-Key-Conflict"
  silencieux.

- ⚠️ **Faille PM2 (P2)** — Pas de `data-testid` sur les digits du numpad (grep PosV5Numpad.vue =
  empty). Difficile à tester E2E. Sentinel manquant.

- ⚠️ **Faille PM3 (P1)** — `:aria-disabled="loading.isActive || ..."` sur le bouton confirm est
  bon, mais **le `aria-busy` n'est pas accompagné d'un `<div role="status" aria-live="polite">`
  visible** pour annoncer "Paiement en cours...". Screen reader silencieux pendant 1-3 sec.

### Questions au blue team

- ❓ **Q-13-1** : Le commit `36695d24f` a "tolérantisé" 2 sentinels PaymentComponent
  (`paymentComponentPropMutation.spec.js`). Lecture du diff : ils accept maintenant des emits
  additionnels comme `"order:confirmed"`. **Question** : pourquoi ce nouvel emit a-t-il été
  introduit ? Quel parent l'écoute ? Sentinel "tolérantisé" peut masquer une régression future
  (ex. quelqu'un ajoute `"payment-form:nuke"` et le test passe).
- ❓ **Q-13-2** : Si l'API renvoie 409 Idempotency-Key-Conflict, l'UX caissier est-elle gérée ?
  Toast spécifique ? Re-soumission ? Ou écran blanc ?
- ❓ **Q-13-3** : Network slow (3s+) + caissier appuie 5× sur confirm — `loading.isActive` se
  pose immédiatement (synchrone) ou après le await ? Race possible si pose async.

---

## Étape 14 — Ticket + impression

### Capture
- `step-14-post-paiement-state.png`

### Observation honnête

Pas de paiement effectif → `receiptVisible: false, successToast: false`. **Étape non testée
réellement.** À refaire avec un fixture qui peut soumettre un order via API (bypass wizard) puis
vérifier le rendu de `ReceiptComponent.vue`.

### Critique potentielle (source)

- ⚠️ **Faille R1 (P2)** — Présence de `ReceiptDuplicataMarker.vue` séparé suggère duplicate
  reprint. NF525 exige marquage "DUPLICATA" sur réimpressions. **À vérifier** : marker est-il
  toujours rendu en cas de re-print ?

### Questions au blue team

- ❓ **Q-14-1** : Si l'imprimante est offline / hors-papier, le ticket est-il enqueued, ou
  l'order est-il simplement complété sans trace papier ? Conformité NF525 ?
- ❓ **Q-14-2** : Ticket affiché à l'écran après paiement — pendant combien de temps avant
  retour au menu ? Caissier suivant peut voir données client précédent ?

---

## Étape 15 — KDS event dispatch

### Capture
- `step-15-kds-state-pos-side.png`

### Observation honnête

**Vérification `domain_events` non-faite** ce run (pas de paiement réel, donc pas d'event
émis). À ré-exécuter via :
```bash
php artisan tinker --execute="echo \DB::table('domain_events')->latest('id')->first()?->event_name;"
```

### Questions au blue team

- ❓ **Q-15-1** : Paiement → événement `OrderConfirmed` (ou similaire) émis dans `domain_events`
  ET broadcasté via Pusher au KDS — quel garant si Pusher tombe ? Polling fallback ?
- ❓ **Q-15-2** : Idempotency : si le même ordre est confirmé 2× (race), 2 events `OrderConfirmed`
  se retrouvent-ils en KDS ? Dédup côté projection ?

---

## Étape 16 — Offline behavior

### Capture
- `step-16-offline-tile-click.png`

### Observations dures
```json
{ "hasOfflineBanner": false, "navigatorOnLine": false }
```

### Critique sévère

- 🔴 **Faille O1 (P1, OPS BLOQUANT)** — `navigator.onLine === false` ET **AUCUN banner
  "offline/connexion perdue" affiché**. Le caissier continue à cliquer des produits
  **sans savoir que rien ne se synchronise**. En kiosk il y a `kioskOfflineQueue.js` mais
  **côté POS admin, rien**. Un service de soir → 1h de commandes potentiellement perdues
  silencieusement.

### Questions au blue team

- ❓ **Q-16-1** : Y a-t-il un offline queue côté POS (équivalent
  `helpers/kioskOfflineQueue.js`) ? Si non, **pourquoi** ? Le POS est aussi exposé aux coupures
  Wifi.
- ❓ **Q-16-2** : Sans banner visuel, comment le caissier sait-il qu'il est offline ? Test UX
  manqué dans les 1573 phpunit tests ?

---

## Annexe — Thread "sentinels tolérantisés" (commit 36695d24f)

Le blue team a commit le **3 mai 2026** un fix qui :
1. Ajoute `safeHtml()` (DOMPurify) sur `KsThemeToggle.vue` v-html — bonne pratique XSS.
2. **Tolérantise 2 sentinels PaymentComponent** : remplace
   `expect(source).toContain('emits: ["payment-form:patch", "payment-form:reset"]')`
   par regex match qui accepte n'importe quel autre event additionnel
   (ex. `"order:confirmed"` mentionné en commit message).

### Critique adversaire

- ⚠️ **Faille X1 (P2)** — Le sentinel "tolérantisé" **n'est plus une signature stricte**. Demain,
  un agent peut ajouter `emits: ["payment-form:patch", "payment-form:reset", "payment-form:nuke",
  "any-event"]` et le sentinel passe. **Sentinel à valeur défensive amoindrie.** Décision
  documentée comme "verrouille intent" mais ne verrouille en réalité que les 2 events nommés.
  Si l'intent est "events explicites pour parent-state", alors le sentinel devrait **interdire
  l'ajout silencieux** d'events sans review humaine.

- ⚠️ **Faille X2 (P1, MÉTHODOLOGIE)** — Le commit a été réalisé en `EXECUTE_DELEGATION:
  claude-orchestrator (in-session direct edit)` — **violation Rôle Separation** (CLAUDE.md
  §4 : "Claude does not directly implement code as its main function"). **Bypass de la
  discipline orchestrator/executor** justifié par "Codex Pro saturé". Si répété, érode la
  doctrine.

- ⚠️ **Faille X3 (INFO)** — `KsThemeToggle.vue` est un composant **kiosk** (pas POS), mais le
  commit dit "Fix 3 régressions DS POS V5". **Mauvaise classification produit** dans le commit
  message (kiosk ≠ POS).

### Questions au blue team

- ❓ **Q-X-1** : Le sentinel `paymentComponentPropMutation` accepte maintenant n'importe quel
  event additionnel. Quelle est la **liste autorisée explicite** d'events pour PaymentComponent ?
  Documentée où ?
- ❓ **Q-X-2** : Pourquoi le commit `[CV1-DS-XSS-CLEANUP-001]` parle de "POS V5" alors que le
  v-html sanitized est sur **kiosk** (`components/frontend/kiosk/ds/KsThemeToggle.vue`) ?
  Confusion produit ou refactoring incomplet ?
- ❓ **Q-X-3** : "EXECUTE_DELEGATION: claude-orchestrator" — c'est un bypass documenté. Combien
  d'autres commits récents bypass la discipline cursor-executor ? Audit méthodologie nécessaire.

---

## SYNTHÈSE ADVERSAIRE

| Métrique                                       | Valeur                                       |
|------------------------------------------------|----------------------------------------------|
| Étapes parcourues                              | 16/15 (1 offline bonus) + 1 retry ciblée     |
| Captures Playwright                            | **20** (full-page PNG)                       |
| Findings totaux (durables JSON)                | **27** (24 main + 3 retry)                   |
| **P0 confirmés runtime**                       | **0**                                        |
| **P1 confirmés runtime (a11y/UX/ops)**         | **5** (W1, W2, W3, O1, R-Rush1)              |
| **P1 source-grep / suspects à valider**        | **3** (D1/D2 discount cap, L1 autocomplete, X1/X2 sentinel) |
| **P2 confirmés**                               | **11**                                       |
| Failles **réfutées** post-vérif                | **2** (L3 boutons submit, W5 21 modals)      |
| Non-validés ce run (cascade)                   | 3 (step 5 wizard inputs, idempotency, KDS)   |
| Questions critiques au blue team               | **29** (Q-01-1 à Q-R6-2)                     |

### Top 5 failles (P1) — ordre d'urgence (post-RETRY révision)

1. **W1/W2/W3 — Wizard popup A11Y triple manquement** (`role`, `aria-modal`, focus trap absents)
   sur `ItemComponent.vue:64`. **Confirmé 2× par probe stricte.** Bloquant pour caissier malvoyant,
   fail audit RGAA. **DOM evidence non-équivoque**.
2. **O1 — Aucun banner offline côté POS** alors que `navigator.onLine === false`. Kiosk a une queue
   (`kioskOfflineQueue.js`), POS n'a rien. Silent data loss possible. DOM evidence directe.
3. **R-Rush1 — Aucun produit tap-and-go dans le seed POS** (15/15 tuiles testées exigent
   sélection wizard avant ajout). UX caissier rush dégradée vs concurrents (Lightspeed, Square).
4. **L1 — Login `autocomplete="off"` sur password** (LoginComponent.vue:27). Anti-pattern OWASP +
   password managers cassés ⇒ Post-it sous le clavier en restaurant.
5. **X1/X2 — Sentinel `paymentComponentPropMutation` tolérantisé** (commit 36695d24f) +
   méthodologie bypass orchestrator. Le sentinel n'interdit plus l'ajout silencieux d'events à
   PaymentComponent — discipline test érodée.

> **D1/D2 (discount cap)** retiré du top 5 : non-validé runtime ce run (cart vide → discount input
> caché). Reste **suspect P1, à auditer impérativement** — pas un défaut confirmé.
> **Idempotency probe** : non-firée (cascade RETRY add-to-cart), donc claim "frontend dedupe"
> reste **non-vérifié runtime**.

### Top 10 questions au blue team

1. **Q-04-1** : Comment un caissier malvoyant utilise-t-il le wizard sans role/aria-modal/focus
   trap ? Audit a11y formel existe-t-il ?
2. **Q-16-1** : Pourquoi pas d'offline queue côté POS (vs kiosk) ?
3. **Q-09-1** : Backend OrderService re-valide-t-il discount cap ou fait-il confiance frontend ?
4. **Q-13-2** : Idempotency-Key-Conflict (HTTP 409) — UX caissier gérée ?
5. **Q-X-1** : Liste autorisée explicite d'events PaymentComponent — documentée où ?
6. **Q-04-2** : 21 nodes modal/dialog en DOM simultanés — leak ou cohabitation V4/V5 ?
7. **Q-01-1** : Pourquoi `autocomplete="off"` sur password (anti-pattern OWASP) ?
8. **Q-02-1** : Token `--pos-v5-surface` vide au runtime — oubli ou fallback silencieux ?
9. **Q-15-2** : Race idempotency → 2 events `OrderConfirmed` au KDS ? Dédup côté projection ?
10. **Q-09-3** : Discount au-delà d'un seuil → manager PIN modal ? (Pratique standard SaaS POS)

### Verdict adversaire

> **NON PROD-READY EN L'ÉTAT** — verdict blue team "PASS" ne tient pas devant 6 P1 a11y/UX/ops
> confirmés sur évidence durable (DOM probe + screenshots), 4 zones non-validées (cascade sonde) à
> ré-instrumenter, et un thread méthodologique (commit 36695d24f sentinel-tolérantisation +
> bypass orchestrator) qui érode la discipline. **Conditions à lever** : a11y wizard P1×3,
> banner offline POS, cap discount backend, autocomplete password, sentinel re-strictification.
> Les 1573 phpunit + 125+ Playwright valident la backbone fonctionnelle ; ils **ne valident pas
> l'expérience caissier réelle**.
