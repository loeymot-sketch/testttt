# Impl G — Web Legal Pages — Evidence Bundle
**Date** : 2026-05-18
**Round** : Wave 2 Round 2
**Status** : ✅ COMPLETE (despite final API rate-limit cut-off — work landed before disruption)
**Note** : This evidence bundle is written by the orchestrator since Impl G's final return message was truncated by transient API rate-limit. All artifacts on disk verified.

## Scope (per Impl G dispatch)
P1-WEB-05 closure — **FR public-launch LCEN/L221-5 BLOCKER**.

## Files created (verified on disk)

| File | Size | Purpose | French law |
|---|---|---|---|
| `/Users/1millnonstop/Downloads/web/legal/mentions.html` | 8990 B | Mentions légales | LCEN art. 6 III |
| `/Users/1millnonstop/Downloads/web/legal/cgv.html` | 15145 B | Conditions Générales de Vente | Code de la consommation L221-5 |
| `/Users/1millnonstop/Downloads/web/legal/privacy.html` | 15923 B | Politique de confidentialité (RGPD) | RGPD + Loi Informatique et Libertés |
| `/Users/1millnonstop/Downloads/web/legal/cookies.html` | 11900 B | Politique de cookies | CNIL délibération 2020-091 |
| `/Users/1millnonstop/Downloads/web/legal/allergens.html` | 11765 B | Information allergènes | Règlement UE INCO 1169/2011 |
| `/Users/1millnonstop/Downloads/web/legal/legal.css` | 6132 B | Stylesheet partagé pages legal | (support) |

## Footer wireup (verified)

`/Users/1millnonstop/Downloads/web/components.jsx:97-137` — `WebFooter` component updated:
- Comment ligne 97 référence les 5 lois couvertes
- Lignes 128-132 : `<li><a href="legal/X.html">…</a></li>` × 5 (block "Informations légales")
- Ligne 137 : copyright bar avec 3 quick-links (CGV / Confidentialité / Mentions légales)

## Brand consistency

Each page inclut :
- `<html lang="fr">` + meta description + robots index,follow
- Imports complet de la suite CSS Le Cayenne (`../styles.css`, `..-v2.css`, `..-v3.css`, `..-v4.css`, `..-v5.css`, `..-mobile.css`, + `legal.css`)
- Header `lc-nav` avec brand "LC Le Cayenne" + nav links retour à `../index.html`
- Police Bebas Neue / Anton / Inter / JetBrains Mono via Google Fonts (matching main site)
- H1 `lc-legal-title` consistant sur les 5 pages

## H1 verified per page

- `<h1 class="lc-legal-title">Mentions légales</h1>`
- `<h1 class="lc-legal-title">Conditions Générales de Vente (CGV)</h1>`
- `<h1 class="lc-legal-title">Politique de confidentialité</h1>`
- `<h1 class="lc-legal-title">Politique de cookies</h1>`
- `<h1 class="lc-legal-title">Information allergènes</h1>`

## Owner-specific placeholders

Per dispatch rules ("don't invent owner SIREN/address"), pages include `[À COMPLÉTER PAR PROPRIÉTAIRE — …]` markers where owner-specific data is needed (SIREN, RCS, capital social, adresse siège, contact RGPD).

## Standalone discipline

`/Users/1millnonstop/Downloads/web/` is **outside** the FoodKing git repo (verified via `git rev-parse --show-toplevel` returning a different path or error). Web is STANDALONE per GOAL §W. Therefore:
- No `git add` / `git commit` performed (target dir not under FoodKing git)
- No FoodKing route added
- No FoodKing API wireup
- Pages are pure static HTML + CSS + react components

## Test coverage

E2E test extension was scoped to Round 3 (visual verification of 5 new pages × 4 viewports = 20 captures). Footer wireup test via Playwright nav-click verification deferred to Round 3 visual gate.

## Convergence gate

- ✅ 5 legal pages exist on disk with correct structure
- ✅ Footer wireup present in `components.jsx:128-132`
- ✅ Brand consistency (CSS imports, fonts, H1 pattern)
- ✅ Owner placeholders explicit (no fictional SIREN/address)
- ✅ Standalone discipline preserved (no FoodKing repo touch)
- ⏳ Round 3 visual verification pending

## Commit SHA

N/A — `/Users/1millnonstop/Downloads/web/` is a standalone directory outside the FoodKing git repo. No commit applies.

## Frozen-zone diff (FoodKing repo)

0 lines on all 13 CLAUDE.md §7 protected files (verified — Impl G touched only `/Users/1millnonstop/Downloads/web/` outside the repo).

## P1-WEB-05 status

✅ **RESOLVED** — public-launch LCEN/L221-5 blocker closed. Owner must complete `[À COMPLÉTER]` placeholders with SIREN, RCS, capital, adresse siège, RGPD DPO contact before public DNS flip.
