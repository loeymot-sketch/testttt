I have all the data needed. Here is the full independent audit.

---

# AUDIT INDÉPENDANT EXTERNE — FoodKing Phase 2 Train A V1
Date: 2026-04-28 | Auditeur: Claude Opus 4.7 (externe, indépendant de Codex)

---

## 1. AUDIT_VERDICT: `PASS_PROCEED_HARDWARE_UAT`

## 2. RELEASE_DECISION: `HOLD_FOR_HARDWARE_UAT`

Le code local est suffisamment solide pour passer en Hardware UAT. Aucun P0 logiciel bloquant identifié. Deux P2 mineurs documentés ci-dessous.

---

## 3. Findings P0 / P1 / P2

### P0 — Aucun trouvé

Après lecture complète des fichiers critiques, aucun P0 logiciel bloquant l'UAT n'est identifié.

### P1 — Aucun trouvé

Les risques P1 théoriques (stock négatif, doublon queue_number, fiscal_sequence_no prématuré, cross-branch leak) sont tous couverts par du code défensif vérifié et des tests.

### P2-1 — OrderService taxPrice non arrondi dans le legacy path POS

`app/Services/OrderService.php:760` — `$taxPrice = round(...)` est présent, mais dans le legacy path `tableOrderStore` à la ligne 1165, le calcul `$taxPrice = $taxType === TaxType::FIXED ? $taxRate : ($verifiedTotalPrice * $taxRate) / 100;` n'a pas de `round()`. FrontendOrderService:363 a le `round()`.

Risque: dérive de centimes sur les taxes en mode table order legacy. Non bloquant pour UAT (le SSOT path est actif par défaut).

Correction demandée: ajouter `round(..., 2)` à `OrderService.php:1165` pour symétrie.

### P2-2 — OrderService legacy path manque `created_at`/`updated_at` dans FrontendOrderService

`app/Services/FrontendOrderService.php:368-386` — le `$itemsArray` du legacy path kiosk ne contient pas `created_at`/`updated_at`, alors que `OrderService.php:765-785` les inclut. Non bloquant car Eloquent les gère au niveau modèle, mais c'est une asymétrie mineure dans le bulk insert.

---

## 4. Tableau par domaine

| Domaine | Verdict | Justification |
|---------|---------|---------------|
| C0 — Kiosk post-payment auto-return | PASS | E2E Playwright couvert (C1 K5 + kiosk-post-payment-auto-return) |
| C1 — Full kiosk process | PASS | 5/5 scénarios E2E: card, tacos composition, cash-at-counter, rupture, abandon |
| C2 — Full POS process | PASS | 5/5 scénarios E2E: dine-in cash, takeaway card, delivery forge, counter-confirm, counter-cancel |
| C3 — Multi-surface runtime sync | PASS_RUNTIME_LOCAL | 6/6 repeat-each=3, kiosk→KDS 2.8s, POS→KDS/OSS couvert. Fixes branch_id lookup, KdsSyncService auth, is_advance_order enum |
| C4 — Stock V2 | PASS_STRONG | 20 tests Feature/Stock + 50-worker MySQL/Redis prod-like: exactement 20 succès / 30 rejets. lockForUpdate atomique vérifié |
| C5 — Queue number | PASS_STRONG | 5 tests + sentinel + 50-worker MySQL/Redis: A0001→A0050 unique. Lock TTL 30s, block 15s, maxAttempts 5 symétrique |
| C6 — Fiscal NF525 | PASS_LOCAL | 3 tests FiscalCashAtCounter + 5 CounterDeferred + 2 PaymentStateMachine + 9 Outbox. fiscal_sequence_no=null avant confirm, idempotent, cancel safe |
| C7 — Delivery/geocode | PASS | 3+2+1 tests delivery. Backend SSOT recompute, forge ignoré |
| C8 — Realtime sync | PASS_LOCAL | C3 runtime + Vitest KDS contracts (version gate, backoff, sync cadence, broadcast fallback) |
| C9 — Dashboard/composer/catalog | PASS_API | 6 ComposerAuthz + 1 PhotoE2EKioskInvalidation + 2 CatalogChanged + schema/addon/projection. Prix rejeté dans payload composer |
| C10 — Authz matrix | PASS_TARGETED | ComposerAuthz 6 tests: branch admin own only, tenant admin all, POS/delivery forbidden. Counter cross-branch 404 |
| D1 — Kiosk design | PASS | Playwright design audit |
| D2 — POS design | PASS | Playwright design audit |
| D3 — KDS/OSS design | PASS | Playwright design audit |

---

## 5. Verdict symétrie OrderService / FrontendOrderService

**PASS** avec réserve P2 mineure.

Éléments vérifiés symétriques:
- `saveOrderWithQueueNumber` / `saveFrontendOrderWithQueueNumber`: maxAttempts=5, lock TTL=30s, block=15s — identiques
- `allocateQueueNumber`: même logique exacte (query `orders` table, même lock key pattern, même fallback 409)
- `resolveBusinessDate`: identique
- `isQueueNumberUniqueViolation`: identique
- `findExistingOrderForIdempotencyRecovery` / `findExistingFrontendOrderForIdempotencyRecovery`: même pattern (branch_id + idempotency_key)
- Backend pricing SSOT: les deux `unset($validatedRequest['total'], $validatedRequest['subtotal'], $validatedRequest['discount'])` avant create
- Stock decrement: les deux appellent `StockService::decrementForOrder()` dans la transaction
- Dispatch after commit: les deux dispatch notifications/events APRÈS la transaction DB
- OrderStateMachine::recordTransition: les deux enregistrent les transitions
- Allergen snapshot: les deux utilisent `OrderItemAllergenSnapshot::hydrate()`
- Cross-item injection guard: les deux vérifient `$dbVar->item_id !== $item->item_id`

Asymétrie P2 mineure: `created_at`/`updated_at` dans itemsArray (OrderService les inclut, FrontendOrderService non). Non bloquant.

---

## 6. Verdict ProdLikeConcurrencyTest

**Preuve SUFFISANTE pour UAT.**

Le test est structurellement solide:
- Skip conditionnel MySQL + Redis (lignes 27-31) — pas de faux positif SQLite
- `migrate:fresh` sur DB dédiée — isolation propre
- 50 vrais `proc_open` workers parallèles, pas de simulation in-process
- Stock: 20 units, 50 workers → assertion exacte 20 success + 30 stock_unavailable + on_hand=0 + 20 mouvements négatifs
- Queue: 50 POS/kiosk alternés → 50 queue_numbers uniques A0001→A0050
- Worker script (`prodlike-concurrency-worker.php`) utilise `ReflectionMethod` pour appeler les méthodes privées — acceptable pour test prod-like
- 3 runs consécutifs rapportés PASS

Limite honnête: le test ne simule pas la latence réseau ni les timeouts Redis réels. Acceptable pour UAT local.

---

## 7. Tests à relancer: AUCUN (pas de REWORK)

Le verdict est PASS. Aucun test ne nécessite de relance bloquante.

Si l'équipe souhaite une confiance supplémentaire avant go-live (post-UAT):
- `php artisan test tests/Feature/ProdLike/ProdLikeConcurrencyTest.php` sur l'environnement staging MySQL/Redis
- Full 7-role authz matrix (C10 étendu)
- D4-D13 campaign massive (2000 runs)

---

## 8. Plan d'implémentation Codex (corrections P2 optionnelles)

Aucune correction P0/P1 requise. Si Codex souhaite corriger les P2 avant UAT:

**P2-1** — `app/Services/OrderService.php` ~ligne 1165: entourer le calcul taxPrice de `round(..., 2)` dans le legacy tableOrderStore path. 1 ligne, 0 risque.

**P2-2** — `app/Services/FrontendOrderService.php` ~lignes 368-386: ajouter `'created_at' => now(), 'updated_at' => now()` dans le `$itemsArray` du legacy path pour symétrie avec OrderService. 2 lignes, 0 risque.

Ces deux corrections sont optionnelles et ne bloquent pas l'UAT.

---

## 9. Vérification des invariants FoodKing

| Invariant | Statut | Preuve |
|-----------|--------|--------|
| Backend pricing SSOT | PASS | `unset($validatedRequest['total/subtotal/discount'])` dans les deux services. Prix toujours depuis `$dbItem->price`. PricingService SSOT actif. Aucun `$request->price/total/subtotal` accepté |
| OrderStatus enum | PASS | Interface PHP avec constantes numériques (1,4,7,8,10,13,16,19,22). Zéro chaîne magique dans OrderService/FrontendOrderService — toutes les références sont `OrderStatus::PENDING`, `::ACCEPT`, etc. |
| branch_id isolation | PASS | Kiosk force `$kiosk->branch_id`. POS vérifie `assertOrderBranchVisible()`. Counter-collect cross-branch → 404. Queue lock scopé par branch_id. Stock scopé par branch_id + lockForUpdate |
| Dispatch after commit | PASS | 12 events utilisent `DispatchableAfterCommit` trait (OrderCreated, OrderStatusChanged, OrderCanceled, OrderPaidAtCounter, StockLevelChanged, ItemAvailabilityChanged, CatalogChanged, etc.). Notifications dispatch hors `DB::transaction()` dans les deux services |
| Symétrie OrderService/FrontendOrderService | PASS | Voir §5 ci-dessus |
| Zones frozen | PASS | Les modifications OrderService/FrontendOrderService sont limitées au queue lock hardening (maxAttempts, TTL, block) — justifié par le test prod-like qui a exposé le timeout sous 50 workers |
| fiscal_sequence_no | PASS | Kiosk: jamais alloué (FrontendOrderService ne touche pas fiscal_sequence_no). POS: alloué atomiquement via FiscalSequenceService::next() avec Cache::lock + lockForUpdate. Counter-confirm: alloué seulement si payment_status=PAID. Cancel: reste null |
| Stock atomique | PASS | StockService utilise lockForUpdate sur StockLevel. Vérifie `on_hand <= 0` avant decrement. Mouvement append-only. Idempotency via seed |
| Queue number unique | PASS | Cache::lock + DB unique constraint + retry loop (5 attempts). Même séquence partagée POS/kiosk via table `orders` |

---

## 10. Conclusion

**Codex PASS. Le code local est prêt pour Hardware UAT.**

Les invariants FoodKing sont respectés. Les corrections Codex (queue lock hardening, prod-like MySQL/Redis, migration rollback, C3 runtime fixes) sont vérifiées et cohérentes. Aucun P0/P1 logiciel ne bloque le passage en UAT. Les deux P2 identifiés sont cosmétiques et non bloquants.

Le Hardware UAT reste obligatoire pour: TPE physique, imprimante fiscale, lockdown kiosk OS, écrans KDS réels, perte réseau, Google Maps live.
