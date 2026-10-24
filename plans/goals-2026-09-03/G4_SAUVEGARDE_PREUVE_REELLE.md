# G4 — La sauvegarde prouve la sauvegarde du jour, ou elle ne prouve rien

Défauts couverts : **V-08, V-09** (P1) · **V-12** (P2) · **N-01** (trouvé en propre, P2)
Dépendances : aucune. C'est le GOAL le plus dangereux du lot : il porte sur la seule chose qui
protège contre une perte de données.

---

## Le défaut, dit simplement

Le cockpit affiche « sauvegarde vérifiée, réellement remontée ». Trois mécanismes différents
permettent à cette phrase d'être fausse.

1. **Le drill n'est rapproché d'aucun fichier.** Le cockpit publie côte à côte le *dernier
   fichier de sauvegarde* et le *dernier résultat de restauration*, sans jamais vérifier qu'ils
   parlent du même fichier. Un dump B corrompu, arrivé après un drill vert sur A, est présenté
   comme « réellement remonté ». Le nom **et** le SHA-256 sont pourtant persistés tous les deux :
   il ne manque que la comparaison.

2. **Six sorties d'échec sur neuf ne laissent aucune trace rouge.** `backup:verify-restore` rend
   `FAILURE` sans persister de verdict dans six cas : fichier illisible, base live inaccessible,
   base de travail impossible à créer, etc. Le succès de la veille reste alors le « dernier
   résultat connu » et `/health/ready` continue d'afficher « restauration vérifiée ». Pendant
   jusqu'à 48 heures.

3. **La carte reste verte 29 minutes par jour alors que la bande d'alertes du même écran dit le
   contraire.** Le serveur compare bien l'âge en décimal (`> 26`), mais publie dans le JSON la
   valeur **arrondie à l'heure**, et l'écran recalcule son vert dessus (`26 <= 26`). Entre
   26 h 01 et 26 h 29, le même écran se contredit.

Le troisième point n'était dans aucun des deux audits externes : l'un le plaçait côté serveur,
où il est déjà corrigé.

## Ancres vérifiées (2026-09-03)

| ID | Fichier:ligne | Constat |
|---|---|---|
| V-08 | `SyncOverviewController.php:758` (`dernier_fichier`) et `:871` (`restauration`) | publiés côte à côte, jamais rapprochés |
| V-09 | `BackupVerifyRestoreCommand` | persistent : 144, 219, 291 — **ne persistent pas** : 108, 116, 126, 149, 181, 207 |
| V-12 | `app/Support/Backup/RestoreDrillResult.php:35` | `MAX_AGE_HOURS = 48` contre 26 h au contrat readiness |
| N-01 | `SyncOverviewController.php:834` | `$ageHeuresExact > 26` — **correct** |
| N-01 | `SyncOverviewController.php:868` | publie la valeur **arrondie** |
| N-01 | `SystemHealthComponent.vue:229` | recalcule le vert sur l'arrondi : `26 <= 26` |

## Tâches

- **T4.1 — V-08, lier la preuve au fichier.**
  Banc : `(À CRÉER) tests/Feature/Observability/RestoreDrillAttesteFichierCourantTest.php`
  Injecter : drill vert portant `file=A`/`sha=aaa`, `dernier_fichier=B`/`sha=bbb`.
  Exiger : cockpit **et** `/health/ready` dégradés, et un message qui nomme l'écart.
  Rouge avant correctif.
  Correctif : comparer nom **et** SHA-256 ; si l'un diffère, statut `attention`/`unknown`, jamais
  `vert`. Le texte affiché ne doit plus dire « cette sauvegarde a été remontée » quand il ne le
  sait pas.

- **T4.2 — V-09, un échec efface toujours l'ancien vert.**
  Banc : `(À CRÉER) tests/Feature/Backup/RestoreDrillPersisteTousLesEchecsTest.php`
  Un cas par sortie non persistée : 108, 116, 126, 149, 181, 207. Après chacune, exiger que
  `RestoreDrillResult::current()` rende un verdict **rouge**, pas le succès précédent.
  Correctif : faire passer **toutes** les sorties `FAILURE` par un enregistreur unique de verdict.
  Un `try/finally` ou un enregistreur appelé sur chaque chemin — pas six appels recopiés.

- **T4.3 — V-12, un seul seuil.**
  `RestoreDrillResult::MAX_AGE_HOURS` passe de 48 à **26**, aligné sur readiness.
  Banc : dans `tests/Feature/Observability/SystemHealthRestoreDrillTest.php`, ajouter les bornes
  25 h 59 / 26 h 00 / 26 h 01 / 48 h. Le cas 27 h doit devenir rouge.

- **T4.4 — N-01, cockpit et readiness comptent pareil.**
  Banc : `(À CRÉER) tests/js/systemHealthAgeSauvegardeNonArrondi.spec.js` — payload à 26 h 20 :
  la carte doit être rouge, pas verte. Et
  `(À CRÉER) tests/Feature/Observability/CockpitEtReadinessMemeAgeSauvegardeTest.php` — pour
  26 h 01, 26 h 15 et 26 h 29, les deux surfaces doivent rendre le même verdict.
  Correctif : publier la valeur décimale (ou le verdict serveur lui-même) et ne plus recalculer
  côté écran. Le serveur décide, l'écran affiche.

- **T4.5 — Exercer réellement la commande.**
  Après correctifs : lancer `php artisan backup:verify-restore` sur une vraie sauvegarde locale,
  puis sur un fichier volontairement tronqué, et consigner les deux sorties **et** les deux
  verdicts persistés dans `reports/supervision/2026-09-03/G4-drill-reel.txt`.
  Vérifier au passage que le fichier `storage/backups/db-daily/not-sql.gz` présent dans l'arbre
  (20 octets, cf. G7) est bien **rejeté** par le filtre `*.sql.gz`.

## Acceptation

- `tests/Feature/Observability/RestoreDrillAttesteFichierCourantTest.php` — VERT, prouvé rouge avant.
- `tests/Feature/Backup/RestoreDrillPersisteTousLesEchecsTest.php` — VERT, 6 cas.
- `tests/js/systemHealthAgeSauvegardeNonArrondi.spec.js` — VERT.
- `tests/Feature/Observability/CockpitEtReadinessMemeAgeSauvegardeTest.php` — VERT.
- Non-régression VERTE : `tests/Feature/Observability/SystemHealthRestoreDrillTest.php` ·
  `SystemHealthTest.php` · `tests/Unit/Backup/BackupVerifyRestoreLogicTest.php` ·
  `tests/js/systemHealthSauvegardeRestauration.spec.js`.
- Sortie réelle de la commande consignée, dans les deux cas.

## Surface visuelle

`http://127.0.0.1:8766/admin/observability/system` — compte Admin.
Quatre états capturés et analysés : sauvegarde fraîche + drill vert du **même** fichier ·
sauvegarde fraîche + drill vert d'un **autre** fichier (doit être rouge) · drill en échec ·
sauvegarde de 26 h 20 (doit être rouge, et cohérente avec la bande d'alertes du même écran).

## Condition de sortie

Deux rondes identiques. Et surtout : **aucun chemin par lequel un vert puisse survivre à un
échec.** Si un seul demeure, ce GOAL n'est pas fermé.
