# AI Change Gates (Verrous de Sécurité pour les Agents)

Ce document est une checklist de sécurité. Avant chaque changement d'un Agent IA (Claude ou Kimi) sur la codebase, la validation implicite de ces points est **obligatoire**.

### 1. Prise d'information
- [ ] Les _Docs_ pertinentes (ARCHITECTURE, AUTHZ_MATRIX, API_MAP) ont-elles été lues ?
- [ ] Le workflow actuel respecte-t-il `AGENTS.md` et `workflows/qa-loop.md` ?

### 2. Rapport et Planification
- [ ] Un rapport Anti-Gravity récent (`reports/antigravity/`) atteste-t-il du problème ?
- [ ] Un Plan de résolution validé par Claude (`reports/planning/`) encadre-t-il la tâche actuelle ?
- [ ] La tâche est-elle bien routée selon `workflows/task-routing.md` (Kimi pour UI/CRUD léger, Claude pour Architecture) ?

### 3. Exécution et Qualité
- [ ] Le _scope_ de la modification est-il petit et bien contenu ?
- [ ] Les tests (ex: `tests/Feature/*`) concernés par ce module (Pricing, KDS, Auth) ont-ils été identifiés pour éviter la régression ?

### 4. Boucle Finale
- [ ] Une _Review_ par Claude est-elle prévue avant le signalement de fin pour générer le bilan d'exécution ?
- [ ] Un _Retest_ par Anti-Gravity est-il prévu dans la boucle ?

> Note pour l'IA : Si vous vous apprêtez à faire une modification de code métier sans avoir de rapport de test initial ou de plan de travail, **ARRÊTEZ-VOUS** et effectuez l'étape de génération de rapport ou demandez à l'Orchestrateur (Cursor) de vous fournir un plan.
