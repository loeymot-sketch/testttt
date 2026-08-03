# Ultra-review système D — OSS (suivi client)

HEAD 594eb92f5 + heals working-tree. Verdict: **GREEN** (production-perfect V1 LOCAL).
0 nouveau défaut réel. Aucune écriture (lecture seule + curl LECTURE).

## Invariants confirmés (file:line + preuve)

1. **Mur public poll 5s** — `PreparingAndReadyComponent.vue:266-270`
   `isPublicWall = authBranchId() <= 0` → `ossSyncService.start({ options: { intervalMsWhenConnected: 5_000 } })`.
   Staff authed (branchId>0) garde 60_000ms + push Echo `OrderStatusChanged`/`OrderCreated`
   (subscribeEcho:280-313 early-return si branchId<=0). Conforme SYNC_CONTRACT cadence 5s.

2. **0 PII payload public** — `CDSOrderDetailsResource.php:17-24` expose UNIQUEMENT
   id / order_serial_no / token / queue_number / order_type / status.
   Preuve live `GET /api/frontend/oss-order?branch_id=1` :
   `keys = ['id','order_serial_no','order_type','queue_number','status','token']` (n=2).
   Aucun name/phone/address/total. Le champ `total` vit dans `PosShortcutOrderResource.php:44`
   mais celui-ci n'est servi QUE par `index()` — gaté `permission:order-status-screen`
   (constructeur `OrderStatusScreenController.php:22`).

3. **Throttle oss-public** — `routes/api.php:1311-1316` : les 2 routes publiques
   (`/oss-order`, `/oss-order/popular-items`) portent `throttle:oss-public`.
   Limiter `RouteServiceProvider.php:238-245` = 60/min by IP, réponse 429 JSON propre.
   Anti-énumération branch_id sur feed non-auth (rationale documentée).

4. **publicIndex branch_id** — `OrderStatusScreenController.php:83-98` :
   `(int) query('branch_id',0)`, sinon première branche ACTIVE (`Branch::where status=ACTIVE orderBy id`).
   Délègue à `OrderStatusScreenOrderService::listForBranch()` — corps de requête byte-identique
   à `list()` (allowlist fail-closed KIOSK+TAKEAWAY, status IN [PREPARING=7, PREPARED=8],
   fenêtre Paris-local TZ, prune stale 8h, FIFO queue_number+id). Scope `where branch_id`
   appliqué si >0.

5. **Colonnes Préparation/Prêt** — `PreparingAndReadyComponent.vue:399-413` split par
   `status === PREPARING(7)` / `PREPARED(8)`. Enum confirmé `OrderStatus.php:9-10`.
   Live: statuts 7 servis, cohérent.

## Findings triés déjà connus (NON re-signalés)
- catch→getMessage() 422 (publicIndex:103) = backlog trait connu.
- branch_id enumeration = cloud-prep (V1 mono-branche, payload PII-free).
- Résidus e2e (CARDTEST order_type=25 token/queue null) = P3 data-hygiène connu.

## Conclusion
Système D OSS VALIDÉ. Mur public non-auth PII-free, cadence 5s, throttle actif,
scope branche correct, colonnes correctes. Rien à corriger.
