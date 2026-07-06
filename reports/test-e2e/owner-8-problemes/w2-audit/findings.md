# W2 AUDIT — Caisse formule sans choix de boisson (READ-ONLY, HEAD 24e8a09c3, 2026-07-06)

## Symptôme CONFIRMÉ (captures 01/02 dans ce dossier)
Cayenne → « Menu (Frites+Boisson) +2,50 € » : seulement Options frites + Sauce frites, AUCUNE section boisson. « Boisson Seule +2,00 € » : aucune liste.

## Cause racine (double)
2a PRIMAIRE data : pos-wizard.js addonItems = data.addons de l'item (647-671) ; boissonItems = filtre (936-976), step si non-vide (977). Items menu-capables n'ont que 3 addons génériques (Menu id1 2,50 role menu_component ; Frites id2 ; Boisson Seule id3 role drink) tous exclus par le filtre → boissonItems=[] → step jamais rendu. Les 35 rows role=drink pointent TOUTES vers l'addon générique id3.
2b SECONDAIRE : data-pos-drinks-catalog="[]" en pratique — PosComponent.vue:2189 dérive drinksCatalog de item/lists re-fetché PAR CATÉGORIE (landing 15 boissons, après clic Sandwichs = 0). Fix non-frozen : cache persistant.

## DB / noms
15 boissons actives cat 10 (ids 52-59, 119-125), 1,90 € (Eau 1,00, Capri-Sun 1,50). Fuze Tea 33cl id 123 OK. « Fanta Hawai 33cl » id 124 slug fanta-hawai ; occurrences : DrinksUpdate20260705Seeder.php:48 + config/menu_images.php:162. ⚠ « Hawaï » seul ne matche pas DRINK_LIKE_REGEX (:955) — sans effet si liste = catalogue.

## Pricing — aucun role ne donne 0 €
PricingService::menuRoleAdjustedAddonPrice (FROZEN :793-814) : drink = plein tarif ; menu_full×1.0/menu_frites×0.6/menu_boisson×0.4 ; pas de menu_drink. Modèle borne = boisson en TEXTE instruction (KioskWizardComponent.vue:2103-2106), 0 €. La caisse a déjà le canal : pos-wizard.js:2581-2588 pousse « BOISSON: <nom> » dès que boissonChoice existe. Borne : repli globalBoissonCatalogRows (KioskStepMenuComponent.vue:306-344) = le delta exact avec le POS.

## Plan retenu — Option B (~25 lignes, zéro backend)
❌ A data-only rejetée : syncAndSubmit (4005-4038) cliquerait la carte Vue → facturation pleine (9,90→11,80 €) + 300 rows.
✅ B : (1) LOCK pos-wizard.js ~12-15 lignes : IIFE boissonItems — si filtre vide ET catalogue non-vide → construire depuis catalogList (price:0, 'Incluse') ; sync-to-Vue matche par nom → no-op facturation (= borne). (2) PosComponent.vue non-frozen ~10 lignes : drinksCatalog persistant. (3) Data+seeder : id124 → « Hawaï 33cl ». (4) Tests Vitest + re-repro Playwright.
Acceptance devis : Menu Complet 9,90 € avant = 9,90 € après (+ BOISSON: X en instruction) ; Boisson Seule 9,40 = 9,40.
Décisions owner : libellé « Hawaï » ; 11 vs 15 boissons ; Menu Complet 2,50 € DB vs « 3 € » cité goal.
Note mémoire C1 « ~350 lignes » INFIRMÉE.
