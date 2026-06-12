# VAGUE B — CAISSE gestion & clôture PROFOND (mutations réelles)
Dispute round-1 — 2026-06-12 — App :8768 / DB foodking_e2e jetable — compte bm.t2admin@lecayenne.fr
Agent: GSTACK MAIN TEAM (Architect+Tester+A11y+SRE). CAPTURE+OBSERVE — sévérité = adversaire.

## Statut: TERMINÉ — 6/6 tâches couvertes, 21 anomalies suspectées (B-R1-01 → B-R1-20, dont 04b)
Tête d'affiche: **B-R1-15** (refund espèces livré « Carte bancaire » au grand
livre, slug 'credit' en dur OrderService.php:2061/2197), **B-R1-04/04b** (file
borne sans purge + numéros A-dupliqués inter-jours → encaissement de la
mauvaise commande), **B-R1-06** (copy modal promet un miroir NF525 inexistant
en pre-Z), **B-R1-07** (cash-overview brut vs net incohérent post-refund),
**B-R1-16** (« Voir les clôtures Z » → page Transactions).

Scripts jetables: `tests/e2e/_d1-B-lib.mjs` (helper quartet), `_d1-B-borne-orders.mjs`,
`_d1-B-caisse-session.mjs`, `_d1-B-refund.mjs`, `_d1-B-reports.mjs`,
`_d1-B-histo-deep.mjs`, `_d1-B-api-probe.mjs`.
Contexte partagé: vagues parallèles actives sur la même DB (sessions caisse #22 02:39,
encaissement 25,00 € hors-session 02:38, refund 25,00 € F2-RDM-4 = PAS de cette vague).

## Recon code statique (verify-before-report, greps confirmés)

- Dialog session caisse: `resources/js/components/admin/cash/PosCashDrawerSessionDialog.vue` (876 l.)
  — modes `open/active/close/movements`, testids `cash-session-*`, écart live
  `data-testid="cash-session-close-variance"`, raison obligatoire si écart
  (`varianceRequiresReason` → `cash-session-reason-input`).
- Bouton header POS: `data-testid="pos-cash-session-open"` (PosComponent.vue:243).
- No-sale (tiroir hors-vente): `data-testid="pos-no-sale"` (PosComponent.vue:228) —
  c'est un **open-drawer audit** (`triggerNoSaleOpenDrawer`, PosComponent.vue:3723),
  PAS un formulaire entrée/sortie d'espèces.
- **OBSERVATION B-R1-01 (UI manquante?)**: aucune UI de mouvement manuel
  entrée/sortie d'espèces (paid-in/paid-out) trouvée dans le POS — le mode
  `movements` du dialog est une table **lecture seule** (grep `addMovement|paid_in|paid_out`
  = 0 hit dans resources/js/components/admin). Le service
  `resources/js/services/CashDrawerService.js` n'expose que GET movements
  (l.177-179) + computeExpected (l.193). À confronter au besoin métier
  (apport/retrait de fond en cours de session impossible depuis l'UI).
- Refund: bouton `data-testid="pos-order-refund-open"`
  (PosOrderShowComponent.vue:202, permission `pos-refund`), modal
  `PosRefundModal.vue` (testids `pos-refund-modal-*`).
- Z-report: API admin `routes/api.php:1256-1264` (`/api/admin/fiscal/z-report` index/open/close/show/pdf,
  throttle 10/min sur open/close). **Côté frontend admin: AUCUNE page dédiée Z**
  — seul `resources/js/components/admin/dashboard/LastZReportWidget.vue` consomme
  `z-report` (grep router/modules = 0 route z). Pas de bouton « Clôturer la
  journée » trouvé dans le frontend admin (observation, lecture seule, aucun POST open/close déclenché).
- Historique: `resources/js/components/admin/orderHistory/HistoriqueListComponent.vue`
  — `ExportComponent` (l.11) + export XLSX store `orderHistory/export` (l.512).

## États capturés (quartet PNG+DOM+console+network)

### b0 — Borne: 2 commandes comptoir créées (préparation file POS)
- `b0-01-cart` panier Glace 3,80 € (image OK, totaux FR) · `b0-02-payment` ·
  `b0-03-cash-instruction` « Rendez-vous en caisse » #A0001 3,80 €.
- Commandes créées: id=4516 (#A0001, 3,80 €), id=4519 (#A0003, 3,80 €) —
  `_b0-borne-orders.json` (POST 201 ×2).

### b1 — Session caisse, encaissements, no-sale, mouvements, clôture
- `b1-00-pos-landing` · `b1-01-session-dialog-initial` (dialog AUTO-OUVERT car
  aucune session — fond pré-rempli 50,00 €) · `b1-02-session-open-form` (fond
  saisi 100,00 €) · `b1-03-session-active` (FOND 100,00 € / OUVERTE 12/06 02:35 /
  MOUVEMENTS 0 / ATTENDU 100,00 €).
- API open: POST 201 `/api/admin/pos/cash-drawer/sessions/open` → session id=21,
  branch 1, opening_amount=100.
- Encaissement borne ×2 — ATTENTION ordres servis = les PLUS ANCIENS de la file
  (A0009 id=4328 1,00 € + A0010 id=4329 3,80 €, sérials 1006264328/29 = créés
  10/06), PAS mes 4516/4519 du jour (voir B-R1-04). `b1-04-encaisse-4516-modal`
  (modal « Encaisser la commande borne », total 1,00 €, reçu 3.8 → « Monnaie à
  rendre 2,80 € » correct) · `b1-05-encaisse-4519-modal` (3,80 €, reçu 5,00 →
  rendu 1,20 € correct) + `-after` ×2. API confirm 200 ×2
  (`counter-collect/4328/confirm`, `counter-collect/4329/confirm`).
- `b1-06-no-sale`: clic « Ouvrir tiroir » → POST 422
  `/api/admin/pos/cash-drawer/open` `{"success":false,"error":"no_printer"}` →
  toast « Impossible d'ouvrir le tiroir. » (voir B-R1-05).
- `b1-07-movements`: table 2 mouvements « Paiement de commande » 1,00 € + 3,80 €
  ↑ Entrée, notes « Encaissement borne au comptoir (SSOT modal) » (B-R1-03) ;
  entête de colonne « Écart » sur la colonne SENS (B-R1-02).
- `b1-08-session-active-stats`: ATTENDU 104,80 € / MOUVEMENTS 2 —
  arithmétique correcte (100 + 1,00 + 3,80).
- Clôture: `b1-14-close-variance-zero` (compté 104,80 → ÉCART 0,00 €) ·
  `b1-15-close-variance-050` (compté 105,30 → ÉCART +0,50 € vert, champ
  « Raison de l'écart * » apparu, requis) · `b1-16-close-submitted`.
- API close: POST 200 close (closing_amount=105.3) puis POST 200 reconcile →
  expected_closing_amount=104.8, variance=0.5, variance_reason persistée. ✓
- Après clôture: le dialog disparaît sans récapitulatif (B-R1-08).

### b2 — Refund 4329 + miroir + cash-overview
- `b2-01-order-4329-paid` (badges Payé, Espèces) · `b2-02-refund-modal-vide`
  (confirm DISABLED sans raison ✓; warning « Cette action génère une commande
  miroir NF525 et un ticket de remboursement. L'opération est irréversible. ») ·
  raison 4 chars → confirm toujours DISABLED ✓ (min 5) ·
  `b2-03-refund-modal-rempli` · `b2-04-refund-confirmé` ·
  `b2-05-order-4329-apres-refund` (badge « Remboursé », AUCUN montant négatif,
  AUCUN lien miroir) · `_b2-api-responses.json`.
- API: POST 200 `/api/admin/pos-order/4329/refund-with-counter-entry` →
  `mode="pre_z"`, `meta.mirror_fiscal_sequence_no=null`, parent
  payment_status=20 (reste PAYÉ), status=22 (RETURNED). PAS de commande miroir
  (voie pre-Z documentée PosOrderController.php:75-110) → voir B-R1-06.
- `b2-07/08-cash-overview`: GRAND TOTAL 29,80 € (3 tx) = CAISSE 25,00 € (1 tx)
  + BORNE 4,80 € (2 tx) ; Réconciliation: « Caisse ouverte à:02:39 » (session
  parallèle d'une autre vague, fond 50,00 €), « Espèces encaissées (session en
  cours): -3,80 € », « Espèces attendues au tiroir: 46,20 € » ; bandeau
  « 1 encaissement(s) espèces sans session caisse … (à régulariser) » ;
  mention « saisir le comptage physique du tiroir (à venir) » → B-R1-07/09/10.

### b3 — Rapports (historique initial, Z lecture seule, sessions, transactions)
- `b3-01-historique-initial`: 2409 entrées, 10/page, pagination 1..241, colonnes
  N° commande / Origine / N° file / Client / Montant / Paiement / N° fiscal /
  Date / Statut / Action.
- `b3-10-dashboard-widget-z` + `_b3-zreport-index.json`: « Dernier Rapport Z #20 ·
  Fermée · 10/06/2026 07:06:45 ». GET index Z = 200 (dernier Z id=22 seq 20,
  clos 10/06, totaux 0 / 0 commandes). GET x-report 200: période 10/06 07:06 →
  12/06 02:47, total_ttc=144.2, ht=131.12, tva=13.08, order_count=17,
  refund_count=1 ✓ (mon refund), total_by_method à CLÉS NUMÉRIQUES {"1":135.7,
  "5":8.5} (`_b3-xreport.json`). AUCUN POST open/close déclenché (lecture seule).
- Constat Z: aucune page admin dédiée aux Z (grep router 0 route) ; seuls le
  widget « Dernier Z » + l'API index/show/pdf existent. Aucun bouton « Clôturer
  la journée » côté frontend (la clôture Z est cron/API). Voir B-R1-16/18.
- `b3-11-cash-sessions-report` + `_b3-cash-sessions-report.txt`: vendredi 12 juin —
  Sessions 2, Transactions 3, Total ouverture 150,00 € (=100+50 ✓), Total
  clôture 105,30 € (✓ seule #21 close). #21: 02:35→02:35, fond 100,00 €,
  final 105,30 €, Écart « 0,50 € » (ambre, SANS signe +), 2 transactions,
  Réconciliée ✓. #22: 02:39, 50,00 €, Ouverte, 1 transaction (le refund OUT).
  La raison d'écart persistée n'apparaît NULLE PART dans le rapport.
- `b3-12-transactions` + `_b3-transactions.txt`: 750 entrées. Lignes clés:
  « COUNTER-4329-… 02:35 Espèces 1006264329 +3,80 € » ✓,
  « COUNTER-4328-… 02:35 Espèces 1006264328 +1,00 € » ✓,
  « TXN-fkDxb6PZtOwj 02:41 **Carte bancaire** 1006264329 **−3,80 €** » ✗ B-R1-15.

### b4 — Historique profond + Z widget + EOD PDF
- `b4-01-historique-page2`: pagination OK (1re ligne 1206264537 → F1R8451096).
- `b4-02-historique-recherche`: recherche N° 1006264329 → 1 ligne exacte
  (Borne, N°A0010, 3,80 €, badge Remboursé, N° fiscal 2170, Statut Retournée)
  + chip « N° Commande : 1006264329 » + Effacer. ✓
- `b4-03/04-datepicker`: vue-datepicker FR, presets « Aujourd'hui / Ce mois /
  Mois dernier / Cette année », plage saisie « 10/06/2026 - 12/06/2026 » ✓.
- `b4-05`: plage appliquée → 177 entrées.
- `b4-06/07` + `b4-export-Historique.xlsx` (12 202 octets, 178 lignes): export
  XLS OK, **inclut** 1006264329 + commandes 01:06-01:42 du 10/06 → la borne
  from_date=…T00:55Z de l'URL est castée DATE côté serveur (suspicion
  d'exclusion de bord RÉFUTÉE empiriquement — pattern `date('Y-m-d',…)`
  TransactionService.php:50 idem).
- `b4-08/09`: « Voir les clôtures Z » → atterrit sur **/admin/transactions**
  (aucun Z listé) — B-R1-16.
- `b4-10` + `b4-cloture_jour_2026-06-12.pdf` (1 281 958 octets): « PDF Clôture
  du jour » POST eod-pdf 200 application/pdf, téléchargé (rendu non vérifié
  localement — poppler absent; artefact conservé pour l'adversaire).

### b5 — Sondes API lecture seule (`_b5-api-probe.json`)
- order-history 4329 ET 4519 (créée anonymement à la borne ce jour): client =
  « Admin Le Cayenne », source kiosk → B-R1-17.
- counter-collect/pending: 58 en attente, tri created_at ASC
  (routes/api.php:817-854, cap 200, AUCUNE expiration), plus ancien = 10/06 01:46 ;
  **collision de numéros de file entre jours**: id 4332 (10/06, 7,00 €) et
  id 4544 (12/06, 23,00 €) portent TOUS DEUX « A0013 » → B-R1-04b.

## Hygiène console/réseau (cumulée dans les quartets .console.txt/.network.txt)
- WebSocket ws://127.0.0.1:6001 failed sur chaque page admin (soketi non lancé
  dans ce harnais e2e — environnemental, à confirmer en prod-like).
- POST 422 cash-drawer/open (no-sale, B-R1-05) — seul ≥400 du flux caisse.
- **403 GET /api/admin/setting/payment-gateway sur /admin/transactions** pour
  le Branch Manager (+ PAGEERROR AxiosError non interceptée) — la page charge
  mais le filtre « Mode de paiement » dépend d'un endpoint interdit au rôle →
  B-R1-19. Evidence `b3-12-transactions.console.txt` / `.network.txt`.
- 401 one-shot /api/login au boot kiosk (b0) = gate connue, non recomptée.
- 401 ponctuels mi-parcours b2 = churn de token Sanctum dû aux re-logins des
  vagues parallèles sur le même compte (environnemental, documenté).

## Intégrité numérique relevée (chiffre par chiffre)

| Étape | Valeur UI | Attendu calculé | Verdict |
|---|---|---|---|
| Fond saisi | 100,00 € | 100,00 € | ✓ |
| Rendu monnaie 4328 (total 1,00, reçu 3,80) | 2,80 € | 2,80 € | ✓ |
| Rendu monnaie 4329 (total 3,80, reçu 5,00) | 1,20 € | 1,20 € | ✓ |
| Montant attendu après 2 encaissements | 104,80 € | 100+1,00+3,80=104,80 | ✓ |
| Écart comptage exact 104,80 | 0,00 € | 0 | ✓ |
| Écart comptage 105,30 | +0,50 € | +0,50 | ✓ |
| Reconcile serveur | expected=104.8 variance=0.5 | idem | ✓ |
| Overview BORNE | 4,80 € / 2 tx | 1,00+3,80 | ✓ (brut) |
| Overview GRAND TOTAL après refund 3,80 | 29,80 € (inchangé) | net devrait refléter −3,80 ? | ⚠ B-R1-07 |
| Overview « Espèces encaissées (session en cours) » | −3,80 € | refund sorti du tiroir courant | ✓ mécanique, ⚠ attribution (B-R1-09) |

## Anomalies suspectées (evidence jointe — sévérité = adversaire)

- **B-R1-01** — Pas d'UI d'entrée/sortie d'espèces hors-vente (apport/retrait) ;
  le « no-sale » n'est qu'une ouverture tiroir auditée. Evidence: greps §recon.
  (Possible choix V1 assumé.)
- **B-R1-02** — Table « Mouvements de caisse »: l'entête de la colonne sens
  affiche « Écart » alors que les cellules affichent « ↑ Entrée / ↓ Sortie ».
  Code: `PosCashDrawerSessionDialog.vue:281` —
  `{{ $t('label.cash_session_variance') || 'Sens' }}` ; la clé résout « Écart »
  (resources/js/languages/fr.json label.cash_session_variance='Écart' ;
  lang/fr/all.php:161 idem) donc le fallback 'Sens' ne s'applique jamais.
  Visuel: `b1-07-movements.png`.
- **B-R1-03** — Jargon développeur persisté côté métier: notes de mouvement
  « Encaissement borne au comptoir (SSOT modal) » — chaîne en dur
  `PosCounterCollectModal.vue:451` (`return 'Encaissement borne au comptoir
  (SSOT modal)';`), non i18n, écrite en DB (cash_movements.notes) et affichée
  au caissier (`b1-07-movements.png`).
- **B-R1-04** — File « À encaisser borne »: 52 commandes en attente dont des
  commandes du 10/06 (sérials 1006264328/29) servies EN PREMIER ; les clients
  du jour (#A0001/#A0003 de 02:30) sont enfouis derrière ~48 entrées
  périmées (« Voir plus (48) → »). Aucune expiration/purge des commandes borne
  « comptoir différé » abandonnées (l'écran borne rend la main après 45 s mais
  la commande reste en file indéfiniment). Visuel: `b1-06-no-sale.png` (panel
  N°A0011→A0014 du 10/06), badge « À encaisser 52 ». Impact opérateur: le
  caissier ne retrouve pas la commande du client présent sans cliquer
  « Voir plus ».
- **B-R1-05** — Incohérence simulation matériel: le paiement Espèces affiche
  « Ouvre le tiroir (simulation) » et réussit sans imprimante, mais le bouton
  dédié « Ouvrir tiroir » (no-sale) échoue POST 422 `{"error":"no_printer"}` →
  toast « Impossible d'ouvrir le tiroir. » (`b1-06-no-sale.png`). Deux voies
  tiroir, deux comportements. (Et le message n'explique pas la cause.)
- **B-R1-06** — Copy du modal refund contredit la voie pre-Z: le warning
  (`PosRefundModal.vue` testid `pos-refund-modal-warning`) affirme « Cette
  action génère une commande miroir NF525 et un ticket de remboursement »
  alors que la voie pre-Z (cas nominal du jour-même, `PosOrderController.php:241`
  mode='pre_z', mirror=null — design documenté l.75-110) NE crée PAS de miroir.
  Constat post-refund: badge « Remboursé » sur le parent, aucun lien miroir,
  aucun montant négatif (`b2-05`). Le caissier est informé d'un artefact
  fiscal qui n'existera pas.
- **B-R1-07** — /admin/cash-overview: après le refund 3,80 €, les cartes
  sources affichent toujours BORNE 4,80 € / 2 tx et GRAND TOTAL 29,80 € (3 tx)
  (aucune ligne négative, aucun retrait), tandis que le bloc Réconciliation
  compte « Espèces encaissées (session en cours): -3,80 € ». Deux totaux de la
  même page racontent deux réalités (brut vs net) sans mention « hors
  remboursements ». Visuel `b2-07-cash-overview.png` + texte
  `_b2-cash-overview-text.txt`.
- **B-R1-08** — Clôture caisse: après « Valider la clôture », le dialog
  disparaît sans écran récapitulatif (pas de rappel écart +0,50 €/raison, pas
  d'impression/ticket de session) (`b1-16-close-submitted.png`). À confronter
  à la politique design.
- **B-R1-09** — Attribution du refund espèces: l'encaissement d'origine était
  dans la session 21 (fermée), la sortie -3,80 € est imputée à la session
  OUVERTE d'un autre caissier (02:39, fond 50 → attendu 46,20 €). Physiquement
  cohérent (l'argent sort du tiroir actif) mais l'écart du caissier en poste
  porte le remboursement d'une vente qu'il n'a pas encaissée — à confronter
  aux règles métier. Evidence: `_b2-cash-overview-text.txt` + b1 API session 21.
- **B-R1-10** — cash-overview, micro-copy: « Caisse ouverte à:02:39 » /
  « Fond de caisse:50,00 € » (deux-points collés, typo FR exige une espace) ;
  « Pour calculer l'écart, saisir le comptage physique du tiroir (à venir). »
  = fonctionnalité-IOU visible en prod. Texte brut `_b2-cash-overview-text.txt`
  (PNG à l'appui b2-07).
- **B-R1-11** — Le POS autorise un encaissement espèces SANS session caisse
  ouverte (constat indirect: bandeau overview « 1 encaissement(s) espèces sans
  session caisse — à régulariser », généré par une vague parallèle entre ma
  clôture 02:36 et l'ouverture 02:39). Garde a-posteriori seulement, pas de
  blocage à l'encaissement. (Design assumé? → adversaire.)
- **B-R1-12** — Title Case CSS sur la page Commandes Caisse / Voir: « Imprimer
  La Facture » — la trad FR est correcte (« Imprimer la facture »,
  resources/js/languages/fr.json button.print_invoice) mais
  `PosOrderShowComponent.vue:182` applique `class="capitalize"`. Surface
  NON-frozen, distincte de l'arbitrage « Title Case PaymentComponent frozen ».
  Visuel `b1-09-order-4519-show-paid.png`.
- **B-R1-13** — Accord de genre incohérent sur les badges jumeaux de la
  commande remboursée: « Remboursé » (masc.) à côté de « Retournée » (fém.)
  pour la même entité commande (`b2-05-order-4329-apres-refund.png`).
- **B-R1-14** — Dérive commentaire-vs-comportement: `PosOrderController.php:94-103`
  affirme « we deliberately do NOT flip the parent's payment_status to
  REFUNDED … PAID => [] » or la réponse API du refund pre-Z renvoie
  `data.payment_status=20` = `PaymentStatus::REFUNDED`
  (app/Enums/PaymentStatus.php:10) et la page Voir affiche le badge
  « Remboursé ». Le flip a donc bien lieu quelque part (probable voie directe
  hors PaymentStateMachine — à relier au P0 connu W1-W3 « changePaymentStatus
  hors séquence fiscale »). Evidence: `_b2-api-responses.json` + `b2-05`.
- **B-R1-15** ⭐ — Remboursement ESPÈCES comptabilisé « Carte bancaire » dans
  le grand livre /admin/transactions: ligne « TXN-fkDxb6PZtOwj · 02:41,
  12-06-2026 · Carte bancaire · 1006264329 · − 3,80 € » alors que la commande
  a été encaissée ET remboursée en espèces (show: « Type de paiement:
  Espèces » ; réconciliation overview: −3,80 € espèces). Chaîne causale
  vérifiée: `OrderService.php:2061` et `:2197` appellent
  `cashBack($locked, 'credit', 'TXN-…')` avec le slug EN DUR `'credit'`
  quel que soit le moyen de paiement réel → `PaymentService.php:139` persiste
  `payment_method='credit'` → `TransactionResource.php:51` mappe
  `'credit' => 'Carte bancaire'`. Même motif sur le refund parallèle 25,00 €
  espèces (« F2-RDM-4 · Carte bancaire · −25,00 € »). Le tiroir (cash
  movement OUT) est correct (cashSettledPortion), mais le LEDGER transactions
  ment sur le moyen de paiement — répartition par mode faussée (espèces
  sous-évaluées, carte sur-évaluée en négatif). Visuel `b3-12-transactions.png`.
- **B-R1-04b** — Collision des numéros de file entre jours dans la file
  « À encaisser »: deux commandes distinctes portent « A0013 » (id 4332 du
  10/06 à 7,00 € vs id 4544 du 12/06 à 23,00 €) dans la MÊME liste pending
  (`_b5-api-probe.json`). Le client annonce « A0013 » → le caissier peut
  encaisser la mauvaise commande (c'est exactement ce qui est arrivé à mon
  scénario: mes 4516/4519 jamais servis, fallback sur les plus anciennes).
  Aggrave B-R1-04 (numéros reset quotidien + file sans purge).
- **B-R1-16** — Lien « Voir les clôtures Z » (dashboard, widget Dernier Z,
  `LastZReportWidget.vue:28-31` testid last-z-report-link) route vers
  `admin.transactions.list` → /admin/transactions, page qui ne liste AUCUN
  rapport Z (`b4-09-voir-clotures-z-destination.png`). Le commentaire W3.5
  l'admet: « Router target (transactions list) unchanged » alors que la copy a
  été changée pour promettre les clôtures fiscales.
- **B-R1-17** — Attribution client borne incohérente: la liste Historique (et
  l'API order-history) affiche « Admin Le Cayenne » comme CLIENT des commandes
  borne (y compris 4519 créée anonymement ce jour via l'UI kiosk:
  `_b5-api-probe.json`), alors que la page Voir affiche « Client Borne »
  (`b1-09`) / « Client passage ». Le compte machine de la borne fuit comme
  identité client dans l'historique (`b4-02-historique-recherche.png` colonne
  CLIENT).
- **B-R1-18** — Aucune UI admin pour consulter les rapports Z (index/show/pdf
  API existent routes/api.php:1256-1264 mais aucune route frontend; seul le
  dernier Z apparaît en widget). Le gérant ne peut ni lister ni télécharger
  les PDF Z archivés depuis l'interface. (Le « PDF Clôture du jour » eod-pdf
  est un rapport du jour, pas l'archive Z.) + X-report API
  `total_by_method` à clés numériques {"1","5"} non mappées
  (`_b3-xreport.json`) — si une UI consomme ça telle quelle, labels illisibles.
- **B-R1-19** — /admin/transactions en Branch Manager: GET
  /api/admin/setting/payment-gateway → 403 + PAGEERROR AxiosError à chaque
  visite (quartet `b3-12-transactions.console.txt`) ; le filtre « Mode de
  paiement » s'alimente sur cet endpoint → options potentiellement vides pour
  le rôle BM, erreur console non gérée.
- **B-R1-20 (mineur)** — Signe de l'écart incohérent entre surfaces: le dialog
  de clôture affiche « +0,50 € » (formatVariance) mais le Rapport Caisses
  Quotidien affiche « 0,50 € » sans signe (CashSessionReportListComponent.vue:112
  formatMoney; couleur seule distingue excédent/manque, varianceClass l.270).
  Et « Dernier rapport Z #20 … Fermée » (accord fém. sur « rapport » masc.,
  réutilisation cash_status_closed).

## Gates/P3 connus REVUS (non recomptés, listés à part)
- Dates listes « 01:42, 10-06-2026 » à tirets — revu sur historique/transactions, inchangé.
- « Accepter » infinitif badge statut — revu sur `b1-09`, inchangé.
- 401 one-shot boot kiosk — revu (b0), inchangé.
- Images cassées Frites Seules / Boisson Seule (DATA DB) — revues sur grille POS
  (`b1-00`, `b1-06`), inchangées.
- Tutoiement / « (session en cours) » cash-overview: le libellé « (session en
  cours) » est CORRECT ici (session #22 réellement ouverte au moment de la
  capture) — comportement validé, pas d'anomalie nouvelle.

## Couverture des 6 tâches de la vague
1. Session caisse (fond, 2 encaissements espèces borne, no-sale, clôture
   comptage, écart) — FAIT, écart calculé correct, UI claire (B-R1-08 récap absent).
2. /admin/cash-overview après mutations — FAIT (réconciliation arithmétiquement
   cohérente mais brut/net incohérent B-R1-07; « (session en cours) » correct).
3. Remboursement bout-en-bout — FAIT sur 4329 (modal → raison min 5 →
   confirmation → badge Remboursé/Retournée; miroir: INEXISTANT en pre-Z par
   design, copy modal trompeuse B-R1-06; aucun montant négatif sur le père).
4. /admin/historique — FAIT (datepicker plage réelle 10→12/06 = 177 entrées,
   recherche N° exacte, pagination p.2, export XLSX téléchargé et inspecté).
5. Z-report — FAIT lecture seule (pas de page admin; widget + API GET index/x;
   AUCUNE clôture déclenchée; eod-pdf généré = rapport, pas une clôture).
6. /admin/cash-sessions-report + /admin/transactions — FAIT (session #21
   cohérente 100→105,30 écart 0,50; transactions: refund espèces étiqueté
   « Carte bancaire » B-R1-15).
