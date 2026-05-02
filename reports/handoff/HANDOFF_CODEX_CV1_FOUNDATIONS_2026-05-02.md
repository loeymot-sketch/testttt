# HANDOFF — Codex / Cursor — CV1 Foundations

| Champ | Valeur |
|---|---|
| Date | 2026-05-02 |
| Auteur | Claude (terminal `claude`, modèle `claude-opus-4-7`, effort `xhigh`) |
| Cible | Codex (gpt-5.5-pro xhigh) + Cursor strict-executor |
| Cycles concernés | `CV1-CATALOG-CONVERGENCE-001`, `CV1-LIFECYCLE-UX-001` |
| Sources d'autorité | Audits §1 + §2 + Plans master + ce handoff |
| Group Graphiti | `foodking` |

---

## 0. TL;DR pour Codex

1. **Lire** ce handoff dans son intégralité **avant** d'ouvrir une tâche.
2. Lire **les 2 plans** (`plans/PLAN_CV1-CATALOG-CONVERGENCE-001_2026-05-02.md`, `plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md`).
3. Ouvrir **un cycle à la fois** (jamais les deux en parallèle).
4. Prendre **les tâches Vague 1 d'abord**, dans l'ordre numérique (1.1 → 1.9).
5. Pour chaque tâche, **ne PAS recréer** les fichiers déjà posés en fondation (cf. inventaire §1).
6. **Compléter les TODO Codex** marqués dans chaque fichier squelette + dé-skipper les sentinels associés.
7. **Aucune** modification dans frozen zones sans LOCK_* ou gate cleared.
8. À chaque PR, lancer `scripts/audit-guard.sh` + `safety-check.sh` + suite test ciblée.
9. Si bloqué (ambiguïté, contradiction, gate non cleared), **arrêter et créer une note `NEEDS_CLAUDE.md`** dans `reports/handoff/blocks/` plutôt que de deviner.

---

## 1. Inventaire des fondations posées (à NE PAS recréer)

### 1.1 Backend services & commands

| Fichier | Rôle | Lignes "TODO Codex" actives | Plan task |
|---|---|---|---|
| `config/catalog_v15.php` | Feature flags | n/a (config complète) | M1 V1+V2, M2 V1+V2 |
| `app/Services/Menu/PosMenuProjection.php` | Shim 3-modes legacy/shadow/unified, kill-switch | lignes 95-105 (`adaptUnifiedToLegacyShape`), lignes 158-168 (`structuralDiff`) | M1 V2 (2.2) |
| `app/Services/Catalog/CatalogWarningService.php` | Warnings non-bloquants admin | lignes 75-94 (forItem), lignes 105-111 (forCategory) | M2 V1 (1.1, 1.4, 1.5) |
| `app/Console/Commands/StockScanRupture.php` | Cron auto-86 préventif | lignes 60-97 (handle body) | M2 V2 (2.1) |

### 1.2 Frontend — services & composables

| Fichier | Rôle | TODO Codex | Plan task |
|---|---|---|---|
| `resources/js/services/PosSyncService.js` | Fallback polling POS | lignes 67-95 (start lifecycle) | M1 V1 (1.7) |
| `resources/js/composables/useCatalogChangeNotifier.js` | Composable kiosk catalog change | lignes 47-94 (diffSnapshot, onCatalogChanged) | M2 V1 (1.3) + V2 (2.3) |

### 1.3 Frontend — composants Vue squelettes

| Fichier | Rôle | TODO Codex (haut du `<script>`) | Plan task |
|---|---|---|---|
| `resources/js/components/admin/items/ItemPreviewComponent.vue` | Aperçu inline POS+Kiosk | méthodes `loadProjection`, `computeParityWarning` | M2 V1 (1.2) |
| `resources/js/components/admin/items/ComposerProfileWarningBadge.vue` | Badge warnings catalog | onAction routing + i18n + persistance localStorage | M2 V1 (1.1, 1.4, 1.5) |
| `resources/js/components/admin/items/wizard/ProductCreateWizardComponent.vue` | Wizard 9 steps admin | sub-composants WizardStep* + persistance draft + finish flow | M2 V2 (2.9) |
| `resources/js/components/frontend/kiosk/CatalogChangeToastComponent.vue` | Toast catalog change kiosk | wiring useCatalogChangeNotifier + i18n + a11y escalation | M2 V1 (1.3) + V2 (2.3) |
| `resources/js/components/admin/stock/StockRuptureDashboardComponent.vue` | Dashboard auto-86 | loadAll, runScanNow, endpoints backend | M2 V2 (2.1, 2.7) |

### 1.4 Design system + tokens CSS

| Fichier | Rôle |
|---|---|
| `resources/css/foundations/cv1-tokens.css` | Variables CSS sémantiques (status, surfaces, motion, focus ring, stepper) |
| `docs/design/DESIGN_SYSTEM_FOUNDATIONS_CV1.md` | Documentation tokens + composants + patterns + anti-patterns |
| `docs/a11y/A11Y_CHECKLIST_CV1_WCAG_AA.md` | Checklist WCAG 2.1 AA + EAA 2025 par composant |

### 1.5 Sentinels PHPUnit (déjà skipped, à dé-skipper)

| Fichier | Mission / Vague / Tâche |
|---|---|
| `tests/Feature/Catalog/ChannelsNullWarningTest.php` | M1 V1 (1.4) |
| `tests/Feature/Catalog/ItemDeletionWithOrderHistoryTest.php` | M2 V1 (1.8) |
| `tests/Feature/Composer/ProfilePublishMidCartRejectionTest.php` | M2 V1 (1.4) |
| `tests/Feature/Menu/MaxDailyQtyChangeReEvaluationTest.php` | M2 V2 (2.5) |
| `tests/Feature/Menu/PosCategoryBranchScopeTest.php` | M1 V1 (1.1) |
| `tests/Feature/Menu/PosKioskProjectionParityTest.php` | M1 V2 (2.5) |
| `tests/Feature/Menu/PosMenuProjectionFeatureFlagTest.php` | M1 V2 (2.1-2.6) |
| `tests/Feature/Outbox/CatalogEventDispatchAfterCommitTest.php` | M1 V1 (1.6) |
| `tests/Feature/Pos/PosMenuRuntimeAccessTest.php` | M1/M2 (parité POS) |

### 1.6 Plans + audits

| Fichier | Rôle |
|---|---|
| `reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_1_CATALOG_SYNC_2026-05-02.md` | Audit Mission #1, verdict READY_WITH_DEBT_TICKET |
| `reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_2_STOCK_COMPOSITION_2026-05-02.md` | Audit Mission #2, verdict READY_WITH_DEBT_TICKET |
| `plans/PLAN_CV1-CATALOG-CONVERGENCE-001_2026-05-02.md` | Plan Mission #1 (V1+V2+V3) |
| `plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md` | Plan Mission #2 (V1+V2+V3) |

---

## 2. Frozen zones — INTERDIT sans gate cleared

| Fichier | Niveau de protection |
|---|---|
| `app/Services/Orders/OrderService.php` | Frozen NF525 |
| `app/Services/Payments/PaymentService.php` | Frozen NF525 |
| `app/Services/Pricing/PricingService.php` | Frozen NF525 (touché par M2 V2 2.2 derrière `GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK`) |
| `app/Services/FrontendOrderService.php` | Frozen sync lifecycle |
| `resources/js/components/admin/pos/PaymentComponent.vue` | Frozen sync lifecycle |
| `resources/js/components/admin/pos/ItemComponent.vue` | Frozen sync lifecycle |

**Règles d'interdiction :**
1. Aucune modification.
2. Aucune addition de méthode (même publique).
3. Aucun renommage.
4. Aucune extraction.
5. **Si vous pensez avoir besoin d'y toucher → arrêtez et écrivez `reports/handoff/blocks/NEEDS_CLAUDE_<task>.md` listant la justification, le diff prévu, le plan rollback.**

Hook `scripts/audit-guard.sh` + pre-commit `.cursor/hooks/safety-check.sh` détectent toute modif et bloquent le commit.

---

## 3. Ordre d'exécution recommandé

### Sprint 1 (Vague 1 mission #1 + #2 quick wins)

| # | Tâche | Cycle | Effort |
|---|---|---|---|
| 1 | M1 1.4 Warning channels-null | CATALOG | S |
| 2 | M1 1.6 DispatchableAfterCommit | CATALOG | S |
| 3 | M2 1.8 Hard-delete protection | LIFECYCLE | S |
| 4 | M2 1.9 lockForUpdate AvailabilityService | LIFECYCLE | S |
| 5 | M1 1.2 Surface filter défaut | CATALOG | S |
| 6 | M2 1.1 Badge composer warning | LIFECYCLE | S |
| 7 | M2 1.4 Sentinel profil v1→v2 | LIFECYCLE | S |
| 8 | M1 1.1 Branch-scope PosCategoryController | CATALOG | M |
| 9 | M2 1.5 Avertissements state incohérent | LIFECYCLE | M |
| 10 | M1 1.5 Doc runbook | CATALOG | XS |

### Sprint 2 (Vague 1 finition + Vague 2 préparatoire)

| # | Tâche | Cycle | Effort |
|---|---|---|---|
| 11 | M2 1.2 Bouton aperçu Kiosk+POS | LIFECYCLE | M |
| 12 | M2 1.3 Toast UX kiosk catalog change | LIFECYCLE | M |
| 13 | M1 1.3 Sentinel JS parité POS↔Kiosk | CATALOG | M |
| 14 | M1 1.7 PosSyncService implémentation | CATALOG | M |
| 15 | M2 1.6 Help inline distinguant kinds | LIFECYCLE | M |
| 16 | M2 1.7 Bouton Dupliquer | LIFECYCLE | M |
| 17 | M1 2.1 Activer shadow_compare staging | CATALOG | XS |

### Sprint 3+ (Vague 2 — convergence)

| # | Tâche | Cycle | Effort |
|---|---|---|---|
| 18 | M2 2.1 StockScanRupture cron | LIFECYCLE | M |
| 19 | M2 2.5 Re-éval is_available | LIFECYCLE | S |
| 20 | M2 2.6 Unique constraint StockMovement | LIFECYCLE | S |
| 21 | M2 2.7 Stock low alert | LIFECYCLE | M |
| 22 | M2 2.8 Unpublish composer | LIFECYCLE | M |
| 23 | M1 2.2 Migrate PosCategoryController via shim | CATALOG | L |
| 24 | M1 2.3 Migrate ItemController index | CATALOG | L |
| 25 | M1 2.5 Sentinel parité backend | CATALOG | M |
| 26 | M2 2.4 Sentinel renforcé symétrie | LIFECYCLE | M |
| 27 | M1 2.4 Migrate KioskMenuService | CATALOG | XL |

### Sprint gate (M2 V2 2.2 — frozen zone)

| # | Tâche | Pré-req | Effort |
|---|---|---|---|
| 28 | Rédiger `docs/gates/GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK_2026-XX-XX.md` | Tech lead + product owner approbation | S |
| 29 | Implémenter M2 2.2 Profile_version check | Gate cleared | L |
| 30 | M2 2.3 Refactor publication composer | Gate ou pas, peut être fait en parallèle | L |

### Sprint UX final

| # | Tâche | Cycle | Effort |
|---|---|---|---|
| 31 | M2 2.9 Wizard admin guidé (UI complet) | LIFECYCLE | XL |
| 32 | M1 2.6 Activer unified=true production | CATALOG | XS |
| 33 | M1 2.7 Cleanup legacy | CATALOG | M |

---

## 4. Convention de PR

Chaque PR doit :
1. Référencer la tâche du plan (ex: `[CV1-CATALOG-CONVERGENCE-001 task 1.1]`).
2. Inclure le sentinel correspondant (passing localement avant push).
3. Avoir une description avec :
   - Audit ref (file:line).
   - Plan task ref.
   - Liste des sentinels dé-skippés.
   - Liste des nouveaux sentinels créés.
   - Risques + mitigation.
4. Ne PAS toucher de frozen zone (le hook bloquera sinon).
5. Conserver la baseline tests Stock+Composer 5/5 verte.

---

## 5. Convention de commit

```
[CV1-{cycle-id} task {x.y}] <subject — imperative>

- {rationale brief}
- Sentinel: <sentinel-name> (skipped→passing | new)
- Audit: reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_{n}_*.md §{section}
```

Exemple :
```
[CV1-CATALOG-CONVERGENCE-001 task 1.1] Branch-scope PosCategoryController index

- Adds whereHas(items, branch_id=active_branch) gating to category list.
- Preserves virtual id:0 "all_items" header (legacy parity).
- Sentinel: PosCategoryBranchScopeTest (skipped→passing, 3 cases).
- Audit: reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_1_CATALOG_SYNC_2026-05-02.md §A.1 #1
```

---

## 6. Comment savoir quand s'arrêter et appeler Claude

Stoppez et créez `reports/handoff/blocks/NEEDS_CLAUDE_<short-id>.md` quand :

1. **Une frozen zone semble incontournable** sans gate cleared.
2. **Le shape JSON du shadow_compare** diverge sans cause évidente après 2 itérations de fix.
3. **Une i18n key est ambiguë** (5 langues, vous n'êtes pas sûr de la traduction métier).
4. **Un sentinel skipped** vous demande d'inventer un comportement non décrit dans le plan.
5. **Un test cassé** semble être un faux positif que vous ne pouvez pas expliquer en lisant l'audit + le plan.
6. **Un conflit de merge** sur un fichier qui n'est pas dans votre périmètre.
7. **Une décision d'authz** non documentée dans `app/Http/Resources/DefaultAccessResource.php`.

Format du fichier `NEEDS_CLAUDE_<id>.md` :

```markdown
# Block — <short id>

| Cycle | <CV1-CATALOG-CONVERGENCE-001 ou autre> |
| Task  | <x.y> |
| File  | <path:line> |
| Date  | <YYYY-MM-DD> |

## Symptôme
<ce qui ne marche pas / ce qui est ambigu>

## Lecture déjà faite
- <audit ref>
- <plan ref>
- <code lu>

## Décisions possibles (au moins 2)
1. <option A> + risques
2. <option B> + risques

## Recommandation Codex
<votre meilleure proposition + pourquoi vous n'êtes pas certain>
```

Claude répondra avec un patch `RESOLVED_<id>.md` contenant la décision et une éventuelle mise à jour du plan.

---

## 7. Mémoire et continuité

À chaque PR mergé, vous DEVEZ :
1. Créer un épisode JSONL dans `memory/episodes/12_decisions_log.jsonl` (format dans les sections G des deux audits, cf. exemples).
2. Mettre à jour `reports/AGENT_ACTIVITY_LOG.md` avec la ligne du jour.
3. Mettre à jour `reports/compact_snapshot.md` si une nouvelle invariant est posée.

Format épisode :
```jsonl
{"name":"<short>","source":"text","source_description":"<file:line ou plan task>","episode_body":"<300-500 chars expliquant la décision et son contexte>"}
```

---

## 8. Checklist gate humain V2 — `GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK`

À ouvrir AVANT M2 V2 2.2 :

- [ ] Brief écrit dans `docs/gates/GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK_2026-XX-XX.md`
- [ ] Diff exact prévu sur `PricingService.php` (méthode signature + body diff)
- [ ] Plan rollback détaillé : flag `composer_profile_version_check.enabled=false` doit suffire
- [ ] Sentinel détaillé : `tests/Feature/Composer/ProfileVersionMismatchTest.php` couvre 4 cas
- [ ] Approbateur 1 (tech lead) signature
- [ ] Approbateur 2 (product owner) signature
- [ ] Validation staging 7 jours minimum sans erreur de payload `composer_profile_version_changed`

Tant que cette checklist n'est pas verte, **NE PAS** modifier `PricingService.php`.

---

## 9. Anti-patterns à éviter (récap)

1. **Ne PAS dé-skipper en bloc.** Un sentinel à la fois, dans l'ordre du plan.
2. **Ne PAS recréer un fichier déjà posé.** Compléter les TODO marqués.
3. **Ne PAS contourner le hook `safety-check.sh`** avec `--no-verify`.
4. **Ne PAS ajouter de feature flags hors `config/catalog_v15.php`.**
5. **Ne PAS dupliquer la logique entre `PosMenuProjection` et `KioskMenuService`.** Tout passe par `MenuProjectionService::forChannel`.
6. **Ne PAS mélanger Options API et `<script setup>`** dans un même composant.
7. **Ne PAS ajouter de `kiosk-only` token CSS** sans le mettre dans `cv1-tokens.css` ou `tokens-aaa.css` / `tokens-pmr.css`.
8. **Ne PAS oublier i18n pour les 5 langues** (fr, en, ar, bn, de). L'arabe doit être testé en RTL.
9. **Ne PAS commettre une décision dans Graphiti** sans la passer aussi par `memory/episodes/`.
10. **Ne PAS écrire un test qui asserte une couleur RGB.** Asserer un `data-severity` ou une classe CSS.

---

## 10. Liens utiles

| But | Fichier |
|---|---|
| Lire les audits | `reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_{1,2}_*.md` |
| Lire les plans | `plans/PLAN_CV1-{CATALOG-CONVERGENCE-001,LIFECYCLE-UX-001}_2026-05-02.md` |
| Lire les fondations | `app/Services/{Menu,Catalog}/`, `app/Console/Commands/StockScanRupture.php`, `config/catalog_v15.php` |
| Lire le design system | `docs/design/DESIGN_SYSTEM_FOUNDATIONS_CV1.md` |
| Lire l'a11y checklist | `docs/a11y/A11Y_CHECKLIST_CV1_WCAG_AA.md` |
| Workflow product model | `docs/sync/WIZARD_PRODUCT_MODEL.md` |
| Runbook ops | `docs/sync/CENTRAL_MANAGEMENT_RUNBOOK.md` |
| Hooks safety | `.cursor/hooks/safety-check.sh` |
| Audit guard | `scripts/audit-guard.sh` |

---

**Fin du handoff Codex / Cursor.**

> Ce document est l'unique source d'autorité pour l'exécution Codex sur les cycles CV1. Si vous identifiez une contradiction entre ce handoff et les plans / audits, **arrêtez et écrivez `NEEDS_CLAUDE.md`**. Ne devinez pas.
