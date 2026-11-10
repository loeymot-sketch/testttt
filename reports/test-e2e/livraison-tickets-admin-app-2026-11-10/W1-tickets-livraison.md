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

### Ouvert / reste W2
- T-1.4.4 écran admin des livreurs (créer, suspendre, mot de passe, révocation) : tests existants à rejouer + capture.
- T-1.4.5 parité du libellé de règlement et de l'adresse sur `/livreur` (le téléphone est déjà aligné).
- Sub 2.4 : matrice des autres rôles, cliquet FormRequest (52), gestion des accès depuis l'admin.
- Dev DB : arrêt 728 créé pour la commande 9936 (tournée 18:00 du 2026-11-10, sans livreur).
