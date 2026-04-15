# TASK_V1_STATUS_MACHINE_001 — OrderStatus finite state machine

## Meta
- **Priority** : P0 (critique business)
- **Vague** : 2 — Domaine SSOT
- **PRIMARY_MODEL** : GPT-5.4 (state machine + transactional guard)
- **TEST_STRATEGY** : `local-validation`
- **DEPENDS_ON** : (indépendant)
- **BLOCKS** : TASK_V1_TEST_PRICING_STATE_001
- **Estimation** : 3 j-h

## Contexte

Les transitions d'état `OrderStatus` sont aujourd'hui dispersées dans plusieurs contrôleurs et services. Exemples :
- `$order->status = OrderStatus::READY;` direct dans un controller.
- Pas de garde : rien n'empêche `pending → served` (court-circuit cuisine).
- Aucun log structuré de qui a changé le statut, quand, pourquoi.

Risque : un bug dans un contrôleur peut créer un état incohérent (ex. commande `served` jamais `preparing`), et le KDS montre des données incohérentes.

V1 impose une **finite state machine** explicite, avec guard dans une classe dédiée. Toute transition passe par là, point.

## Acceptance Criteria
- [ ] `App\Domain\Order\OrderStateMachine` avec table de transitions légales explicite.
- [ ] `OrderStateMachine::transition(Order $order, OrderStatus $next, ?string $reason, ?User $actor): void` — throw `IllegalTransitionException` si illégal.
- [ ] Table `order_status_transitions` (id, order_id, from, to, actor_id, actor_type, reason, occurred_at, correlation_id).
- [ ] **Zéro** assignation directe `$order->status = ...` hors du StateMachine (grep CI assertion).
- [ ] Transitions V1 légales documentées (voir E1).
- [ ] Event `order.status_changed` émis **via outbox** à chaque transition réussie.
- [ ] Tests PHPUnit exhaustifs : toutes transitions légales + illégales (au moins 2×N cas).
- [ ] `docs/ORDER_FLOW.md` mis à jour avec diagramme d'états.

## Scope

### SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| `app/Domain/Order/OrderStateMachine.php` | nouveau | Write | No | No |
| `app/Domain/Order/IllegalTransitionException.php` | nouveau | Write | No | No |
| `app/Domain/Order/OrderStatus.php` | enum existant (vérifier) | Read | No | No |
| `database/migrations/*_create_order_status_transitions_table.php` | nouveau | Write | Yes (indexé par order → branch) | No |
| `app/Models/OrderStatusTransition.php` | nouveau | Write | No | No |
| `app/Services/OrderService.php` | **frozen — bascule vers StateMachine** | Write | Yes | Yes |
| `app/Services/FrontendOrderService.php` | **frozen — bascule** | Write | Yes | Yes |
| Tous les controllers qui font `$order->status = ...` | refactor vers StateMachine | Write | No | Yes |
| `tests/Unit/Domain/Order/OrderStateMachineTest.php` | tests | Write | No | No |
| `docs/ORDER_FLOW.md` | doc + diagramme | Write | No | No |

### SUBSYSTEMS_OFF_LIMITS
- Pricing — V1_PRICING_SSOT_001.
- Menu availability — V1_MENU_86_001.
- Infra event (outbox) — V1_OUTBOX_001 (dépendance douce, mais pas bloquante : la transition émet l'event, le mécanisme dispatch est géré par OUTBOX_001).

## Invariants at Risk
- [ ] None
- [ ] Backend pricing SSOT
- [x] **OrderStatus enum** — c'est l'invariant directement renforcé.
- [ ] branch_id data isolation
- [x] Dispatch after DB commit — le `order.status_changed` event doit être émis **après** la transition committée.
- [x] OrderService / FrontendOrderService symmetry — les deux doivent utiliser le StateMachine.
- [x] Frozen zone.

## Execution Steps

### E1 — Définir transitions légales V1
Documenter dans `app/Domain/Order/OrderStateMachine.php` (constante statique) :

| From → To | Légal | Commentaire |
|---|---|---|
| `draft → pending` | oui | validation panier → commande envoyée |
| `pending → preparing` | oui | KDS acknowledge |
| `preparing → ready` | oui | KDS signale prêt |
| `ready → served` | oui | POS/borne marque servi |
| `served → completed` | oui | clôture |
| `pending → cancelled` | oui | annulation avant prépa (aucune garde) |
| `preparing → cancelled` | oui (avec `reason` obligatoire) | annulation après début prépa — gate logique interne |
| `ready → cancelled` | oui (avec `reason`) | annulation après prépa — gate |
| `* → completed` (hors `served`) | NON | interdit |
| `cancelled → *` | NON | terminal |
| `completed → *` | NON | terminal |

Toute transition non listée = illégale.

### E2 — StateMachine implementation
```php
final class OrderStateMachine {
    private const LEGAL = [
        'draft' => ['pending'],
        'pending' => ['preparing', 'cancelled'],
        'preparing' => ['ready', 'cancelled'],
        'ready' => ['served', 'cancelled'],
        'served' => ['completed'],
        // cancelled + completed = terminal
    ];

    public function transition(Order $order, OrderStatus $next, ?string $reason = null, ?User $actor = null): void {
        $from = $order->status;
        if (!in_array($next->value, self::LEGAL[$from->value] ?? [], true)) {
            throw new IllegalTransitionException($from, $next);
        }
        DB::transaction(function() use ($order, $from, $next, $reason, $actor) {
            $order->status = $next;
            $order->save();
            OrderStatusTransition::create([
                'order_id' => $order->id,
                'from' => $from->value,
                'to' => $next->value,
                'actor_id' => $actor?->id,
                'actor_type' => $actor ? get_class($actor) : null,
                'reason' => $reason,
                'correlation_id' => request()->header('X-Correlation-ID'),
                'occurred_at' => now(),
            ]);
            // Event outbox — recordé via trait HasDomainEvents sur Order
            $order->recordEvent(new OrderStatusChanged($order, $from, $next));
        });
    }
}
```

### E3 — Migration
`order_status_transitions` avec colonnes ci-dessus + index (order_id, occurred_at).

### E4 — Refactor frozen zones
1. OrderService : remplacer tous `$order->status = ...` par `$this->stateMachine->transition(...)`.
2. FrontendOrderService : idem.
3. Controllers qui modifiaient directement : idem.

### E5 — Guard CI
```
grep -rn "->status[[:space:]]*=[[:space:]]*" app/ --include="*.php" \
    --exclude-dir=Domain/Order \
    | grep -v "OrderStateMachine" \
    && exit 1
```

### E6 — Tests
1. `OrderStateMachineTest` : pour chaque paire (from, to) du tableau LEGAL, test vert. Pour chaque paire absente, test rouge attendu (IllegalTransitionException).
2. Test intégration : transition légale → ligne dans `order_status_transitions` + event enregistré dans `domain_events`.
3. Test annulation post-préparation : `reason` requis → throw si manquant.

### E7 — Documentation
`docs/ORDER_FLOW.md` : ajouter diagramme Mermaid.
```
stateDiagram-v2
  [*] --> draft
  draft --> pending
  pending --> preparing
  pending --> cancelled
  preparing --> ready
  preparing --> cancelled
  ready --> served
  ready --> cancelled
  served --> completed
  cancelled --> [*]
  completed --> [*]
```

## SYMMETRY_NOTE
Obligation : OrderService et FrontendOrderService invoquent **tous deux** `OrderStateMachine::transition(...)`. Aucun contournement. La symétrie est garantie par construction.

## GATE_CONDITIONS
- **Gate requise** : NON (pas de modification de l'enum lui-même, juste formalisation des transitions).
- Stop-gate si : proposition d'ajouter un état hors de la liste V1 (ex: `refunded`, `disputed`) — refuser, V2.

## Status
- [ ] Pending plan
- [ ] Plan approved
- [ ] In execution
- [ ] Validation
- [ ] Audit
- [ ] Gate open
- [ ] Closed
