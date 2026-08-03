# VERIFY-10 — Isolation `branch_id` + Permissions Spatie + State Machine (axes 5-7)

**Date :** 2026-04-20  **Origine :** rapport `AUDIT_POS_110_ISOLATION_PERMS_SM_2026-04-19.md` (**actuellement vide dans le workspace**) + `F-ISO-001/002`, `F-PERM-001/002`, `F-SM-001/002`  **Priorité :** P0  **Mode :** AUDIT-ONLY (mais inclut **restauration** d'un fichier perdu)

## 1. Contexte
Le fichier `AUDIT_POS_110_ISOLATION_PERMS_SM_2026-04-19.md` est **vidé** dans le working tree alors qu'il existait. Il faut **restaurer** son contenu depuis git si possible, sinon **régénérer** un audit complet équivalent.
Risques majeurs : bypass scope branche sur `OrderService::list`, admin `branch_id=0` qui voit tout, autorisations fiscales en contrôleur seulement, transitions d'état legacy via `$order->status = ...`.

## 2. Sources OBLIGATOIRES
- `app/Services/OrderService.php` (list, changeStatus, changePaymentStatus, destroy)
- `app/Services/KitchenDisplaySystemOrderService.php`
- `app/Domain/Order/OrderStateMachine.php`
- `routes/api.php` (groupe admin, middleware `permission:`)
- Controllers POS, KDS, Fiscal
- Spatie config : `database/seeders/RolePermissionTableSeeder.php`
- Tests : `BranchIsolationTest`, `OrderStateMachineApplyTest`, `ConcurrentOrderTest`
- Pusher channels : recherche `private-branch.`
- Doc : `docs/AUTHZ_MATRIX.md`
- Anciens audits : `AUDIT_POS_SECTION_3_FIN_JOURNEE_PERMS_2026-04-18.md`

## 3. Hypothèses à challenger
- H1 : `OrderService::list` accepte `branch_id` du client sans force du scope auth.
- H2 : Admin `branch_id=0` peut muter / déclencher Z d'une autre branche par accident.
- H3 : Routes fiscales sans `permission:` middleware ⇒ contrôleur seul → faille si extension non-Admin.
- H4 : Pusher channel non vérifié (membre rejoint `private-branch.X` arbitraire).
- H5 : Legacy `OrderService` écrit `$order->status` sans passer par OrderStateMachine.

## 4. Plan multi-agent
1. **Étape de restauration** : `git log --all -- reports/review/AUDIT_POS_110_ISOLATION_PERMS_SM_2026-04-19.md`, `git show <sha>:...` puis sauvegarder en `reports/review/AUDIT_POS_110_ISOLATION_PERMS_SM_2026-04-19.RESTORED.md` (sans réécrire le fichier vidé sauf demande).
2. **Explore A** : isolation branch (back).
3. **Explore B** : permissions Spatie + matrice route×rôle.
4. **GeneralPurpose** : state machine vs legacy `$order->status`, produit la matrice complète.

## 5. Vérifications obligatoires
- [ ] V0 : Restauration du fichier vidé (commit & instant ID).
- [ ] V1 : `OrderService::list` force `branch_id = auth->branch_id` (sauf rôle Admin explicite).
- [ ] V2 : Mutations cross-branch retournent 403 avec message explicite.
- [ ] V3 : Pusher channel `private-branch.{id}` validé via `BroadcastChannel` policy.
- [ ] V4 : Routes fiscales protégées par `permission:` ET contrôleur (defense-in-depth).
- [ ] V5 : Aucun `$order->status =` legacy dans les nouveaux call sites.
- [ ] V6 : Matrice route × rôle générée (script `php artisan route:list` + parsing) ou WARN.
- [ ] V7 : Test feature pour staff branchA tente d'accéder branchB.
- [ ] V8 : Admin `branch_id=0` documenté comme choix produit, garde supplémentaire fiscal.

## 6. Critères d'acceptation
- ALL_GREEN si V0–V8 OK.
- WARN si V6 manquant.
- FAIL si V1, V2, V3 ou V5 cassables.

## 7. Livrables
- `reports/review/VERIFY_10_BRANCH_ISOLATION_2026-04-20.md`
- `reports/review/AUDIT_POS_110_ISOLATION_PERMS_SM_2026-04-19.RESTORED.md` (si restauration possible)

## 8. Suite
- FAIL V1 → `P11_BRANCH_SCOPE_FORCE`.
- FAIL V3 → `P11_PUSHER_CHANNEL_AUTHZ`.
- WARN V6 → `P12_ROLE_ROUTE_MATRIX_GEN`.

---

### PROMPT À COLLER
```
Tu es orchestrateur AUDIT-ONLY.
Lis tasks/verify-2026-04-20/10_VERIFY_BRANCH_ISOLATION.md, applique §4-§7.

OBLIGATIONS:
- Étape 0 OBLIGATOIRE: récupérer le contenu git de reports/review/AUDIT_POS_110_ISOLATION_PERMS_SM_2026-04-19.md (vidé) et le sauvegarder dans .RESTORED.md.
- 2 subagents `explore` parallèles (A isolation back, B permissions Spatie + matrice).
- 1 subagent `generalPurpose` synthèse + state machine vs legacy.
- 0 code applicatif modifié.
Livrable: reports/review/VERIFY_10_BRANCH_ISOLATION_2026-04-20.md
Plan 5 lignes. Conclusion "GLOBAL: ..." + cycles P.
```
