# E2E MASSIF 2026-07-24 — Dimension E2 : WEB surfaces VISUEL + mobile

**Cible LIVE** : https://site-lecayenne.vercel.app (Vercel → VPS backend)
**Outil** : Playwright 1.58.2 (chromium desktop 1440×900 + Pixel 7 412×839). READ-ONLY.
**Spec** : `tests/e2e/_e2e-massive-E2-web-visuel-2026-07-24.spec.js` — **10/10 tests passés**.
**Captures** : `tests/e2e/__screenshots__/e2e-massive-E2/` (19 PNG + obs-*.json). Chaque PNG **lu** (multimodal).
**Santé transverse** : 0 erreur console, 0 réseau 4xx/5xx sur les 10 surfaces.

## Tableau PASS/FAIL

| # | Surface | Device | Verdict | Preuve visuelle |
|---|---------|--------|---------|-----------------|
| D1 | Home (hero + sections + CTA + footer) | Desktop | **PASS** | Hero image chargée, palette Cayenne (noir/orange/jaune), FB + 5 tuiles galerie, footer tel/adresse/horaires cohérents |
| D2 | Menu (boissons regroupées + recherche) | Desktop | **PASS** | Section « 🥤 Boissons · 15 au frais » + VOIR TOUTES ; 5 aperçus ; 0 canette grille plats ; 15 boissons au déploiement ; desserts/kids inline ; 9 cats·38 |
| D3 | Légales ×5 (mentions/cgv/privacy/cookies/allergens) | Desktop | **PASS** | 200 ; 0 « [À COMPLÉTER] » ; E.DELICE SAS, SIRET 10417050100019, RCS Béthune, TVA FR19104170501, APE 5610C ; RGPD/CGV/14 allergènes complets |
| D4 | Fidélité + Commandes | Desktop | **PASS** | États déconnectés propres (CTA « Créer mon compte »/« Me connecter »), non-blancs |
| M1 | Nav mobile (burger→tiroir) | Pixel 7 | **PASS** | Tiroir plein écran (covW 1.0), 4 liens 52px (≥44), 0 débord ouvert/fermé, tap Menu→grille OK |
| M2 | Menu mobile + légale mobile | Pixel 7 | **PASS** | 0 débord, 0 image cassée, mentions lisibles mobile |

## Captures clés (lues)
- `D1-home-hero.png` — hero signature chargé + 3 CTA + search + stats.
- `D2-menu-drinks-section.png` + `D2-menu-all-drinks.png` — regroupement boissons prouvé (15).
- `D2-menu-search-tacos.png` — recherche → Tacos M 6,90€ / Tacos L 7,90€.
- `D3-legal-mentions.png` / `D3-legal-privacy.png` — identité légale + RGPD complets.
- `M1-02-drawer-open.png` — tiroir plein écran 4 liens ; Panier + Se connecter persistants top-bar.

## Findings (défauts réels vs artefacts sonde)

**Aucun P0 / P1 / P2.** Site propre, on-brand, cohérent desktop + mobile.

**P3 (owner-data, non-bloquant)**
- Mentions/CGV : « Capital social : **à confirmer par le gérant** » et « Directeur de la publication : le représentant légal ». Champs owner-pending (PAS « [À COMPLÉTER] », juridiquement tolérés) — à renseigner par l'owner.

**P3 (perception UX)**
- Aperçu boissons : la 4ᵉ/5ᵉ carte (Sprite) rendue en fondu grisé (gradient « peek » indiquant « Voir toutes »). Intention design ; pourrait se lire comme désactivée. Cosmétique.

**Artefacts sonde (NON défauts) — écartés**
- `rawLabels=1` sur privacy/cookies = faux positif regex (domaines réels `www.cnil.fr` / `fonts.googleapis.com`), pas de label i18n brut.
- `login=false` tiroir mobile M1 = artefact (scan limité au `#lc-mobile-menu`) ; « Se connecter » + « Panier » sont dans la top-bar persistante — accessibles.

## Verdict E2 : **VERT** — 6/6 surfaces PASS, 0 défaut réel, 2 P3 owner/cosmétique.
