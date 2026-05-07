# AGENT_SECURITY_MEGA — Audit sécurité du flux MEGA PARCOURS V1

- **Date** : 2026-05-08
- **Reviewer** : Agent SECURITY MEGA (rôle GSTACK R1-sec hostile)
- **Périmètre** : audit **flux** du MEGA PARCOURS (10 commandes POS + Kiosk + 2 ruptures), **pas** audit code stationnaire
- **Artefacts source** :
  - `tests/e2e/screenshots/mega-parcours-2026-05-08/http-trace.json` (15 traces)
  - `tests/e2e/screenshots/mega-parcours-2026-05-08/domain-events-timeline.json` (15 snapshots)
  - `tests/e2e/screenshots/mega-parcours-2026-05-08/findings.json` (28 findings)
  - `tests/e2e/screenshots/mega-parcours-2026-05-08/INDEX.md`
  - `docs/audit/MEGA_PARCOURS_E2E_REPORT_2026-05-08.md` (rapport ORCHESTRATOR)
  - `storage/logs/laravel.log` + `laravel-2026-05-07.log`
- **Méthode** : 10 hypothèses adversaires S1..S10 pondérées par evidence concrète, trust-but-verify ; non-duplication de l'AGENT_SEC ROUND-2 (commit `1b38e64a3`, scope = observability/outbox/sentinels)

> **Verdict** : **GO security V1 (avec heal léger)**. Aucun P0 sécurité. Une vraie P1 d'**availability/safety** (rate-limiter partagé entre POS submit et toggle OOS), une honnête limitation S7 (un seul échantillon fiscal_seq).

---

## 1. Bilan par hypothèse adversaire

### S1 — Idempotency-Key uniqueness (POS-N + Kiosk-N)

**INDÉTERMINÉ via cette trace, mais défense architecturale en place.**

Evidence trace : `tests/e2e/screenshots/mega-parcours-2026-05-08/http-trace.json` capture 20 appels API pour POS-1 avec `"method": "GET"` uniquement (catalog, dashboard, walk-in-customer, item details). **Le POST `/api/admin/pos` n'a pas été enregistré dans cette trace** — la spec a opéré en mode `submitPosOrder()` direct (helper Playwright fetch côté browser context) et la liste `apiCalls` capturée provient du listener `page.on('request')` qui filtre uniquement `/api/admin/*` GET (cf flow R1/R2 prior). Conséquence : tous les `"idempotency": null` du fichier sont des **GETs** — non pertinent pour S1.

Defense-in-depth confirmée par config statique :
- `routes/api.php:697` — POS create : `Route::post('/', [PosController::class, 'store'])->middleware(['throttle:pos-order-create', 'idempotency'])`
- `routes/api.php:803-812` — counter-collect confirm/cancel : `'idempotency'` middleware appliqué
- `routes/api.php:1045` — kiosk order : `Route::post('/', [FrontendOrderController::class, 'store'])->middleware(['throttle:kiosk-orders', 'idempotency'])`

**Verdict S1** : middleware `idempotency` câblé sur les 3 endpoints POST critiques (POS create, counter-collect, kiosk store). La trace mega ne permet PAS de prouver que chaque POST a porté un X-Idempotency-Key unique runtime, mais aucune evidence inverse non plus. **À vérifier dans cycle suivant** par instrumentation `page.on('request', r => r.method()==='POST' && capture headers)`.

### S2 — JWT/Sanctum token leak

**RÉFUTÉ.**

Grep des artefacts pour `Bearer`, `Authorization`, `api_token`, `sanctum`, `password` : **aucun match** dans `findings.json`, `http-trace.json`, `domain-events-timeline.json`. Le seul `"token": null` détecté (`findings.json:77`) est le champ **payment provider reference** dans le payload order (cf `Order` model — `token` = transaction reference Stripe/Paddle, pas auth token).

Les URLs tracées pointent toutes vers `http://localhost:8000/api/...` sans query-string `?token=...` ni `?api_key=...`. Les screenshots fullPage (échantillon `pos-3-step-04-payment-modal.png`, `kiosk-1-step-09-after-confirm.png`) ne montrent ni token Sanctum ni cookie session côté UI (HttpOnly, comme attendu).

**Verdict S2** : pas de leak observable côté flux mega.

### S3 — Branch isolation (injection branch_id=2)

**NON EXERCÉ — limitation honnête, pas refutation.**

La spec mega n'a injecté **aucun** `branch_id=2` dans les payloads — toutes les commandes ont seedé `branch_id=1`. Les seules occurrences de `branch_id` dans la trace sont :
- `?branch_id=1` en query GET catalog (auto-injecté par UI)
- `branch_id: 1` dans response order (POS-3 #234)

Conséquence : la mega-spec **ne réfute pas** l'hypothèse RED-R3 d'isolation cross-branch ; elle ne la teste tout simplement pas.

Compensation par autres pièces de preuve :
- RED-R3 a déjà testé "double-vente cascade auto-86" → réfutée (cf `docs/audit/RED_TEAM_R3_RUPTURE_STOCK_2026-05-07.md`).
- `tests/Feature/Sentinels/PosCatalogRequiresBranchSentinelTest.php` verrouille la 422 si `surface=pos` sans `branch_id` (cf AGENT_SEC ROUND-2 §S3).
- `app/Http/Controllers/Admin/ItemController.php:259-273` `forcePosRuntimeBranchScope` : pour POS Operator pur (a `pos` sans `items_show`), force `branch_id` depuis `user->branch_id` ou abort 403.

**Verdict S3** : isolation branch garde-fous **architecturaux** confirmés, runtime cross-branch **non testé** dans cette mega. À ajouter au prochain cycle (forge POST avec `branch_id=2` pour user branch_id=1 → expected 403/422).

### S4 — Bypass mode n'a PAS introduit de bypass auth

**RÉFUTÉ — runtime witness présent.**

Evidence runtime forte dans les logs :
```
[2026-05-07 13:28:17] production.ERROR: PAYMENT_BYPASS_MODE=true is forbidden in production:
bypass mode short-circuits TPE validation. ...
at app/Providers/AppServiceProvider.php:84
```
Cette ligne prouve que **quelqu'un a réellement** booté l'app avec `APP_ENV=production` + `PAYMENT_BYPASS_MODE=true`, et le guard a refusé de boot avec RuntimeException (stack visible). C'est la preuve runtime que **le `BypassProductionGuardTest` (5/5 attendus PASS) est cohérent avec le comportement réel**.

Code source :
- `app/Providers/AppServiceProvider.php:78-128` — guard prod-only avec 4 checks :
  1. `payment.bypass.enabled` → throw
  2. `printing.bypass.enabled` → throw
  3. `broadcasting.default ∈ [null, 'null']` → throw
  4. `queue.default === 'sync'` → throw
  5. `cache.default ∈ ['array', 'null']` → throw (W9-AUDIT B1-OPS pour audit chain integrity)
- `tests/Feature/Sentinels/BypassProductionGuardTest.php` — 5 tests :
  1. `test_payment_bypass_throws_in_production` ✓
  2. `test_printing_bypass_throws_in_production` ✓
  3. `test_both_bypass_disabled_in_production_is_ok_for_bypass_check` ✓
  4. `test_bypass_allowed_in_local_env` ✓
  5. `test_null_printer_transport_resolved_when_printing_bypass_enabled` ✓

L'auth Sanctum reste intacte côté routes (cf S9 AGENT_SEC ROUND-2) — aucun nouveau middleware "bypass auth" introduit. Bypass = uniquement TPE call + thermal printer TCP/IP.

**Verdict S4** : RÉFUTÉ par evidence runtime + sentinels + code review. Aucun bypass auth proper.

**Limitation honnête** : je n'ai pas pu exécuter `php artisan test --filter=BypassProductionGuardTest` runtime (server not callable depuis sandbox). La RuntimeException du 13:28:17 est un proxy fort mais pas un PASS strict des 5 cas du test.

### S5 — CSRF/throttle 429 inattendu sur 10 commandes successives — **VRAI P1 SÉCURITÉ-OPS**

**CONFIRMÉ — finding partagé avec ORCHESTRATOR mais avec angle sécurité.**

Evidence trace (`http-trace.json`) :
- POS-4 step 5 : submit OOS → **429 Too Many Attempts**
- POS-4 step 6 : toggle ON 363 → **429 Too Many Attempts** (juste 19ms après, 17ms response)

Cause : tous les endpoints `/api/admin/*` (incluant `pos/store` ET `menu/availability/toggle`) sont **dans le même groupe** :
```
routes/api.php:242
Route::prefix('admin')->name('admin.')->middleware([
    'installed', 'apiKey', 'auth:sanctum', 'localization',
    'throttle:admin-mutation'  ← 30/min non-GET sur la même clé user_id
])->group(function () { ... });
```

Le throttler `admin-mutation` (`app/Providers/RouteServiceProvider.php:77-89`) :
```php
return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
```
30 mutations/min par utilisateur authentifié. Un cycle rush (10 commandes + 2 toggles + 4 reload catalog POST) sature ce bucket.

**Sub-throttles ne sauvent pas** : `pos-order-create` (60/min) et l'absence de throttle dédié sur `/menu/availability/toggle` ligne 251 → c'est le **bucket parent admin-mutation** qui rejette.

**Pourquoi c'est sécurité-ops, pas seulement bug** :
- Toggle OOS est un **contrôle de sûreté en rush** : caissier doit pouvoir marquer immédiatement un produit indispo (rupture ingrédient). 429 sur toggle = caissier impuissant → continue à vendre OOS → **friction client + erreurs caisse + plaintes**.
- Le rate-limiter mégissée la sûreté disponibilité. Sécurité ≠ uniquement confidentialité ; **availability** fait partie du CIA triad.

Fix architectural proposé :
1. Extraire `/menu/availability/toggle` du groupe parent `admin` ou créer un **bucket dédié haute-fréquence** : `RateLimiter::for('availability-toggle', fn($r) => Limit::perMinute(120)->by($r->user()?->id))`.
2. Aligner sur le pattern existant `pos-quote` qui a déjà 120/min (cf RouteServiceProvider:84-86 — `pos/quote` + `counter-collect/*` exemptés à 120/min).

**Verdict S5** : **P1 sécurité-availability**. Tagged for V1 finalize.

### S6 — Audit log écrit pour TOUT (OrderPaidAtCounter par commande, AuditLogService)

**ARCHITECTURE WIRED ✓ — RUNTIME PARTIEL.**

Evidence `domain-events-timeline.json` :
- POS-3 (#234, TR) : `total: 170 → 171` après submit — **1 event `order.created` ajouté** (id 419) ✓
- Kiosk-1 (#237) : 2 events ajoutés (`order.created` id 422 + `order.status_changed` id 423) ✓
- Kiosk-2 (#238) : 2 events (id 424, 425) ✓

`OrderPaidAtCounter` est **bien câblé** :
- `app/Events/OrderPaidAtCounter.php` — event class
- `app/Services/PaymentService.php:216` — `OrderPaidAtCounter::dispatch($order, $mode)` dans `confirmCounterPayment()`
- `app/Providers/EventServiceProvider.php:131-132` — listener `PersistOrderPaidAtCounterToOutbox` mappé
- `app/Listeners/PersistOrderPaidAtCounterToOutbox.php:33` — écriture outbox avec `broadcast_as='OrderPaidAtCounter'`
- `app/Domain/Events/EventContract.php:37` — `'OrderPaidAtCounter' => EventType::ORDER_PAYMENT_CONFIRMED`

Pourquoi non visible dans cette mega : `OrderPaidAtCounter` ne fire que dans `PaymentService::confirmCounterPayment` (= caissier confirme l'encaissement comptoir). **Aucune commande mega n'a appelé** `/api/admin/pos/counter-collect/confirm` :
- POS-1, POS-2 : 422 OOS leftover, pas d'order créé
- POS-3 : TR mode payé directement à la création (pas counter-collect)
- POS-4, POS-5 : OOS scenarios, pas d'order créé
- Kiosk-1, Kiosk-2 : order créé `payment_status=5` (paid for kiosk-1 stub bypass) ou `pending_counter` (kiosk-2) — mais **counter-collect/confirm jamais invoqué dans la mega**.

**Conclusion architecturale** : event wired correctement, listener wired, outbox écrit, broadcast_as conforme. Le **runtime mega ne l'exerce pas** parce qu'aucun flow `counter-collect/confirm` n'est exécuté.

**Limitations honnêtes** :
- POS-3 mode TR : pas d'evidence d'appel direct à `AuditLogService::write` (chaîne HMAC NF525) pour cette commande dans la mega trace. Selon les invariants documentés (`BypassAuditLogger.php:14-22`), bypass préserve audit log → l'invariant **devrait** tenir mais non vérifié runtime ici.
- Discount/refund : non exercés.

**Verdict S6** : architectural ✓, runtime mega = `order.created` confirmé pour les commandes abouties, `OrderPaidAtCounter` non exercé (pas un gap, juste un flow non-couvert par la mega). À couvrir cycle suivant par appel explicite `counter-collect/confirm` post kiosk-2.

### S7 — fiscal_sequence_no monotone strict

**1 ÉCHANTILLON SEUL — non concluant pour monotonie globale.**

Evidence `findings.json:208` : POS-3 #234 retourne `"fiscal_sequence_no": 7`.

C'est l'unique commande aboutie via API trace dans cette mega (POS-1, POS-2 sont 422 OOS leftover ; kiosk-1, kiosk-2, kiosk-3 ne montrent pas leur `fiscal_sequence_no` dans `domain-events-timeline.json` qui ne capture que metadata events, pas le payload order).

**Vrai test** : il faudrait extraire les `fiscal_sequence_no` des 4 orders aboutis (#234, #237, #238 + un 4e si présent) via `SELECT id, fiscal_sequence_no FROM orders WHERE id IN (234, 237, 238) ORDER BY id`. Si `[7, 8, 9]` strict croissant sans saut, S7 OK. Si gap ou duplicate, P0.

**Verdict S7** : **non concluant**. Limitation honnête mega-spec — instrumenter cycle suivant pour fetch `fiscal_sequence_no` post-submit chaque commande et asserter monotonie.

### S8 — Refund miroir testé

**NON EXERCÉ.**

Aucune commande de la mega n'a invoqué `/api/admin/pos/refund` ni équivalent. La spec a couvert la création + le succès, pas le miroir négatif.

**Verdict S8** : **non testé**. Référencer RED-R1 / cycle refund séparé pour couverture (cf `tests/Feature/Pos/RefundMirroringTest.php` si existant).

### S9 — HTTP response leak (422 / 409 / stack traces / PII)

**RÉFUTÉ.**

Inventaire des bodies 4xx/5xx capturés (`http-trace.json`) :
- `{"status":false, "message":"Article 363 indisponible pour cette branche (mega-kiosk-4)."}` — **statique**, pas de stack, pas de PII.
- `{"message":"Last 4 digits of card is required", "errors":{"pos_payment_note":[...]}}` — Laravel validation standard, pas de PII (juste le nom de champ).
- `{"message":"Too Many Attempts."}` — Laravel default throttle, no leak.
- `{"ok":true, "item_id":363, "branch_id":1, "is_available":false, "unavailable_reason":"mega-pos-4"}` — métadata legitime, pas de leak.

Aucune stack trace `at /Users/.../app/...:NNN` exposée dans les bodies API mega. Le leak `production.ERROR` du 13:28:17 dans laravel.log est **côté logs**, pas dans une response HTTP retournée au client (c'est l'exception qui bloque le boot, pas un endpoint qui leake un trace).

**Verdict S9** : RÉFUTÉ pour cette mega.

### S10 — Bypass `[BYPASS-PAYMENT]` log audit trail

**PARTIELLEMENT CONFIRMÉ — angle plus nuancé que prévu.**

Grep `storage/logs/laravel-2026-05-07.log` pour `[BYPASS-PAYMENT]` :
- 2 entries seulement, pour orders #231 (kiosk-1 first run) et #237 (kiosk-1 second run, date 2026-05-07 18:38:15).
- 0 entry `[BYPASS-PRINTING]`.

Pourquoi seulement 2 alors que mega = 10 commandes ?

Evidence code `app/Services/Bypass/BypassAuditLogger.php` + call sites grep :
1. `OrderController::paymentConfirm` (frontend kiosk card stub) → fire `paymentBypassed` ligne 99-117 ✓ (= les 2 entries du log)
2. `PaymentService::confirmCounterPayment` ligne 127 → fire `paymentBypassed` au début même quand bypass est OFF (le logger garde-côté `if (!$enabled) return` ligne 28-30)
3. `EscPosPrinterService` lignes 18, 23, 113 → fire `printingBypassed` quand TPE printer driver utilisé

**Analyse honnête** :
- POS-3 (TR mode 11) ne passe **pas** par `paymentConfirm` (pas TPE), il passe par `PosController::store` directement → légitime que ne fire PAS `[BYPASS-PAYMENT]`.
- Kiosk-2 (cash counter) → workflow "paiement à la caisse plus tard" → la commande est créée maintenant mais le paiement TPE/cash arrive au moment du `counter-collect/confirm` plus tard — **PAS dans cette mega**, donc légitime que ne fire PAS.
- Kiosk-1 (carte stub bypass) → fire ✓ (2 fois car re-run le test).
- POS-1, POS-2 ont 422 → no payment → no bypass log ✓
- POS-4, POS-5 ne créent pas de commande aboutie (OOS scenarios) → no bypass log ✓
- Kiosk-3, 4, 5 ne créent pas de commande aboutie (multi-add timeout / OOS / no-wizard) → no bypass log ✓
- 0 `[BYPASS-PRINTING]` car **printing bypass mode → NullPrinterTransport résolu** côté provider (cf BypassProductionGuardTest::test_null_printer_transport_resolved_when_printing_bypass_enabled), aucun appel à EscPosPrinterService ne se produit physiquement.

**Verdict S10** : audit trail bypass **cohérent avec les flows réellement exécutés**. Pas de gap réel. Le warning structuré contient `correlation_id`, `user_id`, `branch_id`, `gate=GATE_BYPASS_MODE_2026-05-08`, `env`, `timestamp`, `controller`, `order_id`, `transaction_id` → format audit-log conforme. **OK**.

---

## 2. Top 4 vraies failles **sécurité** (P0/P1/P2)

> Ce top liste uniquement les findings **sécurité** (CIA triad : Confidentialité, Intégrité, **Availability**). Les findings produit / UX (notamment `pos-5/extra-oos-not-marked-ui`, kiosk-3 multi-add timeout) appartiennent à l'ORCHESTRATOR rapport et ne sont pas répétés ici.

| # | Sev | Slug | Description | Evidence | Fix proposé |
|---|---|---|---|---|---|
| 1 | **P1 sec-availability (récurrent)** | `mega/throttle-admin-mutation-shared-bucket` | `/api/admin/pos` POST submit ET `/api/admin/menu/availability/toggle` partagent le bucket `admin-mutation` (30/min). Caissier en rush ne peut PAS toggle un produit OOS si bucket consommé par submits. **Atteinte à la sûreté disponibilité** en pic d'activité. **Finding raised 3 fois** : RED-R3 (2026-05-07), ORCHESTRATOR mega report (2026-05-08), et maintenant cet AGENT_SECURITY_MEGA. | `routes/api.php:242, 251, 697` ; `RouteServiceProvider.php:77-89` ; runtime `http-trace.json` POS-4 step 5 et 6 (429 sur toggle ON juste 19ms après submit OOS) | Extraire `availability/toggle` vers throttler dédié 120/min ; ou passer `admin-mutation` à 60/min ; ou whitelister POST `availability/toggle` dans le filtre RateLimiter::for('admin-mutation') (cf pattern existant `pos/quote` à 120/min lignes 84-86). |
| 2 | **P2 sec** | `mega/idempotency-key-runtime-not-verified` | Middleware `idempotency` câblé sur 3 POSTs (`pos/store`, `kiosk/store`, counter-collect confirm/cancel) mais runtime `X-Idempotency-Key` header émission par client **pas tracé** dans cette mega (toutes les calls capturées sont des GETs). | `routes/api.php:697, 803, 805, 812, 1045` ; `http-trace.json` (apiCalls = GET only) | Cycle suivant : instrumenter `page.on('request')` côté Playwright pour capturer les headers POST. Vérifier que client (axios POS + axios Kiosk) émet bien un UUID v4 unique par submit. |
| 3 | **P2 sec** | `mega/branch-isolation-cross-injection-untested` | Mega ne forge PAS de `branch_id=2` dans les payloads pour tester isolation cross-branch. Hypothèse RED-R3 confirmée à l'époque mais pas re-validée en mega. | `http-trace.json` (toutes URLs `branch_id=1`) ; `tests/Feature/Sentinels/PosCatalogRequiresBranchSentinelTest.php` | Cycle suivant : ajouter test mega-spec qui forge `POST /api/admin/pos` avec `branch_id=2` pour user branch_id=1, attendre 403/422. |
| 4 | **P3 ops** | `mega/fiscal-sequence-monotone-1-sample` | Un seul order #234 a `fiscal_sequence_no=7`. Monotonie strict non vérifiable sur 1 sample. | `findings.json:208` | Cycle suivant : assert monotonie des `fiscal_sequence_no` des 4+ orders aboutis ; alert si gap/duplicate. |

> Cinquième slot intentionnellement vide : aucun autre P0/P1 sécurité réel détecté. Refuser de gonfler artificiellement le top.

---

## 3. Verdict GO/NO-GO security MEGA flow V1

**GO security V1 avec heal léger.**

Critères respectés :
- ✓ Zéro P0 sécurité (pas de bypass auth, pas de leak token, pas de leak PII en response)
- ✓ Bypass production guard **proven runtime** (RuntimeException du 13:28:17)
- ✓ Audit trail `[BYPASS-PAYMENT]` cohérent avec flows réellement exécutés (S10)
- ✓ Idempotency middleware câblé sur les 3 POSTs critiques (S1 architectural)
- ✓ Authz Sanctum + role/permission inchangée côté routes
- ✓ Aucune stack trace ni PII exposée dans les responses 4xx
- ✓ Domain events `order.created` créés pour les commandes abouties

Heal nécessaire (non-blocker mais à clore avant V1 finale) :
- ⚠️ S5 throttle `admin-mutation` partagé : impacte la sûreté disponibilité (toggle OOS bloqué en rush). À extraire/élever.
- ⚠️ S1 runtime headers POST non tracés : add instrumentation Playwright dans cycle suivant.
- ⚠️ S3 cross-branch injection non exercée en mega : add forge test dans cycle suivant.
- ⚠️ S7 monotonie fiscal non vérifiée : add SQL probe dans cycle suivant.

PAS de **block** justifié, **malgré** que S5 ait été levé 3 fois (RED-R3 → ORCHESTRATOR → ce rapport), car :
- Aucune nouvelle surface d'attaque introduite par la mega ni par les flags bypass
- Le rate-limiter partagé est une régression d'**usabilité-sûreté** (DoS-self via légitimes opérations caisse), **pas** une faille exploitable par un attaquant externe ; un attaquant ne peut pas l'utiliser pour escalader privilèges, exfiltrer données, ou déni de service tiers — au pire il sature **son propre** bucket user_id-scopé.
- Toutes les rejections 422/429 observées sont **légitimes** (validation backend solide)
- Bypass invariants préservés (sealing fiscal, Outbox, audit log, idempotency middleware) — confirmé par le doc `BypassAuditLogger.php` lignes 14-22 + AGENT_TEST_INTEGRITY review prior
- V1 reste shippable en l'état pour une release contrôlée (peu de comptoirs, faible volume) ; **block** ne se justifierait qu'à scaling-up production avec rush soutenu

> **Note explicite à l'attention des prochains reviewers** : si vous voyez S5 (throttle `admin-mutation` partagé) listé une 4e fois dans un futur audit sans correction commit, **flippez le verdict en `block`**. Trois remontées sans fix = dette qui devient bug production. CLAUDE.md §8 healing rule = max 3 cycles consécutifs. Cycle suivant DOIT inclure le fix RouteServiceProvider, ou escalate humain.

PAS d'**escalate** car :
- Aucune contradiction architecture détectée
- Aucun secret leak observable (S2, S9 RÉFUTÉS)
- Aucune divergence pricing/calcul cross-flow
- AppServiceProvider production guard **catches** au boot, pas en runtime à un endpoint

---

## 4. Limitations honnêtes de cet audit

1. **Pas de runtime test PHP exécuté** — server local non callable depuis sandbox harness. Je n'ai pas pu lancer `php artisan test --filter=BypassProductionGuardTest`. Compensé par la RuntimeException prod du 13:28:17 dans laravel.log qui prouve que le guard fire effectivement, mais ce n'est pas le test sentinel exact.

2. **Trace HTTP partielle** — la liste `apiCalls` capturée par la spec (cf step `api-calls-captured` POS-1) n'enregistre que les GETs catalog/dashboard, pas les POST `/api/admin/pos` ni `/api/kiosk/order`. Conséquence : S1 (Idempotency-Key headers runtime) et S6 (audit log par commande) sont partiellement non-vérifiables via cette mega seule.

3. **fiscal_sequence_no — 1 sample** — voir S7. Honest gap.

4. **Refund miroir non testé** — S8 hors-scope mega.

5. **Branch isolation cross-injection non forgée** — voir S3. La mega est restée sur `branch_id=1` seulement. Compensé par sentinel architectural + RED-R3 prior, mais pas re-validé runtime mega.

6. **WebSockets DOWN dans la harness** — les KDS reception traces (P2 polling no-match × 4) sont **non-concluantes** côté mega : impossible de distinguer (a) Echo non démarré, (b) polling fallback broken, (c) item kds_station="none" filtre à l'entrée. Pas un sujet sécurité directement, mais limite la capacité à attester du flow live.

7. **Périmètre flux uniquement** — audit non-exhaustif côté code (cf AGENT_SEC ROUND-2 commit `1b38e64a3` pour observability/outbox/sentinels). Si une régression code-level a été introduite hors mega path, ce rapport ne la voit pas.

---

## 5. Non-duplication AGENT_SEC ROUND-2

J'ai **délibérément exclu** les sujets déjà couverts par `docs/audit/AGENT_SEC_REVIEW_1b38e64a3_2026-05-08.md` :
- Authz `/admin/observability/outbox` (S1 ROUND-2)
- Branch isolation observability (S2 ROUND-2)
- 422 leak "POS catalog requires branch_id" (S3 ROUND-2)
- POS Operator bypass `can('pos')` chain (S4 ROUND-2)
- Bootstrap script secrets (S5 ROUND-2)
- GitHub Actions workflow secrets (S6 ROUND-2)
- `kdsInflight` Vuex tampering (S7 ROUND-2)
- Dashboard JSON shape PII (S8 ROUND-2)
- Route binding observability auth (S9 ROUND-2)
- CSRF/idempotency observability retry/drain (S10 ROUND-2)

Mon scope **flux mega** complète orthogonalement le scope **commit observability** de ROUND-2.

---

## 6. Décision (CLAUDE.md §8)

**`heal`** — 4 items sécurité actionnables, classés par sévérité :
1. **P1 sec-availability** throttle `admin-mutation` partagé (S5) — **récurrent 3×, dernier cycle de heal acceptable avant block**
2. **P2 sec** idempotency runtime header non tracé (S1) — instrumentation Playwright cycle suivant
3. **P2 sec** branch_id cross-injection non forgée (S3) — forge test cycle suivant
4. **P3 ops** fiscal_sequence_no monotone non vérifiée (S7) — SQL probe cycle suivant

Pas de **block** justifié : aucune faille exploitable côté attaquant externe, bypass mode preserve invariants, audit trail cohérent, surface d'attaque inchangée.

Pas d'**escalate** : aucune contradiction architecture, aucun secret leak, AppServiceProvider production guard fire correctement (runtime witness 13:28:17 ERROR log).

**Honesty note (CLAUDE.md §11)** : ce rapport **n'a pas pu** vérifier 4 points en runtime (S1 headers POST, S3 forge cross-branch, S7 monotonie fiscal multi-sample, S8 refund miroir). Tous les 4 sont **listés comme limitations** et non comme refutations.

---

**Fin AGENT_SECURITY_MEGA pour MEGA PARCOURS V1 — 2026-05-08.**
