# Pre-Prod Hardening — Master Plan GSTACK
**Date :** 2026-05-08
**Origine :** 12 actions P0/P1 identifiées dans `plans/PRE_PROD_DEEP_RISK_AUDIT_2026-05-08.md` §3
**Méthode :** GSTACK 7 étapes (Think → Plan → Build → Review → Test → Ship → Reflect)
**Politique :** zéro conflit avec branche agent (fb3535a87) + zéro touche frozen-zones

---

## §0 — 12 Actions P0/P1 décomposées en 4 vagues d'exécution

### Wave A — P0 critiques code (4 sub-agents parallèles)

| ID | Item | Type | Greenfield | Effort |
|---|---|---|---|---|
| A1 | F-016b minimal stock endpoint + UI Vue minimaliste | Backend + Frontend | OUI (admin/stockManager) | 4-5h |
| A2 | Receipt PDF fallback endpoint backend (NF525 légal) | Backend | OUI (PdfReceiptController) | 1j |
| A3 | TPE healthcheck mandatoire boot kiosk | Frontend safe + Backend | OUI route + extends safe Vue | 4h |
| A4 | Pusher heartbeat client→server ack ratio | Backend + extends Track 3 | NEW endpoint | 1j |

### Wave B — P1 fixes parallel (5 sub-agents)

| ID | Item | Type | Effort |
|---|---|---|---|
| B1 | Cron `cash:close-stale-sessions` quotidien | Console command + schedule | 4h |
| B2 | Walk-in customer seeder + boot assertion | Seeder + listener | 2h |
| B3 | PosCategoryController order_column whitelist | 1-LOC fix (Track 5 finding) | 30 min |
| B4 | Sanctum tokens expiration + refresh endpoint | Auth config + route | 1j |
| B5 | OrderDetailsResource fiscal_sequence_no display (Track 2.1 unblock) | Resource extension + Receipt template | 2h |

### Wave C — OPS scripts/docs greenfield (1 sub-agent multi-output)

| ID | Item | Type |
|---|---|---|
| C1 | Backup DB script `scripts/backup-db.sh` + rollback procedure doc | Bash + Markdown |
| C2 | Soketi systemd unit + healthcheck script | Systemd + Bash |
| C3 | Outbox cron config + alerting runbook | Crontab snippet + Markdown |

### Wave D — Massive E2E audit + tests cumulatifs final

| ID | Item | Type |
|---|---|---|
| D1 | Run cumulative tests (PHPUnit full + Vitest full + Build production) | Bash inline |
| D2 | Run F-017 E2E npm smoke + full | npm scripts |
| D3 | Verify 13 V1 invariants (NF525-Kiosk, NF525-Z, TPE-amount, Cancel-reason, Idempotency-parity, Queue-monotonic, Cash-reconcile, Cash-acknowledge, Reconcile-queue, Stock-orchestration, Outbox-health, Audit-chain, Branch-isolation) | Bash grep + tests |
| D4 | Final hardening report + Graphiti + memory update | Doc + MCP |

---

## §1 — Frozen zones (rappel strict)

**Owner-frozen :** `pos-wizard.js` + 8 kiosk wizard Vue components
**Agent territory :** F-001..F-017 files (OrderService, FrontendOrderService, PaymentService, Fiscal/*, Stock/*, OrderController Frontend, PaymentConfirmRequest, NormalItemResource, HealthController, KioskAppComponent, KioskPaymentComponent, PosComponent, ItemComponent, PaymentComponent.vue, .env.example, REALTIME_SETUP.md, tests/e2e/*)

---

## §2 — Dependency graph

```
Wave A (4 parallèles)              Wave B (5 parallèles)
  A1 stock endpoint                  B1 cash close cron
  A2 receipt PDF                     B2 walk-in seeder
  A3 TPE healthcheck    →            B3 PosCategory whitelist
  A4 heartbeat ack                   B4 Sanctum TTL
                                     B5 OrderDetailsResource fiscal
        ↓
Wave C (OPS — 1 sub-agent)
  C1 backup script
  C2 Soketi systemd
  C3 outbox cron
        ↓
Wave D (massive audit + tests)
  D1 PHPUnit + Vitest cumulative
  D2 F-017 E2E full run
  D3 13 invariants verification
  D4 Final report + Graphiti
```

---

## §3 — Acceptance criteria globaux

- [ ] 12 P0/P1 actions livrées
- [ ] 0 régression tests existants (baseline ~1900-2000 tests)
- [ ] 0 touche frozen-zones
- [ ] Build production OK
- [ ] F-017 E2E full run passe
- [ ] 13 invariants V1 vérifiés
- [ ] Backup script testé en dry-run
- [ ] Rollback procedure documentée
- [ ] Graphiti episode poussé

---

## §4 — Discipline d'exécution

- Format commit : `harden(P0|P1-X): <résumé>`
- Branch : `claude/blissful-mclean-c915c2` (linear sur orchestrator parallel)
- Chaque sub-agent : TDD + anti-drift checklist + frozen-zones grep guard
- Reporting : `reports/orchestrator_parallel_2026-05-08/REPORT_HARDENING_<wave>.md`

---

## §5 — Engagement final

À l'issue de Wave D, le delta entre "code clean" et "production sereine" sera fermé. Heal-light owner ~2h restera + ops physique (deploy backup, supervised Soketi, cron config). Ces dernières actions sont owner-side, scriptées et documentées par moi.

— *Ultra-plan, ultra-focus, ultra-discipline. Le go-live ne souffre aucune approximation.*
