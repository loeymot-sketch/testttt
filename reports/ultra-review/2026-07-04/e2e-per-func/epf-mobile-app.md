# E2E per-functionality audit — APP MOBILE RN (`mobile/`)

HEAD 3c7145bf4 · 2026-07-04 · CODE-ONLY (standalone, no-API-wireup V1 = attendu)

## Nature réelle de la cible
`mobile/` n'est **pas** un projet React Native au sens natif : c'est un **prototype React 18 + Babel-standalone servi dans un navigateur** (`index.html` charge React UMD + `@babel/standalone` + fichiers `.jsx` via `<script type="text/babel">`, rendu dans un cadre iOS `ios-frame.jsx`). C'est le mockup mobile STANDALONE. Audit = statique (grep/Read/Read seeder + simulation Node du moteur de prix). Aucune écriture.

Fichiers dev/design NON chargés par `index.html` (donc hors app livrée) : `design-canvas.jsx`, `tweaks-panel.jsx`, `ios-frame.jsx`. `image-slot.js` EST chargé mais c'est un éditeur d'images (chrome d'édition).

## Fonctionnalités testées

### 1. Data menu = miroir DB (0 produit inventé) — OK
- `node tests/menu.spec.js` → **ALL CHECKS PASSED (0 failures)**, 31 produits / 9 catégories.
- Recoupé ligne à ligne avec la SSOT `database/seeders/OwnerMenuUpdate20260623Seeder.php` : prix identiques (Tacos M 6,90 / L 7,90 ; Cayenne 7,40 ; Suprême 7,00 ; Méga 8,00 ; Terminator 9,00 ; 6 burgers 4,90→9,00 ; Bols 7,90 ; Menu enfant 4,90 ; desserts 3,50 ; canettes 1,90 ; eau 1,00).
- 7 viandes canon (Mexicanos/Cordon Bleu/Viande Hachée/Nuggets/Tenders/Fricadelle/Poulet mariné) = seeder MEATS. 12 sauces, 3 crudités, 9 suppléments @0,90.
- Assertions anti-fantôme du test passent (« Big Cayenne », « Bowl … », « Menu Nuggets » absents). **0 produit inventé.**

### 2. Palette NOIR/ORANGE/JAUNE/BLANC — OK (pas de rouge Cayenne)
- `styles.css` `:root` : `--ink #0A0A0A` (noir), `--orange #FF5A1F`, `--yellow #FFD93D`, `--paper #FFFFFF`.
- **Aucune occurrence de `#F4501E`** (rouge Cayenne interdit) dans JS/JSX/CSS chargés.
- Rouges présents = **sémantiques statut** uniquement : `--red #E5341A` (erreur/RGPD/SPICY tag), `--green #1FA653` (succès). Oranges foncés `#E54A12`/`#C2410C`(WCAG AA)/`#C73E18` = famille orange, pas rouge brand.
- Terracotta `#C96442`/`#D97757`/`#B3261E` uniquement dans `design-canvas.jsx`/`tweaks-panel.jsx` (non chargés) et états d'édition de `image-slot.js` — pas de rendu consommateur. Non-violation.

### 3. Navigation / écrans — OK
- Routeur inline (`index.html`) : 18 écrans (splash, onb1-4, login, otp, home, menu, item, cart, stripe, confirm, orders, orderDetail, profile, loyalty) + 8 modales (pay, gain, redeem, link, walletApple, walletGoogle, optOut, toast) + TabBar.
- **Tous les composants référencés sont définis** (vérifié 25/25). `ScreenItem` délègue à `window.ScreenItemWizard` (exposé `Object.assign(window,…)` l.1198). Historique back/tab-bar cohérent.

### 4. Composition / panier / moteur de prix — OK
- `computeActiveSteps` mappe les templates (tacos/sandwich/burger/bols/frites/simple) selon `has_sauce/has_crudites/has_supplements/has_menu_addon/viande_count`. Multi-viande géré (`meatIds` requis == `viande_count`, gate `canAdvance`).
- Simulation Node de `menu.priceFor` (bout-en-bout) :
  - Méga x2 + menu + 2 sauces = **22,00** (attendu (8+2,5+0,5)×2) ✓
  - Bol Riz + gratiné + cheddar + coca = **12,70** ✓
  - Grande frites + cheddar-oignons = **6,00** ✓
  - Tacos M + menu = **9,40** ✓
- `buildLineItem` : unitPrice = computeTotal(qty:1), lineTotal = unitPrice×qty, `price`=unitPrice. Formatage `toFixed(2)` partout (pas de float brut affiché).

### 5. Locale FR — OK
- `<html lang="fr">`, `toLocaleTimeString('fr-FR')`, `branch.locale='fr'`.
- **Aucune fuite de texte UI anglais** (grep word-boundary sur les libellés rendus → seuls `I.Back`/`I.Close` noms d'icônes matchent, textes FR).

### 6. Images / assets — PARTIAL (1 asset cassé)
- 65 refs uniques, 249 existent. Faux positifs réfutés : `signature/cayenne-hero.png` + `signature/tacos-hero.png` **existent** (sous-dossier `signature/`, mon 1er scan ne listait que le niveau 0).
- **Défaut réel** : `sauce-barbecue.png` (tiret) référencé par `s-barbecue` n'existe pas — le fichier réel est `sauce_barbecue.png` (underscore). Toutes les 11 autres sauces existent en tiret. Le rendu du swatch sauce (`screens-item-steps.jsx:396`, étape Sauce) n'a **pas de `onError`** → icône image cassée pour la sauce Barbecue. Cosmétique (swatch 18px, aria-hidden), P3.

### 7. Fidélité (balance/earn/redeem/QR) — OK (mock cohérent, no-API attendu)
- `data/loyalty.js` : account/history mock, `generateSignedQR` (HMAC-like), earn 10pts/€, redeem 100pts/€. Modales redeem/gain/optOut RGPD (efface balance) câblées. Mock assumé V1 (pas de table `loyalty_rewards` backend, documenté in-code).
- Note mineure P3 : l'historique mock cite des libellés hérités « Sandwich Cayenne »/« Galette Cayenne » (ancien nommage) — données mock d'affichage, pas des produits composables.

### 8. Persistance (storage.js) — OK
- `window.LC.storage` expose setAuth/getAuth/clearAuth/isAuthenticated/getCart/setCart/markOnboardingSeen/hasSeenOnboarding/setPlasticCardLinked — toutes définies et cohérentes avec les appels du routeur. `window.LC.user`/`window.LC.dev` définis.

## Défauts confirmés
| # | Sévérité | Fonctionnalité | Défaut | Repro |
|---|----------|----------------|--------|-------|
| 1 | P3 | Images/assets | `sauce-barbecue.png` (tiret) manquant → swatch cassé sauce Barbecue, pas de onError | `ls assets/menu/ \| grep barbec` → seul `sauce_barbecue.png` (underscore) ; `menu.js:143` référence `sauce-barbecue.png` |
| 2 | P3 | Prix (cohérence interne) | Capri-Sun : catalogue 1,50€ (`menu.js:429`) mais addon boisson bol `priceForDrinkAddon('d-capri')`=1,90€ (`menu.js:319`) | node : `menu.priceForDrinkAddon('d-capri')` → 1.9 ; item capri-sun.price → 1.5 |
| 3 | P3 | Fidélité (cosmétique) | Historique fidélité mock cite « Sandwich Cayenne »/« Galette Cayenne » (nommage hérité) | `data/loyalty.js:150-153` |

## Verdict
**HAS_BROKEN (mineur uniquement)** — Aucun P0/P1/P2. Le cœur (menu SSOT 0-inventé, palette conforme sans rouge Cayenne, nav complète, moteur de prix correct e2e, FR-lock) est **prouvé OK**. 3 défauts P3 cosmétiques (1 asset sauce manquant, 1 incohérence prix Capri addon, libellés mock hérités).
