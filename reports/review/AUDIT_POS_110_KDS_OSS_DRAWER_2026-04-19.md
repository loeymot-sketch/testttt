# Axes 8–9 — KDS / OSS & tiroir caisse

## KDS

- **Liste / items** : services `KitchenDisplaySystemOrderService` — filtres date + branche + limite 50.
- **P4** : `changeStatus` avec `lockForUpdate`, **409** si statut client ≠ DB, `OrderStateMachine::allows` sur ligne.
- **kds_group_id** : **absent** du codebase (grep) — si demandé côté spec, **écart** `F-KDS-002`.

## OSS

- **Contrôleur** : listes lecture ; **pas** de mutation statut via OSS dans `routes/api.php` (GET + popular-items).
- **Rôle** : écran affichage file / popular — moindre surface d’abus.

## Tiroir caisse (shift)

- **POS** : `openDrawer` importé dans `PaymentComponent.vue` (bridge matériel).
- **Lien Z / variance / main courante** : **non tracé** de bout en bout dans ce read-only (`F-DRW-001`). Nécessite lecture `kioskHardware.js`, modèle drawer s’il existe, et événements `audit_logs` associés — **hors preuve complète** ce run.

**Liens tracker :** F-KDS-001, F-KDS-002, F-DRW-001.
