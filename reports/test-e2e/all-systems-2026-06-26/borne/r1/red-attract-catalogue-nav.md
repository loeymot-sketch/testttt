# BORNE r1 — Lentille ADVERSAIRE-RED — Attract + catalogue + navigation

Sous-système : `/kiosk/idle` + `/kiosk/categories` (sidebar + grille produits) + nav.
Cible : KioskIdleScreenComponent, KioskMenuService::build, KioskCategoriesComponent, kioskRoutes.
Méthode : Read code ancré + SELECT foodking_e2e (Status::ACTIVE=5, INACTIVE=10) + tinker
build() sans effet de bord + Playwright LIVE :8766 (auto-login local bypass, store Vuex
muté en mémoire DOM uniquement — 0 écriture DB, 0 commande, 0 mutation persistée).

Anti-halluc : tout file:line grep/Read confirmé ; chaque finding a repro live + evidence.
Screenshots MCP indisponibles (timeout font-load environnemental) → evidence = extraction
DOM/store live (plus forte que le pixel pour ces défauts structurels).

---

## FINDINGS

[P2] resources/js/helpers/kioskFormatPrice.js:37-40 — TOUS les prix borne en format ANGLAIS « €1,90 » au lieu du FR « 1,90 € » (config dit RIGHT, rendu LEFT)
  repro: serveur :8766 → /kiosk/categories?cat=10 (Boissons) ; lire les prix rendus.
  evidence: DOM live `kiosk-product-price-*` = ["€1,00","€1,50","€1,90","€1,90"]
    (symbole PRÉFIXÉ, sans espace). Store live `globalState.lists.site_currency_position
    = 10` (entier = CurrencyPosition::RIGHT=5? NON : enum RIGHT=10, LEFT=5). `.env
    CURRENCY_POSITION=10` = RIGHT. kioskFormatPrice.js:37 teste `options.position ===
    'right'` (string) ; `10 === 'right'` = false → ligne 40 `${symbol}${formatted}` (LEFT).
    SettingResource.php:41 émet la valeur brute 10 ; SiteRequest.php:32 la valide `numeric`.
    FALSE-GREEN : tests/js/kioskFormatPrice.spec.js:22 nourrit la STRING 'right' que le
    backend N'ÉMET JAMAIS (il émet 10) → test vert pendant que la prod rend « €1,90 ».
    Admin/POS épargnés (formatPrice.js hardcode Intl fr-FR → « 1,90 € »). SEULE la borne
    client (kioskPriceMixin) subit : catalogue + wizard + panier + confirmation = tout faux.
  lentille: client — mandat FR ADR-007 ; DISCIPLINE §2 « Prix non-FR €7.90 au lieu de
    7,90 € côté user → REJET ». Le client voit un format US sur 100% de la borne.
  reco: NON-frozen. kioskFormatPrice.js:37 normaliser la position avant compare :
    `const pos = String(options.position) === '10' || options.position === 'right' ?
    'right' : 'left'` (mapper l'enum CurrencyPosition::RIGHT=10 → 'right'), OU
    getPriceOptionsFromStore (l.66) traduire 10→'right'/5→'left'. Ajouter au test le cas
    `site_currency_position: 10` (numérique, le payload réel) pour tuer le false-green.

[P2] app/Services/Kiosk/KioskMenuService.php:66-68 — catégorie active visible SANS produit kiosk = cul-de-sac quasi-blanc (grille vide, AUCUN empty-state ; jumeau POS protégé, borne NON)
  repro LIVE prouvé: store Vuex en mémoire — injecter 1 catégorie active fake (id 99999,
    0 item) via `kioskMenu/SET_CATEGORIES` + `SET_SELECTED_CATEGORY 99999` (0 DB write).
  evidence: DOM main = "CATVIDETEST\n\n0 produit" ; `.kiosk-product-grid` innerText=""
    (vide), productCards=0, `kiosk-categories-empty` ABSENT (ce bloc ne se déclenche qu'à
    `categories.length===0`, KioskCategoriesComponent.vue:76, PAS pour une catégorie
    sélectionnée à 0 produit). Sidebar : la catégorie est cliquable. La grille
    (l.145 `v-for="product in catalogProducts"`) n'a AUCUN v-else empty-state.
    Mécanisme: build() requête les catégories ACTIVE (l.66-68) SANS `whereHas('items')`
    (grep confirmé absent), puis filtre les items par isVisibleOn APRÈS (l.108-110). Une
    cat dont tous les items actifs sont `channels=['pos']`, ou tous INACTIVE, ou tous
    indisponibles → apparaît en sidebar avec grille vide. Atteignable en prod par un seul
    geste admin (désactiver tous les items d'une cat, ou cat saisonnière vidée).
    NB live actuel: 9 cats actives, toutes ≥2 items kiosk → le cul-de-sac est LATENT
    (pas un blank live aujourd'hui), mais le garde manquant est réel et reproduit en DOM.
    Twin POS: MenuProjectionService.php:69-71 N'A PAS non plus whereHas (le « jumeau
    corrigé » du plan est FAUX au niveau service) MAIS le client POS a un empty-state
    (PosComponent.vue:539-545) ; la borne n'en a aucun → la borne est le seul cul-de-sac.
  lentille: client — il tape une catégorie et fait face à un écran quasi-blanc (« 0
    produit » en gris pâle, rien d'autre), sans message ni issue ; perte de confiance.
  reco: NON-frozen, 2 options (scope-min) : (A) borne — KioskCategoriesComponent.vue ajouter
    un v-else empty-state sur la grille quand `catalogProducts.length===0 && selectedCategoryId`
    (« Catégorie momentanément vide — choisissez-en une autre ») ; (B) source — kioskMenu
    getter `sidebarCategories` filtrer les cats dont 0 item visible (comme l'exclusion 315
    déjà présente l.85). Idéal : (A) au minimum (filet UX), + test À CRÉER
    tests/Feature/Kiosk/KioskEmptyCategoryHiddenTest.php (déjà prévu plan §49).

[P3] resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue:145-220 — catégorie « tout épuisé » : 8 cartes grisées sans message agrégé (« tout est indisponible »)
  repro LIVE: store — marquer `is_available=false` sur les 8 items Boissons (cat 10) via
    SET_ITEMS (0 DB write), sélectionner cat 10.
  evidence: DOM = 8 cartes rendues, toutes `aria-disabled="true"` + classe `filtered-out`,
    chacune tag « Épuisé » (per-item OK, isProductUnavailable l.450-457). MAIS aucun
    message d'ensemble (aggregateMessagePresent=false) : header « 8 produits » + 8 cartes
    grises non-tapables. Moins grave que l'empty (le « Épuisé » par carte donne un signal).
  lentille: client — comprend qu'un produit est épuisé, mais pas pourquoi RIEN n'est
    cliquable dans toute la catégorie ; friction mineure, pas un blank total.
  reco: NON-frozen, optionnel — bandeau « Tous les articles de cette catégorie sont
    indisponibles » quand `catalogProducts.length>0 && tous isProductUnavailable`. P3.

---

## RÉFUTÉS (verify-before-report — NE PAS surfacer comme défauts)

- T-2.a.4 Sélecteur langue placebo / FR-lock confus : REFUTÉ live. Le sélecteur de langue
  ne s'affiche QUE si `enabledLanguages.length > 1` (KioskIdleScreenComponent.vue:16). Live
  `langSelectorPresent=false` (1 seule langue activée). changeLanguage:263 est inerte mais
  le bouton n'est PAS rendu → aucune confusion client. Le no-op est sans surface visible.
- T-2.a.1 Order-type forcé idle = client bloqué : REFUTÉ live. Sans order-type,
  /kiosk/categories redirige proprement vers /kiosk/idle (URL devenue kiosk/idle ;
  ensureOrderTypeSelected l.438-444 → router.replace(idle)). Pas de blocage, pas de blank.
- « Produit sous le pli invisible » : REFUTÉ. main scrollable (mainScrollable=true) ;
  la grille défile, pas de trap below-fold.
- Cat Suppléments masquée fragile (KioskCategoriesComponent.vue:599-613) : hors-scope
  attract/cat ici (c'est hasOptions wizard-gate, pas la nav catalogue). NON traité ce round.
- Console errors live (soketi ws:6001 down, /broadcasting/auth 403) : ENVIRONNEMENTAL
  (sync/infra), hors lentille attract/catalogue ; non comptabilisé comme finding ici.

## NF525 / argent
Aucun impact fiscal : le format prix borne est purement affichage (SSOT prix = backend
PricingService/quote ; le rendu LEFT/RIGHT ne change pas la valeur soumise). Pas de
sous/sur-facturation, pas de divergence Z. P2 = qualité FR/UX, pas fiscal.
