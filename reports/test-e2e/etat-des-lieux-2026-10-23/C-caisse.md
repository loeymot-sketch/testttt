# État des lieux 2026-10-23 — Vague C (caisse) — livraison, exigences C1..C8

Lecture seule du produit. Spec : `tests/Playwright/etat-des-lieux-2026-10-23/C-caisse-livraison.spec.js` (9 tests, tous verts, `--workers=1 --retries=0`).
Serveur : http://127.0.0.1:8766 (base `foodking_e2e`), compte `pos@lecayenne.fr`. Captures : `reports/test-e2e/etat-des-lieux-2026-10-23/C-captures/` (12 PNG, tous lus). Résultats bruts : `C-resultats-spec.json`.
Livraison : active au départ, active à l'arrivée (jamais basculée). `UBER_DIRECT_ENABLED=false`. `fiscal:verify-chain --all` : CHAIN OK sur les 7 branches.

## Verdicts

| Exigence | Verdict | Résumé |
|---|---|---|
| C1 À emporter / Livraison au début | EXISTE | Segment « À emporter / Livraison » dans le ticket de caisse, choisissable panier vide (`01`, `02`). `label[for="delivery"]`, `PosComponent.vue:1328-1342`. |
| C2 Adresse, suggestions, frais | EXISTE | Téléphone/nom/adresse, liste de suggestions serveur (BAN) — 1 seule pour une adresse précise, 5 pour « rue de la gare » (`03`, `08`) —, devis « Hénin-Beaumont · 3,90 € · 0 km · ~4 min · Départ prévu… », ligne « Frais de livraison + 3,90 € » (`04`). |
| C3 Distance routière / <7 km maison / ≥7 km Uber client / au-delà Uber Eats | FAUX (partiel : seul « au-delà de 10 km → Uber Eats » existe) | Distance = vol d'oiseau (haversine, `DeliveryQuoteService.php:162-171`), pas un trajet routier ; paliers 5/8/10 km (`config/delivery.php:23-26`), aucun seuil 7 km ; aucun devis Uber au moment de la commande ; Uber n'est qu'une bascule d'arrêt APRÈS commande et son coût est « à la charge du restaurant » (`UberDirectDispatchService.php:135` `customer_fee_cents => 0`) = l'inverse de l'exigence. Mesures : Carvin 7,49 km → 5,90 €, Lens 8,83 → 6,90 €, Douai 9,68 → 6,90 €, Lille 25,14 → refusé « Proposez Uber Eats au client » (`11`). |
| C4 Mode de paiement à la livraison enregistré à la confirmation | PARTIEL | Au comptoir : Espèces / Carte bleue / Titres-resto / Multi-paiement (`05`), enregistré et la commande est PAYÉE (order 8837 : payment_status 5, fiscal 3181). Mais aucun choix « à régler à la porte » : le seul chemin différé, « Commande téléphone », est REFUSÉ en livraison (`PosComponent.vue:6879-6882`, `09`). Côté serveur, une livraison différée existe (orders 8662/8671 : source pos, PENDING_COUNTER, `collect_method=cash`) mais `OrderService.php:807` force `CASH_ON_DELIVERY` : carte / titre-resto à la porte non représentables. |
| C5 Ticket/facture avec QR d'avis à usage unique | ABSENT | Aucun QR sur le ticket client ni cuisine (`GS ( k` absent des octets réels, `OrderReceiptEscPosRenderer.php:46-285`). La primitive existe (`EscPosCommandBuilder.php:269 qrCode`) mais n'est utilisée que par le ticket promo (`PromoFlyerEscPosRenderer.php:155-180`). Aucune page d'avis, aucun jeton d'avis, aucun +100 points (le seul « avis » = étape de la roue, `WheelStepService`, qui débloque un tour). |
| C6 Tournées 30 min / planche / bascule Uber | EXISTE (tient) + dégradation propre | Planche : 23 départs, 107 arrêts, 0 départ hors grille :00/:30, 164 arrêts ; Uber éteint : 107 boutons « Confier à Uber » grisés + « Uber Direct n'est pas activé. » (`10`), API `uber/quote` → 422 propre, aucune panne. Si activé mais sans scope `eats.deliveries` : `createQuote` renvoie null → « Uber n'a pas donné de prix… La commande reste à nos livreurs » (`UberDirectDispatchService.php:127-130`, lu, non exécuté : activer Uber = modifier l'environnement). Aucun devis réel possible aujourd'hui. |
| C7 Suivi livreur | PARTIEL | Voir tableau ci-dessous. |
| C8 Vouvoiement | PARTIEL (violations relevées) | une quarantaine de phrases tutoyées destinées à un client — liste plus bas. |

## C5 — ce que contient réellement le ticket d'une livraison (octets ESC/POS du serveur, commande 8837)

Client : en-tête établissement, **numéro A0274**, date, bannière `*** LIVRAISON ***`, articles, sous-total, `LIVRAISON : 3,90 €`, `MONTANT TOTAL 4,90 €`, TVA 10 % 0,09 €, mode de paiement (`ESPÈCES : 5,00 €`, `RENDU`), mentions fiscales, `Caissier : …`.
**Absents** : nom du client, téléphone, adresse de livraison, créneau/départ prévu, QR (d'avis ou autre), consigne « à encaisser à la porte ». Ticket cuisine : `CUISINE / A0274 / *** LIVRAISON ***` + articles : ni nom, ni adresse. L'écran de prévisualisation (`07`) est identique (« Type de commande : Livraison »).
Où est généré : `OrderReceiptEscPosRenderer::renderClientTicket` (octets, CP858) ← `PosTicketBytesController` (`GET /api/admin/pos/orders/{id}/escpos?ticket=client|kitchen`) ; aperçu HTML : `ReceiptComponent.vue` ; libellés FR codés en dur dans le renderer.
Instrument prouvé : le décodeur lit `A0274`, `LIVRAISON`, `MONTANT TOTAL`, la ligne `Caissier` (le même texte que la capture 07), et il détecterait un QR par la séquence `1D 28 6B` (positif attendu sur le ticket promo).

## C7 — Suivi livreur : existe / manque

| Besoin | État | Preuve |
|---|---|---|
| Écran livreur mobile (tournées, Waze, départ, arrivée, remise) | EXISTE | `resources/views/livreur.blade.php`, `DeliveryRunController` (`index/depart/arrive/deliver`) |
| Photo de preuve à l'arrivée (disque privé) | EXISTE | `DeliveryRunController.php:101-126`, `photo()` ; `delivery_stops.proof_photo_path` |
| Position GPS du téléphone | PARTIEL | un seul point à l'arrivée (`getCurrentPosition`, `livreur.blade.php:171-172, 203` → `arrival_lat/lng`) ; aucun suivi continu, aucune table de positions, rien au départ |
| Horodatage départ / arrivée / remise par arrêt | EXISTE | `delivery_stops.left_at/arrived_at/delivered_at` |
| Scan du QR du ticket au départ | ABSENT | pas de QR sur le ticket, pas de scanner dans `livreur.blade.php` |
| Code client pour valider la remise | ABSENT | `deliver()` n'exige que `collected_cents` ; aucun code généré ni vérifié |
| Espèces du livreur (fond, encaissements, clôture, écart) | EXISTE | `DeliveryBoyCashSession`/`Movement`, `DeliveryBoyCashSessionService` (open/close/reconcile, variance), routes `delivery-boy-cash-sessions`, mouvement `order_collect` à la remise (`OrderService.php:2371`) — best-effort : sans session ouverte, rien n'est écrit (« skip silently ») |
| TPE du livreur | ABSENT | carte à la porte = affichage du montant seulement (`docs/LIVRAISON.md` §5) |
| Montant dû relu en direct (anti double encaissement) | EXISTE | `DeliveryRunController.php:138, 183` |
| Rapports de rapprochement | PARTIEL | rapprochement de la session espèces (écart + motif) et recoupement Z ; aucun rapport par tournée (prévu vs réel, encaissé vs dû) |
| Détection de détour / hors itinéraire | ABSENT | aucune trace de parcours ni ETA réel vs estimé ; ETA = estimation `distance × 1,3 / 28 km/h` |
| Attribution du livreur (caisse, auto si un seul) | EXISTE | `PosDeliveryController::assignCourier`, planche (`10`) |

## C8 — phrases tutoyées adressées à un client (backend, `fichier:ligne`)

- `lang/fr/all.php:317` « supprimer **ton** compte… **Récupère**-la (ou **attends**…) », `:340` « Si **tu** ne reçois rien… (**pense**…), **passe** au comptoir avec **ton** numéro… **ton** compte »
- `app/Http/Requests/GuestEmailLoginRequest.php:45-46` « **Indique ton** prénom / numéro… **ton** compte »
- `app/Http/Controllers/Auth/GuestSignupController.php:213` « **Indique ton** prénom et **ton** numéro »
- `app/Http/Controllers/Frontend/LoyaltyController.php:500` « utiliser **tes** points »
- `app/Providers/RouteServiceProvider.php:169` « **Patiente** une heure avant de redemander un code »
- `app/Http/Controllers/Frontend/WheelController.php:382` « **Montre ton** avis et **ton** abonnement… **ton** tour »
- `app/Services/Wheel/WheelService.php:431, 497, 698` « **Reviens** demain ! »
- `app/Services/Wheel/WheelStepService.php:131` « c'est à **toi** », `:274` « **Laisse** d'abord **ton** avis », `:275` « **abonne-toi**, et c'est à **toi** »
- `resources/views/admin/wheel/borne.blade.php:47` « **Tourne** la roue », `:631` et `:777` « **Scanne** le QR code », `:764-768` « Scanne / Tourne / Gagne » (écran de la borne, lu par le client)
- Tickets imprimés pour le client : `app/Services/Promo/PromoFlyerService.php:47` « Merci pour **ta** commande… », `:76` « **tu** paies le repas », `app/Services/Promo/PromoFlyerEscPosRenderer.php:116` « sur **ta** prochaine commande », `:143` « rien que pour **toi** », `:158` « **SCANNE** : **ton** code », `:180` « **tape ton** code »
- Libellés d'assistant de composition vus du client : `app/Services/Composer/ComposerTemplateService.php:72-75, 87-93, 103, 111-116, 130-138` et `app/Console/Commands/WizardPagesBootstrapCommand.php:121, 126, 141, 151, 171` « **Choisis ton** pain / **ta** viande / **ta** sauce / **tes** garnitures / **ta** formule / **ton** plat / **ta** boisson / **ton** dessert » ; côté borne : `resources/js/components/frontend/kiosk/ds/KsA11ySettings.vue:146` « **Choisis** l'apparence… **laisse**… »
- Non retenus (destinés au personnel/propriétaire, tutoiement admin) : `resources/views/admin/wheel/{validation,reglages,acces}.blade.php`, `WheelSettingsController`, `WheelSettingsService`, commandes artisan, `resources/js/languages/fr.json:2085` (assistant admin). Le tutoiement du personnel n'est pas dans le périmètre C8 mais est incohérent avec le vouvoiement du reste de l'admin.
- Aucun hit dans les e-mails (`resources/views/emails`), les `Order*NotificationBuilder` (mail/SMS/push) ni `lang/fr/*` hors `all.php`.

## Défauts réels (voir `C-caisse-findings.json`)

- **C-001 P1** — le ticket fiscal (octets ESC/POS et aperçu) imprime `Caissier : <nom du CLIENT>` : `ReceiptDataService.php:70` lit `$order->user` (le client, cf. `OrderService.php:853-860`) au lieu de `creator_id` (le caissier). Commande 8837 : user_id 944 « E2E-ETAT-C Client », creator_id 3 « Caissier Le Cayenne ». Sur une vente comptoir : « Caissier : Client passage » (la fixture JS `receiptNf525FiscalRender.spec.js:33` fige ce défaut).
- **C-002 P2** — en mode Livraison, le gros bouton « Commande téléphone » reste proposé alors qu'il refuse toujours (`PosComponent.vue:6879-6882`) ; seul chemin différé, donc impasse pour « le client paiera à la porte » (capture `09`).
- **C-003 P3** — la planche « Tournées » étiquette « à venir » un départ passé jamais parti (« Départ hier 19:30 à venir », `PosDeliveryBoard.vue:24`, capture `10`) : un retard n'est pas signalé.
- **C-004 P2** — tutoiement de clients (C8) : voir liste.
- Écarts d'exigences (pas des défauts du code existant) : C3 (distance routière, seuil 7 km, devis Uber payé par le client), C4 (paiement à la porte), C5 (QR d'avis), C7 (QR, code client, TPE, détour).

## Observations sans défaut retenu (reproduction insuffisante)

- 20 arrêts sur le départ 2026-10-22 20:30 (cap 6) : tous `slot_fixed=1`, `created_at` posés à la main (2026-11-02) — données de test, rejeté.
- `DeliveryFeePolicy` (client paie le montant Uber, décision du 2026-09-06) n'est appelée par aucun code (`grep` : 0 usage) ; `docs/LIVRAISON.md` §6 pose la règle inverse. Contradiction documentaire à trancher avant de construire C3.
- Le bouton « Commande » reste actif hors zone mais le clic est refusé avec message (`12`) : OK.
- Le ticket n'affiche pas la TVA 10 % des frais de livraison : décision propriétaire en attente déjà documentée (`docs/LIVRAISON.md` §3quinquies).

## Empreinte dans la base locale

Une commande réelle créée par le parcours normal : #8837 (livraison, espèces, fiscal 3181, arrêt 299, client 944 « E2E-ETAT-C Client »). Chaîne fiscale vérifiée après : `fiscal:verify-chain --all` → CHAIN OK ×7. Aucun fichier de produit modifié.
