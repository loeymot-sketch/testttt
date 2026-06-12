# ADVERSARIAL VERDICT — Vague B caisse-gestion (Round 2 post-heal, dispute-2026-06-12)

Superviseur adversarial R2. Mission: disputer le WAVE_REPORT R2 (agent GStack coupé après l'état 5 — l'état 6
b6-* n'a JAMAIS été analysé par le GStack, je le couvre moi-même) + juger les heals de la vague
(B-R1-19, B-R1-15, ADV-B-07, B-R1-04) CONFIRMED/PARTIAL/REFUTED. ÉCRITURE INCRÉMENTALE.

Statut: **TERMINÉ** — pass 0 env + pass 1-2 visuel/technique (45 PNG + quartets + re-greps) + pass 3 live
(3 scripts adversariaux, 1 mutation neuve) + convergence complète + disputes WAVE_REPORT + verdict.

## Pass 0 — Environnement

- App :8768 UP (login 200). Heals présents sur la branche: `42ce66fea` (B-R1-19 backend), `2bef9dfc4`
  (B-R1-19 front), `14d897928` (B-R1-15), `b824dd933` (ADV-B-07), `d377da185` (B-R1-04 UI),
  `956933ec5` (wire-up loyalty_redeem_discount + rebuild bundles).
- DB probe: transactions fraîches `POS-4525/4526/4528/4530/4531` type=payment (write-side ADV-B-07 vivant),
  `cash_back` 763 = `cash` (4531), 764 = `counter_cash` (4334), 762 = `counter_cash` (4516) — plus aucun
  'credit' frais.

## Pass 1+2 — Visuel + technique (états 1-2, heals B-R1-19 + B-R1-04)

### B-R1-19 — /admin/transactions rôle BM
- Re-grep code: `PaymentGatewayController.php:36-37` — `permission:settings|transactions` sur index/update
  PUIS `permission:settings` sur update (empilé, write strict) ✅ ; `PaymentGatewayResource.php` — options
  serialisées UNIQUEMENT pour détenteur `settings` (`$request->user()?->can('settings')`), sinon `[]` ✅ ;
  front `TransactionListComponent.vue:49,225,266` — flag `paymentGatewaysUnavailable` + v-if dégradation ✅.
- Visuel b1-01/b1-06/b1-07: page rendue BM, 746 entrées, filtre « Mode de paiement » fonctionnel
  (Credit → 1 row TXN-JETe3vhjRnfR, artefact seed pré-heal). Console: 0 error (hors WS:6001 connu).
  Réseau ≥400: 0.
- `_b1-gateway-responses.json`: GET payment-gateway → **200, body intégral 75 bytes
  `{"data":[{"id":2,"name":"Credit","slug":"credit","status":5,"options":[]}]}`** — options STRIPPÉ.
- Scan secrets sur TOUS les artefacts vague B: `sk_live|sk_test|stripe_secret|secret_key|client_secret|whsec_`
  → 2 hits = le texte du pattern de scan lui-même (WAVE_REPORT + log). **0 secret réel.**
- Reste à live-confirmer moi-même (session neuve BM) ci-dessous.

### B-R1-04 (part UI) — badges date file encaissement
- DOM b2-01: **50 testids `enc-day-badge-*` uniques, tous « 10/06 »** ; tri jour-récent-d'abord
  (A0002/A0004…A0008 du jour AVANT les zombies A0009+ du 10/06) ; les cartes du jour n'ont PAS de badge.
- DOM b2-05: chip modal `pos-counter-collect-day-badge` = « ⚠ Commande du 10/06/2026 » AVANT confirmation ✅.
- Duplication A inter-jours TOUJOURS présente (2× « A0009 » dans la même liste) — mitigée par badge,
  purge backend = décision owner explicite (pas re-compté P0, voir convergence).
- ⚠ **PROCESS (récidive ADV-B-04)**: `b2-02-encaissement-queue-bas.png` ≡ `b2-01` (MD5
  `4e02d44e7c69dd4aa4b198b5ef7af78a` identiques) ET `b6-02-cash-overview-bas.png` ≡ `b6-01` (MD5
  `3e52fae644c4c116489e30dfb68b1efe`) — le GStack R2 n'a JAMAIS scrollé malgré le finding R1 explicite.
  Je comble en live.

## Pass 1+2 (suite) — états 3-6 (B-R1-15, ADV-B-07, clôture, rapports)

### États 3-5 (session, vente directe, refunds, clôture) — WAVE_REPORT vérifié
- b3-12: stats session 22 — fond 50,00 €, 3 mouvements, attendu **67,30 €** = 50+6,90+8,90+1,50 exact ✅.
  « Clôturer la caisse » en outline-danger (QW ADV-F-P2-2 visible).
- b4-01-modal (refund 4531): « Mode de paiement: Espèces » (plus de Carte bancaire) MAIS warning
  « génère une commande miroir NF525 » toujours présent en pre-Z → **B-R1-06 SURVIVANT P1** (hors scope heals,
  vérifié DB par le GStack: aucun order `parent_order_id IN (4531,4334)`).
- b4-03 + DB: `cash_back` 763=cash(4531), 764=counter_cash(4334), 762=counter_cash(4516) ; UI grand livre
  « Espèces −1,50 / −6,90 / −5,00 » ✅. Zéro 'credit' frais — seul l'artefact seed TXN-JETe3vhjRnfR (07-06,
  pré-heal) reste « Carte bancaire » (pas de migration corrective mandatée).
- b5-03: écart clôture « **+0,50 €** » SIGNÉ + raison obligatoire ✅ (form). MAIS b6-03 (rapport sessions):
  cellules « 0,50 € » / « 2,00 € » SANS signe (DOM re-greppé: `>0,50&nbsp;€<`) → **B-R1-20 PARTIEL, P3 SURVIVANT**
  (form signé, page rapport non signée).
- Intégrité tiroir: attendu clôture 58,90 = 50+17,30−8,40 (refunds OUT 1,50+6,90 décomptés) ✅ exact.

### État 6 (b6-*) — JAMAIS analysé par le GStack (agent coupé) — couvert ici
- b6-01 cash-overview 12/06: GRAND TOTAL 59,54 €/12 tx · CAISSE 33,74 €/8 tx · BORNE 25,80 €/4 tx.
  Arithmétique interne EXACTE: Espèces 9·41,54 + Autre 1·4,50 (split POS-4528, render « Autre » connu
  A-RED-10 frozen) + Carte 2·13,50 = 59,54 ✅. **ADV-B-07 visible sur l'overview**: les ventes POS directes
  (A0018/A0017/A0013/A0012/A0003/A0001) peuplent la carte CAISSE.
- **B-R1-07 SURVIVANT P1**: cartes BRUTES — les 3 refunds du jour (−13,40 €) n'apparaissent ni en ligne ni en
  mention « hors remboursements » sur la page ; le grand livre transactions, lui, les montre en négatif.
- Panneau « Réconciliation caisse » lié à « Caisse ouverte à: 22:46 » = session ZOMBIE #20 du 10/06 (la
  session 22 du jour étant clôturée, `resolveOpenCashSession` retombe sur la plus récente OUVERTE) —
  recoupe **E-ADV-7 (gate, non recompté)** + **ADV-B-09 SURVIVANT P2** (zombies #19/#20 toujours « Ouverte »
  dans b6-03, 14 et 3 transactions, traversent les jours fiscaux).
- b6-08 dashboard: CA jour 37,24 € / 19 commandes / Ticket Moyen 4,66 € — 37,24/19=1,96≠4,66 → **ADV-B-01
  SURVIVANT P2** (base « payées seulement » toujours sans qualificatif). Cohérence croisée 37,24 vs overview
  59,54 EXPLIQUÉE (zombies 10/06 encaissés aujourd'hui + refunds exclus) — pas de nouveau défaut d'intégrité.
- b6-09: « apres-lien-z » atterrit sur **Transactions** → **B-R1-16 SURVIVANT P1** — re-grep
  `LastZReportWidget.vue:28` = `{ name: 'admin.transactions.list' }` inchangé. Aucun heal ne l'a visé.
- b6-11 show 4531: « Imprimer la facture » casse FR naturelle ✅ (**B-R1-12 FERMÉ**, 00cc81a16) ; badges
  « Remboursé » + « Retournée » → **B-R1-13 SURVIVANT P3**. **NOUVEAU P3**: bloc « Informations Client »
  affiche « Passager » alors que l'historique dit « Client passage » (même personne, 2 libellés).
- b3-11 movements DOM: entête « Écart » sur colonne Sens (**B-R1-02 SURVIVANT P2**) + notes
  « (SSOT modal) » persistées (**B-R1-03 SURVIVANT P2**).

## Pass 3 — LIVE adversarial (session NEUVE, scripts `tests/e2e/_d2red-B-caisse-gestion-0{1,2,3}.mjs`, log `_red2-b1-log.txt`)

1. **B-R1-19 LIVE ✅** — login BM frais → /admin/transactions :
   `GATEWAY 200 …payment-gateway… bytes=75 secret=none body={"data":[{"id":2,"name":"Credit","slug":"credit","status":5,"options":[]}]}`
   — scan `sk_live|sk_test|stripe_secret|secret_key|client_secret|whsec_|paypal_client` = **none** ;
   console errors (hors WS) = **0** ; réseau ≥400 = **0**. Capture `red2-b-01-transactions-bm.png`.
2. **B-R1-04 LIVE ✅** — /admin/encaissement : **48 badges `enc-day-badge-*` rendus ET visibles, texte « 10/06 »** ;
   ordre file = 11 cartes [today] PUIS zombies [10/06] ; duplicatas A inter-jours TOUJOURS là (A0011/A0014/A0019
   en double today vs 10/06) mais désambiguïsés par badge. Captures `red2-b-02/03`, bas réel `red2-b-12`.
3. **ADV-B-07 LIVE ✅ (mutation neuve)** — vente POS directe Coca 1,50 € espèces → `POST /admin/pos 201, order 4538` ;
   `/admin/transactions` top row = **POS-4538-… · Espèces · +1,50 €** (OUI) ; `/admin/cash-overview` carte CAISSE
   **42,26 €/10 tx** (33,74/8 → +7,02 vague parallèle +1,50 la mienne = EXACT) + ligne « 16:08 N°A0024 Caisse
   Espèces 1,50 € ». DB 4538: **1 SEULE row transactions** (POS-4538, cash), `source=15` forcé (**ADV-B-08 ✅**),
   `order_payments` mode=1 1,50/2,00/0,50 (E-ADV-9 ✅), fiscal 2180 alloué.
4. **B-R1-17 LIVE ✅** — historique : commandes borne 4536/4537 → CLIENT « **Client borne** » ; caisse → « Client
   passage ». Capture `red2-b-08-historique-borne-4335.png`.
5. **ADV-B-02 (=ADV-F-P0-1) bundle réel ✅** — hit-test GStack b2-06 re-vérifié (`_b2b-modal-log.txt`):
   `keysFound=14, blocked=[], bodyScroll 737/737, footerPos static, ctaVisible true`.
6. **Trou « bas de page » comblé + cause racine trouvée** — `window.scrollTo` ET `fullPage` sont INOPÉRANTS sur
   l'admin (layout `main.db-main` 100vh overflow:auto, scrollHeight 3486 vs 900) → mes propres captures 01 étaient
   AUSSI identiques. Scroll du conteneur INTERNE (`red2-b-12/13-…-INNER.png`) : bas encaissement = zombies badgés
   10/06 + chips attente 53h+ (honnête), bas overview = « Répartition par mode » Espèces 11·50,06 + Autre 1·4,50 +
   Carte 2·13,50 = 68,06 €/14 tx EXACT. **AUCUN défaut nouveau en bas de page.** Le « scroll fake » du GStack est
   donc une LIMITE DE HARNAIS partagée, pas une tromperie — mais personne n'a vérifié les MD5 avant moi.

## Jugement des HEALS de la vague (mandat orchestrateur)

| Heal | SHA(s) | Verdict | Preuve décisive |
|---|---|---|---|
| **B-R1-19** transactions BM sans secrets | H1 `42ce66fea` + H3 `2bef9dfc4` | **CONFIRMED** | Live session neuve: gateway 200/75 bytes options=[], 0 secret (scan 6 patterns sur le body RÉSEAU + tous artefacts), 0 console error, 0 ≥400 ; controller `permission:settings\|transactions` + update settings-strict re-greppé ; resource strip `PaymentGatewayResource.php` re-greppé ; filtre Mode de paiement fonctionnel (b1-07/08) |
| **B-R1-15** refund mode réel | H1 `14d897928` | **CONFIRMED** | DB: cash_back 763=`cash`(4531) / 764=`counter_cash`(4334) / 762=`counter_cash`(4516) ; UI grand livre « Espèces −1,50/−6,90/−5,00 » (b4-03) ; `refundLedgerMethod` aux 3 call-sites re-greppé (OrderService:2166/2306, FrontendOrderService:762) ; seul l'artefact seed 07-06 pré-heal reste « Carte bancaire » (pas de migration corrective mandatée — acceptable) |
| **ADV-B-07** ventes POS dans unifiée+transactions | H1 `b824dd933` | **CONFIRMED** | MA vente neuve 4538: 201 → 1 SEULE row POS-4538 cash +1,50 dans transactions ET carte CAISSE 42,26/10 tx exacte avec la ligne A0024 ; zéro double-compte sur counter-collect (4334/4335/4516 = uniquement COUNTER-*) ; arithmétique overview interne EXACTE 2 fois (59,54 puis 68,06) |
| **B-R1-04** badges date file (part UI) | H3 `d377da185` | **CONFIRMED** | Live: 48 badges « 10/06 » visibles, tri jour-récent-d'abord (11 today en tête), chip modal « ⚠ Commande du 10/06/2026 » avant confirmation (b2-05 DOM + live). **Résiduel assumé** (décision owner explicite, hors mandat heal): pas de purge backend, numéros A toujours dupliqués inter-jours — la mitigation UI réduit fortement le risque « mauvaise commande » sans l'éliminer (disclose, pas re-compté P0) |

## CONVERGENCE — chaque finding R1 vague B → état R2

| ID R1 | Sév R1 | État R2 | Preuve |
|---|---|---|---|
| B-R1-15 | P0 | **FERMÉ** | heal CONFIRMED ci-dessus |
| B-R1-04+04b | P0 | **FERMÉ-MITIGÉ** | heal UI CONFIRMED ; purge=gate owner, duplicatas A subsistent (disclose) |
| B-R1-19 | P0 | **FERMÉ** | heal CONFIRMED ci-dessus |
| ADV-B-07 | P0 | **FERMÉ** | heal CONFIRMED ci-dessus |
| ADV-B-02 numpad occlus | P1 | **FERMÉ** | hit-test bundle réel 14/14, blocked=[], footer static (b2-06 + `_b2b-modal-log.txt`) |
| ADV-B-03 « espèces uniquement » | P1 | **FERMÉ** | `fr.json` kiosk.cash_instruction.help = « Réglez à la caisse — espèces, carte ou ticket restaurant. » (H2 `3538e1a04`) |
| B-R1-06 copy miroir NF525 | P1 | **SURVIVANT** | modal b4-01 promet toujours « commande miroir NF525 » ; DB: AUCUN order parent 4531/4334 en pre_z — hors scope heals livrés |
| B-R1-07 brut/net overview | P1 | **SURVIVANT** | refunds du jour −13,40 invisibles sur la page (59,54→68,06 bruts), aucune mention « hors remboursements » (b6-01 + red2-b-13) |
| B-R1-16 lien Z → transactions | P1 | **SURVIVANT** | b6-09 atterrit sur Transactions ; `LastZReportWidget.vue:28` = `admin.transactions.list` inchangé |
| B-R1-17 client = compte machine | P1 | **FERMÉ** | live historique « Client borne » (4536/4537) ; H1 `7102fcd18` |
| ADV-B-08 source client-controlled | P1 | **FERMÉ** | live 4538 `source=15` malgré payload frontend ; `OrderService.php:736` + canonical quote `OrderQuoteService.php:505` |
| B-R1-01 pas d'UI apport/retrait | P2 | SURVIVANT | grep addMovement/paid_in/paid_out admin = 0 hit |
| B-R1-02 entête « Écart » col. Sens | P2 | SURVIVANT | b3-11 DOM: `Date\|Type\|Montant\|Écart\|Notes` au-dessus de « ↑ Entrée » |
| B-R1-03 « (SSOT modal) » | P2 | SURVIVANT | b3-11 DOM + DB notes |
| B-R1-05 deux voies tiroir | P2 | SURVIVANT | b3-09/10: no-sale → 422 no_printer, toast FR sans cause ; paiement espèces = sim |
| B-R1-08 pas de récap clôture (+raison) | P2 | SURVIVANT | b5-06 ; raison d'écart toujours restituée nulle part (rapport sessions sans colonne raison) |
| B-R1-09 refund imputé au refunder | P2 | SURVIVANT (policy) | mouvements out sur session du clicker (ici la sienne — indécidable autrement; code inchangé) |
| B-R1-10 colons collés + « (à venir) » | P2 | SURVIVANT | live red2-b-13: « Caisse ouverte à:13:41 », « tiroir (à venir) » |
| B-R1-11 encaissement sans session | P2 | SURVIVANT | `PaymentService.php:519` (log+continue) + bandeau orphelins `CashOverviewComponent.vue:196` |
| B-R1-18 aucune UI admin Z | P2 | SURVIVANT | grep router z-report/cloture = 0 route |
| ADV-B-01 Ticket Moyen sans qualificatif | P2 | SURVIVANT | b6-08: 4,66 € vs 37,24/19=1,96 (base payées non disclosée) |
| ADV-B-09 sessions zombies | P2 | SURVIVANT | b6-03: #19/#20 « Ouverte » (10/06) avec 14/3 tx ; panneau réconciliation retombe dessus après clôture #22 (b6-01 « ouverte à 22:46 ») |
| B-R1-12 « Imprimer La Facture » | P3 | **FERMÉ** | b6-11 « Imprimer la facture » (H3 `00cc81a16`) |
| B-R1-13 « Remboursé »/« Retournée » | P3 | SURVIVANT | b6-11 badges |
| B-R1-14 doc-drift listener | P3 | SURVIVANT | aucun heal ne l'a visé |
| B-R1-20 signe écart | P3 | **PARTIEL** | form clôture désormais SIGNÉ « +0,50 € » (b5-03) ; page rapport sessions toujours sans signe (`>0,50 €<` b6-03 DOM) |
| ADV-B-04 captures bas identiques | P3 proc | **RÉCIDIVE** (cf. ADV-B-R2-03) | b2-02≡b2-01, b6-02≡b6-01 MD5 — cause racine harnais identifiée, bas couvert par moi |
| ADV-B-06 « 3.8 » point décimal | P3 | SURVIVANT | input non healé (numpad virgule vs frappe clavier point) |

## NOUVEAUX findings R2 (au-delà du WAVE_REPORT)

- **[ADV-B-R2-01 — P2]** Le filtre « Mode de paiement » de /admin/transactions ne propose QUE « Credit »
  (la liste vient de `payment-gateway?excepts=1` = gateways en ligne, Cash exclu) alors que ~99 % des 759 lignes
  du grand livre sont « Espèces » : le BM ne peut PAS filtrer sur le mode dominant. Le GStack l'a noté P3 ;
  je le monte P2 — un filtre qui ne sait sélectionner ni Espèces ni Carte-au-comptoir est inopérant pour
  l'usage réel du grand livre. (b1-06: dropdown = « -- » + « Credit » seuls.)
- **[ADV-B-R2-02 — P3]** Show commande (b6-11): bloc « Informations Client » affiche « **Passager** » alors que
  l'historique et le POS disent « **Client passage** » pour la même personne (2 libellés, 1 concept).
- **[ADV-B-R2-03 — P3 process]** Récidive ADV-B-04: les captures « -bas » R2 sont MD5-identiques aux « -haut »
  (b2-02≡b2-01 `4e02d44e…`, b6-02≡b6-01 `3e52fae6…`) — flagué au R1, reproduit au R2 sans contrôle MD5.
  Cause racine établie par moi: layout admin inner-scroll (`main.db-main` 100vh) — `window.scrollTo` ET
  `screenshot fullPage` sont tous deux inopérants (mes red2-b-02/03 et b-10/11 identiques aussi).
  Remède pour les prochains rounds: scroller `main.db-main` (cf. `_d2red-B-caisse-gestion-03.mjs`).
  Bas de pages couverts: AUCUN défaut nouveau (red2-b-12/13).

## DISPUTES du WAVE_REPORT R2

| # | Claim GStack R2 | Verdict |
|---|---|---|
| W1 | État 1 « HEAL B-R1-19 CONFIRMÉ » | **UPHELD** — reproduit sur session neuve indépendante |
| W2 | État 2 « HEAL B-R1-04 UI CONFIRMÉ » | **UPHELD** — badges/tri/chip re-prouvés live + DOM |
| W3 | État 3 intégrité session 22 « chiffre par chiffre » | **UPHELD** — 67,30 attendu exact, clôture 58,90 incl. refunds out exacte |
| W4 | État 4 « HEAL B-R1-15 CONFIRMÉ » | **UPHELD** — DB + UI concordants, zéro 'credit' frais |
| W5 | « b2-02-encaissement-queue-bas » et « b6-02-cash-overview-bas » = couverture bas de page | **REFUTED** — MD5 identiques aux hauts ; couverture réelle faite par l'adversaire (red2-b-12/13) |
| W6 | État 6 (rapports/overview/historique) | **ABSENT du rapport** (agent coupé) — couvert intégralement par l'adversaire ci-dessus, RAS bloquant nouveau |

## VERDICT FINAL — Vague B Round 2

**HEALS: 4/4 CONFIRMED** (B-R1-19, B-R1-15, ADV-B-07, B-R1-04-part-UI) + 2 heals d'autres vagues
fermant MES findings R1 (ADV-B-02 via ADV-F-P0-1, ADV-B-03 via H2) + ADV-B-08 et B-R1-17 fermés (H1).
**Aucune régression introduite par les heals détectée** (console 0, ≥400 0, arithmétique tiroir/overview/ledger
exacte sur mutation neuve).

État OUVERT post-R2 (tous = SURVIVANTS R1 hors périmètre des heals livrés + 3 nouveaux mineurs):

| Sév | Count | IDs |
|---|---|---|
| **P0** | **0** | — |
| **P1** | **3** | B-R1-06 (copy miroir NF525 pre-Z) · B-R1-07 (overview aveugle aux refunds) · B-R1-16 (widget Z → transactions) |
| **P2** | **12** | B-R1-01 · B-R1-02 · B-R1-03 · B-R1-05 · B-R1-08 · B-R1-09 · B-R1-10 · B-R1-11 · B-R1-18 · ADV-B-01 · ADV-B-09 · ADV-B-R2-01 |
| **P3** | **6** | B-R1-13 · B-R1-14 · B-R1-20 (partiel) · ADV-B-06 · ADV-B-R2-02 · ADV-B-R2-03 (process) |

Verdict vague: **JAUNE** — les 4 P0 du R1 sont morts et prouvés morts sur mutation neuve, mais 3 P1
user-visibles survivent (aucun n'était dans le périmètre des heals livrés — ce sont des restes R1, pas des
régressions). Un round de heal ciblé B-R1-06/07/16 (3 fixes front/copy bien bornés, 0 frozen) ramènerait
la vague à VERT.

