# Ultra-Review Commands — copie-colle direct
**Branche :** `claude/blissful-mclean-c915c2`

> **Mode d'emploi simple :**
> 1. Copie le bloc `BATCH N` complet (3-4 lignes)
> 2. Colle dans Claude Code / Cursor
> 3. Attends la review
> 4. Applique les findings
> 5. Passe au batch suivant
>
> Chaque batch est limité en taille pour passer sous le seuil "project too big".

---

## ⚠️ Si la commande `/ultrareview` n'existe pas chez toi

Test ces 3 alternatives dans cet ordre — utilise celle qui marche :

1. **`/ultra-review`** (avec tiret)
2. **`/code-review`** (plugin code-review officiel Claude)
3. **`/review-pr`** (plugin pr-review-toolkit officiel Claude)

Si aucune ne marche, colle juste le **TEXTE en gras** sans slash command — Claude le lit comme une demande de review.

---

# 🔴 BATCH 1/4 — Backend PHP (security)

```
/ultrareview Ultra review backend PHP de cette branche claude/blissful-mclean-c915c2. Focus sécurité P0 : auth + branch isolation + defense-in-depth (match() vs Str::studly) + N+1 queries. Périmètre exact : app/Http/Controllers/Admin/UpsellPreviewController.php, app/Http/Controllers/Admin/KioskThemeController.php, app/Http/Controllers/Frontend/OrderRatingController.php, app/Http/Controllers/Frontend/UpsellRecommendationController.php, app/Services/Recommendation/, app/Models/OrderRating.php, app/Models/Branch.php, app/Providers/AppServiceProvider.php, config/recommendation.php, database/migrations/2026_05_08_050000_create_order_ratings_table.php, database/migrations/2026_05_08_060000_add_active_theme_to_branches.php, routes/api.php, tests/Feature/Frontend/OrderRatingTest.php, tests/Feature/Recommendation/UpsellRecommendationTest.php, tests/Feature/Admin/UpsellPreviewControllerTest.php, tests/Feature/Admin/KioskThemeControllerTest.php. Vérifier : 1) Sanctum abilities + Spatie permissions sur chaque endpoint 2) BranchScope strict 3) Validation Form Request 4) Migrations reversible 5) Tests PHPUnit edge cases. Verdict attendu : MERGE / HEAL / BLOCK avec liste actions correctives.
```

---

# 🔴 BATCH 2/4 — Frozen-zone Cart + Payment

```
/ultrareview Ultra review frozen-zone discipline branche claude/blissful-mclean-c915c2. Owner a explicitement débloqué KioskCartComponent UNIQUEMENT pour V1x-1 (spacing tokens) + V1x-3 (image clamp Option A) + V1x-6 (aria-label Option B extensive). KioskPaymentComponent territory agent F-002/F-008/F-009 = additive scope-minimal seul. Périmètre : resources/js/components/frontend/kiosk/KioskCartComponent.vue, resources/js/components/frontend/kiosk/KioskPaymentComponent.vue, resources/css/kiosk/tokens.css, tests/js/KioskCartRestyle.spec.js, plans/PLAN_DESIGN_V1X1_SPACING_TOKENS_2026-05-08.md, plans/PLAN_DESIGN_V1X3_CART_IMAGE_RESPONSIVE_2026-05-08.md, plans/PLAN_DESIGN_V1X6_CART_VARIATIONS_ARIA_2026-05-08.md. Vérifier : 1) Cart : 0 modif script section, exactement 3 nouveaux aria-label 2) Payment : 0 modif state machine + appels API 3) tokens.css : que additions (3 nouveaux : --kiosk-space-7, --kiosk-space-11, --kiosk-opacity-disabled) 4) V1x-3 Option A : 1080p inchangé strict 64x64. Verdict attendu : GATE RESPECTED / DRIFT MINOR / DRIFT MAJOR.
```

---

# 🟠 BATCH 3/4 — Greenfield Vue (POC + admin tools + voice)

```
/ultrareview Ultra review composants Vue 2 greenfield branche claude/blissful-mclean-c915c2. 4 features : V2-2 POC drag-drop ingrédients (admin route hors prod), V2-5 Phase 2 theme manager admin UI, V2-3 admin upsell preview QA tool, V2-4 voice ordering dialog. Périmètre : resources/js/components/frontend/kiosk/builder/KioskBurgerBuilder.vue, resources/js/components/frontend/kiosk/builder/KioskBurgerLayer.vue, resources/js/components/frontend/kiosk/builder/KioskBurgerBuilderPoc.vue, resources/js/components/admin/kioskTheme/KioskThemeManagerPage.vue, resources/js/components/admin/kioskTheme/KioskThemePreviewCard.vue, resources/js/components/admin/upsellPreview/UpsellPreviewPage.vue, resources/js/components/frontend/kiosk/KioskVoiceOrderingButton.vue, resources/js/components/frontend/kiosk/KioskVoiceOrderingDialog.vue, resources/js/router/modules/kioskBurgerBuilderPocRoutes.js, resources/js/router/modules/kioskThemeAdminRoutes.js, resources/js/router/modules/upsellPreviewRoutes.js, tests/js/KioskBurgerBuilder.spec.js, tests/js/KioskThemeManagerPage.spec.js, tests/js/kioskVoiceOrderingDialog.spec.js. Vérifier : 1) vue-draggable-next v2.3.0 default slot pattern (pas #item) 2) A11y WCAG 2.1.1 keyboard alternative drag-drop (Tab/Enter/arrows) 3) ARIA labels source/drop/dialog 4) Voice dialog role=dialog + aria-modal=true + focus trap 5) Mic consent user explicite 6) URL admin/kiosk-theme relative (pas /api/admin/...) 7) Routes admin permission guard router 8) prefers-reduced-motion respecté. Verdict attendu : MERGE-READY / HEAL / BLOCK.
```

---

# 🟡 BATCH 4/4 — Additive Vue + DS + i18n + bootstrap

```
/ultrareview Ultra review additive discipline branche claude/blissful-mclean-c915c2. KioskIdleScreen reçoit voice CTA additif (default OFF safe rollout). KioskCash/Confirmation/Admin reçoivent polish Wave Alpha. Bootstrap-kiosk init themes V2-5. CSS tokens additifs strict. Périmètre : resources/js/components/frontend/kiosk/KioskCashInstructionComponent.vue, resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue, resources/js/components/frontend/kiosk/KioskAdminComponent.vue, resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue, resources/js/components/frontend/kiosk/KioskSkeletonLoader.vue, resources/js/bootstrap-kiosk.js, resources/js/services/kioskThemeManager.js, resources/js/services/kioskVoiceOrdering.js, resources/css/kiosk/global-a11y.css, resources/css/kiosk/themes/_base.css, resources/css/kiosk/themes/standard.css, resources/css/kiosk/themes/halloween.css, resources/css/kiosk/themes/christmas.css, resources/js/languages/fr.json, resources/js/languages/en.json, resources/js/languages/ar.json, tests/js/KioskSkeletonLoader.spec.js, tests/js/KsButton.spec.js, tests/js/kioskAdminA11ySection.spec.js, tests/js/kioskThemeManager.spec.js, tests/js/kioskVoiceOrdering.spec.js. Vérifier : 1) V2-4 voice flag default false (pas true) 2) kioskThemeManager.js URL relative admin/kiosk-theme/ (BUG FIX critique du /api/api/) 3) bootstrap-kiosk short-circuit si branchId null (admin pages safe) 4) global-a11y.css scope :where() specificity 0 5) Themes CSS leak-safe (toutes rules wrappées par [data-kiosk-theme]) 6) i18n fr/en symétriques sur ~106 keys ajoutées 7) Pas de keys i18n orphelines 8) Skeleton 4 types tous utilisés. Verdict attendu : MERGE-READY / HEAL / BLOCK.
```

---

## 📋 Workflow par batch

| Étape | Action |
|---|---|
| 1 | Copie tout le bloc ` ``` ` du batch (3-15 lignes incluant la commande complète) |
| 2 | Colle dans Claude Code / Cursor (ou ton outil ultra-review) |
| 3 | Attends le rapport |
| 4 | Applique les actions correctives |
| 5 | Commit les fixes éventuels |
| 6 | Passe au batch suivant |

**Estimation :**
- Batch 1 : ~30 min review
- Batch 2 : ~15 min review
- Batch 3 : ~40 min review
- Batch 4 : ~25 min review
- **Total : ~110 min**

---

## ✅ Status à cocher

- [ ] Batch 1/4 lancé
- [ ] Batch 1/4 findings appliqués
- [ ] Batch 2/4 lancé
- [ ] Batch 2/4 findings appliqués
- [ ] Batch 3/4 lancé
- [ ] Batch 3/4 findings appliqués
- [ ] Batch 4/4 lancé
- [ ] Batch 4/4 findings appliqués
- [ ] Verdict global validé
- [ ] Push branch + gh pr create
