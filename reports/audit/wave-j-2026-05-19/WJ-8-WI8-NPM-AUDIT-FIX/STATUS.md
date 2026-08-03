# WJ-8 WI-8 — npm audit fix (3 FPP supply-chain CVEs)

**Status:** PARTIALLY CLOSED at HEAD (FPP-2 + FPP-3 already shipped) · FPP-1 DEFER V1.0.X
**Branch:** `heal/cms-pr1-quickwins-2026-05-18`
**HEAD verified:** `6a4b1254d` (docs(routes-WJ-1-P0): note parallel-agent commit absorption in WJ-1 STATUS)
**Date:** 2026-05-19
**Wall-clock:** ~25 min (recon + dry-run + fix + Vitest baseline+post + advisor reconcile + STATUS)

---

## TL;DR

- **FPP-2 axios HIGH → CLOSED** (HEAD lock pins `axios@1.16.1`, outside vulnerable range `1.0.0 - 1.15.1`)
- **FPP-3 lodash HIGH → CLOSED** (HEAD lock pins `lodash@4.18.1`, outside vulnerable range `<=4.17.23`)
- **FPP-1 sweetalert2 LOW → DEFER V1.0.X** (transitive via `vue3-simple-alert@1.0.4` pin `sweetalert2: ^8.18.6`; vulnerable range is the entire `>=8.19.1 <9.0.0` band; no patched 8.x exists; 9.x = major bump = out of WI-8 non-breaking scope)
- **Substantive heal commit:** none required. HEAD lockfile already contains the patched axios/lodash. WI-8 was effectively executed upstream by a prior parallel commit on this branch.
- **Vitest:** 1518 pass / 8 fail / 3 skip — **identical pre/post**, all 8 failures pre-existing & unrelated to axios/lodash/sweetalert2 (verified via stash baseline).
- **Frozen-zone diff:** 0
- **NF525 chain impact:** 0
- **composer.json:** unchanged

---

## 1. Pre-state (initial recon)

### Working tree before WI-8

```
testttt@ /Users/.../testttt
├── axios@1.13.2                # node_modules drifted from lock
├─┬ laravel-mix@6.0.49
│ └── lodash@4.17.21 deduped    # node_modules drifted from lock
└─┬ vue3-simple-alert@1.0.4
  └── sweetalert2@8.19.1
```

### npm audit (node_modules-as-deployed)

```
49 vulnerabilities (8 low, 21 moderate, 17 high, 3 critical)
```

| FPP | Package | Reported severity | Range flagged | fixAvailable |
|-----|---------|-------------------|---------------|--------------|
| FPP-1 | sweetalert2@8.19.1 | LOW | `>=8.19.1 <9.0.0` | true |
| FPP-2 | axios@1.13.2 | HIGH | `1.0.0 - 1.15.1` | true |
| FPP-3 | lodash@4.17.21 | HIGH | `<=4.17.23` | true |

### HEAD lockfile (committed at `6a4b1254d`)

```
node_modules/axios       : version "1.16.1"
node_modules/lodash      : version "4.18.1"
node_modules/sweetalert2 : version "8.19.1"
```

**Key finding:** the on-disk `node_modules/` had drifted from the committed lockfile. axios@1.16.1 and lodash@4.18.1 were already pinned at HEAD; only the installation was stale.

---

## 2. Action taken

1. **`npm audit fix --dry-run`** — confirmed FPP-1/2/3 each had `fixAvailable: true` (no `--force` required). The only packages flagged as needing major-version breaking bumps were `swiper@12.x` and `firebase@12.x` (both out of WI-8 scope).
2. **`npm audit fix`** — reconciled `node_modules/` to lockfile + applied any missing minor bumps. Result: axios `1.13.2 → 1.16.1`, lodash `4.17.21 → 4.18.1`, sweetalert2 unchanged at `8.19.1`.
3. **`npm install --no-audit --no-fund`** — verified post-state idempotency. Final lock diff vs HEAD = **0 substantive lines** (npm 8.19.4 cosmetic omission of inner `"name": "testttt"` field reverted via `git checkout HEAD -- package-lock.json`).
4. **`npm audit`** post-fix — confirmed FPP-2 + FPP-3 closed; FPP-1 remains.

---

## 3. Post-state (final, at HEAD)

```
testttt@ /Users/.../testttt
├── axios@1.16.1            # CLOSED — outside vulnerable range
├─┬ laravel-mix@6.0.49
│ └── lodash@4.18.1         # CLOSED — outside vulnerable range
├── lodash@4.18.1           # CLOSED
└─┬ vue3-simple-alert@1.0.4
  └── sweetalert2@8.19.1    # LOW remains — see §4
```

### npm audit (post)

```
24 vulnerabilities (6 low, 14 moderate, 2 high, 2 critical)
```

| Bucket | Pre | Post | Δ |
|--------|-----|------|---|
| critical | 3 | 2 | -1 |
| high | 17 | 2 | -15 |
| moderate | 21 | 14 | -7 |
| low | 8 | 6 | -2 |
| **total** | **49** | **24** | **-25** |

25 advisories closed total (FPP-2 + FPP-3 + bundled transitive ripple). Matches task estimate of "~25 bundled transitive advisories."

---

## 4. FPP-1 sweetalert2 — DEFER V1.0.X analysis

### Severity correction

Task framed FPP-1 as **"CRITICAL, armed in vendor.js, REACHABLE every page-load."**
npm audit reports: **severity=LOW, cvss.score=0, cwe=CWE-912 ("Hidden Functionality")**.
GHSA-8jh9-wqpf-q52c is a supply-chain hygiene marker (hidden functionality announcement), not an exploit chain. Real impact in app: low — `vue3-simple-alert` is loaded in `resources/js/app.js` and used at 4 callsites in `resources/js/services/appService.js` for admin `confirm()` dialogs.

### Why non-breaking fix fails

- Parent `vue3-simple-alert@1.0.4` (latest) declares `sweetalert2: ^8.18.6`.
- Vulnerable range per advisory: `>=8.19.1 <9.0.0` — the **entire 8.x band from 8.19.1 onward**.
- Last unaffected sweetalert2 versions inside the `^8.18.6` band: `8.18.6`, `8.18.7`, `8.19.0`. `npm audit fix` did NOT auto-downgrade (npm policy: never downgrade, only patch within ^range moving forward).
- sweetalert2@latest is 11.26.24 (major bump 9/10/11 = out of `^8.18.6` parent constraint).

### Available V1.0.X remediation paths (not for this commit)

1. **npm override pin** to sweetalert2@8.19.0 (manual SemVer downgrade) — not a "fix", scope creep beyond WI-8.
2. **Replace `vue3-simple-alert`** with thin wrapper importing sweetalert2@11 directly (4 callsites in `appService.js` — isolated, low refactor).
3. **Remove `vue3-simple-alert`** + migrate the 4 admin confirms to a native `<Modal>` or to `dompurify`-sanitized native `confirm()` (cleanest, no new dep).

Recommended path: **#2** (thin wrapper). 30–45 min, isolated to `appService.js`. Tracked separately.

---

## 5. Verification — Vitest no-regression mandate

### Post-fix run (node_modules reconciled to lock)

```
Test Files  3 failed | 234 passed (237)
Tests       8 failed | 1518 passed | 3 skipped (1529)
Duration    14.45s
```

### Pre-fix baseline (stash + npm install to restore drifted node_modules)

```
Test Files  3 failed | 234 passed (237)
Tests       8 failed | 1518 passed | 3 skipped (1529)
Duration    13.72s
```

**Identical counts.** Zero regression introduced. The 8 failing tests are pre-existing and unrelated to the 3 FPP packages:

| File | Fails | Nature |
|------|-------|--------|
| `tests/js/kioskOfflineQueueV2.spec.js` | 5 | `KioskOfflineConflictModalComponent.vue:4:13` render error |
| `tests/js/posWizardComposerProfile.spec.js` | 1 | POS wizard composer profile runtime contract |
| `tests/js/sentinels/f004KioskCancelReasonSent.spec.js` | 2 | sentinel regex `toMatch(/change-status\/\$\{...}`/)` no longer matches |

All 8 are pre-existing on this branch and are tracked separately (out of WI-8 scope).

---

## 6. Playwright smoke

**Skipped.** Task framed Playwright smoke as conditional ("if feasible — at least mount-check on critical surfaces"). With zero substantive code/lock change vs HEAD, there is nothing new to smoke; the existing CI run against `6a4b1254d` already covers axios@1.16.1 + lodash@4.18.1 transit paths.

---

## 7. Untouched-deferred (out of WI-8 scope)

These remain on the wave-j tracker / V1.0.X backlog:

| Package | Severity | Path | Fix path |
|---------|----------|------|----------|
| `swiper` 6.5.1 - 12.1.1 | CRITICAL | direct dep `^11.0.5` | requires `npm audit fix --force` → swiper@12.1.4 (breaking) |
| `firebase` < 12.x | CRITICAL via `@grpc/grpc-js` chain | direct dep `^9.18.0` | requires major bump to firebase@12 (breaking) |
| `protobufjs` <=7.5.5 | CRITICAL | transitive via firebase | bundled into firebase upgrade |
| `webpack-dev-server` | MODERATE | transitive via laravel-mix | no fix available (laravel-mix locked) |
| `quill` | MODERATE | transitive via `vue3-quill` | no fix available (parent dep abandoned) |

None overlap WI-8's 3 FPPs. Recommend separate WJ items for swiper-critical (highest-priority breaking-bump candidate) and a firebase major-bump audit.

---

## 8. Deliverable diff footprint

- `package.json` : **unchanged**
- `package-lock.json` : **0 line diff vs HEAD `6a4b1254d`** (HEAD already contains the patched lock)
- `composer.json` / `composer.lock` : unchanged (out of WI-8 scope)
- frozen-zone diff : **0**
- NF525 chain bytes : unchanged

---

## 9. Wave-J tracker recommendation

**WI-8 outcome:**
- ✅ **FPP-2 axios HIGH** — CLOSED at HEAD (`axios@1.16.1`)
- ✅ **FPP-3 lodash HIGH** — CLOSED at HEAD (`lodash@4.18.1`)
- 🟡 **FPP-1 sweetalert2 LOW** — DEFER V1.0.X (rationale §4; remediation path #2 recommended)

**No commit produced** — the substantive fix shipped via a prior commit on this branch (lockfile was already updated). This STATUS.md documents the verification.

If the wave-j tracker requires a commit for accounting, recommend a no-op `docs(npm-audit-WJ-8): record WI-8 verification status` referencing this file only. Otherwise mark WI-8 partially closed and open V1.0.X-FPP-1-SWEETALERT2.

---

## 10. Audit trail (artifacts)

- `/tmp/npm-audit-pre.json` — pre-state full JSON (deployed node_modules)
- `/tmp/npm-audit-fix-dryrun.txt` — dry-run preview
- `/tmp/npm-audit-fix.txt` — actual fix output
- `/tmp/npm-audit-post.json` — post-fix JSON
- `/tmp/npm-audit-final.json` — final state at HEAD lock
- `/tmp/vitest-pre.txt` — Vitest baseline (drifted node_modules)
- `/tmp/vitest-post.txt` — Vitest post-fix
- `/tmp/npm-audit-current.json` — post-final reconciliation audit

(Transient `/tmp/` files; STATUS.md is the durable record.)
