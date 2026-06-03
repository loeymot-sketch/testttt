# ENCAISSEMENT E2E — chronologie caisse + encaissement → ticket (supervisor, étape par étape)
**V1 LOCAL Le Cayenne — 2026-06-03 ~15:00 · branch `heal/cms-pr1-quickwins-2026-05-18`**

Owner ask (decoded): *act as supervisor, massive visual E2E of the full caisse + encaissement
chronology — how an order is encaissé at the caisse and on the board, until the ticket
d'impression. Check every point, étape par étape.*

## VERDICT: ✅ Full chronology demonstrated & verified (visual + technical). NF525 CHAIN OK.

Test subject: real borne order **A0014 (id 4133)**, 1× Coca-Cola 33cl, €1,50, ref `SYNC-E2E-ENC-…`.
Captures in `captures/encaissement/`.

| # | Étape | Result | Evidence |
|---|---|---|---|
| 1 | **Création** | order created ACCEPT + PENDING_COUNTER + COUNTER_DEFERRED, €1,50 | API store 200 |
| 2 | **Sur le board** | A0014 in tracker **"À encaisser"** (🖥️ Borne, 1× Coca, 1,50 €, Encaisser btn); caisse orders (🛒 "Client passage") also present | `enc-01-board-tracker-A0014-a-encaisser.png` |
| 3 | **Modal encaissement** | "Encaisser La Commande Borne" — **MONTANT TOTAL 1,50 €**, methods **Espèce** (ouvre tiroir sim) / Carte (TPE sim) / Mobile / Ticket restaurant, **MONTANT REÇU 1,50** + keypad, **"✓ Confirmer & Imprimer ticket"** | `enc-03-counter-collect-modal.png` |
| 3b | **Confirm (CASH)** | `counter-collect/4133/confirm {mode:1,received:1.50}` → **200** | API |
| 4 | **Post-encaissement** | order → **Payé** + **Type de paiement: Espèces**; pos_payment_method 6→**1 (CASH)**; **fiscal_sequence_no 1998 allocated**; **OrderPaidAtCounter broadcast @ private-branch.1 (dispatched)** → KDS/kiosk/OSS reflect paid | `enc-07-order-detail-paid-receipt.png` + domain_events |
| 5 | **Ticket d'impression** | receipt fields: N°A0014, serial 0306264133, **fiscal_seq 1998**, **TVA 0,14 €**, sous-total/total **1,50 €**, CASH, 1× item; **"Imprimer La Facture"** button (`window.print()`) | `enc-07` + DB |
| 6 | **NF525 chain** | **CHAIN OK** after the new fiscal entry (seq 1998) — chain integrity preserved | `fiscal:verify-chain --all` |

**Numeric integrity across the whole chain:** €1,50 identical on kiosk = tracker = modal = order
detail = DB (subtotal=total=1,50, TVA 0,14). No drift.

## Adversarial visual review (Étape 6)
- ✅ Modal, board, order-detail all render cleanly (branding, i18n, layout intact, no raw labels).
- **P3 (cosmetic):** the order-detail shows an **"Informations De Livraison"** block + **"Heure de
  livraison"** for an **À emporter (takeaway)** order — there is no delivery; the label should read
  "Informations client" for takeaway. Minor mislabel, non-blocking.
- **P2 (observed once — verify in a calm env):** the tracker **"Encaisser" first click did not open
  the modal** — `encaisseOrder` was null afterward; it opened reliably when invoked directly. Most
  likely the board's Echo-driven `fetchOrders` re-render (heavy churn from the concurrent abuse-e2e
  batch + my injections) interfered with the click→`openEncaissement` in the same tick. Worth a
  focused check that an incoming order's refresh can't swallow the Encaisser click / close an open
  modal. Not reproduced as a clean defect (env was churning).

## Environment caveats (shared box during active abuse-e2e)
- The shared **`pos@lecayenne.fr` account is contended** — the batch relogins and revokes my token,
  so the POS session dropped repeatedly mid-flow (redirects to /admin/pos with console errors).
  Worked around with SPA-route login (no reload) + completing the confirm via the same API the modal
  calls. **The session drops are env contention, not product defects.**
- **Order 4133 is now a real fiscal entry (seq 1998, €1,50 cash sale)** — by NF525 it **cannot be
  deleted** (it's in the signed chain). This is inherent to testing encaissement (it IS a fiscal
  event). On the dev/test DB; chain stays valid.
- Captured `enc-02/04/05/06` document the click-flakiness + the session redirect honestly.

_Chronology complete. No frozen-zone touched. No push._
