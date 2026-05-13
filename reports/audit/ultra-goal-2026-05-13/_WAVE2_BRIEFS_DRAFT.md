# Wave 2 Sub-agent Briefs (Draft — for Phase A4 + A5)

## A4 — POS Vanilla Wizard (FROZEN read-only)
- File : `public/js/pos-wizard.js` (~296 KB, NOT Mix-built)
- CSS : `public/css/pos-wizard.css`
- Blade : `resources/views/admin-pos-v4.blade.php`
- LOCK required for any code change
- Known backlog : P0-15 frozen breach +237 viande logic +133 composer-aware ; P1-2 fake Pain/Galette step for Cayenne sandwich
- Live test : open POS, click Bol Curry → composer-aware path 4 steps (base/sauce/supp/drink) ; click Sandwich Cayenne → fake step risk
- Heal applied (env) : `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED=true` in `.env` (BRAIN 2026-05-13 P0-1 healed)

### Checks
1. Diff vs HEAD@phase0 = 0 ligne expected
2. Composer-aware gate `posWizardComposerAware.enabled` consumes composer_profile correctly
3. With `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED=true`, bols flow works (4 steps composer)
4. detectCategory fallback for unknown wizard_template 'custom' falls to default
5. getAllowedSteps switch — sandwich/burger/tacos/assiette/salade/omelette/ojja/snacking + default (no 'custom' case = composer-aware mandatory)
6. Hardcoded fallback Pain/Galette (lines 698-703) — Cayenne sandwich fake step risk (P1-2)
7. Sauce attribute regex `'sauce|assaisonnement'` — matches "Sauce Cayenne (incluse)" + "Sauce bol"
8. Viande detection regex `'viande|meat|proteine'` — matches "Viande 1" + "Viande 2"
9. ALL_SAUCES hardcoded list (lines 65-83) — 15 old sauces vs new 13 canonical
10. POS-WIZARD-DRINKS group_label — addons role='drink' picked up
11. Menu choice 'full'/'frites'/'boisson'/'none' — addons matched by name
12. POS_WIZARD_CONFIG fallback prices — sauceExtraPrice 0.50, viandeSupplPrice 2.50

## A5 — POS Vue Admin
- File : `resources/js/components/admin/pos/` + `resources/js/pos-app.js`
- Routes : `routes/web.php` `/admin/pos-v4/{any?}` AdminPosV4Controller

### Checks
1. POS V4 entry route works
2. Sidebar 9 active cats + cat 315 hidden
3. Item add → wizard binding (correct template per item)
4. Cash drawer session start/close
5. Print receipt ESC/POS (composition_snapshot rendering)
6. Idempotency-Key header on POST order
7. Voids/refunds UX
8. Branch_id sticky per session
9. POS V4 vs legacy parity
10. Real-time KDS bump

Plus addressing :
- A03-1 backlog : POS wizard FROZEN n'émet pas `role=menu_*` sur menu addons → POS-path menu formulas silently overcharge 1.20-1.80€/order (E-001 fix landed kiosk ONLY, NOT pos-wizard.js). **A4 verifies this is still a P0**.

## Heal hints (to be evaluated post-adversarial)
- PricingService TTC/HT : add `config(['pricing.tax_inclusive_prices' => false]);` to setUp() of 5 affected test classes — matches architect-intended pattern (TaxInclusivePricesTest already uses this pattern)
- A3 ItemExtraVariationBridge listener wiring — need to investigate EventServiceProvider $listen array
- A3 OutboxOverviewComponent axios /api prefix
- A3 KdsSyncService — update test, not code (design-intent documented)
- A3 channels.php private-branch.{id} naming

## Order of heal (Wave 1)
1. A1 : no heal needed (all PASS)
2. A2 : after adversarial confirms TTC config interpretation
3. A3 : 3 P0s — relatively quick fixes
