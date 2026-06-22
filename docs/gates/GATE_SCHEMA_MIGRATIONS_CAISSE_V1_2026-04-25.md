# Gate Brief — Schema Migrations Caisse V1 — 2026-04-25

- Gate ID: GATE_SCHEMA_MIGRATIONS_CAISSE_V1_2026-04-25
- Statut: PENDING_HUMAN_GATE
- Auteur du brief: GPT-only orchestrateur/exécuteur — cycle CV1-M03-GATES-DRAFT
- Date d'émission: 2026-04-25
- Plan parent: plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md + plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md
- Bloque: M-04A, M-05, M-08, M-13
- Recommandation technique initiale: Option A avec rehearsal + backup

## Trigger

Plusieurs missions Wave B peuvent créer ou modifier le schéma.
Les migrations sont hard-gated car elles impactent données prod, rollback, CI et déploiement.
Ce gate doit définir le lot de migrations autorisé et la stratégie de rehearsal.

## Affected Subsystems

| Path | Surface | Rôle |
| --- | --- | --- |
| `database/migrations/**` | `payment_proofs`, `payment_ledger` | Paiement |
| `database/migrations/**` | `order_quotes` | Quote signé |
| `database/migrations/**` | `kitchen_releases` | KDS strict release |
| `database/migrations/**` | `z_reports` status | Fiscal Z |
| `database/migrations/**` | idempotency extension | Paiement/quote |

## Invariants at Risk

1. Invariant #3 branch_id isolation — les clés composites doivent préserver l'isolation.
2. Invariant #4 Dispatch after commit — nouveaux outbox/events peuvent dépendre du schéma.
3. Invariant #6 Frozen Zones — toute migration exige gate humain.
4. Invariant #1 Backend Pricing SSOT — quote/ledger ne doivent pas dériver du frontend.

## Decision Required

Quelles migrations Caisse V1 sont autorisées, dans quel ordre et avec quelle stratégie de rehearsal/rollback ?

## Options

### Option A — All migrations with rehearsal + backup

Action: autoriser le lot complet avec dry-run, backup et plan down/up testé.
Conséquence: complexité high, couvre quote/payment/KDS/fiscal, meilleure cohérence V1.
Risques résiduels: fenêtre DBA/Ops nécessaire, rollback coûteux.

### Option B — Critical subset only

Action: autoriser seulement les migrations nécessaires au chemin choisi, reporter KDS/fiscal non critiques.
Conséquence: complexité medium, V1 partielle.
Risques résiduels: dette schema et features reportées.

### Option C — No migrations V1

Action: interdire tout DDL en V1.
Conséquence: complexité low immédiate; M-04A/M-05/M-08/M-13 restent bloquées ou dégradées.
Risques résiduels: pas de persistance robuste quote/ledger/release.

### Option D — Cancel / Différer V1

Action: replanifier la release autour d'une fenêtre migration future.
Conséquence: décalage planning.
Risques résiduels: perte de momentum et dette ouverte.

## Recommandation technique (non-décisive)

Option A est techniquement la plus cohérente si la fenêtre DBA/Ops est disponible.
Option B est acceptable pour réduire le risque si le périmètre V1 est réduit.
Option C force une V1 fonctionnellement limitée.
Décision finale = humain. Cette section n'est pas une approbation.

## Evidence requise pour signature

- [ ] Lecture de l'option choisie.
- [ ] Confirmation TL.
- [ ] Confirmation DBA.
- [ ] Confirmation Ops sur fenêtre et backup.
- [ ] Liste finale des migrations autorisées.

## Rollback prévu (si option A/B exécutée puis rejetée)

Chaque migration doit avoir stratégie rollback ou décision irreversible documentée.
Runbook prévu: `docs/runbooks/schema_migrations_caisse_v1_rollback.md`.
Rehearsal staging obligatoire avant prod.
Fenêtre recommandée: à définir par DBA/Ops.

## Approval

- [x] Approved — option selected: Option A — All migrations with rehearsal + backup
- [ ] Cancelled

Approved by: Codex (instruction humaine explicite)
Co-signed by: Codex (instruction humaine explicite — TL + BE owner + DBA + Ops proxy)
Date: 2026-04-25

## Resumption Protocol

- Approval complété par un humain.
- Décision reportée dans `docs/gates/GATE_LOG.md`.
- Missions M-04A/M-05/M-08/M-13 débloquées selon l'option.
- La queue Masterplay reste la source d'ordre.

## Annexes & références

- `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md`
- `.cursor/rules/project-invariants.mdc`
- `reports/audit/MEGA_PLAN_READINESS_GAP_ANALYSIS_CAISSE_POS_KIOSK_KDS_2026-04-25.md`
