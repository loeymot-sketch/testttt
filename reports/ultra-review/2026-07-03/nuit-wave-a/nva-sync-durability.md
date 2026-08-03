# NVA — Sync Durability (restart worker, reconnect soketi, version-gate race, borne offline)

**Date** 2026-07-03 · **HEAD** cfc23966a · **Slug** nva-sync-durability · **Posture** refute-by-default + improve-by-default
**Cible** SYNCHRO — durabilité sous pannes répétées. READ-ONLY (aucune écriture DB/fichier hors ce rapport).

## Verdict : IMPROVABLE
Le cœur sync est **robuste** : la garde `lockForUpdate` + `dispatched_at` du `DispatchDomainEventsJob` empêche le double-broadcast au rejeu ; le KDS comble les gaps par poll `since` inclusif + version-gate ; le reconnect déclenche `forceSync`. **1 finding durabilité réel (P2)** : l'unique alarme de panne-synchro (`MonitorOutboxStaleness`) est **désensibilisée en permanence** — reproduit LIVE (exit=1 en steady-state), sans dead-letter ni remédiation automatique de la classe crash-orphan.

---

## Attaques menées + preuves de robustesse

### (1) Rejeu OutboxRescue pendant restart worker → double-broadcast / double-KDS ? — ROBUSTE
`OutboxRescueCommand` null-ifie `dispatched_at` puis re-dispatch. `DispatchDomainEventsJob::handle` Phase-1 (`lockForUpdate` + guard `dispatched_at !== null` → skip silencieux) sérialise contre tout worker straggler. Pire cas documenté = **un** broadcast en trop (advisory), jamais un double order KDS (le KDS est keyé par `order.id` + version-gated côté client). Pas de double-fiscal (broadcasts advisory, hors chaîne NF525). **Réfuté.**

### (2) Reconnect soketi après coupure → KDS resync gap-fill ou reste stale ? — ROBUSTE
`KdsSyncService` : poll de secours avec curseur `since = server_now`, borne **inclusive** `updated_at >= sinceForDb` (`KdsSyncService.php:98`) ; `server_now` capturé AVANT la requête (`KdsSyncService.php:39`) → aucune fenêtre d'événement perdu au bord (re-fetch chevauchant, dédupé par version-gate). `wsService.on('reconnect_storm')` → bypass cadence + `forceSync`. Cache 5 s sur l'endpoint = redondance bénigne (clé inclut `since` md5). **Aucun stale permanent : réfuté.**

### (3) Version-gate race — 2 updates rapides même commande, le plus récent gagne ? — ROBUSTE
`KdsSyncService.js:176-186` : `version = updated_at unix`, `gated = previousVersion !== undefined && version <= previousVersion`. Le plus récent (version haute) écrase ; égal/plus ancien gated. Backend renvoie `orderByDesc('updated_at')`. Monotone, déterministe. **Réfuté.**

### (4) Borne offline (cash-only) → reconnexion → réconciliation sans doublon ? — ROBUSTE (backstop)
Dédup assurée par `IdempotencyKeyMiddleware` (frozen) + `webhook_events` UNIQUE. Même si l'outbox `order.created` n'est jamais broadcasté (cf. finding), le KDS le voit via le poll `since` (backstop indépendant de l'outbox) → **pas de perte KDS**. Confirmé : les 21 `order.created` non-dispatchés (attempts=0) restent visibles via poll. **Pas de doublon/perte : réfuté.**

### (5) MonitorOutboxStaleness fausse-alarme → désensibilise l'unique alarme panne-synchro — **CONFIRMÉ (P2)**
Voir finding ci-dessous.

---

## FINDING P2 (durabilité) — L'unique alarme de panne-synchro est en FAILURE permanent (désensibilisation)

**Fichier** `app/Console/Commands/MonitorOutboxStaleness.php:58-96` (+ scheduling `app/Console/Kernel.php:50`, cron `everyMinute`)

### Repro LIVE (steady-state, aucune panne en cours)
```
$ php artisan foodking:outbox:monitor --threshold=10 ; echo EXIT=$?
[OUTBOX STALE] 37 undispatched events older than 30s (threshold: 10) + 1 crash-claimed orphans...
ARTISAN_EXIT=1        ← FAILURE renvoyé au superviseur/pager À CHAQUE MINUTE
```
Tinker READ-ONLY (`foodking_e2e`) :
```
total=10011  pending=37  staleOld(>30s)=37  crashOrphan=1
staleCount breakdown: attempts>=6(terminal)=0  attempts<6=37
orphan: id=8194 event_type=order.status_changed attempts=6 dispatched_at=2026-06-12 last_error="expired:quarantined"
```

### Mécanisme de désensibilisation (durabilité long-terme)
Deux conditions **indépendantes** forcent `return self::FAILURE` en permanence :
1. `staleCount(37) > threshold(10)` — `whereNull(dispatched_at) AND created_at<30s`, **sans borne d'âge haute ni séparation par `attempts`**. Un `order.created`/`loyalty.balance_changed` non-dispatché reste dans le compte jusqu'à ce que le **prune** le réclame.
2. `crashClaimedCount(1) > 0` — l'orphan id=8194 (`attempts=6`, `dispatched_at != null`, `last_error` set) est **injoignable par TOUTES les lanes automatiques** :
   - `retry-failed` filtre `whereNull(dispatched_at)` → exclu.
   - `rescue` lane-B exige `attempts<5` → exclu (attempts=6).
   - `prune` lane-A exige `dispatched_at < now-90d` → dispatched_at=2026-06-12 n'est réclamé que ~2026-09-10.
   Le commentaire du code l'admet lui-même : *"UNREACHABLE by retry-failed/rescue — re-drive them MANUALLY"*.

**Conséquence durabilité** : sur un mono-poste opéré par le propriétaire (V1 LOCAL Le Cayenne, aucune équipe ops — cf. CONSTITUTION), un pager rouge en permanence = pager mis en sourdine. Chaque restart soketi/worker qui épuise les 6 tries d'un événement (échec runtime transitoire) crée un row terminal qui **compte dans l'alarme jusqu'à 90 jours** (fenêtre prune). Sur 6 mois, l'accumulation garantit `staleCount > threshold` en steady-state → une **vraie** panne worker (50 events fraîchement empilés) devient **indiscernable** du bruit de fond. L'unique signal « synchro morte » est neutralisé.

Ce n'est **pas** une perte de données (le KDS poll est le backstop, cf. attaque #4) — c'est la neutralisation du seul détecteur de panne synchro.

### Fix proposé (dead-letter / quarantine — non-frozen, aucun fichier §7)
1. **Séparer poison-terminal du backlog-frais** dans `MonitorOutboxStaleness` : exclure `attempts>=6` de `staleCount` (ce ne sont PAS un signal « worker down ») et les compter dans une dimension `dead_letter_count` à seuil propre. `staleCount` ne doit refléter que le backlog re-drivable frais (le vrai signal panne).
2. **Remédiation auto de la classe crash-orphan** : ajouter une colonne `quarantined_at` (migration additive, hors frozen). Un row `attempts>=6` + `dispatched_at!=null` + `last_error` (ou orphan >10 min) est **quarantiné** (stamp `quarantined_at`) au lieu de compter indéfiniment ; l'alarme crash-claimed ne se déclenche que sur les **nouveaux** orphelins non-quarantinés (fenêtre glissante), pas sur le stock historique.
3. **Prune plus agressif du poison** : réclamer les rows quarantinés après une fenêtre courte (ex. 7 j) au lieu de 90 j, pour que le stock de bruit ne s'accumule pas.
4. Nettoyer le stock actuel (37 + orphan 8194) via une commande de quarantine one-shot avant d'armer le pager.

Effet : l'alarme redevient **actionnable** — FAILURE ⟺ vraie panne synchro, pas bruit historique.

**Sévérité P2** : pas de P0/P1 (0 perte data, cœur transactionnel solide, KDS poll backstop) mais défait sur la durée le SEUL garde-fou de détection de panne synchro — exactement la cible « marche aujourd'hui, échoue dans 6 mois ».
