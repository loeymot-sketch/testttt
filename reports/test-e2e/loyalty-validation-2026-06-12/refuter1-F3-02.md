# REFUTER-1 — F3-02 (mutation points sans event, FrontendOrderService fallback)
## Étape 1 — Vérification statique (2026-06-12)
- app/Services/FrontendOrderService.php:899-904 = DB::table('users')->update(['loyalty_points'=>$balanceAfter]) CONFIRMÉ (Read).
- grep LoyaltyBalanceChanged dans FrontendOrderService.php = 0 hit CONFIRMÉ.
- Ledger :906 createKioskLoyaltyRedeemLedger → description 'Reduction fidelite appliquee sur commande kiosk' (:928) CONFIRMÉ.
- Appel applyKioskLoyaltyDiscount à :485 UNCONDITIONNEL (HORS du if !use_ssot_service :464) — atteignable même SSOT actif.
- 7 autres sites dispatchent bien: LoyaltyController 221/294/416, AwardLoyaltyPointsOnDelivery 145, LoyaltyService 91/200, app/Services/Loyalty/PosRedemptionService.php:204 (micro-imprécision de chemin dans le finding: sous-dossier Loyalty/).
- Config harnais e2e: use_ssot=true, manual_discount=true, tax_inclusive=true (tinker).
- Test existant KioskLoyaltyLedgerAtomicTest::test_nominal_redeem exerce EXACTEMENT cette branche fallback (pas de pendingRedeem) → branche pas morte, mais aucun test n'asserte d'event dessus.
