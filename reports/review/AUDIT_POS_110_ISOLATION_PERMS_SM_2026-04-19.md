# Axes 5–7 — Isolation `branch_id` / Permissions Spatie / OrderStateMachine

**Date :** 2026-04-19 (régénéré 2026-04-20 — fichier originel jamais commité, 0 octet untracked).
**Mode :** AUDIT-ONLY, lecture seule sur code applicatif.
**Sources lues :**

- `reports/review/VERIFY_10_BRANCH_ISOLATION_2026-04-20.md` (verdicts V0–V8, file:line confirmés).
- `reports/review/AUDIT_POS_110_ISOLATION_PERMS_SM_2026-04-19.RESTORED.md` (note de restauration, raison du fichier vide).
- `tasks/audits/AUDIT_POS_BRANCH_ISOLATION_004.md` (brief originel axes 5-7).
- `reports/review/AUDIT_POS_SECTION_3_FIN_JOURNEE_PERMS_2026-04-18.md` (matrice rôles antérieure).
- `app/Models/Scopes/BranchScope.php`, `app/Models/Order.php`, `app/Services/OrderService.php`, `app/Services/KitchenDisplaySystemOrderService.php`, `app/Domain/Order/OrderStateMachine.php`, `routes/api.php`, `routes/channels.php`, `database/seeders/RolePermissionTableSeeder.php`, `app/Http/Controllers/Admin/Fiscal/{Z,X}ReportController.php`, `app/Http/Controllers/Admin/PosOrderController.php`.
- `AGENTS.md`, `.cursor/rules/safety.mdc`.

> **Note restauration :** le fichier original `AUDIT_POS_110_ISOLATION_PERMS_SM_2026-04-19.md` n'a jamais été committé (`git log --all -- …` vide ; aucune SHA recouvrable, aucune copie dans les worktrees). Cette régénération s'appuie sur le verify cycle 2026-04-20 et reproduit le scope axes 5-7 du POS 110 %. Voir `F-V0-1` ci-dessous.

---

## Résumé exécutif

| Axe | Verdict | Synthèse courte |
|-----|---------|-----------------|
| **5 — Isolation `branch_id`** | **PASS** (avec 1 note opérationnelle) | `BranchScope` global posé sur 5 modèles ; `OrderService::list` ne lit pas `branch_id` payload ; mutations cross-branch → `abort(403)` explicite côté `OrderService` + KDS ; canal Pusher `branch.{id}` correctement gardé ; admin `branch_id=0` documenté. |
| **6 — Permissions Spatie** | **WARN** | Aucun `permission:` middleware niveau route fiscal — la garde est in-method (`abort_unless`). Matrice rôle×route construite manuellement (pas d'outillage `route:list`). |
| **7 — OrderStateMachine** | **PASS_NOTE** | `OrderStateMachine::allows/apply/recordTransition` implémentés et exploités (KDS P4). Affectations legacy `$order->status =` confinées à 3 services frozen-zone V1, toutes derrière une garde transition + audit. |

**Verdict global axes 5-7 : WARN** — aucune fuite cross-tenant ni bypass fiscal identifié dans le code lu, mais defense-in-depth route fiscale et outillage matrice à compléter (cf. cycles `P11/P12` proposés en VERIFY-10 §8).

---

## §5 — Isolation back-end `branch_id`

### 5.1 Mécanisme de scope

- **Global scope `BranchScope`** appliqué sur `Order` (`app/Models/Order.php:82`), `FrontendOrder` (`:23`), `DiningTable` (`:27`), `PushNotification` (`:31`), `User` (`:90`).
- Logique : si user `branch_id == 0` (Admin), aucun filtre ; sinon `where('<table>.branch_id', '=', $userBranch)` (`app/Models/Scopes/BranchScope.php:55-71`).
- Commentaire `[FIX-54-8]` explicite : « *Only admins (branch_id = 0) can see cross-branch records. Regular staff should NEVER see records with branch_id = 0* » (`BranchScope.php:31-36`).
- `withoutGlobalScope[s]` recensés (6 sites) — tous justifiés (signup dup phone, jobs cron, séquence fiscale par branche, commands artisan, agrégation Z avec `where('branch_id', …)` explicite). Aucun usage non-justifié.

### 5.2 Findings

| ID | Sev | Constat | Preuve (file:line) |
|----|-----|---------|---------------------|
| **F-ISO-001** | P1 | KDS `list()` : Admin `branch_id=0` voit toutes les commandes toutes branches confondues — comportement attendu par spec produit, mais à documenter explicitement côté UX (« mode super-admin »). | `app/Services/KitchenDisplaySystemOrderService.php:41-90` (filtres date/branche dépendent de `BranchScope`) ; `BranchScope.php:65-67` (admin = no filter). |
| **F-ISO-002** | P2 | Évents Pusher `private-branch.{id}` correctement gardés : kiosk machine token restreint à sa branche, admin wildcard, staff branche unique. Vérifier que les listeners frontend filtrent bien sur la branche active (subscribe). | `routes/channels.php:20-39`. |
| **F-ISO-003** | P0 | Mutations cross-branch `OrderService::changeStatus / changePaymentStatus / destroy` retournent **`abort(403)`** explicite avec message FR pour tout user non-Admin dont `branch_id` ≠ `order->branch_id`. Defense-in-depth au-delà du global scope. | `app/Services/OrderService.php:1492-1497`, `:1606-1611`, `:1720-1727`. |
| **F-ISO-004** | P0 | KDS `changeStatus` même garde — `abort(403, 'KDS: order does not belong to your branch.')` après `lockForUpdate`. | `app/Services/KitchenDisplaySystemOrderService.php:117-135`. |
| **F-ISO-005** | P0 | `OrderService::list` n'accepte **pas** `branch_id` du client : aucune lecture de `$request->branch_id` dans le bloc `where`. Le scope est imposé par `BranchScope`. **Hypothèse H1 réfutée.** | `app/Services/OrderService.php:106-167`. |
| **F-ISO-006** | P1 | Garde fiscal admin pinné branche : `resolveBranchId()` abort **422** si `auth()->user()->branch_id == 0` (admin pur ne peut pas déclencher Z « par accident »). | `app/Http/Controllers/Admin/Fiscal/ZReportController.php:98-109`, `XReportController.php:28-30`. |
| **F-ISO-007** | P0 | Test feature couvre staff A vs commande branche B : list / show / changeStatus / changePaymentStatus / destroy / KDS list. | `tests/Feature/BranchIsolationTest.php` (+ `ActionLogBranchIsolationTest`, `KioskPhase7/KioskEventBranchIsolationTest`). |

→ **§5 PASS.** Aucun bypass cross-tenant identifié. Comportement Admin `branch_id=0` cross-branche assumé par design (court-circuit explicite `hasRole('Admin')` dans gardes mutateurs ; tracé via `ActionLog` / `AuditLog`).

---

## §6 — Permissions Spatie + matrice route × rôle

### 6.1 Stack

- Routes admin sous `Route::prefix('admin')->middleware(['installed','apiKey','auth:sanctum','localization','throttle:admin-mutation'])` (`routes/api.php:229`).
- **Aucun** middleware `permission:` au niveau route (recherche full-text sur `routes/api.php` → 0 hit).
- Permissions appliquées soit dans `__construct()` des controllers via `$this->middleware('permission:…')`, soit via `abort_unless($user->can('…'))` ad-hoc (notamment fiscal).
- Mapping rôle × permission dans `database/seeders/RolePermissionTableSeeder.php` ; Admin obtient `Permission::all()` (`:18-19`).

### 6.2 Matrice rôle × permission (extrait POS / KDS / Fiscal)

| Permission | Admin | Branch Manager | POS Operator | Chef | Waiter | Stuff |
|------------|:-----:|:--------------:|:------------:|:----:|:------:|:-----:|
| `dashboard` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `pos` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `pos-orders` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `pos-discount-up-to-10` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `pos-discount-over-10-requires-manager` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `pos-manage-fiscal` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `pos-reopen-z` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `pos-destroy-paid` | ✅ (via `Permission::all()`) | ❌ (absent du seed BM) | ❌ | ❌ | ❌ | ❌ |
| `kitchen-display-system` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `order-status-screen` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `online-orders`, `table-orders` | ✅ | ✅ | ❌ | ❌ | ✅ (table) | ❌ |
| `transactions`, `sales-report` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `items_edit` | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `customers_*`, `delivery-boys_*`, `employees_*`, `waiters_*`, `chefs_*` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `settings`, `administrators_*` | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |

Source : `database/seeders/RolePermissionTableSeeder.php:18-146`.

### 6.3 Matrice route × permission (POS / Fiscal / Kiosk admin)

| Route | Verbe | Controller | Permission | Source garde |
|-------|-------|------------|------------|--------------|
| `/admin/pos-order` (index/store) | GET/POST | `PosOrderController` | `pos-orders` (`__construct`) ; `show` : `pos-orders\|pos` ; `reorderItems` : `abort_unless can('pos-orders')` | `app/Http/Controllers/Admin/PosOrderController.php:26-41` |
| `/admin/pos` (store) | POST | `PosController` | `pos` (`__construct`) | `app/Http/Controllers/Admin/PosController.php:19` |
| `/admin/kitchen-display-system/*` | GET/PATCH | `KitchenDisplaySystemController` | `kitchen-display-system` (`__construct`) | `app/Http/Controllers/Admin/KitchenDisplaySystemController.php:22` |
| `/admin/oss-order/*` | GET | `OrderStatusScreenController` | `order-status-screen` (`__construct`) | `OrderStatusScreenController.php:18` |
| `/admin/fiscal/z-report/{index,open,close,show,pdf}` | GET/POST | `ZReportController` | `pos-manage-fiscal` (`abort_unless` **in-method**) | `app/Http/Controllers/Admin/Fiscal/ZReportController.php:91-96` |
| `/admin/fiscal/x-report` | GET | `XReportController` | `pos-manage-fiscal` (`abort_unless` **in-method**) | `app/Http/Controllers/Admin/Fiscal/XReportController.php:25-26` |
| `/admin/online-order/*` | GET/POST/DELETE | `OnlineOrderController` | `online-orders` (`__construct`) | `OnlineOrderController.php:34` |
| `/admin/table-order/*` | GET/POST/DELETE | `TableOrderController` | `table-orders` (`__construct`) | `TableOrderController.php:26` |
| `/admin/dashboard/*` | GET | `DashboardController` | `dashboard` (`__construct`) | `DashboardController.php:29` |
| `/admin/sales-report/*` | GET | `SalesReportController` | `sales-report` (`__construct`) | `SalesReportController.php:37` |

### 6.4 Findings

| ID | Sev | Constat | Preuve (file:line) |
|----|-----|---------|---------------------|
| **F-PERM-001** | P1 | Routes fiscales (`routes/api.php:794-806`) protégées **uniquement** par `abort_unless($user->can('pos-manage-fiscal'))` **dans la méthode** (pas en `__construct`, pas en middleware route). Risque defense-in-depth : un futur endpoint ajouté sans appel à `authorizeFiscal()` serait ouvert à tout staff authentifié. **Hypothèse H3 confirmée partiellement.** | `routes/api.php:794-806` ; `app/Http/Controllers/Admin/Fiscal/ZReportController.php:91-96` ; `XReportController.php:25-26`. |
| **F-PERM-002** | P2 | Matrice route × rôle **non générée automatiquement** (pas de `php artisan permissions:matrix` ni script `route:list → MD`). Maintenance manuelle → drift garanti dès qu'un controller bouge. Exigence POS 110 % « 100 % matrice » non satisfaite par l'outillage. | — (gap outillage). |
| **F-PERM-003** | P2 | `FormRequest::authorize()` retourne `true` sur `PosOrderRequest` et `OrderRequest` (constat hérité d'AUDIT_POS_SECTION_3 §5.3). La garde repose donc entièrement sur middleware controller + scope branche + métier in-service. | `app/Http/Requests/PosOrderRequest.php:19-22`, `OrderRequest.php:19-22`. |
| **F-PERM-004** | P3 | Comportement Admin `branch_id=0` (cross-branche autorisé sur mutateurs OrderService) documenté dans le code (`BranchScope.php:31-36`) **mais pas** dans `docs/AUTHZ_MATRIX.md` (à confirmer — non lu ce cycle). | `BranchScope.php:31-36`, `OrderService.php:1492-1497`. |

→ **§6 WARN.** Defense-in-depth fiscal et outillage matrice à compléter ; aucun bypass actif identifié.

---

## §7 — OrderStateMachine vs legacy `$order->status =`

### 7.1 SSOT — `OrderStateMachine`

```22:79:app/Domain/Order/OrderStateMachine.php
final class OrderStateMachine
{
    public static function allows(int $from, int $to, ?Authenticatable $user = null): bool
    { ... } // pipeline strict + shortcut POS (ACCEPT|PREPARING → DELIVERED si user a perm 'pos')
            // + Admin role peut sortir d'un état terminal CANCELED/REJECTED/RETURNED

    public static function assertAllows(...): void { ... }
    public static function recordTransition(...): void { ... } // best-effort audit row
}
```

```131:171:app/Domain/Order/OrderStateMachine.php
public static function apply(Model $order, int $next, ?Authenticatable $actor = null, ?string $reason = null): void
{
    $from = (int) $order->status;
    if ($from === $next) { return; }
    if (!self::allows($from, $next, $actor)) { throw new IllegalTransitionException(...); }
    if (self::requiresReason($next) && (!is_string($reason) || trim($reason) === '')) {
        throw new IllegalTransitionException('… requires a non-empty reason.');
    }
    DB::transaction(function () use ($order, $from, $next, $actor, $reason): void {
        $order->status = $next;
        if ($reason !== null && $order->isFillable('reason')) { $order->reason = $reason; }
        $order->save();
        self::recordTransition(...);
    });
}
```

- **`requiresReason`** : motif obligatoire pour `CANCELED`, `REJECTED`, `RETURNED` → `IllegalTransitionException`.
- **KDS P4** : `KitchenDisplaySystemOrderService::changeStatus` utilise `lockForUpdate` puis `OrderStateMachine::allows()` sur la ligne — renvoie **409** si `expected_status` client diffère du DB (pattern POS-9.2 K-409).

### 7.2 Recensement legacy `$order->status =`

| Fichier:ligne | Contexte | Garde présente | Verdict |
|---------------|----------|----------------|---------|
| `app/Services/OrderService.php:1402` | helper `changeKDSStatus` côté KDS | pipeline POS-9-H, transition validée en amont | OK frozen-zone V1 |
| `app/Services/OrderService.php:1464` | `changeStatus` self-cancellation user | `ValidStatusTransition` rule L1443 + `recordTransition` L1466 | OK frozen-zone V1 |
| `app/Services/OrderService.php:1519` | `changeStatus` staff | branch check L1492 + rule L1443 + `recordTransition` L1522 + `AuditLog::write` L1550 | OK frozen-zone V1 |
| `app/Services/FrontendOrderService.php:565,676,808` | self-accept / change / lock | symétrie service frontend (frozen-zone) | OK frozen-zone V1 |
| `app/Services/KitchenDisplaySystemOrderService.php:144` | KDS `changeStatus` sur `$locked` | branch check L130-131 + lock pessimiste, transitions limitées | OK frozen-zone V1 |
| `app/Domain/Order/OrderStateMachine.php:156` | `apply` (le SSOT) | gardes intégrées | OK |

→ Tous les call-sites legacy sont **dans des services V1 documentés** (cf. docblock `OrderStateMachine.php:18-20` : « *Existing OrderService / FrontendOrderService call sites keep their historical pattern […] to honour the frozen zone V1 rule* »). Aucun nouveau call-site introduit hors de ces 3 services.

### 7.3 Tests

- `tests/Unit/Domain/Order/OrderStateMachineTest.php` (allows / requiresReason / Admin override).
- `tests/Feature/Domain/OrderStateMachineApplyTest.php` (apply transactionnel, IllegalTransitionException).
- `tests/Feature/BranchIsolationTest.php` (intersection isolation × transition).

### 7.4 Findings

| ID | Sev | Constat | Preuve (file:line) |
|----|-----|---------|---------------------|
| **F-SM-001** | P2 | `OrderService` / `FrontendOrderService` / `KitchenDisplaySystemOrderService` assignent encore `$order->status =` après garde locale, plutôt que `OrderStateMachine::apply()`. Pattern historique frozen-zone V1, à migrer progressivement (cf. cycle `P15` proposé). | `OrderService.php:1402,1464,1519` ; `FrontendOrderService.php:565,676,808` ; `KitchenDisplaySystemOrderService.php:144`. |
| **F-SM-002** | P0 | `OrderStateMachine::allows()` exploité dans validation pipeline (`ValidStatusTransition` rule) **et** KDS P4 (`lockForUpdate` + 409 si dérive). Cohérence renforcée P4. | `app/Domain/Order/OrderStateMachine.php:30-79` ; `KitchenDisplaySystemOrderService.php:117-135` ; `app/Rules/ValidStatusTransition.php`. |
| **F-SM-003** | P1 | Recommandation : ajouter un test régression qui interdit toute nouvelle assignation `\$order->status =` **hors** des 6 sites recensés (pattern `Grep + assert` dans CI ou architectural test type Deptrac). Évite la dérive frozen-zone. | — (proposition outillage). |

→ **§7 PASS_NOTE.** SSOT en place et exploité ; legacy contenu, justifié, tracé. **Hypothèse H5 (« legacy `\$order->status =` non gardé ») RÉFUTÉE.**

---

## Cross-axes — Reconstruction & hygiène rapports

| ID | Sev | Constat | Action |
|----|-----|---------|--------|
| **F-V0-1** | P2 (docu) | Le fichier `AUDIT_POS_110_ISOLATION_PERMS_SM_2026-04-19.md` n'a jamais été commité (vidé puis perdu). Aucun SHA récupérable. La présente version est régénérée à partir de `VERIFY_10_BRANCH_ISOLATION_2026-04-20.md`. | Ajouter hook git pre-commit ou skill `project-handoff` qui empêche les rapports `reports/review/AUDIT_*.md` de rester `??` > 24 h (cf. cycle `P13` proposé). |

---

## Liens tracker (consolidés)

**Isolation (axe 5)** : `F-ISO-001` (KDS admin cross-branche — opérationnel), `F-ISO-002` (Pusher channels), `F-ISO-003`, `F-ISO-004`, `F-ISO-005`, `F-ISO-006`, `F-ISO-007`.

**Permissions (axe 6)** : `F-PERM-001` (fiscal route-level middleware manquant — **P1**), `F-PERM-002` (matrice non automatisée), `F-PERM-003` (FormRequest authorize=true), `F-PERM-004` (doc Admin cross-branche).

**State Machine (axe 7)** : `F-SM-001` (legacy frozen-zone — dette migration), `F-SM-002` (SSOT exploité KDS P4 — favorable), `F-SM-003` (test régression assignation interdite — outillage proposé).

**Hygiène rapport** : `F-V0-1` (perte rapport originel, hook pre-commit).

**Cycles P proposés (suite, voir VERIFY-10 §8)** : `P11_FISCAL_ROUTE_AUTHZ_HARDENING`, `P12_ROLE_ROUTE_MATRIX_GEN`, `P13_AUDIT_REPORT_HYGIENE`, `P14_AUTHZ_MATRIX_DOC_REFRESH`, `P15_STATE_MACHINE_LEGACY_MIGRATION`.

---

## Verdict global axes 5-7

- **PASS** : §5 (isolation back-end branche, mutations cross-branche bloquées, Pusher policy, garde fiscal admin pinné), §7 (SSOT OrderStateMachine + legacy frozen-zone documenté).
- **WARN** : §6 (defense-in-depth route fiscale + matrice non automatisée).
- **FAIL** : aucun.

Aucune fuite cross-tenant ni bypass permission identifié dans le code lu. Risques résiduels : (a) ajout futur d'un endpoint fiscal sans appel à `authorizeFiscal()` (mitiger via cycle P11), (b) drift matrice rôle×route (mitiger via cycle P12), (c) reprise d'un call-site `\$order->status =` hors frozen-zone V1 (mitiger via cycle P15 + test architectural F-SM-003).
