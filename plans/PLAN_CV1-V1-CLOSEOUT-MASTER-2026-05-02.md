# PLAN MASTER — CV1-V1-CLOSEOUT-001 — Clôture V1 fonctionnelle FoodKing — 2026-05-02

**TASK_ID :** `CV1-V1-CLOSEOUT-001`
**Author :** Claude in-session orchestrator
**Branche :** `cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27`
**Demande user (2026-05-02 21:34) :** « Fais un maximum d'orchestration et audit maximale pour avoir un plan et vision claire de tout ce qui je t'ai demandé et tout ce qui reste à faire puis attaque toute la base à la Maitre hyper intelligente et bien fait. »

---

## §0 — Hypothèses métier (assumées par défaut, modifiables par user)

Basé sur 100% du discours user dans le cycle 2026-04-25 → 2026-05-02 (« fast-food », « POS/Kiosk/KDS », jamais mention livraison/table/online) :

| Question | Hypothèse retenue | Conséquence |
|---|---|---|
| Livraison interne V1 ? | NON | `deliveryBoys` → suppression (gate DROP TABLE) |
| Service à table V1 ? | NON | `waiters`, `chefs`, `tableOrders`, `diningTable` → suppression (4 gates DROP TABLE) |
| Vente en ligne site web V1 ? | NON | `onlineOrders` → suppression (gate DROP TABLE) |

**Si une hypothèse est fausse, le user le signale et je révise instantanément avant exécution.**

---

## §1 — Inventaire de tout ce qui reste à faire (synthèse exhaustive)

### A. Gates humains PENDING (2)

| Gate | Brief | Origine |
|---|---|---|
| `GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK` | `docs/gates/GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK_2026-05-02.md` | M2 V2 task 2.2 |
| `GATE_SCHEMA_STOCK_MOVEMENT_IDEMPOTENCY_KEY_UNIQUE` | `docs/gates/GATE_SCHEMA_STOCK_MOVEMENT_IDEMPOTENCY_KEY_UNIQUE_2026-05-02.md` | M2 V2 task 2.6 |

### B. Tâches en attente / différées (1 + N)

| ID | Description | Tier | Statut |
|---|---|---|---|
| M2 V2 task 2.9 | Wizard admin guidé multi-step (création produit côté admin) | XL | DEFERRED — décision pending : forcer Codex au retour 22:21 ou différer post-V1 |

### C. Demandes user NEW (cette session)

| Demande | Type | Status |
|---|---|---|
| Cleanup dashboard fonctionnalités inutiles V1 | refactor structure | INVENTORIÉ — Axe 5 |
| Refonte runtime wizard POS vs Kiosk page-par-page (visuel borne Splash-level) | refactor UX/UI | À PLANIFIER — Axe 4 |
| Audit Claude global ultra-review V1 | audit indépendant | DEMANDE ÉCRITE — `reports/audit/CLAUDE_TERMINAL_ULTRA_REVIEW_REQUEST_V1_FUNCTIONAL_2026-05-02.md` |
| Suppression complexité globale + clarification système | refactor structure | INTÉGRÉ §3 ci-dessous |

---

## §2 — Méthodologie : 3 phases parallèles maximum

### Phase 1 — 5 audits PARALLÈLES (read-only, sub-agents `explore`)

Lancement simultané de 5 sub-agents `explore` en lecture seule, périmètre disjoint :

| Axe | Sub-agent | Périmètre | Sortie |
|---|---|---|---|
| 1 | explore | Centralisation catalogue | `reports/audit/CV1_AUDIT_AXE1_CATALOG_CENTRALIZATION_2026-05-02.md` |
| 2 | explore | Synchronisation 5 paths | `reports/audit/CV1_AUDIT_AXE2_DATA_SYNC_2026-05-02.md` |
| 3 | explore | Gérance admin 9 cas | `reports/audit/CV1_AUDIT_AXE3_ADMIN_ACTIONS_2026-05-02.md` |
| 4 | explore | Wizard POS vs Kiosk | `reports/audit/CV1_AUDIT_AXE4_WIZARD_DECOMPOSITION_2026-05-02.md` |
| 5 | explore | Cleanup inventory | `reports/audit/CV1_AUDIT_AXE5_CLEANUP_INVENTORY_2026-05-02.md` |

Avantage : **5 audits en temps quasi 1**. Pas de conflit (read-only). Tokens parallélisés.

### Phase 2 — Synthèse + master plan exécutable

Je consolide les 5 rapports en **un master plan exécutable** :
- Verdict V1 : GO / GO_WITH_CONSTRAINT / NO_GO
- Top 5 blockers P0
- Plan d'attaque ordonné par valeur business / risque
- Tier-routing pour chaque tâche

Sortie : `reports/audit/CV1_V1_CLOSEOUT_MASTER_SYNTHESIS_2026-05-02.md`

### Phase 3 — Attaque parallèle (sub-agents implémenteurs)

**Lots indépendants en parallèle** :
- **Lot A** — Cleanup pur frontend (zéro DB) → Composer routine.
- **Lot B** — Gate briefs DROP TABLE conditionnels (livraison/table/online) → moi (orchestrator) écrit, user signe.
- **Lot C** — Refonte dashboard accueil (4 widgets V1) → Composer routine.
- **Lot D** — Tâches issues des 5 audits → routing par tier (routine vs complex).

Wizard runtime POS/Kiosk (Axe 4) → tâches bornées issues du rapport, planifiées en Phase 3 mais **exécutées progressivement** vu l'ampleur.

---

## §3 — Cleanup dashboard (Lot A automatique avec hypothèses §0)

| Module | Action | Justification | Risque DB |
|---|---|---|---|
| `subscribers/` (newsletter) | SUPPRIMER frontend + cache du menu | Hors scope fast-food | Cache UI seul (Lot A) ; DROP table via gate |
| `messages/` | SUPPRIMER frontend + cache du menu | Pas V1 | Cache UI (Lot A) |
| `pushNotification/` | SUPPRIMER frontend + cache du menu | Pas de customer app V1 | Cache UI (Lot A) |
| `settings/social-media` | SUPPRIMER | Marketing site web | Cache UI (Lot A) |
| `settings/cookies` | SUPPRIMER | RGPD site web | Cache UI (Lot A) |
| `settings/analytics` | SUPPRIMER | GA pour site web | Cache UI (Lot A) |
| `settings/sliders` | SUPPRIMER | Carrousel site web | Cache UI (Lot A) |
| `settings/site` | SUPPRIMER | Configuration site web | Cache UI (Lot A) |
| `customers/` | CACHER du menu (garder code) | DIFFÉRÉ V2 (pas de fidélité V1) | Aucun |
| `coupons/` | CACHER du menu | DIFFÉRÉ V2 | Aucun |
| `offers/` | CACHER du menu | DIFFÉRÉ V2 | Aucun |
| `creditBalanceReport/` | CACHER du menu | DIFFÉRÉ V2 | Aucun |
| `settings/mail` | CACHER du menu | DIFFÉRÉ V2 (sauf reset password si actif) | Aucun |
| `settings/loyalty-setup` | CACHER du menu | DIFFÉRÉ V2 | Aucun |
| `settings/notification` | CACHER du menu | DIFFÉRÉ V2 | Aucun |
| `settings/theme` | CACHER du menu | DIFFÉRÉ V2 | Aucun |
| `deliveryBoys/` | GATE BRIEF | Décision §0 = NON livraison | DROP TABLE → gate |
| `waiters/`, `chefs/`, `tableOrders/`, `diningTable/` | GATE BRIEF unique | Décision §0 = NON service à table | 4 DROP TABLE → gate unique |
| `onlineOrders/` | GATE BRIEF | Décision §0 = NON vente en ligne | DROP TABLE → gate |

**Lot A** = exécutable maintenant sans risque DB.
**Lot B** = gates écrits, attente signature user.

---

## §4 — Refonte dashboard accueil (Lot C)

Cible V1 : **4 widgets uniquement**.

1. **Ventes du jour** (CA TTC + nb tickets, filtre branche).
2. **Top 5 produits du jour** (volume).
3. **Stock low alerts** (lien vers `StockRuptureDashboardComponent` M2 2.1).
4. **Dernier Z-report fiscal** (lien vers fiscal archive).

Retirer : tout widget existant qui dépend des modules supprimés (newsletter, push, sliders, etc.) ou marketing.

---

## §5 — Roadmap après cette orchestration

| Étape | Quoi | Quand | Qui |
|---|---|---|---|
| Phase 1 audits | 5 audits parallèles | MAINTENANT | sub-agents explore × 5 |
| Phase 2 synthèse | Master plan | Après audits | Claude orchestrator |
| Phase 3 Lot A + C | Cleanup pur + dashboard refonte | Après synthèse | Composer routine |
| Phase 3 Lot B | Gate briefs DROP TABLE | Parallèle Lot A | Claude orchestrator |
| Lot D issu audits | Tâches restantes V1 | Après synthèse | routing par tier |
| Audit Claude terminal | Ultra-review indépendante 5 axes | User lance quand quota dispo | Anthropic Pro CLI |
| Gates 2.2 + 2.6 + Lot B | Signature humaine | Quand user prêt | user |
| 2.9 Wizard admin | DEFERRED | Décision user | — |
| Wizard runtime refactor | Tâches issues Axe 4 | Post-audit Claude | Codex complex |

---

## §6 — Doctrine appliquée

- `.cursor/routing.md` § Tier-Routing 2026-05-02 (routine vs complex avec halt sur invariant).
- `docs/orchestration/MULTI_AGENT_LOOP_2026-05-02.md` (procédure pivot multi-agents).
- `.cursor/rules/global.mdc` § Token Discipline (qualité d'abord, parallélisation = vrai gain).
- `.cursor/rules/cross-agent-sync.mdc` (réservation `agent-activity-log.sh start/done`).
- `.cursor/rules/project-invariants.mdc` (6 invariants FoodKing).
- `.cursor/rules/scope.mdc` (allowlist par tâche, halt sur expansion).
- `.cursor/rules/human-gates.mdc` (gates écrits avant action, signature humaine pour DROP TABLE / frozen).

---

**Version** : 2026-05-02 v1
**Statut** : ACTIVE — Phase 1 en cours.
