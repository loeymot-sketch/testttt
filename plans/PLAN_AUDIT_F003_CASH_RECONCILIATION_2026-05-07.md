# PLAN_AUDIT_F003 — Cash Reconciliation (Option A — Cashier-Supervised)
**Severity:** P0 — Risque comptable / fiscal sur écart caisse
**Owner agent:** Agent C (Cash reconciliation)
**Sprint:** S2 (5 jours-agent — le plus volumineux)
**Frozen-zone override:** Partiel (touche schema DB nouvelle, ZReportService dans la mesure de l'aggregation cash variance)
**Décision orchestrateur :** **Option A actée** (cashier-supervised + reconciliation schema). Justification : compatible stub actuel (zéro matériel), aligne NF525 français, rétro-compatible avec le code existant, permet branchement matériel ultérieur sans refactor du flow nominal.

---

## 0. STOP CHECKLIST 6 QUESTIONS

| # | Question | Réponse |
|---|---|---|
| 1 | **Why** ? | `payment_status=PAID` est posé avant validation physique du cash → impossible d'auditer écarts caisse. NF525 + comptabilité française exigent journal de caisse avec ouverture/fermeture/variance par service |
| 2 | **What** minimal ? | (1) Schema `cash_drawer_sessions` + `cash_movements`. (2) API CRUD ouvrir/fermer session par caissier. (3) Hook auto-record cash movement à chaque order PAID en cash. (4) Z report inclut `cash_variance` agrégé sur sessions closes dans la fenêtre. |
| 3 | **Where** ? | DB migrations, nouveau `app/Services/Cash/CashDrawerSessionService.php`, hook dans `OrderService::posOrderStore` + `FrontendOrderService::myOrderStore` (sur PAID cash), `ZReportService::aggregate` (ajout cash_variance), nouveaux endpoints `POST /api/admin/pos/cash-drawer/{open,close}` |
| 4 | **Who impacted** ? | POS UI (pour ouvrir/fermer session), Z report dashboard (afficher variance), comptable (export), audit log chain |
| 5 | **How valider** ? | `tests/Feature/Cash/CashDrawerSessionTest.php`, `CashMovementTest.php`, `ZReportCashVarianceTest.php`, `tests/Feature/Pos/PosOrderCashSessionLinkTest.php` |
| 6 | **When rollback** ? | Si Z report breaks (signature/HMAC) ou si flow POS bloqué par session fermée → revert immédiat, escalade |

---

## 1. THINK — Contexte enrichi

### 1.1 Évidence brute

- POS espèces : [`OrderService.php:591`](app/Services/OrderService.php:591) → `payment_status` = PAID inconditionnel
- Kiosk espèces : [`FrontendOrderService.php:200`](app/Services/FrontendOrderService.php:200) → PAID si cash kiosk auto-confirmé
- Aucun fichier `cash_drawer_*`, aucun service `Cash*` (vérifié `find app/Services -name "Cash*"`)
- `ZReportService::aggregate` ne calcule pas de variance cash

### 1.2 Modèle métier choisi (Option A)

```
1. Caissier prend service → ouvre session caisse (ouvert), déclare fond de caisse initial.
2. Pendant le service, chaque order PAID en cash :
   - persiste un cash_movement (session_id, order_id, amount, type='sale')
3. Caissier ferme service → compte le cash physique, déclare actual_cash.
4. Backend calcule : expected_cash = opening_float + sum(cash_movements.amount where type='sale')
5. variance = actual_cash - expected_cash (peut être positive=plus / négative=manque)
6. Si |variance| > seuil (configurable, défaut 20€) → alerte + raison écrite obligatoire.
7. Z report inclut sum(variance) sur toutes sessions closes dans la fenêtre Z.
```

### 1.3 Compatibilité simulation → réel

- **Simulation** : caissier déclare manuellement le `actual_cash` à la fermeture (UI POS prompt). Pas de matériel requis.
- **Réel kiosk avec billet detector (futur)** : le détecteur push automatiquement les `cash_movements` (machine-counted), variance calculée contre les sales backend.
- **Réel POS** : caissier compte manuellement (modèle français standard). UI POS prompt à la fermeture.

### 1.4 Pas de cassure du code existant

- `payment_status = PAID` reste posé immédiatement (pattern V1 conservé).
- Nouveau : un hook side-effect en post-PAID écrit le cash_movement.
- Si la session n'est pas ouverte (legacy ou kiosk hors session) → cash_movement est créé mais lié à `session_id = NULL` ou à une session "auto" par défaut. Le caissier devra reconcilier post-hoc.
- Z report enrichi mais signature HMAC reste valide (le payload signé inclut désormais cash_variance).

---

## 2. PLAN — Architecture

### 2.1 Schema DB

**Migration 1** : `2026_05_xx_create_cash_drawer_sessions.php`

```sql
CREATE TABLE cash_drawer_sessions (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  branch_id BIGINT UNSIGNED NOT NULL,
  cashier_user_id BIGINT UNSIGNED NOT NULL,
  opened_at TIMESTAMP NOT NULL,
  closed_at TIMESTAMP NULL,
  opening_float DECIMAL(10,2) NOT NULL DEFAULT 0,
  expected_cash DECIMAL(10,2) NULL,
  actual_cash DECIMAL(10,2) NULL,
  variance DECIMAL(10,2) NULL,
  variance_reason VARCHAR(255) NULL,
  status TINYINT NOT NULL DEFAULT 1,  -- 1=open, 2=closed, 3=force_closed
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  INDEX idx_branch_status (branch_id, status),
  INDEX idx_cashier_status (cashier_user_id, status),
  INDEX idx_opened_at (opened_at),
  CONSTRAINT fk_session_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
  CONSTRAINT fk_session_cashier FOREIGN KEY (cashier_user_id) REFERENCES users(id)
);
```

**Migration 2** : `2026_05_xx_create_cash_movements.php`

```sql
CREATE TABLE cash_movements (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  session_id BIGINT UNSIGNED NULL,
  branch_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NULL,
  order_type TINYINT NULL,  -- 1=Order, 2=FrontendOrder
  amount DECIMAL(10,2) NOT NULL,
  type ENUM('sale', 'refund', 'manual_in', 'manual_out', 'opening_float') NOT NULL,
  description VARCHAR(255) NULL,
  recorded_by BIGINT UNSIGNED NULL,
  occurred_at TIMESTAMP NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  INDEX idx_session (session_id),
  INDEX idx_branch_occurred (branch_id, occurred_at),
  INDEX idx_order (order_id, order_type),
  CONSTRAINT fk_movement_session FOREIGN KEY (session_id) REFERENCES cash_drawer_sessions(id),
  CONSTRAINT fk_movement_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
);
```

**Migration 3** : `2026_05_xx_alter_z_reports_add_cash_variance.php`

```sql
ALTER TABLE z_reports
  ADD COLUMN cash_variance DECIMAL(10,2) NULL AFTER total_sales,
  ADD COLUMN cash_session_count INT UNSIGNED NULL AFTER cash_variance;
```

### 2.2 Models

`app/Models/CashDrawerSession.php` :
- `$fillable` : branch_id, cashier_user_id, opened_at, closed_at, opening_float, expected_cash, actual_cash, variance, variance_reason, status
- BranchScope, casts
- relations : `cashier()`, `branch()`, `movements()`, `orders()` (via movements)
- scopes : `open()`, `closed()`, `forBranch($id)`

`app/Models/CashMovement.php` :
- `$fillable` : session_id, branch_id, order_id, order_type, amount, type, description, recorded_by, occurred_at
- BranchScope, casts
- relations : `session()`, `order()` (morphTo selon order_type), `recorder()`

### 2.3 Service

`app/Services/Cash/CashDrawerSessionService.php` :

```php
class CashDrawerSessionService
{
    public function open(int $branchId, int $cashierId, float $openingFloat): CashDrawerSession;
    public function close(CashDrawerSession $session, float $actualCash, ?string $varianceReason = null): CashDrawerSession;
    public function recordSale(int $branchId, int $orderId, int $orderType, float $amount, ?int $sessionId = null): CashMovement;
    public function recordRefund(int $branchId, int $orderId, int $orderType, float $amount, ?int $sessionId = null): CashMovement;
    public function findOpenSession(int $branchId, int $cashierId): ?CashDrawerSession;
    public function aggregateVarianceForWindow(int $branchId, Carbon $from, Carbon $to): array;
}
```

### 2.4 Endpoints

| Endpoint | Permission | Throttle |
|---|---|---|
| `POST /api/admin/pos/cash-drawer/open` | `pos` | 10/min |
| `POST /api/admin/pos/cash-drawer/{session}/close` | `pos` | 10/min |
| `GET /api/admin/pos/cash-drawer/current` | `pos` | 60/min |
| `GET /api/admin/pos/cash-drawer/history` | `pos-orders` | 60/min |

### 2.5 Hook auto-record cash movement

Dans `OrderService::posOrderStore` après `$this->order->save()` final, si payment cash :

```php
if ((int) $request->pos_payment_method === \App\Enums\PosPaymentMethod::CASH) {
    $session = app(CashDrawerSessionService::class)->findOpenSession(
        (int) $this->order->branch_id,
        (int) Auth::id()
    );
    app(CashDrawerSessionService::class)->recordSale(
        branchId: (int) $this->order->branch_id,
        orderId: (int) $this->order->id,
        orderType: 1, // Order
        amount: (float) $this->order->total,
        sessionId: $session?->id
    );
}
```

Dans `FrontendOrderService::myOrderStore` après `$this->frontendOrder->save()`, si cash kiosk auto-PAID :

```php
if ($isImmediatePaidKioskCash) {
    app(CashDrawerSessionService::class)->recordSale(
        branchId: (int) $this->frontendOrder->branch_id,
        orderId: (int) $this->frontendOrder->id,
        orderType: 2, // FrontendOrder
        amount: (float) $this->frontendOrder->total,
        sessionId: null  // kiosk — pas de session cashier liée par défaut
    );
}
```

### 2.6 Z report enrichment

Dans `ZReportService::aggregate` :

```php
// [AUDIT-F-003] Cash variance aggregation across closed sessions in window.
$cashVariance = app(CashDrawerSessionService::class)->aggregateVarianceForWindow(
    $branchId,
    $from ?? Carbon::create(1970),
    $to
);

return [
    // ... existing fields
    'cash_variance' => $cashVariance['variance'],
    'cash_session_count' => $cashVariance['session_count'],
];
```

---

## 3. BUILD — Sous-tâches numérotées

> 5 jours = 5 sub-tasks logiques. Chacune mergée séparément avant la suivante.

### Sub-task 3.1 — Schema + Models (1 j)

1. Créer 3 migrations (cash_drawer_sessions, cash_movements, alter z_reports).
2. Tests `php artisan migrate:fresh --seed` OK.
3. Models avec relations.
4. Tests Unit `tests/Unit/Models/CashDrawerSessionTest.php`, `CashMovementTest.php`.
5. Commit : `audit(F-003): cash reconciliation schema`

### Sub-task 3.2 — Service + Tests Unit (1 j)

1. `CashDrawerSessionService` avec 6 méthodes publiques.
2. Tests `tests/Feature/Cash/CashDrawerSessionServiceTest.php` :
   - open() crée session OPEN avec opening_float
   - close() avec actual_cash > expected → variance positive
   - close() avec actual_cash < expected → variance négative
   - close() sans variance_reason si |variance| > 20€ → 422
   - recordSale() incrémente expected_cash de la session
   - aggregateVarianceForWindow() somme variance des sessions closed
3. Commit : `audit(F-003): cash reconciliation service`

### Sub-task 3.3 — Endpoints + Controller (1 j)

1. Nouveau `app/Http/Controllers/Admin/CashDrawerSessionController.php`.
2. Form Requests `OpenCashSessionRequest`, `CloseCashSessionRequest`.
3. Routes dans `routes/api.php` (groupe pos).
4. Tests Feature `tests/Feature/Cash/CashDrawerEndpointsTest.php` :
   - POST open success
   - POST open déjà ouvert pour caissier → 409 conflict
   - POST close avec variance > seuil sans reason → 422
   - GET current pour caissier OPEN → 200, OPEN session
   - Permission `pos` enforcée (test 403 si User sans permission)
   - Branch isolation (caissier branch A ne voit pas session branch B)
5. Commit : `audit(F-003): cash reconciliation endpoints`

### Sub-task 3.4 — Hooks dans OrderService + FrontendOrderService (1 j)

1. `OrderService::posOrderStore` post-save hook recordSale.
2. `FrontendOrderService::myOrderStore` post-save hook recordSale.
3. Tests `tests/Feature/Pos/PosOrderCashSessionLinkTest.php` :
   - POS cash order avec session ouverte → cash_movement linked
   - POS cash order sans session ouverte → cash_movement avec session_id=NULL + log warning
   - Kiosk cash order → cash_movement avec session_id=NULL (always)
   - Card POS order → AUCUN cash_movement
4. Commit : `audit(F-003): wire cash movements on order paid cash`

### Sub-task 3.5 — Z report variance (1 j)

1. Modifier `ZReportService::aggregate` pour inclure cash_variance.
2. Mettre à jour la signature HMAC payload (z_reports.signed_payload doit inclure cash_variance).
3. Tests `tests/Feature/Cash/ZReportCashVarianceTest.php` :
   - Z report close avec 2 sessions closed (variance +5€ + -3€) → cash_variance = 2€
   - Z report close sans session → cash_variance = 0
   - Signature HMAC valide après ajout du champ
   - Vérifier que les anciens Z reports (sans cash_variance) restent vérifiables
5. Commit : `audit(F-003): include cash_variance in z report`

---

## 4. TEST PLAN — Suites complètes

```bash
./vendor/bin/phpunit tests/Unit/Models/CashDrawerSessionTest.php
./vendor/bin/phpunit tests/Unit/Models/CashMovementTest.php
./vendor/bin/phpunit tests/Feature/Cash/   # full suite
./vendor/bin/phpunit tests/Feature/Fiscal/ # no regression Z report
./vendor/bin/phpunit tests/Feature/Pos/    # no regression POS
./vendor/bin/phpunit tests/Feature/Pos/PosOrderCashSessionLinkTest.php
./vendor/bin/phpunit tests/Feature/KioskFrontendComprehensiveTest.php
npm run test
```

---

## 5. EDGE CASES

| Cas | Comportement |
|---|---|
| Caissier ouvre session, oublie fermer, autre prend service | Endpoint `force-close` admin-only ; status='force_closed' ; variance_reason obligatoire |
| Multi-cashier même branche | Plusieurs sessions OPEN OK (1 par cashier) ; cash_movement lié à session du caissier de l'order |
| Cashier change pendant order | `recordSale` cherche par cashier de l'order ; si pas trouvé, session_id=NULL |
| Z report close au milieu d'une session OPEN | session non-incluse dans aggregateVarianceForWindow (filter status=closed) ; le variance reste pour le Z suivant |
| Refund cash | recordRefund (negative amount) — décrémenter expected_cash |
| Session fermée puis re-ouvrir nouvelle | OK, sessions sont auto-incrément ID |
| Kiosk cash sans session | session_id=NULL ; n'impacte aucune session ; tracé pour audit comptable |

---

## 6. ROLLBACK PLAN

Migrations ont des `down()` réversibles :
```php
public function down(): void {
    Schema::dropIfExists('cash_movements');
    Schema::dropIfExists('cash_drawer_sessions');
    Schema::table('z_reports', function ($t) {
        $t->dropColumn(['cash_variance', 'cash_session_count']);
    });
}
```

**MAIS** : si des Z reports ont été clos avec cash_variance signée, retirer le champ casserait la vérification HMAC des Z futurs. → Revert code SANS dropper les colonnes. Migration de cleanup gated owner.

---

## 7. DEFINITION OF DONE

- [ ] 3 migrations + Models + Service + Endpoints + Hooks + Z enrichment
- [ ] Tous les tests verts (Unit + Feature + Regression)
- [ ] Suite Fiscal verte (pas de cassure HMAC)
- [ ] Suite POS verte (flow nominal intact)
- [ ] UI POS adaptée (séparé — frontend task post-backend)
- [ ] Documentation `docs/CASH_RECONCILIATION.md` créée
- [ ] 5 commits atomiques (1 par sub-task)
- [ ] PR ouvertes avec template
- [ ] Rapport `REPORT_F003_cash_reconciliation.md` produit
- [ ] Graphiti episode poussé

---

## 8. ACCEPTANCE CRITERIA

| # | Critère | Vérification |
|---|---|---|
| AC1 | Caissier ouvre session avec opening_float | Test `open creates session with opening float` |
| AC2 | Order POS cash crée cash_movement lié | Test `pos cash order links to open session` |
| AC3 | Caissier ferme avec variance ≤ seuil sans reason | Test passe |
| AC4 | Caissier ferme avec variance > seuil sans reason → 422 | Test rejette |
| AC5 | Z report inclut cash_variance | Test `z report aggregates cash variance` |
| AC6 | HMAC signature inclut cash_variance | Test signature stable |
| AC7 | Pas de régression POS sans session | Test `pos cash order without session creates dangling movement` |
| AC8 | Branch isolation respectée | Test `cashier branch A cannot see session branch B` |

---

## 9. ANTI-DRIFT CHECKLIST

- [ ] Pas de modification de `OrderStateMachine`
- [ ] Pas de modification de `FiscalSequenceService`
- [ ] Hooks recordSale appelés POST-save (pas dans la transaction principale, pour ne pas casser l'order si le movement échoue)
- [ ] Si recordSale échoue, log warning, ne pas rollback l'order (côté défensif)
- [ ] BranchScope appliqué sur les 2 nouveaux models
- [ ] Pas de touche aux frozen zones (POS wizard, kiosk wizard)

---

## 10. RISK REGISTER

| Risque | Probabilité | Impact | Mitigation |
|---|---|---|---|
| Hook recordSale échoue → order créé mais pas tracé | Low | Medium | Try/catch + log warning ; ne PAS rollback (l'order doit toujours être créé) |
| HMAC signature breaks sur les anciens Z (sans cash_variance) | Medium | High | Vérifier signature accept legacy payloads (champ manquant = NULL = pas dans signature) |
| UI POS pas prête → caissiers ne peuvent pas ouvrir session | Medium | High | Phase backend first ; UI ajoutée ensuite ; pendant ce temps, sessions auto-créées avec opening_float=0 |
| Variance threshold mal calibré (20€ trop strict ou trop lâche) | Medium | Low | Configurable via Settings::group('cash')->get('variance_alert_threshold', 20.00) |
| Concurrence : 2 hooks recordSale simultanés sur même session | Low | Low | Atomic INSERT, pas de read-modify-write |

---

## 11. UI POS — FOLLOW-UP TASK (hors scope F-003 backend)

Note pour l'orchestrateur : après merge des 5 commits backend, créer `PLAN_AUDIT_F003_UI_POS_2026-05-XX.md` séparé pour intégrer dans `PosComponent.vue` (NON frozen) :
- Bouton "Ouvrir caisse" → modal opening_float
- Bouton "Fermer caisse" → modal actual_cash + reason si variance > seuil
- Affichage session courante en header POS

---

## 12. REPORTING

Format §0.4 master plan, dans `reports/execution/audit_2026-05-07/REPORT_F003_cash_reconciliation.md`.

---

## 13. GRAPHITI REFLECTION

```json
{
  "name": "F-003 closed: cash reconciliation Option A",
  "group_id": "foodking",
  "source": "json",
  "episode_body": {
    "finding_id": "F-003",
    "severity": "P0",
    "status": "closed",
    "decision_taken": "Option A — cashier-supervised + reconciliation schema",
    "decided_by": "Claude orchestrator (delegated by owner)",
    "tests_added": 25,
    "files_added": [
      "database/migrations/2026_05_xx_create_cash_drawer_sessions.php",
      "database/migrations/2026_05_xx_create_cash_movements.php",
      "database/migrations/2026_05_xx_alter_z_reports_add_cash_variance.php",
      "app/Models/CashDrawerSession.php",
      "app/Models/CashMovement.php",
      "app/Services/Cash/CashDrawerSessionService.php",
      "app/Http/Controllers/Admin/CashDrawerSessionController.php",
      "app/Http/Requests/OpenCashSessionRequest.php",
      "app/Http/Requests/CloseCashSessionRequest.php",
      "tests/Feature/Cash/* (full suite)",
      "docs/CASH_RECONCILIATION.md"
    ],
    "files_modified": [
      "app/Services/OrderService.php (hook recordSale)",
      "app/Services/FrontendOrderService.php (hook recordSale)",
      "app/Services/Fiscal/ZReportService.php (cash_variance aggregation)",
      "routes/api.php (cash-drawer endpoints)"
    ],
    "invariant_enforced": "POS cash order → cash_movement persisted ; closed session.variance computed ; Z aggregates",
    "follow_up": "F-003 UI POS planning required",
    "audit_id": "ultra_review_2026-05-07"
  }
}
```

---

## 14. DISCOVERED

```
- [ ] À compléter par exécuteur
```
