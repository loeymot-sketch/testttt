# Master Play — **Round 3** (pont V0 ↔ Round 2 GPT Pro)

**Rôle** : synthèse **orchestrateur** (session Composer) — pas un second appel terminal ; à valider par **Claude** (`audit-brief` / `foodking-planner-orchestrator` si quota) avant décision durable Graphiti.

## Verdict croisé

| Source | Document | Verdict |
|--------|------------|---------|
| Round 1 (V0) | `SIM_MASTERPLAY_BREAKDOWN_SYNTH_V0_2026-04-25.md` | Cartographie **solide**, matrice événements **incomplète**, risques WS/polling **sous-détaillés**. |
| Round 2 (GPT Pro) | `SIM_MASTERPLAY_GPT_CHALLENGE_ROUND2_2026-04-25.md` | `GPT_CHALLENGE_VERDICT: **MERGE_V0_WITH_REVISIONS**` — attaques **P0** idempotence + pricing paiement + bump KDS ; validations **renforcent** le V0 sur Echo vs doc Firebase. |

**Synthèse une ligne** : **garder le V0 comme ossature**, **intégrer** les 7 attaques + 7 validations + matrice GPT + 3 métriques + liste P0–P2 → **Master Play V1** (nouveau fichier ou même arbre sous `reports/audit/`).

## Table « qui a gagné quoi » (pas une bagarre fictive — complémentarité)

| Thème | V0 | GPT | Décision V1 |
|-------|----|----|-------------|
| Architecture 3 surfaces | Clair | Accuse trous preuve | **Conserver** + exiger preuves grep |
| Doc `DEVICE_FLOW` vs POS | Signalé (POS-DOC-01) | **Preuve fichier** PosComponent | **Mettre à jour doc** en cycle séparé |
| Idempotency borne | Non creusé | **P0** code path | **Cycle produit** dédié + tests |
| Paiement / total UI | SSOT rappelé | **UNVERIFIED** terminal | **Audit ciblé** `KioskPaymentComponent` |
| Bump KDS `localStorage` | Mentionné comme risque UI | **P0** opérationnel | **ADR** humain + règle mono-écran ou serveur |
| Matrice événements | Partielle | **Complétée** (OSS, etc.) | **Remplacer** matrice V0 par SECTION_C GPT |
| After-commit | Rappel invariant | **Trou** `OrderTableChanged` test | **Test** en cycle séparé |

## Prochaines actions (ordre)

1. ~~Rédiger `SIM_MASTERPLAY_BREAKDOWN_SYNTH_V1_2026-04-25.md`~~ **Fait** — rapport unique fusionné.  
2. Si MCP Graphiti disponible : `add_memory` ou JSONL `12_decisions` pour *« MERGE Master Play 2026-04-25 — V1 à produire »*.  
3. Si passage **production code** : nouveau `TASK_ID` + `SUBSYSTEMS_TOUCHED` + gates si auth/pricing.

---

*Round 3 rédigé en session outillage — pas `TERMINAL_AUDIT_OK`.*
