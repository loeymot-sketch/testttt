# AUDIT_KIOSK_BRANCH_ISOLATION_022 — Isolation branch_id côté Kiosk

## Meta
- **Priority** : P0
- **PRIMARY_MODEL** : Claude
- **TEST_STRATEGY** : `static-inspection`
- **DEPENDS_ON** : —
- **Estimation** : 0.5 j-h
- **Vague** : C7

## Contexte

Chaque borne est rattachée à une branche unique. L'invariant `branch_id isolation` vaut pour le kiosk exactement comme pour le POS, avec deux couches supplémentaires :
- `channels.php` auth `private-branch.{id}` doit refuser un token kiosk d'une autre branche.
- Le token kiosk doit porter la branche de manière infalsifiable.

## Questions d'audit

1. Le token Sanctum kiosk contient-il / est-il lié à un User associé à une branch_id serveur unique ?
2. `routes/channels.php` sur `branch.{branchId}` valide-t-il que `$user->branch_id === (int) $branchId` **ET** `tokenCan('kiosk:order')` ?
3. Un kiosk reprogrammé pour écouter `private-branch.999` reçoit-il 403 du serveur ?
4. Les endpoints frontend (menu, order store) filtrent-ils systématiquement par branch_id = user.branch_id serveur ?
5. Les data envoyées au kiosk (menu items, catégories, supplements) sont-elles strictement scopées branche ?
6. `FrontendOrderService::myOrderStore` réutilise-t-il `$user->branch_id` pour la commande, **jamais** le payload ?
7. Les events broadcastés contiennent-ils `branch_id` aligné ? (Lu dans EventContract : oui via buildEnvelope)
8. Un kiosk déplacé physiquement (branche A → B) nécessite-t-il réémission de token (révoquer puis créer) ?
9. Le super-admin peut-il "prendre le contrôle" d'une borne à distance ? Si oui, comment c'est audité ?
10. Les logs d'accès kiosk incluent-ils branch_id + kiosk_id pour forensic multi-branches ?

## Scope

### SUBSYSTEMS_TOUCHED
- `routes/channels.php` (lu partiellement)
- `app/Services/FrontendOrderService.php`
- `app/Http/Controllers/Frontend/MenuController.php` (data menu kiosk)
- `app/Models/Scopes/BranchScope.php`
- `app/Http/Middleware/*` appliqués aux routes kiosk

## Invariants at Risk
- [x] **branch_id data isolation** (invariant central)

## Fichiers à lire
1. `routes/channels.php`
2. `app/Services/FrontendOrderService.php`
3. `app/Http/Controllers/Frontend/MenuController.php`
4. `app/Models/User.php` (relation branch)
5. `docs/AUTHZ_MATRIX.md`

## Grep patterns

```
grep -rn "branch_id" routes/channels.php
grep -rn "Broadcast::channel" routes/channels.php
grep -rn "branch_id" app/Services/FrontendOrderService.php
grep -rn "branch_id" app/Http/Controllers/Frontend/
grep -rn "withoutGlobalScope" app/Services/Frontend* app/Http/Controllers/Frontend/
grep -rn "request()->branch_id\|\$request->input('branch_id')" app/Http/Controllers/Frontend/
```

## Evidence required
- Code exact de l'auth channel `branch.{branchId}`.
- Provenance branch_id dans myOrderStore.
- Scopes appliqués à MenuController.
- Gestion re-assignment kiosk.

## Grille de verdict
- **PASS** : channel auth strict, branch_id server-side partout, scopes actifs, aucun leak payload.
- **WARN** : procédure réassignment kiosk manuelle non documentée.
- **BLOCKED** : channel accepte toute branche, branch_id lu payload, data menu cross-branche.

## Livrable
`reports/review/AUDIT_KIOSK_BRANCH_ISOLATION_022_<DATE>.md`

## Status
- [x] Brief rédigé
- [ ] Plan approuvé
- [ ] Audit exécuté
- [ ] Rapport
- [ ] Tasks correctrices
- [ ] Closed
