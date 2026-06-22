# MEGA CONTEXTE — Version A central sync / VA-SYS (assemblage disque)

> Généré par `node scripts/assemble-mega-api-context-va-sys.mjs`. **Pas** d’appel Graphiti : extraits JSONL locaux ci-dessous.

---

## 0) Mémoire projet (secours Graphiti — index + extraits JSONL)

### memory/INDEX.md

```
# FoodKing — Index de la mémoire d'intelligence

> Table des matières navigable des épisodes Graphiti.
> Chaque fichier = un domaine. Chaque ligne JSONL = un fact atomique.
> **2026-04-26** — `caisse_v1_masterplay_codex_close_2026-04-26.jsonl` : clôture masterplay GPT/Codex, M-04A bloqué Option B, prochaine gate W2 / release (voir `reports/audit/CLAUDE_AUDIT_BRIEF_CODEX_MASTERPLAY_CLOSE_2026-04-26.md`).
> **2026-04-26** — `caisse_v1_wave2_option_b_2026-04-26.jsonl` : 36 missions `CV1-LOT-*` préparées (Option B) ; 4 lots bloqués (K-05, P-06, P-10, P-13) ; prochain run `CV1-LOT-D01-CLIENT-TOTAL-INVARIANT` — `missions/W2_LOT_CODEX_RUN_ORDER_OPTION_B_2026-04-26.md` + `reports/audit/W2_LOT_MISSION_PREP_OPTION_B_2026-04-26.md`.
> **2026-04-26** — Train A V1 release prep : Caisse V1 / POS+Kiosk est l'`ACTIVE_PRIMARY`, W10 passe en lecture seule, et la politique mémoire devient ciblée : tracker uniquement les décisions durables V1, pas les outputs bruités. Sources : `docs/gates/GATE_PHASE2_TRAIN_A_HUMAN_DECISIONS_2026-04-26.md`, `docs/PHASE_A_CLOSED.md`, `reports/audit/PHASE2_PLAN_TRAINS_REWORKED_2026-04-27.md`.

| # | Fichier | Domaine | Épisodes | Pour qui |
|---|---------|---------|----------|----------|
| 01 | `01_project_overview.jsonl` | Vision, business, stack, surfaces | ~10 | Tout LLM/dev qui découvre le projet |
| 02 | `02_architecture_invariants.jsonl` | Invariants techniques, frozen zones, multi-tenant | ~16 | Avant toute modification backend |
| 03 | `03_domain_events_sync.jsonl` | Outbox, DispatchableAfterCommit, Echo, dédup | ~14 | Travail sur sync borne↔POS↔KDS |
| 04 | `04_pricing_ssot.jsonl` | Single Source of Truth pricing, formules, edge cases | ~10 | Avant toute modif PricingService |
| 05 | `05_fiscal_nf525.jsonl` | Conformité fiscale FR, chain hash, Z, audit_log | ~12 | Conformité, compta, fiscaliste |
| 06 | `06_kiosk_features.jsonl` | Wizard tacos, multi-quantité, allergens, offline, a11y | ~14 | Dev frontend Kiosk |
| 07 | `07_pos_features.jsonl` | Park orders, multi-tender, refund, floorplan, ESC/POS, NFC | ~16 | Dev frontend POS |
| 08 | `08_kds_features.jsonl` | Bump/recall, station filter, timers, item availability | ~10 | Dev KDS |
| 09 | `09_tasks_history.jsonl` | 22 tasks V14 + Vague D + cross-wave findings (G-1, G-2, G-3, SYNC-001/002) | 24 | Audit, planning, debug régression |
| 10 | `10_tests_coverage.jsonl` | Sentinels Vitest 707 + PHPUnit 825, par domaine | ~12 | Avant tout refactor |
| 11 | `11_production_plan.jsonl` | Sync-first rollout phases 0-5, monitoring, V2 plan | ~12 | Préparation prod, ops |
| 12 | `12_decisions_log.jsonl` | ADRs, gates passed/blocked, choix d'architecture | 25 | Comprendre POURQUOI |
| 13 | `13_agents_roles.jsonl` | Multi-agents (Claude/GPT-5.4/Composer), orchestration | ~20 | Reprendre orchestration |
| 14 | `14_conventions.jsonl` | Naming, scope, safety, paths critiques, hooks | ~10 | Tout dev |

> Voir aussi : `memory/JSONL_SCHEMA.md` (schéma strict), `memory/POLICIES.md` (clear_graph + duplicates).

## Politique épisodes Train A / V1

- Tracker les décisions durables : gates humaines, choix release, invariants corrigés, blocages D-M13, décisions paiement V1, i18n FR, hardware UAT.
- Ne pas tracker les sorties transitoires : logs volumineux, outputs de tests complets, fichiers temporaires de runner, brouillons non validés.
- Ne pas supprimer ou déplacer `memory/episodes/*.jsonl` sans gate humain explicite.
- Si une décision doit survivre à la session, l'écrire d'abord dans `docs/gates/`, `docs/PHASE_A_CLOSED.md`, ou un rapport d'audit stable, puis seulement l'indexer ici.

## Recherche typique par cas d'usage

### "Reprendre le projet sans contexte (nouveau LLM)"
```
search_memory_facts query="FoodKing project overview surfaces stack"
search_nodes query="frozen zone OrderService PaymentService"
```

### "Que fait composition_snapshot et quand l'utiliser ?"
```
search_memory_facts query="composition_snapshot order_items NF525 immutable"
```

### "Pourquoi DispatchableAfterCommit ?"
```
search_memory_facts query="DispatchableAfterCommit transaction rollback gate C9"
```

### "Comment sont synchronisés borne POS KDS ?"
```
search_memory_facts query="DomainEvent outbox correlation_id dédup KDS ItemAvailabilityChanged"
```

### "Quels tests garantissent quoi ?"
```
search_memory_facts query="sentinel test parked recall variation availability"
search_memory_facts query="PosKioskPricingParityTest"
```

### "Comment passer en production ?"
```
search_memory_facts query="production rollout phase sync monitoring G14-B compta DPO"
```

### "Quelle décision pour quel problème historique ?"
```
search_memory_facts query="decision NF525 chain hash branch isolation"
```

## Conventions des fields

- **name** : titre court orienté facts (~80 chars max)
- **episode_body** : `text` ou JSON échappé selon `source`
- **source** :
  - `text` → narratif factuel
  - `json` → structures (matrices d'événements, tableaux d'état)
  - `message` → décisions de type "X a décidé Y parce que Z"
- **source_description** : path(s) source ou rapport(s) d'origine, séparés par ` + `
- **group_id** : toujours `"foodking"` (override env `GRAPHITI_GROUP_ID` si besoin)

```

### Dernières lignes — memory/episodes/02_architecture_invariants.jsonl

```
{"name": "Frozen zones — fichiers backend critiques interdits aux modifications non-justifiées", "source": "json", "source_description": ".cursor/rules/safety.mdc + .cursor/hooks/safety-check.sh + tasks/phase9-sync/LOCK_*", "episode_body": "{\"frozen_files\":[\"app/Services/Orders/OrderService.php\",\"app/Services/Payments/PaymentService.php\",\"app/Services/Pricing/PricingService.php\",\"app/Services/FrontendOrderService.php\",\"resources/js/components/admin/pos/PaymentComponent.vue\",\"resources/js/components/admin/pos/ItemComponent.vue\"],\"reason\":\"zones validées NF525 + sync lifecycle critiques. Toute modif requiert (a) justification écrite dans la task, (b) safety-check.sh hook OK, (c) review humaine si changement non-trivial.\",\"hook\":\".cursor/hooks/safety-check.sh — check à chaque pre-commit, fail si modif frozen sans LOCK_* approuvé\",\"override_pattern\":\"surgical patch documenté dans tasks/phase9-sync/LOCK_B_POS_*.md\"}"}
{"name": "BranchScope — global scope Eloquent multi-tenant", "source": "text", "source_description": "app/Models/Concerns/HasBranchScope.php + app/Scopes/BranchScope.php", "episode_body": "Tout modèle métier (Order, OrderItem, Payment, Item, AuditLog, DomainEvent, ParkedOrder, etc.) DOIT déclarer le trait HasBranchScope qui applique automatiquement un global scope filtrant where('branch_id', auth_user_active_branch_id). Les jobs queue qui s'exécutent sans user (CLI, schedule) DOIVENT explicitement passer par withoutGlobalScope(BranchScope::class) ET filtrer manuellement par branch_id. Sentinel test : tests/Feature/BranchIsolationTest.php vérifie qu'un user de branche A ne voit jamais de row de branche B."}
{"name": "Idempotency obligatoire sur les endpoints d'écriture", "source": "text", "source_description": "reports/audit-orchestration/REPORT_TASK07_IDEMPOTENCY_BRANCH_2026-04-20.md + app/Http/Middleware/Idempotent.php", "episode_body": "Toute route POST/PUT/PATCH côté Kiosk et POS DOIT exiger un header Idempotency-Key (UUID v4 généré côté client, persisté en localStorage jusqu'à confirmation 200). Middleware Idempotent : (a) hash payload + clé → table idempotency_keys, (b) si hit dans dernières 24h retourne la réponse mémorisée, (c) sinon process et stocke. Évite les doublons de paiement/commande en cas de double-tap, retry réseau, retry queue offline. Sentinel : tests/Feature/IdempotencyTest.php."}
{"name": "Composition snapshot — immuabilité fiscale T07", "source": "text", "source_description": "app/Services/Orders/OrderItemCompositionSnapshot.php + database/migrations/*_add_composition_snapshot_to_order_items + reports/execution/RUN_V14_T07_*", "episode_body": "Lors de la création d'un OrderItem, OrderItemCompositionSnapshot::hydrate() écrit dans la colonne composition_snapshot un JSON figé contenant : item_name, item_price, variations (array de {attribute_name, variation_name, price_modifier, quantity}), extras (array de {name, price, quantity}). Ce snapshot est IMMUTABLE après création (NF525) : si on renomme/désactive un item ou une variation après une commande, la réimpression ticket affichera toujours les noms/prix d'origine. C'est ce snapshot qui est consommé par OrderItemResource pour exposition API et par ReceiptComponent.vue (helper normalizeReceiptVariations / normalizeReceiptExtras) pour l'affichage."}
{"name": "Allergens snapshot — immuabilité fiscale T05", "source": "text", "source_description": "app/Services/Orders/OrderItemAllergenSnapshot.php + database/migrations/*_add_allergens_snapshot_to_order_items + database/migrations/2026_04_20_131600_backfill_fr_codes_in_order_items_allergens_snapshot.php", "episode_body": "À la création d'un OrderItem, OrderItemAllergenSnapshot::hydrate() écrit dans allergens_snapshot un JSON array de codes FR ('lait', 'gluten', 'fruits_a_coque'...) issus de l'union (a) item_allergens du base item + (b) item_extra_allergens des extras choisis (fix consolidé après échec OrderAllergenSnapshotComposedTest). Migration 2026_04_20_131600 a backfillé les rows historiques avec les codes FR (avant : codes EN). Ce snapshot est consommé par les tickets cuisine et l'export fiscal NF525."}
{"name": "DispatchableAfterCommit trait — atomicité événementielle (gate C9)", "source": "text", "source_description": "app/Events/Concerns/DispatchableAfterCommit.php + tests/Feature/DispatchAfterCommitTest.php + reports/execution/SYNTHESE_FINALE_REMEDIATION_2026-04-20.md", "episode_body": "Trait appliqué à OrderCreated, OrderStatusChanged, ItemAvailabilityChanged. Override la méthode statique dispatch() : si DB::transactionLevel() > 0, l'événement est différé via DB::afterCommit() ; si la transaction rollback, l'événement n'est JAMAIS dispatché. Empêche que des consommateurs (KDS, POS) réagissent à un état transient ensuite annulé. Résout le gate C9 et fait passer les 3 tests DispatchAfterCommitTest. Conséquence : OutboxTest::test_domain_event_not_persisted_on_rollback assert maintenant assertDatabaseCount('domain_events', 0) car aucune row n'est créée si rollback."}
{"name": "Pattern Outbox — DomainEvent table + DispatchDomainEventsJob", "source": "text", "source_description": "app/Models/DomainEvent.php + app/Jobs/DispatchDomainEventsJob.php + app/Listeners/Persist*ToOutbox.php", "episode_body": "Table domain_events sert d'outbox transactionnel : chaque listener Persist*ToOutbox crée une row {type, payload (JSON), branch_id, correlation_id (UUID), status='pending', created_at} dans la même transaction que l'action métier. DispatchDomainEventsJob (lancé par Scheduler toutes les minutes ou trigger immédiat via Bus::dispatch) lit les rows pending par branche, broadcast sur le channel privé Pusher, marque status='dispatched'. En cas d'échec broadcast, status='failed' + retry exponentiel (jusqu'à 5 essais). Garantit at-least-once + ordering par order_id grâce à clé de dispatch."}
{"name": "Correlation ID — tracking événementiel bout-en-bout", "source": "text", "source_description": "app/Listeners/Persist*ToOutbox.php + resources/js/services/eventContract.js", "episode_body": "Chaque DomainEvent porte un correlation_id (UUID v4) généré à la création de l'event Laravel. Ce correlation_id est : (a) loggé en backend à chaque étape (création, dispatch, broadcast), (b) inclus dans le payload broadcast Pusher, (c) côté frontend, isDuplicateCorrelation() (LRU Set capacity 512) vérifie qu'un même correlation_id n'est pas traité 2 fois (cas double broadcast par 2 workers). Permet aussi le tracing distribué : grep correlation_id dans laravel.log + sentry → reconstitue le trajet complet d'une commande."}
{"name": "Echo channels — convention de nommage et autorisations", "source": "json", "source_description": "routes/channels.php + resources/js/bootstrap.js", "episode_body": "{\"private_channels\":[\"private-branch.{branchId}.kds\",\"private-branch.{branchId}.pos\",\"private-branch.{branchId}.kiosk.{kioskId}\",\"private-branch.{branchId}.backoffice\"],\"authorization\":\"routes/channels.php — vérifie auth()->user()->branches->contains($branchId) AVANT autoriser join\",\"events_per_channel\":{\"kds\":[\"OrderCreated\",\"OrderStatusChanged\",\"ItemAvailabilityChanged (ajouté SYNC-001)\"],\"pos\":[\"OrderCreated\",\"OrderStatusChanged\",\"ItemAvailabilityChanged\",\"ParkedOrderCreated\"],\"kiosk\":[\"ItemAvailabilityChanged\",\"PromotionUpdated\"]}}"}
{"name": "Frozen zones frontend — composants Vue sensibles", "source": "json", "source_description": ".cursor/rules/safety.mdc + tasks/phase9-sync/LOCK_*", "episode_body": "{\"frozen_components\":[\"resources/js/components/admin/pos/PaymentComponent.vue (multi-tender critical)\",\"resources/js/components/admin/pos/ItemComponent.vue (composition order critical)\"],\"reason\":\"logique de pricing + composition consommée par 3 surfaces, validée par PHPUnit + Vitest sentinels\",\"override\":\"LOCK doc requise + safety-check.sh OK + sub-agent foodking-complex-implementer + relecture finale Claude\"}"}
{"name": "SSOT pricing — calcul total par PricingService backend uniquement", "source": "text", "source_description": "app/Services/Pricing/PricingService.php + reports/audit-orchestration/REPORT_TASK06_SSOT_PRICING_2026-04-20.md", "episode_body": "PricingService::compute(orderDraft) est l'unique source de vérité pour le calcul des totaux (subtotal, discount, tax, tender, refund, change). Le frontend (Kiosk, POS) calcule un APERÇU local pour UX réactive mais soumet TOUJOURS la commande au backend qui recalcule le total et REJETTE (HTTP 422) si l'aperçu frontend ne correspond pas au calcul autoritaire. Évite : décisions tarifaires depuis le client (sécurité), divergence entre 3 surfaces. Sentinels : tests/Feature/PosKioskPricingParityTest.php + tests/js/posKioskVariationParity.spec.js."}
{"name": "Audit log chain hash — chaînage cryptographique NF525", "source": "text", "source_description": "app/Services/Fiscal/AuditLogService.php + reports/review/AUDIT_POS_110_FISCAL_NF525_2026-04-19.md", "episode_body": "Chaque écriture audit_log calcule chain_hash = sha256(previous_chain_hash || json_payload). Le service AuditLogService::write() (1) acquiert un lock pessimiste par branche (cache lock 5s, F-C5), (2) lit le dernier chain_hash de la branche, (3) calcule le nouveau, (4) insère dans une transaction. Si une écriture passe sans branch_id, REJETÉE car cela polluerait le chain shared. La vérification de chain integrity se fait via Z report et CLI fiscal:verify-chain. Branche par branche, le hash forme une liste chaînée immutable."}
{"name": "Frozen zones — liste canonique services, pricing, ticket, migrations", "source": "json", "source_description": "méga-checklist H11 + .cursor/rules/safety.mdc", "episode_body": "{\"frozen_zones\":[\"app/Services/Orders/OrderService.php\",\"app/Services/FrontendOrderService.php\",\"app/Services/Payments/PaymentService.php\",\"app/Services/Pricing/\",\"app/Http/Controllers/Admin/Pos/PosReceiptPrintController.php\",\"database/migrations/\"]}"}
{"name": "Auth invariants — branch, kiosk token, admin branch_id=0", "source": "json", "source_description": "méga-checklist H11/A18 + conventions sécurité", "episode_body": "{\"auth_invariants\":[\"branch_id ne doit pas être pris depuis la request utilisateur sur le flux commande (SSOT serveur)\",\"token kiosk:order branch-scoped\",\"admin branch_id=0 peut voir toutes les branches mais actions sensibles requièrent journalisation explicite\"]}"}
{"name": "Sessions invariants — Sanctum, cache, Redis session", "source": "json", "source_description": "méga-checklist H11/A18 + config Laravel", "episode_body": "{\"sessions_invariants\":[\"Auth Sanctum + session Laravel côté app\",\"CACHE_DRIVER ne doit pas être array ni null en prod (fail-fast au boot)\",\"SESSION_DRIVER=redis recommandé en prod pour cohérence multi-instance\"]}"}
{"name": "NF525 invariants — insert-only audit, Z, séquence, archive J-1", "source": "json", "source_description": "méga-checklist H11 + services fiscaux", "episode_body": "{\"nf525_invariants\":[\"audit_logs INSERT-only chaîné avec HMAC\",\"rapport Z : signature HMAC\",\"fiscal_sequence_no monotone par contexte fiscal\",\"archive J-1 : verify+build atomique sous Cache::lock\"]}"}
{"name":"VA-SYS-09 runtime sync protocol invariant","source":"json","source_description":"docs/sync/API_VS_MCP_DECISION.md + docs/sync/CATALOG_COMPOSER_DATA_FLOW.md","episode_body":"{\"invariant\":\"FoodKing runtime surfaces use Laravel API + branch-scoped WebSocket/outbox + fallback polling; MCP is not the production runtime bus.\",\"must_not_bypass\":[\"backend pricing SSOT\",\"branch authorization\",\"fiscal sequence rules\",\"domain_events outbox\",\"order/payment state machines\"],\"allowed_mcp_usage\":[\"Graphiti/dev memory\",\"agent audits\",\"admin tooling that calls the same APIs\"],\"reason\":\"POS/Kiosk/KDS/OSS devices must work without local MCP agents and must remain auditable/replayable through Laravel/outbox.\"}"}

```

### Dernières lignes — memory/episodes/03_domain_events_sync.jsonl

```
{"name": "Pipeline Outbox complet — schéma de bout en bout", "source": "json", "source_description": "reports/audit-orchestration/REPORT_TASK09_EVENTCONTRACT_OUTBOX_BROADCAST_2026-04-20.md + reports/audit-orchestration/RAPPORT_FINAL_PRODUCTION_ALL_GREEN_2026-04-20.md", "episode_body": "{\"steps\":[{\"step\":1,\"actor\":\"OrderService::create()\",\"action\":\"DB::transaction { create order, items, ... ; OrderCreated::dispatch($order) }\"},{\"step\":2,\"actor\":\"DispatchableAfterCommit trait\",\"action\":\"detect transactionLevel>0 → DB::afterCommit(function() { event(new OrderCreated($order)) })\"},{\"step\":3,\"actor\":\"PersistOrderCreatedToOutbox listener\",\"action\":\"insère row domain_events {type:'order.created', payload:OrderResource->toArray, branch_id, correlation_id:Str::uuid(), status:'pending'}\"},{\"step\":4,\"actor\":\"DispatchDomainEventsJob (ShouldQueue, un job par domain_event_id)\",\"action\":\"DomainEvent::find($id) ; décode channel JSON (ex. [\\\"private-branch.{branchId}\\\"]) ; EventContract::buildEnvelope + assertEnvelopeValid ; BroadcastManager::connection()->broadcast(channels, broadcast_as, envelope) ; forceFill dispatched_at. Pas de SELECT pending par branch dans ce job — granularité = une row outbox à la fois.\"},{\"step\":5,\"actor\":\"Echo client (KDS, POS, Kiosk)\",\"action\":\"Echo.private('private-branch.{branchId}').listen('.EventName', handler) — même préfixe private- que le channel stocké en outbox ; filtrage surface (KDS vs POS) côté composant (handlers), pas via un suffixe .kds dans le nom de canal. eventContract.parseEvent + isDuplicateCorrelation ; debounced refresh\"}],\"failure_modes\":{\"rollback\":\"ne crée pas la row outbox (atomicité)\",\"broadcast_fail\":\"status='failed' + retry exponentiel (max 5)\",\"double_broadcast\":\"correlation_id dédup côté client (LRU 512)\"}}"}
{"name": "Trois events critiques sync", "source": "json", "source_description": "app/Events/{OrderCreated,OrderStatusChanged,ItemAvailabilityChanged}.php + app/Listeners/Persist*ToOutbox.php", "episode_body": "{\"OrderCreated\":{\"emitter\":\"OrderService::create() (POS) + FrontendOrderService::create() (Kiosk)\",\"payload\":\"OrderDetailsResource (id, items[], totals, customer, branch_id)\",\"consumers\":[\"KDS (affiche nouvelle commande)\",\"POS (refresh queue)\",\"Backoffice dashboard\"]},\"OrderStatusChanged\":{\"emitter\":\"OrderService::transitionTo() (preparing→ready→served, etc.)\",\"payload\":\"{order_id, branch_id, old_status, new_status, by_user_id, at}\",\"consumers\":[\"KDS (bump/recall via UI mais aussi sync depuis POS)\",\"POS (timeline)\",\"Kiosk (si commande table assignée → notif)\"]},\"ItemAvailabilityChanged\":{\"emitter\":\"ItemAvailabilityService::set86() (POS bouton 86, ou seuil stock auto)\",\"payload\":\"{item_id, branch_id, is_available, variation_ids_unavailable[], at}\",\"consumers\":[\"Kiosk (greys out item)\",\"POS (badge 86)\",\"KDS (ajouté SYNC-001 — refresh pour ne pas préparer item indispo)\"]}}"}
{"name": "SYNC-001 — KDS écoute ItemAvailabilityChanged (hardening cross-cutting)", "source": "text", "source_description": "resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue + reports/audit-orchestration/RAPPORT_FINAL_PRODUCTION_ALL_GREEN_2026-04-20.md (SYNC-001)", "episode_body": "Avant : KDS ne s'abonnait qu'à OrderCreated/OrderStatusChanged. Risque : un item 86'd côté POS ne descendait pas sur KDS, le chef pouvait préparer un item indisponible et la commande échouait au paiement final. Après : KDS subscribeEcho() ajoute {broadcastAs:'ItemAvailabilityChanged', handler:()=>this._debouncedRefresh()}. Refresh debounced (300ms) pour éviter avalanche si plusieurs items 86'd en rafale. Sentinel : tests/js/kitchenDisplaySystemSync.spec.js (à renforcer pour V2)."}
{"name": "SYNC-002 — Dédup côté client par correlation_id", "source": "text", "source_description": "resources/js/services/eventContract.js + tests/js/eventContractDedupe.spec.js", "episode_body": "Risque identifié dans audit sync : si 2 workers DispatchDomainEventsJob tournent en parallèle (concurrence sur même row pending mal verrouillée) → broadcast 2 fois → double UI update (toast doublé, refresh cumulé, etc.). Mitigation : isDuplicateCorrelation(correlationId) — Set + ordered list LRU (capacity SEEN_CORRELATION_CAP=512), évincée FIFO. Intégré dans rawHandler de tous les onEvents listeners. Helper de test __resetCorrelationDedupe() exposé. 7 tests Vitest verts."}
{"name": "Echo client — eventContract.js architecture", "source": "text", "source_description": "resources/js/services/eventContract.js", "episode_body": "Module exporte : (1) parseEvent(rawEnvelope) → {type, payload, correlation_id, broadcastedAt, branch_id} ou null si invalide ; (2) validateEnvelope(envelope) — schema check minimal ; (3) onEvents(channel, bindings[]) — wrapper Echo qui pour chaque {broadcastAs, handler} attache .listen('.broadcastAs', rawHandler) où rawHandler = (a) parseEvent, (b) isDuplicateCorrelation check, (c) handler(parsed). Convention : tout composant Vue qui consomme des broadcasts utilise onEvents(), JAMAIS Echo.listen direct. Garantit consistance dédup + parsing."}
{"name": "DispatchDomainEventsJob — verrouillage et retries", "source": "text", "source_description": "app/Jobs/DispatchDomainEventsJob.php", "episode_body": "Un job est dispatché avec domain_event_id unique. handle() : DomainEvent::find($id) ; si absent ou déjà dispatched_at → return. Sinon increment attempts, refresh ; si channel + broadcast_as présents : decode channel JSON, buildEnvelope + assertEnvelopeValid (sinon PayloadMismatchException → row last_error), puis BroadcastManager::connection()->broadcast(...). forceFill dispatched_at. failed() : last_error + log warning. Retries Laravel : tries=5, backoff [1,5,30,300]s. La concurrence multi-workers se résout par la granularité row + idempotence côté client (SYNC-002), pas par un scan lockForUpdate par branch dans ce job."}
{"name": "Channels Pusher — autorisation et scoping", "source": "text", "source_description": "routes/channels.php + app/Listeners/Persist*ToOutbox.php + reports/audit-orchestration/REPORT_TASK08_BRANCH_ISOLATION_2026-04-20.md", "episode_body": "Outbox stocke des canaux JSON du type [\"private-branch.{branchId}\"] (listeners PersistOrder* / PersistItemAvailability*). Côté client Echo : Echo.private('branch.{branchId}') — Laravel mappe branch.* vers le channel private-branch.*. Authorization (routes/channels.php) : Broadcast::channel('branch.{branchId}', callback) — tokens kiosk:kiosk:order restreints à la branche de la KioskMachine ; staff branch_id>0 = propre branche uniquement ; admin branch_id=0 peut souscrire à toute branche. Pas de segment .{surface} dans le nom de canal PHP : le routage KDS vs POS se fait dans les composants (handlers d'événements), pas dans le nom Pusher. Si auth échoue → 403. tests/Feature/ChannelAuthorizationTest.php : isolation branche A vs B."}
{"name": "Lifecycle commande — états et transitions autorisées", "source": "json", "source_description": "app/Services/Orders/OrderStateMachine.php + tasks/phase9-sync/LOCK_B_POS_9_2_3_OrderService_2026-04-18.md", "episode_body": "{\"states\":[\"pending\",\"paid\",\"preparing\",\"ready\",\"served\",\"completed\",\"cancelled\",\"refunded\"],\"transitions_allowed\":{\"pending\":[\"paid\",\"cancelled\"],\"paid\":[\"preparing\",\"refunded\"],\"preparing\":[\"ready\",\"cancelled\"],\"ready\":[\"served\",\"recalled→preparing\"],\"served\":[\"completed\",\"refunded\"],\"completed\":[\"refunded\"],\"cancelled\":[],\"refunded\":[]},\"sync_events_emitted\":\"chaque transition → OrderStatusChanged dispatch (after-commit)\",\"frozen_zone\":true,\"sentinel\":\"tests/Feature/OrderStateMachineTest.php\"}"}
{"name": "Risques résiduels sync identifiés et mitigations", "source": "json", "source_description": "reports/audit-orchestration/RAPPORT_FINAL_PRODUCTION_ALL_GREEN_2026-04-20.md (section sync residual risks)", "episode_body": "{\"risk_R1\":{\"name\":\"Latence broadcast > 5s en pic\",\"impact\":\"KDS reçoit commande tardivement, allonge délai prep\",\"mitigation_in_place\":\"DispatchDomainEventsJob trigger immédiat via Bus::dispatch après afterCommit (pas que scheduler)\",\"residual\":\"si Pusher down, fallback poll 30s côté client (à confirmer V2)\"},\"risk_R2\":{\"name\":\"Désync horloges entre kiosk/POS et server\",\"impact\":\"timestamps incorrects sur tickets fiscaux\",\"mitigation\":\"backend always source timestamps (created_at server); frontend display only\",\"residual\":\"NF525 OK car horodatage serveur fait foi\"},\"risk_R3\":{\"name\":\"Worker queue down silencieux\",\"impact\":\"events s'accumulent en pending, KDS ne reçoit rien\",\"mitigation\":\"Horizon dashboard + alerte si pending > 100 ou > 5min\",\"residual\":\"monitoring renforcé phase 1 prod (14j)\"}}"}
{"name": "Réplay outbox — commandes Artisan utiles", "source": "json", "source_description": "app/Console/Commands/", "episode_body": "{\"replay_pending\":\"php artisan outbox:dispatch-pending --branch=ID — force dispatch immédiat de tous les pending d'une branche (debug)\",\"replay_failed\":\"php artisan outbox:retry-failed --branch=ID --max-age-minutes=60 — relance les status='failed' récents\",\"verify_chain\":\"php artisan fiscal:verify-chain --branch=ID — vérifie l'intégrité du chain_hash audit_log\",\"purge_old\":\"php artisan outbox:purge --older-than=30days — purge les domain_events status='dispatched' anciens (réduit la table)\",\"slo_evaluator\":\"php artisan slo:evaluate (job SloEvaluatorJob, observability T16) — calcule le SLA de dispatch (P50/P95)\"}"}
{"name": "Sentinels tests sync — couverture", "source": "json", "source_description": "tests/Feature/ + tests/js/", "episode_body": "{\"backend\":[\"OutboxTest.php (6 tests : pending insert, dispatched after commit, NOT persisted on rollback, replay idempotent)\",\"DispatchAfterCommitTest.php (6 tests : 3 events × dispatched/not-dispatched)\",\"BranchIsolationTest.php (couverture broadcasts + queries)\",\"ChannelAuthorizationTest.php\"],\"frontend\":[\"eventContractDedupe.spec.js (7 tests : isDuplicateCorrelation + parseEvent + validateEnvelope)\",\"kioskAnalytics.spec.js (events frontend tracking)\"],\"to_strengthen_v2\":[\"E2E qui simule double-broadcast et asserte single UI update\",\"Test KDS reçoit ItemAvailabilityChanged et grise vraiment l'item dans la queue\"]}"}
{"name": "ItemAvailabilityChanged — émission et flow", "source": "text", "source_description": "app/Services/Items/ItemAvailabilityService.php + app/Http/Controllers/Frontend/KioskEventController.php", "episode_body": "Émetteurs possibles : (1) POS bouton '86 cet item' → ItemAvailabilityService::set86(itemId, branchId, variationIds=[]), (2) seuil stock auto (job qui scrute stock_levels), (3) admin backoffice toggle. Tous passent par le même service qui : (a) update item.is_available ou item_variations.is_available, (b) clear cache produits par branche, (c) ItemAvailabilityChanged::dispatch (after-commit). Contrôleur Kiosk KioskEventController expose /api/kiosk/items/availability pour pull initial au boot."}
{"name": "Toast et UI feedback sur events sync", "source": "text", "source_description": "resources/js/components/admin/pos/PosComponent.vue + KitchenDisplaySystemComponent.vue", "episode_body": "Convention UX : tout broadcast significatif déclenche un toast non-bloquant 3-5s (ex: 'Nouvelle commande #123 reçue', '86 sur Tacos Poulet'). Le toast est UNIQUE par correlation_id (grâce à dédup) → pas de doublon visuel même si Pusher renvoie. Implémentation : composable useToast() qui collecte la liste actives. KDS : toast + son cuisine (KDS bump sound configurable par branche). POS : toast + clignotement icône notif."}
{"name": "Audit synchro — outils MCP / scripts pour debug", "source": "json", "source_description": "reports/audit-orchestration/REPORT_TASK16_OBSERVABILITY_K9_2026-04-20.md + reports/execution/RUN_T16B_OBSERVABILITY_2026-04-20.md", "episode_body": "{\"datadog\":\"si MCP datadog activé : query tags:foodking,branch_id:X type:broadcast pour voir latence end-to-end\",\"laravel_log_grep\":\"grep correlation_id storage/logs/laravel.log → trace bout-en-bout\",\"sentry\":\"breadcrumbs incluent correlation_id (configuré phase observability)\",\"horizon\":\"horizon dashboard /horizon → DispatchDomainEventsJob runs + failures\",\"sql_debug\":\"SELECT type,status,COUNT(*) FROM domain_events WHERE branch_id=X AND created_at > NOW()-INTERVAL 1 HOUR GROUP BY type,status\"}"}
{"name":"VA-SYS-08 outbox realtime local strong pass","source":"json","source_description":"reports/audit/CODEX_VA_SYS_08_REALTIME_OUTBOX_EXECUTION_2026-04-30.md","episode_body":"{\"verdict\":\"PASS_RUNTIME_LOCAL_STRONG\",\"proofs\":[\"OutboxProductionLikeSimulationTest 5 tests plus 3-run loop\",\"Outbox dir 14 tests\",\"EventContract 9 feature + 12 unit\",\"AfterCommit 14 + DispatchAfterCommit 8\",\"KioskRealtimeBroadcast 2\",\"SyncComprehensive 6\",\"Vitest realtime contracts 29\",\"Playwright C3 repeat local 2 + 4 pass\"],\"guarantees\":[\"broadcast failure does not set dispatched_at\",\"rescue and retry-failed are bounded\",\"CategoryUpdated fans out only to active private-branch channels\",\"contract violation stops before broadcaster\",\"Kiosk/POS to KDS/OSS/POS visible without manual reload locally\"],\"residuals\":[\"hardware/provider UAT deferred\",\"KDS local timing about 5.88s should be watched in staging\"]}"}

```

### Dernières lignes — memory/episodes/12_decisions_log.jsonl

```
{"ts":"2026-04-23T16:30:00Z","cycle":"sync_repair_v2","gate":"G-1","decision":"auto_full_and_partial_line_item","rationale":"refund partiel sans release = stock bloqué; NEW-05 GPT impose granularité line-item idempotente; invariant daily_consumed_qty exact","decided_by":"orchestrator_claude_opus","blocks":["1.B"]}
{"ts":"2026-04-23T16:30:00Z","cycle":"sync_repair_v2","gate":"G-2","decision":"in_place_with_css_flash","rationale":"reprint=anti-pattern; son=trop intrusif pour transfer; flash CSS 2s = signal sans friction","decided_by":"orchestrator_claude_opus","blocks":["1.D"]}
{"ts":"2026-04-23T16:30:00Z","cycle":"sync_repair_v2","gate":"G-3","decision":"fanout_per_branch_status_quo","rationale":"INV-01 confirme backend fait deja fanout correct; re-architecturer = 3x travail zero gain","decided_by":"orchestrator_claude_opus","blocks":["1.A"]}
{"ts":"2026-04-23T16:30:00Z","cycle":"sync_repair_v2","gate":"G-4","decision":"non_blocking_badge","rationale":"bloquant=friction file cuisine inacceptable en rush; silent=risque RGPD/sante; badge=pattern hospitality standard","decided_by":"orchestrator_claude_opus","blocks":["2.I"]}
{"ts":"2026-04-23T16:30:00Z","cycle":"sync_repair_v2","gate":"G-5","decision":"split_separate_lines","rationale":"securite alimentaire > densite board; grouper avec allergens differents = risque servir mauvais plat; non negociable","decided_by":"orchestrator_claude_opus","blocks":["2.I"]}
{"ts":"2026-04-23T16:30:00Z","cycle":"sync_repair_v2","gate":"frozen_zone_f21","decision":"approved_with_documented_gate","rationale":"patch chirurgical 8 lignes; zero changement signature; benefice = bloque money-state corruption; risque ne pas faire >> risque patch","decided_by":"orchestrator_claude_opus","blocks":["1.G"]}
{"ts":"2026-04-26T23:59:00+02:00","cycle":"phase2_train_a_v1_release","gate":"HG-DM13-MIGRATION-SIGNOFF","decision":"local_test_implementation_approved_prod_rollout_pending","rationale":"Human asked Codex to finish remaining Train A work with before/after audit. D-M13 is implemented locally with DB unique (branch_id, queue_number), no microtime fallback, symmetric OrderService/FrontendOrderService allocation, full PHP/Vitest/Playwright validation green. Production migration still requires duplicate preflight, backup, cutover window, and rollback readiness.","decided_by":"user_kossayelbenna8","implemented_by":"codex-extension","reports":["reports/audit/PHASE2_TRAIN_A_VALIDATION_REPORT_2026-04-26.md","missions/D-M13-QUEUE-NUMBER-DB-UNIQUE-2026-04-28/report.md","reports/audit/GPT_SELF_AUDIT_D-M13-QUEUE-NUMBER-DB-UNIQUE-2026-04-28.md"]}
{"ts":"2026-04-23T19:10:00Z","cycle":"sync_repair_v2","lot":"1.B.1","status":"completed","files":["database/migrations/2026_04_23_100000_add_release_tracking_to_order_items.php","app/Events/OrderCanceled.php","app/Events/RefundCreated.php","app/Listeners/ReleaseAvailabilityOnOrderCanceled.php","app/Listeners/ReleaseAvailabilityOnRefundCreated.php","app/Services/Menu/AvailabilityService.php","app/Providers/EventServiceProvider.php","tests/Feature/Availability/StockReleaseTest.php"],"tests":"5/5 stockrelease + 866 regression","decided_by":"orchestrator_claude_opus"}
{"ts":"2026-04-23T19:25:00Z","cycle":"sync_repair_v2","lot":"1.B.2","status":"completed","files":["app/Services/OrderService.php","app/Services/FrontendOrderService.php","app/Jobs/CleanupStalePendingKioskOrders.php"],"description":"Wire OrderCanceled dispatch on 4 cancel call-sites: POS admin cancel, customer self-cancel, frontend kiosk cancel, stale-pending cleanup job. Idempotent via released_qty ledger.","tests":"866 regression pass","decided_by":"orchestrator_claude_opus"}
{"ts":"2026-04-23T20:15:00Z","cycle":"sync_repair_v2","lot":"1.D","status":"completed","files":["app/Events/OrderTableChanged.php","app/Listeners/PersistOrderTableChangedToOutbox.php","app/Enums/EventType.php","app/Domain/Events/EventContract.php","app/Providers/EventServiceProvider.php","app/Services/DiningTableService.php","resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue","resources/js/services/eventContract.js","tests/Feature/Pos/FloorplanControllerTest.php","tests/Feature/EventContractTest.php"],"description":"F-02 OrderTableChanged event with after-commit dispatch, outbox persistence on private-branch.{id} channel, KDS in-place flash (gate G-2). 3 sentinel tests + EventContract V1 contract.","tests":"14 floorplan + 869 regression","decided_by":"orchestrator_claude_opus"}
{"ts":"2026-04-23T20:10:00Z","cycle":"sync_repair_v2","lot":"1.C","status":"completed","files":["app/Http/Controllers/Admin/KdsSyncController.php","app/Services/KdsSyncService.php","resources/js/services/KdsSyncService.js","resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue","resources/js/languages/en.json","resources/js/languages/fr.json","resources/js/languages/ar.json","routes/api.php","tests/Feature/Admin/KdsSyncControllerTest.php","tests/js/kdsSyncCadence.spec.js","tests/js/kdsVersionGate.spec.js","tests/js/kdsDedupeByIdVersion.spec.js","tests/js/kdsBackoffOn5xx.spec.js"],"description":"F-03 KDS adaptive polling fallback (GET /api/admin/kds-order/sync) with WS-state-driven cadence (Infinity when CONNECTED, 5s/10s with jitter when degraded/disconnected, 3s on high activity), per-order version-gate, FIFO dedupe map (cap 256), 5xx backoff doubling capped at 30s, AbortController-driven stop(), self-heal on network errors. Independent Claude Code audit returned PASS WITH WARNINGS; G1 (network self-heal), G2 (strict monotonicity test), G3 (status_changed_at TODO) all resolved before close.","tests":"8/8 KdsSyncControllerTest + 8/8 vitest (4 spec files) + 6/6 invariants","audit_report":"reports/audit/AUDIT_LOT_1C_KDS_ADAPTIVE_POLL_2026-04-23.md","decided_by":"orchestrator_claude_opus","implementer":"gpt_5_4_pro_via_codex_runner","auditor":"claude_code_cli_independent"}
{"ts":"2026-04-23T21:40:00Z","cycle":"sync_hardening_v3","lot":"NEW-01","status":"completed","files":["app/Jobs/DispatchDomainEventsJob.php","resources/js/services/eventContract.js","tests/Feature/Outbox/OutboxConcurrentWorkerDedupeTest.php","tests/js/correlationDedupePersistence.spec.js","tests/js/correlationDedupeCapacity.spec.js"],"description":"Outbox replay/dedupe consumer-side hardening. Backend: claim-then-broadcast-then-finalize-or-release pattern in DispatchDomainEventsJob (lockForUpdate inside short claim transaction → broadcast OUTSIDE any open transaction to honour commit_before_dispatch → success clears last_error / failure releases dispatched_at=null for retry). Frontend: LRU cap raised 512→2048, sessionStorage persistence with 10-min TTL, lazy eviction, debounced writes (250ms) + pagehide flush, graceful degrade with single warn on storage failure. GPT-5.5-high initially refused due to commit_before_dispatch concern (correct objection), accepted after orchestrator reformulated brief with claim-then-broadcast pattern.","tests":"9/9 OutboxConcurrentWorkerDedupeTest + 6/6 vitest (2 spec files: 4 persistence + 1 TTL + 1 capacity) + 6/6 OutboxTest regression + 6/6 EventContractTest regression + 6/6 invariants","audit_report":"reports/audit/AUDIT_LOT_NEW01_OUTBOX_DEDUPE_2026-04-23.md","audit_findings":"PASS WITH WARNINGS — G1 (failed() lost contract_violation prefix → fixed), G2 (DB::transaction throw path documented), G3 (SQLite lockForUpdate no-op documented + MySQL integration test backlog), G4 (debounce+pagehide added), G5/G6/G7 (info-level limitations documented in module header). 5 missing tests added (T-MISS-01..05).","decided_by":"orchestrator_claude_opus","implementer":"gpt_5_5_high_via_codex_runner","auditor":"claude_code_cli_independent"}
{"ts":"2026-04-23T22:00:00Z","cycle":"sync_hardening_v3","lot":"NEW-02","status":"completed","files":["resources/js/services/WebSocketService.js","resources/js/services/KdsSyncService.js","tests/js/wsReconnectStormDetection.spec.js","tests/js/wsReconnectStormCircuitBreaker.spec.js","tests/js/wsAuthAndStormCohabitation.spec.js","tests/js/kdsReactsToReconnectStorm.spec.js","tests/js/eventContractDedupe.spec.js"],"description":"Echo/Pusher reconnect-storm detection + AWS-canonical decorrelated-jitter circuit breaker. Sliding 30s window of disconnect timestamps, threshold=4, breaker delay 5-30s with prev*3 growth, single-open guard, self-reset on CONNECTED. KdsSyncService subscribes to new reconnect_storm event, runs forceSync() with 0-500ms client-side jitter (audit-2 G10) to spread fleet bursts. WebSocketService.on() now returns unsubscribe (audit-2 G7), bookkeeping moved before _emit(state_change) for reentrancy safety (audit-2 G2), timer-fire clears stale timestamps (audit-2 G6), SESSION_INVALID during cool-down cancels pending pusher.connect (audit-2 A1). F-12 auth-failure path (handleSubscriptionError, _pruneAuthFailures, _resetAuthFailures, session_invalid emission) preserved bit-for-bit and proven independent via cohabitation suite. Pre-existing eventContractDedupe.spec.js cap=512 updated to cap=2048 to track NEW-01 LRU bump.","tests":"18/18 NEW-02 specs (4 files) + 790/790 vitest full suite (108 files) + 21/21 phpunit Outbox/EventContract regression + 6/6 invariants","audit_report_t":"missions/T-AUDIT-NEW02/output_codex.json","audit_report_claude":"reports/audit/AUDIT_LOT_NEW02_RECONNECT_STORM_2026-04-23.md","audit_findings":"GPT audit (T): PASS_WITH_WARNINGS — G2 reentrancy / G6 stale timestamps / G7 listener leak / G10 KDS thundering-herd → all fixed. T-MISS-A..E (5 tests) → all added. Claude audit (terminal): PASS_WITH_WARNINGS — A1 (SESSION_INVALID during cool-down → fixed: timer guard + cancel on session-invalid entry) / A2 (onAuthError missing unsubscribe → fixed) / A3 (attempts_in_window over-count → fixed: snapshot before pusher.disconnect) / A4-A5 (intentional behavior, documented). T-NEW-1 (P1) added.","decided_by":"orchestrator_claude_opus","implementer":"gpt_5_5_high_via_codex_runner","auditor_t":"gpt_5_5_high_via_codex_runner","auditor_claude":"claude_code_cli_independent"}
{"ts":"2026-04-23T22:35:00Z","cycle":"sync_hardening_v3","lot":"NEW-03","status":"completed","files":["app/Jobs/DispatchDomainEventsJob.php","app/Jobs/SendFcmNotificationJob.php","app/Listeners/PersistOrderCreatedToOutbox.php","app/Listeners/PersistOrderStatusChangedToOutbox.php","app/Listeners/PersistOrderTableChangedToOutbox.php","app/Listeners/PersistItemAvailabilityChangedToOutbox.php","app/Traits/HasDomainEvents.php","app/Console/Commands/OutboxRetryFailedCommand.php","app/Console/Commands/OutboxRescueCommand.php","docs/operations/QUEUE_TOPOLOGY.md","tests/Feature/Queue/QueueRoutingTest.php","tests/Feature/Queue/DispatchDomainEventsFailedCallbackTest.php","tests/Feature/Queue/ShouldQueueJobsDeclareTriesTest.php"],"description":"Queue topology + retry SLO + observability. DispatchDomainEventsJob: $tries 5→6 (audit-T G2: previous tries=5 made the 300s trailing backoff entry unreachable; now 1+5+15+60+300+300=~6.4min covers Pusher restart SLA), $backoff=[1,5,15,60,300] confirmed, failed() refactored to compute persistedErrorMessage ONCE for DB+log (audit-T G3: contract_violation: prefix now in BOTH last_error column AND log context + new error_category field), Log::warning→error (terminal failure = pager-grade), structured payload (queue, connection, attempts, delay_since_creation_seconds, error_class), optional Sentry breadcrumb behind triple class_exists/function_exists guard. SendFcmNotificationJob: constructor onQueue('notifications') as SSOT (job class encodes its lane). 7 listener/command call sites cleaned of redundant ->onQueue('high') chains (audit-Claude B7: footgun if constructor changes). docs/operations/QUEUE_TOPOLOGY.md: SSOT 3-lane topology (high/notifications/default), Supervisor program-per-lane (no combined queue list in prod), --tries=0 clarification (audit-T G10), updated JSON example with last_error/error_category/attempts=6 (audit-Claude EXTRA-2). Stale comment in handle() phase 3b removed (audit-Claude EXTRA-1). Sentry-absent test narrowed with markTestSkipped + tighter error_handler scope (audit-Claude B4). New invariant test ShouldQueueJobsDeclareTriesTest (audit-Claude T-CLA-3 P1): every ShouldQueue job MUST declare $tries (guard against --tries=0 + unbounded job → infinite retries).","tests":"13/13 NEW-03 (8 routing + 4 failed_callback + 1 invariant) + 9/9 OutboxConcurrentWorkerDedupe regression + 31/31 broader Outbox/Queue/Persist/HasDomainEvents/OrderCreated regression + 6/6 invariants + 790/790 vitest","audit_report_t":"missions/T-AUDIT-NEW03/output_codex.json","audit_report_claude":"reports/audit/AUDIT_LOT_NEW03_QUEUE_SCALABILITY_2026-04-23.md","audit_findings":"Audit T (GPT-5.5 high): PASS_WITH_WARNINGS — G2 backoff window unreachable / G3 contract_violation prefix lost in log context / G10 --tries=0 doc ambiguous → all fixed. T-MISS-A,B,C,D (4 tests) added; T-MISS-E covered. Audit Claude (terminal): PASS_WITH_WARNINGS — B4 set_error_handler scope too broad / B7 7 redundant ->onQueue('high') chains (footgun) / EXTRA-1 stale comment / EXTRA-2 doc JSON outdated → all fixed. T-CLA-1,2,3 added (T-CLA-3 P1 = architectural ShouldQueue $tries invariant test).","decided_by":"orchestrator_claude_opus","implementer":"gpt_5_5_high_via_codex_runner_then_orchestrator_patches","auditor_t":"gpt_5_5_high_via_codex_runner","auditor_claude":"claude_code_cli_independent"}
{"ts":"2026-04-23T23:35:00Z","cycle":"sync_hardening_v3","lot":"NEW-04","status":"completed","files":["database/migrations/2026_04_23_220000_create_sync_metrics_table.php","app/Services/Observability/SyncMetricsRecorder.php","app/Http/Requests/Admin/Observability/StoreClientMetricsRequest.php","app/Http/Controllers/Admin/Observability/SyncOverviewController.php","app/Providers/AppServiceProvider.php","app/Jobs/DispatchDomainEventsJob.php","resources/js/services/MetricsBatcher.js","resources/js/services/WebSocketService.js","resources/js/bootstrap.js","routes/api.php","tests/Feature/Observability/SyncMetricsRecorderTest.php","tests/Feature/Observability/SyncOverviewControllerTest.php","tests/Feature/Observability/EnsureCorrelationIdPropagatesToMetricsTest.php","tests/Feature/Observability/DispatchDomainEventsObservabilityIntegrationTest.php","tests/js/metricsBatcherFlush.spec.js","tests/js/metricsBatcherEvents.spec.js"],"description":"Observability surface: sync_metrics table (3 indexed + 2 composite), SyncMetricsRecorder singleton (non-blocking try/catch around DB::insert + correlation_id fallback to X-Correlation-ID header), GET /api/admin/observability/sync-overview (p50/p95/p99 dispatch-latency, ws.auth_failures_count, kds.sync_fallback_p50, recent_failures from domain_events, since= filter, branch_id scoping with Spatie permission gate), POST /api/admin/observability/client-metrics (whitelisted metric_type Rule::in, max:200 batch, throttle 60/min). MetricsBatcher.js (FIFO buffer 200, retry-preserving flush via unshift on 5xx, lifecycle start/stop/destroy, subscribes to ws.connected/disconnected/reconnect_storm/auth_error + kds.sync_fallback_change). DispatchDomainEventsJob.handle() success branch records outbox.dispatch_latency_ms via app(SyncMetricsRecorder)->recordEventDispatched in non-blocking try/catch. WebSocketService emits observability_metric ws.auth_failure on subscription error. bootstrap.js axios interceptor captures X-Correlation-ID header → window.__correlationId + localStorage so MetricsBatcher.readCorrelationId() can echo same id end-to-end.","tests":"15/15 SyncOverviewControllerTest + 6/6 SyncMetricsRecorderTest + 1/1 EnsureCorrelationIdPropagates + 2/2 DispatchDomainEventsObservabilityIntegration + 8/8 metricsBatcherFlush + 3/3 metricsBatcherEvents = 35 NEW-04 tests passing; 798/798 vitest full suite + 9/9 Outbox + 13/13 Queue + 8/8 KdsSyncController regression + 6/6 invariants","audit_report_t":"missions/T-AUDIT-NEW04/output_codex.json","audit_report_claude":"reports/audit/AUDIT_LOT_NEW04_OBSERVABILITY_2026-04-23.md","audit_findings":"Audit T (GPT-5.5 high): FAIL — G2 critical (cross-branch data leak: GET /sync-overview did NOT enforce branch isolation, branch-scoped operator could read any ?branch_id= → fixed via resolveBranchScope() + permission:kitchen-display-system gate) / G3 (SELECT cap silently skewed percentiles → fixed: SELECT_LIMIT=50000 const + truncated flag + orderBy('id') for determinism) / G4 (ws.auth_failure emitted client-side but missing from ALLOWED_CLIENT_METRICS whitelist → fixed in both PHP and JS) / G9 (frontend correlation ID hydration unproven → fixed: axios response interceptor in bootstrap.js captures X-Correlation-ID echoed by CorrelationIdMiddleware). T-MISS-A,B,C,D,E (5 tests) added. Audit Claude (terminal): PASS_WITH_WARNINGS — A1 (NULL/0 branch_id silently promoted ANY user to global admin → fixed: hasRole('Admin') gate added in resolveBranchScope, returns 403 instead of empty result to surface misconfiguration deterministically) / A3 (POST /client-metrics had no permission gate, asymmetric with GET → fixed: middleware now applies to both 'index' and 'clientMetrics', consistent gating) / A5 (ws.auth_failure double-counting trap if server-side recordWebSocketAuthFailure() ever wired → fixed: extensive doc comment instructs future caller to add 'source' label, dead method preserved with explicit warning). T-CLA-1,2,3,4 added (T-CLA-1 P0 = Branch Manager 403 on GET; T-CLA-2 P1 = POS Operator cross-branch 403; T-CLA-3 P0 = null branch_id without Admin role 403; T-CLA-4 P2 = Branch Manager 403 on POST too).","decided_by":"orchestrator_claude_opus","implementer":"gpt_5_5_high_via_codex_runner_then_orchestrator_patches","auditor_t":"gpt_5_5_high_via_codex_runner","auditor_claude":"claude_code_cli_independent"}
{"ts":"2026-04-24T01:25:00Z","cycle":"mega_orchestration_2026-04-24","lot":"2.I","status":"completed","files":["app/Services/KitchenDisplaySystemOrderService.php","resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue","resources/js/helpers/kdsAllergens.js","resources/js/languages/fr.json","resources/js/languages/en.json","resources/js/languages/ar.json","tests/Feature/KDS/KdsAllergenAggregationSplitTest.php","tests/js/kdsAllergens.spec.js"],"description":"Lot 2.I G-4/G-5 closed: allergens_hash in KDS orderItems() groupBy; badge+modal on 4 columns; kdsAllergens.js helpers; i18n fr/en/ar. Post-audit: focus restore to trigger element, Tab trap in allergens modal (WCAG), inter-order PHP test + order_items/ numeric Vitest. Claude terminal audit: AUDIT_LOT_2I_2026-04-24.md (initial PASS_WITH_WARNINGS → a11y addressed).","tests":"811/811 vitest + 936 phpunit (8 skip) + 6/6 invariants","audit_report":"reports/audit/AUDIT_LOT_2I_2026-04-24.md","decided_by":"orchestrator","implementer":"cursor_composer","auditor":"claude_code_cli"}
{"ts":"2026-04-24T12:00:00Z","cycle":"mega_orchestration_2026-04-24","lot":"P2_P3_P4_batch","status":"completed","summary":"2.ABD POS idempotence tests+reçu toasts+kiosk TPE help; 2.FGH KDS per-user station filter+scheduler parked purge+kiosk payment single-flight; 2.CEJ KDS sound throttle+loyalty HTTP 25s timeout+floorplan release after paid dine-in POS (DiningTableService+OrderService)","evidence":{"check_invariants":"6/6","vitest":815,"phpunit":"939 total 8 skipped","memory_verify_episodes":182},"reports":["reports/audit/AUDIT_GLOBAL_MEGA_CONSOLIDATION_2026-04-24.md","reports/audit/TERMINAL_AUDIT_BRIEF_RAW_2026-04-24.txt"],"terminal_audit":"claude_code audit-brief exit 0","decided_by":"orchestrator_session","implementer":"cursor_composer","auditor_claude":"claude_code_cli audit-brief"}
{"ts":"2026-04-30T02:20:00+02:00","cycle":"version_a_system_finishing","decision":"runtime_sync_protocol_api_outbox_not_mcp","rationale":"POS, Kiosk, KDS and OSS must run without local agent MCP; Laravel API, branch-scoped auth, domain_events outbox, replay/retry and auditability remain the runtime contract. MCP can help agents/devtools but is not the production bus for caisse/borne.","implemented_by":"codex","reports":["docs/sync/CENTRAL_DATA_SYNC_RUNBOOK_2026-04-30.md","docs/sync/PRODUCT_CATALOG_STOCK_COMPOSER_SYNC_SPEC_2026-04-30.md","docs/sync/VERSION_A_SYNC_VALIDATION_MATRIX_2026-04-30.md","reports/audit/CODEX_VA_SYS_09_DOCS_RUNBOOK_MEMORY_2026-04-30.md"],"status":"documented"}

```

---

## 1) AGENTS.md

```
# FoodKing – Cursor Agent Operating Contract

## 0. Quick start contract — read this first

Commence ici. Ne lance aucune action tant que tu n'as pas lu les priorités P0.
Ce contrat fixe l'ordre minimal de lecture pour comprendre le repo en moins de 60 secondes.
Lis complètement ce qui est marqué obligatoire ; diffère seulement ce qui est explicitement classé P2.

### Reading priority

| Priority | What to read | Why |
| --- | --- | --- |
| P0 | `AGENTS.md §1 Parcours obligatoire` | Cadre impératif de travail, ordre de lecture, discipline de cycle. |
| P0 | `.cursor/ACTIVE_CYCLE.md` (continuation) | État courant du cycle actif, contexte vivant, reprise sans divergence. |
| P0 | `.cursor/rules/global.mdc` (auto-attaché — mentionné pour info) | Règles globales toujours applicables, même si déjà injectées par l'outil. |
| P0 | `plans/masterplay/MASTERPLAY_DISCIPLINE.md` + `plans/masterplay/MASTERPLAY_QUEUE.md` (Caisse V1 actif) | **Pendant la phase Caisse V1** : règles d'or de la boucle GPT + file d'exécution. Tout agent qui touche une mission `CV1-MXX-…` DOIT lire ces deux fichiers avant d'agir. |
| P1 | `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md` | Vocabulaire, architecture d'orchestration, invariants transverses. |
| P1 | `.cursor/commands/run-cycle.md` | Procédure exacte pour démarrer un cycle borné correctement. |
| P1 | `docs/orchestration/MEMORY_MATRIX.md` | Où chercher la mémoire utile sans relire tout le dépôt. |
| P1 | `.cursor/routing.md` | Routage des tâches, choix du bon canal, limites d'intervention. |
| P2 | `docs/orchestration/CODEX_API_DELEGATION.md` (quand EXECUTE complexe) | À lire si tu délègues ou exécutes du complexe (uniquement **CLI `codex`**, pas d’exécuteur HTTP dans le dépôt). |
| P2 | `.cursor/ACTIVE_CYCLE_ARCHIVE.md` (forensique humain) | Historique utile pour audit humain, pas requis au démarrage. |
| P2 | `docs/orchestration/EXPORT_CONFIG_CODEX_GPT_API_ET_AUDIT_PERSISTANCE.md` (nouveau poste) | Configuration et persistance utiles surtout lors d'un nouvel environnement. |

Règle simple : P0 avant toute action, P1 avant tout EXECUTE, P2 seulement à la demande du sujet.
Si un doute persiste après P0+P1, arrête-toi et relis ; n'improvise pas.

### One-line bootstrap

```bash
npm run verify:boucle
bash scripts/agent-activity-log.sh tail 50
run-cycle <TASK_ID>
```

### Caisse V1 — Masterplay loop (actif)

Pendant la phase de finition Caisse V1 (POS + Kiosk + KDS + Centrale + Fiscal), l'orchestration passe par la **MASTERPLAY** :

```bash
# Lire d'abord (obligatoire)
cat plans/masterplay/MASTERPLAY_DISCIPLINE.md
cat plans/masterplay/MASTERPLAY_QUEUE.md
cat plans/masterplay/GO.md

# Lancer la boucle (Codex extension complexe + audit Claude terminal + audit Codex final)
bash scripts/run-masterplay.sh --with-audit --with-final
```

- **Plan parent** : `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md` (catalogue 22 missions M-XX, ancrages file:line)
- **Plan autoritaire DAG** : `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md`
- **Statut temps réel** : `reports/masterplay/status.json` + `reports/masterplay/run_*.log`
- Tout `TASK_ID` au format `CV1-MXX-…` est gouverné par la masterplay (allowlist, frozen, gates, REWORK max 5).
- Hors phase Caisse V1 : repasser au `run-cycle <TASK_ID>` standard.

**Anti-répétition (nouvel onglet / agent parallèle)** : copier d’abord le bloc de `docs/orchestration/SESSION_OPENING_ENFORCEMENT.md` — même discipline, moins d’oubli de `ACTIVE_CYCLE` / `run-cycle`.

Utilise `run-cycle <TASK_ID>` pour initier tout cycle borné.
Les deux autres commandes servent à vérifier l'état local et le journal d'activité avant d'exécuter.

### Quality-first, not token-cheap

Lis P0 et P1 intégralement, sans skim. Les économies de tokens ne se font jamais sur la substance des règles, seulement sur la répétition, le bruit et les relectures inutiles. En cas de tension entre vitesse et rigueur, applique la rigueur ; voir `.cursor/rules/global.mdc § Token Discipline`.

### If you're a new human contributor

Commence par `docs/orchestration/EXPORT_CONFIG_CODEX_GPT_API_ET_AUDIT_PERSISTANCE.md` pour préparer ton poste et comprendre le mode de persistance attendu.
Lis-le après P0, puis enchaîne sur P1 avant toute contribution effective.

---

## 1. Parcours obligatoire — **nouvelle conversation** **et** **continuation** (production, non négociable)

> **Objectif** : qu’**à chaque** session (premier message ou 500e message), l’exécutant sache **quel** chemin suivre — **sans** supposer un historique de chat. Tout est **dans le dépôt** ; l’histoire de conversation **n’est pas** la SSOT.

**Règle d’or** : *aucune* modification de code **produit** (hors `plans/`, `reports/`, `docs/gates/`, `missions/`, JSONL gouvernance) **dans le cadre d’un travail borné** sans **(a)** parcours ci-dessous **et** **(b)** cycle `run-cycle` + plan actif, sauf **exception** explicite humaine (notée dans le plan / gate).

| Étape | Action | Quand / pourquoi |
|-------|--------|------------------|
| **0. Continuation** | Lire **`.cursor/ACTIVE_CYCLE.md`**. | Si `PHASE` n’est **pas** vide et le cycle n’est **pas** archivé : **reprendre** ce `TASK_ID` / ce `PLAN_FILE` / ce `REPORT_FILE` — **ne pas** dupliquer un second cycle fantôme. Si humain confirme **nouveau** sujet : réinitialiser / nouveau `TASK_ID` selon `run-cycle` Step 0. |
| **1. Lecture initiale** | Lire **ce fichier** (`AGENTS.md`) **puis** **`docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`** (table §1 = ordre complet : routing, `run-cycle`, Graphiti, `MEMORY_MATRIX`, etc.). | **Avant** tout code ou plan non trivial. Même en « continuation » si le contexte a dérivé ou l’onglet a été rafraîchi. |
| **2. Cycle structuré (SSOT procédurale)** | Toute tâche avec **`TASK_ID`** : suivre **`.cursor/commands/run-cycle.md`** et la commande **`run-cycle <TASK_ID>`** (ou équivalent explicite dans le chat : enchaîner les **Steps 0 → 5** sans en sauter). | **Programme courant (quota-optimized)** : `PLAN GPT → PLAN_REVIEW GPT → EXECUTE GPT → VALIDATE → AUDIT GPT → [CLAUDE CRITIQUE SI NÉCESSAIRE] → [GATE \| CLOSE]`. Aucun « close » sans audit PASS documenté. |
| **3. Vérification d’environnement** | `npm run verify:boucle` (0 requête par défaut). **Si le terminal ne “voit” pas Codex alors que l’extension est connectée** : l’auth IDE n’alimente pas le CLI — lancer `npm run codex:doctor` (npm + `login status` + 1 requête). **Rapide :** `npm run codex:verify-pro`. **Complet :** `npm run verify:boucle:full`. Encart : `agents/codex-extension-instructions.md`. | Même en **Step 5** : l’`EXECUTE` (CLI `codex`) requiert le binaire + session Pro sur **ce** binaire. Voir `run-cycle` Step 0 item 8. |
| **4. Secrets & outils machine** | Binaire **`claude`** sur le **`PATH`** (Claude Code CLI) pour l’**AUDIT** PRIMARY en terminal. Binaire **`codex`** (CLI OpenAI) : *Sign in with ChatGPT* **Pro** — **pas** de clé API dans le dépôt. Résolution : `PATH` **ou** `node_modules/.bin/codex` après **`npm install`** (dépendance **`@openai/codex`**) **ou** `npm i -g @openai/codex`. **Ne pas** mélanger avec une clé *Platform* restreinte dans l’environnement (provoque des 401 *scopes* sur l’API Responses) — `npm run codex:audit-bleed` aide. (Option) MCP Graphiti selon `~/.cursor/mcp.json`. | Sans `claude` : noter dès le **plan** l’`AUDIT` fallback + `AUDIT_FALLBACK_REASON`. Sans binaire `codex` : pas d’`EXECUTE` complexe PRIMARY (sub-agent + `FALLBACK_REASON` ou `npm install` + auth Pro). |
| **5. Traces & mémoire (déjà dans ce fichier)** | **`EXECUTE_DELEGATION:`** avant VALIDATE ; **`AUDIT_CHANNEL:`** + **`TERMINAL_AUDIT_OK: 1`** si audit terminal OK ; `docs/orchestration/MEMORY_MATRIX.md` ; `scripts/agent-activity-log.sh` (tail / start / done). | Traçabilité = **même** qualité en prod sur N agents parallèles. |

**Ce n’est pas optionnel** pour travailler « en production FoodKing » : c’est le **contrat** d’onboarding. Les **règles** `.cursor/rules/*.mdc` (dont **`global.mdc` — alwaysApply**) et ce fichier **s’imposent** **à** tout modèle, **dans** toute conversation, dès l’ouverture du dépôt.

**Pour un humain / nouveau compte** : mêmes étapes ; la doc **`docs/orchestration/EXPORT_CONFIG_CODEX_GPT_API_ET_AUDIT_PERSISTANCE.md`** regroupe l’**export** config API + persistance des règles hors-chat.

## Engine
Cursor local agent. No cloud orchestration. No external framework.
Auto/Premium routing: disabled. Model selection is explicit per cycle.

## Global system primer (multi-agents, Graphiti, tokens — lecture clé)

Tout nouvel intervenant (session Cursor, sub-agent Task, CLI terminal, humain) qui touche **orchestration**, **mémoire**, ou **discipline de contexte** : lire **`docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`** après ce fichier. Y sont définis : ordre de lecture obligatoire, `codex-extension` GPT-5.5-pro/xhigh, fallback `foodking-complex-implementer`, fallback audit `foodking-planner-orchestrator`, terminal **`claude` / `codex`**, **mise à jour continue de Graphiti**, et la politique **« intelligence max — zéro optimisation négative »** (tokens : supprimer le gaspillage, pas la substance). Pour audits longs et robustesse **opération + agentique + mémoire** : **`docs/orchestration/MEGA_CHECKLIST_AUTONOMY_AND_MEMORY.md`** (180 tâches) et le narratif **`reports/audit/MEGA_AUDIT_SYSTEM_OPERATION_AGENTIC_2026-04-23.md`**.

**Discipline mémoire (qui écrit où, qui lit quoi, quand)** : **`docs/orchestration/MEMORY_MATRIX.md`** — matrice unique des **4 stores autorisés** (Code A · Graphiti+JSONL B · Missions C · Rapports D), table d'écriture par phase, ordre de lecture pour une nouvelle session, anti-patterns. Aucun nouveau store de mémoire ne peut être ajouté sans gate `docs/gates/GATE_MEMORY_*`. Décisions 2026-04-23 sur OpenSpace et claude-mem : **non intégrés**, justifications dans la matrice.

**Synchro multi-agents (cross-conv, cross-terminal)** : `.cursor/rules/cross-agent-sync.mdc` (alwaysApply) + `reports/AGENT_ACTIVITY_LOG.md` (append-only) + `scripts/agent-activity-log.sh` (`tail | start | done | collisions | active`). Au démarrage de session : `tail 50` (~500 tokens). Avant édition produit (Step 2 EXECUTE) : `start` (refus exit 2 si collision). À la clôture (Step 5 CLOSE) : `done`. Évite que deux agents (Cursor convs / `codex-extension` / `claude-terminal` / humain) modifient les mêmes fichiers à leur insu. **Doctrine étendue** (Graphiti = mémoire partagée, rôles Claude vs Codex, anti-patterns) : **`docs/orchestration/MULTI_AGENT_ORCHESTRATION.md`**.

## Workflow
PLAN GPT (codex) → PLAN_REVIEW GPT (codex) → EXECUTE GPT (codex) → VALIDATE → AUDIT GPT (codex) → [CLAUDE CRITICAL ESCALATION ONLY] → [HUMAN GATE | CLOSE]

No phase may be skipped. Default close condition is `AUDIT_VERDICT: PASS` from GPT path, with optional Claude escalation audit only for critical/blocked cases.

## Model Roles
| Model | Role | Channel (priorité **qualité maximale / zéro raccourci token**) |
|---|---|---|
| Claude | Escalade critique uniquement | Utiliser Claude seulement pour cas vraiment critiques: blocage logique majeur, gate ambigu non résoluble, conflit d'audits, ou arbitrage architecture multi-fichiers à haut risque. Le canal prioritaire reste GPT/Codex pour économiser les quotas Claude. |
| **GPT-5.5 / GPT-5.5-pro** | **PLAN + PLAN_REVIEW + EXECUTE + AUDIT** | **`codex-extension`** — `npm run codex:plan-review`, `npm run codex:complex`, `npm run codex:final-audit` (CLI `codex` + `codex exec`, **compte ChatGPT Pro**, modèle `gpt-5.5-pro` si dispo sinon `gpt-5.5`, `model_reasoning_effort=xhigh`). GPT devient le canal principal d'orchestration, implémentation et audit de routine. |
| GPT-5.5 (fallback) | Complex implementation (FALLBACK only) | **Sub-agent** `foodking-complex-implementer` (Task Cursor) — consomme l’**usage** des modèles de l’**abonnement Cursor**. **Uniquement** si `codex` / l’exécution `codex exec` a échoué (≥2 tentatives documentées) ou binaire indispo. |
| Composer | Validation/report only | Plus d’implémentation routine pendant les cycles de finition. Composer peut résumer, exécuter/rapporter des validations, mais toute correction produit repart en EXECUTE GPT. |

**Qui décide (mode actuel quota-optimized)** : **GPT/Codex** porte l’**autorité opérationnelle** sur planification, implémentation, auto-audit, et audit final de routine. **Claude** est mis en pause et appelé uniquement en **escalade critique** (ambiguïté structurelle, gate sensible, conflit technique majeur, analyse de risque à très forte complexité). Le **fait** code / test l’emporte sur la croyance.

**Principe unique (mode actuel) — à valider en prod sur chaque cycle :** **PLAN GPT → PLAN_REVIEW GPT → EXECUTE GPT → self-audit GPT → VALIDATE → AUDIT GPT**. Le repli vers Claude n’intervient qu'en escalade critique documentée (`CLAUDE_ESCALATION_REASON:`), avec portée minimale.

One PRIMARY_EXECUTION_MODEL per cycle. Roles are explicit and layered; review checkpoints do not authorize scope expansion.
Full routing policy: `.cursor/routing.md`. Naming: the **PRIMARY complex implementer** is the **FoodKing Codex Complex Implementer** (slug `codex-extension`); see `docs/orchestration/CODEX_API_DELEGATION.md`.

## Authoritative multi-agent bounded cycle (SSOT)

For **TASK_ID-driven** work in Cursor, this path is **authoritative** and overrides any conflicting step elsewhere in this document:

1. **Command:** `.cursor/commands/run-cycle.md` (invoke with a `TASK_ID`, e.g. `run-cycle SMOKE-001`).
2. **Cycle state:** `.cursor/ACTIVE_CYCLE.md` (`RUNNER_MODE: single-session`, `PHASE`, `PLAN_FILE`, `REPORT_FILE`, completion rows).
3. **Plan artifact:** `plans/PLAN_[TASK_ID]_[DATE].md` per `.cursor/context/plan-context.md` (from `plans/PLAN_TEMPLATE.md` when applicable).
4. **Phase instructions:** `.cursor/context/plan-context.md` (PLAN), `.cursor/context/execute-context.md` (EXECUTE), `.cursor/context/audit-context.md` (AUDIT); VALIDATE per `run-cycle.md` when `validate-context.md` is absent.

**EXECUTE delegation (GPT only; PRIMARY first, FALLBACK only on failure):**

- **Routine implementation disabled during finishing cycles** : no product edit via Composer / `foodking-routine-implementer`. Small edits still route through GPT to keep the same quality chain.
- **PRIMARY** : **`codex-extension`** — **FoodKing Codex Complex Implementer** (CLI `codex`, compte **ChatGPT Pro**, `gpt-5.5-pro`, `model_reasoning_effort=xhigh`). Procédure :
  1. Préparer `missions/{TASK_ID}/input.json` (+ optionnels : `graphiti_context.md` issu de `search_memory_facts(group_ids=["foodking"])`, `plan_excerpt.md`, `execute_brief.md`, `cycle_snapshot.md` — fusionnés par le script d’assemblage de prompt).
  2. Lancer `npm run codex:complex -- {TASK_ID}` (`bash scripts/codex-extension-execute.sh`) ; le wrapper passe explicitement `-m ${CODEX_EXT_MODEL_PRO:-gpt-5.5-pro}` et `model_reasoning_effort=${CODEX_EXT_REASONING_EFFORT:-xhigh}`. (Instructions custom : `agents/codex-extension-instructions.md`.) **Bootstrap** : `npm run codex:prepare -- {TASK_ID}`.
  3. Appliquer `missions/{TASK_ID}/output_codex.json` ; consommer l’**auto-audit** `reports/audit/GPT_SELF_AUDIT_{TASK_ID}.md` (généré par le wrapper).
  4. Tracer `EXECUTE_DELEGATION: codex-extension` dans `reports/post_execute_latest.log` et le `REPORT_FILE`.
- **Complexe — FALLBACK (uniquement si `codex exec` est HS après reprises, ou binaire manquant)** : `Task` → `foodking-complex-implementer` — tracer `EXECUTE_DELEGATION: foodking-complex-implementer (codex-extension-fallback)` + `FALLBACK_REASON:`. 

Référence complète : **`docs/orchestration/CODEX_API_DELEGATION.md`** (naming, fallback contract, audit handoff, token discipline, schéma boucle). Procédure cycle : `.cursor/commands/run-cycle.md`. La trace `EXECUTE_DELEGATION` dans le rapport est **obligatoire** pour passer en VALIDATE.

**Clôture vs. audit :** Après `VALIDATE`, l’**audit** **Claude** (terminal `foodking-claude-orchestrate.sh` en PRIMARY, fallback Cursor si quota/rate-limit/terminal HS) écrit `AUDIT_VERDICT: PASS|REWORK`, puis GPT écrit `GPT_FINAL_AUDIT_VERDICT: PASS|REWORK|ESCALATE`. **Pas** de `CLOSED` sans double PASS. Sur `REWORK`, boucle `replan (orchestration Claude) → missions + EXECUTE GPT → self-audit GPT → VALIDATE → re-audit Claude → GPT final`, avec `REMEDIATION_AUDIT_CYCLE` 1..5 — au 5e `REWORK` sans double PASS, **HUMAN_GATE** (détail : Step 5 de `run-cycle.md` et `auto-remediation.mdc`).

Sections below labeled **Legacy workflow** remain valid for **PR-centric / review-loop** habits but **do not replace** this SSOT for bounded cycles.

## Source of truth (extended)
- README.md
- docs/PROJECT_CONTINUITY_AND_VISION.md
- docs/ARCHITECTURE.md
- docs/API_MAP.md
- docs/AUTHZ_MATRIX.md
- docs/ORDER_FLOW.md
- docs/DEVICE_FLOW.md
- docs/BUSINESS_RULES.md
- docs/CORE_MODULES.md
- docs/DATABASE_SCHEMA_CORE.md
- docs/ERROR_HANDLING.md
- docs/SECURITY_NOTES.md
- docs/TEST_PLAN.md
- docs/MASSIVE_TEST_PLAN.md
- docs/SAAS_VISION.md
- docs/CONTRIBUTING_QA_BOTS.md
- workflows/qa-loop.md
- workflows/task-routing.md
- workflows/report-format.md
- workflows/task-status.md
- reports/README.md
- docs/orchestration/MEGA_CHECKLIST_AUTONOMY_AND_MEMORY.md
- docs/orchestration/WORKFLOW_AUDIT_GRAPHIQUE_2026-04-23.md
- docs/orchestration/TERMINAL_CLAUDE_GRAPHITI_ORCHESTRATION.md
- docs/orchestration/ROUTING_MATRIX.md

## Stop Conditions
Halt and generate a gate brief on any of:
- Gate trigger detected
- Scope expansion beyond declared boundary
- FoodKing invariant violation
- Two consecutive validation failures
- Planning ambiguity unresolvable from task context
- **`codex` / `codex exec` indisponible après reprises (auth, binaire, ou échec ≥2 sur la même tâche)** : basculer sur le fallback `Task → foodking-complex-implementer` et **noter explicitement** `EXECUTE_DELEGATION: foodking-complex-implementer (codex-extension-fallback)` + `FALLBACK_REASON:`.
- **Audit en terminal (PRIMARY) indisponible ou limité** (`claude` absent, **quota / rate limit / session Anthropic saturée**, auth, réseau — après **1 retry** documenté de `context` + `audit-brief` ou `audit`) : **continuer le cycle** — même checklist `audit-context.md` via Task **`foodking-planner-orchestrator`** (recommandé) ou session Cursor **Claude** ; **`AUDIT_CHANNEL: cursor-session`** + **`AUDIT_FALLBACK_REASON:`** (obligatoire) + optionnel **`AUDIT_SUBAGENT_FALLBACK: foodking-planner-orchestrator`**. Voir `docs/orchestration/AUDIT_TERMINAL_QUOTA_FALLBACK.md`. **Ne jamais** omettre la raison.

## FoodKing Non-Negotiables
- Backend is pricing SSOT — no frontend price logic
- `OrderStatus` enum is authoritative — no hardcoded strings
- `branch_id` = business data isolation — no cross-branch data bleed
- Dispatch strictly after DB commit
- `OrderService` / `FrontendOrderService` symmetry mandatory on any order change
- Frozen zones require gate clearance before any edit

## MCP

### Phase 1 — Filesystem
Filesystem MCP only for repo reads where applicable.

### Phase 2 — Graphiti (mémoire inter-cycles — **présent dans toutes les sessions où le serveur est enregistré**)

**Objectif** : décisions d’architecture, invariants, sync borne↔POS↔KDS, fiscal NF525, historique de cycles — récupérables sans relire des centaines de fichiers.

| Élément | Détail |
|--------|--------|
| **Enregistrement Cursor (obligatoire côté humain)** | Fusionner le bloc `graphiti` dans **`~/.cursor/mcp.json`** (Settings → MCP). Modèle : **`.cursor/mcp/graphiti.json.example`**. Le dépôt ne peut pas injecter un MCP automatiquement dans l’IDE. |
| **Règle agent (automatique dès que le MCP est chargé)** | Voir **`.cursor/rules/graphiti-memory.mdc`** (always-on) + **`global.mdc`** : avant toute tâche non triviale, appeler au moins **`search_memory_facts`** (et optionnellement **`search_memory_nodes`**) avec `group_ids=["foodking"]`. |
| **Après AUDIT / CLOSE** | Si `add_memory` est disponible : enregistrer les décisions durables (ADR, gate, invariant clarifié). |
| **Si Graphiti absent de la session** | **Ne pas bloquer** PLAN / EXECUTE : une ligne « Graphiti non chargé » + secours **`memory/INDEX.md`** + lecture ciblée des JSONL sous `memory/episodes/`. |
| **Server** | Zep Graphiti — wrapper local **`.cursor/mcp/start-graphiti-mcp.sh`** (voir exemple JSON). Clone typique : `/Users/1millnonstop/graphiti`. |
| **Backend** | Neo4j (ex. Aura) — credentials hors repo. |
| **Dépannage** | **`.cursor/mcp/GRAPHITI_TROUBLESHOOTING.md`** (LiteLLM, embeddings, redémarrage proxy). |
| **Group ID** | Toujours **`foodking`**. |
| **Ingestion / vérif locale** | `memory/ingest.py`, `memory/verify.py`, `bin/graphiti-ingest.sh` ; index des domaines **`memory/INDEX.md`**. |

**Intégration bounded cycle** : la commande **`.cursor/commands/run-cycle.md`** inclut l’appel Graphiti en **Step 0 item 5** (query avant PLAN).

### Phase 3 — Playwright MCP (tests E2E sur flows critiques FoodKing)

- Package : `@playwright/mcp@latest` (npx, pas d’install global)
- Browser : Chromium
- BASE_URL : `http://localhost:8000`
- Config : `.cursor/mcp/playwright.json`
- Flows couverts : POS Cash, POS Card, Kiosk, KDS, Auth refresh (F5)
- Déclencheur : plan déclare `playwright-mcp` | `playwright-critical-flow` | `playwright-full-e2e`
- Rapport : `reports/antigravity/latest.md`
- Règle : le Planner-Orchestrator seul décide si un cycle requiert E2E — jamais auto-déclenché.

### OpenCLI ( skill / CLI — **pas** un serveur MCP )

- Projet : **OpenCLI** ([`jackwener/opencli`](https://github.com/jackwener/opencli)) — `opencli browser` pour piloter **Chrome** (session connectée) ; rôle d’**agent + navigateur** souvent comparé à Playwright, mais **outil différent** (extension, pas le runner E2E du dépôt).
- **Dépôt** : compétence déclarative **`.cursor/skills/opencli-browser-automation/SKILL.md`** + install `npm install -g @jackwener/opencli`, extension *Chrome Web Store*, `opencli doctor`, et `npx skills add jackwener/opencli` (skills upstream optionnels).
- **Ne se substitue pas** à un plan qui impose **Playwright MCP** : les règles `playwright.mdc` / stratégie E2E priment pour les tests bornés.

---

## Terminal allies — Claude Code & OpenAI Codex (abonnements Pro)

Ces outils **complètent** le routage interne Cursor (`.cursor/routing.md` : PLAN Claude, PLAN_REVIEW/EXECUTE/GPT_FINAL_AUDIT via **CLI `codex` GPT-5.5-pro xhigh**, AUDIT Claude terminal avec fallback Cursor). **Aucun remplacement** des rôles du dépôt : ce sont des canaux de la boucle officielle.

### A — Anthropic **Claude Code** (audits / orchestration textuelle, abonnement Anthropic)

1. Dans Cursor : **Extensions** (`Ctrl+Shift+X` / `Cmd+Shift+X`) → chercher **Claude Code** (Anthropic) → **Installer**.
2. Ouvrir le terminal intégré Cursor.
3. Lancer une fois :

```bash
claude
```

4. Première exécution : se connecter avec le compte Anthropic (abonnement Pro/Max) — utilise les quotas du compte, **sans** clé API dans le dépôt.

**Appels non interactifs (audit / plan d’orchestration)** :

```bash
claude "Audite tout le code livré sur ce cycle et produis un plan d'orchestration des corrections restantes, en respectant les invariants FoodKing (AGENTS.md)."
```

**Wrapper recommandé (vérification `PATH` + `claude -p` + `--add-dir` vers la racine du dépôt)** — depuis la racine Git :

```bash
bash scripts/foodking-claude-orchestrate.sh check     # s'assure que `claude` (Claude Code) est installé
bash scripts/foodking-claude-orchestrate.sh smoketest # 1 requête API min. — valide abonnement / auth (retourne TERMINAL_OK)
bash scripts/foodking-claude-orchestrate.sh context   # écrit reports/audit/_TERMINAL_CONTEXT_BRIEF.md (cycle + JSONL + INDEX)
bash scripts/foodking-claude-orchestrate.sh audit-brief  # claude -p : lit ce fichier d'abord = tokens maîtrisés + alimentation mémoire
bash scripts/foodking-claude-orchestrate.sh audit     # audit / plan d'orchestration non interactif (prompt FoodKing)
bash scripts/foodking-claude-orchestrate.sh repl      # session interactive (équivalent : `claude` seul)
# Modèle terminal pour tous les `claude -p` du script : **Opus 4.7** + **effort high** par défaut (`--model claude-opus-4-7 --effort high`). Surcharge : `FOODKING_CLAUDE_TERMINAL_MODEL`, `FOODKING_CLAUDE_TERMINAL_EFFORT` (vide = pas de --effort).
```

Lecture : **`docs/orchestration/TERMINAL_CLAUDE_GRAPHITI_ORCHESTRATION.md`** (Cursor vs sub-agent vs terminal, Graphiti, économie de contexte).

**Après chaque supplémentation (ADR, JSONL, fin de lot)** — ordre pour alimenter la base **sans** gaspiller l’abonnement :

1. Mettre la décision / invariant dans le bon `memory/episodes/*.jsonl` (voir `memory/INDEX.md` + `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md` §4.2).
2. `bash scripts/after-execute-memory.sh` — rafraîchit le manifeste SHA, vérifie le même contrat que CI, rappelle les `bin/graphiti-ingest.sh <préfixe>` pour les fichiers modifiés, puis lance l’ingest côté poste si la stack est up.
3. (Option Graphiti) `python3 memory/verify.py` si le MCP / Neo4j tournent.
4. (Option abonnement Anthropic **ciblé**) `bash scripts/foodking-claude-orchestrate.sh context` puis `... audit-brief` — le terminal lit d’abord le bref disque ; ou raccourci : `... post-execute` pour enchaîner l’étape 2 + `context` (sans lancer l’audit payant à ta place).

Le wrapper branche `stdin` sur `/dev/null` pour `claude -p` (audit) : dans un **terminal non TTY** (ou agent automatisé), sans cela Claude Code affiche *no stdin data* et refuse de lire le prompt. Ne pas l’enlever si tu scripts l’orchestration.

Si `check` échoue : installer l'extension **Claude Code** (Cursor) ou le binaire `claude` côté Anthropic, et vérifier que le dossier binaire (souvent `~/.local/bin`) est sur le `PATH` du terminal intégré.

**Usage aligné sur ton workflow** : orchestration initiale → **Claude dans Cursor** ; audit de cycle → **Claude terminal d’abord**, puis **fallback Cursor Claude** si quota/rate-limit/terminal HS ; clôture seulement après le second avis GPT final.

### B — OpenAI **Codex** CLI (implémentations lourdes, abonnement ChatGPT Plus/Pro)

1. Dans le terminal Cursor :

```bash
npm install -g @openai/codex
```

2. Lancer :

```bash
codex
```

3. Première exécution : choisir **Sign in with ChatGPT** — utilise les crédits du compte **ChatGPT Pro**, **sans** clé API dans le dépôt. Si l’app était liée à une clé : `codex auth logout`, puis reconnecter en **Pro**.

4. **Cycle PLAN_REVIEW / EXECUTE / GPT_FINAL_AUDIT (PRIMARY du dépôt)** : `npm run codex:plan-review -- <TASK_ID>`, `npm run codex:prepare -- <TASK_ID>`, `npm run codex:complex -- <TASK_ID>`, puis `npm run codex:final-audit -- <TASK_ID>` après le PASS Claude. Sous le capot : `codex exec -m gpt-5.5-pro -c model_reasoning_effort=\"xhigh\"`. Instructions + invariants : `agents/codex-extension-instructions.md`.

**Appels non interactifs (exemples)** :

```bash
codex exec "Implémente uniquement ce qui est décrit dans plans/PLAN_<TASK>_<DATE>.md ; ne modifie pas le hors-scope ; respecte AGENTS.md et les frozen zones."
```

**Usage aligné** : `npm run codex:complex` pour les cycles bornés (missions), y compris les petites corrections produit ; validation → **PHPUnit / Vitest** + Cursor VALIDATE ; close → Claude audit puis GPT final audit.

### Résumé opérationnel (sans changer le plan de phases du dépôt)

| Besoin | Outil terminal | Quand |
|--------|----------------|-------|
| Audit global / orchestration textuelle profonde | `claude "..."` | Après livraison, ou boucle 2e audit |
| Patch large multi-fichiers guidé par un plan existant | `codex "..."` | EXECUTE complexe hors session Cursor ou en parallèle humaine |
| Cycle officiel FoodKing (gates, routing, preflight prod) | Cursor + `run-cycle` + subagents | Toujours la source de vérité procédurale |

**Sécurité** : ne jamais coller de secrets (Neo4j, OpenRouter, clés API) dans les prompts terminal ; les abonnements gèrent l’auth OAuth du CLI.


## Pre-Execution
Run `.cursor/hooks/safety-check.sh` manually before every execution phase.

## Artifact Locations
| Artifact | Path |
|---|---|
| Task intake | `tasks/` |
| Plans | `plans/` |
| Reports | `reports/` |
| Gate briefs | `docs/gates/` |
| Routing policy | `.cursor/routing.md` |
| Rules | `.cursor/rules/` |

---

## Extended workflow — repository operating instructions

### Claude (Architect & Reviewer)
Responsibilities:
- Architecture decisions and reasoning
- Root-cause analysis and debugging
- Planning with explicit test strategy
- Final review of implementation quality
- Risky refactors and cross-module decisions
- Auth/sync/pricing/state logic analysis
- Determines **test strategy** in plan using the active vocabulary (see **Testing rules**)

### Playwright / E2E verification (Critical QA)
Responsibilities:
- E2E testing (browser, Playwright MCP, complex flows)
- Critical integration testing
- Functional exploration
- Structured QA reporting under `reports/antigravity/` (legacy directory name)
- Only invoked when Claude's plan specifies **`playwright-critical-flow`**, **`playwright-full-e2e`**, or **`playwright-mcp`**, or when review verdict is **`NEEDS_PLAYWRIGHT`**

### Bugbot (Passive Diff Scanner — NO authority)
Responsibilities:
- Automatically scans PR diffs for bugs, security issues, regressions, edge cases
- Writes findings ONLY to `reports/review/bugbot-latest.md`
- NEVER autonomous — generates a file and stops
- NEVER communicates directly with Kimi or the Playwright / E2E executor outside the documented report chain
- NEVER makes architectural decisions
- Governed strictly by `.cursor/BUGBOT.md`

### Cursor
Orchestration environment

## Legacy workflow (PR / review loop — optional)

Use this loop for **historical PR-centric** review habits. It **does not** replace the **Authoritative multi-agent bounded cycle (SSOT)** above for `TASK_ID` + `run-cycle` work.

### Normal Cycle (90% of cases)
1. **Human** requests feature/fix
2. **Claude** analyzes and may write a **narrative or scratch** plan in `reports/planning/latest.md` **only when not** using the bounded SSOT; for bounded cycles the plan **must** be `plans/PLAN_[TASK_ID]_[DATE].md` as in `plan-context.md`.
   - Plan MUST specify test strategy: `no-test` | `static-inspection` | `local-validation` | `playwright-mcp` | `playwright-critical-flow` | `playwright-full-e2e` | `human-verification`
3. **Human** validates plan (GO / MODIFY / STOP)
4. **Kimi** MUST check FIRST: does `reports/review/bugbot-latest.md` exist?
   - **YES** → Kimi **notifies the Human** with:
     `ℹ️ Bugbot findings detected in reports/review/bugbot-latest.md — Claude review needed (ask Claude to fix when ready).`
     Then Kimi **continues normally to step 5** without stopping.
   - **NO** → Kimi continues normally to step 5
5. **Kimi** (or **Cursor** per plan) implements following Claude's plan
6. **Executor** runs **local-validation** (or other declared strategy) when the plan requires it (PHPUnit, Jest, etc.)
7. **Executor** writes execution summary in `reports/execution/latest.md` with test results
8. **Bugbot** (if PR exists) scans the diff → writes `reports/review/bugbot-latest.md` passively
9. **Claude** reads `reports/review/bugbot-latest.md` (when Human convokes Claude) and decides:
   - `ACCEPT` → not blocking, writes verdict in `reports/review/latest.md`
   - `REQUEST_FIX` → writes a minimal correction plan for Kimi
   - `ESCALATE` → schedules **Playwright / E2E verification** (only Claude can do this)
10. **Claude** writes final review in `reports/review/latest.md` with verdict: **APPROVED** / **NEEDS_FIX** / **NEEDS_PLAYWRIGHT**
11. **Kimi** deletes `reports/review/bugbot-latest.md` only after Claude writes `APPROVED` verdict
12. **Human** validates final result

### Playwright / E2E cycle (10% of cases - critical tests only)
1. **Claude's plan** specifies **`playwright-critical-flow`** / **`playwright-full-e2e`** / **`playwright-mcp`** OR **Claude's review** says **`NEEDS_PLAYWRIGHT`**
2. **Human** explicitly requests or authorizes the browser / E2E cycle when gating requires it
3. **Playwright** (MCP or runner) executes E2E/browser/critical tests
4. **Playwright** writes report in `reports/antigravity/latest.md`
5. **Claude** analyzes report → back to Normal Cycle step 2

## Task routing rules

### Use Claude for:
- Architecture decisions
- Synchronization logic
- Auth/authz
- Pricing integrity
- Risky refactors
- Bug root-cause analysis
- Cross-module decisions
- Order lifecycle logic
- State consistency
- Planning (with test strategy)
- Final review

### Use Kimi for:
- Localized code changes
- UI implementation
- CRUD endpoints
- Simple wiring
- Repetitive code generation
- Limited-scope patches
- Unit/integration testing (PHPUnit, Jest, Vitest)
- Linting and formatting

### Use Playwright / E2E verification for:
- E2E testing (browser automation)
- Complex integration flows
- Critical business scenarios
- Multi-device testing
- Performance testing
- Only when explicitly requested or when Claude's plan specifies **`playwright-critical-flow`**, **`playwright-full-e2e`**, or **`playwright-mcp`**

## Repository behavior rules
1. Always read relevant docs before proposing or implementing a change.
2. Treat existing docs and workflow files as required operational context.
3. Do not change code outside the requested scope.
4. Do not touch unrelated modules.
5. Do not modify architecture casually.
6. Do not bypass server-side validations, pricing recalculation, authorization checks, or state transition rules.
7. Preserve the existing business domain language.
8. Respect all boundaries between:
   - admin
   - manager/cashier
   - kiosk machine
   - kitchen display
   - frontend/customer flows
9. Respect the documented order flow and device flow.
10. If a change affects auth, sync, pricing, device behavior, or order states, explicitly mention the risk and propose tests.

## Implementation rules
1. Prefer small diffs.
2. Prefer the simplest working change consistent with the architecture.
3. Reuse existing services, controllers, patterns, and naming conventions where possible.
4. If existing code is inconsistent, point it out before broad cleanup.
5. Do not introduce new dependencies unless necessary and justified.
6. If a task is large, first produce a plan, then implement in phases.
7. After implementation, summarize:
   - files changed
   - why they changed
   - risks
   - test results (if **local-validation** or other test strategy ran)

## Testing rules
1. **Claude decides test strategy in the plan** (active vocabulary):
   - **`local-validation`**: Unit/integration tests (PHPUnit, Jest, Vitest)
   - **`playwright-mcp`** / **`playwright-critical-flow`** / **`playwright-full-e2e`**: E2E / browser / critical paths
   - **`static-inspection`**: Read-only audit without running the full suite
   - **`no-test`**: Trivial changes (docs, comments, formatting)
   - **`human-verification`**: Explicit human sign-off required

2. **Executor runs `local-validation`** when the plan specifies it:
   - Run PHPUnit for backend changes
   - Run Jest/Vitest for frontend changes
   - Run linter (phpcs, eslint)
   - Include test results in execution summary

3. **Playwright / E2E** executes when the plan specifies **`playwright-mcp`**, **`playwright-critical-flow`**, or **`playwright-full-e2e`**:
   - E2E browser testing
   - Complex integration scenarios
   - Critical business flows
   - Generate detailed QA report under `reports/antigravity/`

4. Prioritize tests for:
   - kiosk auth
   - pricing integrity
   - order creation
   - state transitions
   - KDS flows
   - OSS/display flows
   - authorization boundaries

## Operational output rules
- **Bounded SSOT cycles:** plan under `plans/` per `ACTIVE_CYCLE.md` / `plan-context.md`; execution evidence in `REPORT_FILE` and `reports/post_execute_latest.log` as in `run-cycle.md`.
- **Legacy loop:** planning narrative may go to `reports/planning/latest.md` (with test strategy specified) when not using the bounded SSOT.
- Execution summary goes to `reports/execution/latest.md` (with test results if applicable)
- Review output goes to `reports/review/latest.md` (with verdict)
- QA findings come from `reports/antigravity/latest.md` (only when Playwright / E2E verification is invoked; path name is legacy)
- **Bugbot findings** go to `reports/review/bugbot-latest.md` (passive, read only by Claude)
- Use the report format defined in `workflows/report-format.md`
- See `.cursor/BUGBOT.md` for Bugbot operating rules

## Behavior in uncertainty
- If docs and code disagree, say so explicitly.
- If code and reports disagree, investigate first.
- If the change is risky, stop and propose a safer phased approach.
- If uncertain about test strategy, default to **`local-validation`** for safety.

## Definition of good output
A good result is:
- scoped
- consistent with docs
- easy to review
- safe to test
- explicit about risk
- explicit about test strategy
- explicit about next steps

## Workflow autonomy
This workflow is semi-autonomous, not fully autonomous.
Agents must automatically read the relevant project files and latest operational reports,
but the human developer remains the final authority and explicitly validates each major cycle.

## Definition of success
- architecture preserved
- business rules respected
- authorization intact
- no unrelated regressions
- tests passed (if applicable)
- work easy to review
- clear next step

Before making important changes, first summarize the current architecture understanding in 5-10 lines.

```

---

## missions/VERSION-A-SYSTEM-FINISHING/TASKLIST.md

```
# Version A System Finishing - Task List

Status: READY_FOR_HUMAN_VALIDATION
Plan: `plans/PLAN_VERSION_A_SYSTEM_FINISHING_2026-04-28.md`

## Global Rule

Hardware is deferred to final UAT. This task list finishes the software/system side first.

## Tracking

| ID | Mission | Priority | Status | Main Output |
| --- | --- | --- | --- | --- |
| VA-SYS-00 | Scope lock / hardware deferral | P0 | PASS_SCOPE_LOCK | Gate note + hardware deferral + strict API audit closeout |
| VA-SYS-01 | Dashboard workflow discovery | P1 | PENDING_VALIDATION | Discovery report + selector map |
| VA-SYS-02 | Composer request contract hardening | P1 | PENDING_VALIDATION | Validation fixes + Composer/Menu tests |
| VA-SYS-03 | Wizard runtime contract | P1 | PENDING_VALIDATION | Generic wizard hardening + JS tests |
| VA-SYS-04 | Dashboard builder UX hardening | P1/P2 | PENDING_VALIDATION | Operator-safe composer UI |
| VA-SYS-05 | Full dashboard-to-kiosk/POS/KDS E2E | P1 high | PENDING_VALIDATION | Playwright full central flow |
| VA-SYS-06 | Stockable choices semantics | P1 | PASS_LOCAL | Product rupture + choice-level stock implementation + projection/pricing/POS-kiosk tests |
| VA-SYS-07 | Central management authz matrix | P1 | PASS_LOCAL_STRONG | Dashboard branch scope + composer show scope + availability fanout + global photo authz + seeder wiring tests |
| VA-SYS-08 | Realtime/outbox production-like simulation | P1 | PASS_RUNTIME_LOCAL_STRONG | Outbox production-like simulation + JS reconnect contracts + C3 multi-surface Playwright repeat |
| VA-SYS-09 | Docs/runbook/memory close | P2 | PASS_DOCS_MEMORY | `docs/sync/*` + memory update + final validation matrix |
| VA-SYS-10 | Final massive validation | P0 close | PASS_CORE_SYNC_VALIDATION_WITH_REMAINING_SYSTEM_GATES | 221 critical tests PASS + build PASS; full close still blocked by VA-SYS-01..05 |

## Default Decisions Proposed By Codex

- Hardware: deferred to final UAT.
- Runtime protocol: API + WebSocket/outbox, not MCP.
- Wizard: no-migration hardening first; `step_kind/ui_component` migration only with explicit validation.
- Dashboard E2E: mandatory before calling Version A management complete.
- Stock: user confirmed product rupture + choice-level rupture for stockable wizard choices.
- Authz matrix: mandatory before multi-branch production.

## Execution Protocol After Validation

For every mission:

1. Pre-audit.
2. Implement bounded changes.
3. Run focused tests.
4. Run adversarial read-only review.
5. Fix REWORK.
6. Run run-many validation.
7. Write mission report.
8. Move forward only on PASS.

## Final Target

`VERSION_A_SYSTEM_SOFTWARE: PASS_READY_FOR_HARDWARE_INDUSTRIAL_UAT`

```

---

## reports/audit/CODEX_VA_SYS_06_STOCKABLE_CHOICES_EXECUTION_2026-04-29.md

```
# Codex VA-SYS-06 Stockable Choices Execution - 2026-04-29

## Verdict

`VA-SYS-06_VERDICT: PASS_LOCAL`

`SCOPE: software/runtime local validation`

Hardware, TPE, printer and industrial device UAT remain deferred. This report covers the software system: dashboard composer choices, branch stock state, menu projection, kiosk/POS rendering, backend pricing rejection, stock decrement/release and catalog sync invalidation.

## User Decision Implemented

Stock is managed at two levels:

- Product-level rupture: sandwich, menu, drink, dessert, prepared item can be unavailable as a full product.
- Choice-level rupture: stockable wizard choices can be unavailable inside a composition, including ingredients, sauces, crudites, supplements, drinks, desserts, meat/fish or addon target products.

The runtime behavior is now consistent:

- Dashboard/composer publishes wizard steps.
- Menu/Kiosk/POS projections expose `is_available` and `unavailable_reason` for stockable choices.
- Kiosk generic wizard disables unavailable choices.
- POS legacy modal disables unavailable variations/extras/addons and blocks direct method calls.
- Backend pricing rejects stale/forged payloads selecting an unavailable choice.
- Backend pricing rejects stale active choices that are no longer present in the current published composer profile.
- POS and kiosk edit/restore paths sanitize choices that became unavailable or were removed from the current profile before submit.
- Order stock decrement includes addon target items from `composition_snapshot.addons`.
- Cancel/refund release can restore addon target stock idempotently.
- `StockLevelChanged` invalidates kiosk menu cache and persists catalog outbox refresh.

## Implementation Summary

### Backend

- Added `app/Services/Stock/ChoiceAvailabilityResolver.php`.
  - Resolves branch-scoped availability for `ItemVariation`, `ItemExtra`, and addon target `Item`.
  - Applies surface visibility and catalog status checks.
  - Provides a server-side orderability guard for stale payload rejection.

- Updated menu projection services.
  - `app/Services/Menu/MenuProjectionService.php`
  - `app/Services/Kiosk/KioskMenuService.php`
  - `app/Services/Composer/ComposerProfileProjection.php`
  - These now decorate stockable composer choices, variations, extras and addons with availability metadata by branch.

- Updated API resources.
  - `app/Http/Resources/ItemResource.php`
  - `app/Http/Resources/NormalItemResource.php`
  - `app/Http/Resources/ItemExtraResource.php`
  - `app/Http/Resources/ItemAddonResource.php`
  - Kiosk token branch resolution is server-side; public forged `branch_id` is ignored.

- Updated pricing backend.
- `app/Services/Pricing/PricingService.php`
  - Validates active/visible variations, extras and addons.
  - Rejects branch-stock rupture for selected stockable choices.
  - Rejects selected choices missing from the current published profile projection.
  - Enforces composer min/max/repeat constraints against published profile projection.

- Updated stock and sync.
  - `app/Services/Stock/StockService.php`
  - `app/Listeners/ReleaseStockOnRefundCreated.php`
  - `app/Events/CatalogChanged.php`
  - `app/Providers/EventServiceProvider.php`
  - Addon target stock is decremented/released from composition snapshots.
  - Stock release runs before availability release so the `released_qty` ledger does not block stock compensation.
  - Choice-level stock boundary changes emit catalog invalidation/outbox refresh.

### Frontend

- Updated `resources/js/components/frontend/kiosk/steps/KioskStepGenericChoicesComponent.vue`.
  - Generic composer choices with `is_available=false` cannot be selected.
- Updated `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`.
  - Restored cart selections are filtered against the current composer profile choices.
  - Unavailable/removed composer choices do not count for step advancement and are not serialized.

- Updated `resources/js/components/admin/pos/ItemComponent.vue`.
  - Variations, extras and addons show disabled/rupture state when unavailable.
  - Direct JS method calls cannot add/increment unavailable choices.
  - Existing selected unavailable choices can still be removed, preventing trapped cart lines.
  - Default single-choice selection skips unavailable options.
  - Restored cart selections are pruned before rebuilding a POS cart payload.

## Validation Matrix

| Area | Proof | Result |
| --- | --- | --- |
| Menu projection | `php artisan test tests/Feature/Services/Menu` | PASS, 23 tests |
| Resource branch/composer projection | `php artisan test tests/Feature/ItemAttributeComposerResourceTest.php` | PASS, 5 tests |
| Backend pricing/forgery rejection | `php artisan test tests/Feature/Services/Pricing/ComposerStepConstraintTest.php` | PASS, 12 tests |
| Stock decrement/release/refund | `php artisan test tests/Feature/Stock` | PASS, 21 tests |
| Composer authz/publish sync | `php artisan test tests/Feature/Composer` | PASS, 15 tests |
| Kiosk/POS choice UX | `npx vitest run tests/js/posRuptureUx.spec.js tests/js/kioskWizardGenericComposer.spec.js tests/js/kioskRuptureUx.spec.js tests/js/posWizardComposerProfile.spec.js` | PASS, 13 tests |
| PHP syntax | `php -l` on changed PHP files | PASS |
| Production bundle | `npm run production` | PASS |

Total targeted proof for VA-SYS-06 in this pass: 89 automated tests + production build.

## Adversarial Audit Closure

Read-only adversarial audit found two P1 issues:

- Stale composer choices could be accepted if active/stocked but absent from the current profile.
- POS/kiosk edit restore could carry old selections to submit.

Both were fixed and covered by:

- `test_published_profile_rejects_stale_choice_ids_not_in_current_projection`
- `removes unavailable restored POS selections before rebuilding cart payload`
- Kiosk runtime guards `sanitizeComposerChoicesForCurrentProfile` and `currentComposerAllowedChoiceKeys`

## Invariants Checked

- Pricing SSOT: frontend only disables/labels choices; `PricingService` remains authoritative and rejects invalid selected choices.
- `branch_id` isolation: availability is resolved by server branch context; kiosk branch comes from machine token, not client query.
- Dispatch after commit: no order event dispatch moved into a transaction; catalog invalidation/outbox hooks are event listeners.
- OrderService / FrontendOrderService symmetry: no new direct order-service pricing logic was added; stock release/decrement is centralized in `StockService`.
- Frozen zones: no migration added for this mission; no new business pricing in frontend.

## Residual Risks / Next Missions

- VA-SYS-05 still needs the full dashboard-to-kiosk/POS/KDS E2E flow with a real dashboard-created product, photo, stockable wizard, publish, order, KDS visibility and stock mutation.
- VA-SYS-07 authz matrix remains a broader role/scope campaign beyond the targeted Composer tests run here.
- VA-SYS-08 should stress realtime/outbox replay and reconnect after stockable-choice mutation.
- Hardware UAT remains intentionally out of scope until software Version A reaches final `VA-SYS-10`.

## Execution Trace

`EXECUTE_DELEGATION: explicit-prompt-bind`

`AUDIT_CHANNEL: codex-local-self-audit`

`TERMINAL_AUDIT_OK: 0`

`AUDIT_FALLBACK_REASON: Claude terminal not used in this continuation because the user requested Codex to continue autonomously while Claude is quota-limited; local code/test evidence is recorded here for later external audit.`

`STATUS: PASS_LOCAL_READY_FOR_NEXT_VA_SYS_MISSION`

```

---

## reports/audit/CODEX_VA_SYS_07B_EXTENDED_AUTHZ_EXECUTION_2026-04-30.md

```
# CODEX VA-SYS-07B — Extended Central Management Authz Execution — 2026-04-30

## Verdict

`AUDIT_VERDICT: PASS_LOCAL_STRONG`

`VERSION_A_SYSTEM_SCOPE: SOFTWARE_ONLY_HARDWARE_DEFERRED`

VA-SYS-07 is upgraded from `PASS_LOCAL_PARTIAL` to `PASS_LOCAL_STRONG` for the central management authz matrix.

## What Changed

- Dashboard runtime aggregates are now branch-scoped for branch users and global for `Admin` / `Tenant Admin`.
- Dashboard customer counts/top customers are derived from orders in the visible branch, not from `users.branch_id`, so global customer accounts remain counted correctly per restaurant branch.
- Composer profile `show` now resolves deterministically:
  - branch actor without query defaults to own branch profile;
  - global actor without query defaults to global profile;
  - no accidental “latest foreign profile” leak.
- Product photo mutation is reserved to global catalog roles (`Admin`, `Tenant Admin`) because product media is global catalog state in V1.
- Availability toggle matrix is covered:
  - no `items_edit` => 403;
  - own branch => success;
  - foreign branch => 403;
  - null branch fanout for branch user => own branch only;
  - null branch fanout for admin => all branches.
- `ComposerPermissionsMinimalSeeder` is now wired into `DatabaseSeeder`.

## Files Changed

- `app/Services/DashboardService.php`
- `app/Http/Controllers/Admin/ComposerProfileController.php`
- `app/Services/Composer/ComposerProfileService.php`
- `app/Http/Controllers/Admin/ItemController.php`
- `database/seeders/DatabaseSeeder.php`
- `tests/Feature/Dashboard/DashboardBranchScopeMatrixTest.php`
- `tests/Feature/Menu/AvailabilityToggleAuthzMatrixTest.php`
- `tests/Feature/Catalog/ProductPhotoAuthzTest.php`
- `tests/Feature/Composer/ComposerAuthzMinimalTest.php`
- `tests/Feature/Seeders/ComposerPermissionSeederProductionTest.php`
- `tests/Feature/Menu/AdminItemBranchAvailabilityProjectionTest.php`
- `missions/VERSION-A-SYSTEM-FINISHING/TASKLIST.md`
- `reports/post_execute_latest.log`

## Validation

- `php -l` scoped files: PASS
- `php artisan test tests/Feature/Dashboard/DashboardBranchScopeMatrixTest.php`: 3 PASS
- `php artisan test tests/Feature/Menu/AvailabilityToggleAuthzMatrixTest.php`: 3 PASS
- `php artisan test tests/Feature/Catalog/ProductPhotoAuthzTest.php`: 1 PASS
- `php artisan test tests/Feature/Composer/ComposerAuthzMinimalTest.php`: 11 PASS
- `php artisan test tests/Feature/Seeders/ComposerPermissionSeederProductionTest.php`: 2 PASS
- `php artisan test tests/Feature/Catalog`: 14 PASS
- `php artisan test tests/Feature/Composer`: 17 PASS
- `php artisan test tests/Feature/Menu`: 23 PASS, 6 SKIP (existing SQLite/MySQL JSON surface filtering skip)
- `php artisan test tests/Feature/Stock`: 21 PASS
- `php artisan test tests/Feature/Services/Menu`: 23 PASS
- `php artisan test tests/Feature/Services/Pricing/ComposerStepConstraintTest.php`: 12 PASS
- `php artisan test tests/Feature/Catalog/PhotoEndToEndKioskInvalidationTest.php`: 1 PASS
- `git diff --check` scoped files: PASS
- `npm run production`: PASS

## Sync Risk Review

- Architecture risk: low. Dashboard scoping is centralized in `DashboardService` helpers; catalog photo policy is enforced at controller boundary before mutation.
- State consistency risk: reduced. Branch dashboard now sees only own order/SLA/channel data, while admin remains global.
- Business rule risk: reduced. Product images are treated as global catalog state; branch users cannot overwrite shared product media.
- Authz risk: reduced. Composer read path no longer selects a foreign latest profile before authorization.
- Missing tests: MySQL JSON surface-filtering tests remain skipped under SQLite by existing design and must still run in the MySQL CI job.

## Remaining Work

- VA-SYS-08 remains next: realtime/outbox production-like simulation.
- VA-SYS-05 remains required later for full dashboard-to-kiosk/POS/KDS browser flow.
- Hardware remains intentionally deferred.

```

---

## reports/audit/CODEX_VA_SYS_08_REALTIME_OUTBOX_EXECUTION_2026-04-30.md

```
# Codex — VA-SYS-08 Realtime / Outbox Production-Like Audit — 2026-04-30

TASK_ID: `CENTRAL-SYNC-VA-SYS-FINISHING/VA-SYS-08`

## Verdict

`VA_SYS_08_VERDICT: PASS_RUNTIME_LOCAL_STRONG`

`RELEASE_SCOPE_DECISION: SOFTWARE_SYNC_LOCAL_PASS__HARDWARE_PROVIDER_UAT_DEFERRED`

Objectif: prouver que la couche synchronisation centrale FoodKing tient localement sur les axes outbox, contrats d'events, reprise apres panne broadcaster, fanout branch-scoped, fallback/reconnect front, et runtime multi-surface Kiosk/POS/KDS/OSS sans reload manuel.

## Changement livre

Nouveau test:

- `tests/Feature/Outbox/OutboxProductionLikeSimulationTest.php`

Ce test ne modifie pas le runtime produit. Il ajoute une simulation de production pour les cas qui etaient jusque-la surtout couverts par tests unitaires ou contrats separes:

- rescue outbox: ne requeue que les events pending, stale, retriables, attempts < 5
- retry-failed: ne reset que les events terminal failed recents dans la fenetre demandee
- panne broadcaster: le claim est relache, `attempts` et `last_error` sont traces, rescue peut requeue, puis un retry sain dispatch correctement
- fanout global catalogue: `CategoryUpdated` produit un `CatalogChanged` par branche active, meme correlation, channel `private-branch.{branch_id}`, enveloppe valide
- violation contractuelle: payload invalide ne touche jamais le broadcaster, reste retriable, et garde `last_error=contract_violation:*`

Artifacts mis a jour:

- `missions/VERSION-A-SYSTEM-FINISHING/TASKLIST.md`
- `reports/antigravity/c3-runtime-multi-surface.json`

## Pre-audit raisonne

Risques verifies avant validation:

1. Outbox duplication ou perte: un event ne doit pas etre marque dispatched si le provider tombe.
2. Retry dangereux: une commande stale ou failed doit pouvoir revenir, mais pas un event frais, deja dispatched, ou maxed-out.
3. Cross-branch sync leak: un refresh global catalogue doit cibler les branches actives sans envoyer sur un canal commun non scope.
4. Contrat event: un payload invalide doit bloquer avant broadcast, sinon KDS/OSS/POS peuvent consommer un message incoherent.
5. Runtime multi-surface: les preuves statiques ne suffisent pas; C3 doit prouver un DOM update KDS/OSS/POS depuis une creation Kiosk/POS locale.

## Validations executees

### Outbox / rescue / retry

`php -l tests/Feature/Outbox/OutboxProductionLikeSimulationTest.php`

- PASS

`php artisan test tests/Feature/Outbox/OutboxProductionLikeSimulationTest.php`

- PASS: 5 tests

Run-many:

`for i in 1 2 3; do php artisan test tests/Feature/Outbox/OutboxProductionLikeSimulationTest.php; done`

- PASS 3/3
- Total boucle: 15 assertions de scenario testees via 3 runs complets de la suite, sans retry magique

Suites proches:

- `php artisan test tests/Feature/Outbox` -> PASS: 14 tests
- `php artisan test tests/Feature/OutboxRescueTest.php` -> PASS: 2 tests
- `php artisan test tests/Feature/OutboxTest.php` -> PASS: 6 tests

### Catalog / menu / projection sync

- `php artisan test tests/Feature/Catalog/CatalogChangedDispatchTest.php` -> PASS: 2 tests
- `php artisan test tests/Feature/Catalog/CatalogOutboxIdempotencyTest.php` -> PASS: 1 test
- `php artisan test tests/Feature/Menu/CatalogMutationSnapshotCoverageTest.php` -> PASS: 3 tests
- `php artisan test tests/Feature/Menu/CatalogStockCentralSyncEndToEndTest.php` -> PASS: 1 test

### Event contract / after commit

- `php artisan test tests/Feature/EventContractTest.php` -> PASS: 9 tests
- `php artisan test tests/Unit/Domain/Events/EventContractUnitTest.php` -> PASS: 12 tests
- `php artisan test tests/Feature/AfterCommitDispatchTest.php` -> PASS: 14 tests
- `php artisan test tests/Feature/DispatchAfterCommitTest.php` -> PASS: 8 tests
- `php artisan test tests/Feature/KioskRealtimeBroadcastTest.php` -> PASS: 2 tests

### Sync backend / JS realtime contracts

- `php artisan test tests/Feature/SyncComprehensiveTest.php` -> PASS: 6 tests

`npx vitest run tests/js/eventContractDedupe.spec.js tests/js/correlationDedupePersistence.spec.js tests/js/correlationDedupeCapacity.spec.js tests/js/realtimeBroadcastFallback.spec.js tests/js/kdsReactsToReconnectStorm.spec.js tests/js/kdsBackoffOn5xx.spec.js tests/js/kdsSyncCadence.spec.js tests/js/kdsVersionGate.spec.js`

- PASS: 8 files
- PASS: 29 tests
- Note: warnings console attendus sur enveloppes invalides dans les tests de validation negative.

### Runtime multi-surface C3

Serveur local temporaire: `php artisan serve --host=127.0.0.1 --port=8000`.

`npx playwright test tests/e2e/c3-runtime-multi-surface.spec.js --repeat-each=1 --retries=0`

- PASS: 2 tests

`npx playwright test tests/e2e/c3-runtime-multi-surface.spec.js --repeat-each=2 --retries=0`

- PASS: 4 tests

Dernier artifact JSON:

- `reports/antigravity/c3-runtime-multi-surface.json`
- `kiosk_cash_to_kds_pos_oss`: KDS 5878 ms, OSS preparing 2884 ms, order_id 663
- `pos_to_kds_oss`: KDS 5881 ms, OSS preparing 3876 ms, order_id 664

Le test C3 valide un changement DOM visible cote KDS/OSS/POS depuis creation Kiosk/POS locale, sans reload manuel, sur la limite locale de l'environnement actuel.

### Hygiene

- `git diff --check -- tests/Feature/Outbox/OutboxProductionLikeSimulationTest.php missions/VERSION-A-SYSTEM-FINISHING/TASKLIST.md reports/post_execute_latest.log` -> PASS

## Invariants FoodKing verifies

- Pricing SSOT backend: aucun calcul prix frontend ajoute ou modifie.
- `OrderStatus`: aucune chaine magique ajoutee.
- `branch_id`: fanout catalogue valide uniquement vers `private-branch.{branch_id}` pour branches actives; pas de canal global non scope.
- Dispatch after commit: les suites after-commit existantes restent vertes.
- Frozen zones: pas d'edition `OrderService.php` / `FrontendOrderService.php`.
- Outbox idempotence/reprise: panne provider ne produit pas de faux `dispatched_at`; la reprise rescue est bornee.

## Ce qui est valide fortement maintenant

1. L'outbox ne marque pas un event comme dispatch si le broadcaster echoue.
2. Les events failed/pending ne sont pas relances sans filtre; stale/recent/attempts/dispatched_at sont respectes.
3. Un `CategoryUpdated` global se transforme en events branch-scoped actifs avec enveloppes valides.
4. Une violation de contrat event stoppe avant broadcast et laisse une trace exploitable.
5. Les contrats JS de dedupe, persistence correlation, fallback broadcast, backoff, cadence et version gate passent.
6. Le runtime local C3 prouve Kiosk/POS -> KDS/OSS/POS visible sans reload manuel.

## Limites honnetes

- Ce n'est pas un test provider cloud reel Pusher/Reverb en production: l'UAT hardware/provider reste separe.
- SQLite/local ne remplace pas un stress MySQL/Redis prod-like pour tous les locks; les suites existantes couvrent deja une partie forte, mais staging MySQL/Redis reste le bon prochain cran avant go-live commercial.
- Les timings C3 locaux depassent 5s pour KDS dans le dernier JSON (~5.88s). Le verdict reste PASS local parce que le DOM update arrive sans reload et les suites C3 repetent vert, mais le budget perf cible doit rester surveille en staging.

## Decision

`VA-SYS-08: CLOSED_LOCAL_PASS`

La prochaine etape logique est `VA-SYS-09`:

- consolider docs/runbook/memory pour centralisation, outbox, refresh catalogue, rupture stock, branch isolation
- ecrire clairement les limites hardware/provider laissees a l'UAT
- preparer `VA-SYS-10` final massive validation avec relance selective des suites critiques

```

---

## reports/audit/CODEX_VA_SYS_09_DOCS_RUNBOOK_MEMORY_2026-04-30.md

```
# Codex — VA-SYS-09 Docs / Runbook / Memory Close — 2026-04-30

TASK_ID: `CENTRAL-SYNC-VA-SYS-FINISHING/VA-SYS-09`

## Verdict

`VA_SYS_09_VERDICT: PASS_DOCS_MEMORY`

VA-SYS-09 ferme le manque documentaire local: le dossier `docs/sync` n'existait pas encore, alors que les missions VA-SYS-06/07/08 avaient valide des pieces critiques de centralisation data/sync. Cette mission cree les runbooks qui relient ces preuves en une vue systeme exploitable avant VA-SYS-10.

Un adversarial read-only reviewer a signale un REWORK legitime apres la premiere passe: les cinq livrables nommes dans le plan n'existaient pas encore, et plusieurs docs legacy pouvaient contredire l'etat VA-SYS-06/08. La passe courante ferme ce rework.

## Documents crees

- `docs/sync/CATALOG_COMPOSER_DATA_FLOW.md`
- `docs/sync/WIZARD_PRODUCT_MODEL.md`
- `docs/sync/STOCK_SYNC_AND_AVAILABILITY.md`
- `docs/sync/API_VS_MCP_DECISION.md`
- `docs/sync/CENTRAL_MANAGEMENT_RUNBOOK.md`

Documents de synthese additionnels:

- `docs/sync/CENTRAL_DATA_SYNC_RUNBOOK_2026-04-30.md`
- `docs/sync/PRODUCT_CATALOG_STOCK_COMPOSER_SYNC_SPEC_2026-04-30.md`
- `docs/sync/VERSION_A_SYNC_VALIDATION_MATRIX_2026-04-30.md`

Docs legacy corrigees/annotees:

- `docs/REALTIME_SETUP.md` ne doit plus faire croire que les core events utilisent direct `ShouldBroadcastNow`.
- `docs/MENU_AVAILABILITY.md` precise que stockable wizard-choice rupture est Version A.
- `docs/MENU_PROJECTIONS.md` precise que POS/Kiosk projections ont avance depuis la fondation V1.
- `docs/operations/QUEUE_TOPOLOGY.md` utilise les commandes actuelles `foodking:outbox:*`.

## Memoire mise a jour

- `memory/episodes/02_architecture_invariants.jsonl`
- `memory/episodes/03_domain_events_sync.jsonl`
- `memory/episodes/11_production_plan.jsonl`
- `memory/episodes/12_decisions_log.jsonl`
- Graphiti episode: `VA-SYS-09 docs runbook memory close`

## Contenu couvert

- Surfaces connectees: Dashboard, POS, Kiosk, KDS, OSS, backend.
- Sources de verite: pricing, catalogue, composer, stock, commandes, realtime, fiscal.
- Flux: modification produit/categorie/photo, publication composer, commande kiosk, commande POS, rupture stock, outbox/realtime.
- Produit/composer: produit pret sans wizard, produit simple avec options, produit compose avec wizard multi-step.
- Stock: rupture complete produit + rupture de choix wizard stockable.
- API vs MCP: API/outbox/realtime restent le protocole runtime; MCP reste outil agent/dev, pas bus caisse/borne.
- Matrice final VA-SYS-10: commandes exactes PHP/Vitest/Playwright/build a relancer.

## Pre-audit adversarial local

Questions attaquees:

1. Est-ce que la doc peut faire croire que hardware est valide? Non: chaque document garde hardware/provider dans UAT separe.
2. Est-ce que MCP est propose comme remplacement runtime API? Non: decision explicite API + WebSocket/outbox.
3. Est-ce que le wizard force tous les produits? Non: produit sans wizard, wizard court et wizard complexe sont separes.
4. Est-ce que la rupture est limitee aux sandwiches? Non: produits vendables et choices stockables sont couverts.
5. Est-ce que la doc masque les missions encore pending? Non: VA-SYS-01..05 et VA-SYS-10 restent visibles dans la matrice.

## Validation

- `git diff --check` scoped docs/memory/report/tasklist: PASS.
- JSONL memory lines: parse check PASS.
- Adversarial reviewer REWORK points: closed by adding exact five docs, canonical runbook, memory episodes, and legacy contradiction notes.
- No product runtime code changed.

## Decision

`VA-SYS-09: CLOSED_DOCS_MEMORY_PASS`

Prochaine mission: `VA-SYS-10 Final massive validation`.

```

---

## reports/audit/CODEX_VA_SYS_10_FINAL_MASSIVE_VALIDATION_2026-04-30.md

```
# Codex — VA-SYS-10 Final Massive Validation — 2026-04-30

TASK_ID: `CENTRAL-SYNC-VA-SYS-FINISHING/VA-SYS-10`

## Verdict

`VA_SYS_10_VERDICT: PASS_CORE_SYNC_VALIDATION_WITH_REMAINING_SYSTEM_GATES`

`VERSION_A_SYSTEM_SOFTWARE_CLOSE: NOT_YET_FULLY_CLOSED`

`REASON: VA-SYS-01..05 remain pending in the Version A tasklist, especially the full dashboard-to-kiosk/POS/KDS E2E.`

This final run validates the critical sync/core area delivered in VA-SYS-06, VA-SYS-07, VA-SYS-08 and VA-SYS-09: product/choice rupture, composer constraints, central management authz, catalog/photo sync, outbox/realtime, event contracts, after-commit, frontend guards, production build and C3 runtime multi-surface.

It does not claim hardware readiness or full Version A management closure while VA-SYS-01..05 are still pending.

## Massive validation summary

| Layer | Result |
| --- | --- |
| PHP critical suites | 175 PASS, 6 SKIP known MySQL-only surface filtering |
| Vitest critical suites | 42 PASS |
| Playwright C3 runtime | 4 PASS with `--repeat-each=2 --retries=0` |
| Production build | PASS |
| JSONL memory parse | PASS |
| `git diff --check` scoped | PASS |

## PHP validation detail

| Command | Result |
| --- | --- |
| `php artisan test tests/Feature/Services/Menu` | 23 PASS |
| `php artisan test tests/Feature/Services/Pricing/ComposerStepConstraintTest.php` | 12 PASS |
| `php artisan test tests/Feature/Stock` | 21 PASS |
| `php artisan test tests/Feature/Composer` | 17 PASS |
| `php artisan test tests/Feature/Catalog` | 14 PASS |
| `php artisan test tests/Feature/Menu` | 23 PASS, 6 SKIP |
| `php artisan test tests/Feature/Outbox` | 14 PASS |
| `php artisan test tests/Feature/EventContractTest.php` | 9 PASS |
| `php artisan test tests/Unit/Domain/Events/EventContractUnitTest.php` | 12 PASS |
| `php artisan test tests/Feature/AfterCommitDispatchTest.php` | 14 PASS |
| `php artisan test tests/Feature/DispatchAfterCommitTest.php` | 8 PASS |
| `php artisan test tests/Feature/SyncComprehensiveTest.php` | 6 PASS |
| `php artisan test tests/Feature/KioskRealtimeBroadcastTest.php` | 2 PASS |

Known skips:

- `tests/Feature/Menu/FrontendSurfaceFilteringTest.php`: 6 SKIP under SQLite because the contract relies on MySQL `JSON_CONTAINS`; expected to run in MySQL CI/staging.

## Frontend validation detail

Command:

```bash
npx vitest run \
  tests/js/posRuptureUx.spec.js \
  tests/js/kioskRuptureUx.spec.js \
  tests/js/kioskWizardGenericComposer.spec.js \
  tests/js/posWizardComposerProfile.spec.js \
  tests/js/eventContractDedupe.spec.js \
  tests/js/correlationDedupePersistence.spec.js \
  tests/js/correlationDedupeCapacity.spec.js \
  tests/js/realtimeBroadcastFallback.spec.js \
  tests/js/kdsReactsToReconnectStorm.spec.js \
  tests/js/kdsBackoffOn5xx.spec.js \
  tests/js/kdsSyncCadence.spec.js \
  tests/js/kdsVersionGate.spec.js
```

Result:

- 12 files PASS
- 42 tests PASS

Expected warnings:

- negative `eventContract` tests log invalid-envelope warnings by design.
- `baseline-browser-mapping` dependency warning is informational and unrelated to FoodKing logic.

## Runtime C3 validation

Server:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Command:

```bash
npx playwright test tests/e2e/c3-runtime-multi-surface.spec.js --repeat-each=2 --retries=0
```

Result:

- 4 PASS

Latest JSON artifact:

- `reports/antigravity/c3-runtime-multi-surface.json`
- kiosk cash -> KDS/POS/OSS: PASS, KDS 5874 ms, OSS 2880 ms, order_id 667
- POS -> KDS/OSS: PASS, KDS 5879 ms, OSS 4893 ms, order_id 668

Observation:

- C3 proves local DOM/runtime propagation without manual reload.
- KDS timing is still slightly above the ideal 5s budget; keep as staging/provider perf watch, not a local software blocker.

## Build and hygiene

`npm run production`

- PASS

`git diff --check` scoped docs/memory/reports/tests/tasklist

- PASS

JSONL parse check:

- PASS for memory episodes 02, 03, 11, 12.

## Invariants checked

| Invariant | Status |
| --- | --- |
| Backend pricing SSOT | PASS: ComposerStepConstraint and order guards reject forged/stale selections |
| `OrderStatus` enum | PASS: no runtime status edits in this pass |
| `branch_id` isolation | PASS: VA-SYS-07B authz and menu/projection tests still green |
| Dispatch after commit | PASS: AfterCommit and DispatchAfterCommit tests green |
| Frozen zones | PASS: no `OrderService` / `FrontendOrderService` edits in VA-SYS-08/09/10 |
| Outbox retry/replay | PASS: production-like simulation and outbox suite green |
| Stock product + choice rupture | PASS: Stock and Pricing suites green |

## What is validated strongly

- Product and category catalog sync foundations.
- Product photo authz/invalidation path.
- Composer branch authz and published profile projection.
- Product-level rupture and choice-level stockable rupture.
- Backend rejection of stale/forged/unavailable choices.
- POS/Kiosk disabled-state frontend guards.
- Outbox provider failure recovery semantics.
- Event contract/dedupe/backoff/fallback behavior.
- Kiosk/POS to KDS/OSS local runtime propagation.
- Documentation/runbook/memory now maps actual files, events, cache keys and commands.

## What is still not finished

These are not discovered regressions; they are still-open plan items from `missions/VERSION-A-SYSTEM-FINISHING/TASKLIST.md`:

| Mission | Status | Why it still matters |
| --- | --- | --- |
| VA-SYS-00 | Pending | Formal scope lock/hardware deferral gate note. |
| VA-SYS-01 | Pending | Dashboard workflow discovery and selector map. |
| VA-SYS-02 | Pending | Composer request contract hardening. |
| VA-SYS-03 | Pending | Wizard runtime contract finalization. |
| VA-SYS-04 | Pending | Dashboard builder UX hardening. |
| VA-SYS-05 | Pending high | Full dashboard create/edit product -> photo -> stock -> composer -> publish -> Kiosk/POS order -> KDS -> stock E2E. |

Hardware/provider UAT remains separate:

- physical payment terminal,
- fiscal printer,
- kiosk OS lockdown,
- cloud realtime provider,
- Google Maps live,
- real network loss/reconnect.

## Decision

`CORE_SYNC_AND_DATA_LAYER: PASS_LOCAL_STRONG`

`VERSION_A_SYSTEM_FINAL_CLOSE: HOLD_UNTIL_VA_SYS_01_05`

Recommended next action:

1. Execute VA-SYS-01..05 in order, with VA-SYS-05 as the true final management workflow proof.
2. Re-run this VA-SYS-10 pack after VA-SYS-05.
3. If still green, move to hardware industrial UAT.

```

---

## docs/sync/CATALOG_COMPOSER_DATA_FLOW.md

```
# Catalog / Composer Data Flow — Version A

Status: canonical VA-SYS-09 document.

## Scope

This file maps the real data path for category, product, photo, composer profile, stock availability, and menu projection across Dashboard, POS, Kiosk, KDS, and OSS.

## Write side: Dashboard to backend

| Operation | Entry point | Service/model path | Event/outbox effect | Notes |
| --- | --- | --- | --- | --- |
| Create/update product | `app/Http/Controllers/Admin/ItemController.php` | `Item`, `ItemService` | `ItemCreated`, `ItemUpdated`, `CatalogChanged` via listener chain | Product catalog is global state; branch-specific availability is separate. |
| Change product photo | `ItemController::changeImage` | `ItemService::changeImage` | Catalog refresh / invalidation tests in `PhotoEndToEndKioskInvalidationTest` | VA-SYS-07B policy: global catalog roles only (`Admin`, `Tenant Admin`). |
| Create/update category | `app/Http/Controllers/Admin/ItemCategoryController.php` | `ItemCategory` | `CategoryCreated`, `CategoryUpdated`, `CatalogChanged` | Global category data; projection filters by surface/branch. |
| Variations | `ItemVariationController` | `ItemVariation` | catalog/profile projection affected | Choice availability resolved by `ChoiceAvailabilityResolver`. |
| Extras | `ItemExtraController` | `ItemExtra` | catalog/profile projection affected | Stockable extras can be unavailable. |
| Addons | `ItemAddonController` | `ItemAddon` + addon item | catalog/profile projection affected | Addon target item can be stocked/decremented. |
| Composer profile | `ComposerProfileController` | `ComposerProfileService` | `ComposerProfileChanged`, `ComposerProfilePublished`, `CatalogChanged` | Branch-scoped; `show` defaults to actor branch/global scope after VA-SYS-07B. |
| Composer steps/options | `ComposerStepController` | `ComposerStepService` | `ComposerProfileChanged`, `CatalogChanged` | Branch isolation enforced on store/update/destroy. |

## Read side: projections

| Surface | Projection/file | Branch source | Important fields |
| --- | --- | --- | --- |
| Kiosk | `app/Services/Kiosk/KioskMenuService.php` | kiosk machine token | `is_available`, `unavailable_reason`, `composer_profile`, choice availability |
| POS/admin menu | `app/Services/Menu/MenuProjectionService.php` | staff branch/admin query | categories/items, surface visibility, composer profile |
| API resources | `ItemResource`, `NormalItemResource`, `ItemExtraResource`, `ItemAddonResource` | explicit request context | choice snapshots from `ChoiceAvailabilityResolver` |
| Pricing | `app/Services/Pricing/PricingService.php` | order branch | backend validates profile, choices, stock, price |
| KDS/OSS | order snapshots + status API | order branch | KDS should not recompute current catalog price for historical order lines |

## Cache and invalidation

| Cache/key | Owner | Invalidated by |
| --- | --- | --- |
| `kiosk.menu.branch.{branchId}` | `Frontend\MenuController`, kiosk menu bootstrap | `InvalidateKioskMenuCacheOnItemAvailabilityChanged`; catalog/stock events force refresh paths |
| `menu:snapshot_version:branch:{id}` | `MenuSnapshot` | `BumpMenuSnapshotOnItemAvailabilityChanged` |
| Kiosk IndexedDB/offline snapshot | `resources/js/store/modules/kioskMenu.js` | realtime `CatalogChanged` / `ItemAvailabilityChanged`, force fetch, TTL |
| POS local items array | `PosComponent.vue` | `_onCatalogChanged`, `_onItemAvailabilityChanged`, manual reload/fallback |

## Event map

| Source event | Canonical aggregate | Listener | Outbox broadcast | Surface consumers |
| --- | --- | --- | --- | --- |
| `CategoryCreated/Updated/Deleted` | category | `PersistCatalogChangedToOutbox` | `CatalogChanged` | POS, Kiosk, KDS refresh/fallback |
| `ItemCreated/Updated/Deleted` | item | `PersistCatalogChangedToOutbox` | `CatalogChanged` | POS, Kiosk, KDS refresh/fallback |
| `ComposerProfileChanged/Published` | composer_profile | `PersistCatalogChangedToOutbox` | `CatalogChanged` | POS/Kiosk wizard projection refresh |
| `StockLevelChanged` | stock_level | `PersistCatalogChangedToOutbox` | `CatalogChanged` | POS/Kiosk catalog refresh |
| `ItemAvailabilityChanged` | item availability | `PersistItemAvailabilityChangedToOutbox` + catalog listener | `ItemAvailabilityChanged` and catalog refresh | POS/Kiosk immediate rupture state; KDS refresh |
| `OrderCreated` | order | `PersistOrderCreatedToOutbox` | `OrderCreated` | KDS, POS, OSS |
| `OrderStatusChanged` | order | `PersistOrderStatusChangedToOutbox` | `OrderStatusChanged` | KDS, POS, OSS, Kiosk waiting |

Channel convention:

- Outbox stores `private-branch.{branch_id}`.
- Echo clients subscribe through Laravel mapping as `branch.{branch_id}` / private branch channel depending helper.
- Branch authorization lives in `routes/channels.php`.

## Order of truth

1. Database rows and snapshots.
2. Backend projection services.
3. Outbox/realtime events.
4. Frontend local cache/UI state.

If these disagree, trust backend DB/projection first, then replay/recover outbox, then refresh frontend state.


```

---

## docs/sync/WIZARD_PRODUCT_MODEL.md

```
# Wizard Product Model — Version A

Status: canonical VA-SYS-09 document.

## Product kinds

| Kind | Composer profile | Runtime behavior |
| --- | --- | --- |
| Ready product | none | Add directly to cart if product is available. |
| Simple option product | optional short profile | One or two steps, for example size/sauce. |
| Complex composed product | published profile required | Multi-step wizard, min/max/repeat constraints, stockable choices. |
| Addon target product | may be standalone or addon | Can be stock-decremented when selected as addon. |
| Ingredient/crudite/sauce/supplement | wizard choice | Can be stockable and unavailable per branch. |

## Backend model

Main files:

- `app/Models/Item.php`
- `app/Models/ItemWizardProfile.php`
- `app/Models/ItemWizardStep.php`
- `app/Models/ItemVariation.php`
- `app/Models/ItemExtra.php`
- `app/Models/ItemAddon.php`
- `app/Services/Composer/ComposerProfileService.php`
- `app/Services/Composer/ComposerStepService.php`
- `app/Services/Composer/ComposerProfileProjection.php`
- `app/Services/Pricing/PricingService.php`
- `app/Services/Stock/ChoiceAvailabilityResolver.php`

## Runtime rules

1. No published composer profile means no forced wizard.
2. Published composer profile takes priority over old heuristic wizard logic.
3. A profile can be global or branch-scoped; branch actors cannot mutate foreign branch profiles.
4. Every submitted choice must still exist in the current published projection.
5. Required steps cannot be satisfied by unavailable choices.
6. `repeat`, `min`, `max`, and role semantics are validated by backend pricing.
7. POS/Kiosk UI only helps the user; backend remains the rejection authority.

## Choice availability

`ChoiceAvailabilityResolver` resolves:

- variations via `StockLevel` rows keyed by `ItemVariation::class`;
- extras via `StockLevel` rows keyed by `ItemExtra::class`;
- addons via addon target `Item` stock + `item_branch_availability`;
- surface visibility for addon item on `pos` / `kiosk`.

Returned shape is projected into:

- `ItemResource`
- `NormalItemResource`
- `ItemExtraResource`
- `ItemAddonResource`
- `MenuProjectionService`
- `KioskMenuService`

## Frontend surfaces

| Surface | Files | Responsibility |
| --- | --- | --- |
| Kiosk wizard | `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`, `KioskStepGenericChoicesComponent.vue` | display composer steps, disable unavailable choices, prune stale restored selections |
| POS item modal | `resources/js/components/admin/pos/ItemComponent.vue` | show disabled choices, block direct JS method calls, remove stale restored selections |
| Cart/snapshot | `kioskCart.js`, POS component state | preserve user edit state but sanitize before submit |

## Pricing and stale data

Backend `PricingService` rejects:

- forged price/total/subtotal;
- inactive item/choice;
- choice unavailable for branch;
- choice absent from current published composer profile;
- invalid min/max/repeat selection;
- addon target unavailable on the requested surface.

## Historical order snapshots

Order items persist `composition_snapshot` and allergen snapshots. Historical receipts/KDS lines must read snapshots first; they must not recompute old order meaning from the live catalog after a product or wizard changes.

## Tests

- `tests/Feature/Services/Pricing/ComposerStepConstraintTest.php`
- `tests/Feature/ItemAttributeComposerResourceTest.php`
- `tests/Feature/Composer/ComposerAuthzMinimalTest.php`
- `tests/js/kioskWizardGenericComposer.spec.js`
- `tests/js/posWizardComposerProfile.spec.js`


```

---

## docs/sync/STOCK_SYNC_AND_AVAILABILITY.md

```
# Stock Sync and Availability — Version A

Status: canonical VA-SYS-09 document.

## User decision

Stock/rupture is supported at two levels:

1. Whole product rupture: sandwich, menu, drink, dessert, prepared item, addon target, etc.
2. Stockable wizard choice rupture: ingredient, sauce, crudite, supplement, drink choice, dessert choice, meat/fish choice, addon target.

The UI may show disabled/badged choices, but the backend must reject stale or forged submissions.

## Main files

| Concern | File |
| --- | --- |
| Product branch availability | `app/Services/Menu/AvailabilityService.php` |
| Product/choice stock levels | `app/Services/Stock/StockService.php`, `app/Models/StockLevel.php` |
| Choice availability | `app/Services/Stock/ChoiceAvailabilityResolver.php` |
| Backend orderability guard | `app/Services/Pricing/PricingService.php` |
| Menu projections | `app/Services/Menu/MenuProjectionService.php`, `app/Services/Kiosk/KioskMenuService.php` |
| Kiosk cache invalidation | `app/Listeners/InvalidateKioskMenuCacheOnItemAvailabilityChanged.php` |
| Snapshot bump | `app/Listeners/BumpMenuSnapshotOnItemAvailabilityChanged.php` |
| Catalog outbox | `app/Events/CatalogChanged.php`, `app/Listeners/PersistCatalogChangedToOutbox.php` |
| Availability outbox | `app/Events/ItemAvailabilityChanged.php`, `app/Listeners/PersistItemAvailabilityChangedToOutbox.php` |
| Stock event | `app/Events/StockLevelChanged.php` |

## Availability states

| Level | Data source | Unavailable reason |
| --- | --- | --- |
| Global item status | `items.status` | product hidden/inactive globally |
| Branch product availability | `item_branch_availability` | `out_of_stock`, `seasonal`, `closed_today`, `manual`, etc. |
| Variation stock | `stock_levels` for `ItemVariation` | `stock_rupture` when on_hand is exhausted |
| Extra stock | `stock_levels` for `ItemExtra` | `stock_rupture` |
| Addon target stock | `stock_levels` + addon item availability | `stock_rupture` or branch/surface reason |

## Runtime flow: stock change to screens

1. Dashboard/POS/admin toggles availability or stock changes after order.
2. Backend updates `item_branch_availability` or `stock_levels`.
3. `ItemAvailabilityChanged`, `StockLevelChanged`, or `CatalogChanged` is dispatched after commit.
4. Outbox persists branch-scoped event.
5. Kiosk menu cache key `kiosk.menu.branch.{branchId}` is invalidated where applicable.
6. Menu snapshot key `menu:snapshot_version:branch:{id}` is bumped for relevant branch.
7. POS/Kiosk/KDS receive realtime event or fallback polling catches up.

## Runtime flow: order submit

1. POS/Kiosk submits cart.
2. Backend ignores client totals and recalculates.
3. `PricingService` checks item/choice availability for the order branch.
4. `StockService::decrementForOrder()` decrements product/addon stock inside the order transaction path.
5. Snapshot persists what was sold.
6. Cancel/refund release restores stock idempotently from snapshots/ledger.

## What to diagnose

| Symptom | Checks |
| --- | --- |
| Product visible but should be unavailable | `item_branch_availability`, `items.status`, projection branch_id, `kiosk.menu.branch.{id}` cache |
| Choice still selectable | `stock_levels.stockable_type`, `ChoiceAvailabilityResolver`, projected `is_available`, POS/Kiosk JS disabled state |
| Backend accepts unavailable choice | `PricingService::validateComposerSelections` / `assertSelectionsOrderable`, composer current profile |
| Kiosk stale after stock change | `domain_events` CatalogChanged/ItemAvailabilityChanged, queue worker, cache key invalidation |
| Stock negative | `StockService` locking path, stock movements, duplicate order idempotency |

## Commands

```bash
php artisan test tests/Feature/Stock
php artisan test tests/Feature/Services/Pricing/ComposerStepConstraintTest.php
php artisan test tests/Feature/Services/Menu
npx vitest run tests/js/posRuptureUx.spec.js tests/js/kioskRuptureUx.spec.js tests/js/kioskWizardGenericComposer.spec.js
php artisan foodking:outbox:rescue
php artisan foodking:outbox:retry-failed --since=1h
```

## Non-negotiables

- Product/choice rupture must be branch-scoped where relevant.
- POS/Kiosk UI must never be the only guard.
- Backend pricing must reject stale choices.
- Historical order snapshots must stay readable after catalog changes.
- Stock release must be idempotent.


```

---

## docs/sync/API_VS_MCP_DECISION.md

```
# API vs MCP Runtime Decision — Version A

Status: canonical VA-SYS-09 decision.

## Decision

FoodKing runtime surfaces use Laravel API + branch-scoped WebSocket/outbox + fallback polling.

MCP is not the production runtime bus for POS, Kiosk, KDS, OSS, stock, catalogue, or payment state.

## Why API/outbox stays the runtime contract

| Requirement | API/outbox fit | MCP issue for runtime |
| --- | --- | --- |
| POS/Kiosk device independence | Browser calls Laravel API directly | Requires local/remote agent infrastructure |
| Branch isolation | Laravel auth, policies, `routes/channels.php` | Must be rebuilt or proxied |
| Fiscal and audit trace | DB transactions, audit logs, domain_events | MCP calls alone are not fiscal event storage |
| Replay/retry | `domain_events`, rescue, retry-failed | MCP is not an outbox queue by default |
| Offline/fallback | REST polling and local snapshots | MCP availability would be another dependency |
| Existing tests | PHPUnit/Vitest/Playwright target API/events | MCP would need a parallel contract |

## Allowed MCP usage

MCP can be used for:

- developer/agent memory through Graphiti;
- local automation and audits;
- imports/admin tooling behind explicit operators;
- external system adapters if they ultimately call the same API contracts.

MCP must not bypass:

- backend pricing SSOT;
- branch authorization;
- fiscal sequence rules;
- outbox persistence for runtime sync;
- order/payment state machines.

## Current runtime protocols

| Flow | Protocol |
| --- | --- |
| Dashboard CRUD | Laravel admin API |
| Kiosk menu/order | Laravel kiosk/frontend API + machine token |
| POS order/payment | Laravel admin/POS API |
| KDS sync | Laravel API + branch private channel + fallback sync |
| OSS | Laravel API + branch private channel |
| Realtime | `domain_events` outbox -> `DispatchDomainEventsJob` -> broadcaster |
| Recovery | `foodking:outbox:rescue`, `foodking:outbox:retry-failed` |

## Gate for future MCP runtime adapter

Any future MCP adapter must prove:

1. It calls the same backend services or public APIs.
2. It cannot submit client-side prices as authority.
3. It cannot read/write cross-branch data.
4. It persists auditable events in the same domain/outbox path.
5. It has tests equivalent to current API/E2E suites.

Until then, MCP is an orchestration/devtool layer, not a replacement for API.


```

---

## docs/sync/CENTRAL_MANAGEMENT_RUNBOOK.md

```
# Central Management Runbook — Version A

Status: canonical VA-SYS-09 operations runbook.

## Fast map

| Area | Primary files |
| --- | --- |
| Product CRUD/photo | `ItemController`, `ItemService`, `ItemResource`, `NormalItemResource` |
| Category CRUD | `ItemCategoryController`, `ItemCategory` |
| Composer | `ComposerProfileController`, `ComposerStepController`, `ComposerProfileService`, `ComposerStepService` |
| Projections | `MenuProjectionService`, `KioskMenuService`, `ComposerProfileProjection` |
| Stock/availability | `AvailabilityService`, `StockService`, `ChoiceAvailabilityResolver` |
| Outbox/realtime | `DomainEvent`, `DispatchDomainEventsJob`, `Persist*ToOutbox` listeners |
| KDS fallback | `KdsSyncController`, `KdsSyncService.php`, `KdsSyncService.js` |
| Branch auth | `AdminController::authorizeBranchScope`, routes middleware, `routes/channels.php` |

## Symptom runbook

### Product not visible on kiosk

Check:

1. `items.status` is active.
2. Category is active and visible on `kiosk`.
3. Product `channels` is null or contains `kiosk`.
4. Kiosk machine belongs to expected branch.
5. `item_branch_availability` is not unavailable for that branch.
6. Kiosk cache `kiosk.menu.branch.{branchId}` is invalidated or force refresh.
7. `CatalogChanged` or `ItemAvailabilityChanged` exists in `domain_events`.

Commands:

```bash
php artisan test tests/Feature/Services/Menu
php artisan test tests/Feature/Menu/CatalogStockCentralSyncEndToEndTest.php
php artisan foodking:outbox:rescue
```

### Product visible on POS but not kiosk

Check:

1. Channel filters differ: `pos` vs `kiosk`.
2. Kiosk-specific category label/sort does not hide category.
3. Kiosk projection uses branch from machine token, not query input.
4. POS may have stale local items; kiosk may have offline snapshot.

Files:

- `MenuProjectionService`
- `KioskMenuService`
- `resources/js/store/modules/kioskMenu.js`
- `resources/js/components/admin/pos/PosComponent.vue`

### Wizard not visible

Check:

1. A published `ItemWizardProfile` exists for the product.
2. Profile `branch_id_scope` matches actor/branch.
3. `ComposerProfileController::show` returns own/global profile, not latest foreign.
4. `ComposerProfileProjection` includes steps/options.
5. Kiosk/POS projection has `composer_profile`.
6. Browser is not using stale menu cache.

Commands:

```bash
php artisan test tests/Feature/Composer
php artisan test tests/Feature/ItemAttributeComposerResourceTest.php
php artisan test tests/Feature/Services/Pricing/ComposerStepConstraintTest.php
```

### Photo not updated

Check:

1. Actor has global photo permission policy (`Admin`/`Tenant Admin` in V1).
2. `ItemController::changeImage` succeeded.
3. Stored media URL changed.
4. `CatalogChanged`/photo invalidation event exists.
5. Kiosk/POS refreshed projection or cache was invalidated.

Commands:

```bash
php artisan test tests/Feature/Catalog/ProductPhotoAuthzTest.php
php artisan test tests/Feature/Catalog/PhotoEndToEndKioskInvalidationTest.php
```

### Stock not synced

Check:

1. Is it product-level or choice-level stock?
2. Product-level: inspect `item_branch_availability`.
3. Choice-level: inspect `stock_levels` with stockable type/id.
4. `StockLevelChanged`, `ItemAvailabilityChanged`, or `CatalogChanged` exists in `domain_events`.
5. Kiosk cache key and menu snapshot bumped.
6. POS/Kiosk frontend shows disabled state; backend pricing rejects direct submit.

Commands:

```bash
php artisan test tests/Feature/Stock
php artisan test tests/Feature/Services/Pricing/ComposerStepConstraintTest.php
npx vitest run tests/js/posRuptureUx.spec.js tests/js/kioskRuptureUx.spec.js
```

### KDS not receiving order

Check:

1. Order exists and branch is correct.
2. `OrderCreated` row exists in `domain_events`.
3. `dispatched_at` is not null, or `last_error` explains provider failure.
4. Queue worker processed `DispatchDomainEventsJob`.
5. KDS user/channel auth can subscribe to branch.
6. Fallback `KdsSyncService` can fetch the order without WebSocket.
7. No throttle/429 on KDS sync.

Commands:

```bash
php artisan test tests/Feature/KioskRealtimeBroadcastTest.php
php artisan test tests/Feature/SyncComprehensiveTest.php
php artisan foodking:outbox:rescue
php artisan foodking:outbox:retry-failed --since=1h
npx playwright test tests/e2e/c3-runtime-multi-surface.spec.js --repeat-each=2 --retries=0
```

### Queue number duplicate alert

Check:

1. DB unique constraint for branch/day/queue number path is present in current rollout.
2. Redis/cache lock driver is shared in production.
3. POS and Kiosk both use symmetric allocation/retry.
4. Idempotency key did not replay as a new order.
5. Inspect retry logs and failed inserts.

Commands:

```bash
php artisan test tests/Feature/ProdLike/ProdLikeConcurrencyTest.php
php artisan test --filter=QueueNumber
```

## Branch isolation checklist

| Actor | Expected behavior |
| --- | --- |
| Branch admin/user | Own branch data only |
| Branch user with null branch mutation | Scope to own branch, not all branches |
| Admin/Tenant Admin | Global or all branches where route allows |
| Kiosk machine | Machine branch only |
| KDS/POS user | Own branch private channel only |

Critical VA-SYS-07B tests:

- `DashboardBranchScopeMatrixTest`
- `AvailabilityToggleAuthzMatrixTest`
- `ProductPhotoAuthzTest`
- `ComposerAuthzMinimalTest`
- `AdminItemBranchAvailabilityProjectionTest`

## Final support rule

If a symptom is runtime sync related, inspect in this order:

1. Backend DB state.
2. Backend projection response.
3. `domain_events` row.
4. Queue/provider delivery.
5. Frontend dedupe/cache/fallback.
6. Browser DOM.


```

---

## docs/sync/VERSION_A_SYNC_VALIDATION_MATRIX_2026-04-30.md

```
# FoodKing Version A — Sync Validation Matrix — 2026-04-30

## Etat global logiciel

| Mission | Etat | Commentaire |
| --- | --- | --- |
| VA-SYS-06 | PASS_LOCAL | Produit + choix wizard stockables, rupture et backend pricing reject |
| VA-SYS-07 | PASS_LOCAL_STRONG | Authz gestion centrale, branch scope, photo globale, composer show |
| VA-SYS-08 | PASS_RUNTIME_LOCAL_STRONG | Outbox prod-like, realtime contracts, C3 multi-surface local |
| VA-SYS-09 | PASS_DOCS_MEMORY | Runbooks et memoire de centralisation synchronisation |
| VA-SYS-10 | PENDING | Validation finale massive a lancer apres cette doc |

## Ce qui est valide fortement

| Fonction | Preuve principale | Niveau |
| --- | --- | --- |
| Rupture produit | Stock/Menu/Pricing/JS tests VA-SYS-06 | Fort local |
| Rupture choix wizard | ChoiceAvailabilityResolver + Pricing reject + POS/Kiosk UX tests | Fort local |
| Backend pricing SSOT | ComposerStepConstraint + anti-forge flows | Fort local |
| Branch isolation gestion | VA-SYS-07B authz matrix | Fort local |
| Photo produit globale | ProductPhotoAuthz + Photo E2E invalidation | Fort local |
| Composer branch profile show | ComposerAuthzMinimalTest et controller hardening | Fort local |
| Outbox failure/retry | OutboxProductionLikeSimulationTest | Fort local |
| Event contract/dedupe | EventContract PHPUnit + Vitest realtime | Fort local |
| C3 multi-surface | Playwright C3 repeat local | Runtime local |

## Points encore non fermes avant `VERSION_A_SYSTEM_SOFTWARE: PASS_READY_FOR_HARDWARE_INDUSTRIAL_UAT`

| Point | Priorite | Pourquoi | Mission |
| --- | --- | --- | --- |
| Dashboard workflow discovery complet | P1 | Connaitre les vrais selecteurs/workflows admin et eviter un test E2E fragile | VA-SYS-01 |
| Composer request contract hardening | P1 | Verrouiller payloads dashboard avant E2E complet | VA-SYS-02 |
| Wizard runtime contract final | P1 | Formaliser no-wizard/simple-wizard/complex-wizard | VA-SYS-03 |
| Dashboard builder UX hardening | P1/P2 | Eviter erreurs operateur pendant creation produit | VA-SYS-04 |
| Full dashboard-to-kiosk/POS/KDS E2E | P1 high | Prouver create product -> photo -> stock -> composer -> publish -> order -> KDS -> stock | VA-SYS-05 |
| Final massive validation | P0 close | Relancer les suites critiques et statuer PASS/REWORK | VA-SYS-10 |

## Matrice finale recommandee pour VA-SYS-10

### Backend PHP

```bash
php artisan test tests/Feature/Services/Menu
php artisan test tests/Feature/Services/Pricing/ComposerStepConstraintTest.php
php artisan test tests/Feature/Stock
php artisan test tests/Feature/Composer
php artisan test tests/Feature/Catalog
php artisan test tests/Feature/Menu
php artisan test tests/Feature/Outbox
php artisan test tests/Feature/EventContractTest.php
php artisan test tests/Unit/Domain/Events/EventContractUnitTest.php
php artisan test tests/Feature/AfterCommitDispatchTest.php
php artisan test tests/Feature/DispatchAfterCommitTest.php
php artisan test tests/Feature/SyncComprehensiveTest.php
php artisan test tests/Feature/KioskRealtimeBroadcastTest.php
```

### Frontend JS

```bash
npx vitest run \
  tests/js/posRuptureUx.spec.js \
  tests/js/kioskRuptureUx.spec.js \
  tests/js/kioskWizardGenericComposer.spec.js \
  tests/js/posWizardComposerProfile.spec.js \
  tests/js/eventContractDedupe.spec.js \
  tests/js/correlationDedupePersistence.spec.js \
  tests/js/correlationDedupeCapacity.spec.js \
  tests/js/realtimeBroadcastFallback.spec.js \
  tests/js/kdsReactsToReconnectStorm.spec.js \
  tests/js/kdsBackoffOn5xx.spec.js \
  tests/js/kdsSyncCadence.spec.js \
  tests/js/kdsVersionGate.spec.js
```

### Runtime local

```bash
php artisan serve --host=127.0.0.1 --port=8000
npx playwright test tests/e2e/c3-runtime-multi-surface.spec.js --repeat-each=2 --retries=0
```

### Hygiene

```bash
npm run production
git diff --check
```

## Go / no-go logiciel

`GO_LOCAL_SOFTWARE` exige:

- VA-SYS-06/07/08/09 PASS;
- VA-SYS-05 E2E central complet PASS ou explicitement decoupe en sous-preuves equivalentes;
- VA-SYS-10 final report PASS;
- aucune regression P0/P1 sur pricing, branch_id, fiscal, stock, queue number, outbox.

`HOLD_HARDWARE_UAT` reste normal tant que:

- TPE reel non teste;
- imprimante fiscale non testee;
- device lockdown non teste;
- provider realtime cloud non teste;
- maps live non teste.


```

---

# INSTRUCTION D’AUDIT (à exécuter sur la base du contexte ci-dessus)

Tu es auditeur externe senior FoodKing. Fais un audit profond, adversarial et orienté orchestration finale du système centralisé data/sync/gestion produit.

Contexte:
- Repo: /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
- Hardware/TPE/imprimante/Google Maps live restent hors scope maintenant.
- Objectif actuel: finir le logiciel central avant UAT matériel.
- Ne valide pas aveuglément. Distingue PASS prouvé, PARTIAL, NOT_VALIDATED, REWORK.

Lis impérativement:
1. AGENTS.md
2. missions/VERSION-A-SYSTEM-FINISHING/TASKLIST.md
3. reports/audit/CODEX_VA_SYS_06_STOCKABLE_CHOICES_EXECUTION_2026-04-29.md
4. reports/audit/CODEX_VA_SYS_07B_EXTENDED_AUTHZ_EXECUTION_2026-04-30.md
5. reports/audit/CODEX_VA_SYS_08_REALTIME_OUTBOX_EXECUTION_2026-04-30.md
6. reports/audit/CODEX_VA_SYS_09_DOCS_RUNBOOK_MEMORY_2026-04-30.md
7. reports/audit/CODEX_VA_SYS_10_FINAL_MASSIVE_VALIDATION_2026-04-30.md
8. docs/sync/CATALOG_COMPOSER_DATA_FLOW.md
9. docs/sync/WIZARD_PRODUCT_MODEL.md
10. docs/sync/STOCK_SYNC_AND_AVAILABILITY.md
11. docs/sync/API_VS_MCP_DECISION.md
12. docs/sync/CENTRAL_MANAGEMENT_RUNBOOK.md
13. docs/sync/VERSION_A_SYNC_VALIDATION_MATRIX_2026-04-30.md

Ce qui est déjà livré par Codex:
- VA-SYS-06 PASS_LOCAL: rupture produit + rupture choix wizard stockable, backend pricing reject stale/forged/unavailable choices, POS/Kiosk guards.
- VA-SYS-07B PASS_LOCAL_STRONG: authz central management, branch dashboard scope, composer show no leak, availability fanout scoped, photo produit réservée rôles globaux.
- VA-SYS-08 PASS_RUNTIME_LOCAL_STRONG: test prod-like outbox, rescue/retry, provider failure recovery, CatalogChanged fanout active branches, contract violation before broadcast, C3 runtime local.
- VA-SYS-09 PASS_DOCS_MEMORY: docs sync canoniques, décision API/outbox/realtime et non MCP runtime.
- VA-SYS-10 PASS_CORE_SYNC_VALIDATION_WITH_REMAINING_SYSTEM_GATES: 175 PHP PASS, 42 Vitest PASS, Playwright C3 4 PASS, npm production PASS. Mais VA-SYS-00..05 restent ouverts.

Audit demandé:
1. Vérifie si le verdict Codex est cohérent: core sync/data PASS mais système complet HOLD.
2. Audite spécifiquement la centralisation:
   - ajout/modification/suppression produit
   - catégories
   - photos
   - stock produit
   - stock choix wizard
   - composer/wizard produit simple vs complexe
   - projection POS/Kiosk/KDS/OSS
   - outbox/realtime/fallback
   - branch isolation
   - historique/snapshots
   - docs/sync/API_VS_MCP_DECISION.md (obligatoire : un sous-paragraphe dédié « API vs MCP runtime », cohérence avec VA-SYS-09, ce qui est NOT_VALIDATED hors doc)
3. Identifie les risques P0/P1/P2 restants avant de dire logiciel prêt.
4. Donne un plan d'exécution précis pour VA-SYS-00 à VA-SYS-05.
5. Pour chaque mission, donne:
   - objectif exact
   - fichiers à lire/modifier
   - tests à créer/lancer
   - critères PASS/REWORK
   - ordre d'exécution
   - risques business
6. Ne propose pas hardware maintenant sauf comme liste UAT séparée.
7. Si un point est déjà prouvé par les tests/rapports, dis PASS avec preuve fichier/test.
8. Si un point manque, dis NOT_VALIDATED et donne la mission Codex exacte pour le fermer.
9. Termine OBLIGATOIREMENT par les trois lignes suivantes (dernières lignes du livrable Markdown, texte brut, une variable par ligne, pas de tiret liste, pas de code fence) :
   MASTER_AUDIT_VERDICT: PASS | REWORK
   SOFTWARE_DECISION: READY_FOR_VA_SYS_00_05_EXECUTION | HOLD
   NEXT_CODEX_MISSION: <TASK_ID ou phrase courte>

   Sans ces trois lignes exactes, le livrable est REJECT (non conforme). Choisis une valeur unique pour chaque clé (pas de « | » dans la valeur sauf pour VERDICT et SOFTWARE où tu choisis UNE option).

--- RAPPEL FINAL (même règle que §9, ne pas ignorer) ---

Les trois dernières lignes du document DOIVENT être :

MASTER_AUDIT_VERDICT: PASS
ou MASTER_AUDIT_VERDICT: REWORK

SOFTWARE_DECISION: READY_FOR_VA_SYS_00_05_EXECUTION
ou SOFTWARE_DECISION: HOLD

NEXT_CODEX_MISSION: VA-SYS-00 (ou autre TASK_ID pertinent)
