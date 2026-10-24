# G7 — Reconnaissance production (lecture seule)

Date : 2026-09-03, 02h55 · Accès : `ssh lecayenne` · Aucune écriture, aucune commande d'effet.

## 1. Le dump de 20 octets : NON REPRODUIT en production

L'audit externe signalait « un `pre-deploy` gzip de 20 octets à côté de deux sauvegardes
plausibles ». Relevé réel de `/var/www/lecayenne/storage/backups/db-daily/` :

```
1 798 376  Aug 30 01:00  daily-2026-08-30.sql.gz
1 853 835  Aug 31 01:00  daily-2026-08-31.sql.gz
1 909 704  Sep  1 01:00  daily-2026-09-01.sql.gz
1 946 175  Sep  2 01:00  daily-2026-09-02.sql.gz
1 980 994  Sep  2 22:43  daily-2026-09-03-2243.sql.gz
1 981 603  Sep  2 23:16  daily-2026-09-03.sql.gz
1 971 113  Sep  2 19:57  pre-deploy-20260902-195702.sql.gz
1 991 970  Sep  3 00:08  pre-deploy-caisse-controle-20260903-000836.sql.gz
```

34 fichiers, tous plausibles. `gzip -t` sur la plus récente quotidienne : **OK**.
Aucun fichier de moins d'un kilo-octet, hors `.gitkeep`.

**Verdict : le fichier d'un octet est LOCAL**, pas en production — c'est
`storage/backups/db-daily/not-sql.gz` dans l'arbre de développement, déposé le 2 septembre à
21h06, manifestement pour éprouver le filtre. Le banc qui manquait existe désormais
(`tests/Feature/Backup/TroisSurfacesDesignentLaMemeSauvegardeTest`, troisième cas) et prouve
qu'il n'est jamais retenu. **G7/T7.1 est clos.**

## 2. PHP 8.1 en production, 8.4 dans le script : CONFIRMÉ

```
PHP 8.1.2-1ubuntu2.25 (cli) (built: Jul 16 2026 18:32:33) (NTS)
```

Contre `scripts/deploy/server-setup.sh:154-172` qui installe `php8.4`, `php8.4-fpm`,
`php8.4-mysql`, `php8.4-redis`, et `nginx.conf.template:19` qui pointe vers
`php8.4-fpm.sock`.

Exécuter ce script tel quel sur l'hôte actuel n'est pas un déploiement : c'est une montée de
version majeure de PHP non planifiée, sur la machine qui encaisse. **T7.2 et T7.3 restent
ouverts, et la porte propriétaire P2 aussi.**

## 3. Une trouvaille de la reconnaissance

```
0 octet  Aug 30 16:07  storage/backups/item_variations-avant-sync-20260830-160702.sql
```

Une sauvegarde de sécurité prise **avant une synchronisation de données**, et elle est **vide**.
Elle n'appartient pas à la chaîne quotidienne et ne fausse donc aucune sonde — mais si cette
synchronisation avait mal tourné le 30 août, le filet n'existait pas.

Ce n'est pas un défaut de code : c'est une commande manuelle dont personne n'a lu la sortie.
À porter au propriétaire, avec la question : d'où vient cette sauvegarde, et qui devait la
vérifier ?

## 4. Ce que la décision G4 donne en production

Le motif partagé `daily-*.sql.gz` désignerait ici `daily-2026-09-03.sql.gz` (23h16, 1,98 Mo) —
la vraie sauvegarde du jour — et **exclurait** correctement les deux `pre-deploy-*`, qui sont
des dumps ponctuels de déploiement et non la chaîne de protection.

La décision se comporte donc en production comme prévu.

## 5. Disque

`/dev/sda1  73G  17G utilisés  56G libres  24%` — aucune pression.
