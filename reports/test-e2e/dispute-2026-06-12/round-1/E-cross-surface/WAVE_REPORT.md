# VAGUE E — INTÉGRITÉ CROSS-SURFACE (dispute round 1, 2026-06-12)

Agent: GSTACK MAIN TEAM (Architect+Tester+A11y+SRE). Rôle: CAPTURER + OBSERVER chiffre par chiffre.
Le verdict de sévérité appartient à l'adversaire.

App: http://127.0.0.1:8768 — DB `foodking_e2e` (jetable). Borne 1080×1920, caisse 1440×900, fr-FR, chrome channel.
Artefacts: `reports/test-e2e/dispute-2026-06-12/round-1/E-cross-surface/` (quartet PNG + .dom.html + .console.txt + .network.txt par état).

## Pré-flight (faits DB vérifiés avant tout flux)

- Promo borne `BORNEAUDIT5` EXISTE: `kiosk_promos` id=1, branch_id=1, type=`amount`, value=**5.00**, min_cart=0.00, active=1, uses_count=0 (au départ).
- Surfaces routées (vérifié `resources/js/router/modules/*.js`): `/admin/encaissement`, `/admin/historique`, `/admin/cash-overview`, `/admin/transactions`, `/admin/pos-orders` + `show/:id`, `/admin/pos-orders-tracker`.

## États couverts (incrémental)

### Run préliminaire borne (ordre 4518 — Coca seul, wizard Tacos non complété au 1er essai)
- `E10-02-cart-avant-promo` — panier borne 1 ligne Coca-Cola 33cl 1,50 € (sous-total 1,50 €, total 1,50 €)
- `E10-03-cart-apres-promo` — promo BORNEAUDIT5 appliquée, bloc « ✓ Code promo BORNEAUDIT5 appliqué (−1,50 €) », remise -1,50 € (clamp 5,00→1,50 = cart total), TOTAL **0,00 €**
- `E10-04-payment-counter` — écran paiement Plan B « PAIEMENT À LA CAISSE », « TOTAL À RÉGLER : **0,00 €** »
- `E10-05-cash-instruction` — « Rendez-vous en caisse » #A0002 — montant affiché **1,50 €**
- `_E10-order.json` + `_log-E10-borne.txt`

## Intégrité numérique relevée (chiffre par chiffre)

### Ordre 4518 (borne, Coca 1,50 € + BORNEAUDIT5)
| Surface | Champ | Valeur |
|---|---|---|
| Borne /kiosk/cart (après promo) | sous-total | 1,50 € |
| Borne /kiosk/cart (après promo) | remise promo | **-1,50 €** |
| Borne /kiosk/cart (après promo) | TOTAL | **0,00 €** |
| Borne /kiosk/payment (Plan B) | TOTAL À RÉGLER | **0,00 €** |
| POST /frontend/order (réponse 201) | total | **1.5** |
| Borne /kiosk/cash-instruction | montant | **1,50 €** |
| DB orders id=4518 | subtotal / discount / total_tax / total | 1.500000 / **0.000000** / 0.140000 / **1.500000** |
| DB kiosk_promos BORNEAUDIT5 | uses_count après commande | **0** (inchangé) |

### Commande borne TRAÇANTE (run 2 — ordre 4531, queue A0006)
- Composition réelle DB : Tacos id=26 (Poulet mariné + Algérienne + SANS MENU) 8,50 € + Coca-Cola 33cl id=52 1,50 € → sous-total 10,00 €.
- `E12-01-cart-avant-promo` — panier 2 lignes, sous-total 10,00 €, total 10,00 €
- `E12-02-cart-apres-promo` — « ✓ Code promo BORNEAUDIT5 appliqué (−5,00 €) », remise -5,00 €, TOTAL **5,00 €**
- `E12-03-payment-counter` — « PAIEMENT À LA CAISSE », « TOTAL À RÉGLER : **5,00 €** »
- `E12-04-cash-instruction` — `#A0006`, montant **10,00 €** (URL `?number=A0006&total=10`)
- `_E12-order.json` (request/response complets) + `_log-E12-borne-tracer.txt`

### Ordre traçant 4531 — chaîne requêtes capturée (preuve payload)
| Étape | Champ | Valeur |
|---|---|---|
| POST /frontend/order/quote (200) req | kiosk_promo_code | `"BORNEAUDIT5"` (ENVOYÉ) |
| POST /frontend/order/quote (200) resp | subtotal / discount / total_tax | 10 / **0** / 0.91 |
| POST /frontend/order (201) req | kiosk_promo_code / discount / subtotal / total | `"BORNEAUDIT5"` / **0** / 10 / 10 |
| POST /frontend/order (201) resp | id / queue / subtotal / discount / total / total_tax | 4531 / A0006 / 10 / **0** / **10** / 0.91 |
| DB orders id=4531 | subtotal / discount / total_tax / total / payment_status / fiscal_seq | 10.000000 / **0.000000** / 0.910000 / **10.000000** / 15 / NULL |
| DB order_items 4531 | Tacos / Coca | 8.50 (VAT 10% → 0.77) / 1.50 (VAT 10% → 0.14) |
| DB kiosk_promos | uses_count après 2 applications | **0** |

### Suivi caisse de l'ordre 4531 / A0006 (T = 10,00 € tel que persisté)
Captures AVANT encaissement (E20-*) puis encaissement réussi (E21-*) puis APRÈS (E22/E23-*) :
- `E20-00-cash-overview-baseline` — Espèces 3 mvts · 29,80 € ; fond de caisse 50,00 € ; « 1 encaissement(s) espèces sans session caisse — 25,00 € à régulariser » (préexistant, créé par une autre vague)
- `E20-01-encaissement-liste` — carte A0006 : origine **Borne**, client **« Client borne »**, articles 1× Tacos + 1× Coca-Cola 33cl, montant **10,00 €** ✓
- `E20-02-modal-encaissement` / `E21-01-modal` — modal hero **10,00 €** ✓ ; 4 modes (Espèces/Terminal manuel SumUp/Mobile/Ticket restaurant)
- `E20-03-apres-encaissement` — ÉCHEC tentative 1 : `POST counter-collect/4531/confirm` → **401** (token admin révoqué par une vague parallèle re-loggant `admin@lecayenne.fr` — Sanctum revoke). Artefact réseau conservé. Aucun message d'échec UI capté (toasts vides) — voir E-SUS-5.
- `E21-02-apres-confirm` — succès tentative 2 : confirm **200**, resp subtotal 10 / discount 0 / total_tax 0.91 / total 10
- `E20-04-pos-orders-show` (avant) — #1206264531 · N°A0006, badges « À Encaisser » + « Accepter », paiement « Comptoir différé », Sous-Total 10,00 € / Remise 0,00 € / Total 10,00 €, client « Client Borne »
- `E23-04-pos-orders-show` + `E22-02-receipt-modal-force` (après) — badges « Payé » + « Accepter », « Type de paiement: Espèces », mêmes montants ✓, bouton « Rembourser » apparu
- `_E22-receipt.txt` — receipt `#print` (vue3-print-nb, modal caché → extraction textContent) :
  SIRET 10417050100019 / TVA intra FR19104170501 / **Opérateur: Admin Le Cayenne** / Tacos **7,73 €** (HT) / Coca **1,36 €** (HT) / **Sous-total: 9,09 €** (HT) / **Total taxes: 0,91 €** / **VAT (10%) · Base HT 9,09 € → 0,91 €** / Remise: 0,00 € / **Total: 10,00 €** / Espèces / N°A0006 / **N° ticket NF525: 2172** / Empreinte audit 466d91cc7af8
- `E23-06-historique` — ligne : 1206264531 | Borne | N°A0006 | client **« Admin Le Cayenne »** | **10,00 €** | **Payé** | N° FISCAL **2172** | 02:35, 12-06-2026 | statut « Accepter »
- `E23-07-tracker` — A0006 dans colonne **« EN PRÉPARATION »** : 1× Tacos, 1× Coca-Cola 33cl, **10,00 €**, client « Admin Le Cayenne »
- `E23-08-cash-overview-apres` — Espèces 4 mvts · **39,80 €** (= 29,80 + **10,00 = T exact** ✓) ; ligne mouvement « 02:44 N°A0006 Borne Espèces 10,00 € » ✓ ; MAIS « Espèces encaissées (session en cours): **-3,80 €** » (voir E-SUS-4)
- `E23-09-transactions` — ligne **COUNTER-4531-20260612024459 | Espèces | 1206264531 | + 10,00 €** ✓
- `E23-10-kds` — A0006 NON visible parmi les 8 cartes rendues ; badge overflow « **+8 en attente** » ; cartes visibles A0001/A0002/A0003 (bornes NON payées) avec badge jaune « EN ATTENTE ENCAISSEMENT » **et bouton « Démarrer » actif** (voir E-SUS-6)

### DB après encaissement 4531 (vérité terrain)
| Table | Champ | Valeur |
|---|---|---|
| orders 4531 | payment_status / pos_payment_method / pos_received_amount / fiscal_sequence_no | **5 (payé)** / 1 (espèces) / 10.00 / **2172** (= receipt ✓ = historique ✓) |
| cash_movements | id 226 : session **19**, in, **10.00**, « Encaissement borne au comptoir (SSOT modal) » | ✓ |
| transactions | COUNTER-4531-20260612024459, counter_cash, 10.00 | ✓ |
| order_payments | **AUCUNE ligne** pour 4531 | (voir E-OBS-7) |
| TVA recoupement | orders.total_tax 0.910000 = receipt « Total taxes 0,91 € » = quote 0.91 ; 9,09 HT + 0,91 = 10,00 ✓ ; 10% × 9,09 = 0,909 ✓ | **PAS de divergence TVA** |

## Anomalies suspectées

### E-SUS-1 — PROMO BORNE AFFICHÉE AU CLIENT MAIS NON PERSISTÉE (divergence de montant cross-écran borne + cross-surface)
- **Faits** : panier borne et écran paiement Plan B affichent TOTAL **0,00 €** après application BORNEAUDIT5 (clamp -1,50 € sur panier 1,50 €). La commande créée (id 4518, POST 201) repart à **1,50 €** : `orders.discount=0.000000`, `orders.total=1.500000`, `kiosk_promos.uses_count` reste 0. L'écran cash-instruction (qui lit le total du POST) annonce **1,50 €** au client juste après lui avoir montré 0,00 €.
- **Evidence** : `E10-03-cart-apres-promo.png` (TOTAL 0,00 €) vs `E10-05-cash-instruction.png` (1,50 €) + `_E10-order.json` + dump DB ci-dessus.
- **Conséquence observable** : le client borne voit deux montants différents sur deux écrans consécutifs de la MÊME borne ; la caisse encaissera le montant NON remisé.
- (Sévérité = adversaire ; selon le brief « TOUTE divergence de montant = P0 ».)
- **CONFIRMÉ run 2 (ordre 4531)** : panier 5,00 € + écran paiement 5,00 € → cash-instruction 10,00 € → DB total 10,00 €, discount 0. Le code promo EST envoyé (`kiosk_promo_code:"BORNEAUDIT5"` dans quote + create) — c'est le backend qui le laisse tomber.
- **Root cause code (re-greppé, verify-before-report)** :
  - `app/Services/Order/OrderQuoteService.php:209-219` — `calculatePricing()` kiosk construit `PricingRequest::forKiosk(0, $branchId, $items, coupon_id, …)` SANS jamais passer `kiosk_promo_code` ; le code n'entre que dans la signature canonique (`OrderQuoteService.php:416` `'promo_code' => (string) $request->input('kiosk_promo_code','')`), jamais dans le calcul.
  - `withKioskLoyaltyDiscount()` (OrderQuoteService.php:239+) ne traite QUE `loyalty_code`.
  - Par contraste `app/Services/Kiosk/PricingPreviewService.php:66-99` applique bien `KioskPromo::findValid` + `computeDiscount` — mais ce service n'est PAS sur le chemin quote/create.
  - Affichage panier : validation lecture-seule `POST /api/frontend/promo/validate` (`resources/js/store/modules/kioskCart.js:191-198`, total local `kioskCart.js:253-258`) → l'UI promet une remise que la création de commande ne réalise jamais.
- **Recoupement mémoire projet** : correspond au finding « promo borne fantôme / promo dormante » de l'ultra-audit W1-W3, healé sur la branche `heal/ultra-audit-w4-2026-06-11` — heal NON présent sur cette branche release (worktree release-v1-2026-06-10). À arbitrer comme régression-de-branche plutôt que défaut nouveau, mais sur CETTE branche le défaut est démontré bout-en-bout.

### E-OBS-2 — POST /frontend/order/quote répond 401 « Unauthenticated » au 1er essai, 200 au retry (systématique ×2 dans le run)
- Evidence : `_E12-order.json` (séquence 401→200 à l'application du checkout ET à l'arrivée sur /payment). Possible parenté avec le gate connu « 401 one-shot boot kiosk broadcasting » mais ici c'est sur `/frontend/order/quote`, pas broadcasting — listé pour arbitrage adversaire, pas re-compté comme le gate existant.

### E-SUS-3 — IDENTITÉ CLIENT DIVERGENTE entre surfaces pour la MÊME commande borne 4531 (divergence de libellé/champ)
- /admin/encaissement carte : « **Client borne** » (E20-01)
- /admin/pos-orders/show : « **Client Borne** » (E20-04/E23-04)
- /admin/historique colonne CLIENT : « **Admin Le Cayenne** » (E23-06)
- /admin/pos-orders-tracker carte : « **Admin Le Cayenne** » (E23-07) — alors que la carte voisine A0010 (autre vague) affiche « Client passage »
- Le même fait (qui est le client de la commande borne) a 3 réponses selon la surface. Parenté avec le thème operator-identity NF525 connu (creator kiosk) mais ICI c'est le champ CLIENT affiché au gérant. Receipt « Opérateur: Admin Le Cayenne » est lui CORRECT (caissier encaisseur).

### E-SUS-4 — cash-overview « Espèces encaissées (session en cours) : -3,80 € » alors que MON encaissement +10,00 € vient d'aboutir
- Faits DB : **3 sessions tiroir OUVERTES simultanément** branch 1 (id 19 user 1 ouverte 06-10 70,00 € ; id 20 user 3 ; id 22 user 11 ouverte 02:39 50,00 €). Mon mouvement (+10,00, id 226) est rattaché session **19** ; le « session en cours » affiché (fond 50,00, espèces -3,80) reflète la session **22** (qui ne contient qu'un cashback -3,80 d'une autre vague).
- Conséquence observable : le caissier qui VIENT d'encaisser 10,00 € en espèces lit « -3,80 € » de cash session — le panneau session ne correspond pas à la session qui reçoit ses mouvements. Contexte : sessions multiples créées par vagues parallèles (artefact harness possible) MAIS le branch autorise N sessions ouvertes simultanées et l'UI n'indique pas QUELLE session est « en cours ». Arbitrage adversaire requis (single-box V1 = 1 caisse).
- Evidence : `E23-08-cash-overview-apres` + dump `cash_drawer_sessions`/`cash_movements` (dans ce rapport).

### E-SUS-5 — Échec d'encaissement 401 SILENCIEUX côté UI (modal confirm)
- Tentative 1 (E20) : `POST /api/admin/pos/counter-collect/4531/confirm` → **401**, AUCUN toast/alerte capté (scan `[class*="toast"],[class*="alert"]` vide), le modal s'est fermé, la file s'est rafraîchie comme si de rien n'était ; la commande restait « À encaisser » sur toutes les surfaces. Un caissier réel croirait avoir encaissé.
- Cause du 401 = révocation de token par relogin parallèle (artefact harness/multi-poste), mais l'ABSENCE de feedback d'échec est un comportement UI observable indépendant de la cause.
- Evidence : `E20-03-apres-encaissement.network.txt` (ligne `401 POST .../counter-collect/4531/confirm`), `_log-E20-caisse.txt` (`POST-CONFIRM toasts: []`), `E23-06`-historique pré-retry (état resté À encaisser jusqu'au retry E21).

### E-SUS-6 — KDS : bouton « Démarrer » ACTIF sur des commandes borne « EN ATTENTE ENCAISSEMENT » (lecture seule, non cliqué)
- `E23-10-kds` : cartes A0001/A0002/A0003 (payment_status 15 = à encaisser, vérifié DB) affichent le badge jaune « EN ATTENTE ENCAISSEMENT » ET un bouton « Démarrer » noir pleinement actif. Le release-guard KDS (heal connu de la branche `heal/ultra-audit-w4`) ne semble pas présent sur CETTE branche — non testé au clic (mandat lecture seule). À arbitrer.
- Présence A0006 sur KDS : NON visible parmi les 8 cartes rendues, badge « +8 en attente » (overflow). Présence à l'écran NON confirmée — voir E24 (fullpage) ci-dessous.

### E-OBS-7 — `order_payments` reste VIDE pour un encaissement comptoir réussi
- Le flux counter-collect écrit `transactions` (+ `cash_movements`) mais aucune ligne `order_payments` (table à colonnes mode/tendered/change_amount). Si une surface lit `order_payments` pour la répartition par mode, elle divergera. /admin/transactions lit `transactions` (ligne présente ✓), cash-overview agrège les mouvements (✓). Dualité de schéma à arbitrer.

### E-OBS-8 — Attentes KDS affichées en heures (« ATTENTE 19:18 », « 15:40 », « 14:42 ») sur des commandes seed/anciennes
- `E23-10-kds` : cartes A0001 (19:18), A0005 (15:40), Z0901-Z0904 (14:42) — données de vagues précédentes jamais purgées ; l'affichage HH:MM d'une attente >14h reste ambigu (14:42 ressemble à une heure de la journée). Observation cosmétique, contexte DB jetable partagée.

### E-SUS-9 — VENTES CAISSE DIRECTES INVISIBLES sur /admin/transactions ET /admin/cash-overview (divergence de montant, DB-prouvée)
- **Faits** : commande caisse 4543 (A0012, espèces 4,80 €, encaissée 02:58, `cash_movements` id 227 +4,80 session 19, fiscal 2173) :
  - /admin/transactions : AUCUNE ligne (E32-09 ; la liste affiche par contre `COUNTER-4531` borne +10,00 €)
  - /admin/cash-overview : répartition Espèces INCHANGÉE « 4 · 39,80 € » avant/après la vente (E23-08 = E32-08 au centime) ; le mouvement 02:58 n'apparaît pas dans la liste
- **Généralisation DB (requête jointe)** : **20 commandes `source_surface='pos'` aujourd'hui → 0 ligne `transactions`** (dont A0004 9,00 €, A0005 4,50 €, Z0901-Z0906 ≈ 50 €, 5× 24,00 € : toutes espèces). Seules les lignes `COUNTER-*` (encaissement borne via counter-collect) et 1 ligne synthétique de test (F2-PAY-4515) existent.
- **Root cause code (re-greppé)** : `app/Http/Controllers/Admin/CashOverviewController.php:111-116` — la page « unifiée » est construite sur `Transaction::query()->where('type','payment')` exclusivement ; le flux de paiement POS direct (PaymentComponent → POST admin/pos) ne crée JAMAIS de ligne `transactions` (ni `order_payments`). Le tiroir (cash_movements) voit la vente, la « Vue Caisse Unifiée » ne la voit pas.
- **Conséquence observable** : le GRAND TOTAL et la répartition par mode du gérant excluent TOUTES les ventes caisse directes — sous-évaluation massive du CA encaissé affiché (P0 candidat selon brief « toute divergence de montant »).

### E-SUS-10 — N° DE FILE AMBIGU : la file d'encaissement mélange plusieurs business dates avec des N° identiques
- **Faits DB** : 48 commandes `payment_status=15` de business_date **2026-06-10** (A0011→A0141) encore en attente + 9 d'aujourd'hui (A0001→A0014) — les N° se chevauchent. DEUX « N°A0011 » en attente simultanément (06-10 : Eau Plate 1,00 € ; 06-12 : Galette+2 Coca 9,50 €).
- **Surfaces** : /admin/encaissement (E23-01/E32-01, 54-58 cartes « Total en attente 272,00 € ») affiche les deux sans date — seule une puce d'attente relative (« 48h ») distingue ; ma recherche « A0012 » y matche la carte borne du 06-10 (Glace 3,80 €) alors que le tracker, filtré au jour, montre le A0011 d'aujourd'hui (9,50 €). Le MÊME N° renvoie des commandes différentes selon la surface.
- **Conséquence observable** : un client annonce « A0011 » à la caisse → risque réel d'encaisser la mauvaise commande. (Contexte : backlog 06-10 = données de test jamais purgées ; le mécanisme de réutilisation quotidienne des N° + l'affichage sans date restent structurels.)

### E-SUS-11 — KDS : la commande borne PAYÉE A0006 n'est PAS visible (slots occupés par des commandes NON payées)
- `E24-01-kds-fullpage` + scan DOM fullpage : queues rendues = A0001/A0002/A0003 (non payées, « EN ATTENTE ENCAISSEMENT ») + A0005 + Z0901-Z0904 ; badge « **+11 en attente** ». A0006 (payée 02:44, à produire) est dans l'overflow non rendu, DERRIÈRE des commandes que la cuisine ne peut pas démarrer.
- Réponse factuelle au point 5 du brief (« la commande borne apparaît-elle sur /kds après encaissement ? ») : **NON à l'écran** (présence dans la file overflow non vérifiable visuellement, mandat lecture seule). La politique d'affichage semble ancienneté-d'abord sans tenir compte de l'encaissabilité.

### E-OBS-12 — Élément « 5,80 € » récurrent dans le chrome admin (non résolu)
- Un montant « 5,80 € » apparaît en tête des scans innerText des pages show (4531 ET 4543) et dans les h-éléments du header tracker, sans correspondre à la commande affichée. Hors des DOM tronqués 80KB → non localisé. Micro-observation pour l'adversaire (probable widget topbar).

### E-OBS-13 — Borne : « Paiement en espèces uniquement à la caisse. » vs caisse qui propose 4 modes pour la même commande
- `E12-04-cash-instruction.png` : sous le n° A0006, la borne affirme « Paiement en **espèces uniquement** à la caisse. » ; or le modal d'encaissement de cette même commande (`E21-01-modal.png`) propose Espèces / Terminal (manuel) / Mobile / Ticket restaurant. Le client borne pourrait renoncer faute d'espèces alors que la caisse accepte sa carte. Copy Plan B à arbitrer.

## Confirmations visuelles (lecture multimodale effectuée)
PNG lus et analysés via Read : `_probe-wizard-step2` (étape QUEL MENU, 4 cartes, footer Total 8,50 €) ; `E12-02-cart-apres-promo` (Sous-total 10,00 / promo verte −5,00 / Total 5,00 / CTA « Valider ma commande 5,00 € ») ; `E12-04-cash-instruction` (#A0006, Montant à régler 10,00 €, « espèces uniquement ») ; `E20-04`/`E22-02` (show avant/après : badges, montants, Client Borne, bouton Rembourser apparu) ; `E21-01` (modal « Encaisser la commande borne » N° A0006, MONTANT TOTAL 10,00 €, reçu 10,00, « Confirmer & Imprimer ticket ») ; `E23-01` (liste encaissement 54 cartes, bandeau total 272,00 €, puces attente 48h) ; `E23-07` (tracker : A0006 colonne EN PRÉPARATION 10,00 €, client Admin Le Cayenne) ; `E23-10`/`E24-01` (KDS 8 slots, A0001-A0003 « EN ATTENTE ENCAISSEMENT » avec bouton Démarrer actif, badge +8/+11 en attente, A0006 absent) ; `E30-02` (bounce dashboard mi-flux = éjection 401) ; `E31-03` (modal POS 4,80 €, Espèces, reçu 5, Monnaie à rendre 0,20 €) ; `E32-06` (historique : 1206264543 Caisse/N°A0012/Client passage/4,80 €/Payé/2173 ; lignes borne CLIENT=Admin Le Cayenne ; 2414 entrées) ; `E32-08` (Vue Caisse Unifiée 12/06 : GRAND TOTAL 39,80 € · 4 tx, CAISSE 25,00 € · 1 tx, BORNE 14,80 € · 3 tx — la vente caisse réelle 4,80 € ABSENTE du bucket CAISSE ; réconciliation session « ouverte à 02:39 » fond 50,00 / -3,80 / 46,20).

### Commande CAISSE directe (ordre 4543 — A0012, Tiramisu 3,80 + Eau Plate 1,00, espèces)
- `E31-01-pos-cart` — panier POS 2 lignes, Total 4,80 €
- `E31-02-payment-modal` — modal paiement (PaymentComponent FROZEN, observation) total **4,80 €**
- `E31-03-cash-5` — espèces 5,00 € saisis, rendu **0,20 €**
- `E31-04-apres-confirm` — POST 201, id 4543 serial 1206264543 total 4.8
- `E31-05-receipt` + `_E31-receipt.txt` — receipt POS : Opérateur Admin Le Cayenne / Tiramisu 3,45 € HT / Eau 0,91 € HT / SOUS-TOTAL 4,36 € / TOTAL TAXES 0,44 € / VAT (10%) Base HT 4,36 € → 0,44 € / REMISE 0,00 € / TOTAL **4,80 €** / Espèces 5,00 € / **Rendu : 0,20 €** / **N°A0012** / N° ticket NF525 **2173**
- `E32-01..10` — sweep des 7 surfaces pour 4543 (détails ci-dessous)
- `E30-*` — tentative 1 AVORTÉE par bounce 401 (artefacts conservés : `E30-02-payment-modal.png` montre le dashboard à la place du POS = éjection mi-flux)
- `E24-01-kds-fullpage` / `E24-02` — KDS fullpage : queues rendues A0001/A0002/A0003/A0005/Z0901-Z0904, badge « +11 en attente », A0006 et A0012 absents de l'écran

### Intégrité numérique ordre CAISSE 4543 (T = 4,80 €)
| Surface | Champs | Valeur | OK ? |
|---|---|---|---|
| POS panier | total | 4,80 € | ✓ |
| Modal paiement (frozen) | total / rendu sur 5,00 | 4,80 € / 0,20 € | ✓ |
| POST réponse | subtotal/discount/total_tax/total | 4.8 / 0 / 0.44 / 4.8 | ✓ |
| Receipt | HT 4,36 + TVA 0,44 = TTC 4,80 ; rendu 0,20 ; NF525 2173 | | ✓ |
| DB orders | subtotal/discount/total_tax/total/pos_received/fiscal | 4.80/0/0.44/4.80/5.00/2173 | ✓ |
| DB order_items | Tiramisu tax 0.35 + Eau 0.09 = 0.44 | | ✓ |
| /admin/pos-orders/show | Sous-total/Remise/Total ; Payé ; Espèces | 4,80*/0,00/4,80 (scan: 3,80+1,00 lignes) | ✓ |
| /admin/historique | Caisse \| N°A0012 \| Client passage \| 4,80 € \| Payé \| 2173 \| En préparation | | ✓ |
| /admin/pos-orders-tracker | présent (contient A0012=true) | | ✓ |
| /admin/cash-overview | répartition Espèces : **AUCUN incrément** (39,80 € avant = 39,80 € après) | | ✗ E-SUS-9 |
| /admin/transactions | **AUCUNE ligne** | | ✗ E-SUS-9 |
| /kds | non visible (overflow +11) | | n/a (E-SUS-11) |
| DB cash_movements | +4,80 session 19 | | ✓ (tiroir OK) |
| DB transactions | 0 ligne | | ✗ (source du ✗ ci-dessus) |

## Gates/P3 déjà arbitrés revus en passant (NE PAS re-compter)

- **taxes.name « VAT (10%) » DATA** — revu sur les DEUX receipts (borne 4531 + caisse 4543) : « VAT (10%) · Base HT … » ; toujours le nom DATA anglais.
- **« Accepter » infinitif statut** — revu : badge et select du show 4531, colonne STATUT historique (« Accepter »).
- **Dates listes à tirets** — revu : historique/transactions « 02:35, 12-06-2026 » / « 02:44, 12-06-2026 » (format à tirets toujours présent).
- **401 one-shot boot kiosk broadcasting** — non re-compté ; la variante observée sur `/frontend/order/quote` est listée séparément (E-OBS-2) car endpoint différent.
- Les autres gates de la liste (contraste orange, prix-étapes wizard, images Boisson/Frites, spam log wizard, aria-pressed upsell, Title Case PaymentComponent, seed SUP-LOY-1, « : » orphelin tracker, tutoiement cash-overview, deep-link cash-instruction « #— ») n'ont pas été re-déclenchés/re-comptés par cette vague.

## Synthèse des états couverts (liste finale)

Borne (1080×1920) : E10-02/03/04/05 (run préliminaire 4518) ; E12-01/02/03/04 (traçante 4531) ; `_probe-wizard-step*.png` (structure wizard Tacos).
Caisse (1440×900) : E20-00/01/02/03/04/05/06/07/08/09/10 (avant + échec 401) ; E21-01/02/03 + E22-01/02 (encaissement réussi + receipt) ; E23-01/04/05/06/07/08/09/10 (après) ; E30-01/02/03 (tentative avortée) ; E31-01..05 (vente caisse) ; E32-01..10 (sweep 4543) ; E24-01/02 (KDS fullpage).
Chaque état = quartet PNG + .dom.html (80KB) + .console.txt + .network.txt ; logs `_log-*.txt` ; data `_E10-order.json`, `_E12-order.json`, `_E20-caisse.json`, `_E23-caisse.json`, `_E31-order.json`, `_E32-caisse.json`, `_E22-receipt.txt`, `_E31-receipt.txt`.

## Notes harness (pour l'adversaire)

- DB `foodking_e2e` PARTAGÉE entre vagues parallèles pendant ce round : créations/refunds/sessions concurrentes (A0007-A0011, A0013+, TXN-*, session 22) visibles dans mes captures. Les baselines cash-overview sont contaminées — les recoupements décisifs sont faits au niveau DB par order_id.
- Compte `admin@lecayenne.fr` partagé → révocations Sanctum répétées mi-flux (cause des E20-03 401 et E30 avorté). Les scripts E21/E31 intègrent retry+re-login ; tout artefact 401 est conservé tel quel.
- Aucun fichier source modifié ; scripts jetables `tests/e2e/_d1-E-*.mjs` ; aucune commande git/artisan/npm.
