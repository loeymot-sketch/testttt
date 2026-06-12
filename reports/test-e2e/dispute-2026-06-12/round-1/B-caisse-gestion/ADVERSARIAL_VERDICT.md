# ADVERSARIAL VERDICT — Vague B caisse-gestion (Round 1, dispute-2026-06-12)

Superviseur adversarial (reprise après coupure de l'agent précédent — pass 1 conservé, complété).
45/45 PNG lus en analyse multimodale + quartets DOM/console/network + JSON bruts + re-greps code + DB read-only + PDF EOD rendu.
Statut: PASS 1-2-3 TERMINÉS — reste vérifs live (numpad, cash-overview bas) puis disputes FINAL_REPORT. ÉCRITURE INCRÉMENTALE.

## Pass 1 — Visuel (45 PNG lus)

Observations propres (au-delà du WAVE_REPORT GStack):

- **[ADV-B-01 → CONFIRMÉ P2]** Dashboard (b3-10/b4-10): « Ticket Moyen 8,29 € » vs « Chiffre d'Affaires du Jour 82,87 € » / « Commandes du Jour 24 » → 82,87/24 = 3,45 €, PAS 8,29 €. Code: `app/Services/DashboardService.php:427-434` — `average_ticket = daily_sales / daily_paid_orders` (payées SEULEMENT, =10 à ce moment) tandis que `daily_orders` compte TOUT le volume placé (l.417-421, commentaire W-D1 assumé). Chaque chiffre est individuellement défendable MAIS les 3 cartes côte à côte sans qualificatif induisent le gérant en erreur (82,87/24≠8,29). Preuve croisée: le PDF EOD (b4-cloture_jour_2026-06-12.pdf, rendu vérifié par moi via qlmanage — le GStack ne l'avait PAS vérifié) DIVULGUE les bases (« Commandes totales 24 / Commandes payées 10 / Ticket moyen 8,29 € ») — le dashboard, lui, ne divulgue rien. P2 disclose (ajouter « par commande payée » sur la carte).
- **[ADV-B-02 → P1, vérif live en cours]** Modal « Encaisser la commande borne » (b1-04/05-modal, viewport caisse standard 1440×900): le pavé numérique n'affiche que les rangées 1-2-3-⌫ et 4-5-6 ; les touches 7-8-9-0 sont sous le pli, DERRIÈRE le footer sticky. Cause code: le fix G6 d'hier (`PosCounterCollectModal.vue:843-851` — commentaire `[UIUX-W2 G6 2026-06-11]` footer `position:sticky; bottom:0; z-index:1; background:#fff`) a rendu le CTA toujours visible en échange de l'OCCLUSION du bas du numpad (`.cc-modal max-height:92vh; overflow-y:auto` l.628-629). Chemin primaire de saisie du « montant reçu » sur caisse tactile sans clavier. → live re-check ci-dessous.
- **[ADV-B-03 → CONFIRMÉ P1]** Borne b0-03: « Paiement en espèces uniquement à la caisse. » (`resources/js/languages/fr.json:1715`, clé `kiosk.cash_instruction.help`, rendue par `KioskCashInstructionComponent.vue:36`) alors que le modal d'encaissement caisse offre 4 modes: Espèces / Terminal (manuel) / Mobile / Ticket restaurant (b1-04-modal, `PosCounterCollectModal.vue`). Le client borne n'a AUCUN choix de mode (b0-02 « PAIEMENT À LA CAISSE », route_all_to_counter) — lui affirmer « espèces uniquement » est factuellement FAUX (un client sans espèces mais avec TR/carte peut renoncer à commander). Contredit le mandat owner « encaissement unifié » (Espèces/TR/Terminal-manuel).
- **[ADV-B-04 → CONFIRMÉ P3 process]** `b2-08-cash-overview-bas.png` ≡ `b2-07` — MD5 IDENTIQUES (`696568dcb5795b35c8d28a1776305498` les deux). Le « bas de page » du cash-overview n'a JAMAIS été capturé visuellement par le GStack (couvert seulement par _b2-cash-overview-text.txt). Trou de couverture — je comble en live ci-dessous.
- Confirmations visuelles des findings GStack: B-R1-02 (entête « Écart » sur colonne Sens, b1-07), B-R1-03 (« SSOT modal » en notes, b1-07), B-R1-15 (« TXN-fkDxb6PZtOwj · Carte bancaire · −3,80 € » + « TXN-GXdX82uD6hf7 · Carte bancaire · −25,00 € », b3-12), B-R1-16 (b4-09 = page Transactions), B-R1-17 (CLIENT « Admin Le Cayenne » sur commandes borne, b3-03/b4-02 vs « Client Borne » sur Voir b1-09), B-R1-12 (« Imprimer La Facture », b1-09/b2-01), B-R1-13 (« Remboursé » + « Retournée », b2-04/05), B-R1-08 (b1-16 sans récap), B-R1-20 (écart « 0,50 € » sans signe b3-11 ; « Dernier rapport Z #20 · Fermée » b4-08), B-R1-04 (file A0011..A0014 du 10/06 servie d'abord, « Voir plus (48) », badge « À encaisser 52 », b1-06).
- Note artefact: `b1-09-order-4519-show-paid.png` est MAL NOMMÉ — la page montre « À Encaisser », PAS payé (cohérent avec B-R1-04: les commandes du scénario n'ont jamais été encaissées, le caissier a servi les plus anciennes de la file).

## Pass 2 — Technique (re-greps verify-before-report, tous claims GStack re-vérifiés)

| Claim GStack | Re-grep | Verdict |
|---|---|---|
| B-R1-15 slug 'credit' en dur | `app/Services/OrderService.php:2061-2065` + `:2196-2200` (`cashBack($locked,'credit','TXN-…')`) → `PaymentService.php:141-148` persiste `payment_method='credit'` → `TransactionResource.php:51` `'counter_card','card','credit' => 'Carte bancaire'` | **CONFIRMÉ — P0** (grand livre ment sur le mode du refund: espèces affiché « Carte bancaire ») |
| B-R1-04/04b file sans purge + collision A | `routes/api.php:817-854`: PENDING_COUNTER, `orderBy('created_at')` ASC, cap 200, AUCUN filtre date/expiration. Numéros A: `FrontendOrderService.php:1022-1033` scoping `business_date` (reset quotidien). `_b5-api-probe.json`: 2× « A0013 » (id 4332 10/06 7,00 € vs id 4544 12/06 23,00 €) dans la MÊME liste | **CONFIRMÉ — P0** (le run GStack lui-même a encaissé les MAUVAISES commandes: 4328/4329 du 10/06 au lieu de 4516/4519 du jour) |
| B-R1-19 403 transactions BM | `b3-12-transactions.console.txt`: `ERROR 403` + `PAGEERROR AxiosError` non interceptée; `.network.txt`: `HTTP 403 GET /api/admin/setting/payment-gateway?...`; store `resources/js/store/modules/paymentGateway.js:38` | **CONFIRMÉ — P0 silent_error** (protocole cat.6/10: ≥400 sans alerte visible; impact = filtre « Mode de paiement » + erreur JS non gérée à CHAQUE visite du rôle BM) |
| B-R1-06 copy miroir NF525 | Warning modal vu b2-02; voie pre-Z SANS miroir documentée `PosOrderController.php:75-110` (`mode='pre_z'`, `mirror_fiscal_sequence_no=null` confirmé `_b2-api-responses.json`) | **CONFIRMÉ — P1** |
| B-R1-07 brut/net overview | b2-07 + `_b2-cash-overview-text.txt`: GRAND TOTAL 29,80 € inchangé post-refund, Réconciliation -3,80 € sur la même page, aucune mention « hors remboursements » | **CONFIRMÉ — P1** |
| B-R1-16 lien Z → transactions | `LastZReportWidget.vue:24-31` (commentaire W3.5 admet « Router target (transactions list) unchanged ») + b4-09 | **CONFIRMÉ — P1** |
| B-R1-17 client borne = compte machine | `_b5-api-probe.json` `customer_name: "Admin Le Cayenne"` sur 4329 ET 4519 (anonyme du jour) vs « Client Borne » sur show (b1-09) | **CONFIRMÉ — P1** (même fait, 2 surfaces, 2 réponses; fuite d'identité du compte machine comme CLIENT) |
| B-R1-02 entête « Écart » sur colonne Sens | `PosCashDrawerSessionDialog.vue:282` `$t('label.cash_session_variance') \|\| 'Sens'`; `fr.json:626` =« Écart » → fallback mort | **CONFIRMÉ — P2** |
| B-R1-03 « (SSOT modal) » persisté | `PosCounterCollectModal.vue:451,457,460` — 3 chaînes en dur, écrites en DB (notes) et affichées (b1-07) | **CONFIRMÉ — P2** |
| B-R1-05 deux voies tiroir incohérentes | POST 422 `{"error":"no_printer"}` vs paiement espèces « Ouvre le tiroir (simulation) »; toast sans cause (b1-06) | **CONFIRMÉ — P2** (toast présent donc pas silent; incohérence sim + message pauvre) |
| B-R1-01 pas d'UI apport/retrait | grep `addMovement\|paid_in\|paid_out` = 0 hit admin; `CashDrawerService.js` GET-only | **CONFIRMÉ — P2** (gap métier, possible choix V1 → disclose owner) |
| B-R1-08 pas de récap clôture | b1-16 | **CONFIRMÉ — P2** |
| B-R1-09 refund imputé à la session d'un autre caissier | `_b2-cash-overview-text.txt` (session #22 02:39 fond 50 → attendu 46,20) | **CONFIRMÉ — P2** (policy à arbitrer owner) |
| B-R1-10 deux-points collés + « (à venir) » | `_b2-cash-overview-text.txt` l.63-67 | **CONFIRMÉ — P2** (IOU visible en prod + typo FR) |
| B-R1-11 encaissement sans session autorisé | bandeau « 1 encaissement(s) espèces sans session caisse … à régulariser » (b2-07) | **CONFIRMÉ — P2** (garde a-posteriori seulement) |
| B-R1-18 aucune UI admin Z + clés numériques x-report | grep router 0 route z; `routes/api.php:1256-1264` API seule; `_b3-xreport.json` `total_by_method {"1":135.7,"5":8.5}` | **CONFIRMÉ — P2** (le gérant ne peut ni lister ni télécharger les Z archivés) |
| B-R1-12 « Imprimer La Facture » | `PosOrderShowComponent.vue:182` `class="capitalize"` sur trad FR correcte — surface NON-frozen | **CONFIRMÉ — P3** |
| B-R1-13 « Remboursé »/« Retournée » | b2-05 | **CONFIRMÉ — P3** |
| B-R1-14 commentaire vs flip payment_status | J'ai TROUVÉ le mécanisme que le GStack n'avait que supposé: `app/Listeners/PersistOrderPaymentStatusChangedOnRefundCreated.php:98-102` persiste `payment_status=REFUNDED` directement (listener documenté, voie pre-Z), en CONTOURNANT PaymentStateMachine (`PAID=>[]`). Le commentaire `PosOrderController.php:94-103` (« we deliberately do NOT flip ») est PÉRIMÉ vs ce listener. Comportement voulu (doc listener), commentaire faux | **CONFIRMÉ — P3 doc-drift** (le contournement état-machine est délibéré+journalisé; recoupe le P0 connu W1-W3 changePaymentStatus, non recompté) |
| B-R1-20 signe écart + accord « Fermée » | `CashSessionReportListComponent.vue:112` formatMoney sans signe + varianceClass l.270 couleur seule; widget « Dernier Rapport Z … Fermée » (b4-08) | **CONFIRMÉ — P3** |
| Raison d'écart absente du rapport | grep `variance_reason` dans cashSessionReport/ = **0 hit** — la raison saisie obligatoirement à la clôture n'est restituée NULLE PART | **CONFIRMÉ — je le rattache à B-R1-08/20 (P2)** |

## Pass 3 — Chasses propres supplémentaires (au-delà du GStack)

- **[ADV-B-05 — PDF EOD vérifié, GStack ne l'avait pas fait]** `b4-cloture_jour_2026-06-12.pdf` rendu via qlmanage: layout propre, bases divulguées, mention fiscale correcte (« Synthèse non-fiscale… NF525 »). RAS bloquant. Constats mineurs: en-tête « Paris, France — +33600000000 » = placeholder DATA DB e2e (cf. gate G3, non recompté) ; « Top 5 produits vendus » n'affiche que 3 lignes (3 produits distincts seulement ce jour — correct).
- **[SUSPICION INSTRUITE ET REJETÉE — discipline verify-before-report]** TVA PDF 7,83 € sur CA 82,87 € TTC ≠ 10/110 (7,53 €). Enquête DB read-only: l'écart vient de 6 commandes `F1R*` (ids 4525-4530, total 8,44-8,50 €, total_tax 0,82 = TVA d'un 9,00 €) — MAIS ces lignes sont des FIXTURES insérées directement en DB par la vague parallèle F1 (created_at identique à la seconde 02:34:40, serials customs F1R*, `fiscal_sequence_no=NULL` malgré PAID, ZÉRO ligne audit_logs). Le moteur de calcul app n'a jamais produit ces taxes → PAS un bug produit. NE PAS reporter comme P0 TVA.
- **[ADV-B-06 — P3 info]** L'input « MONTANT REÇU » du modal counter-collect affiche la frappe brute `3.8` (point décimal, b1-04-modal) alors que le numpad injecte la virgule (`:decimal-separator="','"`). Cosmétique de cohérence FR.

## Synthèse sévérités (consolidé adversaire — sévérité = la mienne, pas celle du GStack)

| Sév | ID | Titre court |
|---|---|---|
| **P0** | B-R1-15 | Refund espèces livré « Carte bancaire » au grand livre (slug 'credit' en dur ×2) |
| **P0** | B-R1-04+04b | File à-encaisser sans purge + numéros A dupliqués inter-jours → encaissement de la mauvaise commande (prouvé par le run lui-même) |
| **P0** | B-R1-19 | 403 silencieux payment-gateway + AxiosError non gérée sur /admin/transactions (rôle BM) |
| **P1** | ADV-B-02 | Numpad counter-collect occlus par le footer sticky G6 à 1440×900 (rangées 7-8-9-0) — live à confirmer |
| **P1** | ADV-B-03 | Copy borne « espèces uniquement à la caisse » contredit l'encaissement 4 modes |
| **P1** | B-R1-06 | Modal refund promet un miroir NF525 inexistant en pre-Z |
| **P1** | B-R1-07 | Cash-overview brut vs net incohérent post-refund (même page, 2 réalités) |
| **P1** | B-R1-16 | « Voir les clôtures Z » → page Transactions (0 Z) |
| **P1** | B-R1-17 | Historique: CLIENT = « Admin Le Cayenne » (compte machine) sur commandes borne |
| **P2** ×10 | B-R1-01/02/03/05/08(+raison écart)/09/10/11/18, ADV-B-01 | (détail tableaux ci-dessus) |
| **P3** ×6 | B-R1-12/13/14/20, ADV-B-04 (process), ADV-B-06 | (détail ci-dessus) |

## Pass 4 — Re-vérifications LIVE (script `tests/e2e/_d1red-B-caisse-gestion-01.mjs`, 2 captures `red-01`/`red-02`)

### ADV-B-02 → **CONFIRMÉ LIVE — P1**
Viewport 1440×900, modal counter-collect ouvert, mode Espèces. Géométrie mesurée (log `_red1-log.txt`):
footer sticky top=752 ; touches « 7/8/9 » top=743 bottom=799, « 00/0/, » top=807 bottom=863, « C » bottom=863
→ TOUTES DERRIÈRE le footer sticky opaque (`z-index:1; background:#fff`). `modal.scrollHeight=940 vs clientHeight=828`
(scroll max 112px — même scrollé à fond, la dernière rangée affleure à peine le bord du footer).
**Preuve dure: `locator.click()` trial sur la touche « 7 » ÉCHOUE (timeout 3s, le footer intercepte le pointeur), scrollTop reste 0.**
Un caissier tactile ne peut PAS saisir 7/8/9/0/virgule au pavé. Régression directe du fix G6 d'hier. Visuel `red-01-numpad-occlusion.png`.

### ADV-B-04 → gap comblé
`red-02-cash-overview-fullpage.png` (fullPage): le bas de page = table « Répartition par mode ». Pas de défaut de layout
nouveau en bas de page — MAIS la capture a déclenché les découvertes d'intégrité ci-dessous.

### ⭐ ADV-B-07 — **NOUVEAU P0 (intégrité numérique structurelle)** : Vue Caisse Unifiée + grand livre Transactions AVEUGLES aux ventes caisse directes
- Constat live 11:42: carte BORNE passée à 14,80 € / 3 tx (le +10,00 € de 02:44 est entré) mais carte **CAISSE figée à 25,00 € / 1 tx**
  alors que 4 VRAIES ventes caisse espèces du jour existent: 4520 (9,00 € 02:31), 4522 (4,50 € 02:33), 4543 (4,80 € 02:58), 4552 (1,50 € 03:08)
  — toutes `payment_status=5`, **fiscal_sequence_no alloués (2167/2168/2173/2174)**, audit NF525 `order.created.pos` présents,
  mouvements tiroir enregistrés (cash_movements 220/221/227/228). **AUCUNE n'a de ligne dans `transactions`.**
- Cause STRUCTURELLE (code): les lignes `type='payment'` ne naissent QUE dans `PaymentService::payment()`
  (`app/Services/PaymentService.php:55-62`) qui est **gateway-gated** (`assertGatewayContext()` — uniquement les callbacks
  Stripe/Credit/PayPal) + dans le confirm counter-collect (COUNTER-*). La vente POS directe n'a AUCUN writer de Transaction.
- Preuve historique DB (read-only): **10/06 = 42 ventes POS payées, 1 seule ligne `transactions`** pour une commande pos.
- Impact: `/admin/transactions` (« Transactions », 750 lignes) et les cartes de « Vue Caisse Unifiée »
  (`CashOverviewController.php:111-118` requête `Transaction::where('type','payment')`) présentent au gérant un grand livre
  PARTIEL comme s'il était total: le même fait « encaissements caisse du jour » vaut 25,00 € (overview, 1 tx fixture),
  ~19,80 € de ventes réelles invisibles, tandis que le dashboard CA (realizedRevenue sur orders) les compte. Catégorie
  protocole #11 (même fait, N surfaces, N réponses) → **P0**.
- (La seule tx « CAISSE » affichée, F2-PAY-4515 25,00 €, est une ligne de la vague parallèle F2 — serial custom `F2-RDM-4`,
  source NULL, fiscal NULL: fixture, pas un flux app.)

### ADV-B-08 — **NOUVEAU P1**: champ `source` (canal) contrôlé par le CLIENT à la création POS → canal EOD faux
- `app/Http/Requests/PosOrderRequest.php:114` — `'source' => ['required','numeric']` : aucune whitelist enum, jamais forcé
  serveur à `Source::POS=15` (seul `source_surface='pos'` est forcé, `OrderService.php:1098`).
- Preuve empirique: commande **4520** (audit `order.created.pos`, source_surface='pos', fiscal 2167) persiste **source=1 (Web)**
  → le PDF Clôture du jour (b4) la range dans « **Web/App** » (59,87 € = 50,87 fixtures F1R + **9,00 € de la vente caisse 4520**) ;
  « POS Caisse 2 / 13,00 € » au lieu de 3 / 22,00 €. Répartition par canal d'une synthèse de clôture faussée par un payload client.
- Sévérité P1 (synthèse étiquetée « non-fiscale » — sinon P0) + surface d'abus (canal falsifiable par requête).

### ADV-B-09 — **NOUVEAU P2**: sessions caisse d'avant-hier toujours OUVERTES, encaissant les espèces d'aujourd'hui
- DB: sessions #19 et #20 (ouvertes 10/06 19:53 / 22:46, visibles « Ouverte » dans b3-11 sous « mercredi 10 juin ») reçoivent les
  mouvements du 12/06 (+10,00 02:44 → session 19 ; +4,80 02:58 et +1,50 03:08 → sessions 19/20) car l'attribution est
  par-utilisateur (`CashDrawerService.php:474-481 findOpenSessionForUser`) et AUCUN garde-fou ne ferme/alerte une session
  qui traverse la clôture Z / le jour fiscal.
- Conséquence visible: le bloc « Réconciliation caisse » de la Vue Unifiée ne lit QUE la session du caissier connecté
  (« Espèces attendues au tiroir: 46,20 € ») pendant que +16,30 € d'espèces du jour sont imputés aux sessions zombies —
  sur la borne V1 il n'y a qu'UN tiroir physique. Confondu par le harnais (cron de clôture absent en e2e) → P2 disclose,
  pas P0 (recoupe B-R1-07/09).

## Pass 5 — DISPUTES du FINAL_REPORT 2026-06-11 (`reports/test-e2e/uiux-caisse-borne-2026-06-11/FINAL_REPORT.md`)

| # | Claim FINAL_REPORT | Verdict | Preuve |
|---|---|---|---|
| D1 | « VERDICT ✅ CONVERGED — production-perfect » + « Cycle 2 : P0=0 · P1=0 · P2=0 — aucun nouveau finding » | **REFUTED** (périmètre caisse-gestion) | 24h plus tard, même branche: 4 P0 / 7 P1 sur les surfaces caisse — dont cash-overview (page DANS leur scope de heal, A-5 y a été healé) qui porte brut/net incohérent (B-R1-07) ET cécité structurelle de la carte CAISSE (ADV-B-07); /admin/transactions: 403+PAGEERROR à CHAQUE visite BM (B-R1-19); entête de colonne fausse « Écart » (B-R1-02); « Imprimer La Facture » non-frozen (B-R1-12). La « convergence » n'a jamais poussé un refund jusqu'au grand livre ni comparé les surfaces entre elles. |
| D2 | W2 « CTA encaissement sticky <900px » (fix G6) | **WEAKENED** | Le fix rend le CTA visible mais le footer sticky opaque OCCLUT désormais les rangées 7-8-9/0/« , » du numpad à 1440×900 — click trial « 7 » ÉCHOUE (geo + red-01-numpad-occlusion.png). Problème déplacé, pas résolu. |
| D3 | W2 « Client borne » (heal libellé client) | **WEAKENED** | Healé sur la page Voir uniquement; Historique liste + API order-history affichent toujours « Admin Le Cayenne » (compte machine) comme CLIENT des commandes borne, y compris une commande anonyme du jour (B-R1-17, `_b5-api-probe.json`). |
| D4 | W5 « cross-flow prouvé: commandes borne créées → visibles et encaissées côté caisse, file décrémentée » | **WEAKENED** | Happy-path vrai mais non-représentatif: la file réelle sert D'ABORD 48+ commandes périmées du 10/06, numéros A dupliqués inter-jours (2× « A0013 »); le run GStack B a lui-même encaissé les MAUVAISES commandes (4328/4329 au lieu de 4516/4519) — B-R1-04/04b. |
| D5 | W6 micro-heal A-5 « libellé (session en cours) » | **UPHELD** | Libellé correct en contexte session ouverte (b2-07/red-02, session #22 réellement ouverte). |
| D6 | W6 micro-heal B-1 « statut paiement borne sur show » | **UPHELD** | Badges Payé/Espèces corrects sur show 4329 pré-refund (b2-01); À Encaisser correct sur 4519 (b1-09). |

## VERDICT FINAL — Vague B Round 1: **RED**

| Sév | Count | IDs |
|---|---|---|
| **P0** | **4** | B-R1-15 (refund espèces = « Carte bancaire » au ledger) · B-R1-04+04b (file sans purge + A-collision → mauvaise commande encaissée) · B-R1-19 (403 silencieux transactions BM) · **ADV-B-07** (ledger/overview aveugles aux ventes caisse directes) |
| **P1** | **7** | ADV-B-02 (numpad occlus, confirmé live) · ADV-B-03 (copy borne « espèces uniquement » fausse) · B-R1-06 (copy miroir NF525 mensongère) · B-R1-07 (brut/net même page) · B-R1-16 (lien Z → transactions) · B-R1-17 (client = compte machine) · **ADV-B-08** (canal `source` client-controlled, EOD faussé) |
| **P2** | **11** | B-R1-01 · B-R1-02 · B-R1-03 · B-R1-05 · B-R1-08 (+raison d'écart restituée nulle part) · B-R1-09 · B-R1-10 · B-R1-11 · B-R1-18 · ADV-B-01 (Ticket Moyen sans qualificatif) · ADV-B-09 (sessions zombies inter-jours) |
| **P3** | **6** | B-R1-12 · B-R1-13 · B-R1-14 (mécanisme trouvé: listener `PersistOrderPaymentStatusChangedOnRefundCreated.php:98-102`) · B-R1-20 · ADV-B-04 (b2-08≡b2-07, trou de couverture GStack) · ADV-B-06 (« 3.8 » point décimal input) |

Suspicions instruites et REJETÉES (verify-before-report): TVA PDF 7,83 € (fixtures F1R* hors-app) · attribution mouvements
multi-sessions (per-user par design) · exclusion de bord export date (déjà réfutée empiriquement par le GStack, confirmé).
Sévérité GStack non contestée à la baisse sauf: B-R1-02 (P2, l'entête fausse n'altère pas la lecture des cellules),
B-R1-05 (P2, toast présent donc non-silencieux), B-R1-14 (P3, comportement délibéré documenté côté listener).

