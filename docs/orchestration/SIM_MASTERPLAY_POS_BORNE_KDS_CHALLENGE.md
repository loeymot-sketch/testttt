# Simulation **Master Play** — POS · Borne (kiosk) · KDS (challenge Claude ↔ GPT Pro)

> **But** : produire un **breakdown massif** (connexions, déconnexions, doublons suspects, dette doc) **sans** déclarer un cycle produit ; tester la **boucle (BX)** : orchestration → plan détaillé → passe **adversariale** GPT Pro → synthèse ; alimenter **Graphiti** (store B) après stabilisation.

**TASK_ID simulation** : `SIM-MASTERPLAY-2026-04-25`  
**Livrables** :
- `reports/audit/SIM_MASTERPLAY_BREAKDOWN_SYNTH_V0_2026-04-25.md` — breakdown V0 (dépôt + audits existants).
- `reports/audit/SIM_MASTERPLAY_GPT_CHALLENGE_ROUND2_2026-04-25.md` — **Round 2** GPT Pro (attaques, validations, matrice, verdict).
- `missions/SIM-MASTERPLAY-2026-04-25/output_codex.raw.log` — brut Codex + grep massif.
- `reports/audit/SIM_MASTERPLAY_SYNTH_BRIDGE_ROUND3_2026-04-25.md` — **Round 3** pont V0↔GPT (synthèse orchestrateur session).
- **`reports/audit/SIM_MASTERPLAY_BREAKDOWN_SYNTH_V1_2026-04-25.md`** — fusion V0+R2+R3.
- `reports/audit/SIM_MASTERPLAY_CLAUDE_TERMINAL_ROUND4_2026-04-25.md` — **arbitrage Claude Code terminal** (conflits 1–3 + table GPT vs code + `AUDIT_VERDICT`).
- `reports/audit/SIM_MASTERPLAY_ORCHESTRATION_PLAN_EXECUTED_2026-04-25.md` — plan numéroté exécuté.
- **`reports/audit/SIM_MASTERPLAY_FINAL_CONSOLIDATED_2026-04-25.md`** — **synthèse unique** (tous tableaux, toutes compétitions).

---

## 0. Règles (ne pas casser le projet)

| Règle | Détail |
|-------|--------|
| **Pas d’impl produit** dans cette simulation seule | Pas de commit `app/`, `resources/` hors rapports si non autorisé ; ici : **gouvernance + rapports + missions**. |
| **Pricing** | SSOT backend — tout écart kiosk/POS **affichage vs API** = risque, pas « prix calculé côté Vue » sans preuve. |
| **`OrderStatus`** | Enum unique — pas de littéraux hors enum dans les findings sans citation. |
| **`branch_id`** | Toute anomalie liste filiale / admin `0`. |
| **Graphiti** | `group_id=foodking` ; requêtes **lecture** avant synthèse ; écriture **après** AUDIT humain / verdict stable. |

---

## 1. Paquet Graphiti (à lancer **avant** Round 1 — MCP ou secours JSONL)

Si MCP chargé, exécuter **au moins** :

1. `search_memory_facts` — *« FoodKing POS kiosk KDS sync OrderStatus Echo Pusher branch_id »*  
2. `search_memory_facts` — *« KitchenDisplaySystemOrderService FrontendOrderService OrderService symmetry »*  
3. `search_memory_facts` — *« KDS sync polling WebSocket degraded F-03 »*

Si MCP absent : lire `memory/INDEX.md` + épisodes domaine **sync / devices** dans `memory/episodes/*.jsonl`.

---

## 2. Round 1 — **Claude** (session Cursor = orchestrateur)

**Entrée** : ce fichier + `SIM_MASTERPLAY_BREAKDOWN_SYNTH_V0_*.md` + `docs/DEVICE_FLOW.md` + `docs/HANDOFF_NEW_CURSOR/04_FICHIERS_PIVOTS_PAR_FLUX.md`.

**Sortie attendue** :
- Cartographie **3 surfaces** + **couche glue** (routes, events, jobs).
- Liste **P0 / P1 / P2** : déconnexion doc↔code, doublons de listener, asymétries services, OSS omis si pertinent.
- **Angles d’attaque** explicites pour GPT Pro (3–7 bullets) : « ce que tu veux que GPT tente de défoncer ».

Tracer : pas besoin de `EXECUTE_DELEGATION` si **aucune** édition produit — uniquement rapports / missions.

---

## 3. Round 2 — **GPT Pro** (`codex-extension`, `npm run codex:complex -- SIM-MASTERPLAY-2026-04-25`)

**Rôle** : **adversaire** — chercher erreurs dans le V0, omissions, sur-confiance, incohérences avec `AGENTS.md` / invariants ; proposer **patchs de plan** (pas de code produit sauf si un **nouveau** cycle TASK_ID l’autorise).

**Prompt mental** : *« Tu perds si tu ne trouves pas au moins 5 failles sérieuses ou 5 renforcements majeurs. »*

**Sortie** : JSON / prose dans `output_codex.raw.log` + auto-audit si extract OK.

---

## 4. Round 3 — **Synthèse** (Claude terminal **ou** `foodking-planner-orchestrator` si quota)

1. Table **V0 vs GPT** : ligne par ligne *claim* → *confirmé* / *infirmé* / *à creuser*.  
2. **Master Play** final : une section par domaine (POS, Borne, KDS, glue, OSS) avec **owners** techniques (fichiers pivots).  
3. Décisions durables → `memory/episodes/12_decisions_log.jsonl` + `bash scripts/after-execute-memory.sh`.

---

## 5. Test de la **BX** (boucle) sur cette simulation

| Step `run-cycle` | Application Master Play |
|------------------|---------------------------|
| Step 0 | `session:open`, Graphiti, `ACTIVE_CYCLE` si cycle formel ; sinon lecture seule. |
| Step 1 | Plan `PLAN_SIM_MASTERPLAY_2026-04-25.md` (optionnel) ou ce doc comme SSOT procédural. |
| Step 2 | **N/A code** — pas d’EXECUTE produit. |
| Step 3 | Relecture croisée V0 + GPT. |
| Step 4 | **AUDIT** : terminal ou repli `AUDIT_TERMINAL_QUOTA_FALLBACK.md`. |
| Step 5 | Verdict `PASS` sur la **qualité du breakdown** ou `REWORK` → enrichir V1. |

---

## 6. Références dépôt déjà riches (à fusionner, pas réinventer)

- `reports/audit/AUDIT_KDS_POS_SYNCHRONISATION_PROFONDE_2026-04-24.md`
- `plans/PLAN_POS_KIOSK_KDS_SYNC_REPAIR_v2_2026-04-23.md`
- `docs/DEVICE_FLOW.md`, `docs/ORDER_FLOW.md` (si besoin), `04_FICHIERS_PIVOTS_PAR_FLUX.md`

---

*Document de simulation — évolution via PR / cycle TASK_ID dédié si le breakdown devient exécutable.*
