# V2 — MATRICE MONEY-PATH AU CENTIME (audit lecture seule, 2026-07-29)

Verdict global : **AUCUNE perte de centime sur les 7 flux** — argent 100 % serveur,
centimes entiers aux points critiques, rendu recalculé serveur (mono + split), tiroir =
portion cash physique uniquement, fiscal alloué au bon instant.

| Flux | Verdict | Preuves clés |
|---|---|---|
| Encaissement cash + rendu | SAIN | `PaymentService::confirmCounterPayment:228` (transaction+lock), garde reçu<total 422 (`:369-373`), rendu dérivé serveur (`OrderDetailsResource:139,221-230`), tiroir IN = total (`:607-619`). Tests PosCashTrail/CounterCollectQueueRobust/FiscalCashAtCounterLifecycle |
| Split | SAIN | `SplitPaymentService:51-198` tout en centimes entiers ; rendu split recalculé serveur clampé ≥0 (`:262-270`, leçon 2026-07-11 en place) ; dernière tranche = reste exact revalidé backend. Tests SplitPaymentEndToEnd + sentinel phantom-card + 2 specs vitest |
| Annulation/refund | SAIN | cashBack idempotent atomique (`:92-226`) ; refund cash → tiroir OUT sans crédit wallet (MP-01 `:154-159`) ; portion cash split only (MP-03 `:197-211`) ; stock reverse afterCommit ; netting TVA post-Z sentinelé |
| Remise POS | SAIN (kill-switch) | `manual_discount_enabled` défaut false (`config/pos.php:187-191`) + sentinels 422 ; fidélité découplée, arrondi cent-clean |
| TVA 10 % | SAIN | mode TTC (`config/pricing.php:31`), extraction par ligne (`TaxCalculator:32-48`), total = Σ TTC jamais +tax (`PricingService:350-352`) ; 65 items actifs à 10 % en DB e2e ; sentinels MenuVat10Percent/Preflight/Vat10ZReconciliation |
| Borne Plan B + web COD | SAIN | même chokepoint `confirmCounterPayment` ; fiscal_seq alloué à l'encaissement pour les différés avec `fiscal_dated_at` (`:375-386`) ; verrous FiscalCashAtCounterLifecycle/KioskRetryFiscalDatedAt/ChangePaymentStatus |
| Tiroir | SAIN | open TOCTOU 3 couches, close idempotent, reconcile arrondi 2 déc., gate variance >2 € permissionnée (`CashDrawerService:257-312`) |

## Actions issues de l'audit
1. **FAIT** : `RefundCashNoWalletCreditTest.php` (verrou MP-01, untracked dans le checkout principal
   depuis le 22/07) copié dans la branche S2 et exécuté : **2 tests / 4 assertions VERTS** → commité.
2. P3 cosmétique (backlog) : clamp `max(0,…)` manquant `OrderDetailsResource:139` (JSON `cash_back_amount`
   négatif si paiement carte — affichage only) ; parité non testée du `cashChange` de PosCounterCollectModal.
3. P3 documenté : tolérance overpay split ≤ min(1 €, Σ cash) = sur-encaissement volontaire possible
   (tiroir honnête, Z ≠ encaissé de l'écart) — conception assumée, UI auto-balance rend le cas rare.

## Duplications d'argent (mandat anti-doublons)
- splitPayment JS/PHP : backend autoritaire, rejette tout écart — SAIN.
- ratios menu kiosk JS/PHP : parité par construction (même config exposée) — SAIN.
- pos-wizard (FROZEN) : totaux client jamais pris (`PosOrderRequestNoClientTotalsTest`) — SAIN.

Reste V2 : scénarios e2e réels chronométrés (après reset limite session) — encaisser+rendre,
annuler+motif, refund, borne à encaisser, web accepter/refuser, parking/reprise, split réel.
