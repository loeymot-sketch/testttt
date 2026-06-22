export const meta = {
  name: 'golive-vat10-round4-convergence',
  description: 'Round-4 EXHAUSTIVE enumeration + adversarial verification of the VAT-10 / discretionary-discount F1-dormancy go-live heal (FoodKing Le Cayenne V1)',
  phases: [
    { title: 'Enumerate' },
    { title: 'Verify' },
    { title: 'Synthesize' },
  ],
}

// ── Shared schema for an enumeration lens ────────────────────────────────────
const ENUM_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  properties: {
    lens: { type: 'string' },
    entry_points: {
      type: 'array',
      items: {
        type: 'object',
        additionalProperties: false,
        properties: {
          site: { type: 'string', description: 'file:line of the discount/coupon/loyalty/vat/ui site' },
          kind: { type: 'string', enum: ['discount-assign', 'coupon-write', 'loyalty-ledger', 'order-create', 'vat', 'ui', 'frozen', 'other'] },
          gated: { type: 'boolean', description: 'true if this site is preceded/protected by the manual_discount_enabled gate (or PosRedemptionService inline gate)' },
          gate_site: { type: 'string', description: 'file:line of the protecting gate, or "" if none' },
          fiscal_z_relevant: { type: 'boolean', description: 'true if reaching this site with a non-zero discount can sign a fiscally-incorrect NF525 Z (i.e. it applies a discount to a persisted ORDER)' },
          verdict: { type: 'string', enum: ['gated-ok', 'ungated-fiscal-RISK', 'ungated-harmless', 'n/a'] },
          evidence: { type: 'string', description: 'grep/Read proof: exact code excerpt or command output confirming the claim' },
        },
        required: ['site', 'kind', 'gated', 'gate_site', 'fiscal_z_relevant', 'verdict', 'evidence'],
      },
    },
    new_findings: {
      type: 'array',
      items: {
        type: 'object',
        additionalProperties: false,
        properties: {
          severity: { type: 'string', enum: ['P0', 'P1', 'P2', 'P3'] },
          title: { type: 'string' },
          site: { type: 'string' },
          repro: { type: 'string' },
          fiscal: { type: 'boolean' },
        },
        required: ['severity', 'title', 'site', 'repro', 'fiscal'],
      },
    },
    notes: { type: 'string' },
  },
  required: ['lens', 'entry_points', 'new_findings', 'notes'],
}

const VERDICT_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  properties: {
    finding: { type: 'string' },
    real: { type: 'boolean' },
    fiscal_blocker: { type: 'boolean' },
    reasoning: { type: 'string' },
    evidence: { type: 'string' },
  },
  required: ['finding', 'real', 'fiscal_blocker', 'reasoning', 'evidence'],
}

const REPO = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt'

const RULES = `
HARD RULES (FoodKing CLAUDE.md §3ter anti-hallucination):
- You MUST cite file:line for every claim and confirm via grep/Read. A finding without file:line proof is REJECTED.
- Repo root: ${REPO}
- The "gate" = the discretionary-discount master flag config('pos.manual_discount_enabled'). When false (V1 default),
  any non-zero discount applied to a persisted order must be refused (422). Enforcement points already known:
  OrderService::assertDiscretionaryDiscountAllowed (~2810) called at lines 519/813/988/1514;
  OrderService::assertManualDiscountAllowed (~2835); FrontendOrderService::assertDiscretionaryDiscountAllowed (~803)
  called at line 502; PosRedemptionService::applyToOrder inline gate (~72).
- F1 premise: at a non-zero VAT rate the FROZEN PricingService/ZReportService compute per-line TVA on the
  PRE-discount base, so a discounted order signs a fiscally-incorrect NF525 Z. VERIFY this premise against the
  actual frozen code — do not assume it.
- A loyalty PRE-redeem that only reserves points (pending LoyaltyTransaction, order_id=null, no order created)
  is fiscally HARMLESS (no Z). Classify such sites ungated-harmless, NOT a fiscal blocker.
- Convergence = a COMPLETE list of every discount/coupon/loyalty entry point, each proven gated or proven harmless.
  Do NOT stop at "nothing new" — produce the exhaustive list.
`

// ════════════════ PHASE 1 — EXHAUSTIVE ENUMERATION (parallel) ════════════════
phase('Enumerate')

const LENSES = [
  {
    key: 'discount-assign',
    prompt: `${RULES}\nLENS = discount-assign. Enumerate EVERY site in app/ that assigns a discount onto a persisted order
(grep for "->discount =", "'discount' =>" on Order/FrontendOrder/OrderQuote, and any pricing-result discount).
For each, determine whether a manual_discount_enabled gate executes BEFORE the value can be persisted, and whether
it is fiscal-Z relevant. Prove gating by showing the gate call-site line < the assignment line in the same method.`,
  },
  {
    key: 'coupon-write',
    prompt: `${RULES}\nLENS = coupon-write. Enumerate EVERY OrderCoupon write (OrderCoupon::create/forceCreate, ->orderCoupons()->create)
and every coupon-application path (CouponService, PricingService coupon discount) in app/. Prove each OrderCoupon
write is preceded by a gate, or flag as ungated-fiscal-RISK with repro.`,
  },
  {
    key: 'loyalty-ledger',
    prompt: `${RULES}\nLENS = loyalty-ledger. Enumerate EVERY loyalty redeem / points-decrement / LoyaltyTransaction write in app/
(FrontendOrderService kiosk loyalty, PosRedemptionService, Frontend/LoyaltyController redeem/pre-redeem, kiosk
LoyaltyController, any cron refund). For EACH, classify: does it apply a discount to a persisted ORDER (fiscal-Z
relevant → must be gated) or only reserve/refund points (fiscally harmless)? Pay special attention to
/api/frontend/loyalty/redeem (Frontend/LoyaltyController) — confirm whether it is gated and whether it can strand
points when the order path refuses. Note the status=1 vs Status::ACTIVE(5) lookup quirk in FrontendOrderService.`,
  },
  {
    key: 'vat10',
    prompt: `${RULES}\nLENS = vat10 (B1). Verify the 10% VAT-inclusive (TTC) configuration is correct and prices are unchanged:
check config/pricing.php tax_inclusive_prices, the Tax rows / seeders for the 45 Le Cayenne items, and
TaxCalculator::lineTaxAmountFromTTC. CRUCIALLY verify the F1 premise: read the FROZEN ZReportService +
PricingService and confirm whether per-line TVA is computed on the PRE-discount base (which is what justifies
disabling discounts). State clearly: is the F1 premise TRUE in the actual frozen code?`,
  },
  {
    key: 'ui-exposure',
    prompt: `${RULES}\nLENS = ui-exposure (advisor #4 dead-end). Enumerate the CUSTOMER-FACING surfaces on THIS backend (kiosk +
resources/js/components/frontend/**, NOT the standalone /Downloads/web or mobile) that send coupon_id or
loyalty_code to /api/frontend/order, or call /api/frontend/loyalty/redeem. For each, determine if it is reachable
(routed + rendered) and what a customer SEES when the gate now returns a generic 422 (trace myOrderStore catch at
~653 + QueryExceptionLibrary::message). Classify severity as a go-live UX item (not fiscal).`,
  },
  {
    key: 'frozen-integrity',
    prompt: `${RULES}\nLENS = frozen-integrity. Confirm the cycle did NOT modify any frozen NF525 file. Run:
  cd ${REPO} && git diff --stat $(git merge-base HEAD main 2>/dev/null || echo HEAD~30) HEAD -- app/Services/Fiscal/FiscalSequenceService.php app/Services/Fiscal/ZReportService.php app/Services/Fiscal/AuditLogService.php app/Services/Pricing/PricingService.php app/Domain/Order/OrderStateMachine.php
Also confirm the working-tree change set for THIS heal is limited to FrontendOrderService.php (gate only) + 3 test files.
Report any frozen-zone touch as a P0.`,
  },
]

const enumResults = await parallel(LENSES.map(l => () =>
  agent(l.prompt, { label: `enum:${l.key}`, phase: 'Enumerate', schema: ENUM_SCHEMA, agentType: 'Explore' })
))
const enums = enumResults.filter(Boolean)
log(`Enumerated ${enums.length}/${LENSES.length} lenses; collecting candidate findings`)

// ════════════════ PHASE 2 — ADVERSARIAL VERIFY (each candidate finding) ═══════
phase('Verify')

const candidates = enums.flatMap(e =>
  (e.new_findings || []).map(f => ({ ...f, lens: e.lens }))
)
// Also adversarially re-test the headline claim: "no ungated fiscal-Z discount path remains".
candidates.push({
  severity: 'P0', lens: 'bypass-hunt', fiscal: true,
  title: 'BYPASS HUNT: find ANY code path that applies a non-zero discount to a persisted order WITHOUT the gate',
  site: 'app/Services/*', repro: 'Trace every order-creation method end-to-end with flag=false; find a discounted persisted order.',
})

const verdicts = candidates.length === 0 ? [] : await parallel(candidates.map(c => () =>
  agent(
    `${RULES}\nADVERSARIAL VERIFY. Default to real=true ONLY if you can REPRODUCE with file:line evidence; otherwise real=false.\n` +
    `Candidate finding (lens ${c.lens}, ${c.severity}): ${c.title}\nClaimed site: ${c.site}\nRepro hint: ${c.repro}\n` +
    `Confirm or refute. fiscal_blocker=true ONLY if it can sign a fiscally-incorrect NF525 Z in V1 (flag OFF).`,
    { label: `verify:${c.lens}:${(c.title || '').slice(0, 24)}`, phase: 'Verify', schema: VERDICT_SCHEMA }
  )
))
const confirmed = verdicts.filter(Boolean).filter(v => v.real)

// ════════════════ PHASE 3 — SYNTHESIZE (single judge) ════════════════════════
phase('Synthesize')

const judge = await agent(
  `${RULES}\nYou are the convergence JUDGE for the VAT-10 / F1-dormancy go-live heal.\n` +
  `Here are the per-lens enumerations (JSON):\n${JSON.stringify(enums)}\n\n` +
  `Here are the adversarially CONFIRMED findings (JSON):\n${JSON.stringify(confirmed)}\n\n` +
  `Produce a final convergence verdict:\n` +
  `1. fiscal_convergence: GO only if EVERY fiscal-Z-relevant discount entry point is proven gated and the bypass-hunt found nothing.\n` +
  `2. A complete enumeration table (markdown) of every entry point: site | kind | gated | fiscal-Z | verdict.\n` +
  `3. A separate list of NON-fiscal go-live items (UI dead-end, pre-redeem points-stranding, status=1 loyalty quirk) with severity.\n` +
  `4. Confirm the F1 premise verification result from the vat10 lens.\n` +
  `Be severe and evidence-bound. Do not manufacture a GO.`,
  {
    label: 'judge:convergence', phase: 'Synthesize',
    schema: {
      type: 'object',
      additionalProperties: false,
      properties: {
        fiscal_convergence: { type: 'string', enum: ['GO', 'NO-GO'] },
        f1_premise_confirmed: { type: 'boolean' },
        enumeration_table_md: { type: 'string' },
        fiscal_blockers: { type: 'array', items: { type: 'string' } },
        nonfiscal_golive_items: {
          type: 'array',
          items: {
            type: 'object', additionalProperties: false,
            properties: { severity: { type: 'string' }, item: { type: 'string' }, recommendation: { type: 'string' } },
            required: ['severity', 'item', 'recommendation'],
          },
        },
        summary: { type: 'string' },
      },
      required: ['fiscal_convergence', 'f1_premise_confirmed', 'enumeration_table_md', 'fiscal_blockers', 'nonfiscal_golive_items', 'summary'],
    },
  }
)

return { enums, confirmed, judge }
