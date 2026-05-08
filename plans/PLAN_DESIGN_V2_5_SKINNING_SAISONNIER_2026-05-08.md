# PLAN — V2-5 Skinning saisonnier kiosk

**Date** : 2026-05-08
**Owner** : Wave Gamma G3 — Design / Frontend / Backend
**Statut** : V1.x scaffolding livré (greenfield), V1.y ajout de 5 thèmes restants programmé
**Effort cible (V1.x scaffolding)** : 2-3 h
**Effort V1.y (5 thèmes Pâques / Saint-Valentin / FIFA / été plage / + un de réserve)** : 5-7 j

---

## 1. Vision

Permettre au kiosk FoodKing de **changer d'identité visuelle au fil de l'année**
(Halloween, Noël, FIFA, Saint-Valentin, Pâques, été plage…) sans recompile,
sans toucher aux composants Vue (notamment les **8 wizard frozen owner**, ni
KioskApp / KioskPayment), et **sans dégrader l'a11y AAA** déjà en place.

**Pourquoi maintenant**
- Benchmark McDo / Burger King FR : engagement kiosk **+10..15 %** pendant
  les fenêtres saisonnières (Halloween, Noël, FIFA), avec ticket moyen +3..5 %.
- KPI direct : **time-on-app** + **upsell rate** mesurés par
  `kioskAnalytics.js` (déjà instrumenté).
- Marge : opérationnellement gratuite (pas d'OPS, pas de rebuild) une fois
  le framework en place.

**Non-objectifs V1.x**
- Pas de **scheduling automatique** (ex. flip auto le 1er décembre) — V2.
- Pas de **preview admin** dans le dashboard — V1.y.
- Pas de **per-kiosk-machine theme** (uniquement per-branch) — V2 si demandé.
- Pas de **A/B test** entre 2 thèmes — V2.

---

## 2. Architecture (livré V1.x)

```
┌─────────────────────────────────────────────────────────────────────┐
│  Admin Dashboard            Kiosk runtime                           │
│  ──────────────────         ─────────────────                       │
│                                                                     │
│  PATCH /api/admin/          GET /api/admin/                         │
│  kiosk-theme/{branchId}     kiosk-theme/{branchId}     (public)     │
│        │                            │                               │
│        ▼                            ▼                               │
│  KioskThemeController.update    KioskThemeController.show           │
│  permission:settings            (no auth, no permission)            │
│  + branch isolation             + slug whitelist (stale-safe)       │
│        │                            │                               │
│        ▼                            ▼                               │
│  branches.active_kiosk_theme        kioskThemeManager.initialize()  │
│  (NULL → 'standard')                priority:                       │
│                                       1. localStorage (sticky)      │
│                                       2. backend (above)            │
│                                       3. 'standard' (fallback)      │
│                                            │                        │
│                                            ▼                        │
│                                     document.documentElement        │
│                                       .setAttribute(                │
│                                         'data-kiosk-theme',         │
│                                         '<slug>')                   │
│                                            │                        │
│                                            ▼                        │
│                              CSS  resources/css/kiosk/themes/       │
│                                   <slug>.css scoped to              │
│                                   :root[data-kiosk-theme="<slug>"]  │
│                                   → override --kiosk-* tokens       │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

**Trois couches indépendantes** :

1. **CSS contract** (`resources/css/kiosk/themes/_base.css`) : déclare les
   variables AUTORISÉES à override (couleurs, surfaces, accents, déco) et
   INTERDITES (a11y, sémantique métier, badges, focus, z-index, durations).
2. **JS manager** (`resources/js/services/kioskThemeManager.js`) : pose
   `data-kiosk-theme` sur `<html>` au boot avec une priorité documentée
   et un fallback gracieux pour tous les cas dégradés (pre-login, axios
   absent, backend down, slug stale).
3. **Backend admin endpoint** (`KioskThemeController`) : un GET public
   pour le boot kiosk, un PATCH gated `permission:settings` avec branch
   isolation pour la maintenance admin.

**Discipline** :
- 0 modification des 8 wizard kiosk frozen owner (KioskWizardComponent &
  composants associés).
- 0 modification de KioskApp / KioskPayment (zone agent).
- 0 modification du POS wizard (Vanilla JS strict).
- Aucun token de sémantique (success/error/warning), focus, badge, ou
  spacing n'est override-able par un thème — verrouillé via le contract.
- Migration **additive** (column nullable, idempotence via `hasColumn`).

---

## 3. Phases

### V1.x — scaffolding (livré 2026-05-08)
- [x] Migration `branches.active_kiosk_theme` (nullable, additive).
- [x] Branch model : `$fillable` + `$casts`.
- [x] Theme contract `_base.css` (allowed/disallowed token map).
- [x] 3 thèmes exemples : `standard.css` (fallback), `halloween.css`,
      `christmas.css`.
- [x] Service JS `kioskThemeManager.js` (apply / persist / load /
      initialize / setTheme / forceRefreshFromBackend).
- [x] Endpoint admin `KioskThemeController` (show public, update gated +
      branch isolation + audit log).
- [x] PHPUnit tests : default fallback / valid slug / 403 staff /
      422 invalid / branch isolation / branch-scoped admin own branch.
- [x] Vitest specs : apply / unknown→fallback / persist / load (stale)
      / initialize priority / forceRefresh / network failure.
- [x] Plan + doc.

### V1.y — batch 5 thèmes (5-7 j, après validation V1.x)
- [ ] `paques.css` — pastels printanier (avril).
- [ ] `saint-valentin.css` — rouge/rose/blanc (1-14 février).
- [ ] `fifa.css` — bleu marine + or sur blanc (juin/juillet selon coupe).
- [ ] `ete-plage.css` — turquoise / soleil (juillet/août).
- [ ] `+ 1 de réserve` (printemps neutre / nouveau menu).
- [ ] Mettre à jour `SUPPORTED_THEMES` (JS + PHP `KioskThemeController::SUPPORTED_THEMES`).
- [ ] Assets SVG patterns (`/images/themes/*.svg`) — léger (< 4 KB chacun).
- [ ] Tests d'a11y obligatoires par thème (Axe + contrast checker manuel).

### V2 — automation (après stabilité V1.y)
- [ ] **Schedule auto** : table `kiosk_theme_schedules`
      `(branch_id, slug, starts_at, ends_at)` consommée par un job cron qui
      flip `branches.active_kiosk_theme` selon le calendrier.
- [ ] **Push live** : event `KioskThemeChanged` Pusher → kiosks call
      `kioskThemeManager.forceRefreshFromBackend(branchId)` sans restart.
- [ ] **Admin preview** : iframe sandbox dans le dashboard pour visualiser
      avant push.
- [ ] **A/B test** : 2 thèmes répartis 50/50 sur le parc kiosks d'une branche.

---

## 4. Sub-tasks atomic (V1.x — déjà accomplis)

| Sub-task                                                         | Fichier                                                                                  | Statut |
| ---------------------------------------------------------------- | ---------------------------------------------------------------------------------------- | ------ |
| Migration `branches.active_kiosk_theme`                          | `database/migrations/2026_05_08_060000_add_active_theme_to_branches.php`                 | ✓      |
| Branch model fillable + casts                                    | `app/Models/Branch.php`                                                                  | ✓      |
| Theme contract documentation                                     | `resources/css/kiosk/themes/_base.css`                                                   | ✓      |
| Default theme (no-op)                                            | `resources/css/kiosk/themes/standard.css`                                                | ✓      |
| Halloween theme (orange / violet)                                | `resources/css/kiosk/themes/halloween.css`                                               | ✓      |
| Christmas theme (rouge / vert sapin)                             | `resources/css/kiosk/themes/christmas.css`                                               | ✓      |
| JS manager singleton (apply / persist / load / initialize)       | `resources/js/services/kioskThemeManager.js`                                             | ✓      |
| Backend controller (show public + update gated)                  | `app/Http/Controllers/Admin/KioskThemeController.php`                                    | ✓      |
| Routes wired (public read + admin patch)                         | `routes/api.php`                                                                         | ✓      |
| PHPUnit feature test                                             | `tests/Feature/Admin/KioskThemeControllerTest.php`                                       | ✓      |
| Vitest spec                                                      | `tests/js/kioskThemeManager.spec.js`                                                     | ✓      |
| Doc procédure ajout d'un thème                                   | `docs/KIOSK_THEMES.md`                                                                   | ✓      |
| Plan d'exécution                                                 | `plans/PLAN_DESIGN_V2_5_SKINNING_SAISONNIER_2026-05-08.md`                               | ✓      |

---

## 5. Tests required

### Backend (PHPUnit, `tests/Feature/Admin/KioskThemeControllerTest.php`)
- [x] `default_theme_is_standard_when_branch_unset`
- [x] `show_returns_standard_for_unknown_branch_no_404` (graceful fallback)
- [x] `show_returns_stored_theme_when_valid`
- [x] `show_falls_back_to_standard_when_stored_slug_unknown` (stale-safe)
- [x] `admin_can_set_active_theme`
- [x] `staff_without_permission_cannot_set_theme_403`
- [x] `invalid_theme_rejected_422`
- [x] `branch_isolation_admin_branch_a_cannot_set_branch_b_via_param`
- [x] `branch_scoped_admin_can_set_own_branch`

### Frontend (Vitest, `tests/js/kioskThemeManager.spec.js`)
- [x] `applyThemeSetsDataAttribute`
- [x] `unknownThemeFallsBackToStandard` (warning console attendu)
- [x] `isSupportedTheme matches the public allowlist`
- [x] `persistsToLocalStorage`
- [x] `load returns null when storage is empty`
- [x] `load filters stale slugs not in allowlist`
- [x] `setTheme applies and persists in one call`
- [x] `initializeUsesLocalStorageFirst`
- [x] `initialize falls through to backend when localStorage is empty`
- [x] `initialize falls back to standard when both localStorage and backend are empty`
- [x] `initialize falls back to standard when backend returns unknown slug`
- [x] `initialize survives missing branchId without crashing`
- [x] `initialize survives axios reject (backend down) gracefully`
- [x] `initialize survives missing window.axios entirely`
- [x] `forceRefreshFromBackend ignores cached localStorage`

### A11y (manuel par thème, V1.y)
- [ ] Contraste texte/fond ≥ 4.5:1 sur body, ≥ 3:1 sur large.
- [ ] Focus ring contraste ≥ 3:1 sur la nouvelle `--kiosk-bg` ET la nouvelle
      `--kiosk-surface`.
- [ ] La couleur primaire ne doit pas se confondre avec une couleur sémantique
      (rouge erreur / vert succès / orange warning).

---

## 6. Risks

| Risque                                                | Sévérité | Mitigation                                                                                                  |
| ----------------------------------------------------- | -------- | ----------------------------------------------------------------------------------------------------------- |
| Thème violant l'a11y (contraste insuffisant)          | Haute    | Contract `_base.css` interdit override des tokens focus/sémantique ; QA Axe obligatoire avant publish.      |
| Clash avec brand (CTA primaire halloween orange ≈ warning) | Moyenne  | Documenter dans `KIOSK_THEMES.md` + revue design sur chaque thème ; rollback = 1 PATCH `theme=standard`.    |
| LCP dégradé par image pattern                          | Moyenne  | Limite 4 KB SVG/PNG par pattern ; preload optionnel via `<link rel="preload">` ; activable per-thème.       |
| Cache localStorage stale pendant période active        | Moyenne  | Documenté V2 (push Pusher) ; mitigation V1 = cycle nuit (kiosk redémarre, cache cleared) + force-refresh.   |
| Kiosk avec slug stale sur thème supprimé              | Faible   | Whitelist serveur + JS — le service `show()` retombe sur `'standard'`, le manager filtre côté `load()`.     |
| Migration impactant prod                              | Faible   | Column nullable + `hasColumn` guard ; pas de backfill ; déploiement sans downtime.                          |
| Branch isolation manquante                            | Critique | Test `branch_isolation_admin_branch_a_cannot_set_branch_b_via_param` couvre ; head-office (`branch_id=0`) seul autorisé cross-branch. |
| Permission gate inexistante (`settings_manage` ≠ `settings`) | Critique | **Corrigé** : utilisation de `permission:settings` (gate déjà seedée par `seedSpatieRoles()` et présente en prod). |

---

## 7. Effort

- **V1.x scaffolding** : 2-3 h (livré).
- **V1.y batch 5 thèmes** : 5-7 j incluant design, assets, a11y QA.
- **V2 schedule auto + push live** : 2-3 j.

---

## 8. Activation côté Phase 2 (hors scope V1.x)

L'import des CSS thèmes dans `resources/js/bootstrap-kiosk.js` est repoussé
à la Phase 2 (alignée sur le commentaire d'activation déjà présent dans ce
fichier). Les fichiers thèmes existent dès V1.x mais ne sont pas chargés
tant que le bootstrap kiosk n'est pas câblé sur `app.js` / l'entry kiosk.
Cela évite tout effet visuel sur les écrans kiosk existants pendant la
phase greenfield.

Procédure (Phase 2, en même temps que le restyle Vue) :

```js
// resources/js/bootstrap-kiosk.js — Phase 2
import '../css/kiosk/themes/_base.css';
import '../css/kiosk/themes/standard.css';
import '../css/kiosk/themes/halloween.css';
import '../css/kiosk/themes/christmas.css';

import themeManager from './services/kioskThemeManager';
// branchId issu du store kiosk login / route param
themeManager.initialize(branchId);
```

---

## 9. Liens

- [`docs/KIOSK_THEMES.md`](../docs/KIOSK_THEMES.md) — procédure d'ajout d'un thème.
- [`resources/css/kiosk/themes/_base.css`](../resources/css/kiosk/themes/_base.css) — contract.
- [`resources/js/services/kioskThemeManager.js`](../resources/js/services/kioskThemeManager.js) — manager.
- [`app/Http/Controllers/Admin/KioskThemeController.php`](../app/Http/Controllers/Admin/KioskThemeController.php) — backend.
