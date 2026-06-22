# REWORK pendants — Wave A (corrigés GPT-only le 2026-04-25)

## CV1-M01-TRACEABILITY-MATRIX (statut courant: CLOSED, rework: RESOLVED_GPT_PASS)
**Verdict audit Claude** : `reports/audit/AUDIT_CV1-M01-TRACEABILITY-MATRIX.md`

**Findings** :
- FIND-07 absent de la matrice
- AUDIT_POS:T-010 et AUDIT_POS:T-026 non cités dans `traceability_findings`
- `output_codex.json` reste un placeholder (l'extracteur a écrit un template vide à l'époque, avant le fix)

**Fix appliqué GPT-only** :
- `FIND-07` ajouté via `FK-102`.
- `AUDIT_POS:T-026` ajouté à `FK-036`.
- `AUDIT_POS:T-010` ajouté à `FK-100`.
- `missions/CV1-M01-TRACEABILITY-MATRIX/output_codex.json` remplacé par un artefact réel.
- Audit: `reports/audit/GPT_AUDIT_CV1-M01-TRACEABILITY-MATRIX_REWORK_FIX_2026-04-25.md`.

**Bloque-t-il la suite ?** : NON — `is_closed("CV1-M01")` retourne true (statut CLOSED), donc M-02 et M-03 peuvent démarrer.

---

## CV1-M20-RUNBOOKS-SKELETON (statut courant: CLOSED, rework: RESOLVED_GPT_PASS)
**Verdict audit Claude** : `reports/audit/AUDIT_CV1-M20-RUNBOOKS-SKELETON.md`

**Findings** : 2 fichiers citent `php artisan horizon:status` alors que Laravel Horizon est absent du composer.json.

**Fix appliqué GPT-only** :
- `reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md` : Horizon remplacé par observation process manager / dashboard de déploiement.
- `reports/runbooks/RUNBOOK_INDEX_2026-04-25.md` : références Horizon retirées.
- `missions/CV1-M20-RUNBOOKS-SKELETON/output_codex.json` mis à jour.
- Audit: `reports/audit/GPT_AUDIT_CV1-M20-RUNBOOKS-SKELETON_REWORK_FIX_2026-04-25.md`.

**Bloque-t-il la suite ?** : NON.
