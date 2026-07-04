# LOCK — ZREPORT SPLIT BUCKETING (M6-002, P1 NF525) — 2026-07-04

> **Statut : EN ATTENTE DE CONTRESIGNATURE OWNER (§10).** Aucune ligne de `ZReportService.php`
> n'a été modifiée. Ce document + le test auto-armant `ZReportSplitBucketingLockTest` sont les
> seuls artefacts livrés (documentation + test régression = autorisés §7).

## 1. Le défaut (catalogue pre-cloud M6-002, confirmé Wave 3 ULTRA + repro live)
`applyOrderToTotals` (app/Services/Fiscal/ZReportService.php:661-668) verse le total INTÉGRAL
d'une commande dans le bucket unique `order->pos_payment_method` — posé par le frontend au
tender **dominant** d'un split. Pour un paiement mixte (ex. 1,50 € cash + 2,50 € carte,
`pos_payment_method=CARD`), le Z **SIGNÉ** montre CARD 4,00 / CASH 0,00 : la ventilation
X-carte/X-espèces est fausse et FIGÉE dans le HMAC. `total_ttc` reste juste ; la vérité
par-tranche existe (non signée) dans `order_payments` + `audit_logs`.
Repro live : commande #4937 (tranches CASH 1,50 + CARD 2,50, dominant CARD).
Reachability : split activé par défaut ; débloqué à la caisse ce jour (`3184e5768`).

## 2. Patch exact (à appliquer UNIQUEMENT après contresignature, ~8 lignes)
Dans `applyOrderToTotals`, avant le bucket unique :
```php
// [LOCK M6-002] Ventilation par-tranche pour les paiements split : chaque tranche
// order_payments va dans SON bucket (signe $sign des miroirs remboursement conservé).
$tranches = $order->relationLoaded('payments') ? $order->payments : $order->payments()->get();
if ($tranches->isNotEmpty()) {
    foreach ($tranches as $t) {
        $byMethod[(string) $t->mode] = ($byMethod[(string) $t->mode] ?? 0) + ((float) $t->amount * $sign);
    }
} else {
    // fallback existant inchangé : bucket pos_payment_method (mono-tender)
}
```
(Adapter les noms exacts `$byMethod`/`$sign`/relation au code réel au moment de l'application —
le patch est sémantique, la revue se fait sur le diff réel.)

## 3. Protocole d'application (triple-vert obligatoire)
1. `php artisan fiscal:verify-chain --all` AVANT (attestation count+last_hash).
2. Appliquer le patch ; retirer l'auto-skip du test (il s'arme automatiquement).
3. `php artisan test tests/Feature/Fiscal/ZReportSplitBucketingLockTest.php` → VERT
   (split 15 cash + 10 card → Z `total_by_method` = {CASH: 15.00, CARD: 10.00} ; miroir
   remboursement ; mono-tender fallback inchangé).
4. Suites fiscales complètes + `fiscal:verify-chain --all` APRÈS (chaîne append-only).
5. Frozen-diff = uniquement les lignes du patch, justifiées par CE LOCK. Commit dédié
   référencant `LOCK_ZREPORT_SPLIT_BUCKETING_M6-002`.

## 4. Rollback
Patch atomique (1 hunk) — revert du commit dédié + re-run des suites fiscales.

## 5. Sign-off (§10)
- [ ] **OWNER** : ______________________ date : __________
- Préparé par : Claude (campagne ULTRA 2026-07-04) — patch NON appliqué.
