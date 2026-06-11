# CYCLE 2 CONVERGENCE — Analyse adversariale finale indépendante (GOAL UIUX caisse+borne, W6)
Date: 2026-06-11 · App: http://127.0.0.1:8768 · DB e2e RE-resetée seed W0 · Bundles rebuildés (micro-heals B-1 + A-5 inclus)
Mode: READ-ONLY (aucune édition source, pas de git/artisan/build). Liste formée AVANT lecture de CYCLE1_FINDINGS.md.

---

## VOLET A — Lecture des 25 captures statiques (c2/*.png)

Règles de rejet appliquées : raw label · layout cassé · format EN · texte anglais user-facing ·
état vide inexpliqué · bouton invisible. Redirections guard kiosk = normales (manifest `_manifest.txt`).

| # | Capture | Verdict | Notes |
|---|---------|---------|-------|
| 1 | pos-orders.png | PASS | FR, « Client passage », « À Emporter », montants FR, badges « En préparation » peuplés, pagination « Affichage de 1 à 10 sur 60 entrées ». Dates « 23:54, 10-06-2026 » à tirets → note 3 (P3). |
| 2 | pos-orders-show.png | PASS (note) | #SUP-LOY-1 (id 4511) : badges « Non Payé »/« En Attente » non vides, totaux FR 8,50 € / Remise 1,00 € / 7,50 €, empty-state articles EXPLIQUÉ (« Aucun article dans cette commande. Les articles apparaîtront ici dès qu'ils seront ajoutés. »). Totaux ≠ 0 avec 0 article = artefact seed supervisor → note 4 (P3-DATA). |
| 3 | pos-orders-tracker.png | PASS (note) | Peuplé, colonnes À ENCAISSER / EN PRÉPARATION / PRÊTS À SERVIR, prix FR, CTA Encaisser/Livré. Zoom : « À ENCAISSER : » — « : » orphelin sous le libellé sur cartes étroites → note 5 (P3). |
| 4 | encaissement.png | PASS | « Total en attente d'encaissement : 247,60 € », cartes Client borne + CTA « Encaisser », prix FR, badges attente (seed J-1, cohérent). |
| 5 | cash-overview.png | **PASS — fix A-5 VÉRIFIÉ** | Bandeau réconciliation affiche bien « **Espèces encaissées (session en cours) : 86,00 €** » (libellé healé, lève l'ambiguïté fenêtre session vs fenêtre calendaire du filtre « 0 tx » du 11/06). Empty-state EXPLIQUÉ + CTA. Tutoiement « Modifie la période… » → note 6 (P3). |
| 6 | cash-sessions-report.png | PASS | FR (« mercredi 10 juin 2026 »), écarts 2,00 € en rouge, statuts Ouverte/Réconciliée/Fermée. |
| 7 | historique.png | PASS | 2385 entrées, Payé/Non payé, N° fiscal peuplé (2160-2166), prix FR. Dates à tirets → note 3. |
| 8 | pos-main.png | PASS | Grille peuplée, « À encaisser **50** » (badge header = compteur panneau « À ENCAISSER BORNE (50) », zoom vérifié, cohérent), empty-states EXPLIQUÉS, total FR. Images Frites/Boisson Seules cassées = GATE 4. |
| 9 | pos-floorplan.png | PASS | Empty-state EXPLIQUÉ (« Le service en salle est désactivé » — V1 dine-in off, conforme). |
| 10-14, 16-21, 22 | kiosk-login / idle / categories / products-(sandwich,tacos) / cart-empty / loyalty / upsell / payment / confirmation / admin | PASS | Guards → idle ou cart = NORMAUX ; idle FR propre (« Bienvenue ! / À emporter / CHOISISSEZ UNE OPTION POUR COMMENCER ») ; cart-empty EXPLIQUÉ (« Votre panier est vide / Ajouter des articles »). Surfaces dynamiques couvertes en Volet B. |
| 15 | kiosk-cash-instruction.png | PASS (note) | FR propre (« Rendez-vous en caisse », auto-retour 43 s, J'AI COMPRIS). Deep-link sans commande rend placeholders « #— » / « 0,00 € » au lieu de rediriger → note 7 (P3). |
| 23-25 + 22 | kiosk-error-network / menu-unavailable / product-removed / payment-refused | PASS | 4 écrans erreur FR, CTA lisibles (RÉESSAYER, PAYER EN CAISSE, ANNULER LA COMMANDE, RETOUR…). |

Aucun raw label, aucun texte anglais user-facing, aucun « €1.50 », aucun layout cassé, aucun bouton invisible sur les 25 captures.

---

## VOLET B — Flux dynamiques (Playwright chromium channel:chrome, fr-FR)

Scripts jetables : `tests/e2e/_w6-c2-borne-flow.mjs`, `tests/e2e/_w6-c2-caisse-flow.mjs`.
14 captures → `convergence/c2/flow/` (budget ≤14 respecté). Logs : `flow/_borne-flow-log.txt`, `flow/_caisse-flow-log.txt`, `flow/_borne-orders.json`.

### B.1 Borne 1080×1920 — idle → simple + composé (wizard) → cart → loyalty (+ inscription) → upsell → payment → confirmer → cash-instruction
| Étape | Evidence | Verdict |
|---|---|---|
| Idle → À emporter → catégories | f01-categories.png | PASS — Desserts FR « 5,80 € », bandeau promo BORNEAUDIT5, footer 0 article / 0,00 €. PRICE-OK. |
| Produit simple | log `simple ajouté kiosk-product-add-49 (cat=9)` | PASS. |
| Produit composé (wizard complet) | f02-wizard-step.png ; log `composé ajouté kiosk-product-add-38` | PASS — « VOUS COMPOSEZ CHICKEN BURGER », QUELLE VIANDE/SAUCE/CRUDITÉ/SUPPLÉMENT/RÉCAP, AVEC/SANS, SUIVANT/PRÉCÉDENT/ABANDONNER, total footer « 6,90 € » FR. |
| Panier | f03-cart.png ; total « 39,90 € » | PASS — 22 lignes (artefact script : chaque clic-test ajoute), « X,XX € par unité », steppers, allergènes Tiramisu. PRICE-OK. |
| Loyalty saisie tél (numpad) | f04-loyalty-input.png | PASS — numpad tactile OK (0699999999), throttle 429 (induit par répétition des runs) géré proprement : toast FR « Trop de requêtes — patientez 27s » + inline « Trop de tentatives, patientez quelques secondes. ». |
| Loyalty écran inscription | f05-loyalty-register.png ; log `register rend: true` | PASS — « Créer votre compte fidélité », NOM*/TÉLÉPHONE*/E-MAIL (FACULTATIF), CTA désactivé champs vides, ← Retour. |
| Upsell | f06-upsell.png | PASS — « ET POUR TERMINER ? », prix FR, autoskip « Non merci, continuer sans (28s) ». (Images Frites/Boisson Seules = GATE 4.) |
| Payment (Plan B comptoir) | f07-payment.png | PASS — « PAIEMENT À LA CAISSE / TOTAL À RÉGLER : 39,90 € / Confirmer ma commande ». PRICE-OK. |
| Confirmer → cash-instruction | f08-cash-instruction.png ; ORDER-POST **201 id=4512 queue=A0001 total=39.9** | PASS — « Rendez-vous en caisse », **#A0001**, « 39,90 € », auto-retour 42 s. **Zéro écran blanc sur tout le parcours.** |

Console borne : **2 entrées** — 1× `401 /api/login` au boot (auto-login 1er essai, token OK au retry ≤1,5 s) → note 8 (P3) ; 1× `429 /api/frontend/loyalty/check` (throttle environnemental, UX FR propre). **Aucune PAGEERROR, aucun raw label, aucun format EN.**

### B.2 Caisse 1440×900 — session → mini-commande → show borne EN ATTENTE (B-1) → encaisser borne → cash-overview (A-5) → historique → tracker
| Étape | Evidence | Verdict |
|---|---|---|
| Session caisse | log | PASS — modal « Ouvrir la caisse » présent (DB re-resetée), ouverture OK (fond 50,00 €), dialog « Gestion de la caisse » fermable ✕. |
| Mini-commande | f09-pos-ticket.png ; log `1 ligne(s), total="Total1,50 €"` | PASS — Coca-Cola 33cl via wizard popup (frozen), toast « Article ajouté au panier », ticket 1,50 € FR. |
| **Show commande borne EN ATTENTE (fix B-1)** | f11-pos-orders-show-borne-attente.png ; log `B-1 show 4512: ÀEncaisser=true ClientBorne=true` | **PASS — fix B-1 VÉRIFIÉ** : badge paiement header « **À Encaisser** » NON vide + dropdown paiement « À Encaisser » peuplé, badge statut « Accepter », « Client Borne », « Type de paiement : Comptoir différé », articles + totaux 39,90 € FR, date « 11/06/2026 à 11:31 » FR. |
| Encaisser une borne (modal 900px) | f10-encaisse-modal.png | PASS — modal « Encaisser la commande borne », N° A0009 + badge BORNE, « MONTANT TOTAL 1,00 € », 4 modes (Espèces « Ouvre le tiroir (simulation) » / Terminal (manuel) SumUp / Mobile / Ticket restaurant), MONTANT REÇU pré-rempli, numpad, **CTA « ✓ Confirmer & Imprimer ticket » bottom≈394 px < 900 → VISIBLE** (tous boutons mesurés VISIBLE). |
| Validation encaissement | f10b-after-encaisse.png ; log `ENCAISSE-POST 200 …/pos/counter-collect/4328/confirm` | PASS — POST 200, A0009 sorti de la file (panneau passe à A0010-A0013 ; compteur 50 cohérent : −1 encaissée +1 nouvelle A0001). |
| **Cash-overview (fix A-5)** | f14-cash-overview.png ; log `A-5 … présent=true` | **PASS — fix A-5 VÉRIFIÉ dynamiquement** : « Caisse ouverte à : 11:32 · Fond de caisse : 50,00 € · **Espèces encaissées (session en cours) : 1,00 €** · Espèces attendues au tiroir : 51,00 € » — cohérent avec le filtre du jour (GRAND TOTAL 1,00 € / 1 tx / BORNE 1,00 € / ligne 11:32 N°A0009 Borne Espèces 1,00 €). Plus aucune contradiction apparente. |
| Historique + datepicker | f12-historique-datepicker.png | PASS — datepicker **FR** (« juin 2026 », « lu ma me je ve sa di », Aujourd'hui/Ce mois/Mois dernier/Cette année), A0001 39,90 € paiement « À encaisser ». |
| Tracker | f13-tracker.png | PASS — peuplé, A0001 39,90 € en colonne À ENCAISSER avec CTA Encaisser. |

Console caisse (parcours complet) : **0 erreur console, 0 HTTP≥400, 0 PAGEERROR.** (Aucun 401 ws/broadcasting observé sur ce run.)

---

## FINDINGS CYCLE 2 (hors gates)

**P0 : aucun. P1 : aucun. P2 : aucun.**

Notes P3 (non bloquantes, 7) :
1. **[P3]** Statut de commande « Accepter » (infinitif) au lieu d'un état (« Acceptée ») — show + historique. Cosmétique i18n.
2. **[P3]** Boot kiosk : 1× 401 `/api/login` au premier essai d'auto-login (récupéré ≤1,5 s) — bruit console au démarrage borne.
3. **[P3]** Dates listes caisse « 23:54, 10-06-2026 » (séparateur tirets) vs « 11/06/2026 » sur la page Voir — incohérence interne de format FR. Surfaces : pos-orders, historique.
4. **[P3-DATA]** #SUP-LOY-1 (id 4511, seed supervisor) : totaux 8,50/7,50 € avec 0 article — artefact de seed, l'UI gère l'empty-state correctement.
5. **[P3]** Tracker carte « À ENCAISSER : » — « : » orphelin qui retombe seul (wrap étroit). Cosmétique.
6. **[P3]** cash-overview : tutoiement « Modifie la période ou réinitialise les filtres » — registre incohérent (vouvoiement partout ailleurs).
7. **[P3]** `/kiosk/cash-instruction` accessible en deep-link sans commande : placeholders « #— » / « 0,00 € » au lieu d'une redirection idle.

(Throttle 429 loyalty = environnemental induit par la répétition des runs e2e, UX dégradée proprement en FR — non compté.)

---

## GATES (exclusions owner connues — non comptées)
1. Contraste orange marque #F4501E.
2. Prix sur étapes wizard frozen (arbitrage owner).
3. « VAT (10%) » nom de taxe = DATA.
4. Images cassées Boisson/Frites Seules + descriptions « Upsell item » = DATA DB opérante.
5. Spam log wizard frozen.
6. aria-pressed upsell frozen.
7. Title Case PaymentComponent frozen.

---

## COMPARAISON CYCLE 1 → CYCLE 2

| Item cycle 1 | Statut cycle 2 |
|---|---|
| **B-1 [P2]** badge + dropdown paiement vides sur show borne PENDING_COUNTER | **RÉSOLU** — vérifié dynamiquement sur commande fraîche id 4512 EN ATTENTE : badge « À Encaisser » + dropdown peuplés (f11, log `ÀEncaisser=true`). |
| **A-5 [P2]** double définition d'« aujourd'hui » sur cash-overview | **RÉSOLU** — libellé healé « Espèces encaissées **(session en cours)** » présent (statique 86,00 € + dynamique 1,00 €), réconciliation désormais auto-explicative et chiffres cohérents avec le filtre (1 tx / 1,00 €). |
| A-1 [P3] dates à tirets | Persiste à l'identique (= note 3 c2). |
| A-2 [P3-DATA] SUP-LOY-1 totaux sans articles | Persiste à l'identique (= note 4 c2). |
| A-3 [P3] « : » orphelin tracker | Persiste à l'identique (= note 5 c2, zoom re-vérifié). |
| A-4 [P3] tutoiement cash-overview | Persiste à l'identique (= note 6 c2). |
| A-6 [P3] deep-link cash-instruction placeholders | Persiste à l'identique (= note 7 c2). |
| B-2 [P3] « Accepter » infinitif | Persiste à l'identique (= note 1 c2). |
| B-3 [P3] 401 one-shot boot kiosk | Persiste à l'identique (= note 2 c2). |

**Nouveaux findings cycle 2 : AUCUN** (toutes les observations c2, y compris celles formées indépendamment avant lecture du rapport c1, mappent 1:1 sur les notes c1).

**Ensembles identiques (hors B-1/A-5 healés) : OUI** — 7 P3 c1 = 7 P3 c2, mêmes surfaces, mêmes causes.

**Counts cycle 2 : P0=0 · P1=0 · P2=0 · P3=7.**

---

## VERDICT : **CONVERGED**

- P0+P1 = 0 aux deux cycles.
- Les 2 seuls P2 du cycle 1 (B-1, A-5) sont healés et **vérifiés en live** au cycle 2 (preuves f11 + f14 + logs).
- Aucun nouveau P2+ au cycle 2 ; ensemble résiduel strictement identique (7 P3 cosmétiques/DATA non bloquants).
