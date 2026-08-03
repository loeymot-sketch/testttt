# MEGA DISPUTE R1 — CODEX — Caisse V1 POS/Kiosk/KDS — 2026-04-25

## 0. Position

Codex positionne le chantier V1 caisse comme un problème de **contrats métier**, pas comme une suite de bugs isolés. Les rapports ajoutés montrent que corriger seulement `payment-confirm`, `branch_id LIKE`, KDS whitelist ou promo kiosk ne suffit pas si la caisse continue à encaisser sur un total local et si le transfert cuisine reste implicite via `OrderStatus`.

Verdict R1 Codex:

`CODEX_R1_VERDICT: NEEDS_HUMAN_GATES_THEN_READY_TO_PLAN`

Définition V1 exploitable:

1. POS, borne, web/table et KDS parlent le même langage d'intention de commande, de devis backend, de paiement et de release cuisine.
2. Aucun flux n'encaisse, ne marque `PAID`, ne libère vers KDS ou ne sort en Z sans preuve backend traçable.
3. Les chemins legacy ou indirects restent disponibles en lecture seulement ou sont explicitement mis hors runtime.

## 1. Inputs intégrés

| Source | Donnée ajoutée au raisonnement |
|---|---|
| `reports/audit/MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP_SINGLE_FILE_2026-04-25.md` | Chantier global caisse V1, invariants, cycles, fichiers cachés, config/scheduler/migrations/legacy, actions A-E |
| `reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:529+` | Audit page par page jusqu'au KDS, quote-first POS, ledger paiement, OrderIntent/OrderQuote/KitchenTicketCreated, KDS cap 50 |
| `reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md` | Borne comme module connecté, menu branché unique, prix local, offline id, `payment-confirm`, fiscal kiosk/Z, cash kiosk |
| `reports/audit/CHALLENGE_MASTER_CHECKLIST_DEEP_SINGLE_2026-04-25.md` | Master checklist A-O, gates/frozen, P0/P1/P2 consolidés |
| `reports/audit/CHALLENGE_RAPPORT_FINAL_DEEP_SINGLE_2026-04-25.md` | Deep findings initiaux: branch leaks, KDS whitelist, no-op cashback, promo parity, cleanup race |
| `docs/ORDER_FLOW.md`, `docs/DEVICE_FLOW.md`, `docs/BUSINESS_RULES.md` | Invariants statut, device roles, pricing SSOT, branch/fiscal |

## 2. Thèse Codex

### 2.1 Le problème central n'est pas KDS, POS ou borne séparément

Le système compresse aujourd'hui plusieurs moments métier dans deux champs trop pauvres:

- `orders.status` / `frontend_orders.status`
- `payment_status`

Cela masque cinq instants différents:

| Moment métier | Actuel | Risque |
|---|---|---|
| intention de commande | payload surface-specific POS/kiosk/web/table | divergence de payload et UX |
| devis backend autoritaire | seulement recalcul au submit final | POS peut afficher/encaisser sur total local |
| paiement prouvé | `payment_status=PAID` binaire | pas de ledger, pas de montant/provider unique |
| release cuisine | inféré par `status=ACCEPT` | KDS écoute une conséquence, pas un contrat |
| fulfilment/collecte | `DELIVERED` / endpoint KDS réutilisé | cash kiosk confond paiement, collecte et cuisine |

Donc la correction durable doit introduire les contrats suivants:

1. **OrderIntent**: payload commun et versionné pour POS/kiosk/web/table.
2. **OrderQuote**: devis backend signé/expiré avant paiement ou validation finale.
3. **PaymentLedger**: table/tender/payment attempt/transaction, pas seulement `payment_status`.
4. **KitchenTicketCreated** ou `kitchen_released_at`: release explicite vers KDS.
5. **Branch/Device principal**: toutes les actions sensibles résolvent la branche par acteur ou machine, pas par payload.

### 2.2 Mais la V1 ne peut pas attendre toute la V2

Il faut séparer:

- **P0 immédiats V1**: stopper corruption/fuite/perte directe.
- **P0 structurants V1/V2**: poser les contrats qui empêchent la dette de revenir.
- **P1/P2**: UX, observability, performance, docs, hardware.

## 3. Contestation des rapports existants

| Sujet | Rapport précédent | Contestation Codex |
|---|---|---|
| `payment-confirm` | classé P0, souvent limité à ability route | insuffisant: la mutation `PAID` doit être déplacée après validation méthode/status/machine/idempotence; service `finalizePaidKioskOrder` ne répare pas une mutation `PAID` déjà faite |
| KDS whitelist | focalisé sur Chef cancel/delivered | vrai, mais la vraie dette est "release cuisine" implicite; whitelist est un patch de surface |
| `OrderService::list` LIKE | P0 branch leak | vrai, mais ajouter `TransactionService`, OSS, KDS admin global, KioskMachineService numeric LIKE et exports |
| Kiosk promo | preview/final mismatch | vrai, mais doit être tranché via OrderQuote: preview devient quote ou preview reste non contractuelle |
| POS quote-first | rapport POS le met P0 | Codex confirme et le remonte au-dessus de plusieurs quick wins, car encaisser avant quote backend détruit la confiance prix |
| Payment ledger | parfois V2 | Codex: V1 pilote peut accepter ledger minimal, mais pas une prod multi-moyens/TPE/refund sans ledger ou gate explicite |
| NF525 kiosk | ambigu | Codex: gate obligatoire. Si borne encaisse réellement, les ventes doivent entrer dans le chemin fiscal ou être finalisées par POS avant fiscalité |
| KDS cap 50 | P1/P0 selon charge | Codex: P0 si pilote rush ou multi-branches; P1 si site pilote faible volume avec alerte overflow |
| Legacy quarantine | P1/P2 docs | Codex: P0 process avant exécution, car copier `kiosk_implementation` peut réintroduire prix frontend/branch fallback |

## 4. Master Findings Codex

### 4.1 P0 Stop-The-Line

| ID | Finding | Preuve | Correction minimale |
|---|---|---|---|
| C-P0-001 | POS encaisse sur total local avant quote backend | `AUDIT_TOTAL...` F-001/F-016, PaymentComponent total local | endpoint `POST /api/admin/pos/quote`, modal paiement ouverte seulement sur `quote_id` valide |
| C-P0-002 | `payment-confirm` peut créer `PAID` trop tôt/trop large | `OrderController::paymentConfirm`, audit kiosk R2 | validate machine/order/method/status/transaction before any mutation |
| C-P0-003 | Branch leaks admin list/show/export/report/transaction | `OrderService::list/show`, `TransactionService` | exact branch filters + actor branch default + cross-surface tests |
| C-P0-004 | KDS surface accepts global state machine too broadly | `OrderStatusRequest`, `KitchenDisplaySystemOrderService` | request/service whitelist KDS + POS collect route split |
| C-P0-005 | Payment status binary without ledger/state machine | POS report F-003, kiosk TPE no ledger | ledger minimal or human gate to restrict methods/refunds/offline |
| C-P0-006 | Kiosk fiscal/Z decision contradictory | kiosk report KIOSK-DEEP-014 | human gate: kiosk paid enters Z or POS finalizes fiscal payment |
| C-P0-007 | Kiosk offline CB/TR not reconciliable | kiosk report KIOSK-DEEP-003/015/028 | cash-only offline or signed payment attempt queue |
| C-P0-008 | Kiosk menu/pricing still has local/legacy paths | kiosk report KIOSK-DEEP-001/002 | `/frontend/menu` unique + remove authoritative local pricing |
| C-P0-009 | Kitchen transfer is implicit via `ACCEPT` status | POS report T-027 | `KitchenTicketCreated`/`kitchen_released_at` after payment/acceptance |
| C-P0-010 | Queue number not robust enough for rush | POS report F-004/T-004 | unique per branch/date sequence with retry |
| C-P0-011 | No-op status/cashback side effects | challenge deep | early guard + idempotent cashback/refund transaction |
| C-P0-012 | Kiosk cash collection confuses payment and fulfilment | KIOSK-DEEP-018, POS via KDS endpoint | POS cash collection endpoint + payment collection status |
| C-P0-013 | KDS cap 50 can hide valid tickets | POS/KDS reports | pagination/overflow alert and load test |
| C-P0-014 | Legacy/public web payment by order id | POS report F-010/T-017 | signed PaymentIntent opaque or feature flag disable |
| C-P0-015 | Stripe amount cents bug if active | POS report T-015 | cents conversion test + freeze gateways until ledger |

### 4.2 P1 High-Value Corrections

| ID | Finding | Correction |
|---|---|---|
| C-P1-001 | POS idempotency duplicate catch not branch-scoped | same branch query in catch |
| C-P1-002 | `OrderStatusRequest`/`PaymentStatusRequest` numeric broad | enum `Rule::in`, surface-specific requests |
| C-P1-003 | frontend kiosk hardcoded `status: 16` | shared enum + lint expansion |
| C-P1-004 | KDS expected status not mandatory enough | require explicit `expected_status` from client |
| C-P1-005 | KDS sync version seconds | monotonic/high precision version |
| C-P1-006 | KDS/OSS branch_id 0 global without Admin role parity | align with `SyncOverviewController` |
| C-P1-007 | Floorplan transfer needs `OrderTableChanged` | after-commit dedicated event |
| C-P1-008 | Availability release and payload complete | line-level idempotent release + fan-out |
| C-P1-009 | Kiosk route offline id regex mismatch | accept real `offline_<timestamp>_<suffix>` |
| C-P1-010 | Analytics offline v2/auth transport gap | whitelist/map v2 + auth-safe transport |
| C-P1-011 | POS/KDS tests missing for void/drawer/NFC/stations | add Feature suites |
| C-P1-012 | Hardware campaign absent | TPE/printer/drawer/kiosk/tablet pre-prod |

### 4.3 P2/Operational

- `sync_metrics` purge.
- `pos_parked_orders.expires_at`.
- KDS RTL/Bengali.
- Focus trap POS.
- Kiosk admin PIN fallback.
- KioskMachine numeric `LIKE`.
- Graphiti memory ingestion after gate.
- Preflight prod: queue/broadcast/cache/fiscal/scheduler/workers.
- Archive banners for `kiosk_implementation/**` and `borne (Remix)/**`.

## 5. Correction Plan Codex

### Phase 0 — Gates and Freeze Discipline

1. Gate frozen consolidated for `OrderService`, `FrontendOrderService`, `PaymentService`, pricing, fiscal, outbox.
2. Gate fiscal kiosk/Z: choose:
   - A: kiosk paid orders receive fiscal sequence at capture;
   - B: kiosk creates unpaid/order intent, POS finalizes fiscal payment;
   - C: disable autonomous paid kiosk for pilot.
3. Gate payment ledger scope:
   - ledger minimal in V1 pilot;
   - or restrict payment methods/offline/refunds until ledger.
4. Gate KDS bump: single KDS policy vs server-side bump.
5. Gate schema: queue sequence, payment ledger, parked expires.

### Phase 1 — Sentinels Before Patch

Create failing tests first:

- non-kiosk owner calls `payment-confirm`;
- kiosk machine A confirms branch B order;
- cash order calls `payment-confirm`;
- POS quote mismatch forged subtotal;
- cashier discount limit based on forged subtotal;
- branch 1 cannot list/show/export branch 10 order;
- `TransactionService` staff no param stays scoped;
- Chef cannot cancel/deliver via KDS;
- POS collect cash no longer uses KDS route;
- double cancel does not double cashback;
- offline id route accepts actual queue format;
- KDS >50 active shows overflow/pagination;
- `OrderCreated` pending does not imply KDS release.

### Phase 2 — Minimal V1 Contracts

1. **OrderIntent v1**: shared payload shape for item id, qty, variations, extras, instruction, order_type, source_surface.
2. **OrderQuote v1**:
   - route POS quote first;
   - optional kiosk/web quote unification with existing pricing preview;
   - quote expires, branch-scoped, actor-scoped, hash of intent.
3. **Payment confirmation guard**:
   - route ability;
   - machine/order binding;
   - only deferred methods;
   - idempotence by same transaction reference;
   - no mutation before guard.
4. **Kitchen release**:
   - either explicit event/table or strict `OrderStatusChanged -> ACCEPT` only;
   - KDS stops treating all `OrderCreated` as meaningful refresh for pending.

### Phase 3 — Payment/Fiscal Decision

Minimal acceptable pilot if no ledger:

- cash POS only and kiosk cash via POS final action;
- disable CB/TR kiosk offline;
- signed payment intent for web legacy or disable public order-id payment;
- Stripe cents fix if active;
- no partial refund/split tender until ledger.

Production target:

- `order_payments` / `payment_attempts`;
- provider refs unique;
- amount cents immutable;
- state machine: pending, authorized, captured, failed, voided, refunded;
- fiscal event links to payment capture.

### Phase 4 — Sync/KDS Hardening

- surface-specific `OrderStatusRequest`;
- required expected status;
- monotonic version;
- >50 overflow;
- server-side or policy bump;
- role-admin global KDS/OSS;
- `OrderTableChanged`;
- outbox identity guard;
- front EventContract strictness;
- Echo auth banner/refresh.

### Phase 5 — Kiosk Runtime Cleanup

- `/frontend/menu` unique catalog;
- `/frontend/upsell` branch/canal/availability;
- remove local pricing as authoritative; keep only display estimates;
- offline id fix;
- offline CB/TR policy;
- status enum;
- analytics offline v2/auth;
- docs/prototypes marked archive.

### Phase 6 — Ops/Go-Live

- `app:preflight-production`;
- scheduler/workers proof;
- broadcast smoke;
- outbox lag dashboard;
- fiscal archive smoke;
- TPE/printer/drawer device lab;
- Playwright POS/kiosk/KDS/OSS/table flows;
- branch multi-tenant fixtures.

## 6. Codex Questions for Claude R2

Claude doit contester surtout ces points:

1. Est-ce que `OrderQuote` doit être P0 avant `payment-confirm`, ou peut-on corriger `payment-confirm` et branch leaks d'abord?
2. Est-ce que `PaymentLedger` minimal est obligatoire pour V1 pilote, ou gate + restrictions payment methods suffisent?
3. Est-ce que `KitchenTicketCreated` est nécessaire en V1, ou un strict `OrderStatusChanged -> ACCEPT` plus docs suffit?
4. Est-ce que kiosk autonomous paid orders doivent entrer en Z maintenant, ou faut-il obliger POS finalization?
5. Quels P0 Codex surclasse à tort en P0 alors qu'ils doivent être P1/P2?
6. Quels chemins cachés manquent encore: config, scheduler, seeders, legacy, gateways, hardware?

## 7. R1 Codex Proposed Execution Order

| Order | Block | Rationale |
|---:|---|---|
| 1 | Human gates: fiscal, frozen, ledger scope, KDS bump | unlocks legal/frozen work |
| 2 | Sentinels only | prove failures before patch |
| 3 | `payment-confirm` hardening | direct revenue/fraud/fiscal |
| 4 | branch exactness | data leakage |
| 5 | POS quote-first + discount permission | direct checkout integrity |
| 6 | KDS surface whitelist + POS collect route | prevent kitchen/payment coupling |
| 7 | kiosk menu/offline/status hardening | prevent lost orders and branch drift |
| 8 | no-op cashback/payment status guards | financial idempotence |
| 9 | Kitchen release/queue number/KDS overflow | rush correctness |
| 10 | ledger/fiscal decision implementation | production readiness |
| 11 | observability/hardware/e2e | go-live proof |

## 8. R1 Final

Codex refuse un plan qui corrige uniquement des symptômes. Le plan doit créer des contrats qui empêchent les mêmes erreurs de revenir:

- intent;
- quote;
- payment proof;
- kitchen release;
- branch/device principal;
- fiscal decision.

`CODEX_R1_FINAL: CHALLENGE_CLAUDE_TO_REORDER_AND_REJECT_WEAK_P0S`
