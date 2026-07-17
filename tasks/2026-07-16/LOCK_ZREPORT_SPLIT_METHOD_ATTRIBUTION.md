# LOCK — ZREPORT_SPLIT_METHOD_ATTRIBUTION

**Statut : DRAFT — en attente de sign-off owner (§10 ci-dessous). AUCUN patch appliqué.**
Date : 2026-07-16
Cycle : GOAL validation terrain (vague intersections cross-système)
Fichier frozen visé : `app/Services/Fiscal/ZReportService.php` (CLAUDE.md §7 — NF525-critical, chaîne HMAC signée)
Sous-agent d'application proposé : `foodking-complex-implementer` (patch chirurgical frozen)

---

## 1. Problème (prouvé, re-vérifié §3ter)

`ZReportService::applyOrderToTotals` (lignes 792-793) ventile `total_by_method` en attribuant **la
totalité du `order->total`** à un **seul** moyen de paiement — le `pos_payment_method` **dominant** de la
commande :

```php
$method = (string) ($order->pos_payment_method ?: ($order->payment_method ?: 'unknown'));
$byMethod[$method] = ($byMethod[$method] ?? 0.0) + ($sign * $ttc);   // total ENTIER sur 1 méthode
```

Or en paiement SPLIT, `pos_payment_method` = mode **dominant unique** (`PosOrderRequest.php:91`), tandis
que les montants réels par mode vivent dans les lignes `OrderPayment` (`mode`, `amount`, une row par tranche).

**Conséquence** : sur une vente split, le `total_by_method` du **payload Z signé HMAC** (inclus dans
`sign($branchId, $prevHash, seq, $aggregates, $closedAt)`, l.287) **sur-déclare le mode dominant et masque
les autres tranches**.

**Repro chiffré** : split 10 € CASH + 15 € CARTE (dominant=CARTE), `order.total=25` :
- Z signé `total_by_method` : `card += 25` (**faux** — carte réelle = 15, cash réel = 10 invisible)
- `by_terminal` (enrichissement `ZReportCashEnrichmentService`, même Z) : `card=15, cash=10` (**correct**)
- Tiroir `CashMovement` : `+10` cash (**correct**)

Sur le **même** Z, la ventilation fiscale signée contredit le tiroir et le breakdown TPE.
`total_ttc` reste juste (25) ; seule la **décomposition par moyen de paiement** est erronée.
`SPLIT_PAYMENT_ENABLED=true` en prod (`config/split_payment.php`, `.env`).

## 2. Pourquoi un LOCK est nécessaire

`ZReportService.php` est en frozen-zone §7 (loi NF525, chaîne HMAC append-only). Toute modif de la logique
d'agrégation change la **signature des futures clôtures** → gate owner obligatoire.
Aucun fichier adjacent non-frozen ne peut porter le fix : l'agrégation `byMethod` est intrinsèque à
`ZReportService::aggregate()`.

## 3. Scope du patch (chirurgical, minimal)

**Un seul point** : `applyOrderToTotals()` (≈6 lignes modifiées).

Logique cible :
- Si la commande a des lignes `OrderPayment` (split) → ventiler **chaque tranche** : `byMethod[mode] += sign * amount`
  (le canon de mode = `OrderPayment.mode`, même source que `ZReportCashEnrichmentService` et que le fix
  `refundCashTranchePortion` déjà livré). La somme des tranches == `order->total` (invariant SplitPaymentService).
- Sinon (mono-tender, aucune ligne `OrderPayment`) → comportement **inchangé** : `byMethod[pos_payment_method] += sign * total`.
- `$sign` préservé (les miroirs de refund négatent correctement, cohérent avec le netting existant).

`total_ttc` **inchangé** (toujours `order->total`). L'identité NF525
`total_tva == Σ total_by_tax_rate` **inchangée** (ce patch ne touche PAS la décomposition TVA, seulement `byMethod`).

## 4. Non-régression / portée temporelle

- **Forward-only** : les Z **déjà signés** sont immuables (NF525) et **conservent leur signature** — ce patch
  n'affecte QUE les clôtures **futures**. Aucune migration, aucune ré-signature rétroactive.
- Les Z mono-tender (cash pur / carte pur) : `byMethod` **identique** avant/après (pas de lignes OrderPayment
  ou une seule tranche == total). Zéro changement pour le cas courant Le Cayenne mono-tender.

## 5. Rollback

- `git revert <commit>` du patch → les futures clôtures reviennent au comportement dominant-mode.
- Aucun état à restaurer (pas de données modifiées ; Z passés intouchés).
- Test sentinelle (cf. §7) garde la non-régression du cas mono-tender.

## 6. Frozen-zone diff attendu

- `git diff --stat` : **1 fichier** (`app/Services/Fiscal/ZReportService.php`), ~+8/-2 lignes, méthode `applyOrderToTotals` uniquement.
- Chaîne : `php artisan fiscal:verify-chain --all` doit rester vert (le patch ne touche pas la chaîne HMAC ni les triggers).

## 7. Critères d'acceptation (objectivement vérifiables)

1. Nouveau test `ZReportSplitMethodAttributionTest` : une vente split 10 cash + 15 carte close dans un Z →
   `total_by_method = {cash:10, card:15}` (PAS `{card:25}`) ; `total_ttc == 25`.
2. Test mono-tender inchangé : vente cash 7,50 → `total_by_method = {cash:7,50}` (repli order-level, aucune régression).
3. `php artisan test --filter ZReport` → 100% vert (suites Z existantes non régressées).
4. `php artisan fiscal:verify-chain --all` → vert.
5. `total_tva == Σ total_by_tax_rate` toujours exact dans le payload signé (identité NF525 préservée).

## 8. Risques résiduels

- Faible : le patch lit `OrderPayment` (table existante, déjà lue par l'enrichissement). Pas de nouvelle dépendance.
- Le `by_terminal` (enrichissement) devient **cohérent** avec `total_by_method` après le fix (aujourd'hui ils divergent).

## 9. Évidence pré-patch

- Bug confirmé : `ZReportService.php:787-794` (lu), `total_by_method` dans payload signé (`sign()` l.287).
- Source correcte : `OrderPayment.mode/amount` (déjà utilisée `ZReportCashEnrichmentService:157`, `refundCashTranchePortion`).
- Split pose bien des lignes OrderPayment : `SplitPaymentService::persistTranches` (vérifié vague caisse).

## 10. Human gate — sign-off owner (OBLIGATOIRE avant application)

- [ ] **Owner a lu et approuve** le scope §3 + la portée forward-only §4.
- [ ] Owner autorise le déblocage frozen §7 de `ZReportService.php` pour ce patch précis.
- [ ] Après sign-off : Claude applique via `foodking-complex-implementer`, exécute §7 (tests + verify-chain),
      puis passe ce LOCK en **CLOSED** avec le hash du commit.

**Tant que §10 n'est pas signé, ce LOCK reste DRAFT et aucune ligne de `ZReportService.php` n'est modifiée.**
