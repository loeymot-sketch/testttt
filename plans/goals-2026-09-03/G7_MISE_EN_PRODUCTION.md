# G7 — Le déploiement ne peut pas choisir un dump de 20 octets

Dépendances : **G6 fermé.** Porte propriétaire **P2** obligatoire avant toute action distante.

---

## Les deux pièges, vérifiés

### 1. Un faux fichier de sauvegarde traîne à côté des vrais

`storage/backups/db-daily/` contient, à côté de cinq sauvegardes plausibles (76 Ko à 3,6 Mo) :

```
-rw-r--r--  1 ... 1 octet   2 sept. 21:06  not-sql.gz
```

**Un octet.** Son nom ne se termine pas par `.sql.gz`, il devrait donc être exclu par le filtre —
mais aucun test ne le prouve aujourd'hui, et le nom `not-sql.gz` indique qu'il a été déposé
exprès pour éprouver ce filtre, sans que le banc correspondant ait été écrit.

Tant que G4/T4.5 n'a pas prouvé son rejet, **aucun déploiement ne doit s'appuyer sur une sélection
automatique de sauvegarde.**

### 2. Le script d'installation exige PHP 8.4, la production tourne en 8.1

`scripts/deploy/server-setup.sh:154-172` installe et suppose PHP 8.4
(`php8.4-fpm`, `php8.4-mysql`, `php8.4-redis`…), et les gabarits nginx/supervisor s'y réfèrent
(`nginx.conf.template:19` → `php8.4-fpm.sock`).

La production, elle, tourne en **PHP 8.1** (relevé consigné dans le commit `28cd79d5a`).

Exécuter ce script tel quel sur l'hôte actuel, ce n'est pas un déploiement : c'est une migration
de version majeure de PHP non planifiée, sur la machine qui encaisse.

## Tâches

- **T7.1 — Rendre le faux dump inoffensif, et le prouver.**
  Dépend de G4/T4.5. Le banc doit montrer que `not-sql.gz` n'est jamais retenu comme candidat, ni
  par la sélection du dernier fichier, ni par la commande de vérification de restauration.
  Ensuite seulement : décider si le fichier reste (comme fixture de test, alors documenté) ou s'il
  est retiré.

- **T7.2 — Trancher la version de PHP, par écrit.**
  Trois issues, **le propriétaire choisit** :
  (a) figer les scripts sur 8.1 pour correspondre à l'hôte ;
  (b) planifier une montée en 8.4 comme opération distincte, avec fenêtre et retour arrière ;
  (c) rendre la version paramétrable et faire échouer le pré-vol si l'hôte ne correspond pas.
  Consigner le choix ici, en clair.
  Recommandation : **(c) puis (a)** — un pré-vol qui refuse de tourner sur la mauvaise version
  coûte quelques lignes et supprime la classe entière de l'accident.
  Banc : `(À CRÉER) tests/Feature/Deploy/PreflightRefuseMauvaiseVersionPhpTest.php`.

- **T7.3 — Pré-vol qui refuse au lieu de deviner.**
  `scripts/deploy/pre-flight.sh` doit vérifier et **refuser** : version de PHP de l'hôte,
  existence et taille plausible de la sauvegarde retenue, verdict de restauration frais (26 h,
  après G4), sentinelles de fraîcheur des lots vertes, `safety-check.sh` PASS, chaîne NF525 OK.
  Non-régression : `tests/Feature/Deploy/DeployScriptBackupBeforeMigrateSentinelTest.php`.

- **T7.4 — Vérifier sur le contenu servi, jamais sur le numéro de commit.**
  Après tout déploiement autorisé : comparer les SHA-256 des lots servis par la production à ceux
  de l'instantané figé en G6, et exercer trois URL réelles. Un `git push` ne déploie rien par
  lui-même ; la production ne suit pas automatiquement la pointe de la branche.
  Consigner dans `reports/supervision/2026-09-03/G7-verification-servie.txt`.

## PORTE PROPRIÉTAIRE P2 — obligatoire

| Champ | Contenu |
|---|---|
| QUI | Propriétaire, sans délégation |
| QUOI | Autorisation écrite de déployer le backend, **et** choix explicite sur la version de PHP (T7.2) |
| OÙ | Ce fichier, section Décision, plus le message du commit de déploiement |
| ÉTAT | **EN ATTENTE** |

Tant que P2 est en attente : T7.1, T7.2 (rédaction des options), T7.3 et les bancs s'exécutent
en local. **Aucune commande distante, aucun déploiement.**

## Acceptation

- `tests/Feature/Deploy/PreflightRefuseMauvaiseVersionPhpTest.php` — VERT.
- Banc de rejet de `not-sql.gz` — VERT (via G4/T4.5).
- Pré-vol qui refuse effectivement sur au moins un critère volontairement cassé — preuve consignée.
- Décision PHP écrite dans ce fichier.

## Condition de sortie

Un déploiement lancé par erreur, sur la mauvaise version de PHP ou avec une sauvegarde d'un
octet, doit **s'arrêter tout seul**. Tant que ce n'est pas prouvé par un essai réel de refus,
ce GOAL reste ouvert.

## Décision

_(à remplir par le propriétaire — T7.2)_
