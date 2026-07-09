# V2 Revalidation — KDS + OSS + Intersection cross-surface

Cible: GET /api/admin/kds-order/sync, /api/admin/oss-order, GET /api/frontend/oss-order (public).
HEAD 61e9ea7b7 + working-tree. Serveur LIVE 127.0.0.1:8766 (foodking_e2e). Posture: réfuter le GREEN.

## Verdict: GREEN_HELD — 0 P0/P1/P2 nouveau

## Attaques exécutées (live)

1. **PII sur OSS public** — `GET /api/frontend/oss-order` → 6 champs seulement
   (id, order_serial_no, token(queue), queue_number, order_type, status). `CDSOrderDetailsResource`
   confirmé file:line — aucun name/phone/address/total. HELD.
2. **Énumération branch_id** — branch_id=999 → `{"data":[]}` ; branch_id=-1/abc → (int)<=0 → fallback
   1re branche active (pas de leak) ; branch_id[]=1&branch_id[]=2 → 200, pas de 500. HELD.
3. **No api-key** → 400. **KDS sync no-auth (Accept: application/json)** → 401 `{"message":"Unauthenticated."}`.
   (Sans header Accept, 302 vers /login = comportement Laravel standard, clients API envoient Accept json.) HELD.
4. **Throttle oss-public (anti-énumération)** — burst 70 req → 59×200 + 11×429 `{"message":"OSS rate limit exceeded."}`.
   Limiter perMinute(60)->by(ip) confirmé RouteServiceProvider:238. HELD.
5. **kds_station=mythe** — KDS list utilise `KitchenReleaseRule::visibleStatuses()` +
   `applyBoardReleaseFilter()` (status+payment board-release), PAS kds_station. Confirmé
   KitchenDisplaySystemOrderService:74-78. HELD.
6. **Zero-doubling** — via services réels (tinker read-only) :
   KDS sync(0) → 4 orders, 4 uniques, 0 doublon ; OSS listForBranch(1) → 4 rows, 4 uniques, 0 doublon.
   1 commande = 1 carte KDS + 1 ligne OSS. HELD.
7. **Snapshot figé (intersection)** — order #5398 (TAKEAWAY status PREPARED) : composition_snapshot
   porte `captured_at` + `schema_version` (figé création). KDS et OSS lisent la MÊME entité Order →
   compo identique cross-surface. HELD.

## Held-green attesté
Toutes les attaques ont échoué à casser la cible. Aucun finding reproductible.
