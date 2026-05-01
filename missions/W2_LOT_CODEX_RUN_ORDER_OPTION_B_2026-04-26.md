# Caisse V1 — position Option B + file d’exécution Codex (lots D / P / K)

> **Lecture humaine d’abord (sans jargon)** : `missions/LIRE_MOI_WAVE2.md` — même chemin, noms simples, « un numéro après l’autre ».

**Date** : 2026-04-26  
**Décision** : **Payment Ledger = Option B (pilote restreint)** — ne pas lancer le ledger complet (M-04A / Option A).

## Message de cadrage pour Codex (à copier tel quel)

```text
Décision produit : Option B (pilote restreint) pour le payment ledger. Ne pas exécuter
CV1-M04A-PAYMENT-LEDGER-FULL (reste BLOCKED) tant qu’on ne bascule pas en Option A avec gates humains.

Ordre d’exécution : suivre le fichier missions/W2_LOT_CODEX_RUN_ORDER_OPTION_B_2026-04-26.md
(section « Liste des 36 runs »). UNE tâche = UNE exécution (allowlist propre, activity-log, validate, audits).

Avant chaque run : vérifier que missions/<TASK_ID>/input.json et execute_brief.md existent
(sinon : créer le dossier mission d’abord, ou noter SCOPE_PRESSURE / BLOCKED).
```

## Rappels

- `**CV1-M04A-PAYMENT-LEDGER-FULL**` : exclu par cette décision (sauf changement de gate + déblocage explicite).
- `**CV1-M04B-PAYMENT-PILOT-RESTRICT**` : c’est la piste cohérente avec B (déjà traitée selon `MASTERPLAY_QUEUE` si `CLOSED`).
- Les `TASK_ID` `CV1-LOT-*` ci-dessous sont des **identifiants d’orchestration Wave 2** (lots D/P/K) : les dossiers `missions/CV1-LOT-.../` doivent être **préparés** (input, brief, allowlist) avant `codex:complex` — sinon l’exécuteur refusera faute de mission.

## Liste des 36 runs (ordre d’enchaînement : D, P, K × 7 puis P/K puis P-15 seul)


| #   | TASK_ID                              | LOT  |
| --- | ------------------------------------ | ---- |
| 1   | `CV1-LOT-D01-CLIENT-TOTAL-INVARIANT` | D-01 |
| 2   | `CV1-LOT-P01-QUOTE-BIND`             | P-01 |
| 3   | `CV1-LOT-K01-ROUTING-LEGACY`         | K-01 |
| 4   | `CV1-LOT-D02-ORDER-EVENT-OUTBOX-MAP` | D-02 |
| 5   | `CV1-LOT-P02-DISCOUNT-GUARD`         | P-02 |
| 6   | `CV1-LOT-K02-ORDER-TYPE-EXPLICIT`    | K-02 |
| 7   | `CV1-LOT-D03-BRANCH-FILTER-MATRIX`   | D-03 |
| 8   | `CV1-LOT-P03-DISCOUNT-REASON-BIND`   | P-03 |
| 9   | `CV1-LOT-K03-QUOTE-PRICING-PIN`      | K-03 |
| 10  | `CV1-LOT-D04-DELIVERY-API-CONTRACT`  | D-04 |
| 11  | `CV1-LOT-P04-PAYMENT-REFACTOR-PROPS` | P-04 |
| 12  | `CV1-LOT-K04-PAYMENT-UX-OFFLINE`     | K-04 |
| 13  | `CV1-LOT-D05-CANCEL-AUDIT-TRAIL`     | D-05 |
| 14  | `CV1-LOT-P05-FLOORPLAN-RELEASE`      | P-05 |
| 15  | `CV1-LOT-K05-PAYMENT-CONFIRM-WS`     | K-05 |
| 16  | `CV1-LOT-D06-BROADCAST-FALLBACK-DOC` | D-06 |
| 17  | `CV1-LOT-P06-PARK-TTL`               | P-06 |
| 18  | `CV1-LOT-K06-OFFLINE-WAITING-UX`     | K-06 |
| 19  | `CV1-LOT-D07-FOS-SYMMETRY-CONTRACT`  | D-07 |
| 20  | `CV1-LOT-P07-KIOSK-CASH-DECOUPLE`    | P-07 |
| 21  | `CV1-LOT-K07-WIZARD-UNIFY`           | K-07 |
| 22  | `CV1-LOT-P08-KDS-RELEASE-RULE`       | P-08 |
| 23  | `CV1-LOT-K08-GLOBAL-ERRORS`          | K-08 |
| 24  | `CV1-LOT-P09-AFTER-COMMIT-DISPATCH`  | P-09 |
| 25  | `CV1-LOT-K09-POS-REALTIME-KIOSK-VIS` | K-09 |
| 26  | `CV1-LOT-P10-REFUND-LEDGER`          | P-10 |
| 27  | `CV1-LOT-K10-CLEANUP-IDEMPOTENCY`    | K-10 |
| 28  | `CV1-LOT-P11-PRINT-AUDIT`            | P-11 |
| 29  | `CV1-LOT-K11-PRINT-FALLBACK-IDLE`    | K-11 |
| 30  | `CV1-LOT-P12-RT-RESYNC`              | P-12 |
| 31  | `CV1-LOT-K12-LOYALTY-REFUSAL`        | K-12 |
| 32  | `CV1-LOT-P13-ZREPORT-HARDEN`         | P-13 |
| 33  | `CV1-LOT-K13-SENTINEL-IDEMPOTENCY`   | K-13 |
| 34  | `CV1-LOT-P14-BRANCH-BADGE`           | P-14 |
| 35  | `CV1-LOT-K14-TELEMETRY-HOMOG`        | K-14 |
| 36  | `CV1-LOT-P15-E2E-MATRIX`             | P-15 |


## Préparation missions — 2026-04-26

`MISSION_DIR_STATUS: PREPARED`

Généré par `node scripts/prepare-w2-option-b-missions.mjs`.

- 36 dossiers `missions/CV1-LOT-`* créés.
- 180 fichiers de mission créés : `input.json`, `execute_brief.md`, `plan_excerpt.md`, `graphiti_context.md`, `README.md` pour chaque run.
- Chaque `input.json` contient sa propre `allowlist`, ses `mandatory_tests`, ses gates, ses invariants et la règle Option B.
- Validation locale : `jq -e` OK sur les 36 `input.json`.
- `CV1-M04A-PAYMENT-LEDGER-FULL` reste exclu / non lancé.

Lots préparés mais **bloqués par gate/rescope** avant exécution :


| TASK_ID                          | Motif                                         |
| -------------------------------- | --------------------------------------------- |
| `CV1-LOT-K05-PAYMENT-CONFIRM-WS` | `BLOCKED_FROZEN_F21_GATE_UNTIL_VERIFIED`      |
| `CV1-LOT-P06-PARK-TTL`           | `BLOCKED_SCHEMA_GATE_IF_MIGRATION`            |
| `CV1-LOT-P10-REFUND-LEDGER`      | `BLOCKED_OPTION_B_RESCOPING_REQUIRED`         |
| `CV1-LOT-P13-ZREPORT-HARDEN`     | `BLOCKED_SCHEMA_AND_FISCAL_GATE_IF_MIGRATION` |


Le runner Wave 2 doit lire `missions/<TASK_ID>/input.json.status` avant `codex:complex`. Si le statut commence par `BLOCKED`_, marquer le run bloqué avec note précise et passer au prochain run possible sans mélanger les allowlists.

## Gates / garde-fous (à ne pas sauter, même en Option B)

- Avant gros sujets KDS : **GATE_KDS_BUMP_*** (P-08) — vérifier brief signé si requis.  
- Avant **P-10** (refund / “ledger” dans le nom) : en **Option B**, le périmètre n’est **pas** le M-04A full ; cadrer l’allowlist sur ce qui est compatible pilote.  
- Avant fiscal Z : **GATE_FISCAL_*** (P-13) si touché.  
- Release : **HG-W2_CUTOVER_DECISION_OR_POS_WIZARD_SHIM_ACCEPTANCE** (hors run Codex seul) selon addendum `MASTERPLAY_QUEUE.md`.

## Commande type (une tâche par tour)

Remplace `TASK_ID` par la cellule de la table.

```bash
export TASK_ID='CV1-LOT-D01-CLIENT-TOTAL-INVARIANT'
export ALLOWLIST="$(jq -r '.allowlist | join(",")' "missions/$TASK_ID/input.json")"
npm run codex:prepare -- "$TASK_ID"    # si disponible
npm run codex:plan-review -- "$TASK_ID" # selon run-cycle
bash scripts/agent-activity-log.sh start codex-extension "$TASK_ID" execute "$ALLOWLIST" "W2 lot"
npm run codex:complex -- "$TASK_ID"
bash scripts/agent-activity-log.sh done codex-extension "$TASK_ID" done "W2 lot terminé / ou note"
```

## Missions Caisse V1 « classiques » (réf. masterplay, pas les lots W2)

Fichier autoritaire : `plans/masterplay/MASTERPLAY_QUEUE.md` (24 entrées, ordre 01–24, **M-04A BLOCKED** sous Option B).

**Ce fichier ne remplace pas** `MASTERPLAY_QUEUE` — il **ajoute** seulement la file Wave 2 `CV1-LOT-`* pour l’orchestrateur / Codex quand les dossiers `missions/…` existeront.