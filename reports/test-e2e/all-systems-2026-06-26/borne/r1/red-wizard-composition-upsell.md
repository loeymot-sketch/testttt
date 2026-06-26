# BORNE r1 — Lentille ADVERSAIRE-RED — Sub 2.b « Wizard composition + upsell »

Méthode : Read fichiers ancrés (frozen-aware) + interrogation `foodking_e2e` (READ-ONLY)
+ `PricingPreviewService::preview` en tinker SANS effet de bord + Vitest filtrés.
DB live : `foodking_e2e` (branche 1). Aucune écriture, aucune commande réelle.

## RÉSUMÉ
- **0 P0 / 0 P1.** La défense SSOT NF525 du wizard borne est **prouvée live** :
  prix forgé ignoré, variation étrangère rejetée, MAX cross-attribut enforcé,
  fantôme-upcharge « Viande supplémentaire +2,50 » **réfuté** (correctement
  sérialisé + facturé). Parité prix borne↔backend tenue.
- **3 findings P2/P3** : catégorie-vide dead-end (latent, asymétrie POS) ;
  heuristique nom « Méga→4 viandes » (latent, gardé par champ serveur) ;
  merchandising upsell (plats complets 9€ suggérés en add-on).

---

## RÉFUTATIONS (fausses certitudes tuées par la preuve)

### R1 — « Fantôme-upcharge : prix wizard ≠ backend (+2,50 viande) » → RÉFUTÉ pour la borne
- **Anchor disputé** : germe adversaire « résiduel +2,50 viande FROZEN » +
  `KioskWizardComponent.vue:198/1922`.
- **Fait DB** : « Viande supplémentaire » existe bien (ids 392/393/394, prix
  2,50€, `group_label='supplement'`) sur Tacos M / Tacos L / Méga.
- **Static** : `kioskExtrasPartition.js:51-65` (`kioskIsViandePaidExtra`) renvoie
  **false** pour cet extra car `group_label='supplement'` ∈ `SUPPLEMENT_GROUPS`
  (court-circuit avant l'heuristique nom `name.includes('viande')`). Il tombe
  donc dans le bucket `supplements` → rendu dans l'étape Suppléments → sérialisé
  via `this.selections.supplements` (`KioskWizardComponent.vue:1843-1860`) ET
  additionné par le helper local `kioskPricing.js:99-108`.
- **Preuve live (tinker preview, SANS effet de bord)** — Méga(104) base 8,00 +
  Cheddar(349)@0,90 + Viande supplémentaire(394)@2,50 :
  `BACKEND subtotal=11.4 total=11.4` (extraTot=3.40). EXACTEMENT le total local
  attendu 8,00+0,90+2,50=11,40. **Aucun écart preview/backend.**
- **Caveat honnête** : le « phantom +2,50 » du BRAIN concernait le POS Vanilla
  `pos-wizard.js` (FROZEN, hard-codé) OU une autre config DB ; côté **borne**,
  cet upcharge est un vrai extra DB correctement câblé.

### R2 — « Prix forgé / variation étrangère acceptés » → RÉFUTÉ (SSOT solide)
- **FORGE prix** : payload Méga avec `price=0.01,convert_price=0.01` (composition
  valide) → `preview` renvoie **total=8** (prix relus en DB, hint client ignoré).
- **FORGE variation étrangère** : injecte la variation id 43 (appartient à Tacos M
  item 26) dans la ligne Méga(104) → `InvalidArgumentException: Variation ID 43
  n'appartient pas à l'article 104.` (garde cross-item, `PricingPreviewService`
  doc:24).
- **FORGE MAX** : 2 variations dans le même attribut « Viande 1 » (max_select=1)
  → `InvalidArgumentException: Attribut Viande 1 : maximum 1 sélection(s),
  reçu 2.` Enforcement preview = enforcement backend.

### R3 — « Upsell affiche +0,00 € (convert_price manquant) » → RÉFUTÉ
- L'écran consomme `frontend/item/kiosk-upsell` →
  `FrontendItemController::kioskUpsell` → `SimpleItemResource::collection`.
- `SimpleItemResource:36` expose `convert_price` (= `convertAmountFormat`).
  `addedTotal` (`KioskUpsellComponent.vue:148`) fait `parseFloat(i.convert_price)`.
- tinker : `convertAmountFormat(8.00)` = `"8"` (nombre brut, pas de séparateur)
  → `parseFloat` sûr → `formatPrice` rend `8,00 €`. Chip « +X,XX » correct.
- Live : 17 items éligibles (`is_upsell=5`/`is_featured=5` + `kiosk_upsell_include=1`),
  dont 3 vrais add-ons (Glace, Tiramisu, Coca-Cola 33cl). Flux upsell actif.

### R4 — « viande_count serveur faux (Méga devrait être 2) » → RÉFUTÉ (serveur correct)
- DB : Méga(104) a 2 attributs « Viande » (Viande 1 + Viande 2), Tacos L(97)=2,
  Tacos M(26)=1, Cayenne(22)=0.
- Projection tinker `KioskMenuService::build` : `viande_count` Méga=2,
  Terminator=2, Tacos L=2, Tacos M=1, Cayenne=0. **SSOT serveur juste.**
- Le wizard lit `item.viande_count` EN PRIORITÉ (`KioskWizardComponent.vue:970`)
  avant l'heuristique nom → la valeur correcte (2) est utilisée live.

---

## FINDINGS (prouvées)

[P2] app/Services/Kiosk/KioskMenuService.php:66-74 — Catégorie kiosk visible sans produit = cul-de-sac muet (latent, asymétrie POS)
  repro: `KioskMenuService::build` projette toutes les catégories actives+visibles
    SANS `whereHas('items')`. POS le fait : `PosCategoryController.php:81,119`
    `whereHas('items', ...)`. Frontend net `KioskCategoriesComponent.vue:75-90`
    ne couvre QUE `categories.length===0` (menu entier vide), PAS la catégorie
    individuelle vide → la zone produit rend « 0 produit » + grille blanche sans
    message ni redirection.
  evidence: tinker live → 9 catégories projetées, toutes ≥2 items (AUCUNE vide
    aujourd'hui : trap DORMANT). Déclenché dès qu'un admin rend indispo/inactive
    le dernier item d'une catégorie kiosk-visible, ou crée une catégorie
    kiosk-visible sans item. Asymétrie POS/borne = vrai défaut structurel.
  lentille: client (se retrouve devant un écran vide sans issue claire)
  reco: aligner sur POS — filtrer `$visibleCategories` aux catégories ayant ≥1
    item visible kiosk (NON-frozen, service). Acceptance proposée :
    `tests/Feature/Kiosk/KioskEmptyCategoryHiddenTest.php` (À CRÉER, déjà prévu
    plan Sub 2.a T-2.a.2). Filet UX optionnel : message « Bientôt disponible »
    par catégorie vide dans `KioskCategoriesComponent`.

[P3] resources/js/helpers/kioskTacosSize.js:34-41,84-97 — Heuristique nom « Méga/Famille → 4 viandes » (latent, gardé par viande_count serveur)
  repro: `viandeCountFromName("Méga")` = 4 (regex `/\bm[eé]ga\b/i` → MEGA→4) ;
    idem « Famille »→4. L'item live s'appelle exactement « Méga » (id 104) qui est
    un produit 2-viandes. Le wizard FROZEN ne tombe sur ce fallback que SI
    `item.viande_count` est absent/0 (`KioskWizardComponent.vue:970→973`).
  evidence: Vitest `kioskTacosSize.spec.js:60-61` PIN `'Tacos Méga'→4`,
    `'Tacos Famille'→4` (heuristique by-design). Live : `viande_count=2` exposé →
    fallback JAMAIS atteint aujourd'hui (SAFE). Risque = si la projection serveur
    perdait le champ (rename attribut « Viande N », régression projection), la
    borne offrirait 4 emplacements viande pour un produit 2-viandes (viandes =
    variations gratuites → pas de surfacturation, mais composition erronée :
    cuisine reçoit 4 viandes, perte marge / prép fausse).
  lentille: commerçant (marge) + cuisinier (prép divergente)
  reco: défense — sentinelle de parité `viande_count` projeté vs heuristique nom
    sur les items « Méga/Famille/XXL » (alerte si divergence), pour que la perte
    du champ serveur soit détectée en CI plutôt qu'en prod. (FROZEN wizard
    intouché — la garde vit dans le service/test, pas dans le composant.)

[P3] app/Http/Controllers/Frontend/ItemController.php:68-105 (kioskUpsell) — Merchandising : plats complets suggérés en upsell
  repro: `kioskUpsell` peuple via `is_upsell=5` PUIS fallback `is_featured=5`
    (même règle catégorie `kiosk_upsell_include=1`), `inRandomOrder()`. Live, les
    items `is_featured=5` éligibles incluent Méga(8€), Big Burger(9€), Grill
    Burger(8€), Tacos L(7,90€).
  evidence: requête live → 17 items éligibles dont 14 plats principaux 6–9,50€ +
    3 vrais add-ons (Glace/Tiramisu/Coca). Après que le client a composé son repas,
    l'écran upsell peut proposer un 2e sandwich à 9€ comme « +ajout » — psychologie
    grande-chaîne attend snacks/boissons/desserts, pas un 2e plat.
  evidence-2: ce n'est PAS un bug code (config admin `is_featured`/`kiosk_upsell_include`),
    le prix s'affiche correctement (R3). Severité basse = qualité merchandising.
  lentille: client (pertinence de la suggestion) + commerçant (taux d'upsell)
  reco: owner/admin — réserver le pool upsell aux catégories add-on
    (Desserts/Boissons/Frites) via `kiosk_upsell_include`, OU prioriser
    `is_upsell` explicite et limiter le fallback `is_featured` aux items à prix bas.
    Pas de heal code requis (data/config).

---

## NON-REPRODUITS / hors-scope (transparence anti-hallucination)
- Upsell auto-skip timer `KioskUpsellComponent.vue:92,125` = 30 s + barre de
  progression `role="progressbar"` + skip explicite toujours dispo. NON intrusif,
  NON destructif (skip ne vide pas le panier). Pas un défaut.
- `KioskWizardComponent.vue:204` (nav-total) bind `runningTotalLocal` (helper pur)
  et NON `Math.max(serverPreview, local)` — divergence same-page corrigée
  historiquement (commentaire C-001). Backend reste SSOT à `/order`. Cohérent.

## EVIDENCE (verts)
- Vitest : `kioskPricingPreview` (12) + `kioskTacosSize` (46) + `kioskUpsellFlow`
  (4) + `kioskMenuBundledExtras` (7) = **69 passed**.
- tinker preview (READ-ONLY) : parité 11,40 ; forge prix→8,00 ; foreign-var rejet ;
  MAX rejet ; `viande_count` projeté {104:2,105:2,97:2,26:1,22:0}.
- Frozen diff = 0 (aucune édition ; audit-only + gate respecté).
