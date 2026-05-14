# Adversarial Supervisor Summary — Menu V2 Final, Round 1

**Run** : `menu-v2-final-2026-05-14` · **Round** : 1 · **States reviewed** : 60 quartet captures
**Reviewer** : adversarial supervisor (visual-first, technical-second, paranoid-third)
**Verdict** : **NO-GO** (3 confirmed P1, 1 latent P1, 5 P2/P3)

## Net-new findings (not in GStack reports)

### WV2-R1-01 — Big Cayenne kiosk binding bug (P1, refutes GStack §5 framing)
GStack framed the Big Cayenne 1-viande step as "owner trilemma A/B/C". The supervisor verified that **POS V4 reads the same DB and renders 0/2 correctly** (S-POS-NEW-01-02-wizard-popup.png) — proving the DB structure (attr 307 + 308) is fine. The bug is in kiosk-only code: `resources/js/helpers/kioskTacosSize.js` `viandeCountFromName("Big Cayenne")` returns null (no M/L/XL/MEGA in name) → `KioskWizardComponent.detectViandeCount()` falls back to 1, ignoring attr 308 in DB. Fix is mandatory, not optional.

### WV2-R1-02 — Big Classique latent P1 (same root cause)
**Big Classique (489)** — created by heal-light V2 in cat 346 at 9.00€ — has identical DB structure (attr 307 + 308) and identical name pattern (no taille suffix). Kiosk wizard will render 0/1 same as Big Cayenne. Not exercised by Wave KIOSK because spec didn't test cat 346 NEW item, but the bug is verifiable in DB right now.

### WV2-R1-03 — Big Cayenne missing attr 331 (P1, confirms GStack §5)
Description says "Sauce Cayenne maison incluse" but Big Cayenne (488) has NO attr 331 in DB (Sandwich Cayenne 474 and Galette Cayenne 476 do). Heal-light V2 missed it. Customer never sees the sauce step; cook never gets a sauce line in composition_snapshot.

### WV2-R1-04 — Menu enfant kiosk leak (P1, confirmed visually)
Cat 350 'Menu enfant' is in expected_hidden list, but renders as the 11th sidebar pill on every kiosk capture. User-facing categorization defect.

### WV2-R1-05 — Wave POS-CROSS false KDS multi-item claim (P1, new)
The Wave POS-CROSS report claims KDS shows "Chicken Burger, Bowl Frites Poulet curry, Big Cayenne, Galette Cayenne, Sandwich Classique, Tacos M/L" and 59 card nodes. **The actual PNG shows ONE order #1405261465 Sandwich Cayenne**. supplement-tracking.json says kds_ui.cards_count=22 (not 59). The cross-surface KDS sync claim is unsupported by the durable evidence. This is the "hallucinated context" failure mode flagged in user memory.

### WV2-R1-06 — Composition_full_coverage semantic mismatch (P2, new)
Wave KIOSK asserts `composition_full_coverage: true` for all 9 orders. The assertion checks `item_count == items_with_composition_snapshot` (envelope exists), NOT lines.length > 0. **4/9 orders (1468 Sandwich Classique, 1469 Tacos M, 1470 Tacos L, 1471 Chicken Burger) have empty `lines: []`** — API-hybrid placement bypassed wizard selections. The kiosk wizard end-to-end capture path is unproven for these 4 NEW menu items.

### WV2-R1-07 — POS-CROSS zero E2E persistence (P2)
All 3 POS scenarios returned HTTP 429. DB fallback resolved to parallel-wave kiosk orders (e.g. id=1437 total 13.80€ — not the expected 9.50€ POS Big Cayenne). Visual evidence only; no POS-source NEW order was actually persisted.

### Tacos L claim CORRECTED (supervisor net-positive)
GStack reported Tacos L (S-NEW-06) as a P1 trilemma identical to Big Cayenne. **It is NOT a defect.** Wizard renders "0/2 — Choisissez 2 portions de viande / Sélectionnez 2 viandes pour continuer" via single-step-repeat. GStack's `viande_step_count=1` was a counting artifact (step containers, not selection cardinality). Tacos L works correctly.

## Verdict rationale

Per REVIEWER_PROTOCOL §Severity: "P1 must be 0 to declare green". **4 P1 findings open**: kiosk binding bug × 2 items (Big Cayenne + latent Big Classique), missing attr 331 on Big Cayenne, Menu enfant kiosk leak, false KDS claim.

Default: **NO-GO**. GO-CONDITIONAL would require an explicit owner waiver — the protocol does not grant that latitude.

## Trust signals on GStack work (positive)

- Visual heals real: `+` badge on composer cards visible, "Choisissez 1 viande" copy present (S-NEW-01)
- Price drifts applied + rendered: 7→7.50€ Sandwich Cayenne, 6.50→7€ Sandwich Classique, 11.50→7.90€ Tacos L, 8.50→6.90€ Tacos M
- Rename drifts applied + rendered: Tacos→Tacos M, Big Tacos→Tacos L
- 8 NEW Bowl items (4 Frites + 4 Riz) created in cat 347 at 8.90€
- 2 NEW Burgers in cat 349 (Chicken Burger 6.90€ + Big Chicken 8.90€) with intact wizards
- Bowl Frites composer step renders 11 sauces + supplément + boisson + gratiné correctly in kiosk
- Frozen-zone integrity for commit 62959bfc9 confirmed (zero PHP source touched)
- Fiscal sequence 317-325 monotonic gap-free
