# DESIGN REFERENCES 2026-06-11 — Sourcing externe borne + caisse (ANNEXE)

> Annexe read-only au DESIGN_SYSTEM_POLICY_2026-06-10.md — ne le remplace pas.
> Contraintes projet intégrées : palette Cayenne `#F4501E` / `#FFB800` / `#1A1A1A`,
> **light-mode 100% borne** (mandat owner), locale **FR exclusive**, **prix jamais
> affiché sur une étape de wizard** (NF525 SSOT backend — le prix vit dans le panier,
> pas dans les steps).

---

## §1 Références borne (kiosk fast-food)

### McDonald's kiosk v2 (référence n°1 mondiale)
- Optimisation pilotée data : heat-maps de tap + points d'abandon mesurés à chaque étape ; les éléments à forte valeur sont placés dans les zones chaudes (centre / bas d'écran portrait). Imitable : instrumenter les abandons par étape du wizard kiosk.
- Hiérarchie : photo produit dominante, nom court, prix secondaire ; navigation catégories en rail latéral persistant ; bouton panier ancré en bas, toujours visible.
- Upsell contextuel basé sur le contenu du panier (pas aléatoire), 1 écran d'upsell max avant paiement.
- Sources : [Lizard Global — McDonald's kiosk UI/UX data 2024](https://www.lizard.global/en/blog/how-mcdonalds-kiosks-use-ui-ux-data-analysis-to-boost-revenue), [UX Collective case study](https://uxdesign.cc/mcdonalds-kiosk-ordering-system-ui-ux-case-study-fe7b3693f12c), [Medium « Tap, Order, Spend More » (2025)](https://medium.com/design-bootcamp/tap-order-spend-more-67a5f74b9763).

### Burger King « Reclaim the Flame » / format Sizzle (2023-2025)
- Programme 400 M$+, ~370 remodels début 2025 ; kiosks + menuboards digitaux au cœur du format ; +40 % de commandes digitales YoY dans les sites équipés. Imitable : la borne est le canal par défaut, le comptoir devient secondaire — l'idle screen doit « vendre » l'entrée en commande (CTA plein écran, branding chaud).
- Esthétique fast-casual : grandes photos appétence, fond clair, accents brand saturés — compatible Cayenne light-mode + `#F4501E`.
- Sources : [QSR Magazine](https://www.qsrmagazine.com/story/burger-king-has-spent-hundreds-of-millions-on-remodels-heres-a-look-at-why/), [NRN](https://www.nrn.com/quick-service/burger-king-evolves-reclaim-the-flame-investments), [Restaurant Business](https://www.restaurantbusinessonline.com/financing/burger-kings-revitalization-enters-new-chapter).

### Patterns génériques bornes 1080×1920 portrait (industrie 2024-2026)
- Touch targets : minimum absolu 9-12 mm doigt ; recommandation ~20 mm ≈ **80-82 px sur 21-22″ 1080p** avec ~20 px d'espacement. Notre règle interne ≥48 px CSS = plancher WCAG, viser 80 px+ sur borne.
- Texte lisible debout à 60-90 cm : corps ≥20-24 px équivalent 1080p, titres beaucoup plus gros.
- Navigation permanente : Retour / Accueil / Annuler toujours visibles ; <2 s entre étapes ; timeout d'inactivité avec décompte visible + message privacy (« votre session va se réinitialiser »).
- Zone atteignable : contenus interactifs entre 15-48 in du sol (EN 301 549 §8.3 reach) ; éviter les actions critiques tout en haut de l'écran portrait.
- Erreurs en langage clair (jamais de code), Undo/Modifier avant confirmation finale.
- Sources : [Kiosk Industry — UX/UI checklist](https://kioskindustry.org/kiosk-ux-ui-how-to-design-checklist/), [Look — Touch screen kiosk guide](https://www.lookdigitalsignage.com/blog/touch-screen-kiosk-guide), [Wavetec — kiosk UX challenges](https://www.wavetec.com/blog/challenges-in-ux-design-of-self-service-kiosks/), [SiteKiosk — reducing friction](https://sitekiosk.us/kiosk-design-user-experience/).

### Upsell non-intrusif (consensus 2025)
- **≤3 prompts par transaction** ; au-delà = pop-up fatigue, rejet automatique, abandon >8 % = signal de redesign.
- Boutons symétriques sur le prompt (« Ajouter » / « Non merci » de même poids) — les boutons asymétriques sont un dark pattern mesurablement contre-productif.
- Suggestion pertinente au panier (complémentaire : boisson avec sandwich), pas un catalogue ; exécution cohérente = checks kiosk > checks comptoir (PAR QSR Index 2025).
- Sources : [Seen Labs — Pop-up fatigue](https://seenlabs.com/blog/pop-up-fatigue-how-aggressive-upselling-backfires-on-kiosk-conversions), [Bite — AI kiosk upselling](https://blog.getbite.com/articles/faster-service-higher-checks-what-ai-kiosk-upselling-actually-does-to-throughput), [GRUBBRR 2026](https://grubbrr.com/self-service-kiosks-qsr-profitability-2026/).

### Accessibilité réglementaire (FR/UE)
- **EAA en vigueur depuis 28 juin 2025** ; EN 301 549 v3.2.1 = WCAG 2.1 AA + §8.3 (reach/approche des terminaux self-service) ; v4.1.1 attendue 2026 alignera WCAG 2.2 AA. Cibler WCAG 2.2 AA dès maintenant (focus visible, target size 2.5.8, dragging alternatives).
- Sources : [TPGi — EN 301 549](https://www.tpgi.com/understanding-en-301-549-the-european-standard-for-digital-accessibility/), [Vispero — EAA kiosk Q&A](https://vispero.com/resources/3-months-to-the-european-accessibility-act-deadline-key-website-and-kiosk-questions-answered/), [Level Access — EAA](https://www.levelaccess.com/blog/eu-accessibility-requirements-and-eaa-compliance/).

---

## §2 Références POS / caisse

### Toast POS « New Experience » (2024+) — référence line-item
- **Composition bipanneau** : ticket/check à gauche (total + actions toujours visibles), grille menu à droite — exactement notre topologie POS.
- En-tête du check : table, nom, opérateur, puis Split / Discount / Service charge ; les fonctions rares migrent dans un overflow (⋮) pour préserver la densité.
- Ligne d'item sélectionnée → actions contextuelles : quantité, répéter, supprimer, note, remise ; **modifiers en bas d'écran avec indicateur « requis »**.
- Sur petit écran (Toast Go) : header auto-collapse + boutons d'envoi (Hold/Stay/Send) **verrouillés en bas** — pattern « action bar sticky » à imiter pour l'encaissement.
- Source : [Toast — New POS Experience Ordering Screens](https://support.toasttab.com/article/New-POS-Experience-Ordering-Screens), [doc.toasttab.com UI options](https://doc.toasttab.com/doc/platformguide/adminUiOptionsReference.html).

### Square for Restaurants (refonte 2024)
- Refonte généralisée février 2024 : navigation simplifiée, **grille de tuiles éditable** (taille auto, tri, pages multiples, drag-and-drop, tuile « + » pour items/groupes/fonctions). Imitable : grille produits paramétrable par le gérant, pas figée en code.
- Multi-tender natif : carte / wallet / QR sur le même écran d'encaissement.
- Sources : [Square Community — Restaurants POS redesigned](https://community.squareup.com/t5/Product-Updates/New-Restaurants-POS-redesigned-with-you-in-mind-Opt-in-today/bc-p/704896), [Square — Edit grid layout](https://squareup.com/help/us/en/article/7804-organize-your-menu-with-square-for-restaurants).

### Lightspeed Restaurant (K/O-Series)
- Register en **3 zones : order summary / keypad / menu** ; le résumé porte items, sièges, coursing, tags. Dark mode disponible côté staff (écran caisse en salle sombre) — mais notre mandat = light borne ; dark POS reste une option future owner-gated, pas V1.
- Drag-and-drop produits/catégories pour le layout.
- Sources : [Lightspeed — Register screen](https://k-series-support.lightspeedhq.com/hc/en-us/articles/360050328394-Understanding-the-Register-screen), [Lightspeed — POS look and layout](https://o-series-support.lightspeedhq.com/hc/en-us/articles/31329442916891-Design-your-POS-look-and-layout).

### Olo (Rails / ordering)
- Référence flux d'agrégation de commandes digitales injectées dans le POS (pas une UI caisse en soi) : statut de commande unifié + horodatage canal — utile pour notre historique commandes (badge canal borne/caisse, tri chronologique strict). Source : [Olo](https://www.olo.com/).

---

## §3 Checklist actionnable (vérifiable sur screenshot)

**Touch & layout**
1. Touch target ≥48 px CSS partout ; ≥80 px sur borne pour actions principales.
2. Espacement ≥8 px entre cibles adjacentes (pas de boutons collés).
3. 1 seul CTA primaire (`#F4501E` plein) par écran ; secondaires en outline/ghost.
4. CTA de progression ancré en bas d'écran portrait (zone pouce/reach), pleine largeur ou ≥50 %.
5. Retour / Annuler visibles à chaque étape du wizard (pas de cul-de-sac).
6. Rayons cohérents (1 token cards, 1 token boutons) — pas de mix arrondi/carré aléatoire.
7. Grille produits : ratio image constant, nom ≤2 lignes ellipsé, pas de tuile orpheline déformée.

**Typo & contraste**
8. Corps ≥20 px équivalent borne ; aucun texte fonctionnel <14 px.
9. Contraste AA : ≥4.5:1 texte normal, ≥3:1 grand texte/icônes — vérifier `#FFB800` jamais utilisé pour du texte sur blanc.
10. Light-mode borne : fonds clairs, `#1A1A1A` pour le texte, jamais d'écran à dominante sombre.

**Prix & NF525**
11. Aucun prix affiché sur une étape de wizard (steps choix) — prix uniquement tuile catalogue + panier/ticket.
12. Prix alignés à droite, chiffres tabulaires, format FR (`12,50 €`, espace insécable avant €).
13. Total panier toujours visible (barre panier persistante borne ; ticket latéral caisse).

**Wizard & panier**
14. Indicateur d'étape visible (x/y ou barre de progression).
15. Options obligatoires marquées (« requis ») et bloquantes avant « Suivant ».
16. ≤3 prompts d'upsell par transaction ; boutons accepter/refuser de même taille.
17. Modification de ligne possible depuis le panier (quantité, supprimer, éditer) sans tout recommencer.

**États & feedback**
18. État vide illustré + CTA (panier vide, recherche sans résultat) — jamais une zone blanche brute.
19. Erreur en français clair + action de récupération ; jamais de code/stack/label brut (`kiosk.foo`, `Label.X`).
20. Paiement : état d'attente explicite (spinner + « Présentez votre carte »), puis confirmation pleine page (succès vert / échec avec retry), jamais d'écran figé ambigu.
21. Timeout d'inactivité borne avec décompte visible avant reset.
22. Feedback tactile <100 ms sur tout tap (état pressed visible).

**POS spécifique**
23. Ticket latéral : 1 ligne = item + qté + prix, modifiers indentés dessous ; total/TVA en pied collant.
24. Encaissement multi-tender : chaque tender listé avec montant + reste à payer recalculé visible.
25. FR partout : aucun libellé anglais résiduel sur screenshot (Submit, Cart, Checkout…).

---

## §4 Anti-patterns à détecter en audit

- **Prix sur une étape de wizard** (violation NF525 SSOT) — le plus critique.
- Boutons d'upsell asymétriques (« Oui » géant / « Non » minuscule) ou >3 prompts.
- Touch targets <48 px, liens texte nus comme seule action sur borne.
- Texte `#FFB800` sur fond clair, ou orange sur orange (contraste < AA).
- Dark mode résiduel sur borne (mandat light 100 %).
- Format prix anglo-saxon (`€1.50`, `$`, point décimal) — cf. POS-ERG-07 connu sur wizard frozen.
- Labels i18n bruts, anglais résiduel, `0undefined`, pluriels cassés.
- Deux CTA primaires concurrents sur le même écran ; CTA primaire hors zone de reach.
- Écran de paiement sans état d'attente/échec distinct ; double-tap possible sur « Payer » (pas de disable post-tap).
- Étape sans Retour/Annuler ; timeout sans avertissement ; reset perdant le panier sans confirmation.
- Zone interactive au-dessus de ~120 cm équivalent écran (reach EN 301 549 §8.3).
- Grille produits à tailles de tuiles incohérentes / images étirées.

---
*Sourcing 2026-06-11 — WebSearch/WebFetch. Annexe informative ; le SSOT design reste DESIGN_SYSTEM_POLICY_2026-06-10.md.*
