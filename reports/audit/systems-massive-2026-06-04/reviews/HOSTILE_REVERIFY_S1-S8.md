# Hostile Re-verification — S1..S8 CONFIRMED findings (2026-06-04)

Reviewer: hostile-final-reviewer (independent file:line re-verification)
Scope: all 49 CONFIRMED findings in micro-systems S1,S2,S3,S4,S5,S6,S7,S8.
Method: opened each cited file:line; did NOT trust report prose.

## VERDICT SUMMARY
All 49 findings re-verified as REAL defects at the cited code. The code does what each finding claims.
Challenges are NOT "non-defect" — they are precision/severity/title nuances. NONE of the findings are false-confirms.

### P1 (9) — all confirmed real
- S1-DASH-01 datepicker raw Date -> 422 -> stale chart: CONFIRMED. requestHandler appService.js:253 no encodeURIComponent; datepicker range mode no model-type => raw Date; Carbon::parse(DashboardService.php:136) throws "Double timezone specification" (reproduced). .catch SalesSummaryComponent.vue:126 doesn't reset. Cited DashboardService line :135-137 accurate (parse at 136).
- S1-DASH-04 channel buckets don't partition: CONFIRMED. posCount (DashboardService.php:538) where(source,POS) no kiosk exclusion -> double-count possible; none-of-bucket -> under-count. NUANCE: display-only stat, no money/fiscal impact -> P1 is defensible-but-aggressive (mild over-severity candidate).
- S4-01 stock dashboard vs customer disagree: CONFIRMED. Dashboard reads only stock_levels.manual_unavailable_reason (Ctrl :384-393/:442-451, SELECTs stockable_id only). Customer ChoiceAvailabilityResolver:296/:312 reads is_available first. IngredientAvailabilityService:38/:59 writes is_available. Two columns, real divergence.
- S5-06 sales export paginate: CONFIRMED defect but TITLE MISCHARACTERIZED. props.search.paginate=1 fixed (SalesReportListComponent:376-378); export inherits paginate=1 => exports ONLY current page (truncation), NOT "full heavy export" as title says. SalesReportExport has no merge(paginate=0) (contrast ItemsReportExport:28). Real, but failure mode is truncation not heaviness. weak-reasoning/over-severity on title.
- S6-01 tax/currency unique self-collision: CONFIRMED. ignore($this->route('tax.id'))/('currency.id') -> NULL because routes bind {tax}/{currency} (api.php:369/:361). ignore(null) doesn't exclude self -> update always 422.
- S7-01 branch fiscal identity blank: CONFIRMED. Migration adds nullable siret/vat_intra/register_id/legal_footer; BranchRequest:32-46 has NO rule for them; BranchCreateComponent grep=0 inputs; seeders grep=0. ReceiptDataService:66-69 reads them -> always NULL on receipt.
- S7-03 APP_DEBUG toggle writes .env: CONFIRMED. SiteComponent.vue:333-354 radio v-model form.site_app_debug; SiteService.php:48 writes 'APP_DEBUG'=>'true'/'false' via envService->addData. NUANCE: AppServiceProvider:202-208 prod boot-guard THROWS RuntimeException (does NOT auto-revoke) => realized prod consequence is boot-refusal, not live debug leak. Title "debug leak" only realizable in non-prod. Defect real (self-inflicted DoS via boot failure). severity P1 defensible.
- S8-01 TPE branch_id=0: CONFIRMED all 3 links. Form has no branch_id (defaultForm:203-211/openEdit). resolveBranchId (PaymentTerminalController:108-117): admin(branch_id=0) returns (int)validated('branch_id')=(int)null=0. CARD tranche match strictly where('branch_id',$orderBranchId) (PosOrderRequest:235-238). BranchScope:43 staff never sees branch_id=0 rows. WRONG-FILE in citation: evidence cites SplitPaymentService.php:129-138 but file is at app/Services/Payments/ (plural) and the branch filter shown is in PosOrderRequest, not Split. Core defect via PosOrderRequest holds.
- S8-02 admin logout/destroy/changeStatus doesn't revoke kiosk token: CONFIRMED. logout()=update(is_login=NO) only; destroy()=delete() only; changeStatus()=status only — none call $user->tokens()->delete(). Kiosk order endpoints gate on tokenCan('kiosk:order') NOT is_login (MenuController:37, UpsellController:32). Token TTL 8h. Only relogin revokes (KioskMachineLoginController.php:96 in Auth/ not Frontend/). Real P1 security. NUANCE: cited path Frontend/KioskMachineLoginController is actually Auth/KioskMachineLoginController; line numbers for service methods slightly off but methods correctly identified.

### P2 (S2-01..04, S3-L2, S4-02, S5-01/02/04/05, S6-... none, S7-02/04, S8-04) — all confirmed
### P3 (the rest) — all confirmed

See StructuredOutput for the per-finding challenge list.

## S8 DEEP-VERIFICATION CLOSURES (post-advisor)
- S8-01 LINCHPIN CLOSED: POS order branch_id is NEVER 0. PosOrderRequest:81 branch_id required; frontend applyPosBranchScope (PosComponent.vue:2553) REJECTS <=0; resolveDefaultAccessBranchId returns >0 or authBranchId; DefaultAccessService fallbackBranchId (:64-83) maps admin(branch_id=0) to site_default_branch (>0). So order carries branch_id=1 (Le Cayenne) and a branch_id=0 terminal never matches -> "impossible d'encaisser par carte" is ACCURATE for the real single-branch flow. No overreach.
- S8-01 CITATION CORRECT: SplitPaymentService.php IS at app/Services/Payments/ (plural); lines 129-133 contain ->where('branch_id',$branchId) CARD terminal check. Earlier I looked in singular Payment/ dir. NO wrong-file challenge.
- S8-04 CLOSED: payment-terminals group (api.php:954) is under admin group (api.php:295) middleware auth:sanctum + block_kiosk_token_admin + throttle, but NO group-level permission:settings. index/show ungated -> any authenticated admin user (incl POS Operator w/o settings) reads serial_number/fee_percent/fee_fixed. Real P2 info-disclosure CONFIRMED.

## FINAL: 49/49 CONFIRMED findings = REAL defects at cited code. Zero false-confirms.
Challenges filed: severity/title-precision nuances only (S5-06 mischaracterized title = strongest; S1-DASH-04 mild over-severity; S7-03 title "debug leak" half non-realizable in prod but P1 holds as boot-failure DoS).
