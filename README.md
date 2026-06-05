# FoodKing SaaS

Monolithe **Laravel 9** + **SPA Vue 3** (admin, caisse POS, KDS, écran client OSS, **kiosk web**). Base **MySQL**. Auth **Sanctum** + **Spatie Permission**. Temps réel : **Laravel Broadcasting** (Pusher / **Soketi**) sur canaux privés par branche ; **FCM** pour notifications push ; polling de secours sur certaines vues.

---

## Nouvelle session — lire d’abord

> **Modèle d’orchestration : cloud-as-supervisor (depuis 2026-06-05).** Claude Code sur le web est le
> **superviseur ET l’exécutant** unique ; Cursor/Cowork sont retirés. Point d'entrée d'une nouvelle
> session : **[`tasks/orchestration/FIRST_PROMPT_CLAUDE_CODE.md`](tasks/orchestration/FIRST_PROMPT_CLAUDE_CODE.md)**
> puis **[`tasks/orchestration/CLAUDE_CODE_BOOTSTRAP.md`](tasks/orchestration/CLAUDE_CODE_BOOTSTRAP.md)**.
> Rôles détaillés : **[`docs/orchestration/AGENT_ROLES.md`](docs/orchestration/AGENT_ROLES.md)**.
> Les guides « passation Cursor » ci-dessous restent une référence historique.

Sans l’historique du chat précédent, le dépôt est conçu pour rester compréhensible via la doc et les rapports.

### Passation express (5 min) — référence historique (Cursor)

1. **[`docs/HANDOFF_NEW_CURSOR/00_INDEX.md`](docs/HANDOFF_NEW_CURSOR/00_INDEX.md)** — table des matières de la passation.  
2. **[`docs/HANDOFF_NEW_CURSOR/PROMPT_DEMARRAGE_NOUVEAU_COMPTE.md`](docs/HANDOFF_NEW_CURSOR/PROMPT_DEMARRAGE_NOUVEAU_COMPTE.md)** — **prompt prêt à coller** dans le premier chat (nouveau compte Cursor).  
3. **[`docs/HANDOFF_NEW_CURSOR/CACHE_MEMOIRE_TRANSFERT.md`](docs/HANDOFF_NEW_CURSOR/CACHE_MEMOIRE_TRANSFERT.md)** — **cache mémoire** du projet (état, backlog, synchro, invariants).  
4. **[`docs/HANDOFF_NEW_CURSOR/01_DEMARRAGE_5_MINUTES.md`](docs/HANDOFF_NEW_CURSOR/01_DEMARRAGE_5_MINUTES.md)** — checklist `.env`, comptes test, commandes.  
5. **[`docs/PROJECT_CONTINUITY_AND_VISION.md`](docs/PROJECT_CONTINUITY_AND_VISION.md)** — vision produit (Le Cayenne), architecture, correctifs à ne pas régresser, backlog.  
6. **[`AGENTS.md`](AGENTS.md)** — workflow multi-agents (planning → implémentation → tests → review).

### Skill Cursor (optionnel — changement de compte)

- Fichier : **[`.cursor/skills/foodking-handoff/SKILL.md`](.cursor/skills/foodking-handoff/SKILL.md)** (versionné avec le repo).  
- **Export manuel prêt à l’emploi** : dossier **[`cursor-export-new-account/`](cursor-export-new-account/README.md)** (skill + fichier Rules à coller dans Cursor).  
- Copie **globale** sur la machine : placer le dossier `foodking-handoff` dans `~/.cursor/skills/` pour l’avoir sur **tous** les projets de ce compte.  
- Invocation : *« Applique le skill foodking-handoff »* ou *« Session FoodKing handoff »*.  
- Orchestration fichiers / checklist : **[`docs/HANDOFF_NEW_CURSOR/ORCHESTRATION_FICHIERS_A_TRANSFERER.md`](docs/HANDOFF_NEW_CURSOR/ORCHESTRATION_FICHIERS_A_TRANSFERER.md)**.

### Guides de passation détaillés (`docs/HANDOFF_NEW_CURSOR/`)

| Fichier | Description |
|---------|-------------|
| [`00_INDEX.md`](docs/HANDOFF_NEW_CURSOR/00_INDEX.md) | Index et ordre de lecture |
| [`01_DEMARRAGE_5_MINUTES.md`](docs/HANDOFF_NEW_CURSOR/01_DEMARRAGE_5_MINUTES.md) | Démarrage rapide |
| [`02_ARCHITECTURE_MONOLITHE.md`](docs/HANDOFF_NEW_CURSOR/02_ARCHITECTURE_MONOLITHE.md) | Couches, surfaces, services cœur |
| [`03_SYNCHRONISATION_TEMPS_REEL.md`](docs/HANDOFF_NEW_CURSOR/03_SYNCHRONISATION_TEMPS_REEL.md) | Echo, événements, FCM, polling, pièges config |
| [`04_FICHIERS_PIVOTS_PAR_FLUX.md`](docs/HANDOFF_NEW_CURSOR/04_FICHIERS_PIVOTS_PAR_FLUX.md) | Où modifier le code (kiosk, POS, KDS, auth) |
| [`05_TESTS_ET_SCRIPTS.md`](docs/HANDOFF_NEW_CURSOR/05_TESTS_ET_SCRIPTS.md) | PHPUnit par lots, Vitest, build |
| [`06_MULTI_AGENT_AGENTS_MD.md`](docs/HANDOFF_NEW_CURSOR/06_MULTI_AGENT_AGENTS_MD.md) | Rappel rôles Claude / Kimi / Anti-Gravity |
| [`07_RAPPORTS_PLANS_AUDITS.md`](docs/HANDOFF_NEW_CURSOR/07_RAPPORTS_PLANS_AUDITS.md) | Liens vers `reports/planning`, `reports/review`, etc. |
| [`08_BACKLOG_SYNTHESE.md`](docs/HANDOFF_NEW_CURSOR/08_BACKLOG_SYNTHESE.md) | Backlog priorisé condensé |

### Documentation métier & technique (`docs/`)

| Document | Rôle |
|----------|------|
| [`PROJECT_CONTINUITY_AND_VISION.md`](docs/PROJECT_CONTINUITY_AND_VISION.md) | **Source de vérité** contexte produit + état projet |
| [`ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Architecture, zones gelées |
| [`ORDER_FLOW.md`](docs/ORDER_FLOW.md) | Cycle de vie commande |
| [`DEVICE_FLOW.md`](docs/DEVICE_FLOW.md) | Cartographie par appareil (vérifier cohérence avec Echo/FCM) |
| [`API_MAP.md`](docs/API_MAP.md) | Cartographie API |
| [`AUTHZ_MATRIX.md`](docs/AUTHZ_MATRIX.md) | Autorisations |
| [`BUSINESS_RULES.md`](docs/BUSINESS_RULES.md) | Règles métier |
| [`SECURITY_NOTES.md`](docs/SECURITY_NOTES.md) | Sécurité |
| [`TEST_PLAN.md`](docs/TEST_PLAN.md) | Stratégie de tests + exécution par lots PHP |
| [`REALTIME_SETUP.md`](docs/REALTIME_SETUP.md) | Soketi / Echo / variables `.env` |
| [`LOCAL_TEST_ACCOUNTS.md`](docs/LOCAL_TEST_ACCOUNTS.md) | Comptes locaux Le Cayenne |
| [`AUDIT_LOGIN_ACCOUNTS.md`](docs/AUDIT_LOGIN_ACCOUNTS.md) | Pièges login / identifiants |

### Plans & audits récents (`reports/`)

| Document | Rôle |
|----------|------|
| [`reports/planning/latest.md`](reports/planning/latest.md) | Entrée planning courante |
| [`reports/planning/AUDIT_PROFOND_PLAN_MASSIF_2026-03-31.md`](reports/planning/AUDIT_PROFOND_PLAN_MASSIF_2026-03-31.md) | Audit large + phases A–E + diagrammes Mermaid |
| [`reports/review/AUDIT_SYNC_BROADCAST_ARCHITECTURE_2026-03-31.md`](reports/review/AUDIT_SYNC_BROADCAST_ARCHITECTURE_2026-03-31.md) | Synchro broadcast / événements |
| [`reports/execution/latest.md`](reports/execution/latest.md) | Dernière exécution de tests (si à jour) |
| [`reports/review/latest.md`](reports/review/latest.md) | Dernière revue / verdict |

### Règles Cursor

- **`.cursor/rules/project-continuity.mdc`** — rappelle lecture continuité + `AGENTS.md`.  
- **`.cursor/rules/global-operating-principles.md`** — principes généraux (import possible en User Rules Cursor).

---

## Stack technique (résumé)

- **Backend** : Laravel 9 (PHP 8.1+)
- **Frontend** : Vue 3 + Vuex + Vue Router ; build **Laravel Mix**
- **Base de données** : MySQL 8+ (SQLite possible pour tests)
- **Temps réel** : Broadcasting Laravel → Soketi/Pusher ; **Echo** côté SPA ; **FCM** en complément
- **Node** : 18+ pour `npm`

### Ce que ce dépôt contient

- **API REST** + **SPA admin** (POS, KDS, OSS, réglages).
- **Application kiosk borne en Vue** : `resources/js/components/frontend/kiosk/` (build chunk kiosk).

### Ce que ce dépôt ne contient pas toujours

- **Shell Electron** « borne Windows » : souvent dans un dossier séparé (ex. `borne-windows/`) hors racine Laravel — vérifier le workspace local.
- **Apps mobile Flutter / livreur** : peuvent vivre dans d’autres dépôts ou dossiers frères du projet.

---

## Installation rapide

1. **Prérequis** : PHP 8.1+, Composer 2, Node 18, MySQL.
2. **Dépendances** :
   ```bash
   composer install
   npm install
   ```
3. **Configuration** : copier `.env.example` → `.env`, configurer la BDD, `php artisan key:generate`. Voir aussi **`MIX_API_KEY`** / clé API dans `config/app.php`.
4. **Base de données** :
   ```bash
   php artisan migrate --seed
   ```
5. **Assets** :
   ```bash
   npm run dev
   # ou
   npm run production
   ```

## Tests (mémoire PHP)

La suite Feature complète peut saturer la mémoire ; utiliser les lots :

```bash
php -d memory_limit=512M scripts/run_php_feature_batches.sh auth-security
npm test
```

Détails : [`docs/HANDOFF_NEW_CURSOR/05_TESTS_ET_SCRIPTS.md`](docs/HANDOFF_NEW_CURSOR/05_TESTS_ET_SCRIPTS.md), [`scripts/README.md`](scripts/README.md).

## Workflow développement assisté par IA

1. Lire **[`AGENTS.md`](AGENTS.md)** et la passation **[`docs/HANDOFF_NEW_CURSOR/`](docs/HANDOFF_NEW_CURSOR/)**.  
2. Pour un changement important : plan dans **`reports/planning/`** avec type de test (Kimi-test / Anti-Gravity / No-test).  
3. Après implémentation : **`reports/execution/latest.md`** avec résultats des tests.  
4. Review : **`reports/review/latest.md`**.

### Instructions projet pour les agents
Le fichier **`AGENTS.md`** à la racine décrit la boucle QA / planning / exécution et les responsabilités par rôle (Claude, Kimi, Playwright / E2E verification). Les rapports vivent sous **`reports/`** et les workflows sous **`workflows/`**.

Formats : **`workflows/report-format.md`**, **`workflows/task-routing.md`**.

---

*README mis à jour pour servir de hub unique : installation, passation nouvelle session, architecture, liens vers tous les guides et rapports.*
