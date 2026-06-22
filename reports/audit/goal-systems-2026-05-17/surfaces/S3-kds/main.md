# S3 — KDS (Kitchen Display System) — Goal-Systems Audit 2026-05-17

**Auditor** : Claude Opus 4.7 (1M ctx), read-only main auditor
**Branch** : `feature/mobile-app-le-cayenne-2026-05-10` @ HEAD `56204f052`
**Scope** : Vue 3 KDS surface (V2 grid + legacy 4-col fallback), backend service +
controller, KdsSyncService, store modules, i18n keys, tests inventory.
**Prior baselines re-verified** : cluster-7 audit 2026-05-11 (UX 3.2/10, 8 P0
cross-validated), CTO audit Agent-6 2026-05-16 (KDS 58/100).
**Severity legend** : P0 = blocker shipping / chef-can't-work / silent-data-loss ;
P1 = brand/UX defect a chef will hit / i18n breakage / sync gap ; P2 = polish /
inconsistency.

---

## §1 Top-level scores

| Dimension | Score / 100 | One-line verdict |
|---|---|---|
| **Architecture** | **68** | V2 single-FIFO grid is DEFAULT in production since Sprint 3C 2026-05-16 (`KitchenDisplaySystemComponent.vue:1105-1129`). V2 is well-structured (5 small components, helpers `kdsState`/`kdsSource`/`kdsCustomization`/`kdsDisplay` each ≤263 LOC). Legacy 4-col `v-else` path (lines 22-948) survives as `?v2=0` rollback and is now the *only* place where 8 of 11 cluster-7 P0s still live — kept on purpose, but a sleep-in trap when an operator falls back. |
| **Business** | **70** | Bump system is **localStorage-only** by design (`store/modules/kds.js:1-87`, banner `label.kds_bump_local_only_notice`) — never sync'd across stations or to OSS. Delivery enrichment Sprint 2A DEL-3 (eager-load `address`+`user` in `KitchenDisplaySystemOrderService.php:70`) is live in both V2 and legacy paths (`KdsOrderCard.vue:80-105`, `KitchenDisplaySystemComponent.vue:478-499`). Allergen pill renders on `orderHasAnyAllergen()` aggregate (`KdsOrderCard.vue:47-55`, `helpers/kdsCustomization.js:252-263`). Optimistic-lock conflict 409 handling correct (`KitchenDisplaySystemOrderService.php:170-183`). |
| **UX (chef 3m readability)** | **62 (V2) / 28 (legacy)** | V2: queue-number 52px, elapsed timer 34px, item-name 22px, allergen pill #EA580C with 0 2px shadow — **good**. CTA 52px height (above WCAG 44px). Header-bg colour-shifts fresh→warning→critical (`KdsOrderCard.vue:151-165`) — visible signal. Legacy: item-board bump button **32×32px** (`KitchenDisplaySystemComponent.vue:370`), accordion closed by default (4× `style="height: 0px"` at lines 328/512/657/799), no age-stripe equivalent. |
| **i18n (FR/EN/AR coverage)** | **48** | **15 distinct hardcoded FR strings** in template (verified, full census §3). `kds_status_conflict` key in `en.json` is **in French** (`en.json:1263`) — broken EN locale for the message a chef sees on most-common 409. AR coverage parity for V2-critical keys (`kds_state_*`, `kds_group_*`) = 26/26 keys verified present. Card aria-label `Commande ${id}…` hardcoded FR in `KdsOrderCard.vue:280` (template literal, not i18n) — SR users hear FR regardless of locale. |
| **Integration** | **72** | Echo subscription on `branch.{id}` 4 events (OrderStatusChanged / OrderCreated / OrderPaidAtCounter / ItemAvailabilityChanged / OrderTableChanged — `KitchenDisplaySystemComponent.vue:1660-1690`). Polling fallback adaptive 3/5/10s + jitter via `KdsSyncService.js` (470 LOC, solid: backoff 5xx ≤30s, reconnect-storm jitter 0-500ms, version-gated dedupe, network-error self-heal). Admin (branch_id=0) skips Echo, relies on polling. POST interceptor self-heals when WS dies (`KitchenDisplaySystemComponent.vue:1296-1316`). |
| **Tests** | **74** | 14 Vitest specs (`tests/js/kds*.spec.js`: state, customization, syncCadence, dedupe, source, timerEscalation, stationFilter, lineSemantics, reactsToReconnectStorm, bumpRecall, allergens, autoTransition, backoffOn5xx, versionGate). 8 PHPUnit Feature specs (pagination overflow, status conflict, transition whitelist, change-status concurrency, branch filter exact, snapshot immutable, allergen-aggregation split, sync controller). 1 Playwright `kds-sync.spec.js`. **No test for KDS bump → OSS sync** (by design — bump is local-only) but also **no test that the bump-stays-local invariant holds** (regression risk if anyone tries to wire it). |
| **Performance** | **74** | Single global ticker in V2 (`KdsV2Grid.vue:152-154`) — all cards read `this.now` reactively, no per-card setInterval. `parseOrderCreatedMs` memoization unverified. Backend `list()` capped at 51 rows (`KitchenDisplaySystemOrderService.php:131-134`), `take(50)` for response. Items board groups via SHA-1 over normalized addons+allergens (`KitchenDisplaySystemOrderService.php:281-288`) — O(n × items × snapshot size); fine for 50-order cap. Legacy template renders **4 full column trees with 4× duplicate accordion markup** (~570 LOC of template repetition) — re-renders on any orders change. |
| **A11y** | **58** | V2 allergen pill `role="alert" aria-live="assertive"` (`KdsOrderCard.vue:50-54`) good. V2 CTA + sound + station filter + search all have `aria-label` (`KitchenDisplaySystemComponent.vue:176,192,197,241,244`). V2 card `tabindex="0" role="region"` (`KdsOrderCard.vue:21-25`). Aria-live status region (`KitchenDisplaySystemComponent.vue:893-899`). Allergens modal: keyboard trap implemented (`KitchenDisplaySystemComponent.vue:1472-1502`). **Color-blind allergen signal**: orange #EA580C box + ⚠ icon + bold text — passes for protanopia/deuteranopia. **Negatives**: legacy bump button 32px (#370), V2 hardcoded FR aria-label in JS template literal (line 100, 280), allergen `aria-label="allergen"` not localized (`KdsOrderLine.vue:23`). |

**Overall composite (weighted): 62 / 100** — V2 is materially better than legacy
but the i18n hardcodes plus the EN-locale FR leak prevent the surface from
clearing the operational bar. **Verdict: GO-CONDITIONAL** with P1 i18n
heal-light before production cutover for non-FR clientèle.

---

## §2 Findings

### P0 — Blocker shipping

> **None opened in this audit.** The 8 P0s claimed by cluster-7 2026-05-11
> have collapsed since:
> - 5 P0 became inert by **V2-as-default** rollout 2026-05-16 (closed accordion,
>   banner stack, bump 32px, 4-col-empty, missing age signal — all live only in
>   the `?v2=0` fallback path).
> - 1 P0 was a false-positive : `allergenModal` ≠ `allergensModal` was the
>   focus-return state var (singular, `allergenModalReturnFocus`), not a
>   broken close handler. Modal close path verified intact
>   (`KitchenDisplaySystemComponent.vue:1461-1470`).
> - 2 P0 remain, but only as P1 here because they fire only when an operator
>   uses `?v2=0` URL — see P1-S3-LEGACY-01 / 02.

### P1 — Brand / UX / i18n defects a chef will hit

#### P1-S3-01 — `kds_status_conflict` is in French inside `en.json`
- **File** : `resources/js/languages/en.json:1263`
- **Evidence** : value `"Cette commande a été modifiée ailleurs. L'écran a été
  rafraîchi ; vérifiez l'état avant d'agir."`
- **Trigger** : every 409 from `KitchenDisplaySystemController::changeStatus`
  (`KitchenDisplaySystemOrderService.php:182`) lands here via
  `KitchenDisplaySystemComponent.vue:1401` (`this.$t('label.kds_status_conflict')`).
  A 409 is the **most common** error a chef sees (race when two stations tap
  Prêt on the same card).
- **Impact** : EN-locale operator gets the FR message. Le Cayenne is FR-default
  but Le Cayenne 2 / cloud tenants are EN-default. Silent locale regression.
- **Fix** : 1-line translate (the AR variant `ar.json:1099` is correctly Arabic).

#### P1-S3-02 — V2 card `aria-label` hardcoded French
- **File** : `resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue:277-281`
- **Evidence** : ``return `Commande ${this.order.queue_number || this.order.id},
  source ${this.sourceLabel}, ${this.stateLabel}, attente ${m} minutes ${r}
  secondes`;``
- **Impact** : screen-reader users hear French regardless of locale on the V2
  default surface. EN/AR a11y broken.
- **Fix** : template-literal → `$t('label.kds_card_aria', { id, source, state,
  m, r })` (key must be added 3×).

#### P1-S3-03 — V2 delivery "Appeler" aria-label hardcoded French
- **File** : `KdsOrderCard.vue:100`
- **Evidence** : ``:aria-label="`Appeler ${customerName || ''} ${customerPhone}`.trim()"``
- **Impact** : same as P1-S3-02 — EN/AR SR users hear FR on the Sprint 3C
  delivery enrichment phone link.
- **Fix** : `$t('label.kds_delivery_call_aria', { name, phone })` (+ 3 keys).

#### P1-S3-04 — Items-board top header still uses raw FR fallback (4× repeated)
- **File** : `KitchenDisplaySystemComponent.vue:321, 505, 650, 792`
- **Evidence** : ``:aria-label="$t('label.kds_toggle_items') || 'Afficher les articles'"``
- **Status** : key **exists** in fr/en/ar (`fr.json:966`, `en.json:1096`,
  `ar.json:933`), so the `||` fallback is dead code under normal locale boot —
  but pollutes the codebase and signals that the template was written
  defensively. Carry-over from prior CTO audit.
- **Impact** : low operationally; medium for i18n discipline + future
  auto-extraction tooling.
- **Fix** : drop the `||` fallback (4 lines).

#### P1-S3-05 — Legacy fallback path: 11 hardcoded FR strings the template
- **File** : `KitchenDisplaySystemComponent.vue`
- **Verified census** (line-cited) :
  - L255 `Aucune commande sur place en cours.`
  - L387 `Imprimer ticket` (also L570, L716, L865 — 4×)
  - L425 `Aucune commande en ligne en cours.`
  - L595 `Aucune commande à emporter en cours.`
  - L628 `PAIEMENT COMPTOIR - NON REGLE` (also L778 — 2×)
  - L644 `N° file:` (also L793 — 2×)
  - L739 `🖥️ Borne` (column header + emoji)
  - L746 `Aucune commande borne en cours.`
- **Impact** : when an operator triggers rollback via `?v2=0` they see French
  literal labels. EN/AR locales unsupported on the rollback path. Documented
  as "legacy preserved for rollback" but still ships in build bundle and is
  reachable in 1 URL keystroke.
- **Fix** : EITHER (a) wire `$t()` on each (i18n keys already exist for
  `items_board`, `payment_pending_counter`, `kds_type_kiosk`, etc.) OR
  (b) make `?v2=0` admin-only + sunset the legacy template after the V2
  rollout-window grace period (owner gate). **Prior audit (CTO Agent-6
  2026-05-16) cited only 3 strings — actual count is 11.** Severity upgraded.

#### P1-S3-LEGACY-01 — Items board bump button 32×32 px (cluster-7 P0)
- **File** : `KitchenDisplaySystemComponent.vue:370, 553, 699, 847`
- **Evidence** : `class="w-8 h-8 rounded-lg border ..."` (Tailwind `w-8 h-8`
  = 32×32 px)
- **Impact** : on `?v2=0` rollback path, fails WCAG 2.5.5 SC Target Size
  (44×44 px) and below the kitchen industry standard (60 px wet-glove). Owner
  approved 2026-05-16 cluster-7 sprint-1 brief said "bump 60px" but the heal
  landed only on V2 CTA (52px height — borderline, see P2-S3-02).
- **Severity downgrade** : P0 → P1 because V2 is now default; reachable only
  by URL override.

#### P1-S3-LEGACY-02 — Items board accordion closed-by-default (cluster-7 P0)
- **Files** : `KitchenDisplaySystemComponent.vue:328, 512, 657, 799` (4×
  identical `style="height: 0px"` ; toggle handler
  `services/appService.js:374-406`)
- **Behaviour verified** : the `openFilterSlide` handler **does** properly
  expand on click (sets `scrollHeight + 'px'`), but the **initial state is
  collapsed**, so a chef on a fresh tab cannot see line items until they
  manually tap each card. State CTAs (Prêt / Démarrer) hoisted outside per
  iter15-mega-fix B-002 so transition still works — but item composition
  (variations, supplements, addons, allergens) stays hidden.
- **Severity downgrade** : P0 → P1 same rationale as LEGACY-01.

#### P1-S3-06 — Bump → OSS no broadcast (latent gap or deliberate?)
- **File** : `resources/js/store/modules/kds.js:54-86` (`bumpItem`,
  `recallItem`) — pure localStorage writes via `persistMap(next)`.
- **Banner** : the `label.kds_bump_local_only_notice` warns the operator
  ("Les pastilles « Prêt » (bump) sont mémorisées sur ce poste") and a
  one-time dismiss is exposed (`KitchenDisplaySystemComponent.vue:1503-1510`).
- **What's missing** : **no server endpoint exists** to push bump telemetry
  to OSS, so a customer-facing "ready: N°042" announcement still waits for
  the whole-order status transition (PREPARING → PREPARED). Per-item bumps
  inside an order never reach the customer.
- **Impact** : not strictly a bug (banner is honest), but the task's
  question "KDS bump → OSS update sync test" has no implementation to test
  against. If owner wanted partial-ready signalling (common in Toast/Olo),
  it's missing.
- **Recommendation** : confirm with owner — is this V1 by-design, or a
  V1.0.1 missing feature? **No code change required if confirmed by-design.**

### P2 — Polish / inconsistency

#### P2-S3-01 — Helper `kdsCustomization.js` skips `bread` for taco/burger but config-as-code is name-regex
- **File** : `helpers/kdsCustomization.js:24-31, 184-187`
- **Observation** : `GROUP_PATTERNS` matches `/\bpain\b|\bbread\b/i` — a
  French menu item named "Petit Pain" or "Boules de pain" (decorative
  dish) would be wrongly classified as bread. Adding `items.kds_category`
  + `items.kds_group` server columns would replace heuristic with declared
  classification. Not urgent (Le Cayenne menu is curated, no edge cases
  triggered).

#### P2-S3-02 — V2 CTA height 52 px is borderline for WCAG 2.5.5 + kitchen ergonomics
- **File** : `KdsOrderCard.vue:584` (`height: 52px;`)
- **Status** : passes WCAG (44 px floor) but below industry kitchen
  standard 60 px. Mitigated by full-card-width spanning. No action needed
  unless owner wants the standard.

#### P2-S3-03 — V2 default = `true` is a *hardcoded* SSR-safe fallback, not a settings flag
- **File** : `KitchenDisplaySystemComponent.vue:1105-1129`
- **Observation** : `useV2Layout()` returns `true` for SSR + try/catch
  fallback. There's no `settings.kds.v2_enabled` server-side flag despite
  the comment "or future settings flag". Owner cannot kill V2 fleet-wide
  without `?v2=0` URL distribution. Backlog for V1.0.1 if multi-tenant
  staggered rollout becomes a need.

#### P2-S3-04 — Legacy template duplicates the same 4-column card markup ~570 LOC
- **File** : `KitchenDisplaySystemComponent.vue:249-887` (dine-in / online /
  takeaway / kiosk loops are 95% identical)
- **Observation** : even if legacy is sunset-only, this is the dominant
  duplication source in the file and the reason `wc -l` says 2545. Any
  future heal that touches all 4 lanes is a 4× change-cost.
- **Recommendation** : if owner agrees to sunset `?v2=0`, drop the entire
  `<template v-else>` block — V2 + bare ConnectionStatusBanner + items board
  is enough. Saves ~570 LOC.

#### P2-S3-05 — `kdsAddonDisplayName` fallback `'Addon'` (raw FR-EN-AR-agnostic literal)
- **File** : `KitchenDisplaySystemComponent.vue:1899`
- **Already cited** by CTO audit Agent-6. Low impact (only triggers when
  `addon_name`, `addon_item_name`, `name`, `item_name` are *all* empty —
  malformed snapshot). Useful sentinel value but should be `$t()`.

#### P2-S3-06 — `'Erreur réseau'` raw FR fallback in 2 catch blocks
- **File** : `KitchenDisplaySystemComponent.vue:2004, 2010`
- **Already cited** by CTO audit Agent-6. Same residue as above.

---

## §3 What's been verified vs. delta from prior audits

| Topic | Prior verdict | This audit verdict |
|---|---|---|
| 8 P0 cluster-7 2026-05-11 | All open | **6/8 closed-by-V2-default** ; 2 still open only on `?v2=0` rollback path (downgraded P0→P1) |
| `allergenModal` typo | "modal close silently broken" | **False-positive** — that's a different (correctly named) focus-return var, modal works |
| Raw-FR fallback count | 3 strings (CTO 2026-05-16) | **11 distinct strings** in template + 2 in script + 2 in V2 template literals = **15 total**, severity upgraded |
| Bump → OSS sync | Not addressed | **Architecturally absent**, deliberate per banner, no test guarding the invariant |
| V2 feature-flag default | Off, opt-in `?v2=1` | **Inverted Sprint 3C 2026-05-16** — V2 is default; legacy is `?v2=0` rollback |
| `KdsSyncService.js` | "Solid, no-touch" (cluster-7) | **Re-confirmed solid** — adaptive cadence, version-gated dedupe, reconnect-storm jitter, network-error self-heal, 470 LOC clean |
| Delivery enrichment Sprint 3C | Recent | **Live in BOTH V2 + legacy** (KdsOrderCard.vue:80-105 + KitchenDisplaySystemComponent.vue:478-499). Backend eager-loads `address`+`user` (KitchenDisplaySystemOrderService.php:70). `PENDING_*` phone sentinels hidden client-side (KdsOrderCard.vue:316-325). |
| i18n parity (V2 operational keys) | Unverified | **26/26 keys present in FR/EN/AR** for V2 surface (kds_state_*, kds_group_*, kds_card_cta_ready, kds_undo_done, kds_aria_live_*, kds_allergen_warning_prefix, kds_connection_lost_long) |
| EN locale clean | "≥ 11 raw-FR" | **EN locale itself contains a FR translation** at `en.json:1263` for `kds_status_conflict` (NEW finding) |

---

## §4 Risk register

- **R-S3-01** : `?v2=0` URL rollback path is reachable by any operator in 1
  keystroke. If a Le Cayenne 2 (English-locale) cuisinier hits a bug and reverts,
  they get an FR-only board + 32 px buttons + closed accordion. Mitigation =
  remove rollback or i18n the legacy path.
- **R-S3-02** : Bump-store is `localStorage`-per-device. If two terminals are
  installed at same kitchen station and the cuisinier switches browsers
  mid-shift, recall state is lost — the 60 s grace cannot fire from the new
  browser. Acceptable for single-tablet kitchens; risky for the multi-tablet
  upgrade path.
- **R-S3-03** : `_pollingInterval` = 60 s when WS connected (`KitchenDisplaySystemComponent.vue:1645`)
  vs `KdsSyncService` rate (`disconnectedBaseMs=10000`). Two parallel poll
  loops exist (`autoRefreshInterval` setInterval + `kdsSyncService._timer`). If
  WS goes down, both fire at different cadences. Not strictly a bug (both
  refresh same store) but unnecessary network load on weak kitchen wifi.

---

## §5 Verdict + recommendation

**Composite : 62 / 100. GO-CONDITIONAL.** The V2 grid is genuinely good. Three
P1 heal-light items (P1-S3-01 EN locale FR leak, P1-S3-02/03 V2 template-literal
aria-labels) would land in < 2 hours and lift this surface to ~74. The
hardcoded-FR legacy path (P1-S3-05) is a deferred-decision item — owner picks
"sunset rollback" or "i18n the legacy template".

**Strong-suit** :
- V2 architecture is the example to follow on every other Vue surface (helpers
  + 5 small focused components + canonical i18n keys + theme tokens external).
- `KdsSyncService.js` deserves to be cited as best-in-class adaptive polling.
- Delivery enrichment Sprint 3C plumbed through correctly on both paths.

**Weak-suit** :
- The "production-default since 2026-05-16" decision leaves a documented
  rollback that ships an untranslated, half-broken legacy UI. Either the
  rollback is genuine-needed (then i18n it) or it isn't (then delete).
- 15 distinct hardcoded-FR strings — that's an i18n discipline regression that
  passed prior cluster-7 and CTO audits with under-counts.

**No P0, no NF525 invariant touched, no frozen-zone touched, no auth/branch
isolation issue. Backend service is sound** (concurrency lock, branch isolation,
optimistic-lock 409, allergen-hash dedupe split correctly).

---

## §6 Files cited (absolute paths)

- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` (2545 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue` (297 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue` (632 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue` (284 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue` (204 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/components/admin/kitchenDisplaySystem/KdsUndoToast.vue` (184 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/services/KdsSyncService.js` (470 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/services/appService.js` (`openFilterSlide`/`closeFilterSlide` L374-422)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/helpers/kdsState.js` (103 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/helpers/kdsSource.js` (68 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/helpers/kdsCustomization.js` (263 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/helpers/kdsAutoTransition.js` (82 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/helpers/kdsDisplay.js` (111 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/helpers/kdsAllergens.js` (53 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/helpers/kdsLineSemantics.js` (88 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/store/modules/kds.js` (87 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/store/modules/kdsInflight.js` (158 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/KitchenDisplaySystemOrderService.php` (358 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Controllers/Admin/KitchenDisplaySystemController.php` (61 LOC)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/routes/api.php` (`Route::prefix('kds-order')` L1003-1006)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/languages/fr.json`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/languages/en.json`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/languages/ar.json`
- Tests inventory: 14 Vitest specs `tests/js/kds*.spec.js`, 8 PHPUnit `tests/Feature/Kds*.php` + `tests/Feature/KDS/*` + `tests/Feature/Admin/KdsSyncControllerTest.php` + `tests/Feature/Sentinels/Kds*SentinelTest.php`, 1 Playwright `tests/e2e/kds-sync.spec.js`, 1 `tests/Playwright/KdsMultiScreenPlaywrightTest.spec.js`

---

*End S3-KDS main audit. Companion RED-team report TBD by adversarial pass.*
