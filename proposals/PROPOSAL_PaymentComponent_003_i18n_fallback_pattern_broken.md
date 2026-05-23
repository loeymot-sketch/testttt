# PROPOSAL — PaymentComponent.vue:multiple — `$t('key') || 'fallback'` pattern broken under default vue-i18n config

**ID** : PROP-PAY-003
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/admin/pos/PaymentComponent.vue`
**Frozen reason** : CLAUDE.md §7 "POS payment component, frozen per BRAIN §2 (V1 untouched protected file)"
**Existing LOCK** : plans/LOCK_PAY_PaymentComponent_currency_2026-05-23.md (D3 pending countersign — currency format)

## Finding (read-only audit)

The component uses the JavaScript short-circuit fallback pattern `$t('key') || 'literal fallback'` ~20 times — both in the template and in script `alertService.error(...)` calls. The pattern assumes `$t('missing.key')` returns a falsy value (empty string, `undefined`) on miss, so the literal fallback gets used.

**Verified vue-i18n config** at `resources/js/i18n.js:114-120` :

```js
const i18n = createI18n({
    legacy:          false,
    globalInjection: true,
    locale:          detectedLocale,
    fallbackLocale:  DEFAULT_LOCALE,
    messages,
});
```

Default vue-i18n v9 behavior is `missingWarn: true`, `silentTranslationWarn: false`, and crucially **the missing-key handler returns the key string itself**. So `$t('label.split_payment')` when the key is missing returns the **truthy** string `"label.split_payment"`, and the `||` fallback never fires. The user sees the raw key.

**Affected lines in PaymentComponent.vue** :

| Line | Pattern | Risk if key missing |
|------|---------|---------------------|
| 76   | `$t('label.split_payment') \|\| 'Multi-paiement'` | Tab label shows raw `label.split_payment` |
| 98   | `$t("label.change_due") \|\| 'Monnaie à rendre'` | Cash-mode change label |
| 119  | `$t('label.payment_terminal') \|\| 'Terminal de paiement (TPE)'` | Terminal selector label |
| 130-131 | `$t('label.no_terminal_configured') \|\| '...'` and `$t('label.select_terminal') \|\| '...'` | Empty / placeholder option text |
| 138  | `$t('pos.no_terminal_configured_hint') \|\| '...'` | Hint banner (with `role="alert"`) |
| 182  | `$t('label.split_summary') \|\| 'Résumé multi-paiement'` | Aria-label on summary group |
| 184  | `$t('label.total_covered') \|\| 'Couvert'` | Split summary label |
| 198  | `$t('label.remaining_due') \|\| 'Reste dû'` | Split summary label |
| 229,240 | `$t('label.split_among_n') \|\| 'Diviser entre N personnes'` | Label + aria-label |
| 250  | `$t('button.split_equally') \|\| 'Diviser à parts égales'` | Button label |
| 260  | `$t('label.auto_balance_help') \|\| '...'` | Button `title` tooltip |
| 262  | `$t('button.auto_balance') \|\| 'Équilibrer le reste'` | Button label |
| 269  | `$t('label.suggest_tendered_help') \|\| '...'` | Button `title` tooltip |
| 271  | `$t('button.suggest_tendered') \|\| 'Suggérer les rendus monnaie'` | Button label |
| 280  | `$t('label.split_tranches') \|\| 'Tranches de paiement'` | Aria-label on list |
| 292  | `$t('pos.split_empty_hint') \|\| 'Ajoutez une tranche pour commencer.'` | Empty-state hint |
| 303  | `$t('button.add_tranche') \|\| 'Ajouter une tranche'` | Add-tranche button |
| 779  | `this.$t('pos.cash_received_auto_bumped') \|\| 'The server total changed...'` | alertService info |
| 898  | `this.$t('message.something_wrong') \|\| 'Réponse commande POS invalide.'` | Error toast |
| 975-977 | `this.$t('pos.terminal_required_card') \|\| 'Sélectionnez un TPE...'` | Confirm-gate error toast |
| 982-984 | `this.$t('pos.split_not_balanced') \|\| 'Le paiement multi...'` | Confirm-gate error toast |

For every locale where a single one of these keys is not defined, the user sees the raw dotted key string (e.g. `pos.split_not_balanced`). The bug is silent under the default vue-i18n config — no Vue warning is surfaced (default is missingWarn=true, but in production the console may be muted or unread).

**Verification audit** : I have NOT cross-checked every key against the `resources/lang/<locale>/*.json` files — that is the heal-step work. The proposal is to migrate the pattern to a vue-i18n-correct form, regardless of whether any specific key is currently missing.

## Reasoning fort (multi-perspective)

### Chef perspective
N/A — chef never opens this modal.

### Client perspective
At the kiosk → POS handoff, if a French-locale key is missing the customer sees text like `pos.split_not_balanced` on the cashier screen — visible to the customer leaning over the counter. Trust degradation.

### Cashier perspective
Under stress (rush), seeing a raw dotted key on an error toast (e.g. `pos.terminal_required_card`) instead of a real instruction blocks resolution. Cashier doesn't know what to do.

### Owner perspective
The codebase has TWO error toasts (line 975-977 and 982-984) — both contain the gate logic ("Sélectionnez un TPE", "Le paiement multi n'est pas équilibré"). If these keys are not in the FR locale JSON, the cashier gets dotted gibberish at exactly the friction moment the toast was designed to defuse. This is a quality bug that owner has not yet been informed about because it requires a missing-key event to trigger.

### Multi-tenant-future
Locale switching for V2 SaaS means each restaurant might use a different fallback locale. Some restaurants will inherit gaps in the AR or EN locale catalog. The pattern fails identically there.

### Adversarial dispute (challenge yourself)
- **False positive ?** Maybe, in two ways :
  1. If all these keys ARE present in `resources/lang/fr/*.json`, the production user never hits the fallback path. The fix is preventive, not corrective. **VERIFY** : `grep -r "split_payment\|change_due\|payment_terminal\|...all keys..." resources/lang/` — owner can defer the proposal if all keys are present.
  2. If vue-i18n is configured with a `missing` handler that returns empty string, the `||` pattern works. **VERIFIED** at `resources/js/i18n.js:114-120` : NO `missing` handler configured. Default behavior applies.
- **Verified the default behavior ?** YES per vue-i18n docs (v9.x) : the `_translate` internal fallthrough returns the key as a string when no message is found and `escapeParameterHtml`/`warnHtmlMessage` flags don't intercept. The string is truthy. The fallback never fires.
- **Scope ?** Migration is template-edit-heavy (~20 sites). Each line is mechanical (`{{ $t('foo') || 'bar' }}` → `{{ $te('foo') ? $t('foo') : 'bar' }}` — uses `$te()` "translation exists"). 30 LOC change. Borderline for LOCK (>5 LOC threshold).
- **Cheaper fix ?** Yes : add a `missing` handler to `i18n.js` that returns `""` for missing keys, fixing the pattern globally for every file in the codebase. BUT that touches `i18n.js` (not frozen, owner-decidable) and may surface OTHER bugs (other files relying on `$t()` returning a truthy string in conditional `v-if`). Net safer to fix the pattern at call-site in PaymentComponent.

## Proposed change

Migrate the pattern from `$t('foo') || 'bar'` to a helper-aware form. Two options :

### Option A : use the `$te()` (translation-exists) helper (vue-i18n v9 native)

```diff
- {{ $t('label.split_payment') || 'Multi-paiement' }}
+ {{ $te('label.split_payment') ? $t('label.split_payment') : 'Multi-paiement' }}
```

20 call-sites mechanically migrated. Diff ≈ +20 lines, -20 lines = NET 0 lines but every line changed.

### Option B : configure global `missing` handler in `i18n.js` (NOT frozen)

```diff
@@ resources/js/i18n.js line 114-120 @@
 const i18n = createI18n({
     legacy:          false,
     globalInjection: true,
     locale:          detectedLocale,
     fallbackLocale:  DEFAULT_LOCALE,
     messages,
+    missing: () => '',  // return falsy on miss so `$t('foo') || 'fallback'` works in components
 });
```

1 LOC change in NON-frozen file. Fixes the pattern globally for every component in the codebase. BUT requires regression audit (any code reading `$t()` for non-display purposes — keys in `v-if`, `aria-current` etc. — would break).

**Recommended : Option B with a one-shot grep audit** for non-display `$t()` usages. Total change in PaymentComponent.vue : **ZERO**.

If owner prefers Option A : PaymentComponent.vue gets ~20 surgical edits, the LOCK doc would need re-scoping.

## Risk analysis

| Scenario | Risk if applied (Option B) | Risk if NOT applied |
|----------|---------------------------|---------------------|
| Production locale FR-complete | Zero — `$t()` already returns the FR string, miss handler never fires. | Zero (user never sees raw key). |
| FR locale gap on key like `pos.split_not_balanced` | None — `$t()` returns `""`, `||` fires fallback string `"Le paiement multi n'est pas équilibré."`. | HIGH — cashier sees raw key in error toast at confirm-time. |
| Component uses `$t()` in `v-if` condition | Other components might break (would log empty rather than truthy key). Audit required across `resources/js`. | None |
| Other 17 components use the same pattern | They get auto-fixed by the global handler change. | They keep failing same way. |
| Bundle rebuild | Required (`npx mix`) post change. | None |
| Frozen-zone diff | **PaymentComponent.vue UNCHANGED** if Option B is chosen. Cleanest LOCK avoidance. | None |

**Note** : Option B does NOT touch PaymentComponent.vue. It does not need a LOCK at all. It's a 1-line change in `i18n.js` (non-frozen). The proposal is filed here because the **manifestation** is most visible in PaymentComponent — but the **fix** lives outside the frozen file.

## LOCK feasibility

- Option B (i18n.js): **NO LOCK needed** — file is not frozen. Standard PR review.
- Option A (in-component migration): >5 LOC, mechanical but invasive (~20 template edits). Borderline. NOT recommended over Option B.

## Owner recommendation

[ ] APPLY (Option B — i18n.js global `missing` handler, NO LOCK needed)
[ ] APPLY-WITH-LOCK (Option A — 20 sites in PaymentComponent, requires LOCK)
[ ] DEFER-V1.0.2 — accept risk if all FR keys are verified-present
[ ] DEFER-V2
[ ] KEEP-AS-IS

**Signed-off-by-owner** : ___________  **Date** : ___________

## How to apply (Option B path — recommended)

1. Audit `grep -rn "\$t(" resources/js | grep -v "\.spec\." | grep -E "v-if|:.*=" | grep "\$t("` — find any non-display `$t()` usage that could regress.
2. Edit `resources/js/i18n.js:114-120` to add `missing: () => '',`.
3. Run full vitest suite to surface conditional regressions.
4. Verify FR locale catalog completeness with `grep -rn "label.split_payment\|...all keys from finding table..." resources/lang/`.
5. Rebuild `npx mix`.
6. Smoke kiosk + POS + KDS to confirm no visual regression.
