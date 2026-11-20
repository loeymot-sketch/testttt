# Vague B — CAISSE (POS /admin/pos) + aval cuisine — tour 1 (capture + constat, AUCUNE correction de code)

Date : 2026-10-23 (heure serveur 00:0x → 01:4x). Aucune zone gelée touchée (`pos-wizard.js/.css`, `admin-pos-v4.blade.php`, `PaymentComponent.vue` : lecture seule, jamais modifiés). Aucun appel Uber sortant (UBER_DIRECT_ENABLED=false).

## (a) Environnement
- Backend `http://127.0.0.1:8766` (php artisan serve, 6 workers), base `foodking_e2e`, `APP_ENV=local`, soketi :6001 + `queue:work redis` actifs (vérifié `ps`). Node v22.23.2, Playwright `--workers=1 --retries=0`, 1 à 2 navigateurs max.
- Comptes : pos@ (id 3), admin@ (id 1), chef@ (id 4), mot de passe 123456. Compte jetable créé par moi : `E2E-CAISSE-Caissier` (id 915, rôle « POS Operator », e2e-caisse.caissier@lecayenne.local) pour voir le formulaire d'OUVERTURE de caisse (pos@ a déjà une session ouverte depuis le 06/07).
- Base de test fortement polluée par les campagnes précédentes : 80+ cartes en cuisine, 506 commandes actives dans la fenêtre de service, 12 tournées d'hier/de cette nuit non parties, 1 928 `failed_jobs`, trous de séquence fiscale historiques (voir §d-NF525). Cela explique plusieurs échecs « instrument ».
- Disque : 10 Go → 2,6 Go libres en fin de vague (autres vagues comprises). 31 PNG de ma vague (~12 Mo avec DOM/console/réseau).
- Specs écrites : `tests/Playwright/e2e-complet-2026-10-22/B-caisse-tiroir-ventes.spec.js`, `B-livraison-tournees-uber.spec.js`, `B-z-cloture-tracker.spec.js`, sondes `B-sonde-toast-remise.spec.js`, `B-sonde-item-details-404.spec.js`, `B-sonde-temps-reel.spec.js`. Résultats chiffrés bruts : `tests/e2e/__screenshots__/e2e-complet-B/_resultats-*.json`.
- Interventions manuelles sur la base (instrument, pas produit) : 5 sessions de tiroir du compte jetable (ids 71-75) ont été passées à `closed` par SQL entre deux relances de ma propre spec (une spec relancée trouvait le tiroir déjà ouvert). Aucune autre écriture directe.

## (b) États capturés (quatuor PNG + .dom.html + .console.json + .network.json, dossier `tests/e2e/__screenshots__/e2e-complet-B/`)
| État | PNG |
|---|---|
| Tiroir de pos@ : session héritée 108 j, 306 mouvements, attendu 2 661,70 € | `01-tiroir-pos-session-heritee.png` |
| Ouverture de caisse (fond par défaut 50 €, +5/+10/+20/+50, Effacer) | `02-ouverture-formulaire.png`, `03-apres-ouverture.png` |
| Vente emporter ESPÈCES (5,40 €, 10 € donnés, rendu 4,60 €) + ticket | `04-paiement-especes-rendu.png`, `05-ticket-especes.png` |
| Vente emporter CARTE (TPE simulé, 7,30 €) + ticket | `06-paiement-carte-tpe.png`, `07-ticket-carte.png` |
| Vente avec REMISE (refusée par le serveur) | `08-remise-appliquee-panier.png`, `09-remise-apres-clic-commande.png`, `17-toast-remise-{1280x720,1440x900,1920x1080}-1200ms.png` |
| Cuisine après les ventes (KDS chef) | `11-cuisine-apres-ventes.png`, `40-kds-apres-vente-caisse-ws.png` |
| Tiroir : attendu, mouvements, clôture (comptage exact), après clôture | `12-…`, `13-…`, `14-tiroir-cloture-formulaire.png`, `15-…` |
| Caisse 1280×720 / 1440×900 / 1920×1080 | `16-caisse-<taille>.png` |
| Bouton « Livraison » : actif / armé (1 appui) / suspendu / remis | `20-…`, `21-…`, `22-…`, `23-…` |
| Planche « Tournées » RÉELLE (sans route simulée), 1280 et 1920 | `24-planche-tournees-reelle.png`, `25-planche-1280x720.png`, `25-planche-1920x1080.png` |
| Liste des Z, modale du rapport X, suivi des commandes | `30-liste-rapports-z.png`, `31-rapport-x-modale.png`, `32-suivi-commandes.png` |

Relu visuellement (Read) : 01, 02, 05, 09, 14, 16-1280, 22, 24, 25-1280, 30, 32. Aucune clé i18n brute (balayage `lectureEcran` sur chaque état : `brut: []` partout). Touchabilité par `elementFromPoint` à 1280/1440/1920 : caisse = 6/6 cibles OK (Caisse, Livraison, Tournées, Commande, Remise, nom client), 0 débordement horizontal ; planche = 80 à 82 boutons/liens, 0 recouvert, carte dans la fenêtre.

### Chiffres de contrôle (transverses)
| Vente | panier = ticket = `orders.total` | ligne cuisine (API KDS chef) | n° fiscal |
|---|---|---|---|
| Espèces #8656 | 5,40 = 5,40 = 5,400000 | Coca-Cola Zero ×1 + Tiramisu ×1 (visible en 142 ms) | 3141 |
| Carte #8658 | 7,30 = 7,30 = 7,300000 | Sprite + Oasis + Glace (161 ms) | 3142 |
| Espèces #8674 (spec Z) | 4,50 = 4,50 = 4,500000 | — | 3147 |
- Σ `order_items.total_price` = `orders.total` sur chaque vente. Remise : refusée, 0 commande créée (SQL `pos_customer_name='E2E-CAISSE-Remise'` → 0).
- Tiroir : fond 100 + espèces 5,40 = attendu affiché 105,40 = attendu en base = comptage 105,40 → écart 0,00, session `reconciled` (la vente CARTE n'écrit aucun mouvement : 1 seul mouvement `order_payment in 5.40`, correct).
- Une vente espèces apparaît au X puis au Z : X avant 1 941,85 € → après 1 946,35 € (delta exact 4,50 ; méthode « 1 » +4,50 ; +1 vente) ; Z-28 clôturé = 1 950,85 € / 296 ventes / méthode « 1 » 1 390,90 € = X(avant ma 2ᵉ vente) + 4,50 ; Z-28 `prev_hash` chaîné au Z-27.
- NF525 : `php artisan fiscal:verify-chain --all` — AVANT : « SWEEP COMPLETE — CHAIN OK on every active branch (7 total) » ; APRÈS (3 relevés dont après clôture Z-28 et en fin de vague) : identique, 7 branches CHAIN OK.
- Temps réel (soketi + worker actifs) : sonde indépendante `B-sonde-temps-reel` → trame `OrderCreated` sur `private-branch.1` reçue 358 ms après la vente ; `domain_events` : 158 événements sur 2 h, tous `broadcast_at` renseigné, 0 en attente. Le temps réel fonctionne.

## (c) Résultats des specs existantes (causes classées)
| Spec | Résultat | Cause |
|---|---|---|
| M3 (11 tests) | 8 verts, 3 rouges | **M3-02** (2 assertions molles : pain/galette en emoji = M3-006 ; chiffre de quantité figé à « 1 » = M3-004) → **connu**, `pos-wizard.js` gelé. **M3-05** et **M3-10** (`tracker-order-<id>` introuvable pour la commande téléphone / la commande espèces neuves) → **instrument (base saturée)** : le suivi borne à 300 actives triées par ancienneté (`PosOrderController.php:30`) et l'écran l'annonce (« 405 affichées sur 506 », capture 32) ; la vente neuve #8674 (statut 7) n'est pas dans les 199 lignes affichées. Les deux tests passaient sur une base moins chargée. |
| M4 (5 tests) | 01 vert ; 02 rouge ; 03, 05 verts après relance ciblée (`M4_RESUME=1 -g`) ; 04 rouge | **M4-02** « SLOT non plein avant le départ » (`M4…spec.js:727`) → **instrument** : le créneau 20:30 contient 6 arrêts dont les 6 commandes A, B, C, D, TR, E créées par la spec elle-même (plein = 6/6) ; le test s'invalide lui-même. **M4-04** `expect(F.text).toMatch(/complet/)` (`:1026`) → **instrument (texte attendu)** : le serveur refuse bien en 422, avec « Ce créneau est trop proche : choisissez un horaire plus tardif » (`DeliveryWaveService.php:271`) au lieu de « complet ». Le refus fonctionne. Serial : sans relance, 03/04/05 ne s'exécutent pas. |
| M6 (9 tests) | 7 verts, 1 rouge, 1 skipped | **C** « K2 doit afficher le badge RAPPELÉ (actives au board : 49, rendu max 24) » → **connu** (plafond de 24 cartes en cuisine, décision propriétaire en attente, RAPPORT_FINAL). Skipped = test « M · minuit réel » (condition horaire). Latences : POST store 141-403 ms, KDS reflète PREPARING en 3 ms, PREPARED→servies 529 ms. Réserve : le compteur « WS OrderCreated n=0 / apparition DOM n=0 » de M6-A n'est **pas probant** (plafond 24 cartes + compteur) ; ma sonde indépendante reçoit bien la trame (ci-dessus). |
| `uber-direct-2026-10-22/planche-tournees.spec.js` | 3/3 verts | — (routes simulées par `page.route`, bascule Uber → prix → confirmer → retour à nos livreurs ; 1280 et 1920 touchables). |
| Mes specs B (tiroir/ventes 5 tests, livraison 2, Z 3, sondes 3+1+1) | tous verts au dernier passage | Corrections d'instrument faites en cours de route (champ fond pré-rempli à 50 €, doublon de ligne identique fusionné au panier, `input[type=number]`, sélecteur du type de remise propre au bloc, forme de réponse du X `data.totals`). |

## (d) Constats candidats (preuve, 2ᵉ moyen indépendant)
**B-001 — P3/P2 — la caisse propose encore le bloc « Remise », le refus ne tombe qu'au clic « Commande ».**
Preuve : capture 08 (Remise Fixe 1 € appliquée, Total 4,40 €, bouton « Commande · 4,40 € ») puis 09 : `POST /api/admin/pos/quote → 422`, message affiché « Les remises manuelles sont désactivées sur cette caisse (correction fiscale TVA/HT en attente). Retire la remise pour encaisser. », aucune vente créée. Source : `config/pos.php:214` (`POS_MANUAL_DISCOUNT_ENABLED` défaut false, décision propriétaire 2026-07-18) ; `OrderQuoteService.php:581-584` ; `PosComponent.vue:1833` (bouton `pos-discount-toggle`) n'a aucune lecture du drapeau (aucune occurrence JS de `manual_discount`). 2ᵉ moyen : `curl POST /api/admin/pos/quote` avec `discount:1` → 422 + même message. Le refus est visible et sûr (pas d'erreur muette) ; le choix de conception « refuser au devis » est documenté dans le code — à arbitrer : masquer le bloc tant que le drapeau est faux.

**B-002 — P2 — le toast de refus (110 caractères) reste ≈ 1,5 s lisible.**
Preuve : sonde `B-sonde-toast-remise` (getBoundingClientRect, 1280/1440/1920) : entré à t≈0,6 s (right ≤ innerWidth), en sortie à t≈2,5 s (left 1490 > 1280) ; capture 09 = toast encore en glissement, coupé à droite. Source : `resources/js/app.js:47` `timeout: 2000` (global, y compris erreurs). 2ᵉ moyen : source + mesure DOM. Déjà noté ailleurs (« 2 s globales illisibles »), mais c'est ici le SEUL signal du refus de remise.

**B-003 — P2 — planche « Tournées » : une tournée dont l'heure est passée et qui n'est pas partie reste étiquetée « à venir ».**
Preuve : capture 24 (« Départ hier 19:30 à venir », « Départ hier 20:00 à venir / Aucun livreur ») ; DOM `header` ; SQL `delivery_stops where wave_at < now() and left_at is null and delivered_at is null` → 12 tournées (22/10 19:30 … 23/10 01:00) alors qu'il est 01:29 ; JSON `/pos-delivery/board` : 9 vagues `left=false` à heure passée. Source : `PosDeliveryBoard.vue:21` (`w.left ? 'parti' : 'à venir'`, aucun état « en retard ») ; `PosDeliveryController::board` garde 24 h. Reproduit : DOM + SQL + code. (Données E2E, mais la règle d'affichage est celle du produit.)

**B-004 — P3 — `GET /api/admin/item/details/{1|3}?surface=pos` → 404 pendant des ventes ordinaires.**
Preuve : `.network.json` des captures 04, 16-1920, 17-1280, 17-1440 ; console « Failed to load resource 404 » avec l'URL. Les items 1 « Menu (Frites + Boisson) », 3 « Boisson Seule » existent (status 5) mais dans la catégorie 27 « Technique (interne — upsell) » inactive ; `curl` authentifié pos@ → 404 « L'URL spécifiée est introuvable » pour 1 et 3, 200 pour 53. **Non reproduit** par la sonde ciblée (2 essais : seuls 12 et 55 en 200 ; initiateur JS = `pos-wizard.js:303` XHR pour ces deux-là) : l'initiateur des 404 n'est pas identifié. Aucun effet visible (requête de préchauffage, erreurs avalées `ItemComponent.vue:631`).

**B-005 — P3 — libellés en Title Case français** (« Rapport X (Journée En Cours) », « Terminaux De Paiement (TPE) », « Configuration Des Commandes ») sur la page Rapports Z (capture 30, DOM).

**Connus, constatés à nouveau (pas recomptés comme nouveaux) :** ticket « Opérateur: Client passage » alors que le caissier connecté est `E2E-CAISSE-Caissier` et heure « 12:16 AM » (capture 05 ; M3-007 / M4-006) ; M3-004, M3-006 (wizard gelé) ; plafond 24 cartes KDS : la vente neuve #8676 est reçue par le KDS (trame WS 358 ms, API KDS) mais absente du DOM (`cartes_kds=24`, carte non rendue) — sur cette base saturée un cuisinier ne la verrait pas.

**Contrôles sans défaut :** bouton Livraison — actif ↔ suspendu : serveur caisse (`GET /api/admin/pos-delivery/status`) et site (`GET /api/frontend/delivery/status`) renvoient la même valeur dans les 3 états (`enabled` true / false / true), option Livraison et bouton Tournées disparaissent à la suspension, devis du site pendant la suspension = `allowed:false, reason:"suspended"`, un appui n'arme que l'interface (désarmement à 4 s), audit `delivery.suspended` puis `delivery.resumed` tracés, état initial restauré (true). Uber éteint : planche réelle `uber_enabled:false`, les 2 arrêts créés par l'API (#8670 payé → `paid_online`, #8671 à encaisser → `cash` 490 c) ont le bouton « Confier à Uber » grisé et la raison « Uber Direct n'est pas activé. » (comme demandé) ; clic forcé → 0 appel `/uber/*` ; `POST /uber/quote` → 422 même message (les deux arrêts). Commandes : `orders.total` 4,90 = 1,00 + 3,90 ; `delivery_stops.amount_due_cents` 490 ; payée a n° fiscal 3144/3145, à encaisser `fiscal NULL` (correct). Console : seules erreurs = `ERR_CONNECTION_REFUSED` vers 127.0.0.1:9100/9101 (agent d'impression absent, « MODE TEST — IMPRESSION BYPASSÉE ») = environnement ; et le 422 de la remise (visible).

**NF525 / base (hors défauts de la vague) :** `fiscal:verify-sequence-continuity` → trous historiques 2506-2781 (50 numéros, juin-août) sur la branche 1, max 3147 ; aucun trou n'est au-dessus de 2781 : tous les numéros de ma vague (3131 à 3147) sont présents, les manquants (50) sont tous historiques. `fiscal:verify-z-membership` : uniquement des orphelins « fenêtre morte pre-C33 » historiques. Clôture Z-28 = irréversible sur la base E2E (fenêtre 16/07 → 23/10).

## (e) Non couvert, et pourquoi
- Vente avec remise **aboutie** : impossible (kill-switch propriétaire) ; couvert seulement comme refus.
- Livraison à encaisser/payer par l'écran caisse (BAN en direct, 4 paliers de prix) : déjà couvert par M4-01 (vert) ; mes arrêts ont été créés par l'API (`/pos-delivery/customer|address` + `/admin/pos`) pour tenir « vraie livraison par l'API ».
- Bascule Uber réelle (devis, confirmation, retour) : Uber volontairement éteint — seule la spec simulée la couvre (3/3 verts).
- Wizard caisse (Cayenne, Tacos) : gelé, vu uniquement par M3.
- Remboursement/annulation (M3-10) : non évalué (rouge instrument).
- Impression ticket (agent 9100/9101), afficheur client Web Serial, TPE physique : absents/simulés.
- Le DOM du KDS ne montre pas les ventes neuves au-delà de 24 cartes : le test « ligne cuisine » a été fait par l'API KDS (même flux que l'écran) + capture 11/40.
- M6 « minuit réel » : skipped par la spec.
