# Axe 12 — Intégrité données (schéma & invariants)

| Élément | Audit |
|---------|--------|
| **`orders`** | Colonnes fiscales, `idempotency_key`, `loyalty_points_awarded` fillable — cohérent. |
| **`order_items`** | Relation standard ; wizard variations — hors lecture exhaustive. |
| **`order_payments`** | **Table absente** — pas de modèle split natif (`F-PAY-001`). |
| **`audit_logs`** | Chaîne hash + contraintes (voir tests & migrations dédiées). |
| **`z_reports`** | Séquence `sequence_no`, statut OPEN/CLOSED, signature. |
| **`Order::restore()`** | **Jeté** runtime — **positif** intégrité (`Order.php` ~98–106). |
| **SoftDeletes** Order | Avec enfants hard-deleted — documenté comme one-way audit. |
| **Index / UNIQUE** | fiscal sequence par branche, idempotency branch-scoped — tests `IdempotencyBranchScopedTest`. |

**Liens tracker :** F-DATA-001, F-DATA-002, F-PAY-001.
