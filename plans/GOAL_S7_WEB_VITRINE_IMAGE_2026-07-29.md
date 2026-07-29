# GOAL S7 — SITE VITRINE : L'IMAGE DE MARQUE (niveau O'Tacos et au-delà) (2026-07-29)

> Tu es le LEAD VITRINE. Lis `plans/DISCIPLINE_MULTI_SESSIONS_2026-07-29.md`
> (surtout **§10 TRIO WEB** — S4 et S8 travaillent sur le MÊME repo) D'ABORD.
> Mission owner : un site MAGNIFIQUE qui met en valeur les produits et les
> points forts du Cayenne — **viande fraîche · frites fraîches maison · fait
> maison · savoureux · qualité** — inspiré du niveau du concurrent O'Tacos
> (structure, mise en valeur, animations, expérience) SANS jamais rien copier.
> Convergence §6, autonomie §7.

## Ownership (STRICT — §10)
- Repo web : `screens.jsx` (home/menu présentation), `components.jsx` (visuels),
  `screens-v3.jsx` (fiche produit présentation), **nouveau** `styles-v6-brand.css`,
  `assets/**` (images), pages légales (style seulement).
- Partagés avec pull-rebase obligatoire : `index.html` (lien CSS, metas, cache-bust).
- ⛔ INTERDITS : wizard-v2/flows/upsell/funnel/api/data-menu (S8) ; backend (S4) ;
  styles v1-v5 EN RÉÉCRITURE (surcharges dans TON v6 uniquement).

## Règle d'or « inspiration, jamais copie »
Analyse O'Tacos (o-tacos.com) via captures Playwright LUES : structure des
sections, hiérarchie visuelle, rythme des animations, mise en scène produit.
Tu t'inspires des PRINCIPES — tu n'importes AUCUN asset, texte, logo, couleur
signature ou photo. Notre identité : palette Cayenne (#F4501E orange, #FFB800
jaune, #1A1A1A ink, crème), typo Anton/Bebas/Inter existante, NOS photos
détourées (`assets/menu/` — liste owner validée ; inventorie-la en V1).

## Vagues
### V1 — Moodboard comparatif + inventaire assets
Captures O'Tacos (home, menu, fiche) + captures NOTRE site — analyse côte à
côte par agents UX (hiérarchie, photos, sections, animations, storytelling).
Inventaire complet `assets/` (poids, détourage, qualité, manquants → liste pour
l'owner SANS bloquer). Écart = backlog priorisé `V1-MOODBOARD.md`.
Acceptance : analyse lue + backlog priorisé P1/P2/P3.

### V2 — HOME hero & storytelling points forts
Hero produit spectaculaire (photo + composition), section « pourquoi Le
Cayenne » : 🥩 viande fraîche · 🍟 frites fraîches maison · 👨‍🍳 fait maison ·
⭐ savoureux — avec micro-animations d'apparition (CSS only, IntersectionObserver
ok, `prefers-reduced-motion` respecté, JAMAIS de transform sur un conteneur l l
d'éléments `position:fixed` — leçon CTA bar). Sections réordonnées pour vendre.
Acceptance : captures avant/après mobile+desktop lues + 0 régression nav-smoke.

### V3 — MENU & fiches : mise en valeur produit
Cartes produit plus appétissantes (photo dominante, prix clair, badges
signature/nouveau), transitions de survol/tap, fiche produit immersive (photo
plein cadre, description qui donne faim, allergènes discrets). Filtres/catégories
visuellement premium. Perf : images lazy, poids surveillé (budget < existant +10 %).
Acceptance : avant/après lues + TTI non dégradé (mesure home <1,5 s réseau réel).

### V4 — Finitions & cohérence totale
Footer, pages légales habillées, favicons/manifest, états vides/erreur brandés,
cohérence typographique, dark-abstinence (light only), micro-détails (scrollbar,
sélection, focus rings brandés accessibles).
Acceptance : tour COMPLET du site capturé + lu, zéro page « pauvre ».

### V5 — Convergence
nav-smoke 13/13 ×2 + adversarial UX 2 cycles propres (dont un agent « client
exigeant qui compare à O'Tacos » qui doit conclure : le nôtre est au niveau ou
au-dessus) + deploy Vercel + BRAIN + memory.

## Perf & technique
CSS-only autant que possible ; pas de lib externe (CSP/CDN policy du site :
React/Babel existants seulement) ; ES5-safe hors JSX ; cache-bust à chaque push.
