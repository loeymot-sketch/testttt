export const meta = {
  name: 'golive-vat10-round5-bypass-hunt',
  description: 'Round-5 convergence confirmation: 3 diverse adversarial bypass-hunters prove NO ungated discount-persisting order path remains after the round-4 heal (HEAD 59b13bdec)',
  phases: [{ title: 'BypassHunt' }],
}

const REPO = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt'

const SCHEMA = {
  type: 'object',
  additionalProperties: false,
  properties: {
    angle: { type: 'string' },
    converged: { type: 'boolean', description: 'true if NO ungated discount-persisting order path was found' },
    remaining_ungated_paths: {
      type: 'array',
      items: {
        type: 'object', additionalProperties: false,
        properties: {
          site: { type: 'string', description: 'file:line where a discount is persisted onto an order without a preceding gate' },
          kind: { type: 'string', enum: ['coupon', 'manual', 'loyalty', 'other'] },
          repro: { type: 'string', description: 'concrete request/flag conditions (flag OFF) that reach it' },
          fiscal_z: { type: 'boolean', description: 'can it reach a signed NF525 Z?' },
        },
        required: ['site', 'kind', 'repro', 'fiscal_z'],
      },
    },
    gates_verified: { type: 'array', items: { type: 'string' }, description: 'file:line of each gate confirmed present + correct' },
    notes: { type: 'string' },
  },
  required: ['angle', 'converged', 'remaining_ungated_paths', 'gates_verified', 'notes'],
}

const BASE = `
Repo root: ${REPO}. Current HEAD includes the round-4 heal (59b13bdec).
TASK: adversarially prove whether ANY ungated discretionary-discount path can still persist a discount
onto an ORDER (Order or FrontendOrder, both map to the 'orders' table) with the V1 defaults:
config('pos.manual_discount_enabled')=false AND config('pricing.use_ssot_service')=true.
A path is GATED if config('pos.manual_discount_enabled')!==true causes a refusal (assertDiscretionaryDiscountAllowed /
assertPosManualDiscountAllowed / PosRedemptionService inline gate) BEFORE the discount is persisted, OR the discount
is forced to 0 before persist. Quote-only (OrderQuoteService) and preview-only (PricingPreviewService) and the
pre-redeem reservation (Frontend/LoyaltyController::redeem, order_id=null) are fiscally HARMLESS (no order/Z) — do
NOT report them as ungated fiscal paths.
HARD RULE (anti-hallucination): every claim needs file:line confirmed via grep/Read. converged=false ONLY if you
can show a concrete persist-without-gate with repro. If you cannot, converged=true.
Known gates to confirm: OrderService.php web SSOT (~380, NEW), web else 519, POS SSOT 813, POS else 988,
table SSOT (~1354, NEW), table else 1514; FrontendOrderService.php 502; PosRedemptionService.php 72.
`

phase('BypassHunt')

const ANGLES = [
  `${BASE}\nANGLE A = control-flow per method. For EACH order-creation method (OrderService::myOrderStore,
posOrderStore, tableOrderStore; FrontendOrderService::myOrderStore), read BOTH the if(use_ssot) and else branches
end-to-end and confirm a gate fires before the discount persist in EACH branch. Report any branch that persists a
non-zero discount without a gate.`,
  `${BASE}\nANGLE B = data-flow on the orders table. grep EVERY write that sets a discount onto a persisted order
(Order/FrontendOrder ->discount =, mass-assignment with 'discount', OrderCoupon::create, loyalty ledger that bumps
order discount). For each, trace backwards to confirm a gate dominates it. Watch for whitespace variants in greps
(e.g. '->discount        ='). Report any undominated persist.`,
  `${BASE}\nANGLE C = construct a concrete exploit. With flag OFF + SSOT ON, try to design a real HTTP request (or
sequence) that results in a persisted order with discount>0 — across web online order, QR table order, kiosk order,
POS order, admin loyalty redeem. If you find one, give the exact endpoint + payload. If every avenue is refused
(422) or zeroed before persist, converged=true.`,
]

const hunters = await parallel(ANGLES.map((p, i) =>
  () => agent(p, { label: `bypass-hunt:${['A-controlflow', 'B-dataflow', 'C-exploit'][i]}`, phase: 'BypassHunt', schema: SCHEMA })
))

const ok = hunters.filter(Boolean)
const realRemaining = ok.flatMap(h => (h.remaining_ungated_paths || []).filter(p => p.fiscal_z))
const converged = ok.length === ANGLES.length && ok.every(h => h.converged) && realRemaining.length === 0

log(`Round-5: ${ok.length}/${ANGLES.length} hunters returned; converged=${converged}; remaining fiscal paths=${realRemaining.length}`)

return { converged, realRemaining, hunters: ok }
