# Page 8 — POS Parked Orders — Evidence

**Verdict** : BLOCKED
**Blocking finding** : PG4-P0-001
**Subfinding** : PG8-P3-001 (downstream_blocked, correct UX)
**State captured** : `08-pos-parked-after-park`

## Capture quartet

- PNG : `tests/e2e/__screenshots__/goal-pageby-pos-2026-05-18/08-pos-parked-after-park.png`
- DOM : `.dom.html`
- Console : `.console.json`
- Network : `.network.json`

## Visual analysis

After clicking the "Mettre en attente" button on the empty cart, a blue toast appears at top-right :

> **"Ajoutez au moins un article avant de parker cette commande."**

This is a **correct UX behaviour** — the park endpoint legitimately rejects an empty park request. The toast is informative French i18n, clear instruction. Not a defect.

## Technical analysis

- Park CTA click → frontend pre-validation rejects (no API call needed)
- Console : 1 info entry only (no errors)
- Network : `[]`
- Toast styling : blue informative (not red error), correctly differentiated from the upstream PG4-P0-001 error toast.

## Audit when unblocked

Once PG4-P0-001 healed and cart contains an item :
- Click "Mettre en attente" → expect successful park (POST /api/admin/pos/parked-orders, status 201)
- Cart should clear, parked list should increment to (1)
- Click "Commandes en attente" button → list of parked tickets should render
- Resume one → wizard / cart restored

Also worth checking adjacency to Round 2 commit `606b7aaa7` P0-POS-04 (Admin branch_id=0 → 403 on parked endpoints). Confirmed POS Operator branch_id=1 (verified via tinker) so not blocked by that gate.

## Verdict

BLOCKED-DOWNSTREAM. UX correct. Re-attest once PG4-P0-001 healed.
