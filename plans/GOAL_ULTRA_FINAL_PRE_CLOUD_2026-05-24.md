# 🎯 GOAL ULTRA-FINAL PRE-CLOUD — Mega Task List

**Date** : 2026-05-24
**Branche** : `heal/cms-pr1-quickwins-2026-05-18` HEAD `32bb13730`
**Statut cumul** : 53+ commits · 207 NEW sentinels GREEN · 7 phases couvertes (A-E + F+F2 + G+G2 + H+H2 + I+I2 + J+J2 + K+K2) · 29 production heals shipped · 3 CRITICAL bugs + 3 RED P0 caught

**Mandate owner verbatim 2026-05-24** :
> « ultra plan qui va abuser le max possible pour chaque system de test dans toit les coté puis les point d'intersection puis les syncronisation au max intelligence et decomposition et max agents skilled »

**Mission** : couvrir EXHAUSTIVEMENT ce qui reste indirect / caché / erreur non-découverte / sécurité non-testée APRÈS 11 phases d'audit + heal.

---

## §0 — Inventaire de ce qui RESTE non-couvert (12 zones)

Après Phase A-K, voici les **12 zones genuinely uncovered** :

| ID | Zone | Pourquoi pas encore couvert |
|----|------|----------------------------|
| **L1** | **Hardware integration** | TPE Senangpay (D8 deferred until owner picks hardware) — printer ESC/POS — cash drawer kick — barcode |
| **L2** | **Production env dry-run** | Phase D scripts sur disque seulement, jamais exécutés (cloud blocked owner-mandate) |
| **L3** | **Long-running 4h+ true soak** | F.2 = 5min, G.1 = 13min, H.3 = 15min. Jamais 4h+ continuous |
| **L4** | **Browser matrix** | Seulement Chromium Playwright. Safari/Firefox/Edge jamais empirique |
| **L5** | **Accessibility empirical** | axe-core code-static seulement. Aucun NVDA/VoiceOver réel |
| **L6** | **Data integrity at scale** | 6-year retention jamais simulée. Index efficiency jamais benchmarkée |
| **L7** | **Security penetration deep** | File upload polyglot, SSRF webhook, CRLF, timing oracle, email injection — non testés |
| **L8** | **Cascades multiples (3+ systems)** | F.5 + H.3 testé 5 streams mais pas vraies combinaisons rares 3+ systems |
| **L9** | **Email/SMS delivery flow** | Order confirmation email, password reset email, SMS - jamais E2E |
| **L10** | **Disaster recovery drill** | Backup restore G.12 verified, mais DR full (server crash + recovery from scratch) jamais drilled |
| **L11** | **Cron miss recovery** | If 03:00 backup misses for 3 days, what happens ? Jamais simulé |
| **L12** | **Pre-cloud production gates** | Phase D scripts + AppServiceProvider boot guards + .env validations en conditions réelles |

---

## §1 — Architecture d'exécution

### Disciplines mandatoires (héritées + renforcées)

1. **Frozen-zone PROPOSAL only** (per CLAUDE.md §7) — 14 fichiers untouched
2. **NF525 invariants** : CHAIN OK pre+post chaque op, audit_logs append-only, fiscal_sequence_no monotonic gap-free, composition_snapshot immutable (now DB-trigger enforced per J2-HEAL-06)
3. **DM2** : pre+post audit per correction (snapshot avant, apply, snapshot après, diff verify)
4. **DM3** : multi-persona reasoning (Chef/Client/Cashier/Owner/Auditeur/Multi-tenant)
5. **DM4** : production-real scenarios (pas synthétiques)
6. **Visual mandate** : screenshots per cascade step + multimodal Read
7. **Convergence rule** : 2 rounds GREEN identical findings set + persona consensus
8. **Max parallel** : single-message dispatch 10-16 agents

### Per-task agent team (le "ABUSE MAXIMUM" owner-demandé)

Chaque task L.X reçoit (selon scope) :
- **1 GStack lead** : Architect + Implementer + Tester intern
- **1 Superpowers reviewer** : parallel read-only audit
- **2-3 Adversarial RED** : hostile dispute (security + logic + UX)
- **1 Visual agent** : E2E screenshot capture + multimodal critique
- **1 Sync chain agent** : verify cascade integrity across systems

Total per task : 5-7 sub-agents. 8-12 tasks per wave. Per wave : 40-80 sub-agents.

---

## §2 — Mega-task list décomposée (12 systems × 5+ sub-tasks)

### L1 — HARDWARE INTEGRATION DEEP (5 sub-tasks)

- **L1.1** TPE Senangpay simulation depth : decline / accept / retry / timeout / partial-payment / network-drop-mid-charge
- **L1.2** Printer ESC/POS : paper out / jam / disconnect / multi-receipt queue / format width
- **L1.3** Cash drawer kick : signal correctness / drawer-open audit / drawer-stay-open detection
- **L1.4** Barcode scanner (if applicable) : edge inputs / unknown barcodes / rapid scan
- **L1.5** PWA offline kiosk : service worker / IndexedDB persistence / replay queue under hardware constraints

### L2 — PRODUCTION ENV DRY-RUN (5 sub-tasks)

- **L2.1** Phase D scripts execution on test VM (NOT prod) : server-setup.sh + deploy.sh + nginx + supervisor full sequence
- **L2.2** SSL Certbot dry-run + auto-renewal
- **L2.3** nginx config: SSL + HSTS + CSP + WS upgrade for soketi + cache headers
- **L2.4** Supervisor + Soketi : 2 worker queue + soketi systemd + autorestart drill
- **L2.5** Cross-version migration : SQLite dev → MySQL prod migration parity (especially triggers per J2-HEAL-06)

### L3 — LONG-RUNNING 4H+ TRUE SOAK (5 sub-tasks)

- **L3.1** Continuous mixed load 4h (POS + kiosk + KDS + admin in realistic ratios)
- **L3.2** Memory leak detection over 4h (PHP-FPM + Vue + queue workers)
- **L3.3** DB connection pool monitoring over 4h (saturation / pool starvation)
- **L3.4** Outbox processing latency over 4h (target ≤30s p99)
- **L3.5** Cache hit ratio measurement + Redis memory growth

### L4 — BROWSER MATRIX (5 sub-tasks)

- **L4.1** Safari (iPad portrait + desktop) : kiosk + POS + KDS + admin
- **L4.2** Firefox desktop : payment flow + KDS bump
- **L4.3** Edge / chromium-derivatives differences
- **L4.4** Touchscreen edge cases : fat-finger / accidental swipe / multi-touch
- **L4.5** Mobile responsive admin : owner accessing dashboard from phone

### L5 — ACCESSIBILITY EMPIRICAL (5 sub-tasks)

- **L5.1** NVDA Windows screen reader : kiosk full flow narration
- **L5.2** VoiceOver iPad : POS full flow narration
- **L5.3** Keyboard-only navigation end-to-end (no mouse) : POS + KDS + admin
- **L5.4** High-contrast mode + Windows forced colors mode
- **L5.5** Zoom 400% reflow : layout intact, no horizontal scroll

### L6 — DATA INTEGRITY AT SCALE (5 sub-tasks)

- **L6.1** Synthetic 6-year retention dry-run : seed 1M+ orders, walk chain, verify performance
- **L6.2** Index efficiency at 1M orders : EXPLAIN + slow query log + recommend missing indexes
- **L6.3** Corruption recovery : simulate DB crash mid-tx, restore drill, verify chain integrity
- **L6.4** Cross-version migration : SQLite → MySQL bit-identical schema + data
- **L6.5** audit log search efficiency at scale : query patterns + indexing

### L7 — SECURITY PENETRATION DEEP (5 sub-tasks)

- **L7.1** File upload bypass : polyglot (image+php), SVG XSS, ZIP bomb, double-extension RCE
- **L7.2** SSRF via webhook URLs / image URL fetching / external service calls
- **L7.3** Header injection : CRLF in user-controlled headers, HTTP smuggling
- **L7.4** Time-based attacks : timing oracle on login (per J-ADV-4 LEAK-01 V1.0.2), race window timing
- **L7.5** Email/SMS injection : HTML email payload, SMS gateway escape, sender forgery

### L8 — CASCADES MULTIPLES (5 sub-tasks)

- **L8.1** 3-way concurrent : customer at borne + admin toggle item + cashier encaisser ALL simultaneously
- **L8.2** Stripe webhook + manual refund button + loyalty clawback ALL concurrent
- **L8.3** Z-close in progress + new orders arriving + KDS bumping ALL concurrent
- **L8.4** Branch deactivation cascade + active orders + token revocation
- **L8.5** Webhook secret rotation while events still firing

### L9 — EMAIL/SMS DELIVERY FLOW (5 sub-tasks)

- **L9.1** Order confirmation email empirical end-to-end (Mailtrap or similar)
- **L9.2** Password reset email + token expiry race
- **L9.3** SMS gateway integration (if applicable per Senangpay or Twilio)
- **L9.4** Email queue under load + retry policy + bounce handling
- **L9.5** Locale of emails (FR proper, no English fallback)

### L10 — DISASTER RECOVERY DRILL (5 sub-tasks)

- **L10.1** Full server crash + restore from backup + verify CHAIN OK
- **L10.2** Database server-only crash (web alive) : graceful degradation + retry
- **L10.3** Redis crash : idempotency cache loss recovery (per F.6)
- **L10.4** Soketi crash : polling fallback + Echo recovery
- **L10.5** Network partition (LAN flap) : eventual consistency verification

### L11 — CRON MISS RECOVERY (5 sub-tasks)

- **L11.1** 03:00 backup missed for 3 days : recovery + alert + manual catch-up
- **L11.2** 23:55 Z-close safety-net missed : next-day double-close blocked + manual recovery
- **L11.3** Outbox prune missed : table growth + manual cleanup
- **L11.4** Sanctum prune-expired missed : token table bloat
- **L11.5** Health monitor missed : owner notification path

### L12 — PRE-CLOUD PRODUCTION GATES (5 sub-tasks)

- **L12.1** AppServiceProvider boot guards : all 10 verified per production .env
- **L12.2** .env.example vs .env diff : every required key documented + present
- **L12.3** Frozen-zone diff baseline locked vs main : confirm safety-check.sh works
- **L12.4** Sentinel CI runner : verify all 207 sentinels actually execute in CI
- **L12.5** Production smoke test : 15-min manual walk-through checklist (per H.8 OWNER_PHYSICAL_WALK_CHECKLIST.md)

---

## §3 — Wave execution plan

### Wave L-A (16 agents single-message parallèle) — first dispatch

Tactical priority : pick most-impactful 8 sub-tasks × 2 angles each :

1. L1.1 TPE Senangpay simulation (GStack)
2. L1.1 TPE Senangpay simulation (Adversarial)
3. L3.1 Continuous mixed load 4h (GStack) — START background
4. L3.2 Memory leak over 4h (Visual capture + monitor)
5. L7.1 File upload bypass (Adversarial RED)
6. L7.2 SSRF (Adversarial RED)
7. L7.3 Header injection (Adversarial RED)
8. L7.4 Time-based attacks (Adversarial)
9. L8.1 3-way concurrent (Sync chain)
10. L8.2 Stripe + refund + clawback concurrent (Sync chain)
11. L8.3 Z-close + active orders + KDS bump (Sync chain)
12. L10.1 DR drill restore (GStack)
13. L11.1 Backup cron miss recovery (SRE)
14. L12.1 Boot guards verification (Tester)
15. L12.5 Production smoke walk (Visual + multimodal)
16. PERSONA consensus meta-agent

### Wave L-B — heal wave based on L-A findings

### Wave L-C — 16 agents for L2 + L4 + L5 + L6 + L9 (less-explored systems)

### Wave L-D — heal wave

### Wave L-E — final persona consensus + convergence

---

## §4 — Convergence rule (renforcée)

```
ROUND 1:
  Wave L-A (16 agents) → findings JSONs
  Aggregate
  IF NEW open P0/P1 BLOCKER == 0 → ROUND 2 confirming
  ELSE → Wave L-B heal + ROUND 2 retry

ROUND 2:
  Re-dispatch Wave L-C + Wave L-D (16 agents fresh angle)
  Aggregate
  IF identical-or-subset of round-1 findings AND ALL CLEAN → CONVERGED
  ELSE → ROUND 3 (max 3 rounds)

CONVERGED → Phase E synthesis + BRAIN update + push
```

---

## §5 — Mandate "abuse maximum"

L'owner veut MAX parallèle + MAX profondeur + MAX adversarial. Pour atteindre cela :

- Single-message dispatch 12-16 agents par wave (vs 8 précédents)
- Per task : 5-7 sub-agents (vs 1-3 précédents)
- Adversarial RED quota augmenté à 30-40% des agents (vs 20% précédents)
- Visual capture mandatory pour chaque cascade step (multimodal Read obligatoire)
- Persona consensus check avant convergence : tous les 6 personas doivent valider

---

## §6 — Owner-gate items hérités (toujours pending)

- pos-wizard.js XSS LOCK countersign (12+ jours holding)
- D3 LOCK_PAY currency countersign
- PricingService 2 P0 NF525 LOCK to write
- S3 KDS layout 5+ orders Option A/B/C choice
- P11 Refund UI button missing (PROPOSAL)
- P12 Z-close UI button missing (PROPOSAL written, safety-net cron mitigates)
- PosV5TrancheRow multi-TPE V2 BLOCKER
- PATH-1 Layer 2 KioskMachine dedicated user refactor
- UX-02 KDS card decision (Option A test-fix vs B/C)

---

## §7 — Cycle totals projected post-Phase L

- Commits : 75-90+ projected
- Sub-agents : 250-300+ cumulative
- Sentinels NEW GREEN : 280+ projected
- Frozen-zone diff : 0 LOC maintained
- NF525 chain : bit-identical + cross-chain anchor (K2-HEAL-06)

---

*Plan généré 2026-05-24 par Claude Opus 4.7 (1M context). Ultra-détaillé, ultra-abusif, ultra-decomposé. Dispatch immédiat Wave L-A en parallèle 16 agents.*
