# Mobile (standalone V1 Le Cayenne) — Abuse/Capture E2E findings — Round 1

Date: 2026-05-30  ·  Spec: tests/e2e/test-real-e2e-pagebypage-abuse-mobile-2026-05-30.spec.js
Screenshots: reports/test-e2e/frontends-abuse-2026-05-30/screenshots/mobile/

## Summary
- States captured: 76
- P0: 0  ·  P1: 0  ·  P2: 3  ·  P3: 1

## Spec-detected findings (technical sweeps)

```json
[
  {
    "id": "M-001",
    "severity": "P2",
    "state": "12-abuse-qty-incr.png / 11-wiz-menu-cascade-step8.png (recap)",
    "observed": "On tall recaps (sandwich/tacos/burger with full cascade, 8-9 rows) the black QUANTITÉ stepper bar is partially occluded by the sticky \"Ajouter au panier\" CTA — the bar bottom edge is clipped. +/- and qty value remain visible/usable but the bar is visually cut.",
    "evidence": "12-abuse-qty-incr.png + 11-wiz-menu-cascade-step8.png (overlap of QUANTITÉ row and sticky CTA)"
  },
  {
    "id": "M-002",
    "severity": "P2",
    "state": "04-menu-scrolled-bottom.png vs 11-wiz-menu-cascade-step5.png",
    "observed": "Menu catalog list renders generated placeholder-blob illustrations for drinks/supplements/sandwiches, while the in-wizard drink cascade renders REAL product photos for the same products (e.g. Coca/Fanta/Sprite). Two image paths diverge; catalog shows stale/placeholder art. Ties to ULTRAPLAN known image divergence (kiosk photos 2026-05-30 fresh vs mobile assets frozen 2026-05-17).",
    "evidence": "04-menu-scrolled-bottom.png (placeholder blobs) vs 11-wiz-menu-cascade-step5.png (real drink photos)"
  },
  {
    "id": "M-003",
    "severity": "P3",
    "state": "15-cart-empty-state.png",
    "observed": "Empty cart header shows \"0 article · prêt dans ~12 min\" — an ETA is meaningless with nothing to prepare. Cosmetic; empty-state is otherwise high quality (illustration + copy + suggestions CTA).",
    "evidence": "15-cart-empty-state.png subheader"
  },
  {
    "id": "M-004",
    "severity": "P2",
    "state": "19-abuse-double-tap",
    "observed": "double-tap added 2 lines (no debounce on addToCart). before=0 after=2",
    "evidence": "mobile/index.html:171 addToCart setCart([...c, item])"
  }
]
```

## Captured states
- 01-home.png
- 02-menu-top.png
- 03-menu-scrolled-mid.png
- 04-menu-scrolled-bottom.png
- 05-cat-00-tout.png
- 05-cat-01-sandwich-cayenne.png
- 05-cat-02-galette.png
- 05-cat-03-sandwich-classique.png
- 05-cat-04-burgers.png
- 05-cat-05-tacos.png
- 05-cat-06-bols-gourmands.png
- 05-cat-07-frites.png
- 05-cat-08-suppl-ments.png
- 05-cat-09-desserts.png
- 05-cat-10-boissons.png
- 05-cat-11-menu-enfant.png
- 06-wiz-sandwich-step0.png
- 06-wiz-sandwich-step1.png
- 06-wiz-sandwich-step2.png
- 06-wiz-sandwich-step3.png
- 06-wiz-sandwich-step4.png
- 06-wiz-sandwich-step5.png
- 06-wiz-sandwich-step6.png
- 06-wiz-sandwich-step7.png
- 06-wiz-sandwich-recap.png
- 07-wiz-tacos-step0.png
- 07-wiz-tacos-step1.png
- 07-wiz-tacos-step2.png
- 07-wiz-tacos-step3.png
- 07-wiz-tacos-step4.png
- 07-wiz-tacos-step5.png
- 07-wiz-tacos-step6.png
- 07-wiz-tacos-recap.png
- 08-wiz-bol-step0.png
- 08-wiz-bol-step1.png
- 08-wiz-bol-step2.png
- 08-wiz-bol-step3.png
- 08-wiz-bol-recap.png
- 09-wiz-frites-step0.png
- 09-wiz-frites-step1.png
- 09-wiz-frites-recap.png
- 10-direct-add-simple.png
- 11-wiz-menu-cascade-step0.png
- 11-wiz-menu-cascade-step1.png
- 11-wiz-menu-cascade-step2.png
- 11-wiz-menu-cascade-step3.png
- 11-wiz-menu-cascade-step4.png
- 11-wiz-menu-cascade-step5.png
- 11-wiz-menu-cascade-step6.png
- 11-wiz-menu-cascade-step7.png
- 11-wiz-menu-cascade-step8.png
- 12-abuse-qty-walk-step0.png
- 12-abuse-qty-walk-step1.png
- 12-abuse-qty-walk-step2.png
- 12-abuse-qty-walk-step3.png
- 12-abuse-qty-walk-step4.png
- 12-abuse-qty-walk-step5.png
- 12-abuse-qty-walk-step6.png
- 12-abuse-qty-walk-step7.png
- 12-abuse-qty-walk-recap.png
- 12-abuse-qty-floor.png
- 12-abuse-qty-incr.png
- 13-abuse-combo-step0.png
- 13-abuse-combo-step1.png
- 13-abuse-combo-step2.png
- 13-abuse-combo-step3.png
- 13-abuse-combo-step4.png
- 13-abuse-combo-step5.png
- 14-cart-full-multi-line.png
- 15-cart-empty-state.png
- 17-abuse-mid-wizard.png
- 18-abuse-after-back.png
- 19-abuse-double-tap.png
- 21-cart-recap-composition.png
- 22-modal-pay-choice.png
- 23-confirm-counter-payment.png

## Vision-pass findings (from human multimodal Read of the PNGs)
Vision findings (palette / overflow / truncation / button overlap / wrong-image / empty-state) are
recorded inline in the findings list above (the 00c test registers them). No separate report file.
