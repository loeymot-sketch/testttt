# AUDIT_POS_BRANCH_ISOLATION_004 — Isolation branch_id côté POS

## Meta
- **Priority** : P0
- **PRIMARY_MODEL** : Claude
- **TEST_STRATEGY** : `static-inspection`
- **DEPENDS_ON** : —
- **Estimation** : 0.5 j-h
- **Vague** : A4

## Contexte

FoodKing est multi-branches. L'invariant `branch_id data isolation` est sacré : une caisse de la branche A ne doit JAMAIS lire/écrire des données de la branche B. Violation = fuite cross-tenant, régression P0.

`BranchScope` global sur les modèles + middleware + auth user→branch_id + channels.php → couche complète à auditer.

## Questions d'audit

1. Tous les modèles critiques (Order, OrderItem, Item, Category, User, Customer, Coupon, DomainEvent) ont-ils le `BranchScope` appliqué globalement ?
2. Le `BranchScope` lit-il `auth()->user()->branch_id` ou existe-t-il un chemin override (ex : super-admin) ? Si oui, est-il audité ?
3. Chaque contrôleur POS vérifie-t-il explicitement `$model->branch_id === auth()->user()->branch_id` avant mutation (defense in depth) ?
4. Les requêtes SQL avec joins croisent-elles toujours correctement le branch_id (pas de leak via join sans clause) ?
5. `branch_id` est-il **jamais** lu depuis la requête HTTP client ? (doit venir exclusivement du user authentifié côté serveur)
6. Les jobs asynchrones (DispatchDomainEventsJob) restaurent-ils correctement le contexte branch (sinon ils pourraient tourner avec un branch_id implicite = `null`) ?
7. Les scopes Eloquent (`scopeForBranch`) sont-ils utilisés partout ou existe-t-il du `Order::all()` / `Item::all()` brut ?
8. Les pages d'admin global (super-admin) sont-elles explicitement marquées et isolées (middleware distinct) ?
9. Les routes POS (`routes/api.php` ~L620+) sont-elles protégées par un middleware branch ?
10. Y a-t-il un test d'intrusion (tests/Feature/BranchIsolationTest.php) ? Sinon, c'est une lacune critique.

## Scope

### SUBSYSTEMS_TOUCHED
- `app/Models/Scopes/BranchScope.php` (ou équivalent)
- `app/Models/Order.php`, `OrderItem.php`, `Item.php`, `Category.php`, `User.php`, `Customer.php`, `Coupon.php`, `DomainEvent.php`
- `app/Http/Middleware/*Branch*`
- `app/Http/Controllers/Admin/**`
- `routes/api.php`, `routes/web.php`
- `routes/channels.php`
- `app/Providers/AuthServiceProvider.php`, `app/Providers/AppServiceProvider.php`

### SUBSYSTEMS_OFF_LIMITS
- Kiosk (audit C7)

## Invariants at Risk
- [x] **branch_id data isolation** (invariant central de cet audit)
- [ ] Backend pricing SSOT
- [ ] OrderStatus enum

## Fichiers à lire
1. `app/Models/Scopes/BranchScope.php`
2. Tous les modèles listés (chercher `static::addGlobalScope`)
3. Middleware (grep `Branch` dans `app/Http/Middleware/`)
4. `routes/api.php` (groupes middleware)
5. `routes/channels.php`
6. `docs/AUTHZ_MATRIX.md`

## Grep patterns

```
grep -rn "addGlobalScope\|BranchScope" app/Models/
grep -rn "branch_id" app/Http/Controllers/Admin/
grep -rn "request()->branch_id\|\$request->input('branch_id')\|\$request->branch_id" app/
grep -rn "auth()->user()->branch_id\|Auth::user()->branch_id" app/
grep -rn "->withoutGlobalScope" app/
grep -rn "Order::all\|Item::all\|Category::all" app/ --exclude-dir=Console
grep -rn "branch" routes/channels.php routes/api.php
```

## Evidence required
- Tableau : modèle × BranchScope appliqué (oui/non).
- Liste de chaque endroit qui lit `branch_id` depuis le payload client (doit être VIDE sinon BLOCKED).
- Liste de chaque `withoutGlobalScope` (doit être justifié).
- Vérification que les jobs queue contextualisent le branch (via SerializesModels ou correlation context).
- État du test d'intrusion BranchIsolationTest (existant / absent).

## Grille de verdict
- **PASS** : BranchScope partout, zéro fuite payload, test d'intrusion vert.
- **WARN** : BranchScope présent mais test d'intrusion absent OU 1-2 `withoutGlobalScope` non justifiés.
- **BLOCKED** : branch_id lu depuis client, modèle critique sans BranchScope, join sans clause branch, super-admin route non protégée.

## Livrable
`reports/review/AUDIT_POS_BRANCH_ISOLATION_004_<DATE>.md`

## Status
- [x] Brief rédigé
- [ ] Plan approuvé
- [ ] Audit exécuté
- [ ] Rapport
- [ ] Tasks correctrices
- [ ] Closed
