# PROPOSAL_ZCLOSE_VUE_UI_BUTTON.md

> **Architectural decision document.** Phase G.6 audit (P1 operational)
> caught that Z-close has NO production UI trigger today. The
> companion heal G2-HEAL-06 ships a safety-net cron (lane #17, daily
> 23:55 Paris) so the V1 LOCAL Le Cayenne is operationally complete
> WITHOUT the UI. This doc proposes the Vue UI button as a follow-up,
> targeted V1.0.X — owner gate.
>
> **Status:** OPEN — owner decision required (V1 ship vs V1.0.X).
> **Owner gate:** §5 below.
> **Date:** 2026-05-23. Branch: `heal/cms-pr1-quickwins-2026-05-18`.

---

## 1. Problem statement

Today, an owner who wants to close the daily Z report has THREE non-UI
options:

1. SSH to the box and run `php artisan tinker` then call
   `app(\App\Services\Fiscal\ZReportService::class)->close(1)` —
   technical, error-prone, only fits the dev profile.
2. Hit `POST /admin/fiscal/z-report/close` with a custom `curl` —
   F.10 verified the endpoint EXISTS, but it has no admin UI binding.
3. Wait for the 23:55 safety-net cron (G2-HEAL-06) to fire — works,
   but the cashier loses real-time visibility on the close totals
   (no on-screen receipt-of-close, no reconciliation prompt).

For V1 LOCAL Le Cayenne, option (3) is the operational floor — the
restaurant closes at 22:00–23:00 typically, so the 23:55 cron always
catches the day cleanly. **No business risk.** But the absence of a
visible "Clôturer la journée Z" button is friction:

- The cashier cannot **see** that the Z is closed (no green check).
- The cashier cannot **print** the Z report receipt at closing time
  (the cron has no printer connection).
- Cash reconciliation prompt (NF525 best practice — variance ≥ €0.01
  flagged) cannot fire from a cron.

## 2. Where the button should live

Three candidate placements were evaluated against the existing admin
shell.

### Option A — Admin "Cash overview" page (RECOMMENDED)

`resources/js/views/admin/CashOverview.vue` (or the matching Wave X4
"cash overview unified" route). The page already exposes:

- Open cash drawer sessions (cashier-supervised model — see CLAUDE.md
  §8 "F-003 cash design Option A").
- Cash movements timeline (per-branch).
- Current shift totals.

Adding a **"Clôturer la journée Z"** button at the bottom of this page
mirrors the cashier's mental model: "I am finishing my shift, here is
my drawer total, now I close the Z." No new navigation surface needed.

Visual sketch:

```
┌─ Cash overview (branch_id=1) ─────────────────────┐
│ Sessions ouvertes : 1                              │
│ Mouvements aujourd'hui : 47 (espèces +€348.50)     │
│ Total TTC encaissé : €1,247.30                     │
│ ─────────────────────────────────────────────────  │
│ État Z courant : OPEN (depuis 09:14:22)            │
│ Sequence # : 142                                   │
│                                                    │
│ ┌──────────────────────────────────────────────┐   │
│ │ [   Clôturer la journée Z   ]                │   │
│ └──────────────────────────────────────────────┘   │
└────────────────────────────────────────────────────┘
```

### Option B — Dedicated `/admin/fiscal` page

A new admin page with: Z report list (last 30 days) + open/close
controls + chain verify launch. More technical, fits the admin
profile better than the cashier profile. **Decision: defer to V1.1+.**
The cash overview placement (A) is sufficient for V1.0.X.

### Option C — Admin dashboard tile

A widget on `/admin` home dashboard. **Rejected** — the dashboard is
already loaded with KPI tiles; a destructive action (Z close is
irreversible per NF525) does not belong on a "glanceable" surface
where a misclick costs more than a confirmation modal can recover.

**Recommendation:** Option A.

## 3. UI design

### 3.1 Button states (Vue 3 + Pinia / Vuex consistent with admin shell)

| State                    | Label                          | Disabled? | Tooltip                                                                |
|--------------------------|--------------------------------|-----------|------------------------------------------------------------------------|
| No open Z                | "Aucune journée à clôturer"    | YES       | "Aucune session Z ouverte pour la branche actuelle."                   |
| Open Z, no auth          | "Clôturer la journée Z"        | YES       | "Vous n'avez pas la permission `fiscal.z_report.close`."               |
| Open Z, auth, idle       | "Clôturer la journée Z"        | NO        | "Clôture définitive — non réversible. Procédure NF525."                |
| Closing in flight        | "Clôture en cours..."          | YES       | "Cache::lock `z_report_b{n}` détenu — patientez 5 secondes max."       |
| Closed (just now)        | "Clôturée à 23h47" + green ✓   | YES       | "Réception Z disponible — bouton imprimer."                            |

### 3.2 Confirmation modal

A two-step modal so a misclick on a busy cash counter cannot trigger
an irreversible close:

```
┌─ Confirmation clôture journée Z ─────────────────┐
│ Action irréversible (NF525) — la séquence sera   │
│ figée et l'archive ZIP signée générée à 02h00    │
│ inclura cette clôture.                            │
│                                                    │
│ Branche : Le Cayenne (id=1)                       │
│ Z ouvert depuis : 09h14 (14h33 écoulé)            │
│ Commandes : 47   Total TTC : €1,247.30            │
│                                                    │
│ ┌────────────────────┐  ┌──────────────────────┐  │
│ │  Annuler           │  │  Confirmer la clôture│  │
│ └────────────────────┘  └──────────────────────┘  │
└────────────────────────────────────────────────────┘
```

### 3.3 Reconciliation prompt (NF525 best practice, optional V1.0.X)

If `config('fiscal.reconciliation_prompt_enabled', false)` is true,
between "Confirmer" and the actual `POST` call, surface a cash count
input:

```
┌─ Réconciliation espèces (avant clôture) ─────────┐
│ Théorique espèces : €348.50                       │
│ Saisir le total comptage drawer : [_______] €     │
│ ─────────────────────────────────────────────────  │
│ Écart : (calculé en temps réel)                   │
│                                                    │
│ [Annuler]               [Valider et clôturer]     │
└────────────────────────────────────────────────────┘
```

This writes to `z_reports.cash_*` columns (already wired per
[AUDIT-F-003] schema additive — see `ZReport.php` lines 36–43). If
disabled, the close proceeds with `cash_*` columns left null (current
default behaviour).

### 3.4 Post-close receipt print

After successful `POST /admin/fiscal/z-report/close`:

- Show a success toast.
- Render the Z report receipt in a printable popup using the same
  template as `resources/js/components/admin/pos/PosOrderReceipt.vue`
  (Z-receipt variant). Print button visible — auto-print on first
  load via `window.print()` is owner-gate (some restaurants don't
  print every Z; just store the digital one).

### 3.5 i18n keys (FR primary, EN/AR best-effort)

```
fiscal.zclose.button.label              → "Clôturer la journée Z"
fiscal.zclose.button.disabled.no_open   → "Aucune journée à clôturer"
fiscal.zclose.button.disabled.no_auth   → "Permission requise"
fiscal.zclose.modal.title               → "Confirmation clôture journée Z"
fiscal.zclose.modal.body                → "Action irréversible (NF525)..."
fiscal.zclose.modal.confirm             → "Confirmer la clôture"
fiscal.zclose.modal.cancel              → "Annuler"
fiscal.zclose.recon.title               → "Réconciliation espèces"
fiscal.zclose.recon.theoretical         → "Théorique espèces"
fiscal.zclose.recon.input               → "Saisir le total comptage drawer"
fiscal.zclose.recon.variance            → "Écart"
fiscal.zclose.success                   → "Journée Z clôturée à {time}"
fiscal.zclose.error.locked              → "Une autre clôture est en cours, réessayez dans 5s."
fiscal.zclose.error.no_open             → "Aucune journée ouverte à clôturer."
fiscal.zclose.error.generic             → "Erreur de clôture — voir le journal fiscal."
```

## 4. API surface (already exists per F.10)

- `POST /admin/fiscal/z-report/close` — service-layer GREEN (13/13).
- Returns `200 + {z_report: {...}, signature_prefix: "abcdef123456"}`.
- Errors:
  - `409 Conflict` — no open Z (the button must pre-check via §3.1
    state, but defensive 409 handler still needed).
  - `423 Locked` — `Cache::lock z_report_b{n}` held by another close.
  - `500` — chain integrity violated (paged via fiscal channel).

No new backend surface required. The button is **frontend-only** work
+ Spatie permission `fiscal.z_report.close` already in the RBAC
matrix.

## 5. Owner decision required

| Question                                     | Default if no decision                    |
|----------------------------------------------|-------------------------------------------|
| Ship UI button in V1 or defer to V1.0.X?     | **V1.0.X** (safety-net cron #17 covers V1).|
| Reconciliation prompt enabled by default?    | **Disabled** (additive, opt-in via config).|
| Auto-print Z receipt after close?            | **No** (manual print button only).        |
| Add a "force close past midnight" override?  | **No** — DST + business_date risk; V1.1+. |

**Recommended:** ship V1 IF the implementation fits ≤8h dev (the existing
Cash overview page + admin shell + i18n infrastructure makes this very
plausible — closest comparable was Wave X4 cash overview unification
which shipped in ~6h). Use existing Vue patterns from
`PaymentComponent.vue` modals and `PosOrderReceipt.vue` print template.

## 6. Effort estimate

| Task                                              | Estimate |
|---------------------------------------------------|----------|
| Button + state machine in CashOverview.vue        | 1.5 h    |
| Two-step confirmation modal (Vue + i18n)          | 1.5 h    |
| Reconciliation prompt component (optional path)   | 1.5 h    |
| Receipt print popup + template integration        | 1.5 h    |
| Vitest + Playwright E2E close-flow capture        | 1.5 h    |
| i18n FR + EN + AR best-effort keys + sentinels    | 0.5 h    |
| **Total**                                         | **8 h**  |

Within budget if the owner says "ship V1".

## 7. Frozen zones

- `PaymentComponent.vue` — FROZEN (do not modify). Reuse modal pattern
  via reading, not copying.
- `pos-wizard.js` — FROZEN. Not touched here.
- `ZReportService.php` — FROZEN. The button only invokes the EXISTING
  controller endpoint, which calls the EXISTING `close()` method.

Frozen-zone diff for this proposal: **0** (proposal only — nothing
ships from this file).

## 8. Test plan

- **Vitest** — CashOverview.vue button-state matrix (5 states from §3.1).
- **Vitest** — confirmation modal a11y (focus trap, Escape, focus
  return).
- **Playwright** — happy path: open page → click button → confirm →
  see success toast → see Z status flip OPEN → CLOSED.
- **Playwright** — error path: 423 Locked → toast + retry button.
- **Sentinel** — i18n key matrix sentinel (no raw `fiscal.zclose.X`
  rendered as label).

## 9. Rollout

1. Owner approves this proposal.
2. Ship as a single PR (Cash overview button + modal + tests + i18n).
3. Verify with the existing `tests/Feature/Fiscal/ZReportCloseTest.php`
   suite to confirm no service-layer regression.
4. After 7 days in production with successful UI closes, the safety-net
   cron #17 becomes a true safety-net (not the primary path).
5. **Do NOT remove the safety-net cron.** Even with a UI button, the
   cron protects against the case where the cashier forgets, the
   browser crashes mid-close, or the box reboots between 22:00 and
   23:55.

## 10. References

- Phase G.6 audit finding (P1 operational): `reports/audit/phase-g/G6_findings.json`
- F.10 verdict (service-layer GREEN 13/13): `reports/audit/phase-f/F10_findings.json`
- Safety-net cron heal: G2-HEAL-06 commit (this same wave)
- Wave X4 cash overview unified: commit `adad9161f` (Wave X cycle)
- CLAUDE.md §8 F-003 cash design Option A (cashier-supervised)
