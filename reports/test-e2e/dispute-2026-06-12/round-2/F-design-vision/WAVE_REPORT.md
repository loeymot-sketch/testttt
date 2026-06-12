# VAGUE F ROUND 2 — DESIGN re-jugement post-heals (dispute 2026-06-12)

> GStack main team, round 2. App :8768, DB foodking_e2e (reset + seeder upsell appliqué),
> bundles rebuildés 12/06 13:07 (vérifié `stat public/js/app.js` = Jun 12 13:07:15 — POSTÉRIEUR aux 32 heals).
> Scripts : `tests/e2e/_d2-F-lib.mjs` + `_d2-F-borne.mjs` + `_d2-F-caisse.mjs`.
> Quartet par état : PNG + **DOM `#app.outerHTML`** (correctif ADV-F-P1-4 round-1 — plus jamais documentElement)
> + console + network(≥400). Chaque PNG lu et analysé.

## Incident de capture (process, résolu)
Un re-run accidentel du script borne (run 2) a brûlé le bucket d'auth kiosk → 401 machine-login
sur ~80 s (`F2-02.network.txt` intermédiaire : 401 /api/login + /api/frontend/menu). Run 3 après
fenêtre de throttle = 100 % vert, quartets définitifs re-écrits. Leçon : jamais 2 boots kiosk
adossés ; espacer ≥90 s.

---

## ÉTATS COUVERTS — BORNE 1080×1920 (run 3, `/tmp/f2-borne-run3.txt`)

### F2-01 — /kiosk/idle — HEAL ADV-F-P1-3 (light mode) → ✅ CONFIRMÉ
- Probe computed : `.kiosk-idle-fallback` background = `linear-gradient(#FFF, #FFE8DD 55%, #F4501E)` ;
  overlay `background: none` ; `kiosk-idle--has-video` ABSENT ; titre encre `rgb(15,15,15)`.
- PNG : fond blanc→pêche→orange marque, AUCUNE dominante sombre, plus d'ellipse floue sombre.
  Logo Le Cayenne, « Bienvenue ! » encre, carte « À emporter » blanche.
- Code : `KioskIdleScreenComponent.vue:377` (gradient light) + `:14` binding has-video — bundle servi à jour.
- RESTE (design, voir GAP-01 V2) : compo encore « vide » (pas de photo produit héro), CTA rond
  icône-seule sans label, micro-texte gris « CHOISISSEZ UNE OPTION… », emojis 🌮🍔🍟 décoratifs flottants.

### F2-02 — grille « Sandwich Cayenne » — HEAL ADV-F-P1-2 (seeder SKU techniques) → ✅ CONFIRMÉ
- Probe : `skuTechniques=[]`, `upsellItemDesc=false`, 0 image cassée. PNG : la grille ouvre sur
  SANDWICH CAYENNE 7,00 € puis BIG CAYENNE 9,50 € — vraies photos, badge « Personnaliser ».
  Plus aucune tuile blanche « Frites Seules »/« Boisson Seule »/« Menu (Frites + Boisson) », plus de « Upsell item » EN.
- Sidebar : la catégorie interne `technique-interne-upsell` n'apparaît PAS (channels=admin).
- RESTE : troncature mi-mot « Choix de viande + crud… » (GAP-15), eyebrow « NOS Sandwich Cayenne »
  (accord), libellés rail catégories minuscules (GAP-14).

### F2-03 — panier (2 articles) — re-jugement
- Total 3,00 €, CTA « Valider ma commande [3,00 €] » pattern chip-prix conservé (baseline positive R1).
- GAP-14 TOUJOURS OUVERT (mesuré DOM) : crayon « Modifier cet article » **34×34px**, poubelle
  « Retirer » **36×36px** (<48px plancher). 🥡 bandeau À emporter + placeholder ALL-CAPS
  « SAISIR UN CODE PROMO... » inchangés.

### F2-03b — /kiosk/payment Plan B — HEAL ADV-F-P1-1 (échappatoire) → ✅ CONFIRMÉ, GAP-03 partiel
- `[data-testid="kiosk-payment-counter-back"]` PRÉSENT : « Retour au panier », 640×64px, sous le CTA. Le cul-de-sac est fermé.
- RESTE (PNG) : bloc info « TOTAL À RÉGLER : 3,00 € » = toujours un rectangle orange plein visuellement
  identique au CTA juste en dessous (info déguisée en bouton) ; libellé du CTA visuellement calé à gauche
  (computed text-align=center mais rendu flex non centré) ; cluster flottant à ~55 % de hauteur, non ancré bas ; ~60 % d'écran vide.

### F2-03c — /kiosk/cash-instruction — HEALS C-ADV-06 + cta_back_home → ✅ CONFIRMÉS
- Copy : « Réglez à la caisse — espèces, carte ou ticket restaurant. » — plus de « uniquement » (encaissement unifié).
- CTA « RETOUR À L'ACCUEIL » pleine largeur ancré bas + countdown « Retour à l'accueil dans 41 s ». N° **A0007** énorme. Commande créée : PENDING_COUNTER (sert aux états caisse infra).
- RESTE : icône = emoji 💶 dans la pastille orange (GAP-05).

### F2-03d — panier post-commande — HEAL D-003 (reset) → ✅ CONFIRMÉ
- Store `kioskCart.lists = 0` immédiatement après cash-instruction ; /kiosk/cart = « Votre panier est vide ».
  Plus de panier fantôme → plus de re-validation 409. RESTE : 🛒 emoji supermarché (GAP-05).

---

## ÉTATS COUVERTS — CAISSE 1440×900 + 1366×768 (bm.t2admin, `/tmp/f2-caisse-run1/2.txt` + take 3)

### F2-04a — « Ouvrir la caisse » (overlay session, take 1)
- Capturé AVANT ouverture : afficheur « 50,00 € » + chips +5/+10/+20/+50/Effacer **ET** second input
  brut « 50 » — GAP-08 toujours ouvert. Session ouverte fond 50,00 €.
- Incident process take 1 : après ouverture, l'overlay bascule en vue « Session active » qui couvre
  l'écran → le clic Encaisser a timeout. Take 2 ferme l'overlay via `cash-session-close` d'abord.

### F2-05 / F2-05b — ⭐ P0 ADV-F-P0-1 : pavé du modal « Encaisser la commande borne » → ✅ HEAL CONFIRMÉ AUX 2 RÉSOLUTIONS
Modal ouvert sur **A0002** (ma commande borne, total 3,00 € = total borne — cohérence cross-surface).
- **Structure (probe)** : 1440×900 → `.cc-modal-body` scrollHeight 735 = clientHeight 735 (**AUCUN scroll**),
  footer `position: static` (plus de sticky), footer + CTA visibles. 1366×768 → 663/663, idem.
- **Hit-test elementFromPoint** : 13/13 touches atteignables, **`blocked: []`** aux DEUX résolutions
  (R1 : 6-7 touches interceptées par le footer/CTA). Touches h=48px (plancher tactile).
- **Intégrité chiffre par chiffre (clics Playwright réels, séquence C,1..9,0,00,C)** :
  - 1440×900 : C→"" · 1→"1" · 2→"12" · 3→"123" · 4→"1234" · 5→"12345" · 6→"123456" · 7→"1234567" ·
    8→"12345678" · 9→"123456789" · 0→"1234567890" · 00→"123456789000" · C→"". **13/13 OK.**
  - 1366×768 : séquence identique, **13/13 OK**.
  - **0 POST `counter-collect/*/confirm`** émis pendant les 26 frappes (listener réseau dédié), modal
    jamais fermé, « Confirmer » jamais déclenché. Taper « 9 » ne tombe plus sur l'événement fiscal.
- Captures avant/après de la rangée à risque : `F2-05-keypad-bottomrow-1440-{avant,apres}.png` +
  `F2-05b-keypad-bottomrow-1366-{avant,apres}.png` — rangée 00/0/,/C dégagée, gap net avec le footer.
- PNG plein écran : pavé complet + footer fixes, day-badge absent (A0002 = aujourd'hui, contrat « zéro badge jour J » respecté).

### F2-04 / F2-04b — POS + drawer borne — HEALS ADV-F-P2-1 + B-R1-04 → ✅ CONFIRMÉS
- Couleur : panneau `.pos-shortcuts__cta--cash` **rgb(244,80,30)** = drawer `.kiosk-cash-collect-btn`
  **rgb(244,80,30)** (source `PosComponent.vue:5133-5144` + `:1284`, bundle servi) — plus de VERT vs ORANGE.
- Badges date drawer : « 10/06 » ×10 sur les zombies, AUCUN badge sur les commandes du jour ;
  tri jour-récent-d'abord (A0002/A0004/A0005/A0006/A0007 du 12/06 en tête).

### F2-06 — /admin/encaissement — HEAL B-R1-04 → ✅ CONFIRMÉ
- `enc-day-badge-43xx` = « 10/06 » présents sur les zombies ; commandes du jour (A0002→A0007, sans badge)
  AVANT le bloc 10/06. Les doublons inter-jours (A0009, A0011 ×2) sont maintenant désambiguïsés par badge.
- Reste owner : purge des PENDING_COUNTER expirés (file affiche 68 en attente / 286,90 € — gonflée par les zombies, décision hors mandat documentée H3).

### F2-07 — modal « Session active » — HEAL ADV-F-P2-2 → ✅ CONFIRMÉ
- « Clôturer la caisse » = **outline rouge** (bg transparent, border `1px solid rgb(194,30,47)`) ;
  « Voir les mouvements » blanc bordé au-dessus. Le destructif n'est plus le primaire visuel.

### F2-08 — show — HEALS 09e0a09ac + 00cc81a16 → ✅ CONFIRMÉS (partiels par design)
- « #1206264526 · N°A0013 » : séparateur espacé (plus de « 2· N° » collé). « Imprimer la facture »
  en casse FR naturelle. RESTENT : hiérarchie ID-interne-en-titre (GAP-09), « Référence interne: 2 »,
  « Instruction: TIRAMISU » (GAP-12), 💸 (GAP-05), sidebar Title Case (GAP-10).
- Incident take 2 : token admin révoqué à 13:24:53 (401 auth réel → logout auto correct, mais
  **PAGEERROR AxiosError non catchée** au basculement — hygiène console P3, observation nouvelle).
  F2-08/F2-09 re-capturés take 3 après re-login.

### F2-09 — /admin/cash-overview
- GRAND TOTAL **27,94 € / 6 tx**, CAISSE 22,94 €/5 tx, BORNE 5,00 €/1 tx — le ledger voit désormais les
  ventes POS directes (effet heal H1 `b824dd933` visible en UI). Réconciliation : session 13:20, fond 50,00 €,
  attendues 50,00 € — cohérent. RESTENT : « (à venir) » (GAP-12), aucun sous-titre de période sur les KPI (GAP-13).

---

## HEALS DE MON PÉRIMÈTRE — VERDICTS FINAUX

| Heal (R1) | Verdict R2 | Evidence |
|---|---|---|
| **ADV-F-P0-1 pavé modal encaissement (H3 `34d1e0769`+`bbb79630d`)** | ✅ **CONFIRMÉ 2 résolutions** | F2-05/F2-05b : hit-test blocked=[], 26 frappes intègres, 0 POST confirm, body sans scroll |
| ADV-F-P1-3 idle light (H2 `dcf675617`) | ✅ CONFIRMÉ | F2-01 gradient light computed + PNG |
| ADV-F-P1-2 SKU upsell hors grille (H1 `bbeecd437` + seeder) | ✅ CONFIRMÉ | F2-02 skuTechniques=[] + PNG grille propre |
| ADV-F-P1-1 / D-003 échappatoire paiement + reset panier (H2 `43c5f2d76`) | ✅ CONFIRMÉ | F2-03b back-btn 640×64 + F2-03d panier vide |
| C-ADV-06 copy encaissement unifié (H2 `3538e1a04`) | ✅ CONFIRMÉ | F2-03c « espèces, carte ou ticket restaurant » |
| B-R1-04 badges date + tri file (H3 `d377da185`) | ✅ CONFIRMÉ | F2-04b/F2-06 badges 10/06, jour J en tête sans badge |
| ADV-F-P2-1 Encaisser vert→brand (H3 `9c93920c0`) | ✅ CONFIRMÉ | F2-04/F2-04b deux surfaces rgb(244,80,30) |
| ADV-F-P2-2 Clôturer outline (H3 `7fccbab3b`) | ✅ CONFIRMÉ | F2-07 outline rouge |
| ADV-F-P1-4 DOM quartet inutilisables | ✅ CORRIGÉ process | tous les .dom.html = #app rendu (1,8KB→123KB variés, plus de 26× byte-identiques) |

**Aucun heal de mon périmètre RÉFUTÉ.**

## Intégrité chiffre par chiffre (cross-état)
- Borne : 1,50 + 1,50 = 3,00 € panier = paiement = cash-instruction (A0007) — 0 écart.
- Caisse : modal A0002 « MONTANT TOTAL 3,00 € » = total borne de la même commande ; drawer A0002 3,00 €,
  A0004 6,50 €, A0006 3,80 € ; show A0013 Tiramisu 3,80 = sous-total 3,80 = total 3,80 ; cash-overview
  27,94 = 22,94 (caisse) + 5,00 (borne) + 0 (livreur). 0 incohérence de montant sur 13 états.

## Anomalies suspectées (nouvelles, non comptées gaps — à instrumenter)
1. ~~CTA « Confirmer & Imprimer ticket » pâle = mismatch affordance ?~~ **RÉSOLU par l'évidence** :
   `F2-05b-keypad-bottomrow-1366-avant.png` montre le CTA PLEIN orange quand MONTANT REÇU est valide
   (pré-rempli « 3,00 » à l'ouverture — d'où `disabled=false` au probe) et pâle seulement après C (vidé).
   L'habillage suit l'état — comportement correct, pas un finding.
2. PAGEERROR AxiosError 401 non catchée à l'expiration de session admin (logout auto OK par ailleurs) — P3 console.
3. File encaissement 68/286,90 € gonflée par zombies 10/06 (badgés, triés en bas) — purge = gate owner connu.

## Synthèse design → `DESIGN_GAP_ANALYSIS_V2.md`
4 gaps FERMÉS (GAP-01 light, GAP-02 SKU, GAP-04 couleur, GAP-07 destructif) · 3 PARTIELS (GAP-03, GAP-09,
GAP-11) · 8 OUVERTS (GAP-05 emoji, GAP-06 portrait, GAP-08 double champ, GAP-10 Title Case, GAP-12 fuites,
GAP-13 périodes, GAP-14 cibles<48px, GAP-15 micro-typo) · 0 nouveau gap design.
