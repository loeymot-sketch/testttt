# REFUTER #2 — F3-01 (handler lit event.balance_after vs contrat event.payload.balance_after)

## Verdict: NOT REFUTED — finding CONFIRMÉ, sev P1 maintenue

### Vérifications indépendantes (2026-06-12 ~03:03)
1. **file:line réels** (grep) :
   - `resources/js/components/admin/pos/PosLoyaltyRedeemModal.vue:212` = `const after = Number(event?.balance_after);` ✔
   - `resources/js/services/eventContract.js:62-77` parseEvent retourne `{version,type,aggregateId,branchId,occurredAt,correlationId,payload}` (payload NESTED) ✔ ; `:385` = `handler(parsed);` ✔
   - Convention frère `PosOrdersTrackerComponent.vue:705` = `const data = event?.payload || {};` ✔
2. **Wire backend indépendant** (pas l'envelope embarquée du 1er agent) : tinker foodking_e2e sur DomainEvent **#9199** (différent du #9144 original) → `EventContract::buildEnvelope` + `assertEnvelopeValid` OK ; `top_level_balance_after=false`, `payload.balance_after=200`. `DispatchDomainEventsJob.php:107-116` broadcast **l'envelope entière** → le handler reçoit bien `parsed` avec payload nested.
3. **Repro exécutée** (`node F3-repro-payload-shape.cjs`) : A) wire réel → customerBalance=490 STALE (event avalé par Number.isFinite(NaN)) ; B) objet plat du mock vitest → 500 ; C) `payload.balance_after` → 500. Reproduit tel quel.
4. **False-green confirmé par run réel** : `npx vitest run tests/js/posLoyaltyLiveBalance.spec.js` → 5/5 VERT alors que le spec (lignes 9-12) MOCK onEvents et nourrit des objets PLATS `{balance_after:999}` (lignes ~45-56) — jamais le vrai parse.
5. **Impact LIVE prouvé au-delà du source** : bundles servis `public/js/pos-shell.js` + `public/js/admin-shell.js` contiennent le pattern compilé cassé `Number(null==t?void 0:t.balance_after)` → le NO-OP est dans le code servi sur :8767.

### Dedup: NON — distinct des lots release/v1 A-H et dashboard-deep 06-08. Le GOAL L2 (2026-06-11) a fixé le côté BACKEND (EventType::all() 2b4eb2596 + rebuild bundles ba299a657) mais le mismatch de shape côté CLIENT n'a jamais été rapporté.

### Sev: P1 juste (pas sur-coté multi-tenant/cloud) — scénario mono-poste V1 réel (earn borne pendant modal redeem caisse ouvert) ; la raison d'être du livrable L2 est un NO-OP silencieux masqué par un test vert sur le mauvais contrat. Dégradation = solde périmé jusqu'au prochain lookup (état pré-L2), pas de corruption de données — donc P1, pas P0.
