# GOAL CLIENTS UPDATE & VALIDATION — App mobile + Site de commande web (2026-06-10)

> Owner : « mettre à jour le site web (mobile+desktop) et l'application selon TOUTES les mises à jour faites ; audit+tests profonds avec agents adversaires ; boucle e2e jusqu'à validation ; plan d'abord, audité par adversaires, puis exécution ; couvrir toutes les fonctionnalités comme la dernière mission. »
> Produits : **App mobile** = `mobile/` (repo testttt, prototype React standalone, NO-wireup mandat) · **Site web** = `/Users/1millnonstop/Downloads/web/` (standalone). Palette mobile = NOIR/ORANGE/JAUNE/BLANC (PAS #F4501E). Loyalty = **1 pt/€** (owner-canonical). Menu = miroirs canoniques de la DB SSOT 45 items.

## Constat de départ (recon git, fait)
- **Mobile fragmenté en 3** : (a) spine `pre-cloud-exec` = loyalty 1pt/€ `d3d5c2a60` + contrastes system-a + apiContract miroir ; (b) branche `heal/uiux-exec-2026-06-08` @ `043f3194d` = refonte a11y complète (nested-interactive ×41, tokens --*-text, CTA burnt-orange owner-gated, seed C-1100 38→13) ; (c) **mini-clone JETABLE** `~/foodking-review/app` branche `fix/lecayenne-prodready-2026-06-09` = **12 défauts fonctionnels corrigés** (F1 promo facturée, F3 nav, F4 allergènes bols [légal FIC 1169/2011], F5 upsell slugs, F6 sans-sauce, F7 Tacos L 8,90, F8 vedette, F9 copy, F10 id collision, F11 drinkSlugMap, F12 emoji, RED-P1 price×qty, E2E-P1 z-index footer) + harnais Playwright (18 tests) + gates node — **jamais portés dans le canonique**.
- **Web** : branche `heal/uiux-2026-06-08` @ `5ef1e08` (i18n Pickup→Retrait, login-default, DÉMO, contrastes, noopener) non mergée sur `main` @ `40ce185`.

## Phases

### P0 — AUDIT ADVERSARIAL DU PLAN (avant toute exécution)
2 agents adversaires lecture-seule attaquent CE plan : collisions de merge sous-estimées ? ordre des intégrations ? fixes prodready déjà présents/contradictoires avec uiux-exec ? risques de régression sémantique (loyalty seed, totaux) ? couverture e2e suffisante ? → réconciliation avant P1.

### P1 — MOBILE : intégration des 3 lignes (dans le repo testttt, branche dédiée `heal/mobile-update-2026-06-10` depuis la spine)
1. Merge `heal/uiux-exec-2026-06-08` (≈8 fichiers en collision avec la spine sur `mobile/` — résolution sémantique : union a11y+contraste ; loyalty déjà 1pt/€ des deux côtés, garder l'implémentation la plus complète [helper `pointsFor` + seed cohérent carte==détail]).
2. Port des fixes prodready : diff `ad29e7875..HEAD` du mini-clone sur `mobile/` → patch sur la branche ; pour CHAQUE F1-F12/RED-P1 : vérifier marqueur présent/absent post-merge, appliquer le manquant, résoudre les chevauchements (mêmes fichiers que uiux-exec). Porter AUSSI le harnais (`mobile/tests/node/*`, `mobile/tests/mobile-e2e/audit.spec.js` + massive-audit, playwright.config).
3. Parité SSOT : script compare `mobile/data/menu.js` (noms, prix, catégories) vs DB `items` (clone :8766 = miroir de l'op) → 45/45 exigé ; loyalty 1pt/€ partout (règles, aperçu, modal, historique, seed) ; palette mobile respectée (PAS de #F4501E introduit).
4. Gates rapides : `node mobile/tests/node/data-layer.test.mjs` + `source-assert` + `@babel/parser` syntax sweep.

### P2 — MOBILE : validation profonde en boucle (triade comme la dernière mission)
- Pilote : servir `mobile/` (:8097), exécuter les 2 suites Playwright (audit.spec A-H 18 tests + massive-audit 12) + parcours additionnels par fonctionnalité (onboarding/login/OTP, menu 7 catégories, wizard, panier ±/clamp, promo −10% bout-en-bout, upsell, paiement mock, confirmation, historique carte==détail, loyalty QR/redeem, RGPD, wallet sheets, axe A+AA par écran ET sous-état) — captures analysées (Read).
- Adversaire par cycle ; heal loop ; **2 cycles propres identiques** (root-cause).

### P3 — WEB : intégration + mise à jour
1. Repo `/Downloads/web` : merger `heal/uiux-2026-06-08` → `main` (ou désigner la branche comme canonique si main diverge — vérifier delta) ; vérifier que les updates récents y sont (loyalty 1pt/€, Retrait/À emporter, DÉMO, tokens contraste).
2. Parité SSOT `data/menu.js` vs DB 45 items ; cohérence prix avec mobile (ex Tacos L 8,90 partout).
3. LCEN : placeholders « À COMPLÉTER » = GATE-PUBLISH-1 owner-data → divulgué, non bloquant.

### P4 — WEB : validation profonde en boucle
- Pilote : servir (:8096), suite existante (52 checks session frontends-abuse si présente, sinon spec neuve) + parcours complets mobile-viewport (390px) ET desktop (1280px+) : home, menu, fiche produit, wizard, panier, funnel commande (Retrait), compte login/signup, loyalty, suivi commande, légal ; axe par écran ; captures analysées.
- Adversaire ; heal loop ; 2 cycles.

### P5 — CONVERGENCE & LIVRAISON
- Adversarial FINAL cross-produits (cohérence mobile↔web : prix, loyalty, wording FR, palettes respectives).
- `PROOF_INDEX_CLIENTS.md` (fonctionnalité→preuve→statut, par produit) ; rapports ; BRAIN §2 ; Graphiti ; insight session ; commits sur branches dédiées (PUSH = gate owner).

## Règles dures
Mutations data uniquement locales (apps standalone localStorage — pas de backend touché) · 0 push · palette mobile ≠ Cayenne · NO wireup API · pas d'invention de produits (SSOT 45) · P2/P3 divulgués · max 3 heal-cycles par défaut puis escalade · disque surveillé (captures JPEG q70).

## Risques identifiés d'avance
R1 collisions triple-ligne sur `screens-main.jsx`/`index.html`/`orders.js` (les 3 lignes les touchent toutes) → résolution fichier par fichier avec tests rouges/verts, pas de merge aveugle. R2 prodready re-edit orders.js (estimation 308→30) vs uiux seed (38→13) : la sémantique cible = `pointsFor(total)` 1pt/€ partout, carte==détail. R3 mini-clone jetable : en extraire le patch AVANT toute regen. R4 le harnais e2e mobile suppose Chrome système (`channel:'chrome'`). R5 TCC ~/Downloads (incident passé) — la session actuelle lit/écrit OK.
