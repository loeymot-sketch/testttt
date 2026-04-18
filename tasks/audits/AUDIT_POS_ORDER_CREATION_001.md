# AUDIT_POS_ORDER_CREATION_001 — Cycle de création commande POS

## Meta
- **Priority** : P0
- **PRIMARY_MODEL** : Claude (audit seul, aucune modification code)
- **TEST_STRATEGY** : `static-inspection`
- **DEPENDS_ON** : —
- **Estimation** : 0.5 j-h
- **Vague** : A1

## Contexte

La création de commande au POS (`OrderService::posOrderStore`, ~ligne 546 de `OrderService.php`, fichier de 1693 lignes) est le cœur métier le plus chaud du backend. Elle orchestre :
1. Validation de la requête POS (items, branch, customer, paiement choisi).
2. Calcul de prix (doit passer par PricingService si V1 appliquée, sinon calcul local suspect).
3. Persistance de l'`Order` + items + relations.
4. Émission d'events → outbox `DomainEvent` → broadcast Pusher.
5. Impression éventuelle (ticket, drawer).

Risque business : toute faille silencieuse à ce niveau corrompt la vérité du chiffre d'affaires, casse la symétrie POS/Kiosk, ou émet des events invalides (EventContract V1).

## Questions d'audit

1. `posOrderStore` est-il encapsulé dans une transaction DB unique ? Le rollback couvre-t-il bien la persistance de l'Order ET des items ?
2. Les events (`OrderCreated`, `OrderStatusChanged`) sont-ils émis **après commit** (DB::afterCommit) ? Le listener `PersistOrderCreatedToOutbox` utilise-t-il correctement `DB::afterCommit(fn() => DispatchDomainEventsJob::dispatch(...))` ?
3. Le calcul de prix passe-t-il par un `PricingService` centralisé, ou subsiste-t-il du calcul inline (subtotal, tax, total) dans le contrôleur ou dans `OrderService::posOrderStore` ?
4. `branch_id` est-il alimenté uniquement depuis l'utilisateur/session authentifié côté serveur, **jamais** depuis le payload client ?
5. Le statut initial est-il toujours cohérent (PENDING=1 ou ACCEPT=4 si auto-accept POS) ? Le premier `OrderStatusHistory` est-il créé ?
6. Le `correlation_id` (UUID) est-il généré et propagé au `DomainEvent` pour traçabilité ?
7. Quelle est la gestion d'erreur (validation, FK, concurrence) ? Retourne-t-on un JSON cohérent avec code HTTP approprié ?
8. Les notes / instructions / commentaires cuisine sont-ils correctement persistés et nettoyés (XSS) ?
9. Le `queue_number` est-il attribué de manière thread-safe (unique par branche et jour) ?
10. L'email/SMS éventuel est-il émis en `afterCommit` et **non bloquant** ?

## Scope

### SUBSYSTEMS_TOUCHED (lecture seule)
- `app/Services/OrderService.php` — méthode `posOrderStore` + dépendances directes
- `app/Http/Controllers/Admin/Order/OrderController.php` — point d'entrée POS
- `app/Events/OrderCreated.php`, `app/Events/OrderStatusChanged.php`
- `app/Listeners/PersistOrderCreatedToOutbox.php`, `app/Listeners/PersistOrderStatusChangedToOutbox.php`
- `app/Jobs/DispatchDomainEventsJob.php`
- `app/Domain/Events/EventContract.php`
- `app/Models/Order.php`, `app/Models/OrderItem.php`, `app/Models/OrderStatusHistory.php`, `app/Models/DomainEvent.php`
- `routes/api.php` — sections POS (~620s)
- `app/Http/Requests/*Order*` — formRequests

### SUBSYSTEMS_OFF_LIMITS
- Stripe/Paypal, PushNotificationService, DashboardController, Delivery Boy (zones gelées — cf `docs/ARCHITECTURE.md`)
- FrontendOrderService (objet d'audit dédié C1)

## Invariants at Risk
- [x] Backend pricing SSOT
- [x] OrderStatus enum
- [x] branch_id data isolation
- [x] Dispatch after DB commit
- [x] OrderService / FrontendOrderService symmetry
- [x] Frozen zone (OrderService est frozen)

## Fichiers à lire (ordre)
1. `app/Services/OrderService.php` (section `posOrderStore` : ~L500-L700)
2. `app/Http/Controllers/Admin/Order/OrderController.php` (entrypoint)
3. `app/Events/OrderCreated.php`
4. `app/Listeners/PersistOrderCreatedToOutbox.php`
5. `app/Jobs/DispatchDomainEventsJob.php`
6. `app/Domain/Events/EventContract.php`
7. `app/Models/Order.php`, `app/Models/DomainEvent.php`
8. `routes/api.php` (~lignes 620-780, bloc POS)

## Grep patterns

```
grep -n "posOrderStore" app/Services/OrderService.php
grep -rn "DB::transaction\|DB::beginTransaction" app/Services/OrderService.php
grep -rn "DB::afterCommit" app/Listeners/ app/Services/
grep -rn "OrderCreated::dispatch\|OrderStatusChanged::dispatch" app/
grep -rn "branch_id" app/Http/Controllers/Admin/Order/
grep -n "subtotal\|total\s*=" app/Services/OrderService.php
grep -rn "correlation_id" app/
grep -n "queue_number" app/Services/OrderService.php app/Models/Order.php
```

## Evidence required
- Extrait commenté du code de `posOrderStore` avec annotations (ligne par ligne pour les points critiques : transaction, event, pricing).
- Diagramme texte de la séquence : HTTP → Controller → Service → Event → Listener → Outbox → Job → Pusher.
- Liste exhaustive des calculs de prix trouvés (avec ligne) hors `PricingService`.
- Liste des endroits où `branch_id` est lu depuis le payload client (doit être vide).
- Vérification que chaque chemin de retour (succès / erreur) a son `OrderStatusHistory` et `DomainEvent` cohérent.

## Grille de verdict

- **PASS** : transaction unique, DB::afterCommit utilisé, pricing via service central, branch_id server-side, events conformes V1 envelope.
- **WARN** : ≤ 2 points mineurs (ex : pricing inline résiduel dans un chemin secondaire) → créer task correctrice non bloquante.
- **BLOCKED** : absence de transaction OU branch_id depuis payload OU events avant commit OU EventContract violé → task P0 immédiate + gate architecture-drift.

## Livrable attendu
`reports/review/AUDIT_POS_ORDER_CREATION_001_<DATE>.md` avec :
- Synthèse verdict (PASS/WARN/BLOCKED)
- Réponses numérotées aux 10 questions
- Evidence (extraits + greps)
- Liste actions correctrices proposées (avec IDs de task suggérés)

## Status
- [x] Brief rédigé
- [ ] Plan Cursor approuvé
- [ ] Audit exécuté
- [ ] Rapport déposé
- [ ] Tasks correctrices créées
- [ ] Closed
