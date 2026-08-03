# CAISSE r1 — Lentille LOGIQUE / DATA / NF525 (Sub 1.a prise commande / wizard)

Cible : `quote` (`PosController::164`) + `store`→`PricingService::calculateOrder` ; `MultiVariationConstraint` ;
bridge composer-aware. DB live `foodking_e2e` (default `.env`=`foodking`, coquille schéma-drift → override `DB_DATABASE=foodking_e2e` pour toute preuve tinker, preview orderId=0 = ZÉRO écriture/ordre).

---

## FINDINGS PROUVÉES

### [P2] app/Services/Pricing/PricingService.php:383-449 — Le devis (`quote`) accepte un attribut REQUIS entièrement OMIS que `store` rejette en 422 (quote≠store, aperçu qui ment)

**repro** (déterministe, read-only, aucun ordre placé — preview orderId=0) :
```
cd .../testttt && DB_DATABASE=foodking_e2e php artisan tinker <<'PHP'
$itemsArr = [[ 'item_id'=>26,'quantity'=>1,'item_variations'=>[],'item_extras'=>[] ]]; // Tacos M, Viande 1 + Sauce REQUIS (min_select=1) OMIS
$obj = json_decode(json_encode($itemsArr));
// PATH A — pricer du devis
try { $r=app(\App\Services\Pricing\PricingService::class)->calculateOrder(\App\Services\Pricing\PricingRequest::forPos(0,1,$obj,0,0,0.0,0.0), app(\App\Services\CouponService::class)); echo "A ACCEPTED subtotal=".$r->subtotal."\n"; } catch(\Throwable $e){ echo "A REJECTED ".$e->getMessage()."\n"; }
// PATH B — validateur du store (PosOrderRequest::after)
$err=[]; \App\Rules\MultiVariationConstraint::validateCollectionKeyedByItemIndex($itemsArr, function($i,$m) use(&$err){ $err[]=$m; });
echo ($err===[] ? "B ACCEPTED\n" : "B REJECTED ".implode(' | ',$err)."\n");
PHP
```

**evidence** (sortie réelle live) :
- `A ACCEPTED subtotal=6.9` → `/api/admin/pos/quote` (PosController::164 → OrderQuoteService::69 → PricingService::36) rend un devis 200 à **6,90 €** pour un Tacos M SANS viande ni sauce.
- `B REJECTED Sélectionnez au moins 1 Viande 1 (actuel : 0) | Sélectionnez au moins 1 Sauce (1ère Gratuite) (actuel : 0)` → `store` (PosOrderRequest::248 `validateOrderItemVariationsAfter` → MultiVariationConstraint) **422** sur le MÊME payload.
- Test store-side vert : `vendor/bin/phpunit --filter MultiVariationValidationTest` → `OK (12 tests)`.

**Racine** : `PricingService::assertVariationConstraints()` ne valide QUE les attributs PRÉSENTS dans le payload :
- l.408-410 `if ($byAttribute === []) { return; }` → si aucune variation envoyée, sortie immédiate, aucune contrainte min vérifiée.
- Il n'existe AUCUN équivalent du heal `MultiVariationConstraint::requiredAttributesByOrderedItem()` (committé `9b8398d2f`) qui, lui, dérive les attributs `min_select>=1` depuis les variations ACTIVE de l'item et rejette l'omission totale. Ce heal ne tourne qu'au `store` (couche FormRequest `PosOrderRequest`), JAMAIS au `quote` (qui utilise `Request` nu, pas `PosOrderRequest`, et `OrderQuoteService::quote` n'appelle pas le validateur).

**Périmètre exact** : asymétrie limitée à l'**OMISSION TOTALE** d'un attribut requis en **chemin legacy-variation** (item sans profil composer publié). Vérifié :
- MAX/MIN d'un attribut **présent** = enforced AUSSI au quote (l.422-449). Preuve : 2× Viande 1 (max=1) → `A REJECTED Attribut Viande 1 : maximum 1 sélection(s), reçu 2.`
- Items à **profil composer publié** (ex. bols) → l'omission EST attrapée au quote par `assertComposerStepConstraints` (l.110, boucle sur `$projected['steps']`, `if ($total < $min) throw 422` même à 0). Donc seuls les items legacy sont exposés.
- Blast radius live = **14 produits actifs** avec attribut requis SANS profil publié (donc exposés) :
```
mysql -u root foodking_e2e -e "SELECT DISTINCT i.id,i.name FROM items i JOIN item_variations iv ON iv.item_id=i.id AND iv.status=5 AND iv.deleted_at IS NULL JOIN item_attributes ia ON ia.id=iv.item_attribute_id AND ia.min_select>=1 AND ia.status=5 LEFT JOIN item_wizard_profiles wp ON wp.item_id=i.id AND wp.is_published=1 WHERE i.status=5 AND i.deleted_at IS NULL AND wp.id IS NULL ORDER BY i.id;"
```
→ 22 Cayenne, 23 Galette Normale, 24 Galette Cayenne, 26 Tacos M, 38 Chicken Burger, 97 Tacos L, 98 Cheese, 99 Double Cheese, 100 Fish, 101 Big, 102 Grill, 103 Suprême, 104 Méga, 105 Terminator.

**lentille** : commerçant + technique. PAS NF525/argent : le `store` gagne (aucun ordre sous-facturé n'est créé, séquence fiscale intacte). C'est un défaut de **cohérence logique / UX** : le devis affiche un prix « achetable » (6,90 €) pour une compo que le système refusera ensuite en 422 opaque au moment d'encaisser — exactement la classe « l'aperçu ment » (le ticket/preview ne reflète pas le verdict réel). Le caissier pressé voit un total valide, clique encaisser, mange un 422 sans cause claire.

**reco** (NON-frozen — `PricingService.php` est frozen backend → **ESCALADE / gate** OU fix hors-frozen) :
- Option propre hors-frozen : faire tourner `MultiVariationConstraint::validateCollectionKeyedByItemIndex` AUSSI dans `OrderQuoteService::quoteInsideTransaction` (avant `calculatePricing`) pour les surfaces pos/kiosk — aligne le devis sur le store sans toucher le service frozen. (Le kiosk a la même asymétrie : la borne quote via le même pricer.)
- Sinon, si on touche `PricingService` (frozen) : ajouter le miroir de `requiredAttributesByOrderedItem` dans `assertVariationConstraints` → **LOCK + contre-signature owner**.
- Créer le test manquant `tests/Feature/Pos/PosQuoteVariationConstraintTest.php` (plan le note « À CRÉER », confirmé absent) : quote omis-requis → 422, pour figer la parité quote/store.

---

## HOLDS (défenses vérifiées — PAS des findings, anti-faux-positif)

- **Forge `total_price`/`price` hors profil** → SSOT gagne. Preuve : payload `total_price=999.99,price=999.99` (Tacos M valide) → `A ACCEPTED subtotal=6.9`. Le pricer ne lit que `item_id`/`item_variations[].id`/qty depuis la DB (PricingService:57-159 ; OrderService:864-994 legacy idem). HOLD.
- **Excès MAX (2 viandes, max=1)** → `422 Attribut Viande 1 : maximum 1 sélection(s), reçu 2` aux DEUX chemins. HOLD.
- **Cross-item injection (variation/extra d'un autre item)** → gardé : `PricingService.php:152-157` (`enforceCrossItemGuards`) + `OrderService.php:918/946`. HOLD.
- **Item INACTIF status=10 ajoutable** → `AvailabilityService::assertItemsOrderableForBranch:238` `if ((int)$item->status !== Status::ACTIVE) throw 422`, exécuté DANS `calculateOrder` (donc quote ET store, même en preview). Preuve : item 36 (status=10) → `INACTIVE-ITEM(quote pricer): REJECTED 422 Article 36 inactif dans le catalogue.` HOLD.
- **option_ids forgé (id de variation inexistant)** → `PricingService.php:146-150` `throw 422 Variation ID X introuvable`. HOLD.
- **quote≠store via intent-hash** → `OrderQuoteService::canonicalPayload:392-438` inclut `modifiers` (variations/extras/addons) + `totals` ; `sealForCommit:120-122` re-price au store et **409** si `|total_quote - total_store| > 1e-6` ; `consume:373-385` empêche le double-consume (409). Le store re-price TOUJOURS via `PricingService::calculateOrder` (OrderService:819) puis alloue le fiscal (OrderService:1117) APRÈS le seal. SSOT robuste. HOLD.
- **Snapshot** : `composition_snapshot` construit côté backend depuis le résultat pricing (OrderService:990 / CompositionSnapshotBuilder), `json_encode` figé à l'insert dans la même transaction que l'alloc fiscale. HOLD.

## NOTE INFRA (hors-scope finding mais à signaler au superviseur)
Le `.env` par défaut pointe `DB_DATABASE=foodking` = base orpheline schéma-drift (65 items, PAS de colonne `items.is_available` → `calculateOrder` y crashe `Column not found: is_available`). La base canonique LIVE = `foodking_e2e` (87 items, schéma complet). Toute commande tinker/serve doit override `DB_DATABASE=foodking_e2e`. Non-finding produit, mais piège de repro à documenter.
