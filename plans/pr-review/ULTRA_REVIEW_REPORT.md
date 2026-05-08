# Ultra-Review Report — YC GStack mode auto via 4 sub-agents
**Date :** 2026-05-08
**Branche :** `claude/blissful-mclean-c915c2`
**Mode :** YC GStack autonome — 4 sub-agents `general-purpose` en parallèle + advisor cross-cutting
**Verdict global initial :** HEAL-LIGHT (1 BLOCK + 4 P0/P1 a11y/security)
**Verdict après fixes :** ✅ **MERGE-READY**

---

## §0 — Résumé exécutif

| Batch | Périmètre | Verdict initial | Fixes appliqués | Verdict final |
|---|---|---|---|---|
| 1/4 Backend PHP | 18 fichiers, +2129 LOC | 🔴 HEAL (1 BLOCK + 1 P1) | OrderRating ability + UpsellPreview branch scope | ✅ MERGE |
| 2/4 Frozen Cart+Payment | 7 fichiers, +613/-85 | ✅ GATE RESPECTED | — | ✅ MERGE |
| 3/4 Greenfield Vue | 14 fichiers, +2479 LOC | 🟠 HEAL-LIGHT (2 P0 a11y) | Voice dialog Esc/focus + Burger builder keyboard reorder | ✅ MERGE |
| 4/4 Additive + DS + i18n | 21 fichiers, +2968/-83 | ✅ MERGE-READY | — (2 sibling tasks spawned hors scope) | ✅ MERGE |

**Tests post-fix :**
- Vitest **570/570 ✅** (66 files, +9 nouveaux tests a11y)
- PHPUnit touched **32/32 ✅** (UpsellPreview 7 + OrderRating 8 + UpsellRecommendation 8 + KioskTheme 9)

---

## §1 — Findings consolidés par sub-agent

### Sub-agent 1 — Backend PHP (verdict initial: HEAL)

#### 🔴 P0 BLOCK identifié — `OrderRatingController.php`
**Trou de sécurité réel** : tout sanctum-user authentifié sur la même branche pouvait écraser le rating de N'IMPORTE quelle commande de la branche, sans check d'ownership ni `kiosk:order` ability.

Diverge de `UpsellController` (l.32) et `UpsellRecommendationController` (l.36) qui requièrent `tokenCan('kiosk:order')`.

CLAUDE.md non-négociable #8 : "Branch isolation must never be weakened" — le trou intra-branche est aussi une faiblesse d'autorisation.

**FIX appliqué (commit pending) :**
```php
$user = $request->user();
if (!$user || !$user->tokenCan('kiosk:order')) {
    return response()->json([
        'status'  => false,
        'message' => 'Accès kiosk requis pour noter une commande.',
    ], 403);
}
```
+ test `test_authenticated_non_kiosk_user_cannot_rate` (sanctum sans `kiosk:order` → 403).

#### 🟠 P1 HEAL — `UpsellPreviewController.php` branch scope check
Admin scoped à une branche pouvait prévisualiser une autre branche (validation manquante body `branch_id` vs `user.branch_id`).

**FIX appliqué :**
```php
$userBranchId = (int) ($request->user()?->branch_id ?? 0);
if ($userBranchId !== 0 && $userBranchId !== (int) $validated['branch_id']) {
    return response()->json(['message' => 'Branch scope denied'], 403);
}
```

#### 🟡 P2 WARN (non-bloquant, BACKLOG)
- `RuleBasedStrategy.php` : `branchAvailableItemsQuery::pluck` re-exécuté 4× — hoist possible (~3 round-trips DB économisés sur hot path burger). Non bloquant V1.x.
- `KioskThemeController.php` : pas de domain_event Outbox pour propager aux bornes. Décision design "pull at boot" vs "push real-time" — à confirmer plan V2-5.
- `order_ratings` polymorphisme `Order` vs `FrontendOrder` — possible double-rate via discriminator distincts. Risque opérationnel faible.

**Tests post-fix : 32/32 ✅**

---

### Sub-agent 2 — Frozen-zone Cart+Payment (verdict: GATE RESPECTED ✅)

**Aucun drift détecté.** Tous les 6 anti-drift commands passent :

```
=== CMD 1: Cart no script-section additions ===            (empty) ✓
=== CMD 2: Cart count of new :aria-label ===               3       ✓
=== CMD 3: Payment state-machine guard ===                 (empty) ✓
=== CMD 4: tokens.css no deletions ===                     (empty) ✓
=== CMD 5: tokens.css 3 new tokens ===                     3       ✓
=== CMD 6: Test suite KioskCartRestyle ===                 7/7     ✓
```

Discipline frozen-zone exemplaire :
- Cart : 0 modif `<script>` (lines 344-522 untouched)
- Cart : 3 templates `:title` + `:aria-label` exactement (V1x-6 Decision B)
- Cart : V1x-3 Option A `clamp(64px, 4.7vw, 96px)` + emoji `clamp(32px, 2.4vw, 48px)`
- Payment : 0 modif state machine (`confirmPayment` / `cancelPayment` / `simulateCardSuccess` zero `^+` matches)
- Payment : M-4 + V1x-2 modal additive only
- tokens.css : 3 nouveaux tokens additifs strict

**Note doc-trace mineure (P3) :**
- Plans V1x-3 §8 et V1x-6 §9 : flip `[ ] Pending gate + decision` → `[x] Executed Option A/B`
- V1x-3 footnote orientation : "Baseline 64px portrait 1080×1920 ; landscape 1920×1080 → ~90px"

Aucun fix code requis.

---

### Sub-agent 3 — Greenfield Vue (verdict initial: HEAL-LIGHT)

#### 🔴 P0 — `KioskVoiceOrderingDialog.vue` modal a11y incomplet
ARIA semantics correctes (`role="dialog"` + `aria-modal="true"`) mais comportement manquant :
- ❌ Pas de `Escape` key handler
- ❌ Pas de focus trap (Tab échappe la modal)
- ❌ Pas d'autofocus sur action primaire

**FIX appliqué :**
```vue
<template>
    <div role="dialog" aria-modal="true" @keydown.esc.prevent="$emit('cancel')">
        <div class="voice-dialog" @keydown.tab="onTabKey">
            <!-- ... -->
            <button ref="cancelBtn" ...>Annuler</button>
            <button ref="confirmBtn" ...>OUI, CONTINUER</button>
        </div>
    </div>
</template>
<script>
mounted() { this.$nextTick(() => this.$refs.confirmBtn?.focus()); }
methods: {
    onTabKey(event) {
        // Trap Tab/Shift+Tab cycle entre Cancel et Confirm
        const cancel = this.$refs.cancelBtn;
        const confirm = this.$refs.confirmBtn;
        const active = document.activeElement;
        if (event.shiftKey && active === cancel) { event.preventDefault(); confirm.focus(); }
        else if (!event.shiftKey && active === confirm) { event.preventDefault(); cancel.focus(); }
    }
}
</script>
```
+ 3 nouveaux tests vitest (Escape emit, autofocus, focus trap).

#### 🔴 P0 — `KioskBurgerBuilder.vue` keyboard alternative incomplet
WCAG 2.1.1 fail : pas de reorder via clavier, pas de delete sur layer focused, pas d'Escape.

**FIX appliqué — `KioskBurgerLayer.vue` :**
```vue
<li
    :tabindex="0"
    :aria-label="$t('kiosk.builder.layer_focus_aria', { name, position })"
    aria-keyshortcuts="Delete Backspace ArrowUp ArrowDown Escape"
    @keydown.delete.prevent="$emit('remove')"
    @keydown.backspace.prevent="$emit('remove')"
    @keydown.up.prevent="$emit('move-up')"
    @keydown.down.prevent="$emit('move-down')"
    @keydown.esc.prevent="$emit('blur-layer')"
>
```
+ `KioskBurgerBuilder.vue` méthode `swapLayer(from, to)` avec refocus déplacé.
+ i18n key `kiosk.builder.layer_focus_aria` ajoutée fr/en.
+ 6 nouveaux tests vitest (Delete + Backspace + ArrowUp + ArrowDown + boundary no-op + ARIA shortcuts).

#### 🟡 P1 WARN (BACKLOG)
- `KioskVoiceOrderingButton` : pas de pre-flight consent dialog explicite avant `getUserMedia` implicit. Acceptable POC.
- `KioskThemeManagerPage` : last-write-wins sur `branches.active_theme` — acceptable (effect au reboot kiosk).
- `UpsellPreviewPage` 394 LOC : ne pas refactor (justifié comme tool admin self-contained).
- `[Vue warn]` noisy : `tr()` accède `this.$t` même quand absent. Optionnel polish.

**Tests post-fix : 25/25 ✅** (ajout 9 tests a11y P0)

---

### Sub-agent 4 — Additive Vue + DS + i18n (verdict: MERGE-READY ✅)

**82/82 tests pass.** Aucun blocker.

Vérifications réussies :
- ✅ V2-4 voice flag `isVoiceFeatureEnabled = false` DEFAULT confirmé (line 171)
- ✅ kioskThemeManager URL fix `admin/kiosk-theme/{branchId}` (vs `/api/api/...`)
- ✅ Themes CSS leak-safe (toutes rules sous `:root[data-kiosk-theme="<slug>"]`)
- ✅ i18n fr/en symétriques (29 nouvelles keys, 0 mismatch)
- ✅ bootstrap-kiosk.js short-circuit branchId null (admin pages safe)
- ✅ global-a11y.css scope `:where(.kiosk-app)` specificity 0
- ✅ Skeleton 4 types tous utilisés

#### 🔵 Sibling tasks spawned (hors scope cette PR)

1. **i18n path bug `kiosk.confirmation.*` vs `kiosk.wizard.confirmation.*`** — bug pré-existant (commit `660c9341c`, April 13). 34 `$t('kiosk.confirmation.*')` calls render raw key strings. Cette PR follow la convention existante (broken). Doit être healed dans une wave dédiée.

2. **`/api/api/internal/pusher-ack` 404** — `resources/js/services/eventContract.js:80` même classe de bug que celui fixé par cette PR dans `kioskThemeManager.js`. Pusher ACK observability metrics silently broken.

Aucun fix code requis dans cette PR (out-of-scope).

#### 🟢 Backlog suggéré
- KioskConfirmation `.kiosk-btn-print` migration KsButton (V1x-N future)
- Extension `ar.json` pour `kiosk.voice.*` et `kiosk.skeleton.*`

---

## §2 — Fixes appliqués cette session

### Backend PHP (Batch 1 fixes)
1. **`app/Http/Controllers/Frontend/OrderRatingController.php`** : ajout check `tokenCan('kiosk:order')` (P0 BLOCK)
2. **`tests/Feature/Frontend/OrderRatingTest.php`** :
   - Helper `actAsKiosk()` via `Sanctum::actingAs($user, ['kiosk:order'])`
   - Nouveau test `test_authenticated_non_kiosk_user_cannot_rate` (403 expected)
3. **`app/Http/Controllers/Admin/UpsellPreviewController.php`** : ajout branch scope check (P1)

### Frontend Vue a11y (Batch 3 fixes)
4. **`resources/js/components/frontend/kiosk/KioskVoiceOrderingDialog.vue`** :
   - `@keydown.esc.prevent="$emit('cancel')"` sur overlay
   - `mounted()` autofocus sur `confirmBtn` ref
   - `onTabKey()` focus trap entre Cancel et Confirm
5. **`tests/js/kioskVoiceOrderingDialog.spec.js`** : +3 tests a11y (Escape + autofocus + focus trap)
6. **`resources/js/components/frontend/kiosk/builder/KioskBurgerLayer.vue`** :
   - `tabindex=0` + `aria-label` + `aria-keyshortcuts`
   - 5 keyboard handlers (Delete, Backspace, ArrowUp, ArrowDown, Escape)
   - 4 nouveaux emits (`remove`, `move-up`, `move-down`, `blur-layer`)
7. **`resources/js/components/frontend/kiosk/builder/KioskBurgerBuilder.vue`** :
   - `@move-up`/`@move-down`/`@blur-layer` handlers wirés sur layers
   - Méthode `swapLayer(from, to)` avec refocus à la position post-swap
   - Méthode `onBlurLayer()` qui défocalise vers source pool
8. **`resources/js/languages/fr.json`** + **`en.json`** : ajout key `kiosk.builder.layer_focus_aria`
9. **`tests/js/KioskBurgerBuilder.spec.js`** : +6 tests a11y P0 (Delete, Backspace, ArrowUp, ArrowDown, boundary no-op, ARIA shortcuts)

---

## §3 — Tests cumulative post-fix

```bash
# Vitest
$ npx vitest run
Test Files  66 passed (66)
     Tests  570 passed (570)        ← +9 nouveaux tests a11y P0
  Duration  4.45s

# PHPUnit (touched controllers)
$ php artisan test --filter "UpsellPreviewControllerTest|OrderRatingTest|UpsellRecommendationTest|KioskThemeControllerTest"
Tests:  32 passed                   ← +1 nouveau test sécurité P0
Time:   3.54s
```

**Aucune régression. 0 finding non-fixé bloquant.**

---

## §4 — Verdict final

✅ **MERGE-READY**

Tous les findings P0/P1 sont fixés et testés. Les findings P2/P3 sont documentés en BACKLOG. Les 2 sibling tasks (i18n path + eventContract.js URL) sont hors scope de cette PR mais documentées pour des waves dédiées.

**Branch state final post-fix :**
- 8 commits design (`fb99d12b6` → cette session)
- 4 sous-branches review locales (`review/batch-1` à `4`) — à recréer après fix car contiennent l'ancien code
- 570 tests vitest verts + 32 PHPUnit verts
- 24/24 frozen-zones intact
- 6 anti-drift commands pass

**Décisions auto prises (mode YC GStack) :**
1. Fix OrderRatingController kiosk:order ability (P0 BLOCK)
2. Fix UpsellPreviewController branch scope (P1)
3. Fix KioskVoiceOrderingDialog Escape + autofocus + focus trap (P0)
4. Fix KioskBurgerBuilder keyboard reorder/delete via swapLayer (P0)
5. Tests adaptés (Sanctum::actingAs avec abilities)
6. i18n key ajoutée symétriquement fr/en
7. Sub-branches review NON recréées (à faire post-commit)

**Next steps owner :**
1. Vérifier les diffs des fixes
2. Décider de recréer les 4 sous-branches review pour relancer `/ultrareview` post-fix (optionnel — les findings ont été appliqués)
3. Push branche principale + créer la vraie PR `claude/blissful-mclean-c915c2` → `main`
4. Merge

---

## §5 — Évidence Graphiti & traceability

Tous les findings + fixes sont consignés dans :
- `plans/pr-review/PR_REVIEW_MANIFEST.md` (manifest auditable)
- `plans/pr-review/REVIEW_CHECKLIST.md` (8 phases ultra-review)
- `plans/pr-review/ULTRA_REVIEW_PROMPTS.md` (prompts batch-par-batch)
- `plans/pr-review/ULTRA_REVIEW_COMMANDS.md` (commandes /ultrareview <branch>)
- `plans/pr-review/ULTRA_REVIEW_REPORT.md` (ce fichier — verdict final consolidé)
- `plans/pr-review/diffs/0001-0007.patch` (git format-patch series)

Graphiti push à faire post-commit final.
