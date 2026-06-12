# DESIGN GAP ANALYSIS — Top 15 (dispute round-1, vague F, 2026-06-12)

> Jugement design pur des surfaces caisse + borne, calibré sur
> `docs/design/DESIGN_SYSTEM_POLICY_2026-06-10.md` (POLICY) et
> `docs/design/DESIGN_REFERENCES_2026-06-11.md` (REF — McDonald's kiosk v2, BK Reclaim,
> Toast 2.0, Square 2024). Classement par impact gérant/client. Evidence = captures de ce
> dossier (`F-*.png`) + convergence d'hier (`c2/`, `c2/flow/`). Les gates owner connus
> (orange AA, prix-étapes, VAT data, images DATA, etc.) ne sont PAS re-comptés ici sauf
> dimension nouvelle. Verdict sévérité final = adversaire.
>
> **Lecture d'ensemble** : le produit est fonctionnellement dense et numériquement juste
> (0 incohérence de montant relevée sur 8 états frais + 20 re-jugés), mais il fait
> « gestion générique » plutôt que « produit de marque restaurant » sur 4 axes systémiques :
> (1) iconographie emoji non maîtrisée, (2) couleur d'action non normée, (3) typographie
> FR Title-Case héritée d'un template EN, (4) composition portrait borne non travaillée.
> Un kit DS existe pourtant des deux côtés (`kiosk/ds/Ks*.vue`, `pos/v5/PosV5*.vue`) —
> les gaps viennent des écrans qui ne le consomment pas.

---

## GAP-01 — Écran d'accueil borne sombre et « vide » (1er contact client)
- **Surface** : borne /kiosk/idle. **Capture** : `F-01-borne-idle.png`, `c2/kiosk-idle.png`.
- **Constat** : dominante brun/noir + grande ellipse floue centrale (effet image héro manquante) ; petite carte « À emporter » unique ; micro-texte gris « CHOISISSEZ UNE OPTION… » ; CTA rond orange icône-seule sans label.
- **Principe violé** : POLICY §1 « Kiosk = light mode 100% » ; REF §3 #10 « jamais d'écran à dominante sombre » ; REF §1 BK Sizzle « l'idle doit vendre l'entrée en commande : grandes photos appétence, fond clair, CTA plein écran ».
- **Impact** : CLIENT MAX — c'est l'écran qui décide si on touche la borne. Aujourd'hui il n'« appétise » pas et contredit le mandat light.
- **Proposition** : refondre `KioskIdleScreenComponent.vue` (non-frozen) en attract-screen light : photo produit pleine hauteur (vraie photo Le Cayenne du catalogue, jamais inventée), bandeau marque `#F4501E`, CTA unique pleine largeur bas « Commander » ≥80px, rotation 2-3 visuels (`KioskPromoCarouselComponent` existe déjà).
- **Effort** : M. **Frozen ?** NON (KioskIdleScreenComponent hors liste §7).

## GAP-02 — SKU techniques d'upsell en TÊTE de la grille client, badgés « Nouveau », tuiles cassées
- **Surface** : borne catégorie « Sandwich Cayenne ». **Capture** : `F-02-borne-catalogue-sandwichs.png` (jamais capturée hier).
- **Constat** : « Boisson Seule » + « Frites Seules » (tuiles blanches image cassée, description anglaise « Upsell item ») + « Menu (Frites + Boisson) » sont les positions 1-2-3 de la grille, AVANT les vrais sandwichs ; badge « Nouveau » jaune dessus ; la tuile Menu porte une bordure orange que ses sœurs n'ont pas.
- **Principe violé** : REF §3 #7 (grille produits propre) + #18 (jamais de zone blanche brute) ; REF §1 McDo « photo produit dominante » ; merchandising : le héros de la catégorie doit ouvrir la grille.
- **Impact** : CLIENT MAX — la première impression du rayon est 2 tuiles cassées libellées en anglais.
- **Proposition** : DATA (sort_order : SKU upsell en fin de grille ou catégorie dédiée « Extras », retirer le flag « Nouveau », images réelles — recoupe le gate DATA images) + UI : fallback illustré générique marque pour toute tuile sans image dans `KioskCategoriesComponent.vue`.
- **Effort** : S (data) + S (fallback). **Frozen ?** NON (catégories non-frozen ; la part images/desc EN = gate DATA connu, seule la dimension PLACEMENT/badge est nouvelle).

## GAP-03 — Écran « Paiement à la caisse » : l'info déguisée en bouton, le bouton mal aligné, pas de retour
- **Surface** : borne paiement (route comptoir Plan B). **Capture** : `c2/flow/f07-payment.png`.
- **Constat** : le bloc « TOTAL À RÉGLER : 39,90 € » et le CTA « Confirmer ma commande » sont deux rectangles orange pleins identiques empilés (l'info a l'affordance d'un bouton) ; le texte du CTA est aligné à GAUCHE (le bloc au-dessus est centré) ; aucun Retour/Annuler visible ; CTA flotte à ~60% au lieu d'être ancré bas.
- **Principe violé** : REF §3 #3 (1 seul CTA primaire — ici 2 objets « primaires » visuels), #4 (CTA ancré bas), #5 (Retour visible à chaque étape) ; REF §4 anti-pattern « deux CTA primaires concurrents ».
- **Impact** : CLIENT MAX — écran de bascule de la transaction ; risque de tap sur le bloc-info et d'hésitation au moment critique.
- **Proposition** : dans `KioskPaymentComponent.vue` (non-frozen — ≠ admin/pos/PaymentComponent frozen) : total en texte fort sur fond neutre (pattern `KsPriceLine`), CTA unique centré ancré bas pleine largeur, bouton retour ghost en haut.
- **Effort** : S. **Frozen ?** NON.

## GAP-04 — Couleur d'action non normée : « Encaisser » VERT et ORANGE sur le même écran
- **Surface** : caisse POS. **Capture** : `F-05b-caisse-encaissement-vrai-modal.png` (les deux variantes dans le même PNG).
- **Constat** : même action `openCounterCollect` → drawer « Commandes borne — à encaisser » bouton VERT plein (`.kiosk-cash-collect-btn`, `--pos-v5-success`, `PosComponent.vue:5068-5073`, markup :1277) ; panneau « À ENCAISSER BORNE » derrière bouton ORANGE plein (`.pos-shortcuts__cta--cash`, `PosComponent.vue:367`). Vert et orange s'échangent les rôles primaire/validation au hasard de l'écran.
- **Principe violé** : POLICY §2 (rôles couleur : orange = marque/CTA, verts = fonctionnels normés) ; REF Toast/Square : une action = un traitement.
- **Impact** : GÉRANT FORT — l'œil de l'opérateur apprend deux codes pour le même geste ; coût d'apprentissage et d'erreur en rush.
- **Proposition** : token sémantique unique `--pos-action-collect` (recommandé : orange marque plein, le vert réservé aux états « payé/succès ») appliqué aux deux classes ; règle écrite dans POLICY §2.
- **Effort** : S. **Frozen ?** NON (PosComponent.vue hors liste §7).

## GAP-05 — Iconographie EMOJI transverse (8 occurrences relevées, 2 systèmes)
- **Surfaces** : borne panier vide (caddie 🛒), bandeau « À emporter » (🥡), modal encaissement (💶📱🎫), header POS (🖥 « À encaisser », 📋 « Suivi commandes », 🖥 « Écran client »), panneau borne (💰 `PosComponent.vue:348`), drawer (📟), admin show (💸 « Rembourser »). **Captures** : `F-03-borne-panier.png`, `c2/kiosk-cart-empty.png`, `c2/flow/f10-encaisse-modal.png`, `F-04-caisse-pos.png` + crop `c2-pos-pill`, `F-06-caisse-show.png`.
- **Constat** : emojis plateforme mélangés aux icônes vectorielles (FontAwesome/SVG du shell et du kit `pos/v5`) — rendu dépendant de l'OS, poids visuels disparates, lecture « prototype ». Un caddie de supermarché pour un panier de restaurant de marque.
- **Principe violé** : POLICY §6 rappel design-system (1 langage de composants) ; REF §2 Square/Toast (pictos système cohérents) ; DESIGN_SYSTEM_FOUNDATIONS (tokens/iconographie).
- **Impact** : GÉRANT + CLIENT MOYEN-FORT — c'est LE marqueur « générique vs produit fini » le plus répété.
- **Proposition** : sweep emoji → icônes du set existant (FA déjà chargé) ; pour le panier vide, illustration marque (mascotte Le Cayenne déjà présente sur le logo). Interdiction emoji-icône écrite dans POLICY §3.
- **Effort** : M (sweep multi-fichiers, mécanique). **Frozen ?** NON pour tout le relevé (PosComponent, KioskCartComponent, PosCounterCollectModal, show admin) — vérifier au cas par cas si un emoji vit dans un frozen (aucun relevé ici).

## GAP-06 — Composition portrait borne : 40-70% d'écran vide sur 5 états du flux
- **Surfaces** : wizard step (~55% vide), loyalty (~40%), upsell (~70%), paiement (~60%), panier (~45%). **Captures** : `c2/flow/f02,f04,f06,f07.png`, `F-03-borne-panier.png`.
- **Constat** : contenus dimensionnés pour un écran court, plaqués en haut (ou centre) du 1080×1920 ; le milieu de l'écran — la zone chaude McDo — est un dégradé nu.
- **Principe violé** : REF §1 McDo (éléments à forte valeur dans les zones chaudes centre/bas portrait) ; REF §3 #1 (viser 80px+ : la place perdue devrait grossir cibles et photos).
- **Impact** : CLIENT FORT — densité d'information faible, plus de scroll/d'étapes perçues ; vitrine peu vendeuse.
- **Proposition** : passe de composition portrait : cartes options wizard sur 2 rangées pleine hauteur (composants `steps/*.vue` NON-frozen), upsell tuiles agrandies + centrage vertical, panier : bloc totaux collé sous la dernière ligne avec zone suggestions (« vous aimerez aussi ») au milieu.
- **Effort** : L (multi-écrans). **Frozen ?** MIXTE — steps/cart/loyalty/payment NON-frozen ; `KioskUpsellComponent.vue` et le shell `KioskWizardComponent.vue` FROZEN → leur part = observation/gate owner, pas d'édition.

## GAP-07 — Modal « Session active » : l'action destructive est le primaire visuel
- **Surface** : caisse, bouton « Caisse ». **Capture** : `F-05-caisse-encaissement-modal.png`.
- **Constat** : « Clôturer la caisse » = seul bouton plein (rouge foncé, pleine largeur, dernière position) d'un modal consulté en routine pour lire fond/mouvements ; « Voir les mouvements » est en ghost au-dessus.
- **Principe violé** : hiérarchie destructif-jamais-primaire (pattern admin existant : delete = outline rouge + confirmation) ; REF Toast (fonctions rares → overflow).
- **Impact** : GÉRANT FORT — la clôture déclenche le flux Z fiscal ; un tap routinier au mauvais endroit ouvre un parcours lourd.
- **Proposition** : dans `resources/js/components/admin/cash/PosCashDrawerSessionDialog.vue` : « Clôturer » en outline rouge taille standard, « Voir les mouvements » en primaire neutre ; confirmation existante conservée.
- **Effort** : S. **Frozen ?** NON.

## GAP-08 — « Ouvrir la caisse » : double champ de saisie pour le même montant
- **Surface** : caisse, ouverture session. **Capture** : `F-04a-caisse-session-overlay.png` (état jamais capturé hier).
- **Constat** : grand afficheur formaté « 50,00 € » (avec chips +5/+10/+20/+50/Effacer) ET un second input brut « 50 » en dessous — deux champs simultanés pour le fond de caisse, sans libellé différenciant.
- **Principe violé** : POLICY §3 (1 input = 1 label) ; affordance unique par donnée.
- **Impact** : GÉRANT MOYEN-FORT — premier geste de la journée ; doute « lequel fait foi ? » + risque de divergence saisie.
- **Proposition** : fusionner en un seul champ formaté éditable (pattern `PosV5Numpad` existe) ; chips d'appoint conservées.
- **Effort** : S. **Frozen ?** NON.

## GAP-09 — Hiérarchie des numéros de commande inversée côté gérant
- **Surface** : admin show. **Captures** : `F-06-caisse-show.png`, `c2/flow/f11.png`.
- **Constat** : « N° Commande: #1206264522· N°A0005 » — l'ID interne 10 chiffres est le titre (gros, orange), le numéro métier A0005 (celui que le client borne présente, cf. f08 « Présentez votre numéro ») est en petit, avec un point médian collé « 2· N° ».
- **Principe violé** : REF §2 Olo (statut unifié + identité de commande lisible) ; hiérarchie info = fréquence d'usage.
- **Impact** : GÉRANT FORT — le rapprochement client⇄commande se fait par A000x ; l'écran force un scan visuel inverse.
- **Proposition** : titre = « Commande N°A0005 » + badge canal ; ID interne en métadonnée discrète copiable ; corriger l'espacement du séparateur.
- **Effort** : S. **Frozen ?** NON (page show admin).

## GAP-10 — Title Case anglophone systémique sur les libellés FR
- **Surfaces** : login (« Bon Retour », « Mot De Passe », « Se Souvenir De Moi »), sidebar (« Tableau De Bord », « Commandes Caisse », « Vue Caisse Unifiée », « Caisse Livreur »), breadcrumbs, boutons (« Imprimer La Facture », « Réinitialiser Les Filtres »), badges (« En Préparation »). **Captures** : `F-06-caisse-show.png`, `c2/flow/f11,f12.png`, `c2/cash-overview.png` (+ page login vue à l'incident run-2).
- **Constat** : capitalisation Title Case héritée du template EN appliquée mot à mot au FR — typographiquement fautif en français (règle : majuscule initiale seule).
- **Principe violé** : POLICY §4 i18n (0 label brut, qualité FR V1 LOCAL) ; REF §3 #25 (FR partout, soigné).
- **Impact** : GÉRANT MOYEN mais OMNIPRÉSENT — chaque écran du back-office le crie « template acheté ».
- **Proposition** : sweep i18n des libellés FR (fichiers lang/menus), PAS de CSS text-transform ; règle « sentence case FR » dans POLICY §4. (Le cas PaymentComponent frozen reste gaté — tout le reste est éditable.)
- **Effort** : M (mécanique, large). **Frozen ?** NON sauf l'îlot PaymentComponent (gate connu, exclu).

## GAP-11 — Terminologie instable sur les concepts cœur
- **Surfaces** : caisse + admin. **Captures** : `F-06-caisse-show.png` (« Imprimer La Facture » / « Passager »), `c2/flow/f10` (« Confirmer & Imprimer ticket » / « Client passage »), `c2/flow/f11` (« Client Borne »), `F-02` (badges « Nouveau » état vs « Personnaliser » verbe), `c2/flow/f12` (statuts « Accepter »(gate)/« Annulée »/« En attente »).
- **Constat** : 2 noms pour l'artefact d'impression (ticket/facture — sensible NF525 : un ticket simplifié n'est pas une facture), 3 noms pour le walk-in (Passager/Client passage/Client Borne), grammaire de badges et statuts mélangée (état vs verbe).
- **Principe violé** : cohérence lexicale design-system (POLICY §4) ; REF Toast (vocabulaire d'action unique).
- **Impact** : GÉRANT MOYEN-FORT — formation et ambiguïté fiscale (facture ≠ ticket).
- **Proposition** : glossaire UI court dans POLICY (ticket / Client passage / badges = états) + sweep i18n des occurrences déviantes.
- **Effort** : S-M. **Frozen ?** NON (les occurrences relevées sont hors frozen).

## GAP-12 — Fuites d'état interne dans l'UI de production
- **Surfaces** : caisse + admin. **Captures** : `c2/cash-overview.png` (« …saisir le comptage physique du tiroir **(à venir)** »), `c2/flow/f10` (« Ouvre le tiroir **(simulation)** »), `F-04` (« **Filiale #1** », sélecteur « Filiale Le Cayenne (Principal) »), `F-06` (« **Référence interne: 1** », « **Instruction: COCA-COLA 33CL** » — champ instruction auto-rempli du nom produit en capitales, n'instruisant rien).
- **Constat** : vocabulaire interne (filiale, simulation, à-venir, référence interne, instruction-miroir) exposé au gérant.
- **Principe violé** : REF §3 #19 (jamais de label technique) ; V1 LOCAL mono-site (CONSTITUTION) rend « Filiale » vide de sens.
- **Impact** : GÉRANT MOYEN — confiance produit ; « (à venir) » promet une dette en pleine réconciliation d'espèces.
- **Proposition** : masquer l'instruction quand `instruction === nom produit` ; supprimer la ligne « (à venir) » tant que le comptage n'existe pas ; « Filiale #1 » → rien (mono-site) ; « (simulation) » derrière le flag `POS_SIMULATION_HARDWARE` uniquement en dev.
- **Effort** : S. **Frozen ?** NON.

## GAP-13 — Vue Caisse Unifiée : « 0,00 € » et « 136,00 € attendus » sans explication de période
- **Surface** : admin /admin/cash-overview. **Capture** : `c2/cash-overview.png`.
- **Constat** : KPI « GRAND TOTAL 0,00 € / 0 tx » (filtre du jour) au-dessus d'une réconciliation « Espèces encaissées (session en cours) : 86,00 € / attendues : 136,00 € » (session ouverte 22:46 la veille) — deux périmètres temporels juxtaposés sans lien visuel ni hint de période sur les KPI.
- **Principe violé** : REF §3 #24 (chaque montant avec son périmètre recalculé visible) ; hiérarchie d'info dashboard.
- **Impact** : GÉRANT FORT — c'est l'écran de confiance argent ; lire 0 € et 136 € côte à côte sans explication = doute immédiat.
- **Proposition** : sous-titre de période sur chaque carte KPI (« du 11/06 00:00 à maintenant ») + bandeau réconciliation daté (« session ouverte le 10/06 22:46 ») ; à terme aligner le défaut du filtre sur la session.
- **Effort** : M. **Frozen ?** NON.

## GAP-14 — Cibles tactiles secondaires borne < 48px
- **Surfaces** : panier borne (poubelle ~36px coin de carte, crayon ~32px), rail catégories (vignettes ~64px mais libellés ~8-10px illisibles), stepper qté. **Captures** : `F-03-borne-panier.png`, `c2/flow/f03.png`, `F-02` (rail).
- **Principe violé** : REF §3 #1 (≥48px partout, 80px+ visé borne), #8 (corps ≥20px équivalent) ; EN 301 549 / EAA en vigueur depuis 06/2025 (REF §1).
- **Impact** : CLIENT MOYEN-FORT — suppressions/éditions ratées au doigt, rail catégories inutilisable debout.
- **Proposition** : poubelle/crayon → 48-56px avec zone d'extension invisible ; libellés rail ≥14px sur 2 lignes ; piloter par tokens `KsButton`/`KsChip` du kit ds.
- **Effort** : M. **Frozen ?** NON (KioskCartComponent, KioskCategoriesComponent).

## GAP-15 — Micro-typographie des données : montants alignés à gauche, troncatures brutales
- **Surfaces** : historique admin (colonne MONTANT alignée à gauche), POS rail catégories (« Sandwich… », « Bols Gourm… »), tuile borne (« Choix de viande + crud... » coupé mi-mot), eyebrow « NOS + Sandwich Cayenne » (accord pluriel cassé), placeholder ALL-CAPS « SAISIR UN CODE PROMO... ». **Captures** : `c2/flow/f12.png`, `F-04-caisse-pos.png`, `F-02-borne-catalogue-sandwichs.png`, `F-03-borne-panier.png`.
- **Principe violé** : REF §3 #12 (prix alignés droite, chiffres tabulaires), #7 (ellipse propre ≤2 lignes) ; qualité FR.
- **Impact** : GÉRANT MOYEN (scan colonnes chiffres) + CLIENT FAIBLE-MOYEN (lecture descriptions).
- **Proposition** : `text-align:right + font-variant-numeric:tabular-nums` sur colonnes montants ; ellipse à frontière de mot (CSS line-clamp 2) ; eyebrow dynamique sans « NOS » hardcodé ; casse normale placeholder.
- **Effort** : S. **Frozen ?** NON.

---

## Hors-classement (pour la planification)
- **Baseline POSITIVE à généraliser** : `c2/kiosk-error-payment-refused.png` — hiérarchie 3 actions exemplaire (plein → blanc → outline destructif) ; `c2/flow/f08` cash-instruction (n° énorme + CTA ancré + countdown) ; pattern « CTA avec prix en chip » du panier F-03. Le langage existe — il faut le propager.
- **Couverture à réparer au prochain cycle** (anomalie process, pas design) : écran CONFIRMATION borne jamais capturé ; grilles produits par catégorie non couvertes hier (10/25 captures statiques c2 = redirects, MD5 §1 WAVE_REPORT).
- **Gates owner recroisés sans re-compte** : orange #F4501E AA (omniprésent prix/CTA), prix sur étapes wizard (total footer f02 = total panier, pas un prix d'option — conforme POLICY §5 à confirmer par l'adversaire), images/descriptions DATA (recoupé GAP-02), tutoiement cash-overview, dates à tirets (recoupé GAP-15 pour l'alignement seulement), « Accepter » statut (recoupé GAP-11 pour la grammaire d'ensemble).
- **Env harnais** : ws:6001 down → polling + bouton « Actualiser » manuel visible (F-05b) — re-tester le rendu temps-réel sur l'env nominal avant de juger la fraîcheur des panneaux.
