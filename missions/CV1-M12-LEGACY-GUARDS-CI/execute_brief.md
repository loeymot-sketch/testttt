# EXECUTE BRIEF — CV1-M12-LEGACY-GUARDS-CI (M-12)

## INVIOLABLE
1. Lis `AGENTS.md`, `missions/CV1-M12-LEGACY-GUARDS-CI/input.json`, `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md` (mission M-12).
2. Allowlist stricte. Hors liste → `SCOPE_PRESSURE`.
3. **Aucune modification de code produit.** Tu ne touches **pas** au contenu du dossier `kiosk_implementation/` (Dart/Flutter) ni `borne (Remix)/` autrement qu'en y posant un fichier `ARCHIVE_BANNER.md`.

## OBJECTIF EXACT

Empêcher la **réintroduction** de chemins legacy dans le runtime :
- imports JS depuis `resources/js/` vers `kiosk_implementation/`, `borne (Remix)/`, `pos-wizard.js`
- routes legacy `frontend/item-category`, `frontend/item`, `frontend/item/kiosk-upsell` (à monitorer, pas à supprimer ce cycle)
- présence dans le bundle de prod (`public/build/`) de chunks legacy

## LIVRABLES

### 1. `scripts/lint-fk-legacy-imports.sh`

Bash POSIX, exit 1 si trouve un import legacy depuis `resources/js/`.

```
Patterns à interdire (regex étendue, ripgrep si dispo, sinon grep -RnE) :
  from ['\"]\.{0,2}/?kiosk_implementation/
  from ['\"]\.{0,2}/?borne \(Remix\)/
  from ['\"]\.{0,2}/?pos-wizard
  require\(\s*['\"]\.{0,2}/?(kiosk_implementation|borne \(Remix\)|pos-wizard)
Cibles : resources/js/**/*.{js,vue,ts}
Exclusions : tests/, public/, node_modules/
```

Output : si trouve → `[FAIL] file:line — pattern` puis `exit 1`. Sinon → `[OK] no legacy imports`. exit 0.

### 2. `scripts/lint-fk-legacy-routes.sh`

Bash POSIX. Liste les routes legacy **encore présentes** dans `routes/api.php` (`frontend/item-category`, `frontend/item`, `frontend/item/kiosk-upsell`). Sortie informative (pas exit 1) — c'est un **monitor**, pas un bloqueur (suppression viendra dans M-11).

```
[INFO] Legacy frontend routes still present:
  routes/api.php:NN — frontend/item-category
  routes/api.php:NN — frontend/item
```

exit 0 toujours, sauf échec d'exécution.

### 3. `scripts/scan-bundle-legacy.sh`

Bash POSIX. Si `public/build/` n'existe pas → `[SKIP] no build present` exit 0. Sinon :
- `grep -l "kiosk_implementation\|borne \(Remix\)\|pos-wizard" public/build/*.js public/build/**/*.js 2>/dev/null`
- exit 1 si match.

### 4. `scripts/lint-fk-archive-banner.sh`

Vérifie présence du banner `ARCHIVE_BANNER.md` dans `kiosk_implementation/` et `borne (Remix)/`. exit 1 si manquant.

### 5. `kiosk_implementation/ARCHIVE_BANNER.md`

```markdown
# ARCHIVE — kiosk_implementation/

**Statut** : LEGACY ARCHIVE — non runtime depuis 2026-04-25.

Ce dossier contient une ancienne implémentation kiosk (Flutter/Dart) **conservée pour référence historique uniquement**. Il n'est **pas** importé par le runtime web (Laravel + Vue) sous `resources/js/`.

**Interdictions** :
- Toute modification doit passer par un gate humain explicite (`docs/gates/GATE_LEGACY_KIOSK_IMPL_*.md`).
- Aucun import JS/TS depuis `resources/js/` vers ce dossier.
- Aucun chunk JavaScript ne doit contenir `kiosk_implementation` après build.

**Lints associés** : `scripts/lint-fk-legacy-imports.sh`, `scripts/scan-bundle-legacy.sh`.

**Référence** : `docs/orchestration/LEGACY_QUARANTINE_2026-04-25.md`.
```

### 6. `borne (Remix)/ARCHIVE_BANNER.md`

Identique mais pour Remix.

### 7. `.github/workflows/legacy-guards.yml`

```yaml
name: legacy-guards
on:
  pull_request:
    paths:
      - 'resources/js/**'
      - 'routes/**'
      - 'public/build/**'
      - 'kiosk_implementation/**'
      - 'borne (Remix)/**'
  push:
    branches: [main]
jobs:
  legacy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Banner check
        run: bash scripts/lint-fk-archive-banner.sh
      - name: Imports check
        run: bash scripts/lint-fk-legacy-imports.sh
      - name: Routes monitor
        run: bash scripts/lint-fk-legacy-routes.sh
      - name: Bundle scan
        run: bash scripts/scan-bundle-legacy.sh
```

### 8. `docs/orchestration/LEGACY_QUARANTINE_2026-04-25.md`

Rédige : périmètre legacy, chemins ciblés, lints, exception process (gate humain), date, owner.

## RÈGLES

- macOS-compatible : `grep -E` (POSIX), pas `grep -P`. Si `rg` détecté, l'utiliser.
- Pas de `set -e` dans les lints qui doivent continuer après findings (utiliser flag manuel).
- Yaml workflow : indentation 2 espaces, validé.

## INTERDITS

- Toucher au code Dart/Flutter de `kiosk_implementation/` (sauf création du `.md` banner).
- Toucher au code Remix de `borne (Remix)/` (sauf banner).
- Modifier `routes/api.php` (le lint des routes legacy est uniquement un monitor ce cycle ; suppression = M-11).
- Modifier `eslint.config.*` ou `phpcs.xml` (pas dans allowlist — autre cycle).

## SI BLOCAGE

- Si `public/build/` n'existe pas → script `scan-bundle-legacy.sh` skip propre.
- Si lint trouve findings actuels → documenter dans `notes` du JSON (`pré-existants, à fixer dans M-XX`), `risks` ne doit pas être levé pour ça.
