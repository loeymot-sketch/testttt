# PROPOSAL — KioskAppComponent.vue — Idle timer DISABLED on payment/confirmation routes without a hard safety-net timeout

**ID** : PROP-KioskAppComponent-001
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
**Frozen reason** : Per `CLAUDE.md §7` Frozen Zones — `KioskAppComponent.vue` is explicitly listed alongside `KioskWizardComponent.vue` and `KioskUpsellComponent.vue`. The shell-level component bears the strictest production-validated lock; any change to its lifecycle / timers must traverse an owner gate.
**Existing LOCK** : none.

## Finding (read-only audit)

`startIdleTimer()` at L875–904 returns early when `this.$route?.name` is in `['kiosk.idle', 'kiosk.waiting', 'kiosk.payment', 'kiosk.confirmation']`. The inline comment (L877–880, tagged `AUDIT-52-BUG3`) justifies disabling the timer on `kiosk.payment` and `kiosk.confirmation` to prevent a 60s reset firing mid-TPE-transaction (which would create a paid order with no ticket displayed). The reasoning is sound for the *target* failure mode.

However, the consequence is that **no timeout whatsoever** runs on `kiosk.payment` / `kiosk.confirmation`. If a customer abandons the kiosk mid-payment (typical real-world scenarios: card declined → walks away frustrated; phone call interrupts; child pulls them away; loses NFC card; TPE prompts PIN and they leave), the kiosk remains stuck on the payment screen indefinitely until:
- A staff member notices and physically resets the borne, OR
- The next customer arrives and finds the kiosk unresponsive, OR
- The backend `kiosk_idle_recovery` cron sweeps (if any — not confirmed in this file).

There is no defense-in-depth hard ceiling (e.g. "after 10 minutes on payment screen with no touch, force reset regardless of TPE state"). The "still here?" overlay never fires either, so PCI-DSS-style "session must time out" is also bypassed.

### Personas impacted
- **Client-impatient** (LOW — they finish or leave; if they leave, next customer suffers).
- **Client-50ans-presbyte** (MEDIUM — slower TPE interaction; higher chance of abandonment-by-confusion mid-flow).
- **Cashier-multitask** (HIGH — at single-cashier branches like Le Cayenne V1, staff is busy with other orders; a stuck-payment kiosk costs ~5–10 min of lost throughput per incident).

## Reasoning fort (multi-perspective)

### Chef perspective
Indirect impact — a stuck-payment kiosk delays the order from entering the kitchen. Chef sees no new ticket, assumes lull, slows pace. Once unstuck, sudden batch fire. Minor friction.

### Client perspective
The *next* customer arriving at a stuck borne sees a screen they cannot recover from (payment confirmation locked to a transaction they didn't initiate). They walk away or queue at the counter. Direct revenue loss.

### Cashier perspective
In V1 single-cashier Le Cayenne, the cashier may not notice the stuck borne for several minutes. When she does, recovery requires physically touching the borne, possibly cancelling a phantom transaction in the TPE, then resetting. Each occurrence ≈ 2–5 min of cashier attention away from her primary register. **This is the highest real-world cost.**

### Owner perspective
"No useless complexity V1" — but this is not adding a feature, it's adding a *safety net* that the current code lacks. The proposed fix is a single hard-ceiling timeout (e.g. 15 min) that is *much longer* than any legitimate payment interaction, so the existing inline rationale ("60s reset would fire mid-TPE") is preserved.

### Multi-tenant-future
A high-volume SaaS tenant with 4+ bornes per branch will see this stuck-borne pattern 2–5× per day in absolute terms. Without a hard ceiling, support tickets accumulate ("our kiosk is frozen again"). Defense-in-depth here scales.

### Adversarial dispute (challenge yourself)
- **False positive?** Possible — if there is an upstream cron/watchdog (e.g. PHP `KioskOrderTimeoutCron`) that detects stuck payment-initiated orders and force-resets the borne via push notification, the gap is covered server-side. **I did not find evidence of such a cron sweep targeting borne-side reset in this file or its imports.** A grep in the parent agent's scope should confirm.
- **TPE race?** YES — the inline `AUDIT-52-BUG3` warning is real. A naive 60s reset *would* break payment. Mitigation: gate the hard ceiling at 15 min minimum AND require `kioskHardware.tpeIsIdle()` check before reset (the `kioskHardware` service is already imported, L186).
- **Goal cares?** Yes — `feedback_pos_simulation_hardware_pattern` and `project_pos_payment_fix_2026-05-18` both emphasize end-to-end recoverability; a stuck-payment kiosk violates the spirit even if not the letter.
- **Scope-minimal possible?** Yes — see "Proposed change" below.

## Proposed change

```diff
-      // [AUDIT-52-BUG3] Also disable timer on payment and confirmation screens:
-      // - kiosk.payment: client interacts with physical TPE (no touchstart on screen) — 60s reset
-      //   would fire mid-transaction, creating a paid order with no ticket printed.
-      // - kiosk.confirmation: order already placed, resetting here loses the receipt display.
-      const noTimerRoutes = ['kiosk.idle', 'kiosk.waiting', 'kiosk.payment', 'kiosk.confirmation'];
-      if (noTimerRoutes.includes(this.$route?.name)) return;
+      // [AUDIT-52-BUG3] Disable the short idle timer on payment/confirmation to
+      // protect mid-TPE transactions, BUT keep a long-ceiling safety-net so a
+      // truly abandoned borne self-recovers (PROP-KioskAppComponent-001).
+      const shortTimerSkipRoutes = ['kiosk.idle', 'kiosk.waiting', 'kiosk.payment', 'kiosk.confirmation'];
+      const longSafetyNetRoutes = ['kiosk.payment', 'kiosk.confirmation'];
+      const r = this.$route?.name;
+      if (longSafetyNetRoutes.includes(r)) {
+        // Hard ceiling 15 min — much longer than any legitimate TPE flow;
+        // double-checks TPE idleness before reset to honor AUDIT-52-BUG3.
+        this.idleTimer = setTimeout(async () => {
+          try {
+            const tpeIdle = await kioskHardware.tpeIsIdle?.();
+            if (tpeIdle === false) return; // Active transaction — bail.
+          } catch (_) { /* hardware service unavailable — proceed with reset */ }
+          try { kioskAnalytics.track('idle_reset_safety_net', { route: r }); } catch (_) {}
+          this.resetKiosk();
+        }, 15 * 60 * 1000);
+        return;
+      }
+      if (shortTimerSkipRoutes.includes(r)) return;
```

Total source LOC delta : **+18 / -5 = +13 net** (single method, single file, no new imports — `kioskHardware` and `kioskAnalytics` are already imported at L186–187).

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Customer mid-TPE (legitimate) | Protected — 15 min ceiling + `tpeIsIdle()` guard | Safe today |
| Customer abandons mid-payment | Self-recovery after 15 min | Borne stays stuck until staff resets (highest real-world cost) |
| `kioskHardware.tpeIsIdle()` not implemented | Falls through to reset (acceptable — 15 min is well past any legitimate idle) | N/A |
| Frozen-zone regression | LOW — single method body change, additive logic, existing skip-list preserved for short timer | None |
| V1 ship blocker | NONE — defense-in-depth, not feature | NONE |
| NF525 implication | NONE — `resetKiosk()` clears cart but does NOT touch fiscal sequence or audit chain | NONE |

## LOCK feasibility

- ≤20 LOC, single concern? **YES (+13 net LOC, isolated to `startIdleTimer`)**
- Architectural redesign needed? **NO — purely additive safety net**
- New imports? **NO — `kioskHardware` and `kioskAnalytics` already in scope**
- Owner gate required? **YES — file is frozen per CLAUDE.md §7**

## Owner recommendation

- [x] **APPLY-WITH-LOCK** (recommended — directly addresses a real cashier-multitask cost at V1 Le Cayenne; defense-in-depth pattern; preserves existing TPE-race protection)
- [ ] DEFER-V1.0.2
- [ ] DEFER-V2
- [ ] KEEP-AS-IS (acceptable only if owner confirms an upstream cron/watchdog already handles stuck-payment kiosks server-side, in which case this proposal is redundant)

**Pre-condition for APPLY** : verify `kioskHardware.tpeIsIdle()` exists OR accept the fall-through reset as safe. If the helper doesn't exist, add a 5-LOC stub in `kioskHardware.js` returning `null` (unknown) and adjust the guard accordingly.

**Signed-off-by-owner** : ___________  **Date** : ___________

## References
- `CLAUDE.md §7` Frozen Zones (KioskAppComponent.vue)
- `feedback_pos_simulation_hardware_pattern.md` (simulation_hardware bypass HARDWARE only)
- `project_pos_payment_fix_2026-05-18.md` (end-to-end payment recoverability)
- File L875–904 (`startIdleTimer`), L138–144 (`KioskInactivityOverlayComponent` template)
