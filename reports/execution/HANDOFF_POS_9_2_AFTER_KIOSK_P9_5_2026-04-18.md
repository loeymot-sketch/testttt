# HANDOFF — POS-9.2 (state machine + broadcast) après merge Kiosk P9.5 — 2026-04-18

## Contexte

Phase POS-9.4 livrée (branche `feat/pos-phase-9-4`, 12 commits atomiques, 43 tests Fiscal).
3 items de POS-9.4 ont été délibérément **BLOCKED** parce qu'ils nécessitent de toucher `app/Services/OrderService.php`, zone gelée réservée à Kiosk P9.5.

Ce handoff documente :
1. Comment dégeler ces 3 BLOCKERs **dès que Kiosk P9.5 est mergé sur `main`**.
2. Comment enchaîner ensuite POS-9.2 (state machine POS + broadcast `OrderStatusChanged`).

## Pré-requis avant de lancer

1. Kiosk P9.5 mergé sur `main` — vérifier `tasks/phase9-sync/CROSS_TRACK_STATUS.md` ligne "Track A / Kiosk P9.5".
2. Rebase `feat/pos-phase-9-4` sur `main` (ou merge `main` dedans, selon stratégie git de l'équipe).
3. Zone `app/Services/OrderService.php` : re-lire la nouvelle surface après Kiosk P9.5 — en particulier le contrat de la state-machine shared (source de vérité pour les transitions `placed → in_preparation → ready → served → paid`).
4. Relire `tasks/phase9-pos/POS_INVARIANTS_AND_GATES.md` §1.2 (invariants fiscaux) avant de wirer.

## Étape 1 — dégeler les 3 BLOCKERs POS-9.4 (vague "POS-9.4.bis")

### Ordre recommandé

| # | BLOCKER | Fichier | Effort | Test |
|---|---|---|---|---|
| 1 | 9.4.2b | `app/Services/OrderService.php::posOrderStore()` | **5 min** — 1 ligne | `FiscalSequenceWireInTest` |
| 2 | 9.4.5  | `OrderService::cancel/destroy/applyDiscount`, `PaymentService::refund/setPaymentStatus`, `DiscountCalculator` | **30-45 min** — 5 call-sites | `AuditLogIntegrationTest` (5 cas) |
| 3 | 9.4.10 | `OrderService::destroy()` | **10 min** — 1 guard | `DestroyAfterZClosedTest` |

### 9.4.2b — wire FiscalSequenceService::next()

Dans `posOrderStore()` (ou le contrat équivalent après Kiosk P9.5), **avant `$order->save()`** :

```php
if (is_null($order->fiscal_sequence_no)) {
    $order->fiscal_sequence_no = app(FiscalSequenceService::class)->next((int) $order->branch_id);
}
```

**Test à créer** : `tests/Feature/Fiscal/FiscalSequenceWireInTest.php` :
- `posOrderStore` crée un order → `fiscal_sequence_no === 1` pour le 1er, `=== 2` pour le suivant (même branch).
- Deux branches en parallèle → séquences indépendantes.
- Idempotence : un second save ne ré-alloue pas.

### 9.4.5 — brancher AuditLogService sur les call-sites sensibles

Pour chaque call-site, après la mutation réussie, appeler :

```php
app(AuditLogService::class)->write([
    'branch_id'   => $order->branch_id,
    'user_id'     => Auth::id(),
    'action'      => 'pos.order.cancel', // ou .destroy / .discount.apply / .refund / .payment_status
    'resource'    => 'Order',
    'resource_id' => (string) $order->id,
    'payload'     => [ /* minimal context — amounts, reason, delta — pas de PII */ ],
]);
```

**Call-sites** :
1. `OrderService::cancel()` → `action: 'pos.order.cancel'`
2. `OrderService::destroy()` → `action: 'pos.order.destroy'` (dans le chemin autorisé, après le guard 9.4.10)
3. `DiscountCalculator::apply()` (ou OrderService::applyDiscount) → `action: 'pos.order.discount.apply'`
4. `PaymentService::refund()` → `action: 'pos.payment.refund'`
5. `PaymentService::setPaymentStatus()` → `action: 'pos.payment.status.change'`

**Test à créer** : `tests/Feature/Fiscal/AuditLogIntegrationTest.php` :
- Chaque mutation produit EXACTEMENT 1 ligne `audit_logs` (même branch, bon user, bon action, bonne resource_id).
- `current_hash` = `HMAC(secret, prev_hash | canonical(payload))` (via `AuditLogService::verifyChain(branch_id)`).
- Un rollback DB (transaction exception) → aucun audit_log inséré.

### 9.4.10 — destroy guard "409 si Z clos"

Dans `OrderService::destroy($order)`, **avant tout** :

```php
if ($order->fiscal_sequence_no !== null) {
    $sealingZ = ZReport::query()
        ->where('branch_id', $order->branch_id)
        ->where('status', ZReport::STATUS_CLOSED)
        ->where('closed_at', '>=', $order->created_at)
        ->exists();

    if ($sealingZ) {
        abort(409, 'Order is sealed by a closed Z report; destructive deletion is forbidden (NF525).');
    }
}
```

**Test à créer** : `tests/Feature/Fiscal/DestroyAfterZClosedTest.php` :
- Order créée → Z ouvert → Z fermé → `destroy()` → **409**.
- Order créée → **pas** de Z fermé → `destroy()` → **204** (comportement inchangé).
- Order d'une autre branche scellée par le Z → n'affecte pas la branche cible.

### Gate de la mini-vague "POS-9.4.bis"

- 3 commits atomiques (`feat(pos/phase-9.4.2b): …`, `feat(pos/phase-9.4.5): …`, `feat(pos/phase-9.4.10): …`).
- 3 BLOCKERs retirés de `tasks/phase9-sync/` (ou déplacés vers `tasks/phase9-sync/.resolved/`).
- `CROSS_TRACK_STATUS.md` : 3 lignes passent de `BLOCKED` à `resolved (commit)`.
- Full PHPUnit Fiscal + régression POS-9.1 : vert.
- `RUN_POS_9_4_BIS_<DATE>.md` + `VERIFY_POS_9_4_BIS_<DATE>.md`.

## Étape 2 — lancer POS-9.2 (state machine POS + broadcast)

### Pré-conditions

- POS-9.4.bis mergée → audit_logs branchés, FiscalSequence wiré, destroy guarded.
- Kiosk P9.5 mergée → state-machine shared disponible en tant que contrat unique des transitions.

### Scope POS-9.2 (extrait plan)

D'après `reports/execution/PLAN_PHASE_POS_9_2026-04-18.md` §POS-9.2 :

- POS-9.2.1 — POS consomme la state-machine shared (rejette transitions invalides via `StateTransitionGuard`).
- POS-9.2.2 — `afterCommit` sur mutations fiscales (garantit que `audit_logs` + `fiscal_sequence_no` se persistent dans la même transaction que l'order).
- POS-9.2.3 — broadcast `OrderStatusChanged` (channel `branch.{branch_id}`, anonymisé).
- POS-9.2.4 — POS UI : suppression des transitions interdites côté Vue.
- POS-9.2.5 — tests state-machine exhaustifs (matrice complète des transitions).

### Gates spécifiques POS-9.2

1. Transition illégale → 422 avec payload `{allowed: […]}` explicite.
2. Broadcast `OrderStatusChanged` émis exactement 1× par transition valide.
3. `afterCommit` : exception applicative → rollback complet (audit + fiscal_sequence + order).
4. POS-9.1 régression : 100 % vert.
5. Kiosk régression : 100 % vert (coordonner avec Track A).

### Branche recommandée

`feat/pos-phase-9-2` (fork `main` juste après merge Kiosk P9.5 + POS-9.4 + POS-9.4.bis).

## Alternative si Kiosk P9.5 n'est pas prêt

Si, au moment de reprendre, Kiosk P9.5 n'est **toujours pas mergé**, basculer sur une vague exclusive (0 collision OrderService) :

- **POS-9.8 (UX)** — amélioration ergonomie POS (keyboard shortcuts, focus order, quick actions, accessibility P1). Zone front-only (`resources/js/pages/pos/*`).
- **POS-9.9 (data)** — rapports tabulaires, exports CSV, agrégats analytiques. Zone `app/Services/Reports/*` + `routes/api.php`.

Les deux évitent `OrderService.php` et n'ont aucune dépendance Kiosk P9.5.

## Livrables attendus sur le handoff suivant

À la fin de POS-9.2 (ou POS-9.8/9.9) :

- `RUN_POS_9_2_<DATE>.md` (ou `RUN_POS_9_8_<DATE>.md` / `RUN_POS_9_9_<DATE>.md`).
- `VERIFY_POS_9_2_<DATE>.md` avec verdict 100 % RESOLVED.
- `HANDOFF_POS_9_3_<DATE>.md` (vague suivante selon orchestrateur).

## Artefacts de référence

- Plan master : `reports/execution/PLAN_PHASE_POS_9_2026-04-18.md`.
- Invariants : `tasks/phase9-pos/POS_INVARIANTS_AND_GATES.md`.
- Sync : `tasks/phase9-pos/SYNC_PROTOCOL_KIOSK_POS.md`.
- Token SOP : `tasks/phase9/TOKEN_ECONOMY_SOP.md`.
- Findings : `tasks/phase9-pos/FINDINGS_POS_TRACKER.md`.
- Cross-track : `tasks/phase9-sync/CROSS_TRACK_STATUS.md`.
- BLOCKERs : `tasks/phase9-sync/BLOCKER_POS_9_4_{2b,5,10}_*.md`.

---

*Author : assistant session (exec Track B / Phase POS-9.4).*
*Date : 2026-04-18.*
*Handoff destiné à la session qui reprendra Track B après merge Kiosk P9.5.*
