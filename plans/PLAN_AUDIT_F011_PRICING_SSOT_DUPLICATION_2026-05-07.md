# PLAN_AUDIT_F011 — Pricing SSOT Fallback Duplication
**Severity:** P2 — Risque de drift si feature flag inversé
**Owner agent:** Agent E
**Sprint:** Backlog

## THINK

`OrderService::posOrderStore` ([lignes 604-755](app/Services/OrderService.php:604)) et `FrontendOrderService::myOrderStore` ([lignes 210-370](app/Services/FrontendOrderService.php:210)) ont chacun :
- Un branch SSOT (utilise `PricingService::calculateOrder`)
- Un branch legacy non-SSOT (recalcule items + variations + extras + tax inline)

Les 2 branches dupliquent ~150 lignes de logique de calcul **par service**. Total : ~300 lignes de duplication. Si `pricing.use_ssot_service` flippe en prod (rollback), 2 paths divergent peuvent produire des totaux différents.

## PLAN

1. Auditer si le flag est jamais `false` en prod ou test → si non, supprimer le legacy fallback.
2. Si encore utilisé → extraire le legacy dans un service `LegacyPricingFallback` pour réduire la duplication ; tester équivalence avec SSOT à 0 cent près.

## BUILD

1. Test équivalence : `tests/Unit/Pricing/SsotEquivalenceTest.php` qui run les 2 paths sur 100 cas et asserte égalité au cent près.
2. Si OK → planifier suppression du flag (lecture only `true`).

## Contraintes
- ❌ Pas de suppression sans tests d'équivalence verts.
- ❌ Pas de modification de PricingService (zone scope-actif sensible).

## Decision
`continue` si test équivalence vert. `block` si divergence détectée → P0 immédiat.
