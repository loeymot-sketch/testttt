# Vérification adversaire — CAISSE / Prise commande wizard

## Finding sous revue
[P3] app/Services/Order/OrderQuoteService.php:206 — quote≠store : un attribut REQUIS entièrement
omis est accepté par le devis (PricingService) mais rejeté à la commande (MultiVariationConstraint).

## Verdict : **CONFIRMÉ — P3 (réel, reproductible, NON-frozen, AUCUN impact money/fiscal)**

Tentative de réfutation infructueuse. L'asymétrie devis/commande est réelle, mais elle est
strictement une friction UX du caissier : le store reste le gate dur (422 avant tout pricing/fiscal),
donc zéro perte de commande, zéro fuite, zéro impact NF525.

## Repro live (foodking_e2e)

### (A) DEVIS — PricingService::calculateOrder (chemin OrderQuoteService::calculatePricing:224)
Payload : Tacos L (#97) avec item_variations=[{id:361 = "Mexicanos", attr Viande1}], en OMETTANT
les attributs requis Viande2 (attr#2, min_select=1) ET Sauce (attr#5, min_select=1).
```
DB_DATABASE=foodking_e2e php artisan tinker
PricingRequest::forPos(0,1,[$item],0,1,0,0) -> calculateOrder
=> QUOTE-ENGINE result: [OK] subtotal=7.90 total=7.90
```
Aucun 422. Le devis présente un prix valide 7,90 €.

### (B) COMMANDE — MultiVariationConstraint (branché PosOrderRequest::withValidator:248 = store)
Même payload :
```
MultiVariationConstraint::validateCollectionKeyedByItemIndex([["item_id"=>97,"item_variations"=>[["id"=>361]]]], $fail)
=> STORE-RULE result: [422] [0] Sélectionnez au moins 1 Viande 2 (actuel : 0). | [0] Sélectionnez au moins 1 Sauce (1ère Gratuite) (actuel : 0).
```
Rejet 422. La commande échoue au confirm.

### Données DB confirmées (foodking_e2e)
- item #97 "Tacos L" : price=7.90, status=5 (ACTIVE), deleted_at=NULL.
- attr#1 "Viande 1" min=1 max=1 ; attr#2 "Viande 2" min=1 max=1 allow_repeat=0 ; attr#5 "Sauce" min=1.
- variation #361 "Mexicanos" -> item_id=97, item_attribute_id=1, status=5.
- Status::ACTIVE = 5 (app/Enums/Status.php:7) -> les variations status=5 sont bien vues par
  requiredAttributesByOrderedItem() (filtre ->where('status', Status::ACTIVE)).

## Cause racine vérifiée (file:line par Read/grep)
- `PricingService::assertVariationConstraints` (PricingService.php:383-410) construit `$byAttribute`
  uniquement à partir des variations PRÉSENTES au payload, puis `if ($byAttribute === []) return;` (l.408-410).
  Un attribut requis ENTIÈREMENT omis n'apparaît jamais dans `$byAttribute` -> jamais détecté. (claim CONFIRMÉE)
- Le check d'omission-totale vit dans `MultiVariationConstraint::requiredAttributesByOrderedItem`
  (MultiVariationConstraint.php:59-106) + `presentAttributeIds` ; il est appelé via la trait
  `ValidatesOrderItemVariations::validateOrderItemVariationsAfter`.
- Trait câblée dans 4 FormRequests : PosOrderRequest (store), OrderRequest, TableOrderRequest,
  Kiosk/PricingPreviewRequest (preview kiosk). **Le chemin DEVIS POS (`PosController::quote` ->
  `OrderQuoteService`) n'utilise AUCUN de ces FormRequest** : `PosController::quote` fait un
  `$request->validate([...])` inline (PosController.php:176-191) qui ne contient PAS le check, et
  `grep MultiVariation|validateCollection|assertVariation OrderQuoteService.php` => 0 hit.

## Correction d'evidence du finding
- Le finding annonce "branché UNIQUEMENT dans PosOrderRequest" : imprécis (aussi OrderRequest,
  TableOrderRequest, PricingPreviewRequest), mais la thèse substantielle tient : le **devis POS** ne
  l'applique pas.
- Le finding annonce "PHPUnit existant 18/18" : le compte réel est **12/12** (MultiVariationValidationTest,
  vérifié `php artisan test --filter=MultiVariationValidationTest` => 12 passed). Tous ces tests visent
  l'endpoint kiosk `pricing/preview` (qui applique bien la contrainte), AUCUN ne couvre le **devis POS**
  `/api/admin/pos/quote`. La thèse "aucun ne couvre ce cas côté quote" reste correcte.

## Pourquoi P3 et pas plus (sévérité V1-LOCAL)
- Le store applique INCONDITIONNELLEMENT `validateOrderItemVariationsAfter` (PosOrderRequest.php:248),
  indépendamment d'un quote_token. Un devis incomplet ne peut JAMAIS produire une commande passante :
  le FormRequest renvoie 422 AVANT tout pricing/allocation fiscale. -> 0 money, 0 fiscal, 0 perte de
  commande, 0 fuite. Friction UX pure (prix valide affiché, puis 422 au confirm).
- En pratique le wizard frozen (pos-wizard.js) défaute toujours une valeur par attribut requis, donc
  l'omission n'est atteignable que par payload forgé / désync UI — d'où l'impact faible.

## Reco (NON-frozen) — TDD
Router le devis par la même contrainte que le store. Cible NON-frozen : `OrderQuoteService::calculatePricing`
(OrderQuoteService.php:206) ou un hook post-validation dans `quote()`, appelant
`MultiVariationConstraint::validateCollectionKeyedByItemIndex($items, ...)` et levant un
ValidationException 422 si erreur (avant d'appeler PricingService), pour un aperçu cohérent.
Test : ajouter à MultiVariationValidationTest un cas POST `/api/admin/pos/quote` (auth perm:pos)
Tacos L avec Viande1 seule -> 422 ; régression : devis Tacos L complet -> 200 + total 7,90.
Aucun fichier frozen touché. PricingService/PaymentComponent/pos-wizard.js INTOUCHÉS.
