# FoodKing — Testing Guide

Document court : comment rouler les tests localement, comment le CI les rejoue, et quelles suites ont un besoin de driver DB spécifique.

## 1. Suites disponibles

| Suite | Outil | Commande locale | CI workflow |
|---|---|---|---|
| Unit/Feature PHP | PHPUnit | `php artisan test` (SQLite in-memory par défaut) | `.github/workflows/phpunit.yml` (MySQL 8.0) |
| Unit/Feature PHP ciblé | PHPUnit | `php artisan test --filter=NomDuTest` | idem |
| Frontend JS | Vitest | `npx vitest run` ou `npx vitest run path/spec.js` | _(local & pré-merge)_ |
| E2E | Playwright | `npx playwright test` | `.github/workflows/playwright.yml` (MySQL 8.0) |

## 2. Driver DB par défaut

`phpunit.xml` impose `DB_CONNECTION=sqlite` + `DB_DATABASE=:memory:` pour le dev local : setup zero-config, suite rapide (~30 s).

## 3. §surface-filtering — MySQL-only contract

Certains contract tests dépendent de fonctions JSON qu'**SQLite ne couvre pas** (ou couvre de manière divergente). Dans ce cas, on :

1. **force leur exécution en CI MySQL** (workflow dédié `.github/workflows/phpunit.yml`) ;
2. **skip proprement en local SQLite** avec un message explicite, au lieu d'introduire un fallback qui masquerait la vraie régression.

### Tests concernés

- `tests/Feature/Menu/FrontendSurfaceFilteringTest.php` — repose sur `JSON_CONTAINS(channels, '"kiosk"')` via `whereJsonContains`. Le filtre `?surface=kiosk|pos|web` est une surface SSOT critique pour éviter les leaks de produits POS vers le kiosk et inversement (cf. Kiosk Phase 9.1.14).

### Rouler en local contre MySQL

```bash
# 1. Base de test MySQL prête :
mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS foodking_test;"

# 2. Surcharger le driver pour ce run uniquement :
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=foodking_test \
DB_USERNAME=root DB_PASSWORD=root \
  php artisan test --filter=FrontendSurfaceFilteringTest
```

### Pourquoi pas un fallback SQLite ?

Il serait trivial d'émuler `whereJsonContains` avec un `LIKE '%"kiosk"%'` quand le driver est SQLite. On s'y refuse car :

- le contract test vérifierait alors un predicate différent de celui qui tournera en prod MySQL ;
- la vraie régression (oubli d'une migration, changement de type de colonne, typo dans la whitelist) resterait cachée jusqu'au déploiement.

La règle du projet (audit Kiosk 2026-04-18 §9.1.14) : **pas de fallback SQLite sur les contract tests qui vérifient un comportement MySQL**. Ils sont skippés si non-MySQL, et CI les exécute obligatoirement contre MySQL 8.0 avant merge.
