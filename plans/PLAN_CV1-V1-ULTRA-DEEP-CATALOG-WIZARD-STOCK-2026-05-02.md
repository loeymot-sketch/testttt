# ULTRA PLAN — Hardening V1 Catalog · Product · Stock · Wizard · Sync · Centralisation
## CV1-V1-CLOSEOUT-001 — 2026-05-02

**Auteur :** Claude in-session orchestrator
**Demande user (2026-05-02 21:47) :** « ultra review et ultra orchestration et ultra plan et exécute le plan »
**Contexte :** Consolidation EXHAUSTIVE de tout ce que le user a demandé depuis le début du cycle 2026-04-25 → 2026-05-02, avec mapping vers tâches concrètes ordonnées V1.
**Master plan parent :** `plans/PLAN_CV1-V1-CLOSEOUT-MASTER-2026-05-02.md`
**Synthèse audits :** `reports/audit/CV1_V1_CLOSEOUT_MASTER_SYNTHESIS_2026-05-02.md` (en attente de remplissage post-audits)

---

## §1 — Inventaire EXHAUSTIF des demandes user (depuis 2026-04-25)

| # | Demande user (extrait paraphrasé) | Date | État actuel | Tâche V1 si gap |
|---|---|---|---|---|
| D1 | Transition fluide entre catégories en POS | début | ✅ FIXÉ | — |
| D2 | Wizard fullscreen sans scroll, espace optimisé pour 21" | début | ✅ FIXÉ (densité OK) | — |
| D3 | "Toutes les catégories" affiche catégories (pas articles) | début | ✅ FIXÉ | — |
| D4 | Liste boissons synchronisée stock + caisse + borne dans wizard formule | début | ⚠️ partiel | T-WIZ-RT-DRINKS-001 (à vérifier après Axe 4) |
| D5 | Bouton "voir toutes commandes en cours" depuis caisse | mid | ✅ FIXÉ (M2 V1 PosOrdersTracker) | — |
| D6 | Notification verte sur bouton si commande prête | mid | ⚠️ partiel | T-POS-NOTIF-READY-001 |
| D7 | Écran client (OSS) bon design + caisse adapté caissier | mid | ✅ baseline | T-OSS-DESIGN-002 (polish) |
| D8 | P0/P1/P2 features Caisse V1 implémentées en continu avec audit | mid | ✅ M1 V1 + M2 V1 + M2 V2 | — |
| D9 | Bouton "toutes les catégories" + barre latérale épurée + barre haute petite | mid | ✅ FIXÉ density + VAT | — |
| D10 | Total HT vs TTC corrigé (5,5%/10% France) | mid | ✅ FIXÉ (POS-V4-VAT) | — |
| D11 | Centralisation hyper complexe : sync catalogue + stock + composition lifecycle | mid | 🔄 EN COURS Axe 1+2 audit | T-CENT-* selon Axe 1 |
| D12 | Multi-agent loop fonctionnel + lancer plan automatique | mid | ✅ DOCTRINE EN PLACE | — |
| D13 | Mission 1 V1 (catalog convergence) | late | ✅ CLOSED 7/7 | — |
| D14 | M2 1.9 atomic decrement (industry comparative analysis Option A) | late | ✅ CLOSED | — |
| D15 | Mission 2 V1 (lifecycle UX) | late | ✅ CLOSED 9/9 | — |
| D16 | Fix gray screen admin/pos après login | late | ✅ FIXÉ (backdrop cleanup) | — |
| D17 | Continue M2 V2, audits dans chaque boucle | late | ✅ CLOSED 6/6 + 2 gates | — |
| D18 | Cleanup fonctionnalités inutiles dashboard | latest | 🔄 EN COURS Lot A+B+C | T-CLEANUP-* |
| D19 | Décomposer wizard POS vs Kiosk page-par-page + bon visuel borne | latest | 🔄 Axe 4 audit + plan post-audit | T-WIZARD-RT-* |
| D20 | Audit Claude global ultra-review | latest | ✅ DEMANDE ÉCRITE | (user lance terminal) |
| D21 | "Hyper deep en gestion catégorie produit et stock" | latest (21:44) | 🔄 PRÉSENT PLAN | T-DEEP-CAT-*, T-DEEP-PROD-*, T-DEEP-STK-* |
| D22 | "Modif/ajout/composition spéciale wizard tout faisable" | latest (21:44) | 🔄 PLAN refactor wizard | T-WIZARD-RT-COMPOSER-* |
| D23 | Ultra-orchestration max | maintenant | 🔄 6 jobs background + ce plan | — |

**Gaps identifiés après D17** : 8 familles de tâches V1 (D6, D7 polish, D11-deep, D18, D19, D21, D22).

---

## §2 — Ultra-architecture cible V1 fonctionnelle

### Couche backend (SSOT)

```
┌─────────────────────────────────────────────────────────────┐
│ SSOT BACKEND (Laravel)                                       │
│                                                              │
│  ┌────────────────┐    ┌──────────────────┐                │
│  │ Catalog        │    │ Composer Wizard  │                │
│  │ - Item         │    │ - WizardProfile  │                │
│  │ - Category     │    │ - WizardStep     │                │
│  │ - Variation    │    │ - Source: attr/  │                │
│  │ - Extra        │    │   extra/addon    │                │
│  │ - Addon        │    └──────────────────┘                │
│  │ - Attribute    │                                          │
│  └────────────────┘                                          │
│           │                                                   │
│  ┌────────▼─────────┐  ┌──────────────────┐                │
│  │ AvailabilityServ │  │ StockService     │                │
│  │ - toggle()       │  │ - mutateForOrder │                │
│  │ - setMaxDaily()  │  │ - decrement()    │                │
│  │ - decrement()    │  │ - release()      │                │
│  │   atomic CAS     │  │ - mvts ledger    │                │
│  │ - release()      │  │   idempotency    │                │
│  └──────────────────┘  └──────────────────┘                │
│           │                       │                          │
│  ┌────────▼─────────────────────▼──────┐                   │
│  │ Events (DispatchableAfterCommit)     │                   │
│  │ - CatalogChanged                     │                   │
│  │ - ItemAvailabilityChanged            │                   │
│  │ - ComposerProfileChanged             │                   │
│  │ - StockLevelChanged                  │                   │
│  └──────────────────────────────────────┘                   │
│                       │                                      │
│  ┌────────────────────▼──────────────────┐                  │
│  │ Outbox + Echo broadcast (per branch)  │                  │
│  └───────────────────────────────────────┘                  │
└──────────────────────┬───────────────────────────────────────┘
                       │
       ┌───────────────┼───────────────┬──────────────┐
       │               │               │              │
   ┌───▼───┐      ┌────▼────┐    ┌─────▼────┐   ┌─────▼────┐
   │ POS   │      │  Kiosk  │    │   KDS    │   │   OSS    │
   │ admin │      │ frontend│    │  cuisine │   │  client  │
   │       │      │         │    │          │   │          │
   │PosSync│      │useCatChng│   │KdsSync   │   │OssSync   │
   │polling│      │Notifier │    │polling?  │   │polling?  │
   └───────┘      └─────────┘    └──────────┘   └──────────┘
```

### Projection unifiée (cible architecture)

| Surface | Projection actuelle | Projection cible V1 |
|---|---|---|
| POS | `PosMenuProjection::forBranch` (M1 1.7, ✅) | identique |
| Kiosk | `KioskMenuService::forBranch` (?) | identique OU même fonction `MenuProjectionService::forChannel('kiosk', $branchId)` |
| KDS | ? (à confirmer Axe 1) | `MenuProjectionService::forChannel('kds', $branchId)` si manque |
| OSS | ? (à confirmer Axe 1) | `MenuProjectionService::forChannel('oss', $branchId)` si manque |

**Single function entry** : `MenuProjectionService::forChannel($channel, $branchId)` qui internement délègue aux 4 surfaces. Le flag `catalog_v15.unified_projection.enabled` doit garder cette unification.

---

## §3 — Familles de tâches V1 hardening (T-XXX-NN)

### Famille A — DEEP gestion CATÉGORIE (D21 partie 1)

| ID | Titre | Tier | Sentinel cible | Effort |
|---|---|---|---|---|
| `T-DEEP-CAT-01` | Sentinel `CategoryRenameSyncTest` (rename → POS+Kiosk re-render via CatalogChanged) | routine | `tests/Feature/Catalog/CategoryRenameSyncTest.php` | S |
| `T-DEEP-CAT-02` | Sentinel `CategoryDeletionWithItemsTest` (refus cascade si items actifs OU soft-delete avec move items vers "Uncategorized") | routine | `tests/Feature/Catalog/CategoryDeletionGuardTest.php` | M |
| `T-DEEP-CAT-03` | Sentinel `CategoryReorderPersistenceTest` (drag & drop ordre, persistence + propagation surface) | routine | `tests/Feature/Catalog/CategoryReorderTest.php` | S |
| `T-DEEP-CAT-04` | Endpoint admin `PATCH /api/admin/categories/{id}/visibility/{channel}` (toggle visibilité POS-only / Kiosk-only / both) | routine | `tests/Feature/Catalog/CategoryChannelVisibilityTest.php` | S |
| `T-DEEP-CAT-05` | UI admin : badge "channels: POS+Kiosk" sur chaque catégorie + toggle inline | routine | `tests/js/CategoryListChannelToggle.spec.js` | S |

### Famille B — DEEP gestion PRODUIT (D21 partie 2)

| ID | Titre | Tier | Sentinel cible | Effort |
|---|---|---|---|---|
| `T-DEEP-PROD-01` | Sentinel `ProductPriceChangeSnapshotTest` (changement prix admin = nouveaux orders nouveau prix, ordres en cours préservés via `unit_price` snapshot order_items) | routine | `tests/Feature/Order/PriceChangeSnapshotTest.php` | M |
| `T-DEEP-PROD-02` | Sentinel `ProductChannelToggleSyncTest` (toggle POS-only ↔ POS+Kiosk = propagation immédiate) | routine | `tests/Feature/Catalog/ProductChannelToggleSyncTest.php` | S |
| `T-DEEP-PROD-03` | Sentinel `ProductBulkActionsTest` (bulk activate / desactivate / change category sur N items) | routine | `tests/Feature/Catalog/ProductBulkActionsTest.php` | M |
| `T-DEEP-PROD-04` | Endpoint `POST /api/admin/items/bulk-action` (activate/deactivate/move-category, audit trail) | routine | inclus dans T-DEEP-PROD-03 | M |
| `T-DEEP-PROD-05` | UI admin : checkbox + barre actions bulk en haut du `ItemListComponent` | routine | `tests/js/ItemListBulkActions.spec.js` | M |
| `T-DEEP-PROD-06` | Sentinel `ProductImageUploadCascadeTest` (upload image = thumb généré + propagé surface) | routine | `tests/Feature/Catalog/ProductImageUploadCascadeTest.php` | S |
| `T-DEEP-PROD-07` | Sentinel `ProductDuplicateMaintainsComposerProfileDraftTest` (M2 1.7 vérif draft profile correctement copié) | routine | extension de `ItemDuplicationTest` | S |
| `T-DEEP-PROD-08` | Sentinel `ProductSearchAdminPaginationTest` (recherche + filtre + pagination admin Items list) | routine | `tests/js/ItemListSearchPagination.spec.js` | S |

### Famille C — DEEP gestion STOCK (D21 partie 3)

| ID | Titre | Tier | Sentinel cible | Effort |
|---|---|---|---|---|
| `T-DEEP-STK-01` | Sentinel `StockMovementsLedgerCompletenessTest` (toutes les mutations stock écrivent un mvt avec idempotency_key + reason) | routine | `tests/Feature/Stock/StockMovementsLedgerCompletenessTest.php` | M |
| `T-DEEP-STK-02` | Sentinel `StockReorderPointTriggerTest` (passage on_hand sous threshold_low → low alert event throttled M2 2.7 vérifié) | routine | `tests/Feature/Stock/StockReorderPointTriggerTest.php` | S |
| `T-DEEP-STK-03` | Sentinel `StockManualAdjustmentAuditTest` (admin POST manual adjust → mvt avec reason='manual_adjustment' + auditeur) | routine | `tests/Feature/Stock/StockManualAdjustmentAuditTest.php` | M |
| `T-DEEP-STK-04` | Endpoint `POST /api/admin/stock/levels/{id}/adjust` (delta + reason + auditeur) | routine | inclus T-DEEP-STK-03 | M |
| `T-DEEP-STK-05` | UI admin : page `/admin/stock/levels` listing + édition inline `on_hand` / `threshold_low` / `max_daily_qty` | routine | `tests/js/StockLevelsAdminTable.spec.js` | M |
| `T-DEEP-STK-06` | Gate `GATE_SCHEMA_STOCK_MOVEMENT_IDEMPOTENCY_KEY_UNIQUE_2026-05-02` (M2 2.6) | gate | déjà écrit | — |
| `T-DEEP-STK-07` | Sentinel `StockReleaseAfterCancelTest` (M2 V1 release verifié end-to-end POS+Kiosk) | routine | `tests/Feature/Stock/StockReleaseAfterCancelTest.php` | M |
| `T-DEEP-STK-08` | Sentinel `StockTransferBetweenBranchesTest` (V2 ?) - DIFFÉRÉ V2 | — | — | L |

### Famille D — REFACTOR Wizard runtime (D19 + D22)

> Tâches précisées après réception de l'audit Axe 4. Liste prévisionnelle :

| ID | Titre | Tier | Effort | Dépendance |
|---|---|---|---|---|
| `T-WIZARD-RT-01` | Extraction logique commune POS+Kiosk dans composable `useWizardComposer` (Vue 3) | complex | L | Axe 4 |
| `T-WIZARD-RT-02` | Refactor POS wizard : composant `PosWizardComponent` dédié (densité élevée, 1 seul écran sans scroll) | complex | L | T-WIZARD-RT-01 |
| `T-WIZARD-RT-03` | Refactor Kiosk wizard : 1 composant par step (Splash-level visuel, fullscreen, animation) | complex | XL | T-WIZARD-RT-01 |
| `T-WIZARD-RT-04` | Sentinel parité POS+Kiosk : même `ComposerProfileProjection::project` consommé identiquement | routine | S | T-WIZARD-RT-01..03 |
| `T-WIZARD-RT-05` | Endpoint admin `POST /api/admin/items/{id}/composer-profile/preview` (preview live admin) | routine | M | indep |
| `T-WIZARD-RT-06` | UI admin : preview live wizard côté `ItemPreviewComponent` (M2 1.2 enrichi) | routine | M | T-WIZARD-RT-05 |
| `T-WIZARD-RT-07` | Sentinel `WizardComposerCustomCompositionTest` ("composition spéciale wizard tout faisable" — D22) | routine | M | T-WIZARD-RT-01 |

### Famille E — CENTRALISATION + SYNC (D11 + D17 deep)

> Tâches précisées après audits Axe 1 + Axe 2. Liste prévisionnelle :

| ID | Titre | Tier | Effort | Dépendance |
|---|---|---|---|---|
| `T-CENT-01` | Activer flag `catalog_v15.unified_projection.enabled=true` après vérif shadow_compare | routine | S | Axe 1 |
| `T-CENT-02` | Refactor (si besoin) controllers POS legacy pour passer 100% via `MenuProjectionService` | complex | M | Axe 1 |
| `T-CENT-03` | Ajout fonction `MenuProjectionService::forChannel('kds')` + `'oss'` si manque | complex | M | Axe 1 |
| `T-SYNC-01` | Renforcement outbox replay : sentinel "perte event impossible si Echo down 5min" | complex | L | Axe 2 |
| `T-SYNC-02` | KDS / OSS fallback polling (équivalent `PosSyncService` M1 1.7) | complex | M | Axe 2 |
| `T-SYNC-03` | Sentinel end-to-end `CatalogStockCentralSyncEndToEndTest` enrichi (Axe 2 paths A-E) | routine | M | Axe 2 |

### Famille F — CLEANUP (D18) — Lots A + B + C

| ID | Titre | Tier | Statut |
|---|---|---|---|
| `Lot A` | Cleanup pur frontend (subscribers/messages/pushNotification/sliders/cookies/analytics/social-media/site + cache 8 sous-modules) | routine | EN ATTENTE Axe 5 |
| `Lot B-livraison` | RENAME table `delivery_boys` archive + retire frontend | routine | GATE PENDING |
| `Lot B-table-service` | RENAME 4 tables service à table + retire frontend + retire POS floorplan | complex | GATE PENDING |
| `Lot B-online` | RENAME table online_orders + retire frontend admin + frontend public | complex | GATE PENDING |
| `Lot C` | Refonte dashboard 4 widgets V1 | routine | EN COURS background |

### Famille G — UX polish (D6 + D7 polish)

| ID | Titre | Tier | Effort |
|---|---|---|---|
| `T-POS-NOTIF-READY-001` | Indicateur visuel discret (badge vert) sur bouton "Suivi commandes" caisse quand commande prête (M2 V1 PosOrdersTracker enrichi) | routine | S |
| `T-OSS-DESIGN-002` | OSS écran client polish design (typo, animation transition statuts, branding) | routine | M |

### Famille H — Gates pending V2

| ID | Titre | Tier | Statut |
|---|---|---|---|
| `M2-2.2` | PricingService composer_version check | complex | GATE PENDING |
| `M2-2.6` | Schema migration unique idempotency_key | complex | GATE PENDING (couvert par T-DEEP-STK-06) |
| `M2-2.9` | Wizard admin guidé multi-step XL | complex XL | DEFERRED — décision user post-audit Axe 4 |

---

## §4 — Plan d'exécution ULTRA priorisé V1

### Phase Π — En cours (background)
- [P1] Audits Axe 1-5 (lecture seule, ~10-20 min)
- [P3] Lot C dashboard refonte (Composer routine)

### Phase 2 — Synthèse + Lot A déclenchement (auto post-audits)
1. Lecture des 5 rapports audits
2. Remplissage `CV1_V1_CLOSEOUT_MASTER_SYNTHESIS_2026-05-02.md`
3. Lancement Lot A cleanup (Composer routine, autonome avec inventaire Axe 5)
4. Lancement T-DEEP-CAT-01..05 et T-DEEP-PROD-01..08 et T-DEEP-STK-01..05 (en parallèle sur sub-agents Composer routine)

### Phase 3 — Décisions & Codex Pro
1. Codex Pro restauré 22:21 → lancer T-WIZARD-RT-01..03 (complex L+XL)
2. User signe gates → lancer Lots B + M2 2.2 + M2 2.6 + T-CENT-* / T-SYNC-* complex

### Phase 4 — Audit Claude terminal indépendant
1. User lance `bash scripts/foodking-claude-orchestrate.sh context && audit-brief`
2. Claude terminal produit 6 rapports (Axes 1-5 + synthèse globale)
3. Cross-référencement avec mes audits in-session = vérification croisée
4. Si divergence : mes audits réajustés

### Phase 5 — V1 close-out final
1. Tous tests verts (PHPUnit + Vitest)
2. Tous gates signés ou défer documenté
3. Tous Lots A+B+C+D appliqués
4. Verdict GO V1 avec score >85/100 sur les 5 axes

---

## §5 — Stratégie de tier-routing par tâche (résumé)

| Tier | Tâches | Délégation |
|---|---|---|
| routine S/M (sentinels, UI mineurs, refactors S) | T-DEEP-CAT-01..05, T-DEEP-PROD-01..08, T-DEEP-STK-01..05, T-DEEP-STK-07, T-WIZARD-RT-04..06, T-SYNC-03, Lot A, Lot C, T-POS-NOTIF-READY-001 | Composer routine `foodking-routine-implementer` |
| complex L+XL | T-WIZARD-RT-01..03, T-WIZARD-RT-07, T-CENT-02..03, T-SYNC-01..02, Lot B-table-service, Lot B-online, M2-2.2, M2-2.9 | Codex `codex-extension` PRIMARY ; fallback `foodking-complex-implementer` si Pro saturé |
| gate humain | M2-2.2, M2-2.6 (T-DEEP-STK-06), Lot B × 3 | écrits, attente signature |
| audit/orchestration | synthèse, plan, audit final | Claude in-session |

---

## §6 — Ce qui définit "V1 fonctionnelle" (definition of done)

1. **POS caisse** : caissier ouvre POS → choisit catégorie → sélectionne item → wizard si applicable → encaisse cash/carte → ticket imprimé → commande envoyée KDS → reçue OSS.
2. **Kiosk** : client devant kiosk → idle screen → choisit catégorie → composition wizard fluide (Splash-level visuel) → paiement → ticket reçu OSS.
3. **KDS** : commandes des 2 surfaces arrivent en temps réel → cuistot bump → état propagé OSS + caisse.
4. **OSS** : affichage public client clair (en cours / prête / récupérée).
5. **Admin** : crée/modifie produit / catégorie / stock / composer profile → propagation 3 surfaces sans incident.
6. **Stock** : décrément automatique correct, atomic decrement (M2 1.9), auto-86 préventif cron (M2 2.1), low alerts (M2 2.7).
7. **Fiscal** : NF525 archive quotidienne, Z-report accessible.
8. **Robustesse** : Echo disconnect → POS fallback polling (M1 1.7), KDS+OSS idem (T-SYNC-02).
9. **Multi-branch** : isolation branche stricte (invariant #3) sur tous les paths.
10. **Tests** : suite globale verte (PHPUnit + Vitest), 0 régression.
11. **Pas de feature inutile** : dashboard épuré (Lot C), modules non-V1 supprimés ou cachés (Lot A+B).

---

**Statut :** ULTRA PLAN écrit. Prochaine action automatique = synthèse audits dès réception + lancement Phase 2.
