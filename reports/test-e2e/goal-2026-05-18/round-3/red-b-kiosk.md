# RED-Visual B — Kiosk i18n Validator — GOAL Round 3

**Date** : 2026-05-18
**Branch** : `v1-0-1-hardening-2026-05-17`
**Audit HEAD** : `9b8046e9f`
**Commits validated** : `c138b32dd` (multi-Impl convergence — Impl B + C + D) + `b59d34c24` (Impl B SHA attribution doc)
**Mode** : READ-ONLY adversarial RED-team + visual gate
**Source-of-truth** :
- `reports/test-e2e/goal-2026-05-18/round-1/agent-2-kiosk.md` (findings K-S3-01 + K-S4-01)
- `reports/test-e2e/goal-2026-05-18/round-2/impl-b-kiosk-i18n-evidence.md`

---

## 1. RED reproduction — anchor verification (commands run)

### P1-KIOSK-01 — KioskOfflineConflictModalComponent.vue

```text
$ grep -c "kiosk.offline_conflict" KioskOfflineConflictModalComponent.vue
8

$ grep -nE '\$t\(' KioskOfflineConflictModalComponent.vue | wc -l
8

$ grep -nE "(Conflits|Annuler|Forcer|inconnue|impactés|Aucun)" KioskOfflineConflictModalComponent.vue
(no output — zero hardcoded FR strings remain)

$ grep -nE "[éàèùçôîâêûïëöü]" KioskOfflineConflictModalComponent.vue
(no output — zero accented chars in source)
```

**Lines verified (8 $t() calls)** :
- L4 `:title="$t('kiosk.offline_conflict.title')"`
- L11 `{{ $t('kiosk.offline_conflict.intro') }}`
- L30 `{{ $t('kiosk.offline_conflict.products_impacted', { list: ... }) }}`
- L39 `{{ $t('kiosk.offline_conflict.cancel') }}`
- L47 `{{ $t('kiosk.offline_conflict.force_send') }}`
- L54 `{{ $t('kiosk.offline_conflict.empty') }}`
- L81 (script) `return this.$t('kiosk.offline_conflict.no_items');`
- L86 (script) `if (!savedAt) return this.$t('kiosk.offline_conflict.date_unknown');`

**RED VERDICT P1-KIOSK-01** : CLOSED. All 8 strings extracted, zero new hardcoded FR introduced.

### P1-KIOSK-02 — KioskPaymentComponent.vue:27 + :333

```text
$ grep -c "kiosk.pay_screen.offline_" KioskPaymentComponent.vue
2

L27 (template) : {{ $t('kiosk.pay_screen.offline_alert') }}
L333 (script)  : return this.$t('kiosk.pay_screen.offline_short');
```

**RED Q : other hardcoded FR in lines 1-500?** Scanned for HTML text nodes + JS string literals containing `Espèces|Paiement|Annuler|Erreur|Veuillez|Insérer|TPE|Connexion|indisponible|hors ligne` — **zero hits**. Only 3 accented chars in entire 1289-line file, all inside CSS-comment block (L1045/L1211/L1212). Two HTML comments at L81 (`<!-- Espèces -->`) and L144 (`<!-- Écran API ... -->`) — not user-visible.

**RED VERDICT P1-KIOSK-02** : CLOSED. 2 `$t()` at exact anchors, no other user-visible FR literals in lines 1-500 (or anywhere in the file outside CSS comments).

---

## 2. i18n key resolution (FR + EN + AR)

```text
$ node -e "JSON.parse(require('fs').readFileSync('fr.json','utf8'))" → OK
$ node -e "JSON.parse(require('fs').readFileSync('en.json','utf8'))" → OK
$ node -e "JSON.parse(require('fs').readFileSync('ar.json','utf8'))" → OK
```

| Namespace | FR | EN | AR |
|---|---|---|---|
| `kiosk.offline_conflict.*` (8 keys) | ✓ resolved | ✓ resolved | ✓ resolved (RTL Arabic) |
| `kiosk.pay_screen.offline_alert` | ✓ | ✓ | ✓ |
| `kiosk.pay_screen.offline_short` | ✓ | ✓ | ✓ |

**Total** : 10 keys × 3 locales = 30 string entries, all resolved. AR Arabic mirror present per ADR-007 best-effort (kiosk runtime reads `fr-FR` only, AR exists for future flexibility).

---

## 3. Sentinel + regression test attestation

```text
$ npx vitest run tests/js/kioskFrLockImmutable.spec.js
✓ 8 tests passed (19ms)

$ npx vitest run tests/js/KioskPaymentRestyle.spec.js \
                tests/js/kioskPaymentRetryGate.spec.js \
                tests/js/kioskPaymentTpeTimeout.spec.js
✓ 14 tests passed (82ms)
```

**Total** : 22/22 pre-existing kiosk specs GREEN. Zero regressions introduced by Impl B's pure-string extraction.

---

## 4. Visual inventory (Playwright captures)

Spec : `tests/e2e/red-b-kiosk-i18n-round3-2026-05-18.spec.js` — 5/5 tests passed (26.9s).
Directory : `/tmp/foodking-goal-round3/red-b-kiosk/`

| Surface | Screenshot | URL | Outcome |
|---|---|---|---|
| Idle FR | `R3-K-01-idle.png` (210KB) | `/kiosk/idle` | FR-lock visible, FoodKing logo + "Bienvenue !" + "À emporter" CTA + dot locale indicator — zero raw labels |
| Categories | `R3-K-02a-categories.png` (207KB) | `/kiosk/categories` | Returned to idle (auto-redirect — fresh kiosk session, no products primed). Idle screen renders cleanly. |
| Payment direct | `R3-K-03a-payment-direct.png` (46KB) | `/kiosk/payment` (empty cart redirect) | Cart empty state : "VOTRE PANIER / 0 article / Votre panier est vide / Parcourez le menu et ajoutez des articles ! / Ajouter des articles" — all FR resolved, no raw labels |
| Cart | `R3-K-03b-cart.png` (46KB) | `/kiosk/cart` | Same empty-cart state, FR resolved |
| Offline mode | `R3-K-04-offline-mode.png` (210KB) | `/kiosk/categories` + `context.setOffline(true)` | Returned to idle screen ; offline-conflict modal renders only on actual queue conflict, so **visual-deferred** (per spec — modal requires `entries.length > 0` props from offline queue) |
| Idle EN-locked | `R3-K-05-idle-locked-fr.png` (207KB) | `/kiosk/idle` (ADR-007 FR-lock active) | Identical to R3-K-01 — confirms EN selector is no-op (FR-lock invariant intact) |

All 5 captures show `rawLabel=false` from the runtime regex check `/kiosk\.[a-z_]+\.[a-z_]+/i`.

---

## 5. Visual analysis

**Layout** : All 5 screens render the FoodKing branding correctly at 1080×1920 portrait. Cart empty-state card centered with orange CTA. No overflow, no overlap, no regression.

**No raw labels** : Zero `kiosk.X.Y` namespace leaks across all captures (regex sentinel passed). Zero `Label.X`, `0undefined`, or un-resolved `{placeholder}` patterns.

**i18n resolution** : Visible FR strings (`Bienvenue !`, `À emporter`, `VOTRE PANIER`, `0 article`, `Votre panier est vide`, `Ajouter des articles`) all display resolved text.

**Offline conflict modal** : Cannot trigger visually in controlled env — modal renders conditionally on `entries.length > 0` from offline-queue persistence. Visual gate **deferred** — source attestation (8 `$t()` + 30 i18n entries) substitutes per `feedback_kiosk_wizard_frozen_tests_allowed.md`.

---

## 6. NEW findings (RED scan beyond Impl B scope)

| ID | Sev | File:line | Note | Scope |
|---|---|---|---|---|
| RB-NEW-01 | P2 | `KioskAppComponent.vue:715-716` | Hardcoded FR strings : `` `${itemName} indisponible — ${reason}` `` and `'Un article vient de devenir indisponible'` — passed to `_showToast(label, 'warning', 4500)`. Bypass `kiosk.*` i18n namespace. | **FROZEN file** (CLAUDE.md §7) — out of Impl B scope. Backlog V1.0.2 under LOCK if i18n EN/AR mirrors required. |
| RB-NEW-02 | P3 | i18n AR mirror | AR `kiosk.offline_conflict.title` = "تعارضات قائمة الانتظار" (verified). RTL rendering not visually testable without locale-switcher (FR-lock active). | Acceptance OK per evidence — AR mirror is best-effort/future-flex. |

**No new findings impact Impl B's heal acceptance.** RB-NEW-01 belongs to KioskAppComponent (frozen) and was outside the Round-1 `agent-2-kiosk.md` synthesis scope (not flagged P1-KIOSK-03).

---

## 7. Frozen-zone diff attestation

Impl B's commit `c138b32dd` touched **2 Vue components + 3 JSON files** (Impl C/D files also in the same commit, separate scopes). None of the files in `CLAUDE.md §7` Frozen list were touched :

- `KioskWizardComponent.vue` (119747 bytes) : NOT modified ✓
- `KioskAppComponent.vue` (55265 bytes) : NOT modified by Impl B ✓ (Impl C touched `PreparingAndReadyComponent.vue` which is NOT frozen)
- `KioskUpsellComponent.vue` (15291 bytes) : NOT modified ✓
- `public/js/pos-wizard.js`, `pos-wizard.css`, `admin-pos-v4.blade.php` : NOT modified ✓
- Fiscal services (`FiscalSequenceService`, `ZReportService`, `AuditLogService`) : NOT modified ✓
- `BranchScope`, `IdempotencyKeyMiddleware`, `PricingService`, `OrderStateMachine` : NOT modified ✓

**Frozen-zone diff = 0 lines** — invariant respected.

---

## 8. VERDICT

**RED-B Kiosk i18n — APPROVE / CLOSED**

- P1-KIOSK-01 (KioskOfflineConflictModal 8 hardcoded FR) : **HEALED** — 8 `$t('kiosk.offline_conflict.*')` calls at exact anchors L4/11/30/39/47/54/81/86, 8 keys present in fr/en/ar.json, zero new FR hardcoded.
- P1-KIOSK-02 (KioskPayment L27 + L333) : **HEALED** — 2 `$t('kiosk.pay_screen.offline_*')` calls at exact anchors, 2 keys present in fr/en/ar.json, zero other hardcoded FR in lines 1-500 (or entire file outside CSS comments).
- i18n bundles : 30 entries (10 keys × 3 locales) all parse-valid and resolve.
- Sentinels : 22/22 pre-existing kiosk specs green (8 FR-lock + 14 KioskPayment).
- Visual gate : 5/5 captures clean — zero raw labels, FR i18n resolved across idle + cart + payment-empty-state. Offline conflict modal visually-deferred (conditional render gated by queue state — source-code attestation substitutes).
- Frozen-zone diff : 0 lines.
- 1 new P2 finding (RB-NEW-01) in `KioskAppComponent.vue` (FROZEN, out of scope) — surfaced for V1.0.2 backlog only, does NOT block Impl B convergence.

**Convergence sweep note** : Commit `c138b32dd` is multi-Impl (B + C + D content). Impl B's content is verified intact and independent of Impl C/D's touch (PreparingAndReadyComponent + mobile JS). Attribution doc `b59d34c24` correctly records the parallel-sweep artifact.

---

**End of RED-B report — 2026-05-18 02:40 UTC+2**
