# RED-2 — Contestation de A5 « Uber & livraison »

Adversaire, lecture seule. HEAD `a91f95e2e`. Base mesurée : `foodking_e2e` (celle de `.env`).
Aucune écriture, aucun test lancé (arbre partagé). Chaque contestation porte une mesure exécutée.

---

## 1. « Les frais dynamiques par adresse existent déjà » — **NUANCÉE** (le socle est vrai, les trois qualificatifs sont faux)

**Ce que je confirme.** Ce n'est pas du code mort : `OrderRequest.php:108` appelle
`quoteForSavedAddress()`, alimenté par le vrai parcours client (`CheckoutComponent.vue:783-793`
calcule la distance, pose `branch_id` et `address_id`). Et les colonnes ne sont pas NULL :

```
id | name                   | base | per_km | mini | free_km | zone_len
 1 | Le Cayenne (principal) | 3.00 |   2.00 | 4.00 |    3.00 |      119
 2..10 (branches de démo)   | NULL |   NULL | NULL |    NULL |        0
```

La branche 1 est peuplée et la zone existe (119 caractères de polygone). Le repli hérité ne
s'applique donc **pas** sur la branche de production. Je le concède.

**Ce que je réfute — trois choses.**

**(a) Le barème cité est PÉRIMÉ.** A5 écrit « la règle patron *4 € jusqu'à 5 km, +1 €/km*
(`DeliveryFeeService.php:39-49`) ». Il a recopié un **commentaire obsolète**. Barème réel,
calculé par le service lui-même (`php artisan tinker`, branche 1) :

```
d=1..3km -> 4 EUR   d=4km -> 5 EUR   d=5km -> 7 EUR
d=6km -> 9 EUR      d=8km -> 13 EUR  d=10km -> 17 EUR
```

C'est **4 € jusqu'à 3 km, puis +2 €/km entamé**. `DeliveryConfigSeeder.php:51-56` le dit :
« Barème owner 2026-07-27 (**remplace** 2026-06-27) ». Et
`tests/Feature/Delivery/DeliveryOwnerRuleHeninBeaumontSentinelTest.php:19-30` tient le
journal de dérive : « 2026-06-27 : base 4 € ≤5 km +1 €/km » y figure explicitement comme
règle **REMPLACÉE**. A5 a cité la version d'avant-dernière génération.

**(b) La livraison est COUPÉE côté serveur.** A5 n'en dit pas un mot. Mesure :

```
order_setup_delivery = 10   (DISABLE=10 / ENABLE=5)
free_delivery_above  = 0
```

`database/migrations/2026_07_27_093000_disable_delivery_until_launch.php:25-26` a posé ce gate,
et il mord dans les trois portes : `OrderRequest.php:295`, `PosOrderRequest.php:306`,
`TableOrderRequest.php:61`. Le devis « dynamique » est donc aujourd'hui **inatteignable en
exploitation** : aucune commande DELIVERY ne peut être créée. Dire « EXISTE » sans dire « et
c'est éteint » induit le plan en erreur.

**(c) « Géocode l'adresse » n'est vrai que sur une porte sur deux.** `DeliveryQuoteService`
(géocodage + garde de zone par ray-casting `:71-74`) n'est appelé que par `OrderRequest.php:108`.
La caisse ne l'appelle **jamais** : `PosOrderRequest.php:47-58` et `PosController.php:247-255`
appellent `DeliveryFeeService::fromDistanceKm()` **directement**, avec la distance envoyée par le
client — donc **sans garde de zone et sans re-dérivation de la distance**. Le commentaire
`DeliveryQuoteService.php:62-70` avertit de ce risque exact. Sur le chemin caisse, il est ouvert.

**Tests** : 21 tests couvrent la zone (`DeliveryFeeServiceTest`, `DeliveryFeeConfigurableTest`,
`DeliveryZoneGuardTest`, `DeliveryFeeBranchWireupSentinelTest`, `OrderRequestDeliveryFeeAuthorityTest`)
plus la sentinelle de barème. Couverture réelle. Non exécutés (arbre partagé).

---

## 2. « Uber Direct est ABSENT — 0 ligne » — **CONFIRMÉE**

Motifs disjoints des siens :
`grep -ril "uberdirect|uber_direct|uber-direct|deliveries/quote|delivery_quote|dropoff|pickup_address|courier_|manifest_items"`
sur `app/ config/ routes/ resources/js database/` → **zéro fichier**. `courier` n'apparaît que dans
deux commentaires (`SimpleOrderResource.php:123`, `OrderRequest.php:305`).
`git log --all -i --grep="uber direct"` → vide. Branches Uber : `fix/uber-order-fetch-v2` et
`origin/worktree-uber-scan-titre-entier-2026-08-20`, aucune Direct. **Je concède sans réserve.**

---

## 3. « Point de bascule unique : `OrderService.php:3163` » — **CONFIRMÉE** (avec deux nuances)

Balayage de toutes les écritures : la **seule** mutation d'exécution est
`OrderService.php:3208` (`$order->delivery_boy_id = (int) $targetId; $order->save();`).
`delivery_boy_id` n'est **pas** dans `Order::$fillable` (vérifié `app/Models/Order.php:21-80`) —
donc pas d'assignation de masse. Les occurrences `OrderService.php:83,111` et
`FrontendOrderService.php:98` sont des listes de **filtres de lecture**, pas des écritures.
Seuls `OrderTableSeeder.php` / `KdsOrderTableSeeder.php` écrivent en dur (données de démo).

Nuances. (i) `TableOrderController.php:31` déclare `selectDeliveryBoy` dans sa garde de permission
alors que **la méthode n'existe pas** dans ce contrôleur — entrée morte, pas un trou.
(ii) Surtout, le service accepte `$auth = true` : chemin **self-service client**
(`OrderService.php:3169-3173`, contrôle de propriété seul). Seconde frontière de confiance sur le
même point ; un plan de bascule qui n'ouvre le mode « uber » que côté admin doit l'exclure.

---

## 4. « Une course Uber emprunte le chemin existant, la zone gelée reste intacte » — **RÉFUTÉE**

La moitié transitions est juste : `OrderStateMachine.php:102-107` autorise bien
`OUT_FOR_DELIVERY → {DELIVERED, CANCELED}` — l'annulation coursier après départ **a** son arête
(ouverte le 2026-08-19, LOCK-OSM-CANCEL-AFTER-READY). La machine à états n'est pas le problème.

Le problème est **en aval** : tout le pipeline suppose que le livreur est un `User` maison
authentifié. Une course Uber n'en a pas. Ce qui casse, précisément :

1. `OrderService.php:2154` — `'driver_id' => Auth::check() ? (int) Auth::id() : null`. Pas de
   session Uber → **`null`**.
2. `OrderService.php:2255` — `if (! empty($cashEscrowMeta['driver_id']))`. Avec `null`, le
   mouvement de caisse livreur **n'est jamais enregistré**. Or `ZReportCashEnrichmentService:489`
   recoupe `audit_logs` contre `delivery_boy_cash_movements` : dérive `movement_missing_audit_row`
   sur **chaque** paiement à la porte. C'est un trou de piste d'espèces NF525, pas un détail d'UI.
3. `OrderService.php:2222` — la ligne d'audit `delivery.cash_collected_escrow` part avec
   `user_id => null` : encaissement sans acteur.
4. `OrderService.php:2021` — `deliveryBoyOrderChangeStatus` **403** si
   `$order->delivery_boy_id != $user->id`. Aucune commande Uber ne peut être passée DELIVERED par
   la voie livreur ; il faut repasser par la caisse/admin.
5. `OrderService.php:336` et `:384` — les listes du livreur filtrent sur `delivery_boy_id` : une
   course Uber est invisible partout où l'exploitation regarde ses livraisons.
6. `DeliveryBoyCashSessionOpenRequest.php:37,56-66` — la session de caisse exige un utilisateur
   **portant le rôle Delivery Boy**. Pas de session possible pour Uber.
7. La bascule proposée par A5 (« accepter `delivery_mode` au lieu de `delivery_boy_id` ») heurte
   sa propre garde : `OrderService.php:3176` `abort(422)` si `delivery_boy_id` n'est pas un entier
   positif. L'extension n'est pas gratuite.

Conclusion : « la zone gelée n'a pas à être touchée » est probablement vrai ; « le chemin existant
suffit » est faux. Le chantier n'est pas dans `OrderStateMachine`, il est dans la **comptabilité
espèces** et dans les **écrans qui filtrent sur `delivery_boy_id`**.

---

## 5. « Chaque bascule vend à 4 € une course qu'Uber facture davantage » — **RÉFUTÉE**

Le « 4 € » est le **plancher ≤ 3 km**, pas le tarif. Grille réelle : 4/5/7/9/13/17 € (§1).
Et voici ce qui a été **réellement facturé** — `orders` où `order_type = 5` (`OrderType::DELIVERY`
vaut **5**, pas 3 ; ma première requête s'était trompée de colonne et je la corrige) :

```
n=87   min=0.00   max=5.00   moyenne=1.58   dont 55 à 0 EUR   dont 15 avec livreur
distribution : 0 EUR ×55 · 5 EUR ×18 · 3 EUR ×9 · 4 EUR ×4 · 4.40 EUR ×1
```

Aucune commande n'a jamais été facturée au-dessus de **5 €** ; le barème 7/9/13/17 n'a **jamais**
produit une ligne, et les 18 à 5 € portent la signature du repli hérité `max(5, …)`. Le chiffre
honnête n'est donc ni « 4 € » ni « +1 €/km » : c'est **1,58 € de moyenne observée, dont 63 % à
zéro**. L'intention d'A5 est juste (le supplément Uber manque), sa mesure est fausse.

---

## Ce qui change le plan (15 lignes)

1. La livraison est **éteinte au serveur** (`order_setup_delivery=DISABLE`). Toute tâche Uber est
   derrière une décision propriétaire de lancement de la livraison. À mettre en tête du plan.
2. Corriger le barème partout où il est écrit : **4 € ≤3 km, +2 €/km entamé** (4/5/7/9/13/17).
   Le commentaire `DeliveryFeeService.php:39-42` est périmé et a déjà trompé un auditeur.
3. `free_delivery_above = 0` : l'offerte est coupée. À re-décider explicitement au lancement.
4. Boucher d'abord le chemin **caisse** : il court-circuite `DeliveryQuoteService`, donc pas de
   garde de zone ni de re-dérivation de distance (`PosOrderRequest.php:47-58`).
5. L'écran d'admin des colonnes de tarif manque toujours — confirmé, priorité avant Uber.
6. Le point de bascule unique tient, mais couvrir aussi le chemin self-service (`$auth = true`).
7. Le vrai chantier Uber n'est pas la machine à états : c'est la **caisse espèces livreur**.

**Risque n°1 qu'A5 n'a pas vu — l'impossibilité de réconcilier.** La table `orders` **n'a aucune
colonne de distance** (`SHOW COLUMNS FROM orders` → `delivery_charge`, `delivery_time`,
`delivery_boy_id` ; **pas** de `delivery_distance_km`). La distance est calculée à la volée puis
jetée. Le jour où l'on bascule sur Uber, on ne pourra comparer **aucune** course : ni « ce que
nous avons facturé au client » contre « ce qu'Uber nous a facturé », ni même vérifier a posteriori
qu'un frais correspondait à sa distance. Avant tout supplément paramétrable, il faut **persister
la distance et le fournisseur** sur la commande. Sans cela, le supplément que réclame A5 sera
réglé à l'aveugle, et la perte restera invisible dans les rapports.
