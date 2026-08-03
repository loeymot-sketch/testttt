# Rush-100 Wave A — Kiosk Capture Report

- **Run** : rush-100-2026-05-13
- **Wave** : A (Kiosk)
- **Round** : 1
- **Spec** : tests/e2e/rush-100-kiosk-capture.spec.js
- **Generated** : 2026-05-13T08:04:27.993Z
- **Screenshot dir** : tests/e2e/__screenshots__/rush-100/kiosk/
- **PNG quartets written** : 35 (expected 35)
- **Baseline max order id** : 1329

## Scenario summary

| Scenario | Item | Confirmation | Order id | Fiscal seq # | Total (UI / DB) | item_count | composition_snapshot |
| --- | --- | --- | --- | --- | --- | --- | --- |
| S1 Sandwich Cayenne + menu formule | 474 | NO | no_new_order | — | — / — | — | — |
| S2 Galette Normale + sauce + supp | 475 | NO | no_new_order | — | — / — | — | — |
| S5 Tacos 1v 8.50 | 478 | YES | 1330 | 295 | €11,50 / 11.5 | 1 | all |
| S7 Bol Curry 4-step compose | 480 | NO | no_new_order | — | — / — | — | — |
| S9 Petite Frites + supp | 485 | YES | 1331 | 296 | €2,50 / 2.5 | 1 | all |

## Anomalies observed

- **Console errors / pageerrors** : 31
  - [S1-01-idle.console.json] error: WebSocket connection to 'ws://127.0.0.1:6001/app/app-key?protocol=7&client=js&version=8.4.0&flash=false' failed: Error in connection establishment: net::ERR_CON
  - [S1-01-idle.console.json] error: WebSocket connection to 'ws://127.0.0.1:6001/app/app-key?protocol=7&client=js&version=8.4.0&flash=false' failed: Error in connection establishment: net::ERR_CON
  - [S1-02-categories.console.json] error: WebSocket connection to 'wss://127.0.0.1:6001/app/app-key?protocol=7&client=js&version=8.4.0&flash=false' failed: Error in connection establishment: net::ERR_CO
  - [S1-05-cart.console.json] error: WebSocket connection to 'ws://127.0.0.1:6001/app/app-key?protocol=7&client=js&version=8.4.0&flash=false' failed: Error in connection establishment: net::ERR_CON
  - [S1-05-cart.console.json] error: WebSocket connection to 'wss://127.0.0.1:6001/app/app-key?protocol=7&client=js&version=8.4.0&flash=false' failed: Error in connection establishment: net::ERR_CO
  - [S1-06-payment.console.json] error: WebSocket connection to 'ws://127.0.0.1:6001/app/app-key?protocol=7&client=js&version=8.4.0&flash=false' failed: Error in connection establishment: net::ERR_CON
  - [S2-01-idle.console.json] error: WebSocket connection to 'wss://127.0.0.1:6001/app/app-key?protocol=7&client=js&version=8.4.0&flash=false' failed: Error in connection establishment: net::ERR_CO
  - [S2-01-idle.console.json] error: WebSocket connection to 'ws://127.0.0.1:6001/app/app-key?protocol=7&client=js&version=8.4.0&flash=false' failed: Error in connection establishment: net::ERR_CON
  - … 23 more
- **Network 4xx/5xx (unallowlisted)** : 0

## Observations log (timing / missing testids / runtime notes)

```
baseline_max_order_id=1329
=== START S1 (Sandwich Cayenne + menu formule) item=474 cat=344 ===
S1: before_max_id=1329
S1: side_cat_344_visible=true
S1: card_474_visible=true
S1: walkWizard step 0 opt log=["viande:kiosk-viande-card is-selectable"]
S1: walkWizard step 1 opt log=["option:kiosk-option-card"]
S1: walkWizard step 2 no-option (no_candidate) log=["garniture:already_selected"]
S1: walkWizard step 3 opt log=["supplement:kiosk-supplement-row is-selectable"]
S1: walkWizard step 4 opt log=["menu:kiosk-menu-card"]
S1: walkWizard step 4 pass2 log=[".kiosk-boisson-card"]
S1: walkWizard step 4 no-advance CTA found — abort
S1: walkResult={"closed":false,"steps":4,"reason":"no_advance"}
S1: cart={"root_present":false,"line_count":0,"subtotal":null,"total":null,"empty_visible":false}
S1: payment_reached=false
S1: card_btn=false cash_btn=false
S1: confirmation={"confirmation_visible":false,"cash_instruction_visible":false,"number":null,"total":null,"url_path":"/kiosk/cart"} surface=null
S1: db={"error":"no_new_order","after_id":1329}
=== END S1 === url=http://127.0.0.1:8000/kiosk/idle S1-474
=== START S2 (Galette Normale + sauce + supp) item=475 cat=345 ===
S2: before_max_id=1329
S2: side_cat_345_visible=true
S2: card_475_visible=true
S2: walkWizard step 0 opt log=["viande:kiosk-viande-card is-selectable"]
S2: walkWizard step 1 opt log=["option:kiosk-option-card"]
S2: walkWizard step 2 no-option (no_candidate) log=["garniture:already_selected"]
S2: walkWizard step 3 opt log=["supplement:kiosk-supplement-row is-selectable"]
S2: walkWizard step 4 opt log=["menu:kiosk-menu-card"]
S2: walkWizard step 4 pass2 log=[".kiosk-boisson-card"]
S2: walkWizard step 4 no-advance CTA found — abort
S2: walkResult={"closed":false,"steps":4,"reason":"no_advance"}
S2: cart={"root_present":false,"line_count":0,"subtotal":null,"total":null,"empty_visible":false}
S2: payment_reached=false
S2: card_btn=false cash_btn=false
S2: confirmation={"confirmation_visible":false,"cash_instruction_visible":false,"number":null,"total":null,"url_path":"/kiosk/cart"} surface=null
S2: db={"error":"no_new_order","after_id":1329}
=== END S2 === url=http://127.0.0.1:8000/kiosk/idle S2-475
=== START S5 (Tacos 1v 8.50) item=478 cat=306 ===
S5: before_max_id=1329
S5: side_cat_306_visible=true
S5: card_478_visible=true
S5: walkWizard step 0 opt log=["viande:kiosk-viande-card is-selectable"]
S5: walkWizard step 1 opt log=["menu:kiosk-menu-card"]
S5: walkWizard step 1 pass2 log=[".kiosk-boisson-card"]
S5: walkWizard step 2 no-option (no_candidate) log=[]
S5: walkWizard closed after step 3
S5: walkResult={"closed":true,"steps":3}
S5: cart={"root_present":true,"line_count":1,"subtotal":"€11,50","total":"€11,50","empty_visible":false}
S5: payment_reached=true
S5: card_btn=true cash_btn=true
S5: card confirm clicked
S5: confirmation={"confirmation_visible":true,"cash_instruction_visible":false,"number":"#A0007","total":"€11,50","url_path":"/kiosk/confirmation"} surface=url-match
S5: db={"id":1330,"fiscal_sequence_no":295,"total":11.5,"order_status":null,"payment_status":5,"order_type":10,"source":"5","item_count":1,"has_composition_snapshot":"all","created_at":"2026-05-13 10:03:28","token":""}
=== END S5 === url=http://127.0.0.1:8000/kiosk/idle S5-478
=== START S7 (Bol Curry 4-step compose) item=480 cat=347 ===
S7: before_max_id=1330
S7: side_cat_347_visible=true
S7: card_480_visible=true
S7: walkWizard step 0 opt log=["generic:kiosk-generic-choice"]
S7: walkWizard step 1 opt log=["option:kiosk-option-card"]
S7: walkWizard step 2 opt log=["supplement:kiosk-supplement-row is-selectable"]
S7: walkWizard step 3 opt log=["menu:kiosk-menu-card"]
S7: walkWizard step 3 pass2 log=[".kiosk-boisson-card"]
S7: walkWizard step 3 no-advance CTA found — abort
S7: walkResult={"closed":false,"steps":3,"reason":"no_advance"}
S7: cart={"root_present":false,"line_count":0,"subtotal":null,"total":null,"empty_visible":false}
S7: payment_reached=false
S7: card_btn=false cash_btn=false
S7: confirmation={"confirmation_visible":false,"cash_instruction_visible":false,"number":null,"total":null,"url_path":"/kiosk/cart"} surface=null
S7: db={"error":"no_new_order","after_id":1330}
=== END S7 === url=http://127.0.0.1:8000/kiosk/idle S7-480
=== START S9 (Petite Frites + supp) item=485 cat=348 ===
S9: before_max_id=1330
S9: side_cat_348_visible=true
S9: card_485_visible=true
S9: walkWizard step 0 opt log=["generic:kiosk-generic-choice"]
S9: walkWizard step 1 no-option (no_candidate) log=[]
S9: walkWizard closed after step 2
S9: walkResult={"closed":true,"steps":2}
S9: cart={"root_present":false,"line_count":0,"subtotal":null,"total":null,"empty_visible":false}
S9: payment_reached=true
S9: card_btn=true cash_btn=true
S9: card confirm clicked
S9: confirmation={"confirmation_visible":true,"cash_instruction_visible":false,"number":"#A0008","total":"€2,50","url_path":"/kiosk/confirmation"} surface=url-match
S9: db={"id":1331,"fiscal_sequence_no":296,"total":2.5,"order_status":null,"payment_status":5,"order_type":10,"source":"5","item_count":1,"has_composition_snapshot":"all","created_at":"2026-05-13 10:04:21","token":""}
=== END S9 === url=http://127.0.0.1:8000/kiosk/idle S9-485
```

## Artifacts

- DB checks JSON : `reports/test-e2e/rush-100-2026-05-13/round-1/wave-A-db-checks.json`
- Screenshot quartets : `tests/e2e/__screenshots__/rush-100/kiosk/<scenario>-<NN>-<state>.{png,dom.html,console.json,network.json}`