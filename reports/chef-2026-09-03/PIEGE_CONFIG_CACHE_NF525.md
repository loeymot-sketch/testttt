# `php artisan config:cache` rend la chaîne NF525 invérifiable

**Mesuré en production le 2026-09-03, pendant le déploiement.** Reproduction directe,
deux commandes, résultat inversé à chaque fois.

## La mesure

```
php artisan config:cache
php artisan fiscal:verify-chain --all
  →  * audit_logs.id=1
     SWEEP COMPLETE — TAMPER detected on 1/1 branches

php artisan config:clear
php artisan fiscal:verify-chain --all
  →  + branch=1 CHAIN OK
     SWEEP COMPLETE — CHAIN OK on every active branch
```

Aucune ligne n'a été écrite pendant la fenêtre (0 commande, 0 `audit_logs` en 30 min) :
**la chaîne n'a pas été abîmée.** L'installation a été laissée sans cache de configuration,
et `fiscal:verify-chain --all` rend `CHAIN OK`.

## La cause

`app/Services/Fiscal/AuditLogService.php:324` lit une **clé d'environnement dynamique** :

```php
$override = env('FISCAL_AUDIT_SECRET_BRANCH_'.$branchId);
```

Après `config:cache`, Laravel ne charge plus `.env` : tout `env()` hors fichier de
configuration renvoie `null`. Le secret de branche disparaît, la signature retombe sur
le secret par défaut, et les lignes signées avec le secret de branche cessent de se
reproduire. Le validateur s'arrête sur la première — d'où « id=1 ».

Le piège était **connu et écrit dans le code** (`FiscalChainValidator.php:189`, « piège connu
qui rend `env()` nul ») mais il avait été écarté lors d'une autre enquête, et jamais gardé.

## Pourquoi c'est sérieux

Ce n'est pas qu'un faux signalement de vérification. Si une commande est encaissée pendant
que la configuration est en cache, la nouvelle entrée `audit_logs` est **signée avec le
mauvais secret**. La chaîne se scinde alors durablement, et aucune commande ne la répare :
`audit_logs` est en append-only, garanti par un déclencheur de base.

Et la procédure de déploiement du dépôt prescrit `config:cache` — à six endroits
(`docs/DEPLOIEMENT.md` ×4, `docs/DEPLOYMENT_GUIDE_V1.md`, `docs/PRODUCTION_GOLIVE_CHECKLIST.md`).

## Ce que je n'ai PAS fait, et pourquoi

Il existe une voie de correction dans un fichier **non gelé** : `secretFor()` accepte déjà
un `fiscal.audit_secret` sous forme de **tableau indexé par branche**, et `config/fiscal.php`
pourrait capturer les surcharges `FISCAL_AUDIT_SECRET_BRANCH_*` au moment où la config est
construite — donc les faire survivre au cache.

**Mais** le même `secretFor()` lève une `RuntimeException` si la valeur est un tableau et que
la branche demandée n'y figure pas : une branche sans surcharge cesserait de pouvoir écrire.
Rendre ce repli sûr exige de modifier `AuditLogService.php`, qui est en **zone gelée §7**
(fichier NF525). Cela demande un LOCK et un contreseing du propriétaire — pas une décision
d'agent à 2 h du matin sur le chemin d'écriture fiscal.

## À faire

1. ⛔ **Ne pas lancer `config:cache` sur cette installation** tant que le point 2 n'est pas fait.
2. Corriger sous LOCK : faire capturer les surcharges par branche dans `config/fiscal.php`,
   **et** rendre le repli de `secretFor()` sûr quand la branche manque du tableau.
3. Retirer `config:cache` des six procédures, ou l'y encadrer d'un avertissement.
4. Ajouter une sentinelle : après `config:cache`, `fiscal:verify-chain --all` doit rester vert.
