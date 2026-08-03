---

## Rapport d'audit — Lot NEW-01 / Phase 1bis Vague 3
**Outbox replay/dedupe consumer-side hardening**
Date : 2026-04-23 | Auditeur : Claude (rôle AGENTS.md)

---

## Verdict global

**PASS WITH WARNINGS**

Le pattern claim-then-broadcast-then-finalize-or-release est correctement implémenté. Les invariants critiques (commit_before_dispatch, idempotence, retry-friendliness) sont respectés. Cependant, 4 défauts notables sont identifiés, dont un qui brise la sémantique observable de `PayloadMismatchException` après épuisement des tentatives.

---

## Findings

| ID | Sévérité | Titre |
|----|----------|-------|
| G1 | **warning** | `failed()` écrase le préfixe `contract_violation:` — perte d'observabilité définitive |
| G2 | **warning** | `DB::transaction` qui lève elle-même ne passe pas par le catch de `handle()` — `attempts` non incrémenté, `last_error` non peuplé sur claim-DB-failure |
| G3 | **warning** | SQLite ne supporte pas `lockForUpdate()` — le vrai mécanisme de lock concurrent n'est pas testé |
| G4 | **warning** | `persistCorrelationDedupe()` : rewrite JSON complet à chaque événement, sans debounce — storm I/O potentiel |
| G5 | **info** | `sessionStorage` est par onglet — déduplication non partagée entre onglets |
| G6 | **info** | Commentaire « LRU » inexact — implémentation FIFO par ordre d'insertion |
| G7 | **info** | Événements sans `correlation_id` (null) bypassent toujours la déduplication |

---

## Détails par finding

### G1 — `failed()` perd le préfixe `contract_violation:` (warning)

**Fichier** : `app/Jobs/DispatchDomainEventsJob.php`, lignes 131–148

Le `handle()` (catch, lignes 112–117) écrit :
```php
'last_error' => $e instanceof PayloadMismatchException
    ? 'contract_violation: ' . $e->getMessage()
    : $e->getMessage(),
```

Mais `failed()` (ligne 139) écrit simplement :
```php
'last_error' => $exception->getMessage(),
```

**Conséquence** : après épuisement des 5 tentatives avec une `PayloadMismatchException`, `last_error` en base est remplacé par le message brut sans préfixe `contract_violation:`. Tout monitoring/alerte qui filtre sur ce préfixe pour détecter les violations de contrat rate les cas terminaux. La dernière écriture de `handle()` (attempt 5) mettait `contract_violation:...`, puis `failed()` l'écrase immédiatement après.

**Correction** :
```php
public function failed(\Throwable $exception): void
{
    // ...
    $domainEvent->forceFill([
        'last_error' => $exception instanceof PayloadMismatchException
            ? 'contract_violation: ' . $exception->getMessage()
            : $exception->getMessage(),
    ])->save();
```

---

### G2 — `DB::transaction` qui lève elle-même : `attempts` non incrémenté, comportement silencieux (warning)

**Fichier** : `app/Jobs/DispatchDomainEventsJob.php`, lignes 48–77

Si `DB::transaction()` lève elle-même (deadlock InnoDB, perte de connexion, timeout lock) :
- L'exception sort de `handle()` sans être attrapée par le catch des lignes 107–128.
- `$claimed = false` — la ligne 71 (`if ($skip || ! $claimed)`) n'est jamais atteinte car l'exception a déjà propagé.
- `attempts` dans `DomainEvent` **n'est pas incrémenté** (la transaction a rollback).
- `last_error` **n'est pas peuplé** par `handle()` (seul `failed()` l'écrira après les 5 tentatives, trop tard).
- `failed()` est appelé après épuisement des retries et écrit un `last_error` correct, mais entre-temps les 5 tentatives queue apparaissent comme "zero attempts" dans l'outbox.

Ce n'est pas incorrect fonctionnellement (le job sera bien retryé), mais c'est trompeur pour le monitoring et absent des tests.

**Pas de correction architecturale requise**, mais documenter explicitement ce cas dans le code + ajouter un test (voir section Tests manquants).

---

### G3 — `lockForUpdate()` no-op sur SQLite : concurrence réelle non testée (warning)

**Fichier** : `tests/Feature/Outbox/OutboxConcurrentWorkerDedupeTest.php`

Laravel émet `SELECT ... FOR UPDATE` sur MySQL/PostgreSQL. Sur SQLite (utilisé avec `RefreshDatabase` en CI), `lockForUpdate()` est un **no-op** — la méthode retourne le builder sans modifier la requête.

Le test `test_two_sequential_handle_calls_only_broadcast_once` (ligne 29) exécute deux `handle()` **séquentiellement** dans le même processus PHP. Cela teste la logique d'idempotence (`dispatched_at != null → skip`), mais **ne teste pas** le verrou concurrent :

- En production (MySQL), deux workers PHP qui lisent simultanément avant que l'un committe sont sérialisés par le lock.
- Sur SQLite en test, cette sérialisation n'existe pas. Si deux "threads" lisaient simultanément (`dispatched_at = null` dans les deux), tous deux claimeraient.

La logique est correcte pour MySQL/PostgreSQL, mais les tests ne couvrent que le cas séquentiel, pas la course réelle.

**Mitigation** : accepter comme known limitation ou ajouter un test d'intégration sur MySQL (CI matrix). Documenter dans le fichier de test que SQLite ne simule pas le verrou.

---

### G4 — `persistCorrelationDedupe()` : rewrite complet sans debounce (warning)

**Fichier** : `resources/js/services/eventContract.js`, lignes 103–116

`persistCorrelationDedupe()` est appelé **à chaque invocation de `isDuplicateCorrelation()`** qui ne détecte pas de doublon (lignes 208–209). Sur un branch à fort trafic (>50 events/min), chaque event déclenche :
- Sérialisation JSON de `seenCorrelationOrder` (jusqu'à 2048 entrées `{id, ts}`) — potentiellement ~200–400 KB selon la longueur des correlation IDs.
- `sessionStorage.setItem()` synchrone.

**Scénario 50 onglets** : chaque onglet a son propre sessionStorage (isolation par onglet), donc pas de contention entre onglets. Mais chaque onglet effectue indépendamment ce rewrite complet à chaque event. À 50 events/min × 50 onglets = 2 500 setItem() full-JSON/min. Non bloquant en pratique sur desktop moderne, mais inutilement coûteux.

**Correction recommandée** : debounce de `persistCorrelationDedupe()` (ex. 500ms) pour regrouper les écritures en burst.

---

### G5 — sessionStorage per-tab : pas de déduplication cross-onglets (info)

**Fichier** : `resources/js/services/eventContract.js`, ligne 79 (`CORRELATION_DEDUPE_STORAGE_KEY`)

`sessionStorage` est **isolé par onglet** (spec W3C). Si l'utilisateur a 2+ onglets ouverts sur la même page, chaque onglet maintient son propre set de correlation IDs vus. Un event reçu sur l'onglet A est inconnu de l'onglet B.

Ce comportement est acceptable (la déduplication cible le reload dans le même onglet, pas les duplicates cross-tabs), mais doit être documenté. Si une vraie dédup cross-onglets était requise, il faudrait `localStorage` + mécanisme de sync (BroadcastChannel API). Il n'y a pas de bug ici, uniquement une limitation connue non documentée.

---

### G6 — Label "LRU" inexact, implémentation FIFO (info)

**Fichier** : `resources/js/services/eventContract.js`, ligne 69 (commentaire) et lignes 181–193

Le commentaire dit "LRU-bounded set" mais l'implémentation est FIFO par ordre d'insertion. Un vrai LRU déplacerait un ID ré-accédé en fin de liste. Ici, un ID déjà vu n'est jamais repositionné dans `seenCorrelationOrder`. Fonctionnellement correct pour ce cas d'usage (un ID vu une fois doit juste rester visible jusqu'à TTL/éviction), mais le label est trompeur pour les futurs mainteneurs.

---

### G7 — `correlationId` null bypass total (info)

**Fichier** : `resources/js/services/eventContract.js`, ligne 198

```js
if (!correlationId || typeof correlationId !== 'string') {
    return false;
}
```

Un événement sans `correlation_id` (backend qui n'a pas rempli le champ) **n'est jamais dédupliqué**. `isDuplicateCorrelation(null)` → `false` systématique. Si le backend émet deux fois le même event sans correlation_id (ex. bug outbox qui rejoue), le frontend traitera les deux. C'est une lacune de couverture, pas un bug du code livré.

---

## Tests manquants

### PHP

**T-MISS-01** : `failed()` + `PayloadMismatchException`
- Aucun test ne couvre `failed()` appelé avec une `PayloadMismatchException`.
- Assertion manquante : après `->failed(new PayloadMismatchException(...))`, `last_error` doit contenir `'contract_violation:'`.

**T-MISS-02** : `DB::transaction` qui lève elle-même
- Aucun test pour : mock de `DB::transaction` qui throw une `\RuntimeException` (simule deadlock). Assertions attendues : exception propagée, `attempts` reste 0, `dispatched_at` reste null.

**T-MISS-03** : event avec `channel = null` ou `broadcast_as = null`
- Le code (ligne 83) skip le broadcast si l'un ou l'autre est null. Aucun test ne couvre ce cas. Assertion attendue : handle() termine sans exception, `dispatched_at` défini, aucun broadcast.

### JS

**T-MISS-04** : `isDuplicateCorrelation(null)` / `isDuplicateCorrelation(undefined)` / `isDuplicateCorrelation('')`
- Aucun test sur les entrées invalides. Assertion attendue : retourne `false`, n'ajoute rien au set, ne lève pas d'exception.

**T-MISS-05** : Comportement lors d'un reload avec entrées partiellement expirées dans sessionStorage
- Aucun test combine TTL-expired et TTL-valid dans le même storage avant reload. Assertion attendue : seules les entrées valides sont rechargées.

---

## Risques résiduels

### Race condition (production MySQL multi-worker)
Le pattern est correct sous MySQL/PostgreSQL avec InnoDB. Le vrai risque résiduel : si un worker crash **après** le commit de la transaction de claim mais **avant** la fin du catch (lignes 112–117), `dispatched_at` reste non-null dans la DB et le broadcast n'a pas eu lieu. Le retry verra `dispatched_at != null` et skippera. L'event est **perdu**.

Ce scénario nécessite un job de cleanup qui détecte les events "claimed mais pas broadcastés après N minutes" et libère le claim. C'est une lacune architecturale pré-existante à ce lot, mais ce lot ne l'adresse pas non plus.

### `failed()` race avec une transaction active
Si le queue worker appelle `failed()` pendant qu'une autre instance du même job est dans sa transaction de claim (très improbable mais théoriquement possible), `failed()` fait un `find()` + `save()` sans lock, pouvant écraser `dispatched_at` ou `last_error` que le worker actif vient juste de setter. Sévérité faible, non bloquant.

### sessionStorage quota en prod
Avec 2048 entrées et des correlation IDs UUID (36 chars) + timestamp (13 chars) + overhead JSON, le payload atteint ~120–150 KB. Les quotas sessionStorage sont généralement 5 MB par origin. Pas de risque de quota overflow avec ce design. ✅

### Ordre des champs dans `forceFill` après broadcast failure
Ligne 112–117 : `dispatched_at = null` et `last_error = ...` dans le même `forceFill`. Laravel fait un seul `UPDATE`. Si la DB est indisponible à ce moment (paradoxal — le save échoue), la libération du claim ne s'effectue pas et l'event est stuck avec `dispatched_at != null` sans que le broadcast ait réussi. Même scénario que le crash mentionné ci-dessus. Non adressé par ce lot.

---

## Résumé décision

| Domaine | Statut |
|---------|--------|
| Invariant commit_before_dispatch | ✅ PASS |
| Invariant PayloadMismatchException semantics (handle) | ✅ PASS |
| Invariant PayloadMismatchException semantics (failed) | ⚠️ FAIL — préfixe perdu |
| Invariant idempotence consumer | ✅ PASS |
| Invariant retry-friendly | ✅ PASS |
| Invariant attempts ×1 par handle (succès/échec) | ✅ PASS |
| Invariant attempts ×0 sur skip | ✅ PASS |
| Invariant attempts sur DB-transaction-throw | ⚠️ 0 incrémenté (acceptable mais non testé) |
| Frontend graceful degrade sessionStorage | ✅ PASS |
| Couverture tests concurrence réelle | ⚠️ SQLite no-op lockForUpdate |

**Décision recommandée** : **HEAL** — corriger G1 (`failed()` + PayloadMismatchException prefix) et ajouter T-MISS-01 avant merge. G3 (SQLite lock) à documenter. G4 (debounce) à planifier en backlog performance.
