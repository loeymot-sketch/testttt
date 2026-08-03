# SOURCE-FK Technical Feasibility Audit

Date: 2026-05-03  
Gate option selected: staging-only (Option 2)

## Key finding (critical)

`item_wizard_steps.source_ref` is currently a **single string field** used with **multiple semantics**:

- `source_type=item_attribute` -> numeric ID string (example: `"12"`)
- `source_type=extra_group` -> label/group string (example: `"Sauces"`)
- `source_type=addon` -> role token (example: `"drink"`)
- `source_type=fixed` -> free/manual token (example: `"manual"`)

Because one column mixes IDs, labels, and tokens, a direct SQL foreign key on `source_ref` is **not technically valid** as-is.

## Evidence points

- Migration schema: `database/migrations/2026_04_27_143110_create_item_wizard_steps_table.php` (`source_ref` is `string`).
- Validation contracts accept string `source_ref` in composer requests.
- Tests confirm mixed `source_ref` values (`id`, `Sauces`, `drink`, `manual`).

## Impact

- Gate intention (FK hardening) is valid.
- Initial implementation hypothesis ("add FK on current column") is invalid without data model adjustment.

## Corrective strategy (staging-first compatible)

### Phase A — Non-breaking schema extension

Add typed optional columns to `item_wizard_steps`:

- `source_item_attribute_id` (nullable FK)
- `source_extra_group_id` (nullable FK if canonical extra groups table exists; else defer)
- `source_addon_id` (nullable FK if addon catalog id is canonical; else defer)

Keep legacy `source_ref` during transition.

### Phase B — Dual-write + backfill

- On write/update, populate typed columns when resolvable.
- Backfill historical rows where deterministic mapping exists.
- Keep unresolved rows explicit and auditable.

### Phase C — Integrity checks (staging)

- Add check constraints matching `source_type` to allowed typed columns.
- Run soak and parity tests.

### Phase D — Optional finalization

- Only after full backfill and proof: tighten nullability and deprecate raw `source_ref`.

## Recommended decision refinement

Proceed with Option 2 scope as:

- **staging design migration (non-breaking)** + backfill proof,
- **not** direct hard FK on legacy `source_ref`.

## Verdict

- SOURCE-FK gate can proceed in staging, but with **model-correction approach**, not naive FK.
