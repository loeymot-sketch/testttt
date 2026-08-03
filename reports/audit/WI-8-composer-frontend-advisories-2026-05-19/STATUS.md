# WI-8 — Composer + Frontend Advisories Final Triage — STATUS

**Date** : 2026-05-19
**Branch** : `v1-0-1-hardening-2026-05-17`
**Discipline** : AUDIT-ONLY (no `composer update`, no `npm install`, no `npm audit fix` executed)
**Context** : V1 Le Cayenne single-resto LOCAL production. Pre-production gate.

---

## TL;DR

- **Composer** : 12 advisories remaining post Wave 5H (was 17, Wave 5H closed 5 PhpSpreadsheet incl. CVE-2026-34084 CRITICAL).
- **NPM** : 49 advisories (3 critical, 17 high, 21 moderate, 8 low) — **~40 of 49 are build-time/dev-only** transitives that never ship to browser runtime.
- **Real V1-blocker count after exploit-surface analysis** : **3 prod-runtime FIX-PRE-PROD** + **0 critical unauthenticated RCE** + **1 supply-chain CVE armed (sweetalert2)** + **1 structural EOL (Laravel 9 + CVE-2025-27515)**.
- **All FIX-PRE-PROD items can be cleared by a single non-breaking `npm audit fix` run** (recommended scope-minimal heal).
- **Laravel 9 → 10 → 11 migration track** : structural EOL since Feb 2024. CVE-2025-27515 file-validation bypass has NO 9.x fix. Admin-only attack surface, bounded by single-resto trust model — **ACCEPT-RISK V1 + commit V1.1 Laravel 10 migration** recommended.
- **Spatie 5 → 6 migration** : no CVE forcing it, defer with Laravel 10 cycle.
- **Adversarial RED-team verdict** : NO chained unauthenticated RCE path identified. sweetalert2 supply chain is the single "already-armed" exposure.

---

## 1. RECON Summary

### Composer audit (12 advisories, 9 packages)

| Package | Version | Top CVE | Severity | Reachable? |
|---|---|---|---|---|
| laravel/framework | 9.52.21 | CVE-2025-27515 file validation bypass | medium | YES (admin upload) |
| aws/aws-sdk-php | 3.359.13 | PKSA-4t1p (CloudFront) + CVE-2025-14761 | high + med | NO (no CloudFront / no S3 enc client) |
| firebase/php-jwt | 6.11.1 | CVE-2025-45769 weak enc | low | NO (no callsites) |
| league/commonmark | 2.7.1 | CVE-2026-33347 + 30838 | medium | NO (no markdown render) |
| phpseclib/phpseclib | 3.0.47 | CVE-2026-44167 + 40194 + 32935 | high + low + high | NO (no SSH/SFTP/AES use) |
| phpunit/phpunit | 9.6.29 | CVE-2026-24765 deserialize | high | NO (require-dev only) |
| psy/psysh | 0.12.14 | CVE-2026-25129 LPE | medium | NO (tinker dev-only) |
| symfony/process | 6.4.26 | CVE-2026-24739 Windows escape | medium | NO (Linux prod) |

### NPM audit (49 advisories breakdown)

- **Prod-runtime direct deps with CVEs** : `axios` (15+ CVEs), `lodash` (3 CVEs), `swiper` (1 critical proto pollution), `sweetalert2` (1 critical supply chain), `firebase` 9.x + transitives (gRPC/protobufjs).
- **Build-time / dev-only** (~40 advisories) : laravel-mix node-libs-browser polyfills (bn.js / elliptic / browserify-sign / node-forge), vitest + vite + esbuild + happy-dom, webpack + webpack-dev-server, ajv / fast-uri / glob / minimatch / path-to-regexp, express/body-parser/qs (only via vitest), ws/engine.io-client/socket.io-client (vitest fake-indexeddb), yaml/immutable (sass/postcss), follow-redirects (axios http adapter test).

### Wave 5H heal context (closed)

Commit `46fb4ef2d` (2026-05-18) — PhpSpreadsheet 1.30.0 → 1.30.4 (composer.lock-only, capped by maatwebsite/excel ^3.1) closed 5 CVEs:
- CVE-2026-34084 SSRF/RCE via IOFactory::load **CRITICAL** ✅
- CVE-2026-40902 DoS XLSX rows (high) ✅
- CVE-2026-40863 DoS SpreadsheetML row index (high) ✅
- CVE-2026-40296 XSS HTML writer @ placeholder (medium) ✅
- CVE-2026-35453 XSS NumberFormat @ substitution (medium) ✅

Total composer audit advisories: 17 → 12.

---

## 2. Triage Matrix (4-list)

### A. FIX-PRE-PROD (3 items + bonus bundle)

| ID | Package | Current | Target | Action | Breaking? | Owner gate? |
|---|---|---|---|---|---|---|
| FPP-1 | sweetalert2 | 8.19.1 | 8.19.2+ | `npm audit fix` | NO | NO |
| FPP-2 | axios | 1.0.0-1.15.1 | 1.15.2+ | `npm audit fix` | NO | NO |
| FPP-3 | lodash | <=4.17.23 | 4.17.21+ | `npm audit fix` | NO | NO |
| FPP-4 | bundle (~25 npm transitives) | various | various | same `npm audit fix` run | NO | NO |

**Recommended execution path** (out-of-scope for this audit, but trivial): single `npm audit fix` run + smoke test (Vitest 1444/1447 baseline + Playwright smoke + 4-viewport visual on home/menu/POS login).

**Rationale per item** :
- **FPP-1 sweetalert2** : RED-team flagged as the single CRITICAL — GHSA-8jh9-wqpf-q52c is supply-chain compromise (8.19.1 contains "hidden functionality"). Bundled in `public/js/vendor.js` → loaded on every customer + admin page. NO exploit required.
- **FPP-2 axios** : 133 callsites direct dep. 15+ cumulative CVEs include prototype-pollution gadgets, SSRF via NO_PROXY bypass, CRLF injection, JSON tampering. Most not naturally exploitable in FoodKing's backend-formed JSON shape but defense-in-depth + zero-cost fix = no reason not to bump.
- **FPP-3 lodash** : Prototype pollution + `_.template` code injection. Wide admin/POS/kiosk usage. Conservative bump 4.17.21+ closes regardless of whether `_.template()` is used.

### B. DEFER V1.0.X (6 items)

| ID | Package | Current | Reason for defer |
|---|---|---|---|
| DEF-2 | swiper | ^11.0.5 | 11→12 BREAKING. 9 component callsites to re-verify. RED-team verified CVE inert in hardcoded-config usage. |
| DEF-3 | firebase JS SDK 9 → 12 | ^9.18.0 | 3 major versions BREAKING. Messaging-only usage may make Firestore CVEs inert. Bundle-analysis path first. |
| DEF-4 | aws/aws-sdk-php | 3.359.13 | Neither CVE reachable (no CloudFront, no S3 client-side encryption). Routine bump. |
| DEF-5 | league/commonmark | 2.7.1 | Zero callsites in app code. Transitive via Laravel mail. Routine bump. |
| DEF-6 | phpseclib/phpseclib | 3.0.47 | Zero callsites. Transitive via aws-sdk. Will bump transitively with DEF-4. |
| DEF-7 | firebase/php-jwt | 6.11.1 | Zero direct callsites. Low severity. Routine bump. |

### C. NOT-APPLICABLE (5 items / ~40 npm transitives)

| ID | Package | Reason |
|---|---|---|
| NA-1 | phpunit/phpunit | require-dev — not on prod (composer install --no-dev) |
| NA-2 | psy/psysh | tinker = dev-only |
| NA-3 | symfony/process CVE | Windows-only — FoodKing prod is Linux |
| NA-4 | happy-dom | Test env — not browser runtime |
| NA-5 | ~40 npm transitives | Build-time / dev-server-only (laravel-mix polyfills + vitest + esbuild + webpack tooling). Not shipped to browser. Bundle with FPP-4. |

### D. NEEDS-OWNER-DECISION (4 items)

| ID | Topic | Recommendation |
|---|---|---|
| OWN-1 | Laravel 9→10→11 migration | **Option A** — ship V1 on 9.52.21 with CVE-2025-27515 ACCEPTED-RISK (admin-only surface + single-resto trust model). Commit V1.1 Laravel 10 migration (4-6 weeks). |
| OWN-2 | Spatie 5→6 migration | **Option B** — bundle with Laravel 10 cycle. No CVE forcing. team_id↔branch_id audit required. |
| OWN-3 | swiper 11→12 BREAKING | **Option A** — ACCEPT-RISK current usage (RED-team verified inert), defer V1.0.X bundled with firebase. |
| OWN-4 | firebase JS 9→12 BREAKING | **Option A** — bundle-analysis first (1 day) to confirm Firestore inert; likely confirms defer-safe. |

---

## 3. Adversarial RED-team Synthesis

10 attack paths probed (see `specialist-red.json` for full detail). Findings:

- **No unauthenticated RCE path identified.** All Laravel/Composer CVEs require admin auth, MITM, or precondition not met.
- **sweetalert2 supply chain (RED-3)** is the single CVE that needs NO exploit — the package itself contains the malicious code, every page-load executes it. This is THE pre-prod blocker.
- **axios prototype pollution (RED-2)** : requires either HTTPS-break or backend response containing `__proto__` (Laravel doesn't). Chain broken in current FoodKing shape.
- **swiper config pollution (RED-4)** : usage is hardcoded config, no user-input merge. Chain broken in current shape; future refactor must audit.
- **Laravel file-upload bypass (RED-1)** : admin-only, insider-threat surface. Bounded by single-resto trust model.
- **commonmark / phpseclib / firebase-php-jwt / aws-sdk** : zero callsites in app code — not exploitable.

**RED-team top 3 actual concerns** :
1. **sweetalert2 8.19.1** — CRITICAL — fix today
2. **axios 1.x** — HIGH — fix today (defense-in-depth, zero cost)
3. **laravel/framework 9.52.21 EOL** — STRUCTURAL — V1.1 migration commitment

---

## 4. Migration Tracks (BRAIN §1 V1.x backlog)

### Laravel 9 → 10 → 11

- **Current** : 9.52.21 (latest 9.x patched line)
- **EOL** : Feb 2024 (community support ended)
- **Forcing CVE** : CVE-2025-27515 file validation bypass — fixed in 10.48.29+ / 11.44.1+ / 12.1.1+
- **Effort** : 2-4 weeks for 9→10 (Symfony 6 OK, Sanctum personal-access-token type change, config/auth password verbiage) + 1-2 weeks for 10→11
- **Breaking changes** : Sanctum token type, Pest/PHPUnit coupling (forces phpunit 10/11), config refactor, Symfony 7 (on 11)
- **Recommendation** : **V1.1 release scope**. ACCEPT-RISK CVE-2025-27515 for V1 ship (admin-only surface + single-resto trust).

### Spatie permissions 5 → 6

- **Current** : 5.11.1
- **Forcing CVE** : NONE
- **Breaking changes** : team_id resolution refactor (interacts with branch_id multi-tenant pattern), PermissionRegistrar API, cache key versioning, config restructure
- **Branch isolation risk** : MUST audit team_id↔branch_id interaction during migration
- **Recommendation** : **Bundle with Laravel 10 cycle** (V1.1).

---

## 5. Recommended Heal Plan (out-of-scope for this audit)

Single PR scope-minimal heal, V1-blocker path:

1. `npm audit fix` (non-breaking) — clears FPP-1 (sweetalert2), FPP-2 (axios), FPP-3 (lodash), FPP-4 (~25 bundled transitives).
2. Run smoke : Vitest baseline + Playwright critical paths + 4-viewport visual on home/menu/POS-login/checkout.
3. Verify NF525 chain bit-identical (no backend changes expected).
4. Commit message : `fix(deps-WI-8): npm audit fix — close FPP-1/2/3 sweetalert2+axios+lodash supply-chain + prototype pollution`.

V1.0.X follow-ups (separate PRs):
- DEF-2 swiper 11→12 visual+technical regression (1-2 days)
- DEF-3 firebase bundle analysis (1 day) → then defer or migrate
- DEF-4 aws-sdk routine bump (transitively bumps phpseclib DEF-6) (1 day)
- DEF-5 commonmark routine bump (1 hour)
- DEF-7 firebase/php-jwt routine bump (1 hour)

V1.1 release scope:
- OWN-1 Laravel 9 → 10 migration (closes CVE-2025-27515 + brings phpunit 10/11 + Symfony 7 path)
- OWN-2 Spatie 5 → 6 migration (bundle with Laravel)

---

## 6. Evidence Files

- `raw-composer-audit.json` — full composer audit output (12 advisories, 9 packages)
- `raw-npm-audit.json` — full npm audit output (49 advisories)
- `specialist-architect.json` — dep graph + version constraints + migration tracks
- `specialist-security.json` — per-CVE exploit-surface analysis
- `specialist-red.json` — 10 adversarial attack paths probed
- `triage-matrix.json` — 4-list mapping with all items + recommendations

---

## 7. Verdict

**V1-ship blockers from advisory triage** :
- 1 hard blocker → **FPP-1 sweetalert2 supply chain** (5-min `npm audit fix`).
- 2 strong recommends bundled with FPP-1 → **FPP-2 axios + FPP-3 lodash** (same `npm audit fix` run, defense-in-depth, zero cost).
- 0 unauthenticated RCE paths identified.
- 1 structural concern → **Laravel 9 EOL + CVE-2025-27515** = ACCEPT-RISK V1 + commit V1.1 Laravel 10 migration.

**Net pre-prod path** : single `npm audit fix` + smoke test + V1.1 Laravel migration commitment in Owner Gates.

**0 frozen-zone touch needed**. **0 NF525 impact**. **0 composer.json constraint changes** (composer.lock-only bumps could be done if owner wants composer-side cleanups, but no CVE forces it pre-V1).

---

*Generated: 2026-05-19 by WI-8 master sub-agent (1 author, 3 specialist framings synthesized).*
