# PLAN AUDIT F-006 — POS Idempotency Parity (2026-05-07)

> **Reconstruit 2026-07-02 (ultra-audit).** Original dans un worktree transitoire supprimé
> (`.claude/worktrees/blissful-mclean-c915c2`). Traçabilité préservée par le rapport d'exécution.

## Portée
Parité d'idempotence sur les routes POS mutantes : chaque route déclarant le middleware
`idempotency` doit figurer dans `config('idempotency.required_routes')` (pas de double-exécution
au retry : double charge, double ouverture tiroir, double changement de statut).

## Invariants vérifiés (sentinelle `F006PosIdempotencyParitySentinelTest` + `IdempotencyRequiredRoutesCoverageTest`)
- Couverture route↔config exhaustive (ex. ajout `pos/orders/*/print-kitchen` 2026-07-02).
- `IdempotencyKeyMiddleware` (frozen) : dual-layer cache + DB UNIQUE, replay 2xx-only.

## Résultat / clôture
→ Voir `reports/execution/audit_2026-05-07/` (FINAL_REPORT + rapports Wave). Middleware idempotency actif.
