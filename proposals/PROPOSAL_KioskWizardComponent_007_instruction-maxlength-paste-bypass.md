# PROPOSAL — KioskWizardComponent.vue — `maxlength=190` HTML-only enforcement on instruction textarea (paste bypass + sanitizer-twice latency)

**ID** : PROP-KWZ-007
**Author** : PROPOSAL AGENT (Phase B.5)
**Date** : 2026-05-23
**Status** : Awaiting owner gate
**Severity** : **P2** — Not a security hole (sanitizer downstream), but a defense-in-depth gap + UX friction (silent truncation).
**Frozen file** : `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
**Touch** : ≤6 LOC inside the `<textarea>` template region (lines 152-162) + 1 method.

---

## 1. Finding (read-only audit)

The instruction textarea (lines 152-162):

```html
<textarea
  id="kiosk-note-input"
  v-model.trim="selections.instruction"
  class="kiosk-note-input"
  :placeholder="$t('message.add_note')"
  maxlength="190"
  rows="2"
/>
```

And the same `190` cap reappears in `buildInstruction` (line 2098):

```js
const manualNote = sanitizeKioskCustomerFacingText(String(this.selections.instruction || '').trim()).slice(0, 190);
```

**Issues**:

1. **`maxlength="190"` is the HTML attribute — enforced ONLY on input, NOT on programmatic paste of text already in the clipboard exceeding the limit.** Standard browsers truncate paste to fit; **Safari iOS 14 had a known bug** where paste bypasses maxlength (the kiosk borne uses Webkit; iOS 14 era hardware is in the supplier pool). Verified by spec: HTML maxlength applies to "user input", paste-by-text is supposed to truncate but Webkit shipped a regression in 2020-2022 era.
2. **Double sanitizer**: `v-model.trim` already trims, then `buildInstruction` calls `sanitizeKioskCustomerFacingText` (line 2098) and slices to 190 chars. If the customer types 190 chars exactly, the sanitizer might strip emoji-zwj sequences and the post-sanitize length could be **< 190**, but no validation surfaces this. Silent truncation.
3. **No user feedback** when truncation occurs. The hint label below the textarea says `{{ $t('message.special_instructions_limit') }}` — generic. Customer doesn't know "you typed X chars, 30 were dropped".
4. **Newlines** are allowed by default (`rows="2"`), no max-newlines enforcement → a customer pasting a 5-line note (within 190 chars) will overflow the print area on the kitchen ticket → ticket layout broken.

**Note**: `sanitizeKioskCustomerFacingText` (from `../../../helpers/kioskDisplayText.js`, line 259) is the FIC sanitizer that strips unsafe Unicode (zero-width chars, RLO override, control codepoints). It is the **last line of defense** before `composition_snapshot`. The frontend gap doesn't introduce a security hole, but it lets bad data reach the snapshot.

---

## 2. Why this matters

### Persona impact — client-impatient
**Mild.** A 50-year-old who tries to add a long instruction ("no nuts, no gluten, no dairy, allergic to shellfish, ...") gets silently truncated. The chef sees an incomplete note. Customer dissatisfaction.

### Chef perspective
**Real.** Truncated notes lose allergen-warning context. NF525 composition_snapshot is sealed with the truncated form → reprint shows the same truncation → no way to recover the missing context.

### Owner
**Real for safety / FIC liability.** EU 1169/2011 FIC requires accurate allergen labeling. If the customer's allergen note is silently truncated, owner has a documentary risk on a contested order.

### NF525
None — sanitizer + slice is byte-deterministic.

### V2 SaaS
Multi-tenant — different cuisines may need longer instructions. Hard-coded 190 cap is brand-specific.

---

## 3. Adversarial dispute

- **False positive?** Webkit paste-bypass bug is largely fixed in 2024+ Safari. Real-world incidence on kiosk Webkit pinned to a specific version is low.
- **Counter**: 190-char silent truncation is the persistent issue, paste-bypass is the icing.
- **Goal cares?** V1: yes — FIC liability is owner-direct.
- **Scope-minimal?** YES — see Option A.

---

## 4. Proposed change

### Option A (RECOMMENDED) — Add JS-level guard + visible counter + multi-line check

**Template change**:

```diff
   <div v-if="currentStep?.type === 'recap'" class="kiosk-note-block">
     <label class="kiosk-note-label" for="kiosk-note-input">{{ $t('label.special_instructions') }}</label>
     <textarea
       id="kiosk-note-input"
-      v-model.trim="selections.instruction"
+      v-model.trim="selections.instruction"
+      @input="onInstructionInput"
+      @paste="onInstructionPaste"
       class="kiosk-note-input"
       :placeholder="$t('message.add_note')"
       maxlength="190"
       rows="2"
     />
-    <p class="kiosk-note-hint">{{ $t('message.special_instructions_limit') }}</p>
+    <p class="kiosk-note-hint" :class="{ 'is-near-limit': instructionRemaining < 20 }">
+      {{ $t('message.special_instructions_limit') }} ({{ instructionRemaining }} / 190)
+    </p>
   </div>
```

**Script additions** (new computed + 2 methods, ~12 LOC total):

```js
// computed
instructionRemaining() {
  return 190 - String(this.selections.instruction || '').length;
},

// methods
onInstructionInput(e) {
  // Defensive — sanitize+slice on every input to defend against Webkit
  // paste-bypass + any non-Latin1 codepoint expansion the maxlength attr
  // doesn't account for.
  const raw = String(e.target.value || '');
  const clean = raw.replace(/\n{2,}/g, '\n').slice(0, 190);
  if (clean !== raw) {
    this.selections.instruction = clean;
    e.target.value = clean;
  }
},
onInstructionPaste(e) {
  // Belt + suspenders : intercept paste explicitly. Browser maxlength is
  // honored in modern Safari but historically bypassed.
  e.preventDefault();
  const pasted = (e.clipboardData || window.clipboardData).getData('text');
  const current = String(this.selections.instruction || '');
  const available = 190 - current.length;
  const slice = pasted.replace(/\n{2,}/g, '\n').slice(0, available);
  this.selections.instruction = (current + slice).slice(0, 190);
},
```

**Total**: 18 LOC across template + script. (Slightly over the 9-LOC LOCK quick-win threshold — owner gate as a small refactor.)

### Option B — Lift the cap to 380 (twice today's) + keep current logic

Address customer dissatisfaction but not paste-bypass / multi-line / counter.

### Option C — Refactor into a `KioskInstructionTextarea.vue` sub-component

Cleaner but breaks frozen-zone scope.

---

## 5. Risk analysis

| Scenario | Option A | KEEP-AS-IS |
|----------|----------|------------|
| Customer with long allergen note | Sees counter, knows truncation will happen, edits | Silent truncation, chef misses allergens |
| Webkit paste-bypass | Caught | Bypassed |
| FIC audit | Defensible | Borderline |
| Frozen-zone diff | 18 LOC (over 9-LOC LOCK quick-win line) | NONE |
| NF525 sanity | Preserved (downstream sanitizer unchanged) | NONE |
| Existing tests | No regression — sanitizer is unchanged | NONE |

---

## 6. LOCK feasibility

18 LOC > 9-LOC quick-win threshold → **LOCK_KIOSK_WIZARD_INSTRUCTION_HARDEN_2026-05-23.md** required, plus owner countersign.

---

## 7. Verification plan

- Vitest unit on `onInstructionPaste` clipboard truncation.
- Manual paste test on Safari + Chrome.
- Counter renders + transitions to `is-near-limit` class at 170-190.
- Frozen-zone diff = 18 LOC.

---

## 8. Owner sign-off

- [ ] APPLY-WITH-LOCK Option A (recommended)
- [ ] APPLY Option B (cap lift only, no JS guard)
- [ ] DEFER-V1.0.2
- [ ] KEEP-AS-IS

**Signed** : ___________ **Date** : ___________

---

## 9. References

- EU 1169/2011 Food Information to Consumers (FIC)
- WHATWG HTML — `maxlength` attribute spec
- Webkit Bug #218956 (paste-bypass historical)
