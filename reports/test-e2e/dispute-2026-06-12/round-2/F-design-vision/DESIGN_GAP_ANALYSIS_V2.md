# DESIGN GAP ANALYSIS V2 — re-jugement post-heals (dispute round-2, vague F, 2026-06-12)

> Mise à jour du `round-1/F-design-vision/DESIGN_GAP_ANALYSIS.md` (15 gaps) après les ~32 heals
> H1/H2/H3 + rebuild bundles (12/06 13:07) + seeder upsell. Evidence = quartets frais `F2-*.png/.dom.html`
> de CE dossier (DOM = `#app.outerHTML`, exploitable — correctif ADV-F-P1-4). Niveau d'exigence
> maintenu : McDonald's kiosk 2025 / POLICY `docs/design/DESIGN_SYSTEM_POLICY_2026-06-10.md` /
> REF `docs/design/DESIGN_REFERENCES_2026-06-11.md`. Les citation-drifts relevés par l'adversaire
> R1 (ADV-F-P2-15 : 4 trous normatifs POLICY) restent à combler côté DOC — non re-comptés ici.
>
> **Bilan : 4 fermés · 3 partiels · 8 ouverts · 0 nouveau gap design** (2 observations process infra).
> Les heals ont fermé tout ce qui était « défaut » (P0/P1 + couleur + destructif-primaire) ;
> ce qui reste est la couche « produit de marque vs gestion générique » : emoji, composition
> portrait, Title Case FR, terminologie, fuites internes, micro-typo — exactement les 4 axes
> systémiques du constat R1, qui demandent des sweeps normés plus que des fixes ponctuels.

---

## FERMÉS (4)

### GAP-01 — Idle borne sombre → ✅ FERMÉ (la part « mandat light »)
- **Evidence** : `F2-01-borne-idle.png` + probe computed : `.kiosk-idle-fallback` =
  `linear-gradient(#FFF, #FFE8DD 55%, #F4501E)`, overlay `none`, scrim absent, titre encre `rgb(15,15,15)`,
  `kiosk-idle--has-video` absent. Heal H2 `dcf675617` (`KioskIdleScreenComponent.vue:377`) servi par le bundle.
- POLICY §1 « light mode 100% » : CONFORME. La variante sombre ne survit que gatée vidéo (V1 sans vidéo).
- **Résiduel design (reporté en « ambition », pas un défaut policy)** : l'écran reste peu vendeur —
  pas de photo produit héro, CTA rond icône-seule sans label, micro-texte « CHOISISSEZ UNE OPTION… »,
  3 emojis flottants 🌮🍔🍟 (compté dans GAP-05). La proposition attract-screen R1 reste valable
  pour la planification UI/UX finale.

### GAP-02 — SKU techniques en tête de grille client → ✅ FERMÉ
- **Evidence** : `F2-02-borne-catalogue-sandwichs.png` + probe : `skuTechniques=[]`, `upsellItemDesc=false`,
  0 image cassée. La grille « Sandwich Cayenne » ouvre sur SANDWICH CAYENNE 7,00 € puis BIG CAYENNE 9,50 €,
  vraies photos, badge « Personnaliser ». La catégorie interne `technique-interne-upsell` (channels=admin)
  n'apparaît ni en grille ni en sidebar. Seeder H1 `bbeecd437` appliqué sur foodking_e2e.
- Le gate DATA images/descriptions des AUTRES catégories reste un gate connu (non re-compté).

### GAP-04 — « Encaisser » VERT vs ORANGE sur le même écran → ✅ FERMÉ
- **Evidence** : probes computed `F2-04`/`F2-04b` : panneau `.pos-shortcuts__cta--cash` = `rgb(244,80,30)`
  ET drawer `.kiosk-cash-collect-btn` = `rgb(244,80,30)` (source `PosComponent.vue:5133-5144`, commentaire
  DISPUTE-R1 ADV-F-P2-1) + libellé i18n unifié `label.pos_shortcut_cash_cta` (`:1284-1287`). PNG : les deux
  surfaces rendent le même orange marque. Une action = un traitement.
- ⚠ Effet de bord assumé : le libellé partagé embarque l'emoji 💳 → l'emoji a GAGNÉ une surface
  (drawer, ex-« ✓ Encaisser »). Compté dans GAP-05.

### GAP-07 — « Clôturer la caisse » = primaire visuel d'un modal de routine → ✅ FERMÉ
- **Evidence** : `F2-07-caisse-session-dialog.png` + probe : « Clôturer la caisse » = fond transparent,
  `border 1px solid rgb(194,30,47)`, texte rouge (variante danger-outline, heal H3 `7fccbab3b`) ;
  « Voir les mouvements » = blanc bordé au-dessus. Le destructif n'est plus le seul bouton plein.
  Stats session (fond initial / ouverte le / mouvements / montant attendu) lisibles en cartes.

---

## PARTIELS (3)

### GAP-03 — Écran « Paiement à la caisse » borne → ◐ PARTIEL (le P1 est fermé, le P2 reste)
- **Fermé** : échappatoire — « Retour au panier » 640×64px sous le CTA (`kiosk-payment-counter-back`,
  heal H2 `43c5f2d76`), prouvé `F2-03b-borne-paiement-planB.png` + probe. Plus de cul-de-sac (ADV-F-P1-1).
- **Reste (= ADV-F-P2-6)** : le bloc info « TOTAL À RÉGLER : 3,00 € » est TOUJOURS un rectangle orange
  plein visuellement identique au CTA « Confirmer ma commande » empilé dessous (info déguisée en bouton,
  REF §4 anti-pattern 2-CTA) ; le libellé du CTA rend calé à gauche quand le bloc info est centré ;
  le cluster flotte à ~55 % de hauteur au lieu d'être ancré bas (REF §3 #4) ; ~60 % d'écran nu (→ GAP-06).
- Fichier : `KioskPaymentComponent.vue` (NON frozen). Effort S.

### GAP-09 — Hiérarchie des numéros de commande (show) → ◐ PARTIEL
- **Fermé** : l'espacement — « #1206264526 · N°A0013 » (espaces insécables, heal H3 `09e0a09ac`),
  prouvé `F2-08-caisse-show.png` (plus de « 2· N° » collé).
- **Reste** : la hiérarchie inversée — l'ID interne 10 chiffres est toujours le titre (gros, orange),
  le N° métier A0013 (celui que le client présente) toujours en petit. Proposition R1 inchangée :
  titre = « Commande N°A0013 » + badge canal, ID interne en métadonnée copiable. Effort S, non frozen.

### GAP-11 — Terminologie instable → ◐ PARTIEL
- **Fermé** : copy borne « espèces uniquement » → « Réglez à la caisse — espèces, carte ou ticket
  restaurant. » (`F2-03c`, heal H2 `3538e1a04`) — aligne la borne sur l'encaissement unifié owner.
- **Reste** : « Imprimer la facture » (show, `F2-08`) vs « ✓ Confirmer & Imprimer ticket » (modal, `F2-05`)
  — ambiguïté fiscale ticket≠facture intacte ; walk-in toujours pluriel (« Passager » `F2-08`,
  « Client passage » POS, « Client borne » historique) ; grammaire badges état/verbe non normée.
  Glossaire UI court dans POLICY §4 + sweep i18n. Effort S-M.

---

## OUVERTS (8)

### GAP-05 — Iconographie EMOJI transverse → ✗ OUVERT (inchangé, ~19 occurrences / 10 fichiers)
- **Evidence R2** : 💳 « Encaisser » ×2 (panneau + drawer, `F2-04`/`F2-04b`) ; 💶 pastille cash-instruction
  (`F2-03c`) ; 🛒 panier vide (`F2-03d`) ; 🥡 bandeau À emporter (`F2-03`) ; 💸 « Rembourser » (`F2-08`) ;
  🖥/📋 header POS + 🍔 « PRÊT À LIVRER » (`F2-04`) ; tuiles modes du modal (`F2-05`) ; 🌮🍔🍟 idle (`F2-01`).
- Aucun heal ne visait ce sweep. Rappel adversaire R1 : 1 occurrence FROZEN (`PosV5TrancheRow.vue:133`)
  → part gate ; tout le reste éditable. Effort M mécanique. **Le marqueur « générique vs produit » n°1.**

### GAP-06 — Composition portrait borne 40-70 % vide → ✗ OUVERT
- **Evidence R2** : `F2-03b` paiement ~60 % nu (cluster flottant), `F2-03` panier zone morte centrale
  ~45 % (2 articles en haut, totaux en bas), `F2-01` idle sans héro. Upsell/wizard non re-capturés ce
  round (frozen — part gate). Aucun heal de composition n'était au lot. Effort L, steps/cart/payment non frozen.

### GAP-08 — « Ouvrir la caisse » : double champ pour le même montant → ✗ OUVERT
- **Evidence R2** : `F2-04a-caisse-session-overlay.png` — grand afficheur « 50,00 € » + chips +5/+10/+20/+50
  ET second input brut « 50 » en dessous, sans libellé différenciant (v-model commun re-confirmé R1
  `PosCashDrawerSessionDialog.vue:56/:89`). Premier geste de la journée du gérant. Effort S.

### GAP-10 — Title Case anglophone FR systémique → ✗ OUVERT (1 occurrence fermée sur ~30)
- **Evidence R2** : `F2-08` sidebar « Tableau De Bord », « Commandes Caisse », « Vue Caisse Unifiée »,
  « Caisse Livreur », « Rapport Des Ventes », breadcrumb « Tableau De Bord / Commandes Caisse / Voir »,
  badges « En Préparation » ; `F2-06` breadcrumb idem. Seul « Imprimer la facture » a été corrigé
  (classe `capitalize` retirée, H3 `00cc81a16` — prouve que le pattern de fix est trivial).
  Sweep i18n + règle « sentence case FR » à écrire dans POLICY §4 (trou normatif ADV-F-P2-15). Effort M.

### GAP-12 — Fuites d'état interne → ✗ OUVERT (5 relevés re-confirmés)
- **Evidence R2** : « Filiale #1 » (`F2-04` header) ; « Référence interne: 2 » + « Instruction: TIRAMISU »
  (instruction-miroir du nom produit, `F2-08`) ; « Ouvre le tiroir (simulation) » (`F2-05` tuile Espèces) ;
  « …saisir le comptage physique du tiroir **(à venir)** » (`F2-09` bandeau réconciliation). Effort S.

### GAP-13 — Vue Caisse Unifiée : périmètres temporels juxtaposés → ✗ OUVERT structurellement, MITIGÉ par les data
- **Evidence R2** : `F2-09` — KPI « GRAND TOTAL 27,94 € / 6 tx » au-dessus de « Réconciliation caisse —
  session ouverte à 13:20, espèces (session en cours) 0,00 €, attendues 50,00 € ». Toujours AUCUN
  sous-titre de période sur les cartes KPI ni lien visuel KPI↔session. MAIS le scénario de confiance
  s'est amélioré par ailleurs : le ledger inclut désormais les ventes POS directes (heal H1 `b824dd933` —
  CAISSE 22,94 €/5 tx peuplé) et session/filtre regardent le même jour ici. Le jour où une session
  chevauche minuit, la confusion R1 revient telle quelle. Effort M.

### GAP-14 — Cibles tactiles secondaires borne < 48 px → ✗ OUVERT (re-mesuré au DOM)
- **Evidence R2** : `F2-03` probe — crayon « Modifier cet article » **34×34 px**, poubelle « Retirer »
  **36×36 px** ; libellés rail catégories toujours ~8-10 px (`F2-02`). EAA/EN 301 549 en vigueur.
  À noter : le pavé du modal caisse respecte désormais le plancher 48 px (heal P0) — le standard existe,
  il manque côté borne. Effort M.

### GAP-15 — Micro-typographie des données → ✗ OUVERT
- **Evidence R2** : troncature mi-mot « Choix de viande + crud… » (`F2-02`) ; eyebrow « NOS Sandwich
  Cayenne » (accord) ; placeholder ALL-CAPS « SAISIR UN CODE PROMO... » (`F2-03`) ; rail POS « Sandwich… »,
  « Bols Gourm… » (`F2-04`). La colonne Montant de `F2-09` rend alignée à droite (bien) ; l'historique
  admin (f12 R1, colonne MONTANT à gauche) n'a pas été re-capturé — à re-juger au sweep. Effort S.

---

## OBSERVATIONS NOUVELLES R2 (non comptées comme gaps design)

1. **Affordance du CTA « ✓ Confirmer & Imprimer ticket »** : RÉSOLU par l'évidence —
   `F2-05b-keypad-bottomrow-1366-avant.png` montre le CTA plein orange quand MONTANT REÇU est valide
   (pré-rempli au total à l'ouverture) et pâle seulement une fois vidé (touche C). L'habillage suit
   l'état : comportement correct, pas un finding.
2. **Console : PAGEERROR `AxiosError 401` non catchée à l'expiration de session admin** (take-2,
   `F2-08-caisse-show.console.txt` 11:24:53Z) : le logout auto fonctionne (heal pos-app 401-auth), mais une
   promesse non gérée fuit en pageerror au moment du basculement. Hygiène console P3, voie non-frozen.
3. **File d'encaissement : 68 en attente / 286,90 €** (`F2-06`) — les zombies 10/06 sont maintenant
   badgés+triés en bas (heal OK) mais la purge des PENDING_COUNTER expirés reste une décision owner
   (documentée H3, hors mandat). Le chiffre de tête de page reste « gonflé » tant que la purge n'est pas tranchée.

## Note de pondération pour la planification UI/UX finale
Les 8 gaps ouverts sont TOUS des sweeps transverses (emoji, casse FR, terminologie, fuites, micro-typo,
cibles, composition, double-champ) — aucun n'est un défaut fonctionnel. Ordre de valeur suggéré inchangé
vs R1 : GAP-05 (marqueur produit n°1, M mécanique) → GAP-14 (légal EAA, M) → GAP-10+GAP-11 (sweep i18n
commun, M) → GAP-12 (S) → GAP-08 (S) → GAP-03-reste+GAP-09-reste (S) → GAP-15 (S) → GAP-06 (L) →
GAP-13 (M) → GAP-01-résiduel attract-screen (M, le plus « vendeur » côté client).
+ combler les 4 trous normatifs POLICY (ADV-F-P2-15) pour que les sweeps aient une règle écrite à citer.
