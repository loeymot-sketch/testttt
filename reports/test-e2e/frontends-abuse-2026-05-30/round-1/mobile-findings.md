# Mobile (standalone V1 Le Cayenne) — Abuse/Capture E2E findings — Round 1

Date: 2026-05-30  ·  Spec: tests/e2e/test-real-e2e-pagebypage-abuse-mobile-2026-05-30.spec.js
Screenshots: reports/test-e2e/frontends-abuse-2026-05-30/screenshots/mobile/

## Summary
- States captured: 3
- P0: 3  ·  P1: 3  ·  P2: 2  ·  P3: 1

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
    "severity": "P0",
    "state": "02-menu-top",
    "observed": "blank/near-empty screen (innerText len=0)",
    "evidence": "PNG 02-menu-top.png"
  },
  {
    "id": "M-005",
    "severity": "P1",
    "state": "02-menu-top",
    "observed": "expected anchor not visible: button[aria-pressed]:has-text(\"Tout\")",
    "evidence": "PNG 02-menu-top.png"
  },
  {
    "id": "M-006",
    "severity": "P1",
    "state": "02-menu-top",
    "observed": "category chips missing from DOM: SANDWICH CAYENNE, GALETTE, SANDWICH CLASSIQUE, BURGERS, TACOS, BOLS GOURMANDS, FRITES, SUPPLÉMENTS, DESSERTS, BOISSONS, MENU ENFANT",
    "evidence": "document.body.textContent"
  },
  {
    "id": "M-007",
    "severity": "P0",
    "state": "03-menu-scrolled-mid",
    "observed": "blank/near-empty screen (innerText len=0)",
    "evidence": "PNG 03-menu-scrolled-mid.png"
  },
  {
    "id": "M-008",
    "severity": "P0",
    "state": "04-menu-scrolled-bottom",
    "observed": "blank/near-empty screen (innerText len=0)",
    "evidence": "PNG 04-menu-scrolled-bottom.png"
  },
  {
    "id": "M-009",
    "severity": "P1",
    "state": "wiz-sandwich",
    "observed": "could not open Sandwich Cayenne wizard",
    "evidence": "card not visible"
  }
]
```

## Captured states
- 02-menu-top.png
- 03-menu-scrolled-mid.png
- 04-menu-scrolled-bottom.png

## Vision-pass findings (from human multimodal Read of the PNGs)
Vision findings (palette / overflow / truncation / button overlap / wrong-image / empty-state) are
recorded inline in the findings list above (the 00c test registers them). No separate report file.
