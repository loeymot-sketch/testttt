# Wave A — Adversarial Review (Round 1)

**Run** : rush-100-2026-05-13 | **Verdict** : NO-GO (3 P0 + 5 P1 open) | **States reviewed** : 35

The GStack capture self-report claimed "0 unallowlisted 4xx/5xx" and 21 pusher-only console errors. False clean. Visual + cross-artifact inspection of the 35 quartets surfaced 12 findings (3 P0 / 5 P1 / 4 P2).

**P0 blockers** :
1. **WA-R1-05 / WA-R1-06 — Unexpected 422 on /api/frontend/pricing/preview** for Bol Curry (S7) AND Petite Frites (S9) at composer-step open. Protocol allowlist only covers form-validation 422; this is an AJAX preview rejection. NF525 pricing SSOT contract is violated — kiosk silently falls back to local pricing and tells user "Tarif rafraîchi localement, vérifié au paiement", masking the backend reject.
2. **WA-R1-07 — Numeric integrity S9** : kiosk reached visual confirmation #A0003 / 2,50 € / cash-at-counter, but the spec DB check reports `no_new_order` (after_id == before_max_id == 1324). Either the kiosk lies (confirmation drawn without persistence) or the DB query reads the wrong column/table. Note: the other 4 scenarios also show `no_new_order` but those flows ended on an empty-cart screen — that is expected. S9 is the unique anomaly.

**P1** :
- WA-R1-01 / 02 — **i18n leak** : "Votre tacos comprend 1 viande" rendered on Sandwich Cayenne (S1) AND Galette Normale (S2) wizards. Hardcoded literal "tacos" in shared meat-step component.
- WA-R1-03 / 04 — **Aria/affordance missing** : composer cards (Frites/Riz on Bol Curry, Nature/Cheddar on Petite Frites) have no +, no CHOISIR, no badge. Cards look like static labels — blocks primary task path.
- WA-R1-08 — **Spec quality** : walkWizard helper mislabels states. S1-05-cart actually shows QUEL MENU wizard, S1-06-payment / S2-06-payment / S5-06-payment / S7-06-payment all show empty cart. 60%+ of artifact filenames don't match content.

**P2** (4) : duplicate category thumbnails (cover.png on 9 categories), toast-vs-nav overlap, missing aria-describedby on composer cards, empty-cart route guard missing.

**Cross-validation of orchestrator's pre-known findings** : VS-A-01, VS-A-02, VS-A-03, VS-A-04, VS-A-05 all CONFIRMED via direct PNG / DOM / network inspection.

Next round must close P0 #5/#6/#7 and P1 #8 (spec re-run) before any GO declaration.
