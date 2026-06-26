# Round 2 — Re-attaque : Quote-omission, couverture complète du fix

**Verdict : HOLD** (la défense tient — prouvé reproductiblement contre `foodking_e2e`).
**Sévérité : NONE.** Aucun contournement, aucune couture d'intégration ratée.

Heals sondés (déjà committés) : `10e462149`. Code probé = état ACTUEL (avec fix).

---

## Cible du heal

`OrderQuoteService::quoteInsideTransaction()` appelle désormais
`assertVariationPresenceConstraints($items)` (app/Services/Order/OrderQuoteService.php:69),
qui rejoue `App\Rules\MultiVariationConstraint::validateCollectionKeyedByItemIndex`
— exactement la règle que le STORE utilise via le trait
`App\Http\Requests\Concerns\ValidatesOrderItemVariations`. But : parité quote↔store
sur les attributs REQUIS omis (le devis « mentait » en acceptant un Tacos sans viande/sauce).

---

## (a) Toutes les surfaces de devis couvertes ? — OUI

Il n'existe que **deux** routes POST `/quote`, toutes deux → `PosController::quote` :
- POS `routes/api.php:796` → `/api/admin/pos/quote`
- Kiosk `routes/api.php:1316` → `/api/frontend/order/quote`

`PosController::quote` (app/Http/Controllers/Admin/PosController.php:195) :
`$surface = $request->is('api/frontend/*') ? 'kiosk' : 'pos'`, puis
`orderQuoteService->quote($request, $surface)`. Les deux surfaces traversent
`quoteInsideTransaction:69` → guard. **Aucune surface de devis « web »** : les
commandes web/online n'ont PAS de devis, elles vont directement au STORE
(`Frontend/OrderController::store(OrderRequest)`), lui-même gardé (cf. (d)).

→ Couverture quote = complète (POS + kiosk).

## (b) MAX dépassé (2 viandes, attr max=1) passe au quote ? — NON (double garde)

Repro tinker (PricingRequest::forPos, orderId=0, `DB::rollBack()`, 0 écriture), item 26
« Tacos M » (req Viande 1 attr1 max=1 + Sauce attr5), deux viandes ACTIVES non-supprimées
(43 « Poulet mariné » + 352 « Mexicanos ») :

| Couche | Résultat |
|---|---|
| heal `MultiVariationConstraint` (quote L69) | REJET « Sélectionnez au maximum 1 Viande 1 (actuel : 2). » |
| `PricingService::assertVariationConstraints` (frozen, quote L71/164/427) | THROW 422 « Attribut Viande 1 : maximum 1 sélection(s), reçu 2. » |

Variantes aussi rejetées : même viande ×2 (no_repeat), `quantity:2` sur viande unique.
> Piège data noté : variations 44/45/46 sont SOFT-DELETED (`deleted_at` 2026-06-23) ;
> ItemVariation a SoftDeletes → les deux couches les ignorent (« introuvable »). Un test
> MAX valide DOIT utiliser deux variations actives non-supprimées du même attribut.

→ MAX rejeté sur les DEUX couches du quote.

## (c) Extra prix négatif forgé ? — NON (Pricing = SSOT, prix DB)

Repro tinker, item 26 + extra réel (247 « Oignons frits » DB=0,90 / 250 « Cheddar » DB=0,90) :

| Payload forgé | Total backend | Lecture |
|---|---|---|
| `item_extras:[{id:247, price:-999, quantity:1}]` | **7,80 €** (6,90 + 0,90) | prix payload IGNORÉ, prix DB utilisé |
| `item_extras:[{id:250, quantity:-5}]` | **7,80 €** | quantité clampée `max(1,-5)=1` |

`PricingService` (app/Services/Pricing/PricingService.php:189) :
`$extraTotal += (float) $dbExt->price * $extraQuantity` — `$dbExt->price` vient de la DB,
jamais du payload ; `$extraQuantity = max(1, ...)` interdit le négatif. Zéro ligne extra/var
à prix négatif en base (`SELECT ... WHERE price<0` = vide).

→ Forge de prix/quantité extra impossible à exploiter.

## (d) Le STORE a-t-il le même guard ? Contournement par bypass du quote ? — NON

Les **4** points d'entrée de création de commande type-hintent un FormRequest qui câble
le MÊME `MultiVariationConstraint` (trait `ValidatesOrderItemVariations`, via
`withValidator → $validator->after → validateOrderItemVariationsAfter`) :

| Surface | Route → Controller | FormRequest | Câblage |
|---|---|---|---|
| POS | api.php:798 `PosController::store` | `PosOrderRequest` | :157/248 |
| Web/online | `Frontend/OrderController::store` | `OrderRequest` | :188/234/259 |
| Kiosk | api.php:1317 `FrontendOrderController::store` | `OrderRequest` | idem |
| Dine-in QR | api.php:1522 `Table/OrderController::store` | `TableOrderRequest` | :54/65 |

`OrderRequest::withValidator` : chaque branche non-erreur appelle
`validateOrderItemVariationsAfter` (kiosk L234, web/delivery L259 ; les early-returns
L202/206/231 ont déjà ajouté une erreur dure → commande rejetée). Aucun chemin store
n'échappe au guard. Les seuls Requests `items` sans trait sont `ItemRequest` /
`OfferItemRequest` = CRUD catalogue/offres, PAS de création de commande.

Garde secondaire : POS + kiosk re-scellent via `sealForCommit` (OrderService.php:1059,
FrontendOrderService.php:516) → re-`quote()` → guard L69. Et la validation FormRequest
s'exécute AVANT le contrôleur, inconditionnellement. Ordre prouvé par Read :
guard L69 < pricing L71 < résolution token L79 < `OrderQuote::create` L85 → le rejet
précède toute écriture, y compris au scellage d'un replay.

→ Bypasser le devis ne contourne PAS le STORE : il revalide tout seul.

---

## Preuves de régression
- `tests/Feature/Pos/PosQuoteVariationConstraintTest.php` → **3/3** (pos omis rejeté, pos présent accepté, kiosk omis rejeté).
- `tests/Feature/MultiVariationValidationTest.php` → **12/12** (omis total / 1-sur-2 omis / tous présents).

## Note (non-finding, parité préservée)
Le check « attribut requis omis » dérive les attributs requis des `item_variations`
ACTIVES legacy (MultiVariationConstraint.php:131, `status=ACTIVE`). Les sélections
requises purement composer-profile ne sont donc pas contraintes en présence — mais ce
trou est IDENTIQUE sur quote et store (parité = but du heal, intacte), et il est sans
objet pour le menu V1 réel : les bols publiés 41/45 portent des variations legacy ACTIVES
pour leurs attributs requis (Viande 1 attr1 min=1 ×7, Sauce bol attr8 min=1 ×2) → gardés
des deux côtés. Le wizard défaut toujours une valeur par attribut requis.

## Conclusion
Fix complet et structurellement miroir (quote et store partagent la même règle exacte).
(a) couvert, (b) double-rejet, (c) SSOT, (d) store auto-gardé. **HOLD / NONE.**
