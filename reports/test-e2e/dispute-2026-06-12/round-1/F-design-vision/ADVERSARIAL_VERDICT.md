# ADVERSARIAL VERDICT — Vague F-design-vision — Round 1 (dispute 2026-06-12)

> Superviseur adversarial. Je ne corrige rien. « Tested by another spec » n'est PAS un PASS.
> 8 PNG quartet lus en multimodal + 4 PNG d'hier re-lus (f04/f07/f10 + crop F-02) + 3 scripts
> live `tests/e2e/_d1red-F-design-vision-{1,2,3}.mjs` (4 captures RED-*). Tous les file:line
> ci-dessous re-greppés ce jour.

## VERDICT GLOBAL : **RED** — 1 P0 · 5 P1 · 14 P2 · 4 P3
La « convergence 0 P0/0 P1/0 P2 » d'hier ne tient pas : un P0 d'interception keypad a été
**INTRODUIT PAR UN HEAL W2 (G6)** et était VISIBLE dans la capture f10 du cycle 2 sans être
flaggé ; l'écran paiement borne est un cul-de-sac sans retour (prouvé live au DOM) ; et la
moitié « DOM » du quartet de la vague F est de l'evidence vide (8/8 tronqués avant `<body>`).

---

## P0

### ADV-F-P0-1 — Modal encaissement : le footer sticky (heal W2-G6 d'hier) INTERCEPTE 6 touches du pavé — taper « 9 » déclenche « Confirmer & Imprimer ticket »
- **Surface** : caisse 1440×900, modal « Encaisser la commande borne » (PosCounterCollectModal).
- **Preuve live (hit-test elementFromPoint, script `_d1red-F-design-vision-3.mjs`)** :
  touches `7, 8, 00, 0, ,` → interceptées par `.cc-modal-footer` (tap = MORT, silencieux) ;
  touche `9` → intercepte **le bouton « ✓ Confirmer & Imprimer ticket »** : le geste « je tape 9 »
  peut DÉCLENCHER l'encaissement + impression ticket (événement fiscal NF525 irréversible).
  `blocked: ["7←cc-modal-footer","8←cc-modal-footer","9←✓ Confirmer & Imprimer ticket","00←…","0←…",",←…"]`,
  scroller `.cc-modal` scrollHeight 940 / clientHeight 828, aucun indicateur de scroll visible.
  Même un `locator.click()` Playwright sur « 7 » échoue (timeout actionability).
- **Root cause code (re-greppé)** : `resources/js/components/admin/pos/PosCounterCollectModal.vue:839-853` —
  commentaire `[UIUX-W2 G6 2026-06-11]` : le CTA passait sous le fold → fix = `position: sticky;
  bottom: 0; z-index: 1; background: var(--pos-v5-surface,#fff)` OPAQUE dans le scroll du modal.
  Le heal d'hier a échangé « CTA caché » contre « pavé numérique recouvert ». Markup footer `:177`.
- **Impact métier** : saisir un montant reçu contenant 7/8/9/0 (ex. « 10,00 », « 20,00 » — les
  montants espèces les plus courants) est impossible au tap centre ; pire, viser 9 peut encaisser.
- **Captures** : `RED-F-encaisse-modal-live.png`, `RED-F-encaisse-keypad-hittest.png` (fraîches) ;
  le défaut est DÉJÀ VISIBLE dans `c2/flow/f10-encaisse-modal.png` du cycle de convergence d'hier.
- **Fichier non-frozen** → réparable sans gate (footer non-opaque au-dessus du pad, ou pad au-dessus du footer, ou modal sans scroll à 900px).

---

## P1

### ADV-F-P1-1 — Écran « Paiement à la caisse » borne = CUL-DE-SAC : aucun retour/annulation (prouvé live)
- **Preuve live** (`_d1red-F-design-vision-1.mjs`, flux réel idle→catalogue→panier→checkout→payment, panier 1,50 €) :
  `backBtnPresent:false, backBtnVisible:false, visibleEscapeButtons:[]` — le SEUL élément interactif
  de l'écran est « Confirmer ma commande ». Le client qui veut modifier son panier est piégé
  (seule sortie : timer d'inactivité).
- **Root cause code** : `KioskPaymentComponent.vue:84-86` — le header avec bouton retour est gaté
  `v-if="!paymentRouteAllToCounter"` ; le bloc Plan-B (`:36-81`) n'a AUCUN contrôle d'échappement.
  Plan B actif en V1 (config kiosk.php:161/257, mandat owner) → le retour n'existe jamais en prod.
- **Capture** : `RED-F-payment-live.png` (reproduit f07 1:1 avec MON panier).
- NON-frozen (KioskPaymentComponent hors liste §7).

### ADV-F-P1-2 — Grille client « Sandwich Cayenne » : les 3 SKU techniques d'upsell OUVRENT le rayon (tuiles cassées + badge « Nouveau » + bordure orange inexpliquée)
- `F-02-borne-catalogue-sandwichs.png` + crop `/tmp/f02-menu-price-crop.png` : positions 1-2-3 =
  « BOISSON SEULE » / « FRITES SEULES » (tuiles blanches image cassée) / « MENU (FRITES + BOISSON) »
  (bordure orange unique), tous trois badgés « Nouveau », desc « Upsell item » EN — AVANT les vrais
  sandwichs. Première impression du rayon = 2 tuiles cassées anglophones.
- Le gate DATA connu couvre images+descriptions ; le **placement en tête + badge « Nouveau » +
  l'état featured disparate** = dimension merchandising NON gatée, jamais capturée hier
  (kiosk-products-sandwich.png d'hier = redirect idle, prouvé manifest+MD5).
- Remédiation mixte DATA (sort_order/flags/catégorie) + UI fallback image (KioskCategoriesComponent non-frozen).

### ADV-F-P1-3 — Idle borne à dominante SOMBRE : contradiction du mandat owner « kiosk light-mode 100% »
- `F-01-borne-idle.png` : fond brun/noir dominant + ellipse floue centrale (effet héro manquant),
  CTA rond orange icône-seule sans label, micro-texte gris.
- Règle écrite re-greppée : `docs/design/DESIGN_SYSTEM_POLICY_2026-06-10.md:10` « Kiosk = light mode
  100% (dark désactivé, mandat owner) » + CLAUDE.md §3bis. Premier écran client = celui qui contredit
  le mandat. (Anti-drift §12 : contradiction règle stable → surfacée, arbitrage owner sur le design idle.)

### ADV-F-P1-4 — Evidence quartet vague F : 8/8 DOM INUTILISABLES — claims « DOM = rendu ✓ » non prouvés par artefact
- Tous les `.dom.html` = 81 923 octets exactement ; la coupe 80KB (`_d1-F-lib.mjs` `dom.slice(0, 80*1024)`)
  tombe dans le CSS du `<head>` : **0 `<body>` partout**. MD5 : 3 borne identiques `20faba2a…`,
  5 caisse identiques `c4aad7e4…`. `grep 'Boisson Seule' *.dom.html` → 0.
- Conséquences : (1) le claim WAVE_REPORT « Intégrité F-02 … (DOM = rendu ✓) » est invérifiable
  depuis l'artefact (le chiffre est bon — vérifié par MON crop — la méthode revendiquée est fausse) ;
  (2) le check protocole catégorie 1 (i18n regex sur DOM) n'a JAMAIS été exécuté par le GStack.
  Je l'ai re-exécuté LIVE sur idle/catalogue/panier/paiement : **CLEAN** (aucune clé brute).
- Ironie : le GStack dénonce les MD5 dupliqués des c2 d'hier en livrant un quartet 8/8 dupliqué.

### ADV-F-P1-5 — Couverture de la convergence d'hier SURESTIMÉE (vérifié MD5 + manifest)
- Re-vérifié moi-même : `kiosk-payment = kiosk-upsell = kiosk-loyalty = kiosk-cart-empty`
  → 4 fichiers MD5 identique `b3ac7571…` (= panier vide) ; manifest : categories/products-sandwich/
  products-tacos/confirmation/admin/login → tous final=/kiosk/idle. **10/25 statiques borne = redirects.**
- L'écran CONFIRMATION : `KioskConfirmationComponent.vue` existe mais n'est routé QUE depuis
  `KioskWaitingComponent.vue:394` (flux TPE) ; sous Plan B tout part sur `kiosk.cash-instruction`
  (`KioskPaymentComponent.vue:540`) → **inatteignable par config V1** (nuance vs GStack : ce n'est
  pas un trou de test d'un état atteignable — mais le nommer « capturé » hier était faux quand même).
- Le verdict « double cycle identique, 0 nouveau finding » reposait donc sur une surface partielle :
  F-02 (grille), F-04a (overlay session), F-05 (modal session) = 3 états à défauts JAMAIS vus hier.

---

## P2 (disclose — pas de loop)

1. **ADV-F-P2-1 Couleur d'action non normée** : « Encaisser » VERT (`PosComponent.vue:5068-5073`
   `.kiosk-cash-collect-btn` `--pos-v5-success`, markup `:1279`) vs ORANGE (`:367`
   `.pos-shortcuts__cta--cash`) — même action `openCounterCollect`, 2 couleurs sur le même écran
   (`F-05b`). + micro-défaut attrapé par moi : `:1279` libellé **hardcodé `'✓ Encaisser'`** quand
   le jumeau passe par `$t('label.pos_shortcut_cash_cta')` — incohérence i18n.
2. **ADV-F-P2-2 « Clôturer la caisse » = primaire visuel** du modal Session active consulté en
   routine (`F-05`) — l'action destructive (flux Z fiscal) est le seul bouton plein.
3. **ADV-F-P2-3 Double champ fond de caisse** (`F-04a`) — vérifié code : display formaté
   (`PosCashDrawerSessionDialog.vue:56`) + input brut (`:81-90`) liés au MÊME `v-model openingAmount`
   → **pas de risque de désynchronisation** (je requalifie : affordance dupliquée, pas intégrité).
   Même pattern sur le close form (`:171` + `:195-203`).
4. **ADV-F-P2-4 Emoji-comme-icônes** : grep complet = **~19 occurrences / 10 fichiers** (plus que
   les 8 du GStack) : KioskCartComponent:69 🛒 / :116 🥡, KioskLoginComponent:5 🖥️,
   KioskPaymentComponent:259 🎫, KioskCashInstructionComponent:6 💶, PosOrdersTrackerComponent:493-494/987-989,
   PosComponent:129/149/161/348/648/1164, PosRefundModal:60 💸, PosCounterCollectModal:267-269,
   + ⭐ header loyalty (f04). **CORRECTION du GAP-05 GStack (« Frozen ? NON … aucun relevé ») :
   `PosV5TrancheRow.vue:133` (📱) est FROZEN §7** → cette occurrence-là = observation/gate, pas d'édition.
5. **ADV-F-P2-5 Composition portrait borne 40-70% vide** sur 5+ états (f02/f04/f06/f07/F-03) —
   confirmé live : sur RED-F-payment-live, le contenu utile occupe y964-1220 sur 1920 (13%).
6. **ADV-F-P2-6 Paiement : bloc info déguisé en CTA + texte CTA aligné à gauche** — root-causé par moi :
   `.kiosk-pay-counter-total` (`KioskPaymentComponent.vue:1110-1122`) = rect orange dégradé 140px
   avec `--kiosk-shadow-cta` (plus GROS que le bouton 92px) ; `.kiosk-btn-confirm`
   `justify-content: space-between` (`:1493-1495`) conçu pour 2 enfants (label+prix) utilisé avec
   1 seul `<span>` (`:78`) → texte épinglé à gauche. Mesures live : span l232 dans bouton l200-880.
7. **ADV-F-P2-7 Hiérarchie numéros inversée** : ID interne 10 chiffres en titre, A0005 métier en petit
   + « 2· N° » point médian collé (`F-06`, f11).
8. **ADV-F-P2-8 Title Case FR systémique** hors gate PaymentComponent : « Tableau De Bord »,
   « Vue Caisse Unifiée », « Imprimer La Facture », « En Préparation », login « Bon Retour /
   Mot De Passe / Se Souvenir De Moi » (`F-06`, f11, f12, cash-overview, login run-2).
9. **ADV-F-P2-9 Terminologie instable** : « Imprimer La Facture » vs « Confirmer & Imprimer ticket »
   (sensible NF525 : ticket ≠ facture) ; **3 libellés walk-in coexistent** : « Passager » (F-06) /
   « Client passage » (f10/ticket) / « Client borne » (f11) — voir aussi dispute D-3 ci-dessous.
10. **ADV-F-P2-10 Fuites d'état interne** : « (à venir) » (cash-overview), « Ouvre le tiroir
    (simulation) » (f10 + RED-F-encaisse-modal-live FRAIS — toujours là), « Filiale #1 » (F-04/F-04a),
    « Référence interne: 1 » + « Instruction: COCA-COLA 33CL » (F-06).
11. **ADV-F-P2-11 Cash-overview double période** : GRAND TOTAL 0,00 € (filtre jour) vs tiroir attendu
    136,00 € (session de la veille) sans hint de périmètre (c2/cash-overview ; 50+86=136 recalculé ✓).
12. **ADV-F-P2-12 Loyalty : 2 messages d'erreur simultanés divergents** (toast « patientez 27s » +
    inline « quelques secondes ») + 2 CTA pleins concurrents orange/jaune (f04 re-lu ce jour).
13. **ADV-F-P2-13 Troncatures/grammaire** : rail catégories POS ~10 chars (« Bols Gourm… ») ;
    « + crud... » coupe mi-mot ; « NOS / Sandwich Cayenne » accord cassé (F-02, F-04).
14. **ADV-F-P2-14 Cibles tactiles secondaires <48px** : poubelle ~36px, crayon ~32px coin de carte,
    libellés rail ~8-10px illisibles debout (F-03, F-02, f03).

## P3
1. ADV-F-P3-1 — Colonne MONTANT historique alignée à gauche (f12).
2. ADV-F-P3-2 — Placeholder ALL-CAPS « SAISIR UN CODE PROMO... » + bouton « Appliquer » disabled
   orange-pâle adjacent au bandeau fidélité jaune-pâle (deux pâleurs côte à côte, F-03).
3. ADV-F-P3-3 — Show : « 4,50 € » affiché sous le nom produit (slot prix-unitaire) pour une ligne
   qté 3 — lisible comme PU sans libellé « ligne » (F-06 ; total juste par ailleurs).
4. ADV-F-P3-4 — Env harnais : ws://127.0.0.1:6001 down → warnings console répétés + bouton
   « Actualiser » manuel visible (SYNC-WS-01 connu) — à re-tester env nominal, pas un défaut produit prouvé.

## Intégrité numérique (recalculée par moi, indépendamment)
- F-03 : 1,50+1,50=3,00=Total=CTA ✓ · F-04 : pill 56 = panneau (56) = 4+52 ✓ · F-05 : 50+0=50 ✓ ·
  F-05b : drawer A0011-14 = panneau ✓ · F-06 : 3×1,50=4,50=Sous-Total=Total ✓ ·
  c2 : 50+86=136 ✓ · flux hier : 39,90 € constant f07=f08=f11 ✓ · live : payment 1,50 € = panier ✓.
- Crop Menu = **3,00 €** : la lecture GStack était JUSTE, ma lecture « 5,00 » réfutée par mon propre crop.
- **0 incohérence numérique** — je confirme le GStack sur ce point.

## Gates connus revus SANS re-compte (conformité brief)
Orange #F4501E petits textes (omniprésent) · prix-étapes wizard (f02 : footer = total panier, pas un
prix d'option — conforme policy §5, je CONFIRME la lecture GStack) · « VAT (10%) » DATA · images/desc
« Upsell item » DATA (re-cité uniquement pour la dimension placement P1-2) · 401 one-shot boot
(F-01/F-02 console, exactement 1) · tutoiement cash-overview · dates tirets · « Accepter » infinitif ·
« : » orphelin tracker · seed SUP-LOY-1 · deep-link cash-instruction « #— » · spam log wizard ·
aria-pressed upsell · Title Case PaymentComponent (l'îlot frozen seulement — le systémique hors-îlot = P2-8).

---

## DISPUTES du FINAL_REPORT d'hier (uiux-caisse-borne-2026-06-11)

| # | Claim | Verdict | Preuve |
|---|---|---|---|
| D-1 | « ✅ CONVERGED — double cycle 0 P0 / 0 P1 / 0 P2, aucun nouveau finding » | **REFUTED** | ADV-F-P0-1 (interception keypad) introduit par le heal W2-G6 et VISIBLE dans la capture du cycle 2 `c2/flow/f10` sans être flaggé ; ADV-F-P1-1 (cul-de-sac paiement) visible dans f07 du même cycle. Les 2 écrans étaient DANS les captures de convergence. |
| D-2 | « W2 heal : 13/13 fixes CONFIRMED par l'adversaire » (dont G6 CTA encaissement sticky <900px) | **REFUTED** (pour G6) | Le fix G6 est réel (CTA visible) mais a créé une régression pire : footer sticky opaque z-index 1 → 6/12 touches keypad mortes/interceptées (`PosCounterCollectModal.vue:843-853`, hit-test live). La « confirmation » n'a testé que la visibilité du CTA, pas les effets de bord. |
| D-3 | « W2 heal : libellé walk-in unifié “Client borne” » | **WEAKENED** | F-06 FRAIS affiche « Passager » (Informations Client) et f10 « Client passage » — 3 libellés coexistent encore post-heal. |
| D-4 | « ~200 screenshots analysés » / couverture convergence | **WEAKENED** | 10/25 statiques c2 borne = redirects (manifest), 4 fichiers byte-identiques (MD5 `b3ac7571…`) ; grilles produits et overlay session jamais vus. Nuance : l'écran confirmation est INATTEIGNABLE sous Plan B (`KioskWaitingComponent.vue:394` seule route, `KioskPaymentComponent.vue:540` route Plan B) — trou de couverture honnête mais état non-atteignable par config. |
| D-5 | « formats € FR partout (appService Intl fr-FR) » | **UPHELD** | Tous les états frais + live : « X,XX € » partout (F-02/03/04/05b/06, RED-F-payment-live). 0 format `€2.00` revu. |
| D-6 | « B-1/A-5 prouvés résolus live » (statut paiement borne show ; libellé “session en cours”) | **UPHELD** | F-06 frais : badge « Payé » rendu sur show borne ; c2/cash-overview porte « (session en cours) ». Rien ne les contredit dans ma revue. |
| D-7 | P3 résiduels « identiques ×2 cycles » (401 boot, dates tirets, etc.) | **UPHELD** | 401 one-shot revu F-01/F-02 (exactement 1 par boot) ; les autres P3 listés réapparaissent à l'identique. |

## DISPUTES du WAVE_REPORT GStack (cette vague)
- Prix Menu 3,00 € : **UPHELD** (mon crop) — et ma propre lecture 5,00 auto-réfutée.
- « DOM = rendu ✓ » : **REFUTED comme méthode** (artefacts sans body — P1-4) ; chiffres exacts par ailleurs.
- GAP-05 « aucun emoji en frozen » : **CORRIGÉ** — `PosV5TrancheRow.vue:133` est frozen.
- f10 « modal déborde … à confirmer frais » : ils ne l'ont JAMAIS confirmé frais et la vague est marquée
  TERMINÉ — je l'ai fait : le modal ne déborde PAS du viewport, le vrai défaut est l'interception sticky (P0-1).
- Anomalies 2-8 du résumé GStack : toutes UPHELD au visuel (F-02/F-05b/F-05/F-04a/F-03/F-01, f07).

## Artefacts adversariaux produits
- `RED-F-payment-live.png` (+ quartet) — paiement borne frais, flux complet réel.
- `RED-F-encaisse-modal-live.png`, `RED-F-encaisse-keypad-hittest.png` (+ quartets) — modal encaissement frais + hit-test.
- Scripts : `tests/e2e/_d1red-F-design-vision-{1,2,3}.mjs` (jetables).
- Crop : `/tmp/f02-menu-price-crop.png`.
- i18n live scan : idle/categories/cart/payment = CLEAN (check protocole cat-1 enfin exécuté).

## Statut : TERMINÉ 2026-06-12
