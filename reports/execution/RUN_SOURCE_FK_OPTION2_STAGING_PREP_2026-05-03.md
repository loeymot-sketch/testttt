# RUN — SOURCE-FK Option 2 (staging-first) preparation

Date: 2026-05-03  
Task: CV1-V2-REMAINING-MISSIONS-001

## Gate decision captured

- Gate: `docs/gates/GATE_CV1-WC-T-WC-SOURCE-FK-01_2026-05-03.md`
- Human decision: **Approved option 2 (staging only)**
- Approver: `Kossay / user kossayelbenna8`
- Gate log updated in `docs/gates/GATE_LOG.md`

## Immediate execution policy

1. Staging migration cycle is authorized.
2. Production migration is explicitly deferred until soak evidence is reviewed.
3. No destructive DB action on production in this run.

## Next operational steps (staging environment)

1. Open dedicated staging migration branch for SOURCE-FK.
2. Apply technical feasibility correction:
   - do **not** enforce FK directly on legacy `source_ref` (mixed semantics),
   - use model-correction plan `plans/PLAN_SOURCE_FK_STAGING_MODEL_CORRECTION_2026-05-03.md`.
3. Implement non-breaking typed-source migration + rollback script.
4. Run pre/post integrity assertions for `source_ref` consumers.
5. Execute staging soak window and capture:
   - migration runtime
   - query error rate
   - wizard/composer read/write behavior
6. Produce staging evidence report and return for prod/no-prod decision.

## Cycle status

- Remaining missions cycle unblocked from gate.
- Progress continues under post-gate plan:
  - `plans/PLAN_POST_GATE_SOURCE_FK_AND_FINAL_CLOSE_2026-05-03.md`

## Implementation started (local, non-breaking)

- Added migration: `database/migrations/2026_05_03_200500_add_source_item_attribute_id_to_item_wizard_steps_table.php`
  - new nullable FK column `source_item_attribute_id`
  - safe backfill for deterministic legacy rows (`source_type=item_attribute` + numeric `source_ref`)
- Updated composer write-path to populate typed id without breaking legacy payloads:
  - `app/Services/Composer/ComposerStepService.php`
- Extended validation/API output:
  - `app/Http/Requests/ComposerStepRequest.php`
  - `app/Http/Requests/ComposerProfileRequest.php`
  - `app/Http/Resources/ComposerStepResource.php`
  - `app/Models/ItemWizardStep.php`
- Added regression coverage:
  - `tests/Feature/Composer/ComposerStepServiceContractTest.php`
- Local proof:
  - `php artisan test tests/Feature/Composer/ComposerStepServiceContractTest.php` -> 5 passed.
