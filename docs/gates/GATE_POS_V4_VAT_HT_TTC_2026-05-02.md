# Gate Brief – POS-V4-VAT-HT-TTC – 2026-05-02

## Trigger

Cashier complaint surfaced on 2026-05-02 :

> « le prix de tout en bas il est écrit total HT pourquoi, on met hors-taxes
> alors que le prix qui est vraiment au panier c'est le prix qui TTC parce
> que toutes les produits sont en pré TTC … en France emporter 5 % ou bien
> 5,5 % et pour le sur place 10 % normalement faudra bien vérifier ça et le
> mettre correctement »

Two distinct fiscal concerns are entangled here :

1. **Catalog price semantic** : are the values stored in `items.price` HT
   (ex-tax) or TTC (incl-tax) ? Today the **backend pricing math assumes HT**
   (cf. `app/Services/Pricing/PricingService.php:321`) :
   ```
   $rawTotal = $realSubtotal + $totalTax + $delivery - $calculatedDiscount;
   ```
   `$realSubtotal` is the sum of `item.price * qty`. Tax is added **on top**.
   If the operator typed prices as TTC into the catalog, the system silently
   produces an **inflated final order total** (TTC + TVA again).

2. **Per-order-type tax rate** : French food law requires
   - 5,5 % (TVA réduite) for **takeaway / vente à emporter**
   - 10 % (TVA intermédiaire) for **dine-in / sur place**
   On the same product. Today `items.tax_id` is a **single** FK pointing to one
   `taxes` row → `tax_rate`. There is no resolver from `OrderType` →
   `tax_rate`. Whatever is configured on the item is applied uniformly.

## Affected Subsystems

- `app/Services/Pricing/PricingService.php` (line tax + final total)
- `app/Services/Pricing/TaxCalculator.php` (`lineTaxAmount` signature
  explicitly takes `lineSubtotalExTax`)
- `app/Services/Order/OrderQuoteService.php` (quote SSOT — `total_ttc` field)
- `app/Services/Fiscal/ZReportService.php` (NF525 daily aggregates : `total_ht` /
  `total_ttc` columns on `z_reports` table — relied on by the fiscal export)
- `app/Models/OrderQuote.php`, `app/Models/ZReport.php` (decimal columns)
- `database/migrations/*` — adding a tax-resolution table or a per-item
  HT/TTC flag would be a **schema change** (hard gate)
- `resources/js/components/admin/pos/ReceiptComponent.vue` and the kiosk
  receipt builder (`resources/js/helpers/posReceiptBuilder.js`) — display
  semantics must stay coherent with the engine
- `resources/js/components/admin/items/ItemCreateComponent.vue` — UI for
  declaring catalog prices

## Invariants at Risk

- **Backend pricing is SSOT** (`.cursor/rules/project-invariants.mdc §1`) :
  any change here is a top-shelf invariant decision.
- **`OrderStatus` enum is authoritative** : not directly at risk, but the
  fiscal Z report aggregates orders by status — semantic of `total_ht` /
  `total_ttc` must remain stable for closed days.
- **Frozen Zone** : `OrderService` / `FrontendOrderService` symmetry — both
  call into `PricingService::calculateOrder` ; any change must be mirrored.
- **NF525 fiscal contract** (cf. `composition_snapshot` immutability,
  `z_reports.total_ht` / `total_ttc` historical columns) : changing the
  semantic of `items.price` retroactively would invalidate every closed day.

## Decision Required

Two binary decisions must be confirmed by a human operator with French
fiscal context :

### Q1. What does `items.price` represent, today, in this deployment ?

- **Option A** — HT (legacy assumption, what the code does today). Then the
  cashier UX must clearly show that catalog prices are HT, the prep team
  must keep typing HT, and the displayed cart total can keep saying « (HT) ».
- **Option B** — TTC (what the operator believes today). Then the engine
  is silently double-charging TVA on every closed order and **all closed
  Z reports since the catalog was switched to TTC entry are wrong**. This is
  a critical fiscal regression that needs human triage : either re-statement
  of historical data, or a forward-only repair with a clear cut-off date.

### Q2. Should TVA become **OrderType-aware** (5,5 % takeaway / 10 % dine-in) ?

- **Option A** — No. Keep one tax rate per item ; operator picks the right
  rate at item creation knowing it applies uniformly. Simpler but not
  conformant to French food-service practice.
- **Option B** — Yes. Add a resolver from `OrderType` → `tax_rate`.
  Implementation requires : a new `tax_profiles` table (or extending `taxes`)
  with two rates (`takeaway_rate`, `dine_in_rate`), a migration to populate
  it, and a change in `PricingService` to resolve the rate from
  `PricingRequest::orderType` instead of directly from `items.tax_id`. This
  is a **schema migration** (hard gate per `human-gates.mdc`) and an EXECUTE
  cycle on its own.

## Options summary

1. **A1 + A2** — keep status quo (HT, single rate). Fix only the cart label
   (already done in this density cycle) ; document for the operator that
   catalog values must be entered HT. Lowest risk.
2. **B1 + A2** — switch catalog semantic to TTC, single rate. Requires :
   (a) per-line « inverse tax » math in `PricingService` (`unitPrice / (1+rate)`),
   (b) fiscal cut-off date, (c) one-off recompute job for closed orders OR a
   marker `prices_include_tax` per fiscal period. Schema migration likely.
3. **B1 + B2** — full alignment with French food law : TTC catalog +
   per-OrderType rate. Largest scope, but the only one that matches the
   regulatory baseline of 5,5 % / 10 %. Schema migration required.
4. **Cancel** — keep the system as-is and document the cashier UX with a
   clear advisory : catalog prices are HT until a fiscal cycle is run.

## Recommended path

Start with a 30-minute fact-finding session :
- Pull a sample of 5 catalog items the operator considers as « prix TTC ».
- Open `tax_id` for each, look up the `tax_rate` ; verify by hand whether the
  receipt total matches what the customer paid at the till.
- If the receipts already match the customer's expected TTC price, the
  system is silently doing the right thing (probably tax_rate = 0 on those
  items, i.e. TVA is not actually being added). Then this is a UX/labelling
  fix only, no schema change.
- If the receipts are **higher** than expected, this is option B and we need
  a fiscal triage (option 2 or 3 above).

The density cycle (POS-V4-DENSITY-VAT-2026-05-02) ships :
- The cart label « (HT) » is removed (no longer asserted)
- This gate brief is created
- No pricing math, no schema change

## Approval

[ ] Approved — option selected: ___
[ ] Cancelled
Approved by: ___
Date: ___
