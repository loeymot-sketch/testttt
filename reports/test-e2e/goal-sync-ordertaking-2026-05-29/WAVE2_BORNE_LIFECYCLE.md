# Wave 2 — BORNE order lifecycle E2E (client's place)

**Date** 2026-05-29 · HEAD `962d9d154` · Surface: Kiosk Borne (machine `kiosk-lecayenne`) · Browser: Playwright MCP

## ✅ PROVEN end-to-end (screenshots `wave2-kiosk/01..14`)
Idle → "À emporter" → menu (canonical, 11 cat, real items) → **TACOS wizard 4-step composition**
(QUELLE VIANDE: Poulet mariné → QUELLE SAUCE: Algérienne → QUEL MENU: Sans menu → RÉCAP) → AJOUTER AU PANIER
→ cart (Coca €1,50 + Tacos €8,50 = **€10,00**, composition shown) → upsell ("ET POUR TERMINER?") → decline
→ **Plan B payment** ("PAIEMENT À LA CAISSE / Veuillez payer à la caisse / €10,00") → Confirmer
→ **order N°A0004 created** ("Rendez-vous en caisse").

### DB attestation (authoritative)
- Order id=420, queue=**A0004**, branch=1, **source=kiosk**, type=10 (à emporter), total=**10.00**.
- Items: Coca €1.50 (simple) + Tacos €8.50 with **composition_snapshot frozen**: `Viande 1 = Poulet mariné (variation_id 43)` + sauce Algérienne.
- `fiscal_seq=NULL` — **CORRECT** for unpaid Plan-B order (status=4 "en attente encaissement", payment_status=15); fiscal seq allocates at **counter encashment**, not kiosk creation.

### Kiosk → KDS cascade PROVEN
KDS card [C] **N°A0004** ATTENTE 01:41 (fresh): `1× Coca-Cola 33cl` + `1× Tacos — Choix: Poulet mariné, Sauce: Algérienne · Viandes: Poulet mariné ×1`, badge EN ATTENTE ENCAISSEMENT. **composition_snapshot reached the chef intact.**

### POS counter-encashment UI PROVEN functional
"À encaisser borne" side-panel renders pending orders with **Détail / Encaisser / Annuler** per order.

## Findings
| ID | Sev | Status | Note |
|---|---|---|---|
| WAVE2-OBS-1 | — | **DOWNGRADED → dev artifact** | `localhost/api/login` 401 = `APP_URL=http://localhost:8000` vs access via `127.0.0.1`. Cross-origin cookie. NOT a prod defect (prod APP_URL=real domain). `sanctum.stateful` lists both. Optional: align dev APP_URL with documented 127.0.0.1 host. |
| WAVE2-OBS-2 | P3 | needs clean re-test | kiosk `menu`/`kiosk-event` 401 at page-load (token-hydration race + offline-first cache mask). Muddied by token-injection harness + host mismatch. **User-triggered commit succeeded** → auth works. Re-test via real UI machine-login on matching host. |
| WAVE2-OBS-3 | P2 | **HEAL candidate (non-frozen)** | `KioskCashInstructionComponent` copy "Paiement en **espèces uniquement** à la caisse" contradicts owner's counter-**card** (software TTP) model. Should read "Paiement à la caisse" (espèces ou carte). Verify i18n key + config first. |
| WAVE2-OBS-4 | P3 | UX | newest borne order not surfaced top of cashier "à encaisser" (queue-number sort, not recency). |
| WAVE2-OBS-5 | P2 | latent (test-amplified) | "à encaisser borne" capped ~50 sorted queue DESC → with >50 pending, oldest/lowest-queue (e.g. A0004) unreachable for encashment. `queue_number` not monotonic w/ creation (seeded A0031-A0110 vs live counter A0004). Low severity clean-prod; add search/oldest-first or raise cap. |
| WAVE2-DATA | — | owner DB realign | dev DB polluted with ~110 accumulated unpaid borne test orders. Owner "réaligne DB" on receipt. Not a code bug. |

## Verdict
**BORNE order-taking + Kiosk→KDS cascade = GREEN / PROVEN.** Composition fidelity client→DB→kitchen confirmed. Plan-B counter routing correct. Encashment→fiscal→OSS leg: UI-confirmed + covered by 164 PHPUnit GREEN (PosCashTrail / fiscal-seq / split-payment); live encashment drive scheduled Wave 5. 1 healable copy finding (OBS-3). No P0/P1 functional defect in the borne path.
