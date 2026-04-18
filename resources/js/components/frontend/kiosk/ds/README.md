# Kiosk Design System — Atoms (Phase 0)

Atoms Vue 3 du DS Kiosk FoodKing, livrés en Phase 0 du plan d'intégration
(`MASTER PROMPT CURSOR — Intégration Design Kiosk FoodKing V1`).

> **Portée stricte Phase 0** : aucun composant kiosk existant n'est modifié.
> Ce dossier définit les briques DS réutilisables ; leur adoption dans les
> écrans se fait en Phase 2 (restyle).

## Source de vérité

| Sujet | Fichier |
|---|---|
| Tokens bruts (colors/typo/spacing) | `resources/css/kiosk/tokens.css` |
| Overrides AAA (contraste renforcé) | `resources/css/kiosk/tokens-aaa.css` |
| Overrides PMR (tap+20%, repli bas) | `resources/css/kiosk/tokens-pmr.css` |
| Inspiration React | `borne (Remix)/foodking/atoms.jsx` |
| Spec tokens W3C DTCG | `borne (Remix)/docs/design/tokens.json` |

## Namespace

Tous les tokens CSS utilisent le préfixe **`--kiosk-*`** imposé par le master prompt.
Toutes les classes atom utilisent **`ks-*`** (portée `scoped` Vue).

## Activation des modes a11y

Les modes se pilotent via des `data-*` sur `<html>` ou `<body>` :

| Mode | Attribut | Valeur |
|---|---|---|
| AA (défaut) | — | aucune |
| AAA contraste renforcé | `data-kiosk-contrast` | `"aaa"` |
| PMR (tap +20%, repli bas) | `data-kiosk-pmr` | `"true"` |
| Combo AAA + PMR | les deux attributs simultanés | OK |
| RTL | `dir="rtl"` natif | police arabe auto-chargée |

La bascule UI est implémentée en Phase 4/5 (toggles admin + settings).

## Atoms livrés

| Atom | Rôle | Props principales |
|---|---|---|
| `KsButton` | CTA (primary / secondary / ghost / danger / dark) | `variant`, `size` (lg/md/sm), `loading`, `fullWidth`, `icon` |
| `KsCard` | Conteneur produit/option | `surface`, `elevation`, `padding`, `interactive`, `selected`, `disabled` |
| `KsBadge` | Pastille statut/label | `color` (red/green/veg/halal/spicy/new/chef-pick), `size`, `soft` |
| `KsChip` | Option toggle (sauces, viandes…) | `selected`, `disabled`, `count`, `removable`, `size` |
| `KsModal` | Modal accessible (role=dialog) | `v-model`, `title`, `size`, `closable`, `backdropClose`, `escClose`, `bare` |
| `KsStepper` | Indicateur wizard à fenêtre glissante | `steps`, `current`, `numeric`, `ariaLabel` |
| `KsPriceLine` | Ligne prix / total / delta | `label`, `price` \| `delta`, `size`, `emphasis`, `locale` |

## Import

```js
// Import nommé (recommandé — tree-shaking préservé)
import { KsButton, KsCard } from '@/components/frontend/kiosk/ds';

// Ou installation globale (usage mesuré — augmente le bundle)
import KioskDesignSystem from '@/components/frontend/kiosk/ds';
app.use(KioskDesignSystem);
```

## Invariants non-négociables rappelés

- **Aucun prix calculé côté Vue**. `KsPriceLine` ne fait qu'afficher un `Number`
  fourni par le backend (payload POST `/api/frontend/order` ou réponses
  `/pricing/preview` / `/promo/validate`). Cf. invariant §1.1 du prompt maître.
- **Aucune stat dynamique** affichée client. Le badge `chef-pick` est lié à
  `is_chef_pick` (flag admin statique) — pas à des données de vente (§1.5).
- **Pas de dépendance UI lourde** : DS maison uniquement (pas de Vuetify /
  PrimeVue / autre lib de composants).

## Gate Phase 0

- [x] `tokens.css`, `tokens-aaa.css`, `tokens-pmr.css` livrés.
- [x] 7 atoms Vue livrés, prefixés `ks-*`, scoped CSS.
- [x] Aucun composant Vue kiosk existant touché.
- [ ] Revue visuelle (démo/Storybook) — à valider humain avant Phase 1.
- [ ] Build `npm run dev` vert — à lancer après merge des imports CSS.

## Prochaine étape

Phase 1 = prérequis backend (migrations + 6 endpoints). Aucun restyle Vue
avant validation Phase 0 par un humain (cf. §6 « Une phase à la fois »).
