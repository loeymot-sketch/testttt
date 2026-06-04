# W1 — BASELINE + P1 RECONCILE (Pre-Cloud Remediation execution)

**Date** 2026-06-05 · **Exec branch** `heal/pre-cloud-exec-2026-06-05` (worktree, from HEAD `ad29e7875`
of `heal/cms-pr1-quickwins-2026-05-18`) · **Status** W1 COMPLETE.
Owner opened the execution gate (`/goal exécute le goal max power and smart`); PREPARE-ONLY/HALT lifted.

## Baselines (clean — failures after this point are attributable)
- **PHPUnit**: `2844 passed`, 0 failed (1 risky, 2 incomplete, 29 skipped). 480s. sqlite :memory:.
- **Vitest**: `275 test files passed`, 0 failed.
- Worktree validated: vendor cloned (APFS), autoload regenerated (relative `$baseDir`, no shadow),
  `ReceiptDataServiceWireInTest` 5/5 green in worktree.

## P1 Reconcile vs THIS branch (21 findings, read-only Explore fleet, 712k tok)
Catalog derived vs `main`; this branch has +57 commits. **None of the catalog P1 code defects were
healed by those commits.** Heal `6b26e1be3` (operator) confirmed NOT an ancestor of HEAD.

- **OPEN (20)**: M1-01, M1-02, M3-01, M3-02, M4-02, M6-001, M6-002, M7-02, M8-01, M10-01, M11-01,
  S1-DASH-01, S6-01, S7-03, S8-01, S10-01, S11-02, S13-02, S16-01, S17-01
- **RESOLVED_BY_DATA (1)**: M11-02 (branch legal SIRET/TVA/footer populated — code path correct)

**Active P1 gate = 19** = 20 OPEN − **S8-01 (DEFERRED: terminals = manual SumUp, not V1)**.

## Owner gates answered (2026-06-05, binding)
- **G-H Encaissement = VRAIE FUSION incl. frozen** → unify borne+caisse including `PaymentComponent.vue`
  (FROZEN §7). **Requires LOCK + owner countersign before edit.** Modes: Espèces / Tickets-resto / Terminal(manuel SumUp+réf).
- **A Remise POS = garder + motif obligatoire** → fix M4-02.
- **C Sur place = fermer la faille / flag OFF** → fix S17-01.
- **E App Debug = retirer le toggle** → fix S7-03.
- Deferred: D (resolved), F (TPE — future mission).

## Execution plan (19 active P1)

### A. NON-FROZEN, backend-only (PHPUnit-verifiable this session)
S6-01, S10-01, S17-01, M11-01/S11-02/S16-01 (operator), M6-001, M8-01, M10-01.

### B. NON-FROZEN, frontend (need npm rebuild + visual gate — separate batch)
S7-03, M4-02, M7-02, S1-DASH-01, M1-01, M1-02.

### C. FROZEN-GATED — LOCK + countersign (escalate gate G), do NOT touch
- M6-002 (`ZReportService.php:661` split Z bucketing).
- S13-02 (`ZReportService.php:672` TVA netting side; OrderService side non-frozen).
- M3-01 / M3-02 (`public/js/pos-wizard.js` strict no-touch) — prefer server-side fix; else LOCK.
- **G-H fusion** (`PaymentComponent.vue`) — owner chose full fusion → LOCK + countersign.

## Disciplines
LOOP §5 · frozen-diff=0 unless LOCK+countersign · NF525 attestation appended-only · ANCHOR-FIRST ·
per-cluster checkpoint commit · RED dispute · visual gate on frontend · **no push without owner**.
Isolation: dedicated worktree from HEAD (vendor cloned, autoload regenerated — no shadow).
