# REFUTER-1 verdict — F3-01 (2026-06-12)

VERDICT: NOT REFUTED — CONFIRMED, sev P1 maintenue.

## Vérifications indépendantes (toutes par Read/grep source + exécution)
1. file:line EXACTS:
   - PosLoyaltyRedeemModal.vue:212 = `const after = Number(event?.balance_after);` (grep confirmé, lu :190-240)
   - eventContract.js parseEvent (:62-77) retourne `{version,type,aggregateId,branchId,occurredAt,correlationId,payload}` — balance_after JAMAIS top-level
   - eventContract.js dispatch: `handler(parsed)` (lu bloc :360-400)
   - Convention frère: PosOrdersTrackerComponent.vue:705 `const data = event?.payload || {};` confirmé
2. Backend wire shape (indépendant de l'envelope hard-codée du repro):
   - PersistLoyaltyBalanceChangedToOutbox.php:45-49 met balance_after DANS 'payload'
   - EventContract::buildEnvelope (EventContract.php:81-93) garde payload imbriqué
   - DispatchDomainEventsJob.php:107-116 broadcast l'envelope TEL QUEL → raw Echo = envelope imbriquée. Aucune couche n'aplatit.
3. Repro exécutée: `node F3-repro-payload-shape.cjs` →
   A) REAL wire → customerBalance=490 STALE (event avalé) ; B) flat mock → 500 ; C) payload.balance_after → 500.
4. Spec masquante confirmée: tests/js/posLoyaltyLiveBalance.spec.js:9-12 mocke onEvents, :45-54 nourrit des objets PLATS {balance_after:...} → vert sur le mauvais contrat.

## Sévérité
P1 juste: la raison d'être client du GOAL L2 (solde live modal redeem, convergé 2026-06-11) est un no-op TOTAL,
masqué par un test vert (CLAUDE.md §3.10). 2 commits récents (2b4eb2596 EventType, ba299a657 bundle) ont déjà
réanimé ce même pipeline côté serveur — ce 3e maillon client reste mort. Pas de sur-cote SaaS/scale (feature
100% V1 locale). Pas d'impact NF525/argent (redeem revalidé serveur, dégradation = stale-until-lookup) → pas P0.

## Dedup
Pas un dedup: distinct de 2b4eb2596 (EventType::all()) et ba299a657 (bundle non build) — même feature, bug différent
jamais listé dans release/v1 A-H ni dashboard-deep 06-08.
