# V1 Pivot — procédure smoketest staging

Procédure humaine pour valider la préparation **Pivot V1** sur un poste ou un environnement staging avant cutover. Le script automatisé : `scripts/v1-pivot-staging-smoketest.sh`.

## Pré-requis poste / staging

- Application Laravel staging accessible sur `http://localhost:8000` (ou une autre URL — définir `BASE_URL` avant le script).
- Base de données : copie staging de la prod **anonymisée** si possible, alignée avec les migrations du dépôt.
- Fichier d’environnement : `.env.staging` ou `.env` avec notamment `FEATURE_WIZARD_PER_ITEM_DEMO=false` par défaut (comportement prod / Demo V2 OFF).
- Outils : PHP (CLI), Node.js, `npm`, `npx`.
- Playwright (pour l’étape 10 du script complet) : dépendances npm installées et navigateur Chromium : `npx playwright install chromium`.

## Procédure (5 étapes)

1. **Mettre à jour le code** : tirer la dernière branche `main` (ou la branche de release validée).
2. **Dry-run** : exécuter  
   `bash scripts/v1-pivot-staging-smoketest.sh --dry-run`  
   pour le pré-vol binaire / fichiers d’env et un `migrate --pretend` + `migrate:status` **sans** mutation de schéma après l’arrêt contrôlé du script.
3. **Run complet** : si le dry-run est OK, lancer  
   `bash scripts/v1-pivot-staging-smoketest.sh`  
   (optionnel : `--skip-playwright` si pas de Chromium / pas de serveur — l’étape E2E sera ignorée avec avertissement).
4. **Lire le rapport** : consulter le fichier généré sous `reports/execution/SMOKETEST_V1_*.log` (horodatage dans le nom).
5. **Smoketest manuel (~10 min)** :
   - Se connecter à l’admin.
   - Ouvrir `/admin/items/studio` → choisir une catégorie → « Wizard de la catégorie » → vérifier l’ouverture du drawer.
   - Ouvrir `/admin/ingredients` → mettre un extra en rupture → vérifier le badge « En rupture ».
   - Ouvrir le kiosk dans un autre onglet → vérifier que l’extra apparaît en rouge / non sélectionnable.
   - **Drill-down ingrédient (V1.5b livré 2026-05-04)** : sur `/admin/ingredients`, cliquer sur un ingrédient référencé par **2+ wizards** → vérifier que le drawer affiche la liste des produits/catégories qui l'utilisent, dans l'ordre **catégorie puis produit** (alpha sur `owner_name`), avec liens `<a>` cliquables qui pointent vers les bonnes URLs admin (`/admin/categories/{id}` ou `/admin/items/{id}`).
   - Accéder directement à `/admin/items/1/composer` → avec Demo V2 désactivé, doit rediriger vers `/admin/items/studio`.

## En cas d’échec

| Étape script | Action suggérée |
|--------------|------------------|
| 4 (rollback + re-migrate) | Restaurer un backup BDD si l’état migratoire est incohérent ; investiguer les derniers batches avant de relancer. |
| 6 (PHPUnit ciblé Ingredients / Wizard) | Consulter le log d’étape `/tmp/v1-smoketest-step-6.log`, ouvrir un ticket ou une mission corrective ciblée. |
| 10 (Playwright) | Vérifier `BASE_URL`, serveur Laravel actif, auth/cookies/session, et `E2E_BACKEND_AVAILABLE=1` si vous forcez l’E2E. |

## Critères de PASS

- Script : code de sortie **0** et toutes les étapes exécutées marquées OK dans le terminal et dans `SMOKETEST_V1_*.log` (les étapes Playwright peuvent être SKIP explicites si env non prêt).
- Manuel : les **5** vérifications UI ci-dessus sont OK (incl. drill-down ingrédient V1.5b).
- Traçabilité : au moins un rapport `reports/execution/SMOKETEST_V1_*.log` archivé pour l’audit.

## Variables utiles

- `BASE_URL` : URL racine pour le test `curl` et Playwright (défaut `http://localhost:8000`). Playwright lit aussi `PLAYWRIGHT_BASE_URL` via la config du dépôt ; le script aligne `PLAYWRIGHT_BASE_URL` sur `BASE_URL` pour l’étape 10.
- `E2E_BACKEND_AVAILABLE=1` : requis pour **exécuter** l’étape Playwright (sinon SKIP avec avertissement).
- `--skip-playwright` : skip total de l’étape 10 (ex. CI sans Chromium).
