# RECONNAISSANCE — KDS + OSS + CHAÎNE D'IMPRESSION

**Voie** : écran cuisine, écran statut client, chaîne d'impression comptoir + cuisine
**Repo** : `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt` — HEAD `e2d2ca3b4`
**Date** : 2026-08-15 · **Mode** : READ-ONLY, aucun fichier modifié
**Angle** : « le cuisinier travaille sans friction, AUCUN ticket ne se perd ni ne sort en double »

Toute ligne citée ci-dessous a été ouverte (grep/Read). Ce qui n'a pas pu être vérifié est
marqué `(à vérifier)`.

---

## RÉPONSES DIRECTES AUX 3 QUESTIONS DU BLOC 2

**(a) Reste-t-il un chemin capable de DOUBLER un ticket ? — OUI, trois.**
Le commentaire du commit `e2d2ca3b4` est **exact** (le déclencheur du watcher est bien
neutralisé, `KitchenDisplaySystemComponent.vue:1690`, et `autoPrintNewKitchenTickets()` n'a plus
aucun appelant vivant), mais il **ne referme pas** le sujet :
1. **P0 — le jumeau sans le garde-fou du délai.** Le listener désormais SEUL importe
   `printEscPosViaKitchenBridge` depuis `posLocalPrinter.js:158`, dont le délai client est
   **3 000 ms** (`posLocalPrinter.js:92`) alors que le pont cuisine répond le **résultat réel**
   d'impression borné à **15 000 ms** (`kitchen-bridge.js:54`). Abandon à 3 s pendant que le
   papier sort → faux échec → `ack(success:false)` → `releaseClaim` (suppression de la ligne) →
   re-réclamation au cycle suivant → **ré-impression en boucle**. Le jumeau
   `kitchenLocalPrinter.js:73` utilise **20 000 ms** et documente noir sur blanc cette exacte
   conséquence — le correctif n'a jamais été porté sur l'autre moitié.
2. **P1 — la boucle de retry KDS est toujours armée.** `retryFailedKitchenTickets()` tourne
   encore toutes les 20 s (`:1846`) et POSTe directement au pont **sans jamais toucher
   `kitchen_ticket_claims`**. Elle se nourrit de `localStorage['kds.failedKitchenIds']` restauré
   au montage (`:2217`) — donc des résidus de la soirée du 2026-08-14 sont encore sur le PC
   cuisine.
3. **P2 — le bouton de réimpression manuelle** (`:2381`) court-circuite lui aussi la réclamation
   serveur : sur une commande pas encore réclamée, il sort un papier que le listener sortira
   à nouveau ≤5 s plus tard.

**(b) Reste-t-il un chemin où un ticket peut être PERDU en silence ? — OUI.**
La fenêtre de réclamation est de **30 minutes** (`config/kds.php:114`) ET les statuts éligibles
s'arrêtent à `PREPARED` (`KitchenReleaseRule.php:16-22`). Un pont cuisine éteint plus de 30 min,
ou une commande avancée au-delà de `PREPARED` avant d'avoir été réclamée, sort définitivement de
la file `pending` — **sans aucune alarme** : rien dans `app/Console/` ni `app/Jobs/` ne surveille
`kitchen_ticket_claims`. Second cas : `document.hidden` coupe tout le sondage
(`KitchenTicketPrintListener.vue:104`) — écran verrouillé / navigateur réduit = zéro réclamation,
et la fenêtre de 30 min continue de courir.

**(c) Les deux dédup sont-elles cohérentes ? — NON, elles sont aveugles l'une à l'autre par
construction.**
`KitchenTicketPrintListener.vue` n'importe **aucun** helper de dédup (`:38-44`) : il ne connaît
que `kitchen_ticket_claims`. Le KDS (`:1224`) ne connaît que `localStorage`. Aucune écriture
croisée n'existe. Un ticket sorti par le KDS n'existe pas pour le serveur ; un ticket sorti par
le listener n'existe pas pour le KDS. Tant que les deux surfaces tournent sur le même PC cuisine,
c'est un doublon structurel, pas un accident.

---

## BLOC 1 — INVENTAIRE

### Chaîne d'impression (transverse)

| Classe | Fonction | Emplacement |
|---|---|---|
| **BASE** | Imprimeur global (réclame + imprime, monté sur tout écran admin) | `resources/js/components/admin/kitchen/KitchenTicketPrintListener.vue:75` |
| **BASE** | Montage global de cet imprimeur (`theme==='backend'`) | `resources/js/components/DefaultComponent.vue:35` |
| **BASE** | Sondage 5 s, démarrage différé 4 s, re-test pont 60 s | `KitchenTicketPrintListener.vue:66,69,73` |
| **BASE** | Détection de destination par pont présent (counter 9100 / kitchen 9101) | `KitchenTicketPrintListener.vue:59-62` |
| **BASE** | File serveur des tickets à sortir (`pending`) | `app/Http/Controllers/Admin/Pos/KitchenTicketQueueController.php:93` |
| **BASE** | Accusé de réception + remise en file sur échec | `KitchenTicketQueueController.php:240` |
| **BASE** | Réclamation atomique par destination (`insertOrIgnore` + unicité) | `app/Services/Kitchen/KitchenTicketAutoPrinter.php:173` |
| **BASE** | Reprise d'une réclamation orpheline (TTL 90 s, UPDATE conditionnel) | `KitchenTicketAutoPrinter.php:199-204` |
| **BASE** | Libération / marquage imprimé | `KitchenTicketAutoPrinter.php:214,223` |
| **BASE** | Table `kitchen_ticket_claims` + contrainte d'unicité (order_id, destination) | `database/migrations/2026_08_12_170000_create_kitchen_ticket_claims_table.php:62` |
| **BASE** | Octets ESC/POS du ticket (SSOT serveur, client ou cuisine) | `app/Http/Controllers/Admin/Pos/PosTicketBytesController.php:26` |
| **BASE** | Autorisation ticket cuisine élargie au rôle Chef | `PosTicketBytesController.php:37-39` |
| **BASE** | POST au pont cuisine (version listener) | `resources/js/helpers/posLocalPrinter.js:158` |
| **BASE** | POST au pont cuisine (version KDS, avec délai 20 s) | `resources/js/helpers/kitchenLocalPrinter.js:76` |
| **BASE** | POST au pont caisse 9100 | `posLocalPrinter.js:95` |
| **BASE** | Pont local cuisine (HTTP 127.0.0.1:9101, worker winspool) | `tools/kitchen-bridge/kitchen-bridge.js:278` |
| **BASE** | File FIFO + verdict réel 200/500 (pas de 202 optimiste) | `kitchen-bridge.js:222-252` |
| **BASE** | Saut d'un job abandonné par le client (anti-doublon partiel) | `kitchen-bridge.js:234` |
| **BASE** | Pont local caisse | `tools/caisse-bridge/caisse-bridge.js` |
| **BASE** | Chemin serveur→imprimante TCP (`printOnce`, garde `orders.kitchen_ticket_printed_at`) | `KitchenTicketAutoPrinter.php:63,126` |
| **BASE** | Déclencheur serveur à l'entrée en cuisine (ACCEPT/PREPARING) | `app/Listeners/AutoPrintKitchenTicketOnKitchenEntry.php:41` |
| **BASE** | Déclencheur serveur à la création (kiosk/web/online/delivery/uber_eats) | `app/Listeners/PrintKioskKitchenTicketOnOrderCreated.php:31` |
| **BASE** | Rendu du ticket cuisine (symbolique, largeur sûre) | `app/Services/Hardware/KitchenTicketSymbolicFormatter.php` · `OrderReceiptEscPosRenderer.php` |
| **BASE** | Bandeau cuisson (portions de viande agrégées) | `app/Services/Kitchen/MeatPortionCalculator.php:140` |
| **SECONDAIRE** | Réimpression manuelle depuis le KDS (pont direct, `force`) | `KitchenDisplaySystemComponent.vue:2381` |
| **SECONDAIRE** | Réimpression demandée côté serveur (`reprint_requested_at`) — **utilisée uniquement par l'écran photo Uber** | `KitchenTicketQueueController.php:161,204` · `app/Http/Controllers/Admin/UberPhotoCaptureController.php:267` |
| **SECONDAIRE** | Retry auto des tickets en échec (20 s) | `KitchenDisplaySystemComponent.vue:1846,2313` |
| **SECONDAIRE** | Dédup persistée localStorage + claims cross-onglet | `kitchenLocalPrinter.js:98,199,245` |
| **SECONDAIRE** | Liste d'échec persistée (survit au F5 pendant une panne) | `kitchenLocalPrinter.js:275,279,288` |
| **SECONDAIRE** | Seed du backlog au montage (n'imprime pas l'historique) | `KitchenDisplaySystemComponent.vue:2209` |
| **SECONDAIRE** | Bascule « Impression auto » ON/OFF persistée | `KitchenDisplaySystemComponent.vue:321,1738,2106` |
| **SECONDAIRE** | Ticket cuisine manuel depuis la caisse (pont **comptoir** 9100) | `resources/js/components/admin/pos/ReceiptComponent.vue:796,719` |

**Surfaces servies automatiquement** — comptoir : `kiosk, web, online, delivery, uber_eats`
(`KitchenTicketQueueController.php:64`) ; cuisine : les mêmes **+ `pos, phone`**
(`:72`). `pos`/`phone` sont exclus du comptoir à dessein (le caissier a déjà son papier au clic),
ce qui est cohérent : le clic caissier vise 9100, le canal automatique cuisine vise 9101 — deux
machines distinctes, vérifié `ReceiptComponent.vue:796` → `printEscPosViaCaisseBridge` →
`posLocalPrinter.js:25` (9100).

### KDS / OSS

> **Nommage vérifié** : aucune route ne porte le nom `kds-order.`. Le groupe authentifié a le
> *préfixe d'URL* `kds-order` mais le *nom* `kdsOrder.` (`routes/api.php:1582`) ; idem `ossOrder.`
> (`:1650`). Seules les 2 routes publiques OSS portent réellement `oss-order.` (`:1724`, `:1727`).

| Classe | Fonction | Emplacement |
|---|---|---|
| **BASE** | Liste des commandes du board | `routes/api.php:1583` → `app/Http/Controllers/Admin/KitchenDisplaySystemController.php:47` |
| **BASE** | Computed `orders` (source du board) | `KitchenDisplaySystemComponent.vue:1620` ← `store/modules/kitchenDisplaySystemOrder.js:35` |
| **BASE** | Grille FIFO V2 (**layout par défaut en prod**) | `KdsV2Grid.vue:112` · tri `:287` (`created_at` asc puis `id`) |
| **BASE** | Bump / avancer le statut | `KdsV2Grid.vue:507` (`onCtaTap`) · `KdsOrderCard.vue:546` (`onCta`) |
| **BASE** | Envoi du changement de statut + auto-réparation 409/422 | `KitchenDisplaySystemComponent.vue:1917` → `routes/api.php:1587` → `KitchenDisplaySystemController.php:73` |
| **BASE** | Rappel d'une commande bumpée (compensatoire, statut JAMAIS muté, 60 s) | `KdsHistoryDrawer.vue:480` → `routes/api.php:1606` → `KitchenDisplaySystemController.php:135` |
| **BASE** | Diffusion du rappel cross-poste | `app/Events/KdsOrderRecalled.php:27` · réception `KitchenDisplaySystemComponent.vue:3007` · réinjection `KdsV2Grid.vue:315` |
| **BASE** | Remise en préparation (« j'ai validé trop tôt ») | `KdsV2Grid.vue:160` → `KitchenDisplaySystemComponent.vue:2354` → `routes/api.php:1614` → `KitchenDisplaySystemController.php:174` |
| **BASE** | Sondage principal (15 s si WS actif, 5 s si WS tombé) | `KitchenDisplaySystemComponent.vue:2506` · cadence `:2500` |
| **BASE** | Push Echo (6 events) + anti-rebond 300 ms | `KitchenDisplaySystemComponent.vue:2515` · `:2986` |
| **BASE** | Synchro delta (repli WS dégradé) | `resources/js/services/KdsSyncService.js:154` → `routes/api.php:1592` → `KdsSyncController.php:32` |
| **BASE** | Règle SSOT de libération en cuisine | `app/Domain/Kds/KitchenReleaseRule.php:16,130` |
| **BASE** | Mur client OSS 2 colonnes (préparation / prêt) | `OrderStatusScreenComponent.vue:22` → `PreparingAndReadyComponent.vue:27` |
| **BASE** | OSS backend authentifié + public | `OrderStatusScreenController.php:25` / `:80` · `routes/api.php:1651` / `:1722` |
| **BASE** | Sondage OSS (60 s WS actif, 2 s déconnecté, **5 s forcé sur le mur public**) | `OssSyncService.js:9,22` · `PreparingAndReadyComponent.vue:274` |
| **SECONDAIRE** | Tiroir historique du jour | `KdsHistoryDrawer.vue:327` → `routes/api.php:1597` |
| **SECONDAIRE** | Filtre poste (bar / chaud / froid) — **legacy uniquement** | `KitchenDisplaySystemComponent.vue:294` · `helpers/kdsDisplay.js:51` |
| **SECONDAIRE** | Regroupement par table — legacy uniquement | `KitchenDisplaySystemComponent.vue:303,2420` |
| **SECONDAIRE** | Allergènes (pastille + modale) | `helpers/kdsAllergens.js:19` · `KdsOrderCard.vue:65` |
| **SECONDAIRE** | Auto-transition ACCEPT→PREPARING | `helpers/kdsAutoTransition.js:32` · `KdsV2Grid.vue:381` |
| **SECONDAIRE** | Rendu symbolique avancé | `helpers/kdsSymbolic.js:585` · `KdsOrderLine.vue:30` |
| **SECONDAIRE** | Bandeau CUISSON agrégé | `helpers/kdsSymbolic.js:936` · `KdsOrderCard.vue:81,443` |
| **SECONDAIRE** | Coloration fraîcheur + escalade d'âge (3 min / 6 min) | `helpers/kdsFreshness.js:52` · `helpers/kdsDisplay.js:63,77` |
| **SECONDAIRE** | Bandeau de statut V2 · bandeau commandes programmées | `KdsStatusBanner.vue:67` · `KdsScheduledBanner.vue:94` |
| **SECONDAIRE** | Cartes par écran 4/6/8 · raccourcis A–H · défilement ◀▶ | `KdsV2Grid.vue:90,207,124` |
| **SECONDAIRE** | Bande « Récemment servies » (4 dernières, cap 8 h) | `KdsV2Grid.vue:144,333` |
| **SECONDAIRE** | Chip source · bloc livraison · rupture « 86 » en vol | `helpers/kdsSource.js:54` · `KdsOrderCard.vue:135,160` |
| **SECONDAIRE** | Son nouvelle commande + déblocage autoplay — **legacy uniquement, cf. P0 BLOC 3** | `KitchenDisplaySystemComponent.vue:339,2114,2152` |
| **SECONDAIRE** | Produits populaires OSS — **composant non monté** | `PopularItemComponent.vue` (absent de `OrderStatusScreenComponent.vue:10`) ; routes `:1652`, `:1726` toujours servies |
| **CODE MORT** | `KdsUndoToast.vue` — retiré volontairement de V2, réintroduction interdite par sentinelle | `KdsV2Grid.vue:177` · `tests/js/sentinels/kdsV2MultiBumpSentinel.spec.js:72` |

---

## BLOC 2 — RISQUE TICKET

### Recensement des chemins produisant un ticket CUISINE (pont 9101)

| # | Chemin | Garde anti-doublon | Garde anti-perte | Vivant ? |
|---|---|---|---|---|
| C1 | `KitchenTicketPrintListener._tick()` → `pending` → `claimForBridge` → `ack` | **serveur** `kitchen_ticket_claims` unicité (order_id, destination) | `ack(false)` → `releaseClaim` → remise en file ; reprise TTL 90 s | ✅ **seul chemin auto** |
| C2 | `retryFailedKitchenTickets()` (intervalle 20 s) | **localStorage seul** (`hasKitchenPrinted` + `claimKitchenPrint`) | liste d'échec persistée | ⚠️ **armé** (`:1846`) |
| C3 | `reprintKitchenTicket()` (bouton 🖨️) | `claimKitchenPrint(force:true)` + in-flight — **aucune garde serveur** | toast d'erreur explicite | ✅ volontaire |
| C4 | `autoPrintNewKitchenTickets()` via watcher `orders` | localStorage | liste d'échec | ❌ **neutralisé** `:1690` (vérifié) |
| C5 | `printOnce()` serveur→TCP (2 listeners) | `orders.kitchen_ticket_printed_at` UPDATE conditionnel | `release()` sur exception | 💤 inerte en prod (`printers` vide) — **latent** |

Les gardes de C1 et de C5 sont **explicitement indépendantes** et assumées comme telles
(`KitchenTicketAutoPrinter.php:167-169`). Le jour où une imprimante serveur devient joignable,
C1 et C5 sortent chacun leur papier.

---

### `[P0]` `resources/js/helpers/posLocalPrinter.js:92` — le délai de 3 s du pont CAISSE est appliqué au pont CUISINE : faux échec → boucle de ré-impression

**scénario :** Une commande tombe. `KitchenTicketPrintListener` (désormais le SEUL chemin auto)
réclame, obtient les octets, POSTe au pont cuisine. L'impression physique prend plus de 3 s
(ticket long avec bandeau cuisson, imprimante au réveil, ou worker winspool relancé
paresseusement après une mort — `kitchen-bridge.js:174` recompile alors le P/Invoke PowerShell).
Le client abandonne à 3 s → `catch` → `null` → `success=false` → `ack(success:false)` →
`releaseClaim` **supprime la ligne** → au cycle suivant (5 s) la commande est de nouveau
candidate → nouveau papier. Tant que la latence dépasse 3 s, la boucle continue : ce n'est pas un
doublon, c'est un **rouleau**.

**evidence :**
- `posLocalPrinter.js:158-168` — `printEscPosViaKitchenBridge` appelle `rawTimeoutMs(opts)`.
- `posLocalPrinter.js:86-93` — une SEULE définition de `rawTimeoutMs`, clé
  `caisseBridgeRawTimeoutMs`, **défaut `3000`**. Son commentaire justifie ce chiffre ainsi :
  *« le pont répond désormais 202 {queued:true} DÈS réception (impression async côté pont) →
  3 s suffisent largement »*.
- Cette prémisse est **fausse pour le pont cuisine** : `kitchen-bridge.js:216-221` — *« La RÉPONSE
  HTTP attend désormais le RÉSULTAT RÉEL de l'impression (200 imprimé / 500 échec) — plus de 202
  optimiste »*, borné par `PRINT_TIMEOUT_MS = 15000` (`kitchen-bridge.js:54`).
- Le jumeau a le bon chiffre ET l'avertissement : `kitchenLocalPrinter.js:68-73` — *« Le timeout
  client DOIT être PLUS GRAND (20 s) : sinon on abandonnerait (abort) pendant que le pont imprime
  encore → faux « échec » → retry → DOUBLE impression »*, `return 20000`.
- La remise en file sur échec est confirmée côté serveur : `KitchenTicketQueueController.php:262-263`
  (`releaseClaim`) — et testée comme un comportement voulu (`tests/Feature/Pos/KitchenTicketQueueTest.php:107`).
- Le garde-fou du pont ne couvre pas ce cas : `kitchen-bridge.js:234` teste `abortState.aborted`
  **avant** `printRaw`, donc uniquement pour les jobs encore en file. Un ticket seul sur une file
  vide part immédiatement en impression (drapeau encore `false` à t≈0) et n'est plus annulable.
- `window.foodkingConfig.kitchenBridgeRawTimeoutMs` n'est défini nulle part (aucune occurrence
  dans `resources/views/`), donc aucune configuration ne corrige ce défaut en production.

**fix-suggéré :** donner au pont cuisine son propre délai dans `posLocalPrinter.js` (fonction
dédiée lisant `kitchenBridgeRawTimeoutMs`, défaut 20 000 ms — strictement supérieur aux 15 000 ms
du pont), exactement comme `kitchenLocalPrinter.js:62-74`. En complément : ne traiter comme
« échec » que les verdicts explicites (500) et considérer un abandon réseau comme *indéterminé*
(ne pas libérer la réclamation, laisser la reprise TTL 90 s trancher). Ajouter un test jumeau
imposant `timeout(kitchen) > PRINT_TIMEOUT_MS(pont)` pour interdire la re-divergence.

---

### `[P1]` `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:1846` — la boucle de retry KDS est restée armée après la désactivation de l'auto-print

**scénario :** Le correctif `e2d2ca3b4` a commenté l'appel du watcher (`:1690`) mais laissé
l'intervalle de 20 s et toute la machinerie. `_seedKitchenPrintedBacklogOnce()` restaure au
montage `this._kitchenFailedPrint = getKitchenFailed()` depuis
`localStorage['kds.failedKitchenIds']`. Le PC cuisine porte très probablement encore des ids de
la soirée du 2026-08-14 (période où le pont échouait par intermittence). À chaque ouverture du
KDS, pour chaque id encore présent sur le board, la boucle POSTe au pont **sans passer par
`kitchen_ticket_claims`** — pendant que le listener imprime la même commande via sa propre garde.

**evidence :**
- `:1846-1848` — `this._kitchenRetryInterval = setInterval(() => { this.retryFailedKitchenTickets(); }, KITCHEN_PRINT_RETRY_MS)` avec `KITCHEN_PRINT_RETRY_MS = 20000` (`:1228`).
- `:2331` — la boucle appelle `this.autoPrintKitchenTicket(order)`, qui atteint
  `printEscPosViaKitchenBridge` en `:2286`. Aucun appel à `admin/pos/kitchen-tickets/*` sur ce
  chemin (vérifié : les seules occurrences de cette route sont dans
  `KitchenTicketPrintListener.vue:118,187`).
- `:2217` — `this._kitchenFailedPrint = getKitchenFailed();` restaure la liste persistée.
- `kitchenLocalPrinter.js:275` — `FAILED_LS_KEY = 'kds.failedKitchenIds'`, persistance durable
  voulue (« survit au reload pendant une panne »).
- La liste ne peut plus être alimentée en fonctionnement (seuls `:2253` et `:2256`, dans la
  méthode désactivée, appellent `_addKitchenFailed`) : le risque est donc **purement résiduel**,
  ce qui le rend d'autant plus sournois — il ne se reproduira pas en test sur un profil neuf.

**fix-suggéré :** retirer l'intervalle et `retryFailedKitchenTickets()` (le filet de reprise vit
désormais côté serveur : TTL 90 s + `releaseClaim`), OU le faire transiter par
`admin/pos/kitchen-tickets/{id}/ack`. Dans les deux cas, **purger `kds.failedKitchenIds` et
`kds.printedKitchenIds` au montage** (migration ponctuelle une fois) pour désamorcer les résidus
déjà présents sur le PC cuisine.

---

### `[P1]` `app/Http/Controllers/Admin/Pos/KitchenTicketQueueController.php:130` — hors de la fenêtre de 30 min ou passé `PREPARED`, un ticket jamais sorti est perdu sans aucune alarme

**scénario :** Le pont cuisine est éteint (PC redémarré, service non lancé — situation réelle du
2026-08-14). `_hasBridge()` renvoie `false`, donc **aucune réclamation n'est faite** : correct, ça
évite la perte. Mais la fenêtre de 30 minutes court quand même sur `orders.created_at`. Au
rallumage du pont 35 minutes plus tard, les commandes de la période ne sont plus candidates :
elles ne sortiront **jamais**, et rien ne le signale. Variante : le cuisinier avance une commande
au-delà de `PREPARED` avant que le pont l'ait réclamée → elle quitte `visibleStatuses()` et
disparaît définitivement de la file.

**evidence :**
- `:130` — `->where('created_at', '>=', now()->subMinutes($fenetreMinutes))` avec
  `$fenetreMinutes = config('kds.bridge_print_window_minutes', 30)` (`:106`), valeur par défaut
  confirmée `config/kds.php:114`.
- `:129` — `->whereIn('status', KitchenReleaseRule::visibleStatuses())`, et
  `app/Domain/Kds/KitchenReleaseRule.php:16-22` limite à `ACCEPT, PREPARING, PREPARED`.
- Aucune surveillance : `grep -rn "kitchen_ticket_claims" app/Console/ app/Jobs/` → **aucun
  résultat**. Le seul journal existe sur l'échec *accusé* (`:265` `Log::warning`), pas sur le
  ticket jamais réclamé.
- `KitchenTicketPrintListener.vue:104` — `if (document.hidden) return;` coupe tout le sondage
  (écran verrouillé, navigateur réduit), sans compensation côté serveur.

**fix-suggéré :** une sonde (commande planifiée) qui compte les commandes éligibles cuisine sans
ligne `kitchen_ticket_claims` `printed_at` au-delà de N minutes et lève une alerte visible en
caisse/KDS. Accessoirement, rendre la fenêtre configurable par destination et la relever pour
`kitchen` — un ticket cuisine de 40 min reste utile si la commande est encore en préparation.

---

### `[P2]` `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:2381` — la réimpression manuelle ignore la réclamation serveur

**scénario :** Une commande arrive ; dans les 5 s avant le tick du listener, le cuisinier
appuie sur 🖨️ (par exemple parce qu'il a vu la carte et pas le papier). Le KDS sort le ticket via
le pont, marque `localStorage`, mais **n'écrit rien dans `kitchen_ticket_claims`** : le listener
réclame ensuite normalement et sort un second papier.

**evidence :**
- `:2391-2394` — `claimKitchenPrint(order.id, { force: true })` puis
  `this.autoPrintKitchenTicket(order)` — chemin purement local.
- `:2399` — `markKitchenPrinted(order.id)` n'écrit que dans `localStorage`.
- Le mécanisme serveur prévu pour ça existe mais n'est pas utilisé ici : `reprint_requested_at`
  n'est posé que par `app/Http/Controllers/Admin/UberPhotoCaptureController.php:267` (vérifié :
  seule occurrence d'écriture hors du contrôleur de file).
- Effet de bord annexe : le bouton vise toujours 9101 (`kitchenLocalPrinter.js:29`), donc depuis
  le PC **caisse** il échoue systématiquement — le caissier ne peut pas relancer un ticket cuisine
  manquant.

**fix-suggéré :** router le bouton 🖨️ vers un endpoint qui pose `reprint_requested_at` sur
`(order, 'kitchen')`. Le listener sert alors la demande sur la bonne machine, avec accusé — et
le bouton devient utilisable depuis n'importe quel écran, y compris la caisse.

---

### `[P2]` `resources/js/components/admin/kitchen/KitchenTicketPrintListener.vue:193-195` — commentaire périmé : il annonce une perte définitive qui n'existe plus

**scénario :** Le commentaire affirme qu'un `ack` perdu condamne le ticket (« Le prochain cycle ne
le reprendra pas (il reste réclamé) »). C'est faux depuis la reprise des réclamations orphelines
du 2026-08-12. Un futur intervenant pourrait « corriger » ce faux problème et casser la garde
réelle.

**evidence :** `KitchenTicketAutoPrinter.php:199-204` reprend toute réclamation dont
`printed_at IS NULL` et `updated_at < now() - 90 s` ; `KitchenTicketQueueController.php:118-127`
la fait ressortir dans `pending`. Comportement couvert par
`tests/Feature/Pos/KitchenTicketQueueTest.php:192`.

**fix-suggéré :** mettre le commentaire à jour (la fenêtre résiduelle réelle est celle du P1
ci-dessus : 30 min / statut, pas l'accusé perdu).

---

### `[P2]` `app/Services/Uber/UberOrderMapper.php:81` — le bandeau cuisson Uber repose sur un mot dans le titre du groupe, jamais confronté à un vrai payload

**scénario :** Le fix `c377d959f` déduit « ceci est un choix de viande » par
`preg_match('/viande|meat/i', ...)` sur le **titre du groupe de modificateurs**, un texte saisi
par le commerçant dans le tableau de bord Uber. Deux échecs symétriques : un groupe nommé
« Choix de la garniture », « Votre protéine », « Base » ou « Au choix » **n'est pas détecté** (la
viande ne compte pas au bandeau — le défaut d'origine persiste) ; un groupe « **Sans** viande »
(option végétarienne) **est** détecté et injecte une portion fantôme. C'est le motif exact déjà
rencontré sur cette intégration (`memory/uber_photo_deploye_refus_devient_ajout_2026-08-12.md` :
nos canaux sont additifs, un ticket Uber s'écrit en négatif).

**evidence :**
- `app/Services/Uber/UberOrderMapper.php:80-81` — `$groupTitle = (string) ($group['title'] ?? $group['name'] ?? '');`
  puis `$isMeatGroup = (bool) preg_match('/viande|meat/i', $this->norm($groupTitle));`
- `:89` — `$lines[] = ['attribute_name' => 'Viande', 'variation_name' => $modTitle];` — le nom
  injecté est le titre **brut** du modificateur Uber, qui doit ensuite être reconnu par
  `MeatPortionCalculator` (`app/Services/Kitchen/MeatPortionCalculator.php:152-158` filtre sur
  `attribute_name` contenant « viande », puis résout `variation_name`). Deux couches de
  correspondance textuelle en série.
- Aucune donnée réelle : les seuls fichiers contenant un payload de modificateurs Uber sont
  `tests/Feature/Uber/UberIntegrationTest.php` et le test créé par le commit lui-même,
  `tests/Feature/Uber/UberOrderMapperMeatLinesTest.php` — soit une heuristique validée uniquement
  contre l'exemple qui l'a inspirée.

**fix-suggéré :** avant toute confiance, capturer un vrai payload Uber de production (une commande
tacos/sandwich réelle) et vérifier le titre exact des groupes. À court terme : exclure les
négations (`/\bsans\b/`) et élargir la détection aux libellés réellement utilisés par le
restaurant, en journalisant les groupes non reconnus pour construire la liste sur des faits.

---

### `[P3]` `resources/js/helpers/posLocalPrinter.js:158` vs `resources/js/helpers/kitchenLocalPrinter.js:76` — deux implémentations de la même fonction

Même nom, même rôle, comportements différents (délai 3 s vs 20 s ; retour `null` vs retour
discriminé `{ok:false, retriable:true}`). C'est la cause racine du P0 et la garantie que le
prochain correctif n'en réparera encore qu'une moitié.
**fix-suggéré :** une seule implémentation paramétrée par destination (URL + délai + clé de
config), les deux modules réexportant la même fonction.

---

## BLOC 3 — FRICTIONS DE CONFORT CUISINIER

> **Contexte de layout, déterminant pour lire ce bloc.** Le composant KDS embarque DEUX interfaces :
> la grille V2 (`v-if="useV2Layout"`, `KitchenDisplaySystemComponent.vue:131`) et une interface
> « legacy » en lanes (`<template v-else>`, `:149` → `:1186`). **V2 est le défaut en production**
> (`:1507` `return true;`, repli également `true` si le stockage est refusé). Plusieurs commandes
> historiques sont restées dans la branche legacy et sont donc **invisibles/inopérantes en
> production** — c'est la source des deux P0 ci-dessous.

### `[P0]` `KitchenDisplaySystemComponent.vue:339` — le carillon « nouvelle commande » ne sonne JAMAIS dans le layout de production

**scénario :** Coup de feu, le cuisinier est dos à l'écran, mains dans le bac à frites. Une
commande tombe : aucun son, aucune vibration. Il la découvre au prochain coup d'œil, 30 s à 2 min
plus tard. C'est la friction la plus coûteuse de toute la voie — elle décale le début de
préparation de chaque commande.

**evidence :**
- `:339` — `<audio ref="kdsNewOrderAudio" preload="auto" class="hidden" src="/sounds/kds-new-order.mp3" />`
  est situé **entre** `:149` (`<template v-else>`) et `:1186` (`</template>`), donc dans la branche
  legacy uniquement (vérifié par lecture des 4 lignes).
- `:2124-2127` — `const el = this.$refs.kdsNewOrderAudio; if (!el) { return; }` → sortie
  silencieuse quand la ref n'existe pas.
- `:1507` — `return true;` : V2 est le layout par défaut, donc la ref n'existe jamais en prod.
- Le repli haptique est également mort : il vit dans le `.catch()` de `el.play()` (`:2135`),
  jamais atteint. Idem `_unlockKdsAudio` (`:2159`).
- Le watcher joue pourtant bien le son à chaque nouvelle commande (`:1677`
  `this.playKdsNewOrderSound();` — c'est la seule ligne que le commit `e2d2ca3b4` a conservée).

**fix-suggéré :** remonter `<audio>` (et le bandeau « touchez pour activer le son », `:331`) à la
racine du template, avant le `v-if` de `:131` — même geste que `KdsScheduledBanner` (`:123`).
Verrouiller par une sentinelle `expect(ligneAudio).toBeLessThan(ligneVElse)`.

### `[P0]` `KdsV2Grid.vue:809` — la seule marche arrière de V2 est une cible tactile de 21 px

**scénario :** Le cuisinier valide « Prêt » sur la mauvaise carte ; elle quitte aussitôt la grille.
Pour la récupérer il doit viser une icône de 15 px dans une pastille de 21 px, sur une bande
horizontale défilante, avec des doigts gras. Il rate, touche la pastille voisine, ou renonce — et
un plat part incomplet.

**evidence :**
- `KdsV2Grid.vue:168` — `<svg width="15" height="15" ...>`.
- `KdsV2Grid.vue:809-814` — `.kds-v2__served-pill-reopen { display:inline-flex; ... padding: 3px; }`
  → 15 + 2×3 = **21 px** de cible utile (WCAG 2.5.5 = 44 px).
- Le voisin immédiat respecte pourtant la règle : `KdsV2Grid.vue:682-683` —
  `.kds-cols-picker__btn { min-width: 44px; min-height: 44px; }`.

**fix-suggéré :** porter le bouton à `min-width/min-height: 48px` (hauteur de bande ajustée), ou
rendre toute la pastille servie cliquable (surface ~90×32 px déjà présente) avec un libellé texte
« Reprendre » plutôt qu'une icône seule.

### `[P1]` `KdsOrderCard.vue:1041` — aucun état optimiste au bump : la carte ne bouge pas pendant ~0,5–1,5 s

**scénario :** Tap sur « Prêt ». Rien ne change à l'écran. Le cuisinier croit avoir raté et retape.
Le 2ᵉ tap est avalé **sans aucun retour visuel**. Il retape une 3ᵉ fois. Perception : « l'écran
rame » — alors que le système fonctionne.

**evidence :**
- `KdsOrderCard.vue:1041-1043` — seul retour visuel : `.kds-card__cta:active { transform: translateY(1px); }`,
  qui disparaît au relâchement.
- `KdsOrderCard.vue:552-555` — `if (this._ctaInFlight) return;` puis `setTimeout(... 1200)` :
  le 2ᵉ tap est bloqué, mais `_ctaInFlight` n'est pas une donnée réactive → aucune classe visuelle.
- La carte ne disparaît qu'après l'aller-retour : `KitchenDisplaySystemComponent.vue:1927` →
  `_debouncedRefresh()` (300 ms, `:2986`) → `dispatch('kitchenDisplaySystemOrder/lists')` (`:2656`).
  Aucune mutation locale de `order.status` entre-temps.

**fix-suggéré :** rendre `_ctaInFlight` réactif et lier une classe `--pending` (fond grisé +
coche) ; retirer la carte localement au succès, la restaurer sur erreur.

### `[P1]` `KdsOrderLine.vue:174` — le texte des lignes produit ne s'adapte pas à la largeur de carte et se fait couper à 2 lignes

**scénario :** Le cuisinier passe en 8 colonnes pour voir toute la file. Le n° et le bandeau
CUISSON rétrécissent proprement, mais les lignes produit restent en 22 px fixes et sont tranchées
à 2 lignes : « Tacos poulet cheddar sauce algérienne » devient « Tacos poulet cheddar… ». **La
sauce disparaît de l'écran** — l'information la plus fréquemment source d'erreur.

**evidence :**
- `KdsOrderLine.vue:171-181` — `font-size: 22px;` fixe + `-webkit-line-clamp: 2;`.
- Le fichier ne contient **aucune** unité `cqw`, alors que `KdsOrderCard.vue` en use partout
  (`:748` `clamp(16px, 8.5cqw, 34px)`, `:795` `clamp(24px, 13cqw, 52px)`) et que le
  `container-type: inline-size` est bien posé sur la carte (`KdsOrderCard.vue:422`).
- La carte est en `overflow: hidden` (`KdsOrderCard.vue:571`) et la colonne vaut 1/8 du viewport
  au réglage 8 (`KdsV2Grid.vue:586`).

**fix-suggéré :** passer `.kds-line__name` / `.kds-line__qty` / `.kds-line__symbolic-text` en
`clamp(…, Xcqw, …)`, et porter la troncature à 3 lignes ou la supprimer — une carte qui défile
vaut mieux qu'une sauce invisible.

### `[P1]` `KitchenDisplaySystemComponent.vue:324` — l'alerte « ticket non imprimé » n'est affichée nulle part **et** son compteur est désormais toujours à zéro

**scénario :** Le pont d'impression tombe. Les tickets ne sortent plus. Rien ne le signale sur
l'écran cuisine. Personne ne l'apprend avant qu'un client réclame un plat.

**evidence — double panne, les deux vérifiées :**
1. *Affichage* : le badge vit dans la branche legacy — `:324-327`
   `<span v-if="kitchenPrintFailedCount > 0" role="alert" ...>⚠️ ...</span>`, situé entre `:149`
   et `:1186`. Jamais rendu en V2. Même sort pour la bascule d'impression auto (`:321`) et le
   curseur de volume (`:315`).
2. *Donnée* : `kitchenPrintFailedCount` (`:1631`) lit `_kitchenFailedPrint`, alimenté uniquement
   par `_addKitchenFailed` — appelé exclusivement en `:2253` et `:2256`, **à l'intérieur de la
   méthode neutralisée par `e2d2ca3b4`**. Le compteur vaut donc structurellement 0 depuis ce
   commit, quel que soit le layout.
3. Côté imprimeur global, l'échec est explicitement silencieux :
   `KitchenTicketPrintListener.vue:127-133` — `catch (_) { }` avec le commentaire « Silence
   volontaire » (justifié pour ne pas polluer un encaissement, mais sans contrepartie côté cuisine).

**fix-suggéré :** un voyant sur l'écran cuisine **uniquement**, alimenté par l'état réel du pont
(`/health`) et par le dernier `ack` en échec — pas par `_kitchenFailedPrint`, qui est mort. Le
brancher sur la prop `error-message` déjà câblée de `KdsStatusBanner` (`:142`) ou sur la barre
racine `kds-toolbar` (`:23`).

### `[P1]` `KitchenDisplaySystemComponent.vue:294` — filtre poste et regroupement par table inaccessibles dans le layout par défaut

**scénario :** Deux écrans, un chaud un froid. Le poste froid voit défiler tacos et burgers qui ne
le concernent pas, et rate ses salades noyées dans le flux.

**evidence :** le `<select id="kds-station-filter">` (`:294-301`) est dans la branche legacy, et les
computeds qui l'appliquent ne servent que les lanes legacy (`:1637-1648`). `KdsV2Grid` reçoit
`:orders="orders"` **non filtré** (`:133`). Le helper `filterOrdersByStation`
(`helpers/kdsDisplay.js:51`) est donc mort en V2, alors que la préférence continue d'être
persistée (`:2099`) — l'utilisateur croit avoir réglé quelque chose.

**fix-suggéré :** remonter le `<select>` dans `kds-toolbar` (`:23`) et appliquer
`filterOrdersByStation()` sur la prop passée à `KdsV2Grid`.

### `[P2]` `KitchenDisplaySystemComponent.vue:3124` — les boutons de la barre haute font ~26 px de haut

**evidence :** `:3124-3135` — `.kds-history-trigger { padding: 4px 12px; font-size: 0.78rem; }`
≈ 12,5 px de texte + 8 px de marge ≈ **26 px**. Le bouton ⓘ est pire (`:3119-3121`,
`padding: 4px 9px`, ≈ 23 px). Le compromis est assumé en commentaire (`:3085` « ne PAS gâcher
l'espace des commandes ») — mais l'arbitrage s'est fait contre la cible tactile.
**fix-suggéré :** `min-height: 44px`, en compensant par un regroupement des réglages dans un menu.

### `[P2]` `KitchenDisplaySystemComponent.vue:550` — le bouton de réimpression des lignes « legacy » fait 32×32 px

**scénario :** Mains grasses, écran tactile, coup de feu. Le bouton 🖨️ est **l'unique outil de
récupération** quand un ticket manque. Dans les lanes legacy il mesure 32×32 px, sous le minimum
tactile de 44 px, et il est collé au bouton « Rappeler » — un appui manqué déclenche l'action
voisine.

**evidence :** `:550` — `class="w-8 h-8 rounded-lg border border-[#D9DBE9] ..."` (Tailwind
`w-8 h-8` = 32 px), répété à l'identique en `:737`, `:914`, `:1097`. La carte V2 fait mieux :
`KdsOrderCard.vue:986` documente « 52×52, gabarit du CTA » et `:998` `.kds-card__reprint`.

**fix-suggéré :** aligner les lanes legacy sur le gabarit 52×52 de la carte V2, avec une
séparation explicite d'avec « Rappeler ».

### `[P2]` `KitchenDisplaySystemComponent.vue:537` — layout legacy : bump 32×32 px et rappel en lien souligné 11 px

**evidence :** `:536-547` — bump `class="w-8 h-8 ..."` (32 px) et rappel
`class="text-[11px] ... underline ..."`. Motif répété à l'identique sur les 4 lanes (`:723`,
`:900`, `:1080`). Concerne les postes retombés en legacy (`?v2=0`, `localStorage kds.v2_enabled=0`,
ou `FK_KDS_V2_DEFAULT_ENABLED=false` — `:1488`, `:1495`, `:1504`).
**fix-suggéré :** `w-11 h-11` (44 px) sur le bump, rappel en bouton plein de même gabarit.

### `[P2]` `KitchenDisplaySystemComponent.vue:2654` — layout legacy : voile de chargement plein écran à chaque sondage (5–15 s)

**scénario :** Sur un poste legacy, un voile plein écran clignote toutes les 5 s quand le
WebSocket est tombé — précisément quand la cuisine a le plus besoin de lire. Sur réseau lent il
peut aussi intercepter un tap de bump.
**evidence :** `:2654` — `this.loading.isActive = true;` armé à chaque refresh ; le composant est
plein écran (`resources/js/components/admin/components/LoadingComponent.vue:2`,
`:is-full-screen="true"`), monté en `:158` donc dans le `v-else` (V2 en est épargné). Cadence
5 000 ms WS down (`:2500`).
**fix-suggéré :** n'armer le voile que sur la première hydratation (`!this._kdsOrdersHydrated`,
drapeau déjà présent `:1314`).

### `[P2]` `KdsV2Grid.vue:177` — annuler un bump demande d'ouvrir un tiroir : 3 gestes dans une fenêtre de 60 s

**evidence :** l'annulation en ligne a été retirée volontairement (`:177-184`, course
cross-commande réelle) et la réintroduction est verrouillée par sentinelle
(`tests/js/sentinels/kdsV2MultiBumpSentinel.spec.js:72`). Le seul chemin restant est le tiroir
(`KdsHistoryDrawer.vue:480`), avec le compte à rebours affiché uniquement là (`:195-197`).
**fix-suggéré :** ne pas ressusciter le toast — fiabiliser plutôt le `reopen` de la bande servies
(cf. P0 ci-dessus), qui rend le même service **sans** fenêtre de 60 s.

### `[P2]` `KdsOrderCard.vue:1081` — les cartes rappelées repassent à 70 % d'opacité, juste quand il faut les relire

**evidence :** `:428` applique `'kds-card--ready': this.kdsState === 'READY'` et `:1081-1083`
`.kds-card--ready { opacity: 0.7; }`. Or la réinjection après rappel conserve le statut `PREPARED`
(`KdsV2Grid.vue:322`), le backend garantissant que le statut n'est jamais muté
(`KitchenDisplaySystemController.php:120`). Une carte rappelée est donc systématiquement atténuée.
**fix-suggéré :** `.kds-card--ready.kds-card--recalled { opacity: 1 }` — la prop `recallActive`
existe déjà (`KdsV2Grid.vue:118`).

### `[P3]` `KdsV2Grid.vue:368` — les raccourcis clavier ne couvrent que le premier écran

**evidence :** `:368-370` — `return this.activeOrders.slice(0, this.cartesParEcran);` contre
8 lettres déclarées (`:207`) et 24 cartes montées (`:364`, `KDS_RENDER_MAX = 24` `:223`). Au
réglage par défaut de 4 colonnes (`:216`), 4 des 8 touches sont inertes.
**fix-suggéré :** faire défiler jusqu'à la carte visée (`scrollGrid` existe, `:130`) plutôt
qu'ignorer la touche.

### `[P3]` `KdsOrderCard.vue:663` — la pastille d'état est le plus petit texte de la carte (11 px)

**evidence :** `:663-673` — `font-size: 11px;` fixe, sans `cqw`, contre le n° (`:795`
`clamp(24px, 13cqw, 52px)`) et la cuisson (`:748`). Le même diagnostic avait déjà été posé et
corrigé pour la pastille allergène (`:694-697`, « était le PLUS PETIT texte de la carte… illisible
debout à 1-2 m ») — la pastille d'état n'a pas suivi. À 2 m du passe, le cuisinier ne distingue
pas « CONFIRMÉE » de « EN PRÉPARATION » et peut relancer deux fois la même viande.
**fix-suggéré :** `font-size: clamp(13px, 4.5cqw, 20px)` et `height: 28px`, comme l'allergène.

---

## BLOC 4 — TROU DE PREUVE EN TEST RÉEL

| Fonction BASE | Preuve |
|---|---|
| KDS liste les commandes | **TEST-UNIT** `tests/Feature/KDS/KdsBoardQueryPlanTest.php:165` (jeu d'ids exact), `:190`, `:214` · zombie `tests/Feature/KDS/KdsAdvanceZombieFloorTest.php:78` |
| KDS bump / avance statut | **TEST-UNIT** `tests/Feature/Kitchen/KdsBumpTimingTest.php:68,77,82` · concurrence `tests/Feature/KdsChangeStatusConcurrencyTest.php:50` · **TEST-E2E** `tests/e2e/_ultra-e2e-kds-oss-timing-2026-07-04.spec.js:95-104` |
| KDS rappel | **TEST-UNIT** `tests/Feature/KDS/KitchenRecallEndpointSentinelTest.php:81,111,117,235` · `tests/js/kdsBumpRecall.spec.js:25` · **TEST-E2E** `tests/e2e/test-e2e-abuse-C-kds.spec.js:507` (⚠ sous `if (recallButtonPresent)`) |
| Contenu du ticket cuisine (octets) | **TEST-UNIT** `tests/Unit/Hardware/KitchenTicketSymbolicFormatterTest.php:37,161` · octets bruts `tests/Feature/Hardware/KitchenTicketHeaderAndDistinctionTest.php:146` · `tests/Feature/Pos/PosTicketBytesEndpointTest.php:42` |
| Auto-print exactement 1× | **TEST-UNIT** `tests/Feature/Kitchen/KitchenTicketAutoPrintTest.php:175,183` (couvre C5) · `tests/js/kitchenLocalPrinter.spec.js:69,84` (couvre la dédup locale) · `tests/js/kdsNoDoubleAutoPrint.spec.js:36` (couvre C4) — **aucun test ne couvre la combinaison C1+C2/C3**, c'est-à-dire le doublon réellement survenu |
| Cycle réclamation/accusé/expiration | **TEST-UNIT** `tests/Feature/Pos/KitchenTicketClaimsMigrationTest.php:43,76,94,110` · `tests/Feature/Pos/KitchenTicketQueueTest.php:94,107,144,192,210,351` |
| Pont cuisine envoie les octets | **TEST-VACUOUS** `tools/kitchen-bridge/kitchen-bridge.test.js:70` — `cp.spawn = fakeSpawn` remplace `powershell.exe` ; le bloc winspool (`kitchen-bridge.js:66-94`) n'est jamais compilé. Le faux worker (`:59`) ne lit que le **nom** du fichier temporaire, jamais son contenu → la fidélité des octets sur le dernier saut n'est prouvée nulle part. (Ce qui EST prouvé et vaut d'être gardé : verdict réel 200/500 `:106`, worker figé → 500 `:135`, saut du job abandonné `:157`.) |
| Impression comptoir / caisse | **TEST-UNIT** `tests/Feature/Hardware/CounterPaidPrintAndDrawerTest.php:71,79,87` · `tests/Feature/EscPosOpenDrawerTest.php:55` · `tests/js/posCounterCollectPrint.spec.js:63` · **TEST-VACUOUS** `CounterPaidPrintAndDrawerTest.php:94` (`assertNotEmpty(getListeners(...))`) et `tests/Feature/Idempotency/CounterCollectAndPrintIdempotencyTest.php:100-115` (assertions sur la config de route) |
| OSS affiche préparation/prêt | **TEST-UNIT** `tests/Feature/OSS/OssBoardReleaseParityTest.php:51` · `tests/Feature/OSS/OssCustomerScreenFilterTest.php:118,148` · anti-PII `tests/Feature/OrderStatusScreen/OssPublicNoPiiTest.php:115` · **TEST-E2E** `_ultra-e2e-kds-oss-timing-2026-07-04.spec.js:117` · **TEST-VACUOUS** `tests/js/orderStatusScreenOssSync.spec.js:13` (lit le `.vue` comme une chaîne, ne monte rien) |
| Bandeau cuisson (portions viande) | **TEST-UNIT** `tests/Feature/Kitchen/MeatPortionCalculatorTest.php:55,70,217,242` · rendu ticket `tests/Feature/Hardware/KitchenTicketCuissonBannerTest.php:85,101` · parité PHP↔JS `tests/Feature/Kitchen/CuissonPhpJsParityFixtureTest.php` |
| Bandeau cuisson **sur commande Uber réelle** | **AUCUNE PREUVE RÉELLE** — seul `tests/Feature/Uber/UberOrderMapperMeatLinesTest.php` (créé par le commit qu'il valide, payload synthétique) |
| Fan-out `KdsOrderRecalled` → outbox | **AUCUNE PREUVE RÉELLE** — `app/Listeners/PersistKdsOrderRecalledToOutbox.php` n'a aucun test ; seul `tests/js/sentinels/kdsRecallButtonSentinel.spec.js:119` le mentionne, par expression régulière sur le texte source |

### Tests vacuous à ne pas compter comme couverture
- `tests/Feature/KDSFlowTest.php:117` — `assertEquals(200, $response->status())` seule assertion.
- `tests/Feature/KDSFlowTest.php:156` — `assertContains($status, [200,201,202,422])` : un bump
  **refusé** passe le test.
- `tests/Feature/OSSReadOnlyTest.php:30` — `in_array($status, [401,403,405])` : une surface
  entièrement cassée passe à l'identique.

### Prouvable UNIQUEMENT sur le matériel réel (jamais vert en CI)
1. Le papier sort physiquement de l'imprimante cuisine — dernier saut
   `powershell.exe` → `winspool.Drv WritePrinter` (`kitchen-bridge.js:66-94`), stubé en test.
2. Le papier sort physiquement de l'imprimante comptoir (`caisse-bridge.test.js:44`).
3. Fidélité des octets à travers le fichier temporaire (`kitchen-bridge.js:170` écrit, le faux
   worker ne relit jamais).
4. Le tiroir-caisse s'ouvre réellement (les octets sont prouvés, le solénoïde non).
5. Rendu CP858 sur la tête d'impression (accents, `€`).
6. Coupe mécanique, détecteur de fin de papier, lisibilité thermique, largeur réelle 80 mm.
7. **Le P0 ci-dessus** : il ne se manifeste que lorsque l'impression physique dépasse 3 s. Aucun
   test ne peut le révéler, puisque le pont est stubé et répond instantanément. **Il ne se verra
   que sur le vrai matériel, en service — c'est-à-dire au pire moment.**

---

## SYNTHÈSE

**Sur l'impression.** Le correctif `e2d2ca3b4` est **réel et correctement ciblé** : le second
déclencheur est bien neutralisé et verrouillé par un test. Mais il a fait du jumeau le plus faible
le **seul** chemin d'impression cuisine — celui dont le délai client (3 s) est plus court que le
temps d'impression que le pont peut légitimement prendre (jusqu'à 15 s). Le doublon exact-2 de la
soirée est corrigé ; un risque de boucle N reste armé, ainsi qu'une boucle de retry résiduelle
alimentée par des données persistées dans le navigateur du PC cuisine.

**Sur le confort cuisinier.** Le fil rouge n'est pas l'ergonomie de la grille V2 (globalement
soignée : `cqw`, cibles 44 px, escalade d'âge), c'est une **dette de migration** : plusieurs
fonctions de confort — carillon de nouvelle commande, alerte d'impression, filtre poste, réglage
du volume — n'ont jamais été portées de l'interface legacy vers V2 et vivent encore dans le
`<template v-else>` qui ne s'affiche plus en production. Elles ne sont pas cassées : elles sont
inatteignables. Le carillon muet est, de toute la voie, la friction qui coûte le plus de secondes
par commande.

**Deux motifs « jumeau oublié » identifiés**, tous deux conformes au motif récurrent du projet :
`printEscPosViaKitchenBridge` dupliqué avec deux délais différents (cause du P0 impression), et
les commandes de confort dupliquées entre legacy et V2 puis corrigées d'un seul côté.

**Ordre de traitement recommandé :**
1. P0 délai `posLocalPrinter` (risque de rouleau de tickets en service)
2. P0 carillon muet (remonter `<audio>` hors du `v-else`)
3. P1 boucle de retry KDS : retirer + purger `kds.failedKitchenIds` / `kds.printedKitchenIds`
4. P1 sonde serveur des tickets jamais sortis (aucune alarme aujourd'hui)
5. P0 cible tactile du `reopen` (21 px) — seule marche arrière de V2
6. P1 alerte d'impression visible en V2, alimentée par l'état réel du pont
7. P2 réimpression manuelle via `reprint_requested_at` (utilisable depuis la caisse)

**Avant toute confiance dans le bandeau cuisson Uber :** capturer un vrai payload de production.
L'heuristique `/viande|meat/i` sur le titre du groupe n'a jamais vu autre chose que son propre
test.
