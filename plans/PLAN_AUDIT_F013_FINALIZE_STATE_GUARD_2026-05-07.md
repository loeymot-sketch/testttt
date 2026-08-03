# PLAN AUDIT F-013 — Finalize State Guard (2026-05-07)

> **Reconstruit 2026-07-02 (ultra-audit).** Original dans un worktree transitoire supprimé
> (`.claude/worktrees/blissful-mclean-c915c2`). Traçabilité préservée par le rapport d'exécution.

## Portée
Garde d'état à la finalisation d'une commande payée (kiosk carte / TR) : les transitions post-
paiement (PENDING→ACCEPT→PREPARING) et l'ordre du trail fiscal doivent rester cohérents,
sans double-transition ni état incohérent.

## Invariants vérifiés (sentinelle `F013FinalizeStateGuardSentinelTest`)
- `OrderService` / `FrontendOrderService::finalizePaidKioskOrder` : transitions gardées.
- `OrderStateMachine` (frozen) : transitions autorisées uniquement.

## Résultat / clôture
→ Voir `reports/execution/audit_2026-05-07/`. Garde d'état confirmée.
