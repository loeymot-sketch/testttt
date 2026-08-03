# Audit global massif — FoodKing (implémentations, qualité, perf) — 2026-04-24

**Objectif** : synthèse exécutable post-plan POS 10 phases + vérification large du dépôt, avec **preuves locales** (tests, scripts) et avis **Claude Code terminal** (2 passes ciblées). **Ce n’est pas** une certification de prod : les risques résiduels et l’E2E opt-in restent de la gouvernance humaine/CI.

---

## 1. Preuves automatisées (même session)

| Vérification | Résultat | Détail |
|-------------|----------|--------|
| `bash scripts/check-invariants.sh` | **PASS** (6/6) | Voir `POS_INVARIANTS_AND_GATES.md` §3 |
| `bash scripts/after-execute-memory.sh` | **OK** | Manifeste JSONL cohérent |
| `python3 memory/verify.py` | **PASS** | `count = 182` (Neo4j `foodking`), 17 requêtes `search_memory_facts` smoke ≥ 1 hit |
| PHPUnit (suite complète) | **PASS** | **866** tests, **8** `skipped` (environnement SQLite vs MySQL, CORS, machine login, etc. — attentes documentées) |
| Vitest (`npx vitest run`) | **PASS** | **720** tests, **93** fichiers (stderr attendus sur certains scénarios shallow / happy-dom) |
| Budget bundles (`npm run perf:bundle-check`) | **PASS** | `app.js` 4515 KB / 5000, `kiosk.js` 513 KB / 600, `pos-wizard.js` 280 KB (hors budget) |

---

## 2. Correctif appliqué (sans toucher les zones *frozen* sensibles)

**Fichier** : `app/Http/Requests/Admin/Pos/FloorplanTransferRequest.php`

- **Avant** : `authorize()` retournait `true` (défense en profondeur faible vs policy FormRequest).
- **Après** : `return $user !== null && $user->can('pos');` — aligné sur le middleware `permission:pos` de `FloorplanController`.
- **Vérification** : `./vendor/bin/phpunit tests/Feature/Pos/FloorplanControllerTest.php` — **11/11** OK.

*Note* : l’isolation `branch_id` des tables de transfert est **déjà** garantie par `DiningTableService::transfer` (`where('branch_id', $branchId)` + tests cross-branch) — l’audit terminal qui suggérait un P0 “sans branch” sur le seul `FormRequest` est **trompeur** si l’on ignore la couche service (défense en profondeur = Request + middleware + service).

---

## 3. Travail local non committé observé (bon alignement invariants)

**Fichier** : `app/Services/FrontendOrderService.php` (modifié dans l’arbre de travail)

- Passage de `event(new OrderStatusChanged(...))` à `OrderStatusChanged::dispatch(...)` avec cast `(int) $request->status`, + commentaire **DispatchableAfterCommit**.
- **Lecture** : c’est cohérent avec l’**invariant 4** (dispatch après commit) et ne constitue pas un contournement du *freeze* : c’est un durcissement de conformité. **Revue / commit** : à la charge de l’équipe (symétrie / gate habituel *FrontendOrderService*).

*Aucune autre modification* des services *frozen* (OrderService, PaymentService, matrice pricing) dans cette session d’audit.

---

## 4. Synthèse des audits terminal Claude (2 lancements)

### Pass A — invariants & architecture

- **Verdict annoncé** : `GAPS` (risques théoriques : coupons multi-branche, Z/sealed, `check-invariants` en CI, etc.).
- **Relecture critique** : plusieurs points sont des **dettes** ou sujets de **gouvernance** (p.ex. `CouponRequest`, preuve de gate `docs/gates/`), pas des régressions prouvées sur cette branche. Le point **floorplan** vs `branch_id` est **à nuancer** : le service + tests le couvrent (voir §2).

### Pass B — qualité / perf / CI

- **Verdict annoncé** : `GAPS` (E2E Playwright non prouvé en intégration, bundles monolithiques Mix, couverture % non forcée, dette d’outillage).
- **Alignement factuel** : l’E2E `tacos-4-viandes-cash-flow` a échoué sur **login** sans identifiants/seed alignés ; variables documentées dans `.env.example` (travail antérieur). `perf:bundle-check` montre des **plafonds** actifs, contrairement à l’affirmation stricte « pas de budget » (à formuler : pas de *couverture %* de code, mais budgets mix présents).

---

## 5. Verdict exécuteur (synthèse)

| Axe | Statut | Commentaire court |
|-----|--------|-------------------|
| **Tests logique (PHP/JS)** | **Vert** | 866 + 720 — socle solide |
| **Garde-fous POS scriptés** | **Vert** | 6 invariants |
| **Mémoire / Graphiti (gate W10)** | **Vert** (182) | Vérif MCP Neo4j OK |
| **Performance bundles (seuil outil)** | **Vert** | Sous plafond — **pas** d’optimisation de chunking dans ce lot |
| **E2E navigateur (T22)** | **Rouge / non prouvé** | Auth + données ; prérequis env/seed |
| **Gouvernance (gates, dettes, CI Playwright stricte)** | **Amber** | Décision produit / humain, hors scope d’un seul run agent |

**Formulation** : l’**implémentation cœur** est **bien couverte par les tests** et les **invariants** ; il **faudra encore** (en dehors de ce rapport) : **E2E optionnel/CI**, traitement des **GAPS** listés (coupons, fiscalité Z, a11y `announce()`, etc.) selon priorisation PO, et **revue** du diff `FrontendOrderService` avant merge.

---

## 6. Liste priorisée (hors code dans ce lot)

1. **P0 — E2E T22** : compte POS + `E2E_*` + re-run Playwright (ou job CI opt-in + secrets).
2. **P0/P1 — Coupons / tenant** : si l’invariant 3 s’applique aux remises, traiter `CouponRequest` (ou règle métier documentée).
3. **P1 — CI** : confirmer exécution stricte de `check-invariants` sur la branche principale ; intégrer Playwright en label.
4. **P1 — A11y** : câblage ciblé de `announce()` sur actions critiques (Phase 9, dette reconnue).
5. **P2 — Build** : stratégie long terme (split / Vite) si la taille de `app.js` devient un enjeu malgré le plafond actuel.

---

*Généré automatiquement après exécution des tests, scripts, 2 appels `foodking-claude-orchestrate.sh audit`, et 1 correctif ciblé `FloorplanTransferRequest`.*
