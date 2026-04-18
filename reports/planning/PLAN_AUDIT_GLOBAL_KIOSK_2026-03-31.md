# Audit global borne — Idle → KDS

**Date** : 2026-03-31  
**Périmètre** : Flux complet de prise de commande borne (kiosk), de l'écran d'animation (idle) jusqu'à la réception par le KDS, incluant architecture, logique métier, UI/UX, sécurité et qualité du code.  
**Méthode** : Lecture exhaustive du code source (Vue 3, Vuex, Laravel 9, routes, services, events, middleware).

---

## Table des matières

1. [Vue d'ensemble du flux](#1-vue-densemble-du-flux)
2. [Écran idle / animation](#2-écran-idle--animation)
3. [Authentification machine](#3-authentification-machine)
4. [Shell applicatif (KioskAppComponent)](#4-shell-applicatif-kioskappcomponent)
5. [Catalogue — catégories + produits](#5-catalogue--catégories--produits)
6. [Wizard produit — toutes les étapes](#6-wizard-produit--toutes-les-étapes)
7. [Panier (Cart)](#7-panier-cart)
8. [Upsell](#8-upsell)
9. [Fidélité (Loyalty)](#9-fidélité-loyalty)
10. [Paiement](#10-paiement)
11. [Écran d'attente (Waiting)](#11-écran-dattente-waiting)
12. [Écran de confirmation](#12-écran-de-confirmation)
13. [Backend — création commande](#13-backend--création-commande)
14. [Backend — KDS (réception cuisine)](#14-backend--kds-réception-cuisine)
15. [Sécurité transversale](#15-sécurité-transversale)
16. [Synthèse des findings par sévérité](#16-synthèse-des-findings-par-sévérité)
17. [Plan d'exécution proposé](#17-plan-dexécution-proposé)

---

## 1. Vue d'ensemble du flux

```
Idle (vidéo/animation)
  → Tap → Auto-login machine (Sanctum kiosk:order)
    → Shell (KioskAppComponent) — idle timer, branch bootstrap, Echo
      → Catalogue (catégories sidebar + grille produits)
        → Tap produit → Wizard (taille → viande → pain → sauce → garnitures → suppléments → menu/combo → récap)
          → Ajouter au panier
      → Panier (quantités, type emporter/sur place)
        → Fidélité (optionnel)
        → Upsell (optionnel)
          → Paiement (CB/TPE, espèces, ticket restaurant)
            → POST /api/frontend/order (serveur recalcule les prix)
            → Auto-accept (status ACCEPT) + OrderCreated + OrderStatusChanged broadcasts
              → Écran d'attente (polling + Echo)
                → KDS reçoit la commande (status ACCEPT → PREPARING → PREPARED)
```

**Fichiers clés** : ~35 composants Vue, 8 helpers JS, 2 stores Vuex, 5 fichiers backend (controller, service, model, events), routes API, middleware.

---

## 2. Écran idle / animation

**Fichier** : `KioskIdleScreenComponent.vue`

### Constats positifs
- Vidéo en boucle (`autoplay`, `loop`, `muted`, `playsinline`) avec fallback gradient animé.
- `$nextTick` + `play().catch` gère les restrictions autoplay navigateur.
- `mounted` → `kioskCart/reset` : nettoyage session propre.
- Double-tap prévenu (`touchstart.prevent` + flag `_startingOrder`).

### Findings

| # | Sévérité | Finding | Impact |
|---|----------|---------|--------|
| I-1 | **Faible** | `loadSettings` en `catch` silencieux → fallback textes FR hardcodés | Pas d'erreur visible, mais borne affiche des textes par défaut si l'API settings échoue |
| I-2 | **Faible** | Logo `alt="Logo"` générique | Accessibilité / SEO mineur (borne physique) |
| I-3 | **Info** | Pas de `webpackPrefetch` sur les chunks kiosk malgré le commentaire dans le routeur | Chargement lazy OK mais pas de pré-chargement anticipé |

---

## 3. Authentification machine

**Fichiers** : `KioskLoginComponent.vue`, `kioskRoutes.js` (guards), `config/kiosk.php`, `master.blade.php`, `KioskMachineLoginController.php`

### Constats positifs
- Auto-login transparent : credentials injectés côté serveur (`window.foodkingConfig.kioskAutoLogin`), pas de formulaire visible.
- Token Sanctum avec ability `kiosk:order` (scope réduit).
- `lockForUpdate` + révocation des anciens tokens dans le controller.
- Rate limit `throttle:5,1` sur la route login.
- `config()` au lieu de `env()` (compatible `config:cache`).

### Findings

| # | Sévérité | Finding | Impact |
|---|----------|---------|--------|
| A-1 | **Moyenne** | **Incohérence maintenance** : le guard routeur (`getKioskAutoCredentials`) vérifie `sessionStorage kiosk_maintenance_mode` et skip l'auto-login ; mais `KioskLoginComponent.getAutoCredentials()` ne fait **pas** cette vérification → auto-login se déclenche quand même depuis la page login | En mode maintenance, la borne se reconnecte malgré la volonté de l'admin |
| A-2 | **Faible** | `?auto_failed=1` écrit dans l'URL par le guard mais **jamais lu** par aucun composant | Paramètre mort, pas d'impact fonctionnel |
| A-3 | **Faible** | UX premier affichage : statut « Nouvelle tentative... » avec icône `!` avant tout échec réel | Confusion visuelle initiale |
| A-4 | **Info** | Credentials machine (username/password) dans le HTML source (`window.foodkingConfig`) | Inhérent au pattern SPA-injected ; mitigation = réseau physique + scope token + device control |
| A-5 | **Info** | Token Sanctum persisté en localStorage (via vuex-persistedstate) | Risque XSS standard ; acceptable pour borne dédiée |
| A-6 | **Faible** | Defaults `kiosk-lecayenne` / `kiosk123` si `.env` vide | Dangereux si oublié en prod ; documenté mais pas de garde-fou runtime |
| A-7 | **Faible** | `master.blade.php` unique pour admin + POS + borne → credentials borne dans le HTML de toutes les surfaces | Déploiement : séparer les layouts ou conditionner l'injection |

---

## 4. Shell applicatif (KioskAppComponent)

**Fichier** : `KioskAppComponent.vue`

### Constats positifs
- Idle timer avec exclusions intelligentes (paiement TPE, attente, confirmation).
- Modal « Toujours là ? » avant reset.
- Transitions directionnelles (slide-left/right) selon l'ordre des routes.
- `kioskStableShell` pour éviter le re-render du catalogue à chaque changement de query.
- Echo subscription pour broadcasts temps réel.
- Admin overlay (5 taps) pour maintenance.

### Findings

| # | Sévérité | Finding | Impact |
|---|----------|---------|--------|
| S-1 | **Faible** | Messages d'erreur branch en français hardcodé (`branchError`) | Incohérence i18n |
| S-2 | **Faible** | Modal « Toujours là ? » sans `role="dialog"` / `aria-modal` / focus trap | Accessibilité (mineur pour borne tactile) |
| S-3 | **Info** | Double chargement settings : shell (`frontend/setting`) + idle screen (`frontendSetting/lists`) | Requête réseau redondante |
| S-4 | **Info** | Admin 5-tap = sécurité par obscurité ; protection réelle dépend du contenu de `KioskAdminComponent` | Acceptable si PIN/auth dans le composant admin |

---

## 5. Catalogue — catégories + produits

**Fichiers** : `KioskCategoriesComponent.vue`, `KioskProductListComponent.vue`, `kioskMenu.js`, `kioskCategoryOrder.js`, `kioskItemDisplayOrder.js`, `kioskSandwichSplit.js`, `kioskMenuCache.js`, `kioskDisplayText.js`, `kioskDrinkAddons.js`, `kioskFormatPrice.js`, `ItemCategoryResource.php`

### Constats positifs
- Cache L1 mémoire (5 min TTL) + L2 IndexedDB (24h) avec bannière offline.
- Tri catégories par tiers (plats → accompagnements → desserts/boissons) + sort admin + nom.
- Tri items par prix → taille → sort → nom.
- Split sandwich froid virtuel (config-driven, pas de 2e catégorie en base).
- Sanitisation textes client (`kioskDisplayText`).
- Format prix depuis les settings globaux (devise, position, décimales).

### Findings

| # | Sévérité | Finding | Impact |
|---|----------|---------|--------|
| C-1 | **Haute** | **Contract mismatch images catégories** : `ItemCategoryResource` expose `thumb` / `cover` ; `KioskCategoriesComponent` attend `image_full_path` / `image` → **les thumbnails sidebar tombent toujours sur l'emoji fallback** | Aucune image catégorie visible sur la borne |
| C-2 | **Moyenne** | **Deux surfaces catalogue** (`KioskCategoriesComponent` + `KioskProductListComponent`) avec logique dupliquée (emoji, badges, hasOptions, cart add) mais divergente (sanitisation noms dans ProductList mais pas dans Categories) | Incohérence UX + maintenance |
| C-3 | **Faible** | `hasOptions(product)` sur les items de liste (payload simplifié) → badge « Personnaliser » potentiellement incorrect | Badge trompeur si le payload liste n'inclut pas extras/variations |
| C-4 | **Faible** | Boutons « Mon compte » et « Allergènes » visibles mais `disabled` | Impression de fonctionnalité cassée pour le client |
| C-5 | **Faible** | `filteredProducts` retourne `allItems` si `selectedCategoryId` est falsy | Tous les produits affichés d'un coup (edge case) |
| C-6 | **Info** | Tri répété dans `kioskCatalogItems` (déjà trié par `itemsByCategory`) | Performance négligeable mais code redondant |
| C-7 | **Info** | Regex heuristiques dans `kioskCategoryOrder` : maintenance par tenant | Acceptable pour Le Cayenne, fragile en multi-tenant |
| C-8 | **Info** | Ligne virtuelle « Sandwich froid » toujours en fin de sidebar, pas intégrée au tri par tiers | Voulu par design mais peut surprendre si desserts/boissons sont avant |

---

## 6. Wizard produit — toutes les étapes

**Fichiers** : `KioskWizardComponent.vue`, `KioskStepTailleComponent.vue`, `KioskStepViandeComponent.vue`, `KioskStepPainComponent.vue`, `KioskStepSauceComponent.vue`, `KioskStepGarnituresComponent.vue`, `KioskStepSupplementsComponent.vue`, `KioskStepMenuComponent.vue`, `KioskOrderSummaryComponent.vue`

### Constats positifs
- Orchestrateur dynamique : template par catégorie (`tacos`, `sandwich`, `burger`, etc.) avec steps conditionnels.
- Détection intelligente du nombre de viandes (meta taille + heuristiques nom).
- Sauces avec ordre (première gratuite, extras facturés).
- Garnitures par défaut toutes cochées (UX « retirer » plutôt qu'« ajouter »).
- Running total client-side pour feedback immédiat.
- `canAdvance` par étape (validation avant navigation).
- Transitions slide entre étapes.
- i18n quasi complet (`kiosk.wizard.*`).

### Findings

| # | Sévérité | Finding | Impact |
|---|----------|---------|--------|
| W-1 | **Haute** | **Crash potentiel** `KioskStepPainComponent` : si `itemAttributes` existe mais aucun attribut ne contient « pain » / « galette », `painAttr` est `undefined` → `kioskVariationsForAttribute(item, painAttr.id)` → **TypeError** | Wizard cassé pour un produit sandwich sans attribut pain en base |
| W-2 | **Haute** | **Crash potentiel** `KioskStepViandeComponent` : même pattern — `viandeAttr = find(...)` peut être `undefined` → `viandeAttr.id` → **TypeError** | Wizard cassé pour un produit avec attributs mais sans « viande » |
| W-3 | **Moyenne** | **Duplication formules prix** : `runningTotal` (wizard), `buildCartItem` (wizard), `KioskOrderSummaryComponent` — trois implémentations parallèles du même calcul | Risque de drift → total affiché ≠ total panier ≠ total serveur |
| W-4 | **Moyenne** | **Règle 60/40/100 menu hardcodée** en 3 endroits (wizard + summary + menu step) | Maintenance risquée si le ratio change |
| W-5 | **Moyenne** | **Formatage devise EUR/fr-FR hardcodé** dans `KioskStepSauceComponent`, `KioskStepSupplementsComponent`, `KioskStepMenuComponent` au lieu du mixin `kioskPriceMixin` | Incohérence si devise/locale change |
| W-6 | **Faible** | `currentStepIndex` ne se reset pas si `activeSteps` change dynamiquement (rare) | Index potentiellement hors bornes |
| W-7 | **Faible** | Détection template par nom de produit (`detectTemplateFromName`) fragile | Faux positifs possibles si noms ambigus |
| W-8 | **Faible** | Multi-viande → un seul `item_variations` ID (première viande) ; les autres = instruction texte | Limitation connue : KDS voit l'instruction mais pas les IDs des viandes 2+ |
| W-9 | **Faible** | `hasBoissonIds` computed dans `KioskStepMenuComponent` est **dead code** | Nettoyage |
| W-10 | **Info** | Labels internes `activeSteps[].label` jamais affichés (labels viennent de `getStepLabel`) | Dead data |

---

## 7. Panier (Cart)

**Fichiers** : `KioskCartComponent.vue`, `kioskCart.js` (Vuex)

### Constats positifs
- Merge intelligent des lignes identiques (`item_id` + variations/extras stringifiés).
- Quantité +/- avec mise à jour du total ligne.
- Type de commande (sur place / emporter) sélectionnable.
- Idempotency key par session panier.
- Offline queue avec auto-sync.
- `RESET` nettoie loyalty + upsell flags.

### Findings

| # | Sévérité | Finding | Impact |
|---|----------|---------|--------|
| P-1 | **Faible** | Édition d'un article (pop + wizard) ne pré-remplit pas la quantité | UX : quantité perdue à l'édition |
| P-2 | **Faible** | Merge par `JSON.stringify` sensible à l'ordre des clés | Lignes identiques non mergées si l'ordre JSON diffère entre wizard et upsell |
| P-3 | **Info** | Back button évite `router.go(-1)` (commenté) — bon choix pour éviter le retour vers paiement |  |

---

## 8. Upsell

**Fichier** : `KioskUpsellComponent.vue`

### Constats positifs
- Max 6 suggestions, auto-skip si vide ou erreur.
- Timer 30s avec barre de progression.
- Multi-select avec toggle.

### Findings

| # | Sévérité | Finding | Impact |
|---|----------|---------|--------|
| U-1 | **Faible** | **Pas de debounce** sur `addAndContinue` → double-tap peut dupliquer les ajouts | Lignes en double dans le panier |
| U-2 | **Faible** | Toast succès hardcodé en français (`ajouté !`) | i18n manquant |

---

## 9. Fidélité (Loyalty)

**Fichier** : `KioskLoyaltyComponent.vue`

### Constats positifs
- Numpad large adapté borne.
- Vérification code → affichage solde → choix rédemption.
- Inscription inline.
- Discount plafonné au total panier.

### Findings

| # | Sévérité | Finding | Impact |
|---|----------|---------|--------|
| L-1 | **Moyenne** | **Tiers fidélité hardcodés** `[100, 250, 500, 1000, 2000]` → ne suit pas `frontend/loyalty/config` si le backend évolue | Affichage tiers désynchronisé |
| L-2 | **Moyenne** | **Discount client-side** envoyé dans le payload → si le backend fait confiance au champ `discount` sans revalider, **risque de tampering** | Sous-facturation (dépend du backend — voir §13) |
| L-3 | **Faible** | Config load failure → fallback silencieux `minRedeemPoints = 100` | Valeur par défaut potentiellement incorrecte |
| L-4 | **Info** | Duplication logique navigation upsell/payment avec le panier | Maintenance |

---

## 10. Paiement

**Fichier** : `KioskPaymentComponent.vue`

### Constats positifs
- Trois méthodes (CB/TPE, espèces, ticket restaurant).
- Intégration Electron (`borne.chargeCard`, `borne.openDrawer`).
- Timeout TPE 120s avec `Promise.race`.
- Stub navigateur pour dev.
- Annulation TPE → void (status 16) fire-and-forget.
- Guard `submitting` contre double soumission.
- `payment-confirm` POST pour le `transaction_id`.

### Findings

| # | Sévérité | Finding | Impact |
|---|----------|---------|--------|
| PM-1 | **Moyenne** | **`payment-confirm` failure** = `console.warn` seulement → si le POST échoue, le `transaction_id` n'est **jamais persisté** côté serveur | Réconciliation comptable impossible pour cette commande |
| PM-2 | **Faible** | Montant TPE : préfère `res.data.data.total` puis fallback `cartTotal` → si la réponse API a un format inattendu, le TPE charge le montant client | Écart montant facturé vs encaissé (rare) |
| PM-3 | **Faible** | Void (status 16) fire-and-forget → pas de retry si le réseau échoue | Commande fantôme en base (status ACCEPT mais non payée) |
| PM-4 | **Info** | Loyalty race : warning toast si `loyalty_applied === false` mais commande quand même créée | Comportement voulu (best effort) |

---

## 11. Écran d'attente (Waiting)

**Fichier** : `KioskWaitingComponent.vue`

### Constats positifs
- Polling + Echo (double canal).
- Guard anti-requêtes concurrentes (`_pollInFlight`).
- Bannière réseau après 3 échecs.
- Timeout 15 min avec modal.
- Annulation possible avant PREPARING.
- Auto-reset 20s après PREPARED.
- Mode offline pour `offline_*` IDs.

### Findings

| # | Sévérité | Finding | Impact |
|---|----------|---------|--------|
| WT-1 | **Faible** | `branchId` stale pour Echo → subscription sur mauvais channel si branch change | Événements manqués (polling compense) |
| WT-2 | **Info** | Cancel = `POST change-status` avec status 16 → serveur doit vérifier que le kiosk a le droit d'annuler | Dépend de l'implémentation backend (vérifié : `user_id` match) |

---

## 12. Écran de confirmation

**Fichier** : `KioskConfirmationComponent.vue`

### Constats positifs
- Snapshot panier avant reset.
- Points fidélité calculés.
- Timer auto-retour 30s.
- Impression ticket (DOM caché).

### Findings

| # | Sévérité | Finding | Impact |
|---|----------|---------|--------|
| CF-1 | **Moyenne** | **Route potentiellement orpheline** : aucune navigation vers `kiosk.confirmation` trouvée dans le code SPA (le paiement redirige vers `kiosk.waiting` uniquement) | Écran jamais affiché dans le flux normal → code mort ou flux incomplet |
| CF-2 | **Faible** | Totaux dans l'URL query (`?total=...`) → tamperable pour l'affichage (pas d'impact comptable) | Affichage incorrect si URL modifiée |
| CF-3 | **Faible** | `goHome` émet `close` mais aucun parent ne l'écoute | Emit mort |

---

## 13. Backend — création commande

**Fichiers** : `Frontend/OrderController.php`, `FrontendOrderService.php`, `FrontendOrder.php`

### Constats positifs
- **Recalcul prix serveur** : `unset` des totaux client, recalcul depuis les prix DB.
- **Idempotency** via `X-Idempotency-Key` header.
- **Queue number** avec cache lock + MAX atomique.
- **Loyalty** avec `lockForUpdate` + `LoyaltyTransaction`.
- **Branch forcing** depuis `KioskMachine` (pas de trust client).
- **Address IDOR fix** (address.user_id === auth).
- **Post-commit events** (pas de notifications fantômes).
- **Kiosk auto-accept** (PENDING → ACCEPT automatique).

### Findings

| # | Sévérité | Finding | Impact |
|---|----------|---------|--------|
| B-1 | **Haute** | **Variation/extra IDs invalides silencieusement ignorés** (`continue` au lieu de `throw`) → un payload crafté peut **omettre des add-ons payants** du total serveur tout en les gardant dans le JSON instruction | **Sous-facturation** : le client paie moins que ce que la cuisine prépare |
| B-2 | **Moyenne** | **Validation coupon incomplète** : `Coupon::find` sans vérification fenêtre active, branche, limites par utilisateur, panier minimum | Coupons expirés ou hors scope acceptés |
| B-3 | **Moyenne** | **Discount ligne** (`$item->discount`) persisté mais **ignoré** dans `$verifiedTotalPrice` | Incohérence entre total ligne et total commande |
| B-4 | **Moyenne** | **Auto-accept hors transaction** : `save()` ACCEPT après le commit de la transaction principale → fenêtre où la commande est PENDING en base si le process crash entre les deux | Commande bloquée en PENDING (rare mais possible) |
| B-5 | **Moyenne** | **`OrderStatusChanged` émis avant `OrderCreated`** sur le flux kiosk → les consumers qui n'écoutent qu'un event peuvent avoir un état incohérent | KDS/OSS reçoivent un changement de statut avant la notification de création |
| B-6 | **Faible** | `catch (Exception)` → 422 avec `$exception->getMessage()` → fuite d'information interne | Messages techniques visibles côté client |
| B-7 | **Info** | `FrontendOrder` partage la table `orders` avec `Order` → discipline requise entre les deux modèles | Documenté, acceptable |

---

## 14. Backend — KDS (réception cuisine)

**Fichiers** : `KitchenDisplaySystemController.php`, `KitchenDisplaySystemOrderService.php`

### Constats positifs
- Permission `kitchen-display-system` sur les actions.
- `ValidStatusTransition` avant mise à jour.
- Events post-commit.
- Items board avec groupement par article + variations.

### Findings

| # | Sévérité | Finding | Impact |
|---|----------|---------|--------|
| K-1 | **Moyenne** | **`orderBy($orderColumn, $orderType)` sans allowlist** → colonnes arbitraires acceptées | Erreurs SQL ou information disclosure (admin-only, risque limité) |
| K-2 | **Faible** | Filtre `excepts` appliqué sur `order_type` (pas sur status comme le nom suggère) | Confusion nommage, fonctionnel OK |

---

## 15. Sécurité transversale

| # | Sévérité | Finding | Impact |
|---|----------|---------|--------|
| SEC-1 | **Haute** | **B-1** (variations/extras ignorés) = surface de tampering prix | Sous-facturation |
| SEC-2 | **Moyenne** | **A-7** : credentials borne dans le HTML de toutes les surfaces (layout unique) | Exposition si le même domaine sert admin + public |
| SEC-3 | **Moyenne** | **L-2** : discount loyalty potentiellement trusté côté serveur | À vérifier dans `FrontendOrderService` (le service `unset` les totaux mais le champ `discount` loyalty mérite audit) |
| SEC-4 | **Faible** | Token Sanctum sans expiration configurée (par défaut : jamais) | Token volé = accès permanent |
| SEC-5 | **Faible** | API key statique globale (pas par device) | Rotation difficile |
| SEC-6 | **Info** | Pas de CSP header spécifique pour la borne | XSS mitigation absente (borne dédiée = risque faible) |

---

## 16. Synthèse des findings par sévérité

### Haute (action immédiate recommandée)

| # | Composant | Description |
|---|-----------|-------------|
| **B-1** | `FrontendOrderService` | Variations/extras invalides ignorés → sous-facturation |
| **W-1** | `KioskStepPainComponent` | Crash si attribut « pain » absent |
| **W-2** | `KioskStepViandeComponent` | Crash si attribut « viande » absent |
| **C-1** | `KioskCategoriesComponent` / `ItemCategoryResource` | Images catégories jamais affichées (contract mismatch) |

### Moyenne (à planifier rapidement)

| # | Composant | Description |
|---|-----------|-------------|
| **A-1** | Login / Router | Incohérence maintenance mode |
| **W-3** | Wizard | Duplication formules prix (3 implémentations) |
| **W-4** | Wizard / Menu | Ratio menu 60/40/100 hardcodé ×3 |
| **W-5** | Steps sauce/suppl/menu | EUR/fr-FR hardcodé au lieu du mixin |
| **B-2** | `FrontendOrderService` | Validation coupon incomplète |
| **B-3** | `FrontendOrderService` | Discount ligne ignoré dans le total |
| **B-4** | `FrontendOrderService` | Auto-accept hors transaction |
| **B-5** | Events | `OrderStatusChanged` avant `OrderCreated` |
| **PM-1** | Paiement | `payment-confirm` failure silencieuse |
| **CF-1** | Confirmation | Route potentiellement orpheline |
| **L-1** | Loyalty | Tiers hardcodés |
| **L-2** | Loyalty | Discount client potentiellement trusté |
| **K-1** | KDS Service | `orderBy` sans allowlist |
| **C-2** | Catalogue | Deux surfaces dupliquées divergentes |

### Faible (amélioration qualité)

| # | Description courte |
|---|--------------------|
| A-2 | `auto_failed` query inutilisé |
| A-3 | UX « retrying » avant tout échec |
| A-6 | Defaults credentials si .env vide |
| A-7 | Layout unique expose creds borne |
| S-1 | Erreur branch FR hardcodé |
| S-2 | Modal sans aria |
| C-3 | Badge hasOptions sur payload simplifié |
| C-4 | Boutons disabled visibles |
| C-5 | allItems si selectedCategoryId falsy |
| W-6 | stepIndex hors bornes |
| W-7 | detectTemplate fragile |
| W-8 | Multi-viande → instruction seule |
| W-9 | hasBoissonIds dead code |
| P-1 | Quantité perdue à l'édition |
| P-2 | Merge JSON.stringify ordre-sensible |
| U-1 | Pas de debounce upsell |
| U-2 | Toast FR hardcodé upsell |
| L-3 | Fallback minRedeemPoints |
| PM-2 | Montant TPE fallback client |
| PM-3 | Void fire-and-forget |
| WT-1 | branchId stale Echo |
| CF-2 | Totaux URL tamperable |
| CF-3 | Emit close mort |
| B-6 | Exception message leak |
| K-2 | Nommage filtre excepts |
| SEC-4 | Token sans expiration |
| SEC-5 | API key globale |

---

## 17. Plan d'exécution proposé

### Sprint 1 — Critiques (sécurité + crashes)

| Tâche | Finding | Effort | Test |
|-------|---------|--------|------|
| Rejeter les variation/extra IDs invalides dans `FrontendOrderService` (throw au lieu de continue) | B-1 | 1h | Kimi-test (PHPUnit) |
| Guard `painAttr` / `viandeAttr` undefined dans les steps Pain et Viande (fallback gracieux + log) | W-1, W-2 | 1h | Kimi-test (Vitest) |
| Aligner `ItemCategoryResource` avec les champs attendus par le front (`image` / `image_full_path`) ou adapter le composant | C-1 | 1h | Kimi-test (visuel + Vitest) |

### Sprint 2 — Intégrité métier

| Tâche | Finding | Effort | Test |
|-------|---------|--------|------|
| Wraper auto-accept dans la même transaction DB | B-4 | 2h | Kimi-test (PHPUnit) |
| Ordonner `OrderCreated` avant `OrderStatusChanged` | B-5 | 1h | Kimi-test |
| Valider coupon (fenêtre, branche, limites) | B-2 | 3h | Kimi-test (PHPUnit) |
| Inclure discount ligne dans le calcul total | B-3 | 1h | Kimi-test |
| Retry `payment-confirm` (3 tentatives avec backoff) | PM-1 | 1h | Kimi-test |
| Allowlist `orderBy` dans KDS service | K-1 | 30min | Kimi-test |

### Sprint 3 — Qualité wizard + UI

| Tâche | Finding | Effort | Test |
|-------|---------|--------|------|
| Extraire `calculateRunningTotal()` en helper partagé (wizard + summary + cart) | W-3 | 2h | Kimi-test (Vitest) |
| Extraire ratio menu en constante config | W-4 | 30min | Kimi-test |
| Remplacer `Intl.NumberFormat` hardcodé par `kioskPriceMixin` dans les steps | W-5 | 1h | Kimi-test |
| Corriger incohérence maintenance login | A-1 | 30min | Kimi-test |
| Unifier les deux surfaces catalogue (ou supprimer `KioskProductListComponent` si non utilisé) | C-2 | 2h | Anti-Gravity |
| Masquer ou retirer boutons disabled (compte, allergènes) | C-4 | 15min | No-test |
| Wirer ou supprimer la route `kiosk.confirmation` | CF-1 | 1h | Kimi-test |

### Sprint 4 — Hardening

| Tâche | Finding | Effort | Test |
|-------|---------|--------|------|
| Configurer expiration token Sanctum pour kiosk | SEC-4 | 1h | Kimi-test |
| Conditionner injection `kioskAutoLogin` dans blade (seulement si route kiosk) | A-7 | 1h | Kimi-test |
| Charger tiers loyalty depuis l'API config | L-1 | 1h | Kimi-test |
| Vérifier que le backend revalide le discount loyalty (pas de trust client) | L-2 | 1h | Kimi-test (PHPUnit) |
| Debounce `addAndContinue` upsell | U-1 | 15min | No-test |
| Nettoyage dead code (hasBoissonIds, auto_failed, emit close, labels activeSteps) | W-9, A-2, CF-3, W-10 | 30min | No-test |

---

**Estimation totale** : ~22h de développement réparties sur 4 sprints.  
**Priorité absolue** : Sprint 1 (crashes + sous-facturation) — à exécuter en premier.

---

*Document généré par Claude (architecte) — 2026-03-31. Le code et Git font foi en cas d'écart.*
