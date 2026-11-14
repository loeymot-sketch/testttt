# CONTRAT TICKET — Client détaillé / Cuisine symbolique (borne + caisse)

> 2026-06-29. **À LIRE avant toute modification touchant l'impression d'un ticket ou
> l'écran cuisine (KDS).** Ce contrat décrit ce que CHAQUE ticket doit afficher. Il
> est verrouillé par des tests (§5) — si tu modifies le format, fais-le des DEUX côtés
> (PHP + JS) et garde les tests verts.

---

## 1. La règle (non négociable)

Toute la prise de commande (chaque étape choisie) doit ressortir, mais SOUS DEUX FORMES :

| Destinataire | Format | Contenu |
|---|---|---|
| **CLIENT** (ticket remis au client) | **DÉTAILLÉ, en toutes lettres** | Nom du produit + **toutes** les étapes : viandes, sauce(s), crudités choisies, pain/galette, suppléments (avec prix), formule + boisson. Lisible par un humain. |
| **CUISINE** (ticket cuisine + écran KDS) | **SYMBOLIQUE, 3 lignes** | Symboles courts pour le cuisinier (voir §3). Même info, condensée. |

Les deux formats lisent **la même source de vérité** : `composition_snapshot` de l'`OrderItem`
(scellé à la création, NF525). On ne réinvente jamais la compo ailleurs.

⛔ Un ticket client qui n'affiche PAS une étape choisie = bug. Un ticket cuisine qui
n'est PAS au format symbolique 3 lignes = bug. Les deux doivent rester **imprimables**.

---

## 2. Les 5 surfaces et leurs fichiers (où c'est codé)

| Surface | Format | Fichier source (NON-frozen) |
|---|---|---|
| **Caisse — ticket CLIENT** | détaillé | `app/Services/Hardware/OrderReceiptEscPosRenderer.php` → `renderClientTicket()` |
| **Caisse — ticket CUISINE** | symbolique 3 lignes | même fichier → `renderKitchenTicket()` → délègue à `KitchenTicketSymbolicFormatter` |
| **Borne — ticket CLIENT** | détaillé | `resources/js/helpers/kioskPrinter.js` → `kioskItemCompositionText()` → `buildBridgePayload()` |
| **Borne — ticket CUISINE** | symbolique 3 lignes | listener `app/Listeners/PrintKioskKitchenTicketOnOrderCreated.php` → `renderKitchenTicket()` (même renderer que la caisse) |
| **Écran KDS** | symbolique 3 lignes | `resources/js/helpers/kdsSymbolic.js` (jumeau JS du formatter PHP) |

### 2bis. QUI imprime QUOI, et QUAND (cartographie relue dans le code le 2026-11-10)

Le serveur applicatif est chez l'hébergeur : il ne joint aucune imprimante. Tout papier sort par le **pont local**
d'un poste (`127.0.0.1:9100` caisse, `:9101` cuisine) qui **réclame** les tickets au serveur ou les demande au clic.
Les chemins « serveur → imprimante » (`PrintKioskOrderToCounter`, `PrintFiscalReceiptAndOpenDrawerOnCounterPaid`,
`KitchenTicketAutoPrinter::printOnce`) existent mais n'ont jamais rien sorti en production (table `printers` vide).

| Événement | Ticket CUISINE (symbolique) | Ticket CLIENT (détaillé, fiscal) |
|---|---|---|
| Commande entre en cuisine (ACCEPTÉE / EN PRÉPARATION), toute surface | **Automatique** sur le pont cuisine (`KitchenTicketPrintListener.vue` → `kitchen-tickets/pending`, réclamation `kitchen_ticket_claims` par destination). Copie caisse **coupée** par défaut (`kds.counter_auto_print=false`, propriétaire 2026-10-19 ; interrupteur « impression_caisse_auto ») | rien |
| Encaissement au comptoir (caisse, Encaissement, Suivi) | — | **Question** « Imprimer le ticket client ? » (`PosQuestionImpressionTicket`, R-048, propriétaire : « garder le choix ») ; automatique seulement si `printing.auto_print_client_receipt` (défaut `false`) |
| Vente directe caisse payée | — | boutons de `ReceiptComponent` (pont ou navigateur), jamais `window.print` automatique |
| Borne, paiement en ligne | — | la borne imprime sa copie sur son propre pont (`kioskPrinter.js`) |
| **Livraison du site à régler à la porte** | automatique en cuisine (avec `Tel :` et `Livrer :` depuis le 2026-11-10) | **AUCUNE impression automatique** : pas d'encaissement au comptoir → pas de question ; départ de tournée = sur le téléphone du livreur (`DeliveryRunController::depart`). Impression à la demande : Suivi des commandes (réimpression) et, depuis le 2026-11-10, bouton **« Ticket »** de la planche Tournées (`PosDeliveryBoard.vue`, `GET pos/orders/{id}/escpos?ticket=client`, QR de remise si `DELIVERY_HANDOVER_QR_PRINT`) |
| Réimpression | KDS (ticket cuisine, rôle Chef autorisé) | Suivi / planche : `?duplicata=1` marque « DUPLICATA » ; **jamais** de compteur fiscal sur ce chemin (`PosTicketBytesController` = lecture seule) |

Garde d'accès de `GET api/admin/pos/orders/{order}/escpos` (dans le contrôleur, pas en route) : ticket client → permission
`pos` ; ticket cuisine → `pos` ou `kitchen-display-system` ; Livreur (aucune permission) → 403 sur les deux
(`DeliveryBoyAccessMatrixTest`, `KdsKitchenTicketAuthzTest`).

**Décision ouverte (porte G2, propriétaire)** : faut-il imprimer **seul** le ticket client d'une livraison de nos tournées
(au passage PRÊTE, une fois, journalisé dans une table de réclamation comme la cuisine), ou garder le clic ? La règle
« plus aucun papier automatique à la caisse » du 2026-10-19 visait la copie de COMMANDE ; le ticket de livraison porte
le téléphone, l'adresse, le règlement à la porte et le QR : sans lui le livreur part avec le ticket cuisine seul.

➡️ Le format **cuisine** (ticket imprimé) et l'**écran KDS** partagent la même logique,
implémentée DEUX FOIS : `KitchenTicketSymbolicFormatter.php` (impression) et
`kdsSymbolic.js` (écran). **Ils DOIVENT rester identiques** — verrouillé par le sentinel §5.

---

## 3. Format CUISINE symbolique — les 3 lignes

Par produit, dans l'ordre :

```
<qté> x  L1 : SUPPORT | PRODUIT | TAILLE | VIANDES | CRUDITÉS | SAUCES
         L2 : + Supplément payant      (une ligne par supplément, crudités gratuites exclues)
         L3 : MENU  ou  F              (formule : MENU complet, F = frites seules)
```

Exemple réel (Méga galette, 2 viandes, STO, Samouraï, + Cheddar, menu complet) :
```
1 x G | MÉGA | Cordon Tender | STO | SAM
  + Cheddar
  MENU
```

- **Support** : `G` pour tacos/galette (défaut `G` si non précisé), sinon le pain.
- **Crudités** : repliées en une suite ordonnée **`STO`** = Salade, Tomate, Oignon (ordre canonique `S,T,O`). Une crudité non choisie disparaît (`SO` = salade+oignon, sans tomate).
- **Sauces** : symboles courts (voir tables). Sauce supplémentaire = symbole en plus.
- Les **crudités gratuites** (Salade/Tomate/Oignon prix 0) se replient en L1, elles ne
  réapparaissent PAS en L2. Un supplément payant nommé comme une crudité (« Oignons frits » 0,90€) RESTE en L2.

### Tables de symboles (SSOT — dupliquées PHP + JS, à garder identiques)
- **Viandes** : `hach|steak|bœuf`→`K`, `poulet`→`P`, `tender`→`Tender`, `nugget`→`Nug`, `mexic`→`Mex`, `fricadelle`→`Frec`, `cordon`→`Cordon`.
- **Sauces** : `mayo`→`MAY`, `samou`→`SAM`, `hannibal`→`HAN`, `curry`→`CURY`, `andalouse`→`AND`, `blanche`→`BL`, `ketchup`→`KTP`, `burger`→`Burg`, `algerien`→`ALG`, `barbecue|bbq`→`BBQ`, `harissa`→`HAR`, `fromage`→`FRO`, `spicy`→`SPI`.
- **Crudités** : `salade`→`S`, `tomate`→`T`, `oignon`→`O` ; ordre d'impression `S,T,O`.

> Pour AJOUTER un symbole (nouvelle viande/sauce) : l'ajouter dans **les deux** fichiers
> (`KitchenTicketSymbolicFormatter.php` ET `kdsSymbolic.js`) au même endroit, puis lancer
> le sentinel de parité (§5). Sinon l'écran et le papier divergent.

---

## 4. Format CLIENT détaillé

Par produit : `<qté>  <Nom> .......... <prix>` puis, en dessous, en toutes lettres :
- la compo (viandes, sauce, crudités, pain) séparée par virgules ;
- chaque **supplément payant** sur sa ligne avec le prix (`+ Cheddar  0,90 EUR`) ;
- la **formule** + la **boisson** (`+ Menu (Frites + Boisson)`).
Puis les totaux, la TVA, le moyen de paiement, et les mentions fiscales (caisse) /
« À régler en caisse » (borne Plan B).

**Client à distance (site / application), depuis le 2026-11-10** — SSOT `App\Support\CoordonneesClientTicket`,
même périmètre que le nom (par SURFACE `web` / `online` / `delivery`, jamais par type de commande ; la caisse et la
borne n'empruntent rien à leur compte technique) :
- `Tel : <numéro du compte>` à défaut de saisie caisse (`pos_customer_phone` prime) — client ET cuisine ;
- LIVRAISON de nos tournées seulement (jamais retrait, borne, table, Uber) : bloc `LIVRER A :` (voie, complément,
  CP ville sans répéter la commune déjà dans la voie) sur le ticket client, `Livrer : …` sur UNE ligne d'en-tête cuisine ;
- livraison pas encore encaissée : `** A REGLER A LA LIVRAISON : ESPÈCES | CARTE AU LIVREUR | TITRE-RESTAURANT **`
  selon `payment_method` (1/5) et `door_payment_mode='card'` — jamais « en caisse » ; payée en ligne → `*** PAYÉ ***`
  inchangé ; carte en ligne en attente → règle habituelle.
- L'écran caisse (`OrderDetailsResource`) lit le même SSOT (il ne repliait que sur `'web'`, donc jamais sur une livraison).
Verrou : `tests/Feature/Hardware/ClientTicketLivraisonCoordonneesTest.php`.

Exemple (caisse) :
```
1  Méga                                14,80 EUR
   Galette, Cordon Bleu, Tenders, Samouraï, Salade, Tomate, Oignon
   + Cheddar                            0,90 EUR
   + Menu (Frites + Boisson)            2,50 EUR
```

Côté borne, `kioskItemCompositionText()` reconstruit cette compo détaillée depuis les
champs structurés du panier (`item_variations` / `item_extras` / `item_addons`).

---

## 5. Tests qui VERROUILLENT le contrat (à garder verts)

| Test | Ce qu'il protège |
|---|---|
| `tests/Unit/Hardware/KitchenTicketSymbolicFormatterTest.php` | Format symbolique PHP (L1/L2/L3, ordre STO, 2 viandes, crudité-vs-supplément) |
| `tests/js/kdsSymbolic.spec.js` + `kdsSymbolicRender.spec.js` | Format symbolique JS (écran KDS) |
| `tests/Unit/Hardware/KitchenSymbolPhpJsParityTest.php` | **PARITÉ PHP↔JS** des tables de symboles (écran == papier) |
| `tests/js/kioskTicketComposition.spec.js` | Ticket CLIENT borne = compo complète (sauce + crudités + viandes + suppléments) |
| `tests/Feature/Hardware/OrderReceiptEscPosRendererTest.php` | Ticket CLIENT caisse = détail + fiscal |
| `tests/Feature/Hardware/KioskKitchenTicketTest.php` | Le ticket cuisine borne sort bien au bon format |

**Avant de livrer une modif d'impression** : `vendor/bin/phpunit --filter 'Symbolic|Receipt|KioskKitchen'`
+ `npx vitest run tests/js/kdsSymbolic*.spec.js tests/js/kioskTicketComposition.spec.js`.

---

## 6. Checklist « je modifie un truc un jour »

- [ ] La compo client reste **complète** (toutes les étapes en toutes lettres) ? → test §5.
- [ ] La cuisine reste **symbolique 3 lignes** (L1/L2/L3) ? → test §5.
- [ ] Si j'ai touché un symbole : modifié dans **PHP + JS** ? → sentinel parité §5 vert.
- [ ] Les 5 surfaces (§2) lisent toujours `composition_snapshot` (jamais une compo réinventée) ?
- [ ] Borne ET caisse, le ticket cuisine reste **imprimable** (renderer branché) ?
