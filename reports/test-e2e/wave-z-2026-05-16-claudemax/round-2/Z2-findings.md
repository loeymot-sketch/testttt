# Wave Z — Round 2 — Z2 Kiosk FR-lock + Wizard composer — Convergence Findings

**Auditor** : Z2 Round 2 (Claude Code RED-team, read-only)
**Branch** : `feature/mobile-app-le-cayenne-2026-05-10`
**HEAD** : `56204f052`
**Round 1 baseline** : `c3ba89863`
**Wave Z heal commits** : `7fc62c066`, `7e62f7bbc`, `c9509b3ad`, `fe883b457`, `d424f8402`, `56204f052`
**Date** : 2026-05-16
**Method** : git diff range c3ba89863..HEAD + source spot-check + Wave Z heal cross-impact RED-team. No code touched.

---

## Verdict synthétique

| Finding | Round 1 verdict | Round 2 verdict | Severity now |
|---------|-----------------|-----------------|--------------|
| K-001 FR-lock breach (locale radiogroup) | HEALED | **HEALED — confirmed stable** | — closed |
| K-002 OrderRequest fail-open if token null | UNHEALED — by design + documented | **UNCHANGED** (V1.0.1 backlog) | P1 |
| K-003 magic FRITES_INCLUDED_CATS | UNHEALED | **UNCHANGED** (V1.0.1 backlog) | P1 |
| K-004 detectTemplateFromName substring | UNHEALED | **UNCHANGED** (V1.0.1 backlog) | P1 |
| RED-team NEW (R2) — Wave Z heal cross-impact on kiosk | n/a | **NONE detected** | — |
| RED-team NEW (R2) — kiosk dir diff vs Round 1 baseline | n/a | **0 lines on all kiosk files** | — |

**Aggregate** : Z2 convergence confirmed. K-001 P0 remains decisively closed. K-002/K-003/K-004 P1 remain V1.0.1 backlog (per Sister-verdict scope; not in Wave Z heal-light scope). **0 new findings** from Wave Z heals affecting kiosk.

---

## §1 — K-001 verification (FR-lock breach) — still HEALED

### 1.1 Component intact at HEAD

`resources/js/components/frontend/kiosk/ds/KsA11ySettings.vue` — grep at HEAD:
- Line 202 : `// [ADR-007 / Sprint 3D 2026-05-16] LOCALE_OPTIONS retiré : kiosk runtime`
- Line 252 : `// [ADR-007 / Sprint 3D 2026-05-16] selectLocale retiré : kiosk runtime`
- **No executable references** to `selectLocale`, `LOCALE_OPTIONS`, `kiosk-a11y-lang-{fr,en,ar}`, `setLocale` — only ADR-007 documentation comments remain.

Verified via `grep -n "selectLocale\|LOCALE_OPTIONS\|kiosk-a11y-lang" KsA11ySettings.vue` → only 2 comment-only hits (lines 202, 252). No radiogroup, no per-locale buttons, no Vuex locale mutation paths.

### 1.2 Config flag still defaults false

`config/kiosk.php` at HEAD :
- Line 31 : `$localeSwitchAllowed = filter_var(env('KIOSK_LOCALE_SWITCH_ALLOWED', false), FILTER_VALIDATE_BOOLEAN);`
- Line 48 : `'locale_switch_allowed' => $localeSwitchAllowed,` (in `$requireForm` block)
- Line 95 : `'locale_switch_allowed' => $localeSwitchAllowed,` (in standard returns)

Default `false` — kiosk runtime stays FR. No env override observed in repo.

### 1.3 Frozen-zone diff since Round 1 (c3ba89863..HEAD)

```
$ git diff c3ba89863..HEAD --stat -- resources/js/components/frontend/kiosk/
(empty output)
```

**Zero lines changed** across the entire `resources/js/components/frontend/kiosk/` tree (including `ds/` subdir) during Wave Z. The Round 1 K-001 heal state is structurally untouched.

Specifically the 3 root frozen kiosk components (BRAIN §7) :
- `KioskWizardComponent.vue` — 0 lines
- `KioskAppComponent.vue` — 0 lines
- `KioskUpsellComponent.vue` — 0 lines

### Verdict K-001 : **HEALED — confirmed stable through Wave Z**

No regression. ADR-007 / Sprint 3D heal state preserved across all 6 Wave Z heal commits.

---

## §2 — K-002 verification (OrderRequest fail-open) — UNCHANGED

`app/Http/Requests/OrderRequest.php:35-66` at HEAD (verified via Read tool):

```php
public function authorize(): bool {
    $user = $this->user();
    if (! $user) { return false; }
    // [iter15-P0-08] Defense-in-depth ability check.
    // ... rationale comment lines 42-59 (unchanged) ...
    $token = $user->currentAccessToken();
    if (! $token) {
        return true;                                    // ← line 62 still fail-open
    }
    return (bool) $user->tokenCan('kiosk:order');
}
```

**Status** : identical to Round 1. Lines 60-63 fail-open path persists. Documenting comment block 42-59 unchanged.

**Z2-R2 assessment** : Round 1 RED-team analysis stands — risk is architectural (session-auth cookie path bypasses `kiosk:order` ability check). Sister-verdict (per `reports/audit/ultra-review-2026-05-16/HEAL_FINAL_VERDICT.md`) classified this as V1.0.1 backlog. Out of Wave Z scope.

**Verdict K-002** : **UNCHANGED. P1. V1.0.1 backlog.**

---

## §3 — K-003 verification (magic FRITES_INCLUDED_CATS) — UNCHANGED

`resources/js/components/frontend/kiosk/KioskWizardComponent.vue:1025-1039` at HEAD (verified via Read tool):

```js
if (type === 'frites_style') {
  const extras = Array.isArray(item.extras) ? item.extras : [];
  const hasFritesStyleExtras = extras.some((e) => e?.group_label === 'frites_style');
  if (!hasFritesStyleExtras) return false;
  const FRITES_INCLUDED_CATS = new Set([309, 310, 311, 314]);  // ← line 1029 still magic IDs
  const catId = parseInt(item.item_category_id, 10);
  if (FRITES_INCLUDED_CATS.has(catId)) return false;
  // ...
}
```

Comments lines 1018-1024 documenting V3.6/V3.7/V3.8.2 menu reset cycles intact. No `config/kiosk.php` key `frites_included_categories` added. No Vitest snapshot guard added in `tests/js/`.

**Status** : identical to Round 1. Magic numbers [309, 310, 311, 314] persist at line 1029.

**Verdict K-003** : **UNCHANGED. P1. V1.0.1 backlog.**

---

## §4 — K-004 verification (detectTemplateFromName substring) — UNCHANGED

`resources/js/components/frontend/kiosk/KioskWizardComponent.vue:907-947` at HEAD (verified via Read tool):

```js
detectTemplateFromName() {
  const item = this.resolvedItem;
  if (!item) return 'simple';
  const name = (item.name || '').toLowerCase();
  const category = (item.category_name || '').toLowerCase();
  if (name.includes('tacos') || category.includes('tacos')) return 'tacos';
  if (name.includes('sandwich') || category.includes('sandwich')) return 'sandwich';
  if (name.includes('burger') || category.includes('burger')) return 'burger';
  if (name.includes('assiette') || category.includes('assiette')) return 'assiette';
  if (name.includes('omelette') || name.includes('omelet') || category.includes('omelette')) return 'omelette';
  if (name.includes('ojja') || category.includes('ojja')) return 'omelette';
  if (
    category.includes('menu enfant') ||
    category.includes('menus enfants') ||
    (name.includes('menu') && (name.includes('enfant') || name.includes('nugget') || name.includes('cheese burger')))
  ) { return 'omelette'; }
  if (name.includes('salade') || category.includes('salade')) return 'salade';
  if (name.includes('nugget') || name.includes('tenders') || name.includes('tender') ||
      name.includes('goujon') || name.includes('goujons') || name.includes('crousti') ||
      name.includes('strip') || category.includes('snack')) { return 'snacking'; }
  return 'simple';
}
```

**Status** : identical to Round 1. Called as Priority 3 fallback (line 905) with `kioskAnalytics.trackHeuristicFallback()` (lines 900-904) — observability path intact. No server-driven `wizard_template` enforcement. No Vitest snapshot of name→template mapping.

**Verdict K-004** : **UNCHANGED. P1. V1.0.1 backlog.**

---

## §5 — Frozen-zone verification (Round 1 → Round 2)

```
$ git diff c3ba89863..HEAD --stat -- resources/js/components/frontend/kiosk/
(0 lines)
$ git log --oneline --name-only c3ba89863..HEAD -- resources/js/components/frontend/kiosk/
(no commits touch this tree)
```

**Verdict frozen-zone** : **0 lines changed** on:
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` (frozen, BRAIN §7)
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue` (frozen, BRAIN §7)
- `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue` (frozen, BRAIN §7)
- `resources/js/components/frontend/kiosk/ds/KsA11ySettings.vue` (heal target — preserved post-Sprint 3D)

Frozen-zone discipline respected across all 6 Wave Z heal commits.

---

## §6 — RED-team NEW (Round 2): Wave Z heal cross-impact on kiosk

Cross-impact analysis on each Wave Z heal commit (`7fc62c066..56204f052`) for kiosk-affecting changes:

### 6.1 Commit `d424f8402` — EN i18n parity (`lang/en/all.php` +25 lines)

`git diff c3ba89863..HEAD -- lang/en/all.php` shows 25 new keys all prefixed `cash_session_*` plus 1 `cash_no_open_session_blocks_sale`. **All POS cash-drawer-scoped — zero kiosk consumers.** Sister commit message confirms "EN parity for the POS cash drawer session dialog (PosCashDrawerSessionDialog.vue)".

No `kiosk.*` namespace touched in `lang/en/all.php`. **No kiosk impact.**

### 6.2 Commit `7fc62c066` — GDPR data minimization (`SimpleOrderResource.php`, `KDSOrderDetailsResource.php`)

`SimpleOrderResource.php` line 53 changes `customer_phone` from unconditional to DELIVERY-only:
```php
'customer_phone' => ((int) $this->order_type === OrderType::DELIVERY) ? $this->user?->phone : null,
```

Consumer audit:
- `app/Http/Controllers/Admin/SalesReportController.php:43`
- `app/Http/Controllers/Admin/OnlineOrderController.php:48`
- `app/Http/Controllers/Admin/AdministratorController.php`
- `app/Http/Controllers/Admin/PosOrderController.php:98`

**No kiosk controller consumes `SimpleOrderResource`.** Grep `customer_phone` in `resources/js/components/frontend/kiosk/` returns 0 hits — kiosk UI doesn't render or expect customer phone. **No kiosk impact.**

`KDSOrderDetailsResource.php` change at line 64 strips phone for non-DELIVERY orders too. Sister explicit comment: "Dine-in/takeaway/kiosk KDS [doesn't need phone]". KDS-scoped, not kiosk-frontend. **No kiosk impact.**

### 6.3 Commit `56204f052` — Auth token revoke on relogin (`LoginController.php` +9 lines)

`app/Http/Controllers/Auth/LoginController.php:84-93` adds:
```php
$user->tokens()->where('name', 'auth_token')->delete();
```

**Scoped by `name = 'auth_token'`** — explicitly preserves kiosk:order tokens (different name minted by `KioskMachineLoginController` with abilities `['kiosk:order']`). Sister comment confirms intent: "Scoped by name so we never touch kiosk:order tokens (different name, separate concern)."

**No kiosk auth impact.** Verified by tracing kiosk token flow: kiosk tokens are scoped by `kiosk:order` ability and minted with a distinct name, untouched by this `where('name', 'auth_token')` filter.

### 6.4 Other Wave Z commits — kiosk impact scan

`7e62f7bbc` (cash forensic + POS auth), `c9509b3ad` (RBAC POS quote close), `fe883b457` (docs only) — none touch kiosk files. `git log --name-only c3ba89863..HEAD -- resources/js/components/frontend/kiosk/` returns **empty** — no kiosk file in Wave Z commit set.

### Verdict Z2-R2 cross-impact : **NONE**

No Wave Z heal commit modifies the kiosk component tree, kiosk i18n namespace, kiosk API resources, or kiosk auth path. The K-001 healed state and the unchanged K-002/K-003/K-004 backlog remain exactly as Round 1 recorded.

---

## §7 — Citations index (file:line)

| Anchor | Path | Line(s) | Status R2 |
|--------|------|---------|-----------|
| K-001 component heal | `resources/js/components/frontend/kiosk/ds/KsA11ySettings.vue` | 202, 252 (ADR-007 comments) | ✓ stable |
| K-001 config flag | `config/kiosk.php` | 31, 48, 95 | ✓ stable |
| K-002 fail-open | `app/Http/Requests/OrderRequest.php` | 60-63 | unchanged P1 |
| K-003 magic IDs | `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` | 1029 | unchanged P1 |
| K-004 substring heuristic | `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` | 907-947 | unchanged P1 |
| Wave Z kiosk diff | `git diff c3ba89863..HEAD -- resources/js/components/frontend/kiosk/` | 0 lines | ✓ |
| Wave Z kiosk commits | `git log c3ba89863..HEAD -- resources/js/components/frontend/kiosk/` | empty | ✓ |
| EN i18n scope | `lang/en/all.php` | +25 cash_session_* | POS only, no kiosk |
| GDPR phone strip | `app/Http/Resources/SimpleOrderResource.php` | 50-56 | admin only, no kiosk |
| Auth token revoke | `app/Http/Controllers/Auth/LoginController.php` | 87-93 | name-scoped, kiosk:order untouched |

---

## §8 — Z2-R2 verdict synthesis

**Z2 GREEN on Wave Z scope.** K-001 P0 remains decisively closed (heal stable through 6 Wave Z commits). K-002/K-003/K-004 P1 unchanged — these are V1.0.1 backlog per Sister-verdict scope, not in Wave Z heal-light expectations.

**0 new P0.** **0 new P1.** **0 Wave-Z-triggered kiosk regressions detected.**

Frozen-zone discipline impeccable: 0 lines changed across the entire kiosk component tree (`resources/js/components/frontend/kiosk/`) between c3ba89863 (Round 1 baseline) and 56204f052 (Round 2 HEAD).

Wave Z heal cross-impact on kiosk : **none observed**. The 3 commits that could plausibly touch kiosk surface (EN i18n / GDPR phone strip / auth token revoke) are each scoped to POS/admin/non-kiosk paths and verified non-disruptive.

**Recommendation for AGGREGATE.md** : Z2 converges with Round 1. No re-open. K-002/K-003/K-004 carry forward to V1.0.1 backlog as already planned. Wave Z completes Z2 scope cleanly.
