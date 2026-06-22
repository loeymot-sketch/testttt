# 🎯 MASTER PLAN — Test profondeur maximale 15 systèmes

**Date** : 2026-05-27
**Branche** : `heal/cms-pr1-quickwins-2026-05-18`
**HEAD** : `27a036323`
**Owner mandate** : « test toutes les systèmes un par un au maximum profondeur + visuel + adversarial »

---

## §1 — Identification des 15 systèmes

### 5 SYSTÈMES VISIBLES (front-facing)

| # | Système | Route | Persona principal |
|---|---------|-------|-------------------|
| **V1** | **POS Caisse** | `/admin/pos` | Caissier |
| **V2** | **Borne Kiosk** | `/kiosk/idle` | Client borne |
| **V3** | **Écran Cuisine KDS** | `/admin/kitchen-display-system` | Chef |
| **V4** | **OSS Public Display** | `/admin/order-status-screen` | Client attente |
| **V5** | **Gestion Admin (Dashboard)** | `/admin/dashboard` + 50 sous-pages | Owner/Manager |

### 10 SYSTÈMES CACHÉS (infrastructure + backend)

| # | Système | Composant | Critique pour |
|---|---------|-----------|---------------|
| **H1** | **Synchronisation Echo** | Soketi WebSocket + private-branch channels | Sync cross-surface temps réel |
| **H2** | **Outbox/Queue Redis** | DispatchDomainEventsJob + 13 PersistToOutbox | Garantie livraison events |
| **H3** | **NF525 Fiscal Chain** | AuditLogService + FiscalSequenceService + ZReportService | Conformité légale |
| **H4** | **RBAC Permissions** | Spatie 82 perms × 2 guards | Sécurité accès |
| **H5** | **Stock/Availability cascade** | AvailabilityService + ItemUpdated event | Rupture propagation |
| **H6** | **Cache invalidation** | InvalidateKioskMenuCache listeners + Redis | Fraicheur catalogue |
| **H7** | **Cron scheduler** | 18 tasks Kernel.php | Backups + Z-loop + retry |
| **H8** | **Backup automation** | Laravel cmd + cron | Disaster recovery |
| **H9** | **Notifications** | Push + SMS + email (Twilio/FCM/Mailgun) | Comms client/staff |
| **H10** | **Payment Gateways** | Stripe + SenangPay + TPE Valina | Encaissement |

---

## §2 — Discipline (10 contracts)

- **DM1** Frozen-zone PROPOSAL only (14 §7 files)
- **DM2** Pre+post diff per action
- **DM3** Captures quartet (PNG + DOM + console + network)
- **DM4** Production-real scenarios
- **DM5** Adversarial inline (1 hostile paragraph per agent)
- **DM6** NF525 READ-ONLY ABSOLU (no INSERT/UPDATE on audit_logs/z_reports)
- **DM7** Rate-limit aware (5 max per batch + pause)
- **DM8** Honest reporting (PASS-source-only if can't trigger visual)
- **DM9** Convergence loop max 3 rounds
- **DM10** Bundle freshness gate

---

## §3 — Architecture exécution

```
BATCH 1 · 5 VISIBLE SYSTEMS deep test (5 agents parallèle)
   V1 POS · V2 Borne · V3 KDS · V4 OSS · V5 Gestion

BATCH 2 · 5 HIDDEN INFRA (5 agents parallèle)
   H1 Echo · H2 Outbox · H3 NF525 · H4 RBAC · H5 Stock

BATCH 3 · 5 HIDDEN OPS (5 agents parallèle)
   H6 Cache · H7 Cron · H8 Backup · H9 Notif · H10 Payments

BATCH 4 · ADVERSARIAL PER SYSTEM (3 agents)
   ADV-A red-team visible · ADV-B red-team hidden · ADV-C cross-system

BATCH 5 · SYNTHESIS + VERDICT
   1 synth agent + convergence final

TOTAL : ~19 agents · 60-90 min wall-clock · Rate-limit safe
```

---

## §4 — Per-system test template

Chaque agent test couvre :

### Technical
1. Code structure (controller + service + model)
2. API endpoints accessible (HTTP 200/401/403/404 inventory)
3. Database schema integrity
4. Sentinels existants pass/fail
5. Listeners/Events wired

### Visual (Playwright MCP)
6. UI pages load
7. Screenshots multiple states
8. Console errors zero
9. Network errors zero
10. Branding intact
11. i18n FR/EN/AR resolved
12. WCAG accessibility basics

### Adversarial (inline)
13. "Worst-case scenario : what breaks ?"
14. Cross-system attack surface
15. Race conditions
16. NF525 invariant violation possible ?
17. Owner-impact if breaks

---

## §5 — Output structure

```
reports/test-e2e/master-15-systems-2026-05-27/
├── plan.md (this file)
├── agents/
│   ├── V1-POS-deep.json
│   ├── V2-Borne-deep.json
│   ├── V3-KDS-deep.json
│   ├── V4-OSS-deep.json
│   ├── V5-Gestion-deep.json
│   ├── H1-Echo-deep.json
│   ├── H2-Outbox-deep.json
│   ├── H3-NF525-deep.json
│   ├── H4-RBAC-deep.json
│   ├── H5-Stock-deep.json
│   ├── H6-Cache-deep.json
│   ├── H7-Cron-deep.json
│   ├── H8-Backup-deep.json
│   ├── H9-Notif-deep.json
│   ├── H10-Payments-deep.json
│   ├── ADV-A-redteam-visible.json
│   ├── ADV-B-redteam-hidden.json
│   ├── ADV-C-redteam-cross.json
│   └── SYNTH-final.json
├── captures/
│   ├── V1-pos-*.png
│   ├── V2-borne-*.png
│   ├── ...
└── convergence/
    └── CONVERGENCE_FINAL.md
```

---

## §6 — Per-system verdict format

```json
{
  "agent": "Vx-System-deep",
  "system_name": "...",
  "technical": {
    "code_intact": true|false,
    "endpoints_tested": {"endpoint": "status"},
    "schema_integrity": true|false,
    "sentinels_pass": N/N
  },
  "visual": {
    "captures": [paths],
    "states_tested": N,
    "console_errors": N,
    "network_errors": N,
    "branding_intact": true|false,
    "i18n_clean": true|false
  },
  "adversarial": {
    "worst_case": "...",
    "attack_surfaces": [],
    "race_conditions": [],
    "nf525_risk": "...",
    "owner_impact": "..."
  },
  "verdict": "GREEN|AMBER|RED",
  "top_3_findings": [],
  "v1_ship_impact": "BLOCK|NICE|POLISH"
}
```

---

## §7 — Convergence rule

- IF all 15 systems = GREEN/AMBER + 3 ADV = GREEN/AMBER → CONVERGED
- IF any RED → heal-wave + re-test
- Max 3 rounds

---

## §8 — Owner deliverable

`reports/test-e2e/master-15-systems-2026-05-27/convergence/CONVERGENCE_FINAL.md` :
- Verdict par système (15 lignes)
- Adversarial findings (top 10)
- V1 ship verdict
- Owner-physical actions remaining
- Captures count summary

---

**Plan ready. Launching BATCH 1 now.**
