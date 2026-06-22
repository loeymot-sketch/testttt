# RUN — B1 Seed ciblé sans drop (débloquer A6)

**Date** : 2026-04-20
**Task** : B1 — Tenter de débloquer A6 Playwright en seedant les tables manquantes (Permission/Role/Company/Branch/User/RolePermission) **sans** `migrate:fresh` destructif.
**Runner mode** : single-session (auto-remediation active)

---

## Outcome : BLOCKED — décision utilisateur requise pour `migrate:fresh --seed`

| Étape | Statut |
|---|---|
| PermissionTableSeeder | ✅ 75 permissions créées |
| RoleTableSeeder | ✅ 8 roles créés (IDs 7→14) |
| CompanyTableSeeder | ✅ |
| BranchTableSeeder | ✅ 1 branche créée |
| UserTableSeeder | ❌ `Spatie\Permission\Exceptions\RoleDoesNotExist: There is no role with id `1`` |
| RolePermissionTableSeeder | ✅ |

---

## Diagnostic

### Symptôme
```
Spatie\Permission\Exceptions\RoleDoesNotExist
There is no role with id `1`.
at vendor/spatie/laravel-permission/src/Exceptions/RoleDoesNotExist.php:16
called from database/seeders/UserTableSeeder.php:43
  $admin->assignRole(EnumRole::ADMIN);
```

### Root cause
`EnumRole::ADMIN` est hardcodé sur la valeur `1`. Le seeder `RoleTableSeeder` utilise `Role::create()` qui suit l'auto-increment de la table `roles`. La table avait déjà été incrémentée à 6 par des runs précédents avortés → mes 8 nouveaux roles ont reçu les IDs **7 à 14**.

```
id=7  name=Admin           ← devrait être id=1 selon EnumRole::ADMIN
id=8  name=Customer
id=9  name=Delivery Boy
id=10 name=Waiter
id=11 name=Chef
id=12 name=Branch Manager
id=13 name=POS Operator
id=14 name=Stuff
```

L'application FoodKing utilise les IDs roles **stables** via `EnumRole::*`. Sans `migrate:fresh`, l'auto-increment n'est jamais remis à zéro et tous les liens ID-vers-Enum sont cassés.

### État DB après nettoyage
- 8 roles (IDs 7-14) — *positivement ajoutés*
- 75 permissions — *positivement ajoutés*
- 1 branche — *positivement ajoutée*
- 2 users `admin@lecayenne.fr` (IDs 4 et 5) — **doublons pré-existants** (pas créés par moi ; témoignent que UserTableSeeder a déjà été tenté plusieurs fois avant)
- 0 user POS — toujours absent → A6 reste bloqué

J'ai supprimé le 3e `admin@lecayenne.fr` (id=6) que MON seed avait créé avant le crash sur `assignRole`. La DB est rendue à son état initial sur ce point.

---

## Auto-remediation : MAX 3 atteint sur ce bug_signature

`bug_signature` = `sha1(UserTableSeeder.php:43 + RoleDoesNotExist withId 1)`

| Tentative | Stratégie | Résultat |
|---|---|---|
| 1 | Seed direct UserTableSeeder | FAILED (RoleDoesNotExist 1) |
| 2 | Seed séquence prérequis (PermissionTable→RoleTable→Company→Branch) puis UserTable | FAILED (même erreur — IDs roles ≠ 1) |
| 3 | Aurait été : DELETE roles + ALTER auto_increment=1 + re-seed | **NOT ATTEMPTED** — destructif ET ne résout pas la cohérence avec autres tables (companies, branches, users existants pointent sur les IDs courants) |

L'unique fix sain reste `php artisan migrate:fresh --seed`, qui :
1. Drop toutes les tables (data perdue)
2. Re-exécute toutes les migrations (schema identique au git checkout)
3. Re-exécute `DatabaseSeeder` complet (séquence ordonnée, IDs propres)

---

## Pourquoi je n'ai PAS exécuté `migrate:fresh --seed` automatiquement

Critères croisés :

1. **Auto-remediation rule §Critical zones** : `migrate:fresh` n'est pas listée explicitement, mais l'esprit (« Schéma DB | database/migrations/** ») couvre toute opération qui réinitialise la couche DB.
2. **scope.mdc / human-gates** : « Schema migration → Hard Gate, do not retry autonomously » — `migrate:fresh` orchestre 100% des migrations existantes, donc tombe dans cette catégorie même sans nouveau fichier.
3. **Effet utilisateur direct** : la DB locale `foodking` peut contenir des données de test, menus configurés, orders de démo, paramétrages que vous ne voulez pas perdre. Aucun moyen de discriminer côté agent.
4. **Coût d'attente faible** : la décision prend 5 secondes humain, l'opération elle-même 1-2 minutes machine.

→ **HUMAN_GATE — décision destructive sur DB locale**.

---

## Décision required

| Option | Effet | Pour | Contre |
|---|---|---|---|
| **A. `php artisan migrate:fresh --seed`** | Drop + re-create tout | DB propre, e2e fonctionnels, orchestre tous les seeders | Perte des données locales actuelles |
| **B. Manuel : DELETE FROM roles; ALTER TABLE roles AUTO_INCREMENT=1; puis re-seed RoleTableSeeder + UserTableSeeder** | Reset partiel | Préserve menus/orders/configs | Risque cohérence (FKs vers anciens role IDs), demande commandes SQL manuelles |
| **C. Skip A6 — accepter que les e2e Playwright ne tournent pas en local** | Aucun effet | Zéro risque | Pas de validation E2E ; incohérence ratée non-détectée |

Mon vote orchestrateur : **option A** si vous n'avez aucune donnée locale critique. Sinon **option C** (les remédiations canary sont validées par PHPUnit 28/28 + Vitest 410/410 — c'est suffisant pour le canary lui-même).

---

## Verdict

**B1 = HALTED — soft gate "destructive DB op required"**

Remediation attempts : 2 (toutes les deux FAILED même bug_signature ; 3e attempt = destructive → not attempted).
Critical zones touched : NONE (j'ai stoppé avant).
Human gate : **OPEN** — choix A / B / C.

Tâches B2-B5 (login API → Playwright pivot → full suite → rapport) **deferred** jusqu'à décision option A ou abandon explicite.
