# REFUTER #2 — F3-02 (mutation points sans LoyaltyBalanceChanged, fallback kiosk)

Date: 2026-06-12 — worktree cms-gestion-2026-06-10, harnais :8767/foodking_e2e (lecture config seule), repro PHPUnit sqlite :memory:.

## Étape 1 — Vérif file:line (grep/Read) : CONFIRMÉ
- `grep -n "LoyaltyBalanceChanged" app/Services/FrontendOrderService.php` → **0 hit** (exit 1).
- Lignes réelles : `:485` appel `applyKioskLoyaltyDiscount` (dans `myOrderStore`, dans la TX), `:815` définition, `:864` query pendingRedeem (le finding cite :877 = le `if ($pendingRedeem)`, même branche, offset mineur), `:899-905` `DB::table('users')->update(['loyalty_points'=>$balanceAfter])`, `:906` `createKioskLoyaltyRedeemLedger`.
- Les 7 autres sites d'écriture dispatchent bien (grep confirmé) : LoyaltyController.php:221/294/416, AwardLoyaltyPointsOnDelivery.php:145, LoyaltyService.php:91/200, PosRedemptionService.php:204. Ce site est le SEUL orphelin.
- Docblock de `app/Events/LoyaltyBalanceChanged.php` : « Emitted whenever a customer's loyalty balance changes (… kiosk/frontend redeem …) » → site orphelin = violation du contrat L2 énoncé.
- Harnais : `config('pos.manual_discount_enabled')=true`, `pricing.use_ssot_service=true`, DB=foodking_e2e (vérifié tinker APP_ENV=e2e).

## Étape 2 — Repro exécutée : FINDING REPRODUIT
Test écrit (hors repo-suite) : `reports/test-e2e/loyalty-validation-2026-06-12/F302RefuterRepro2Test.php` — setup miroir du test canonique `tests/Feature/KioskLoyaltyLedgerAtomicTest.php`, vraie route `POST /api/frontend/order` + vrai `FrontendOrderService`.

Commande : `./vendor/bin/phpunit -c phpunit.xml reports/test-e2e/loyalty-validation-2026-06-12/F302RefuterRepro2Test.php`
Résultat : **OK (2 tests, 7 assertions)**

1. `test_fallback_kiosk_redeem_mutates_points_without_balance_changed_outbox_row` :
   - précondition 0 LoyaltyTransaction (force la branche fallback :899, pas de pendingRedeem) ;
   - POST commande kiosk avec `loyalty_code` + `discount=1.00`, sans coupon, sans redeem préalable → 201 ;
   - points 500→400 + ledger `points=-100, balance_after=400, description='Reduction fidelite appliquee sur commande kiosk'` (= preuve que c'est bien la branche :899/:906) ;
   - **`domain_events` count(event_type=loyalty.balance_changed) = 0** → aucune row outbox → aucun push L2.
2. `test_positive_control_dispatch_inside_transaction_persists_outbox_row` (contrôle anti-faux-négatif) :
   - `LoyaltyBalanceChanged::dispatch(...)` DANS `DB::transaction` (même forme que la TX de :485) → **1 row outbox**. Donc si le code avait dispatché, la row existerait. La méthode de détection est valide.

## Étape 3 — Dedup : pas de doublon trouvé
Grep `reports/test-e2e/cms-e2e-2026-06-10/` + `reports/audit/` : aucune mention antérieure de ce site orphelin. Le GOAL L2 2026-06-11 (commits 2b4eb2596/ba299a657) a corrigé EventType::all() + bundles, pas ce site.

## Verdict : refuted = FALSE (finding réel, reproduit avec preuve)
## Sévérité : P1 → **P2** (downgrade)
Justification V1 LOCAL Le Cayenne :
- Chemin DORMANT dans le flux réel : la borne passe par `/api/frontend/loyalty/redeem` (LoyaltyController:397→416 dispatch) puis la commande rattache le pendingRedeem SANS 2e déduction (l'event a déjà été émis). Le fallback n'est atteint que si >10min entre redeem et commande, ou commande API directe avec loyalty_code. Évidence du finding lui-même : 0 ligne ledger fallback dans foodking_e2e.
- Impact quand déclenché : points + ledger CORRECTS et atomiques (aucune perte de données, aucun impact NF525/fiscal). Seul le push live L2 manque → solde périmé sur le modal caisse jusqu'au prochain fetch (staleness d'affichage transitoire, auto-corrigée).
- Pas un finding multi-tenant/scale/cloud sur-coté — c'est un vrai trou local du contrat L2 — mais P1 dans ce projet = sous-facturation, 500, gaps NF525 ; un event de sync manquant sur un chemin dormant à conséquence affichage-seul = P2.
- La recommandation du finding (dispatch après :906, DispatchableAfterCommit gère la TX) est techniquement correcte et validée par le contrôle positif.
