# Handoff S2 → S1 (borne) · S5 (KDS) · zone partagée — 2026-07-29

Issus de la vague 3 STOCK de S2 (rapport `reports/goal-s2-caisse-stock/V3-V4-STOCK-NAVIGATION.md`).
Findings reproduits par lecture de code + vérification de configuration à l'exécution.

## 1. → S1 (BORNE) et S5 (KDS) — aucun filet de secours 86 si la queue tombe (P2)
**Fait** : la propagation des ruptures 86 repose sur `ItemAvailabilityChanged` → outbox
`domain_events` → soketi. Mesurée à **< 1 s** quand tout tourne.
Mais **seule la caisse** a un filet indépendant du transport : `PosComponent.vue:3901`
`loadAvailabilitySnapshotFallback`, poll ~30 s (posé en réaction au « worker queue DOWN,
soketi UP masquait »). La borne (`KioskAppComponent.vue:548,662`) et le KDS
(`KitchenDisplaySystemComponent.vue:2463,2785`) n'ont **que** l'abonnement Echo.

**Repro** : couper le worker `queue:work` (ou redis) → poser un 86 en caisse → la caisse se
rattrape en ≤30 s ; **la borne continue de vendre le produit en rupture** jusqu'à expiration du
cache menu ou redémarrage. Le client commande et paie un produit indisponible.

**Diff proposé** : porter le même poll-fallback (snapshot de disponibilité toutes les ~30 s,
diff local) dans `KioskAppComponent` (S1) et `KitchenDisplaySystemComponent` (S5). Le modèle
exact est déjà écrit et éprouvé côté caisse — copie du pattern, pas d'invention.

## 2. → Zone partagée (EventServiceProvider) — pas de reprise BOM au remboursement PARTIEL (P2)
**Fait** : `EventServiceProvider.php:219-232` — `RefundCreated` déclenche
`ReleaseStockOnRefundCreated` + `ReleaseAvailabilityOnRefundCreated`, mais **aucun listener de
reprise matière**. La reprise BOM n'existe que sur `OrderCanceled`
(`ReverseRawMaterialsOnOrderCanceled`).

**Repro** : commande 2× Cheese Burger payée → rembourser **une seule ligne** sans annuler la
commande → `stock_levels` est recrédité de +1, `raw_material_stocks` **jamais** → dérive
permanente de 75 g de viande, 1 cheddar, 1 pain, 25 g de sauce, invisible et non réconciliée.
(L'annulation TOTALE, elle, est correcte : prouvée au gramme par S2, aller-retour exact.)

**Diff proposé** : enregistrer un listener de reprise matière sur `RefundCreated`, borné aux
lignes réellement remboursées (miroir de `ReleaseStockOnRefundCreated`, qui sait déjà lire
`refundedItems`). S2 ne l'a pas fait : `app/Listeners/**` + `EventServiceProvider` sont un
registre partagé, et la reprise partielle demande une décision de contrat (quantités partielles
côté matières).

## 3. Pour information — cron préventif d'auto-86 désactivé (P2, registre partagé)
`config('catalog_v15.auto_86_preventive_cron.enabled')` = **false** (vérifié en runtime) ;
`app/Console/Kernel.php:303-308` planifie `stock:scan-rupture` sous un `->when(...)` toujours
faux. L'auto-86 est donc **purement réactif** : un item dont toutes les options tombent à 0
entre deux commandes reste vendable jusqu'à la commande suivante. Conséquence directe : le
correctif S2 « la réception lève la rupture » est d'autant plus nécessaire (rien d'autre ne
rattrape). Activer le cron = décision owner + voie CENTRAL.
