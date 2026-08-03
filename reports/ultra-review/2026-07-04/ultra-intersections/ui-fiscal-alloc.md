# Ultra-intersection audit — Allocation fiscale (FiscalSequenceService::next)

HEAD 48050af80 · DB foodking_e2e · CACHE_DRIVER=redis (LockProvider réel) · 2026-07-04

## Fonction partagée
`App\Services\Fiscal\FiscalSequenceService::next(int $branchId): int`
Réserve `MAX(fiscal_sequence_no)+1` par branche (start 1), monotone, gap-free.
Défense triple : `Cache::lock('fiscal_seq_b{branch}', 5s)` (redis, atomique) + `->lockForUpdate()` (InnoDB) + index UNIQUE `orders_branch_fiscal_seq_unique(branch_id, fiscal_sequence_no)`.

## Les N consommateurs (4 chemins d'allocation — TOUS énumérés)
1. **Caisse inline** — `OrderService.php:1116-1119`. Alloue à la création SI `!deferToCounter` (walkin_route_to_counter=false). Échec → throw → rollback.
2. **Borne / encaissement comptoir** — `PaymentService::confirmCounterPayment` `:335-337`. Alloue à l'encaissement SI `fiscal_sequence_no === null`. Échec → throw → rollback.
3. **Kiosk direct TPE** — `FrontendOrderService::finalizePaidKioskOrder` `:1232-1238`. Alloue à la finalisation SI null + flag. Échec → SWALLOW (set `fiscal_alloc_error_at` hors-tx) + retry cron `foodking:fiscal:retry-alloc` (RetryFiscalAllocCommand → re-appelle finalizePaidKioskOrder). Ordre reste PENDING (invisible KDS).
4. **Refund contre-écriture** — `RefundWithCounterEntryService::execute` `:103`. Alloue un seq FRAIS pour l'ordre miroir (RETURN_OF). Échec → throw → rollback.

Aucun autre appelant `->next(` en prod (grep exhaustif ; BypassAuditLogger = commentaire seulement).

## Preuves LIVE de cohérence
- **Réservation-only prouvée** (tinker) : `next(1)` deux fois sans persister → `2624` / `2624`. La sérialisation ne vient PAS de `next()` seul mais du persist-sous-lock. Les 4 chemins persistent DANS la même `DB::transaction` qui appelle `next()` → le `lockForUpdate` (savepoint imbriqué) tient les verrous lignes jusqu'au commit externe. Cohérent sur les 4.
- **Rollback safety** (tinker) : `next()` dans une tx rollback → `2624`, puis `next()` → `2624`. Une réservation annulée n'est PAS consommée → PAS de trou. Confirme la sémantique SAVEPOINT documentée.
- **Guards** : `next(0)` et `next(-5)` → `InvalidArgumentException`. OK.
- **Infra réelle** : index unique présent (non_unique=0, cols branch_id+fiscal_sequence_no) ; `Cache::store()->getStore()` = `RedisStore instanceof LockProvider = YES` (lock atomique réel, pas le cas dégradé file/database d'UNI-03).
- **`withTrashed()` dans next()** (`:97-101`) : le MAX inclut les soft-deleted. Chemin destroy prod (`OrderService:2709`) = SOFT delete + `Order::restoring` throw (one-way) → aucun trou. Aucun hard-delete d'ordre fiscalisé en code (CleanupStalePendingKioskOrders touche `whereNull(fiscal_sequence_no)` uniquement).
- **Chaîne HMAC** : `fiscal:verify-chain --all` → CHAIN OK sur 4 branches (1,7,8,9), avant analyse et invariant préservé (aucune écriture).

## Cohérence exactly-once inter-chemins (raisonnement adversaire)
- **Double-alloc via 2 chemins ?** NON. Chemins 2/3/4 gardent `fiscal_sequence_no === null` (miroir = ordre neuf). Un ordre kiosk collecté (chemin 2) passe PAID+ACCEPT → chemin 3 return-early (`payment_status!=PAID` / `status!=PENDING`). Un ordre kiosk payé en ligne (chemin 3) n'est pas COUNTER_DEFERRED → chemin 2 `assertCounterDeferredOrder` rejette. Double garde.
- **Refund** : miroir reçoit un seq DISTINCT du parent (2 documents fiscaux = 2 numéros = conforme NF525). `UNIQUE(parent_order_id)` + SealedOrderGuard → 2 refunds concurrents = le 2e échoue → sa réservation seq non-consommée (savepoint) → ni double-miroir ni trou.
- **Concurrence** : branche non-vide → `SELECT MAX ... FOR UPDATE` verrouille la ligne max ; l'allocateur concurrent bloque jusqu'au commit → N+1 correct. Redis lock réduit la contention. Index unique = garde ultime.

## Seams vérifiés mais NON-nouveaux (déjà DÉFÉRÉS / test-noise)
- **Trou 2506-2508 (branche 1)** = 19 ids hard-deletés dans la plage 4974-5019 sur la DB e2e (teardown de test / manip manuelle). PAS un chemin de code prod (destroy=soft). Bruit de données e2e, pas un défaut.
- **Orphelins cross-Z-window** : `fiscal:verify-z-membership` liste des ventes numérotées dans AUCUN Z signé (seq alloué à l'encaissement/finalisation, Z agrège par `created_at`). = P0 #1 detect-only déjà tracké (ESCALATION_NO_GO.md) + DÉFÉRÉ « clôture périodique fiscale ». Non ré-signalé.

## Verdict
**COHERENT.** L'allocation fiscale est cohérente et exactly-once sur les 4 chemins (caisse inline / borne-encaissement / kiosk-TPE / refund-miroir). Monotone + gap-free garantis par savepoint (rollback-safe) + withTrashed (soft-delete-safe) + triple-défense concurrence réelle (redis lock + FOR UPDATE + unique index), prouvés LIVE. Aucun P0/P1/P2 nouveau confirmé.
