# PLAN — CV1-LIFECYCLE-UX-001

| Champ | Valeur |
|---|---|
| Cycle ID | `CV1-LIFECYCLE-UX-001` |
| Date plan | 2026-05-02 |
| Auteur plan | Claude (Anthropic, terminal `claude`, modèle `claude-opus-4-7`, effort `xhigh`) |
| Périmètre | Mission #2 — Lifecycle produit centralisé V1 (UX admin + race conditions + auto-86 préventif) |
| Audit source | `reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_2_STOCK_COMPOSITION_2026-05-02.md` |
| Mission liée | #1 (`plans/PLAN_CV1-CATALOG-CONVERGENCE-001_2026-05-02.md`) |
| Frozen zones touchées | **Aucune en Vague 1.** Vague 2 action 2.2 touche `PricingService` → gate humain `GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK`. |
| Gates humains | Vague 2 = `GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK` (action 2.2 only). |
| Estimation | Vague 1 ≈ 1 sprint ; Vague 2 ≈ 2-3 sprints (gate compris). |
| Effort cumulé | XL |

---

## 0. Lecture rapide pour Codex / Cursor

**But :** transformer le ressenti restaurateur ("rien ne marche dans la gestion") en un workflow guidé, transparent et résilient sans toucher à la chaîne fiscale NF525.

**Trois clés du plan :**

1. **Vague 1 = UX wins**. Avertissements composer, prévisualisations inline, toast catalog change kiosk, sentinel race v1→v2, lock `lockForUpdate` sur `AvailabilityService::decrementForOrder`. Aucun gate.
2. **Vague 2 = hardening**. Auto-86 préventif via cron, profile_version check (gate frozen), wizard admin guidé multi-step. Sécurité métier renforcée.
3. **Vague 3 = refactor schéma**. Channels=required (cross-Mission #1), modèle stock unifié, `composer_profile_version` colonne sur `order_items`. Repoussé à V2.

**Fondations déjà posées (à reprendre, NE PAS recréer) :**
- `app/Services/Catalog/CatalogWarningService.php` — service warnings (TODO Codex tâches 1.1, 1.4, 1.5).
- `app/Console/Commands/StockScanRupture.php` — squelette command auto-86 préventif (TODO tâche 2.1).
- `config/catalog_v15.php` — flags `warnings`, `auto_86_preventive_cron`, `composer_profile_version_check`, `item_deletion`, `stock_low_alert`.
- `resources/js/composables/useCatalogChangeNotifier.js` — composable Vue 3 pour toast catalog change kiosk (TODO tâche 1.3).
- 5 composants Vue squelettes : `ComposerProfileWarningBadge`, `ItemPreviewComponent`, `ProductCreateWizardComponent`, `CatalogChangeToastComponent`, `StockRuptureDashboardComponent`.
- 5 sentinels PHPUnit `markTestSkipped` à dé-skipper progressivement.

**Règles d'or de ce cycle :**
- L'admin actuel ne doit PAS être interrompu : les nouveaux composants s'ajoutent **à côté** des anciens, pas en remplacement (Vague 1).
- Les sentinels existants Stock/Composer (5/5 passing) doivent rester verts au cours de chaque PR.
- L'action 2.2 (PricingService) est la SEULE qui touche une frozen zone — gate humain obligatoire AVANT.

---

## 1. Tableau de bord exécutif

| Vague | Tâche | Cible | Effort | Risque | Sentinels |
|---|---|---|---|---|---|
| V1 | 1.1 Badge "Composer profile non publié" | `ComposerProfileWarningBadge.vue` (squelette) + `ItemShowComponent.vue` | S | Nul | `tests/js/itemShowComposerWarning.spec.js` (à créer) |
| V1 | 1.2 Bouton "Aperçu Kiosk + POS" | `ItemPreviewComponent.vue` (squelette) | M | Faible | `tests/js/itemPreviewProjection.spec.js` (à créer) |
| V1 | 1.3 Toast UX kiosk catalog change | `CatalogChangeToastComponent.vue` + `useCatalogChangeNotifier.js` | M | Faible | `tests/js/kioskWizardCatalogChangedHandling.spec.js` |
| V1 | 1.4 Sentinel profil v1→v2 mid-cart | `tests/Feature/Composer/ProfilePublishMidCartRejectionTest.php` (déjà skipped) | S | Faible | le test lui-même |
| V1 | 1.5 Avertissement state incohérent | `CatalogWarningService::forItem` + `ItemController::show` | M | Faible | sentinels applicatifs |
| V1 | 1.6 Help inline distinguant attribute/variation/extra/addon | composants admin/* | M | Nul | UX qualitatif |
| V1 | 1.7 Bouton "Dupliquer ce produit" | nouvel endpoint + UI | M | Modéré | `tests/Feature/Catalog/ItemDuplicationTest.php` |
| V1 | 1.8 Hard-delete protection ItemController::destroy | `app/Http/Controllers/Admin/ItemController.php:95-103` | S | Faible | `tests/Feature/Catalog/ItemDeletionWithOrderHistoryTest.php` (déjà skipped) |
| V1 | 1.9 Lock `lockForUpdate` AvailabilityService::decrementForOrder | `app/Services/Menu/AvailabilityService.php:191-236` | S | Faible | `tests/Feature/Stock/AvailabilityServiceConcurrentTest.php` (à créer) |
| V2 | 2.1 Auto-86 préventif cron | `app/Console/Commands/StockScanRupture.php` (squelette) + `app/Console/Kernel.php` | M | Modéré | `tests/Feature/Stock/StockScanRuptureCommandTest.php` |
| V2 | 2.2 Profile_version check au submit | `app/Services/Pricing/PricingService.php` (FROZEN) | L | Élevé | **`GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK` requis** |
| V2 | 2.3 Refactor publication composer pendant panier ouvert | `KioskWizardComponent.vue` + `kioskMenu.js` | L | Modéré | `tests/js/kioskComposerProfileChangeHandling.spec.js` |
| V2 | 2.4 Sentinel renforcé OrderService/FrontendOrderService symétrie | `tests/Feature/Stock/StockSymmetryDiffTest.php` | M | Faible | extension du test existant |
| V2 | 2.5 Re-évaluation is_available à la modif max_daily_qty | `app/Services/Menu/AvailabilityService.php::toggle` | S | Faible | `tests/Feature/Menu/MaxDailyQtyChangeReEvaluationTest.php` (déjà skipped) |
| V2 | 2.6 StockMovement unique constraint idempotency_key | migration | S | Faible | `tests/Feature/Stock/StockMovementIdempotencyKeyUniqueTest.php` |
| V2 | 2.7 Stock low alert listener | `app/Listeners/NotifyStockLowOnStockLevelChanged.php` (à créer) | M | Faible | sentinel applicatif |
| V2 | 2.8 Symétrie unpublish composer profile | `ComposerProfileService::unpublish` | M | Modéré | `tests/Feature/Composer/ComposerProfileUnpublishTest.php` |
| V2 | 2.9 Wizard admin guidé multi-step | `ProductCreateWizardComponent.vue` (squelette) | XL | Modéré | `tests/js/productCreateWizardE2E.spec.js` + Playwright |
| V3 | 3.x | Channels=required, modèle stock unifié, composer_profile_version | n/a | Très élevé | gates humains multiples |

---

## 2. Vague 1 — UX wins (détail tâche par tâche)

### 1.1 — Badge "Composer profile non publié"

**Fichier(s) cible(s) :**
- `resources/js/components/admin/items/ComposerProfileWarningBadge.vue` (squelette posé — implémenter TODO)
- `resources/js/components/admin/items/ItemShowComponent.vue` (intégrer le badge)
- `app/Services/Catalog/CatalogWarningService.php` (méthode `forItem`, TODO Codex composer_unpublished)

**Contrat :**
- Si l'item a un `ItemWizardProfile` qui n'est pas `is_published=true`, afficher un badge orange `severity=warning` avec call-to-action "Publier maintenant".
- Le badge est cliquable et redirige vers la page composer.
- Si l'item a `item_type` complexe (variations/extras non vide) MAIS aucun composer profile, afficher un badge `severity=blocker` avec call-to-action "Créer composer profile".

**Étapes Codex :**
1. Implémenter `CatalogWarningService::forItem` détection :
   - Cas 1 (composer_unpublished) : `ItemWizardProfile::where('item_id', $item->id)->latest()->first()` non publié.
   - Cas 2 (composer_missing_for_complex_kind) : item type complexe ET pas de profile du tout.
2. Modifier `ItemController::show` pour appeler `CatalogWarningService::exposeFor($item)` et merger dans la réponse.
3. Dans `ItemShowComponent.vue`, importer et utiliser `<ComposerProfileWarningBadge :warnings="warnings" @action="onWarningAction" @dismiss="onDismiss" />`.
4. Implémenter `onWarningAction` : router vers `/admin/items/{id}/composer` selon le code.
5. Compléter les i18n keys dans `resources/js/languages/{fr,en,ar,bn,de}.json` (cf. design system §6 / a11y CT1-WB7).

**Critères d'acceptation :**
- Item avec composer draft → badge warning visible.
- Item complexe sans composer → badge blocker visible.
- Item simple sans composer → aucun badge.
- Click "Publier" → navigation vers la page composer.

**Sentinel :** `tests/js/itemShowComposerWarning.spec.js`.

---

### 1.2 — Bouton "Aperçu Kiosk + POS"

**Fichier(s) cible(s) :**
- `resources/js/components/admin/items/ItemPreviewComponent.vue` (squelette posé — implémenter TODO)
- `resources/js/components/admin/items/ItemShowComponent.vue` (ajouter onglet "Aperçu")
- Endpoint backend déjà existant : `GET /api/admin/menu-projection?channel={channel}&branch_id={branchId}`

**Contrat :**
- Sur la page détail item, un nouvel onglet "Aperçu" affiche côte à côte la projection Kiosk et la projection POS.
- L'utilisateur sélectionne la branche via un dropdown.
- Si POS et Kiosk divergent (prix, dispo, image), un avertissement "Divergence détectée" s'affiche.

**Étapes Codex :**
1. Implémenter `ItemPreviewComponent::loadProjection` (cf. squelette ligne 175).
2. Implémenter `computeParityWarning` qui compare prix, is_available, image.
3. Ajouter dans `ItemShowComponent.vue` un nouvel onglet `<button @click="handleTab('#preview', ...)">Aperçu</button>`.
4. Wirer l'i18n (clés `admin.item_preview.*`).

**Critères d'acceptation :**
- Onglet "Aperçu" visible sur tous les items.
- Sélection branch met à jour les 2 cartes.
- Divergence prix → warning amber.
- Loading state + aria-busy respectés.

**Sentinel :** `tests/js/itemPreviewProjection.spec.js`.

---

### 1.3 — Toast UX kiosk catalog change

**Fichier(s) cible(s) :**
- `resources/js/composables/useCatalogChangeNotifier.js` (squelette posé — implémenter TODO)
- `resources/js/components/frontend/kiosk/CatalogChangeToastComponent.vue` (squelette posé)
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue` (intégration)
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` (consommation)

**Contrat :**
- Quand un événement `CatalogChanged` ou `ComposerProfileChanged` arrive pendant que le kiosk affiche un wizard ou un panier non-vide :
  1. Toast non-bloquant 5s "Le menu a été mis à jour".
  2. Diff entre snapshot du panier et nouvelle projection.
  3. Si choix retiré : action "Vérifier mon panier".
  4. Pruning automatique des items disparus.
  5. Annoncer via `useKioskSpeech` et lecteur d'écran.

**Étapes Codex :**
1. Implémenter `useCatalogChangeNotifier::diffSnapshot` et `onCatalogChanged` (cf. squelette lignes 47-94).
2. Intégrer le composable dans `KioskAppComponent.vue` (mounted) et passer le state au `CatalogChangeToastComponent`.
3. Câbler l'action "Vérifier mon panier" pour ouvrir la cart drawer + focus sur la première ligne affectée.
4. Compléter i18n keys dans 5 langues.
5. Wirer analytics : `analytics.track('catalog_change_mid_session', ...)`.

**Critères d'acceptation :**
- Catalog change pendant wizard ouvert → toast visible.
- Choix retiré → label affecté + ré-focus.
- aria-live="polite" annonce le toast.
- reduced-motion → animation désactivée.

**Sentinel :** `tests/js/kioskWizardCatalogChangedHandling.spec.js`.

---

### 1.4 — Sentinel profil v1→v2 mid-cart

**Sentinel à dé-skipper :** `tests/Feature/Composer/ProfilePublishMidCartRejectionTest.php`.

**Étapes Codex :**
1. Implémenter le scénario complet :
   - Setup : 1 item avec composer profile v1 contenant choix `option_X`.
   - User ouvre wizard côté kiosk → snapshot pris.
   - Admin publie composer v2 SANS `option_X`.
   - User submit cart contenant `option_X` → expect HTTP 422.
   - Vérifier que la response contient un payload structuré (`error_code: stale_choice`, `removed_options: [option_X]`).
2. Asserter que la chaîne fiscale n'est PAS touchée (`composition_snapshot` reste vide, aucun `OrderItem` créé).

---

### 1.5 — Avertissement state incohérent

Cf. § audit 1.5 et table 1.4. Implémenter dans `CatalogWarningService` les codes `channels_null`, `missing_photo`, `branch_availability_unset`, `high_daily_consumed`. Tous TODO marqués dans le squelette.

---

### 1.6 — Help inline attribute/variation/extra/addon

Ajouter un panel d'aide ouvert par défaut sur les pages de création (lit `docs/sync/WIZARD_PRODUCT_MODEL.md`). Composant léger, pas de sentinel automatisé requis (UX qualitatif).

---

### 1.7 — Bouton "Dupliquer ce produit"

**Fichier(s) cible(s) :**
- Nouvel endpoint `POST /api/admin/items/{id}/duplicate` (à créer dans `ItemController`).
- Service `app/Services/ItemService.php::duplicate($itemId)`.
- UI dans `ItemListComponent.vue` ou `ItemShowComponent.vue`.

**Contrat :**
- Duplique l'item, ses variations, ses extras, son composer profile (en draft, non publié).
- Suffixe le nom : "Tacos M (copie)".
- Conserve les channels, le tax_id, l'image (référence Spatie media).
- Aucune commande historique n'est touchée.

**Sentinel :** `tests/Feature/Catalog/ItemDuplicationTest.php`.

---

### 1.8 — Hard-delete protection ItemController::destroy

**Fichier(s) cible(s) :**
- `app/Http/Controllers/Admin/ItemController.php:95-103`
- `app/Services/ItemService.php` (méthode `destroy`)

**Contrat :**
- Soft-delete reste autorisé.
- Hard-delete (`forceDelete`) est refusé avec HTTP 409 Conflict si `OrderItem::where('item_id', $id)->exists()`.
- Le user reçoit un message clair : "Cet item est référencé par X commandes historiques. Suppression douce uniquement."

**Étapes Codex :**
1. Vérifier le flag `config('catalog_v15.item_deletion.protect_force_delete_when_referenced')` (default true).
2. Modifier `ItemService::destroy($id, $forceDelete = false)` pour bloquer si flag actif et `$forceDelete=true` et `OrderItem::exists()`.
3. Adapter `ItemController::destroy` pour passer le flag depuis la query string `?force=true`.

**Sentinel à dé-skipper :** `tests/Feature/Catalog/ItemDeletionWithOrderHistoryTest.php`.

---

### 1.9 — Lock `lockForUpdate` AvailabilityService::decrementForOrder

**Fichier(s) cible(s) :**
- `app/Services/Menu/AvailabilityService.php:191-236`

**Contrat :**
- Symétrique à `StockService::decrementForOrder` qui pose `lockForUpdate`.
- Garantit que 2 commandes simultanées sur le même `max_daily_qty` ne perdent pas un increment.

**Étapes Codex :**
1. Wrap la lecture `ItemBranchAvailability::where('branch_id', ...)->where('item_id', ...)->first()` (ligne ~205) dans une transaction avec `lockForUpdate`.
2. Vérifier que le contexte appelant (`OrderService::create` etc.) est bien dans une transaction.

**Sentinel :** créer `tests/Feature/Stock/AvailabilityServiceConcurrentTest.php` (similaire à `StockConcurrentDecrementTest.php`).

---

## 3. Vague 2 — Hardening (détail tâche par tâche)

### 2.1 — Auto-86 préventif cron

**Fichier(s) cible(s) :**
- `app/Console/Commands/StockScanRupture.php` (squelette posé — implémenter `handle` body, cf. lignes 60-97)
- `app/Console/Kernel.php` (registrer le schedule)
- Endpoints admin pour le `StockRuptureDashboardComponent` (à créer)

**Contrat :**
- `php artisan stock:scan-rupture` itère les branches actives.
- Pour chaque branche, identifie les items dont TOUTES les variations stockables ont `on_hand <= 0`.
- Pour chaque item identifié, appelle `AvailabilityService::toggle($itemId, $branchId, false, 'stock_rupture')`.
- Idempotent (ne re-flippe pas un item déjà unavailable).
- Cron toutes les 5 min via `$schedule->command('stock:scan-rupture')->cron('*/5 * * * *')->withoutOverlapping()->onOneServer()`.

**Étapes Codex :**
1. Implémenter le body de `handle()` (cf. pseudo-code dans le squelette).
2. Ajouter le schedule dans `app/Console/Kernel.php` avec gate `->when(fn() => config('catalog_v15.auto_86_preventive_cron.enabled'))`.
3. Créer endpoints :
   - `GET /api/admin/stock/scan-rupture/last-summary`
   - `GET /api/admin/stock/scan-rupture/currently-unavailable`
   - `POST /api/admin/stock/scan-rupture/run` (manual trigger pour staging)
4. Wirer `StockRuptureDashboardComponent` (squelette posé).

**Sentinel :** `tests/Feature/Stock/StockScanRuptureCommandTest.php` (4 cas : skip-when-disabled, dry-run-no-mutation, multi-branch, idempotent-second-run).

---

### 2.2 — Profile_version check au submit ⚠️ FROZEN ZONE

**🛑 GATE HUMAIN OBLIGATOIRE : `docs/gates/GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK_2026-XX-XX.md`**

Brief gate à rédiger AVANT toute implémentation :
- Justification : pourquoi PricingService doit être touché.
- Diff exact prévu (méthode signature, retour, nouveau code de réponse).
- Plan rollback : flag `composer_profile_version_check.enabled` permet rollback en O(1).
- Stratégie de validation : sentinel + UAT staging 7 jours.
- Approbateur humain : tech lead + product owner.

**Une fois gate cleared :**

**Fichier(s) cible(s) :**
- `app/Http/Requests/OrderRequest.php` (champ `composer_profile_version_at_open` optionnel).
- `app/Services/Pricing/PricingService.php` (méthode `validateComposerSelections` étendue) — frozen.
- `resources/js/store/modules/kioskCart.js` (capture la version au snapshot).
- Frontend kiosk : compose la modale `ComposerProfileVersionConflictModal` (à créer).

**Contrat :**
- Si `composer_profile_version_at_open` est présent dans le payload submit ET diffère de la version courante publiée : retourner HTTP 409 Conflict avec un payload de remédiation.
- Payload : `{ "error_code": "composer_profile_version_changed", "current_version": 2, "submitted_version": 1, "removed_options": [...], "added_options": [...] }`.
- Frontend : modale dédiée qui montre le diff et propose "Continuer avec les nouveaux choix" / "Annuler".

**Sentinel :** `tests/Feature/Composer/ProfileVersionMismatchTest.php`.

---

### 2.3 — Refactor publication composer pendant panier ouvert

Symétrique à 1.3 mais pour `ComposerProfileChanged` spécifiquement. Le composable `useCatalogChangeNotifier` doit gérer les deux events.

**Sentinel :** `tests/js/kioskComposerProfileChangeHandling.spec.js`.

---

### 2.4 — Sentinel renforcé OrderService / FrontendOrderService symétrie

Étendre `tests/Feature/Stock/StockSymmetryDiffTest.php` pour parser via reflection les méthodes `decrementXxx` / `releaseXxx` des deux services et asserer signature/comportement identiques.

---

### 2.5 — Re-évaluation is_available à la modif max_daily_qty

**Fichier(s) cible(s) :**
- `app/Services/Menu/AvailabilityService.php::toggle` ou nouvelle méthode `reEvaluateAfterMaxDailyQtyChange`.

**Contrat :**
- Quand admin baisse `max_daily_qty` à valeur < `daily_consumed_qty` actuel, flip immédiat `is_available=false` et émettre `ItemAvailabilityChanged`.
- Quand admin remonte `max_daily_qty` au-dessus de `daily_consumed_qty`, flip immédiat `is_available=true`.

**Sentinel à dé-skipper :** `tests/Feature/Menu/MaxDailyQtyChangeReEvaluationTest.php`.

---

### 2.6 — StockMovement unique constraint

Migration ajoutant `$table->unique('idempotency_key')` sur `stock_movements`. Sentinel : `tests/Feature/Stock/StockMovementIdempotencyKeyUniqueTest.php`.

---

### 2.7 — Stock low alert listener

**Fichier(s) cible(s) :**
- Nouveau `app/Listeners/NotifyStockLowOnStockLevelChanged.php`.

**Contrat :**
- Écoute `StockLevelChanged`.
- Si `on_hand <= threshold_low`, émet une notification toast dashboard + email.
- Throttle par `(branch_id, stockable)` via cache 1h pour ne pas inonder.

**Configuration :** `config('catalog_v15.stock_low_alert.enabled')` + `throttle_seconds`.

---

### 2.8 — Symétrie unpublish composer profile

Si `ComposerProfileService::unpublish` n'existe pas, le créer + émettre `ComposerProfileChanged` avec `is_published=false`. Sentinel : `tests/Feature/Composer/ComposerProfileUnpublishTest.php`.

---

### 2.9 — Wizard admin guidé multi-step

**Fichier(s) cible(s) :**
- `resources/js/components/admin/items/wizard/ProductCreateWizardComponent.vue` (squelette posé).
- 9 sub-composants `WizardStep*.vue` à créer dans `resources/js/components/admin/items/wizard/steps/`.

**Contrat :**
- Une seule route admin `/admin/items/wizard/create` orchestre les 9 endpoints existants.
- Draft persisté en localStorage 24h TTL.
- Bouton "Précédent" toujours actif sauf step 0.
- Bouton "Suivant" désactivé tant que les requirements de step ne sont pas remplis.

**Étapes Codex :**
1. Reprendre les 7 sub-tâches du squelette `ProductCreateWizardComponent.vue` (lignes 165-200).
2. Créer 9 components `WizardStep*` réutilisant les fragments admin existants.
3. Wirer la route admin.
4. Ajouter test Playwright `tests/e2e/product-create-wizard.spec.ts` parcourant le happy path.

**Sentinel :** `tests/js/productCreateWizardE2E.spec.js` + Playwright.

---

## 4. Vague 3 — Refactor structurel (V2 — gates humains)

| Sujet | Gate | Détail |
|---|---|---|
| Channels=required | `GATE_CATALOG_CHANNELS_REQUIRED` (cross-Mission #1) | Migration backfill + suppression NULL=ALL |
| Modèle stock unifié | `GATE_STOCK_UNIFIED_MODEL` (à ouvrir) | `stock_levels` source unique, `item_branch_availability` vue dérivée |
| `composer_profile_version` colonne sur order_items | `GATE_ORDER_ITEMS_COMPOSER_VERSION` (à ouvrir) | Diagnostic post-mortem des commandes vs versions composer |

**NE PAS exécuter ce cycle CV1.**

---

## 5. Sentinels — état et activation

| Sentinel | Statut | Vague |
|---|---|---|
| `tests/Feature/Catalog/ItemDeletionWithOrderHistoryTest.php` | skipped | V1 (1.8) |
| `tests/Feature/Composer/ProfilePublishMidCartRejectionTest.php` | skipped | V1 (1.4) |
| `tests/Feature/Menu/MaxDailyQtyChangeReEvaluationTest.php` | skipped | V2 (2.5) |
| `tests/Feature/Pos/PosMenuRuntimeAccessTest.php` | skipped | V1/V2 (parité POS) |
| `tests/js/itemShowComposerWarning.spec.js` | à créer | V1 (1.1) |
| `tests/js/itemPreviewProjection.spec.js` | à créer | V1 (1.2) |
| `tests/js/kioskWizardCatalogChangedHandling.spec.js` | à créer | V1 (1.3) |
| `tests/Feature/Catalog/ItemDuplicationTest.php` | à créer | V1 (1.7) |
| `tests/Feature/Stock/AvailabilityServiceConcurrentTest.php` | à créer | V1 (1.9) |
| `tests/Feature/Stock/StockScanRuptureCommandTest.php` | à créer | V2 (2.1) |
| `tests/Feature/Composer/ProfileVersionMismatchTest.php` | à créer | V2 (2.2) — gate requis |
| `tests/js/kioskComposerProfileChangeHandling.spec.js` | à créer | V2 (2.3) |
| `tests/Feature/Stock/StockMovementIdempotencyKeyUniqueTest.php` | à créer | V2 (2.6) |
| `tests/Feature/Composer/ComposerProfileUnpublishTest.php` | à créer | V2 (2.8) |
| `tests/js/productCreateWizardE2E.spec.js` | à créer | V2 (2.9) |
| `tests/e2e/product-create-wizard.spec.ts` | à créer | V2 (2.9) |
| `tests/e2e/cv1-axe-sweep.spec.ts` | à créer | V1+V2 (a11y) |

---

## 6. Definition of Done — cycle CV1-LIFECYCLE-UX-001

- [ ] Vague 1 complète (1.1 → 1.9) déployée en staging puis prod.
- [ ] Tous les sentinels Vague 1 passent ✅.
- [ ] Suite stock+composer existante reste verte (5/5 baseline maintenue).
- [ ] Gate `GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK` ouvert et clearé pour 2.2.
- [ ] Vague 2 complète sauf 2.2 si gate non cleared.
- [ ] Auto-86 cron actif en prod 14j sans incident.
- [ ] Wizard admin guidé déployé et UAT par 1 restaurateur réel.
- [ ] Cross-référence Mission #1 vérifiée (`PLAN_CV1-CATALOG-CONVERGENCE-001_2026-05-02.md`).

---

## 7. Hooks de garde-fou

- `safety-check.sh` doit refuser tout PR qui touche `app/Services/Pricing/PricingService.php` SANS un LOCK_*.md ou un gate cleared.
- Sentinel `tests/Feature/Stock/StockSymmetryDiffTest.php` doit rester vert à chaque PR.
- A11y sweep `tests/e2e/cv1-axe-sweep.spec.ts` doit retourner 0 violations critical/serious après chaque ajout de composant.

---

## 8. Risques résiduels et mitigations

| Risque | Mitigation |
|---|---|
| Wizard admin morcelé crée des transactions partielles | Draft localStorage 24h + replay des étapes manquantes au finish |
| Profile_version check casse les commandes legacy en cours | Champ optionnel ; absence → comportement actuel |
| Auto-86 cron flippe par erreur un item encore stockable | `--dry-run` mode + log structuré préalable + sentinel idempotent |
| Hard-delete protection bloque un admin qui voulait vraiment supprimer | Endpoint séparé `force-purge` derrière permission `SUPER_ADMIN` (V2) |

---

**Fin du plan CV1-LIFECYCLE-UX-001.**
