# Vérification adversaire — CAISSE / Prise commande / wizard

## FINDING examinée
[P3] `tests/Feature/Pos/PosQuoteVariationConstraintTest.php` (absent) — test d'acceptance
planifié (Sub 1.a) ABSENT → couverture contraintes viande sur le endpoint `quote` manquante.
RECO d'origine : créer le test (omis-viande → 422, excès-viande → 422 sur `/api/admin/pos/quote`)
en miroir du `store`, comme garde anti-régression de l'asymétrie quote/store.

## VERDICT : CONFIRMÉE (partiellement) — **P3**
La finding est **réelle mais plus étroite que formulée**. Le test est bien absent, et il
existe une **asymétrie quote/store réelle**, MAIS uniquement sur le vecteur
**attribut requis WHOLLY OMITTED**, pas sur l'excès (déjà couvert sur quote). Impact
V1-LOCAL = **cosmétique** (aucun argent / fiscal / perte-commande / fuite). D'où P3.

---

## REPRO

### 1. Fichier absent (claim de surface = vrai)
```
$ ls tests/Feature/Pos/PosQuoteVariationConstraintTest.php
=> No such file or directory
$ find tests -iname '*QuoteVariation*' -o -iname '*VariationConstraint*'
=> (vide)
```
Plan `plans/goal-test-e2e-all-systems-2026-06-26/01_SYSTEM_CAISSE.md` Sub 1.a Acceptance :
« *(À CRÉER `tests/Feature/Pos/PosQuoteVariationConstraintTest.php`)* » — non créé. Confirmé.

### 2. Asymétrie de couche — vérifiée par lecture
- `store` : `PosController::store(PosOrderRequest $request)` (`app/Http/Controllers/Admin/PosController.php:54`).
  `PosOrderRequest` (`app/Http/Requests/PosOrderRequest.php:19-20`) `use ValidatesOrderItemVariations`
  → `MultiVariationConstraint::validateCollectionKeyedByItemIndex`
  (`app/Http/Requests/Concerns/ValidatesOrderItemVariations.php:30`).
- `quote` : `PosController::quote(Request $request)` (`:164`) — **plain `Request`**, validation
  inline `$request->validate([...])` (`:176`) qui **n'inclut PAS** `MultiVariationConstraint`.
  Délègue à `OrderQuoteService::quote` → `PricingService::calculateOrder`.

### 3. Différence structurelle — laquelle exactement
- `PricingService::assertVariationConstraints` (`app/Services/Pricing/PricingService.php:383`,
  appelé `:164`) : boucle `foreach ($byAttribute ...)` construit UNIQUEMENT à partir des
  variations **présentes** dans le payload. Un attribut requis **totalement absent** n'entre
  jamais dans `$byAttribute` → **invisible** à PricingService. Il enforce MAX (`:426`),
  MIN-si-présent (`:432`), allow_repeat (`:440`).
- `MultiVariationConstraint` (store-only) a en plus `requiredAttributesByOrderedItem`
  (`app/Rules/MultiVariationConstraint.php:50-59,115`) qui détecte l'attribut requis
  **wholly-omitted** — ce que PricingService ne peut pas voir.

### 4. Preuve d'exécution (READ-ONLY, aucune écriture)
Données live `foodking_e2e` : item 22 « Cayenne », attributs requis (status=5 ACTIF) :
attr 5 « Sauce (1ère Gratuite) » min1/max1 (var 281), attr 6 « Type de Pain » min1/max1 (var 450).
Harness = appel direct `PricingService::calculateOrder` avec `orderId=0` (chemin preview,
explicitement lecture-seule `PricingService.php:51`) + `MultiVariationConstraint` ; `order_quotes`
count inchangé **3179 → 3179**, aucun ordre placé.

| Vecteur | STORE (`MultiVariationConstraint`) | Moteur partagé `PricingService::calculateOrder` (utilisé par quote ET store) |
|---|---|---|
| A) Pain (requis) **omis** | **REJETÉ** « Sélectionnez au moins 1 Type de Pain (actuel : 0). » | **ACCEPTÉ total=7,40** ← n'attrape PAS l'omission |
| B) **Excès** 2 sauces (max=1) | REJETÉ | **REJETÉ 422** « Attribut Sauce (1ère Gratuite) : maximum 1 sélection(s), reçu 2. » |
| C) Valide (281+450) | (accepté) | ACCEPTÉ total=7,40 |

→ **Vecteur B (excès) DÉJÀ enforced sur quote** (par PricingService partagé). La moitié
« excès-viande → 422 manquant sur quote » de la finding est un **faux postulat**.
→ **Vecteur A (omission requise) = la SEULE vraie asymétrie** : store rejette, quote accepte.

### 5. Innocuité (pourquoi P3 et pas plus)
- L'unique entrée de création de commande POS est `OrderService::posOrderStore(PosOrderRequest $request)`
  (`app/Services/OrderService.php:657`), **typée** sur `PosOrderRequest` → la validation
  `MultiVariationConstraint` tourne AVANT le corps. Aucun chemin de création n'accepte un
  quote_token en contournant ce FormRequest (vérifié `grep` posOrderStore/quote).
- `quote` est un **aperçu non-fiscal** : crée une ligne `order_quotes` advisory, **place 0 ordre,
  alloue 0 séquence fiscale, encaisse 0 €**. Une commande à attribut requis omis est **rejetée
  au commit (`store` → 422, vecteur A prouvé)**.
- Conséquence réelle = l'aperçu POS pourrait afficher un total pour une composition que le
  système refusera à la validation → **incohérence aperçu-vs-commit cosmétique**. Pas de fuite
  argent / fiscal / perte-commande / données. **P3**.

## LENTILLE
🧑‍💼 commerçant — micro-incohérence UX (aperçu ment légèrement) ; surtout **dette de
couverture de test** (la garde d'asymétrie n'est pinglée par aucun test : les 4 tests
existants `QuoteBindingTest|PosOrderRequestNoClientTotalsTest|FritesWizardComposerTest|PosMenuRuntimeAccessTest`
ne couvrent pas ce vecteur).

## RECO (NON-frozen) — racine NON frozen, heal sûr
1. **Heal (optionnel, qualité aperçu)** : faire passer le payload du `quote` par la même garde
   wholly-omitted. Option propre **non-frozen** : ajouter dans `PosController::quote` (`:176`) un
   `after`-validation réutilisant `MultiVariationConstraint::validateCollectionKeyedByItemIndex`
   (déjà extrait dans le trait `ValidatesOrderItemVariations`), OU centraliser la vérification
   d'omission dans `OrderQuoteService::quote` (`app/Services/Order/OrderQuoteService.php`) avant
   le pricing. **Ne PAS toucher `PricingService` (frozen)** : la garde d'omission vit en couche
   requête/service. Bénéfice = aperçu et commit cohérents (l'aperçu rejette ce que le commit
   rejettera).
2. **Test TDD (le cœur de la finding)** : créer `tests/Feature/Pos/PosQuoteVariationConstraintTest.php`
   — vecteurs sur `/api/admin/pos/quote` (auth `permission:pos`) :
   - **A) omission requise** → 422 (anti-régression de l'asymétrie ; rouge AVANT heal #1, vert après).
   - **B) excès (max=1, 2 sélections)** → 422 (garde de régression du comportement DÉJÀ correct
     via PricingService — verrouille qu'il ne casse pas).
   - **C) valide** → 200 (contrôle).
   Cibles toutes NON-frozen.

**Note priorité** : ceci est une dette de test + polish UX, pas un défaut bloquant V1. Le store
(argent/fiscal) est déjà étanche. À traiter dans un cycle TDD calme, pas en urgence.

## EVIDENCE FILES (file:line vérifiés)
- `app/Http/Controllers/Admin/PosController.php:54` (store/PosOrderRequest), `:164,:176` (quote/Request inline)
- `app/Http/Requests/PosOrderRequest.php:19-20` (use ValidatesOrderItemVariations)
- `app/Http/Requests/Concerns/ValidatesOrderItemVariations.php:30` (MultiVariationConstraint call)
- `app/Rules/MultiVariationConstraint.php:50-59,115` (wholly-omitted required logic)
- `app/Services/Pricing/PricingService.php:164,383,426,432,440` (assertVariationConstraints — present-only)
- `app/Services/Order/OrderQuoteService.php:56,64-69,156` (quote → calculatePricing → PricingService)
- `app/Services/OrderService.php:657` (posOrderStore typed PosOrderRequest — seule entrée création)
- DB `foodking_e2e` : item 22, attr 5/6 min1 max1, vars 281/450 (status=5)
