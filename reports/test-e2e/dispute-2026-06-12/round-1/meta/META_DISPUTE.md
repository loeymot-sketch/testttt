# META-DISPUTE — Attaque claim-par-claim du rapport de convergence 2026-06-11
— Run dispute-2026-06-12 · MÉTA-DISPUTEUR (superviseur en chef) · rapport INCRÉMENTAL
— Cible : `reports/test-e2e/uiux-caisse-borne-2026-06-11/FINAL_REPORT.md` + `convergence/CYCLE{1,2}_FINDINGS.md` + `round1/wave-W{2,4}-RED-verdict.md`
— Recoupé avec : Round 1 dispute vagues A (caisse vente), B (caisse gestion), C (borne edge), D (borne robustesse), E (cross-surface), F (design)

> STATUT : TERMINÉ 2026-06-12 — 15 claims jugés (5 UPHELD / 7 WEAKENED / 3 REFUTED), 3 vérifs live en propre + 2 par commande, 15 angles morts structurels pour la planification.

---

## 0. Lecture méthodologique d'ensemble (la faille racine)

Le rapport d'hier déclare « ✅ CONVERGED — production-perfect au sens du GOAL §F » sur la base de
**deux cycles qui rejouent LE MÊME parcours scripté sur LA MÊME DB re-seedée à l'identique**
(`_w6-c1-*` vs `_w6-c2-*` : mêmes scripts à quelques octets près, même seed W0, mêmes 25 captures
statiques, même flux borne « simple + composé + loyalty + upsell + payment », même mini-flux caisse).
Deux runs déterministes du même chemin qui trouvent la même chose ne prouvent PAS la convergence du
SYSTÈME — ils prouvent la convergence du SCRIPT. La preuve empirique est arrivée < 24 h plus tard :
le Round 1 du dispute, en variant simplement les chemins (promo au checkout, vraie vente caisse
encaissée, refund, clôture session, multi-tab, offline), a produit **~50 anomalies nouvelles dont
plusieurs candidates P0 d'intégrité monétaire** sur exactement les surfaces déclarées converged
(cash-overview, encaissement, file borne, KDS).

Trois choix méthodologiques du GOAL ont fabriqué l'angle mort :
1. **Aucune commande n'a jamais été suivie jusqu'à la DB** (totaux UI relevés, jamais `orders.discount/total` recoupés). D'où promo fantôme invisible (E-SUS-1).
2. **Aucune vente caisse directe n'a été menée au bout** (PaymentComponent frozen → contourné ; le seul encaissement testé = counter-collect borne). D'où le ledger transactions/cash-overview aveugle aux ventes POS (E-SUS-9) et le ticket (receipt) JAMAIS audité.
3. **« Identiques 1:1 » entre cycles = même observateur, mêmes scripts, même seed** — l'« indépendance » du cycle 2 (« liste formée avant lecture de c1 ») est une indépendance de lecture, pas de méthode.

---

## 1. Verdicts claim par claim

(UPHELD = tient · WEAKENED = survit mais la preuve avancée ne le prouve pas · REFUTED = faux ou contredit par preuve)

### C-01 « VERDICT ✅ CONVERGED — production-perfect au sens du GOAL §F » → **REFUTED**
- Round 1 (24 h après, même app, même DB jetable) : E-SUS-1 (promo borne affichée −5,00 € au client, JAMAIS persistée — le client voit 0,00 € puis 1,50 € sur deux écrans consécutifs ; DB `orders.discount=0`), E-SUS-9 (20 ventes caisse `source_surface='pos'` → 0 ligne `transactions`, cash-overview/« Vue Caisse Unifiée » exclut TOUT le CA caisse direct), B-R1-15 (refund espèces persisté `payment_method='credit'` → ledger « Carte bancaire », `OrderService.php:2061/2197` slug en dur), B-R1-04b/E-SUS-10 (collisions N° de file A0011/A0013 inter-jours dans la file d'encaissement → risque d'encaisser la mauvaise commande), D-A6 (« Animations réduites » inopérant en live — watcher jamais câblé).
- Ces défauts étaient ATTEIGNABLES depuis le périmètre du GOAL (cash-overview audité, encaissement audité, promo testée en K8 jusqu'au panier seulement, drawer a11y healé en K9 sans tester l'effet du toggle voisin).
- « Production-perfect » est donc un verdict de chemin, pas de système.

### C-02 « Cycle 2 : P2=0, identiques 1:1 au cycle 1, aucun nouveau finding » → **WEAKENED**
- Vrai au sens littéral (sur le chemin rejoué) ; sans valeur probante au sens revendiqué (convergence).
- Même seed + mêmes scripts + même agent ⇒ le déterminisme garantit quasi mécaniquement le « 1:1 ».
- Wave F a en plus prouvé que la couverture statique c2 est SURESTIMÉE : MD5 identiques —
  `kiosk-payment.png` = `kiosk-upsell.png` = `kiosk-loyalty.png` = `kiosk-cart-empty.png` (4 fichiers = même écran panier vide) ; 6 autres = idle. **10/25 « captures » statiques borne c2 ne montrent pas l'état nommé.** L'écran CONFIRMATION borne n'existe dans AUCUNE capture du GOAL ; grilles Sandwichs/Tacos jamais capturées (et la grille Sandwichs fraîche a immédiatement révélé F-02 : 3 SKU upsell cassés en tête de catégorie client, badge « Nouveau »).
- Ce qu'il aurait fallu : cycle 2 avec PARCOURS DIFFÉRENT (promo au checkout, vente caisse réelle, refund), idéalement par un agent différent, + interdiction de compter une capture-redirect comme couverture d'état.

### C-03 « 13/13 fixes W2 CONFIRMED par l'adversaire » → **WEAKENED**
- Le verdict W2-RED est RECONSTITUÉ par l'orchestrateur après coupure de l'agent RED (admis en tête du fichier). L'orchestrateur qui a commandité les heals a auto-confirmé ses healers = juge et partie. Le fichier admet lui-même 2 réserves : 429-doublon confirmé par code-review et non par déclenchement répété ; régression baseline « non disputée formellement ».
- Les fixes sont individuellement plausibles (captures existantes) mais « CONFIRMED par l'adversaire » est une sur-revendication : c'est « CONFIRMED par l'orchestrateur sur preuves partielles ».
- Round 1 fissure au moins 2 des 13 au niveau système : F2 datepickers (voir C-09), G6 modal sticky (voir C-08 ; Wave F flow/f10 montre les boutons posés AU MILIEU du pavé numérique, touches coupées sous le bord du modal). [vérifs live PENDING]

### C-04 « W4 : 11 CONFIRMED / 1 PARTIAL / 0 REFUTED ; N1 healé par webpackPrefetch » → **REFUTED (sur N1) / WEAKENED (K8, K9)**
- **N1 : le heal webpackPrefetch est INEFFECTIF À L'EXÉCUTION** — D-A1 (Wave D) le prouve sur CE harnais : link prefetch présent dans le DOM, mais offline → `ChunkLoadError: Loading chunk 28 failed` + `net::ERR_INTERNET_DISCONNECTED` sur `/js/kiosk-errors.js` ; l'écran `/kiosk/error/network` ne rend JAMAIS offline (d1-02 + d1-10). Le commit `5cc1880f0` (« en cache avant coupure ») n'a jamais été testé offline après heal : le FINAL_REPORT dit « healé par l'orchestrateur » sans re-test du scénario offline. Cause : réponse dev-server sans Cache-Control/ETag → prefetch non réutilisable.
- **K8 promo « CONFIRMED »** : confirmé jusqu'au PANIER seulement (banner −2,50 €, total 0,00 €). E-SUS-1 prouve que la création de commande laisse tomber la remise (`OrderQuoteService.php:209-219` ne passe jamais `kiosk_promo_code` au pricing). Le fix UI était cosmétiquement vrai et systémiquement mensonger pour le client.
- **K9 drawer a11y « CONFIRMED »** : le retrait de la section thème est vrai, mais le toggle voisin « Animations réduites » du même drawer est INOPÉRANT (D-A6 : `_wireA11yWatchers()` `KioskAppComponent.vue:480-494` ne câble que contrast/pmr/audio ; `useKioskA11y()` n'a AUCUN consommateur runtime — seuls les tests l'importent). L'adversaire W4 a validé le périmètre du diff, pas l'écran.

### C-05 « Vitest full : 2198 passed / 0 failed » → **UPHELD (le chiffre) / WEAKENED (ce qu'il prouve)** [vérif commande PENDING]
- Le compte est probablement exact, mais D-A6 fournit le contre-exemple canonique « tests verts ≠ feature opérante » : `tests/js/kioskA11yComposable.spec.js` teste un composable (`useKioskA11y`) que la prod NE MONTE JAMAIS. Le vert Vitest a co-signé une feature morte. [Je rejoue la spec en commande ci-dessous pour le démontrer.]

### C-06 « Frozen-diff : 0 ligne sur toutes mes vagues » → [PENDING — vérif `git diff --stat` lecture seule]

### C-07 « W5 cross-flow : prouvé » → **REFUTED (au sens « intégrité cross-surface prouvée »)**
- Ce qui a été prouvé : le happy-path création borne → visible caisse → POST 200 → file décrémentée. C'est du ROUTAGE, pas de l'INTÉGRITÉ.
- Ce que Round 1 montre sur les mêmes surfaces : montant client borne ≠ montant caisse dès qu'une promo entre en jeu (E-SUS-1) ; identité client divergente ×3 pour LA MÊME commande (« Client borne » / « Client Borne » / « Admin Le Cayenne » — E-SUS-3, B-R1-17) ; KDS jamais inclus dans le « cross-flow » du GOAL alors que les commandes borne non payées y affichent « Démarrer » actif (E-SUS-6) et que la commande payée est invisible derrière l'overflow (E-SUS-11) ; ledger transactions aveugle aux ventes caisse (E-SUS-9).
- « Cross-flow prouvé » sans jamais lire la DB ni le KDS = sur-revendication caractérisée.

### C-08 « CTA encaissement sticky <900px → VISIBLE » → [PENDING — vérif live 1366×768]
- Mesuré par le GOAL à 1440×900 uniquement (bottom≈394/410 px). 900 px est la borne BASSE de leur unique viewport ; aucun viewport réellement petit (1366×768 = standard caisse low-cost) n'a été testé. Wave F (flow/f10) observe déjà des touches de numpad COUPÉES sous le bord du modal à 900px.

### C-09 « datepickers FR ×4 surfaces » → [PENDING — vérif live cash-sessions-report]
- Cycle 1/2 n'ont re-vérifié EN LIVE que historique (f12). pos-orders/cash-overview/cash-sessions reposent sur les captures du verdict W2 RECONSTITUÉ (05b/06b/15/16). Je re-teste moi-même la 4e surface.

### C-10 « Console caisse : 0 erreur console, 0 HTTP≥400, 0 PAGEERROR (2 passes complètes) » → **WEAKENED**
- « Complètes » = POS, show, cash-overview, historique, tracker. `/admin/transactions` (surface caisse listée dans leurs propres audits W1) n'était PAS dans la passe : B-R1-19 y trouve **403 `setting/payment-gateway` + PAGEERROR AxiosError non interceptée à CHAQUE visite en Branch Manager**. Wave D (D-A3) trouve en plus des 401 récurrents EN LIGNE (login/menu/kiosk-event) côté borne en usage — le gate connu ne couvre que le one-shot boot.
- La claim est vraie du sous-ensemble visité, fausse comme énoncé de surface (« caisse »).

### C-11 « B-1 / A-5 prouvés résolus live » → **UPHELD (B-1) / WEAKENED (A-5)**
- B-1 (badge « À Encaisser » sur show PENDING_COUNTER) : vérifié dynamiquement c2 f11 sur commande fraîche, mécanisme + enum mappé — tient.
- A-5 : le heal = un LIBELLÉ (« (session en cours) »). La confusion STRUCTURELLE survit et s'aggrave sous mutations réelles : B-R1-07 (brut vs net post-refund sur la même page), E-SUS-4 (3 sessions tiroir ouvertes simultanément, le panneau « session en cours » reflète une AUTRE session que celle qui reçoit les mouvements du caissier), E-SUS-9 (le GRAND TOTAL ignore les ventes caisse). Le P2 a été fermé en traitant le symptôme lexical.

### C-12 « P3 résiduels identiques ×2 cycles = backlog polish » (7 P3) → **WEAKENED**
- Deux des « P3 cosmétiques » se révèlent être des symptômes de défauts structurels : « 401 one-shot boot » → D-A3 montre des 401 récurrents en usage (famille élargie, dont quote 401→200 systématique E-OBS-2) ; « deep-link cash-instruction #— » → D-A5 montre que le flux cash-instruction ne vide JAMAIS le panier (fenêtre 45 s de re-commande, 409 brut EN « Request failed with status code 409 »). Le tri P3 a éteint la curiosité sur ces deux-là.

### C-13 « PHPUnit ciblé healers : 24/24 + 34/34 + 29/29 » → **UPHELD (portée assumée)**
- Ciblé, déclaré ciblé. Pas de sur-revendication ici. (Mais aucun test backend n'a couvert le chemin promo→pricing, le trou exact d'E-SUS-1.)

### C-14 « ~200 screenshots analysés » → **WEAKENED**
- Wave F : 10/25 statiques c2 = redirects (4 MD5-identiques) ; les compter dans « analysés » gonfle la couverture. Les états réellement uniques analysés sont nettement moins nombreux que le chiffre vitrine.

---

## 2. Vérifications en propre (live + commande) — [section complétée au fil de l'eau]

### V-1 [commande] Frozen-diff du range GOAL — **FAIT → C-06 UPHELD**
```
git diff --stat 7351808a8^..8e9c37416 -- <13 fichiers frozen §7>
 public/js/pos-wizard.js | 336 +++…  (335 insertions, 1 deletion)
git log --oneline 7351808a8^..8e9c37416 -- public/js/pos-wizard.js
 c1fc7aa52 feat(pos): renderer générique composer dans pos-wizard.js [FROZEN §7]
```
- Sur tout le range GOAL (W0 `7351808a8` → FINAL `8e9c37416`), le SEUL fichier frozen touché est
  `pos-wizard.js`, par l'unique commit `c1fc7aa52` = le job parallèle wizard Phase C, couvert par
  `plans/LOCK_POS_WIZARD_GENERIC_RENDER_2026-06-10.md` (fichier vérifié présent). Aucun commit
  `heal(uiux-*)` ne touche un frozen. KioskAppComponent.vue : 0 commit dans le range.
- **C-06 UPHELD** — la claim « frozen 0 ligne sur mes vagues, delta pos-wizard.js = LOCK parallèle » est exacte.

### V-2 [commande] Vitest vert sur feature NON câblée — **FAIT → C-05 WEAKENED confirmé**
```
npx vitest run tests/js/kioskA11yComposable.spec.js --reporter=basic
 ✓ tests/js/kioskA11yComposable.spec.js (5 tests) — 1 passed (5/5)
```
- Et pourtant (re-greppé moi-même) : `grep -rn "useKioskA11y(" resources/js` → l'UNIQUE hit hors tests
  est la définition (`resources/js/composables/useKioskA11y.js`) ; `_wireA11yWatchers()`
  (`KioskAppComponent.vue`, lu lignes 470-500) ne câble QUE contrast/pmr/audio — `reducedMotion` :
  0 occurrence dans le composant. La spec verte 5/5 teste un composable que la prod ne monte jamais
  → le toggle « Animations réduites » du drawer est inopérant en session (D-A6 Wave D confirmé en propre).
- Conclusion : « Vitest 2198/0 » est un chiffre honnête qui co-signe au moins une feature morte.
  Le vert Vitest ne peut PAS servir de preuve de convergence UX.

### V-3 [live] Promo borne → checkout → DB — **FAIT → C-01/C-07 REFUTED, reproduit en propre**
Script `tests/e2e/_d1meta-promo.mjs` (Playwright channel:'chrome', 1080×1920, fr-FR). Run 09:36 :
```
CART après promo : subtotal 1,50 € · promoDiscount -1,50 € · total 0,00 €
                   « ✓ Code promo BORNEAUDIT5 appliqué (−1,50 €) »
PAYMENT          : « TOTAL À RÉGLER : 0,00 € »
ORDER POST 201   : id=4562 subtotal=1.5 discount=0 total=1.5
CASH-INSTRUCTION : #A0017 — « 1,50 € »
DB foodking_e2e  : orders 4562 → subtotal 1.500000 / discount 0.000000 / total 1.500000 / ps=15
                   kiosk_promos BORNEAUDIT5 → uses_count = 0
```
- Le client borne voit « 0,00 € » sur DEUX écrans (panier + paiement) puis « 1,50 € » sur le 3e ;
  la caisse encaissera le montant NON remisé. La remise promo n'existe nulle part côté backend.
- Captures : `meta-V3-cart-promo.png`, `meta-V3-payment.png`, `meta-V3-cash-instruction.png` + `_V3-promo.json`.
- Confirme E-SUS-1 (Wave E) de mes propres mains, sur le parcours EXACT que les cycles 1/2 ont rejoué —
  à un input près : les cycles n'ont jamais TAPÉ le code promo avant checkout. Un seul input de plus
  et la convergence tombait. **Divergence de montant client = P0 candidat** (brief : intégrité numérique).

### V-4 [live] Modal encaissement borne à 1366×768 — **FAIT → C-08 UPHELD (élargi)**
Script `tests/e2e/_d1meta-caisse.mjs`, viewport 1366×768 (jamais testé par le GOAL, mono-1440×900).
- Capture `meta-V4-modal-768.png` (lue) : modal « Encaisser la commande borne » N°A0011, MONTANT TOTAL
  1,00 €, 4 modes, MONTANT REÇU pré-rempli, **footer « Annuler / ✓ Confirmer & Imprimer ticket » VISIBLE**
  à 768px — le sticky G6 tient sous 900.
- Réserves honnêtes : (1) mon probe DOM `div[class*="modal"] button` a aussi mesuré « visibles » les
  boutons d'un AUTRE modal monté simultanément derrière (Espèces/Carte (TPE)/Multi-paiement = PaymentComponent,
  + « Ajouter un client ») — empilement de modals dans le DOM, probe mis-scopé, le screenshot fait foi ;
  (2) des touches du numpad apparaissent COUPÉES sous le footer (slivers en bas de modal) — recoupe
  l'observation Wave F flow/f10, cosmétique.
- Verdict : claim UPHELD, mais c'est MOI qui apporte la preuve <900 réelle — le GOAL affirmait
  « <900px » en n'ayant mesuré QUE 900.

### V-5 [live] Datepicker FR cash-sessions-report — **FAIT → C-09 UPHELD**
- 4e surface (celle dont la preuve reposait sur le verdict W2 RECONSTITUÉ) re-testée en propre :
  input `dp__input` (vue-datepicker, plus de natif mm/dd/yyyy), overlay « juin 2026 · lu ma me je ve sa di » → FR=true.
- Capture `meta-V5-datepicker-sessions.png` + `_V45-log.txt`. Claim « datepickers FR ×4 » tient.

---

## 3. Angles morts structurels — jamais testés par le GOAL (recoupés avec ce que Round 1 couvre DÉSORMAIS)

| # | Angle mort | GOAL 06-11 | Round 1 (06-12) | Reste à couvrir (round suivant) |
|---|---|---|---|---|
| BS-1 | **Ticket/receipt NF525** (rendu, montants, libellés) | JAMAIS capturé (aucune capture receipt dans ~200 shots) | Wave A : A-SUS-1 (colonne « Prix » en HT sans marqueur), A-SUS-2 (typo « : »), A-SUS-6 (« mariné ,Sauce » file:line ReceiptComponent.vue:126-128) ; Wave E receipts extraits | Rendu papier 80mm réel, reprint, ticket cuisine (:311-313 même motif), ticket remboursement |
| BS-2 | **Vente caisse directe end-to-end** (PaymentComponent → POST → ledger) | Jamais menée au bout (panier construit, jamais payé) | Waves A+E : faite ×3 → **E-SUS-9** (ledger transactions + Vue Caisse Unifiée AVEUGLES à 100% des ventes POS directes, CashOverviewController.php:111-116) | Multi-paiement multi-tranches, « MFS »/« Autre » (A-SUS-4), remises caisse, TR mixte |
| BS-3 | **Refunds** | Zéro test | Wave B : **B-R1-15** (refund espèces persisté `'credit'` → « Carte bancaire » au ledger, OrderService.php:2061/2197), B-R1-06 (copy miroir mensongère pre-Z), B-R1-13/14 | Refund post-Z (miroir réel + séquence fiscale), refund partiel, refund TR/carte, lien P0 connu changePaymentStatus |
| BS-4 | **Clôture session + Z** | Zéro test (le GOAL a ouvert des sessions, jamais fermé) | Wave B : close/reconcile OK (écart 0,50 persisté) MAIS B-R1-08 (aucun récap), B-R1-16 (« Voir les clôtures Z » → page Transactions), B-R1-18 (AUCUNE UI Z), raison d'écart affichée nulle part | Clôture Z réelle (POST close), PDF Z rendu, X-report clés numériques {"1","5"} si UI les consomme |
| BS-5 | **KDS boundary** (le cross-flow y aboutit) | Exclu du « cross-flow prouvé » | Wave E lecture seule : E-SUS-6 (« Démarrer » ACTIF sur commandes NON payées — release-guard de heal/ultra-audit-w4 absent de CETTE branche ?), E-SUS-11 (payée invisible derrière overflow +11) | Clic Démarrer sur non-payée, bump/recall, OSS, politique d'ordre de file KDS |
| BS-6 | **Offline / chaos réseau** | Heal N1 jamais re-testé offline post-heal | Wave D : **D-A1** (prefetch inopérant, ChunkLoadError, écran réseau JAMAIS atteignable offline), D-A2 (« Network Error » EN au checkout panier), D-A4 (aucun indicateur offline catalogue) | Coupure MI-POST (timeout en vol), re-queue kiosk-event, soketi down→up, mode dégradé caisse |
| BS-7 | **Cycle de vie panier / état borne** | Happy-path only | Wave C : S1 (promo perdue au reload — non persistée vs loyalty persistée), S5 (reset borne TOTAL sans message sur 401 terminal) ; Wave D : **D-A5** (panier JAMAIS vidé sur flux cash-instruction → fenêtre 45 s, 409 brut EN), D-A11 (multi-tab last-writer-wins, articles perdus silencieusement) | Idempotency-key après MODIFICATION du panier (doublon partiel plausible, non testé), restauration session Electron |
| BS-8 | **File borne : purge/expiration/collisions** | Jamais pensé (la file à 50 était lue comme « peuplée » = PASS) | Wave B : B-R1-04/04b (48 commandes périmées du 10/06 devant celles du jour ; DEUX « A0013 » dans la même file) ; Wave E : E-SUS-10 (même N° → commandes différentes selon surface) | Politique d'expiration des « comptoir différé » abandonnées, date sur les cartes, garde anti-collision à l'encaissement |
| BS-9 | **Multi-session / multi-caissier** | 1 session, 1 acteur, toujours | Wave E : E-SUS-4 (3 sessions tiroir OUVERTES simultanément, mouvements imputés à une autre session que celle affichée), B-R1-09 (refund imputé au tiroir d'un autre caissier), B-R1-11 (encaissement sans session = garde a-posteriori) | 2 caissiers simultanés, double-clic Encaisser sur la MÊME commande (race), single-session enforcement |
| BS-10 | **Viewports réels caisse** | Mono 1440×900 | Wave A re-capture 1366×768 (partiel) ; moi V-4 (modal OK à 768) | Sweep 1280×800 + zoom navigateur 125 %, tablette |
| BS-11 | **Stress / concurrence** | Rien (les 429 subis = artefact, pas un test) | Subi passivement (token churn documenté partout) | Rafale commandes borne pendant encaissements, throttle UX systématique, perf file 200+ |
| BS-12 | **RGPD / confidentialité borne** | Rien | Wave D : D-A10 (tel → NOM COMPLET + solde + redeem sans 2e facteur, atténué rate-limit) | Mentions légales/consentement à l'inscription fidélité borne, rétention, affichage minimal (initiale au lieu du nom ?) |
| BS-13 | **Impression réelle** | Tout le harnais en bypass (« MODE TEST — IMPRESSION BYPASSÉE ») ; « Confirmer & **Imprimer ticket** » jamais imprimé | Idem (gate PRINT-1 connu) | E2E nœud impression réel (hors harnais), drawer-kick, B-R1-05 (deux voies tiroir, deux comportements) |
| BS-14 | **Identité client/opérateur cross-surface** | G4 healé sur show UNIQUEMENT | E-SUS-3 + B-R1-17 (« Client borne » / « Client Borne » / « Admin Le Cayenne » / « Passager » selon surface ; compte machine fuite en CLIENT dans historique) | Sweep des surfaces consommatrices (historique, tracker, KDS, exports XLSX) |
| BS-15 | **A11y OPÉRANTE (effet réel des réglages)** | axe-core déclaratif W1 + heals DOM | Wave D : **D-A6** (« Animations réduites » mort — composable jamais monté ; vérifié en propre V-2), D-A8 (mode AAA n'agit pas sur le CTA idle) | Watcher reducedMotion/audioDescription, parcours clavier caisse complet, lecteur d'écran |

Lesson transverse pour la planification : **le GOAL validait des DIFFS, le Round 1 valide des COMPORTEMENTS.**
Tout round suivant doit imposer : (a) trace DB par commande (orders.discount/total + ledger), (b) au moins
un parcours NON scripté-identique par cycle, (c) interdiction de compter une capture-redirect comme couverture,
(d) re-test du scénario d'origine après chaque heal (N1 = contre-exemple), (e) verdicts adversaires
JAMAIS reconstitués par l'orchestrateur des heals.

---

## 4. Décomptes finaux

| Verdict | Claims | Détail |
|---|---|---|
| **UPHELD** | 5 | C-06 frozen 0 ligne (vérifié git) · C-08 CTA <900px (prouvé par MOI à 768) · C-09 datepickers FR ×4 (4e surface re-testée) · C-11a B-1 résolu · C-13 PHPUnit ciblé |
| **WEAKENED** | 7 | C-02 « identiques 1:1 / aucun nouveau finding » · C-03 « 13/13 CONFIRMED » (verdict reconstitué juge-et-partie) · C-05 Vitest 2198/0 (vrai chiffre, co-signe une feature morte — V-2) · C-10 « 0 console errors caisse » (sous-ensemble) · C-11b A-5 (symptôme lexical healé, structure intacte) · C-12 « 7 P3 cosmétiques » (2 sont des symptômes structurels) · C-14 « ~200 screenshots analysés » (10/25 c2 = redirects, 4 MD5-identiques) |
| **REFUTED** | 3 | C-01 « CONVERGED production-perfect » · C-04 « N1 healé » (webpackPrefetch inopérant offline, K8/K9 validés au diff pas à l'écran) · C-07 « cross-flow prouvé » (routage ≠ intégrité ; promo, identité, KDS, ledger tous divergents) |

Artefacts en propre : `meta-V3-cart-promo.png` · `meta-V3-payment.png` · `meta-V3-cash-instruction.png` ·
`meta-V4-modal-768.png` · `meta-V5-datepicker-sessions.png` (5 captures ≤ 6) + `_V3-promo.json` ·
`_V4-modal-768.json` · `_V3-log.txt` · `_V45-log.txt`. Scripts jetables `tests/e2e/_d1meta-{promo,caisse}.mjs`.
Mutations DB en propre : 1 commande borne (id 4562, #A0017) sur DB jetable. Aucun fichier source modifié,
aucun git d'écriture, aucun artisan, aucun build.
