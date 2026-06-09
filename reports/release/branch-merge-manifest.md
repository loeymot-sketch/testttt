# BRANCH-MERGE MANIFEST — RC-01 integration (the un-shippable-branch blocker)
**2026-06-09 · T1.1 of GOAL_SUPERVISOR_100 · input for owner GATE-INT-1**

## Verdict
**No single shippable branch exists.** The two production lines are **divergent, neither a superset**:
- **A = `heal/pre-cloud-exec-2026-06-05`** (deployed OVH, served :8767) — carries the **wizard-builder W4–W7** + W5 collision-guard + the supervisor-audit docs.
- **B = `heal/deployed-dashboard-fixes-2026-06-08`** — carries **dashboard-excellence W1–W5 + the FR-locale/i18n + transactions + encaissement fixes**.
- **merge-base** `8cb938b1ec` · A-only = **20 commits** · B-only = **20 commits** · `git diff A..B` = **138 files, +2987/−4415**.

→ Shipping A drops B's FR-locale + dashboard work (live-visible as SWEEP-MONEY/TIME/PAYMODE on the deployed tree). Shipping B drops A's wizard-builder. **A true integration (merge, not cherry-pick) is required.**

## A-only commits (in deployed tree, NOT in sibling) — the wizard-builder line
```
8361ba83c..7f7906b05 (20) — wizard-builder W4.2→W7 (provision real wizards, media/projection/forms,
  multi-attribute expansion, XSS guard, generic-choices photos, box escape-hatch, resolveForItem sentinel),
  W5 collision-guard (5fd1b58f9/b97bd6474), + supervisor-audit docs (df278124f→7f7906b05).
```
**Disposition: KEEP ALL** (the dynamic-wizard feature + this audit). None are reverts of B.

## B-only commits (in sibling, MISSING from deployed tree) — the FR-locale + dashboard line
| Commit | What it fixes | Maps to finding |
|---|---|---|
| `421f1b030` | **transactions FR money + payment label** (additive) | SWEEP-MONEY-01, SWEEP-PAYMODE-01 |
| `a952c5f72` | **FR confirm/delete dialogs + label.no `N°`→`Non`** | CENTRAL-P1-02, RC-01 evidence |
| `ef7ffd227` | mass-send confirm + rename buttons | (safety polish) |
| `4313f4547`..`2decb8633` | **dashboard-excellence W1–W5** (FR-locale roots, money parcours, cockpit, catalogue/stock, nav) | CENTRAL FR-locale cluster |
| `dbdb86a10` | export N° fiscal heading → lang key | (fiscal-display i18n) |
| `a657f79f8`/`d05a1f0d5` | encaissement G-DEC-1 honest partial-total caveat (pending list 200-cap) | (encaissement correctness) |
| `0c0183ee4` | Fiscal/AuditLogService config:cache hardening (PREPARE+DEFER, no-op V1) | UNI-03 backlog |
| `625482726`/`5459952da`/`c3192c154` | bundle rebuilds | (build) |
**Disposition: KEEP ALL** (closes CENTRAL-P1-02 + SWEEP-MONEY/PAYMODE + dashboard FR-locale for free).

## Conflict-prone files (BOTH branches edited since merge-base — manual resolution required)
| File | Why both touched | Resolution strategy |
|---|---|---|
| `resources/js/languages/fr.json` | A added wizard-builder keys; B added FR validation/dialog/transaction keys | **Union merge** — keep BOTH keysets; verify `'no':'Non'` + wizard keys + studio key all present, 0 `<<<<<<<` |
| `routes/api.php` | A added wizard/composer routes; B added export/encaissement routes | **Union** — append-coordination registry; keep both route blocks |
| `public/js/admin-shell.js`, `pos-app.js`, `mix-manifest.json` | compiled bundles on both | **Discard both, REBUILD** post-merge (`npm run prod`); never hand-merge bundles |

## Recommended integration (for owner GATE-INT-1)
1. **Target = A** (`heal/pre-cloud-exec-2026-06-05`, the deployed line) — merge **B into A** so the deployed tree gains the FR-locale/dashboard fixes without losing the wizard-builder.
2. `git merge B` → resolve `fr.json` (union) + `routes/api.php` (union) → `git checkout --theirs` is WRONG for these (lose A's keys); resolve by hand.
3. **Rebuild bundles** (`admin-shell`, `pos-app`, `app`) → discard the bundle conflicts → fresh compile.
4. Gate with sentinels: `BundleFreshnessSentinel` green, `grep 'Yes, Delete it!' public/js/app.js = 0`, `FrozenZoneSha256BaselineSentinelTest` GREEN (merge must touch 0 frozen files).
5. Live e2e (post-rebuild): `/admin/items` delete → "Êtes-vous sûr ? / Oui, supprimer" ; `/admin/transactions` → FR money + FR payment label ; `/admin/items/create` radio → "Non".

## Owner decision needed (GATE-INT-1)
- [ ] Confirm **A is the integration target** (recommended) — or specify otherwise.
- [ ] Confirm which tree the live server should serve post-merge.
- [ ] Authorize the merge (touches `fr.json`/`routes/api.php` + bundle rebuild; **0 frozen** if done right).

> Once GATE-INT-1 is signed, T1.2–T1.4 execute the merge + rebuild + sentinel gate. This single integration closes RC-01 **and** CENTRAL-P1-02 + SWEEP-MONEY/PAYMODE (transactions) + label.no `N°`.
