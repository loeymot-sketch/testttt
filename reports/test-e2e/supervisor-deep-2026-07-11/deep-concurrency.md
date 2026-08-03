# Deep concurrency / idempotency audit — FoodKing V1 LOCAL

- Date: 2026-07-11
- Auditeur: adversaire CONCURRENCE/IDEMPOTENCE (read-only + tests + curl/tinker read-only)
- HEAD: `3628c1ccc40a554d3d2737f83ebca465878b5785` (branch `pos/category-first-caisse-2026-06-23`)
- PHP: 8.2.30 · Backend live `:8766` (APP_ENV=local, **DB=mysql, CACHE=redis, QUEUE=redis**, IDEMPOTENCY_MIDDLEWARE_ENABLED=true)
- Méthode: aucune mutation destructive. Tests PHPUnit (sqlite:memory) + forensic **lecture seule sur la base LIVE MySQL** (preuve réelle sous charge accumulée ~2 mois) + probe Redis `Cache::lock` réel.

## Verdict global: invariants TIENNENT. 1 nit LOW (docblock/branche morte), 1 observation NF525 hors-scope-concurrence (gap = hard-delete manuel, pas une course).

---

## 1. Fiscal sequence gap-free sous contention réelle — TIENT

### Preuve tests (sqlite:memory)
- `Tests\Feature\Fiscal\FiscalSequenceTest` — 5/5 PASS (first=1, atomic per branch no gaps, independent per branch, rejette branch<=0, continues from existing max)
- `Tests\Feature\Fiscal\AuditLogConcurrencyTest` — 4/4 PASS (`unique chain index rejects fork`, `cache lock serialises writers`, retry après collision forcée → chaîne cohérente)
- `Tests\Feature\QueueNumberConcurrencyTest` — 6/6 PASS (DB rejette duplicate queue même branch+date ; pos+kiosk partagent 1 séquence gapless sur 50 créations)

> Limite honnête: en test, `CACHE_DRIVER=array` (lock in-process) + `DB=sqlite:memory` (FOR UPDATE = no-op). Le vrai test 50-workers `Tests\Feature\ProdLike\ProdLikeConcurrencyTest` est **SKIPPED** (exige `DB=mysql` + `CACHE=redis`) et il fait `migrate:fresh` → **non exécuté** (destructeur sur la base live). La preuve réelle vient donc du forensic live ci-dessous.

### Preuve réelle — DB LIVE MySQL (lecture seule)
Contrainte ultime présente et appliquée (SHOW INDEX FROM orders):
- `orders_branch_fiscal_seq_unique (branch_id, fiscal_sequence_no)` ✅
- `orders_branch_business_date_queue_unique (branch_id, business_date, queue_number)` ✅
- `orders_branch_user_idempotency_unique (branch_id, user_id, idempotency_key)` ✅
- `orders_parent_order_id_unique (parent_order_id)` ✅ (refund one-counter-entry)

Forensic séquence fiscale sur les données réelles accumulées (table `orders`, partagée POS+kiosk — `FrontendOrder::getTable()=orders`):

| branch | count | min | max | span | duplicates | gaps |
|--------|-------|-----|-----|------|-----------|------|
| 1 | 2640 | 1 | 2643 | 2643 | **0** | 3 (2506,2507,2508) |
| 7 | 1 | 1 | 1 | 1 | 0 | 0 |
| 8 | 1 | 1 | 1 | 1 | 0 | 0 |
| 9 | 2 | 101 | 102 | 2 | 0 | 0 |

**2640 numéros fiscaux réels, ZÉRO doublon, strictement monotone.** C'est la preuve terrain que `Cache::lock(redis) + lockForUpdate(InnoDB) + UNIQUE` n'a jamais produit de collision sous la charge réelle de ~2 mois de sessions.

Le probe Redis confirme le primitive de sérialisation réel:
`Cache::lock($k,5)->get()=1 ; 2e acquéreur pendant détention → LockTimeoutException (BLOQUÉ, exclusion mutuelle OK) ; après release → réacquis OK.`

## 2. Observation NF525 (hors scope concurrence): gap 2506-2508 = hard-delete manuel, PAS une course

Cause caractérisée (lecture seule):
- `orders` a `deleted_at` (SoftDeletes). **338 lignes soft-deleted, TOUTES avec fiscal_sequence_no=NULL** → le chemin soft-delete ne numérote jamais (correct).
- Les seq 2506-2508 sont **absentes physiquement** (ni actives ni soft-deleted) → lignes **hard-deleted**. Voisins: seq 2505=id4974 (2026-06-19 00:31), seq 2509=id5019 (2026-06-20 01:13). Bloc supprimé entre le 19 et 20 juin.
- Code applicatif: **aucun `forceDelete` sur les orders**. Order utilise `SoftDeletes` + garde `static::restoring` (une-passe). Les seuls `$order->delete()` (OrderService:2887, CleanupWebTestOrdersCommand:79) sont des **soft-deletes**.
- Conclusion: gap issu d'un **purge SQL manuel/raw** de commandes de test (cohérent avec les purges de test documentées dans la mémoire projet). Non reproductible par le code, non lié à une course. **Sévérité: LOW** (box LOCAL dev, données de test). En prod: le code ne peut pas produire ce gap (soft-delete one-way), donc pas de régression de conformité côté applicatif.

## 3. Idempotency (rejeu même clé / clé+payload différent) — TIENT

Tests (tous PASS):
- `Idempotency\IdempotencyMiddlewareTest` 8/8 — **`two identical posts create only once and replay second`**, **`same key different payload returns 409`**, `post without header on required route returns 422`, `redis unavailable fail closed returns 503`, `... fail open passes through`, `replay after ttl expired executes anew`, `cross branch same key distinct executions`.
- `Orders\IdempotencyBranchScopedTest` 2/2, `Security\IdempotencyCrossUserLeakSentinelTest` 5/5, `Sentinels\IdempotencyRecoveryBranchScopedTest` 4/4, `Idempotency\CounterCollectAndPrintIdempotencyTest` 5/5 (**`print receipt is idempotent on replay no double count`**), `Idempotency\ChangeStatusIdempotencyTest`, `ConcurrentOrderTest::idempotency prevents duplicate order`.
- Webhooks: `StripeWebhookIdempotencyTest` 6/6, `SenangpayWebhookIdempotencyTest` 6/6, `WebhookEventIdempotencyTest` 7/7 (`unique constraint prevents concurrent duplicate insert`).

Preuve réelle DB LIVE: **0 groupe (branch_id,user_id,idempotency_key) avec count>1** sur toutes les commandes réelles → la contrainte `orders_branch_user_idempotency_unique` a tenu en défense-en-profondeur.

Runtime live confirmé: `config('idempotency.enabled')=true`, `cache.default=redis`, `redis PING=PONG`. Middleware réellement armé (scope (branch,user,hash(key)) ; replay 2xx via `Idempotency-Replayed`; payload≠ → 409 `IDEMPOTENCY_KEY_CONFLICT` ; storage down → 503 fail-closed par défaut).

Note queue: 1 seul groupe queue "dupliqué" = branch1 / **business_date NULL** / `A001` ×3 (lignes legacy 2026-05-29, surface vide, seq NULL). MySQL UNIQUE traite les NULL comme distincts — **autorisé by-design** (cf. `QueueNumberConcurrencyTest::null queue numbers remain allowed for legacy rows`). Toutes les commandes à business_date réel: **0 doublon queue**.

## 4. Quote seal race — TIENT

- `OrderQuoteService::sealForCommit` re-price backend (SSOT) dans une `DB::transaction`, `resolveReplay`/`findOpenQuote` en `lockForUpdate`, compare `total_ttc` vs `expectedTotal` → **HttpException(409)** si divergence ; token+signature exigés ensemble (401) ; HMAC `hash_equals` intent+signature ; expiration → 410.
- Tests PASS: `QuoteReplayIdempotencyTest` 3/3 (`quote consume replay is idempotent`, pos/kiosk commit consomme le quote avec order id), `Order\PriceChangeSnapshotTest` 3/3 (**`concurrent price change during order creation does not corrupt existing`**), `Pos\PosFreeDeliveryQuoteSealTest` 1/1.

## 5. Outbox concurrent worker dedupe — TIENT

- `Outbox\OutboxConcurrentWorkerDedupeTest` 9/9 — `two sequential handle calls only broadcast once`, `claim is committed before broadcast`, `broadcast failure releases claim for retry`, `event with null channel marks dispatched without broadcast`.
- `Outbox\OutboxConcurrentRetryLockTest` 7/7 (lock TTL 300s, skip si lock déjà tenu, cap 500 rows), `OutboxRescueStaleClaimedRowsTest` 5/5, `ListenerReplayDedupeTest`, `OutboxDeliveryTest` 7/7 (ready→503 si stale/queue sync/broadcast off en prod).

## 6. Cache::lock timeout — degraded path = flag + retry (contrat §8), PAS de gap silencieux — TIENT

Chemins d'allocation `FiscalSequenceService::next()`:
- **Kiosk-payé (FrontendOrderService:1236-1404)**: `next()` dans la tx ; sur échec → `catch (\Throwable $e)` → `promoted=false`, commande reste PENDING/seq=NULL, puis **`fiscal_alloc_error_at=now()` écrit via `DB::table()->update()` HORS transaction** (ne peut pas être rollback) → `RetryFiscalAllocCommand` (cron) rattrape. Tests: `FiscalAllocOrphanRetryTest` 4/4, `FiscalAllocErrorFlagOutsideTxSentinelTest` 3/3, `KioskRetryFiscalDatedAtTest` 2/2.
- **POS / deferred (OrderService:1997/2603, PaymentService:338)**: `next()` dans `DB::transaction`+`lockForUpdate` ; sur throw → **rollback propre** (numéro jamais committé → `MAX+1` identique au retry, unique = garde ultime) → échec synchrone, le caissier réencaisse. Pas de gap.

### NIT (LOW) — branche morte + docblock trompeur dans FiscalSequenceService
`FiscalSequenceService.php:69` `if (!$lock->block(3)) { throw new RuntimeException(...) }` : Laravel `Lock::block()` **NE retourne jamais `false`** — il **lève `LockTimeoutException`** au timeout (prouvé au probe Redis live). Donc:
- La branche `!block()` + `RuntimeException` (l.70-73) et le `@throws RuntimeException` (l.54) sont **du code/doc mort** — au vrai timeout de contention c'est un `LockTimeoutException` qui remonte.
- **Impact fonctionnel: nul.** Tous les call-sites catchent `\Throwable` (kiosk) ou laissent remonter dans une tx qui rollback (POS) → le contrat degraded (flag+retry / rollback) tient à l'identique. C'est un défaut de lisibilité, pas de correction. Fix suggéré: envelopper `block()` dans `try { $lock->block(3); } catch (LockTimeoutException $e) { throw new RuntimeException(...); }` OU corriger le docblock pour documenter `LockTimeoutException`.

---

## Récapitulatif tests exécutés (sqlite:memory, 0 échec)
167 PASS / 6 SKIP (2 mysql-only ProdLike + 4 consolidés StockMovementIdempotencyKeyUnique) / **0 FAIL** sur:
FiscalSequence, AuditLogConcurrency, QueueNumberConcurrency, ConcurrentOrder, Idempotency(×7), QuoteReplayIdempotency, Outbox(×5), FiscalAllocOrphanRetry, FiscalAllocErrorFlagOutsideTx, KioskRetryFiscalDatedAt, F001KioskFiscalSequenceInvariant, PosFreeDeliveryQuoteSeal, PriceChangeSnapshot, KioskLoyaltyDoubleRedeem/LedgerAtomic, CleanupVsConfirmRace, PosCounterCollectRaceProtection, PaymentConfirmConcurrency, StockConcurrentDecrement, AvailabilityDecrementConcurrency, ChangeStatusRaceGuard, Stripe/Senangpay/WebhookEvent Idempotency, LoyaltyRefundPointsIdempotent, PaymentNoop, KdsChangeStatusConcurrency, CashDrawerConcurrentSession, OrderStateMachineLockForUpdate.

## Findings priorisés
- **P0/P1: aucun.** Aucun invariant de concurrence/idempotence cassé.
- **P3 (LOW) FSEQ-NIT-01**: `FiscalSequenceService::next()` — branche `RuntimeException`/`@throws` morte (block() throws LockTimeoutException). Cosmétique, aucun impact runtime. Fix 3 lignes.
- **INFO NF525-GAP-01**: gap fiscal 2506-2508 branche 1 = hard-delete SQL manuel de commandes de test (hors code applicatif, box LOCAL dev). À noter pour hygiène des purges (utiliser soft-delete / `CleanupWebTestOrdersCommand`). Pas une course.
