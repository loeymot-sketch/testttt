# Ultra-Review Commands — copie-colle direct
**Date :** 2026-05-08
**4 sous-branches review créées localement** ✅

> `/ultrareview` accepte UNIQUEMENT : un nom de branche, un numéro de PR, ou rien (current branch).
> Le projet entier = trop volumineux. J'ai donc créé **4 sous-branches** scopées par batch.
> Chaque sous-branche contient seulement les fichiers de son batch, basée sur `main`.
> Tu vas reviewer **une par une** en utilisant le nom de branche.

---

## ✅ 4 sous-branches prêtes

| Branche | Fichiers | LOC | Focus |
|---|---|---|---|
| `review/batch-1-backend-php` | 18 | +2129 | 🔴 Sécurité backend PHP |
| `review/batch-2-frozen-cart-payment` | 7 | +613 / -85 | 🔴 Frozen-zone Cart+Payment |
| `review/batch-3-greenfield-vue` | 14 | +2479 | 🟠 POC + admin tools + voice |
| `review/batch-4-additive-ds-i18n` | 21 | +2968 / -83 | 🟡 Additive + DS + i18n |

---

# 🔴 BATCH 1/4 — Backend PHP

**Copie cette ligne :**

```
/ultrareview review/batch-1-backend-php
```

**Périmètre (18 fichiers) :**
- `app/Http/Controllers/Admin/UpsellPreviewController.php` (P0 defense-in-depth `match()`)
- `app/Http/Controllers/Admin/KioskThemeController.php`
- `app/Http/Controllers/Frontend/OrderRatingController.php`
- `app/Http/Controllers/Frontend/UpsellRecommendationController.php`
- `app/Services/Recommendation/{Service interface, RuleBasedStrategy, MlPlaceholderStrategy}`
- `app/Models/{OrderRating, Branch}`
- `app/Providers/AppServiceProvider.php`
- `config/recommendation.php`
- 2 migrations (`order_ratings`, `add_active_theme_to_branches`)
- `routes/api.php`
- 4 tests Feature PHPUnit

**Focus si reviewer demande contexte :**
- Sécurité Sanctum abilities + Spatie permissions
- BranchScope strict
- Validation Form Request
- N+1 queries dans RuleBasedStrategy
- Migrations reversible

---

# 🔴 BATCH 2/4 — Frozen-zone Cart + Payment

**Copie cette ligne :**

```
/ultrareview review/batch-2-frozen-cart-payment
```

**Périmètre (7 fichiers) :**
- `resources/js/components/frontend/kiosk/KioskCartComponent.vue` (V1x-1+V1x-3+V1x-6)
- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` (M-4 + V1x-2 + V1x-1)
- `resources/css/kiosk/tokens.css` (3 nouveaux tokens additifs)
- `tests/js/KioskCartRestyle.spec.js` (V1x-6 aria-label assertion)
- 3 plans gate documentation V1x-1/3/6

**Focus si reviewer demande contexte :**
- Owner a explicitement débloqué Cart pour V1x-1+V1x-3+V1x-6 UNIQUEMENT
- 0 modif `<script>` section
- Exactement 3 nouveaux `:aria-label` dans `<template>`
- Payment additive only (pas de modif state machine)
- tokens.css que des additions

---

# 🟠 BATCH 3/4 — Greenfield Vue (POC + admin tools + voice)

**Copie cette ligne :**

```
/ultrareview review/batch-3-greenfield-vue
```

**Périmètre (14 fichiers) :**
- V2-2 POC : `KioskBurgerBuilder.vue` (340) + `KioskBurgerLayer.vue` (116) + `KioskBurgerBuilderPoc.vue` (124)
- V2-5 Phase 2 : `KioskThemeManagerPage.vue` (332) + `KioskThemePreviewCard.vue` (163)
- V2-3 admin : `UpsellPreviewPage.vue` (394)
- V2-4 voice : `KioskVoiceOrderingButton.vue` (269) + `KioskVoiceOrderingDialog.vue` (185)
- 3 router modules admin (kioskBurgerBuilderPoc + kioskThemeAdmin + upsellPreview)
- 3 tests vitest (KioskBurgerBuilder + KioskThemeManagerPage + kioskVoiceOrderingDialog)

**Focus si reviewer demande contexte :**
- vue-draggable-next v2.3.0 default slot pattern (pas `#item`)
- WCAG 2.1.1 keyboard alternative drag-drop (Tab/Enter/arrows)
- ARIA labels source/drop/dialog
- `role="dialog"` + `aria-modal="true"` + focus trap voice dialog
- Mic consent user explicite
- URL relative `admin/kiosk-theme/{branchId}` (PAS `/api/admin/...`)
- Routes admin permission guard router (beforeEnter)

---

# 🟡 BATCH 4/4 — Additive Vue + DS + i18n + bootstrap

**Copie cette ligne :**

```
/ultrareview review/batch-4-additive-ds-i18n
```

**Périmètre (21 fichiers) :**
- 4 composants additive : `KioskCashInstruction` + `KioskConfirmation` + `KioskAdmin` + `KioskIdleScreen`
- 1 greenfield : `KioskSkeletonLoader.vue`
- `bootstrap-kiosk.js` (V2-5 themes init)
- 2 services : `kioskThemeManager.js` (BUG FIX URL) + `kioskVoiceOrdering.js`
- 5 CSS : `global-a11y.css` + 4 themes (`_base`, `standard`, `halloween`, `christmas`)
- 3 i18n : `fr.json` + `en.json` (~106 keys) + `ar.json` (10 partial)
- 5 tests vitest

**Focus si reviewer demande contexte :**
- V2-4 voice flag `isVoiceFeatureEnabled = false` DEFAULT (safe rollout)
- `kioskThemeManager.js` URL relative `admin/kiosk-theme/...` (BUG FIX du `/api/api/`)
- `bootstrap-kiosk.js` short-circuit si `branchId` null (admin pages safe)
- `global-a11y.css` scope `:where()` specificity 0
- Themes CSS leak-safe (toutes rules wrappées par `[data-kiosk-theme="..."]`)
- i18n fr/en symétriques
- 0 keys i18n orphelines

---

## 📋 Workflow

```
1. Copie le bloc /ultrareview du batch courant
2. Colle dans Claude Code (la branche existe déjà localement)
3. Attends le rapport
4. Fix les findings (si applicable) → commit sur claude/blissful-mclean-c915c2
5. Passe au batch suivant
```

**Note importante** : les 4 sous-branches sont basées sur `main`. Si tu fixes un finding, fais-le sur la branche principale `claude/blissful-mclean-c915c2`. Les sous-branches review sont **read-only** (juste pour scoper la review).

---

## ✅ Status à cocher

- [ ] Batch 1/4 lancé (`/ultrareview review/batch-1-backend-php`)
- [ ] Batch 1/4 findings appliqués
- [ ] Batch 2/4 lancé (`/ultrareview review/batch-2-frozen-cart-payment`)
- [ ] Batch 2/4 findings appliqués
- [ ] Batch 3/4 lancé (`/ultrareview review/batch-3-greenfield-vue`)
- [ ] Batch 3/4 findings appliqués
- [ ] Batch 4/4 lancé (`/ultrareview review/batch-4-additive-ds-i18n`)
- [ ] Batch 4/4 findings appliqués
- [ ] Verdict global validé
- [ ] Merge `claude/blissful-mclean-c915c2` vers `main`

---

## 🧹 Cleanup post-merge

Une fois la review terminée et la branche principale mergée, supprime les 4 sous-branches :

```bash
git branch -D review/batch-1-backend-php
git branch -D review/batch-2-frozen-cart-payment
git branch -D review/batch-3-greenfield-vue
git branch -D review/batch-4-additive-ds-i18n
```
