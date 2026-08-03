# VERIFY-15 — Observability & Performance

**Date :** 2026-04-20  **Mode :** AUDIT-ONLY (read-only)  **Origine task :** `tasks/verify-2026-04-20/15_VERIFY_OBSERVABILITY_PERF.md`  **Audit source :** `reports/review/AUDIT_POS_110_OBSERVABILITY_PERF_2026-04-19.md`

## 0. Méthodo

- Pass A (back) : `HealthController`, `config/logging.php`, `CorrelationIdMiddleware`, `HasCorrelationId`, listeners Outbox, services Fiscal/Z, services KDS / Kiosk / POS — eager-loading + monitoring.
- Pass B (front + perf) : build pipeline (`webpack.mix.js`, `package.json`), tailles bundles `public/js/*.js`, throttle `kiosk-menu` (`RouteServiceProvider`).
- Cross-check : audit `AUDIT_POS_110_OBSERVABILITY_PERF_2026-04-19.md` (F-OBS-001, F-PERF-001).
- Aucun fichier applicatif modifié. Seul livrable écrit : ce rapport.

## 1. Vérifications §5 — résultats

### V1 — `/health` couvre DB, cache, queue, broadcast

**Statut : GREEN**

`app/Http/Controllers/HealthController.php` expose trois endpoints :
- `live` → `200 OK` plain text (probe k8s liveness).
- `ready` → DB + Redis (probe readiness, `503` si dégradé).
- `full` → DB + Redis + Queue (`Queue::size('default')` + `'high'`) + Broadcast (driver actif vs `null`), avec gating IP via `HEALTH_IPS_ALLOWED` (config-driven, non `env()` runtime).

Contract test `tests/Feature/HealthControllerTest.php` couvre : schéma `subsystems` (`db`, `redis`, `queue`, `broadcast`), propagation `X-Correlation-ID` sur `live`, IP gating (403 hors whitelist), code `503` si `ready` dégradé.

Note : `broadcast` retourne `warning` (pas `error`) quand driver = `null` → ne fait pas chuter `status` global à `degraded`. Comportement assumé (mode dev sans Pusher).

### V2 — Logs critiques avec `branch_id`, `order_id`, `actor_id`, `correlation_id`

**Statut : WARN**

`app/Http/Middleware/CorrelationIdMiddleware.php` (groupes `web` + `api` dans `app/Http/Kernel.php`) attache via `Log::withContext` :
- `correlation_id` (header `X-Correlation-ID` ou UUID frais)
- `user_id` (`auth()->id()`)
- `branch_id` (`auth()->user()?->branch_id`)

Header `X-Correlation-ID` re-émis sur la response. `HasCorrelationId` trait (utilisé par `DispatchDomainEventsJob`) restaure le contexte dans les jobs queued (`restoreCorrelationContext()`).

**Manquants** :
- `order_id` n'est pas attaché au shared context. Les logs métier doivent le passer explicitement par `['order_id' => …]` dans chaque appel — pas de garantie systématique côté `OrderService` / `FrontendOrderService` / fiscal.
- `actor_id` n'existe pas comme champ distinct de `user_id` (acteur applicatif vs user authentifié — utile pour cron / system jobs).
- Listeners Outbox (`PersistOrderCreatedToOutbox`, `PersistOrderStatusChangedToOutbox`) injectent `correlation_id` dans la table `domain_events` mais pas dans `Log::withContext` au point d'invocation.

→ Corrélation disponible mais pas universelle. WARN, pas FAIL (audit `F-OBS-001` aligné).

### V3 — Eager loading sur listes POS / KDS

**Statut : GREEN**

- KDS list (`app/Services/KitchenDisplaySystemOrderService.php` ligne 53) : `Order::with('orderItems')`. `KDSOrderDetailsResource` complète au render via `$this->orderItems->loadMissing('orderItem')` → batch unique par collection, pas N+1.
- POS reorder (`app/Http/Controllers/Admin/PosOrderController.php` ligne 123) : `$order->load(['orderItems.item', 'orderItems.itemVariations', 'orderItems.itemExtras'])` — eager complet sur la chaîne items/variations/extras.
- Kiosk menu (`app/Services/Kiosk/KioskMenuService.php` lignes 62-71) : eager `variations`, `extras`, `allergens` avec sélection de colonnes ; `ItemBranchAvailability` chargé en un `whereIn` puis indexé par `keyBy('item_id')` — pas de N+1.
- Hypothèse audit H3 (POS list query N+1 sur items/variations) : non reproductible sur les chemins courants. `OrderResource` / `OrderDetailsResource` reposent sur les relations préchargées des call-sites (vérifié ci-dessus). Si un call-site externe paginate `Order` sans `with('orderItems')`, le risque persiste — ne pas détecter d'usage déclencheur dans cette passe.

### V4 — Bundle POS analysé

**Statut : WARN (vers FAIL)**

Build pipeline = **Laravel Mix / Webpack** (`webpack.mix.js`, `package.json` script `production` = `mix --production`). **Pas de Vite** → `vite build --report` non applicable. Aucun analyser webpack configuré (`webpack-bundle-analyzer` absent des deps).

Tailles statiques observées dans `public/js/` (build 2026-04-18 / 2026-04-20) :

| Fichier            | Taille | Cible  | Verdict          |
|--------------------|--------|--------|------------------|
| `app.js`           | 4.4 MB | 1.5 MB | DÉPASSE (×2.9)   |
| `kiosk.js`         | 513 KB | 1.5 MB | OK               |
| `pos-wizard.js`    | 280 KB | 1.5 MB | OK               |

`app.js` agrège `vue` + `vuex` + `vue-router` + `firebase` + `pusher-js` + `apexcharts` + `swiper` + `vue3-quill` + écrans admin/POS. Pas de code-splitting route-level visible côté Mix.

Caveat : taille observée non confirmée comme issue d'un build `production` minifié+gzip (mtime mixte ; pas de `.gz` à côté). Sans build prod fraîche traçable, on ne peut conclure à un FAIL ferme — la cible §5 V4 autorise WARN.

→ WARN documenté. Risque H4 (bundle POS > 1.5 MB) : non infirmé.

### V5 — Outbox lag observé (count + age max)

**Statut : FAIL (downgradé en WARN par charte §6 — voir 2.)**

État présent :
- `OutboxRescueCommand` (`foodking:outbox:rescue`) re-queue les `DomainEvent` `stale(2)` minutes avec `attempts < 5`.
- `OutboxRetryFailedCommand` existe.
- `DispatchDomainEventsJob` log les contract violations.

État absent :
- Aucun endpoint HTTP exposant `outbox.pending_count` / `outbox.max_age_seconds` (pas dans `HealthController.full`, pas de `/metrics` Prometheus, pas de gauge Cloudwatch / Datadog visible).
- Pas de scheduled task qui logge l'âge max des rows `dispatched_at IS NULL` (cron Kernel non scanné en détail mais aucun signal trouvé).
- Pas d'alerte sur `attempts >= 5` (rows en quarantaine permanente).

Conséquence opérationnelle : un blocage broadcast (Pusher down, contract violation persistante) reste invisible côté monitoring tant qu'un humain n'inspecte pas la table.

### V6 — Métriques fiscal (Z generation time, audit log write time)

**Statut : WARN**

`ZReportService` log `z_report.open` (ligne 84) et `z_report.close` (ligne 151) sur le canal `fiscal` (rétention 400 jours, conforme exigence NF525 §H.3.2). Payload close inclut totaux TTC/HT/TVA, counts, prefix signature.

**Manquants** :
- Aucun champ `duration_ms` / `aggregate_duration_ms` / `sign_duration_ms` dans le log close. Le coût d'agrégation (`aggregate()` + `lockForUpdate` + signature HMAC) n'est pas mesuré.
- `AuditLogService` (`app/Services/Fiscal/AuditLogService.php`) — pas de `microtime` autour des writes.
- Pas de métrique d'erreur (compteur `z_report.close.failed`) exploitable hors logs.

→ Logs présents (signal-to-noise OK), métriques temporelles absentes. WARN.

## 2. Critères d'acceptation §6 — application

| V  | Statut       | Décision §6                                 |
|----|--------------|---------------------------------------------|
| V1 | GREEN        | OK                                          |
| V2 | WARN         | corrélation présente → pas FAIL             |
| V3 | GREEN        | OK                                          |
| V4 | WARN         | autorisé partiel (§6 « WARN sur V4/V6 »)    |
| V5 | WARN         | (voir note critère ci-dessous)              |
| V6 | WARN         | autorisé partiel (§6)                       |

§6 : `FAIL si N+1 critique ou logs sans corrélation`. Aucune des deux conditions n'est remplie. V5 (outbox lag) n'est pas listé comme déclencheur FAIL → conservé en WARN avec recommandation P prioritaire.

## 3. Cross-check audit POS-110

- `F-OBS-001` (corrélation `X-Correlation-ID` non globale) : confirmé. Middleware OK mais propagation métier (order_id, actor_id) incomplète.
- `F-PERF-001` (pas de test charge 500+ ord/h, N+1 non profilé) : V3 lève le risque N+1 sur les chemins audités ; absence de bench charge persiste — hors scope V15 (viserait V16 tests/régressions).
- Audit H1 « `/health` ne vérifie pas DB+queue+broadcast » : **infirmé** par V1. État du code postérieur à l'audit du 04-19.
- Audit H5 « pas de monitoring Outbox lag » : **confirmé** (V5).

## 4. Risques résiduels

- **R1 — Bundle `app.js` 4.4 MB** : impact TTFB+TTI sur tablet POS bas de gamme. Cycle code-splitting + migration Vite ou `mix.extract` requis.
- **R2 — Outbox silencieux** : un domain event coincé en `attempts=5` ne déclenche aucune alerte ; perte d'évènements `OrderCreated` / `OrderStatusChanged` invisible côté ops.
- **R3 — Logs fiscal sans duration** : pas de baseline pour détecter dégradation perf NF525 (signature HMAC + agrégation) sous charge rush.
- **R4 — `actor_id` indistinct du `user_id`** : dans les flows system (jobs, scheduler), les logs n'identifient pas l'origine non-utilisateur.
- **R5 — `kiosk-menu` 60 rpm** : suffisant si menu cache (`KioskMenuService`) tient ; à monitorer côté logs throttle 429 sous campagnes promo.

## 5. Recommandations cycles P

| Cycle                          | Périmètre                                                                                              | Priorité |
|--------------------------------|--------------------------------------------------------------------------------------------------------|----------|
| `P11_LOGS_CORRELATION_ID`      | Étendre `Log::withContext` avec `order_id` + `actor_id` dans `OrderService`, `FrontendOrderService`, listeners Outbox, jobs fiscal | P1       |
| `P_OUTBOX_OBSERVABILITY`       | Endpoint `/api/health/outbox` (count pending, max age, count failed >= 5) + alerte ; gauge logguée par cron        | P1       |
| `P_BUNDLE_POS_SPLIT`           | Audit Webpack (analyzer), code-splitting POS / Admin / Kiosk, lazy-load apexcharts/swiper/quill, cible 1.5 MB POS shell | P2       |
| `P12_POS_QUERY_OPTIM` (option) | Profiler systématique listes Order paginées hors KDS/POS reorder pour confirmer absence N+1 résiduel    | P3       |
| `P_FISCAL_TIMING_METRICS`      | Ajouter `duration_ms` aux logs `z_report.close` + `audit_log.write` ; baseline P95                       | P3       |

## 6. Conclusion

**GLOBAL : WARN**

V1 et V3 OK. V2, V4, V5, V6 partiels — aucun déclencheur FAIL §6 (corrélation présente, pas de N+1 critique détecté). Cycles correctifs prioritaires : `P11_LOGS_CORRELATION_ID`, `P_OUTBOX_OBSERVABILITY`, `P_BUNDLE_POS_SPLIT`.
