# KIOSK DESIGN V1 — Rapport d'exécution Phase 0

- **Date** : 2026-04-18
- **Phase** : 0 — Design System & tokens
- **Scope** : tokens CSS + 7 atoms Vue DS, aucune modification d'écran existant
- **Statut** : ✅ livré, en attente de revue humaine avant Phase 1

---

## 1. Livrables

### 1.1 Tokens CSS (`resources/css/kiosk/`)


| Fichier          | Rôle                                                       | Taille     |
| ---------------- | ---------------------------------------------------------- | ---------- |
| `tokens.css`     | Base AA — namespace `--kiosk-`* imposé par le prompt       | 157 lignes |
| `tokens-aaa.css` | Overrides contraste renforcé (`data-kiosk-contrast="aaa"`) | 78 lignes  |
| `tokens-pmr.css` | Overrides PMR +20% (`data-kiosk-pmr="true"`)               | 75 lignes  |


**Choix techniques :**

- Préfixe `--kiosk-`* imposé par le master prompt §0.1. Le DS livré dans
`borne (Remix)/kiosk-ds/tokens.css` utilisait `--fk-`* — non conservé.
- Sémantique alignée sur les valeurs **AA renforcées** spécifiées par le prompt
(`--kiosk-success #1B8A3A`, `--kiosk-error #C21E2F`, `--kiosk-warning #B8730B`)
— plus foncées que `atoms.jsx` (`#16A34A`/`#DC2626`/`#F59E0B`). Choix conforme
§1.7 invariants accessibilité.
- Typographie / spacing / radii / shadows / motion / layout / z-index / a11y
tirés de `borne (Remix)/docs/design/tokens.json` (format W3C DTCG) et
normalisés en CSS custom properties.
- Multiplicateur `--kiosk-text-scale` exposé pour AAA (×1.10) et PMR (×1.20),
consommé par les atoms via `font-size: calc(… * var(--kiosk-text-scale))`.
- `@media (prefers-reduced-motion)` met toutes les durations à 0 ms.
- RTL : `[dir="rtl"]` bascule `font-family` sur Noto Sans Arabic.

### 1.2 Atoms Vue (`resources/js/components/frontend/kiosk/ds/`)


| Atom              | Rôle                                                       | Props publiques notables                                                       |
| ----------------- | ---------------------------------------------------------- | ------------------------------------------------------------------------------ |
| `KsButton.vue`    | CTA primaire (aligné `FkButton`)                           | `variant`, `size`, `loading`, `fullWidth`, `icon`, `disabled`                  |
| `KsCard.vue`      | Conteneur produit / option                                 | `surface`, `elevation`, `padding`, `interactive`, `selected`, `disabled`       |
| `KsBadge.vue`     | Pastille statut / label                                    | `color` (red/green/veg/halal/spicy/new/chef-pick…), `size`, `soft`, `iconOnly` |
| `KsChip.vue`      | Option toggle (sauces / viandes / garnitures)              | `selected`, `disabled`, `count`, `removable`, `size`                           |
| `KsModal.vue`     | Modal `role="dialog"` + focus trap + body-scroll lock      | `v-model`, `title`, `size`, `closable`, `backdropClose`, `escClose`, `bare`    |
| `KsStepper.vue`   | Indicateur wizard (aligné `FkProgress`, fenêtre glissante) | `steps`, `current`, `numeric`, `ariaLabel`                                     |
| `KsPriceLine.vue` | Ligne prix (label / valeur, delta signé, total)            | `label`, `price` ou `delta`, `size`, `emphasis`, `locale`                      |


**Conventions :**

- Options API (`export default { … }`) pour homogénéité avec les composants
kiosk existants (`<script>` + `props:` + `methods:`). Vue 3.5.31 confirmé
dans `package.json`.
- Classes internes préfixées `ks-`*, styles `scoped`. Aucune classe `kiosk-`*
touchée.
- Accessibilité :
  - `KsButton` : `aria-busy`, `aria-disabled`, `focus-visible` avec ring 4 px.
  - `KsCard` (interactive) : `role="button"`, `tabindex`, `aria-pressed`,
  gestion `Enter` / `Space`.
  - `KsChip` : `aria-pressed`, `× count` en `aria-label`.
  - `KsModal` : `aria-modal`, `aria-labelledby`, trap focus minimal, restauration
  de l'élément actif à la fermeture, scroll body locké.
  - `KsStepper` : `role="progressbar"`, `aria-valuenow/min/max`, `aria-valuetext`
  en français lisible.
- Zero dépendance externe (pas de lib tierce).
- Zero logique métier : aucun atom ne calcule de prix, ne lit `branch_id`,
ni ne déclenche d'appel backend.

### 1.3 Entry & barrel


| Fichier                                               | Rôle                                              |
| ----------------------------------------------------- | ------------------------------------------------- |
| `resources/js/components/frontend/kiosk/ds/index.js`  | Barrel exports + plugin `KioskDesignSystem`       |
| `resources/js/components/frontend/kiosk/ds/README.md` | Doc atoms, modes a11y, invariants                 |
| `resources/js/bootstrap-kiosk.js`                     | Entry DS : charge les 3 CSS + réexporte les atoms |


---

## 2. Gate Phase 0 — check-list


| Item                                    | Statut | Détail                                                                                                            |
| --------------------------------------- | ------ | ----------------------------------------------------------------------------------------------------------------- |
| `resources/css/kiosk/tokens.css` créé   | ✅      | 157 lignes, namespace `--kiosk-*`                                                                                 |
| `tokens-aaa.css` créé                   | ✅      | Override `data-kiosk-contrast="aaa"`                                                                              |
| `tokens-pmr.css` créé                   | ✅      | Override `data-kiosk-pmr="true"`                                                                                  |
| 7 atoms Vue créés                       | ✅      | `KsButton`, `KsCard`, `KsBadge`, `KsChip`, `KsModal`, `KsStepper`, `KsPriceLine`                                  |
| Aucun composant Vue existant modifié    | ✅      | `git status` inchangé sur `resources/js/components/frontend/kiosk/*.vue` et `steps/*.vue` (hors `?? ds/` nouveau) |
| Aucun import dans `resources/js/app.js` | ✅      | Voir §3 risque identifié                                                                                          |
| Lint / ReadLints passe                  | ✅      | 0 erreur sur les 12 nouveaux fichiers                                                                             |
| Démo atoms affichable                   | ⚠      | Non implémentée (pas de Storybook côté projet). Alternative proposée §3                                           |
| Tokens consommés par les atoms          | ✅      | Vérifié par lecture : chaque atom n'utilise que `var(--kiosk-*)`                                                  |


---

## 3. Risques & écarts vs master prompt

### 3.1 Bootstrap global NON câblé — choix conservateur

Le master prompt §0.4 demande :

> Charger tokens.css en amont via resources/js/bootstrap-kiosk.js ou l'entrypoint kiosk existant.

Le gate Phase 0 demande en parallèle :

> aucun composant Vue existant encore touché.

**Conflit identifié :** le fichier legacy `resources/css/kiosk-wizard.css` déclare
déjà les variables `--kiosk-primary` (`#E93C3C` — **ancien rouge erroné**),
`--kiosk-touch-min: 64px`, `--kiosk-success: #43C6AC` (teal au lieu de vert),
`--kiosk-bg: #F8F9FA` (cool gray au lieu de warm `#FFFBF5`), etc. Importer
`tokens.css` au `:root` global **overriderait ces variables** et provoquerait
un repaint visuel des écrans kiosk existants → violation du gate.

**Décision :** `bootstrap-kiosk.js` est livré **sans wiring** dans `app.js`.
Il sera activé au tout début de Phase 2, en même temps que le premier restyle
Vue qui consomme les atoms. À ce moment-là :

- Option A : import de `bootstrap-kiosk.js` dans `resources/js/app.js`.
- Option B : rationaliser `resources/css/kiosk-wizard.css` pour déléguer
la palette à `tokens.css` (recommandée, supprime les conflits de variables).

Risque résiduel si la revue humaine exige un câblage immédiat :

- **Impact** : petite régression visuelle (couleurs plus proches de la vraie
charte FoodKing) sur les écrans existants. Touch targets passeraient de
64 → 48 px — **régression a11y non voulue**.
- **Mitigation** : si câblage immédiat souhaité, je **modifie en plus**
`kiosk-wizard.css` pour retirer les redéclarations de `--kiosk-`* qui
collisionnent. Sortirait du gate Phase 0 → doit être validé explicitement.

### 3.2 Démo Storybook absente

Le projet n'intègre pas Storybook. Le prompt autorise « storybook/démo des
atoms affichable ». Proposition pour la revue humaine :

- Option 1 : ajouter un composant `KioskDsPreview.vue` (hors dossier `kiosk/`,
ex. `resources/js/components/dev/`) et une route dev-only pour l'afficher.
- Option 2 : ajouter une page HTML statique `public/kiosk-ds-preview.html`
important uniquement `bootstrap-kiosk.js` compilé. Aucune dépendance à Vue
runtime nécessaire côté plate-forme.

Non livré en Phase 0 pour rester strictement dans le périmètre demandé.
À décider humainement avant de démarrer Phase 1.

### 3.3 Valeurs sémantiques : prompt vs atoms.jsx

Le master prompt impose des valeurs AA renforcées (`#1B8A3A`, `#C21E2F`,
`#B8730B`) qui **diffèrent** des sources `atoms.jsx` et `tokens.json`. Choix
du prompt conservé. Les badges `veg/halal/spicy` et surfaces secondaires
restent alignés sur `tokens.json`. Documenté dans `tokens.css` en commentaire.

---

## 4. Inventaire git

Nouveaux fichiers (non trackés) :

```
resources/css/kiosk/tokens.css
resources/css/kiosk/tokens-aaa.css
resources/css/kiosk/tokens-pmr.css
resources/js/bootstrap-kiosk.js
resources/js/components/frontend/kiosk/ds/KsButton.vue
resources/js/components/frontend/kiosk/ds/KsCard.vue
resources/js/components/frontend/kiosk/ds/KsBadge.vue
resources/js/components/frontend/kiosk/ds/KsChip.vue
resources/js/components/frontend/kiosk/ds/KsModal.vue
resources/js/components/frontend/kiosk/ds/KsStepper.vue
resources/js/components/frontend/kiosk/ds/KsPriceLine.vue
resources/js/components/frontend/kiosk/ds/index.js
resources/js/components/frontend/kiosk/ds/README.md
reports/execution/KIOSK_DESIGN_V1_PHASE_0_2026-04-18.md
```

Fichiers modifiés : **aucun**.

---

## 4bis. Audit profond Phase 0 — correctifs appliqués (2026-04-18, post-livraison)

Audit systématique déclenché à la demande explicite de l'utilisateur. Passage
atome par atome + scan templates via node + `ReadLints`.

### 🔴 Bug critique identifié — corrigé

`**KsChip.vue` : deux `<button>` imbriqués (removable + bouton parent).**
HTML5 interdit les boutons imbriqués (spec `button` §4.10.6 : "Content model:
Phrasing content, but there must be no interactive content descendant"). Les
navigateurs séparent silencieusement les éléments → comportement incohérent
(focus piégé sur l'enfant, events ne bubblent pas).

**Fix** : transformation du parent en `<div role="button" tabindex>` + handlers
`@keydown.enter.prevent` / `@keydown.space.prevent`. Le sous-bouton `×`
(remove) reste un `<button>` natif, plus de violation. Pattern conforme
WCAG Authoring Practices 1.2 « Button (Disclosure) ».

### 🟡 Amélioration — appliquée

`**KsStepper.vue` : `aria-valuetext` hardcodé en français.**
Phase 0 tourne en FR, mais le projet exige i18n EN/AR en Phase 4. Ajout
d'une prop `formatAriaValueText: Function` avec default FR. Permet
d'injecter le store i18n en Phase 4 sans toucher l'atom.

### Vérifications systématiques passées ✅


| Item                                                                     | Méthode                               | Résultat                                        |
| ------------------------------------------------------------------------ | ------------------------------------- | ----------------------------------------------- |
| Nested `<button>` dans tous atomes                                       | scan regex node                       | 0 après fix                                     |
| Balance `<div>/<span>/<button>`                                          | scan regex node                       | balanced                                        |
| Variables `--kiosk-*` référencées                                        | grep scoped CSS                       | 247 références, toutes déclarées                |
| Collisions legacy `kiosk-wizard.css`                                     | inspection app.css                    | aucune (bootstrap-kiosk.js non câblé, cf. §3.1) |
| RTL `dir="rtl"` isolé à police                                           | lecture tokens.css                    | OK, aucun layout-inversion globale              |
| Modes AAA + PMR combinables                                              | cascade CSS                           | OK, overrides indépendants                      |
| Text scale × multiplicateur                                              | `calc(Npx * var(--kiosk-text-scale))` | appliqué 18 fois dans les atomes                |
| `prefers-reduced-motion`                                                 | @media                                | durations → 0 ms                                |
| Focus-visible sur tous les éléments interactifs                          | inspection                            | KsButton, KsCard, KsChip, KsModal close         |
| Aria-pressed / aria-busy / aria-disabled / aria-modal / role=progressbar | inspection                            | présents                                        |
| Logique métier (pricing, branch_id, stats)                               | relecture full                        | **zéro** — atomes 100% présentationnels         |
| SSOT prix : KsPriceLine ne calcule rien                                  | inspection                            | OK (Intl.NumberFormat uniquement)               |
| `ReadLints` sur les 12 fichiers                                          | tool                                  | 0 erreur                                        |


### Points volontairement différés

- **Focus trap complet `KsModal`** : implémentation minimale (déplace le focus
sur le panel à l'ouverture, restaure à la fermeture). Pas de Tab cyclique.
Décision : kiosk tactile, clavier virtuel géré Phase 4.6 — trap complet
coûteux pour gain marginal. À reconsidérer si KsModal ressort en app POS.
- **Composable `useKioskTheme()`** (toggles AAA / PMR / audio) : Phase 5,
non pertinent Phase 0.

---

## 5. Décision demandée avant Phase 1

1. **Approuver / refuser** les 12 livrables.
2. **Trancher §3.1** : bootstrap kiosk.js câblé tout de suite (avec
  rationalisation `kiosk-wizard.css`) ou activation reportée à Phase 2.
3. **Trancher §3.2** : démo DS (Vue dev-only ou HTML statique) nécessaire
  pour le gate, ou visuel différé à Phase 2.

Une fois validé, j'enchaîne **Phase 1 — Prérequis backend** :

- Migrations : `categories.parent_id`, `allergens` + pivot, `upsell_rules`,
`kiosk_promos`, `branches.available_locales`.
- 6 endpoints kiosk SSOT + tests PHPUnit `branch_id` scopé.
- Aucun touch Vue.

---

**Fin de rapport Phase 0.**