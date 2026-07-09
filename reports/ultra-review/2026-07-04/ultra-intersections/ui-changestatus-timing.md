# Ultra-intersections — `changeStatus` × timing stamps (slug: ui-changestatus-timing)

HEAD 48050af80. Read-only audit. Adversaire + raisonnement.

## Fonction partagée / feature ciblée
Kitchen-timing analytics (commits `16f89b0b2` + `5389fc4a3`, migration `2026_07_03_000001`) :
`orders.accepted_at / preparing_at / prepared_at` → consommés par
`KDSOrderDetailsResource.php:51` :
```
'actual_prep_seconds' => ($this->accepted_at && $this->prepared_at)
    ? $this->prepared_at->diffInSeconds($this->accepted_at) : null
```

## Les 6 SEULS sites qui horodatent (grep exhaustif)
Tous dans `changeStatus`, jamais ailleurs :
- `OrderService.php:2214/2216/2218` (POS changeStatus, if/elseif keyed sur target)
- `KitchenDisplaySystemOrderService.php:459/461/463` (KDS changeStatus)

## Les sites qui transitionnent vers ACCEPT/PREPARING SANS horodater
Les 3 sites d'intégration de `AutoPrepareOnPaidPolicy` (doc du policy = « Three integration sites ») :
1. `OrderService::posOrderStore` — `Order::create([...'status' => $posInitialStatus])` (ligne ~757) crée d'emblée en PREPARING/ACCEPT. Aucun stamp.
2. `FrontendOrderService::finalizePaidKioskOrder` — `:1291` naissance ACCEPT, `:1325` promotion ACCEPT→PREPARING. Aucun stamp.
3. `PaymentService::confirmCounterPayment` — `:375` promotion ACCEPT→PREPARING (Plan B counter-collect carte/mobile). Aucun stamp (grep `accepted_at|preparing_at|prepared_at` sur PaymentService = 0 hit).

De plus, les commandes borne/kiosk naissent DIRECTEMENT en ACCEPT
(`FrontendOrderService.php:629-631` + `:1291`) — elles ne passent JAMAIS
par une transition PENDING→ACCEPT via `changeStatus`, donc `accepted_at`
n'est jamais posé. Aucun observer ne le pose (grep observers = 0).

## Faute de raisonnement (repro par trace de code)
Flux V1 réels dominants (Le Cayenne) :
- **Borne Plan B** : créée ACCEPT (accepted_at=NULL) → `confirmCounterPayment` ACCEPT→PREPARING (preparing_at=NULL) → chef bump KDS PREPARING→PREPARED (prepared_at posé, mais `newStatus===PREPARED` ⇒ le if/elseif ne touche PAS accepted_at). ⇒ `accepted_at` reste NULL.
- **POS direct** : `Order::create` d'emblée en PREPARING (accepted_at=NULL, preparing_at=NULL) → bump KDS →PREPARED (prepared_at). ⇒ accepted_at NULL.
- **Kiosk TPE** : idem via finalizePaidKioskOrder.

Résultat : `actual_prep_seconds = (NULL && prepared_at) → NULL` pour
**tout le volume auto-préparé** = quasi 100 % du trafic V1. La feature
« mesure temps réel prépa cuisine » est AVEUGLE pour exactement les
commandes qu'elle prétend mesurer. Elle ne produit une valeur que pour
la minorité de commandes acceptées manuellement PENDING→ACCEPT via
`changeStatus`.

Le test `tests/Feature/Kitchen/KitchenTimingTest.php:18-21` documente
UNIQUEMENT le sous-cas « POS direct » comme SUIVI, et affirme à tort que
« kiosk … + auto-accept PENDING→ACCEPT » est couvert — or le kiosk ne
passe jamais par PENDING→ACCEPT. Les chemins counter-collect
(`confirmCounterPayment`) et kiosk-TPE (`finalizePaidKioskOrder`) ne sont
ni couverts ni documentés. GREEN (POS+KDS changeStatus) ≠ correct.

## Réfutation de l'hypothèse littérale de la mission
`FrontendOrderService::changeStatus` (`:744`) n'autorise QUE la transition
CANCELED (self-cancel, `:757-759` throw 422 sinon) — il ne transitionne
jamais vers PREPARING/PREPARED, donc « timing oublié dans
FrontendOrderService::changeStatus » est FAUX. Le vrai oubli est dans les
chemins de **création / auto-prepare** (PaymentService + finalizePaidKioskOrder
+ posOrderStore), pas dans un `changeStatus`.

## DB read-only
2820 commandes, `accepted_at`/`preparing_at`/`prepared_at` = 0 posé
partout (feature datée 2026-07-03, données historiques antérieures ;
0 commande n'a été bumpée depuis le déploiement sur cette DB → repro
runtime complet exigerait une écriture, hors périmètre).

## Fix proposé
Poser `accepted_at` (+ `preparing_at` à la promotion) aux 3 sites
`AutoPrepareOnPaidPolicy`, OU calculer `actual_prep_seconds` depuis une
base toujours présente (`created_at`/`paid_at`) en fallback quand
`accepted_at` est NULL. Centraliser le stamp (helper Order ou dans le
caller de `AutoPrepareOnPaidPolicy::nextStatus`) pour que les N chemins
restent cohérents.
