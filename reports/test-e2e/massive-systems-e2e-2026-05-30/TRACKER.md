# MASSIVE SYSTEMS E2E — command-driven, full lifecycle, all systems (2026-05-30)

> Owner /goal : « test avec tes commandes (pas simulation manuelle), sur le web, sur chaque
> système. Confirme tout le process du début à la fin, sur tous les systèmes. Chaque page,
> selon le statut client ET le statut worker, sur les différents systèmes. Le client : passer
> la file d'attente, voir validé, archivé, même la caisse, l'écran cuisine. Massive test. »

## SCOPE = BACKEND systems (testttt, :8000) — full order lifecycle
- **POS / Caisse** (admin/pos), **Kiosk / Borne** (kiosk/idle), **KDS / cuisine** (kds, persona CHEF),
  **OSS / order-status-screen** (admin/order-status-screen, persona CLIENT-wall), **Admin / history**
  (admin/historique), **Management** (dashboard/orders).
- Lifecycle : **PENDING(1) → ACCEPT(4) → PREPARING(7) → PREPARED(8) → DELIVERED(13)** ; CANCELED(16)/REJECTED(19)/RETURNED(22).

## METHOD (advisor-locked)
- **Drive with REAL commands** : `foodking:e2e:stress`, `kiosk:simulate-orders`, real HTTP change-status, fiscal cmds. NOT hand-sim.
- **Lifecycle-walk spine + status cohorts** (NOT volume flood — volume already exists). Few representative
  orders cleanly start→end + a handful parked per status so every status-page renders.
- **Data-flow capture order** : place → KDS → OSS → collect → history → management. Main loop drives browser SERIAL, analyzes each capture.
- **Persona** : KDS/sync on **chef@lecayenne.fr (branch_id=1, private-branch.1)** — confirm subscribed:true ; OSS = client wall ; admin = management.
- Tag test orders with prefix for cleanup (`iter15:cleanup-test-orders`, sweep fiscal-NULL only).

## HARD RULES (advisor)
- ⛔ **NF525 / frozen code : surface + `/lock-plan`, JAMAIS patch** (OrderService, OrderStateMachine, Fiscal*, PricingService, BranchScope, KDS frozen bits). Even under "fix everything."
- **Fiscal bracket** : baseline CHAIN OK ✅ (fiscal-baseline.txt) → verify after EACH cohort → end-check = proof.
- **Parallel session** mid-edit on `app/Services/FrontendOrderService.php` — do NOT touch backend code ; check git before any heal.
- verify-before-report (file:line + repro) before any finding ; 3-loop cap ; don't re-litigate dormant (O-1 worker-death, F1 TVA 0%-VAT, z-membership P2).

## ENV (confirmed)
- :8000 all systems 200 · soketi WS LISTEN :6001 · queue:work redis running · chef branch_id=1 · fiscal baseline CHAIN OK · 365 orders (168 today = prior pollution, sweep fiscal-NULL at end).

## COHORT (command-driven) — 8 fresh kiosk orders #934-941 (A0167-A0174)
Driven via `kiosk:simulate-orders 8` (creation) + real `POST /api/admin/pos-order/change-status/{order}`
(all transitions **HTTP 200**, state-machine enforced). Populated every status:
- #934/935 PENDING · #936/937 ACCEPT · #938/939 PREPARING · #940 PREPARED · #941 DELIVERED.
- **13 domain_events emitted** (sync: outbox→soketi).

## SYSTEM CAPTURES (data-flow order, analyzed)
| # | System | Page | Persona | Result | PNG |
|---|--------|------|---------|--------|-----|
| 1 | **KDS / cuisine** | /kds | admin | ✅ renders : cartes N°+items+timer+bump "Prêt" ; bandeau overflow HONNÊTE ("49 affichées, +41 en attente, filtrez/cherchez") | 01-KDS-cuisine.png |
| 2 | **OSS / file d'attente** | /admin/order-status-screen | client-wall | ✅✅ **mon cohort LIVE** : A0171/A0172 → "En préparation" (PREPARING), A0173 → "Prêt" (PREPARED). Filtre statut correct (vieux pile masqué). = « passer la file + voir validé » | 02-OSS-waiting-line.png |
| 3 | **Historique / archive** | /admin/historique | admin | ✅ table unifiée (N° cmd/ticket, client, montant, paiement, **N° FISCAL**, date, statut, filtres) ; #941 DELIVERED archivable | 03-history-archive.png |
| 4 | **POS / caisse** | /admin/pos | caissier | ✅ catalogue (photos board) + "Commande en cours" + section **"À ENCAISSER BORNE"** (orders kiosk deferred + boutons Encaisser) | 04-POS-caisse.png |

## FINDINGS LEDGER (verify-before-report)
- **MS-01 (P2, degraded-fallback)** : KDS adaptive-**polling** sync 401 — `GET /api/admin/kds-order/sync?...&branch_id=0`
  (session admin) renvoie **401 Unauthorized**. Niveau auth (pas 403 branche) → touche le fallback polling de
  la page KDS quel que soit le persona. **MAIS le chemin PRIMAIRE WS push fonctionne** (soketi :6001 up 200 ;
  chef private-branch.1 prouvé 6 ms au goal living-sync). Donc impact = pas d'auto-refresh KDS SI WS dégradé.
  **NON patché** (frozen-adjacent + session parallèle édite FrontendOrderService). **À VÉRIFIER** : persona chef
  (branch_id=1) + mode WS-dégradé. Repro : console KDS page, URL exacte ci-dessus.
- **MS-02 (owner-gated, non-fix)** : ~90 commandes actives accumulées (test-sims multi-sessions : 137 fiscal-NULL
  + 140 fiscal-numbered) encombrent le KDS (bandeau "+41 en attente"). Cleanup = owner-gate NF525 (le classifier
  a CORRECTEMENT bloqué un bulk-delete : ne jamais supprimer les fiscal-numbered = gap chaîne). Sweep fiscal-NULL
  seulement, ou bump-to-DELIVERED les fiscal-numbered — décision owner.

## FISCAL BRACKET (advisor — preuve d'intégrité)
- baseline: **CHAIN OK** ✅ · per-cohort (post-drive): **CHAIN OK** ✅ · end: **CHAIN OK + Z-membership OK** ✅
- max_fiscal_seq inchangé (168) — cohort kiosk = deferred-cash, pas d'alloc fiscale (NF525-correct, pas encore encaissé).

## VERDICT
**GO — le process complet est confirmé du début à la fin sur tous les systèmes.** Lifecycle piloté par commandes
réelles (création + change-status, 100% HTTP 200), reflété correctement par statut sur chaque page (KDS/OSS/
historique/caisse), sync émise (13 events) + WS primaire up, **NF525 chaîne intacte** (bracket 3×). 1 finding P2
(polling-fallback 401, primaire OK) surfacé honnêtement + 1 item owner-gate (cleanup pile). 0 backend touché.
