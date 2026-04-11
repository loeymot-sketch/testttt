# Dossier de decision — Graphify pour FoodKing

**Statut :** En attente de validation humaine. Aucune installation effectuee.
**Artefact :** `safishamsi/graphify` v2 (PyPI: `graphifyy`, 19K+ stars)
**Type :** Outil Python — knowledge graph de codebase via tree-sitter + Claude API
**Date :** 10 Avril 2026

---

## 1. Fiche integration-gate

```markdown
## Integration audit request

- **task_id:** DECISION-GRAPHIFY-001
- **artifact_name:** graphify (PyPI: graphifyy)
- **artifact_type:** pip_package
- **source_url / registry:** https://github.com/safishamsi/graphify (branche v2)
- **version or commit:** v2 (latest sur branche v2, PyPI: graphifyy)
- **purpose in this project:**
  Construire un graphe de dependances navigable du monolithe FoodKing
  (Laravel PHP + Vue 3 JS) pour alimenter les gates pre-implementation
  (architecture-drift-gate, data-contract-gate, sync-consistency-gate)
  en donnees structurees sur les relations entre modules.
- **transitive_risk_notes:**
  - Dependance Python (pas npm — hors du runtime Laravel/Vue)
  - Utilise tree-sitter (bindings natifs Rust/WASM) pour le parsing AST
  - Appelle l'API Claude (Anthropic) pour l'extraction semantique de
    docs/images — necessite une cle API Claude active
  - Stockage local uniquement (NetworkX + fichiers JSON/HTML)
  - Aucun postinstall script dangereux documente
  - Aucun acces aux secrets du projet (ne lit que les fichiers source)
  - Pas de reseau sortant sauf vers api.anthropic.com
- **vision_alignment_question:**
  Le graphe enrichit l'observabilite architecturale sans modifier
  le code, l'auth, le pricing, ni les flows de commande. Il ne
  remplace aucun composant du pipeline. Compatible avec
  docs/PROJECT_CONTINUITY_AND_VISION.md (outil d'analyse, pas de
  modification). Compatible avec docs/ARCHITECTURE.md (ne touche pas
  les zones gelees, ne modifie pas les dependances critiques).
- **security_question:**
  - Pas de CVE connue documentee (repo actif, 19K stars)
  - Le code source est ouvert et auditable
  - Envoie le contenu des fichiers source a l'API Anthropic
    pour extraction semantique — le code FoodKing transiterait
    par l'API Claude. A evaluer selon politique de confidentialite.
  - Aucun analytics, tracking ou telemetrie
  - tree-sitter est un parseur reconnu (Mozilla/GitHub)
- **license:** MIT (a confirmer sur le repo)
- **alternatives_considered:**
  - @optave/codegraph (npm, tree-sitter, SQLite) — plus leger mais
    moins de profondeur semantique, pas de clustering communautaire
  - dependency-graph-analyzer (npm) — cycles uniquement, pas de
    graph semantique
  - Analyse manuelle — non viable sur un monolithe de cette taille
  - phpstan/psalm + madge (JS) — analyse statique pure, pas de
    graphe relationnel cross-stack
```

### Checklist de compatibilite

- [x] **Vision :** Outil d'observabilite. Ne modifie pas le code.
      Aligne avec docs/PROJECT_CONTINUITY_AND_VISION.md.
- [x] **Architecture :** Hors du runtime. Ne touche pas Laravel,
      Vue, Sanctum, Spatie, Pusher. Aucun impact sur les zones gelees.
- [ ] **Securite :** Le contenu source est envoye a l'API Anthropic.
      A valider selon politique de confidentialite du client.
- [x] **Licence :** MIT — compatible.
- [x] **Maintenance :** Version pinnable. Pas de dependance au
      runtime de production.
- [x] **Reversibilite :** Suppression = `pip uninstall graphifyy` +
      `rm -rf graphify-out/`. Zero impact sur le repo.

### Verdict preliminaire

**SAFE WITH CONDITIONS** — sous reserve de :
1. Validation humaine de l'envoi de code source a l'API Anthropic
2. Execution dans un venv isole, jamais dans le conteneur de production
3. Sortie (`graphify-out/`) dans `.gitignore`, jamais committee
4. Utilisation en lecture seule — jamais en CI sans accord explicite

---

## 2. Plus petit POC isole possible

### Perimetre

Analyser **un seul sous-arbre** du monolithe FoodKing : le chemin
de commande POS (`OrderService` + ses dependances directes).

### Fichiers en entree (maximum 15)

```text
app/Services/OrderService.php
app/Services/FrontendOrderService.php
app/Services/CouponService.php
app/Services/KitchenDisplaySystemOrderService.php
app/Models/Order.php
app/Models/FrontendOrder.php
app/Models/OrderItem.php
app/Http/Controllers/Admin/PosOrderController.php
app/Http/Controllers/Frontend/OrderController.php
app/Http/Requests/PosOrderRequest.php
app/Rules/ValidJsonOrder.php
app/Events/OrderCreated.php
app/Events/OrderStatusChanged.php
app/Jobs/SendOrderGotPush.php
docs/ORDER_FLOW.md
```

### Commandes exactes

```bash
# 1. Environnement isole
python3 -m venv .graphify-venv
source .graphify-venv/bin/activate  # Linux/Mac
# .graphify-venv\Scripts\activate   # Windows

# 2. Installation
pip install graphifyy

# 3. Execution sur le sous-arbre uniquement
graphify ./app/Services/OrderService.php \
         ./app/Services/FrontendOrderService.php \
         ./app/Services/CouponService.php \
         ./app/Models/Order.php \
         ./app/Models/FrontendOrder.php \
         ./app/Models/OrderItem.php \
         ./app/Http/Controllers/Admin/PosOrderController.php \
         ./app/Http/Requests/PosOrderRequest.php \
         ./app/Rules/ValidJsonOrder.php \
         ./app/Events/OrderCreated.php \
         ./app/Events/OrderStatusChanged.php \
         ./docs/ORDER_FLOW.md

# 4. Resultats
ls graphify-out/
# graph.html   — visualisation interactive
# graph.json   — graphe queryable
# GRAPH_REPORT.md — rapport textuel
```

### Duree estimee

5 minutes d'installation, 2-5 minutes d'execution (15 fichiers,
tokens API Claude moderes).

### Cout estime

~5000 tokens Claude API pour l'extraction semantique de 15 fichiers.
Negligeable.

---

## 3. Criteres de succes

Le POC est un succes si et seulement si les 6 conditions suivantes
sont remplies :

| # | Critere | Verification |
|---|---------|-------------|
| S1 | Le graphe identifie correctement les 2 chemins de commande (`OrderService` → `Order` et `FrontendOrderService` → `FrontendOrder`) comme des clusters distincts | Inspecter `graph.html` ou `graph.json` |
| S2 | Le graphe montre la relation `PosOrderController` → `OrderService` → `Order` → `OrderItem` | Edge list dans `graph.json` |
| S3 | Le graphe detecte la dependance croisee entre `OrderService` et `CouponService` | Edge present |
| S4 | Le graphe identifie la chaine de sync : `OrderService` → `OrderCreated` → `SendOrderGotPush` | Edges events/jobs visibles |
| S5 | Le rapport (`GRAPH_REPORT.md`) mentionne `OrderService` comme noeud central (god node ou hub) | Texte du rapport |
| S6 | L'execution n'a modifie aucun fichier du repo FoodKing (lecture seule) | `git status` propre (hors `graphify-out/` et `.graphify-venv/`) |

### Bonus (non bloquant)

- Le graphe integre le contenu de `docs/ORDER_FLOW.md` et le
  relie aux entites code
- Les communautes detectees correspondent aux surfaces documentees
  (POS, KDS, Frontend)

---

## 4. Criteres d'echec

Le POC est un echec si l'une des conditions suivantes est remplie :

| # | Critere | Consequence |
|---|---------|-------------|
| E1 | Graphify ne parse pas PHP (tree-sitter-php absent ou defaillant) | Le POC est inutile pour FoodKing — **abandon** |
| E2 | Le graphe produit est un amas indifferencie (pas de clusters, pas de relations typees) | La valeur ajoutee par rapport a un `grep` est nulle — **abandon** |
| E3 | L'execution modifie des fichiers du repo (hors `graphify-out/`) | Violation de la regle lecture seule — **abandon + rollback** |
| E4 | L'extraction semantique envoie des secrets (`.env`, credentials) a l'API Anthropic | Violation securite — **abandon immediat + rollback + audit** |
| E5 | Le temps d'execution depasse 15 minutes pour 15 fichiers | Non viable pour un usage iteratif — **suspendu** |
| E6 | Le cout API depasse 50K tokens pour 15 fichiers | Non viable economiquement a l'echelle — **suspendu** |
| E7 | Les dependances Python entrent en conflit avec l'environnement de dev (meme sans venv) | Risque d'environnement — **suspendu, reessayer en conteneur** |

---

## 5. Ce que Graphify alimenterait dans la boucle

### Consommateurs directs

| Consommateur | Donnee fournie par Graphify | Usage |
|-------------|---------------------------|-------|
| **architecture-drift-gate** (Step 2a) | `blast_radius` calcule par les edges du graphe au lieu d'etre declare manuellement | Detection automatique des modules impactes par un changement |
| **data-contract-gate** (Step 2b) | Relations `Model → $fillable/casts → Controller → Request` | Detection des desynchronisations `Order` vs `FrontendOrder` |
| **sync-consistency-gate** (Step 4b) | Chaine `Service → Event → Job → Listener` extraite du graphe | Verification que la chaine de sync est complete apres modification |
| **Claude (architecte)** | `GRAPH_REPORT.md` comme contexte supplementaire dans le plan | Meilleure comprehension du blast_radius avant planification |
| **code-clarity-graph** | `blast_radius` pre-calcule pour les headers de fichiers | Coherence entre header et graphe reel |

### Mode d'integration

```text
Graphify ne tourne PAS dans le pipeline temps reel.

Execution : manuelle ou hook post-commit (optionnel futur)
Sortie    : graphify-out/graph.json (queryable)
Lecture   : les gates lisent graph.json comme reference statique
Frequence : apres un changement architectural significatif
            (nouveau service, nouveau model, refactor cross-module)
```

Le `graph.json` est un **cache d'observabilite**. Il est consulte,
jamais modifie par les gates. S'il est absent, les gates fonctionnent
comme avant (declaration manuelle du `blast_radius`).

---

## 6. Ce que Graphify ne doit JAMAIS remplacer

| Element protege | Pourquoi | Reference |
|----------------|----------|-----------|
| `docs/ARCHITECTURE.md` | Document de gouvernance humain. Le graphe illustre, il ne decide pas. | AGENTS.md "Source of truth" |
| `docs/PROJECT_CONTINUITY_AND_VISION.md` | Vision produit. Aucun outil ne remplace la direction humaine. | AGENTS.md, user rules |
| `docs/ORDER_FLOW.md` | Flux metier valide par le business. Le graphe montre du code, pas des regles. | BUSINESS_RULES.md |
| `docs/AUTHZ_MATRIX.md` | Matrice de securite. Les permissions sont un contrat humain. | SECURITY_NOTES.md |
| `vision-keeper` | Gate de priorite absolue. Le graphe ne peut pas overrider un blocage vision. | vision-keeper SKILL.md |
| `playwright-smoke-gate` | Preuve comportementale reelle. Un graphe statique ne remplace pas un test navigateur. | PLAYWRIGHT_MCP_OPS.md |
| Claude (architecte) | Decisions architecturales. Le graphe informe, Claude decide. | AGENTS.md "Agent role model" |
| Tests PHPUnit/Jest | Preuves d'execution. Un graphe de dependances ne prouve pas que le code fonctionne. | TEST_PLAN.md |
| Revue humaine | Le Human reste l'autorite finale. | AGENTS.md "Workflow autonomy" |

**Regle dure :** Si le graphe et un document de gouvernance sont en
desaccord, le document de gouvernance fait foi. Toujours.

---

## 7. Procedure de rollback propre

### Rollback complet (suppression totale)

```bash
# 1. Supprimer la sortie
rm -rf graphify-out/

# 2. Supprimer l'environnement Python
rm -rf .graphify-venv/

# 3. Verifier que le repo est propre
git status
# Si graphify-out/ ou .graphify-venv/ apparaissent : les ajouter a .gitignore
# puis git checkout -- . pour nettoyer

# 4. Supprimer les entrees .gitignore ajoutees pour Graphify (si presentes)
# Retirer les lignes :
#   graphify-out/
#   .graphify-venv/

# 5. Verification finale
git diff        # doit etre vide
git status      # doit etre propre
```

### Impact du rollback

| Element | Impact de la suppression |
|---------|------------------------|
| Code source FoodKing | **Zero** — Graphify ne modifie aucun fichier source |
| Pipeline cursor-executor-strict | **Zero** — les gates fonctionnent sans `graph.json` |
| Tests (PHPUnit, Playwright) | **Zero** — aucune dependance |
| `AGENTS.md`, docs de gouvernance | **Zero** — aucune modification |
| CI/CD | **Zero** — Graphify n'est pas dans le pipeline CI |
| `.env` / secrets | **Zero** — Graphify ne lit pas `.env` |

### Conditions de declenchement du rollback

1. Critere d'echec E3 ou E4 declenche → rollback immediat
2. Verdict integration-gate `BLOCKED` par le reviewer → rollback
3. Decision humaine a tout moment → rollback
4. Graphify abandonne le support PHP → rollback (outil inutile)

### Apres rollback

- Mettre a jour `logs/full-history.log` : noter la date, la raison,
  le verdict final
- Mettre a jour `status.json` si applicable
- Ne pas supprimer ce dossier de decision (`docs/DECISION_GRAPHIFY.md`)
  — il sert de trace pour les decisions futures

---

## Resume de decision

| Question | Reponse |
|----------|---------|
| Faut-il installer Graphify maintenant ? | **Non.** Ce document prepare la decision. |
| Verdict integration-gate preliminaire | **SAFE WITH CONDITIONS** |
| Conditions bloquantes | Validation envoi code source a Anthropic API |
| POC viable ? | Oui — 15 fichiers, 5 min, venv isole, rollback trivial |
| Valeur ajoutee ? | `blast_radius` automatique pour les gates, chaine de sync verifiable |
| Risque ? | Faible si venv isole + `.gitignore` + jamais en CI sans accord |
| Rollback ? | Trivial — `rm -rf` de 2 dossiers, zero impact repo |

### Prochaine etape

Le Human lit ce document et repond :

- **GO** → executer le POC (section 2), evaluer les criteres (sections 3-4)
- **MODIFY** → ajuster le perimetre ou les conditions
- **STOP** → archiver ce document, ne pas installer

---

*Dossier de decision. Aucune installation effectuee.
Aucun fichier du repo modifie par Graphify.*
