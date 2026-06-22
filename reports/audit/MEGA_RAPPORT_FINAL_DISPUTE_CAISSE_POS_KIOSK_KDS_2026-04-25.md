# MEGA RAPPORT FINAL DISPUTE — Caisse V1 POS/Kiosk/KDS — 2026-04-25

## 0. Statut de l'orchestration

Ce rapport consolide:

- le round Codex R1 produit dans `reports/audit/MEGA_DISPUTE_CODEX_R1_CAISSE_POS_KIOSK_KDS_2026-04-25.md`;
- les rapports deep caisse/POS/borne demandés;
- les audits Claude/terminal déjà présents dans le dépôt;
- les invariants FoodKing du repo.

Deux appels Claude terminal Opus 4.7 ont été lancés pendant cette mission:

| Round | Fichier cible | Statut observé |
|---|---|---|
| R2 long | `reports/audit/MEGA_DISPUTE_CLAUDE_R2_CAISSE_POS_KIOSK_KDS_2026-04-25.md` | process lancé, fichier resté à 0 octet pendant la fenêtre d'attente |
| R2 compact | `reports/audit/MEGA_DISPUTE_CLAUDE_R2_COMPACT_CAISSE_POS_KIOSK_KDS_2026-04-25.md` | process lancé, fichier resté à 0 octet pendant la fenêtre d'attente |

Le binaire Claude est détecté et configuré (`claude 2.1.119`, modèle wrapper `claude-opus-4-7`, effort `high`). Le blocage pratique est une absence de sortie des appels `audit`, pas une absence du binaire. Les positions "Claude" utilisées ici proviennent donc des rapports Claude/terminal déjà enregistrés dans le dépôt, plus une consolidation Codex locale.

`FINAL_DISPUTE_VERDICT: NEEDS_HUMAN_GATES_THEN_READY_TO_PLAN`

## 1. Verdict exécutif

La correction caisse V1 ne doit pas être pensée comme "réparer POS" ou "réparer borne". La caisse FoodKing est un pipeline:

```text
OrderIntent -> OrderQuote -> PaymentProof -> KitchenRelease -> Fulfillment -> Fiscal/Audit -> Realtime/OSS
```

Aujourd'hui, le backend protège déjà beaucoup de choses: pricing serveur, branch scope, outbox after-commit, state machine, fiscal Z/audit, disponibilité, tests. Mais les moments métier sont compressés dans des champs trop faibles (`status`, `payment_status`) et dans des conventions implicites (`ACCEPT` signifie "KDS peut voir", `DELIVERED` sert parfois à collecter une commande cash borne).

**Décision principale**: avant d'améliorer l'UX ou l'upsell, il faut figer quatre contrats:

1. `OrderIntent`: payload commun POS/kiosk/web/table.
2. `OrderQuote`: devis backend signé/expiré avant paiement.
3. `PaymentLedger`: preuve paiement/tender/transaction, ou restriction explicite des moyens tant que le ledger n'existe pas.
4. `KitchenRelease`: signal explicite ou règle formalisée pour l'entrée KDS.

## 2. Dispute Codex vs Claude — Arbitrage par Topic

| Topic | Position Codex R1 | Position Claude/rapports existants | Arbitrage final |
|---|---|---|---|
| OrderQuote POS | P0 très haut: pas de paiement sur total local | Audit POS confirme: modal paiement lit total local, quote-first recommandé T-001/T-026 | **P0 structurant**. À faire avant pilot multi-caisses. |
| OrderIntent commun | P0 structurant pour éviter drift surfaces | Rapports caisse le listent T-025; moins immédiat que `payment-confirm` | **P0 architecture**, mais peut commencer par tests contractuels sans tout refactorer. |
| PaymentLedger | Codex tend P0 | Rapports POS disent P0 V2; V1 peut restreindre méthodes/refunds/offline si gate | **Human split**: ledger P0 pour prod complète; gate possible pour pilote limité. |
| `payment-confirm` | P0 stop-line | Kiosk R2 confirme mutation `PAID` trop large avant preuve complète | **P0 immédiat n°1 backend borne**. |
| Fiscal kiosk/Z | Gate P0 | Kiosk R2 montre contradiction tickets non fiscaux vs ventes hors Z | **Gate humain obligatoire avant go-live payant kiosk**. |
| KDS whitelist | P0 | Challenge deep confirme Chef peut sortir couloir cuisine | **P0**, mais patch doit préserver POS cash via route dédiée. |
| KitchenTicketCreated | Codex le veut P0 | POS audit le recommande T-027; peut être V1.5 si status->ACCEPT strict est formalisé | **P0 architecture si web/table/kiosk cash complexes; P1 si pilote POS+kiosk limité avec règle ACCEPT documentée.** |
| Branch exactness | P0 | Tous les rapports convergent | **P0 immédiat** sur list/show/export/report/transaction. |
| POS cash via KDS | P0 | Kiosk/POS audits confirment confusion paiement/fulfillment | **P0 couplé à KDS whitelist**. |
| Kiosk offline CB/TR | P0 | Borne audit confirme non reconciliable | **P0: désactiver CB/TR offline ou signer payment attempt.** |
| Queue number | Codex P0 | POS audit signale fallback microtime/sans unique | **P0 si rush/OSS go-live, P1 si pilote faible volume avec monitoring.** |
| KDS >50 | Codex P0/P1 | KDS audit: cap peut cacher tickets | **P1 par défaut, P0 si rush/multi-branch.** |
| Legacy quarantine | Codex P0 process | Rapports caisse/borne confirment archives dangereuses | **P0 process avant délégation**: marquer non-runtime et lint no import. |
| Web PaymentIntent | Codex P0 | POS audit: route public order id risquée | **P0 si gateways web actifs; P1/feature-flag si désactivés.** |
| Stripe cents | Codex P0 si actif | POS audit T-015 | **P0 si Stripe actif; sinon P1 pré-activation.** |
| Availability release | Codex P0 | Audit sync initial puis POS second passage disent solide en partie | **NEEDS_EVIDENCE**: ne pas repatcher aveuglément; vérifier code/tests actuels, puis P0 seulement si gap réel. |
| Outbox/EventContract | Codex P1/P2 | Rapports disent socle solide mais front laxiste | **P1 observability/recovery; P2 strictness sauf si incidents.** |
| KDS bump | Codex P0/P1 | KDS audit: majeur multi-écrans | **Human product gate**: single-screen policy ou server bump. |
| Hardware go-live | Codex P1 | POS audit: pas de TPE/printer/drawer réels | **P1 bloquant go-live réel**, pas bloquant tests code. |

## 3. Ordre Final d'Exécution

### Gate 0 — Décisions humaines avant code produit

| Gate | Question | Impact |
|---|---|---|
| G0-FROZEN | Autoriser patchs `OrderService`, `FrontendOrderService`, `PaymentService`, pricing/fiscal/outbox? | Débloque P0 backend |
| G0-FISCAL-KIOSK | Les ventes kiosk payées entrent-elles dans Z, ou POS finalise-t-il l'encaissement fiscal? | Détermine `payment-confirm`, cash kiosk, fiscal sequence |
| G0-LEDGER | Ledger paiement minimal obligatoire pour V1 ou restrictions de moyens/refunds/offline? | Détermine scope migrations |
| G0-KDS-BUMP | Bump KDS local single-screen ou état serveur multi-écrans? | Détermine UI/KDS backend |
| G0-SCHEMA | Autoriser migrations queue sequence, payment ledger, payment intents, parked expires? | Débloque robustesse prod |

### Lot 1 — Tests sentinelles rouges

Objectif: prouver les failles avant patch.

| Test | Attendu |
|---|---|
| `payment-confirm` non-kiosk propriétaire | 403/422, `payment_status` inchangé |
| `payment-confirm` kiosk mauvaise machine/branche | refus, aucune mutation |
| `payment-confirm` cash order | refus |
| `payment-confirm` concurrent même transaction | idempotent, un seul dispatch |
| POS forged subtotal discount | permission remise calculée sur subtotal backend, pas client |
| POS payment modal quote missing | ne peut pas confirmer |
| branch 1 list/show/export branch 10 | aucune fuite |
| transaction staff no branch param | scoped |
| Chef KDS -> CANCELED/DELIVERED | refus |
| POS collect kiosk cash | n'appelle pas endpoint KDS |
| double cancel/refund | pas de double cashback |
| kiosk offline id réel `offline_<timestamp>_<suffix>` | route waiting valide |
| KDS >50 | overflow visible |
| pending OrderCreated web/table | ne crée pas ticket cuisine visible |

### Lot 2 — P0 sécurité/revenue immédiats

1. Durcir `payment-confirm`.
2. Exact branch filters `OrderService::list/show`, reports/exports, `TransactionService`.
3. Surface-specific status policy: KDS/POS/kiosk/admin.
4. POS collect-kiosk-cash route dédiée.
5. Kiosk offline CB/TR: bloquer ou payment attempt signé.
6. Legacy quarantine: no import from `kiosk_implementation/**`, docs archive banner.

### Lot 3 — P0 prix et quote

1. `POST /api/admin/pos/quote`.
2. `OrderQuote` minimal branch/user/intent hash/expiry.
3. Payment modal lit `quote.total_ttc`, pas `form.total`.
4. Discount permission après calcul backend.
5. Retirer recap financier authoritative du POS wizard.
6. Kiosk promo preview/final: soit preview devient quote, soit promo refusée au checkout.

### Lot 4 — Paiement/fiscal

Deux branches possibles:

| Option | Choix | Corrections |
|---|---|---|
| A | Ledger minimal V1 | payment attempts, payment transactions, amount cents, provider ref unique, state machine |
| B | Pilot restreint | pas de CB/TR offline, pas split/refund partiel, gateways web désactivés/PaymentIntent minimal, cash kiosk finalisé POS |

Dans les deux cas:

- corriger Stripe cents si actif;
- signed PaymentIntent pour `/payment/{order}/pay` ou feature-flag off;
- fiscal kiosk/Z gate appliqué;
- `changePaymentStatus` borné ou désactivé hors admin/fiscal gate.

### Lot 5 — Kitchen release et KDS

1. KDS whitelist strict PREPARING/PREPARED.
2. `expected_status` obligatoire.
3. KDS monotonic version.
4. KDS overflow >50 visible.
5. `KitchenTicketCreated` ou règle `OrderStatusChanged -> ACCEPT` unique documentée/testée.
6. Role Admin explicite pour KDS/OSS global.
7. Bump décision: serveur ou single-screen documented.

### Lot 6 — Kiosk runtime

1. `/frontend/menu` unique source catalogue borne.
2. `/frontend/upsell` branch/canal/availability.
3. Supprimer prix métier locaux comme source de vérité.
4. Remplacer `status: 16` par enum.
5. Offline id route guard.
6. Analytics offline v2 + auth transport.
7. Loyalty scan middleware ability.
8. Kiosk admin PIN fallback prod.

### Lot 7 — Ops / Go-live

1. `app:preflight-production`.
2. Scheduler/workers proof.
3. Broadcast smoke.
4. Outbox lag p95/p99 dashboard.
5. Fiscal archive smoke.
6. Device lab: TPE, printer, drawer, kiosk, KDS tablet, network loss.
7. Playwright full path POS cash/card, kiosk cash/card, web/table accept, KDS/OSS.

## 4. Master Correction Plan — Fichiers Probables

| Domaine | Fichiers à lire/écrire |
|---|---|
| POS quote | `routes/api.php`, `Admin/PosController.php`, `OrderService.php`, `PricingService.php`, `PosOrderRequest.php`, `PosComponent.vue`, `PaymentComponent.vue`, `posOrder.js` |
| Payment confirm | `Frontend/OrderController.php`, `FrontendOrderService.php`, `OrderRequest.php`, `routes/api.php`, `KioskPaymentComponent.vue`, tests kiosk |
| Branch exactness | `OrderService.php`, `TransactionService.php`, `OrderExport.php`, `SalesReportExport.php`, controllers POS/online/table/sales/transaction |
| KDS status | `OrderStatusRequest.php`, `KitchenDisplaySystemOrderService.php`, `KitchenDisplaySystemController.php`, `KitchenDisplaySystemComponent.vue`, `kitchenDisplaySystemOrder.js` |
| POS collect kiosk cash | `routes/api.php`, `PosOrderController.php` or new POS controller, `OrderService.php`, `PosComponent.vue` |
| Ledger/payment | migrations, `PaymentService.php`, `PaymentStatus.php`, new payment domain services, gateway adapters, fiscal services |
| Kitchen release | new event/listener/model or `OrderStatusChanged` specialization, `EventContract.php`, `eventContract.js`, KDS services |
| Kiosk menu/offline | `kioskMenu.js`, `kioskCart.js`, `kioskOfflineQueue.js`, `KioskPaymentComponent.vue`, `KioskWaitingComponent.vue`, `MenuController.php`, `UpsellController.php` |
| Fiscal/Z | `ZReportService.php`, `FiscalSequenceService.php`, `AuditLogService.php`, `FrontendOrderService.php`, fiscal tests |
| Ops | `AppServiceProvider.php`, `Console/Kernel.php`, preflight command, config queue/broadcast/fiscal/kiosk, scripts |

## 5. Red-Team Test Matrix

### PHP Feature

| Suite | Tests |
|---|---|
| Auth/payment-confirm | non-kiosk owner, wrong machine, wrong branch, cash method, duplicate transaction, concurrent confirm |
| Branch | POS/online/table list/show/export, sales-report pdf/export, transactions, KDS/OSS global role |
| Pricing/quote | POS quote forged subtotal, cashier discount cap, quote expiry, quote branch mismatch |
| Payment | PaymentStatus invalid transitions, ledger duplicate provider ref, Stripe cents, public web PaymentIntent |
| Fiscal | kiosk paid in Z or POS finalization proof, Z closed prevents status/payment if in scope |
| KDS | Chef legal/illegal transitions, expected_status required, >50 overflow, monotonic versions, admin role global |
| Kitchen release | pending OrderCreated not visible, ACCEPT release visible, rollback no ticket |
| Availability | cancel/refund release if gap real, ItemAvailabilityChanged payload/fan-out |
| Queue | unique queue_number branch/day concurrency |

### JS/Vitest

| Suite | Tests |
|---|---|
| POS | payment modal requires quote, no prop mutation, discountReason binding, idempotency key random, no KDS route for cash collect |
| Kiosk | offline id format, status enum no `16`, menu source `/frontend/menu`, no local authoritative pricing, analytics v2 auth-safe |
| KDS | stale poll rejected, reconnect storm, >50 warning, bump multi-screen policy, RTL/Bengali |
| Event contract | missing branch/correlation rejected or normalized consistently |

### E2E/Hardware

| Flow | Expected |
|---|---|
| POS cash -> KDS -> OSS -> receipt | quote backend, fiscal sequence, KDS visible, drawer/printer |
| POS card/TPE simulated | no local total mismatch, payment proof |
| Kiosk cash | payment/fiscal rule explicit, POS collection if required |
| Kiosk card online | pending until confirm, KDS after confirm |
| Kiosk offline | cash-only or safe queued intent; no CB/TR blind TPE |
| Web/table | pending acceptance SLA, KDS after accept/release |
| Network loss | outbox/polling/reconnect recover |
| Rush >50 KDS | no hidden ticket without alert |

## 6. Gates de Clôture V1

Un lancement pilote V1 est acceptable seulement si:

1. `payment-confirm` est durci et testé négatif.
2. Les fuites branch exactness sont fermées.
3. POS n'encaisse plus sur un total local non signé backend.
4. KDS ne peut plus exécuter de transitions hors cuisine.
5. POS cash kiosk ne passe plus par endpoint KDS.
6. CB/TR offline est bloqué ou auditable.
7. Fiscal kiosk/Z est explicitement tranché.
8. KDS >50 et queue number ont une mitigation adaptée au volume pilote.
9. Legacy/prototypes sont mis hors runtime.
10. Device lab ou gate humain documente ce qui n'a pas été testé physiquement.

## 7. Risques Résiduels Après Correctifs

| Risque | Raison | Mitigation |
|---|---|---|
| Ledger complet repoussé | migrations/refactor lourds | restreindre payment methods/refunds/offline |
| NF525 externe | conformité légale non auto-certifiable | gate conformité externe |
| KDS cognition | erreurs cuisine pas seulement sync | design KDS instructions/allergens/stations |
| Hardware variance | TPE/printer/drawer réels | device lab par modèle |
| Multi-branch admin | besoin SaaS réel | role Admin strict + branch pin |
| Archives docs | vieux exemples reviennent | lint/import guard + README archive |
| Event burst | tests unitaires insuffisants | load test + observability |

## 8. Addendum Handoff Context

Après réception des deux historiques d'avancement supplémentaires, le rapport final doit être lu avec l'addendum suivant:

`ADDENDUM_HANDOFF_CONTEXT: reports/audit/MEGA_HANDOFF_CONTEXT_INTEGRATION_CAISSE_POS_KIOSK_KDS_2026-04-25.md`

`READINESS_GAP_ANALYSIS: reports/audit/MEGA_PLAN_READINESS_GAP_ANALYSIS_CAISSE_POS_KIOSK_KDS_2026-04-25.md`

`CLAUDE_MAX_ORCHESTRATION: reports/audit/CLAUDE_MAX_ORCHESTRATION_CAISSE_V1_2026-04-25.md`

`CODEX_CLAUDE_COMPARISON: reports/audit/CODEX_CLAUDE_MEGA_PLAN_COMPARISON_CAISSE_V1_2026-04-25.md`

`EXECUTABLE_MASTER_PLAN: plans/PLAN_CAISSE_V1_MEGA_CORRECTION_2026-04-25.md`

`FILE_INDEX: reports/audit/MEGA_ORCHESTRATION_FILE_INDEX_CAISSE_V1_2026-04-25.md`

Les deux fichiers intégrés sont:

- `docs/DOC_EXPO_HER_ANCIEN_AGENT_ALIMENTATION_WORKFLOW_2026-04-22.md` — index global des chemins et passation nouvelle session;
- `docs/orchestration/EXPORT_HANDOFF_POS_KDS_MASTER_FINITIONS_2026-04-26.md` — handoff POS/KDS, verdict NOT-READY 4/10, 9 lots, TASK_ID, protocole de fusion de plans.

Effet sur l'ordre de lecture du prochain agent:

```text
AGENTS.md
docs/orchestration/GLOBAL_SYSTEM_PRIMER.md
docs/DOC_EXPO_HER_ANCIEN_AGENT_ALIMENTATION_WORKFLOW_2026-04-22.md
.cursor/ACTIVE_CYCLE.md
reports/audit/MEGA_RAPPORT_FINAL_DISPUTE_CAISSE_POS_KIOSK_KDS_2026-04-25.md
reports/audit/MEGA_PLAN_READINESS_GAP_ANALYSIS_CAISSE_POS_KIOSK_KDS_2026-04-25.md
reports/audit/CLAUDE_MAX_ORCHESTRATION_CAISSE_V1_2026-04-25.md
reports/audit/CODEX_CLAUDE_MEGA_PLAN_COMPARISON_CAISSE_V1_2026-04-25.md
plans/PLAN_CAISSE_V1_MEGA_CORRECTION_2026-04-25.md
reports/audit/MEGA_ORCHESTRATION_FILE_INDEX_CAISSE_V1_2026-04-25.md
docs/orchestration/EXPORT_HANDOFF_POS_KDS_MASTER_FINITIONS_2026-04-26.md
plans/PLAN_MASTER_FINITIONS_POS_KDS_AVANT_LANCEMENT_2026-04-26.md
```

Ces addendums ne changent pas les P0 métier. Le handoff renforce la reprise de contexte, le mapping POS/KDS vers les lots déjà préparés, et la procédure de fusion si un autre agent produit un plan concurrent. Le readiness/gap analysis tranche le point demandé ensuite: `READY_FOR_MEGA_PLAN: YES`, `READY_FOR_IMPLEMENTATION: NO_WITHOUT_GATES`. Claude terminal a ensuite livré `CLAUDE_MAX_ORCHESTRATION_VERDICT: READY_TO_WRITE_MEGA_PLAN`, avec l'arbitrage final suivant: garder les concepts Codex, suivre l'ordre d'exécution Claude. Le fichier maître exécutable est maintenant `plans/PLAN_CAISSE_V1_MEGA_CORRECTION_2026-04-25.md`.

## 9. Décision Finale

Codex R1 avait raison sur le noyau: **OrderIntent, OrderQuote, PaymentProof, KitchenRelease**. Le point à corriger dans R1 est le séquencement: on ne doit pas commencer par l'architecture lourde si des P0 directs restent ouverts. Le bon ordre est:

1. gates;
2. sentinelles;
3. sécurité paiement/branch/status;
4. quote backend;
5. payment/fiscal decision;
6. KDS/release;
7. kiosk runtime/offline;
8. ops/hardware.

Ce plan évite deux échecs:

- corriger des symptômes sans contrat durable;
- lancer un refactor massif ledger/quote avant d'avoir fermé les trous fraud/revenue immédiats.

`MEGA_FINAL_VERDICT: NEEDS_HUMAN_GATES_THEN_READY_TO_PLAN`
