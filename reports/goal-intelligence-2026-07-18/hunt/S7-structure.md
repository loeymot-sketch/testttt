# S7 — Chasse STRUCTURE + CONTRATS CROSS-SYSTÈME (read-only)

Date : 2026-07-18 · HEAD branche `pos/category-first-caisse-2026-06-23` · Scope : défauts ENTRE les systèmes (events/outbox, enums, config, doubles SSOT, BranchScope, code mort, rate-limit). **0 fichier modifié.**

Méthode : lecture directe `routes/api.php` (1599 l.), 13 listeners `Persist*ToOutbox`, `DispatchDomainEventsJob`, `EventContract` PHP + `eventContract.js`, enums PHP↔JS (5 paires), `BranchScopeCoverageSentinelTest`, `config/kiosk|pricing|fiscal|menu|kds.php`, + 2 sous-agents (config-coherence, double-SSOT) dont CHAQUE claim retenu a été re-vérifié file:line par le principal. 1 claim sous-agent réfuté, 1 hypothèse propre réfutée (listées en « écartés »).

---

## FINDINGS CONFIRMÉS

### [P2] app/Models/FrontendOrder.php:21-34 vs app/Models/Order.php:136-215 — deux modèles Eloquent sur la MÊME table `orders` avec des hooks de boot divergents
- `Order::boot()` porte 3 protections : (a) `restoring` → RuntimeException (soft-delete one-way, NF525, Order.php:150-163) ; (b) hook `source_surface=delivery` ; (c) hook `saving` d'horodatage cuisine `accepted_at/preparing_at/prepared_at` (Order.php:178-214, fix ULTRA-AUDIT 2026-07-04 « posé au niveau MODÈLE pour qu'AUCUN chemin ne l'oublie »).
- `FrontendOrder::booted()` ne mirrore QUE (b). **Or le flux dominant écrit via FrontendOrder** : création borne/web `FrontendOrder::create` (FrontendOrderService.php:284, OrderService.php:1451), auto-accept Plan-B `$this->frontendOrder->status = ACCEPT; ->save()` (FrontendOrderService.php:633-635), finalize kiosk lock (l.773, 1206, 1318).
- Conséquences : (1) une commande borne Plan-B atteint ACCEPT **sans** `accepted_at` — le stamp n'arrive qu'au premier save via le modèle `Order` (bump KDS) → `accepted_at` = heure du bump, durée accept→preparing écrasée à ~0, la métrique `actual_prep_seconds` que le fix 2026-07-04 devait réparer reste faussée sur le flux borne ; (2) `FrontendOrder::withTrashed()->restore()` n'est PAS bloqué → l'invariant NF525 « soft-delete one-way » est contournable par tout futur code passant par ce modèle (aucun appelant aujourd'hui — latent).
- Repro : créer une commande borne espèces (auto-accept) → `SELECT accepted_at FROM orders WHERE id=…` → NULL jusqu'au bump KDS.

### [P2] app/Services/Fiscal/ZReportService.php:861-864 vs config/menu.php:73 + app/Services/Pricing/PricingService.php:244 — TVA du frais de livraison au Z : config figée vs table `taxes` éditable
- `deliveryVatRate()` = `Config::get('menu.settings.tax_rate', 10.0)` (hardcode 10.00 dans config/menu.php:73), versé dans le même bucket par-taux que les articles (`normalizeTaxRateKey`, ZReportService.php:871-874).
- Les articles prennent leur taux de la DB (`Tax` rows, PricingService.php:244, figé dans `order_items.tax_rate`) — table modifiable par `TaxService::update()` (app/Services/TaxService.php:67).
- Divergence : owner passe la VAT DB 10→5,5 % → articles ventilés à 5,5 %, frais livraison toujours extrait à 10 % dans un bucket fantôme → ventilation TVA du Z (NF525-adjacent) fausse. Aligné aujourd'hui (10==10) → latent mais sans garde.
- (Vérifié par principal sur claim sous-agent SSOT.)

### [P2] config/kiosk.php:288 — `queue_start_number` absent de la branche de retour `$requireForm=true` (l.156-198)
- Le fichier a DEUX `return` (l.156 si `KIOSK_REQUIRE_MACHINE_LOGIN=true`, l.253 sinon). Diff des clés : la branche requireForm n'a NI `queue_start_number` NI `auto_login_secret`.
- Lecteurs : OrderService.php:3381 et FrontendOrderService.php:1096 → `config('kiosk.queue_start_number', 1)` → avec requireForm actif, la numérotation quotidienne repart à **A0001 au lieu de A0032** (mandat owner 2026-07-07).
- C'est la récurrence EXACTE de la classe P0 RED-08 déjà healée pour `payment_route_all_to_counter` (« flag MUST appear in BOTH return branches », config/kiosk.php:296-300). Latent si requireForm=false aujourd'hui.

### [P2] resources/views/master.blade.php:238 — `env('KIOSK_USE_POS_WIZARD')` cru dans un Blade → flag inerte sous `config:cache`
- `kioskUsePosWizard: @json((bool) env('KIOSK_USE_POS_WIZARD', false))` ; aucune clé config/*.php ne le porte. Sous `config:cache` (exécuté par TOUS les scripts deploy : tools/deploy-lecayenne.sh:110, deploy-now-2026-07-15.sh:44…), `.env` n'est plus chargé → env() = null → toujours false.
- Consommé par resources/js/router/modules/kioskRoutes.js:181-183 pour router `KioskPosWizardComponent` vs `KioskWizardComponent`. Le flag jumeau `staffOnlyMode` (l.237) a été migré vers `config('features.staff_only_mode')` ; celui-ci non — dette auto-documentée dans admin-pos-v4.blade.php (« backlog ST-W2-ENV-1-LEGACY »).
- Impact : `KIOSK_USE_POS_WIZARD=true` en prod = zéro effet, silencieux. (Claim sous-agent config, re-vérifié.)

### [P2] app/Providers/AppServiceProvider.php:362 — boot guard Stripe lit `env()` → catch-22 sous config:cache
- `if ($stripeEnabled && empty(env('STRIPE_WEBHOOK_SECRET'))) throw` : sous config:cache, env() = null MÊME si le secret est dans `.env` — et le message d'erreur (l.363-371) ordonne précisément de faire `config:cache`, ce qui entretient le refus de boot. La clé config existe (config/services.php `webhook_secret`) mais le guard ne la lit pas ; son propre commentaire (l.345) prétend « Config-driven ».
- Latent V1 (gateway Stripe désactivée par mandat) ; brique le boot le jour où Stripe est réactivé sur une prod cachée. (Claim sous-agent config, re-vérifié.)

### [P2] app/Services/Loyalty/PosRedemptionService.php:233 vs app/Services/Pricing/PricingService.php:250,350 (+11 autres) — défauts inline contradictoires sur `pricing.tax_inclusive_prices` (invariant NF525 TTC)
- PosRedemptionService : fallback `true` ; PricingService/OrderService/FrontendOrderService/PricingPreviewService/OrderQuoteService : fallback `false` (13 sites). La clé existe (config/pricing.php:31, défaut true) donc dormant — mais le fichier config lui-même (l.25-29) documente que `false` réintroduit le bug « 3 € affiché → 3,60 € facturé ». Une typo/suppression de clé ferait diverger le calcul TVA ENTRE services sur le même order. (Claim sous-agent config, re-vérifié sur 3 sites.)

---

### [P3] app/Listeners/PersistSettingsUpdatedToOutbox.php:19,69 — événement `SettingsUpdated` broadcast SANS AUCUN consommateur front
- Le docblock promet « POS/Kiosk … receive a SettingsUpdated broadcast and refresh their frontend/setting payload live ». `grep SettingsUpdated resources/js` → **vide**. Idem `OrderPaymentStatusChanged` (PersistOrderPaymentStatusChangedToOutbox.php:61) et `BranchStatusChanged` (broadcast outbox, l'effet réel — révocation tokens — est un listener PHP).
- Réalité : changement devise/TVA/site admin → une row outbox PAR branche active + broadcast soketi à vide ; POS/kiosk restent stales jusqu'à reload/poll. Contrat documenté ≠ comportement ; poids mort dans `domain_events`.

### [P3] app/Domain/Events/EventContract.php:34-50,57-77 vs resources/js/services/eventContract.js:18-29 — les deux BROADCAST_MAP « Keep in sync » ont divergé + validation payload lacunaire
- PHP contient `OrderItemAdded`/`OrderCancelled`/`StockLow` (aucun producteur outbox — noter le piège `OrderCanceled` 1 L côté classe PHP vs `OrderCancelled` 2 L côté map) ; JS contient `KdsOrderRecalled` absent du map PHP ; `ItemExtraAvailabilityChanged`/`ItemVariationAvailabilityChanged` sont absents des DEUX maps alors qu'ils ont producteurs réels (Persist*ToOutbox.php:56-57) ET consommateur réel (StockRuptureDashboardComponent.vue:479-481).
- `REQUIRED_PAYLOAD_KEYS` ne couvre pas `menu.extra/variation_availability_changed`, `kds.order_recalled`, `settings.updated`, `branch.status_changed` → `assertPayloadValid` passe-plat (l.159-161) → un payload tronqué de ces 5 types broadcasterait silencieusement — la classe de défaut exacte que le durcissement C1 (commentaire l.66-73) dit prévenir.

### [P3] resources/js/components/frontend/kiosk/KioskAppComponent.vue:561 + resources/js/composables/useCatalogChangeNotifier.js:423 — listener `.ComposerProfileChanged` mort (jamais sur le fil)
- Le backend n'émet ComposerProfileChanged que BRIDGÉ en `broadcast_as='CatalogChanged'` (EventServiceProvider.php:293-296 → PersistCatalogChangedToOutbox ; census des 13 `broadcast_as` : aucun 'ComposerProfileChanged'). Inoffensif car les deux surfaces écoutent aussi CatalogChanged avec le même handler — mais contrat trompeur pour tout futur handler différencié.

### [P3] app/Services/ItemAttributeService.php:69-81 — update d'un ItemAttribute n'invalide RIEN
- Aucun event/bump/Cache::forget, contrairement à TOUTES les autres mutations catalogue (EventServiceProvider.php:273-292). Or la borne projette `min_select/max_select/allow_repeat/status` (KioskMenuService.php:487-493) → contraintes wizard stales jusqu'au TTL 60 s, `snapshot_version` jamais bumpé (pas de signal temps réel). (Claim sous-agent SSOT, re-vérifié.)

### [P3] scripts/deploy/PRODUCTION_GO_LIVE_CHECKLIST.md:23 + scripts/deploy/README_DEPLOY.md:237 — `php artisan route:cache` prescrit mais IMPOSSIBLE
- routes/api.php contient ≥6 routes Closure (l.149 /login, 804 counter-collect/pending, 871 web-orders/pending, 886 confirm, 930 cancel, 950 collect-kiosk-cash) + web.php (l.60 carnet, 98 /dl) → `route:cache` lève `Unable to prepare route for serialization`. Les scripts tools/deploy-*.sh, cohérents, ne font QUE config:cache — mais la checklist go-live échoue à cette étape (confusion opérateur garantie le jour J).

### [P3] routes/api.php:1198 — mutation d'état via GET
- `Route::get('/change-status/{message}/{customer}', [MessageController::class, 'changeStatus'])` : mutation sur GET (pré-fetchable, pas de couche idempotency). Impact faible (statut message), incohérent avec le reste du fichier (mutations = POST+idempotency).

### [P3] Config « poids morts / non câblés » (groupé)
- `kiosk.rush_windows` lu (KioskMenuService.php:189) mais défini NULLE PART → heuristique `is_rush` = no-op permanent.
- `kiosk.stale_web_collect_ttl_minutes` lu (CleanupStalePendingKioskOrders.php:187, défaut 360) jamais défini → TTL lane web non réglable, asymétrique avec kiosk/phone (env-backed).
- `kiosk.menu_cache_ttl` lu (MenuController.php:66) jamais défini → TTL 60 s non réglable.
- `kds.show_fallback_banner` (config/kds.php:57) défini mais jamais injecté — le front lit `window.FK_KDS_SHOW_FALLBACK_BANNER` que master.blade.php ne pose pas (seul FK_KDS_V2_DEFAULT_ENABLED l.287 existe) → `KDS_SHOW_FALLBACK_BANNER=false` sans effet (fail-safe : bannière visible ; différé auto-documenté).
- RouteServiceProvider.php:76 : fallback `order_rate_limit` = 5 (valeur retirée pour cause de 429, RATE-FIX 2026-07-10) vs config 30 — piège si la clé saute.
- app/Http/Resources/SettingResource.php:95 : `'demo' => env('DEMO')` cru (null sous config:cache) vs `config('app.demo_mode')` canonique — deux vérités « demo ».

### [P3 · INCERTAIN] app/Services/Menu/AvailabilityService.php:517-541 vs app/Services/Stock/ChoiceAvailabilityResolver.php:287-289 — dashboard ruptures aveugle aux extras `on_hand<=0` sans flag manuel
- Le snapshot admin ne liste que `manual_unavailable_reason IS NOT NULL` ; le resolver menu 86 aussi sur `on_hand<=0`. Un extra stock-tracké à 0 sans flag = grisé borne, absent du dashboard. INCERTAIN : en V1 les extras sont flag-only (l'état n'est atteignable qu'avec vrai comptage). (Sous-agent SSOT, périmètre V1 vérifié par lui.)

### [P3] resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:1732 — `KDS_VISIBLE = [4, 7, 8]` hardcodé
- Unique occurrence de statuts magiques front (miroir commenté de KitchenReleaseRule) au lieu d'importer `orderStatusEnum`. Dérive silencieuse possible si la release-rule backend change.

---

## ÉCARTÉS (vérifiés puis rejetés)

1. **`kiosk.payment_route_all_to_counter` flag mort** — RÉFUTÉ (hypothèse du principal) : consommé via master.blade.php:182 (`paymentRouteAllToCounter` camelCase — d'où le grep raté) → KioskPaymentComponent.vue:344-352. Chaîne back→front intacte.
2. **/payment/{order}/pay IDOR non-auth** — `guardWebPaymentV1()` (Frontend/PaymentController.php:131-136) → 404 tant que `payment.web_payment_v1.enabled` = false (défaut). Latent by-design, gate propre.
3. **/api/frontend/loyalty/opt-in sans auth** — équivalent fonctionnel du `/loyalty/register` public assumé (throttle 5/min) ; le consent est lié au user créé par le même register, pas de mutation d'un tiers arbitraire.
4. **/api/health verbeux public** — `assertFullHealthIpAllowed()` (HealthController.php:16) : allowlist IP.
5. **Sentinelle BranchScope** — 23 modèles conformes, `DailyBookEntry` exempté documenté (sentinel l.66-69), aucun modèle en sous-dossier (blind spot depth(0) vide), `WizardProfileBranchScope` variante déclarée. Les 111 `withoutGlobalScope` n'ont pas été audités un-à-un (hors budget) ; celui de channels.php:47 est justifié (lookup pre-auth machine kiosk, documenté).
6. **KDS `_statusChangeAffectsKds` rate PENDING→CANCELED** — un PENDING n'est jamais sur le board (KitchenReleaseRule) + fallback refresh si payload absent (l.1738-1740).
7. **Enums PHP↔JS** — OrderStatus/PaymentStatus/Source/OrderType/PosPaymentMethod : valeurs identiques (comparaison directe des 5 paires). `Source` sans KIOSK : compensé par `source_surface`, bucketing analytics documenté (DashboardService.php:527-586).
8. **public/js/pos-wizard.js** — aucun statut/payment magique en comparaison (grep vide).
9. **Outbox ordering / rejeu** — 2 workers `high` peuvent réordonner, mais TOUS les consommateurs order-events refetchent (`fetchOrders`/`_debouncedRefresh`) au lieu d'appliquer le payload → pas d'écrasement stale. Claim/dedupe atomique lockForUpdate sain (DispatchDomainEventsJob:65-94) ; worker down → rows persistées + `outbox:retry-failed` + readiness 503 (déjà tracké SYNC_CONTRACT §7).
10. **Exclusions du brief** — base 8766, catch-all assets, Faker branch order, exemptions BranchScope documentées : non re-signalés.

## Synthèse chiffrée
6 × P2 (tous latents-mais-armés sauf FrontendOrder/horodatage qui est ACTIF sur le flux borne) · 9 × P3 · 2 réfutations documentées. Aucun P0/P1 actif sur le money-path : les invariants durs (SSOT prix, outbox durable, canal branch privé, sentinelle scope) tiennent — les défauts trouvés vivent dans les COUTURES : doubles modèles, doubles branches de config, contrats d'événements non tenus, docs de deploy divergentes.
