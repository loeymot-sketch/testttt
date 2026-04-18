# TASK_V1_DATA_SOFTDELETE_001 — Soft-delete critical tables

## Meta
- **Priority** : P1
- **Vague** : 4 — Data, observabilité, tests
- **PRIMARY_MODEL** : GPT-5.4 (touche branch_scope global scope → vigilance)
- **TEST_STRATEGY** : `local-validation`
- **DEPENDS_ON** : (indépendant)
- **BLOCKS** : —
- **Estimation** : 2 j-h

## Contexte

Les tables critiques (`orders`, `order_items`, `branches`, `users`, `products`, `product_options`, `product_categories`) ne sont **pas soft-deletées**. Conséquences :
- Un clic "Supprimer" admin = perte **irrécupérable**.
- Pas d'audit trail historique (commande supprimée = disparue).
- Pas de possibilité d'annulation utilisateur.

V1 production exige soft-delete + UI de restauration minimale pour l'admin.

Point de vigilance : le `BranchScope` global scope interagit avec `SoftDeletes` trait. Tests existants doivent rester verts.

## Acceptance Criteria
- [ ] Migration ajoute `deleted_at TIMESTAMP NULL` sur : orders, order_items, branches, users, products, product_options, product_categories.
- [ ] Modèles Eloquent utilisent `SoftDeletes` trait.
- [ ] Scopes existants (`BranchScope`) continuent de fonctionner — 0 régression test.
- [ ] UI admin : colonne "Supprimé le" visible sur listes avec toggle "afficher supprimés" + bouton "Restaurer".
- [ ] Audit log : table `deletion_log` (id, model_type, model_id, actor_id, reason, deleted_at). Insertion hook sur `deleting` event Eloquent.
- [ ] Commande `php artisan foodking:purge-old-soft-deleted --days=365` (purge hard-delete > 1 an).
- [ ] Tests existants verts (php artisan test).
- [ ] Test dédié : supprimer commande → invisible par défaut, visible via `withTrashed()`, restaurable.

## Scope

### SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| Migrations multiples ajout `deleted_at` | création | Write | Yes (interaction BranchScope) | No |
| `app/Models/Order.php`, `OrderItem.php`, `Branch.php`, `User.php`, `Product.php`, `ProductOption.php`, `ProductCategory.php` | trait SoftDeletes | Write | Yes | No |
| `app/Models/DeletionLog.php` | nouveau | Write | No | No |
| `database/migrations/*_create_deletion_log_table.php` | nouveau | Write | No | No |
| `app/Observers/DeletionLogObserver.php` | hook Eloquent | Write | No | No |
| `app/Console/Commands/PurgeOldSoftDeletedCommand.php` | purge | Write | No | No |
| `resources/js/components/admin/**` | toggle "afficher supprimés" + bouton restore | Write | No | No |
| `app/Http/Controllers/Admin/*Controller.php` | endpoint restore + liste trashed | Write | No | No |
| `docs/SOFT_DELETE_POLICY.md` | doc | Write | No | No |

### SUBSYSTEMS_OFF_LIMITS
- Pricing / OrderStatus / Events — hors scope.
- Tables non critiques (logs techniques, jobs, cache) — pas de soft-delete, pas utile.

## Invariants at Risk
- [ ] None
- [ ] Backend pricing SSOT
- [ ] OrderStatus enum
- [x] **branch_id data isolation** — BranchScope + SoftDeletes doivent coexister correctement.
- [ ] Dispatch after DB commit
- [ ] OrderService / FrontendOrderService symmetry
- [ ] Frozen zone

## Execution Steps

### E1 — Migrations
Une migration par table pour ajouter `deleted_at TIMESTAMP NULL` + index.

### E2 — Trait
Ajouter `use SoftDeletes;` sur chaque model.

### E3 — Audit log
1. Table `deletion_log`.
2. `DeletionLogObserver` bindé à `Eloquent::deleting` events sur les 7 models.
3. Insertion en transaction.

### E4 — Vérification interaction BranchScope
Tests :
- Admin branch 1 soft-delete une commande → invisible pour tous via scope global.
- Admin branch 0 (super-admin) `withTrashed()` → visible, peut restaurer.
- Branch manager non super-admin ne peut pas `withTrashed()` (policy).

### E5 — UI admin
1. Listings : option "Afficher supprimés" (checkbox).
2. Ligne grisée si soft-deleted, bouton "Restaurer".
3. Modal confirmation suppression → champ `reason` optionnel.

### E6 — Endpoint restore
```php
Route::middleware(['auth', 'can:restore,$model'])->post('/admin/{resource}/{id}/restore', ...);
```

### E7 — Commande purge
```
php artisan foodking:purge-old-soft-deleted --days=365 [--dry-run]
```
Hard delete des rows soft-deletées > 365 jours.

### E8 — Tests
`tests/Feature/SoftDelete/*Test.php` pour chaque model.

### E9 — Documentation
`docs/SOFT_DELETE_POLICY.md` : quelles tables, durée rétention, qui peut restaurer, comment purger.

## SYMMETRY_NOTE
N/A — pas de modification OrderService / FrontendOrderService.

## GATE_CONDITIONS
- **Gate requise** : NON.
- Vigilance : si un test existant se met à échouer à cause du BranchScope + SoftDeletes → STOP et investigation avant bascule. **Ne pas mass-update les scopes** sans comprendre.

## Status
- [ ] Pending plan
- [ ] Plan approved
- [ ] In execution
- [ ] Validation
- [ ] Audit
- [ ] Gate open
- [ ] Closed
