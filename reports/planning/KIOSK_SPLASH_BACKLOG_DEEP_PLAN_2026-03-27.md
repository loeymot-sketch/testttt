# Plan d’implémentation — backlog Splash (hors carrousel catégories)

**Date** : 2026-03-27  
**Contexte** : suite à l’analyse `SPLASH_FOODKING_GAP_ANALYSIS_2026-03-27.md`. **Décision produit** : ne pas implémenter le carrousel horizontal de catégories ; conserver la **sidebar + grille** (référence type McDonald’s).

**Type de test global par phase** : voir colonne « Test » (local-validation / Playwright / E2E verification / No-test).

---

## 0. Synthèse exécutive

| Phase | Thème | Effort relatif | Risque métier | Test |
|-------|--------|----------------|---------------|------|
| **A** | Upsell piloté par catégorie | Moyen | Moyen (AOV, cohérence menu) | **local-validation** |
| **B** | « Comme d’habitude ? » (recommandation dernière commande) | Élevé | Élevé (RGPD, identité client, prix serveur) | **Playwright / E2E verification** + local-validation API |
| **C** | Fidélité sur ticket imprimé + polish confirmation | Faible | Faible | **local-validation** + revue visuelle |
| **D** | P2 middle (idle slideshow, hors-stock, copy, PMR) | Variable | Faible à moyen | Mix |
| **E** | P3 structurel (Electron, temps réel étendu) | Très élevé | Élevé | **Playwright / E2E verification** si GO |

---

## Phase A — Upsell par catégorie (équivalent `suggestion_config` Splash)

### A.1 État actuel

- Endpoint `GET /api/frontend/item/kiosk-upsell` (`ItemController::kioskUpsell`) : items `is_upsell = Ask::YES`, complément `is_featured`, exclusion des IDs panier.
- `items.is_upsell` existe (migration 2026_03_25).
- `item_categories` : pas encore de drapeau merchandising upsell.

### A.2 Objectif fonctionnel

1. **Pool de suggestions** : n’afficher comme candidats upsell que les articles dont la catégorie autorise l’inclusion dans le pool (ex. boissons / desserts ouverts, menus fermés).
2. **Saut d’écran (optionnel phase A.2)** : si le panier ne contient **que** des lignes issues de catégories marquées « ne pas proposer l’écran upsell après panier », enchaîner directement vers le paiement (comme Splash qui désactive la suggestion pour certaines catégories).

### A.3 Modèle de données proposé

Migration `item_categories` :

| Colonne | Type | Défaut | Sémantique |
|---------|------|--------|------------|
| `kiosk_upsell_include` | boolean | `true` | Si `false`, les articles de cette catégorie **ne sont jamais** proposés dans `kioskUpsell`. |
| `kiosk_upsell_skip_after_cart` | boolean | `false` | Si `true` et **toutes** les lignes du panier sont dans des catégories avec ce flag → **sauter** la route `kiosk.upsell`. |

**Règle d’agrégation pour le saut** : une ligne = une `item_category_id` (via `items`). Si panier multi-catégories, le saut ne s’applique que si **chaque** catégorie présente a `kiosk_upsell_skip_after_cart = true` (comportement strict, prévisible pour les ops).

**Alternative plus simple (MVP)** : uniquement `kiosk_upsell_include` sur les catégories + filtre SQL sur `Item::whereHas('category', …)` ; reporter le « skip screen » à une itération suivante si besoin de livrer vite.

### A.4 Fichiers impactés (cible)

- `database/migrations/…_add_kiosk_upsell_flags_to_item_categories.php`
- `app/Models/ItemCategory.php` (`$fillable`, `$casts`)
- `app/Http/Controllers/Frontend/ItemController.php` — méthode `kioskUpsell`
- Admin : formulaire catégorie (composant liste / édition catégorie existant)
- `resources/js/components/frontend/kiosk/KioskCartComponent.vue` — `proceedToUpsell` : si API ou getter indique « skip », `push` direct `kiosk.payment` et marquer `upsellShown` pour ne pas re-boucler
- Option : petit endpoint `GET /api/frontend/kiosk-upsell-meta` ou champ dans réponse menu listant les flags par `category_id` pour éviter un aller-retour — **à trancher** (préférer réutiliser le payload menu si déjà chargé).

### A.5 Risques & mitigations

| Risque | Mitigation |
|--------|------------|
| Panier vide de suggestions → écran upsell vide | Conserver fallback `is_featured` **uniquement** parmi catégories `kiosk_upsell_include=true` ; si toujours vide, le composant existant gère déjà skip / auto-skip. |
| Incohérence cache menu | Invalider cache kiosk menu si flags catégorie changent (TTL court déjà) ou versionner `menu_updated_at` côté settings. |
| Régression authz | Route déjà `frontend` + token kiosk ; pas d’exposition admin. |

### A.6 Tests (local-validation)

- PHPUnit : `kioskUpsell` exclut les items dont la catégorie a `kiosk_upsell_include=false`.
- PHPUnit : avec flags skip, logique pure (service dédié `KioskUpsellEligibilityService` recommandé) testable sans HTTP.
- Vitest optionnel : mock store, `proceedToUpsell` branche payment.

---

## Phase B — « Comme d’habitude ? » (dernière commande)

### B.1 Problème

Splash propose un raccourci vers la **dernière commande** du client. Cela impose une **identité stable** (téléphone, code fidélité, QR) et une **reconstruction panier** compatible avec le wizard (variations, extras, prix actuels).

### B.2 Options d’identité (ordre de complexité)

1. **Uniquement après `KioskLoyaltyComponent`** : si `loyaltyCustomer` / token déjà en session store → requête « dernière commande » pour ce `customer_id` + branche `kiosk.idle` ou `kiosk.categories` avec modal « Recommander votre dernière commande ? ».
2. **Jeton anonyme device** (`localStorage` UUID) : lié côté serveur aux commandes kiosk **sans** compte fidélité — **attention RGPD** (consentement affiché, durée de rétention, droit d’effacement).
3. **Scan carte / QR** : même flux que (1) avec identifiant externe.

**Recommandation** : commencer par **(1)** uniquement — pas de nouveau stockage persistant sans validation produit/RGPD.

### B.3 Contrat API esquisse

- `GET /api/frontend/kiosk/last-order`  
  - Auth : `auth:sanctum` + ability kiosk.  
  - Query : `customer_id` (depuis loyalty) **ou** futur token.  
  - Réponse : structure **légère** (lignes `item_id`, qty, snapshots `variation_ids` / `extra_ids` si stockés) + **prix non faisant foi** ; le client doit repasser par wizard ou endpoint « expand » qui **recalcule** via `FrontendOrderService` / même pipeline que POS.

### B.4 Risques

| Risque | Gravité | Note |
|--------|---------|------|
| Prix historique ≠ prix actuel | Haute | Toujours recalcul serveur avant paiement. |
| Article indisponible / menu changé | Haute | Mapper lignes → état « indisponible » + proposer substitut ou retour menu. |
| Sorcellerie UX (double tap, panier non vide) | Moyenne | Règle : vider panier ou fusionner — **décision produit** à figer dans la spec. |

### B.5 Tests

- **local-validation** : contrat API, refus sans customer, recalcul prix.
- **Playwright / E2E verification** : parcours complet loyalty → dernière commande → paiement sur borne de test.

---

## Phase C — Fidélité sur ticket imprimé

### C.1 État actuel

- `KioskConfirmationComponent.vue` : bloc `pointsEarned` + `loyaltyCustomerName` déjà prévu (style Splash).
- Zone `#kiosk-print-receipt` : pas de ligne dédiée « points gagnés » visible dans les premières lignes du template lu.

### C.2 Travail

- Ajouter dans le HTML ticket (impression) les lignes conditionnelles : nom client raccourci, `+X points`, conformité largeur ESC/POS si `kioskPrinter` impose une largeur fixe.
- Vérifier que `buildReceiptData` / `escPosPrint` reçoivent les mêmes champs que l’écran.

### C.3 Test

- **local-validation** : test unitaire sur helper `buildReceiptData` si existant ; sinon test composant shallow.
- **No-test** : ajustement CSS ticket uniquement.

---

## Phase D — Middle changes (P2)

| Item | Description | Fichiers / domaine | Test |
|------|-------------|-------------------|------|
| Idle slideshow | Répéter N médias en attract (au-delà d’une seule vidéo) | Settings + `KioskIdleScreenComponent` | local-validation léger |
| Hors-stock | Badge sur cartes produit si `status` ≠ actif | `KioskCategoriesComponent`, API menu | local-validation |
| Copy Splash | Clés i18n `kiosk.*` alignées « TOUCHEZ POUR COMMANDER », etc. | `lang/*`, composants | No-test |
| PMR | Mode grossissement / contraste (drapeau + classe CSS racine) | `KioskAppComponent` | Playwright / E2E verification accessibilité basique |

---

## Phase E — Structurel (P3)

- **Electron** : reprendre `frontend public/public/electron.js` comme référence dimensions 1080×1920 ; empaqueter l’URL FoodKing ou build statique ; IPC pour TPE si besoin.
- **Temps réel** : déjà `ItemAvailabilityChanged` ; étendre seulement si besoin métier (file d’attente commande sur borne).

---

## 10. Ordre de déploiement recommandé

1. **A** (upsell catégories) — valeur marketing rapide, risque maîtrisable.  
2. **C** (ticket fidélité) — petit diff, complète l’existant.  
3. **D** items à faible coût (copy, hors-stock).  
4. **B** après cadrage juridique + spec UX exacte.  
5. **E** si hardware imposé.

---

## 11. Invariants à ne pas violer

- Recalcul des prix et validations **côté serveur** pour toute commande kiosk.
- Auth machine / Sanctum abilities inchangées pour les nouveaux endpoints.
- Pas de contournement du flux `kioskCart` / `submitOrder` existant.

---

---

## 12. Audit d’avancement (2026-03-27)

| Phase | Statut | Notes |
|-------|--------|--------|
| **A** Upsell par catégorie | **Fait** | Migration `kiosk_upsell_include` / `kiosk_upsell_skip_after_cart`, filtre `ItemController::kioskUpsell`, admin catégories, helper `kioskUpsellFlow.js`, panier + fidélité, `item_category_id` sur lignes panier (wizard). Tests : `KioskUpsellCategoryTest`, `kioskUpsellFlow.spec.js`. |
| **B** Comme d’habitude | Non démarré | Spec inchangée — besoin identité + API recalcul. |
| **C** Fidélité ticket | **Fait** | `buildReceiptData` / ESC-POS / zone HTML confirmation + champs optionnels Electron `orderData`. |
| **D** P2 middle | Partiel | Copy / slideshow / PMR / hors-stock : toujours backlog. |
| **E** Structurel | Non démarré | Electron / temps réel étendu. |

---

*Document vivant — à lier depuis `SPLASH_FOODKING_GAP_ANALYSIS_2026-03-27.md`.*
