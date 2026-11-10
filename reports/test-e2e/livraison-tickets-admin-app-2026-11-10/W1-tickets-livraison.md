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
