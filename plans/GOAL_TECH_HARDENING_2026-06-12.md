# GOAL — TECH HARDENING post-dispute (P2/P3 + angles morts + gates)
— 2026-06-12 · planificateur technique (phase max-planification du GOAL dispute superviseur) · **PREPARE-ONLY, aucune exécution**
— Sources : `reports/test-e2e/dispute-2026-06-12/round-2/META_CONVERGENCE.md` · `round-1/meta/META_DISPUTE.md` · 6 ADVERSARIAL_VERDICT R2 · CONSTITUTION.md · PROJECT_BRAIN §1-2
— Toutes les ancres file:line ci-dessous **re-greppées ce jour** sur ce worktree (HEAD `fb6d55c3c`, branche `heal/cms-pr1-quickwins-2026-05-18` du worktree release-v1).

> **DÉDUP R3** : `HEAL_R3.md` n'existe pas encore (healer en vol). Les 6 items du lot H4 — P0 fidélité status=5 (`OrderQuoteService.php:272` + `FrontendOrderService.php:936`, encore `->where('status', 1)` au grep), C-R2-NEW-1 (upsell DATA), C-R2-NEW-2 (`AvailabilityService.php:247` vérifié), B-R1-06, B-R1-07, B-R1-16 (`LastZReportWidget.vue:28` vérifié) — **EXCLUS de ce backlog**. Sas V0 : tout item R3 non fermé réintègre en tête de V1.
> **DETTE DESIGN** : les 14 P2 survivants F (DESIGN_GAP_ANALYSIS_V2) = autre planificateur, **NON TRAITÉS ici**.

---

## §A VISION & PÉRIMÈTRE

Durcir la **V1 LOCAL Le Cayenne** (mono-poste, FR, branch 1, TPE simulé assumé) au niveau « gérant ne peut plus se faire mentir par un écran » : fermer les ~22 P2 + ~13 P3 techniques survivants du dispute, transformer les angles morts BS-* en missions de test exécutées, et présenter à l'owner une liste de gates unique et ordonnée. Aucun item cloud/SaaS — tout finding qui l'exigerait est hors vision (CONSTITUTION §1).

---

## §B BACKLOG TECHNIQUE PRIORISÉ

Ordre = (risque gérant × effort inverse). Légende : S<½j · M ½-1j · L>1j. « Tests » = ajout requis avant heal (TDD).

### Bloc 1 — Argent & décisions du gérant (P2, haut risque)

| # | ID | Sév | Ancre re-greppée | Root cause | Fix scope-minimal | Effort | Frozen/Gate | Tests |
|---|---|---|---|---|---|---|---|---|
| 1 | E2-P2-1 | P2 | `PosOrderReceiptComponent.vue:99-102` (`label.subtotal` → `subtotal_without_tax_currency_price`) | Sous-total = HT **net** post-remise alors que les lignes sont HT **brut** → « Sous-total − Remise » incohérent sur document fiscal | Afficher le sous-total HT BRUT (Σ lignes) et laisser la ligne « Remise » soustraire ; ne PAS toucher au netting NF525 (affichage only) | M | non / non | PHPUnit snapshot receipt commande remisée (bases brut/net) + Vitest composant |
| 2 | ADV-B-R2-01 | P2 | `TransactionListComponent.vue:264` (`excepts: 1` → liste gateways en ligne seulement) | Le filtre « Mode de paiement » ne propose que « Credit » alors que ~99 % du grand livre = Espèces/comptoir | Ajouter au dropdown les modes comptoir statiques (Espèces, Carte comptoir, TR) mappés sur les valeurs réelles de `transactions.payment_method` | S | non / non | Vitest filtre + capture live filtrée |
| 3 | B-R1-08 | P2 | `PosCashDrawerSessionDialog.vue` (dialog close — constat WAVE_REPORT B R1 : disparaît sans récap) | Aucun récapitulatif post-clôture (montants, écart signé, raison) | Écran/état « récap clôture » dans le même dialog après POST close (lecture de la réponse, pas de nouveau endpoint) | M | non / non | Vitest état récap + capture |
| 4 | B-R1-05 | P2 | `EscPosPrinterService.php:112` (`'error' => 'no_printer'`) | No-sale tiroir → 422 `no_printer` mais toast FR générique sans cause ; 2 voies tiroir, 2 comportements | Mapper la cause (`no_printer` → « Aucune imprimante configurée — tiroir non ouvert ») + même message sur les 2 voies | S | non / non (PRINT-1 reste gate pour le test réel) | PHPUnit message 422 + Vitest toast |
| 5 | B-R1-11 | P2 | `PaymentService.php:512-525` (mode `$strict=false` legacy kiosk : « log + return si pas de session OPEN ») | Encaissement borne sans session = silencieux côté caissier (bandeau a-posteriori `CashOverviewComponent.vue:190-200` seulement) | Avertissement bloquant-doux dans `PosCounterCollectModal` si aucune session OPEN du caissier (warning + confirmation), sans toucher au mode strict | M | non / policy-light (passage strict=true = gate #11/§D) | PHPUnit garde + Vitest modal |
| 6 | ADV-B-01 | P2 | dashboard « Ticket Moyen » (constat b6-08 : 4,66 € vs 37,24/19) | KPI calculé sur « payées seulement » sans qualificatif | Sous-titre disclose « par commande payée » (copy only) | S | non / non | capture |

### Bloc 2 — Parcours borne résiduels (P2)

| # | ID | Sév | Ancre | Root cause | Fix | Effort | Frozen/Gate | Tests |
|---|---|---|---|---|---|---|---|---|
| 7 | D-R2-A1 | P2 | `KioskErrorNetworkComponent.vue:62+` (`retry()` → reload sur la route erreur, `$emit('retry')` non câblé par le parent frozen) | RÉESSAYER re-land en boucle sur l'écran erreur même online ; titre mensonger | Health-check dans `retry()` → `router.replace` vers cart si non vide, sinon idle (composant NON frozen) | S | non / non | Vitest retry online/offline + probe `_d2red-D-…-1.mjs` rejouée |
| 8 | C-ADV-07 | P2 | `kioskCart.js:749` (`pruneUnavailableLines`) | Lignes panier prunées au mount SANS feedback (chemin non-broadcast) | Toast FR « X article(s) retiré(s) — indisponible(s) » quand prune > 0 (cohérent avec le fix C-R2-NEW-2 de R3) | S | non / non | Vitest store + Vitest toast |
| 9 | D-006-rés. | P2 | non-frozen : `KioskPaymentComponent.vue:1027` (`cash_drawer_failure` fire-and-forget) + `kioskAuthInterceptor.js:84` ; frozen : `KioskAppComponent.vue:1010/1025` | Events hardware perdus en fenêtre stale-token (`.catch(() => {})`) | Mini-queue retry (1 re-émission post-refresh token) dans l'intercepteur pour les émetteurs NON-frozen ; émetteurs `KioskAppComponent` = gate #5/§D | M | partiel / partiel | Vitest interceptor (token stale → re-émis) |
| 10 | D-R2-A3 | P3↗ | constat D R2 §NOUVEAUX (promo 5,00 > total 3,80 → CTA « Valider 0,00 € » actif) | Commande Plan B à 0,00 € commandable — cadrage métier jamais posé | Investigation + proposition (bloquer total 0 ? autoriser avec motif ?) → 1 page de décision, fix après arbitrage | S (cadrage) | non / policy-light | — (cadrage d'abord) |

### Bloc 3 — Cohérence copy/affichage caisse (P2/P3 quick-wins, 1 healer, hotspot fr.json)

| # | ID | Sév | Ancre | Root cause → Fix | Effort | Tests |
|---|---|---|---|---|---|---|
| 11 | B-R1-02 | P2 | `PosCashDrawerSessionDialog.vue:285` (`$t('label.cash_session_variance') \|\| 'Sens'` ; `fr.json:626 "cash_session_variance":"Écart"`) | L'entête de la colonne Sens réutilise la clé « Écart » → nouvelle clé `label.movement_direction` (« Sens ») | S | Vitest header |
| 12 | B-R1-03 | P2 | `PosCounterCollectModal.vue:495/501/504` (suffixe « (SSOT modal) » écrit dans les notes DB) | Jargon dev persisté dans le grand livre → retirer le suffixe des libellés écrits (forward-only, pas de migration) | S | PHPUnit note propre |
| 13 | B-R1-10 | P2 | `CashOverviewComponent.vue:143-144` (`}}:` + `<strong class="ml-1">` → « ouverte à:13:41 ») + « (à venir) » même page | Deux-points collés + placeholder interne visible → espace insécable avant « : », retirer/conditionner « (à venir) » | S | capture |
| 14 | A-RED-10-part | P2 | `fr.json:917-918` (`"mobile_banking": "MFS"`, `"other": "Autre"`) | Libellés cryptiques dans les tranches multi-paiement — la PART fr.json est fixable sans gate (le render `PosV5TrancheRow.vue` reste frozen, gate #3/§D) | S | capture tranche |
| 15 | ADV-B-06 | P3 | `PosCounterCollectModal.vue:126/182` (`type="text"`, `inputmode="text"`) | Frappe clavier « 3.8 » acceptée (point) vs numpad virgule → normaliser `.`→`,` à la saisie | S | Vitest input |
| 16 | B-R1-20 | P3 | `CashSessionReportListComponent.vue:111-112` (`formatMoney(s.variance)` non signé) | Écart non signé sur le rapport (form clôture déjà signé) → signe +/− | S | Vitest cellule |
| 17 | ADV-B-R2-02 | P3 | `PosOrderShowComponent.vue:343` (ternaire `'Passager'` hardcodé) | « Passager » vs « Client passage » pour le même concept → clé i18n unique `label.walk_in_customer` | S | Vitest |
| 18 | B-R1-13 | P3 | `fr.json:456 "refunded":"Remboursé"` vs `:1257 "returned":"Retournée"` | Genre incohérent sur la même commande → harmoniser (« Remboursée »/« Retournée ») | S | capture badges |
| 19 | A-RED-13 | P3 | show card04 « TACOS CHOISIS TES VIANDES : » (constat A R2) | Instructions wizard restituées en caps brutes back-office → formatage casse à l'affichage (PAS dans le wizard frozen) | S | Vitest |

### Bloc 4 — Tickets & arrondis (P3, fichiers disjoints du bloc 3)

| # | ID | Sév | Ancre | Root cause → Fix | Effort | Tests |
|---|---|---|---|---|---|---|
| 20 | A-RED-8 | P3 | `ReceiptComponent.vue:238/251` (`{{ $t('label.change') }} : {{`) | Espace avant « : » sur « Rendu » vs « Espèces: » → coller (cohérence ticket) | S | DOM receipt |
| 21 | C-RED-05 | P3 | `OrderQuoteService.php:368` (`discountedKioskTotal`) | Preview 0,27 € de TVA résiduelle sur panier 100 % remisé — edge jamais re-testé depuis le moteur TTC-aware | Investigation TDD : test panier total=0 → si bug réel, fix arrondi dans le quote (NON frozen) ; sinon clore avec preuve | S/M | PHPUnit edge total 0 |

### Bloc 5 — Hygiène erreurs & doc (P3)

| # | ID | Sév | Ancre | Root cause → Fix | Effort | Tests |
|---|---|---|---|---|---|---|
| 22 | A-R2-1 | P3 | `pos-app.js:54-68` (re-throw du 409 → toast affiche le message backend EN brut) | « Order quote signature mismatch. » EN au caissier (tamper-only) → mapping FR générique « Intégrité de commande invalide — re-tentez » côté catch appelant (PAS dans PaymentComponent frozen) | S | Vitest mapping |
| 23 | ADV2-F-P3-NEW-1 | P3 | constat F R2 §4 (PAGEERROR AxiosError 401 non catchée au logout auto admin) | Promesse non catchée sur la voie logout → `.catch` propre dans l'intercepteur admin (voie non-frozen) | S | console clean live |
| 24 | C-ADV-04 | P3 | constat C R1 §C-ADV-04 (« Utiliser mes points : −0,00 € » confirmable, plafond 0) | Option rachat sélectionnable à plafond 0 → disable + copy (composant loyalty borne non-frozen) | S | Vitest |
| 25 | C-ADV-09 | P3 | `router/modules/kioskRoutes.js:291` (`error/product-removed` jamais câblé) | Écran erreur orphelin → soit câbler au flux prune (#8), soit retirer la route (décision dans le heal) | S | Vitest route |
| 26 | C-R2-NEW-3 | P3 | constat C R2 (toast warning chevauche CTA « RETOUR À L'ACCUEIL », c11b-04) | Position toast vs CTA ancré bas sur cash-instruction → offset/position toast sur cet écran | S | capture |
| 27 | B-R1-14 | P3 | `PosOrderController.php:94-103` (« we deliberately do NOT flip ») vs `PersistOrderPaymentStatusChangedOnRefundCreated.php:98-104` (flip REFUNDED réel) | Commentaire périmé vs listener → corriger le commentaire (le mécanisme est délibéré et journalisé ; l'élargissement PaymentStateMachine = gate §D-24) | S | — |

**Routés en §D (gates, pas de heal autonome)** : B-R1-01 (UI apport/retrait), B-R1-09 (imputation refund), B-R1-18 (UI Z), ADV-B-09 + E-ADV-7 (sessions zombies), D-004 (indicateur offline), D-008 (multi-tab), C-RED-04 (allergènes DATA), A-RED-9/10/12, ADV-F-P2-16, D-006 part frozen. **Routé en §F (process)** : COV-2, ADV-B-R2-03.

---

## §C ANGLES MORTS RESTANTS → MISSIONS DE TEST

Chaque mission = protocole + environnement + blocage éventuel. Discipline commune (leçons §F) : trace DB par commande, parcours variés, MD5-check captures, adversaire ≠ exécutant.

| M | BS | Mission | Protocole concret | Env requis | Bloqué par |
|---|---|---|---|---|---|
| M1 | BS-3 | **Refunds profonds** | :8768/foodking_e2e : refund partiel, TR, carte, post-Z (miroir réel vs copy B-R1-06 healée R3) ; trace `cash_back`+`transactions`+séquence fiscale par cas | harnais actuel | rien (post-R3) |
| M2 | BS-9 | **Multi-caissier / races** | 2 contexts Playwright (caissiers A+B, sessions distinctes) : double-clic « Encaisser » MÊME commande (assert 1 transaction), vente simultanée, mouvements imputés à la bonne session | harnais + 2 comptes staff | gate #11 pour le FIX ; le TEST documente |
| M3 | BS-4+11 | **Stress multi-jours + Z réel** | 3 « journées » : J1 ventes+clôture Z réelle (POST close), J2 + refund post-Z, J3 file 200+ borne en rafale pendant encaissements ; assert `fiscal:verify-chain`, X-report, 0 collision N° A inter-jours, throttle UX visible | harnais + horloge simulée (`Carbon::setTestNow` au seed) | UI Z = gate #16 (test via API) |
| M4 | BS-5 | **KDS boundary** | Bump/recall, OSS, clic « Démarrer » sur commande NON payée, overflow +11 ; trace transitions `OrderStateMachine` | harnais + écran KDS | **gate #13 AVANT** (tester = figer une politique) |
| M5 | BS-6 | **Chaos réseau résiduel** | Abort MID-POST `frontend/order` → assert idempotency 0 doublon ; soketi down→up (re-sub `private-branch.1`) ; mode dégradé caisse (polling) | harnais + contrôle réseau Playwright | rien |
| M6 | BS-10 | **Viewports caisse** | Sweep 1280×800 + zoom 125 % POS/encaissement/modals ; PaymentComponent 1366 = capture-only | harnais | fix PaymentComponent = gate #2 |
| M7 | BS-12 | **RGPD borne** | Audit lecture seule : mentions/consentement inscription fidélité, rétention, nom complet post-lookup tel (initiale ?) → page conformité + findings | harnais read-only | fixes = copy + policy owner |
| M8 | BS-15 | **A11y opérante** | Watcher `audioDescription` (méthode computed-values D-005), parcours clavier caisse complet, lecteur d'écran 3 écrans borne | harnais + axe-core | rien |
| M9 | BS-1+13 | **Impression réelle** | Ticket 80 mm réel, reprint, ticket cuisine, ticket remboursement, drawer-kick, 2 voies tiroir | **matériel réel** (SAGA, IP) | **gate PRINT-1 (#21)** |
| M10 | BS-7+8 | **Borne physique** | Restauration session Electron, purge PENDING_COUNTER (post gate #12), single-session | borne réelle / Electron | gates #12, #17 |

Critère de sortie §C : chaque mission rend un rapport `reports/test-e2e/hardening-2026-06/M<x>.md` avec findings triés P0-P3 + trace DB — les nouveaux P0/P1 réintègrent §B en tête.

---

## §D GATES OWNER CONSOLIDÉES (exhaustivité vérifiée vs META R2 §5 + ajout #24)

Reprise intégrale des 23 gates méta R2 + **1 ajout** (#24, exhaustivité : mécanisme trouvé en B-R1-14). Ordre de traitement recommandé = colonne O.

| O | # | Gate | Type | Recommandation |
|---|---|---|---|---|
| 1 | 13 | **Politique KDS** « cuisine avant encaissement » (cette branche) vs « release-guard » (heal/ultra-audit-w4) — OPPOSÉES | Policy | **Trancher AVANT tout merge ET avant M4** (anti-drift §12) — 2 politiques sur 1 page, owner coche |
| 2 | 11 | **Sessions tiroir zombies** (`CashOverviewController.php:490 resolveOpenCashSession` retombe sur la plus récente OPEN ; preuve write-side F §5.1 : `expected_closing` corrompu) | Policy | URGENT (argent) : clôture forcée zombies + résolution UNIFIÉE writer/reader + arbitrage N-sessions. Dossier enrichi R2 |
| 3 | 12 | Purge/expiration PENDING_COUNTER + anti-collision N° A inter-jours | Policy | Proposer : expiration 24 h auto-annulée + jour dans le N°. Mitigation UI en place |
| 4 | 21 | **PRINT-1** impression réelle + drawer-kick | Infra | Débloque M9 + clôt B-R1-05. Demander IP imprimante |
| 5 | 2 | **A-RED-12** CTA paiement sous le pli 1366×768 (`PaymentComponent.vue` frozen) — **blueprint H3 prêt** | Frozen-LOCK | Contresigner le LOCK existant (pattern sibling validé hit-test ×2) |
| 6 | 8 | **Allergènes** 44/45 items sans données + badge invisible (INCO UE 1169/2011) | DATA | Légal — matrice allergènes par item (template à générer) |
| 7 | 7 | « VAT (10%) » EN sur receipts | DATA | data-ops 5 min → « TVA (10 %) » |
| 8 | 9 | Pool upsell : choix add-ons `is_upsell=5` (`ItemController.php:79`) | DATA | R3 reseed, nod owner sur la liste |
| 9 | 10 | Seed SUP-LOY-1 sans articles | DATA | purger ou compléter |
| 10 | 1 | **A-RED-9** rejet silencieux wizard caisse (`pos-wizard.js` strict no-touch) | Frozen-LOCK | LOCK chirurgical (feedback section manquante) OU accepter — décision explicite |
| 11 | 3 | **A-RED-10** « MFS »/« Autre » tranches (`PosV5TrancheRow.vue` frozen ; libellés fr.json fixés §B#14) | Frozen+NF525 | « Autre » traçable NF525 ? Proposer retrait des modes non-câblés V1 |
| 12 | 24 | **AJOUT** — `PaymentStateMachine` `PAID=>[]` vs flip listener REFUNDED (`PersistOrderPaymentStatusChangedOnRefundCreated.php:98-104` ; recoupe P0 connu changePaymentStatus W1-W3) | Frozen-adjacent fiscal | Canoniser le flip listener (actuel, journalisé) OU élargir la machine — jamais les deux. Lié M1 |
| 13 | 5 | **D-006** émetteurs hardware `KioskAppComponent.vue:1010/1025` (frozen) fire-and-forget | Frozen-LOCK | Après §B#9, décider si la télémétrie perdue justifie un LOCK |
| 14 | 4 | ADV-F-P2-16 asymétrie upsell (`KioskUpsellComponent.vue` frozen) | Frozen-LOCK | Basse priorité — grouper avec LOCK kiosk éventuel |
| 15 | 6 | Cosmétiques frozen connus (spam log wizard · aria-pressed upsell · Title Case PaymentComponent · prix-étapes wizard) | Frozen | Grouper dans un LOCK unique si #5/#10 ouverts |
| 16 | 16 | B-R1-18 aucune UI admin Z (widget B-R1-16 healé R3) | Produit | Page lecture seule « Clôtures Z » (liste + PDF) — petit, forte valeur gérant |
| 17 | 14 | B-R1-01 aucune UI apport/retrait espèces (grep `paid_in/paid_out` admin = 0 hit re-vérifié) | Produit | Confirmer le choix V1 ou commander l'UI |
| 18 | 15 | B-R1-09 refund imputé au tiroir du cliqueur | Policy | Documenter la règle dans le récap clôture (§B#3) |
| 19 | 18 | D-004 indicateur offline catalogue borne | Produit | Badge discret recommandé (S) — visible client donc owner |
| 20 | 17 | D-008 multi-tab last-writer-wins borne | Produit | Risque faible mono-écran — accepter + documenter |
| 21 | 19 | ADV-B-01 base « Ticket Moyen » | Produit | Couvert §B#6 — gate = valider le qualificatif |
| 22 | 20 | Design backlog (DESIGN_GAP_ANALYSIS_V2 + 4 règles POLICY) | Design | **→ planificateur design** |
| 23 | 22 | SYNC-WS-01 ws:6001 down harnais | Infra | Re-tester env nominal au prochain serve |
| 24 | 23 | Drift config serveur :8768 vs .env (A-RED-3) | Infra | Relancer le serve avant le prochain round |

---

## §E VAGUES D'EXÉCUTION (périmètres fichiers disjoints, parallélisables sauf hotspot fr.json)

| V | Scope (items §B) | Fichiers (disjoints) | Parallélisme | Critères de sortie mesurables |
|---|---|---|---|---|
| **V0 SAS R3** | Vérifier `HEAL_R3.md` + verdict R3 focalisé ; réintégrer tout item H4 non fermé | — | bloquant, court | R3 = 0 P0/0 P1 sur le périmètre focalisé, sinon réinjection en V1 |
| **V1 CAISSE-GESTION** (12 items : #2,3,4*,5,6,11,12,13,14,15,16,17,18,19) | `TransactionListComponent` · `CashOverviewComponent` · `PosCashDrawerSessionDialog` · `CashSessionReportListComponent` · `PosOrderShowComponent` · `PosCounterCollectModal` · `fr.json` | healer UNIQUE (hotspot fr.json) | PHPUnit ciblé + Vitest verts ; captures lues ; tripwire frozen 0 ; vague B re-jugée VERT par adversaire indépendant |
| **V2 BORNE UX** (6 items : #7,8,9,10-cadrage,24,25,26) | `KioskErrorNetworkComponent` · `kioskCart.js` · `kioskAuthInterceptor.js` · `KioskCashInstructionComponent` · loyalty borne · `kioskRoutes.js` | ∥ V1 (fichiers disjoints ; éditions fr.json sérialisées après V1) | probes `_d2red-D-*`/`_d2red-C-*` rejouées vertes ; 0 ChunkLoadError/pageerror ; D-R2-A1 fermé live |
| **V3 TICKETS & ARRONDIS** (3 items : #1,20,21) | `PosOrderReceiptComponent` · `ReceiptComponent` · `OrderQuoteService` (tests) | ∥ V1/V2 | PHPUnit receipt remisé (bases cohérentes) + edge total 0 ; DOM receipt re-extrait live ; convention F1 inchangée (diff Fiscal/* = 0) |
| **V4 ROBUSTESSE & HYGIÈNE** (5 items : #4-toast,22,23,27 + B-R1-11 #5 si non pris V1) | `pos-app.js` · intercepteur admin · `EscPosPrinterService` · `PaymentService` (warning) · commentaire `PosOrderController` | après V1 (touche PaymentService/modal) | console caisse+borne 0 error sur passes live ; toasts FR ; PHPUnit verts |
| **V5 MISSIONS ANGLES MORTS** (M1,M2,M3,M5,M6,M7,M8 — M4/M9/M10 gatées) | tests/probes only (lecture + DB jetable) | fan-out ∥ par mission, adversaire ≠ exécutant | 7 rapports M*.md sur disque, trace DB par commande, 0 nouveau P0/P1 OU réinjection §B documentée |

Clôture du GOAL = V0-V5 done + adversaire indépendant re-juge B (la seule vague JAUNE) + sentinelles A/D/F rejouées (`_d2red-*`) + frozen-diff global 0 + BRAIN §2/§3 à jour. Les gates §D suivent leur propre vie (page de décision owner unique recommandée, format Wave-Polish 2026-05-21).

---

## §F LEÇONS MÉTHODOLOGIQUES À CODIFIER (du méta R1 §0 + bilans R2)

Amendements **concrets** proposés (PREPARE-ONLY — à appliquer après nod) :

1. **CLAUDE.md §13 (Evidence)** : « Convergence sur 2 cycles = cycles **non-isomorphes** (parcours différents, seed mutée OU opérateur différent). Deux runs du même script/même seed prouvent la convergence du script, pas du système. » (racine du REFUTED C-01).
2. **Skill `test-e2e` — 4 règles dures** : (a) **trace DB obligatoire par commande** (orders.discount/total + ledger + loyalty_transactions) — jamais un montant validé sur le seul écran ; (b) **contrôle MD5 des captures** avant de les compter en couverture (10/25 « captures » c2 = redirects ; récidive ADV-B-R2-03) + remède inner-scroll admin `main.db-main` (`_d2red-B-caisse-gestion-03.mjs`) ; (c) **verdict adversarial JAMAIS reconstitué par l'orchestrateur des heals** — agent mort ⇒ adversaire indépendant reconstruit depuis le disque (rapport incrémental obligatoire, 3 agents sauvés ce round) ; (d) **chaque heal re-teste son scénario d'ORIGINE + son voisinage** (N1 jamais re-testé offline ; 2 heals/30 collatéraux : keypad R1, upsell R2).
3. **CLAUDE.md §3ter — anti-récidive status** : avant tout lookup `users.status`, grep des précédents (`LoyaltyController::isCustomerActive`) — le P0 fidélité R2 a ré-introduit un bug **documenté dans le code même** ; factories alignées sur la population RÉELLE (`Status::ACTIVE=5`).
4. **CLAUDE.md §13 — Vitest** : Vitest vert sur un composable ≠ feature opérante — toute spec exige une preuve de **consommateur runtime** (grep d'import hors tests ; contre-exemple `useKioskA11y` 5/5 vert, jamais monté).
5. **Skill `test-e2e` — provenance** : vérifier PID/cwd du serve avant toute citation file:line ; relancer le serve si drift config (gates #23-24 §D).

---

## RÉCAP

- **§B : 27 items healables** (13 P2 + 14 P3), tous file:line re-greppés ce jour, 0 frozen-touch, dédupliqués du lot R3 (6 items exclus).
- **§E : V0 sas + V1 ×12 + V2 ×6 + V3 ×3 + V4 ×5 + V5 ×7 missions** — V1/V2/V3 parallélisables (fr.json sérialisé).
- **§C : 10 missions** d'angles morts (7 lançables maintenant, 3 gatées : KDS-policy, PRINT-1, borne physique).
- **§D : 24 gates** consolidées + ordonnées (1 ajout #24 PaymentStateMachine), design routé au planificateur design.
- **§F : 5 amendements** CLAUDE.md/skill prêts à poser.

— Fin GOAL_TECH_HARDENING_2026-06-12 — PREPARE-ONLY, prêt à lancer sur ordre.
