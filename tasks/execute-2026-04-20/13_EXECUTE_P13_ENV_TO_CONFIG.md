# EXECUTE — P13_ENV_TO_CONFIG — 2026-04-20

## Status
**STATUS:** `READY_TO_LAUNCH`
**GATE_REQUIRED:** **NON** (1 ligne PHP, utility class, aucune logique métier)
**VAGUE:** V4 salve 1 (P3 hygiène — plan §1.4 ligne 89)
**BLOCKING:** Aucun

## Source
- `plans/PLAN_POST_VERIFY_2026-04-20.md` §1.4 ligne 89 (P13_ENV_TO_CONFIG)
- `reports/review/VERIFY_TRACKER_2026-04-20.md` F-VERIFY-18-04
- `reports/review/VERIFY_18_HIDDEN_RISKS_2026-04-20.md` §5.2 ligne 143 + §R5 ligne 203

## Constat factuel pré-cycle (vérifié read-only)

**Cible précise** : `app/Libraries/QueryExceptionLibrary.php:22`

```php
return env('APP_DEBUG') ? $e->getMessage() : trans('all.message.database_error_message');
```

**Bug** : appel `env('APP_DEBUG')` direct hors `config()`. Cassé après `php artisan config:cache` en production (env() retourne `null` quand le cache config est actif). Caveat Laravel documenté.

**Fix** : remplacer par `config('app.debug')` qui lit la même valeur via le cache config :

`config/app.php:44` :
```php
'debug' => (bool) env('APP_DEBUG', false),
```

→ La clé canonique est `app.debug` (cast bool, default false).

**Callsites de `QueryExceptionLibrary::message`** (preuve scope d'impact) :
- `app/Http/Controllers/Auth/DeactivateController.php:40` — réponse 422 deactivate
- `app/Services/LanguageService.php` lignes 49, 83, 101, 124, 137, 176 — exception handling i18n

Tous des chemins **exception handling DB**, comportement préservé après remplacement (même valeur lue, juste source différente).

**Pourquoi P3 et pas plus haut** : impact prod faible (chemin exception, message d'erreur générique vs détaillé). Mais bug de config cache réel → devrait être traité.

## Routing (AGENTS.md §Model Roles)
- **PRIMARY_MODEL:** `Composer` (AGENTS.md:16 — "configuration tweaks, no schema, no auth, no pricing, no lifecycle")
- **SUBAGENT:** `foodking-routine-implementer`
- **RUNNER_MODE:** `single-session`

## Scope

### SUBSYSTEMS_TOUCHED
- `app/Libraries/QueryExceptionLibrary.php` (1 caractère + 1 mot modifié — `env('APP_DEBUG')` → `config('app.debug')`)

### SCOPE_FILES (whitelist stricte — 2 fichiers)
- `app/Libraries/QueryExceptionLibrary.php` (1 ligne)
- `reports/execution/RUN_P13_ENV_TO_CONFIG_2026-04-20.md` (création)

### SUBSYSTEMS_OFF_LIMITS (strict)
- ❌ Tout autre fichier `app/` (DeactivateController, LanguageService, etc. — callsites NE bougent pas)
- ❌ `config/app.php` (clé déjà existante, ne pas toucher)
- ❌ Tests (`tests/**` — pas de test à créer pour 1 ligne utility, scope V4 strict)
- ❌ Tout autre fichier (pricing, auth, schema, lifecycle, fiscal)
- ❌ `composer.json`, `package.json`, lockfiles
- ❌ Frontend (`resources/`)
- ❌ `.env`, `.env.example`

## Invariants at Risk
- **Aucun** — `config('app.debug')` retourne EXACTEMENT la même valeur que `env('APP_DEBUG')` dans tous les cas où le config cache n'est pas actif. Avec config cache actif, `config('app.debug')` retourne la valeur correcte alors que `env('APP_DEBUG')` retourne `null`.
- Comportement runtime : identique (true → message détaillé en debug ; false → message générique en prod). **Plus correct** en prod cachée.

## Dependencies
- Aucune

## Plan bref

### Étape 1 — Lire (vérité terrain)
- `app/Libraries/QueryExceptionLibrary.php` (intégral, 27 lignes — déjà lu par parent, contenu confirmé)
- `config/app.php` lignes 40-50 (confirmer `'debug' => (bool) env('APP_DEBUG', false)`)

### Étape 2 — Modifier 1 ligne

Avant :
```php
return env('APP_DEBUG') ? $e->getMessage() : trans('all.message.database_error_message');
```

Après :
```php
return config('app.debug') ? $e->getMessage() : trans('all.message.database_error_message');
```

**Précisions** :
- Préserver l'indentation exacte
- Préserver le `?` ternaire et le reste de la ligne tel quel
- **Ne PAS** ajouter de commentaire (`// [P13_ENV_TO_CONFIG]`) — la modification est triviale et auto-explicative ; le commentaire bruite la ligne
- **Ne PAS** modifier d'autre `env()` dans `app/` même si tu en trouves (scope strict — il y en a peut-être beaucoup d'autres légitimes en provider boot, etc.)

### Étape 3 — Validation
- `git diff --stat app/Libraries/QueryExceptionLibrary.php` → 1 fichier, +1/-1
- `git status --short` (vérifier aucun fichier hors whitelist)
- `git diff app/Libraries/QueryExceptionLibrary.php` → DOIT montrer EXACTEMENT 1 ligne `-` et 1 ligne `+`, identiques sauf `env('APP_DEBUG')` ↔ `config('app.debug')`
- Vérifier syntaxe PHP : `php -l app/Libraries/QueryExceptionLibrary.php` → "No syntax errors detected"

### Étape 4 — Tests existants ne doivent PAS casser
**Pas obligatoire** mais recommandé : si tests rapides liés aux callsites :
- `vendor/bin/phpunit --filter Deactivate 2>&1 | tail -5` (test si existe pour DeactivateController)
- `vendor/bin/phpunit --filter Language 2>&1 | tail -5` (test si existe pour LanguageService)
- En cas d'absence de tests : noter dans le rapport (pas bloquant — comportement préservé par construction).

### Étape 5 — Rapport
`reports/execution/RUN_P13_ENV_TO_CONFIG_2026-04-20.md` avec gabarit Final report.

## Acceptance Tests
- [ ] `app/Libraries/QueryExceptionLibrary.php:22` contient `config('app.debug')` au lieu de `env('APP_DEBUG')`
- [ ] `php -l app/Libraries/QueryExceptionLibrary.php` → No syntax errors
- [ ] `git diff app/Libraries/QueryExceptionLibrary.php` montre exactement 1 ligne `-` et 1 ligne `+`
- [ ] **Aucun** fichier hors whitelist modifié

## Exit Criteria
- [ ] 1 fichier app touché exactement, 1 ligne changée
- [ ] PHP syntax OK
- [ ] `reports/execution/RUN_P13_ENV_TO_CONFIG_2026-04-20.md` avec Final report

## Scope Pressure Protocol (renforcé — leçons V1+V3+V4)
**STOP IMMÉDIAT** si :
- Tentation de purger d'autres `env(...)` ailleurs dans `app/` → ❌ scope strict (`grep -rn "env(" app/` retournera beaucoup de résultats légitimes en service providers / config)
- Tentation de modifier `config/app.php` → ❌ clé déjà OK
- Tentation de créer un test (`QueryExceptionLibraryTest.php`) → ❌ pas dans scope V4 salve 1
- Tentation de modifier les callsites (DeactivateController, LanguageService) → ❌ ils consomment l'API publique inchangée
- Tentation de refacto la classe (ex. injecter `Application` en static) → ❌ refacto = nouveau cycle dédié
- Tentation de modifier le message d'erreur ou la logique ternaire → ❌ scope = changer la source de la valeur, pas la logique
- Tentation d'ajouter un commentaire explicatif → ❌ change non triviale, mais le commentaire bruite (accepter la modif sèche, traçable via git blame + ce rapport)
- **Anti-pattern V3 #4** : si le diff montre des lignes `-` autres que celle ciblée → STOP + escalade

## Remediation
- Attempt 1 KO (PHP syntax) → re-fix
- Attempt 2 KO → STOP + escalade
- Aucun retry sur scope creep — STOP immédiat

## Deliverables
- Diff `QueryExceptionLibrary.php` (+1/-1)
- `reports/execution/RUN_P13_ENV_TO_CONFIG_2026-04-20.md`

## Communication
Subagent renvoie : verdict, `git status --short`, `git diff --stat`, `git diff app/Libraries/QueryExceptionLibrary.php`, output `php -l`, optionnellement output tests existants si trouvés.
