# Handoff compressé — Codex (GPT-only) Caisse V1 Masterplay → demande d’ultra-review Claude

**Généré** : alimentation pour `bash scripts/foodking-claude-orchestrate.sh audit` (une lecture, pas l’historique de chat).  
**SSOT queue** : `plans/masterplay/MASTERPLAY_QUEUE.md` (vérifier les statuts en temps réel ; ce fichier est un snapshot sémantique).

## 1) Mode d’exécution (tel que mené)

- **GPT / Codex CLI only** : pas d’appel audit Claude terminal pendant les runs décrits ; audits **GPT** (`codex:final-audit`, self-audit) comme second avis.
- Ajustements **orchestrateur** : `FOODKING_GPT_ONLY=1` ; final audit ne suppose plus PASS Claude ; parsing explicite de `GPT_FINAL_AUDIT_VERDICT: PASS` ; `MASTERPLAY_STOP_ON_REWORK=1` pour ne pas enchaîner en aveugle sur REWORK.
- **Graphiti** : certaines passes Codex ont tenté MCP (échec/cancel) — l’exécution a continué (documenter le risque mémoire inter-sessions).

## 2) Décisions humaines gates (figées côté trace)

- **GATE_PAYMENT_LEDGER** : *Option B — Restricted pilot* (exclut ledger full → **M-04A BLOCKED** par design).
- **GATE_FROZEN_ZONES** : *Option C — Partial allowlist by method/surface*.
- Puis lot Wave B : options type **PAYMENT_PROP=A, SCHEMA=A, FISCAL=B, KDS=B, OFFLINE=A, WEB=B, STRIPE=B** (signées côté session Codex) — vérifier cohérence avec `docs/gates/GATE_LOG.md` et briefs.

## 3) Périmètre livré (missions CLOSED, synthèse)

| Mission | Thème | Notes |
|--------|--------|--------|
| **M-03** | 8 briefs gates + `GATE_LOG` | Wave B conditionnée aux signatures. |
| **M-01** | Matrice trace (MD/CSV), checks | REWORK → résolu GPT. |
| **M-20** | Runbooks | REWORK (Horizon) → résolu. |
| **M-09** | `branch_id` isolation | Services/contrôleurs + tests ciblés. |
| **M-04B** | Paiement pilote restreint | `PaymentService`, `PaymentController`, `config/payment.php`, tests. |
| **M-06** | Garde-fous revenu POS / paiement | Reworks itérés (self-audit NEEDS_FIX → correctifs) : authority backend, `paymentConfirm`, idempotence, tests. |
| **M-05** | Quote scellée / consommée | Order quote au commit (OS/FOS, POS/kiosk) — reworks jusqu’au PASS final GPT. |
| **M-08** | Fiscal Z / NF525 | Sentinels, tests fiscaux ; REWORKs sur allowlist/scope (sentinel hors allowlist) puis PASS. |
| **M-07** | KDS release | `isReleasedToKitchen`, listing payé/exception cash POS — REWORK → PASS. |
| **M-10** | Symétrie OrderService / FrontendOrderService | CLOSED. |

**Artefacts** : nombreux `reports/audit/GPT_AUDIT_*.md`, `missions/**/output_codex.json`, traces `FOODKING_GPT_ONLY` quand applicables.

## 4) État queue (indicatif — lire le fichier table pour vérité)

D’après dernière narration + table :

- **Wave A** : en grande partie **CLOSED**.
- **Wave B** : beaucoup **CLOSED** (M-05, M-06, M-07, M-08, M-09, M-10, M-04B, …).
- **M-17 (WEB/STRIPE scope)** : signalé **EXECUTED** (implémentation faite, audit final / clôture à valider côté repo).
- **M-13 (migrations safety)** : **PENDING** (schéma gate A) — prochaine grosse brique.
- **M-11 (kiosk runtime)** : **BLOCKED** (dépend offline+fiscal+policy).
- **M-14, M-15, M-21b, M-22** : **BLOCKED** par DAG (M-13, M-15 deps, etc.).

## 5) Risques transverses à auditer (pour Claude)

1. **Symétrie OS/FOS** après vagues de patchs (M-05, M-06, M-09, M-10) — toute zone non recouverte par tests d’intégration.
2. **Gouvernance gates** : options signées en chat vs `GATE_LOG` + briefs (pas de self-approbation humaine par un modèle — vérifier conformité `human-gates.mdc`).
3. **Stop-on-rework + runner** : un REWORK M17 ou M-13 non clos pourrait laisser la queue incohérente.
4. **Dette** : Pusher/ws, `verify:boucle` exit 1 parfois non diagnostiqué — ne doit pas masquer un échec produit.
5. **Cohérence V1** : caisse, borne, KDS, web, fiscal — scénarios bout-en-bout manquants si campagne E2E non lancée.

## 6) Demande explicite pour l’audit terminal (Claude)

Produire un **plan d’orchestration global** (pas du code) pour :

- **P0/P1** restants jusqu’à « Caisse V1 opérationnelle intégrée » (POS + kiosk + KDS + web scope + ops).
- Découper **ce que Codex peut exécuter seul** (fichiers allowlist, mission `input.json`, preuves tests) vs **ce qui requiert humain** (signatures, staging DB, clés, prod).
- **Discipline** pour Codex : ordre DAG, `MASTERPLAY_DISCIPLINE`, pas d’expansion de scope, double audit, symétrie OS/FOS, invariants (prix, `OrderStatus`, `branch_id`, commit-before-dispatch).
- **Vagues** : rôle Wave A (terminée) vs Wave B (reliquat) ; ce qui doit être **séquentiel** vs parallélisable.
- Scénarios critiques : quote → commande, paiement pilote, Z fiscal, KDS, offline kiosk, web/stripe, rollout.

---

*Fin du handoff — l’orchestrateur terminal doit s’appuyer sur ce fichier + `MASTERPLAY_QUEUE.md` + invariants `AGENTS.md`.*
