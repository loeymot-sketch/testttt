# Kiosk Seasonal Themes — Operator Guide

[Wave Gamma G3 / V2-5] Skinning saisonnier — comment ajouter et activer un
thème kiosk.

---

## Vue d'ensemble

Le kiosk FoodKing supporte un mécanisme de **skinning saisonnier** :
l'admin pousse un slug (ex. `halloween`, `christmas`) dans
`branches.active_kiosk_theme`, le kiosk lit ce slug au boot et pose
`data-kiosk-theme="<slug>"` sur `<html>`. Les fichiers
`resources/css/kiosk/themes/<slug>.css` déclarent leurs overrides sous le
sélecteur `:root[data-kiosk-theme="<slug>"]`.

Aucun composant Vue n'est modifié — uniquement des **variables CSS**
override-ables via le contract `_base.css`.

---

## Ajouter un nouveau thème (3 étapes)

### Étape 1 — Créer le fichier CSS

`resources/css/kiosk/themes/<slug>.css` :

```css
:root[data-kiosk-theme="<slug>"] {
    /* Surcharger uniquement les variables AUTORISÉES par le contract.
       Voir resources/css/kiosk/themes/_base.css pour la liste exhaustive. */
    --kiosk-primary:       #...; /* CTA primaire */
    --kiosk-primary-dark:  #...;
    --kiosk-primary-soft:  #...;
    --kiosk-accent:        #...; /* couleur d'appoint */
    --kiosk-bg:            #...;
    --kiosk-surface:       #...;
    --kiosk-text:          #...;
    --kiosk-text-muted:    #...;
    --kiosk-text-on-red:   #...;

    --kiosk-theme-emoji-prefix: '...'; /* ex. '🌸' */
    --kiosk-theme-pattern:      none;  /* url('/images/themes/<slug>.svg'); */
    --kiosk-theme-banner-text:  '... bannière ...';
}
```

### Étape 2 — Inscrire le slug dans la liste blanche

**JS** (`resources/js/services/kioskThemeManager.js`) :

```js
export const SUPPORTED_THEMES = Object.freeze([
    'standard',
    'halloween',
    'christmas',
    'paques', // ← nouveau slug
]);
```

**PHP** (`app/Http/Controllers/Admin/KioskThemeController.php`) :

```php
public const SUPPORTED_THEMES = [
    'standard',
    'halloween',
    'christmas',
    'paques', // ← nouveau slug
];
```

> ⚠ Les deux listes DOIVENT rester synchrones. Sinon un thème poussé par
> l'admin sera accepté côté backend mais ignoré côté kiosk (fallback
> `'standard'`), ou inversement.

### Étape 3 — Importer le CSS au boot kiosk (Phase 2+)

`resources/js/bootstrap-kiosk.js` :

```js
import '../css/kiosk/themes/paques.css';
```

> **Phase 0/1 (actuel)** : `bootstrap-kiosk.js` n'est pas encore câblé.
> Les fichiers CSS thèmes vivent en greenfield et seront importés en
> Phase 2 (alignée sur le restyle Vue). Pas d'action requise tant que
> ce fichier n'est pas activé sur `app.js`.

---

## Theme Contract (allowed / disallowed overrides)

Voir `resources/css/kiosk/themes/_base.css` pour la liste exhaustive.

### ✅ Autorisé à override
- **Marque & accents** : `--kiosk-primary`, `--kiosk-primary-dark`,
  `--kiosk-primary-soft`, `--kiosk-accent`.
- **Surfaces** : `--kiosk-bg`, `--kiosk-surface`, `--kiosk-surface-alt`,
  `--kiosk-text`, `--kiosk-text-muted`, `--kiosk-text-on-red`.
- **Décoratives** : `--kiosk-theme-emoji-prefix`, `--kiosk-theme-pattern`,
  `--kiosk-theme-banner-text`.

### ❌ Interdit à override
- **Tailles typographiques** (`--kiosk-font-size-*`) — utiliser
  `--kiosk-text-scale` réservé aux modes AAA/PMR.
- **Spacing** (`--kiosk-space-*`) — grille verticale partagée.
- **Tap targets** (`--kiosk-touch-*`) — contrainte WCAG 2.2 AA.
- **Focus ring** (`--kiosk-focus-*`) — contraste 3:1 obligatoire.
- **Sémantique** (`--kiosk-success`, `--kiosk-error`, `--kiosk-warning`,
  `--kiosk-info`) — couleurs d'état métier, jamais déco.
- **Badges produits** (`--kiosk-badge-*`) — info allergène / chef-pick.
- **Z-index** (`--kiosk-z-*`).
- **Durations** (`--kiosk-duration-*`) — respecte `prefers-reduced-motion`.

---

## Tests d'a11y obligatoires AVANT publication d'un thème

Tout nouveau thème doit valider :

1. **Contraste texte / fond** ≥ 4.5:1 (WCAG 2.2 AA — body) et ≥ 3:1 pour
   les grandes tailles (≥ 22 px / bold ≥ 18 px). Outils :
   [Coolors](https://coolors.co/contrast-checker), `axe DevTools`.
2. **Focus ring** : contraste de `--kiosk-focus-ring` ≥ 3:1 sur la
   nouvelle `--kiosk-bg` ET la nouvelle `--kiosk-surface`.
3. **Pas de confusion sémantique** : la couleur primaire ne doit pas être
   confondue avec une couleur sémantique. Cas typique : un thème orange
   ne doit pas faire ressembler un CTA primaire à un warning. Si la
   confusion existe, ajuster la teinte ou la saturation.
4. **Performance** : si vous utilisez `--kiosk-theme-pattern`, le SVG/PNG
   doit faire **moins de 4 KB** pour ne pas dégrader le LCP en mode
   réseau dégradé (Electron + WiFi salle).
5. **Test manuel** : screenshot Playwright `KioskCategoriesRestyle.spec.js`
   avant/après avec le thème actif pour valider visuellement.

---

## Activation côté admin

### Via l'API (manuel)

```bash
# Pousser un thème actif sur la branche 42
curl -X PATCH https://app.foodking.com/api/admin/kiosk-theme/42 \
     -H "Authorization: Bearer <token>" \
     -H "x-api-key: <api-key>" \
     -H "Content-Type: application/json" \
     -d '{"theme":"halloween"}'
```

### Via le dashboard (V1.y)

Un onglet "Thème kiosk" sera ajouté dans `Settings > Kiosk` avec une
liste déroulante des thèmes supportés. Preview iframe en V2.

---

## Comportement runtime kiosk

Au boot, `kioskThemeManager.initialize(branchId)` résout le thème selon
cette priorité (documentée dans le service) :

1. **localStorage** (`kiosk_active_theme`) — sticky côté borne, survit
   aux restarts Electron, indépendant du réseau.
2. **Backend** `GET /api/admin/kiosk-theme/{branchId}` — source de
   vérité poussée par l'admin via PATCH.
3. **`'standard'`** — fallback marque FoodKing.

> ⚠ **Limite connue (V2)** : un kiosk déjà boot avec un thème en
> localStorage ne picke pas un changement admin avant son prochain
> restart. La V2 branchera un événement Pusher `KioskThemeChanged` qui
> fera appeler `forceRefreshFromBackend(branchId)` côté JS pour pousser
> le nouveau thème en live.

---

## Schedule auto (V2)

Roadmap V2 : table `kiosk_theme_schedules` consommée par un cron
quotidien qui flip `branches.active_kiosk_theme` selon le calendrier.

```
+----+------------+-------+-------------+-------------+
| id | branch_id  | slug  | starts_at   | ends_at     |
+----+------------+-------+-------------+-------------+
|  1 | 42         | xmas  | 2026-12-01  | 2026-12-31  |
|  2 | 42         | nye   | 2026-12-31  | 2027-01-02  |
+----+------------+-------+-------------+-------------+
```

---

## Liens

- Plan complet : [`plans/PLAN_DESIGN_V2_5_SKINNING_SAISONNIER_2026-05-08.md`](../plans/PLAN_DESIGN_V2_5_SKINNING_SAISONNIER_2026-05-08.md)
- Theme contract : [`resources/css/kiosk/themes/_base.css`](../resources/css/kiosk/themes/_base.css)
- Manager JS : [`resources/js/services/kioskThemeManager.js`](../resources/js/services/kioskThemeManager.js)
- Controller : [`app/Http/Controllers/Admin/KioskThemeController.php`](../app/Http/Controllers/Admin/KioskThemeController.php)
