# Addendum Handoff Context — Caisse/POS/Kiosk/KDS — 2026-04-25

## 0. But

Ce fichier complète le méga rapport final:

- `reports/audit/MEGA_RAPPORT_FINAL_DISPUTE_CAISSE_POS_KIOSK_KDS_2026-04-25.md`
- `reports/audit/MEGA_DISPUTE_CODEX_R1_CAISSE_POS_KIOSK_KDS_2026-04-25.md`

Il intègre les deux handoffs fournis par l'utilisateur, sans modifier le code produit:

1. `docs/DOC_EXPO_HER_ANCIEN_AGENT_ALIMENTATION_WORKFLOW_2026-04-22.md`
2. `docs/orchestration/EXPORT_HANDOFF_POS_KDS_MASTER_FINITIONS_2026-04-26.md`

`HANDOFF_INTEGRATION_VERDICT: UPDATE_READING_ORDER_AND_PLAN_CONTEXT`

## 1. Ce que le handoff 2026-04-22 ajoute

`docs/DOC_EXPO_HER_ANCIEN_AGENT_ALIMENTATION_WORKFLOW_2026-04-22.md` n'ajoute pas un P0 métier caisse nouveau. Il ajoute une information de gouvernance: c'est l'**index de reprise des chemins** pour un nouvel agent.

### Effet sur le méga plan

Avant toute exécution issue du méga rapport, le nouvel agent doit lire:

1. `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`
2. `AGENTS.md`
3. `docs/DOC_EXPO_HER_ANCIEN_AGENT_ALIMENTATION_WORKFLOW_2026-04-22.md`
4. `.cursor/ACTIVE_CYCLE.md`
5. le présent paquet caisse: méga rapport final + rapports caisse/POS/borne

### Pourquoi c'est important

| Élément | Impact |
|---|---|
| Table ~45 chemins | Réduit le risque qu'un agent rate Graphiti, gates, active cycle, memory, outbox, KDS/POS files |
| Section fichiers non versionnés | Rappelle que `~/.cursor/mcp.json`, secrets Graphiti et env locaux ne sont pas dans git |
| Prompt de nouvelle session | Stabilise le handoff pour sub-agent ou terminal |
| Lien depuis `GLOBAL_SYSTEM_PRIMER.md` | Le doc est déjà branché dans le parcours d'ouverture officiel |

### Modification de doctrine

Le méga rapport final disait déjà: gates -> sentinelles -> sécurité paiement/branch/status -> quote backend. Cet addendum ajoute une étape **0a**:

```text
0a. Lire le handoff global 2026-04-22 pour charger correctement les chemins, la mémoire, les gates et le cycle actif.
```

## 2. Ce que le handoff POS/KDS 2026-04-26 ajoute

`docs/orchestration/EXPORT_HANDOFF_POS_KDS_MASTER_FINITIONS_2026-04-26.md` confirme le contexte POS/KDS le plus opérationnel:

- verdict POS+KDS existant: **NOT-READY 4/10**;
- source de fond: `reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md`;
- plan exécutable: `plans/PLAN_MASTER_FINITIONS_POS_KDS_AVANT_LANCEMENT_2026-04-26.md`;
- découpage en 9 lots;
- protocole de compétition/fusion entre plans d'agents.

### Impact sur le méga rapport final

Le méga rapport final avait un plan plus large "caisse système" avec OrderIntent, OrderQuote, PaymentProof et KitchenRelease. Le handoff POS/KDS 2026-04-26 fournit le **sous-plan immédiat** pour les finitions POS/KDS.

L'arbitrage devient:

| Niveau | Source | Usage |
|---|---|---|
| Onboarding global | `DOC_EXPO_HER...` | Nouvelle session / nouvel agent |
| POS/KDS finitions | `EXPORT_HANDOFF_POS_KDS...` + `PLAN_MASTER_FINITIONS...` | Lots rapides et gates POS/KDS déjà cadrés |
| Système caisse complet | `MEGA_RAPPORT_FINAL_DISPUTE...` | Architecture correction V1/V2, quote/ledger/release |

## 3. Ordre de lecture corrigé pour la prochaine session

### Minimum obligatoire

```text
1. AGENTS.md
2. docs/orchestration/GLOBAL_SYSTEM_PRIMER.md
3. docs/DOC_EXPO_HER_ANCIEN_AGENT_ALIMENTATION_WORKFLOW_2026-04-22.md
4. .cursor/ACTIVE_CYCLE.md
5. reports/audit/MEGA_RAPPORT_FINAL_DISPUTE_CAISSE_POS_KIOSK_KDS_2026-04-25.md
6. reports/audit/MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP_SINGLE_FILE_2026-04-25.md
7. reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md
8. reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md
9. docs/orchestration/EXPORT_HANDOFF_POS_KDS_MASTER_FINITIONS_2026-04-26.md
10. plans/PLAN_MASTER_FINITIONS_POS_KDS_AVANT_LANCEMENT_2026-04-26.md
```

### Si le prochain cycle est POS/KDS quick wins

Lire aussi:

```text
reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md
reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_BRIEF_2026-04-26.md
docs/gates/GATE_LOG.md
```

### Si le prochain cycle est caisse architecture

Lire aussi:

```text
reports/audit/MEGA_DISPUTE_CODEX_R1_CAISSE_POS_KIOSK_KDS_2026-04-25.md
reports/audit/CHALLENGE_MASTER_CHECKLIST_DEEP_SINGLE_2026-04-25.md
docs/ORDER_FLOW.md
docs/DEVICE_FLOW.md
docs/BUSINESS_RULES.md
```

## 4. Mise à jour du plan par rapport aux deux handoffs

### Ce qui ne change pas

Les priorités système restent:

1. gates humains;
2. tests sentinelles;
3. sécurité paiement/branch/status;
4. quote backend;
5. payment/fiscal decision;
6. KDS/release;
7. kiosk runtime/offline;
8. ops/hardware.

### Ce qui devient plus précis

| Point | Avant | Après intégration handoffs |
|---|---|---|
| Démarrage agent | lire AGENTS/Primer/rapports | lire aussi `DOC_EXPO_HER...` comme index chemins |
| POS/KDS quick wins | listés dans méga plan | mapper sur `LOT-0`, `LOT-2`, `LOT-3`, `LOT-5a` du handoff 2026-04-26 |
| Gates POS/KDS | génériques | utiliser `GATE_LOG.md`, `GATE_VERIFY_P0_FROZEN...`, `GATE_PAYMENT_PROP_MUTATION...` |
| Compétition de plans | implicite dans dispute | suivre section 7 du handoff: merge review avec `finding -> keep/merge/drop` |
| Nouvel agent | possible mais long | `EXPORT_HANDOFF_POS_KDS...` sert de fichier d'entrée rapide |

## 5. Recommandation pratique

### Prochain cycle si priorité "débloquer terrain vite"

Lancer d'abord:

```text
run-cycle POS_KDS_FINITIONS_LOT0_QUICKWINS_2026-04-26
```

Scope attendu:

- `discountReason` POS;
- KDS RTL;
- tests Vitest associés;
- aucun backend frozen.

Pourquoi: c'est le lot le plus court, sans gate, et il donne une victoire contrôlée sans toucher aux zones risquées.

### Prochain cycle si priorité "sécurité caisse"

Créer un nouveau TASK_ID dédié:

```text
CAISSE_V1_SENTINELS_PAYMENT_BRANCH_STATUS_2026-04-25
```

Scope attendu:

- tests rouges `payment-confirm`;
- tests branch exactness;
- tests KDS status whitelist;
- tests POS collect cash via KDS;
- aucun patch produit dans ce premier cycle.

Pourquoi: c'est le chemin le plus sûr avant de toucher `OrderService`/`FrontendOrderService`.

## 6. Ligne à ajouter au méga rapport final

Le méga rapport final doit considérer ce fichier comme addendum de passation:

```text
ADDENDUM_HANDOFF_CONTEXT: reports/audit/MEGA_HANDOFF_CONTEXT_INTEGRATION_CAISSE_POS_KIOSK_KDS_2026-04-25.md
```

## 7. Verdict

Les deux handoffs ne changent pas le verdict métier du méga rapport final. Ils changent surtout la façon de le rendre exécutable sans perte de contexte:

- `DOC_EXPO_HER...` = index global de reprise;
- `EXPORT_HANDOFF_POS_KDS...` = plan POS/KDS prêt pour nouvel agent;
- `MEGA_RAPPORT_FINAL_DISPUTE...` = arbitrage système caisse/POS/kiosk/KDS.

`HANDOFF_CONTEXT_FINAL: INTEGRATED_WITHOUT_PRODUCT_CODE_CHANGE`
