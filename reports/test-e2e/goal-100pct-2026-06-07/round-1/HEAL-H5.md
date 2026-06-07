# HEAL-H5 — Branch NF525 legal-identity command (covers H4 footer + H5 set-branch-legal)

**Date:** 2026-06-07 · **Agent:** HEAL-H5 · **Verdict:** PASS
**Source defect:** agent 09 (`round-1/09-FISCAL.md`) — operating DB `foodking` has
`branches.siret / vat_intra / legal_footer / register_id` ALL NULL → production
tickets render NO fiscal header; NO `foodking:set-branch-legal` command exists;
current `legal_footer` says "TVA non applicable art.293B CGI" which is WRONG for a
VAT-registered business billing VAT-10.

---

## What changed (2 NEW files, 0 frozen, 0 product-runtime edits)

| File | Role |
|------|------|
| `app/Console/Commands/SetBranchLegalCommand.php` | NEW idempotent artisan command `foodking:set-branch-legal` |
| `tests/Feature/Fiscal/SetBranchLegalCommandTest.php` | NEW feature test (9 tests, sqlite `:memory:`) |

Scope is strictly the `branches` table. The command never writes to the NF525
chain (`audit_logs` / `z_reports`), never to orders, never to a frozen service.
The fields it sets feed the receipt via `app/Services/Receipt/ReceiptDataService.php:69`
(`pos_legal_footer => optional($order->branch)->legal_footer`) — the SSOT both the
Vue `ReceiptComponent` and the ESC/POS renderer read. That service is in
`app/Services/Receipt/`, NOT the frozen `app/Services/Fiscal/`. No frozen-zone touch.

### Command path
`app/Console/Commands/SetBranchLegalCommand.php` (Laravel auto-discovers
`app/Console/Commands` — no manual Kernel registration needed).

### Signature / options
```
foodking:set-branch-legal
  --branch=1        target branch id (default 1 = Le Cayenne single box)
  --siret=          SIRET, exactly 14 digits
  --vat-intra=      TVA intra, "FR" + 11 chars (e.g. FR19104170501)
  --register-id=    caisse / register identifier
  --legal-footer=   legal mentions; OMIT to apply a VAT-registered-safe default
  --no-interaction  (inherited Symfony global — NOT redeclared, see note below)
```

**Trap avoided (Symfony option collision):** `--no-interaction` (`-n`) is a
Symfony console **global** option. Declaring it in `$signature` would throw
"An option named no-interaction already exists" and break `php artisan list`
itself. It is inherited for free. Confirmed: `php artisan list` lists the command
with no error.

### Validation (reject → exit 1, NO DB write)
- SIRET: `^\d{14}$`
- VAT-intra: `^FR.{11}$` (matches the spec "FR + 11 chars"; `FR19104170501` = FR+11)
- Unknown branch → exit 1.

### Idempotency model
"Re-run = identical end-state" (not "first run is a no-op"). Run #1 legitimately
fixes the wrong footer; run #2 with the same args yields a byte-identical branch
row and prints "No change (idempotent re-run)".

### Footer rule (fixes H4 without clobbering a good footer)
- `--legal-footer` provided → used verbatim.
- omitted → apply the safe default **only if** current is null/empty **OR** matches
  `/non applicable|293B/i` (self-contradictory for VAT-registered). A deliberate,
  non-contradictory footer is **preserved**.

---

## Chosen default legal_footer

```
TVA intracommunautaire - Merci de votre visite
```

(constant `SetBranchLegalCommand::DEFAULT_LEGAL_FOOTER`)

Rationale: VAT-registered-safe, NO "non applicable art.293B CGI" / "non applicable"
wording (that is the *franchise-en-base* mention, self-contradictory for a business
that bills VAT-10). The per-branch VAT number itself lives in the `vat_intra` field
(printed separately on the ticket), so it is intentionally NOT embedded in the
default footer. This default is a SAFE PLACEHOLDER, **not** the legally-signed text.

Uses a plain ASCII hyphen `-` (not an em-dash `—`) on purpose: the unmerged G5
ESC/POS thermal renderer (`PosReceiptEscPosRenderer`) may not encode `—` in its
default code page and would print a substitution glyph. The ASCII `-` matches the
prior footer's `" - "` style and is renderer-safe everywhere. Final wording is
owner gate G3 regardless.

---

## OWNER GATES (explicit)

- **G3 — final official footer wording.** The default above is a safe placeholder.
  The FINAL legally-validated mentions text is the owner's to provide/approve.
  Surfaced in the command `--legal-footer` help text, the command description, and
  the runtime NOTE line.
- **G4 — applying REAL legal values per device.** Running this command per physical
  machine with the real owner-supplied SIRET / TVA / register / footer is an
  owner-driven operation (run once per device). Surfaced in the command description
  and runtime NOTE line.

Both notes print on every run:
`NOTE: real per-device values = owner gate G4; final official footer wording = owner gate G3.`

---

## EVIDENCE (clone foodking_e2e @ :8766 ONLY — operating foodking NEVER written)

### `php artisan list` shows the command
```
foodking:set-branch-legal   Set a branch NF525 legal identity (SIRET/TVA/register/footer)
                            idempotently. Real per-device values = owner gate G4;
                            final footer wording = owner gate G3.
```
No "option already exists" error → Trap 1 handled.

### RUN 1 (verify args, NO --legal-footer) — fixes the wrong footer (BEFORE→AFTER)
`APP_ENV=e2e php artisan foodking:set-branch-legal --branch=1 --siret=10417050100019 --vat-intra=FR19104170501 --no-interaction`
```
Replaced self-contradictory footer (was: "E.DELICE SAS - TVA non applicable art.293B CGI - Merci de votre visite")
with VAT-registered-safe default. FINAL wording = owner gate G3.
Branch #1 legal identity (DB: foodking_e2e)
+--------------+--------------------------------------------------+------------------------------------------------+
| Field        | Before                                           | After                                          |
+--------------+--------------------------------------------------+------------------------------------------------+
| siret        | 10417050100019                                   | 10417050100019                                 |
| vat_intra    | FR19104170501                                    | FR19104170501                                  |
| register_id  | (null)                                           | (null)                                         |
| legal_footer | E.DELICE SAS - TVA non applicable art.293B CGI…  | TVA intracommunautaire - Merci de votre visite |
+--------------+--------------------------------------------------+------------------------------------------------+
```

### RUN 2 (identical args) — IDEMPOTENT, before == after
```
| legal_footer | TVA intracommunautaire - Merci de votre visite | TVA intracommunautaire - Merci de votre visite |
No change (idempotent re-run — state already at target).
```

### Validation rejection (raw exit codes, no pipe masking)
```
invalid SIRET 'BAD'        -> "Invalid SIRET 'BAD': must be exactly 14 digits."        raw exit = 1
invalid VAT 'DE12345'      -> "Invalid TVA intracommunautaire... must be 'FR' + 11"    raw exit = 1
unknown --branch=999999    -> "Branch id=999999 not found."                            raw exit = 1
valid run                                                                              raw exit = 0
```
Final clone branch-1 state after the rejection attempts (proves NO write on reject):
```
DB=foodking_e2e
{ "siret":"10417050100019", "vat_intra":"FR19104170501",
  "register_id":null, "legal_footer":"TVA intracommunautaire - Merci de votre visite" }
```

### PHPUnit (sqlite :memory:, canonical config — never foodking)
`vendor/bin/phpunit --filter SetBranchLegal`
```
.........  9 / 9 (100%)
OK (9 tests, 21 assertions)
```
Coverage: set-from-null, 293B-footer replacement, default-not-franchise-base
regression guard, idempotent end-state, deliberate-good-footer preservation,
explicit-footer verbatim, invalid-SIRET-no-write, invalid-VAT-no-write,
unknown-branch graceful fail.

### Frozen-zone / DB-safety discipline
- `git status --short` → only the 2 new files (`??`). No frozen file in diff.
- All artisan invocations prefixed `APP_ENV=e2e` → DB `foodking_e2e`. Operating
  `foodking` never written (confirmed via printed `DB=foodking_e2e` on each run).

---

## NOTES / residual

- `register_id` is left NULL by the verify run (no `--register-id` passed). Setting
  it is part of owner gate G4 (per-device caisse id). The command supports it
  (`--register-id`), tested in `test_sets_legal_identity_from_null_state`.
- Applying these values to the **operating** `foodking` DB is owner gate G4 and was
  intentionally NOT done (read-only on the operating chain per HARD RULES).
- The mechanism gap (H5) and the wrong-footer config (H4) are now both **resolved
  at the tool level**; the remaining steps (final wording, real per-device apply)
  are owner gates G3/G4 by design, not code defects.
