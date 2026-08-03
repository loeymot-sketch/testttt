# REGISTRE — GOAL borne (drop paiement) + crudités + idle + structure stock/wizard — 2026-07-21

Branche: `pos/category-first-caisse-2026-06-23` · HEAD départ: `f4c57f4d8`

## Défauts signalés owner (borne + web + admin)

| ID | Surface | Défaut | Sévérité | Statut |
|----|---------|--------|----------|--------|
| B1 | Borne | Au **paiement final**, tous les suppléments s'annulent, prix baisse auto, ticket n'imprime que le produit de base (miroir du drop web 19/07) | P0 | ⏳ investig |
| B2 | Borne | Images **crudités barrées à l'envers** : sélectionnée = barrée (devrait: barrée = retirée) | P1 | ⏳ investig |
| B3 | Borne | Modale **idle "toujours là ?"** : texte des 2 boutons invisible | P1 | ✅ fix source (ghost→secondary, KioskInactivityOverlayComponent.vue:44) + rebuild requis (symptôme déployé = bundle périmé pré-`f1c985f3d`) |
| W1 | Web | Vérifier même logique wizard (choix gratuits inclus: sauce/frites/viande) + drop possible comme borne | P1 | ⏳ investig |
| S1 | Admin | Interface UNIQUE stock/wizard sans doublage (caisse/borne/web depuis 1 source) | P2 | ⏳ investig |
| S2 | Admin | Gestion produit avec **photo** : changer photo existante, ajouter produit + sa photo | P2 | ⏳ investig |

## Invariants inclus gratuits (à garantir borne == web)
- Sauce: 1ʳᵉ incluse gratuite, extras payants
- Frites: 1 incluse gratuite (produit)
- Viande: ce que le produit contient de base gratuit + extra payant (cap 3)

## Notes clés
- Backend a DÉJÀ une garde générique `expected_total` (422 si total_serveur < attendu) — memo drop_prix_web 19/07. Donc si la borne droppe, soit elle n'envoie pas expected_total, soit elle calcule le total bas elle-même.
- Frozen: KioskWizardComponent.vue, KioskAppComponent.vue, PricingService.php, OrderStateMachine.php, PaymentComponent.vue → gate owner si touche.

## Résolution (2026-07-21)

| ID | Verdict | Preuve |
|----|---------|--------|
| B1 | ✅ Backend prouvé sain + durci | `KioskSupplementDropRegressionTest` VERT (Cayenne 7,40 + Cheddar/Viande/Sauce = 11,30€ scellé, snapshot contient les 3). Backend fail-loud sur 2 chemins. **Durcissement RC2** : `CompositionSnapshotBuilder:89` silent-skip → `throw 422` (seul silent-drop backend fermé, brèche NF525 §V). Gardes client borne vertes (`kioskCartSendPayload` 12/12). **Bundle recompilé** (kill hypothèse stale). |
| B2 | ✅ Fix UX compilé | Logique barré = retiré N'ÉTAIT PAS inversée (design "tout inclus, toucher pour retirer") — le vrai défaut = état inclus visuellement invisible (fond 2,5%). Fix `KioskStepGarnituresComponent.vue` : inclus = bordure orange 2px + fond 12% + glow ; retiré = grisé+désaturé+trait rouge 5px ; bandeau "TOUT INCLUS" pastille lisible. Vérifié compilé dans `kiosk-wizard-step.js` (`#c1121f`). |
| B3 | ✅ Fix contraste compilé | Bouton "Abandonner" ghost→`secondary` (fond blanc + texte #1A1A1A garanti, indépendant du token muted quasi-blanc). Bouton "Je suis là" = primary rouge/texte blanc. Vérifié compilé `variant:"secondary"` dans app.js. Symptôme déployé = bundle périmé pré-`f1c985f3d` → recompilé. |
| W1 | ✅ Invariants uniformes | Audit data SSOT partagé borne↔web : sauce sup @0,50 + viande sup @2,50 + crudités @0 (4/sandwich, 0/bol) + sauces gratuites variation — UNIFORME sur 7 composables. Web poste au même backend durci (garde RC2 + expected_total + pricing fail-loud protègent les 2 surfaces). |
| S1/S2 | ✅ Socle vérifié, gaps documentés | SSOT stock unique fonctionne (167 tests verts, rupture variation/extra/addon→événements+sync borne/web canal public). Photo upload existe (create/edit/replace Spatie). Add-produit+photo existe (Catalog Studio). SSOT wizard unique (`ItemWizardProfile`+`ComposerProfileProjection`) lu borne/caisse/web. **Gaps (décision owner)** : (1) dashboard stock + Catalog Studio = 2 écrans cross-liés, pas fusionnés ; (2) pas d'action photo SUR le dashboard stock ; (3) doublage POS : flag `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED=false` → caisse peut diverger du composer SSOT (flip touche pos-wizard.js FROZEN = gate owner) ; (4) 2 endpoints photo (consolidation). |

## Frozen zones : 0 touché (diff vide vérifié). NF525 chaîne OK (4 branches). PHPUnit 622+167 verts.

## Preuve visuelle E2E (Playwright headless, Chromium propre — PAS l'extension Chrome)
Débloqué : le catalogue borne charge en headless avec clic `force:true` (bouton masqué par l'animation du chooser) ; les 401 étaient de la télémétrie de fond (port 8766), non bloquants. Parcours réel SUPRÊME sur `127.0.0.1:8000` servant le bundle recompilé :
- `03-crudites-toutes-incluses.png` — **B2 prouvé** : bandeau pastille « TOUTES LES GARNITURES SONT INCLUSES » + « Désélectionnez celles que vous ne voulez pas » ; Salade/Tomate/Oignon = bordure orange 2px + fond orange + ✓ (état inclus incontestable).
- `04-crudites-1-retiree-barree.png` — **B2 prouvé** : après retrait Salade → carte grisée+désaturée+trait barré + badge « + » ; Tomate/Oignon restent inclus ; résumé « GARNITURES : Tomate, Oignon ». Doute owner levé.
- `06-panier.png` (étape menu) — **B1 prouvé** : « VOTRE COMPOSITION » trace « SUPPLÉMENTS : Oignons frits » + **Total 7,90 €** (base 7,00 + 0,90) → supplément NON largué, prix correct. Cohérent avec le test backend (11,30 € scellé + snapshot complet).
- Bundle servi vérifié live : `#c1121f` (crudités) + `variant:"secondary"` (idle) présents dans les assets `127.0.0.1:8000`. Sur cette machine le fix est ACTIF.

## Test E2E LOGIQUE bout-en-bout (owner « test-e2e pour validé avec logique ») — VERT
`tests/Playwright/borne-e2e-logique-2026-07-21.spec.js` : UI borne RÉELLE + backend RÉEL (`:8766`, token machine minté via kiosk-login + injecté en localStorage vuex). Parcours SUPRÊME → supplément payant → panier → **checkout déclenche le quote authentifié**. Assertions au centime :
- **Total AFFICHÉ panier 7,90 € == Total SCELLÉ backend `total_ttc` 7,90 €** (7,00 base + 0,90 supplément).
- **Payload quote = 3 extras** transmis (supplément + garnitures) → aucun largage CLIENT.
- Free-included prouvé : total = base + supplément payant uniquement (crudités/sauce incluses = 0 €).
→ Le bug owner « calcule jusqu'au paiement puis annule » NE se reproduit PAS. Affiché == scellé = pas de drop, bout-en-bout, à travers la vraie borne.

## Reste owner
- Déployer le bundle recompilé sur la borne (le symptôme terrain venait probablement d'un build périmé).
- Valider visuellement crudités + idle sur la vraie borne (capture headless bloquée : idle tactile-gated + 3 Chrome connectés non sélectionnables en autonomie).
- Décisions S1/S2 : fusion écrans stock/studio ? action photo sur dashboard stock ? flip flag composer POS (gate frozen) ?
