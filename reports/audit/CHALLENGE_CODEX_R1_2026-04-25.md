## A) Hypothèses
- *Supposition* : l’objectif V1 prioritaire est un flux opérationnel minimal POS · Borne · KDS, pas une couverture exhaustive fiscale/NF525.
- *Supposition* : les rôles réels en production suivent les permissions Laravel actuelles ; les seeders/guards complets n’ont pas été relus.
- *Supposition* : Echo/Pusher et les queues tournent en production ; l’audit juge surtout les contrats code/events.
- *Supposition* : les tests cités sont représentatifs, mais je n’ai pas lancé toute la suite ni Playwright.
- *Supposition* : les gros fichiers ont été lus par extraits ciblés ; je marque `UNVERIFIED` quand le fichier complet n’a pas été ouvert.
- *Supposition* : Graphiti/MCP n’était pas disponible dans cette session ; fallback par docs et mémoire locale.
- *Supposition* : les invariants AGENTS.md restent bloquants : prix serveur, `branch_id`, enum `OrderStatus`, dispatch après commit, symétrie Order/Frontend.

## B) Ce qui me semble **correct / robuste** dans le code ou la doc
- `OrderStatus` est centralisé dans `app/Enums/OrderStatus.php:5-15`, ce qui évite une partie des chaînes magiques côté PHP.
- La machine d’état est explicite dans `app/Domain/Order/OrderStateMachine.php:33-67`, avec transitions canoniques et terminaux protégés.
- Les raisons obligatoires pour annulation/rejet/retour existent dans `OrderStateMachine.php:177-183`.
- Les events critiques utilisent un trait after-commit : `app/Events/Concerns/DispatchableAfterCommit.php:29-41`, testé par `tests/Feature/DispatchAfterCommitTest.php:54-85`.
- Les events `OrderCreated`, `OrderStatusChanged`, `OrderTableChanged`, `ItemAvailabilityChanged` sont branchés vers outbox/listeners dans `app/Providers/EventServiceProvider.php:102-133`.
- L’outbox a un contrat d’enveloppe strict côté backend : `app/Domain/Events/EventContract.php:34-73` et validation `:81-129`.
- `DispatchDomainEventsJob` verrouille la ligne outbox avant diffusion et réinitialise en cas d’exception : `app/Jobs/DispatchDomainEventsJob.php:65-117` et `:140-161` (`UNVERIFIED`: fichier lu par extraits).
- Le canal Echo `branch.{branchId}` vérifie `branch_id`, y compris restriction kiosk token → branche machine : `routes/channels.php:25-38`.
- POS serveur ignore les totaux client et recalcule via pricing SSOT : `app/Services/OrderService.php:592-655` (`UNVERIFIED`).
- Borne serveur ignore aussi les totaux client et calcule via `PricingService` : `app/Services/FrontendOrderService.php:195-227` (`UNVERIFIED`).
- La preview pricing borne refuse prix/totaux/branch côté client et résout la branche via `KioskMachine` : `app/Http/Requests/Kiosk/PricingPreviewRequest.php:15-20`, `app/Http/Controllers/Frontend/PricingPreviewController.php:31-56`.
- L’idempotence borne est branch-scoped avec lock cache : `app/Services/FrontendOrderService.php:126-149` (`UNVERIFIED`).
- La contrainte DB composite `branch_id + idempotency_key` existe : `database/migrations/2026_04_18_140003_scope_idempotency_key_to_branch.php:14-36`.
- Le test `tests/Feature/Orders/IdempotencyBranchScopedTest.php:20-71` couvre même clé sur branches différentes et rejet sur même branche.
- KDS a un test de filtre exact par branche contre l’ancien risque `LIKE`: `tests/Feature/KdsBranchFilterExactTest.php:16-57`.
- Le composant KDS écoute les events utiles, dont `OrderCreated`, `OrderStatusChanged`, `ItemAvailabilityChanged`, `OrderTableChanged` : `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:1135-1157` (`UNVERIFIED`).

## C) Ce qui me semble **fragile, faux ou dangereux** (P0 d’abord)
- **P0** — `payment-confirm` peut marquer une commande frontend possédée comme `PAID` avant de prouver qu’elle est bien une commande borne/TPE ; pourquoi : fraude ou état payé artificiel. Preuve : `routes/api.php:889-895`, `app/Http/Controllers/Frontend/OrderController.php:77-115`.
- **P0** — Si le TPE accepte mais que `payment-confirm` échoue après retries, le front borne continue vers l’écran d’attente ; pourquoi : paiement réel possible avec commande backend encore `PENDING/UNPAID`. Preuve : `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:448-459` et `:562-575` (`UNVERIFIED`).
- **P0** — KDS peut théoriquement envoyer des statuts terminaux comme `CANCELED` via la machine d’état ; pourquoi : Device Flow dit KDS limité à ACCEPT→PREPARING→PREPARED, sans annulation/reason flow. Preuve : `OrderStatusRequest.php:23-30`, `KitchenDisplaySystemOrderService.php:150-179`, `OrderStateMachine.php:37-49`.
- **P0** — Le verrou optimiste KDS est faible en HTTP : `expectedFrom` vient du modèle fraîchement bindé, pas d’un état client ; pourquoi : deux écrans peuvent écraser/sauter un conflit UI. Preuve : `KitchenDisplaySystemOrderService.php:120-148`, commentaire test `tests/Feature/KdsChangeStatusConcurrencyTest.php:21-26`.
- **P0** — Les transitions identité peuvent répéter des side-effects dans `OrderService::changeStatus` avant sauvegarde ; pourquoi : cashback/refund/audit peuvent se répéter sur retry terminal. Preuve : identité autorisée `OrderStateMachine.php:27-31`, side-effects `app/Services/OrderService.php:1548-1575` (`UNVERIFIED`).
- **P0** — La promo borne est appliquée en preview mais ne semble pas consommée à la création réelle ; pourquoi : total affiché ≠ total facturé serveur. Preuve : preview `PricingPreviewService.php:66-97`, payload `kioskCart.js:26-37`, absence règle `OrderRequest.php:35-68`, création `FrontendOrderService.php:216-227` (`UNVERIFIED`).
- **P1** — Le catch duplicate idempotency POS est non scopé par branche dans un chemin d’erreur ; pourquoi : admin branch 0 + collision concurrente peut retourner la mauvaise commande. Preuve : precheck scopé `OrderService.php:568-589`, catch non scopé `:1008-1018` (`UNVERIFIED`).
- **P1** — L’outbox marque `dispatched_at` avant broadcast ; pourquoi : crash process entre claim et broadcast peut laisser un event bloqué hors `pending()`. Preuve : `DispatchDomainEventsJob.php:65-86`, `DomainEvent.php:33-35`.
- **P1** — POS “collect cash” utilise l’endpoint KDS `admin/kds-order/change-status/{order}` pour `DELIVERED` ; pourquoi : permission et responsabilité métier confuses. Preuve : `PosComponent.vue:1414-1421`, `KitchenDisplaySystemController.php:18-23` (`UNVERIFIED`).
- **P1** — La preview pricing borne accepte une quantité de variation mais la conversion la perd ; pourquoi : preview fausse sur multi-quantités. Preuve : `PricingPreviewRequest.php:40-42`, `kioskPricingPreview.js:70-75`, `PricingPreviewService.php:146-155`.
- **P1** — `changeStatus` / `changePaymentStatus` n’ont pas le même garde sealed-Z que `destroy`; pourquoi : risque fiscal si NF525 V1 exige immutabilité après clôture. Preuve : `OrderService.php:1661-1714` vs `:1804-1823` (`UNVERIFIED`).
- **P1** — KDS peut dispatcher un `OrderStatusChanged` old==new ; pourquoi : bruit sync, notifications et outbox inutiles. Preuve : identité autorisée `OrderStateMachine.php:27-31`, dispatch KDS `KitchenDisplaySystemOrderService.php:173-179`.
- **P1** — Le filtre admin `branch_id` dans `OrderService::list` passe par une logique générique `LIKE`; pourquoi : vues admin/rapports peuvent mélanger branche 1 et 10. Preuve : `OrderService.php:61-72` et `:133-151`, doc lacune `docs/centralisation/ADMIN_CROSS_BRANCH_MAP_2026-04-20.md:55-57` (`UNVERIFIED`).
- **P1** — `OrderStatusRequest` autorise plusieurs rôles sur tout statut numérique ; pourquoi : les politiques par surface POS/KDS/borne ne sont pas assez exprimées dans la request. Preuve : `app/Http/Requests/OrderStatusRequest.php:23-49`.
- **P2** — Le contrat event frontend valide moins strictement que le backend ; pourquoi : broadcasts legacy/directs peuvent contourner `correlation_id`/`branch_id` et la déduplication. Preuve : `resources/js/services/eventContract.js:23-45` et `:252-265`.
- **P2** — KDS sync version repose surtout sur `updated_at` et contient un TODO `status_changed_at`; pourquoi : dette de versioning pour sync fine. Preuve : `app/Services/KdsSyncService.php:126-142`.
- **P2** — Store KDS accède à `payload.vuex` sans garde si payload absent ; pourquoi : crash mineur possible sur appel action mal formé. Preuve : `resources/js/store/modules/kitchenDisplaySystemOrder.js:20-29`.
- **P2** — Couverture E2E récente non vérifiée ici ; pourquoi : l’audit statique ne prouve pas POS cash/card + borne TPE + KDS temps réel bout-en-bout. Preuve : tests unitaires/feature vus, mais rapport Playwright courant non ouvert (`UNVERIFIED`).

## D) Matrice

| Sujet | État perçu | Risque | Action | Preuve requise |
|---|---|---|---|---|
| Paiement TPE borne | Fragile | P0 fraude / commande bloquée | Garde serveur avant mutation + erreur bloquante front | Test `payment-confirm` non-kiosk refusé + retry failure |
| KDS transitions | Trop permissif | P0 annulation sans flux métier | Whitelist KDS PREPARING/PREPARED + reason hors KDS | Feature test rôle Chef |
| Verrou optimiste KDS | Insuffisant | P0 conflit silencieux | Ajouter `expected_status` client requis | Test HTTP 409 sur état périmé |
| Idempotence POS | Bonne base, catch faible | P1 mauvaise commande retournée | Scoper catch duplicate par branche | Test admin multi-branch concurrent |
| Outbox events | Robuste mais stuck possible | P1 event perdu après crash | Ajouter claim state/timeout rescue | Test ou job de récupération |
| Pricing serveur | Globalement correct | P1 mismatch UX | Garder SSOT, corriger promo/variation preview | Tests preview vs création |
| Promo borne | Incohérente | P0/P1 total affiché ≠ facturé | Appliquer réellement ou retirer preview | Test checkout avec `kiosk_promo_code` |
| `branch_id` admin | Partiel | P1 vues croisées imprécises | Filtres exacts dans services | Test branch 1 vs 10 |
| `OrderTableChanged` | Bien outboxé | P2 intégration à surveiller | Garder event + refresh ciblé | Test KDS refresh table |
| Sealed-Z | Garde partielle | P1 fiscal | Étendre aux status/payment si requis V1 | Test mutation refusée après Z |

## E) Plan d’amélioration (ordre d’exécution)
1. Verrouiller `/frontend/order/{id}/payment-confirm` : exiger contexte borne/TPE, propriété machine/branche, méthode différée, et ne muter `PAID` qu’après validation.
2. Faire échouer le parcours borne si `confirmBackendPayment()` échoue après retries, avec état explicite “paiement accepté, confirmation serveur en attente/échec”.
3. Restreindre le service KDS à une whitelist métier (`PREPARING`, `PREPARED`, éventuellement `ACCEPT` si voulu) et interdire les statuts terminaux.
4. Ajouter `expected_status` obligatoire aux mutations KDS/POS sensibles et retourner 409 si l’état serveur diffère.
5. Ajouter garde no-op idempotente avant side-effects dans `OrderService::changeStatus` et chemins terminal/payment.
6. Décider le statut V1 de `kiosk_promo_code` : soit support complet à la création, soit retrait de la preview ; corriger aussi la quantité des variations.
7. Corriger le catch duplicate POS pour rechercher par `branch_id + idempotency_key`.
8. Ajouter une récupération outbox pour claims bloqués ou un état `claimed_at` séparé de `dispatched_at`.
9. Remplacer le changement POS cash via endpoint KDS par une route/service POS explicite.
10. Ajouter une suite V1 bout-en-bout minimale : POS cash, POS card, borne card confirm, KDS preparing/prepared, Echo/outbox, branch 1 vs 10.

## F) Questions pour un **relecteur** (le rôle Closer / Claude)
- Le endpoint `payment-confirm` doit-il être réservé exclusivement aux tokens borne/TPE, ou un client web connecté peut-il confirmer un paiement ?
- KDS a-t-il le droit métier d’annuler/rejeter une commande, ou seulement de passer en préparation/prêt ?
- Le V1 doit-il supporter `kiosk_promo_code` au checkout réel, ou seulement les coupons classiques ?
- Souhaite-t-on rendre `expected_status` obligatoire pour toutes les mutations de statut POS/KDS ?
- Après paiement TPE accepté mais confirmation backend échouée, quel état UX/opérationnel est attendu ?
- La contrainte sealed-Z doit-elle bloquer status/payment dès V1 ou seulement delete/export ?
- Les vues admin branch 0 doivent-elles rester globales par défaut, ou imposer une branche active pour POS/KDS ?

## G) Métacritique
Cette analyse est statique et partielle : plusieurs gros fichiers ont été lus par extraits, Graphiti n’était pas disponible, les seeders de permissions et la configuration runtime n’ont pas été exhaustivement vérifiés, et je n’ai pas lancé la suite complète ni un vrai parcours Playwright. Les risques P0 signalés reposent sur preuves de code précises, mais certains peuvent être atténués par middleware, politiques, config ou conventions d’exploitation non ouvertes ici ; ils doivent donc être confirmés par tests ciblés avant patch massif.
