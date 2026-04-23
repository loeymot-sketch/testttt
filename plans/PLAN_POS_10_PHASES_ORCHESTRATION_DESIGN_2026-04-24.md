# PLAN — Caisse POS : 10 phases d’orchestration (design + F/E + B/E)

**TASK_ID** : `PLAN_POS_10_PHASES_2026-04-24`  
**Auteur** : orchestration Cursor (Claude) — aligné sur `plans/PLAN_FINALISATION_POS_BASE_2026-04-20.md` (T01–T22) et `AGENTS.md`  
**Date** : 2026-04-24  
**Statut (auto-validation orchestrateur)** : **PRÊT** — le découpage couvre l’existant T01–T22 sans inventer de scope produit ; les gates humains (ex. T09 remises / G14-B) restent rappelés par phase.  
**Skills** : `.agents/skills/frontend-design/SKILL.md` (sens esthétique) + `.cursor/skills/foodking-vue-best-practices/SKILL.md` (invariants).

---

## PRIOR_CONTEXT (synthèse — à coller telle quelle en tête d’`EXECUTE`)

- **Mémoire** : lire ciblé `memory/episodes/07_pos_features.jsonl` + dernières lignes `12_decisions_log.jsonl` ; côté Graphiti, **une** requête `search_memory_facts` thème *POS / OrderService / NF525* (groupe `foodking`), pas de dump de roman.
- **Docs** : `docs/CORE_MODULES.md` (A actif, B gelé) ; `docs/DEVICE_FLOW.md` (POS) ; `docs/ORDER_FLOW.md` si parcours commande ; pricing **jamais** côté front.
- **Frozen** : `OrderService`, `FrontendOrderService`, `PaymentService`, `app/Services/Pricing/*` — toute modif = gate explicite ; lire `project-invariants.mdc` (ne pas citer l’ancien nom `foodking-invariants`).

---

## Direction design (application de la skill « frontend-design » au POS)

L’exigence « mémorable + non générique » **ici** = **clarté de service haute cadence** (ce n’est pas une landing marketing) :

| Principe skill | Traduction caisse |
|----------------|--------------------|
| Ton choisi | **Industriel / pro utilitaire** : hiérarchie de prix **lisible** en lumière du jour, contrastes élevés, zéro « gradient violet sur blanc ». |
| Typo | S’aligner sur la stack admin existante (Mix/Vue) ; un **seul** caractère display pour montants si cohérent thème, sinon **pas** de polices exotiques qui cassent l’i18n. |
| Couleur | Tokens du thème admin (variables CSS existantes) ; **états** (hold, 86, erreur paiement) = couleurs sémantiques stables, pas de palettes décoratives. |
| Motion | **Micro** : feedback tap, toasts, transitions courtes sur panier ; pas d’animations longues (service en rush). |
| Détails | Bordures nettes, **cibles tactiles** ≥ 44px pour actions critiques, reçu **NF525** : lisibilité avant fioritures. |

Chaque phase ci-dessous inclut un **Livrable design** : ce n’est pas « refonte cosmétique », c’est **cohérence** + **différenciation utile** (vitesse, erreur, accessibilité).

---

## Abonnement Claude (terminal) + optimisation tokens

| Quand | Commande / usage |
|-------|------------------|
| Après chaque lot JSONL / avant audit | `bash scripts/after-execute-memory.sh` puis `bash scripts/foodking-claude-orchestrate.sh post-execute` (fichier bref, **pas** 50 pages de chat). |
| Audit d’**une** phase (avis « +4 ») | `context` → `audit-brief` (lit `_TERMINAL_CONTEXT_BRIEF.md` d’abord) ; consignes = **1 sujet** / appel. |
| Jamais | Coller tout `PosComponent.vue` + tout `OrderService` dans le terminal ; l’agent **lit** le repo. |
| Graphiti | Même idée : **recherche ciblée** (faits) avant rédaction ; `memory/INDEX.md` en secours. |

---

## Cartographie 10 phases ↔ tâches V14 (Txx)

| Phase | Tâches périphériques (référence) | Complexité |
|------|-----------------------------------|------------|
| 1 | T01, T05, T06, T07, T20 | Surtout B/E + gate pricing |
| 2 | T02, T03, T04, T11 | F/E + B/E, parité Kiosk |
| 3 | T17 (+ C9), **rappel AUTHZ** / T09 (remises) si dans le même lot | B/E + F/E critique + **gate** |
| 4 | T15, T16, T21 | Matériel + reçu |
| 5 | T08 | F/E + state panier |
| 6 | T10, T12 | F/E perf / recherche |
| 7 | T13, T14 | F/E KDS — **avant** floorplan (avis terminal, flux commande) |
| 8 | T19 | F/E + B/E plan de salle |
| 9 | T18 | A11y + cohérence visuelle |
| 10 | T22 + relecture globale | E2E + « pimper » contrôlé |

### Avis terminal Claude Code (2026-04-24) — relecture `audit` ciblée

1. **Risque** : Phase 3 touche `PaymentService` (frozen) — exiger **gate / revue** explicite dans le `RUN_*` avant merge.  
2. **Ordre** : KDS (ex-phase 8) **avant** floorplan (ex-phase 7) — stabiliser le flux cuisine ↔ commande avant l’occupation des tables.  
3. **Trou** : rôles opérateur / remises (T09) : raccrocher `docs/AUTHZ_MATRIX.md` à la **Phase 3** (ou gate dédié G14-B).  
4. **Frozen** : Phase 1 touche `Pricing` / `OrderItem` — nommer le gate (ex. **G14-B** ou humain) dans le rapport d’exécution.  
5. **Amélioration** : ajouter sur chaque phase un champ **`GATE:`** une ligne (fait ci-dessous).

---

## Phase 1 — Données & moteur prix (fondation)

| | |
|---|--|
| **GATE** | **Humain / G14-B** si schéma ou règle métier ambiguë ; toute modif `app/Services/Pricing/*` = gate explicite. |
| **Objectif** | Le serveur sait modéliser **multi-qty** / variations, **prix SSOT** étendu, **snapshot** immuable par ligne, normalisation des types. |
| **Back** | Migrations / modèles / `Pricing` / `OrderItem` snapshot — selon T01, T05, T06, T07, T20. |
| **Front** | Préparer **seulement** affichage des champs reçus (pas de logique de prix) ; feature flags locaux si besoin. |
| **Tests** | PHPUnit sentinels pricing + composition ; tests existants V14. |
| **Délégation** | `foodking-complex-implementer` + revue stricte frozen ; Composer pour tâches mécaniques. |
| **Livrable design** | Aucun « look » ; validation **JSON** et **prix** affichés = copie de l’API. |
| **Claude terminal** | `audit` court sur **cohérence invariants** après implémentation (un seul prompt). |

## Phase 2 — Saisie panier & parité Kiosk (cœur opérateur)

| | |
|---|--|
| **GATE** | Aucun sauf extension schéma — sinon aligner sur Phase 1. |
| **Objectif** | UI multi-viande / compteurs, alignement Kiosk, garde 86. |
| **Front** | `ItemComponent`, `PosComponent` flux articles ; toasts & blocage live (T11). |
| **Back** | Form requests / contrôle `min_max` côté API. |
| **Tests** | Vitest panier + scénarios parité (T03). |
| **Délégation** | Complexe pour UX parité ; routine pour toasts/ i18n. |
| **Livrable design** | Grille **dense mais scannable** ; compteurs grands, état indispo **sans** ambiguïté couleur. |
| **Claude terminal** | `audit-brief` sur **seulement** la liste : risques de drift POS↔Kiosk. |

## Phase 3 — Paiement & résilience

| | |
|---|--|
| **GATE** | **C9** (dispatch-after-commit) + **revue humaine** pour toute modif `PaymentService` / jobs ; **G14-B** / `docs/AUTHZ_MATRIX.md` si T09 (remise / void) dans le même lot. |
| **Objectif** | Decline, timeout, idempotence **visible** opérateur (T17) ; respect dispatch-after-commit. |
| **Front** | `PaymentComponent` états, retry, messages non techniques. |
| **Back** | `PaymentService` / jobs — **frozen** : pas de diff sans gate. |
| **AuthZ** | Quand T09 actif : qui peut remiser / void — **aligner** la matrice documentée. |
| **Délégation** | `foodking-complex-implementer` obligatoire si toucher frozen. |
| **Livrable design** | États d’erreur : **1 action primaire** (réessayer / autre moyen), pas de murs de texte. |
| **Graphiti** | Ligne `12_` sur décision si choix d’échec recovery. |

## Phase 4 — Reçu, TPE, tiroir, code-barres matériel

| | |
|---|--|
| **GATE** | Reçu NF525 : **pas** de refonte légale sans relecture ; `PosReceiptPrintController` = sensibilité (voir frozen). |
| **Objectif** | ESC/POS, tiroir, lecture code-barres/NFC, reçu TVA (T15, T16, T21). |
| **Front** | `ReceiptComponent` / duplicata ; print flows. |
| **Back** | Impression / endpoints reçu, chaîne NF525 déjà en place. |
| **Livrable design** | 80/58mm : **type monospace** tabulaire pour colonnes, pas de “magazine layout” sur ticket légal. |
| **Claude terminal** | Audit sur **séparabilité** réception vs UI (1 prompt). |

## Phase 5 — Park / hold / recall

| | |
|---|--|
| **GATE** | Isolation `branch_id` : tests cross-branche **obligatoires** (sentinels existants). |
| **Objectif** | Paniers côté serveur, rappel sans surprise (T08). |
| **Front** | `ParkedOrdersComponent`, flux rappel. |
| **Back** | Endpoints branch-scoped, pas de fuite inter-branches. |
| **Livrable design** | Liste parked **triée** (horaire) ; action recall **1 tap** si sûr. |
| **Tests** | Feature déjà couverts par sentinels cross-branche. |

## Phase 6 — Recherche, raccourcis, performance perçue

| | |
|---|--|
| **GATE** | Aucun (front safe). |
| **Objectif** | Barre recherche, barcode, debounce, skeleton (T10, T12). |
| **Front** | Champs de recherche, clavier, `SkeletonGrid` usage cohérent. |
| **Livrable design** | Délai **&lt; 100 ms ressenti** = spinners discrets, pas d’images placeholder criards. |
| **Délégation** | Souvent `foodking-routine-implementer`. |

## Phase 7 — KDS (cuisine) — stabilisation flux commande

| | |
|---|--|
| **GATE** | Aucun produit lourd côté domaine **OrderService** — UI KDS en priorité. |
| **Objectif** | Station, son, bump, recall (T13, T14) ; le flux **cuisine** doit être stable **avant** d’agencer le plan de salle (recommandation audit terminal). |
| **Front** | Vues KDS ; pas mélange logique caisse dans le même commit que la caisse. |
| **Livrable design** | Gros chiffres **commande** + compte à rebours discret, pas d’art décoratif. |

## Phase 8 — Plan de salle (floorplan)

| | |
|---|--|
| **GATE** | Transfert table : vérifier locks / `branch_id` (C-β déjà hardené) — **non-régression** explicite dans `RUN_*`. |
| **Objectif** | Tables, assignation, transfer (T19). |
| **Front** | `FloorplanComponent` ; états de table. |
| **Back** | Lock / occupy — vérifier non-régression. |
| **Livrable design** | Couleurs d’**occupation** standard (libre / occupé / bientôt) + légende compacte. |

## Phase 9 — A11y, contraste, harmonisation de fin de vague

| | |
|---|--|
| **GATE** | Aucun — surface safe. |
| **Objectif** | WCAG ciblées opérateur (T18) + relecture `frontend-design` vs thème. |
| **Livrable design** | Même palette ; focus visible ; pas de `outline: none` sauf remplacement. |
| **Délégation** | Routine. |

## Phase 10 — E2E « tacos 4 viandes » + audit UI global (pimper)

| | |
|---|--|
| **GATE** | E2E opt-in (label) — rappel `workflows/qa-loop.md` ; pas de changement de prod sans review. |
| **Objectif** | Playwright parcours bout-en-bout (T22) + passes correctives visuelles **ciblées** (pas re-sculpter toute l’UI). |
| **Front** | Ajustements mineurs constatés en E2E. |
| **Livrable design** | Checklist : contrastes, tailles, cohérence 8 composants max touchés par l’audit. |
| **Claude terminal** | `audit-brief` **une fois** : liste priorisée de 5 seulement améliorations UI restantes. |

---

## Rôles sub-agents (rappel)

- **Routage** : `.cursor/routing.md` + `docs/orchestration/ROUTING_MATRIX.md`  
- **Rapport d’exécution** : chaque phase qui touche le code a un `reports/execution/RUN_*` avec ligne **`EXECUTE_DELEGATION:`** conforme `run-cycle.md`.

---

## Prochaine action (ordre utilisateur)

1. **Valider** ce plan (toi : OK / ajuster) — l’orchestrateur le considère **déjà** cohérent techniquement.  
2. **Ouvrir** `PLAN_…_PLAN_…` comme SSOT de phase pour **Phase 1** via `.cursor/commands/run-cycle.md` ou tâche dédiée.  
3. **Après chaque phase** : `after-execute-memory.sh` + ingest JSONL + option `context` + `audit-brief` ciblé.

**EXECUTE_DELEGATION (ce document)** : rédaction plan — `foodking-planner-orchestrator` / orchestrateur parent Cursor (pas d’implem code dans ce fichier).

---

*Fin — 10 phases, 1 feuille de route design+bilingue+terminal.*
