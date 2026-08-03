# CAISSE r1 — Lentille ADVERSAIRE-RED — Sub 1.a « Prise commande / wizard »

Auditeur: RED-adversaire (caissier qui se trompe/fraude + client qui abuse).
DB live: `foodking_e2e` (Status::ACTIVE=5, INACTIVE=10 ; 62 actifs / 25 inactifs).
Méthode: lecture code ancré + vecteurs réels via `PricingService::calculateOrder`
en mode preview (`orderId=0` = AUCUNE écriture, read-only strict) + `MultiVariationConstraint`
direct + données d'ordres réels en DB + PHPUnit existant (base test isolée).

> ⚠️ Piège env: `php artisan tinker` se branche sur `.env DB_DATABASE=foodking`
> (coquille vide, pas de col `is_available`). Toujours préfixer
> `DB_DATABASE=foodking_e2e` pour viser la base que le serveur :8766 utilise.

---

## VERDICT GLOBAL: cœur SSOT SOLIDE. 0 P0 / 0 P1. Le fantôme-upcharge documenté est RÉFUTÉ sur le menu live.

Le backend gagne sur TOUS les vecteurs de forge testés. Aucune perte d'argent,
aucune fuite, aucune brèche NF525 reproductible côté prise de commande.

---

## VECTEURS PROUVÉS — backend SSOT TIENT (read-only, foodking_e2e)

| # | Vecteur (caissier/client) | Résultat backend | Preuve |
|---|---|---|---|
| V1 | forge `total_price=0.01`+`price=0.01` Tacos M | total=**6,90** (= contrôle) | client price IGNORÉ |
| V2 | contrôle Tacos M (viande+sauce) | total=6,90 sub=6,90 tax=0,63 (TTC) | baseline |
| V3 | item INACTIF #25 (status=10) | **422** « Article 25 inactif » | `AvailabilityService:238` |
| V4 | item INACTIF #27 Big Tacos | **422** « Article 27 inactif » | idem |
| V5 | Tacos L 2 viandes dans Viande1 (max=1) | **422** « max 1, reçu 2 » | `PricingService:427` |
| V6 | cross-item: variation 361 (Tacos L) sur item 26 | **422** « n'appartient pas » | `PricingService:152` |
| V7 | Tacos M + extra 392 « Viande suppl. » 2,50 | total=**9,40** (6,90+2,50) | extra DB facturé |
| V8 | variation 43 quantity=2 (allow_repeat=0) | **422** « max 1, reçu 2 » | `PricingService:439` |
| V9 | qty=99 (DoS prix) | total=**683,10** (6,90×99 +tax) | prix=DB×qty |

Reproduction (exact): `DB_DATABASE=foodking_e2e php artisan tinker` →
`app(PricingService::class)->calculateOrder(PricingRequest::forPos(0,1,[$item],0,1,0,0), app(CouponService::class))`.

**Pourquoi ça tient (lecture code):**
- `PricingService::calculateOrder` lit `Item::price`, `ItemVariation::price`,
  `ItemExtra::price` depuis la DB ; le payload client n'envoie que id/qty/option_ids.
- `OrderService::posOrderStore:704-705` fait `unset($validated['total'],['subtotal'],['discount'])`
  AVANT `Order::create` → forge client jamais persistée, même transitoirement.
- `assertOptionsOrderable` (PricingService:452) rejette variation/extra/addon
  non-ACTIVE ou non-`isVisibleOn(surface)`.
- `enforceCrossItemGuards: true` sur TOUS les `PricingRequest::for*` (jamais
  désactivé) → injection d'option d'un autre produit bloquée.
- `ValidJsonOrder` cape les items à 50 (DoS) ; instruction ≤500 car.

---

## FINDINGS (prouvées)

### [P3] app/Services/Order/OrderQuoteService.php:206 — quote≠store : attribut REQUIS omis accepté par le devis, rejeté à la commande
- **repro**: Tacos L (#97) envoyé avec Viande1=361 (+sauce) mais SANS Viande2 (min_select=1).
  - QUOTE-ENGINE (`PricingService::calculateOrder` via `OrderQuoteService::calculatePricing`) → **[OK] total=7,90**.
  - STORE-RULE (`MultiVariationConstraint::validateCollectionKeyedByItemIndex`, branché seulement dans `PosOrderRequest::withValidator:248`) → **[422] « Sélectionnez au moins 1 Viande 2 (actuel : 0) »**.
- **evidence**: tinker `DB_DATABASE=foodking_e2e` (sortie `QUOTE-ENGINE: [OK] total=7.90` / `STORE-RULE: [422] …Viande 2…`). Le check « attribut requis ENTIÈREMENT omis » (heal 2026-06-24) vit dans `MultiVariationConstraint:51-106` ; `PricingService::assertVariationConstraints:383` n'inspecte QUE les attributs PRÉSENTS au payload (min vérifié sur present-attr), donc le devis ne voit pas l'omission totale.
- **lentille**: commerçant (caissier voit un prix « valide » au devis puis 422 au confirm → friction, pas de perte). Aussi technique (asymétrie preview/commit).
- **reco**: NON-frozen. Faire passer `OrderQuoteService::calculatePricing` (ou un hook après pricing) par `MultiVariationConstraint::validateCollectionKeyedByItemIndex` sur `$items` afin que le devis rejette la même omission que le store. Créer `tests/Feature/Pos/PosQuoteVariationConstraintTest.php` (prévu au plan, ABSENT vérifié) couvrant omis/excès viande sur le endpoint quote. Pas de money/fiscal: le store reste le gate, aucun ordre incomplet ne peut être créé.

### [P3] tests/Feature/Pos/PosQuoteVariationConstraintTest.php — test d'acceptance planifié ABSENT (couverture quote-contraintes manquante)
- **repro**: `ls tests/Feature/Pos/PosQuoteVariationConstraintTest.php` → No such file. `find tests -iname '*QuoteVariation*'` → vide.
- **evidence**: le plan `01_SYSTEM_CAISSE.md` Sub 1.a Acceptance liste ce test « À CRÉER » ; il n'existe pas. Les 18 tests existants (`QuoteBindingTest|PosOrderRequestNoClientTotalsTest|FritesWizardComposerTest|PosMenuRuntimeAccessTest`) passent (18/18, 143 assertions) mais aucun ne pingle l'asymétrie ci-dessus côté quote.
- **lentille**: technique (régression non gardée).
- **reco**: créer le test en même temps que le heal du P3 ci-dessus (un seul TDD couvre les deux).

---

## FAUX-POSITIFS RÉFUTÉS (verify-before-report)

### RÉFUTÉ — « Fantôme-upcharge viande +2,50 » (P2 historique MEMORY/plan §PIÈGES 1) N'EST PLUS reproductible sur le menu live
- Le wizard FROZEN `pos-wizard.js:89` affiche `+VIANDE_SUPPL_PRICE` (fallback 2,50 ;
  setting `order_setup_viande_suppl_price` ABSENT en DB → fallback utilisé), et la
  viande-supplément est keyée `'v_<variationId>'` (`pos-wizard.js:3886-3895`), pas par l'extra.
- MAIS la base live a (seeder `OwnerMenuUpdate20260623`) un **extra réel
  « Viande supplémentaire » prix 2,50** sur CHAQUE item actif à viande
  (ids 392-407: Tacos M/L, Méga, Terminator, Cayenne, Suprême, 6 burgers, Galettes, Bols…).
- Preuve data RÉELLE: order_item **#4894** (order 5138, Tacos M): `item_extras`
  contient `{id:392, qty:1}`, `composition_snapshot.extras[392] unit_price=2.5 line_total=2.5`,
  `item_extra_total=2,50`, `total_price=9,40` (=6,90+2,50). Idem #4911/#4914/#4928.
- **Tous** les items actifs qui affichent le contrôle « +2,50 viande » possèdent
  l'extra 2,50 correspondant (16/16). Affichage wizard (+2,50) == charge backend (2,50).
- ⇒ Sur le menu live, ZÉRO sous-facturation. (Caveat: si un futur item à viande
  est créé SANS son extra « Viande supplémentaire », l'écart réapparaîtrait — gardé
  par aucun test ; à surveiller, mais pas une finding live aujourd'hui.) NON reporté en P-actif.

### RÉFUTÉ — « 6 bols INACTIFS status=10 ajoutables »
- Les bols expérimentaux inactifs (status=10) sont rejetés au backend (V3/V4
  prouvent le rejet générique status≠5 dans `AvailabilityService:238`, fired dès `branchId>0` ;
  le POS envoie toujours `branch_id` requis `PosOrderRequest:81`). Pas de chemin d'ajout.

### Note (non-finding) — `quote` HTTP écrit une ligne `order_quotes`
- `OrderQuoteService:83` fait `OrderQuote::create(...)`. Le plan dit « preview SANS
  effet de bord » mais l'endpoint HTTP `/api/admin/pos/quote` INSÈRE (intent-hash binding).
  Intentionnel (pas un bug). J'ai donc évité l'endpoint HTTP et testé `PricingService`
  en direct (orderId=0) pour rester read-only strict.

---

## FROZEN TOUCHÉ: aucun heal proposé sur frozen.
- `pos-wizard.js` / `.css` / `admin-pos-v4.blade.php` = STRICT no-touch, NON modifiés.
- Les 2 P3 visent du NON-frozen (`OrderQuoteService.php` + nouveau fichier de test).
