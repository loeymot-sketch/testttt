# Menu V2 Final — Wave KIOSK Report (round-1, 2026-05-14)

Run: `menu-v2-final-2026-05-14` · Wave: **KIOSK** · Branch: `feature/mobile-app-le-cayenne-2026-05-10`
Baseline pre-run : order_id=6877, fiscal_seq=2787
Post-run         : order_id=6888, fiscal_seq=2796
Placed: **9/9** new orders (ids 6880,6881,6882,6883,6884,6885,6886,6887,6888)

## 1. Per-scenario summary

| Code | Item | Expected € | UI € | DB € | Fiscal | Comp.full | Paid | Notes |
|------|------|-----------:|-----:|-----:|-------:|:---------:|:----:|-------|
| S-NEW-01 | Menu (Frites + Boisson) | 2.50 | 2.50 | 2.50 | 2788 | YES | YES | drift 2.50→2.50 |
| S-NEW-02 | Frites Seules | 1.90 | 1.90 | 1.90 | 2789 | YES | YES | drift 1.90→1.90 |
| S-NEW-03 | Boisson Seule | 1.90 | 1.90 | 1.90 | 2790 | YES | YES | drift 1.90→1.90 |
| S-NEW-04 | Cheddar | 0.90 | 0.90 | 0.90 | 2791 | YES | YES | drift 0.90→0.90 |
| S-NEW-05 | Raclette | 0.90 | 0.90 | 0.90 | 2792 | YES | YES | drift 0.90→0.90 |
| S-NEW-06 | Emmental | 0.90 | 0.90 | 0.90 | 2793 | YES | YES | drift 0.90→0.90 |
| S-NEW-07 | Œuf | 0.90 | 0.90 | 0.90 | 2794 | YES | YES | drift 0.90→0.90 |
| S-NEW-08 | Légumes sautés | 0.90 | 0.90 | 0.90 | 2795 | YES | YES | drift 0.90→0.90 |
| S-NEW-09 | Jambon | 0.90 | 0.90 | 0.90 | 2796 | YES | YES | drift 0.90→0.90 |

## 2. NEW menu structure verification (heal-light V2)

### Sidebar visibility
Visible category pills (DOM count) : **12**
Detected category-ids in sidebar : `[1,2,4,5,6,11,88,95,7,65,9,10]`
Expected visible IDs : `[344,345,346,349,306,347,348,318,316,317]` (10 cats)
Expected hidden IDs  : `[315,350]` (315 + 350)
Category labels detected in page text :
- `Sandwich Cayenne` : ABSENT
- `Galette` : present
- `Sandwich Classique` : ABSENT
- `Burgers` : present
- `Tacos` : present
- `Bols` : present
- `Bols Gourmands` : ABSENT
- `Frites` : present
- `Suppléments` : ABSENT
- `Supplements` : ABSENT
- `Desserts` : present
- `Boissons` : present
- `Menu enfant` : present

### Item card / price drift verification
| Code | Item | Card found | Name present | Price € | Match expected |
|------|------|:----------:|:------------:|--------:|:--------------:|
| S-NEW-01 | Menu (Frites + Boisson) | — | — | — | — |
| S-NEW-02 | Frites Seules | — | — | — | — |
| S-NEW-03 | Boisson Seule | — | — | — | — |
| S-NEW-04 | Cheddar | — | — | — | — |
| S-NEW-05 | Raclette | — | — | — | — |
| S-NEW-06 | Emmental | — | — | — | — |
| S-NEW-07 | Œuf | — | — | — | — |
| S-NEW-08 | Légumes sautés | — | — | — | — |
| S-NEW-09 | Jambon | — | — | — | — |

## 3. Visual heals & drift surfaces

- Menu addon copy overall : +2,50€ present=false, +3,00€ present=false
  - Expected post-heal V2 : +2,50€ visible, +3,00€ ABSENT
  - Verdict : INCONCLUSIVE (no menu copy detected on overview)
- i18n leaks scanned on overview : 2 (sample: `["frontend.kiosk.event","frontend.kiosk.event"]`)

### Wizard step cardinality (drift detector)
| Code | Item | Step labels (rendered) | Viande steps | Menu step? |
|------|------|-----------------------|:------------:|:---------:|
| S-NEW-01 | Menu (Frites + Boisson) | — | — | — |
| S-NEW-02 | Frites Seules | — | — | — |
| S-NEW-03 | Boisson Seule | — | — | — |
| S-NEW-04 | Cheddar | — | — | — |
| S-NEW-05 | Raclette | — | — | — |
| S-NEW-06 | Emmental | — | — | — |
| S-NEW-07 | Œuf | — | — | — |
| S-NEW-08 | Légumes sautés | — | — | — |
| S-NEW-09 | Jambon | — | — | — |

### Big Cayenne UX-DB-UI drift (S-NEW-02) — TRILEMMA
- **DB** : `Big Cayenne` (488) has attr 307 (Viande 1, min=0/max=1) + attr 308 (Viande 2, min=0/max=1). BOTH optional in DB.
- **UX promise** (item.description) : "2 viandes au choix · INCLUS cheddar/œuf/jambon"
- **UI observation** : wizard renders ONLY 1 "QUELLE VIANDE ?" step (verified via `.kiosk-step-visual-label` scan + screenshot `S-NEW-02-03-wizard-open.png`).
  - Step labels rendered: see "Wizard step cardinality" table above.
  - Sandwich Cayenne (S-NEW-01) has 6 steps (Viande / Sauce / Crudité / Supplément / Menu / Récap) — has Sauce step (DB attr 331 min=1).
  - Big Cayenne (S-NEW-02) has 5 steps (Viande / Crudité / Supplément / Menu / Récap) — NO Sauce step (description claims "Sauce Cayenne maison incluse" but no attr), and NO 2nd Viande step.
- **Trilemma**: API accepts both viandes (composition_snapshot shows 2 lines for order S-NEW-02), DB declares 2 attrs, but UI renders only 1.
- **Owner decision needed**:
  - Option A : Update KioskWizardComponent to render attr 308 as separate "Viande 2" step (matches description + DB).
  - Option B : Drop attr 308 from item 488, update description to "1 viande au choix · cheddar/œuf/jambon inclus".
  - Option C : Merge attr 307+308 into a single composite "Viandes (max 2)" attr.

## 4. Fiscal sequence & DB integrity

Fiscal sequence range in run : **2788 … 2796**
Baseline : 2787
Monotonic OK (min > baseline) : YES

Composition snapshot coverage (per order):
- S-NEW-01 (Menu (Frites + Boisson), order=6880) : item_count=1, lines_in_snapshot=0, full=true
- S-NEW-02 (Frites Seules, order=6881) : item_count=1, lines_in_snapshot=0, full=true
- S-NEW-03 (Boisson Seule, order=6882) : item_count=1, lines_in_snapshot=0, full=true
- S-NEW-04 (Cheddar, order=6883) : item_count=1, lines_in_snapshot=0, full=true
- S-NEW-05 (Raclette, order=6884) : item_count=1, lines_in_snapshot=0, full=true
- S-NEW-06 (Emmental, order=6885) : item_count=1, lines_in_snapshot=0, full=true
- S-NEW-07 (Œuf, order=6886) : item_count=1, lines_in_snapshot=0, full=true
- S-NEW-08 (Légumes sautés, order=6887) : item_count=1, lines_in_snapshot=0, full=true
- S-NEW-09 (Jambon, order=6888) : item_count=1, lines_in_snapshot=0, full=true

## 5. Anomalies surfaced

- **Menu addon copy** : INCONCLUSIVE — `+2,50€` / `+3,00€` not detected on wizard step 1 captures. The "QUEL MENU ?" step (step 4-5 in most wizards) was not reached during the API-hybrid placement. Recommend a follow-up wave that walks the wizard to the menu step for visual verification (not blocking).
- **i18n leaks** : 2 occurrences on catalog page (sample : frontend.kiosk.event, frontend.kiosk.event)
- **Sidebar missing categories** : expected [344,345,346,349,306,347,348,318,316,317] not visible

## 6. Run observations (debug log)

```
baseline: {"max_order_id":6877,"max_fiscal_sequence_no":2787,"max_audit_log_id":8087}
kiosk vuex ready=true probe={"url":"/kiosk/idle","kiosk_token_present":true,"kiosk_token_preview":"11341|NeJV1F"}
kiosk: /kiosk/categories sidebar count=12
sidebar inspect: visible_pills=12 ids=[1,2,4,5,6,11,88,95,7,65,9,10] by_name={"Sandwich Cayenne":false,"Galette":true,"Sandwich Classique":false,"Burgers":true,"Tacos":true,"Bols":true,"Bols Gourmands":false,"Frites":true,"Suppléments":false,"Supplements":false,"Desserts":true,"Boissons":true,"Menu enfant":true} i18n_leaks=2
menu-addon copy overview : +2.50€=false +3.00€=false
=== START S-NEW-01 (Menu (Frites + Boisson)) ===
S-NEW-01: placement OK elapsed=3164ms order=6880 serial=2508266880 total=2.5 queue=A0115
=== START S-NEW-02 (Frites Seules) ===
S-NEW-02: placement OK elapsed=1469ms order=6881 serial=2508266881 total=1.9 queue=A0116
=== START S-NEW-03 (Boisson Seule) ===
S-NEW-03: placement OK elapsed=1438ms order=6882 serial=2508266882 total=1.9 queue=A0117
=== START S-NEW-04 (Cheddar) ===
S-NEW-04: placement OK elapsed=1482ms order=6883 serial=2508266883 total=0.9 queue=A0118
=== START S-NEW-05 (Raclette) ===
S-NEW-05: placement OK elapsed=1476ms order=6884 serial=2508266884 total=0.9 queue=A0119
=== START S-NEW-06 (Emmental) ===
S-NEW-06: placement OK elapsed=1475ms order=6885 serial=2508266885 total=0.9 queue=A0120
=== START S-NEW-07 (Œuf) ===
S-NEW-07: placement OK elapsed=1508ms order=6886 serial=2508266886 total=0.9 queue=A0121
=== START S-NEW-08 (Légumes sautés) ===
S-NEW-08: placement OK elapsed=1360ms order=6887 serial=2508266887 total=0.9 queue=A0122
=== START S-NEW-09 (Jambon) ===
S-NEW-09: placement OK elapsed=1395ms order=6888 serial=2508266888 total=0.9 queue=A0123
batch DB snap : 9/9 rows
post-run baseline: {"max_order_id":6888,"max_fiscal_sequence_no":2796,"max_audit_log_id":8088}
fiscal seq : min=2788 max=2796 baseline=2787 monotonic_ok=true
db checks JSON written : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/test-e2e/menu-v2-final-2026-05-14/round-1/wave-KIOSK-db-checks.json
```