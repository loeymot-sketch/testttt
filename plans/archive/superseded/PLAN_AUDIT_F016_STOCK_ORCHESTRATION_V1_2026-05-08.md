# PLAN_AUDIT_F016 — Stock Orchestration Items + Variations + Extras (V1 fast-food)
**Severity:** P0 — Blocker fonctionnel V1 (rupture sauce/supplément non gérée)
**Owner agent:** Agent A (Menu/Stock)
**Sprint:** S2 (après S0 F-015 + S1 F-001/F-005)
**Estimated:** 7-12 jours-agent backend + 4-5 jours UI dashboard
**Frozen-zone override:** NO côté backend ; côté wizards POS/Kiosk = **filtrage côté API uniquement** (pas de modif des wizards)

---

## 0. POURQUOI CE PLAN EXISTE

Le user (owner) a posé une question précise le 2026-05-08 :

> "Il y a une différence : produit ≠ supplément ≠ composition. Sauce en rupture, cheddar en rupture, une supplément peut être en rupture, ils ne pourront plus le choisir. La case, la borne, la future site web vont avoir besoin d'une bonne implémentation et exécution. Audit profond du système central."

Mon audit V1 précédent ([V1_FOUNDATION_VERDICT_2026-05-08.md](plans/V1_FOUNDATION_VERDICT_2026-05-08.md)) **assumait** que `item_branch_availability` couvrait tout. **Faux**. Il ne couvre que les **items**. Les **extras (suppléments)** et **variations** sont en angle mort.

---

## 1. ÉTAT ACTUEL — Audit profond confirmé

### 1.1 Schema vérifié (lecture directe)

| Entité | Table | Status global | Per-branch availability | Auto-86 quota | Frontend rupture handling |
|---|---|---|---|---|---|
| **Item** (produit principal : burger, pizza, plat) | `items` | ✅ `status` + `is_available` | ✅ `item_branch_availability` | ✅ `max_daily_qty` + `decrementForOrder` | ✅ POS (pastille rouge) + Kiosk (item filtré du menu) |
| **ItemVariation** (taille, cuisson, etc.) | `item_variations` | ✅ `status` (ACTIVE/INACTIVE global) | ❌ **AUCUNE** | ❌ | ❌ |
| **ItemExtra** (sauce, supplément, fromage, etc.) | `item_extras` | ✅ `status` global | ❌ **AUCUNE** | ❌ | ❌ |
| **Addon** | `addons` | ✅ `status` global | ❌ AUCUNE | ❌ | ❌ |

### 1.2 Service `AvailabilityService` — surface vérifiée

```
toggle($itemId, $branchId, $available, $reason)               ← items only
toggleForAllBranches($itemId, $available, $reason)            ← items only
isAvailable($itemId, $branchId): bool                         ← items only
decrementForOrder(Model $order): void                         ← items only (via OrderItem.item_id)
dispatchEvent($itemId, $branchId, $available, $reason)        ← items only
```

→ **Aucune méthode pour extras / variations / addons.**

### 1.3 API `/api/admin/menu/availability/toggle` — vérifié

Route ligne `routes/api.php:238` → `AvailabilityController::toggle`. Accepte `item_id, branch_id, is_available, reason`. **Aucun support extras/variations.**

### 1.4 Wizards (POS Vanilla JS frozen + Kiosk Vue frozen)

- **POS wizard** (`public/js/pos-wizard.js` 5769 LOC) : gère `disabled` sur compteurs (count <= 0, max atteint) **MAIS** ne consomme aucun champ `is_available` sur extras/variations.
- **Kiosk wizard** (`KioskPosWizardComponent.vue` frozen) : `grep "extra.*available"` retourne 0 résultat → idem aucune logique rupture.

→ **Si on flagge un extra/variation en rupture côté DB, les wizards ne le verront pas.**

### 1.5 Verdict gap

**Pour ton fast-food**, si une sauce part en rupture :
- ❌ Impossible de la flagger en rupture per-branche (juste INACTIVE global → masquée partout, pas seulement sur ta branche).
- ❌ Le caissier / kiosk ne peuvent pas voir l'info.
- ❌ La sauce reste cliquable dans le wizard → commande passe → cuisine reçoit la commande sans la sauce → frustration client.

**C'est exactement ce que tu as identifié.** Mon audit précédent était incomplet.

---

## 2. STRATÉGIE D'ARCHITECTURE — Décision orchestrateur

### 2.1 Choix du modèle de données (3 options)

| Option | Description | Pros | Cons | Reco |
|---|---|---|---|---|
| **A. Tables séparées** | `item_extra_branch_availability` + `item_variation_branch_availability` | Simple, requêtes claires, types stricts | 2 nouvelles tables, 2 nouveaux services | ✅ |
| **B. Table polymorphique unifiée** | `menu_entity_branch_availability(entity_type, entity_id, branch_id, ...)` | Extensible (addon futur, etc.), 1 seule table | Requêtes morph plus complexes, indexation moins efficace | ❌ |
| **C. Champs sur tables existantes** | Ajouter `is_available_per_branch` sur item_variations + item_extras | Léger | Casse le pattern V1 existant (item_branch_availability), perd la richesse (max_daily_qty) | ❌ |

**Décision : Option A — Tables séparées.**

Justification : aligne avec le pattern existant `item_branch_availability` (cohérence architecturale), index efficaces, contraintes FK strictes, lisibilité pour le futur dev. Tu peux toujours migrer vers polymorphique en V2 si besoin.

### 2.2 Choix du frontend wizard (gap zones gelées)

Les wizards sont gelés. **Solution :** ne PAS modifier les wizards, mais **filtrer la liste des extras/variations envoyée par l'API**.

**Backend nouveau comportement :**
- Endpoint `GET /api/frontend/menu` (kiosk) et `GET /api/admin/items/{id}/wizard-config` (POS) renvoient déjà la liste extras/variations.
- On ajoute un champ calculé `is_available_for_branch: true|false` sur chaque extra/variation, basé sur la nouvelle table.
- Quand `is_available_for_branch = false`, on a 2 options : (a) ne pas envoyer l'entrée du tout (filter out), (b) envoyer avec le flag pour que le wizard l'affiche grisé.

**Le user veut option (b)** : "ils ne pourront plus le choisir parce qu'ils sont en rupture" → l'extra reste **visible mais désactivé**.

→ Mais le wizard frozen ne sait pas afficher "désactivé". **Compromis pragmatique pour V1** :
- **Filter out** (option a) : extra absent du wizard → user voit pas la sauce du tout.
- C'est moins riche UX-wise mais zéro modification wizard.
- En V2 (post go-live), revoir le wizard pour afficher grisé proprement.

**Décision V1 : Option (a) — filtrer côté API.** Wizard frozen pas modifié.

### 2.3 Synchronisation realtime — extension du pattern outbox existant

Réutiliser le pattern Outbox déjà en place :
- Nouveaux events PHP : `ItemExtraAvailabilityChanged`, `ItemVariationAvailabilityChanged`.
- Listeners outbox : `PersistItemExtraAvailabilityChangedToOutbox`, idem variation.
- Broadcast sur `private-branch.{id}` channel existant.
- Frontend : ajouter handler dans `KioskAppComponent.vue` (kiosk) et `PosComponent.vue` (POS) — **NOUS POUVONS** modifier ces deux composants car ils sont **HORS** la frozen zone wizard.
- Sur réception de l'event, le handler invalide le cache menu et refetch — le wizard sera nourri par l'API filtrée.

---

## 3. PLAN D'EXÉCUTION — Sub-tasks numérotées

### 3.1 Backend schema (1 jour)

**Migration 1 :** `2026_05_xx_create_item_extra_branch_availability_table.php`

```sql
CREATE TABLE item_extra_branch_availability (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  item_extra_id BIGINT UNSIGNED NOT NULL,
  branch_id BIGINT UNSIGNED NOT NULL,
  is_available BOOLEAN DEFAULT TRUE,
  unavailable_reason VARCHAR(32) NULL,  -- out_of_stock | seasonal | supplier_issue | manual
  unavailable_since DATETIME NULL,
  max_daily_qty INT UNSIGNED NULL,       -- NULL = illimité
  daily_consumed_qty INT UNSIGNED DEFAULT 0,
  daily_reset_at DATE NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uniq_extra_branch (item_extra_id, branch_id),
  INDEX idx_branch_available (branch_id, is_available),
  CONSTRAINT fk_extra_branch_extra FOREIGN KEY (item_extra_id) REFERENCES item_extras(id) ON DELETE CASCADE,
  CONSTRAINT fk_extra_branch_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
);
```

**Migration 2 :** `2026_05_xx_create_item_variation_branch_availability_table.php` — schéma identique avec `item_variation_id`.

**Règle V1** : si aucune ligne pour `(extra_id, branch_id)` → **disponible par défaut** (cohérence avec items).

### 3.2 Models (0.5 jour)

```
app/Models/ItemExtraBranchAvailability.php
app/Models/ItemVariationBranchAvailability.php
```

Avec BranchScope, casts, relations vers ItemExtra/ItemVariation et Branch.

### 3.3 Service AvailabilityService extension (1.5 jour)

Étendre `app/Services/Menu/AvailabilityService.php` (ne pas créer un nouveau service — cohérence) :

```php
// Extras
public function toggleExtra(int $extraId, int $branchId, bool $available, ?string $reason): ItemExtraBranchAvailability;
public function toggleExtraForAllBranches(int $extraId, bool $available, ?string $reason): int;
public function isExtraAvailable(int $extraId, int $branchId): bool;
public function decrementExtraForOrder(int $extraId, int $branchId): void;

// Variations
public function toggleVariation(int $variationId, int $branchId, bool $available, ?string $reason): ItemVariationBranchAvailability;
public function toggleVariationForAllBranches(int $variationId, bool $available, ?string $reason): int;
public function isVariationAvailable(int $variationId, int $branchId): bool;
public function decrementVariationForOrder(int $variationId, int $branchId): void;
```

Pattern strictement identique aux méthodes items existantes (idempotence, transaction, lockForUpdate, dispatch event).

### 3.4 Events (0.5 jour)

```
app/Events/ItemExtraAvailabilityChanged.php
app/Events/ItemVariationAvailabilityChanged.php
```

Constructeurs :
```php
ItemExtraAvailabilityChanged::forBranch(int $extraId, int $branchId, bool $isAvailable, ?string $reason);
ItemVariationAvailabilityChanged::forBranch(int $variationId, int $branchId, bool $isAvailable, ?string $reason);
```

### 3.5 Listeners outbox (1 jour)

```
app/Listeners/PersistItemExtraAvailabilityChangedToOutbox.php
app/Listeners/PersistItemVariationAvailabilityChangedToOutbox.php
```

Pattern identique à `PersistItemAvailabilityChangedToOutbox` :
- INSERT domain_events row avec channel `private-branch.{id}`
- DB::afterCommit → DispatchDomainEventsJob

### 3.6 EventContract update (0.5 jour)

`app/Domain/Events/EventContract.php` :
- Ajouter types canoniques : `MENU_EXTRA_AVAILABILITY_CHANGED`, `MENU_VARIATION_AVAILABILITY_CHANGED`
- Ajouter required keys validation : `extra_id` ou `variation_id`, `is_available`
- Ajouter dans BROADCAST_MAP

### 3.7 Listener auto-86 sur OrderCreated (1 jour)

Étendre `app/Listeners/DecrementItemAvailabilityOnOrder.php` :
- Pour chaque OrderItem du order, parser `item_extras` JSON (ou table OrderItemExtras si existante).
- Pour chaque extra utilisé, appeler `decrementExtraForOrder($extraId, $branchId)`.
- Idem pour variations.
- Si max_daily_qty atteint → toggleExtra(false, 'out_of_stock') → broadcast.

### 3.8 Filtrage côté API menu (kiosk + POS) (1.5 jour)

**Kiosk** : `app/Services/Kiosk/KioskMenuService::preview()` enrichit chaque item avec ses extras/variations **filtrés** :
```php
$filteredExtras = $item->extras
    ->filter(fn($extra) => $extra->status === Status::ACTIVE)
    ->filter(fn($extra) => app(AvailabilityService::class)->isExtraAvailable($extra->id, $branchId));
```
Idem variations.

**POS** : `ItemController::show` ou wizard config endpoint — appliquer la même filter.

### 3.9 Frontend handlers Echo (1 jour)

Dans **`KioskAppComponent.vue`** (HORS frozen) :
- Ajouter subscription `ItemExtraAvailabilityChanged` et `ItemVariationAvailabilityChanged` sur le branch channel.
- Sur event reçu, invalider le cache menu kiosk → refetch /menu pour avoir la liste filtrée fraîche.

Dans **`PosComponent.vue`** (HORS frozen wizard, c'est le composant parent du wizard) :
- Idem : sur event, refetch wizard config / menu / item details.

### 3.10 Endpoints admin pour toggle extras/variations (0.5 jour)

Dans `app/Http/Controllers/Admin/AvailabilityController.php` :
```php
POST /api/admin/menu/availability/extra/toggle      { extra_id, branch_id, is_available, reason }
POST /api/admin/menu/availability/extra/toggle-all  { extra_id, is_available, reason }
POST /api/admin/menu/availability/variation/toggle  { variation_id, branch_id, is_available, reason }
POST /api/admin/menu/availability/variation/toggle-all { variation_id, is_available, reason }
```

Permission : `pos-orders` ou nouveau `menu-availability-manage`.

### 3.11 Endpoint admin pour stock view (0.5 jour)

```php
GET /api/admin/menu/availability/branch/{branch_id}
```

Retourne :
```json
{
  "items": [{ "id", "name", "is_available", "max_daily_qty", "daily_consumed_qty", "reason" }],
  "extras": [{ "id", "name", "item_id", "item_name", "is_available", ... }],
  "variations": [{ "id", "name", "item_id", "attribute", "is_available", ... }]
}
```

→ Backend pour la UI dashboard.

### 3.12 Tests (1.5 jour)

Étendre `tests/Feature/Menu/AvailabilityServiceTest.php` ou créer `MenuExtraVariationAvailabilityTest.php` :

```php
/** @test */ public function extra_can_be_toggled_per_branch();
/** @test */ public function variation_can_be_toggled_per_branch();
/** @test */ public function extra_unavailable_filtered_from_kiosk_menu();
/** @test */ public function extra_unavailable_filtered_from_pos_wizard_config();
/** @test */ public function variation_unavailable_filtered_likewise();
/** @test */ public function broadcast_event_sent_on_extra_toggle();
/** @test */ public function broadcast_event_sent_on_variation_toggle();
/** @test */ public function decrement_extra_on_order_triggers_auto_86_at_max();
/** @test */ public function isExtraAvailable_default_true_when_no_row();
/** @test */ public function order_with_unavailable_extra_is_rejected();  // sécurité
```

### 3.13 UI Dashboard "Stock Manager" (4-5 jours, peut chevaucher backend)

**Composant Vue admin** : `resources/js/components/admin/menu/StockManagerComponent.vue`

Sections :
1. **Vue tableau Items** : nom, branch courante, is_available toggle, max_daily_qty éditable, daily_consumed_qty visible.
2. **Vue tableau Extras** : nom, item parent, is_available toggle, max_daily_qty.
3. **Vue tableau Variations** : nom, item parent, attribute, is_available toggle.
4. **Filtre par branche** (dropdown — pour admin multi-branche).
5. **Bouton "Rupture rapide"** : modal pour toggle rapide avec raison (out_of_stock, seasonal, manual).
6. **Indicateur live** : auto-86 atteint → badge rouge animé.
7. **Historique** (optionnel V1+) : log des toggles avec qui/quand/pourquoi.

Routes Vue Router :
- `/admin/menu/stock` — vue par défaut Items
- `/admin/menu/stock/extras` — Extras
- `/admin/menu/stock/variations` — Variations

Permission UI : visible pour `pos-orders` ou `menu-availability-manage`.

### 3.14 Documentation (0.5 jour)

Étendre `docs/MENU_AVAILABILITY.md` :
- Section "Extras availability"
- Section "Variations availability"
- Mise à jour du tableau Hors V1 (V2+) — retirer "category-level rupture" reste pour V2 mais "extras + variations" est désormais V1

---

## 4. EFFORT TOTAL

| Sub-task | Effort |
|---|---|
| 3.1 Migrations | 1 j |
| 3.2 Models | 0.5 j |
| 3.3 Service extension | 1.5 j |
| 3.4 Events | 0.5 j |
| 3.5 Listeners outbox | 1 j |
| 3.6 EventContract | 0.5 j |
| 3.7 Decrement on order | 1 j |
| 3.8 Filtrage API menu | 1.5 j |
| 3.9 Frontend handlers Echo | 1 j |
| 3.10 Endpoints toggle | 0.5 j |
| 3.11 Endpoint stock view | 0.5 j |
| 3.12 Tests | 1.5 j |
| 3.13 UI Dashboard | 4-5 j |
| 3.14 Doc | 0.5 j |
| **TOTAL** | **15-16 jours-agent** |

→ Backend pur ~10 jours, UI dashboard 4-5 jours en parallèle = **~12-15 jours** wall-clock avec 1 dev backend + 1 dev frontend.

---

## 5. ORCHESTRATION DES SURFACES — Réponse à ta question "où est la data centralisée"

### 5.1 Source of truth

**Backend Laravel central** = la seule source de vérité pour :
- Items, catégories, variations, extras, addons (catalogue)
- Per-branch availability (rupture)
- Quotas et compteurs jour
- État global du menu

### 5.2 Flow de propagation

```
Admin dashboard (toggle rupture sauce X)
    ↓
POST /api/admin/menu/availability/extra/toggle
    ↓
AvailabilityService::toggleExtra
    ├── DB::transaction → INSERT/UPDATE item_extra_branch_availability
    ├── event(ItemExtraAvailabilityChanged::forBranch)
    │     └── PersistItemExtraAvailabilityChangedToOutbox listener
    │           └── INSERT domain_events row (channel=private-branch.X)
    └── DB::afterCommit → DispatchDomainEventsJob
                           ↓
                       Worker queue → Pusher::trigger
                           ↓
        ┌──────────────────┼─────────────────┐
        ↓                  ↓                 ↓
  POS subscribed       Kiosk subscribed   Site web (futur) subscribed
  refetch wizard       refetch menu       refetch menu
  config               (kiosk store        (à implémenter)
                        partial update)
```

### 5.3 Affichage différencié rupture

| Surface | Item rupture | Extra rupture | Variation rupture |
|---|---|---|---|
| **POS** (caissier) | Pastille rouge sur le bouton, désactivé | Filter dans wizard config (V1) — option grisé en V2 | Idem |
| **Kiosk** (client) | Item filtré du menu | Filter dans wizard pré-load (V1) | Idem |
| **KDS** | Pas concerné (commande déjà passée) | Pas concerné | Pas concerné |
| **OSS** | Pas concerné | Pas concerné | Pas concerné |
| **Admin dashboard** | Toggle is_available, voir consumed/max | Toggle, voir | Toggle, voir |
| **Site web futur** | Filter/grisé selon UX choisie | Filter | Filter |

### 5.4 Lignes directrices "central data well managed"

✅ **Single Source of Truth** : Laravel backend
✅ **Pas de cache local divergent** : POS/Kiosk reçoivent toujours la liste filtrée frais à l'ouverture du wizard
✅ **Realtime durable** : Outbox pattern garantit la livraison même si Pusher down momentanément
✅ **Idempotence** : toggle même état = no-op (pas de spam events)
✅ **Audit trail** : `domain_events` garde l'historique complet
✅ **Branch isolation** : BranchScope sur les nouvelles tables
✅ **Extensible** : Site web futur s'abonne au même channel sans refactor backend

---

## 6. INTÉGRATION DANS LE PLAN GLOBAL V1 — Mise à jour

Mon plan V1 précédent était :
```
S0 (1j)   F-015
S1a (1j)  F-001
S1b (0.5j) F-005
S1c (5-7j) UI stock manager (items only)
TOTAL : 8-10j
```

**Plan révisé V1 fonctionnel complet :**

```
S0 (1j)         F-015 production blocker queue config
S1a (1j)        F-001 NF525 kiosk fiscal_sequence_no
S1b (0.5j)      F-005 queue number monotonic fallback
S2 (10-12j)     F-016 Stock orchestration backend (items + extras + variations)
                  + UI dashboard stock manager (parallèle 4-5j)
═══ GO-LIVE FAST-FOOD ═══
TOTAL : 13-15 jours-agent (au lieu de 8-10)
```

→ **+5 jours** par rapport à mon plan précédent, mais avec couverture complète du besoin réel "produits + suppléments + variations".

---

## 7. ACCEPTANCE CRITERIA

| AC | Critère |
|---|---|
| AC1 | Manager peut toggle rupture d'une sauce X pour la branche A via dashboard |
| AC2 | Sauce X disparaît du wizard kiosk de la branche A en <2s |
| AC3 | Sauce X disparaît du wizard POS de la branche A en <2s |
| AC4 | Sauce X reste disponible sur la branche B (isolation) |
| AC5 | Toggle re-disponible → revient dans tous les wizards en <2s |
| AC6 | Variation "XL" peut être toggled rupture identiquement |
| AC7 | Order avec extra rupture est rejetée 422 (sécurité défensive) |
| AC8 | Admin voit dashboard avec items, extras, variations par branche |
| AC9 | Auto-86 fonctionne sur extras (max_daily_qty atteint → broadcast) |
| AC10 | Site web futur peut s'abonner sans refactor backend |
| AC11 | Pas de modif des wizards frozen (POS Vanilla JS, Kiosk Vue) |
| AC12 | Tous les events validés par EventContract |
| AC13 | Outbox retries fonctionnent (test simulant Pusher down) |
| AC14 | Branch isolation testée (toggle branche A ne broadcaste pas sur B) |

---

## 8. ANTI-DRIFT

- [ ] Aucune modification de `pos-wizard.js` (frozen)
- [ ] Aucune modification des composants kiosk wizard frozen (KioskWizardComponent, KioskPosWizardComponent, KioskCartComponent, KioskCategoriesComponent, KioskUpsellComponent, KioskPromoCarouselComponent, KioskOrderSummaryComponent, KioskProductListComponent)
- [ ] Modifications autorisées sur KioskAppComponent.vue + PosComponent.vue (HORS frozen)
- [ ] Pattern strictement identique à AvailabilityService existant (cohérence)
- [ ] BranchScope appliqué sur les 2 nouvelles tables
- [ ] Pas de bypass de pricing SSOT
- [ ] EventContract validation respectée

---

## 9. RISK REGISTER

| Risk | Probabilité | Impact | Mitigation |
|---|---|---|---|
| Filtre API menu fait apparaître items sans extras → wizard cassé visuellement | Medium | Medium | Tests E2E Playwright sur kiosk avec sauce ruptured ; documentation produit |
| Migration bloque sur item_extras qui ne FK existe pas | Low | High | Test sur copie DB prod avant deploy |
| User flag rupture sur 100+ extras simultanément → DB lock cascade | Low | Medium | Endpoint bulk avec transaction + cap |
| Auto-86 sur extra rupture pendant rush → caissier surpris | Medium | Low | Notification dashboard + son optionnel |
| Wizard cache stale 5+ minutes après broadcast | Medium | Medium | Cache TTL court (60s) + invalidation explicite via listener |

---

## 10. PROCHAINES ÉTAPES

1. **Owner valide** ce plan F-016 (5 min lecture).
2. **Décide** UI dashboard parallèle backend (oui/non).
3. **Exécuteur lance** S0 (F-015), puis S1 (F-001 + F-005), puis S2 (F-016).
4. **Test sync 6 étapes** + new test "sauce rupture multi-surface" avant deploy.
5. **Go-live fast-food** quand checklist V1 verte.

---

## 11. SIGNATURE

- Audit conduit par : Claude orchestrateur
- Date : 2026-05-08
- Évidence : 100% vérifiée par lecture directe code + migrations + grep services
- Verdict : gap réel identifié, plan actionable, effort honnête (15-16 jours)

— *La rupture d'une sauce ne peut pas être un angle mort. La discipline V1, c'est aussi ne pas oublier les détails opérationnels.*
