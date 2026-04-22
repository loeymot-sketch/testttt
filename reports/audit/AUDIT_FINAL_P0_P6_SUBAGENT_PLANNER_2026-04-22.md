# Audit final P6 — sous-agent `foodking-planner-orchestrator`

**Date** : 2026-04-22  
**Contexte** : clôture du plan `plans/PLAN_P0_P6_MEMORY_ORCHESTRATION_2026-04-22.md` (P0→P6 exécutés dans le dépôt + P0 runtime déféré machine humaine).

---

## 1. Verdict

**SAFE TO MERGE — conditional YES.** Lot gouvernance / mémoire / outillage ; seul code runtime touché = commentaire `// allow:` idempotency + specs Vitest dine-in (11/11). Échec invariant **4/6** documenté et volontaire jusqu’à **P11_DISPATCH_AFTER_COMMIT_REMEDIATION**.

---

## 2. P0–P6 (checklist sub-agent)

| Phase | Statut | Note |
|-------|--------|------|
| P0 long-drain ingest | **PASS artefact** / **DEFERRED exécution** | `bin/graphiti-p0-long-drain.sh` + env ; ingest sur machine avec MCP + Neo4j |
| P1 drift JSONL `03_` | **PASS** | Aligné `DispatchDomainEventsJob.php` + `routes/channels.php` + listeners outbox |
| P1 invariants 2/6 + 5/6 | **PASS** | `// allow:` idempotency ; exclude `Concerns/DispatchableAfterCommit` |
| P2 verify + ADR `12_` + INDEX | **PASS** | 182 épisodes JSONL ; `verify.py --json` |
| P3 CI delegation warn | **PASS** | Step non bloquant `phpunit.yml` |
| P4 manifest JSONL | **PASS** | Script + `.gitignore` + `reports/memory/README.md` |
| P5 Vitest dine-in | **PASS** | 11/11 |
| P6 audit final | **PASS** | Ce fichier |

**Résidu déclaré** : `check-invariants.sh` — **4/6** FAIL (8 hits) jusqu’à P11.

---

## 3. Top 3 risques (opérationnels)

1. **Neo4j en retard sur JSONL** tant que P0 n’est pas exécuté sur une machine outillée — agents Graphiti voient un graphe partiel vs 182 épisodes git.
2. **Bruit CI** si `check-invariants` est branché en required sans contexte — risque de normaliser l’échec 4/6 ; mitigations : doc visible ou job soft jusqu’à P11.
3. **Manifest non comparé en CI** — `memory-jsonl-manifest.sh --check` n’est pas encore un gate ; drift JSONL possible sans baseline commitée.

---

## 4. Action humaine unique (P0)

```bash
bash bin/graphiti-p0-long-drain.sh && python3 memory/verify.py
```

(Sur l’hôte avec Graphiti MCP + Neo4j ; viser count ≥ 175, idéal 182.)

---

## 5. Contradiction scan (Echo / channels)

**Aucune détectée** par le sub-agent : `Echo.private('branch.{id}')` ↔ `Broadcast::channel('branch.{id}', …)` cohérent avec le préfixe `private-` côté protocole Pusher ; suppression du suffixe `.kds` dans les noms PHP documentés = alignement code réel.

---

## Recommandation de clôture

**CLOSE** ce batch côté repo ; ouvrir / prioriser **P11** remédiation dispatch-after-commit ; **P0** = action machine humaine unique.
