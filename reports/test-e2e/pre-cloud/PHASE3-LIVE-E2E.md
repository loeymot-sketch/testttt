# PHASE 3 — Live E2E pass (cross-surface, visual + timing + console)

**Date** 2026-06-05 · **Target** worktree server `127.0.0.1:8765` (worktree code = 4 P1 fixes + cloned
built bundles; API base re-pointed to :8765, CORS resolved). Driven by Playwright MCP (supervisor),
serial (single-server discipline). Screenshots in `captures/` (Read + analyzed each).

## Surfaces captured — all VISUAL + TECHNICAL green
| System | URL | Verdict | Evidence |
|---|---|---|---|
| **BORNE (kiosk)** | /kiosk/idle→/kiosk/login | ✅ visual | Clean "Borne de commande / Connexion automatique" + degraded-state card; FR; Cayenne dark theme + orange CTA; no raw labels. (The "Connexion impossible" was the pre-fix CORS artifact — degraded state rendered correctly.) |
| **CENTRAL (dashboard)** | /admin/dashboard | ✅ all dims | 0 console errors; no raw labels; load 524ms; **"Total articles menu 45"** (matches canonical SSOT), 3483 cmd / 32 056,20 €; FR; single-branch "Le Cayenne (Principal)"; quick-access incl. "Vue caisse unifiée". |
| **CAISSE (the box)** | /admin/pos | ✅ surface | 0 errors; no raw labels; real menu (Tacos/Burgers/Bols/Frites — no invented products); **"À ENCAISSER BORNE (200)"** panel (kiosk Plan-B→counter, per-order Encaisser→unification modal); live **"Article indisponible: Œuf"** stock-rupture banner; ticket-caisse pane. ⚠️ **load 6.1s** (perf P3). |
| **KDS (cooking screen)** | /kds→/admin/kitchen-display-system | ✅ visual / ⚠️ sync | 0 errors; clean "Aucune commande en cours" empty-state + "RÉCEMMENT SERVIES"; **banner: "Mode admin centralisé : rafraîchissement automatique toutes les 60 s"**. |
| **OSS (the board)** | /admin/order-status-screen | ✅ visual | 0 errors; clean 2-col "En préparation" (magenta) / "Prêt" (green); FR; correct empty-state. |

Login flow (admin@lecayenne.fr) works end-to-end (auth XHR clean after API-base fix).

## ⚠️ SYNC dimension finding (the owner's "total synchronization system")
KDS runs in **60s polling fallback** ("Mode admin centralisé … 60 s"), NOT live websocket. In this E2E
env soketi/websocket is not active, so cross-surface real-time (borne→KDS→OSS→caisse, target ~1s) is
**degraded to polling**. This is the documented degradation path, NOT a code defect — but **the SYNC
dimension cannot be marked cloud-VALIDATED until a pass is run with soketi up** (place a live kiosk order
→ measure it appearing on KDS/OSS within the latency budget). **Required for cloud-readiness sign-off.**

## What this pass did and did NOT validate
- **DID** (per surface, with proof): technical (0 console errors, loads), interface (layout/branding/FR/
  no-raw-labels), partial logic (catalog 45, encaissement-borne queue, stock banner), vision (FR/Cayenne/
  single-branch), timing (captured: dashboard 524ms, box 6.1s).
- **DID NOT**: (a) real-time SYNC (soketi down → polling) — needs a live-order cross-surface timing run;
  (b) the 8 Phase-2 box NEEDS_FIX (operator-identity, refund cascade, cash-trail, Z-bucketing, parked-
  recall, offline, wizard validation) — these are **backend-logic defects invisible to a static capture**;
  they are the heal worklist (15 P1), each needs functional TDD + a targeted interaction E2E.

## Findings opened this pass
- **SYNC-E2E-01 (P2/cloud-gate)**: real-time sync not exercised (polling fallback) → run soketi-up cross-surface timing E2E before cloud sign-off.
- **PERF-BOX-01 (P3)**: /admin/pos full load 6.1s (menu grid + 200 pending borne orders + images). Acceptable but flag for cloud (CDN/pagination).

## Next in the loop
1. Heal the box backend cluster (operator-identity NF525 first) — functional TDD, then a targeted
   interaction E2E per healed functionality (click Encaisser → modal → confirm → receipt; refund → stock).
2. Run the soketi-up SYNC E2E (live order → KDS/OSS timing).
3. Frozen-gated (M6-002/S13-02/M3-x/G-H) await owner countersign.
4. Then BORNE full-order E2E + CENTRAL history/management deep E2E. Loop per system to VALIDATED+proof.
