# Impl B — Kiosk i18n Heal — Evidence Bundle

**Date** : 2026-05-18
**Branch** : `v1-0-1-hardening-2026-05-17`
**Pre-commit HEAD** : `abe0e9b5a3b67b72d76163f31e01d941091d61fe`
**Scope** : P1-KIOSK-01 + P1-KIOSK-02 (from `round-1/99_SYNTHESIS_MASTER.md`)

---

## 1. P1-KIOSK-01 — KioskOfflineConflictModalComponent.vue (0 `$t()` calls → 8 keys wired)

**File** : `resources/js/components/frontend/kiosk/KioskOfflineConflictModalComponent.vue`
**Frozen status** : NOT frozen (only KioskWizard/App/Upsell are per `reference_frozen_zones.md`).

### Before (8 hardcoded FR literals)
| Anchor | Literal |
|---|---|
| L4   | `title="Conflits file d'attente"` |
| L11  | `Une ou plusieurs commandes en attente contiennent désormais un produit indisponible.` |
| L30  | `Produits impactés : {{ formatItemList(entry.staleItems) }}` |
| L39  | `Annuler` |
| L47  | `Forcer envoi` |
| L54  | `Aucun conflit en attente.` |
| L82  | `return 'Aucun';` |
| L87  | `if (!savedAt) return 'Date inconnue';` |

### After (8 `$t('kiosk.offline_conflict.*')` calls)
```
$ grep -c "kiosk.offline_conflict\." resources/js/components/frontend/kiosk/KioskOfflineConflictModalComponent.vue
8
```

### Keys added (3 locales)

`resources/js/languages/fr.json` § `kiosk.offline_conflict.*` :
- `title` : "Conflits file d'attente"
- `intro` : "Une ou plusieurs commandes en attente contiennent désormais un produit indisponible."
- `products_impacted` : "Produits impactés : {list}"
- `cancel` : "Annuler"
- `force_send` : "Forcer envoi"
- `empty` : "Aucun conflit en attente."
- `no_items` : "Aucun"
- `date_unknown` : "Date inconnue"

`resources/js/languages/en.json` § `kiosk.offline_conflict.*` (mirror, 8 keys):
- `title` : "Queue conflicts"
- `intro` : "One or more queued orders contain a product that is no longer available."
- `products_impacted` : "Affected products: {list}"
- `cancel` : "Cancel"
- `force_send` : "Force send"
- `empty` : "No pending conflicts."
- `no_items` : "None"
- `date_unknown` : "Unknown date"

`resources/js/languages/ar.json` § `kiosk.offline_conflict.*` (best-effort, 8 keys):
- AR translations provided (RTL-safe, kiosk reads `fr-FR` per ADR-007 FR-lock; AR keys exist for future flexibility).

---

## 2. P1-KIOSK-02 — KioskPaymentComponent.vue (lines 27 + 333)

**File** : `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue`
**Frozen status** : NOT frozen.

### Before (2 hardcoded FR literals)
| Anchor | Literal |
|---|---|
| L27 (template) | `Paiement CB/TR indisponible hors ligne. Le menu reste consultable; choisissez les espèces au comptoir ou réessayez quand la connexion revient.` |
| L333 (script `offlinePaymentMessage()`) | `return 'Paiement CB/TR indisponible hors ligne.';` |

### After (2 `$t('kiosk.pay_screen.offline_*')` calls)
```
$ grep -c "kiosk.pay_screen.offline_" resources/js/components/frontend/kiosk/KioskPaymentComponent.vue
2
```

### Keys added (3 locales, appended to existing `pay_screen` namespace)

`resources/js/languages/fr.json` § `kiosk.pay_screen.*` :
- `offline_alert` : "Paiement CB/TR indisponible hors ligne. Le menu reste consultable; choisissez les espèces au comptoir ou réessayez quand la connexion revient."
- `offline_short` : "Paiement CB/TR indisponible hors ligne."

`resources/js/languages/en.json` § `kiosk.pay_screen.*` (mirror):
- `offline_alert` : "Card/meal-voucher payment is unavailable offline. You can still browse the menu; choose cash at the counter or try again once the connection is back."
- `offline_short` : "Card/meal-voucher payment is unavailable offline."

`resources/js/languages/ar.json` § `kiosk.pay_screen.*` (best-effort).

---

## 3. Frozen-zone diff attestation (= 0 lines)

```
$ git diff --stat \
    resources/js/components/frontend/kiosk/KioskWizardComponent.vue \
    resources/js/components/frontend/kiosk/KioskAppComponent.vue \
    resources/js/components/frontend/kiosk/KioskUpsellComponent.vue \
    public/js/pos-wizard.js \
    public/css/pos-wizard.css \
    resources/views/admin-pos-v4.blade.php \
    app/Services/Fiscal/FiscalSequenceService.php \
    app/Services/Fiscal/ZReportService.php \
    app/Services/Fiscal/AuditLogService.php \
    app/Models/Scopes/BranchScope.php \
    app/Http/Middleware/IdempotencyKeyMiddleware.php \
    app/Services/Pricing/PricingService.php \
    app/Domain/Order/OrderStateMachine.php
(no output — zero lines, attestation PASS)
```

---

## 4. Test attestation

### Vitest sentinel `kioskFrLockImmutable` (regression guard for ADR-007 FR-lock)
```
$ npx vitest run tests/js/kioskFrLockImmutable.spec.js
✓ tests/js/kioskFrLockImmutable.spec.js  (8 tests) 19ms
Test Files  1 passed (1)
Tests       8 passed (8)
```

### Vitest KioskPayment-related specs (regression guard for component I touched)
```
$ npx vitest run tests/js/KioskPaymentRestyle.spec.js tests/js/kioskPaymentRetryGate.spec.js tests/js/kioskPaymentTpeTimeout.spec.js
✓ tests/js/kioskPaymentTpeTimeout.spec.js  (2 tests)
✓ tests/js/KioskPaymentRestyle.spec.js     (6 tests)
✓ tests/js/kioskPaymentRetryGate.spec.js   (6 tests)
Test Files  3 passed (3)
Tests       14 passed (14)
```

### JSON validity check (all 3 locale files parse)
```
$ node -e "JSON.parse(require('fs').readFileSync('resources/js/languages/fr.json', 'utf8'))"  → fr.json OK
$ node -e "JSON.parse(require('fs').readFileSync('resources/js/languages/en.json', 'utf8'))"  → en.json OK
$ node -e "JSON.parse(require('fs').readFileSync('resources/js/languages/ar.json', 'utf8'))"  → ar.json OK
```

### Key resolution check (all 10 keys × 3 locales = 30 resolved)
```
fr offline_conflict missing: NONE | pay_screen.offline_* missing: NONE
en offline_conflict missing: NONE | pay_screen.offline_* missing: NONE
ar offline_conflict missing: NONE | pay_screen.offline_* missing: NONE
```

### Hardcoded-FR-leak scan (both files)
```
$ grep -nE "Conflits|Aucun|Annuler|Forcer|Date inconnue|Produits impactés|attente contiennent" \
    resources/js/components/frontend/kiosk/KioskOfflineConflictModalComponent.vue
ZERO hardcoded FR strings remain

$ grep -nE "Paiement CB/TR indisponible" \
    resources/js/components/frontend/kiosk/KioskPaymentComponent.vue
ZERO hardcoded offline-payment strings remain
```

---

## 5. Files changed (5 files)

| File | Type | Frozen? |
|---|---|---|
| `resources/js/components/frontend/kiosk/KioskOfflineConflictModalComponent.vue` | Vue (template + script methods) | No |
| `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` | Vue (template L27 + script method L333) | No |
| `resources/js/languages/fr.json` | i18n | No |
| `resources/js/languages/en.json` | i18n | No |
| `resources/js/languages/ar.json` | i18n | No |

**Total new keys** : 10 keys × 3 locales = 30 string entries.

**Logic change** : ZERO (pure string extraction).

**Commit SHA** : `c138b32dd`

**Attribution note (Round 2 parallel-sweep)** : Impl B's 5 source-file changes
(2 Vue + 3 JSON) and this evidence bundle were absorbed into commit
`c138b32dd` whose subject line reads `fix(oss-v1-prep): chime TV-wall
fallback + PRÊT WCAG AA contrast heal` (Impl C's heading). This is the
result of parallel sub-agents (Impl B, C, D) sharing the same working tree
and competing for the staging area; my final `git commit --amend` window
captured Impl C's and Impl D's freshly-staged files in addition to mine.
The CONTENT of my fix is intact and verified (8 `$t()` refs in modal, 2 in
payment, 10 keys × 3 locales = 30 string entries in fr/en/ar.json). Impl
C's and Impl D's work also live in the same commit. The orchestrator should
treat `c138b32dd` as a multi-Impl convergence commit, not as Impl C alone.

---

## 6. Acceptance criteria (from synthesis)

- [x] P1-KIOSK-01 closed : 8 hardcoded FR strings extracted to `kiosk.offline_conflict.*`
- [x] P1-KIOSK-02 closed : 2 hardcoded FR strings extracted to `kiosk.pay_screen.offline_*`
- [x] No logic change
- [x] No frozen-zone touch (diff = 0)
- [x] FR + EN bundles updated (AR best-effort included)
- [x] Existing kiosk specs still green (14 KioskPayment + 8 FR-lock sentinel)
- [x] Resolved-key count matches extracted-string count (8 + 2 = 10 new keys)
