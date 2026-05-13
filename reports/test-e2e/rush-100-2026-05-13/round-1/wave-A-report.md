# Rush-100 Wave A — Kiosk Capture Report

- **Run** : rush-100-2026-05-13
- **Wave** : A (Kiosk)
- **Round** : 1
- **Spec** : tests/e2e/rush-100-kiosk-capture.spec.js
- **Generated** : 2026-05-13T07:45:11.096Z
- **Screenshot dir** : tests/e2e/__screenshots__/rush-100/kiosk/
- **PNG quartets written** : 35 (expected 35)
- **Baseline max order id** : 1321

## Scenario summary

| Scenario | Item | Confirmation | Order id | Fiscal seq # | Total (UI / DB) | item_count | composition_snapshot |
| --- | --- | --- | --- | --- | --- | --- | --- |
| S1 Sandwich Cayenne + menu formule | 474 | NO | no_new_order | — | — / — | — | — |
| S2 Galette Normale + sauce + supp | 475 | NO | no_new_order | — | — / — | — | — |
| S5 Tacos 1v 8.50 | 478 | NO | no_new_order | — | — / — | — | — |
| S7 Bol Curry 4-step compose | 480 | NO | no_new_order | — | — / — | — | — |
| S9 Petite Frites + supp | 485 | NO | no_new_order | — | — / — | — | — |

## Anomalies observed

- **Console errors / pageerrors** : 21
  - [S1-01-idle.console.json] error: WebSocket connection to 'ws://127.0.0.1:6001/app/app-key?protocol=7&client=js&version=8.4.0&flash=false' failed: Error in connection establishment: net::ERR_CON
  - [S1-01-idle.console.json] error: WebSocket connection to 'ws://127.0.0.1:6001/app/app-key?protocol=7&client=js&version=8.4.0&flash=false' failed: Error in connection establishment: net::ERR_CON
  - [S1-02-categories.console.json] error: WebSocket connection to 'wss://127.0.0.1:6001/app/app-key?protocol=7&client=js&version=8.4.0&flash=false' failed: Error in connection establishment: net::ERR_CO
  - [S1-05-cart.console.json] error: WebSocket connection to 'ws://127.0.0.1:6001/app/app-key?protocol=7&client=js&version=8.4.0&flash=false' failed: Error in connection establishment: net::ERR_CON
  - [S1-05-cart.console.json] error: WebSocket connection to 'wss://127.0.0.1:6001/app/app-key?protocol=7&client=js&version=8.4.0&flash=false' failed: Error in connection establishment: net::ERR_CO
  - [S1-06-payment.console.json] error: WebSocket connection to 'ws://127.0.0.1:6001/app/app-key?protocol=7&client=js&version=8.4.0&flash=false' failed: Error in connection establishment: net::ERR_CON
  - [S2-01-idle.console.json] error: WebSocket connection to 'wss://127.0.0.1:6001/app/app-key?protocol=7&client=js&version=8.4.0&flash=false' failed: Error in connection establishment: net::ERR_CO
  - [S2-01-idle.console.json] error: WebSocket connection to 'ws://127.0.0.1:6001/app/app-key?protocol=7&client=js&version=8.4.0&flash=false' failed: Error in connection establishment: net::ERR_CON
  - … 13 more
- **Network 4xx/5xx (unallowlisted)** : 0

## Observations log (timing / missing testids / runtime notes)

```
baseline_max_order_id=1321
=== START S1 (Sandwich Cayenne + menu formule) item=474 cat=344 ===
S1: before_max_id=1324
S1: side_cat_344_visible=true
S1: card_474_visible=true
S1: walkWizard step 0 no-option (no_candidate)
S1: walkWizard step 0 no-advance CTA found — abort
S1: walkResult={"closed":false,"steps":0,"reason":"no_advance"}
S1: cart={"root_present":false,"line_count":0,"subtotal":null,"total":null,"empty_visible":false}
S1: payment_reached=false
S1: card_btn=false cash_btn=false
S1: confirmation={"visible":false,"number":null,"total":null}
S1: db={"error":"no_new_order","after_id":1324}
=== END S1 === url=http://127.0.0.1:8000/kiosk/idle S1-474
=== START S2 (Galette Normale + sauce + supp) item=475 cat=345 ===
S2: before_max_id=1324
S2: side_cat_345_visible=true
S2: card_475_visible=true
S2: walkWizard step 0 no-option (no_candidate)
S2: walkWizard step 0 no-advance CTA found — abort
S2: walkResult={"closed":false,"steps":0,"reason":"no_advance"}
S2: cart={"root_present":false,"line_count":0,"subtotal":null,"total":null,"empty_visible":false}
S2: payment_reached=false
S2: card_btn=false cash_btn=false
S2: confirmation={"visible":false,"number":null,"total":null}
S2: db={"error":"no_new_order","after_id":1324}
=== END S2 === url=http://127.0.0.1:8000/kiosk/idle S2-475
=== START S5 (Tacos 1v 8.50) item=478 cat=306 ===
S5: before_max_id=1324
S5: side_cat_306_visible=true
S5: card_478_visible=true
S5: walkWizard step 0 no-option (no_candidate)
S5: walkWizard step 0 no-advance CTA found — abort
S5: walkResult={"closed":false,"steps":0,"reason":"no_advance"}
S5: cart={"root_present":false,"line_count":0,"subtotal":null,"total":null,"empty_visible":false}
S5: payment_reached=false
S5: card_btn=false cash_btn=false
S5: confirmation={"visible":false,"number":null,"total":null}
S5: db={"error":"no_new_order","after_id":1324}
=== END S5 === url=http://127.0.0.1:8000/kiosk/idle S5-478
=== START S7 (Bol Curry 4-step compose) item=480 cat=347 ===
S7: before_max_id=1324
S7: side_cat_347_visible=true
S7: card_480_visible=true
S7: walkWizard step 0 no-option (no_candidate)
S7: walkWizard step 0 no-advance CTA found — abort
S7: walkResult={"closed":false,"steps":0,"reason":"no_advance"}
S7: cart={"root_present":false,"line_count":0,"subtotal":null,"total":null,"empty_visible":false}
S7: payment_reached=false
S7: card_btn=false cash_btn=false
S7: confirmation={"visible":false,"number":null,"total":null}
S7: db={"error":"no_new_order","after_id":1324}
=== END S7 === url=http://127.0.0.1:8000/kiosk/idle S7-480
=== START S9 (Petite Frites + supp) item=485 cat=348 ===
S9: before_max_id=1324
S9: side_cat_348_visible=true
S9: card_485_visible=true
S9: walkWizard step 0 no-option (no_candidate)
S9: walkWizard step 0 no-advance CTA found — abort
S9: walkResult={"closed":false,"steps":0,"reason":"no_advance"}
S9: cart={"root_present":false,"line_count":0,"subtotal":null,"total":null,"empty_visible":false}
S9: payment_reached=false
S9: card_btn=false cash_btn=false
S9: confirmation={"visible":false,"number":null,"total":null}
S9: db={"error":"no_new_order","after_id":1324}
=== END S9 === url=http://127.0.0.1:8000/kiosk/idle S9-485
```

## Artifacts

- DB checks JSON : `reports/test-e2e/rush-100-2026-05-13/round-1/wave-A-db-checks.json`
- Screenshot quartets : `tests/e2e/__screenshots__/rush-100/kiosk/<scenario>-<NN>-<state>.{png,dom.html,console.json,network.json}`