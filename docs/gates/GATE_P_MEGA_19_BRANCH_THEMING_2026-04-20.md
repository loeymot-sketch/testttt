# GATE_BRIEF P-MEGA-19 — Branch theming (Phase C.2 du cycle W7)

**Date** : 2026-04-20  
**Source audit** : `reports/execution/AUDIT_P_MEGA_19_BRANCH_THEMING_BASELINE_2026-04-20.md`  
**HEAD** : `9c8f9e202`  
**Mode** : HUMAN_GATE — décision business obligatoire avant tout EXECUTE  
**Estimation post-gate** : ~800–1800 LOC selon réponses business  

---

## 0. Pourquoi ce GATE

Le plan source P-MEGA-19 prévoit **theming par branche** (logo, couleurs, idle video). L'audit C.1 a révélé que :

1. **Rien n'existe** au niveau branche dans la DB ni dans les CSS tokens ni dans l'API.
2. Le thème kiosk actuel est **global** (table `settings` via Spatie media-library, ressources `SettingResource.theme_logo` + `kiosk_idle_video`).
3. Spatie media-library **est déjà installée** (`composer.json` `^10.5`) — peut être étendue à `Branch`.
4. **BUG sub-jacent identifié** (out-of-scope mais à signaler) : `KioskAppComponent.loadBranch()` prend **arbitrairement** `data[0]` (`id desc`) sans contrôle de la branche physique → si plusieurs branches existent, le kiosk peut afficher la mauvaise.

Ces faits imposent que le **business** définisse les contours **AVANT** code (architecture, scope, granularité, fallback). Sinon risque de livrer un système inutilisable ou trop ambitieux pour le besoin réel.

---

## 1. Décisions business attendues

### Q1 — Scope assets requis

| Asset | Variantes possibles | Recommandation technique |
|-------|--------------------|--------------------------|
| **Logo** | (a) 1 seul logo PNG/SVG, (b) couple light/dark, (c) horizontal/vertical/icon | (b) light+dark = bon compromis (kiosk noir kiosk blanc) |
| **Couleurs** | (a) primary seule, (b) primary + secondary, (c) palette 8-10 (primary, secondary, success, warning, danger, neutral 4 nuances) | (b) suffit pour 90% des cas. (c) si forte identité par franchise |
| **Idle video** | (a) MP4 seul, (b) MP4 + WebM (compatibilité), (c) MP4 + image poster fallback | (c) car kiosk peut être hors-réseau (utiliser poster avant chargement) |

**Risque si non décidé** : implémenter (a) puis devoir refondre pour (c) = double travail.

### Q2 — Architecture stockage

| Option | Avantage | Inconvénient |
|--------|----------|--------------|
| **Spatie media-library sur `Branch`** (recommandé) | Cohérent avec ThemeSetting, gestion conversions/responsive auto | Lourdeur si just 3-4 fichiers |
| **Colonnes URL `theme_logo_url` + `Storage::put`** | Simple, peu de migration | Pas de gestion versions, manuel |
| **S3 / CDN externe** | Scalable multi-tenant, performance | Coût + dépendance réseau pour kiosk hors-ligne |

**Recommandation technique** : Spatie media-library + disque `s3` (si déjà configuré) ou `public` (sinon). Cache local IDB si offline-critique (cf. Q6).

### Q3 — Granularité

- **Branche uniquement** (le plus simple) ?
- **Hiérarchie** : tenant/franchise → branche (override) ?

**Risque** : si franchises multi-restaurants, modèle plat = beaucoup de duplication. Si franchise = niveau intermédiaire, modèle hiérarchique est utile mais complique l'admin UX.

### Q4 — Workflow admin

- **Self-service** : manager de branche peut uploader son logo/couleurs ?
- **Centralisé** : seul super-admin peut modifier ?
- **Modération** : changement nécessite validation avant propagation ?

**Recommandation technique** : permissions Laravel-permission `branches.theme.update` + flag `theme_published` sur Branch + hook audit.

### Q5 — Fallback

- Logo manquant → logo global FoodKing ? logo blanc ? null (cassé) ?
- Idle video manquante → image poster ? video globale ? gradient (actuel) ?
- Couleur manquante → tokens globaux `--kiosk-*` (recommandé) ?

**Risque** : oublier fallback = kiosk avec zone vide ou crash à l'affichage.

### Q6 — Performance kiosk

- **Préchargement** au boot kiosk (1 seule fois) — meilleur UX mais boot plus lent ?
- **Lazy load** au besoin (idle screen seulement) — boot rapide mais flash possible ?
- **Cache local IDB** offline support — si kiosk hors-réseau, theme reste affiché ?

**Recommandation technique** : preload logo au boot (taille faible), lazy idle video (taille variable), cache IDB optionnel si exigence offline forte.

### Q7 — Audit / historique

- Tracker `who_uploaded_what_when` (Activity log) ?
- Versions précédentes accessibles (rollback) ?

**Recommandation technique** : Spatie activitylog (déjà disponible si installé) + Spatie media-library garde versions par défaut.

### Q8 — Cohérence cross-surface

- POS / KDS / kiosk utilisent **tous** le même theme branch ?
- Receipt fiscal NF525 imprime logo branche ?

**Important NF525** : `ReceiptDataService` (SSOT depuis le wire-in 2026-05-18 — voir `tests/Feature/Receipt/ReceiptDataServiceWireInTest.php`) n'imprime pas de logo (juste texte : SIRET, TVA intra, register_id, legal_footer, operator_name, fiscal_sequence_no). Ajouter logo = changement format ticket = potentiellement audit fiscal requis. **À confirmer avec comptable / expert fiscal.**

---

## 2. Architecture technique recommandée (si toutes Q répondues OK)

```
DB:
  branches.theme_logo_media_id (Spatie media)
  branches.theme_logo_dark_media_id (Spatie media)
  branches.theme_idle_video_media_id (Spatie media)
  branches.theme_idle_poster_media_id (Spatie media)
  branches.theme_primary_color VARCHAR(7) NULL  -- #RRGGBB
  branches.theme_secondary_color VARCHAR(7) NULL
  branches.theme_published_at TIMESTAMP NULL
  branches.theme_updated_by_user_id BIGINT NULL

Admin UI:
  /admin/branches/:id/theme (nouvelle route)
  Components: BranchThemeFormComponent.vue (uploads + colorpickers)
  Permissions: 'branches.theme.update'

Kiosk:
  Boot: loadBranch() retourne theme bundle (URLs + colors)
  Apply: kioskTheme.js helper applique CSS variables [data-branch-theme]
         + précharge logo
         + lazy idle video
  Fallback: si null → tokens globaux

Receipt:
  TBD selon Q8 (logo ticket fiscal NF525)
```

---

## 3. Scope EXECUTE (post-gate, estimé)

| Bloc | Fichiers | LOC |
|------|----------|-----|
| **A — DB schema** | 1 migration `add_theme_to_branches.php` | ~50 |
| **B — Modèle + Resources** | `Branch.php` (+ casts, media collections), `BranchResource.php`, `BranchRequest.php`, `BranchService.php` | ~120 |
| **C — Admin CRUD theme** | `BranchThemeController.php` + form requests + `BranchThemeFormComponent.vue` + tests | ~400 |
| **D — Kiosk apply theme** | `kioskTheme.js` helper + `KioskAppComponent.vue` integration + CSS variables override + tests | ~200 |
| **E — Idle video par branche** | `KioskIdleScreenComponent.vue` (lecture URL branche au lieu globale) + tests | ~80 |
| **F — Receipt logo (si Q8 oui)** | `ReceiptDataService.php` (ajout logo) + `OrderReceiptComponent.vue` + tests | ~150 |
| **G — Tests E2E** | Vitest theme apply + isolation branch + PHPUnit branch theme controller + integration | ~300 |
| **H — Bugfix loadBranch** | `KioskAppComponent.vue` (résoudre branche par config kiosk install + middleware) — out-of-scope mais critique | ~50 |
| **TOTAL** | | **~800–1850 LOC** |

---

## 4. INVARIANTS_AT_RISK

1. **Isolation branche** : assets d'une branche A ne doivent JAMAIS leak vers branche B (kiosk + admin)
2. **Pas de payload prix dans theme** : theming = visuel pur, ne JAMAIS embed `tax_rate` / `currency` / pricing
3. **NF525 receipt** : si ajout logo ticket, vérifier que ça ne casse pas la signature fiscale ni le format imprimante (largeur `${PAPER_WIDTH}`)
4. **Cache invalidation** : changement admin → kiosk doit voir nouveau theme dans <60s (broadcast Echo `BranchThemeChanged` ou polling)
5. **CDN / cache HTTP** : assets doivent avoir hash dans URL pour bypass cache navigateur

## 5. RISKS résiduels

- **R1** Performance preload : si logo HD + video 50MB → boot kiosk ralenti. Mitigation : limites taille upload (~ 500KB logo, 20MB video).
- **R2** Spatie + S3 : configuration `disk` pour Spatie media collection sur `Branch` → vérifier `config/filesystems.php` accept `s3`.
- **R3** Cohérence cross-surface : POS/KDS pas auditées dans C.1 → audit complémentaire si Q8 = oui.
- **R4** Conflit avec V14 : Branch.php déjà modifié par V14 (fiscal/locales) → mergeability.
- **R5** Migration en prod : ajout colonnes sur table active → lock court attendu, OK pour MariaDB ≥10.

## 6. RECOMMANDATION ORCHESTRATION post-gate

Si **gate APPROUVÉ** :
- Cycle dédié `P_MEGA_W?_BRANCH_THEMING_IMPL_2026-XX-XX`
- Sub-cycles parallèles possibles : (i) DB+Backend, (ii) Admin UI, (iii) Kiosk apply
- PRIMARY_MODEL : routine-implementer pour chaque sub-cycle (pas de complex logic, juste plumbing)
- Sentinel tests : (a) isolation branch (assert branch A theme ≠ branch B), (b) fallback null, (c) cache invalidation <60s
- HARD GATE additionnel si Q8 = oui : audit fiscal NF525 logo ticket

Si **gate REPORTÉ** :
- W7.C reste fermé readonly
- Pas de blocage W7.A/W7.B
- Reprise sur signal business

Si **gate REJETÉ** :
- Closer P-MEGA-19 avec note "feature non priorisée"
- Documenter dans ROADMAP backlog

---

## 7. NEXT — Décision attendue

L'humain doit répondre :

```
Q1 : (a) | (b) | (c)
Q2 : Spatie+local | Spatie+S3 | URL+Storage | autre
Q3 : branche | franchise→branche
Q4 : self-service | centralisé | hybride
Q5 : fallback {logo, video, color} = ...
Q6 : preload | lazy | hybride + IDB ?
Q7 : audit oui/non + versioning oui/non
Q8 : POS/KDS oui/non + ticket logo oui/non (impact NF525 critique)
```

Une fois ces 8 réponses obtenues, le cycle EXECUTE peut être planifié avec scope fixe et estimation LOC précise (variance ±20%).

**SI la décision business prend >1 sprint** : recommandation de **CLOSER P-MEGA-19 dans backlog Wave 9+** et de procéder à W7.A/W7.B sans bloquer la roadmap technique.
