=== Auto-audit GPT (2e passe) ===
OpenAI Codex v0.124.0 (research preview)
--------
workdir: /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
model: gpt-5.5
provider: openai
approval: never
sandbox: workspace-write [workdir, /tmp, $TMPDIR, /Users/1millnonstop/.codex/memories]
reasoning effort: xhigh
reasoning summaries: none
session id: 019deaca-28a4-7ec3-9692-1204259bab29
--------
user
Tu effectues l’**auto-audit GPT** (2e passe) d’un cycle FoodKing — **pas** l’audit Claude terminal.

**Contexte** : le JSON ci-dessous est la proposition d’implémentation (format agents/codex.prompt.txt) pour la mission `CV1-WC-T-WC-POS-RUNTIME-01`.


**JSON d’implémentation (à recouper)** :
```json
{
  "task_id": "CV1-WC-T-WC-POS-RUNTIME-01",
  "parent_cycle": "CV1-WIZARD-COMPOSABLE-001",
  "execution_tier": "complex",
  "primary_execution_model": "gpt-5.5-pro",
  "reasoning_effort": "xhigh",
  "plan_file": "plans/PLAN_CV1-WIZARD-COMPOSABLE-ADMIN-V1-2026-05-02.md",
  "plan_section": "Phase D — T-WC-POS-RUNTIME-01",
  "audit_source": "reports/audit/CV1_WIZARD_MASTER_SYNTHESIS_2026-05-02.md §4 + Audit Axe 4 §6",
  "delegated_by": "Claude in-session orchestrator (Phase D POS runtime composer-aware path)",
  "delegation_reason": "Refactor partiel de public/js/pos-wizard.js (5832 lignes vanilla JS monolithe) pour ajouter un code path composer-aware sous flag opt-in. Préserve 100% comportement legacy par défaut. XL Codex Pro xhigh requis pour navigation code legacy + intégration sécurisée.",
  "instruction": "Add a composer-aware code path to public/js/pos-wizard.js that, when the feature flag pos_wizard_composer_aware.enabled is true AND the item dataset includes composer_profile.steps, builds the wizard pages from the composer profile instead of the legacy buildSteps(data) heuristic. Default behavior (flag OFF) MUST remain identical to current. The XL audit Axe 4 found this file has zero references to composer — current pos-wizard.js does NOT consume the composer_profile published by admin.\n\n**SCOPE & FILES (allowlist stricte) :**\n\n1. **MODIFY** `public/js/pos-wizard.js` (5832 lines, vanilla IIFE single-page wizard) — add composer-aware path:\n   - Add a top-level helper `function isComposerAwareEnabled() { return !!(window?.foodkingConfig?.posWizardComposerAware?.enabled); }`\n   - Add a top-level helper `function getComposerProfileFromData(data) { return data?.composer_profile?.steps ? data.composer_profile : null; }`\n   - In the function that builds steps from item data (likely `buildSteps(data)` referenced ~line 427 per audit), at the very top:\n     ```js\n     if (isComposerAwareEnabled()) {\n         const profile = getComposerProfileFromData(data);\n         if (profile) {\n             return buildStepsFromComposerProfile(profile, data);\n         }\n     }\n     // ... existing buildSteps logic INTACT ...\n     ```\n   - Add a NEW function `buildStepsFromComposerProfile(profile, data)` that maps `profile.steps[]` to the existing internal step objects shape (compatible with downstream renderers in the same file). Each composer step → 1 internal step. Mapping:\n     - `step.step_key` → step `key`/`type` (preserve heuristic mapping pattern: `pain` → 'pain', `viande`/`meat` → 'viande', `sauce` → 'sauce', `garnitures` → 'garnitures', `supplements` → 'supplements', `menu`/`addon_role=drink|side|menu_component|dessert` → 'menu', anything else with `choices` → 'generic_choices', else skip)\n     - `step.label` → display label\n     - `step.choices` → option list (mapped to existing internal shape — likely `{ id, name, label, available, price }`)\n     - `step.min_select`, `step.max_select`, `step.allow_repeat` → preserved\n     - `step.visible_on` → respected (if 'pos' not in array, skip the step)\n   - Skip-step semantic: if a step in composer profile is unsupported (no mapping path), log to console.warn `[pos-wizard.composer] step skipped (unsupported step_key=X)` and continue.\n   - Preserve all downstream rendering, validation, cart-add event ('wizard:add-to-cart'), tickets, etc. The composer-aware path ONLY changes how the steps array is built.\n\n2. **MODIFY** `resources/views/admin-pos-v4.blade.php` — extend window.foodkingConfig with new flag (around line 105-107 per audit Axe 2):\n   ```php\n   posWizardComposerAware: {\n       enabled: {{ json_encode((bool) config('catalog_v15.pos_wizard_composer_aware.enabled', false)) }},\n   },\n   ```\n\n3. **MODIFY** `config/catalog_v15.php` — add the flag config block (FOLLOW EXISTING PATTERN of pos_fallback_polling lines 55-63):\n   ```php\n   'pos_wizard_composer_aware' => [\n       // When true, public/js/pos-wizard.js uses the composer profile (when present\n       // in item dataset) to build wizard pages instead of legacy heuristic buildSteps.\n       // Default false — production-safe rollout via env flip.\n       'enabled' => env('FK_POS_WIZARD_COMPOSER_AWARE_ENABLED', false),\n   ],\n   ```\n\n4. **NEW** `tests/js/posWizardComposerAware.spec.js` — vitest sentinel with at minimum 5 cases:\n   - Flag OFF + composer_profile present: legacy buildSteps used (no console.warn 'composer' message)\n   - Flag ON + no composer_profile: legacy buildSteps used (no error)\n   - Flag ON + composer_profile with 3 steps (sandwich-like): buildStepsFromComposerProfile returns 3 internal steps with correct keys\n   - Flag ON + composer_profile with unsupported step_key: warn logged + step skipped\n   - Flag ON + composer_profile with visible_on=['kiosk'] only: step skipped (POS not in array)\n   \n   Note: pos-wizard.js is a vanilla IIFE that pollutes nothing globally. To test, the spec should:\n   - Mock `window.foodkingConfig.posWizardComposerAware.enabled`\n   - Mock the DOM modal (#item-variation-modal) with `<script data-wizard='...'>` JSON dataset\n   - Load pos-wizard.js via dynamic import after setting up window/document\n   - Assert observable behavior (DOM rendered or step count via internal API exposure if any)\n   \n   PRAGMATIC: if testing the IIFE directly is too brittle, instead extract the helpers into a small testable `buildStepsFromComposerProfile` function exported in a NEW small ES module file `public/js/pos-wizard-composer.js` (or similar) that pos-wizard.js imports/uses, and test THAT module. Document the choice in NOTES.\n\n**STRICT INVARIANTS :**\n- Pricing SSOT (#1): no client price math\n- branch_id (#3): not in scope (frontend wizard only)\n- Frozen zones (#6): public/js/pos-wizard.js is NOT marked frozen — but treat with extreme care given size + critical POS path\n- DEFAULT BEHAVIOR PRESERVATION: with flag OFF, ALL existing tests MUST pass unchanged\n\n**Validation locale (mandatory before commit) :**\n```\nphp -l config/catalog_v15.php\nphp artisan config:clear\nnpx vitest run tests/js/posWizardComposerAware.spec.js\nnpx vitest run tests/js/ 2>&1 | tail -8\n# Sanity: pos-wizard.js still parses (no syntax error)\nnode --check public/js/pos-wizard.js && echo 'pos-wizard.js OK' || echo 'pos-wizard.js SYNTAX ERROR'\n```\nSentinel 5/5 PASS, **0 régression** vitest globale.\n\n**Trace** (append to `reports/post_execute_latest.log`) :\n```\n=== EXECUTE — CV1-WIZARD-COMPOSABLE-001 / T-WC-POS-RUNTIME-01 ===\nDATE: <ISO>\nEXECUTION_TIER: complex\nEXECUTE_DELEGATION: codex-extension\nFILES_TOUCHED: <list>\nNEW_FUNCTIONS: isComposerAwareEnabled, getComposerProfileFromData, buildStepsFromComposerProfile\nNEW_SENTINEL: posWizardComposerAware (5 cas)\nFLAG: catalog_v15.pos_wizard_composer_aware.enabled (default false)\nLOCAL_VALIDATE: <verdicts>\nGAP_FERMÉ: Audit Axe 4 — POS pos-wizard.js consomme désormais composer_profile sous flag opt-in\nNOTES: <plan-drift discoveries>\n```\n\n**Commit ONCE** with message :\n`[CV1-WIZARD-COMPOSABLE-001 T-WC-POS-RUNTIME-01] POS wizard composer-aware path (gated by flag) + sentinel`\n\nNo amend, no force-push.",
  "subsystems": [
    "public/js/pos-wizard.js (5832 lines vanilla IIFE — modify only buildSteps entry + add 3 helpers)",
    "resources/views/admin-pos-v4.blade.php (extend window.foodkingConfig)",
    "config/catalog_v15.php (add pos_wizard_composer_aware flag)",
    "tests/js/posWizardComposerAware.spec.js (NEW)",
    "(optional) public/js/pos-wizard-composer.js (extracted helper module if testing requires)"
  ],
  "subsystems_off_limits": [
    "any backend Laravel file",
    "resources/js/components/admin/items/composer/* (T-WC-EDITOR-01 territory)",
    "resources/js/components/frontend/kiosk/* (T-WC-KIOSK-REGISTRY-01 territory)",
    "any frozen file from reports/handoff/HANDOFF_CODEX_CV1_FOUNDATIONS_2026-05-02.md §2"
  ],
  "invariants_at_risk": [
    "Default behavior preservation: flag OFF MUST be byte-identical behavior",
    "Pricing SSOT (#1): no client price math added",
    "POS critical path: 5832 lines of legacy code — minimal surgical change required"
  ],
  "acceptance": [
    "Flag default = false (env-driven, non-flipped in commit).",
    "Helper isComposerAwareEnabled + getComposerProfileFromData + buildStepsFromComposerProfile added.",
    "buildSteps entry has early-return pattern for composer-aware (only when flag ON + profile present).",
    "config/catalog_v15.php updated with flag block.",
    "admin-pos-v4.blade.php extends window.foodkingConfig.",
    "Sentinel 5/5 PASS.",
    "0 regression on existing pos-wizard tests (if any in tests/js/).",
    "node --check public/js/pos-wizard.js: OK (no syntax error).",
    "Trace appended.",
    "Single commit."
  ],
  "halt_conditions": [
    "If pos-wizard.js cannot be modified surgically (functions tightly coupled, no clear buildSteps entry) → halt with structural dump and request orchestrator decision",
    "If sentinel cannot be written without extracting helper module → make the choice explicit in NOTES + commit as designed",
    "If existing pos-wizard test exists and breaks → halt and dump",
    "If frozen file modification appears necessary → write reports/handoff/blocks/NEEDS_CLAUDE_T_WC_POS_RUNTIME_01.md and stop"
  ],
  "trace_template": "=== CV1-WIZARD-COMPOSABLE-001 / T-WC-POS-RUNTIME-01 ===\nEXECUTE_DELEGATION: codex-extension\nEXECUTION_TIER: complex\nDATE: 2026-05-02\nFILES_TOUCHED:\n  - public/js/pos-wizard.js (3 helpers + early-return in buildSteps)\n  - resources/views/admin-pos-v4.blade.php (window.foodkingConfig extension)\n  - config/catalog_v15.php (flag block)\n  - tests/js/posWizardComposerAware.spec.js (NEW 5 cas)\nFLAG: catalog_v15.pos_wizard_composer_aware.enabled (default false)\nGAP_FERMÉ: Audit Axe 4 — POS consomme composer_profile sous flag opt-in\nNODE_CHECK: pos-wizard.js OK\nLOCAL_VALIDATE: vitest posWizardComposerAware -> 5/5 ; --filter tests/js/ -> X/Y no regression\nHALT: <none | reason>\n",
  "commit_message_template": "[CV1-WIZARD-COMPOSABLE-001 T-WC-POS-RUNTIME-01] POS wizard composer-aware path (gated by flag) + sentinel\n\n- Add helpers isComposerAwareEnabled / getComposerProfileFromData / buildStepsFromComposerProfile to public/js/pos-wizard.js (5832-line vanilla IIFE preserved structure).\n- Early-return in buildSteps when flag ON + composer_profile present (legacy path 100% unchanged when flag OFF).\n- New flag catalog_v15.pos_wizard_composer_aware.enabled (env-driven, default false, production-safe rollout via FK_POS_WIZARD_COMPOSER_AWARE_ENABLED=true).\n- admin-pos-v4.blade.php extends window.foodkingConfig with the new flag.\n- Sentinel posWizardComposerAware (5 cases) covers OFF passthrough, ON with/without profile, unsupported step skipped, visible_on filter.\n- GAP fermé: Audit Axe 4 — POS pos-wizard.js consume composer_profile (was 0 reference).\n- Audit: reports/audit/CV1_WIZARD_MASTER_SYNTHESIS_2026-05-02.md §4 + Audit Axe 4\n"
}
```

**Livrable** (Markdown uniquement, en français) :
# AUTO_AUDIT_GPT — CV1-WC-T-WC-POS-RUNTIME-01

## 1. Conformité au plan / scope
(Énumérer manques ou dérives ; si scope élargi sans escale → **ESCALATE**)

## 2. Invariants FoodKing
Pour chacun : OK / RISQUE / N/A
- pricing_ssot (backend seul)
- order_status (enum, pas de strings)
- branch_id
- commit_before_dispatch
- frozen_zones
- order_service_symmetry (si un des deux services touché)

## 3. Verdict
Une ligne : `VERDICT: PASS` | `VERDICT: NEEDS_FIX` | `VERDICT: ESCALATE` + 1–3 phrases.

ERROR: You've hit your usage limit. Upgrade to Pro (https://chatgpt.com/explore/pro), visit https://chatgpt.com/codex/settings/usage to purchase more credits or try again at May 6th, 2026 6:30 PM.
ERROR: You've hit your usage limit. Upgrade to Pro (https://chatgpt.com/explore/pro), visit https://chatgpt.com/codex/settings/usage to purchase more credits or try again at May 6th, 2026 6:30 PM.

Reading additional input from stdin...
OpenAI Codex v0.124.0 (research preview)
--------
workdir: /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
model: gpt-5.5
provider: openai
approval: never
sandbox: workspace-write [workdir, /tmp, $TMPDIR, /Users/1millnonstop/.codex/memories]
reasoning effort: xhigh
reasoning summaries: none
session id: 019deaca-51b1-7f83-9393-2ae8e7836fba
--------
user
Tu effectues l’**auto-audit GPT** (2e passe) d’un cycle FoodKing — **pas** l’audit Claude terminal.

**Contexte** : le JSON ci-dessous est la proposition d’implémentation (format agents/codex.prompt.txt) pour la mission `CV1-WC-T-WC-POS-RUNTIME-01`.


**JSON d’implémentation (à recouper)** :
```json
{
  "task_id": "CV1-WC-T-WC-POS-RUNTIME-01",
  "parent_cycle": "CV1-WIZARD-COMPOSABLE-001",
  "execution_tier": "complex",
  "primary_execution_model": "gpt-5.5-pro",
  "reasoning_effort": "xhigh",
  "plan_file": "plans/PLAN_CV1-WIZARD-COMPOSABLE-ADMIN-V1-2026-05-02.md",
  "plan_section": "Phase D — T-WC-POS-RUNTIME-01",
  "audit_source": "reports/audit/CV1_WIZARD_MASTER_SYNTHESIS_2026-05-02.md §4 + Audit Axe 4 §6",
  "delegated_by": "Claude in-session orchestrator (Phase D POS runtime composer-aware path)",
  "delegation_reason": "Refactor partiel de public/js/pos-wizard.js (5832 lignes vanilla JS monolithe) pour ajouter un code path composer-aware sous flag opt-in. Préserve 100% comportement legacy par défaut. XL Codex Pro xhigh requis pour navigation code legacy + intégration sécurisée.",
  "instruction": "Add a composer-aware code path to public/js/pos-wizard.js that, when the feature flag pos_wizard_composer_aware.enabled is true AND the item dataset includes composer_profile.steps, builds the wizard pages from the composer profile instead of the legacy buildSteps(data) heuristic. Default behavior (flag OFF) MUST remain identical to current. The XL audit Axe 4 found this file has zero references to composer — current pos-wizard.js does NOT consume the composer_profile published by admin.\n\n**SCOPE & FILES (allowlist stricte) :**\n\n1. **MODIFY** `public/js/pos-wizard.js` (5832 lines, vanilla IIFE single-page wizard) — add composer-aware path:\n   - Add a top-level helper `function isComposerAwareEnabled() { return !!(window?.foodkingConfig?.posWizardComposerAware?.enabled); }`\n   - Add a top-level helper `function getComposerProfileFromData(data) { return data?.composer_profile?.steps ? data.composer_profile : null; }`\n   - In the function that builds steps from item data (likely `buildSteps(data)` referenced ~line 427 per audit), at the very top:\n     ```js\n     if (isComposerAwareEnabled()) {\n         const profile = getComposerProfileFromData(data);\n         if (profile) {\n             return buildStepsFromComposerProfile(profile, data);\n         }\n     }\n     // ... existing buildSteps logic INTACT ...\n     ```\n   - Add a NEW function `buildStepsFromComposerProfile(profile, data)` that maps `profile.steps[]` to the existing internal step objects shape (compatible with downstream renderers in the same file). Each composer step → 1 internal step. Mapping:\n     - `step.step_key` → step `key`/`type` (preserve heuristic mapping pattern: `pain` → 'pain', `viande`/`meat` → 'viande', `sauce` → 'sauce', `garnitures` → 'garnitures', `supplements` → 'supplements', `menu`/`addon_role=drink|side|menu_component|dessert` → 'menu', anything else with `choices` → 'generic_choices', else skip)\n     - `step.label` → display label\n     - `step.choices` → option list (mapped to existing internal shape — likely `{ id, name, label, available, price }`)\n     - `step.min_select`, `step.max_select`, `step.allow_repeat` → preserved\n     - `step.visible_on` → respected (if 'pos' not in array, skip the step)\n   - Skip-step semantic: if a step in composer profile is unsupported (no mapping path), log to console.warn `[pos-wizard.composer] step skipped (unsupported step_key=X)` and continue.\n   - Preserve all downstream rendering, validation, cart-add event ('wizard:add-to-cart'), tickets, etc. The composer-aware path ONLY changes how the steps array is built.\n\n2. **MODIFY** `resources/views/admin-pos-v4.blade.php` — extend window.foodkingConfig with new flag (around line 105-107 per audit Axe 2):\n   ```php\n   posWizardComposerAware: {\n       enabled: {{ json_encode((bool) config('catalog_v15.pos_wizard_composer_aware.enabled', false)) }},\n   },\n   ```\n\n3. **MODIFY** `config/catalog_v15.php` — add the flag config block (FOLLOW EXISTING PATTERN of pos_fallback_polling lines 55-63):\n   ```php\n   'pos_wizard_composer_aware' => [\n       // When true, public/js/pos-wizard.js uses the composer profile (when present\n       // in item dataset) to build wizard pages instead of legacy heuristic buildSteps.\n       // Default false — production-safe rollout via env flip.\n       'enabled' => env('FK_POS_WIZARD_COMPOSER_AWARE_ENABLED', false),\n   ],\n   ```\n\n4. **NEW** `tests/js/posWizardComposerAware.spec.js` — vitest sentinel with at minimum 5 cases:\n   - Flag OFF + composer_profile present: legacy buildSteps used (no console.warn 'composer' message)\n   - Flag ON + no composer_profile: legacy buildSteps used (no error)\n   - Flag ON + composer_profile with 3 steps (sandwich-like): buildStepsFromComposerProfile returns 3 internal steps with correct keys\n   - Flag ON + composer_profile with unsupported step_key: warn logged + step skipped\n   - Flag ON + composer_profile with visible_on=['kiosk'] only: step skipped (POS not in array)\n   \n   Note: pos-wizard.js is a vanilla IIFE that pollutes nothing globally. To test, the spec should:\n   - Mock `window.foodkingConfig.posWizardComposerAware.enabled`\n   - Mock the DOM modal (#item-variation-modal) with `<script data-wizard='...'>` JSON dataset\n   - Load pos-wizard.js via dynamic import after setting up window/document\n   - Assert observable behavior (DOM rendered or step count via internal API exposure if any)\n   \n   PRAGMATIC: if testing the IIFE directly is too brittle, instead extract the helpers into a small testable `buildStepsFromComposerProfile` function exported in a NEW small ES module file `public/js/pos-wizard-composer.js` (or similar) that pos-wizard.js imports/uses, and test THAT module. Document the choice in NOTES.\n\n**STRICT INVARIANTS :**\n- Pricing SSOT (#1): no client price math\n- branch_id (#3): not in scope (frontend wizard only)\n- Frozen zones (#6): public/js/pos-wizard.js is NOT marked frozen — but treat with extreme care given size + critical POS path\n- DEFAULT BEHAVIOR PRESERVATION: with flag OFF, ALL existing tests MUST pass unchanged\n\n**Validation locale (mandatory before commit) :**\n```\nphp -l config/catalog_v15.php\nphp artisan config:clear\nnpx vitest run tests/js/posWizardComposerAware.spec.js\nnpx vitest run tests/js/ 2>&1 | tail -8\n# Sanity: pos-wizard.js still parses (no syntax error)\nnode --check public/js/pos-wizard.js && echo 'pos-wizard.js OK' || echo 'pos-wizard.js SYNTAX ERROR'\n```\nSentinel 5/5 PASS, **0 régression** vitest globale.\n\n**Trace** (append to `reports/post_execute_latest.log`) :\n```\n=== EXECUTE — CV1-WIZARD-COMPOSABLE-001 / T-WC-POS-RUNTIME-01 ===\nDATE: <ISO>\nEXECUTION_TIER: complex\nEXECUTE_DELEGATION: codex-extension\nFILES_TOUCHED: <list>\nNEW_FUNCTIONS: isComposerAwareEnabled, getComposerProfileFromData, buildStepsFromComposerProfile\nNEW_SENTINEL: posWizardComposerAware (5 cas)\nFLAG: catalog_v15.pos_wizard_composer_aware.enabled (default false)\nLOCAL_VALIDATE: <verdicts>\nGAP_FERMÉ: Audit Axe 4 — POS pos-wizard.js consomme désormais composer_profile sous flag opt-in\nNOTES: <plan-drift discoveries>\n```\n\n**Commit ONCE** with message :\n`[CV1-WIZARD-COMPOSABLE-001 T-WC-POS-RUNTIME-01] POS wizard composer-aware path (gated by flag) + sentinel`\n\nNo amend, no force-push.",
  "subsystems": [
    "public/js/pos-wizard.js (5832 lines vanilla IIFE — modify only buildSteps entry + add 3 helpers)",
    "resources/views/admin-pos-v4.blade.php (extend window.foodkingConfig)",
    "config/catalog_v15.php (add pos_wizard_composer_aware flag)",
    "tests/js/posWizardComposerAware.spec.js (NEW)",
    "(optional) public/js/pos-wizard-composer.js (extracted helper module if testing requires)"
  ],
  "subsystems_off_limits": [
    "any backend Laravel file",
    "resources/js/components/admin/items/composer/* (T-WC-EDITOR-01 territory)",
    "resources/js/components/frontend/kiosk/* (T-WC-KIOSK-REGISTRY-01 territory)",
    "any frozen file from reports/handoff/HANDOFF_CODEX_CV1_FOUNDATIONS_2026-05-02.md §2"
  ],
  "invariants_at_risk": [
    "Default behavior preservation: flag OFF MUST be byte-identical behavior",
    "Pricing SSOT (#1): no client price math added",
    "POS critical path: 5832 lines of legacy code — minimal surgical change required"
  ],
  "acceptance": [
    "Flag default = false (env-driven, non-flipped in commit).",
    "Helper isComposerAwareEnabled + getComposerProfileFromData + buildStepsFromComposerProfile added.",
    "buildSteps entry has early-return pattern for composer-aware (only when flag ON + profile present).",
    "config/catalog_v15.php updated with flag block.",
    "admin-pos-v4.blade.php extends window.foodkingConfig.",
    "Sentinel 5/5 PASS.",
    "0 regression on existing pos-wizard tests (if any in tests/js/).",
    "node --check public/js/pos-wizard.js: OK (no syntax error).",
    "Trace appended.",
    "Single commit."
  ],
  "halt_conditions": [
    "If pos-wizard.js cannot be modified surgically (functions tightly coupled, no clear buildSteps entry) → halt with structural dump and request orchestrator decision",
    "If sentinel cannot be written without extracting helper module → make the choice explicit in NOTES + commit as designed",
    "If existing pos-wizard test exists and breaks → halt and dump",
    "If frozen file modification appears necessary → write reports/handoff/blocks/NEEDS_CLAUDE_T_WC_POS_RUNTIME_01.md and stop"
  ],
  "trace_template": "=== CV1-WIZARD-COMPOSABLE-001 / T-WC-POS-RUNTIME-01 ===\nEXECUTE_DELEGATION: codex-extension\nEXECUTION_TIER: complex\nDATE: 2026-05-02\nFILES_TOUCHED:\n  - public/js/pos-wizard.js (3 helpers + early-return in buildSteps)\n  - resources/views/admin-pos-v4.blade.php (window.foodkingConfig extension)\n  - config/catalog_v15.php (flag block)\n  - tests/js/posWizardComposerAware.spec.js (NEW 5 cas)\nFLAG: catalog_v15.pos_wizard_composer_aware.enabled (default false)\nGAP_FERMÉ: Audit Axe 4 — POS consomme composer_profile sous flag opt-in\nNODE_CHECK: pos-wizard.js OK\nLOCAL_VALIDATE: vitest posWizardComposerAware -> 5/5 ; --filter tests/js/ -> X/Y no regression\nHALT: <none | reason>\n",
  "commit_message_template": "[CV1-WIZARD-COMPOSABLE-001 T-WC-POS-RUNTIME-01] POS wizard composer-aware path (gated by flag) + sentinel\n\n- Add helpers isComposerAwareEnabled / getComposerProfileFromData / buildStepsFromComposerProfile to public/js/pos-wizard.js (5832-line vanilla IIFE preserved structure).\n- Early-return in buildSteps when flag ON + composer_profile present (legacy path 100% unchanged when flag OFF).\n- New flag catalog_v15.pos_wizard_composer_aware.enabled (env-driven, default false, production-safe rollout via FK_POS_WIZARD_COMPOSER_AWARE_ENABLED=true).\n- admin-pos-v4.blade.php extends window.foodkingConfig with the new flag.\n- Sentinel posWizardComposerAware (5 cases) covers OFF passthrough, ON with/without profile, unsupported step skipped, visible_on filter.\n- GAP fermé: Audit Axe 4 — POS pos-wizard.js consume composer_profile (was 0 reference).\n- Audit: reports/audit/CV1_WIZARD_MASTER_SYNTHESIS_2026-05-02.md §4 + Audit Axe 4\n"
}
```

**Livrable** (Markdown uniquement, en français) :
# AUTO_AUDIT_GPT — CV1-WC-T-WC-POS-RUNTIME-01

## 1. Conformité au plan / scope
(Énumérer manques ou dérives ; si scope élargi sans escale → **ESCALATE**)

## 2. Invariants FoodKing
Pour chacun : OK / RISQUE / N/A
- pricing_ssot (backend seul)
- order_status (enum, pas de strings)
- branch_id
- commit_before_dispatch
- frozen_zones
- order_service_symmetry (si un des deux services touché)

## 3. Verdict
Une ligne : `VERDICT: PASS` | `VERDICT: NEEDS_FIX` | `VERDICT: ESCALATE` + 1–3 phrases.
ERROR: You've hit your usage limit. Upgrade to Pro (https://chatgpt.com/explore/pro), visit https://chatgpt.com/codex/settings/usage to purchase more credits or try again at May 6th, 2026 6:30 PM.
ERROR: You've hit your usage limit. Upgrade to Pro (https://chatgpt.com/explore/pro), visit https://chatgpt.com/codex/settings/usage to purchase more credits or try again at May 6th, 2026 6:30 PM.
