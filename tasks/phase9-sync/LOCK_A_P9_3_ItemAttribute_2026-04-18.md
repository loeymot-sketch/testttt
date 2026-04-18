# LOCK_A — `app/Models/ItemAttribute.php` + migration role enum — P9.3 Kiosk

**Status.** RELEASED
**Opened.** 2026-04-18 — Track A Kiosk, démarrage P9.3.
**Owner.** Kiosk Phase 9.3 (wizard robustness, item 9.3.1).
**Branch.** `feat/kiosk-phase-9-3` (HEAD baseline `b890209f8` — merge main + P9.2).

## Motif

Item **9.3.1** ajoute une colonne `role` nullable (enum VARCHAR) sur la table `item_attributes` pour permettre au wizard kiosk de détecter les attributs pain / viande / sauce / taille / topping / drink / condiment **sans substring FR** (fragilité admin-dépendante documentée en audit P1-1 et §4.3).

Le modèle `App\Models\ItemAttribute` est **partagé POS/kiosk** :
- POS consomme via `PosOrderResource`, `OrderItemsResource`, et l'édition POS du wizard admin.
- Kiosk consomme via `kioskSauce|Viande|PainCatalog.js` et les `KioskStep*Component.vue`.

## Périmètre autorisé (additif rétrocompatible uniquement)

| Fichier | Opération autorisée |
|---|---|
| `app/Models/ItemAttribute.php` | **Ajout** à `$fillable` : `'role'`. **Ajout** scope `scopeWithRole($query, $role)` (helper lecture). Aucune modification des attributs existants (`name`, `sort`, `price`, `variation_id`, etc.). |
| `database/migrations/<TS>_add_role_to_item_attributes.php` | `Schema::table('item_attributes', fn ($t) => $t->string('role', 32)->nullable()->after('name')->index())`. `down()` symétrique : `dropIndex + dropColumn` avec `hasColumn()` guard. |
| `database/seeders/ItemAttributeRoleSeeder.php` | Backfill best-effort sur rows existantes (name match en fallback temporaire — uniquement pour migration ponctuelle). N'impose pas `role` NOT NULL. |

## Interdictions

- ❌ Modifier la shape JSON retournée par `PosOrderResource::toArray()` ou `OrderItemsResource::toArray()`.
- ❌ Renommer / déplacer / supprimer un attribut existant du modèle.
- ❌ Rendre `role` NOT NULL (les anciennes rows POS peuvent rester `null` sans casser).
- ❌ Modifier `ItemAttributeRequest` côté admin (une phase ultérieure pourra).
- ❌ Toucher à `app/Services/PricingService.php` (calculs sauces) — frozen zone.

## Cross-track impact

- **Track B (POS).** Lecture seule de `item_attributes.role` ; consommation optionnelle. Aucun breaking change. `BROADCAST_P9_3_MERGED` à diffuser dès merge pour ouvrir à POS la possibilité d'utiliser `role` dans son propre catalog si désiré en POS-9.x.
- **LOCK_B actif sur `ItemAttribute`.** Vérification `CROSS_TRACK_STATUS.md` avant toute touche — à ce jour, aucun LOCK_B posé par Track B.

## Gate de release

- [ ] Migration rollback-safe : `php artisan migrate --database=<driver>` puis `php artisan migrate:rollback --database=<driver>` → diff `0`.
- [ ] Seeder idempotent (2 exécutions consécutives → identiques).
- [ ] Tests POS non régressés : PHPUnit `tests/Feature/Orders/PosOrder*Test.php` + Vitest `tests/js/pos*Spec.js` verts.
- [ ] Commit ajoutant `role` annoté avec SHA dans ce LOCK file + mise à jour `CROSS_TRACK_STATUS.md`.

## Transitions

| Date | Event | SHA | Notes |
|---|---|---|---|
| 2026-04-18 | OPENED | `b890209f8` | Baseline merge main + P9.2 ; prêt pour 9.3.1 migration + seeder. |
| 2026-04-18 | RELEASED | `3f0d86f9b` | Item 9.3.1 committé ; lock libéré pour les travaux suivants. |

## Release

Lorsque 9.3.1 est committé et validé par le verifier, mettre `Status` à `RELEASED`, renseigner le SHA du commit et ajouter une ligne dans `CROSS_TRACK_STATUS.md`.
