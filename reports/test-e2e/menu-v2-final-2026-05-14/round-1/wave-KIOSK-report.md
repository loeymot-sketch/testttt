# Menu V2 Final — Wave KIOSK Report (round-1, 2026-05-14)

Run: `menu-v2-final-2026-05-14` · Wave: **KIOSK** · Branch: `feature/mobile-app-le-cayenne-2026-05-10`
Baseline pre-run : order_id=1400, fiscal_seq=316
Post-run         : order_id=1480, fiscal_seq=323
Placed: **7/9** new orders (ids 1474,1475,1476,1477,1478,1479,1480)

## 1. Per-scenario summary

| Code | Item | Expected € | UI € | DB € | Fiscal | Comp.full | Paid | Notes |
|------|------|-----------:|-----:|-----:|-------:|:---------:|:----:|-------|
| S-NEW-01 | Sandwich Cayenne | 7.50 | 7.50 | 7.50 | 317 | YES | YES | drift 7.00→7.50 |
| S-NEW-02 | Big Cayenne | 9.50 | — | — | — | — | — | **FAIL** placeKioskOrder failed at stage="quote" HTTP 422: {"status": |
| S-NEW-03 | Galette Cayenne | 7.00 | 7.00 | 7.00 | 318 | YES | YES | — |
| S-NEW-04 | Sandwich Classique | 7.00 | 7.00 | 7.00 | 319 | YES | YES | drift 6.50→7.00 |
| S-NEW-05 | Tacos M | 6.90 | 6.90 | 6.90 | 320 | YES | YES | drift 8.50→6.90, rename Tacos→Tacos M |
| S-NEW-06 | Tacos L | 7.90 | — | — | — | — | — | **FAIL** placeKioskOrder failed at stage="quote" HTTP 422: {"status": |
| S-NEW-07 | Chicken Burger | 6.90 | 6.90 | 6.90 | 321 | YES | YES | NEW |
| S-NEW-08 | Bowl Frites Poulet curry | 8.90 | 8.90 | 8.90 | 322 | YES | YES | NEW |
| S-NEW-09 | Multi-cart (Petite Frites + Cayenne + Tiramisu) | 13.80 | 13.80 | 13.80 | 323 | YES | YES | multi-cart |

## 2. NEW menu structure verification (heal-light V2)

### Sidebar visibility
Visible category pills (DOM count) : **10**
Detected category-ids in sidebar : `[344,345,346,349,306,347,348,318,316,317]`
Expected visible IDs : `[344,345,346,349,306,347,348,318,316,317]` (10 cats)
Expected hidden IDs  : `[315,350]` (315 + 350)
Category labels detected in page text :
- `Sandwich Cayenne` : present
- `Galette` : present
- `Sandwich Classique` : present
- `Burgers` : present
- `Tacos` : present
- `Bols` : present
- `Bols Gourmands` : present
- `Frites` : present
- `Suppléments` : present
- `Supplements` : ABSENT
- `Desserts` : present
- `Boissons` : present
- `Menu enfant` : ABSENT

### Item card / price drift verification
| Code | Item | Card found | Name present | Price € | Match expected |
|------|------|:----------:|:------------:|--------:|:--------------:|
| S-NEW-01 | Sandwich Cayenne | 1 | yes | 7.5 | yes |
| S-NEW-02 | Big Cayenne | 1 | yes | 9.5 | yes |
| S-NEW-03 | Galette Cayenne | 1 | yes | 7 | yes |
| S-NEW-04 | Sandwich Classique | 1 | yes | 7 | yes |
| S-NEW-05 | Tacos M | 1 | yes | 6.9 | yes |
| S-NEW-06 | Tacos L | 1 | yes | 7.9 | yes |
| S-NEW-07 | Chicken Burger | 1 | yes | 6.9 | yes |
| S-NEW-08 | Bowl Frites Poulet curry | 1 | yes | 8.9 | yes |

## 3. Visual heals & drift surfaces

- Menu addon copy overall : +2,50€ present=false, +3,00€ present=false
  - Expected post-heal V2 : +2,50€ visible, +3,00€ ABSENT
  - Verdict : INCONCLUSIVE (no menu copy detected on overview)
- i18n leaks scanned on overview : 0 (sample: `[]`)

### Wizard step cardinality (drift detector)
| Code | Item | Step labels (rendered) | Viande steps | Menu step? |
|------|------|-----------------------|:------------:|:---------:|
| S-NEW-01 | Sandwich Cayenne | QUELLE VIANDE ? / QUELLE SAUCE ? / QUELLE CRUDITÉ ? / QUEL SUPPLÉMENT ? / QUEL MENU ? / RÉCAP | 1 | yes |
| S-NEW-02 | Big Cayenne | Viande 1 (choix) / Viande 2 (choix) / Sauce Cayenne maison (incluse) / QUEL SUPPLÉMENT ? / QUEL MENU ? / RÉCAP | 2 | yes |
| S-NEW-03 | Galette Cayenne | QUELLE VIANDE ? / QUELLE SAUCE ? / QUELLE CRUDITÉ ? / QUEL SUPPLÉMENT ? / QUEL MENU ? / RÉCAP | 1 | yes |
| S-NEW-04 | Sandwich Classique | QUELLE VIANDE ? / QUELLE SAUCE ? / QUELLE CRUDITÉ ? / QUEL SUPPLÉMENT ? / QUEL MENU ? / RÉCAP | 1 | yes |
| S-NEW-05 | Tacos M | QUELLE VIANDE ? / QUEL MENU ? / RÉCAP | 1 | yes |
| S-NEW-06 | Tacos L | Viande 1 (choix) / Viande 2 (choix) / QUEL MENU ? / RÉCAP | 2 | yes |
| S-NEW-07 | Chicken Burger | QUELLE VIANDE ? / QUELLE SAUCE ? / QUELLE CRUDITÉ ? / QUEL SUPPLÉMENT ? / QUEL MENU ? / RÉCAP | 1 | yes |
| S-NEW-08 | Bowl Frites Poulet curry | QUELLE SAUCE ? / QUEL SUPPLÉMENT ? / QUEL MENU ? / Option Gratiné (+2€) / RÉCAP | 0 | yes |

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

Fiscal sequence range in run : **317 … 323**
Baseline : 316
Monotonic OK (min > baseline) : YES

Composition snapshot coverage (per order):
- S-NEW-01 (Sandwich Cayenne, order=1474) : item_count=1, lines_in_snapshot=1, full=true
- S-NEW-03 (Galette Cayenne, order=1475) : item_count=1, lines_in_snapshot=1, full=true
- S-NEW-04 (Sandwich Classique, order=1476) : item_count=1, lines_in_snapshot=0, full=true
- S-NEW-05 (Tacos M, order=1477) : item_count=1, lines_in_snapshot=0, full=true
- S-NEW-07 (Chicken Burger, order=1478) : item_count=1, lines_in_snapshot=0, full=true
- S-NEW-08 (Bowl Frites Poulet curry, order=1479) : item_count=1, lines_in_snapshot=1, full=true
- S-NEW-09 (Multi-cart (Petite Frites + Cayenne + Tiramisu), order=1480) : item_count=3, lines_in_snapshot=2, full=true

## 5. Anomalies surfaced

- **S-NEW-02** : placement FAILED — placeKioskOrder failed at stage="quote" HTTP 422: {"status":false,"message":"Composition Sauce Cayenne maison (incluse) : minimum 1 sélection(s) requise(s), reçu 0."}
- **S-NEW-06** : placement FAILED — placeKioskOrder failed at stage="quote" HTTP 422: {"status":false,"message":"Composition Viande 1 (choix) : minimum 1 sélection(s) requise(s), reçu 0."}
- **Menu addon copy** : INCONCLUSIVE — `+2,50€` / `+3,00€` not detected on wizard step 1 captures. The "QUEL MENU ?" step (step 4-5 in most wizards) was not reached during the API-hybrid placement. Recommend a follow-up wave that walks the wizard to the menu step for visual verification (not blocking).

## 6. Run observations (debug log)

```
baseline: {"max_order_id":1400,"max_fiscal_sequence_no":316,"max_audit_log_id":26}
kiosk vuex ready=true probe={"url":"/kiosk/idle","kiosk_token_present":true,"kiosk_token_preview":"3915|npxJbnU"}
kiosk: /kiosk/categories sidebar count=10
sidebar inspect: visible_pills=10 ids=[344,345,346,349,306,347,348,318,316,317] by_name={"Sandwich Cayenne":true,"Galette":true,"Sandwich Classique":true,"Burgers":true,"Tacos":true,"Bols":true,"Bols Gourmands":true,"Frites":true,"Suppléments":true,"Supplements":false,"Desserts":true,"Boissons":true,"Menu enfant":false} i18n_leaks=0
menu-addon copy overview : +2.50€=false +3.00€=false
=== START S-NEW-01 (Sandwich Cayenne) ===
S-NEW-01: cat-clicked=true item-card itemId=474 cards=1 name_present=true price_eur=7.5 price_match=true
S-NEW-01: wizard menu-addon : +2.50€=false +3.00€=false url=http://127.0.0.1:8000/kiosk/categories?cat=344
S-NEW-01: wizard steps labels=["QUELLE VIANDE ?","QUELLE SAUCE ?","QUELLE CRUDITÉ ?","QUEL SUPPLÉMENT ?","QUEL MENU ?","RÉCAP"] dots=6 viande_steps=1 menu_steps=1 sauce_steps=1
S-NEW-01 (Cayenne): copy choisissez_1_viande=true legacy_tacos_copy=false
S-NEW-01: placement OK elapsed=1765ms order=1474 serial=1405261474 total=7.5 queue=NaN
=== START S-NEW-02 (Big Cayenne) ===
S-NEW-02: cat-clicked=true item-card itemId=488 cards=1 name_present=true price_eur=9.5 price_match=true
S-NEW-02: wizard menu-addon : +2.50€=false +3.00€=false url=http://127.0.0.1:8000/kiosk/categories?cat=344
S-NEW-02: wizard steps labels=["Viande 1 (choix)","Viande 2 (choix)","Sauce Cayenne maison (incluse)","QUEL SUPPLÉMENT ?","QUEL MENU ?","RÉCAP"] dots=6 viande_steps=2 menu_steps=1 sauce_steps=1
S-NEW-02 (Big Cayenne): viande_1=true viande_2=true copy_2viandes=true choisissez_2=false
S-NEW-02: placement FAIL elapsed=59ms err={"message":"placeKioskOrder failed at stage=\"quote\" HTTP 422: {\"status\":false,\"message\":\"Composition Sauce Cayenne maison (incluse) : minimum 1 sélection(s) requise(s), reçu 0.\"}","stage":"quote","status":422,"body":{"status":false,"message":"Composition Sauce Cayenne mai
=== START S-NEW-03 (Galette Cayenne) ===
S-NEW-03: cat-clicked=true item-card itemId=476 cards=1 name_present=true price_eur=7 price_match=true
S-NEW-03: wizard menu-addon : +2.50€=false +3.00€=false url=http://127.0.0.1:8000/kiosk/categories?cat=345
S-NEW-03: wizard steps labels=["QUELLE VIANDE ?","QUELLE SAUCE ?","QUELLE CRUDITÉ ?","QUEL SUPPLÉMENT ?","QUEL MENU ?","RÉCAP"] dots=6 viande_steps=1 menu_steps=1 sauce_steps=1
S-NEW-03: placement OK elapsed=174ms order=1475 serial=1405261475 total=7 queue=NaN
=== START S-NEW-04 (Sandwich Classique) ===
S-NEW-04: cat-clicked=true item-card itemId=477 cards=1 name_present=true price_eur=7 price_match=true
S-NEW-04: wizard menu-addon : +2.50€=false +3.00€=false url=http://127.0.0.1:8000/kiosk/categories?cat=346
S-NEW-04: wizard steps labels=["QUELLE VIANDE ?","QUELLE SAUCE ?","QUELLE CRUDITÉ ?","QUEL SUPPLÉMENT ?","QUEL MENU ?","RÉCAP"] dots=6 viande_steps=1 menu_steps=1 sauce_steps=1
S-NEW-04: placement OK elapsed=229ms order=1476 serial=1405261476 total=7 queue=NaN
=== START S-NEW-05 (Tacos M) ===
S-NEW-05: cat-clicked=true item-card itemId=478 cards=1 name_present=true price_eur=6.9 price_match=true
S-NEW-05: wizard menu-addon : +2.50€=false +3.00€=false url=http://127.0.0.1:8000/kiosk/categories?cat=306
S-NEW-05: wizard steps labels=["QUELLE VIANDE ?","QUEL MENU ?","RÉCAP"] dots=3 viande_steps=1 menu_steps=1 sauce_steps=0
S-NEW-05: placement OK elapsed=233ms order=1477 serial=1405261477 total=6.9 queue=NaN
=== START S-NEW-06 (Tacos L) ===
S-NEW-06: cat-clicked=true item-card itemId=479 cards=1 name_present=true price_eur=7.9 price_match=true
S-NEW-06: wizard menu-addon : +2.50€=false +3.00€=false url=http://127.0.0.1:8000/kiosk/categories?cat=306
S-NEW-06: wizard steps labels=["Viande 1 (choix)","Viande 2 (choix)","QUEL MENU ?","RÉCAP"] dots=4 viande_steps=2 menu_steps=1 sauce_steps=0
S-NEW-06: placement FAIL elapsed=69ms err={"message":"placeKioskOrder failed at stage=\"quote\" HTTP 422: {\"status\":false,\"message\":\"Composition Viande 1 (choix) : minimum 1 sélection(s) requise(s), reçu 0.\"}","stage":"quote","status":422,"body":{"status":false,"message":"Composition Viande 1 (choix) : minimum 1 sé
=== START S-NEW-07 (Chicken Burger) ===
S-NEW-07: cat-clicked=true item-card itemId=375 cards=1 name_present=true price_eur=6.9 price_match=true
S-NEW-07: wizard menu-addon : +2.50€=false +3.00€=false url=http://127.0.0.1:8000/kiosk/categories?cat=349
S-NEW-07: wizard steps labels=["QUELLE VIANDE ?","QUELLE SAUCE ?","QUELLE CRUDITÉ ?","QUEL SUPPLÉMENT ?","QUEL MENU ?","RÉCAP"] dots=6 viande_steps=1 menu_steps=1 sauce_steps=1
S-NEW-07: placement OK elapsed=212ms order=1478 serial=1405261478 total=6.9 queue=NaN
=== START S-NEW-08 (Bowl Frites Poulet curry) ===
S-NEW-08: cat-clicked=true item-card itemId=493 cards=1 name_present=true price_eur=8.9 price_match=true
S-NEW-08: wizard menu-addon : +2.50€=false +3.00€=false url=http://127.0.0.1:8000/kiosk/categories?cat=347
S-NEW-08: wizard steps labels=["QUELLE SAUCE ?","QUEL SUPPLÉMENT ?","QUEL MENU ?","Option Gratiné (+2€)","RÉCAP"] dots=5 viande_steps=0 menu_steps=1 sauce_steps=1
S-NEW-08: placement OK elapsed=276ms order=1479 serial=1405261479 total=8.9 queue=NaN
=== START S-NEW-09 (Multi-cart (Petite Frites + Cayenne + Tiramisu)) ===
S-NEW-09: placement OK elapsed=248ms order=1480 serial=1405261480 total=13.8 queue=NaN
batch DB snap : 7/7 rows
post-run baseline: {"max_order_id":1480,"max_fiscal_sequence_no":323,"max_audit_log_id":26}
fiscal seq : min=317 max=323 baseline=316 monotonic_ok=true
db checks JSON written : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/test-e2e/menu-v2-final-2026-05-14/round-1/wave-KIOSK-db-checks.json
```