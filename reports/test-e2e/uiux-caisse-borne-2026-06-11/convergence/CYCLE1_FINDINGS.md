# CYCLE 1 CONVERGENCE — Analyse adversariale finale (GOAL UIUX caisse+borne, W6)
Date: 2026-06-11 · App: http://127.0.0.1:8768 · DB e2e reset W0 · Bundles W4+N1
Mode: READ-ONLY (aucune édition source). Rapport incrémental.

---

## VOLET A — Lecture des 26 captures statiques (c1/*.png)

Règles de rejet appliquées : raw label · layout cassé · format EN (€1.50, mm/dd/yyyy) ·
texte anglais user-facing · état vide inexpliqué · bouton invisible/illisible.

| # | Capture | Verdict | Notes |
|---|---------|---------|-------|
| 1 | pos-orders.png | PASS | FR, « Client passage », montants « 7,50 € », badges statut peuplés (En attente/En préparation), pagination FR « Affichage de 1 à 10 sur 60 entrées ». Date « 23:54, 10-06-2026 » = jour-premier (pas EN) mais séparateur tirets → voir A-1 (P3). |
| 2 | pos-orders-show.png | PASS (note) | #SUP-LOY-1 : badges « Non Payé »/« En Attente » non vides, « À emporter », totaux FR (8,50 € / Remise 1,00 € / 7,50 €), empty-state articles EXPLIQUÉ (« Aucun article dans cette commande »). Incohérence DATA seed : totaux ≠ 0 avec 0 article → A-2 (P3 data seed, pas UI). |
| 3 | pos-orders-tracker.png | PASS (note) | Peuplé (17 actives, 153 aujourd'hui), colonnes À ENCAISSER / EN PRÉPARATION / PRÊTS À SERVIR, prix FR. Wrap mineur « À ENCAISSER : » (le « : » retombe seul sous le libellé sur les cartes étroites) → A-3 (P3). |
| 4 | encaissement.png | PASS | Peuplé, « Total en attente d'encaissement 247,60 € », cartes Client borne + « Encaisser » CTA visibles, prix FR, attente « 33h10min » (seed ancien, cohérent DB resetée). |
| 5 | cash-overview.png | PASS (note) | FR, datepickers « 11/06/2026 » format FR, empty-state EXPLIQUÉ + CTA « Réinitialiser Les Filtres ». 2 notes : tutoiement « Modifie la période… » (registre incohérent) → A-4 (P3) ; « 0 tx aujourd'hui » vs « Espèces encaissées aujourd'hui : 86,00 € » dans le bandeau réconciliation (fenêtres temporelles différentes session vs calendrier) → A-5 (P2 à confirmer). |
| 6 | cash-sessions-report.png | PASS | FR (« mercredi 10 juin 2026 »), écarts 2,00 € en rouge, statuts Ouverte/Réconciliée/Fermée. |
| 7 | historique.png | PASS | Peuplé (2385 entrées), Payé/Non payé, N° fiscal peuplé (2160-2166), statuts OK, prix FR. Même format date à tirets → A-1. |
| 8 | pos-main.png | PASS | Grille peuplée, panneau « À ENCAISSER BORNE (50) » avec CTA Encaisser, empty-states ticket + prêt-à-livrer EXPLIQUÉS, total « 0,00 € » FR. Images Frites Seules / Boisson Seule cassées = GATE DATA connue (exclue). |
| 9 | pos-floorplan.png | PASS | Empty-state EXPLIQUÉ (« Le service en salle est désactivé » — V1 dine-in off, conforme mandate). |
| 10 | kiosk-login.png | PASS | Redirigé → idle (guard normal). Idle FR propre. |
| 11 | kiosk-idle.png | PASS | « Bienvenue ! / Commandez en quelques touches / À emporter / CHOISISSEZ UNE OPTION POUR COMMENCER » — FR, branding OK. |
| 12 | kiosk-categories.png | GUARD→idle (normal) | Pas d'évidence statique — couvert en Volet B. |
| 13 | kiosk-products-sandwich.png | GUARD→idle (normal) | Couvert en Volet B. |
| 14 | kiosk-products-tacos.png | GUARD→idle (normal) | Couvert en Volet B. |
| 15 | kiosk-cart-empty.png | PASS | « VOTRE PANIER / 0 article / Votre panier est vide / Parcourez le menu… / Ajouter des articles » — empty-state EXPLIQUÉ, FR. |
| 16 | kiosk-loyalty.png | GUARD→cart (normal) | Couvert en Volet B. |
| 17 | kiosk-upsell.png | GUARD→cart (normal) | Couvert en Volet B. |
| 18 | kiosk-payment.png | GUARD→cart (normal) | Couvert en Volet B. |
| 19 | kiosk-cash-instruction.png | PASS (note) | FR propre (« Rendez-vous en caisse », « Paiement en espèces uniquement à la caisse », auto-retour 43 s, J'AI COMPRIS). MAIS accès deep-link sans commande rend des placeholders « #— » et « Montant à régler 0,00 € » au lieu de rediriger → A-6 (P3 guard gap). |
| 20 | kiosk-confirmation.png | GUARD→idle (normal) | Couvert en Volet B. |
| 21 | kiosk-admin.png | GUARD→idle (normal) | OK. |
| 22 | kiosk-error-network.png | PASS | « Connexion perdue » FR, CTA RÉESSAYER + PRÉVENIR UN MEMBRE DE L'ÉQUIPE lisibles. |
| 23 | kiosk-error-menu-unavailable.png | PASS | FR, CTA RÉESSAYER + RETOUR À L'ACCUEIL. |
| 24 | kiosk-error-product-removed.png | PASS | FR, CTA RETOUR AU MENU + RETOUR À L'ACCUEIL. |
| 25 | kiosk-error-payment-refused.png | PASS | FR, 3 CTA (RÉESSAYER LE PAIEMENT / PAYER EN CAISSE / ANNULER LA COMMANDE) lisibles. |

### Findings Volet A
- **A-1 [P3]** Format date listes caisse « HH:MM, 10-06-2026 » — jour-premier (pas un format EN) mais séparateur tirets au lieu de « 10/06/2026 » FR. Surfaces : pos-orders, historique. Cosmétique.
- **A-2 [P3-DATA]** #SUP-LOY-1 (id 4511, seed supervisor) : totaux 8,50/7,50 € avec 0 article — artefact de seed de test, l'UI gère l'empty-state correctement.
- **A-3 [P3]** Tracker carte « À ENCAISSER : » — le « : » orphelin retombe seul sous le libellé (wrap étroit). Cosmétique.
- **A-4 [P3]** cash-overview : tutoiement « Modifie la période ou réinitialise les filtres » — registre incohérent avec le reste (vouvoiement).
- **A-5 [P2→à vérifier]** cash-overview filtré sur aujourd'hui : « GRAND TOTAL 0,00 € / 0 tx » alors que le bandeau réconciliation affiche « Espèces encaissées aujourd'hui : 86,00 € » — deux définitions d'« aujourd'hui » (session caisse ouverte 22:46 J-1 vs jour calendaire) côte à côte = confusion gérant. (Vérification dynamique Volet B.)
- **A-6 [P3]** /kiosk/cash-instruction accessible en deep-link sans commande : rend « #— » + « 0,00 € » au lieu de rediriger vers idle comme les autres routes profondes.

Aucun raw label, aucun texte anglais user-facing, aucun « €1.50 », aucun layout cassé détecté sur les 25 captures.

---

## VOLET B — Flux dynamiques (Playwright chromium channel:chrome, fr-FR)

Scripts jetables : `tests/e2e/_w6-c1-borne-flow.mjs`, `tests/e2e/_w6-c1-caisse-flow.mjs`,
`tests/e2e/_w6-c1-caisse-flow2.mjs`. 14 captures → `convergence/c1/flow/` (budget ≤14 respecté).
Logs bruts : `flow/_borne-flow-log.txt`, `flow/_caisse-flow-log.txt`, `flow/_caisse-flow2-log.txt`.

### B.1 Borne 1080×1920 — idle → produit simple + composé → cart → loyalty → upsell → payment → confirm → cash-instruction
| Étape | Evidence | Verdict |
|---|---|---|
| Idle → À emporter → catégories | f01-categories.png | PASS — Desserts FR, prix « 5,80 € », bandeau promo BORNEAUDIT5 rendu, footer « 0 article / 0,00 € / Abandonner ma commande ». |
| Produit simple (add direct) | log : `simple ajouté kiosk-product-add-49` | PASS. |
| Produit composé — wizard complet | f02-wizard-step.png ; log `composé ajouté kiosk-product-add-38` | PASS — « VOUS COMPOSEZ CHICKEN BURGER », étapes QUELLE VIANDE/SAUCE/CRUDITÉ/SUPPLÉMENT/RÉCAP, AVEC/SANS FR, CTA SUIVANT/PRÉCÉDENT/ABANDONNER. (Total 6,90 € sur étape = GATE wizard frozen, exclu.) |
| Panier | f03-cart.png ; total « 39,90 € » | PASS — 22 lignes (artefact script : chaque clic-test ajoute), prix unitaire « 0,90 € par unité », steppers, allergènes Tiramisu rendus. PRICE-OK. |
| Loyalty saisie tel (numpad) | f04-loyalty-input.png | PASS — « Programme fidélité », numpad tactile fonctionnel (0699999999 saisi), CTA « Vérifier mon code / Continuer sans fidélité / Pas encore membre ? S'inscrire ». Throttle 429 (induit par les runs répétés) géré proprement : toast FR « Trop de requêtes — patientez 26s » + inline « Trop de tentatives, patientez quelques secondes. ». |
| Loyalty écran inscription | f05-loyalty-register.png ; log `register rend: true` | PASS — « Créer votre compte fidélité », NOM*/TÉLÉPHONE*/E-MAIL (FACULTATIF), placeholders FR, CTA désactivé tant que champs vides, ← Retour. |
| Upsell | f06-upsell.png | PASS — « ET POUR TERMINER ? », prix FR, autoskip « Non merci, continuer sans (28s) ». (Images Frites/Boisson Seules cassées = GATE DATA.) |
| Payment (Plan B comptoir) | f07-payment.png | PASS — « PAIEMENT À LA CAISSE / Veuillez payer à la caisse / TOTAL À RÉGLER : 39,90 € / Confirmer ma commande ». PRICE-OK. |
| Confirmer → cash-instruction | f08-cash-instruction.png ; ORDER-POST 201 id=4512 queue=A0001 | PASS — « Rendez-vous en caisse », N° #A0001, « 39,90 € », auto-retour 42 s, J'AI COMPRIS. Aucun écran blanc sur tout le parcours. |
| Overlay inactivité | — | SKIP (non déclenchable rapidement sans re-seed idleMs ; déjà validé W3-C5). |

Console borne sur tout le parcours : **2 entrées** — 1× `401 /api/login` au boot kiosk (auto-login premier essai, récupéré au retry ~1,5 s, token OK) ; 1× `429 /api/frontend/loyalty/check` (throttle induit par la répétition des runs e2e, UX dégradée proprement en FR). Aucun 401 ws/broadcasting observé sur ce run, aucune PAGEERROR.

### B.2 Caisse 1440×900 — login bm.t2admin → POS → encaisser borne → show → historique → tracker
| Étape | Evidence | Verdict |
|---|---|---|
| Login + /admin/pos | — | PASS. Première visite : modal « Ouvrir la caisse » (fond initial 50,00 €, chips +5/+10/+20/+50, Annuler/Ouvrir la caisse) — rendu FR propre, bloque le POS tant que la session n'est pas ouverte (comportement attendu) ; ouverture OK, dialog « Gestion de la caisse » (session active) fermable via ✕. |
| Mini-commande 1 produit | f09-pos-ticket.png ; log `1 ligne(s), total="Total1,50 €"` | PASS — Coca-Cola 33cl via wizard popup (frozen), toast « Article ajouté au panier », ticket 1,50 €, CTA « Commande · 1,50 € ». |
| Encaisser commande borne | f10-encaisse-modal.png | PASS — modal « Encaisser la commande borne », N° A0009 + badge BORNE, « MONTANT TOTAL 1,00 € », « CHOISIR LE PAIEMENT » Espèces (Ouvre le tiroir (simulation)) / Terminal (manuel) / Mobile / Ticket restaurant, MONTANT REÇU pré-rempli, numpad. **CTA « ✓ Confirmer & Imprimer ticket » bottom≈410px < 900px → VISIBLE** (tous les boutons du modal mesurés VISIBLE, log _caisse-flow2). Hero FR ✔. |
| Validation encaissement | f10b-after-encaisse.png | PASS — modal fermé, file « À ENCAISSER BORNE » décrémentée (51→50), aucun écran d'erreur. |
| /admin/pos-orders/show/4512 (borne) | f11-pos-orders-show.png + zoom | PASS avec findings — « Client Borne » ✔, badge statut commande « Accepter » non vide ✔, articles + totaux FR 39,90 €, date « 11/06/2026 à 11:03 » FR. **MAIS badge paiement VIDE (pastille rose sans texte) + 1er dropdown paiement VIDE** → B-1 ci-dessous. |
| /admin/historique + datepicker | f12-historique-datepicker.png | PASS — datepicker **FR** (« juin 2026 », « lu ma me je ve sa di », raccourcis Aujourd'hui/Ce mois/Mois dernier/Cette année), filtres FR ; la commande borne y affiche bien paiement « À encaisser » (contrairement à la page Voir). |
| Tracker | f13-tracker.png | PASS — peuplé (17 actives, 5 prête(s)), notre A0001 39,90 € en colonne À ENCAISSER avec CTA. |

Console caisse (2 passes complètes) : **0 erreur console, 0 HTTP≥400, 0 PAGEERROR**.

### Findings Volet B
- **B-1 [P2]** Page « Voir » commande caisse (`/admin/pos-orders/show/<id>`) : badge ET dropdown de statut de paiement **vides** pour toute commande borne en attente d'encaissement. Cause grep-confirmée : `resources/js/components/admin/posOrders/PosOrderShowComponent.vue:626-631` — `paymentStatusEnumArray` ne mappe que `PAID(5)`/`UNPAID(10)` alors que `resources/js/enums/modules/paymentStatusEnum.js` définit aussi `PENDING_COUNTER(15)` et `REFUNDED(20)`. DB : `orders.id=4512 payment_status=15`. Rendu badge ligne 21-22 → texte vide. (Même classe de bug que le HEAL-H7 « index manquant → label blanc » commenté dans ce fichier.) L'historique, lui, affiche « À encaisser ». Evidence : f11 + /tmp zoom. REFUNDED serait blanc aussi.
- **B-2 [P3]** Statut de commande affiché « Accepter » (verbe à l'infinitif, clé `label.accept`) au lieu d'un état (« Acceptée ») — show + historique. Cosmétique i18n.
- **B-3 [P3]** Boot kiosk : 1× 401 `/api/login` premier essai d'auto-login (récupéré automatiquement) — bruit console au démarrage borne.

---

## VERDICT FINAL — liste de convergence (à comparer au cycle 2)

**P0 + P1 = 0 — explicitement : AUCUN P0, AUCUN P1 nouveau ou survivant (hors gates owner).**

Findings P2 numérotés (survivants pour cycle 2) :
1. **[P2] B-1** — Badge + dropdown paiement vides sur `/admin/pos-orders/show/<id>` pour commande borne à encaisser (`PosOrderShowComponent.vue:626` ne mappe pas `PENDING_COUNTER(15)`/`REFUNDED(20)`).
2. **[P2] A-5** — `/admin/cash-overview` : double définition d'« aujourd'hui » côte à côte — « GRAND TOTAL 0,00 € / 0 tx » (fenêtre calendaire du filtre) vs bandeau réconciliation « Espèces encaissées aujourd'hui : 86,00 € » (fenêtre session ouverte la veille 22:46) — confusion gérant (cash-overview.png).

Notes P3 (non bloquantes, 7) : A-1 dates listes « 10-06-2026 » (tirets ; la page Voir utilise « 11/06/2026 » → incohérence interne), A-2 seed SUP-LOY-1 totaux sans articles (DATA), A-3 « : » orphelin tracker, A-4 tutoiement cash-overview, A-6 deep-link cash-instruction placeholders #—/0,00 €, B-2 « Accepter » infinitif, B-3 401 one-shot boot kiosk.

**Counts : P0=0 · P1=0 · P2=2 · P3=7.**

---

## GATES (exclusions owner connues — non comptées)
1. Contraste orange marque #F4501E.
2. Prix sur étapes wizard (frozen, arbitrage owner).
3. « VAT (10%) » nom de taxe = DATA.
4. Images cassées Boisson/Frites Seules + descriptions « Upsell item » = DATA DB opérante.
5. Spam log wizard frozen.
6. aria-pressed upsell frozen.
7. « Title Case » PaymentComponent frozen.
