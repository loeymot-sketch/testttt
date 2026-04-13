# Backlog synthétique (aligné vision Le Cayenne)

Source principale : [`../PROJECT_CONTINUITY_AND_VISION.md`](../PROJECT_CONTINUITY_AND_VISION.md) section 6 + [`../../reports/planning/AUDIT_PROFOND_PLAN_MASSIF_2026-03-31.md`](../../reports/planning/AUDIT_PROFOND_PLAN_MASSIF_2026-03-31.md) phases A–E.

## P0 — Infrastructure & fiabilité

- Queue **asynchrone** en production (`database` / `redis`) + **worker** supervisé (éviter `sync` seul).
- **Temps réel** : `BROADCAST_DRIVER=pusher`, Soketi/Pusher opérationnel, `.env` complet sur chaque environnement.
- Vérification **FCM** (clés projet) si les pushes sont requis métier.

## P1 — Produit & doc

- **Amend commande POS** (modifier commande déjà validée) : spec + API + UI — largement non livré.
- **Aligner** `docs/DEVICE_FLOW.md` avec Echo/polling/FCM (éviter confusion Firebase seul).
- **E2E multi-écrans** (Anti-Gravity) avant mise en salle critique.

## P2 — Parité borne / Splash (voir rapports planning)

- « Comme d’habitude » / dernière commande.
- Upsell / merchandising par catégorie (déjà partiellement amorcé côté schéma/admin).
- Slides attract / config pages avancée (comparatif Splash).

## P3 — Qualité technique

- Refactor strict types sur payloads `OrderCreated` / `OrderStatusChanged` si besoin outillage.
- Optimiser fan-out `ItemAvailabilityChanged` si explosion du nombre de branches.

## Rappel : ne pas casser

- Recalcul **serveur** des prix ; pas de confiance au `discount` client sur les lignes.
- **Idempotence** borne ; isolation **branche** kiosk.
- **OrderStatusChanged** sur tous les chemins statut pertinents (KDS, POS, annulations).
- Zones gelées : `docs/ARCHITECTURE.md`.
