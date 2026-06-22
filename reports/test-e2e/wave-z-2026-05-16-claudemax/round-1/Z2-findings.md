# Wave Z — Round 1 — Z2 Kiosk FR-lock + Wizard composer — Findings

**Auditor** : Z2 (Claude Code RED-team, read-only)
**Branch** : `feature/mobile-app-le-cayenne-2026-05-10`
**HEAD** : `c3ba89863`
**Scope** : Verify post-heal status of Kiosk Wave B findings (K-001..K-004) from sister session `reports/audit/ultra-review-2026-05-16/ULTRA_REVIEW_VERDICT.md`
**Date** : 2026-05-16
**Method** : git diff + source read + test inspection. No code touched.

---

## Verdict synthétique

| Finding | Sister verdict | Z2 verdict | Severity now |
|---------|----------------|-----------|--------------|
| K-001 FR-lock breach (locale radiogroup) | P0 | **HEALED in tree** (tests + code + flag + ADR) | — closed |
| K-002 OrderRequest fail-open if token null | P1 | **UNHEALED — by design + documented** | P1 (RED-team reports, orchestrator decides) |
| K-003 magic FRITES_INCLUDED_CATS | P1 | **UNHEALED** | P1 (unchanged) |
| K-004 detectTemplateFromName substring | P1 | **UNHEALED** | P1 (unchanged) |
| RED-team NEW — raw labels kiosk | n/a | clean | — |
| RED-team NEW — delivery i18n FR keys | n/a | partial (label.delivery_address OK, kiosk surface absent) | P3 |
| RED-team NEW — orphaned i18n key kiosk.a11y.language | n/a | confirmed residual (`fr.json` retains `"language": "Langue"` inside the kiosk.a11y nesting block) | P3-cleanup |
| RED-team NEW — composer profile=custom plumbing | n/a | `kioskCustomization.js` does NOT exist; `composerProfile` path lives in `KioskWizardComponent.vue:887-888` via `publishedComposerProfile()`. Substring fallback (K-004) only fires when both `item.wizard_template` and `item.category.wizard_template` are absent | observation |
| RED-team NEW — no regression test for K-003/K-004 | n/a | gap | P2 |

**Aggregate** : 1/4 P0 healed. 3/4 P1 unchanged. 1 new gap (no regression test for magic numbers / template inference). No new P0.

---

## §1 — K-001 verification (FR-lock breach)

### Heal scope (in HEAD)

1. **Component** — `resources/js/components/frontend/kiosk/ds/KsA11ySettings.vue` :
   - Lines 24-32 : ADR-007 comment block replacing the language section.
   - Lines 25-32, 195-198, 202-203, 218-220, 252-255 : ADR-007 explanatory comments — `LOCALE_OPTIONS`, `selectLocale`, `langHeadingId` references all removed/neutralized.
   - Diff vs main : `-30 / +20`, the `<section>` rendering `kiosk-a11y-lang-group` radiogroup and per-locale buttons (`kiosk-a11y-lang-fr|en|ar`) is fully removed (see `git diff main..HEAD -- resources/js/components/frontend/kiosk/ds/KsA11ySettings.vue` lines 1-50).
   - Confirmed by `grep selectLocale|setLocale|kioskSettings/setLocale` → 0 executable hits (only comment-only lines 252-253).

2. **Config flag** — `config/kiosk.php` :
   - Line 31 : `$localeSwitchAllowed = filter_var(env('KIOSK_LOCALE_SWITCH_ALLOWED', false), FILTER_VALIDATE_BOOLEAN);`
   - Lines 48, 95 : `'locale_switch_allowed' => $localeSwitchAllowed` exposed in both `$requireForm` and standard returns.
   - Default = `false` → kiosk runtime stays FR.

3. **Persistance lockdown** — `resources/js/store/index.js:244-289` :
   - `createPersistedState` `paths` array (lines 246-289) includes `kioskSettings.contrast`, `kioskSettings.pmr`, `kioskSettings.audio`, etc., but **explicitly excludes** `kioskSettings.locale` (commented lines 273-279 explaining ADR-007 rationale).
   - Defends against legacy iter15 localStorage state pinning `ar`/`en`.

4. **Tests new** — `tests/js/kioskFrLockImmutable.spec.js` :
   - Lines 37-43 : `TARGETS_NO_SETLOCALE` adds `KsA11ySettings.vue` to FR-lock guarded files alongside the Idle screen and App component.
   - Lines 155-208 : new `describe` block "FR-lock kiosk — UI surface a11y drawer" asserts (a) no `kiosk-a11y-lang-group` data-testid, (b) no `kiosk-a11y-lang-{fr,en,ar}` buttons, (c) `LOCALE_OPTIONS` constant absent, (d) store `paths` array no longer contains `kioskSettings.locale`, (e) `locale_switch_allowed` config key exposed with `KIOSK_LOCALE_SWITCH_ALLOWED` defaulted to `false`.

5. **Tests existing reinforced** — `tests/js/kioskA11ySettingsDrawer.spec.js` :
   - Lines 60-72 : asserts no `kiosk-a11y-lang-*` elements rendered.
   - Lines 74-84 : "click-everything" defense — clicks every `<button>` in the drawer, asserts `store.state.kioskSettings.locale === 'fr'` after.

6. **ADR doc** — `docs/adr/ADR-007-kiosk-fr-lock.md` exists, status "Accepted (iter15-P1a) — Restored Sprint 3D 2026-05-16", rationale + post-V1 relaxation procedure documented.

### Wave Z heal commit identification

`git log --follow -- resources/js/components/frontend/kiosk/ds/KsA11ySettings.vue` shows the most recent touch is commit `2e3635d64` (`feat(cash-trail): Sprint 1B — POS direct + split tranches CASH → CashMovement write + block sale guard`). The K-001 heal was therefore **bundled into the Sprint 1B cash-trail commit**, not given a dedicated Sprint 3D commit — a commit-hygiene observation that doesn't affect verdict correctness (file state at HEAD is what matters) but does mean the heal isn't easily traceable via `--oneline --grep="Sprint 3D"`. The supporting test specs (`kioskFrLockImmutable.spec.js`, `kioskA11ySettingsDrawer.spec.js`) and ADR doc may be in the same commit or staged; `git status` showed only test screenshot dirs + plan markdown as untracked, so the heal IS committed.

### Verdict K-001 : **HEALED**

The fix is structurally robust: code removal + i18n persistence removal + config flag + ADR doc + Vitest regression guard. Defense-in-depth strong.

**Caveat** — see §6 NEW issue: the i18n key `kiosk.a11y.language` may remain orphaned in `fr.json` if not cleaned up; would not break anything but is dead weight.

---

## §2 — K-002 verification (OrderRequest fail-open)

`app/Http/Requests/OrderRequest.php:35-66` :

```php
public function authorize(): bool {
    $user = $this->user();
    if (! $user) { return false; }
    // ... defense-in-depth comment lines 42-59 ...
    $token = $user->currentAccessToken();
    if (! $token) { return true; }                          // ← line 62
    return (bool) $user->tokenCan('kiosk:order');
}
```

**Status** : code unchanged. Lines 51-59 add a comment-block rationale explaining: tests using `$this->actingAs($user, 'sanctum')` produce a `TransientToken` proxy where `currentAccessToken()` returns null, and the team intentionally treats this as "auth happened via guard, not via a scoped token" → pass.

The rationale (lines 56-59) claims: "State-changing damage in production requires an attacker to forge a token, and forged tokens always go through the PersonalAccessToken path where the ability check bites."

**Z2 assessment** : the documented justification is reasonable for the test-affordance use case, BUT it does mean any *session-authenticated* request to `/api/frontend/order/*` (i.e. via cookie-based session, not Bearer token) bypasses the `kiosk:order` ability check entirely. If web SPA login ever issues a session that hits this endpoint (e.g. customer ordering from `/my-account`), the ability check is moot. The risk is **architectural** — the safer path is to require `$token` and configure tests differently — but the maintainers explicitly traded that for test ergonomics.

**Verdict K-002** : **UNHEALED — by design + documented**. RED-team retains P1 grading; orchestrator decides whether the documented rationale warrants downgrade.

---

## §3 — K-003 verification (magic FRITES_INCLUDED_CATS)

`resources/js/components/frontend/kiosk/KioskWizardComponent.vue:1025-1039` :

```js
if (type === 'frites_style') {
  const extras = Array.isArray(item.extras) ? item.extras : [];
  const hasFritesStyleExtras = extras.some((e) => e?.group_label === 'frites_style');
  if (!hasFritesStyleExtras) return false;
  const FRITES_INCLUDED_CATS = new Set([309, 310, 311, 314]);  // ← still hardcoded
  const catId = parseInt(item.item_category_id, 10);
  if (FRITES_INCLUDED_CATS.has(catId)) return false;
  // ...
}
```

Comments lines 1016-1024 reference V3.6/V3.7/V3.8.2 menu reset cycles documenting WHAT the IDs mean (309 Assiettes, 310 Ojja, 311 Omelettes, 314 Menus Enfants).

**Status** : unchanged. No `config/kiosk.php` key for `frites_included_categories`, no DB-driven flag. Magic numbers persist.

**Risk** : if owner renumbers categories or adds a new "frites incluses" category (e.g. cat 316 future combo), the frites_style step will incorrectly offer Cheddar/Cheddar+Oignons upgrade on top of an already-included frites portion → double-billing risk OR confused customer.

**Mitigation absent** : no Vitest spec asserts the integrity of these IDs against the seeder/migrations. A grep across `tests/js/` for `FRITES_INCLUDED|frites_style` returns 0 hits.

**Verdict K-003** : **UNHEALED**. P1.

---

## §4 — K-004 verification (detectTemplateFromName substring)

`resources/js/components/frontend/kiosk/KioskWizardComponent.vue:907-947` : `detectTemplateFromName()` still drives wizard template via `name.includes('tacos')`, `name.includes('sandwich')`, `name.includes('burger')`, `name.includes('assiette')`, `name.includes('omelette')`, `name.includes('ojja')`, `name.includes('nugget|tenders|tender|goujon|crousti|strip')`, `category.includes('snack')`, etc.

Comments lines 917-922 document the V3.7/V3.8.1 menu reset additions (Ojja, Menus Enfants, snacking expansion).

**Heal-light context** : the function is called as a **Priority 3 fallback** (line 905) when `item.wizard_template` is missing AND `item.category.wizard_template` is missing. The proper path is server-driven `wizard_template`. The substring branch is "best-effort heuristic" with `kioskAnalytics.trackHeuselValrtic.trackHeuristicFallback()` instrumentation (lines 900-904) firing when reached.

**Risk** : valid. Renaming "Sandwich Cayenne" → "Wrap Cayenne" silently maps to template `simple` instead of `sandwich`, skipping pain/garniture steps. The analytics callback (lines 900-904) gives observability but no preventive enforcement.

**Status** : unchanged. No DB-driven `wizard_template` enforcement / no Vitest guard preventing the heuristic from being the only path.

**Verdict K-004** : **UNHEALED**. P1.

---

## §5 — Frozen-zone status

`git diff main..HEAD --stat` on the 4 protected kiosk files:

```
KioskAppComponent.vue           | 1009 +++++++++++++---------
KioskUpsellComponent.vue        |   57 +-
KioskWizardComponent.vue        | 1891 +++++++++++++++++++---
ds/KsA11ySettings.vue           |  155 +-
4 files changed, 2605 insertions(+), 507 deletions(-)
```

BRAIN baseline (per task brief: ~+2668/-507) is consistent with the menu reset cycle (V2/V3/V3.1) — these diffs PREDATE Wave Z. The drawer file changes (`155 +/-`) include the Wave Z heal AND the prior menu-reset-cycle structural alignment.

Last 10 commits' touch on kiosk dir (`git diff HEAD~10..HEAD --stat -- resources/js/components/frontend/kiosk/`) :
```
ds/KsA11ySettings.vue           | 60 ++++++++++++---------- (25 ins, 35 del)
```

**Only `KsA11ySettings.vue` was touched** in the last 10 commits — which is the K-001 heal target. `KioskWizardComponent.vue`, `KioskAppComponent.vue`, `KioskUpsellComponent.vue` are **0 lines changed** in last 10 commits → frozen-zone respect ✓.

**Verdict frozen-zone** : compliant. Heal touches only the drawer (which sits in `kiosk/ds/`, not the strictest frozen list — only the 3 root components are in the BRAIN §7 frozen list).

---

## §6 — NEW RED-team issues

### Z2-NEW-1 (P3-cleanup) — Orphaned i18n key `kiosk.a11y.language` confirmed

The drawer no longer renders `kiosk.a11y.language` (line 31 of pre-heal version is removed). `grep -B2 -A2 "language"` on `resources/js/languages/fr.json` reveals THREE occurrences:
1. Top-level admin `"language": "Langue"` (legitimate, used elsewhere).
2. `kiosk.confirmation.language` + `kiosk.confirmation.choose_language` (was a real user-facing surface — may itself be orphaned if confirmation no longer offers locale switch; out of Z2 scope).
3. **`kiosk.a11y.language: "Langue"` inside the `kiosk.a11y` nesting block** — orphan key, no longer referenced after drawer heal.

**Severity** : P3 cosmetic. No user-facing impact (key not requested anymore). Recommend removal in next i18n cleanup pass; also re-evaluate `kiosk.confirmation.language` + `kiosk.confirmation.choose_language` for the same orphan suspicion.

### Z2-NEW-1b (info) — `kioskCustomization.js` does not exist

Task brief §6 referred to "composer wizard for bowls/frites — does `kioskCustomization.js` handle profile=custom correctly post-heal". `find resources/js -iname "*customization*"` returns ONLY `resources/js/helpers/kdsCustomization.js` (Kitchen Display System helper, unrelated). The kiosk composer profile path lives in `KioskWizardComponent.vue:887-888`:

```js
const composerProfile = this.publishedComposerProfile();
if (composerProfile?.template) return composerProfile.template;
```

`grep "profile === 'custom'\|composer_profile.*custom"` across kiosk components returns 0 hits — there is no `profile=custom` special-case branch. The composer profile is read from a published item attribute and feeds template selection directly. Recommend the orchestrator/sister revisit whether "kioskCustomization.js" was a hallucinated reference in the original Wave B prompt.

### Z2-NEW-2 (P2) — No regression test for K-003 / K-004

`tests/js/kioskFrLockImmutable.spec.js` correctly guards K-001 against re-introduction. But no equivalent guard exists for:
- K-003 : a unit test asserting `FRITES_INCLUDED_CATS` integrity against a known-good seed snapshot (or driving the set from a config key)
- K-004 : a unit test snapshotting current name→template mapping outcomes for the menu V3 corpus (Tacos, Sandwich Cayenne, Sandwich Classique, Galette, Bowl, Big …) so a future rename / heuristic regression is caught

`grep frites_style|FRITES_INCLUDED|detectTemplateFromName` across `tests/js/` returns 0 hits — risk surfaces during a future menu rename / DB renumber.

**Severity** : P2. Heal-light recommendation: 1 Vitest spec covering both heuristics with current menu corpus, plus a config-key migration roadmap (see §7).

### Z2-NEW-3 (P3) — Delivery i18n keys absent for kiosk surface

Per sister DEL-6 context, delivery FR keys are needed. Grep on `resources/js/languages/fr.json` :
- `"delivery_address"` exists at line 669 (FR : "Adresse de livraison") ✓
- But searching for `kiosk.delivery.*` or `kiosk.confirmation.delivery` returns 0 hits via `grep -rn "kiosk.delivery|kiosk.confirmation.delivery" resources/js/`

Kiosk currently does NOT support delivery order type (kiosk = on-premises sur place / à emporter only), so absence is **likely correct by design** — but if a future iter exposes delivery on kiosk (per Sprint 2B groundwork), the i18n surface needs filling.

**Severity** : P3 conditional. Pre-condition for kiosk-delivery feature.

### Z2-NEW-4 (info) — Raw labels in kiosk Vue files

`grep -rEn ">[a-z]+\.[a-z_.]+<" resources/js/components/frontend/kiosk/` returned **0 hits**. `grep -rEn "Label\.[A-Z]" ...` returned **0 hits**. `grep -rEn "0undefined|undefined<|null<|>NaN<" ...` returned **0 hits**.

i18n hygiene clean for the kiosk component tree.

---

## §7 — Recommendations (heal-light path forward)

1. **K-002** — add an explicit `permission:order` or `permission:kiosk-order` middleware on the route group instead of relying on FormRequest `authorize()` ability check. Decouples the test-affordance from production-auth path. Or: change the test fixture to mint a real PAT in a test helper.
2. **K-003** — promote `FRITES_INCLUDED_CATS = [309,310,311,314]` to `config/kiosk.php` key `frites_included_category_slugs` (slug-based, not ID-based — survives renumber). Update the wizard component to read via `window.kioskConfig.frites_included_category_slugs` injected at boot. Add Vitest snapshot.
3. **K-004** — server-driven `wizard_template` enforcement: add a NormalItemResource → expose `wizard_template` per item, and treat `detectTemplateFromName` as a deprecation-warned fallback. Add Vitest snapshot of name→template for current menu V3 corpus.
4. **Z2-NEW-2** — bundle the K-003/K-004 hardening with a single Vitest regression spec (~30 LOC) that pins the current behavior so menu V4 doesn't silently regress.

---

## §8 — Citations index (file:line)

| Anchor | Path | Line(s) | Status |
|--------|------|---------|--------|
| K-001 component heal | `resources/js/components/frontend/kiosk/ds/KsA11ySettings.vue` | 24-32, 195-203, 218-220, 252-255 | ✓ healed |
| K-001 config flag | `config/kiosk.php` | 31, 48, 95 | ✓ |
| K-001 store persistence | `resources/js/store/index.js` | 273-289 | ✓ |
| K-001 test guard new | `tests/js/kioskFrLockImmutable.spec.js` | 37-43, 155-208 | ✓ |
| K-001 test guard reinforced | `tests/js/kioskA11ySettingsDrawer.spec.js` | 60-84 | ✓ |
| K-001 ADR | `docs/adr/ADR-007-kiosk-fr-lock.md` | 1-50+ | ✓ |
| K-002 fail-open | `app/Http/Requests/OrderRequest.php` | 60-63 | unhealed, documented |
| K-003 magic IDs | `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` | 1029 | unhealed |
| K-004 substring heuristic | `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` | 907-947 | unhealed |
| Frozen-zone proof | `git diff HEAD~10..HEAD --stat` | 0 root component lines | ✓ |

---

**Z2 verdict** : Wave B K-001 P0 is decisively closed (code + flag + doc + tests + persistence). K-002/K-003/K-004 P1 unchanged — they were NOT in the Wave Z scope per the heal-light scoping inference. No new P0 surfaces. Z2 GREEN on Wave Z scope, AMBER on residual P1 backlog.
