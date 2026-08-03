# EXECUTE BRIEF — CV1-M01-TRACEABILITY-MATRIX (M-01)

## INVIOLABLE
1. Lis dans cet ordre :
   - `AGENTS.md` (parcours obligatoire)
   - `missions/CV1-M01-TRACEABILITY-MATRIX/input.json` (allowlist + off_limits)
   - `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md` (sections 0, 1, 2 et la mission M-01)
   - `reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md` (matrice initiale à étendre)
   - Les `source_reports` listés dans `input.json`
2. **Allowlist stricte** :
   - `reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md` (réécriture étendue)
   - `reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.csv` (NEW)
   - `scripts/check-traceability.sh` (NEW)
3. **Tu ne touches AUCUN code produit.** Aucun fichier sous `app/`, `resources/`, `routes/`, `database/`, `tests/`, `config/`, `.cursor/`, `AGENTS.md`, `plans/PLAN_CAISSE_V1_SUPER_MASTER_*.md`.
4. **Tu n'approuves aucun gate.** Tu peux *citer* leur statut, jamais cocher `[x] Approved`.

## OBJECTIF EXACT

Produire une matrice de traçabilité **complète et machine-vérifiable** des findings P0/P1/P2 de Caisse V1 (POS + Kiosk + KDS + Centrale + Fiscal + Ops), reliant chaque finding à un Plan-ID, TASK_ID, Sentinel, test, gate, owner, status, evidence.

## SCHEMA OBLIGATOIRE

### Matrice `.md`

Tableau Markdown avec colonnes (dans cet ordre exact) :

| FK-ID | Source | Description | Severity | Plan-ID | TASK_ID | Sentinel | Test_Command | Gate | Owner | Status | Evidence |
|-------|--------|-------------|----------|---------|---------|----------|--------------|------|-------|--------|----------|

- **FK-ID** : `FK-001`, `FK-002`, ... (séquentiel stable, 3 chiffres)
- **Source** : nom court du rapport (ex: `MEGA_RAPPORT_FINAL_DISPUTE`, `AUDIT_POS`, `AUDIT_KIOSK`, `MASTER_REQUEST_CV1`, `CLAUDE_SUPER_MASTER_REVIEW`, `MASTER_REVIEW_POS_KDS_FINITIONS`)
- **Description** : 1 phrase ≤ 140 caractères. Doit pouvoir se lire seule.
- **Severity** : `P0` | `P1` | `P2` | `INFO`
- **Plan-ID** : `PLAN-XX` du super master (ex: `PLAN-06`, `PLAN-09`, `PLAN-21`). Aucun P0 vide.
- **TASK_ID** : `CV1-MXX-...` proposé (du masterplay). Si pas encore mappé : `(unmapped)` mais alors Status = `unmapped`.
- **Sentinel** : nom de test sentinelle de M-02 (ex: `PaymentConfirmAbilitySentinelTest`). `(none)` si pas de sentinelle requise.
- **Test_Command** : commande exécutable précise (`php artisan test --filter=...`, `npx vitest run tests/js/...`, `bash scripts/...`). `PREUVE_MANQUANTE` si rien n'existe encore.
- **Gate** : nom du gate bloquant (`GATE_FROZEN_ZONES_CAISSE_V1`, `GATE_PAYMENT_LEDGER_V1`, ...). `(none)` si pas de gate.
- **Owner** : `BE` | `FE` | `BE+FE` | `DevOps` | `QA` | `DBA` | `Ops` | `Product` | `Human`
- **Status** : `unmapped` | `planned` | `in_progress` | `verified` | `deferred`
- **Evidence** : lien `file:line` quand disponible (ex: `app/Services/OrderService.php:151`), sinon `(pending)`.

### CSV

Même schéma, en CSV :
- header : `FK-ID,Source,Description,Severity,Plan-ID,TASK_ID,Sentinel,Test_Command,Gate,Owner,Status,Evidence`
- séparateur : `,`
- échappement : double-quote complet (RFC 4180), virgules échappées avec `""`, retours-ligne dans description interdits (utiliser `; `)
- encoding : UTF-8 sans BOM

## SOURCES À PARCOURIR

Pour chaque rapport source, extraire **toutes** les findings énumérées :

1. `MEGA_RAPPORT_FINAL_DISPUTE_CAISSE_POS_KIOSK_KDS_2026-04-25.md` — section 2 (table dispute par topic), section 3 (lots), section 4 (master fix), section 5 (red-team).
2. `AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md` — toutes les `F-XXX` (F-001..F-015 typiquement) + recommandations T-XXX.
3. `AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md` — section 4 points critiques classés (P0/P1/P2), KIOSK-DEEP-XXX.
4. `MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP_SINGLE_FILE_2026-04-25.md` — toutes les T-XXX (T-001..T-027+).
5. `CLAUDE_SUPER_MASTER_PLAN_REVIEW_CAISSE_V1_2026-04-25.md` — section A.2 (15 insuffisances), section C (findings non mappés), section D (22 plans), section H (matrice tests).
6. `MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md` — FIND-01..FIND-15.
7. `MEGA_PLAN_READINESS_GAP_ANALYSIS_CAISSE_POS_KIOSK_KDS_2026-04-25.md` — tous les GAP-XXX.

**Dédoublonnage** : si la même finding apparaît dans plusieurs rapports, **une seule ligne FK-XXX** dont la colonne `Source` liste les rapports `;`-séparés.

## RÈGLES DE QUALITÉ

1. **Aucun P0 sans `Plan-ID`** — si tu n'arrives pas à mapper, mets `Plan-ID = ?` et `Status = unmapped` ET `risks` du JSON contient `ESCALATION: <FK-ID> non mappable`.
2. **Aucun P0 sans `Sentinel` ou `Test_Command` ou `PREUVE_MANQUANTE`** — l'un des trois doit être renseigné explicitement.
3. **Aucune ligne sans `Evidence`** : minimum `(pending)`. Pour les findings code, **donner file:line** quand le rapport source le donne.
4. **Cohérence Plan-ID** : utiliser uniquement les Plan-IDs déclarés dans `PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md` § 4 (PLAN-00..PLAN-22).
5. **Cohérence TASK_ID** : utiliser le préfixe `CV1-MXX-` aligné sur le masterplay (M-01..M-22).
6. **Cohérence Gate** : nom exact (sensible casse) tel que listé dans `docs/gates/GATE_LOG.md` ou super master § 3.

## SCRIPT DE VÉRIFICATION

`scripts/check-traceability.sh` — bash POSIX, exit 0 si OK, exit 1 sinon :

```
#!/usr/bin/env bash
# Vérifie l'intégrité de reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md|.csv
# Règles :
#  R1 — Aucun P0 sans Plan-ID (Plan-ID != "?" et != "")
#  R2 — Aucun P0 sans Sentinel non-(none) OU Test_Command exécutable OU "PREUVE_MANQUANTE"
#  R3 — CSV : header conforme, nb colonnes constant, FK-ID séquentiel sans trou
#  R4 — Plan-ID dans la liste PLAN-00..PLAN-22 (ou "?" si unmapped)
# Sortie : lignes "OK" / "FAIL — <règle> — FK-XXX — <raison>"
```

Implémenter avec `awk`/`grep`/`cut` POSIX. Ne pas dépendre de `jq`. Doit fonctionner sur macOS et Linux.

## SECTIONS DU `.md` À PRODUIRE

Le fichier `reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md` doit contenir, dans cet ordre :

1. `# TRACEABILITY MATRIX — CAISSE V1` (titre)
2. `## 0. Verdict` — `TRACEABILITY_STATUS: COMPLETE` (à mettre seulement si toutes les règles passent)
3. `## 1. Compteurs` — total findings, P0/P1/P2 split, % avec test, % avec gate, % unmapped
4. `## 2. Matrice principale` — tableau Markdown avec **toutes** les findings
5. `## 3. Findings non mappés (escalation)` — sous-table P0 avec Status=unmapped (vide si zéro)
6. `## 4. Couverture par Plan-ID` — pour chaque PLAN-XX, nombre de findings et liste FK-IDs
7. `## 5. Couverture par Gate` — pour chaque gate, findings impactés
8. `## 6. Procédure de mise à jour` — comment ajouter une nouvelle finding (format + script)

## LIVRABLES DANS `output_codex.json`

```json
{
  "files_to_modify": [
    "reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md",
    "reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.csv",
    "scripts/check-traceability.sh"
  ],
  "implementation_steps": ["..."],
  "code_blocks": [
    { "path": "reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md", "op": "create", "excerpt": "<contenu complet>" },
    { "path": "reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.csv", "op": "create", "excerpt": "<csv complet>" },
    { "path": "scripts/check-traceability.sh", "op": "create", "excerpt": "<bash complet>" }
  ],
  "risks": [],
  "notes": "Compteurs finaux : total=NN, P0=NN, P1=NN, P2=NN, unmapped=NN, with_test=NN%, with_gate=NN%",
  "execution_trace": {
    "delegation": "codex-extension",
    "invariants_considered": []
  }
}
```

## INTERDITS

- Toucher du code produit.
- Cocher un gate `[x] Approved`.
- Inventer un Plan-ID hors `PLAN-00..PLAN-22`.
- Réduire la matrice à un échantillon : il faut **toutes** les findings des rapports source.
- Modifier `AGENTS.md`, `.cursor/routing.md`, super master, master finitions.

## SI BLOCAGE

- Finding non rattachable à un Plan-ID existant → `risks: ["ESCALATION: FK-XXX nécessite nouveau plan ou clarification humaine"]` et `Status: unmapped`.
- Conflit entre rapports (severity divergente) → trancher au plus pessimiste, noter dans `notes` du JSON.
- Source illisible / vide → mention dans `notes` ; ne **pas** halluciner.
