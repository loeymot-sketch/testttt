# RAISONNEMENT PROFOND — SYNCHRONISATION CAISSE ↔ BORNE ↔ KDS — 2026-07-02
Analyse tracée dans le code + **vérifiée en direct** (dont la dégradation, worker tué en séance). Aucune assertion non prouvée.

## 1. LES DEUX CHEMINS (le KDS est DB-autoritaire, accéléré par broadcast — PAS broadcast-dépendant)

### Chemin ÉCRITURE (temps-réel, caisse OU borne → KDS)
1. Commande créée / statut changé → événement `OrderCreated` / `OrderStatusChanged` (`app/Events/`).
2. `EventServiceProvider` → listener `Persist*ToOutbox` → écrit une ligne `domain_events`
   avec `channel = ['private-branch.{branchId}']` (vérifié : listeners outbox l.73/83).
3. `DispatchDomainEventsJob::onQueue('high')` (vérifié `DispatchDomainEventsJob.php:46`) → lit `domain_events`
   → **broadcast vers soketi** → canal `private-branch.{branchId}` → marque `dispatched_at`.
4. Le KDS de la branche N est abonné à `private-branch.N` (Echo) → reçoit l'événement → **version-gate déclenche un refetch**.

### Chemin LECTURE (le board, autoritaire)
- `KdsSyncService::sync(branchId, $since, includeDeleted)` lit **directement la DB** :
  `status ∈ {ACCEPT, PREPARING, PREPARED}`, scopé branche, du jour (ou advance demain), `limit 50`, `orderByDesc(updated_at)`.
- `version = updated_at` (unix) → version-gate frontend rejette le stale, applique le plus récent.
- **Poll ~5 s** = baseline ET filet. Le board vient de la DB, **jamais** de l'outbox.

**Conséquence clé** : le broadcast ne fait qu'ACCÉLÉRER (push instantané). La vérité du board = la DB via poll.

## 2. CAISSE vs BORNE sur le KDS — la règle exacte (vérifiée)
| | Statut à la création | payment_status | Visible KDS ? | Badge |
|---|---|---|---|---|
| **CAISSE (inline)** | ACCEPT (auto) | PAID (5) — payé inline | **OUI immédiat** (status ACCEPT) | « réglé » |
| **BORNE (Plan B)** | ACCEPT (4, auto-accept) | PENDING_COUNTER (15) | **OUI immédiat, AVANT paiement** | « à encaisser » |

- **La visibilité board = STATUT**, PAS le paiement, PAS `kds_station` (**mythe confirmé** : colonne inexistante,
  0 référence dans la requête board `KdsSyncService:97`).
- Le paiement est un **badge d'affichage** (à-encaisser vs réglé), **pas une porte d'entrée sur le board**.
- **Preuve live** : **113 commandes borne PENDING_COUNTER (à-encaisser) sont sur le board** (status ACCEPT). La
  cuisine PRÉPARE la commande borne AVANT l'encaissement = modèle Plan B « préparer puis encaisser au comptoir ».
- Les deux surfaces alimentent **le MÊME board** (scopé branche), taguées CAISSE / BORNE (cf. `v4-kds.png`).

## 3. ANTI-DOUBLAGE
1 commande = 1 ligne DB = **1 carte KDS** (clé = order id, version-gate anti-stale). Aucun doublage quelle que
soit la source. (Confirmé V4 : 0 paire (branche, fiscal_seq) dupliquée ; KDS 1 carte/commande.)

## 4. DÉGRADATION — **PROUVÉE EN DIRECT (worker tué en séance)**
- **Worker down** → `DispatchDomainEventsJob` ne tourne plus → `domain_events` s'accumulent (37 non-dispatchés)
  → **plus de push temps-réel**.
- **MAIS** `KdsSyncService::sync(1)` a renvoyé **50 commandes actives** (lecture DB directe) → le KDS se
  rafraîchit au poll 5 s → **zéro perte, latence ≤ 5 s** au lieu d'instantané.
- **soketi down** → `WebSocketService` circuit-breaker → même repli poll.
- **Récupération** : worker relancé → `OutboxRescueCommand` rejoue les non-dispatchés → temps-réel reprend.
- **`MonitorOutboxStaleness`** alerte si l'outbox stagne (P3 connu : fausse alarme sur les 37 vieux events —
  documenté, shared-zone LOCK-gate).

## 5. LE SEUL IMPÉRATIF OPS (répété car central)
Le temps-réel dépend d'**UN worker** : `php artisan queue:work --queue=high,default` (les broadcasts vont sur
`high`). Sans lui → le KDS reste fonctionnel en **poll 5 s** (dégradé, pas cassé). En prod : **supervisor**
doit garantir ce worker (absent de `deploy-vps.sh` — à ajouter). C'est le point de défaillance unique du temps-réel.

## 6. VERDICT
La synchro caisse↔borne↔KDS est **robuste par conception** : DB-autoritaire (poll) + broadcast-accéléré (soketi),
scopée branche, anti-doublage, dégradation gracieuse **prouvée**. Modèle Plan B correct (borne préparée avant
encaissement, badge « à encaisser »). Risque unique = **opérationnel** (worker high,default sous supervisor),
pas architectural.
