# RAPPORT MAX ORCHESTRATION — MÉGA PLAN V1 CAISSE / POS / KIOSK / KDS

Date : 2026-04-25 — Cycle : préparation `PLAN_CAISSE_V1_MEGA_CORRECTION_2026-04-25` — Orchestrateur : Claude (Opus 4.7, terminal PRIMARY).

Sources lues (intégralement ou par délégation Explore avec synthèses distillées) :
- `AGENTS.md`, `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`, `.cursor/ACTIVE_CYCLE.md`, `docs/DOC_EXPO_HER_ANCIEN_AGENT_ALIMENTATION_WORKFLOW_2026-04-22.md`
- `reports/audit/MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP_SINGLE_FILE_2026-04-25.md`
- `reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md`
- `reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md`
- `reports/audit/CHALLENGE_MASTER_CHECKLIST_DEEP_SINGLE_2026-04-25.md`
- `reports/audit/MEGA_DISPUTE_CODEX_R1_CAISSE_POS_KIOSK_KDS_2026-04-25.md`
- `reports/audit/MEGA_RAPPORT_FINAL_DISPUTE_CAISSE_POS_KIOSK_KDS_2026-04-25.md`
- `reports/audit/MEGA_HANDOFF_CONTEXT_INTEGRATION_CAISSE_POS_KIOSK_KDS_2026-04-25.md`
- `reports/audit/MEGA_PLAN_READINESS_GAP_ANALYSIS_CAISSE_POS_KIOSK_KDS_2026-04-25.md`
- `reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_BRIEF_2026-04-26.md`, `MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md`
- `plans/PLAN_MASTER_FINITIONS_POS_KDS_AVANT_LANCEMENT_2026-04-26.md`
- `docs/ORDER_FLOW.md`, `docs/BUSINESS_RULES.md`, `docs/AUTHZ_MATRIX.md`, `docs/API_MAP.md`, `docs/DATABASE_SCHEMA_CORE.md`
- `docs/orchestration/EXPORT_HANDOFF_POS_KDS_MASTER_FINITIONS_2026-04-26.md`

`FICHIERS_MANQUANTS` : aucun fichier obligatoire absent. `docs/TEST_PLAN.md`, `docs/MASSIVE_TEST_PLAN.md`, `docs/orchestration/MEMORY_MATRIX.md` existent mais n'ont pas été relus directement pour ce cycle (cités en référence Phase 0, non nécessaires pour le verdict).

---

## A — Verdict de préparation

**Sommes-nous prêts à rédiger le méga plan ?** Oui. Les rapports d'audit (caisse ultra-profond, kiosk connecté, checklist challenge, dispute Codex R1, rapport final, handoff, gap analysis) convergent sur un même ensemble cohérent de 24–30 findings P0/P1 couvrant POS/Kiosk/KDS/fiscal/sync. Les invariants FoodKing sont cartographiés. Les primitives domaine candidates (`OrderIntent`, `OrderQuote`, `PaymentLedger`, `KitchenTicketCreated`) sont discutées avec contre-arguments. Le chemin critique plan→exécution est identifié.

**Sommes-nous prêts à implémenter ?** Non. Cinq gates humains bloquent toute mutation de code produit : (1) frozen zones consolidées, (2) politique fiscale kiosk, (3) scope `PaymentLedger` V1, (4) autorité bump KDS, (5) migrations schéma V1 (quote, payment, queue sequence, parked expires). Deux gates POS finitions déjà pendantes (FIND-02 `GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20`, FIND-03 `GATE_PAYMENT_PROP_MUTATION_2026-04-26`) doivent être jugées avant toute intervention sur `PaymentComponent.vue` ou `OrderService.php`.

**Quels gates bloquent ?** Les cinq gates ci-dessus + les deux POS finitions = 7 décisions humaines avant tout patch produit. Aucune n'est triviale ; toutes ont des alternatives qu'il faut trancher.

**Niveau de risque V1.** Élevé sur trois axes : (a) revenue/fraude (`payment-confirm` sans ability, discount forgeable, Stripe cents si actif, offline CB/TR non réconciliable) ; (b) data leakage (`OrderService::list` LIKE, `show` sans guard, `branch_id=0` sans role Admin) ; (c) intégrité fiscale kiosk (ventes hors Z, cleanup 15 min vs confirm tardif, cash PAID à la création). Le risque opérationnel KDS (cap 50, version sub-seconde) est moindre mais réel. Le risque architectural (surarchitecture précoce des primitives Codex) est présent si on commence par les contrats avant la sécurité.

**Jugement orchestrateur.** Les fondations sont saines : pricing SSOT backend existant, outbox `domain_events` post-commit mature, optimistic lock KDS, 811 tests Vitest + ~940 PHPUnit, invariants lint guards. Les 24+ findings ne sont **pas architecturaux** (pas de refonte) mais **contractuels, sécuritaires et UX**. Un méga plan V1 réaliste tient en 10 phases sur 6–10 semaines équipe dédiée, en commençant par sentinelles rouges + security P0, puis contrats (quote/ledger/release) ciblés, pas inversement comme le pousse Codex dans sa R1.

`CLAUDE_READINESS_VERDICT: READY_TO_PLAN`

---

## B — Contestation Codex

| Thèse Codex | Accepté | Contesté | Décision Claude | Preuve / chemin |
|---|---|---|---|---|
| Le problème est systémique (5 moments métier compressés dans 2 champs) — T-001 R1 | ✅ | — | ACCEPTÉ. Cadre diagnostique juste. | `reports/audit/MEGA_DISPUTE_CODEX_R1_CAISSE_POS_KIOSK_KDS_2026-04-25.md`, `app/Enums/OrderStatus.php`, `app/Enums/PaymentStatus.php` |
| `OrderIntent` en P0 architecture avant tout refactor — T-002 | Partiel | ⚠️ | P1 V1 : on peut prouver les divergences POS/kiosk/web/table via tests contractuels sans refactor centralisé. Centralisation P2 post-V1. | POS/kiosk payloads divergents déjà documentés ; `OrderService::createOrder`, `FrontendOrderService::createOrder` |
| `OrderQuote` backend signé/expiré avant paiement — T-003 | ✅ | — | P0 obligatoire V1. Sans quote, le POS encaisse un total local HT (F-001). Gate schéma requis. | `PaymentComponent.vue`, `PosComponent.vue` total local, `PricingService.php` SSOT existante mais non exposée quote-first |
| `PaymentLedger` complet en V1 OU restriction explicite — T-004 | Partiel | ⚠️ | **HUMAN SPLIT**. Option A ledger minimal V1 (~2 sem). Option B pilote restreint (cash-only POS, pas de CB kiosk offline, pas de refund split) + ledger V2. Claude recommande **B pour livrer V1** si pilote mono-branche. | Kiosk audit KIOSK-DEEP-003/015/028, POS F-003, `PaymentService.php` binary |
| `KitchenTicketCreated` event explicite — T-005 | Partiel | ⚠️ | P1 V1 : règle ACCEPT formalisée + test statique suffit si POS+kiosk cash seulement. P0 si web/table/kiosk cash complexes. Ne pas précéder les P0 sécurité. | `PersistOrderStatusChangedToOutbox`, filtre KDS `status IN (ACCEPT,PREPARING,PREPARED)` |
| Branch/Device exact sur list/show/export/report — T-006 | ✅ | — | P0 immédiat. Fuite directe. `OrderService::list` utilise `LIKE '%'.$branchId.'%'`, `show` sans guard. Pas discutable. | `OrderService.php:151`, `:1330-1346`, `TransactionService` |
| `payment-confirm` durcir machine/order/method avant mutation — T-007 | ✅ | — | P0 immédiat. Route manque `abilities:kiosk:order` + non-kiosk peut forcer PAID. | `routes/api.php:889-895`, `OrderController::paymentConfirm` |
| KDS whitelist PREPARING/PREPARED strict + POS route collect séparée — T-008 | ✅ | — | P0. Chef peut aujourd'hui cancel/deliver via KDS. POS utilise endpoint KDS `change-status/DELIVERED` pour collecter cash kiosk (couplage métier fautif). | `PosComponent.vue:1414-1421`, `OrderStatusRequest.php`, `KitchenDisplaySystemController.php` |
| Kiosk fiscal/Z gate obligatoire — T-009 | ✅ | — | GATE HUMAIN P0 incontournable. Ventes kiosk PAID aujourd'hui hors Z = trou fiscal. | `ZReportService::aggregate` filtre `whereNotNull('fiscal_sequence_no')`, `FrontendOrderService::finalizePaidKioskOrder` |
| Offline CB/TR non réconciliable → désactiver ou signed queue — T-010 | ✅ | — | P0. Désactivation V1 la plus sûre. Signed queue = V2. | `kioskPaymentComponent.vue:447-577`, `kioskOfflineQueue.js` |
| Queue number unique par branche/date — T-011 | ✅ | — | P0 si rush ; P1 si pilote mono-branche faible volume. Migration index unique + retry. | POS F-004, lock microtime fallback `OrderService` |
| Legacy quarantine avant linting — T-012 | ✅ | — | P0 process (pas code). Bandeau `ARCHIVE_NON_AUTHORITATIVE` sur `kiosk_implementation/**`, `borne (Remix)/**` avant tout agent autonome. | Archives repo |
| Kiosk cash confond paiement/fulfilment — T-013 | ✅ | — | P0. Route POS dédiée, KDS endpoint ne doit jamais collecter paiement. | Même finding que T-008 |
| KDS cap 50 sans pagination — T-014 | Partiel | ⚠️ | P1 V1 : alerte overflow + monitoring suffit pour pilote. P0 si multi-branches rush. | `KitchenDisplaySystemComponent.vue`, limite 50 hardcodée |
| Stripe cents bug (×100 sur décimal) — T-015 | ✅ si Stripe actif | — | P0 **pré-activation**. Si Stripe gelé V1 → P2. Gate fonctionnalité Stripe à acter. | POS F-011 |
| Verdict Codex `NEEDS_HUMAN_GATES_THEN_READY_TO_PLAN` | ✅ | — | ACCEPTÉ. Même verdict Claude. | `MEGA_DISPUTE_CODEX_R1:9`, `MEGA_RAPPORT_FINAL_DISPUTE:21` |
| Ordre tactique Codex R1 : contrats avant security P0 | — | ❌ | **CONTESTÉ FORT**. On fait security P0 d'abord (quick wins à haut impact), puis contrats ciblés. Rapport Final (MEGA_RAPPORT_FINAL) arbitre déjà en faveur de Claude. | `MEGA_RAPPORT_FINAL_DISPUTE_CAISSE_POS_KIOSK_KDS_2026-04-25.md` section 7 |
| Graphiti absent du plan V1 | — | ❌ | Graphiti **doit** rester en boucle : lire `search_memory_facts` avant PLAN ; écrire après CLOSE. Non négociable (cf. `GLOBAL_SYSTEM_PRIMER.md`). | `memory/INDEX.md`, `AGENTS.md` Parcours obligatoire |
| Tests sentinelles rouges avant patch (16 items Codex) | ✅ | — | ACCEPTÉ. Mais sentinelles exécutables en Phase 1, pas bloqueur Phase 0 si déjà listés. | Lot 1 Rapport Final p.76-95 |
| Availability release incomplète (release cancel/refund non idempotent) | Partiel | ⚠️ | NEEDS_EVIDENCE. Audit code à refaire avant repatch aveugle — `ItemAvailabilityService` + `release_tracking` migration 2026-04-23 ont déjà été durcis. | `migrations/2026_04_23_100000_add_release_tracking_to_order_items.php`, `ItemAvailabilityService` |
| POS `discountReason` v-model cassé (FIND-01 finitions) | ✅ | — | ACCEPTÉ. Quick win immédiat, pas de gate. LOT-0. | `resources/js/components/admin/pos/PosComponent.vue:781,1668` |
| POS `PaymentComponent` 16+ mutations props (FIND-03) | ✅ | — | GATE P0 : Option A emit+parent vs Option B copie locale. Bloque refactor PaymentComponent. | `PaymentComponent.vue`, `docs/gates/GATE_PAYMENT_PROP_MUTATION_2026-04-26.md` |

---

## C — Définition V1 fonctionnelle

Une V1 fonctionnelle réelle pour FoodKing signifie : (1) un opérateur POS encaisse une commande multi-items dans une branche donnée, avec prix, taxes et remise **intégralement recalculés backend via un quote signé**, paiement cash ou carte validé, ticket imprimé, transitions `ACCEPT → PREPARING → PREPARED → DELIVERED` propres et auditées. (2) un kiosk connecté mono-machine prend une commande cash au comptoir (ou carte si gate T-004 option B autorise), dépose une commande PENDING au backend, route la cuisine via un signal de release explicite, et réconcilie la collecte cash par une route POS dédiée (pas l'endpoint KDS). (3) le KDS affiche uniquement les tickets de sa branche, accepte uniquement `PREPARING/PREPARED` avec `expected_status` obligatoire, paginé/alerté au-delà de 50 tickets. (4) le paiement garantit idempotence composite `(branch_id, idempotency_key)`, aucun double encaissement, aucune mutation `PAID` sans preuve (transaction_ref, machine resolved, method cohérent, status source valide). (5) le fiscal produit un rapport Z par branche avec tous les ordres PAID ou tout-POS-seulement selon gate T-009, HMAC chaîné, numéro séquentiel monotone, sceau annule les destructions. (6) l'isolation branche est stricte : `OrderService::list/show/export`, `TransactionService`, KDS, canaux Echo `private-branch.{id}`, rate-limits — plus aucune fuite LIKE ni `branch_id=0` sans role Admin. (7) les tests passent (Feature POS void/drawer/NFC/parked, Feature KDS transitions/stations, Kiosk payment negative paths, invariants lint, Playwright 5 flows critiques) et `check_invariants.sh` reste vert. (8) le cutover legacy est fait : `kiosk_implementation/**`, `borne (Remix)/**`, `public/js/pos-wizard.js`, routes web `/payment/{order}/pay` sont quarantainés ou supprimés du chemin actif ; `POS_WIZARD_CONFIG` n'expose plus de prix client. (9) l'ops est prête : `APP_ENV=production php artisan app:preflight-production --strict` vert ; workers `high/notifications/default` actifs ; scheduler `foodking:outbox:rescue`, `cleanup-stale-pending-orders`, `parked-purge`, fiscal archive tournent ; `sync_metrics` purge active. (10) les observables existent : `correlation_id` bout-en-bout, dashboards outbox latency p50/p95/p99, `kds.sync_fallback_p50`, alertes 401/broadcast/storm.

---

## D — P0/P1/P2 consolidé

| Priorité | Sujet | Pourquoi | Risque si ignoré | Preuve attendue | Phase |
|---|---|---|---|---|---|
| **P0** | `payment-confirm` sans `abilities:kiosk:order` + validation machine/order/method/status/transaction | Non-kiosk force PAID, machine A paie ordre branche B | Fraude directe, fiscal trompé | Test négatif Feature 403/422 + test concurrent idempotent | 2 |
| **P0** | `OrderService::list` LIKE, `show` sans guard, `TransactionService` sans scope | Fuite données inter-branches | Violation multi-tenant, RGPD | Tests isolation branch 1 vs 10 sur list/show/export/report | 2 |
| **P0** | `branch_id=0` accepté KDS/OSS sans role Admin | Staff mal provisionné voit global | Leak cross-branch | Test 403 si `branch_id=0` sans Admin | 2 |
| **P0** | Discount forgeable via subtotal client (F-002) | Remise non autorisée applicable | Perte revenue | Test backend refuse discount avec subtotal forgé | 2 |
| **P0** | POS total local HT avant quote backend (F-001) | Encaissement sur montant faux, taxes absentes | Sous-encaissement, litige client | `POST /api/admin/pos/quote` + test modal lit `quote.total_ttc` | 3 |
| **P0** | `OrderQuote` contrat signé/expiré/hash intent | Pas de source vérité prix exposée POS/kiosk | Divergence preview/final | Migration + test quote expiré refuse paiement | 3 |
| **P0** | Stripe cents `decimal * 100` faux (F-011) | Montant facturé faux si Stripe actif | Pertes ou overcharge | Test 10.99 EUR → 1099 cents | 4 (si Stripe actif) |
| **P0** | `PaymentLedger` V1 OU restriction pilote (gate T-004) | Pas de refund/split/void ni rapprochement TPE | Ledger faux, fiscal corrompu | Gate tranché + migration minimale OU restriction documentée | 4 |
| **P0** | Web `/payment/{order}/pay` publique par id nu | Enumeration paiement | Fraude externe | `PaymentIntent` signé + test route non-publique | 4 |
| **P0** | Fiscal kiosk hors Z (T-009) | Ventes PAID sans fiscal_sequence_no | Trou Z, amende NF525 | Gate tranché + test Z inclut kiosk selon option A/B/C | 6 |
| **P0** | Cleanup stale pending 15 min vs confirm tardif | TPE encaissé + order auto-REJECTED | Cash volant, litige | Test confirm après cleanup → refuse + log reconcile | 2 |
| **P0** | KDS accepte `OrderStatusRequest` global (Chef cancel/deliver) | Chef casse état métier | Corruption statut | Whitelist PREPARING/PREPARED + test 403 sur autres | 5 |
| **P0** | `expected_status` KDS non obligatoire | Race conditions KDS | Double bump, ordre perdu | Test 409 Conflict si `expected_status` mismatch | 5 |
| **P0** | Status `16` (CANCELED) hardcodé kiosk (F-015) | Violation enum, régression silencieuse | Drift futur | Test lint + remplace par enum partagé | 6 |
| **P0** | Offline CB/TR non réconciliable (F-014) | TPE charge sans commande backend | Pertes comptables | Désactivation V1 + test offline cash-only OK, CB/TR bloqué | 2 |
| **P0** | POS collecte cash kiosk via KDS endpoint (F-007) | Couplage métier paiement/cuisine | Bugs, audit flou | Route POS dédiée + test KDS endpoint refuse cash | 2 |
| **P0** | `POS_WIZARD_CONFIG` expose prix client (F-016) | Wizard calcule total côté client | Violation SSOT | Removal + test guard pricing frontend | 2 |
| **P0** | Offline id kiosk format UUID ≠ `offline_*` | Commande offline détectée online | TPE vs offline confusion | Préfixe `offline_` + regex alignée + test | 6 |
| **P0** | Menu legacy endpoints encore consommés kiosk | Catalogue non-branché | Items indisponibles visibles, promo zéro | Forcer `/frontend/menu` unique + test | 6 |
| **P0** | Frozen zones gate pending FIND-02/03 | Cycles P0 non signés | Bloque refactor Order/Payment | Signatures TL+Backend+QA NF525 | 0 |
| **P1** | Queue number non unique en rush (F-004) | Duplicates possibles | Confusion OSS/KDS | Migration unique `(branch_id, date, queue_no)` + retry | 2 |
| **P1** | Idempotency catch non branch-scoped (F-005) | Race cross-branch | Duplicates cross-tenant | Ajout `branch_id` au catch query + test | 2 |
| **P1** | `OrderStatusRequest` numeric broad | Surface trop large | Transitions invalides | Rule::in par surface | 5 |
| **P1** | KDS sync version en secondes (F-023) | Dedupe même-seconde perd event | Ordre invisible | Version monotone sub-seconde + test | 5 |
| **P1** | KDS cap 50 overflow silencieux (F-028) | Tickets cachés rush | Retard cuisine | Pagination + alerte visible + test volume | 5 |
| **P1** | Admin KDS global en polling (F-024) | Retard opérationnel multi-branch | UX admin | Realtime parity + test dispatch | 5 |
| **P1** | Kiosk promo UI lit `discount` pas `discount_amount` | Promo affichée zéro | Fraud perception | Fix lecture + test UI réelle | 6 |
| **P1** | Analytics kiosk v2 sans whitelist/auth beacon | Events perdus/401 silencieux | Funnel aveugle | Whitelist + axios authentifié + test | 6 |
| **P1** | Tests Feature POS manquants (FIND-08) | Couverture insuffisante | Bugs cachés | `VoidOrderTest`, `CashDrawerTest`, `CustomerNfcLookupTest`, `ParkedOrderResumeTest` | 8 |
| **P1** | Tests Feature KDS manquants (FIND-13) | Couverture insuffisante | Régressions transitions | `KdsStatusTransitionTest`, `KdsStationRoutingTest`, `KdsConcurrentUpdateTest` | 8 |
| **P1** | Hardware campaign absente (TPE/imprimante/drawer) | Pas de smoke pré-prod réel | Blocage terrain | Lab validé + logs smoke | 8 |
| **P1** | Receipt persist sans TTL kiosk | PII dernier client visible suivant | Confidentialité | TTL 30s + purge + test | 6 |
| **P1** | Credit wallet double-débit callback concurrent (F-018) | Pertes ou double-crédit | Finance | Lock + test concurrency | 4 |
| **P2** | Bengali `bn.json` clés KDS manquantes (FIND-05) | UX KDS dégradée locale | UX | Traduction + flag REVIEW_PENDING | 8 |
| **P2** | Swiper KDS `dir="ltr"` hardcoded (FIND-09) | RTL arabe cassé | UX | `:dir="swiperDir"` + test | 8 |
| **P2** | `kioskFormatPrice.js` hardcode EUR/fr-FR (FIND-04) | Toutes branches en EUR | i18n | Store branch currency + test | 6 |
| **P2** | Focus trap POS import mort (FIND-06) | WCAG 2.1 §2.1.2 | A11y | Instanciation 4 modals + test | 8 |
| **P2** | `sync_metrics` croissance non bornée (FIND-10) | DB bloat | Perf | TTL job + test purge | 8 |
| **P2** | `pos_parked_orders.expires_at` absent (FIND-11) | Orphelines persistantes | Ops | Gate migration + setter 8h + purge | 8 |
| **P2** | Aggregators Uber/Deliveroo contrat mature | Hors scope V1 | Risque V2 | Doc gelée, pas actif | — |
| **P2** | Virtualisation listes > 100 items | Perf non critique pilote | UX | Backlog V1.5 | — |

---

## E — Méga plan phase par phase

Format : pour chaque phase = Objectif / Fichiers / Invariants touchés / Tâches / Tests / Risques / Gate sortie / Owners / Critère PASS-REWORK.

### Phase 0 — Gates, Scope, Sentinelles (SEMAINE 1)

- **Objectif.** Trancher 5 gates humains + 2 gates POS finitions pendantes ; définir scope V1 définitif ; produire le fichier `plans/PLAN_CAISSE_V1_MEGA_CORRECTION_2026-04-25.md` et ses `TASK_ID`.
- **Fichiers/zones.** `docs/gates/GATE_*.md` (5 nouveaux + 2 existants), `plans/PLAN_CAISSE_V1_MEGA_CORRECTION_2026-04-25.md`, `.cursor/ACTIVE_CYCLE.md` (ouverture cycle).
- **Invariants touchés.** Aucun code ; discipline cycle SSOT (`AGENTS.md`).
- **Tâches.** (a) rédiger gates : `GATE_FROZEN_ZONES_CAISSE_V1`, `GATE_FISCAL_KIOSK_V1`, `GATE_PAYMENT_LEDGER_V1`, `GATE_KDS_BUMP_V1`, `GATE_SCHEMA_MIGRATIONS_V1` ; (b) décision TL+BE+QA+UX sur FIND-02 et FIND-03 ; (c) bandeaux `ARCHIVE_NON_AUTHORITATIVE` sur `kiosk_implementation/**`, `borne (Remix)/**`, `_archive/ignored_legacy_web_orchestration/**` ; (d) Graphiti read (`search_memory_facts group_id=foodking`) pour contexte précédents cycles W9/PROD-1..4.
- **Tests.** Aucun code. Revue gate signée.
- **Risques.** Gate trainée > 5 j bloque 4 phases suivantes. Mitigation : deadlines explicites TL+Product J0.
- **Gate sortie.** 7 gates tranchés (approuvés OU rejetés OU option A/B/C choisie). Plan V1 accepté.
- **EXECUTE owner.** Humain (TL + Backend lead + QA NF525 + UX lead + Product owner).
- **AUDIT owner.** `claude-terminal` valide cohérence gates + plan.
- **PASS/REWORK.** PASS si 7 gates signés + plan cohérent avec gates. REWORK si incohérences ou option non tranchée.

### Phase 1 — Contrats domaine + Sentinelles rouges (SEMAINE 1–2)

- **Objectif.** Écrire les 16 tests sentinelles rouges prouvant chaque P0 AVANT tout patch ; poser les DTOs / événements minimaux (`OrderQuote` schema, `PaymentIntent` brouillon, règle `KitchenRelease` formalisée).
- **Fichiers.** `tests/Feature/Sentinel/*.php` (nouveau dossier), `tests/Feature/KioskPaymentStateMachineTest.php` (extend), `tests/Unit/OrderStateMachineTest.php` (extend), `database/migrations/*_create_order_quotes_table.php` (squelette, pas run), `app/Domain/Pricing/OrderQuote.php` (DTO), `app/Domain/Payment/PaymentIntent.php` (DTO si option A), `docs/CONTRACTS_V1.md`.
- **Invariants touchés.** Aucun en production (tests + DTOs).
- **Tâches.** (a) 16 sentinelles (voir section F) ; (b) DTOs domaine avec validation Laravel ; (c) règle `OrderStateMachine::release()` formalisée (ACCEPT → KDS visible) + test unitaire.
- **Tests.** 16 sentinelles rouges confirmées par CI (rouges avant patch, vertes après chaque phase suivante).
- **Risques.** Sentinelles trop larges → faux positifs. Mitigation : chaque sentinelle cible un finding unique, chemin précis.
- **Gate sortie.** CI atteint 16 tests sentinelles configurés, tous rouges avec message attendu.
- **EXECUTE owner.** `codex-extension` (implémentation tests + DTOs ; revue SSOT docs).
- **AUDIT owner.** `claude-terminal`.
- **PASS/REWORK.** PASS si 16 sentinelles rouges documentées et reproductibles. REWORK si sentinelles couvrent > ou < findings sans justification.

### Phase 2 — P0 Sécurité / Revenue immédiats (SEMAINE 2–3)

- **Objectif.** Fermer la fraude directe et les leaks inter-branches. 10 P0 security.
- **Fichiers.** `app/Services/OrderService.php` (méthodes `list`, `show`, catch idempotency, `changePaymentStatus`) ; `app/Services/TransactionService.php` ; `app/Http/Controllers/Frontend/OrderController.php` (`paymentConfirm`) ; `routes/api.php` (ajout `abilities:kiosk:order` sur `/payment-confirm`) ; `app/Http/Controllers/Admin/KitchenDisplaySystemController.php` + `SyncOverviewController.php` (align `branch_id=0` role Admin) ; `app/Http/Requests/PosOrderRequest.php` (discount subtotal server-side) ; `app/Http/Controllers/Admin/PosController.php` (route collect cash kiosk dédiée) ; `resources/js/components/admin/pos/PosComponent.vue:1414-1421` (appel nouvelle route) ; `resources/js/components/kiosk/KioskPaymentComponent.vue:447-577` (désactive CB/TR offline) ; `public/js/pos-wizard.js` (retirer prix client) ; `resources/views/admin/pos/pos-v4.blade.php` (nettoyer `POS_WIZARD_CONFIG`) ; `app/Jobs/CleanupStalePendingKioskOrders.php:19-58` (refuser confirm post-REJECTED + log).
- **Invariants touchés.** `OrderService`, `TransactionService` (frozen ; gate FIND-02 requise). `FrontendOrderService` partiel. Symétrie `OrderService` / `FrontendOrderService` à préserver (`.cursor/rules/project-invariants.mdc`).
- **Tâches.** (a) `payment-confirm` : `abilities:kiosk:order` + machine resolve + branch match + method match + status source valide + transaction_ref unique ; (b) `OrderService::list` : remplacer LIKE par `where('branch_id', =)` + actor default ; (c) `show` : ajout guard branche ; (d) idempotency catch : `branch_id` dans query ; (e) `branch_id=0` gate role Admin KDS/OSS ; (f) `PosOrderRequest` : subtotal backend, pas accepté dans payload ; (g) route POS `POST /api/admin/pos-order/collect-kiosk-cash/{order}` dédiée ; (h) désactivation offline CB/TR (feature flag `KIOSK_OFFLINE_CARD=false` env) ; (i) nettoyage `POS_WIZARD_CONFIG` prix ; (j) cleanup race fix.
- **Tests.** Sentinelles 1–11 passent. Régression full PHPUnit + Vitest. Ajout tests cross-branch list/show/export. Kiosk `payment-confirm` non-kiosk owner → 403 ; machine A ≠ branche B → refus ; cash order → refus ; concurrent même transaction → idempotent.
- **Risques.** Frozen zones : sans gate FIND-02, bloquer. Symétrie POS/Kiosk manquée → cycle REWORK. Mitigation : PR par finding, audit atomique.
- **Gate sortie.** Sentinelles 1–11 vertes ; pas de régression ; `AUDIT_VERDICT: PASS` terminal.
- **EXECUTE owner.** `codex-extension` (primary) ; fallback `foodking-complex-implementer`.
- **AUDIT owner.** `claude-terminal` via `foodking-claude-orchestrate.sh audit`.
- **PASS/REWORK.** PASS si 10 P0 fermés avec evidence. REWORK si un seul finding laissé en "partial fix".

### Phase 3 — OrderQuote + Paiement POS correct (SEMAINE 3–4)

- **Objectif.** Rendre le paiement POS impossible sans quote backend signé. Éliminer F-001.
- **Fichiers.** `database/migrations/*_create_order_quotes_table.php` (run après gate schéma) ; `app/Models/OrderQuote.php` ; `app/Services/OrderQuoteService.php` ; `app/Http/Controllers/Admin/PosController.php` (`quote`, modification `store`) ; `routes/api.php` (ajout `POST /api/admin/pos/quote`) ; `resources/js/components/admin/pos/PosComponent.vue` (fetch quote avant ouverture modal) ; `resources/js/components/admin/pos/PaymentComponent.vue` (lit `quote.total_ttc`) ; `app/Services/PricingService.php` (expose `buildQuote`).
- **Invariants touchés.** Prix SSOT backend (renforcement). `OrderService::createOrder` doit refuser commande sans `quote_id` valide (ajout paramètre). Symétrie kiosk `FrontendOrderService` : quote kiosk (optionnel ou P1) ?
- **Tâches.** (a) schéma `order_quotes` (branch_id, actor_id, intent_hash, subtotal, discount, tax_cascade JSON, total_ttc, currency, expires_at) ; (b) `OrderQuoteService::issue()`, `::consume()`, signature HMAC ; (c) route `POST /api/admin/pos/quote` acteur branch-scoped ; (d) `OrderService::createOrder` consomme quote (idempotent, expiration, hash intent) ; (e) modal paiement POS lit `quote.total_ttc` + disabled si quote expirée ; (f) discount reason server-enforced via PosOrderRequest + quote.
- **Tests.** Sentinelles 5, 6 vertes. Ajouts : quote expiré → refuse paiement ; double consommation → erreur ; forged intent_hash → refuse ; régression `OrderStateMachineApplyTest`, `FrontendDiscountIntegrityTest`.
- **Risques.** Changement contrat POST `/api/admin/pos-order` ; feature flag `POS_QUOTE_REQUIRED=true` avec fallback legacy pendant rollout.
- **Gate sortie.** F-001, F-002 fermés avec evidence. Sentinelles 5, 6 vertes.
- **EXECUTE owner.** `codex-extension`.
- **AUDIT owner.** `claude-terminal`.
- **PASS/REWORK.** PASS si POS impossible de payer sans quote valide et modal affiche `total_ttc`. REWORK si fallback legacy laissé ouvert > 7 jours.

### Phase 4 — Paiement V1 / Fiscal / PaymentIntent (SEMAINE 4–5)

- **Objectif.** Matérialiser la décision gate T-004 (ledger minimal OU restriction pilote) ; signer les paiements publics web ; corriger Stripe cents si actif ; guard credit wallet double-débit.
- **Fichiers.** Si option A ledger minimal : `database/migrations/*_create_payment_attempts_table.php`, `*_create_payment_transactions_table.php` ; `app/Domain/Payment/PaymentLedger.php` ; `app/Services/PaymentService.php` refactor ; `app/Enums/PaymentStatus.php` enrichissement (pending, authorized, captured, voided, refunded). Si option B restriction : feature flags `POS_CASH_ONLY=true`, `KIOSK_CARD_OFFLINE=false`, `POS_REFUNDS=false`, `POS_SPLIT_TENDER=false` + docs `docs/V1_SCOPE_RESTRICTIONS.md`. Commun : `routes/web.php` (`/payment/{order}/pay` → route signée `PaymentIntent` token) ; `app/Services/Payment/StripeGateway.php` (cents arrondi `(int) round($amount * 100)`) ; `app/Services/CreditWalletService.php` (lock). 
- **Invariants touchés.** `PaymentService` frozen (gate FIND-02). Symétrie cash/card POS/kiosk.
- **Tâches.** (a) option A : ledger tables + service + state machine + migration `orders.payment_transaction_id` ; ou (b) option B : flags + docs + gating backend strict. (c) route `/payment/{order}/pay` → `/payment/intent/{token}` signé HMAC ; (d) Stripe cents fix ; (e) CreditWallet `lockForUpdate` + idempotency key.
- **Tests.** Sentinelles 7, 9 passent. Test Stripe 10.99 → 1099. Test PaymentIntent token invalide → 403. Test CreditWallet concurrent → un seul débit.
- **Risques.** Option A = 2 sem minimum. Option B = limite V1 drastique. Décision humaine Phase 0 obligatoire.
- **Gate sortie.** Gate T-004 implémentée ; Stripe OK ou désactivé ; route publique par id nu supprimée.
- **EXECUTE owner.** `codex-extension`.
- **AUDIT owner.** `claude-terminal`.
- **PASS/REWORK.** PASS si F-011, F-010 fermés ; option T-004 traçable ; CreditWallet test concurrency vert. REWORK si option B mal documentée.

### Phase 5 — KDS Whitelist + Release + Rush (SEMAINE 5–6)

- **Objectif.** Rendre le KDS sûr en concurrence, visible en rush, strictement scopé.
- **Fichiers.** `app/Http/Requests/OrderStatusRequest.php` (Rule::in par surface ; `expected_status` required pour KDS) ; `app/Services/KitchenDisplaySystemOrderService.php` (whitelist PREPARING/PREPARED ; version monotone micro timestamp) ; `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` (pagination + alerte >50 ; admin realtime parity) ; `resources/js/services/KdsSyncService.js` (version sub-seconde) ; `app/Models/OrderStateMachine.php` (règle ACCEPT → release formalisée).
- **Invariants touchés.** `OrderStatus` enum unique ; pas de magic int.
- **Tâches.** (a) whitelist KDS ; (b) `expected_status` mandatory 409 Conflict ; (c) version monotone (microtime ou row version) ; (d) pagination KDS (limit 200, overflow banner) ; (e) admin global realtime parity (subscribe all branch channels admin) ; (f) `OrderStateMachine` : règle `isReleasedToKitchen()` + test ; événement `KitchenTicketCreated` si gate T-005 option A, sinon documentation stricte ACCEPT.
- **Tests.** Sentinelles 13, 14 vertes. `KdsChangeStatusConcurrencyTest` extended (même-seconde transitions). Test Chef cancel/deliver → 403. Test overflow >50 → alerte visible.
- **Risques.** `expected_status` mandatory peut casser anciens clients ; feature flag `KDS_EXPECTED_STATUS_REQUIRED` avec rollout graduel.
- **Gate sortie.** Sentinelles 13, 14 + KDS tests concurrency verts.
- **EXECUTE owner.** `codex-extension`.
- **AUDIT owner.** `claude-terminal`.
- **PASS/REWORK.** PASS si tout KDS filtre strict branche + whitelist. REWORK si admin realtime parity absente.

### Phase 6 — Kiosk Runtime (SEMAINE 6–7)

- **Objectif.** Rendre le kiosk fiscalement correct, promo correcte, offline maîtrisé, enum propre.
- **Fichiers.** `resources/js/stores/kioskCart.js` (offline id `offline_{ts}_{rand}`, promo lit `discount_amount`) ; `resources/js/router/modules/kioskRoutes.js` (regex `^(offline_\d+_\w+)|\d+$`) ; `resources/js/components/kiosk/KioskPaymentComponent.vue:447-577` (remplacer status `16` par `OrderStatus.CANCELED`, enum JS partagé) ; `resources/js/helpers/kioskPricing.js` (retirer fallback locaux) ; `resources/js/services/kioskMenu.js` (source unique `/frontend/menu`) ; `resources/js/services/kioskAnalytics.js` (whitelist v2, axios authentifié) ; `resources/js/components/kiosk/KioskWaitingComponent.vue` (receipt TTL 30s) ; `resources/js/helpers/kioskFormatPrice.js` (store branch currency) ; `app/Services/FrontendOrderService.php` (finalize kiosk selon gate fiscal T-009) ; `app/Http/Controllers/Auth/KioskMachineLoginController.php` (rotation machine token si gate).
- **Invariants touchés.** `OrderStatus` enum JS↔PHP parité ; prix backend SSOT.
- **Tâches.** (a) offline id format fix ; (b) promo UI `discount_amount` ; (c) enum partagé JS `resources/js/domain/OrderStatus.js` généré ou manuel ; (d) menu unique ; (e) analytics v2 whitelist + auth ; (f) TTL reçu ; (g) locale store branch ; (h) finalize kiosk selon gate fiscal ; (i) désactivation `sendBeacon` sans Authorization.
- **Tests.** Sentinelles 12, 16 vertes. `kioskOfflineQueue.spec.js` extend (format réel). `KioskFullFlowE2ETest` extend. Vitest enum parity test.
- **Risques.** Gate fiscal T-009 option C (désactiver autonomie payante kiosk) simplifie mais retire feature produit. Mitigation : décision humaine tranchée.
- **Gate sortie.** Sentinelles 12, 16 vertes ; promo UI correcte ; fiscal kiosk conforme au gate.
- **EXECUTE owner.** `codex-extension` (JS complexes) + `foodking-routine-implementer` (JSON locales).
- **AUDIT owner.** `claude-terminal`.
- **PASS/REWORK.** PASS si UI/offline/fiscal alignés gate. REWORK si drift status `16` laissé.

### Phase 7 — Legacy cutover + Feature flags (SEMAINE 7)

- **Objectif.** Retirer ou quarantainer tous les chemins legacy pouvant réintroduire régressions ; stabiliser feature flags V1.
- **Fichiers.** `public/js/pos-wizard.js` (suppression ou archive banner) ; `resources/views/admin/pos/*_v3.blade.php` (suppression) ; `routes/web.php` (`/payment/{order}/pay` retiré) ; `kiosk_implementation/**` (banner `ARCHIVE_NON_AUTHORITATIVE` visible + `.gitattributes linguist-generated=true`) ; `borne (Remix)/**` (idem) ; `_archive/ignored_legacy_web_orchestration/**` (idem) ; `config/features.php` (nouveau, centralise flags V1 : `POS_QUOTE_REQUIRED`, `KIOSK_OFFLINE_CARD`, `KDS_EXPECTED_STATUS_REQUIRED`, etc.) ; `app/Providers/AppServiceProvider.php` (fail-fast si flag incohérent prod).
- **Invariants touchés.** Discipline code (pas produit).
- **Tâches.** (a) quarantaine ; (b) `config/features.php` SSOT flags ; (c) fail-fast prod ; (d) docs `docs/V1_FEATURE_FLAGS.md`.
- **Tests.** Test `config/features.php` cohérence prod ; lint interdit `public/js/pos-wizard.js` dans les bundles actifs.
- **Risques.** Oubli d'un chemin actif utilisé par une blade legacy. Mitigation : audit grep systématique.
- **Gate sortie.** Aucun chemin legacy actif côté serveur. Flags centralisés.
- **EXECUTE owner.** `codex-extension`.
- **AUDIT owner.** `claude-terminal`.
- **PASS/REWORK.** PASS si grep `pos-wizard.js` et `/payment/{order}/pay` ne rend aucun résultat actif.

### Phase 8 — Qualification V1 / Tests / Hardware (SEMAINE 7–9)

- **Objectif.** Couverture tests au seuil ; campagne hardware ; observabilité minimale ; finitions UX bloquantes.
- **Fichiers.** `tests/Feature/Pos/VoidOrderTest.php`, `CashDrawerTest.php`, `CustomerNfcLookupTest.php`, `ParkedOrderResumeTest.php` ; `tests/Feature/KDS/KdsStatusTransitionTest.php`, `KdsStationRoutingTest.php`, `KdsConcurrentUpdateTest.php` ; `tests/js/kiosk*`, `tests/e2e/playwright/pos-flow.spec.ts`, `kiosk-flow.spec.ts`, `kds-flow.spec.ts`, `fiscal-z.spec.ts`, `branch-isolation.spec.ts` ; `resources/js/languages/bn.json` (27 clés KDS) ; `KitchenDisplaySystemComponent.vue` (Swiper `:dir=swiperDir`) ; `PosComponent.vue` + modals (focustrap instanciation) ; `database/migrations/*_add_expires_at_to_pos_parked_orders.php` (gate GATE_POS_PARKED_EXPIRES_AT) ; `app/Jobs/SyncMetricsPurgeJob.php` (TTL 30j) ; `config/logging.php` (correlation_id end-to-end).
- **Invariants touchés.** Couverture tests (voir `.cursor/rules/global.mdc`).
- **Tâches.** (a) 7 nouveaux Feature POS/KDS tests ; (b) 5 flows Playwright critiques ; (c) Bengali + RTL Swiper ; (d) focustrap 4 modals ; (e) migration parked expires_at + job purge ; (f) sync_metrics purge ; (g) correlation_id header bout-en-bout ; (h) campagne hardware lab : TPE réel, imprimante ESC/POS, tiroir, kiosk machine token, smoke documentés dans `reports/hardware/LAB_SMOKE_2026-XX.md`.
- **Tests.** Suite complète PHPUnit + Vitest + Playwright verte. `check_invariants.sh` vert.
- **Risques.** Hardware absent → décalage go-live. Mitigation : lab commandé semaine 1 Phase 0.
- **Gate sortie.** Tous tests verts ; hardware smoke signé humain ; A11y focustrap OK ; observability présent.
- **EXECUTE owner.** `codex-extension` + `foodking-routine-implementer` (JSON locales) + humain (hardware).
- **AUDIT owner.** `claude-terminal`.
- **PASS/REWORK.** PASS si suite verte + lab smoke signé. REWORK si un seul flow Playwright critique flaky.

### Phase 9 — Close / Gate / Go-NoGo (SEMAINE 9–10)

- **Objectif.** Preflight production, ops ready, décision Go/NoGo humaine finale.
- **Fichiers.** `app/Console/Commands/PreflightProductionCommand.php` (run `--strict`) ; `.env.production` (review) ; `config/broadcasting.php`, `config/queue.php`, `config/cache.php`, `config/fiscal.php` (review) ; `docs/RUNBOOK_V1_GO_NOGO.md` ; `reports/execution/V1_CAISSE_GO_NOGO_2026-XX.md`.
- **Invariants touchés.** Production readiness.
- **Tâches.** (a) `APP_ENV=production php artisan app:preflight-production --strict` vert ; (b) workers `high/notifications/default` actifs ; (c) scheduler actif (`outbox:rescue`, `cleanup-stale-pending`, `parked-purge`, `fiscal-archive`) ; (d) dashboards observability vérifiés (outbox p50/p95/p99, ws auth failures, KDS sync fallback) ; (e) runbook Go/NoGo ; (f) gate humain final.
- **Tests.** Preflight vert. Smoke staging.
- **Risques.** Config prod incohérente (BROADCAST_DRIVER, QUEUE_CONNECTION). Mitigation : preflight `--strict`.
- **Gate sortie.** Humain GO V1 ; `AUDIT_VERDICT: PASS` final + ingestion Graphiti JSONL (`09_tasks_history.jsonl`, `11_production_plan.jsonl`, `12_decisions_log.jsonl`).
- **EXECUTE owner.** `codex-extension` (preflight) + Humain (gate GO).
- **AUDIT owner.** `claude-terminal` (audit terminal) + Humain (signature GO).
- **PASS/REWORK.** PASS si preflight + dashboards + runbook + gate humain OK.

---

## F — Plan de tests et preuves

| Preuve | Type test | Surface | Commande probable | Bloquant ? | Pourquoi |
|---|---|---|---|---|---|
| Sentinelle 1 : `payment-confirm` non-kiosk owner → 403 | Feature PHP | Kiosk | `php artisan test --filter=SentinelPaymentConfirmOwnership` | Oui P0 | Prouve fraude kiosk bouchée |
| Sentinelle 2 : `payment-confirm` machine A ≠ branche B | Feature PHP | Kiosk | `--filter=SentinelPaymentConfirmCrossBranch` | Oui P0 | Isolation multi-tenant |
| Sentinelle 3 : `payment-confirm` cash order → refus | Feature PHP | Kiosk | `--filter=SentinelPaymentConfirmCashRefuse` | Oui P0 | Couplage payé/cuisine brisé |
| Sentinelle 4 : concurrent même transaction → idempotent | Feature PHP concurrency | Kiosk | `--filter=SentinelPaymentConfirmIdempotent` | Oui P0 | Double-PAID évité |
| Sentinelle 5 : POS subtotal forgé discount → 403 | Feature PHP | POS | `--filter=SentinelPosForgedDiscount` | Oui P0 | Revenue protection |
| Sentinelle 6 : POS modal sans `quote_id` → refuse | Feature PHP + Vitest | POS | `--filter=SentinelPosQuoteRequired`, `npm run test:unit -- pos.payment` | Oui P0 | Backend SSOT |
| Sentinelle 7 : branche 1 list/show/export branche 10 → 0 fuite | Feature PHP | POS/Admin | `--filter=SentinelBranchIsolation` | Oui P0 | Multi-tenant |
| Sentinelle 8 : TransactionService staff sans param → scoped | Feature PHP | POS | `--filter=SentinelTransactionScope` | Oui P0 | Leak report |
| Sentinelle 9 : Chef KDS cancel/deliver → 403 | Feature PHP | KDS | `--filter=SentinelKdsWhitelist` | Oui P0 | Intégrité statut |
| Sentinelle 10 : POS collect cash kiosk ≠ endpoint KDS | Feature PHP | POS/Kiosk | `--filter=SentinelPosKioskCashCollect` | Oui P0 | Séparation métier |
| Sentinelle 11 : double cancel/refund → pas double cashback | Feature PHP concurrency | POS | `--filter=SentinelDoubleCancelIdempotent` | Oui P0 | Finance |
| Sentinelle 12 : kiosk offline id `offline_<ts>_<rand>` routable | Vitest | Kiosk | `npm run test:unit -- kioskOfflineId` | Oui P0 | UX cohérence offline |
| Sentinelle 13 : KDS >50 tickets → alerte overflow | Vitest + Feature | KDS | `npm run test:unit -- kdsOverflow`, `--filter=KdsOverflowTest` | Oui P1 | Rush visibilité |
| Sentinelle 14 : pending OrderCreated → pas ticket cuisine | Feature PHP | KDS/Outbox | `--filter=SentinelKitchenReleaseRule` | Oui P0 | Règle ACCEPT formalisée |
| Sentinelle 15 : idempotency kiosk payment → un seul dispatch | Feature PHP | Kiosk/Outbox | `--filter=SentinelKioskIdempotentDispatch` | Oui P0 | Double event évité |
| Sentinelle 16 : queue_number collision branche/date → retry unique | Feature PHP concurrency | POS | `--filter=SentinelQueueNumberUnique` | Oui P0/P1 | Rush robustesse |
| Playwright flow POS : encaissement cash + impression | Playwright critique | POS | `npx playwright test pos-flow.spec.ts` | Oui | E2E réel |
| Playwright flow kiosk : commande cash + ticket | Playwright critique | Kiosk | `kiosk-flow.spec.ts` | Oui | E2E réel |
| Playwright flow KDS : bump ACCEPT→PREPARING→PREPARED | Playwright critique | KDS | `kds-flow.spec.ts` | Oui | E2E réel |
| Playwright fiscal : open Z, vente, close Z, HMAC chain | Playwright critique | Fiscal | `fiscal-z.spec.ts` | Oui | NF525 |
| Playwright isolation : user branche 1 / branche 10 | Playwright critique | Sécurité | `branch-isolation.spec.ts` | Oui | Multi-tenant |
| Hardware smoke TPE/imprimante/drawer | Human + log | POS | Manuel lab | Oui | Go-live terrain |
| Hardware smoke kiosk machine + scanner + admin PIN | Human + log | Kiosk | Manuel lab | Oui | Go-live terrain |
| Preflight production strict | Artisan | Ops | `php artisan app:preflight-production --strict` | Oui | Go-live |
| Invariants lint | Shell | Repo | `bash scripts/check-invariants.sh` | Oui | Drift detection |
| Régression full PHPUnit | PHPUnit | Repo | `php artisan test --parallel` | Oui | Baseline 940+ tests |
| Régression full Vitest | Vitest | Repo | `npm run test:unit` | Oui | Baseline 815+ tests |
| Correlation_id bout-en-bout | Integration | Observability | `--filter=EnsureCorrelationIdPropagates` | Non (P1) | Observabilité |
| Sync_metrics TTL purge | Feature | Observability | `--filter=SyncMetricsPurgeJob` | Non (P2) | DB bloat |
| CreditWallet concurrent debit | Feature concurrency | Paiement | `--filter=CreditWalletConcurrencyTest` | Oui P1 | Finance |

---

## G — Gates humains nécessaires

| Gate | Décision exacte | Options | Recommandation Claude | Impact plan | Peut-on coder avant ? |
|---|---|---|---|---|---|
| `GATE_FROZEN_ZONES_CAISSE_V1` (consolide FIND-02) | Ouvrir frozen zones `OrderService`, `FrontendOrderService`, `PaymentService`, `PricingService`, `changeStatus`, coupons, idempotency pour ce cycle V1 | A) Ouvrir sous contrôle cycle/run-cycle — B) Refuser (impasse) — C) Ouvrir partielle (lister méthodes autorisées) | **A** avec lock par run-cycle et audit terminal obligatoire | Débloque Phases 2, 3, 4, 5 | Non — aucun patch produit sans ce gate |
| `GATE_FISCAL_KIOSK_V1` (T-009) | Ventes kiosk PAID entrent où dans Z ? | A) Kiosk Z autonome (branche = kiosk) — B) POS finalise / consolide — C) Désactiver autonomie payante kiosk V1 (kiosk ticket non fiscal) | **B** si POS opérationnel, **C** si pas prêt. A = coûteux NF525 | Phase 6 FrontendOrderService, Phase 8 Playwright fiscal | Non — bloque kiosk payant |
| `GATE_PAYMENT_LEDGER_V1` (T-004) | Ledger complet V1 ou restriction pilote ? | A) Ledger minimal (attempts + transactions, state machine) ~2 sem — B) Restriction pilote (cash-only POS, pas CB kiosk offline, pas split/refund V1, ledger V2) — C) Mixte (ledger attempts seulement, transactions V2) | **B pour livrer V1 pilote mono-branche**, **A pour multi-branches prod complète** | Phase 4 schéma, Phase 2 flags | Non — bloque paiement hors cash |
| `GATE_KDS_BUMP_V1` (T-005/008) | Bump autorité local écran ou serveur ? | A) Local single-screen — B) Serveur multi-écrans avec `expected_status` strict | **B** (robuste rush multi-écrans) | Phase 5 KDS | Partiel — whitelist P0 peut partir, bump decision V1 avant Phase 5 |
| `GATE_SCHEMA_MIGRATIONS_V1` | Autoriser migrations `order_quotes`, `payment_*` (option A), `pos_parked_orders.expires_at`, `queue_sequence` unique ? | A) Toutes V1 — B) Subset (quote + parked, reste V2) — C) Refuser (fallback memory) | **A** | Phase 1, 3, 4, 8 | Non — bloque Phase 3 et 4 |
| `GATE_PAYMENT_PROP_MUTATION_2026-04-26` (FIND-03 existant) | Refactor mutations props `PaymentComponent.vue` ? | A) Emit vers parent (one-way data flow) — B) Copie locale props vers data() | **A** (Vue.js idiomatique) | Phase 2 POS P0 UI | Non — bloque refactor UI paiement |
| `GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20` (FIND-02 existant) | Signer les 8 cycles P0 antérieurs (coupons, discount, pricing, idempotency, OrderService, PaymentService, DiscountCalculator) | A) Signer tous — B) Signer subset — C) Refuser | **A** (bloc technique bouclé) | Préalable Phase 2 | Non — même raison |

---

## H — Stratégie d'implémentation Codex/Claude

**Principe.** Claude orchestre ; Codex/Cursor exécutent sous contrôle cycle SSOT (`AGENTS.md`). Claude audit terminal est le second filet qualité après l'auto-audit Codex. Graphiti est lu avant chaque PLAN complexe et écrit après chaque CLOSE durable.

**Protocole séquence cycle.**

1. **Créer plan** `plans/PLAN_CAISSE_V1_MEGA_CORRECTION_2026-04-25.md` avec : identifiant, `## PRIOR_CONTEXT` (Graphiti `search_memory_facts group_ids=["foodking"]`), scope V1, invariants cités, 10 phases avec TASK_IDs, gates de sortie, risk log, test strategy par phase.

2. **Découper en `TASK_ID`** (un par lot fonctionnel). Nomenclature : `CAISSE_V1_P<phase>_<scope>_2026-XX-XX`. Exemple : `CAISSE_V1_P2_PAYMENT_CONFIRM_HARDEN_2026-04-27`, `CAISSE_V1_P3_ORDER_QUOTE_BACKEND_2026-05-04`. Chaque TASK_ID = un cycle `run-cycle` complet.

3. **Mission Codex par phase.** Pour chaque TASK_ID : (a) écrire `missions/<TASK_ID>/input.json` avec plan pointer, invariants touchés, fichiers autorisés, interdictions explicites (ex : "ne pas toucher `PricingService::buildQuote` avant mission dédiée"), test strategy, `EXECUTE_DELEGATION: codex-extension` (ou fallback). (b) Lancer `npm run codex:complex -- <TASK_ID>` (PRIMARY). (c) Si `codex-extension` indisponible après 2 tentatives → fallback `foodking-complex-implementer` + `EXECUTE_DELEGATION: foodking-complex-implementer (codex-extension-fallback)` + `FALLBACK_REASON:` obligatoire.

4. **Auto-audit Codex.** Codex produit `reports/audit/GPT_SELF_AUDIT_<TASK_ID>.md` avec : findings auto-détectés, correctifs appliqués, tests ajoutés, invariants vérifiés. Claude lit ce fichier AVANT VALIDATE (non négociable).

5. **Validation locale.** `check_invariants.sh` vert ; `php artisan test --filter=<TASK_SENTINEL>` vert ; Vitest ciblé vert ; `scripts/post-execute-guard.sh` trace OK ; `scripts/agent-activity-log.sh done <TASK_ID>`.

6. **Audit Claude terminal.** `bash scripts/foodking-claude-orchestrate.sh context && audit-brief` puis `audit` complet si brief ambigu. Claude produit `reports/audit/AUDIT_<TASK_ID>_CLAUDE_<DATE>.md` avec verdict `PASS` ou `REWORK` + findings supplémentaires.

7. **REWORK loop.** Si `AUDIT_VERDICT: REWORK` : Claude relance PLAN minimal (delta), mission Codex ciblée sur findings, nouveau auto-audit, nouveau audit Claude. Max 5 boucles (`REMEDIATION_AUDIT_CYCLE` 1..5). Au 5ème REWORK sans PASS → `HUMAN_GATE_ESCALATION` avec rédaction gate dédié.

8. **Gate humain** (7 gates Phase 0 + gates intra-phase si nouveau risque détecté). Humain signe `docs/gates/GATE_<ID>.md`. Claude ne signe jamais lui-même (cf. CLAUDE.md § "never self-approve a human gate").

9. **Close.** Cycle clôturé avec `AUDIT_VERDICT: PASS` final, ingestion Graphiti via `bash scripts/after-execute-memory.sh` → `bin/graphiti-ingest.sh`. Mise à jour JSONL : `09_tasks_history.jsonl`, `12_decisions_log.jsonl`, `11_production_plan.jsonl` si rollout touché, `13_agents_roles.jsonl` si protocole évolué. `.cursor/ACTIVE_CYCLE.md` mis à jour avec prochain TASK_ID.

**Règles transversales.**
- Tokens : qualité max, pas de troncature plan/risk. Résumé handoff entre phases obligatoire.
- Context hygiene : un fichier par phase (`reports/execution/PHASE_<N>_<TASK_ID>.md`).
- Multi-agent sync : `scripts/agent-activity-log.sh start/done` pour éviter collisions cross-agent (Cursor + Claude + Codex).
- Test strategy : Claude décide le niveau (`no-test`, `static-inspection`, `local-validation`, `playwright-mcp`, `playwright-critical-flow`, `playwright-full-e2e`, `human-verification`). V1 par défaut = `playwright-critical-flow` + `local-validation`.

---

## I — Risques de surarchitecture / sous-architecture

### Où NE PAS surconstruire pour V1

| Zone | Tentation | Ce qu'on fait V1 | Pourquoi |
|---|---|---|---|
| `OrderIntent` centralisé | Refactor complet POS/kiosk/web/table vers DTO commun | Tests contractuels comparant payloads + documentation divergences ; DTO seulement POS quote | Coût refactor > bénéfice V1 ; divergences gérables par tests |
| `PaymentLedger` complet | Ledger attempts + transactions + state machine + gateway adapters | **Option B** gate T-004 : restriction cash-only + désactivation offline CB/TR ; ledger V2 | V1 livre avec scope réduit ; ledger complet = 2 sem isolées |
| `KitchenTicketCreated` event | Nouvelle table + event + outbox listener + fan-out KDS | Règle `OrderStateMachine::isReleasedToKitchen()` formalisée + test unitaire + filtre KDS `status IN (ACCEPT,PREPARING,PREPARED)` documenté | Complexité event séparé sans bénéfice V1 si POS+kiosk seulement |
| Queue number sharding | Redis sequencer, quorum | `lockForUpdate` + retry + migration index unique `(branch_id, date, queue_no)` | Pilot mono-branche faible volume |
| KDS multi-écrans bump server | Full row version + broadcast conflict resolution | `expected_status` required + version sub-seconde + 409 Conflict | Couvre 90% des cas rush sans overhead |
| PaymentIntent gateway adapter complet | Stripe/Adyen/Worldline abstractions génériques | PaymentIntent token HMAC signé route publique + gateway actif (Stripe seul si activé) | V2 ajoutera adapters |
| Aggregators Uber/Deliveroo | Contrat complet + webhook idempotent + reconciliation | Hors scope V1 documenté `docs/V1_SCOPE_RESTRICTIONS.md` | Pas prioritaire pilote |
| Virtualisation KDS/POS listes | react-window-like + offscreen rendering | Pagination 200 + alerte overflow | V1.5 si mesure prouve besoin |
| Observability complète APM | Sentry breadcrumbs + OpenTelemetry traces | Correlation_id header + dashboards outbox + alertes 401/storm | Baseline suffit pilote |
| Refactor complet `PaymentComponent.vue` | Découper en 6 sous-composants | Option A gate FIND-03 : emit + parent controls ; pas de découpage | Cible atomique du gate |

### Où un patch minimal serait DANGEREUX

| Zone | Tentation | Ce qu'on fait V1 | Pourquoi |
|---|---|---|---|
| `payment-confirm` garde simple `auth:sanctum` | Ajouter uniquement `abilities:kiosk:order` | Ajouter **ability + machine resolve + branch match + method match + status source valide + transaction_ref unique** | Le vrai risque est multi-vectoriel ; ability seule ne suffit pas |
| `OrderService::list` LIKE → `=` basique | Remplacer LIKE par `=` | Remplacer LIKE par `=` **+ actor branch default + test cross-surface export/report/transaction** | LIKE n'est qu'une manifestation ; vérifier toutes surfaces |
| Stripe cents fix `(int) ($amount * 100)` | Cast int direct | `(int) round($amount * 100)` + test 10.99 → 1099 + test 0.30 → 30 | Flottants PHP : `0.1 + 0.2 = 0.30000000000000004` → cast int = 30 faux |
| `OrderQuote` sans expiration | Hash intent + signature seule | Expiration 5 min + consume idempotent + revalidation promo au consume | Sans expiration : replay attack ou promo expirée consommée |
| Désactivation offline CB/TR flag seul | Feature flag `KIOSK_OFFLINE_CARD=false` | Flag + **UI disabled visible client** + **guard backend refuse order avec offline_card payment_method** + test | Flag seul peut être contourné ; backend doit être dernière ligne |
| Fiscal kiosk option C (désactivation) | Enlever bouton paiement UI | Désactiver UI + **backend refuse `payment_method` CB/cash avec machine kiosk** + gate `ZReportService` cohérent | UI seule = contournement API direct |
| `expected_status` mandatory sans feature flag | Forcer 100% des clients | Rollout avec flag `KDS_EXPECTED_STATUS_REQUIRED` + deprecation log 7 jours | Casse clients legacy (bornes anciens firmware?) |
| Remove `POS_WIZARD_CONFIG` prix | Suppression config seule | Suppression + **guard lint `tools/lint/pos_pricing_guard.mjs`** qui échoue CI si réintroduction | Sans guard, régression triviale |
| CreditWallet lock simple | `lockForUpdate` sur ligne | Lock + **idempotency key par callback** + test concurrency sous charge | Lock seul + callback sans idempotency = double débit différent chemin |
| Menu kiosk legacy endpoint removal | Retirer appel `kioskMenu.js` | Retirer + **guard bundle analyze** (ne doit plus contenir endpoint legacy) + route backend soft 410 Gone | Sans guard, réintroduction UI ancienne |

---

## J — Questions finales pour humain

1. **GATE_FISCAL_KIOSK_V1** — Confirme-t-on l'option **C** (désactiver autonomie payante kiosk V1, kiosk = saisie commande + paiement comptoir POS) ? Si oui, délai acceptable vs besoin produit kiosk payant ? (Cette décision change Phase 4, Phase 6 et le scope tests fiscaux.)

2. **GATE_PAYMENT_LEDGER_V1** — Validation Option **B** (restriction pilote : cash-only POS, pas de CB kiosk offline, pas de split tender, pas de refund partiel V1) ? Ou scope V1 inclut multi-tender POS qui oblige ledger minimal Option A ?

3. **Scope cutover legacy** — `public/js/pos-wizard.js`, routes `POS V3`, `/payment/{order}/pay` public : suppression physique V1 ou simple quarantaine (banner + désactivation) ? Impact : users actuels de POS V3 ?

4. **Stripe V1** — Stripe activé en production au go-live V1 ? Si oui, Phase 4 inclut fix T-015 + tests gateway. Si non, Phase 4 simplifiée.

5. **Rollout multi-branches ou mono-branche pilote** — Le go-live V1 vise combien de branches simultanément ? Si pilote mono-branche, P1 queue_number / KDS cap 50 / admin realtime peuvent basculer P2. Si multi-branches direct, doivent rester P0/P1.

6. **Hardware lab** — Quand le lab TPE + imprimante ESC/POS + tiroir + kiosk machine est-il disponible ? Bloque Phase 8 (~1 sem). Si > semaine 7, shift go-live.

7. **Durée cycle 6–10 semaines acceptable** — Compatible roadmap produit et gates mobile/legal ? Sinon, quels P0 peuvent être requalifiés P1 avec mitigations documentées ?

---

## K — Verdict final

Le système FoodKing est architecturalement sain ; les 24+ findings P0/P1 sont **contractuels, sécuritaires et UX**, pas des refontes. Les rapports d'audit convergent suffisamment pour rédiger un méga plan V1 exécutable en 10 phases (6–10 semaines équipe dédiée). Sept gates humains (5 nouveaux + 2 POS finitions pendantes) conditionnent le démarrage de la Phase 2. Le plan doit suivre l'ordre tactique Claude (sécurité d'abord, contrats ciblés ensuite), contestant l'ordre R1 Codex qui privilégiait les primitives avant la sécurité. La stratégie Codex/Claude est celle du cycle SSOT `AGENTS.md` avec REWORK loop max 5 et fallback documenté. La V1 livrable est un pilote mono-branche avec scope paiement restreint (Option B recommandée gate T-004), fiscal kiosk désactivé ou consolidé POS (Option B/C gate T-009), tests sentinelles + Playwright critiques verts, preflight production strict vert. L'écriture du plan peut démarrer immédiatement ; l'implémentation doit attendre la Phase 0 gates.

`CLAUDE_MAX_ORCHESTRATION_VERDICT: READY_TO_WRITE_MEGA_PLAN`
