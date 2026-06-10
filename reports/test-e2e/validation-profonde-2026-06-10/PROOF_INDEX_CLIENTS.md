# PROOF INDEX — CLIENTS UPDATE & VALIDATION (App mobile + Site web) 2026-06-10

> GOAL owner : mettre à jour les 2 apps client selon toutes les updates faites, audit+test profond avec adversaires, boucle e2e jusqu'à validation, couverture 100%.
> Mobile = `mobile/` repo testttt, branche `heal/mobile-update-2026-06-10` (worktree dédié). Web = `/Users/1millnonstop/Downloads/web` branche `main` @ `4f3d902`. 0 push (gate owner §10). Standalones — 0 backend touché, NO-wireup.

## P0 — Plan audité par 2 adversaires → NEEDS_FIXES → 9 amendements intégrés (SSOT 41+4, exclusion loyalty.js, cascade F7=31, tokens, couverture, harnais). Plan : `plans/GOAL_CLIENTS_UPDATE_VALIDATION_2026-06-10.md`.

## P1 — MOBILE : intégration des 3 lignes divergentes
- Merge `heal/uiux-exec-2026-06-08` (a11y nested-interactive x41, tokens --*-text, CTA, seed) → 7 conflits sémantiques résolus (loyalty.js garde ratio 1 + helper pointsFor ; QR aria riche ; tokens AA).
- Port prodready 12 fixes (F1 promo facturée, F4 allergènes bols [FIC 1169/2011], F5 upsell, F6 sans-sauce, F7 Tacos L 8,90, F8 vedette, F9 copy, F10 id, F11 drinkSlugMap, F12 emoji, RED-P1 price×qty, E2E-P1 z-index) — patch extrait du mini-clone jetable AVEC son état worktree (heal H9 inclus), loyalty.js exclu.
- Cascade F7 tranchée : C-1234 total 29,80→30,80 € ⇒ pointsFor(30.80)=31 ⇒ estimate 31 + ledger row 1001 +31 (invariant carte==détail==pointsFor préservé).
- 8 tokens CSS indéfinis healés (--fs-base/body/caption/micro/h3, --green-light, --shadow-1, --shadow-selected) — le piège axe-blind du dernier run.
- Harnais porté + adapté (mobile/tests/playwright.config.js séparé, :4173). Gates : data-layer 10/10, source-assert 10/10 (T-2.1 réécrits 1 pt/€ SSOT).

## P2 — MOBILE : validation profonde (triade)
- Playwright audit 8/8 + massive 12/12 = 20/20 x2 cycles identiques (viewport 390×844, Chrome système). Heals de specs : test D (10→1 pt/€), F/G (sélecteur a11y overlay « Voir <name> »), onboarding (login-validation fill 10 digits).
- Adversaire mobile = EXHAUSTED (0 P0/P1) : loyalty cascade cohérente live (gain modal réel 9,36 €→+9 pts ; ledger/estimate/balance alignés), merge CLEAN (0 marqueur, tokens OK, 0 #F4501E), axe 0 violation (nested-interactive 0, favoris aria-pressed, allergènes labellisés), F1/F4/F5/F6 fonctionnels live.
  - MADV-1 (P2) HEALÉ : 5 boutons « + » upsell panier sans nom accessible → aria-label « Ajouter <nom> — <prix> » (WCAG 2.4.6).
  - MADV-2 (P3 divulgué) : ledger 7-row ≠ balance 347 = mock by-design (balance=lifetime 1247−900), trap Phase-6 wireup déjà commenté.

## P3 — WEB : intégration
- Merge ff-only `heal/uiux-2026-06-08` → main (main = ancêtre strict, FF prouvé sain) : i18n Retrait/À emporter, login-default, DÉMO V1, tokens contraste AA, noopener.

## P4 — WEB : validation profonde (triade)
- Playwright 2 cycles identiques GREEN mobile 390 ET desktop 1280 : Home, Menu (11 cats/41 items, recherche, favoris aria-pressed), fiche produit, wizard, panier (qty/remove/promo −10%), checkout Retrait, payment DÉMO V1, confirm, track, Orders (gate login), Loyalty, About, compte (défaut login), 5 pages légales. axe 0 violation, 0 console err, 0 anglais résiduel, tokens gate PASS.
- 4 heals appliqués (web sans frozen-zone) : P1 contraste legal.css « Voir aussi » 3,11→4,86:1 (5 pages) ; P2 tables scrollables tabindex=0 + focus-ring ; P2 i18n « + Loyalty »→« + Fidélité » ; P3 « pickup-only »→« 100% à emporter ». Commit 4f3d902.
- LCEN : 29 placeholders « À COMPLÉTER » (mentions 13/privacy 7/cgv 4/cookies 4/allergens 1) = GATE-PUBLISH-1 owner-data, divulgué non bloquant.
- **Adversaire web = BROKEN (P1) → HEALÉ → re-vérifié EXHAUSTED** : le pilote avait certifié « axe 0 tous écrans » SANS s'être connecté → l'espace fidélité AUTHENTIFIÉ cachait WADV-1 (5 toggles `.lc-toggle` sans nom accessible, button-name CRITICAL), WADV-2 (leaderboard+badge blanc-sur-orange/vert 3.1:1), WADV-3 (badge panier 3.12:1), WADV-5 (tabs sans role=tab), WADV-6 (favicon 404). **Tous healés** (aria-label dynamique toggles, tokens --orange-text/--green-text, role=tab+aria-selected, favicon SVG inline) et **re-vérifiés LIVE** (Playwright MCP : login→loyalty→Mon compte authentifié, **axe-core 0 violation**, 5 toggles nommés). WADV-4 REFUTED (« Fermer le panier » = drawer panier, correct). Web spec re-run **18/18 post-heals** (0 régression logged-out).

## P5 — CONVERGENCE CROSS-PRODUITS
- SSOT : mobile et web = 41 items / 11 catégories identiques, 0 divergence prix (Tacos L 8,90 · Sandwich Cayenne 7,00 · Big Cayenne 9,50 · Galette Normale 6,50 · Chicken Burger 6,90 — vérifié les deux côtés). 4 constructs (Menu+3,00/Frites/Boisson/Boule gratinée) en addons.
- Loyalty : earn 1 pt/€ aligné mobile↔web. Divergence redeem (mobile 100 pts=1€/min 100 vs web paliers 500/1500/5000) = à confirmer owner (gate, divulgué).
- Palettes : mobile orange #FF5A1F (NOIR/ORANGE/JAUNE/BLANC), web #FF5A1F — aucun #F4501E (Cayenne POS) introduit.
- FR partout, Retrait/à emporter (pas de livraison), DÉMO V1 assumé.

## Gates owner (divulgués, non bloquants)
GATE-PUBLISH-1 (29 LCEN web) · loyalty redeem cross-produit · MADV-2 reconciliation Phase-6 · PUSH (mobile branche dédiée + web main, 0 push).

## Livraison
Mobile : worktree mobile-update-2026-06-10 (commits intégration+heals). Web : /Downloads/web main 4f3d902. Captures : mobile reports/.../mobile-adversarial/ + mobile/tests/mobile-e2e/__screens__/ ; web /Downloads/web/reports/validation-profonde-2026-06-10/{cycle1,cycle2,adversarial}/.
