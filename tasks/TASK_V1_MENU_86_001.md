# TASK_V1_MENU_86_001 — Gestion rupture / disponibilité menu multi-surface

## Meta
- **Priority** : P0 (cœur métier V1)
- **Vague** : 2 — Domaine SSOT
- **PRIMARY_MODEL** : GPT-5.4 (multi-surface coordonné)
- **TEST_STRATEGY** : `playwright-critical-flow`
- **DEPENDS_ON** : TASK_V1_EVENT_CONTRACT_001
- **BLOCKS** : —
- **Estimation** : 3 j-h

## Contexte

FoodKing n'a **aucune gestion de rupture**. Si la cuisine épuise un produit :
- La borne kiosk continue à l'afficher et à le vendre.
- Le POS l'encaisse.
- Le KDS reçoit une commande pour un article impossible à servir.
- Le client paie sans avoir ce qu'il a commandé.

C'est une **fonctionnalité cœur métier** absente. Un restaurant ne peut pas lancer en production sans.

V1 : toggle simple "rupture", broadcast live aux 4 surfaces, sans aller jusqu'à un inventaire complet matières premières (V2+).

## Acceptance Criteria
- [ ] Table `product_branch_availability` créée : `(product_id, branch_id, is_available, unavailable_reason, unavailable_since, max_daily_qty nullable, daily_consumed_qty)`.
- [ ] Mêmes colonnes étendues à `product_options` (option level) et `product_categories` (toute catégorie unavailable → tous items de la catégorie unavailable).
- [ ] UI Admin : toggle "Rupture" (avec sélecteur raison : out_of_stock, seasonal, closed_today, manual) + scope (toutes branches / branche active).
- [ ] UI POS : pastille rouge + tap désactivé sur article en rupture. Toast informatif.
- [ ] UI Kiosk : **masquage automatique** (l'article ne doit pas apparaître du tout dans l'arbre de navigation).
- [ ] UI KDS : badge rouge "rupture signalée" sur commandes déjà en cours contenant un article passé en rupture pendant la prépa.
- [ ] Event `menu.item_availability_changed` (via outbox) broadcasté avec payload `{ product_id, branch_id, is_available, reason, occurred_at }`.
- [ ] Compteur optionnel "quantité restante N" : si `max_daily_qty` défini, décrémente à chaque commande confirmée, auto-rupture à 0 avec event.
- [ ] Test Playwright : scénario rupture pendant commande kiosk → article disparaît < 2s.
- [ ] `docs/MENU_AVAILABILITY.md` livré.

## Scope

### SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| `database/migrations/*_create_product_branch_availability_table.php` | nouveau | Write | Yes (branch_id central) | Yes |
| `database/migrations/*_add_availability_to_product_options.php` | nouveau | Write | Yes | Yes |
| `app/Models/ProductBranchAvailability.php` | nouveau | Write | Yes | Yes |
| `app/Services/Menu/AvailabilityService.php` | nouveau — orchestre toggle + event | Write | Yes | Yes |
| `app/Events/MenuItemAvailabilityChanged.php` | nouveau (DomainEvent) | Write | Yes | Yes |
| `app/Http/Controllers/Admin/ProductController.php` | ajout endpoint toggle | Write | No | Yes |
| `resources/js/components/admin/products/AvailabilityToggle.vue` | nouveau composant | Write | No | No |
| `resources/js/components/admin/pos/ProductCard.vue` | pastille rupture | Write | No | No |
| `resources/js/kiosk/components/ProductList.vue` | filter hors rupture | Write | No | No |
| `resources/js/components/admin/kitchenDisplaySystem/OrderCard.vue` | badge rupture | Write | No | No |
| `tests/Feature/Menu/AvailabilityTest.php` | tests | Write | No | No |
| `tests/playwright/menu-rupture.spec.ts` | Playwright | Write | No | No |
| `docs/MENU_AVAILABILITY.md` | doc | Write | No | No |

### SUBSYSTEMS_OFF_LIMITS
- Inventaire matières premières — V2+.
- Fournisseurs / approvisionnement — V2+.
- Suggestions de substitution produit — V2+.
- `OrderService` / `FrontendOrderService` — frozen. Cette task ne modifie **pas** le flow de création de commande, seulement la validation pré-commande (frontend) et la visibilité.

## Invariants at Risk
- [ ] None
- [ ] Backend pricing SSOT
- [ ] OrderStatus enum
- [x] **branch_id data isolation** — la rupture est scoped par branche.
- [x] Dispatch after DB commit — event via outbox.
- [ ] OrderService / FrontendOrderService symmetry
- [ ] Frozen zone

## Execution Steps

### E1 — Migration DB
```sql
CREATE TABLE product_branch_availability (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  branch_id BIGINT UNSIGNED NOT NULL,
  is_available BOOLEAN NOT NULL DEFAULT 1,
  unavailable_reason VARCHAR(32) NULL,  -- enum in PHP
  unavailable_since DATETIME NULL,
  max_daily_qty INT UNSIGNED NULL,       -- null = illimité
  daily_consumed_qty INT UNSIGNED NOT NULL DEFAULT 0,
  daily_reset_at DATE NOT NULL DEFAULT (CURDATE()),
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_product_branch (product_id, branch_id),
  INDEX idx_branch (branch_id),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
);
```
Idem pour `product_options` + colonne `is_available` sur `product_categories` (niveau branche via table dédiée ou JSON).

### E2 — AvailabilityService
```php
final class AvailabilityService {
    public function toggle(int $productId, int $branchId, bool $available, ?string $reason, ?User $actor): void {
        DB::transaction(function() use (...) {
            $row = ProductBranchAvailability::firstOrNew(['product_id' => $productId, 'branch_id' => $branchId]);
            $row->is_available = $available;
            $row->unavailable_reason = $available ? null : $reason;
            $row->unavailable_since = $available ? null : now();
            $row->save();
            event(new MenuItemAvailabilityChanged($productId, $branchId, $available, $reason));
        });
    }

    public function decrementAndMaybe86(int $productId, int $branchId, int $qty): void {
        // Called in OrderService after order confirmed.
        // Incremente daily_consumed_qty, si ≥ max_daily_qty → toggle(false, 'out_of_stock').
    }
}
```

### E3 — UI Admin
1. Liste produits admin — colonne "Disponibilité" avec toggle par branche (ou "Toutes").
2. Modal : sélecteur raison + case "appliquer à toutes les branches".

### E4 — UI POS
Composant `ProductCard` : listener `menu.item_availability_changed` via eventContract. Si `is_available=false` → overlay rouge + tap disabled + toast.

### E5 — UI Kiosk
`ProductList.vue` : filtre actif = `availability[productId] === true`. Listener event → re-render. Si article au panier devient rupture → alert + remove du panier.

### E6 — UI KDS
`OrderCard` : badge rouge clignotant "!" sur commandes contenant un article passé en rupture **après** avoir été commandé. Informer le chef qui peut contacter le client.

### E7 — Auto-86 via compteur
Dans `OrderService::confirm(...)` (frozen zone, ajout minimal) :
```php
foreach ($order->items as $item) {
    $this->availabilityService->decrementAndMaybe86($item->product_id, $branch->id, $item->quantity);
}
```
Scheduler : reset `daily_consumed_qty` + `daily_reset_at` à 4h du matin chaque jour.

### E8 — Tests Playwright
Scénario :
1. Admin ouvre produit "Tacos XL" → toggle rupture.
2. Kiosk ouverte en parallèle → l'article disparaît en < 2s.
3. POS en parallèle → pastille rouge apparaît.
4. KDS : aucune commande en cours → RAS. Si commande en cours avec l'article → badge.

### E9 — Documentation
`docs/MENU_AVAILABILITY.md` : règles, flows UI par surface, event contract, snapshot écran.

## SYMMETRY_NOTE
Les deux flows de création commande (OrderService POS, FrontendOrderService Kiosk) doivent **tous deux** invoquer `AvailabilityService::decrementAndMaybe86(...)` pour que le compteur reste cohérent. Ajout minimal et symétrique dans les deux services.

## GATE_CONDITIONS
- **Gate requise** : NON.
- Stop-gate si : demande d'aller au-delà de la rupture simple (ex: gestion ingrédients, recettes) → V2.
- Stop-gate si : demande d'intégrer ça avec un ERP externe → V2+.

## Status
- [ ] Pending plan
- [ ] Plan approved
- [ ] In execution
- [ ] Validation
- [ ] Audit
- [ ] Gate open
- [ ] Closed
