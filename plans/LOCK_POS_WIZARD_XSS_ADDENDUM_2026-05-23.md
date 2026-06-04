# LOCK Addendum — POS Wizard Stored XSS (GOAL ULTRA-DEEP 2026-05-23)

> **Parent LOCK** : `plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md` (authored Wave 5G `155ddbde8`, **AWAITING OWNER COUNTERSIGN since 2026-05-17**).
>
> **Source of this addendum** : GOAL ULTRA-DEEP 2026-05-23 Phase B.5 proposal `proposals/PROPOSAL_pos-wizard_001_xss-sinks-lock-pending.md` + N5 negotiation ranking #1.

## Why this addendum

The original LOCK plan enumerated **11 innerHTML/insertAdjacentHTML sinks** (lines 4773, 5135, 4945, 4958, 5093, 4986, 4989, 3329, 1255, 1642, 4851). The Phase B.5 frozen-zone proposal agent re-ran the audit at HEAD `f28688675` (post Phase A.1 heal) and discovered **2 NEW sites NOT enumerated in the original LOCK plan** :

| # | Line | Sink | User-controlled string interpolated upstream |
|---|------|------|-----------------------------------------------|
| **NEW-12** | **3180** | `<textarea>` innerHTML re-injection on wizard re-render | `instructionText` = cashier-typed value, re-rendered into `<textarea>` body verbatim. Self-XSS via `</textarea>...` break-out + nested HTML script injection. |
| **NEW-13** | **3187** | ticket-preview innerHTML | `buildTicketInstruction()` concatenates raw `viande.name`, `sauce.name`, `extra.name` without escapeHtml. Same Items REST upstream as sinks 1-10. |

These NEW sites raise the scope to **13 confirmed innerHTML sinks** (was 11) + **15+ unescaped `entity.name` text-context interpolations** (was 15+) + **2 unescaped attribute-context injections** at L1701, L1801 (was 2).

## Scope amendment

When the original LOCK plan §2.1 (sub-agent instructions) is executed, **also** :

1. **Patch L3180 textarea reflection** :
   - Replace `'<textarea ...>' + instructionText + '</textarea>'` (raw concat) with `<textarea ...>' + escapeHtml(instructionText) + '</textarea>'`.
   - Add Vitest sentinel that types `</textarea><img onerror=alert(1)>` into the wizard cashier-instruction field and asserts the textarea body remains the literal escaped string.

2. **Patch L3187 ticket-preview** :
   - Wrap `buildTicketInstruction()` output assembly with `escapeHtml()` calls on every `entity.name` interpolation.
   - Add Playwright spec that creates an Item with `name = "Tacos <script>alert(1)</script>"` (admin-side seeded via tinker), opens the wizard, and asserts the rendered ticket-preview shows the literal escaped text — no script execution.

## No change to original LOCK plan rollback / sentinel / countersign sections

The rollback plan, safety-check override, sub-agent instructions, and owner-countersign block in `LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md` remain authoritative. This addendum only **expands the scope by 2 sites** — the heal mechanism (`escapeHtml` helper application at every untrusted-data sink) is identical.

## Updated metrics for owner countersign decision

- **Original scope** : 11 sinks + 15+ text-context + 2 attribute-context = **~28+ patch sites**
- **Revised scope** : 13 sinks + 15+ text-context + 2 attribute-context + 2 NEW sentinel specs = **~32+ patch sites + 2 sentinels**
- **Estimated wall-clock** : original 1 working day → revised **1.0-1.2 working days** (~15% scope creep from the 2 NEW sites)
- **Risk if NOT applied** : P0 Stored XSS persists in cashier-authenticated origin (Sanctum/PCI scope). Owner has been holding the LOCK in DRAFT for **6+ days** as of 2026-05-23.
- **Risk if applied** : minimal — `escapeHtml` is character-identical output for benign data. Cosmetic regression scope = none (Vanilla JS wizard visual unchanged for legitimate Items).

## Owner gate sign-off (additive — does NOT replace original LOCK countersign block)

By signing below, owner acknowledges :
- The original LOCK plan scope is EXPANDED by L3180 + L3187 sites.
- Sub-agent applier follows the original LOCK §2.1-§2.4 procedure.
- 2 NEW sentinel specs (textarea reflection + ticket-preview ticker) added to V1 protection contract.

**Signed-off-by-owner (addendum)** : ___________  **Date** : ___________

## Cross-references

- Original LOCK : `plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md`
- GOAL prop : `proposals/PROPOSAL_pos-wizard_001_xss-sinks-lock-pending.md`
- N5 negotiation : `reports/test-e2e/goal-2026-05-23/round-1/negotiation-N5.json`
- Wave 5G origin commit : `155ddbde8`
- Phase A.1 heal commit (verified file integrity at audit time) : `f28688675`
