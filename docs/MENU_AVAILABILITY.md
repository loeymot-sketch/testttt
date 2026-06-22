# Menu availability (86 / branch stock) — V1

> VA-SYS-09 note: this file remains useful for product-level 86 behavior, but
> Version A now also supports stockable wizard-choice rupture. The canonical
> combined spec is `docs/sync/STOCK_SYNC_AND_AVAILABILITY.md`.

## Overview

FoodKing V1 gère deux niveaux de disponibilité :

1. **Global item** (niveau `items.status`) — visible ou masqué partout. Modifié par l'admin via l'édition produit. Broadcast à toutes les branches.
2. **Per-branch availability** (table `item_branch_availability`) — rupture locale sur une branche sans toucher au produit global. Broadcast sur la branche concernée uniquement.

Le mode branch-scoped est le chemin privilégié pour la rupture opérationnelle quotidienne (épuisement stock jour, fermeture temporaire d'une catégorie sur une branche). Le mode global reste pour le catalogue.

## Modèle de données

Table `item_branch_availability` (migration `2026_04_15_230100`) :

| Colonne              | Type      | Notes |
|----------------------|-----------|-------|
| `item_id`            | bigint FK | Contrainte unique (item_id, branch_id) |
| `branch_id`          | bigint FK |  |
| `is_available`       | bool      | Défaut `true` |
| `unavailable_reason` | varchar(32) nullable | `out_of_stock` \| `seasonal` \| `closed_today` \| `manual` |
| `unavailable_since`  | datetime nullable |  |
| `max_daily_qty`      | unsigned int nullable | `null` = illimité |
| `daily_consumed_qty` | unsigned int | Réinitialisé par `decrementForOrder` quand `daily_reset_at` != today |
| `daily_reset_at`     | date |  |

**Règle V1** : si aucune ligne n'existe pour `(item_id, branch_id)`, l'item est **disponible par défaut** avec quantité illimitée.

## API service

`App\Services\Menu\AvailabilityService` expose :

### `toggle(int $itemId, int $branchId, bool $available, ?string $reason): ItemBranchAvailability`

- Transactionnel (`DB::transaction` + `lockForUpdate`).
- Crée la ligne si absente.
- Idempotent : ne ré-émet pas l'event si l'état + `reason` sont déjà identiques.
- Émet `ItemAvailabilityChanged::forBranch(...)` sur chaque changement effectif.

### `toggleForAllBranches(int $itemId, bool $available, ?string $reason): int`

- Itère sur toutes les branches, applique `toggle()` à chacune.
- Retourne le nombre de lignes effectivement modifiées (hors no-op).

### `isAvailable(int $itemId, int $branchId): bool`

- Lecture défensive pour les consommateurs (POS / Kiosk filters).
- Retourne `true` si aucune ligne stockée (règle V1).

### `decrementForOrder(Illuminate\Database\Eloquent\Model $order): void`

- Déclenché par listener `DecrementItemAvailabilityOnOrder` sur `OrderCreated`.
- Réinitialise `daily_consumed_qty` si `daily_reset_at` < today.
- Incrémente le compteur, déclenche auto-86 à `max_daily_qty`, et émet `ItemAvailabilityChanged::forBranch($itemId, $branchId, false, 'out_of_stock')` **uniquement au flip** disponible → indisponible.

## Contrat d'event

Event PHP : `App\Events\ItemAvailabilityChanged`.
Constructeurs :

```php
// Mode global (admin edit item)
ItemAvailabilityChanged::fromItem(Item $item, string $type = 'status');

// Mode branch-scoped (rupture / 86)
ItemAvailabilityChanged::forBranch(int $itemId, int $branchId, bool $isAvailable, ?string $reason, float $price = 0.0);
```

Le listener `PersistItemAvailabilityChangedToOutbox` écrit dans `domain_events` :

| Cas | `branch_id` row | `channel` | Payload |
|-----|-----------------|-----------|---------|
| Global | `null` | `["private-branch.{id1}", "private-branch.{id2}", …]` pour chaque branche active | `{item_id, status, price, type}` |
| Branch-scoped | `{branch_id}` | `["private-branch.{branch_id}"]` | `{item_id, status, price, type: "branch_availability", branch_id, is_available, reason}` |

**Canonical event type** : `EventType::MENU_ITEM_AVAILABILITY_CHANGED`.
**broadcast_as** : `ItemAvailabilityChanged` (conforme `App\Domain\Events\EventContract::BROADCAST_MAP`).

Les keys requises par `EventContract::assertPayloadValid()` (`item_id`, `status`) sont présentes dans les deux modes. Le mode branch-scoped ajoute `branch_id`, `is_available`, `reason` comme keys additionnelles — acceptées par le contrat.

## Consommation front

Les surfaces (POS, Kiosk, KDS) s'abonnent à `private-branch.{id}` et écoutent `.ItemAvailabilityChanged`. Le handler doit discriminer le mode via `payload.type` :

- `type === 'branch_availability'` → update UI rupture sur la branche (pastille rouge POS, filter Kiosk).
- `type === 'status'` / `type === 'full'` → refetch menu complet (legacy path).

## Tests

- `tests/Feature/Menu/AvailabilityServiceTest.php` :
  - `toggle` crée + dispatche event.
  - toggle retour clears reason/since.
  - idempotence.
  - `isAvailable` défaut `true`.
  - `toggleForAllBranches` compte correctement.
  - Outbox persiste l'event branch-scoped avec bon channel et payload.

## Hors V1 (V2+)

- Full recipe/raw-material inventory (decrement by recipe ingredients) remains V2+.
  Stockable wizard choices themselves are Version A and are documented in
  `docs/sync/STOCK_SYNC_AND_AVAILABILITY.md`.
- Suggestions de substitution.
- Catégorie-level rupture (table `category_branch_availability`).
- Auto-restock scheduler configurable (cron 4 AM).
