EXECUTE_DELEGATION: foodking-routine-implementer

# RUN — P_MEGA_CHECKLIST_BATCH2_MACHINE_GUARDS_2026-04-23

Lot 2 méga-checklist : garde-fous machine (hook post-édition + 3 scripts d’audit shell).

## Fichiers livrés

### `.cursor/hooks/post-edit-check.sh`

- Entrée : `$1` = chemin du fichier édité ; si vide → **sortie silencieuse, exit 0**.
- **Frozen zones** (préfixe ou égalité) : `app/Services/OrderService.php`, `FrontendOrderService.php`, `PaymentService.php`, `app/Services/Pricing/*`, `PosReceiptPrintController.php`, `database/migrations/*` → message **WARN** (gate `LOCK_*.md` / `docs/gates/`), **exit 0**.
- `.cursor/routing.md` → **WARN** (rappel B13 mid-cycle), **exit 0**.
- `memory/episodes/*.jsonl` → **INFO** (rappel `bin/graphiti-ingest.sh`), **exit 0**.
- Sinon → **OK**, **exit 0**.
- Léger : pas de scan repo, uniquement des tests de chaînes.

### `scripts/check-execute-delegation.sh` (B05)

- Dénombre tous les `reports/**/RUN_*.md`.
- Compte ceux contenant une ligne **`^EXECUTE_DELEGATION:`** (`rg` si dispo, sinon `grep -E`).
- Affiche `EXECUTE_DELEGATION sentinel coverage : X/Y (Z%)`.
- Si **Z < 50%** : **WARN** + jusqu’à 5 chemins les plus récents (mtime) **sans** sentinel.
- **exit 0** toujours.

### `scripts/list-gates.sh` (D01)

- Parcourt `docs/gates/*.md`.
- Statut : **CLOSED** si `APPROUVÉ|APPROVED|CLOSED|RESOLVED` (prioritaire) ; sinon **OPEN** si `PENDING|AWAITING|DRAFT` ou mot entier `OPEN` ; sinon **UNKNOWN**.
- Affiche l’inventaire OPEN / CLOSED / UNKNOWN + **TOTAL**.
- **exit 0** toujours.

### `scripts/check-active-cycle.sh` (B06)

- Lit `.cursor/ACTIVE_CYCLE.md`.
- Compte les lignes `## …` contenant **`IN_PROGRESS`** ou **`IN PROGRESS`**.
- **> 1** : WARN + liste des en-têtes ; **== 1** : OK + nom ; **== 0** : INFO idle/closed.
- **exit 0** toujours.

## Sorties des 3 scripts d’audit (exécution de vérification)

### `./scripts/check-execute-delegation.sh`

```
EXECUTE_DELEGATION sentinel coverage : 18/114 (15%)
WARN: coverage < 50% — jusqu'à 5 derniers RUN_*.md (mtime) sans sentinel :
  reports/execution/RUN_P_MEGA_CHECKLIST_BATCH1_DATA_QUALITY_2026-04-23.md
  reports/execution/RUN_P_MEGA_W8_C_P2_SCHEDULE_EXECUTE_2026-04-20.md
  reports/execution/RUN_V14_T03_PARITY_PLUS_G3_2026-04-20.md
  reports/execution/RUN_P_MEGA_W7_B_REM1_2026-04-20.md
  reports/execution/RUN_V14_T22_E2E_PARTIAL_TACOS_2026-04-20.md
```

### `./scripts/list-gates.sh`

```
GATES INVENTORY (docs/gates/) :
  OPEN    : 0 → (aucun)
  CLOSED  : 12 → GATE_BATCH_V1_APPROVAL_CHECKLIST.md GATE_G14A_VARIATION_MULTI_QTY_CONSOLIDATED_2026-04-20.md GATE_LOG.md GATE_MULTISURF_001_2026-04-14.md GATE_PAYMENT_SAFETY_001_2026-04-14.md GATE_P_MEGA_19_BRANCH_THEMING_2026-04-20.md GATE_P_MEGA_22_NF525_READINESS_2026-04-20.md GATE_SYNC_WIZARD_DEEP_001_2026-04-14.md GATE_V1_DATA_SOFTDELETE_001_2026-04-15.md GATE_V1_MENU_86_001_2026-04-15.md GATE_V1_STATUS_MACHINE_001_2026-04-15.md GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md 
  UNKNOWN : 4 → GATE_P_MEGA_20_BRANCH_MISMATCH_2026-04-20.md GATE_P_MEGA_21_THROTTLE_2026-04-20.md GATE_V1_PRICING_SSOT_001_2026-04-15.md GATE_W9_AUDIT_KIOSK_RECEIPT_NON_FISCAL_2026-04-21.md 
TOTAL   : 16
```

### `./scripts/check-active-cycle.sh`

```
[check-active-cycle] OK: 1 cycle IN_PROGRESS
  ## CYCLE_W10_EXECUTION_CLOSEOUT (IN_PROGRESS — mémoire 180 + MCP global + commit + CI + prod)
```

## Test `post-edit-check.sh` (zone gelée)

Commande : `bash .cursor/hooks/post-edit-check.sh app/Services/OrderService.php`

Sortie :

```
[post-edit-check] WARN: app/Services/OrderService.php est en frozen zone — vérifie qu'un LOCK_*.md gate existe avant commit (docs/gates/).
```

**Code de sortie : 0** (warn-only, conforme).

Test `$1` vide : aucune sortie, **exit 0**.

## Livraison

- [x] LOT 2 livré
