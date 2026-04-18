# Tests & validation

## 1. Documentation officielle

- [`../TEST_PLAN.md`](../TEST_PLAN.md) — stratégie générale + **validation par lots PHP** (post-stabilisation).
- [`../MASSIVE_TEST_PLAN.md`](../MASSIVE_TEST_PLAN.md) — inventaire étendu si présent.

## 2. PHPUnit (Feature / Unit)

- Dossiers : `tests/Feature/`, `tests/Unit/`.
- **Problème connu** : `php artisan test` sur **toute** la suite peut saturer la mémoire.
- **Mitigation** : scripts à la racine du projet :
  - `scripts/run_php_feature_batches.sh` — exécuter par lot (ex. `auth-security`, `kiosk-pos-sync`, …).
  - `scripts/profile_php_memory.sh` — profilage optionnel.
- Détail : [`../TEST_PLAN.md`](../TEST_PLAN.md) et [`../../scripts/README.md`](../../scripts/README.md).

Exemple :

```bash
cd /chemin/vers/projet
php -d memory_limit=512M scripts/run_php_feature_batches.sh auth-security
```

## 3. Frontend (Vitest)

- Specs : `tests/js/*.spec.js` (kiosk helpers, wizard, offline queue, etc.).
- Commande : `npm test` (vérifier `package.json` pour le runner exact).

## 4. Build production assets

```bash
npm run production
```

## 5. E2E / navigateur réel

- Non couvert par la suite automatisée standard du repo de manière exhaustive.
- Workflow : **Anti-Gravity** ou QA manuel — voir `reports/antigravity/latest.md` et `AGENTS.md`.

## 6. Rapports d’exécution

- Derniers résultats souvent dans [`../../reports/execution/latest.md`](../../reports/execution/latest.md).
