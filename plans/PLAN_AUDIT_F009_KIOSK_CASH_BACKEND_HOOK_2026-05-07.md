# PLAN AUDIT F-009 — Kiosk Cash Backend Hook / Counter-Deferred Invariant (2026-05-07)

> **Reconstruit 2026-07-02 (ultra-audit).** Original dans un worktree transitoire supprimé
> (`.claude/worktrees/blissful-mclean-c915c2`). Traçabilité préservée par le rapport d'exécution.

## Portée
Invariant kiosk Plan B (paiement routé caisse) : une commande borne cash naît PENDING_COUNTER +
COUNTER_DEFERRED, la cuisine démarre immédiatement, l'allocation fiscale se fait UNIQUEMENT à
l'encaissement (INV-5). Pas de séquence fiscale à la création différée (gap-free).

## Invariants vérifiés (sentinelle `F009KioskCashCounterDeferredInvariantSentinelTest`)
- `FrontendOrderService` : Plan B → statut différé, totaux client unset, PricingService SSOT.
- Encaissement (counter-collect confirm) → PAID + fiscal seq (prouvé live 2026-07-02 : #5398 → seq 2589).

## Résultat / clôture
→ Voir `reports/execution/audit_2026-05-07/`. Flux confirmé end-to-end (borne→caisse→KDS→OSS→encaissement→fiscal).
