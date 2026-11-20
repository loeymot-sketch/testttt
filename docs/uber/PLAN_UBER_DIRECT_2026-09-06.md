# Uber Direct — analyse de l'existant et plan d'intégration

> 2026-09-06. Rédigé **avant toute modification de code**, comme demandé.
> Chaque affirmation est adossée à un `fichier:ligne` ou à une requête SQL réelle
> (rapports détaillés : `reports/uber-direct-2026-09-06/`).

---

## 1. La bonne nouvelle : il n'y a rien à réécrire

L'architecture actuelle a **déjà** tout ce qu'il faut. Six points d'accroche existent.

| Besoin | Ce qui existe déjà | Où |
| --- | --- | --- |
| Marquer une commande « livraison » | **automatique** : `source_surface='delivery'` dès `order_type=5` | `Order.php:175-176`, `OrderType.php:7` |
| Stocker l'adresse client | table `order_addresses` (adresse, complément, **lat/lng**) | vérifié en base, 0 ligne |
| Adresse du restaurant | **déjà en base**, avec coordonnées | `branches` id 1 : `437 Rue Élie Gruyelle, 62110 Hénin-Beaumont`, `50.4215667 / 2.9549060` |
| Porter le frais au total | `PricingService.php:347` additionne `deliveryCharge` | **zone gelée NON concernée** |
| Décider le frais | `OrderRequest.php:108-128` (`prepareForValidation`) | **le seul point à modifier** |
| Savoir que c'est payé | webhook Mollie, qui re-interroge Mollie au lieu de croire le corps | `routes/api.php:176`, écriture décisive `Mollie.php:605-608` |
| Ne pas créer deux courses | `webhook_events` UNIQUE `(provider, webhook_id)` + `firstOrCreate` avec rattrapage sur violation | `Mollie.php:343-373` |
| Jeton OAuth Uber | `client_credentials`, cache −60 s, retry sur 401 | `UberClient.php:21-59` |
| Webhook signé | HMAC-SHA256, `hash_equals`, **corps brut préservé**, fail-closed si secret vide | `UberWebhookController.php:316-330` |
| Heure de prêt | `orders.preparation_time` (minutes) + `preparing_at` / `prepared_at` | vérifié en base |

**Conséquence** : aucune zone gelée n'est touchée, donc **aucun LOCK n'est nécessaire**.

## 2. Les six décisions d'architecture

### D1 — Un SECOND client Uber, pas une modification du premier
`UberClient` est réutilisable **en forme, pas en l'état** : sa clé de cache de jeton est une
**constante** (`UberClient.php:18`) — deux jeux d'identifiants s'écraseraient mutuellement — et
il lit `config('uber.*')` en dur. Les scopes diffèrent (`eats.store eats.order` vs
`eats.deliveries`).
→ **`UberDirectClient` séparé**, même patron, cache propre. On ne refactore PAS le client Eats,
qui est en service.

### D2 — Une TABLE DÉDIÉE, pas de colonnes sur `orders`
Le propriétaire l'a demandé explicitement : *« Le webhook Uber concerne la LIVRAISON. Le KDS
continue de gérer la PRÉPARATION. Ne mélange pas les deux machines d'état. »*
→ Table `uber_direct_deliveries` (`branch_id` + BranchScope, lien nullable vers `orders`),
sur le précédent exact de `uber_ticket_captures` (migration du 2026-08-10) : domaine neuf,
additif, hors NF525.
→ **Zéro arête nouvelle dans `OrderStateMachine`** (zone gelée intacte).

### D3 — L'argent : centimes DANS le nouveau domaine, décimal à la frontière
Le code existant manipule des `decimal(19,6)` en base et des `float` arrondis à 2 en PHP
(`PricingRequest.php:20`). Le propriétaire exige des entiers-centimes ; tout convertir serait
**réécrire l'application**, ce qu'il a interdit.
→ Compromis assumé : **entiers-centimes partout dans le service Uber Direct et sa table**
(Uber renvoie nativement des centimes), **une seule conversion** au moment d'alimenter
`delivery_charge`. Aucun calcul financier en flottant dans le code neuf.

### D4 — Le frais entre par `OrderRequest`, pas par le pricing
`PricingService` (gelé) se contente d'additionner ce qu'on lui donne. Le devis Uber se
substitue donc **uniquement** dans `OrderRequest.php:108-128`, là où le frais est décidé
aujourd'hui par le barème maison. Le barème maison reste en place comme repli.

### D5 — La course est créée APRÈS la confirmation serveur du paiement
Mollie est en **capture directe** (pas d'autorisation différée). Le serveur n'apprend le
paiement de façon fiable que par le webhook.
→ Accroche : un listener sur `OrderCreated`, dispatché après commit
(`FrontendOrderService.php:1759`) — motif déjà utilisé par l'impression cuisine.
→ Idempotence : clé portant l'identifiant de commande, plus la contrainte UNIQUE de la table
dédiée. **Un rejeu de webhook ne peut pas créer deux courses.**

### D6 — La règle tarifaire est isolée dès le premier jour
`delivery_fee_customer = montant Uber`, sans remise ni majoration. Mais la règle vit dans une
classe dédiée à un seul point d'entrée, pour qu'on puisse plus tard ajouter « offerte au-delà
de X € », une participation du restaurant ou un plafond **sans toucher à l'intégration Uber**.
**Aucun tarif n'est écrit en dur.**

## 3. Trois obstacles réels, à traiter avant la mise en service

### O1 — La livraison est ÉTEINTE au serveur
`order_setup_delivery = 10` (`DISABLE`), refus en `OrderRequest.php:295`, posé par la
migration `2026_07_27_093000_disable_delivery_until_launch`.
→ **Rien de ce chantier n'a d'effet visible tant qu'elle l'est.** À rallumer (`= 5`) au
moment du go-live, pas avant.

### O2 — `deploy.sh` lance `config:cache`, qui casse la chaîne fiscale
`scripts/deploy/deploy.sh:289`. Le garde existe (`AppServiceProvider.php:381-396`) mais tout
le bloc est enfermé dans `if (app()->environment('production'))` — or **la production tourne
en `APP_ENV=staging`**. Le garde est donc **inerte sur la machine qui encaisse**.
État actuel sain : `bootstrap/cache/config.php` **absent**.
→ Procédure de déploiement : tout sauf `config:cache`. Si le fichier apparaît :
`rm bootstrap/cache/config.php` (jamais `config:clear`, qui démarre l'application et se
heurte au garde).

### O3 — Le polygone de zone de la branche 1 est à PARIS
`branches.zone` contient un polygone `48.86 / 2.33` — **faux de 200 km**. Sans effet
aujourd'hui (livraison éteinte), mais il piégerait toute garde de zone maison.
→ Ne pas s'appuyer dessus. **Uber Direct est la source de vérité de la livrabilité**, comme
demandé.

## 4. Ce que je ne coderai pas sur une supposition

La documentation publique d'Uber ne donne pas : le schéma exact des corps de requête, le
mécanisme d'idempotence côté Uber, les points d'entrée « lire » et « annuler », ni la durée de
validité d'un devis. Une page décrit même les devis en `GET`, une autre en `POST`.
→ Détail dans `docs/uber/UBER_DIRECT_API_FAITS_VERIFIES_2026-09-06.md`. Ces points seront
**paramétrables**, et confrontés à la documentation du compte avant tout appel réel.

## 5. Ordre d'exécution proposé

1. `config/uber_direct.php` + `UBER_DIRECT_ENABLED=false` par défaut (rien ne bouge tant que
   c'est faux).
2. Migration `uber_direct_deliveries`.
3. `UberDirectClient` (OAuth) + `UberDirectService` (devis, création, statut).
4. Règle tarifaire isolée + branchement dans `OrderRequest`.
5. Endpoint `POST /api/delivery/quote`.
6. Listener de création de course après paiement confirmé, idempotent.
7. Webhook `POST /api/webhooks/uber-direct`, signature vérifiée sur le corps brut.
8. Suivi client (`tracking_url`) sur le récapitulatif de commande.
9. Checkout du site Vercel : choix « À emporter / Livraison » + saisie d'adresse.
10. Tests (les dix cas demandés), puis bac à sable, puis production.

## 6. Ce qui dépend de toi, et bloque la suite

1. **Vérifier qu'Uber Direct couvre Hénin-Beaumont** — la couverture diffère de celle de la
   marketplace. **Avant tout développement facturable.**
2. Ouvrir le compte sur `direct.uber.com`, onglet *Developer* : relever `Customer ID`,
   `Client ID`, `Client Secret`, puis la *Webhook Signing Key*.
   ⚠️ **Ne jamais les envoyer dans un message** — ils vont dans le `.env` du serveur.
3. Demander l'**accès bac à sable** et la **documentation des endpoints du compte**.
