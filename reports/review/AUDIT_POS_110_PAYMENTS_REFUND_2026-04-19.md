# Axes 4 & 10 — Paiements multi-tender & refunds

## Multi-tender (cash, CB, TR, …)

- **POS** : `PosOrderRequest` borne montants (`min:0` P7) ; contrôle caisse vs total serveur côté `withValidator` (montant reçu).
- **P2** : Ticket restaurant — `pos_payment_note` requis, enum alignée gateway.
- **Constat data** : **pas de table `order_payments`** dans le dépôt (`grep` vide) — **pas de split-payment durable multi-lignes** au sens data warehouse (`F-PAY-001`).
- **Surpaiement / monnaie** : UI `PaymentComponent` affiche **monnaie à rendre** (composant déjà audité en sessions précédentes).
- **Remises** : règles Spatie `%` dans `PosOrderRequest` + raison — cohérent anti-fraude caisse.

## Refunds / annulations / RETURNED

- **P3** : `RETURNED` avec motif + audit `order.returned` + cashback gateway aligné annulation.
- **Partiel** : explicitement **backlog** (handoff P3).
- **Soft delete** : `Order::restore()` **interdit** (`Order.php` boot) — évite incohérence NF525 avec lignes enfants hard-deleted (`F-DATA-001`).

**Scénarios limites** : remboursement post-Z clôt — **règle métier** à valider hors code (impact comptable Z déjà signé).

**Liens tracker :** F-PAY-001, F-PAY-002, F-REF-001, F-DATA-001.
