# AUDIT_MENU_ITEM_CRUD_011 — CRUD Items (produits)

## Meta
- **Priority** : P0
- **PRIMARY_MODEL** : Claude
- **TEST_STRATEGY** : `static-inspection`
- **DEPENDS_ON** : —
- **Estimation** : 0.75 j-h
- **Vague** : B1

## Contexte

L'Item (produit) est l'atome métier. Sa création/édition/suppression impacte toutes les surfaces : POS, Kiosk, KDS. Risques : suppression hard au lieu de soft-delete (casse historique) ; image uploadée sans validation ; prix TTC/HT confondus ; branch_id implicite manquant.

## Questions d'audit

1. Les Items sont-ils soft-deletés (`deleted_at`) pour préserver l'historique des commandes ?
2. La suppression propage-t-elle un event `ItemDeleted` / `ItemAvailabilityChanged` pour invalidation cache client ?
3. Le `branch_id` d'un Item est-il alimenté côté serveur depuis l'admin connecté ?
4. Les images uploadées passent-elles par validation MIME + redimensionnement + storage privé ? Taille max contrôlée ?
5. Le prix est-il stocké TTC ou HT ? Documenté ? Cohérent entre admin, POS, kiosk ?
6. Les FormRequests validation items sont-elles strictes (name length, price >0, category_id exists + branch_id match) ?
7. La duplication d'un item (clone button) est-elle supportée ? Si oui, branch_id préservé ?
8. L'édition d'un item dont des commandes existantes contiennent le snapshot (nom/prix) : les Order.items gardent-ils une copie figée ou rétrospectivement changent-ils ?
9. Le bulk edit (changer prix plusieurs items en une fois) existe-t-il ? Traçable ?
10. L'unicité (nom + branch_id) est-elle contrainte DB ou seulement validation Laravel ?

## Scope

### SUBSYSTEMS_TOUCHED
- `app/Models/Item.php`
- `app/Http/Controllers/Admin/Item/*`
- `app/Http/Requests/*Item*`
- Migrations items
- `resources/js/components/admin/item/*.vue`

### SUBSYSTEMS_OFF_LIMITS
- Affichage kiosk / POS (autres audits)

## Invariants at Risk
- [x] branch_id data isolation
- [x] Backend pricing SSOT (prix source de vérité)
- [ ] OrderStatus enum

## Fichiers à lire
1. `app/Models/Item.php` (SoftDeletes trait, BranchScope, casts)
2. `app/Http/Controllers/Admin/Item/ItemController.php`
3. `app/Http/Requests/*Item*`
4. Migrations `database/migrations/*items*`
5. Composants admin Vue

## Grep patterns

```
grep -rn "SoftDeletes" app/Models/Item.php
grep -rn "deleted_at\|forceDelete" app/Models/Item.php app/Http/Controllers/Admin/Item/
grep -rn "ItemAvailabilityChanged\|ItemDeleted" app/Events/
grep -rn "class ItemRequest\|class StoreItem\|class UpdateItem" app/Http/Requests/
grep -rn "UploadedFile\|image\|storeAs" app/Http/Controllers/Admin/Item/
grep -n "unique" database/migrations/*items*
grep -rn "price_ttc\|price_ht\|is_tax_included" app/Models/Item.php
```

## Evidence required
- Confirmation SoftDeletes + BranchScope actifs.
- Politique TTC vs HT documentée.
- Présence/absence d'event ItemDeleted.
- Comportement snapshot items dans orders.

## Grille de verdict
- **PASS** : soft-delete, BranchScope, event, validations strictes, snapshot orders préservé.
- **WARN** : soft-delete OK mais event absent OU TTC/HT non documenté.
- **BLOCKED** : hard-delete casse historique, branch_id manipulable, images non validées.

## Livrable
`reports/review/AUDIT_MENU_ITEM_CRUD_011_<DATE>.md`

## Status
- [x] Brief rédigé
- [ ] Plan approuvé
- [ ] Audit exécuté
- [ ] Rapport
- [ ] Tasks correctrices
- [ ] Closed
