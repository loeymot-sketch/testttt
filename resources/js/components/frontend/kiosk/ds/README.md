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
| **Bold Appétissant — palette warm light + dark** | `resources/css/kiosk/tokens-bold.css` |
| **Bold Appétissant — typo Fraunces + scale display** | `resources/css/kiosk/typography-bold.css` |
| **Composable theme switching (light/dark/auto)** | `resources/js/composables/useKioskTheme.js` |
| Inspiration React | `borne (Remix)/foodking/atoms.jsx` |
| Spec tokens W3C DTCG | `borne (Remix)/docs/design/tokens.json` |
| **Plan refonte CV1-KIOSK-VISUAL-REDESIGN-001** | `plans/PLAN_CV1-KIOSK-VISUAL-REDESIGN-001_2026-05-02.md` |
| **Maquette HTML Bold Appétissant** | `reports/design/KIOSK_REDESIGN_BOLD_PREVIEW_2026-05-02.html` |

## Namespace

Tous les tokens CSS utilisent le préfixe **`--kiosk-*`** imposé par le master prompt.
Toutes les classes atom utilisent **`ks-*`** (portée `scoped` Vue).

## Activation des modes a11y

Les modes se pilotent via des `data-*` sur `<html>` ou `<body>` :

| Mode | Attribut | Valeur | Source |
|---|---|---|---|
| AA (défaut) | — | aucune | `tokens.css` |
| AAA contraste renforcé | `data-kiosk-contrast` | `"aaa"` | `tokens-aaa.css` + `tokens-bold.css` cascade |
| PMR (tap +20%, repli bas) | `data-kiosk-pmr` | `"true"` | `tokens-pmr.css` |
| Reduced motion | `data-kiosk-reduced-motion` | `"true"` | `tokens-bold.css` + `cv1-tokens.css` |
| **Theme bold (V1.4)** | `data-kiosk-theme` | `"light"` \| `"dark"` (jamais `auto` — toujours résolu) | `tokens-bold.css` cascade ; piloté par `useKioskTheme` composable |
| Combo AAA + PMR + dark | les attributs simultanés | OK | cascade complète |
| RTL | `dir="rtl"` natif | police arabe auto-chargée | `tokens.css` `[dir="rtl"]` |

La bascule UI est implémentée en Phase 4/5 (toggles admin + settings) ; le toggle thème est ajouté en V1.4 via `KsThemeToggle` dans `KsA11ySettings`.

## V1 Bold Appétissant — Discipline ADDITIVE

Tous les variants livrés en V1.5+ (KsButton hero/ghost-bold/pop, KsCard hero/option-bold/summary, KsChip composition/included, KsBadge promo/included/quota/price-impact, KsModal warm-blur, KsStepper minimal-bar, KsPriceLine size=hero) :

- **Coexistent** avec les variants V1 baseline — aucun écran existant impacté
- **Consomment** uniquement les nouveaux tokens `--kiosk-bold-*`
- **Héritent automatiquement** du switch light/dark via la cascade `[data-kiosk-theme='dark']`
- **Respectent** AAA (cascade `[data-kiosk-contrast='aaa']`) et reduced-motion (cascade `[data-kiosk-reduced-motion='true']`)

Les écrans kiosk (idle, categories, wizard, cart, checkout) seront refondus en V2-V4 et adopteront ces variants progressivement. Tant qu'ils ne le font pas, ils restent en V1 baseline (light only).

## Atoms livrés

| Atom | Rôle | Props principales |
|---|---|---|
| `KsButton` | CTA (primary / secondary / ghost / danger / dark / **hero / ghost-bold / pop**) | `variant`, `size` (lg/md/sm/**hero-xl**), `loading`, `fullWidth`, `icon` |
| `KsCard` | Conteneur produit/option | `surface` (default/alt/**bold/option-bold/summary/hero**), `elevation` (flat/card/lift/**card-bold/hero/pop**), `padding`, `interactive`, `selected`, `disabled` |
| `KsBadge` | Pastille statut/label | `color` (red/green/veg/halal/spicy/new/chef-pick/**promo/included/quota/price-impact**), `size`, `soft` |
| `KsChip` | Option toggle (sauces, viandes…) | `selected`, `disabled`, `count`, `removable`, `size`, **`variant`** (default/composition/included) |
| `KsModal` | Modal accessible (role=dialog) | `v-model`, `title`, `size`, `closable`, `backdropClose`, `escClose`, `bare`, **`tone`** (default/warm-blur) |
| `KsStepper` | Indicateur wizard à fenêtre glissante | `steps`, `current`, `numeric`, `ariaLabel`, **`variant`** (full/minimal-bar) |
| `KsPriceLine` | Ligne prix / total / delta | `label`, `price` \| `delta`, `size` (sm/md/lg/**hero**), `emphasis`, `locale`, **`bold`** |
| **`KsThemeToggle`** | Sélecteur thème (light/dark/auto) — V1.4 | `v-model`, `ariaLabel`, `testid`, `labels` |
| **`KsHero`** | Bandeau d'identité (strip/cinematic/banner) — V1.11 | `variant`, `density`, `title`, `eyebrow`, `subtitle`, `photo`, `titleScale`, slots `composition`/`price`/`aside` |
| `KsA11ySettings` | Drawer paramètres a11y (drawer-style modal) | `v-model` ; intègre `KsThemeToggle` (V1.4) |
| `KsVirtualKeyboard` | Clavier virtuel layouts FR/EN/AR | — |
| `KsConsentModal` | Dialog RGPD opt-in loyalty + analytics | — |
| `KsFilterChip` | Chip de filtre catalogue | — |
| `KsAllergenBadge` | Badge allergène (FR/EN/AR) | — |

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
