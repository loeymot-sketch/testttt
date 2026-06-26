# RED-Verifier — CAISSE r1 — finding « quote accepte attribut REQUIS omis (quote≠store) »

Rôle : vérificateur adversaire (défaut = REFUTED). Verdict : **CONFIRMÉ — P2** (NON réfutée, mais correctement P2, pas P1).

---

## [P2] app/Services/Pricing/PricingService.php:383-449 (early-return :408-410) — quote POS/kiosk accepte un attribut REQUIS entièrement omis que le store rejette en 422

### repro (READ-ONLY, 0 ordre, orderId=0)
```
DB_DATABASE=foodking_e2e php artisan tinker
$itemsArr=[['item_id'=>26,'quantity'=>1,'item_variations'=>[],'item_extras'=>[]]]; // Tacos M, Viande1 + Sauce (min_select=1) OMIS
$obj=json_decode(json_encode($itemsArr));
$r=app(PricingService::class)->calculateOrder(PricingRequest::forPos(0,1,$obj,0,0,0.0,0.0),app(CouponService::class)); // A
MultiVariationConstraint::validateCollectionKeyedByItemIndex($itemsArr,fn($i,$m)=>$err[]=$m);                          // B
```

### evidence (live foodking_e2e, reproduit ce jour)
- **A ACCEPTED subtotal=6.9** — le pricer rend un devis 200 à 6,90 € pour Tacos M SANS viande/sauce.
- **B REJECTED** « Sélectionnez au moins 1 Viande 1 (actuel : 0). | Sélectionnez au moins 1 Sauce (1ère Gratuite) (actuel : 0). » — la règle store rejette le MÊME payload.
- Item 26 = Tacos M, `status=5` (= ACTIF, 48 items à status 5), `deleted_at=NULL`. Attributs requis vérifiés en DB :
  `item_variations`→`item_attributes` : attr 1 « Viande 1 » min_select=1, attr 5 « Sauce (1ère Gratuite) » min_select=1.
- Store-side vert : `vendor/bin/phpunit --filter MultiVariationValidationTest` → **OK (12 tests, 32 assertions)**.

### racine vérifiée (le file:line cité est exact)
- Les DEUX endpoints quote (`api/admin/pos/quote` ET `api/frontend/order/quote`, `php artisan route:list`) tapent `Admin\PosController@quote` → `Request` simple validé par `ValidJsonOrder` UNIQUEMENT (PosController.php:186-191). **Aucun** `MultiVariationConstraint` sur le chemin quote.
- `OrderQuoteService::quoteInsideTransaction` (app/Services/Order/OrderQuoteService.php:61-107, chemin réel — le file cité `app/Services/Pos/OrderQuoteService.php` n'existe pas, le vrai est `app/Services/Order/`) → `calculatePricing` (:206) → `PricingService::calculateOrder`. Jamais la règle.
- Dans `calculateOrder` : `assertComposerStepConstraints` (:110) attrape les items à **profil composer publié** (bols) même au quote → protégés (conforme au finding). Mais pour les items legacy-variation, seul `assertVariationConstraints` (:164→:383) tourne, et son **early-return :408-410** (`if ($byAttribute === []) return;`) laisse passer un attribut entièrement omis : il n'inspecte QUE les attributs présents. La logique de présence-requise n'existe QUE dans `MultiVariationConstraint::requiredAttributesByOrderedItem` (HEAL 9b8398d2f), côté FormRequest, jamais appelée au quote.

### blast radius (SQL corrigé — item_wizard_profiles n'a PAS de colonne status/deleted_at, a `is_published`)
```sql
SELECT DISTINCT i.id,i.name FROM items i
JOIN item_variations iv ON iv.item_id=i.id AND iv.deleted_at IS NULL
JOIN item_attributes ia ON ia.id=iv.item_attribute_id AND ia.min_select>=1
LEFT JOIN item_wizard_profiles wp ON wp.item_id=i.id
WHERE i.status=5 AND i.deleted_at IS NULL AND wp.id IS NULL;
```
→ **14 items confirmés** : 22 Cayenne, 23 Galette Normale, 24 Galette Cayenne, 26 Tacos M, 38 Chicken Burger, 97 Tacos L, 98 Cheese Burger, 99 Double Cheese, 100 Fish Burger, 101 Big Burger, 102 Grill Burger, 103 Suprême, 104 Méga, 105 Terminator. (Conforme au finding.)

### tentatives de réfutation (toutes échouées → finding tient)
1. « La garde existe au quote » → NON : grep prouve aucune invocation de la règle/présence-requise sur le chemin quote (PosController:186 + OrderQuoteService entier).
2. « Le store ne rejette pas vraiment » → NON : MultiVariationValidationTest 12/12, + B REJECTED live.
3. « Kiosk est protégé (PricingPreviewRequest utilise le trait) » → PARTIEL mais NON disculpant : `PricingPreviewRequest` (avec `validateOrderItemVariationsAfter`) est un endpoint kiosk distinct ; le **quote** kiosk réel passe par `api/frontend/order/quote` → `PosController@quote` (Request nu). Donc le quote kiosk porte la MÊME asymétrie (finding correct).

### nuisance réelle V1-LOCAL (pourquoi P2, pas P1)
- **Le store gagne TOUJOURS** : aucun ordre n'est sous-facturé, aucune séquence fiscale consommée, aucune écriture audit_logs. Pas NF525, pas perte d'argent, pas perte de commande.
- Exposition pratique réduite : le wizard frozen a un gate client `canProceedFromStep` (public/js/pos-wizard.js:5147+) qui bloque « proceed » sans le nombre requis de viandes/sauces → le parcours UI normal n'atteint pas l'état omis ; l'asymétrie est surtout atteignable par un appel quote forgé/anormal, que le store rejette ensuite.
- Dommage résiduel = cohérence/UX : le devis 200 ment (prix qui sera refusé au commit) ; un client d'API tierce (future intégration) verrait un devis valide puis un 422.

### lentille
technique + commerçant (confiance dans le devis ; un aperçu qui ment vs ce que le store acceptera).

### reco (racine FROZEN → heal NON-frozen)
- `PricingService` est FROZEN backend → NE PAS toucher.
- **Heal NON-frozen** : appeler `MultiVariationConstraint::validateCollectionKeyedByItemIndex($items, …)` dans `OrderQuoteService::quoteInsideTransaction` (app/Services/Order/OrderQuoteService.php) AVANT/au lieu de continuer après `calculatePricing`, pour les surfaces pos ET kiosk → aligne le devis sur le store sans toucher le service frozen ni le wizard frozen. Lever une `ValidationException` (le contrôleur la propage déjà en 422, PosController:201-202).
- **TDD** : créer `tests/Feature/Pos/PosQuoteVariationConstraintTest.php` (absent — confirmé via grep ; le plan le note « À CRÉER ») qui fige la parité quote/store : POST `api/admin/pos/quote` Tacos M sans viande/sauce → 422 ; avec viande+sauce → 200 ; + jumeau kiosk `api/frontend/order/quote`.
- Alternative (déconseillée) : mirror de `requiredAttributesByOrderedItem` dans `PricingService::assertVariationConstraints` → exige LOCK + gate owner (frozen). Préférer la voie OrderQuoteService.

### severity
**P2** confirmé (cohérence logique/UX ; PAS NF525/argent — le store protège). `heal_safe_nonfrozen = true` (la voie OrderQuoteService est hors-frozen). Racine dans frozen → ne PAS healer dans PricingService.
