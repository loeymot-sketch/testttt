# W4 — Builder↔Borne integration + sync — CONVERGENCE (GOAL_WIZARD_E2E_PARITY)

Date: 2026-06-09 · Harness: :8766 disposable clone (`foodking_e2e`). All mutations REVERTED → clone clean.
Owner asks covered here (advisor-flagged, were uncovered by W2): **"the modification of each wizard
page"** + **"the image update"** + runtime **synchronisation borne→KDS**.

Deterministic method: the kiosk renders from `GET /api/frontend/item/details/{id}` →
`composer_profile.steps[].choices` — that projection IS the borne's data source, so "projection shows
X" == "borne shows X". Builder mutations via the W1 admin endpoints (create/update + publish).

## (a) CREATE a page in the builder → publish → borne shows it ✓
`POST …/personal-page` "TEST-PARITY Page" (2 options) → 201 (step 188) → publish → borne projection
steps: `[sauce bol, supplement_bol, drink, TEST-PARITY Page]` (`borneShowsNewPage=true`). Cleanup:
delete step + extras → back to `[sauce bol, supplement_bol, drink]`.

## (b) MODIFY a page (add option) → publish → borne shows the new option set ✓
`PUT …/personal-page/24` add "TEST-PARITY Extra" to the 5 supplément options → borne projection
supplément choices = 6 (incl. the new one). Revert → back to 5. Note: the projection reads the LIVE
profile/extras, so the change appears **immediately**; `publish` updates `published_at` (snapshot bump)
— change was visible both pre- and post-publish.

## (c) IMAGE update → publish → borne shows the new image ✓
`PUT …/personal-page/24` set Oignon frais `image_path='images/menu/champignons.png'`:
- before: `…/oignons-frits.png` (legacy name→config map)
- after override + publish: `…/champignons.png` (builder per-option image WINS — `ItemExtra::getThumbAttribute` prefers a resolvable `image_path` over the name map)
- after revert (`image_path=null`): `…/oignons-frits.png` again
Builder per-option images propagate to the borne and override the legacy convention map.

## (d) Runtime sync borne→KDS (composition flows to the kitchen) ✓
Kiosk order **#4313** (built via the wizard: sauce 202 + Boule gratinée 180, total 10.90) appears on
`GET /api/admin/kds-order/` (status 4) carrying `composition_snapshot` + `item_extras` +
`item_variations`. Kitchen-readable names confirmed: sauce **"Sauce fromagère maison"**, extra
**"Boule gratinée"**. The wizard composition reaches the KDS intact.

## Notes
- All builder mutations were reverted; the 6 category wizards + item 41 profile are back to their
  recorded state. NB (adversarial P3): the re-edit endpoint's removal is a SOFT-delete, so reverting
  left a soft-deleted `TEST-PARITY Extra` row (live count correctly 5); it was subsequently hard-deleted
  so the clone is now fully clean (`leftover=0, supplément active=5`).
- Definition-sync model: the borne projection is live (no stale-cache gap); publish writes the
  `published_at` snapshot timestamp. Runtime order sync borne→KDS confirmed for wizard-composed orders.
- Caisse leg of "synchronized borne AND caisse" is W3 — GATED (see GATE-W6 surface below).

## Verdict: W4 GREEN — P0+P1 = 0. Builder→borne create/modify/image all propagate; order→KDS
composition intact. The owner's "modification of each page" + "image update" asks are covered.
