# G2 — Le cockpit outbox dit la vérité, et agit juste

Défauts couverts : **V-02, V-03, V-04, V-05, V-06** (P1) · **V-10, V-11** (P2)
Dépendances : aucune. Exécutable immédiatement.

---

## Le défaut, dit simplement

Le cockpit outbox est l'écran qu'un exploitant regarde quand la synchro temps réel a un doute.
Aujourd'hui il ment sur cinq points, et deux de ses trois actions ne rendent pas compte de ce
qu'elles ont fait.

1. **La relance ne dit jamais combien.** Le serveur répond `requeued`, l'écran lit `retried`.
   Résultat : « Relance demandée. » — jamais « 37 événements remis en file ».
2. **Les claims en vol et orphelins sont invisibles.** Ils sont chargés dans l'état du composant
   et jamais rendus. Le cockpit peut connaître des milliers de claims orphelins sans les montrer.
3. **La purge compte la mauvaise table.** La confirmation annonce un nombre de `domain_events`
   terminaux, alors que l'action supprime des `failed_jobs`. Et le bouton lui-même est activé ou
   désactivé sur ce compteur étranger.
4. **L'audit de la purge ment quand elle rate.** `deleted => count($ids)` est écrit **avant** le
   `DELETE`. Un `DELETE` qui échoue, ou qui n'en supprime qu'une partie, laisse une ligne
   `audit_logs` — immuable, signée en chaîne — qui affirme la suppression.
5. **La sonde du worker regarde la mauvaise file.** Elle accepte n'importe quelle ligne
   `jobs.reserved_at`. Un worker de notifications bien vivant affiche donc le worker outbox
   « up » alors que la file `high` est morte : exactement le cas où l'on a besoin de l'écran.

## Ancres vérifiées (2026-09-03)

| ID | Fichier:ligne | Constat |
|---|---|---|
| V-02 | `app/Http/Controllers/Admin/Observability/SyncOverviewController.php:478` | `'requeued' => $result['requeued']` |
| V-02 | `resources/js/components/admin/observability/OutboxOverviewComponent.vue:509` | `typeof data.retried === 'number'` → toujours `null` |
| V-03 | `OutboxOverviewComponent.vue:417-418, 472-473` | `inFlight` / `staleClaimed` assignés, aucun usage dans le template |
| V-04 | `OutboxOverviewComponent.vue:82` et `:153` | texte et `:disabled` sur `terminalFailures.count` (`domain_events`) |
| V-04 | `SyncOverviewController.php:566` | `DB::table('failed_jobs')->whereIn('id',$ids)->delete()` |
| V-05 | `SyncOverviewController.php:547` puis `:566` | audit `'deleted' => count($ids)` avant le `DELETE` ; `$deleted` réel jamais remonté |
| V-06 | `SyncOverviewController.php:658-662` | `DB::table('jobs')->whereNotNull('reserved_at')` — pas de `where('queue','high')` |
| V-10 | `SyncOverviewController.php:365` | prédicat terminal sans condition sur `attempts` |
| V-10 | `app/Jobs/DispatchDomainEventsJob.php:166-172` | `last_error` écrit + claim relâché dès le 1ᵉʳ des 6 essais |
| V-11 | requête de purge | `limit(500)` appliqué avant le filtre PHP sur `DispatchDomainEventsJob` |

**Point vérifié en propre** : contrairement à ce qu'affirmait l'audit externe, **aucun test ne
verrouille `attempts=1` comme terminal**. Le seul cas `attempts=1` compté terminal
(`tests/Feature/Observability/OutboxDeliverySemanticsTest.php:117`) est un `contract_violation`,
réellement terminal via `$this->fail()`. La correction de V-10 est donc libre de contrainte.

## Tâches

- **T2.1 — V-02, contrat de relance.**
  Banc : `(À CRÉER) tests/js/outboxRelanceCompteVrai.spec.js` — monter le composant, répondre
  `{requeued: 37}`, exiger « 37 » à l'écran. Rouge avant correctif.
  Correctif : lire `data.requeued`. Garder `data.retried` en repli une version, avec commentaire
  de dépréciation daté.

- **T2.2 — V-03, rendre les états chargés.**
  Banc : `(À CRÉER) tests/js/outboxEtatsClaimsVisibles.spec.js` — payload avec
  `in_flight:{count:12}`, `stale_claimed:{count:2149,rows:[...]}`, `terminal_failures:{count:5}` ;
  exiger les trois nombres rendus, et la liste des orphelins consultable.
  Correctif : trois blocs dans le template, avec `aria-live` sur la zone qui change.

- **T2.3 — V-04, purger ce qu'on annonce.**
  Banc : `(À CRÉER) tests/js/outboxPurgeAnnonceLaBonneTable.spec.js` — payload où
  `terminal_failures.count = 5` et `failed_jobs` purgeables = 0 : le bouton Purger doit être
  désactivé, et la confirmation ne doit jamais annoncer 5.
  Correctif backend : exposer un compteur dédié `purgeable_failed_jobs`. Correctif front : texte
  et `:disabled` sur ce compteur-là.

- **T2.4 — V-05, l'audit n'atteste que le fait accompli.**
  Banc : `(À CRÉER) tests/Feature/Observability/OutboxDrainAuditApresSuppressionTest.php` —
  forcer un `DELETE` partiel (2 sur 5) et exiger `deleted = 2` dans `audit_logs` ; forcer un
  `DELETE` en échec et exiger qu'aucune ligne n'affirme la suppression.
  Correctif : déplacer l'écriture d'audit **après** le `DELETE`, avec le `$deleted` réel.
  ⚠️ `audit_logs` est chaîné HMAC : l'écriture reste un `append`, on ne réécrit jamais.

- **T2.5 — V-06, sonder la bonne file.**
  Banc : `(À CRÉER) tests/Feature/Observability/SondeWorkerBorneeFileHighTest.php` — une ligne
  `jobs` réservée sur la file `default` et rien sur `high` ⇒ le worker outbox doit être `down`.
  Correctif : `->where('queue','high')`, ou battement de cœur explicite si la file est nommée
  autrement en configuration (à vérifier dans `config/queue.php` avant de figer la valeur).

- **T2.6 — V-10, terminalité honnête.**
  Un événement à `attempts=1` avec `last_error` est **en cours de reprise**, pas terminal.
  Banc : `(À CRÉER) tests/Feature/Observability/TerminaliteExigeAttemptsEpuisesTest.php`.
  Correctif : ajouter le seuil sur `attempts` (valeur lue du job, pas recopiée).

- **T2.7 — V-11, filtrer avant de borner.**
  Banc : `(À CRÉER) tests/Feature/Observability/PurgeNeSeLaissePasAffamerTest.php` — 500
  `failed_jobs` étrangers plus vieux qu'un `DispatchDomainEventsJob` candidat ; le candidat doit
  quand même être atteint.
  Correctif : filtrer côté SQL sur la classe, ou paginer jusqu'à 500 candidats outbox.

## Acceptation

Bancs nouveaux, tous VERTS et tous prouvés rouges sans leur correctif :
`tests/js/outboxRelanceCompteVrai.spec.js` · `tests/js/outboxEtatsClaimsVisibles.spec.js` ·
`tests/js/outboxPurgeAnnonceLaBonneTable.spec.js` ·
`tests/Feature/Observability/OutboxDrainAuditApresSuppressionTest.php` ·
`tests/Feature/Observability/SondeWorkerBorneeFileHighTest.php` ·
`tests/Feature/Observability/TerminaliteExigeAttemptsEpuisesTest.php` ·
`tests/Feature/Observability/PurgeNeSeLaissePasAffamerTest.php`

Non-régression VERTE : `tests/Feature/Observability/OutboxOverviewControllerTest.php` ·
`OutboxDeliverySemanticsTest.php` · `OutboxWebActionsAreAuditedTest.php` ·
`SyncOverviewControllerTest.php` · `tests/js/observabilityOutboxRoute.spec.js` ·
`tests/js/outboxOverviewPanneReseau.spec.js`

## Surface visuelle

`http://127.0.0.1:8766/admin/observability/outbox` — en compte Admin.
Trois états à capturer et analyser : nominal · file `high` morte avec un autre worker vivant ·
purge confirmée puis annulée. Zéro erreur console. Les refus WebSocket doivent **échouer** le
banc, pas être collectés en silence.

## Condition de sortie

Deux rondes identiques, P0+P1 = 0, la documentation `docs/OUTBOX_PATTERN.md` alignée sur
`broadcast_at` comme preuve de livraison, et le runbook corrigé.
