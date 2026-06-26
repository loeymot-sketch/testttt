# BORNE r1 — Lentille PSYCHOLOGIE CLIENT — Wizard composition + upsell

Sous-système 2.b. DB live `foodking_e2e` (status ACTIVE=5, INACTIVE=10). READ-ONLY, 0 mutation.
Parcours joué : idle → catégorie → wizard (Méga, sauces, viandes, suppléments) → panier → Plan-B.

Résumé verdict : **PricingService SSOT honnête** (preview Méga+supplément = 8,90 € exact, viandes gratuites,
TTC correct sans double-taxe). Le « fantôme-upcharge » HISTORIQUE (menu-formule `:1917-1947`) et
l'heuristique « Méga→4 viandes » sont **dormants/neutralisés** sur le menu live actuel. **Une vraie
divergence prix-affiché ≠ prix-payé subsiste : la sauce extra +0,50 €** (sens inverse = sur-affichage,
en faveur du client, donc P2 confiance — pas perte revenu, pas NF525).

---

[P2] resources/js/helpers/kioskPricing.js:35,88-90 — Sauce extra « +0,50 € » affichée mais JAMAIS facturée par le backend (prix affiché ≠ SSOT, sens sur-affichage)
  repro:
    1. Wizard Méga (id=104). Étape Sauce : attribut « Sauce (1ère Gratuite) », 12 sauces toutes à 0,00 € en DB.
    2. `KioskStepSauceComponent.toggleSauce` n'applique AUCUN cap max_select → le client peut cocher 2 sauces.
       La 2ᵉ sauce affiche « +0,50 € » (`sauceUnitPriceLabel:191` → `extraSaucePrice` → `getKioskExtraSauceUnitPrice`).
    3. `calculateKioskRunningTotal` (kioskPricing.js:88-90) ajoute (n-1)×0,50 ; `getKioskExtraSauceUnitPrice:35`
       retombe sur `let unit = 0.50;` car AUCUNE variation sauce n'a price>0 (vérifié DB : 0 sauce price>0 pour 104).
    4. tinker preview réel : le wizard ne POST que `sauceOrder[0]` en variation (KioskWizardComponent.vue:1786-1792) ;
       les sauces extra ne partent QUE dans la note texte (`:2056-2062`), jamais en variation/extra/addon.
  evidence:
    - Backend `PricingPreviewService::preview(Méga, 1 sauce envoyée)` → TOTAL = 8,00 € (base seule).
    - Wizard (`runningTotalLocal` lié au chip nav `KioskWizardComponent.vue:204`, + panier subtotal
      `kioskCart.js:225-231` via `item_variation_total`) → affiche 8,50 € (8,00 + 0,50).
    - DIVERGENCE prouvée live : wizard montre 8,50 €, backend facture 8,00 € (counter Plan-B = SSOT).
    - Test `tests/js/KioskWizard.spec.js:386-388` ENTÉRINE la règle +0,50 en display (assert runningTotal 9,00).
    - Données live contradictoires : TOUS les attributs sauce ont max_select=1 ET 0 variation payante
      (16 items vérifiés) → la règle « extra +0,50 » n'a aucun correspondant backend pour ce menu.
  lentille: client (paie un prix ≠ celui affiché ; croit la 2ᵉ sauce payante alors qu'elle est gratuite →
    peut renoncer à une sauce qu'il aimait, ou être surpris à la caisse ; rupture de confiance prix).
  reco (NON-frozen, helper + données — PAS le composant frozen) :
    - Aligner DATA ↔ règle : soit l'owner VEUT la sauce extra payante (alors corriger la DB : max_select>1
      + price>0 sur les variations sauce, et faire transiter l'extra comme vraie variation/extra côté submit),
      soit la sauce extra est gratuite (alors retirer le surcharge d'affichage `kioskPricing.js:88-90` +
      le label `+0,50` dans `KioskStepSauceComponent.sauceUnitPriceLabel`).
    - Court terme sûr : remplacer le fallback dur `let unit = 0.50` par 0 quand aucune variation payante
      n'existe (le display cesse de promettre un surcoût inexistant). Test : `tests/js/kioskWizardSauceExtraParity.spec.js`
      (à créer) — assert runningTotalLocal == backend preview total pour Méga + 2 sauces (0 DB-payantes).
    - ESCALADE owner : décision business « sauce extra gratuite ou payante » (sémantique de l'attribut
      « Sauce (1ère Gratuite) » contredit max_select=1).

---

[P3] resources/js/helpers/kioskTacosSize.js:38-39,84-97 — Heuristique nom « Méga→4 viandes » (réelle=2), neutralisée par le SSOT serveur mais latente
  repro:
    - `viandeCountFromName("Méga")` → match regex MEGA → SIZE_TO_VIANDE_COUNT.MEGA = 4.
    - Item live « Méga » (id=104) a 2 attributs « Viande 1 | Viande 2 » → vrai compte = 2.
  evidence:
    - tinker projection `KioskMenuService::projectItemAttributes` → server `viande_count` = 2 (Méga/Terminator/Tacos L=2,
      Tacos M/Bols=1) — confirmé DB.
    - Wizard `detectViandeCount:970` lit `item.viande_count` (priorité 2) AVANT l'heuristique nom (priorité 3) ;
      `shouldAskTacosTaille:1064` idem. Le serveur expose `viande_count=2` → l'heuristique Méga=4 N'EST JAMAIS atteinte.
  lentille: client (latent — si la projection cessait d'exposer viande_count, le client devrait choisir 4 viandes
    pour un Méga à 2, friction + récap faux). Aujourd'hui : 0 impact (shadowé par SSOT serveur).
  reco: aucune action V1 requise (SSOT serveur correct). Optionnel V1.0.X : corriger SIZE_TO_VIANDE_COUNT.MEGA=2
    OU retirer le mapping nom (défense en profondeur) — non urgent. Garder le test de garde si MEGA mappé.

---

## NON-FINDINGS vérifiés (verify-before-report)
- **Fantôme-upcharge menu-formule** (`KioskWizardComponent.vue:1917-1947`, ratios frites 0,6 / boisson 0,4) :
  DORMANT — `KioskMenuService::build(branch1)` expose **0 item has_menu=true** sur le menu live → le chemin
  ne se déclenche jamais. Le leak historique (owner perdait 1,20 €) est non-reproductible aujourd'hui.
- **Catégorie vide cul-de-sac** : 0 catégorie active a 0 item actif en DB live → trap latent (T-2.a.2, lentille
  catalogue, autre agent), pas reproductible maintenant côté wizard.
- **Upsell intrusif / timer trop court** : auto-skip = 30 s (KioskUpsellComponent.vue:125), reset à chaque
  interaction (`:208-211`), n'auto-AJOUTE jamais (seulement auto-SKIP), bouton « Passer » toujours visible.
  Bien pensé client, aucun dark pattern → PAS un finding.
- **Suppléments +0,90 €** : honnêtes — preview Méga+Oignons frits = 8,90 € exact (extras_total=0,90), affichés
  par carte avant panier. Conformes SSOT → PAS un finding.
- **Viandes payantes (Double Steak etc.)** : 0 viande payante dans le menu live (toutes les viandes Méga=0,00 €,
  source=variation) → `kioskSumPaidViandesSurcharge` = 0, aucune divergence.

## Preuves brutes (live `foodking_e2e`, serveur :8766)
- Méga base = 8,00 € ; viandes 1&2 toutes 0,00 € (source variation) ; suppléments tous 0,90 € ; sauces toutes 0,00 €, max_select=1.
- `preview(Méga + supplément)` = subtotal 8,90 / tax 0,81 / total 8,90 (TTC, pas de double-taxe).
- `preview(Méga, 1 sauce envoyée)` = 8,00 € vs wizard affiché 8,50 € pour 2 sauces.
- `preview(Méga, 2 sauces variations)` → backend lève `InvalidArgumentException: Sauce max 1 sélection, reçu 2`
  (le wizard évite ce 422 en n'envoyant que sauceOrder[0] ; les extra ne sont que des notes).
