# PLAN — Étiquettes Brother QL‑820NWB (DLC / hygiène + étiquettes par produit de commande)

> Créé 2026‑10‑16 · mandat propriétaire « max discipline, fonctionnel sans faute ».
> Domaine NEUF, ADDITIF, HORS NF525 (aucun prix / séquence fiscale / chaîne d'audit touchés).
> Aucune zone gelée (CLAUDE.md §7) n'est modifiée.

## 1. Ce que veut le propriétaire (verbatim condensé)

1. **DLC / hygiène** — une catégorie « Étiquettes » dans le dashboard ET sur la tablette. Une liste de
   produits (oignons, tomates, olives, champignons…), chacun avec une durée de conservation. Un appui =
   étiquette « produit · date d'ouverture · date limite de consommation » imprimée.
2. **Étiquettes de commande** — écran « commandes en cours » comme l'écran de scan Uber. Pour chaque
   PRODUIT d'une commande : n° de commande en haut, nom du produit, personnalisation **en clair**
   (sauces, crudités, menu ou seul, frites, suppléments — pas les symboles du ticket cuisine), et un
   **n° de produit « 2/4 »** pour savoir combien il y en a dans la commande.
3. Mode **semi‑automatique** (bouton Imprimer) aujourd'hui ; **automatique** demain (la tablette posée,
   elle imprime toute commande qui arrive).
4. Imprimante connectée **en Bluetooth à la tablette Android** et qui y reste connectée.

## 2. Choix techniques (mes décisions — le propriétaire peut les renverser)

| Sujet | Décision | Preuve / raison |
|---|---|---|
| Transport | **Web Serial sur Bluetooth RFCOMM (SPP)**, depuis Chrome Android | MDN BCD `api/Serial.json` : Chrome Android 138 « Serial ports are only available if they're provided by Bluetooth RFCOMM serial port emulation », sans restriction depuis 148. Datasheet QL‑820NWB : profil SPP. Même technique que l'afficheur client (`posCustomerDisplay.js`). |
| Pas de serveur dans la boucle d'impression | Le navigateur écrit sur le port ; Laravel (cloud OVH) ne peut pas joindre une imprimante Bluetooth. | Même constat que `posCustomerDisplay.js` (2026‑09‑30). |
| Protocole | **Raster Brother** (QL‑800/810W/820NWB Raster Command Reference v1.01), SANS compression | Le manuel : « The QL‑800 does not support the compression mode » → on n'en dépend pas. 90 octets/ligne (720 broches). |
| Rendu | **Canvas** navigateur → bitmap 1 bit | Accents, ligatures (Œuf), gras : aucune table de caractères à maintenir. L'aperçu à l'écran EST l'image envoyée. |
| Rouleau par défaut | **62 mm continu (DK‑22205)**, longueur selon le contenu, coupe auto | Une étiquette de sandwich personnalisé a une longueur variable. Autres formats réglables (62×29, 62×100, 29×90, 38×90, 29 continu). |
| Source des commandes | Même service que l'écran cuisine (`KitchenDisplaySystemOrderService::list`) + `KDSOrderDetailsResource` | « Étiquettes à imprimer » == « commandes sur l'écran cuisine ». |
| Composition en clair | Réutilise les helpers cuisine exportés (`collapseBundledAddonItems`, `kdsVariationGroupValue`, `fritesSauceNames`, `extraSauceNames`/`extraDisplayName`, `cruditeSymbol`, `sanitizeKdsInstruction`, `categorize`) — aucun fichier cuisine modifié | Le repli des formules et la lecture des sauces ont déjà coûté des incidents réels ; on ne les réécrit pas. |
| Numérotation | « k/N » compte les **unités étiquetées** de la commande (2 sandwichs identiques = 2 étiquettes, 1/3 et 2/3) | Demande explicite. |
| Boissons seules | **Exclues par défaut** (réglable) — la boisson d'un menu est écrite sur l'étiquette du menu | Une canette ne reçoit pas d'étiquette collée ; décision réglable, signalée au propriétaire. |
| Mode auto | Ne s'arme que pour les commandes **arrivées après l'activation** ; verrou serveur (`claim`) → deux tablettes n'impriment jamais deux fois | Sans cela, activer l'auto imprimerait toute la file déjà en cuisine. |
| DLC | Dates calculées **côté serveur** (horloge unique), journalisées (traçabilité HACCP) ; repli local si le réseau tombe | Papier == journal. |
| Secours | Bouton « Imprimer via Android » (`window.print`, plugin Brother) si Web Serial indisponible | Navigateur non Chrome / Chrome < 138. |

## 3. Livrables

### Backend (Laravel)
- Migrations : `dlc_label_products`, `label_print_logs`. Modèles `DlcLabelProduct`, `LabelPrintLog` (BranchScope).
- `Admin\LabelController` : CRUD produits DLC, `dlc/print` (calcul serveur + journal), `orders` (flux cuisine),
  `orders/{order}/claim|printed`, `prints/{log}/result`, `history`.
- Routes `api.php` (porte `permission:pos-orders|pos`, comme l'écran Uber photo).
- Tests PHPUnit : calcul de DLC, isolation de branche, claim idempotent, permissions.

### Frontend
- `helpers/brotherQl.js` — table des rouleaux, encodeur raster, analyse du statut 32 octets (pur, testé).
- `helpers/labelPrinter.js` — gestionnaire Web Serial (connexion, reconnexion sans geste, file d'impression, statut, erreurs en français).
- `helpers/labelRender.js` — mise en page canvas → bitmap 1 bit.
- `helpers/orderLabelBuilder.js` — commande → étiquettes (pur, testé sur des compositions RÉELLES de la base).
- Écrans : `LabelDlcComponent`, `LabelOrdersComponent`, `LabelPrinterSettingsComponent`, barre d'état imprimante.
- Entrée de menu « Étiquettes » (3 sous‑entrées), routes, i18n fr + en.

### Preuves
- Vitest (encodeur, statut, builder, rendu) · PHPUnit · Playwright avec **port série simulé** qui enregistre les octets,
  les re‑décode en image et vérifie qu'elle est identique à l'aperçu (aller‑retour complet protocole + orientation).
- Capture + lecture des écrans (tablette portrait/paysage).
- Revue adversariale (agents) sur le diff avant livraison.

## 4. Limites assumées (non vérifiables sans la machine — à tester par le propriétaire)

1. Appairage Bluetooth Android + Chrome ≥ 138 sur LA tablette : testé par la page « Imprimante » (diagnostic).
2. Orientation physique du papier (le sens miroir suit la convention du manuel Brother ; un bouton « Étiquette test » avec flèche permet de la vérifier en 5 s ; un réglage inverse existe).
3. Extinction automatique de l'imprimante (réglage « Auto Power Off » à mettre sur « Jamais ») — expliqué dans la page Imprimante.
