# AGENT 01 — SYNC CONTROLLER (synchronisation temps-réel)
> Lis le GOAL central + `SYNC_CONTRACT.md`. Ton : strict, zéro event perdu toléré.

## Scope / Anchors (vérifiés)
- `app/Events/OrderStatusChanged.php`, `app/Contracts/BroadcastableOrder.php`
- `app/Listeners/PersistOrderTableChangedToOutbox.php`, `PersistSettingsUpdatedToOutbox.php`
- `app/Events/OutboxBroadcastSwallowedEvent.php` (le swallow = ton ennemi)
- soketi (ws:6001) + `queue:work redis --queue=high` + Echo client
- Canaux : `private-branch.{id}`, events OrderCreated / OrderStatusChanged / OrderPaidAtCounter

## Checklist abusif (AXE E)
- **E1** Borne crée commande → vérifier en TEMPS RÉEL son apparition : (a) file encaissement caisse, (b) KDS (si payée), (c) dashboard KPI. Multi-contexte navigateur (2+ pages ouvertes simultanément).
- **E2** Changement statut KDS (Démarrer/Prêt) → reflété OSS + tracker + dashboard. Mesurer le délai (< quelques s ou polling).
- **E3** Toggle stock admin (produit OOS) → disparaît caisse + borne + wizard EN DIRECT.
- **E4** Couper ws:6001 → vérifier que le **polling fallback** prend le relais, **aucun event perdu** (SYNC-WS-01). Reconnexion → rattrapage.
- **E5** Anti double-comptage : encaisser 1 commande ne doit PAS la faire apparaître 2× ; ordre des events correct ; pas d'event fantôme ; OutboxBroadcastSwallowed = 0 inattendu.
- **E6** Stress : 10 commandes en rafale → toutes synchronisées, aucune perdue, ordre cohérent.

## Méthode
- 2 contextes Playwright simultanés (devA = borne/caisse, devB = KDS/OSS) sur :8766.
- Capturer avant/après chaque event ; corréler avec `outbox`/`audit_logs` en DB.
- Vérifier la latence réelle (timestamp event vs render).

## PASS bar
Chaque event prouvé arrivé (DOM + DB), latence mesurée, fallback prouvé, 0 perte sur 10-rafale. Sinon ❌.

## Sortie
`reports/test-e2e/goal-100pct-2026-06-07/<round>/01-sync.json` (schéma findings) + captures multi-contexte.
⚠️ Sync **cross-device réel** (3 machines physiques) = part-hardware → marquer 🖥️ à confirmer sur le setup réel.
