# LOCK_B — `app/Services/Pricing/DiscountCalculator.php` — POS-9.4.BL.2

**Posé par.** Track B (POS orchestrator) sur `feat/pos-phase-9-2-3`.
**Date.** 2026-04-18 post P9.5 + Phase H merge.

## Pré-conditions vérifiées

- [x] Aucun `LOCK_A_*` actif sur `DiscountCalculator.php`. P9.5 n'a pas édité ce fichier (PricingService core untouché per BROADCAST §2).
- [x] Aucun autre `LOCK_B_*` actif.

## Fichier et scope

**Fichier.** `app/Services/Pricing/DiscountCalculator.php`.

**Modifications planifiées** :

| Vague | Scope |
|---|---|
| POS-9.4.BL.2 | Ajout d'un appel `AuditLogService::write(['action' => 'order.discount_applied', ...])` dans la méthode qui finalise l'application d'une remise significative (seuil à définir au commit, typiquement `$discountAmount > 0`). |

## Règles de respect invariants pendant ce lock

- **SSOT pricing** intact : on ne modifie PAS le calcul de prix, on ajoute UN appel audit en sortie de calcul.
- **Performance** : l'appel audit ne doit pas bloquer le calcul de prix. Le `Cache::lock` HMAC chain est interne au service et rapide (<10ms).
- **Idempotency** : si la même requête recalcule le prix plusieurs fois, un seul audit log doit être écrit. Solution : appeler `AuditLogService::write` seulement au commit final dans le service appelant (`OrderService` via wrapper), pas dans le calculateur pur.

**Note design** : après réflexion, il est possible que l'appel `AuditLogService::write` pour `order.discount_applied` soit mieux placé dans `OrderService` au lieu de `DiscountCalculator` (respect pureté du calculateur). Arbitrage au moment du commit BL.2. Si l'appel reste dans `OrderService`, ce lock sera libéré sans modification et noté comme "non édité finalement".

## ETA libération

**Release par.** Commit POS-9.4.BL.2.
**Durée estimée.** ~30 min.
**Procédure de release.** Mettre à jour `## Status` en `RELEASED` avec SHA du commit BL.2, ou en `RELEASED (unused)` si arbitrage final place le call dans `OrderService`.

## Status

**RELEASED (unused)** le 2026-04-18 au commit BL.2 `a7036f6ec`. Arbitrage final : l'appel `AuditLogService::write(action=order.discount_applied)` est placé dans `OrderService::posOrderStore` (qui connaît la distinction coupon_id vs manual_cashier) plutôt que dans le calculateur pur. `DiscountCalculator.php` n'a donc PAS été modifié par cette vague.
