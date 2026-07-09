# Vérification — MonitorOutboxStaleness en FAILURE permanent (P2 durability)

**Verdict : CONFIRMED (P2, durability / alerting-hygiene).**
Cible : `app/Console/Commands/MonitorOutboxStaleness.php:79-132`, planifié
`everyMinute()` dans `app/Console/Kernel.php:50-53`.

## Repro rejouée moi-même (LIVE, DB foodking_e2e)

```
$ php artisan foodking:outbox:monitor --threshold=10
[OUTBOX STALE] 37 undispatched events older than 30s (threshold: 10) + 1 crash-claimed orphans...
ARTISAN_EXIT=1
```

En steady-state (worker `high,default` actif, aucune panne en cours) la commande
retourne `self::FAILURE`. Elle est schedulée `everyMinute()` → exit non-zéro chaque
minute.

Tinker READ-ONLY :
- `staleCount = 37` (created 2026-06-12 → 06-17, soit 16–21 j ; attempts 0–4).
  Distribution : attempts=0×20, =2×15, =3×1, =4×1. Le plus ancien (id=9212) porte
  `last_error = contract_violation: ...` → enveloppe invalide, ne validera JAMAIS.
- `crashClaimedCount = 1` : id=8194, attempts=6, dispatched_at=2026-06-12 19:08,
  last_error=`expired:quarantined`.

## Les deux conditions forcent FAILURE indépendamment, sans auto-remédiation

Vérifié par lecture de chaque lane :

| Lane | Prédicat | Atteint l'orphelin 8194 ? |
|---|---|---|
| `outbox:rescue` (OutboxRescueCommand.php:47) | `attempts < 5` | NON (attempts=6) |
| `outbox:retry-failed --since=24h` | `whereNull(dispatched_at)` + fenêtre 24h | NON (dispatched_at set + 21 j) |
| `outbox:prune --older-than-days=90` (PruneOutboxCommand.php:50-60) | `dispatched_at < now-90d` OU `attempts>=6 && created_at<now-90d` | seulement ~2026-09-10 |

L'orphelin crash-claimed est donc injoignable par TOUTE lane automatique jusqu'au
prune 90 j. Le message de la commande l'admet : « re-drive them MANUALLY ». Un seul
orphelin = pager rouge chaque minute pendant ~90 jours.

Côté staleCount : une ligne `contract_violation` (poison-pill) échoue à chaque
dispatch ; rescue la re-drive jusqu'à attempts=5 puis s'arrête (attempts<5),
retry-failed ne la prend pas (--since=24h), prune (B) exige attempts>=6 → une
ligne bloquée à attempts=5 pending n'est purgée NI par les lanes NI même à 90 j.
Classe de lignes qui s'accumule et gonfle staleCount durablement.

## Pourquoi CONFIRMED (critère durability = manque réel + fix justifié)

- **Manque réel, design-inhérent (pas seulement pollution test-DB)** : la dimension
  crash-claimed n'a AUCUN chemin d'acquittement/quarantine. Toute panne worker réelle
  survenant entre Phase-1 (claim) et Phase-2 (broadcast) crée exactement un tel
  orphelin, qui re-page ensuite chaque minute pendant 90 j sans clear automatique.
  Sur mono-poste owner-opéré sans ops dédié, l'unique alarme « worker down » devient
  rouge en permanence → indiscernable d'une vraie panne = désensibilisation.
- **Nuance adversariale honnête** : les 37 lignes actuelles sont du bruit test-DB
  (`expired:quarantined` n'est produit par aucun code — `grep quarantin app/` = 0 ;
  dates groupées juin ; 20 lignes attempts=0 jamais tentées). Il n'y a PAS de perte
  de données (backstop poll KDS). C'est un défaut de **qualité de détection /
  hygiène d'alarme**, pas de corruption. Le « pager » est aussi partiellement
  aspirationnel sur mono-box (exit-code → scheduler → backend pager owner-câblé).
- **Mais l'angle long-terme est en scope explicite (durabilité mono-box)** et le
  mécanisme non-test tient : sur 6 mois d'exploitation, orphelins de crash +
  poison-pills s'accumulent sans purge < 90 j → l'alarme se dégrade réellement.
- **Fix justifié + non-frozen** : `MonitorOutboxStaleness.php` n'est pas §7. Le
  remède proposé (séparer une dimension `dead_letter_count` à seuil propre ;
  stamp `quarantined_at` en fenêtre glissante pour que seuls les NOUVEAUX orphelins
  paginent ; prune agressif ~7 j des quarantinés ; commande one-shot pour armer le
  pager sur le stock actuel) est le pattern standard dead-letter + dé-duplication
  d'alarme. Correct et proportionné.

Le vrai défaut de fond : **absence de chemin d'acquittement/quarantine** — une
condition connue et triée re-page indéfiniment. C'est une amélioration d'hygiène
d'alarme légitime pour la fiabilité long-terme, sans toucher au cœur transactionnel.
