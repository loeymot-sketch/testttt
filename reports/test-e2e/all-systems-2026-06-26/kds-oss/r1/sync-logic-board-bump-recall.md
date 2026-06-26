# KDS/OSS r1 — Lentille SYNC/LOGIQUE (Board + bump/recall + transitions)

Sous-système : `KitchenDisplaySystemOrderService::list/changeStatus/recall/historyToday`,
`KitchenReleaseRule`, `KdsSyncService`, OSS. DB `foodking_e2e`, serveur Paris-tz
(`NOW()`=04:24, `UTC_TIMESTAMP()`=02:24, `@@session.time_zone='SYSTEM'`=Paris).
READ-ONLY. Tous file:line vérifiés ; chaque finding a un repro DB/code/test.

---

## FINDING 1 — P2 — `KdsSyncService::sync` n'applique PAS le release-filter board (divergence SSOT prouvée, instance live)

[P2] app/Services/KdsSyncService.php:96-117 — la delta-poll surface des commandes NON-RELEASE que `list()` cache (UNPAID), + ship le téléphone DELIVERY sur le fil

- **repro** (prouvé live via tinker, branch 1, `since=2026-06-01`) :
  - `KdsSyncService::sync(1, since, true)` retourne l'ordre **4991** dans `orders[]` → `4991 in SYNC delta? YES`.
  - `KitchenDisplaySystemOrderService::list()` (même board, endpoint autoritaire) **CACHE** 4991 → `LIST ids contains 4991? no`.
  - Ordre 4991 : `status=8 (PREPARED)`, `payment_status=10 (UNPAID)`, `order_type=5 (DELIVERY)`, `pos_payment_method=NULL`, `is_advance_order=5 (YES)`, `order_datetime=2026-06-19` (advance-overdue) — donc NON-release au sens `KitchenReleaseRule::orderIsReleasedForBoard` (ni PAID, ni PENDING_COUNTER, ni POS+CASH).
  - `mysql foodking_e2e -e "SELECT u.phone FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=4991"` → `0600000000`. Comme `KDSOrderDetailsResource:72-75` expose `phone` quand `order_type==DELIVERY`, ce téléphone client part dans le payload `/sync` à TOUS les abonnés KDS, alors que l'ordre ne devrait pas être board-visible du tout.
- **evidence** :
  - `KitchenDisplaySystemOrderService.php:78` appelle `KitchenReleaseRule::applyBoardReleaseFilter($query)`. `KdsSyncService.php:96-117` (requête active-window) **n'appelle jamais** ce filtre (grep `applyBoardReleaseFilter` ⇒ présent uniquement dans `KitchenDisplaySystemOrderService.php`). `OrderStatusScreenOrderService` ne l'a pas non plus mais se protège autrement (voir note ci-dessous).
  - **Aucun test** ne pingle cette invariante : `KdsSyncControllerTest`, `KdsSyncSargableTest`, `KdsSyncTzAwareTest` ⇒ `grep -c 'payment_status|UNPAID|PENDING_COUNTER|release|PAID'` = 0 sur chacun. `KdsUnreleasedOrderBumpP1Test` ne couvre que `changeStatus`, pas le feed.
  - La docstring de `KitchenReleaseRule:93-98` impose explicitement : « list() and changeStatus() MUST share one definition so "visible on the board" and "bumpable" can never diverge again ». Le 3ᵉ feed (delta `/sync`) a été oublié de ce contrat.
- **lentille** : technique / cuisinier + frontière PII client.
- **Mitigation actuelle (downgrade de P1→P2)** : le consommateur Vue standard `KitchenDisplaySystemComponent.vue:1550-1556` n'injecte PAS `orders[]` du delta dans le board — il ne déclenche qu'un `_debouncedRefresh()` qui re-fetch `/list` (autoritaire, qui cache 4991). Donc le **board visible cuisinier ne montre pas** l'ordre UNPAID aujourd'hui. Le défaut reste réel car : (a) le payload `/sync` émet quand même l'ordre + le téléphone DELIVERY sur le fil ; (b) un futur consommateur direct des `orders[]` du delta (un TODO `status_changed_at` est déjà noté `KdsSyncService.php:165-172` pour basculer la logique de version) ré-exposerait le board ; (c) l'invariante SSOT documentée est silencieusement violée et non-testée.
- **reco** (hors-frozen, scope-minimal) : dans `KdsSyncService::sync`, après `->whereIn('status', $activeStatuses)` (l.97), ajouter `KitchenReleaseRule::applyBoardReleaseFilter($ordersQuery);` — exactement le miroir de `list():78`. Écrire d'abord le test rouge (TDD) `tests/Feature/KDS/KdsSyncReleaseFilterTest.php` : seed 1 UNPAID delivery advance-overdue + 1 PAID, asserter que `/sync` ne renvoie QUE le PAID. Re-run `KdsSyncSargableTest`+`KdsSyncTzAwareTest` (non-régression sargable/tz).

---

## VECTEURS ADVERSAIRES VÉRIFIÉS — TIENNENT (report de robustesse)

- **Bump commande NON-PAYÉE → notif client avant paiement** : TIENT. `KitchenDisplaySystemOrderService.php:447` `if (! KitchenReleaseRule::orderIsReleasedForBoard($locked)) throw 422`. Test `KdsUnreleasedOrderBumpTest::test_can_bump_unpaid_delivery_order_via_kds_change_status` PASS ⇒ le nom est trompeur mais l'assertion est `assertEquals(422)` + `status reste ACCEPT` (l.70-73). `SendOrderMail/Sms/Push` (l.478-480) ne partent qu'après le commit d'un bump VALIDE. Release admet PAID(5)|PENDING_COUNTER(15)|POS+CASH — confirmé `KitchenReleaseRuleTest` 8/8 PASS.
- **2 stations bump simultané → 409** : TIENT. `changeStatus` sous `lockForUpdate` (l.399-402) + garde optimiste `$fromLocked !== $expectedFrom → abort(409)` (l.411-424). `KdsExpectedStatusConflictTest` + `KdsChangeStatusConcurrencyTest` présents.
- **Recall spam > cap N=1 / fenêtre 60s expirée** : TIENT. `recall()` sous `DB::transaction`+`lockForUpdate` (l.294-299) : état≠PREPARED→422 (l.309), `updated_at` >60s→422 (l.320), dédup `kitchen_recall` sur **fenêtre glissante stable** `occurred_at >= now-60s`→409 (l.331-339), et assertion dure « status MUST stay PREPARED » (l.360-363). `order_status_transitions.occurred_at` existe + indexé (MUL) ; 12 lignes `kitchen_recall` réelles en DB. `KdsRecallCapNTest::second_recall_is_409_even_after_updated_at_advances` PASS. Status JAMAIS muté (NF525 forward-only, l.347-348 from=to=PREPARED).
- **Recall ne downgrade PAS l'OSS** : INTENTIONNEL, pas un bug. `recall()` docstring l.268 « The OSS "Prêt" notification is NOT downgraded » + status reste PREPARED (NF525 §7 frozen-forward). Le client voit "PRÊT" même après un recall cuisine — trade-off documenté, pas un défaut P0/P1/P2 (au pire P3 UX, non retenu).
- **51ᵉ commande tronquée invisible** : TIENT (avec signal). `list():172` `->limit(51)` puis l.175 `lastListOverflow = count()>50` puis l.177 `->take(50)`. Le controller `KitchenDisplaySystemController.php:32-34` expose `meta.overflow=true` ⇒ le cuisinier EST averti de la troncature. `KdsPaginationOverflowTest` PASS. Board actuel branch 1 = 21 actives (release+window), < 50, pas de risque live.
- **Zombie advance d'hier invisible** : non-reproduit. 0 commande released non-advance hors fenêtre `list()` (`SELECT COUNT(*) ... is_advance_order=10 AND order_datetime<today AND released` = 0). Les advance (is_advance_order=5) overdue restent visibles via la clause `order_datetime < tomorrow AND status NOT IN (DELIVERED,CANCELED)` (l.133-137). Note : `changeStatus` n'a pas de garde-date ⇒ une commande released hors-fenêtre serait bumpable par ID mais invisible au board, donc non-déclenchable par l'UI cuisinier (non-exploitable V1).
- **TZ Paris** : TIENT. `list():121-124`, `historyToday():226-228`, `KdsSyncService:91-94`, OSS `:86-89` utilisent tous `Carbon::today(config('app.timezone'))` (Paris-local) — cohérent avec `@@session.time_zone='SYSTEM'`=Paris vérifié. Sentinelle `KdsTodayWindowTzSentinelTest`.
- **Frontière PII OSS** : TIENT. Mur public `CDSOrderDetailsResource` (id/serial/token/queue/order_type/status) = 0 PII. `KDSOrderDetailsResource:72-75` gate `phone` à DELIVERY uniquement. `PosShortcutOrderResource` (expose `total`) = widget POS AUTHED, PAS le mur (docstring l.13-16). Mur Vue `PreparingAndReadyComponent.vue:280-283` early-return Echo si branchId<=0 (poll-only, cadence 5s l.266-270) → ne rejoint jamais `private-branch.{id}`.
- **Post-commit notif isolation** : TIENT. `changeStatus` l.477-493 capture `\Throwable` sur les dispatch notif + le broadcast KDS post-commit (commit déjà fait) ⇒ un échec sync ne re-wrappe pas un bump réussi en 422.

---

## NOTE DATA-HYGIÈNE (hors lentille logique, signalée pour DBA)
`orders.is_advance_order` a des valeurs non-canoniques en DB : `10 (NO)`×2654, `5 (YES)`×158, mais aussi `0`×11, `2`×4, `1`×17. Les lignes ≠{5,10} ne matchent NI la clause standard (`=10`) NI advance (`=5`) ⇒ invisibles à list/sync/items/OSS (probable pollution e2e). Pas un bug de logique du board ; à nettoyer (`catalog:clean-test-data`-like) si confirmé test-only.

---

## TESTS EXÉCUTÉS (evidence)
`php artisan test --filter "KdsUnreleasedOrderBump|KdsRecallCapN|KdsPaginationOverflow|KitchenReleaseRule"` ⇒ **16 passed** (suite sqlite test-DB ; NE PAS passer `DB_DATABASE=foodking_e2e` au phpunit, ça force le sqlite-path à pointer un fichier inexistant → 23 erreurs de connexion, faux-négatif).
Preuve live divergence sync/list ⇒ tinker `DB_DATABASE=foodking_e2e php artisan tinker` (read-only, aucune écriture).
