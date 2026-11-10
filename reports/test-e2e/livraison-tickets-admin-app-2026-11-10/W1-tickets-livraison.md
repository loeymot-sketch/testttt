# W1 — Tickets de livraison et reçu caisse d'une commande du site (2026-11-10)

Plan : `plans/GOAL_LIVRAISON_TICKETS_ADMIN_APP_2026-11-10.md` (Sub 1.1, 1.2.1, 1.4.5-téléphone).
Demande du propriétaire : « il devrait y avoir le numéro de téléphone, l'adresse ainsi que le type d'encaissement
selon le choix du client sur le site Web — tout ça ne fonctionne pas vraiment ».

## Défaut prouvé (avant correctif)

`tests/Feature/Hardware/ClientTicketLivraisonCoordonneesTest.php` écrit d'abord : **6 rouges / 7** sur le code en ligne (4c48695d2).
Ticket client d'une livraison du site, tel qu'imprimé avant :

```
*** LIVRAISON ***
Client : Karim Test
QT ARTICLES                              MONTANT
…
A REGLER TTC :                           27,50 €
** A REGLER EN CAISSE **
```

Ni téléphone, ni adresse ; « À RÉGLER EN CAISSE » pour une livraison à régler à la porte — quel que soit le mode choisi
(espèces, carte au livreur, titre-restaurant). Ticket cuisine : nom seul. Écran caisse (`OrderDetailsResource`) : repli sur le
compte client uniquement si `source_surface === 'web'`, or toute livraison du site porte `'delivery'` → aveugle aussi.

Cause : `OrderReceiptEscPosRenderer` ne lisait que `pos_customer_phone` (saisie caisse) et l'adresse de la BRANCHE ;
`door_payment_mode` et `payment_method` n'étaient jamais lus pour le libellé de règlement.

## Correctif

- `app/Support/CoordonneesClientTicket.php` (nouveau, SSOT) : `telephone()` (saisi caisse, sinon compte du client des
  surfaces web/online/delivery), `lignesAdresseLivraison()` / `adresseLivraisonUneLigne()` (livraisons de nos tournées
  seulement, jamais retrait/borne/table/Uber ; commune non répétée), `reglementALaPorte()` (ESPÈCES / CARTE AU LIVREUR /
  TITRE-RESTAURANT ; null si payée, carte en ligne en cours, ou pas une livraison).
- `OrderReceiptEscPosRenderer.php` : ticket client = `Tel :`, bloc `LIVRER A :`, `** A REGLER A LA LIVRAISON : X **` ;
  ticket cuisine = `Tel :` + `Livrer : …` (une ligne tronquée, décision G8 ouverte).
- `OrderDetailsResource.php` : nom et téléphone repliés via le même SSOT (web/online/delivery). Champ `loyalty_points_awarded`
  de la session voisine intact.
- `ReceiptComponent.vue` (reçu HTML caisse) : bloc client (`Client`, `Téléphone`, `Livrer à`) + « À régler à la livraison : … » ;
  libellés `resources/js/languages/{fr,en}.json` (`deliver_to`, `pay_at_delivery`, `card_to_courier`).
- `DeliveryRunController.php` : téléphone du livreur = même résolution (saisie caisse d'abord).
- `docs/TICKET_CONTRACT.md §4` : contrat écrit.

## Preuves (après correctif)

| Preuve | Résultat |
|---|---|
| `ClientTicketLivraisonCoordonneesTest` (8 cas : espèces, carte au livreur, titre-restaurant, payée en ligne, surface `delivery` réelle, cuisine, retrait, caisse) | 8/8 verts |
| `tests/Feature/Hardware` (34 fichiers) | 230 verts |
| `tests/Feature/Delivery` (43 fichiers) | 352 verts |
| 63 fichiers citant `pos_customer_*` / `OrderDetailsResource` + `Pos*` + `Receipt` (un par un) | tous verts |
| `DeliveryRunTest`, `DeliveryHandoverConfirmTest`, `DeliveryRunCashTest` après T-1.4.5 | 11 + 37 + 21 verts |
| Vitest `tests/js/receiptComponentLivraison.spec.js` + 5 specs tracker/reçu existants | 6 + 40 verts |
| Playwright `tests/Playwright/livraison-tickets-2026-11-10/recu-caisse-livraison.spec.js` sur :8766 (commande 9936 clonée, carte au livreur) | vert ; captures lues : bloc client, « Livrer à : 20 Place de la République 62110 Hénin-Beaumont », « À régler à la livraison : Carte au livreur », aucune clé brute |
| Ticket thermique rendu en texte (`apercu-tickets-livraison-web-9936.txt`) | `Tel : 0670182060` · `LIVRER A :` · `** A REGLER A LA LIVRAISON : CARTE AU LIVREUR **` |
| Zones gelées (13 fichiers) | diff 0 ligne |

## Ouvert

- G5 : impression sur la SAGA réelle (adresse longue, QR) — propriétaire, photo du ticket.
- G7 : téléphone complet + adresse sur le ticket client (demande explicite du propriétaire ; sinon masquer sur la copie client).
- G8 : en cuisine, `Livrer : …` est tronqué à 48 colonnes (« …62110 Héni... ») ; garder une ligne ou retirer de la cuisine.
- P3 préexistant vu sur la capture : « Sauce (1ère Gratuite): Ketchup , » (virgule avant un vide) ; « VAT (10.00 %) » en anglais dans le détail d'article.
- T-1.3 (qui imprime le ticket client d'une livraison, et quand) non commencé : aujourd'hui seul l'encaissement au comptoir
  imprime automatiquement un ticket client ; une livraison à la porte n'en a pas sans clic.

Effet de bord sur la base de dev `foodking_e2e` : commande 9936 (`VIS1110021327`, clone de 9933, statut acceptée,
rattachée au client 1166) créée pour la capture ; à annuler ou laisser (aucun numéro fiscal).

---

## W1 suite + W2 début (2026-11-10, après-midi) — déclencheurs d'impression, planche, accès livreur

### Cartographie (T-1.3.1 → `docs/TICKET_CONTRACT.md §2bis`)
Le serveur n'imprime jamais (hébergeur) ; tout papier sort par les ponts des postes. Ticket cuisine : automatique à l'entrée
en cuisine (réclamation `kitchen_ticket_claims`), copie caisse coupée depuis le 2026-10-19 (`kds.counter_auto_print=false`).
Ticket client : question « Imprimer ? » après un encaissement au comptoir seulement. **Une livraison à régler à la porte n'a
donc aucun ticket client sans clic** — et la planche Tournées n'avait aucun bouton pour le demander. Décision propriétaire
G2 toujours ouverte (impression automatique à PRÊTE ou clic).

### Fait
- Planche Tournées (`PosDeliveryBoard.vue`, `PosDeliveryController::board`) : téléphone cliquable (`tel:`), adresse complète
  (complément, CP ville) depuis `CoordonneesClientTicket`, bouton **« Ticket »** par arrêt non remis → `GET pos/orders/{id}/escpos?ticket=client`
  → pont caisse ; échec annoncé (toast), jamais muet.
- Parité (T-1.2.3, `tests/Feature/Receipt/ReceiptParityLivraisonTest.php`) : total, téléphone, adresse, règlement identiques
  entre ticket thermique, ressource du reçu caisse, planche et livreur. **Défaut trouvé par ce test** : une adresse saisie à la
  main (« 10 Rue Test, Hénin-Beaumont » + CP 62110 + ville) imprimait la commune deux fois ; corrigé (PHP + jumeau JS du reçu :
  la voie porte la commune → on n'ajoute que le code postal).
- Accès livreur (T-1.4.1/2/3, `tests/Feature/Authz/DeliveryBoyAccessMatrixTest.php`) : rôle « Delivery Boy » = **0 permission**
  (dev et prod, lecture seule ; prod = 0 compte livreur, dev = 12). Sonde dynamique : les 74 routes `api/admin/*` sans middleware
  de permission sont appelées avec ce rôle → 6 lectures de configuration d'interface sans client ni argent (succursale, société,
  tables, modèles de messages, accès par défaut), **0 commande, 0 client, 0 ticket, 0 mutation** ; `menu-projection` (valide
  avant d'autoriser) refusée avec paramètres valides. Arrêts/photo/départ d'un collègue → 403 sans adresse ni téléphone.
- Atterrissage (T-1.4.2) : le livreur arrivait sur `delivery-boys` (liste admin interdite → page « exception »). Désormais
  landing `livreur` (seeder + migration de données `2026_11_10_100000_delivery_boy_lands_on_livreur`, n'écrase pas un choix
  fait depuis l'écran des rôles) → route SPA `admin.livreur` → sortie dure vers `/livreur`. `docs/AUTHZ_MATRIX.md` : ligne Livreur.

### Preuves
| Preuve | Résultat |
|---|---|
| `DeliveryBoyAccessMatrixTest` (4) | verts |
| `ReceiptParityLivraisonTest` (1), `ClientTicketLivraisonCoordonneesTest` (8) | verts (après correction commune doublée) |
| `DeliveryBoardAndCatchUpTest` (12, dont téléphone + adresse complète) | verts |
| `AntiGravityLoginRedirectionTest` (5, dont livreur → `livreur` + migration idempotente) | verts |
| Vitest `posTourneesTicketClient` (3), `receiptComponentLivraison` (6), planche (27), routeur/landing (31) | verts |
| Playwright `planche-tournees-ticket.spec.js` sur :8766 (arrêt 728 = commande 9936) : téléphone `tel:`, adresse, clic Ticket → `escpos?ticket=client` 200 + octets, toast d'échec « pont injoignable » | vert ; capture lue : `planche-tournees-arret-9936.png` |
| Zones gelées | diff 0 ; `fiscal:verify-chain --all` CHAIN OK (7) |

### W2 (même jour, suite)
- T-1.4.4 / T-2.4.3 : tests existants rejoués (`DeliveryBoyCashSessionControllerTest` 18, `StaffCredentialChangeRevokesSessionsTest` 3,
  `DeliveryBoyAddressPermissionSplitTest` 4, `EmployeePeerManagementGuardTest` 3, `EmployeeAbusGuardTest` 3, `EmployeeRoleGrantEntitlementTest` 7) ;
  captures lues `/admin/delivery-boys` et `/admin/employees` (admin) : listes, actions, aucune clé brute. **P3 (données de dev)** :
  trois comptes livreurs de test affichent `PENDING_CREATE_…` en colonne téléphone et un statut vide — résidus de bancs, pas un
  écran de production ; à nettoyer avec la base de dev.
- T-1.4.2 visuel : `livreur.e2e@lecayenne.test` sur `/login` → URL `/livreur` (son écran, connexion propre) — `acces-livreur-admin.spec.js`.
- T-1.4.5 : mode à la porte prouvé identique ticket ↔ livreur (espèces, carte au livreur, titre-restaurant) dans `ReceiptParityLivraisonTest`.
- T-2.4.2 : cliquet FormRequest **52 → 46** (`DeliveryBoyAddressRequest`, `EmployeeAddressRequest`, `ItemAttributeRequest`,
  `ItemCategoryImportRequest`, `ItemImportRequest`, `ItemPhotoUploadRequest` reflètent la permission exacte de leur route ;
  `FormRequestAuthorizeWaveLivraisonTest`). `CouponCheckRequest` reste ouverte : route publique du site. Un test qui construisait
  `ItemAttributeRequest` sans utilisateur (`ItemAttributeRenamePropagationTest`) porte désormais un admin `settings`.
- T-2.4.1 : couvert par `tests/Feature/Authz/*` (rejoués verts) + la matrice livreur ; pas de nouvelle matrice croisée des autres rôles.

### W3 (même jour) — stock et produits depuis l'admin
- Stock : suite `tests/Feature/Stock` 27 fichiers / 93 verts (+ 1 fichier de 4 tests `skipped` préexistant) ; capture
  `/admin/stock/rupture` lue (catégories, cartes, « EN STOCK », photos). **L'alerte stock bas existe déjà** : widget
  `StockLowAlertsWidget` (tableau de bord et caisse) sur `admin/stock/low-alerts`, 3 tests existants ; l'e-mail/journal est
  derrière `FK_CATALOG_STOCK_LOW_ALERT_ENABLED`, **actif en production** (lu via `config()`, lecture seule ; les autres
  interrupteurs lus au passage : `kds.counter_auto_print=false`, `printing.auto_print_client_receipt=false`,
  `delivery.handover.qr_print_enabled=false`).
- Produits : suites Items/Catalog/Requests/Composer (59 fichiers, 229 verts) + Item (4 fichiers, 32 verts) ; captures
  `/admin/items` et tableau de bord lues, 0 clé brute, 0 erreur console. Banc du site `catalogue-distant-2026-11-05.unit.mjs`
  55/55 ; `verify-catalogue-distant.mjs --prod` (lecture seule) : 40 produits, 9 catégories, 13 hors carte à dessein, 0 erreur JS
  → photo changée / produit caisse seule / rupture d'option restent couverts sans republication.
- **Fiche produit, échec muet** (mémoire `formulaire-admin-echec-muet`) : le correctif de septembre était un toast + un
  défilement ; ajouté un message **persistant** près d'« Enregistrer » (`item-save-error`, `role=alert`), même règle que le
  formulaire de code promo. Preuves : `itemFormErreurVisible.spec.js` 3 verts ; banc réel (nom vidé → 422) :
  `fiche-produit-erreur-visible.png` lue (toast en haut + message rouge au-dessus du bouton).

### W5 (même jour, soir) — vitrine et catalogue distants
- **« Design d'un jour » construit** : thèmes de couleurs programmés (`storefront_settings.scheduled_themes`, migration
  `2026_11_10_110000`). Un thème porte nom, début, fin (heure de Paris), priorité, actif, 8 couleurs soumises aux mêmes règles
  de contraste que le thème de base. Le serveur sert ses couleurs dans `theme` (+ `theme_name`) entre début (inclus) et fin
  (exclue), même si le restaurant garde les couleurs embarquées, et annonce début/fin dans `next_change_at` ; le thème de
  base reprend seul ; priorité la plus grande en cas de chevauchement ; validé à l'enregistrement ET à la publication ;
  restauré par « revenir à la version N ». Écran Vitrine : section « Thèmes programmés » (liste avec état, éditeur, contraste,
  aperçu téléphone aux couleurs du thème en cours). Le site (`vitrine.js`) n'a rien à changer : il applique `theme` et relit à
  `next_change_at`. Preuves : `StorefrontThemePlanifieTest` 9, suites Storefront 47 inchangées, Vitest 9, banc réel admin
  (thème couvrant l'heure présente → « En cours » + aperçu, capture lue), banc site `vitrine-robustesse` 15/15 (dont les 2 cas
  thème ajoutés : couleurs posées puis retirées à la relecture).
- Catalogue distant : banc 55/55 ; référence identique au serveur (70/70) ; `check:www` à jour (285 fichiers) ; prod : 40
  produits affichés, 0 ignoré. Règle de régénération déjà dans `app/PUBLICATION.md §11`.
- Codes promo : fin d'offre INCLUSE à la seconde des deux côtés (paiement et vitrine), relecture programmée à la fin
  (`CouponExpireALaMinuteTest`). ⚠️ Un coupon créé depuis l'admin finit toujours à 23:59:59 : l'heure fine vient du bandeau.
- Robustesse : `/storefront` 304 mesuré ; `/app/config` répondait 200 à chaque relecture → ETag + 304 ajoutés
  (`AppConfigController`, test). P3 neuf : hors ligne, un thème programmé reste affiché après sa fin jusqu'à la prochaine
  lecture réussie.

### W6 (même jour, soir) — application des stores, partie navigateur
- Paquet : `check:www` « à jour (285 fichiers) » sur la branche des stores (= en ligne `b61d61f`) ; nouveau banc site
  `tests-e2e/app-www-parite-interdits.unit.mjs` 5/5 (aucun montant dépensé — le barème « 1 € dépensé = 10 points » reste permis
  et la règle mord sur « 400 € dépensés » —, `api-base-url` = backend HTTPS, images embarquées, 36 ressources `?v=` présentes,
  aucun script local). Le seul `127.0.0.1:8766` du paquet est le repli de `api.js` quand la meta manque, avec bannière d'alerte.
- Comportement application vs navigateur (`tools/verify-app-behaviour.mjs`, pont Capacitor simulé) : 55/55. Abandon de paiement
  Mollie : banc vert ; `MollieAppReturnTest` 14 verts.
- Vitrine distante dans l'application (`vitrine-distante-2026-11.spec.js`, miroir rebranché sur :8766) : 15/16 — couleurs et
  bandeau publiés visibles, nouvelle publication prise au retour au premier plan sans rechargement, 304, hors ligne conservé,
  vitrine éteinte → couleurs d'origine. Le 1/16 est un artefact : le serveur :8766 tourne depuis le 3 nov. avec
  `POS_COUPON_CODES_ENABLED=true` dans son environnement, le `.env` actuel ne l'a plus, le banc lit le `.env`.
  Deux passes perdues avant : miroir sans réécriture de la meta `api-key` (règle déjà dans la mémoire du dépôt).
- Textes de fiche (PUBLICATION.md §6) relus : conformes ; §11 complété d'une ligne « thème de couleurs programmé ».
- **Non fait, dit franchement** : émulateur Android, `gradlew assembleDebug`, installation et parcours sur l'APK, capture du
  thème d'un jour dans l'app installée — **disque du poste à 100 % (4 Go libres)**, partition émulateur de 6 Go : lancer ferait
  tomber la machine. L'APK présent (6 nov. 02:46) est antérieur au dernier correctif du site (`b61d61f`, 6 nov. 17:01) : à
  reconstruire après libération d'espace (les 17 Go de `.claude/worktrees/` sont le gisement).
- Site (branche `app/catalogue-distant-2026-11-05`, commits locaux non poussés) : `af6b5c6` banc thème, `4cfd7c2` banc paquet,
  `1cb6671` PUBLICATION §11.

### Ouvert
- G2 (impression automatique du ticket client d'une livraison) : décision propriétaire ; T-1.3.2 attend.
- W4 offres : bloquée par G1. W6 émulateur/APK : bloqué disque. W7 convergence + déploiement : propriétaire.
- Dev DB : arrêt 728 créé pour la commande 9936 (tournée 18:00 du 2026-11-10, sans livreur) ; comptes `d@p.test`… à purger.
