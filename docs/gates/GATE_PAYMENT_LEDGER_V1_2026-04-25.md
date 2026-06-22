# Gate Brief — Payment Ledger V1 — 2026-04-25

- Gate ID: GATE_PAYMENT_LEDGER_V1_2026-04-25
- Statut: Approved
- Auteur du brief: GPT-only orchestrateur/exécuteur — cycle CV1-M03-GATES-DRAFT
- Date d'émission: 2026-04-25
- Plan parent: plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md + plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md
- Bloque: choix M-04A ou M-04B
- Recommandation technique initiale: Option B — restricted pilot, sauf exigence business de ledger complet

## Trigger

Caisse V1 doit choisir entre un ledger paiement complet et un pilote restreint.
Le choix impacte migrations, `PaymentService`, payment proof, fiscalité, idempotency et rollback.
M-04A et M-04B sont exclusifs; aucun des deux ne doit partir sans décision humaine.

## Affected Subsystems

| Path | Surface | Rôle |
| --- | --- | --- |
| `app/Services/PaymentService.php` | payment/refund/cashback | Paiement et ledger |
| `database/migrations/**` | `payment_proofs`, `payment_ledger` si Option A | Persistance financière |
| `app/Http/Controllers/Frontend/OrderController.php` | payment confirm | Entrée TPE/kiosk |
| `reports/audit/MEGA_RAPPORT_FINAL_DISPUTE_CAISSE_POS_KIOSK_KDS_2026-04-25.md` | PaymentProof | Evidence audit |

## Invariants at Risk

1. Invariant #1 Backend Pricing SSOT — le ledger ne doit pas dépendre de totaux frontend.
2. Invariant #3 branch_id isolation — toute preuve paiement doit rester branch-scoped.
3. Invariant #4 Dispatch after commit — événements paiement après commit uniquement.
4. Invariant #6 Frozen Zones — payment service et migrations sont gated.

## Decision Required

Caisse V1 livre-t-elle un ledger paiement complet ou un pilote restreint sans ledger complet ?

## Options

### Option A — Ledger full

Action: créer tables de preuves/ledger, idempotency callbacks, state machine paiement.
Conséquence: complexité high, environ deux semaines, migrations et tests Feature requis.
Risques résiduels: régression fiscal/paiement, coût de migration, rollback plus lourd.

### Option B — Restricted pilot

Action: refuser serveur les paiements hors pilote, journaliser les attempts, limiter surfaces.
Conséquence: complexité medium, pas de ledger complet, dette V1.1 assumée.
Risques résiduels: périmètre paiement réduit, dette technique future.

### Option C — Cancel / Différer paiement V1.1

Action: retirer les changements paiement de V1.
Conséquence: complexité low immédiate, mais V1 sans garanties paiement élargies.
Risques résiduels: produit incomplet et replanification nécessaire.

## Recommandation technique (non-décisive)

Option B est la plus sûre pour une finition V1: elle limite le risque tout en fermant les chemins dangereux.
Option A est justifiée seulement si paiements larges sont indispensables au go-live.
Option C est cohérente si fiscal/NF525 impose un report global.
Décision finale = humain. Cette section n'est pas une approbation.

## Evidence requise pour signature

- [ ] Lecture de l'option choisie.
- [ ] Confirmation TL.
- [ ] Confirmation BE owner paiement.
- [ ] Confirmation QA NF525 si ledger complet.
- [ ] Confirmation DBA si migrations Option A.

## Rollback prévu (si option A/B exécutée puis rejetée)

Flag prévu: `payment_ledger_v1_mode`.
Option A exige rollback migrations contrôlé; Option B exige désactivation du pilote.
Runbook prévu: `docs/runbooks/payment_ledger_v1_rollback.md`.
Fenêtre recommandée: 2 jours après activation pilote.

## Approval

- [x] Approved — option selected: Option B — Restricted pilot
- [ ] Cancelled

Approved by: Codex (instruction humaine explicite)
Co-signed by: ___________________ (TL + BE owner + QA NF525 + DBA si Option A)
Date: 2026-04-25

## Resumption Protocol

- Approval complété par un humain.
- Décision reportée dans `docs/gates/GATE_LOG.md`.
- Débloquer M-04A ou M-04B, jamais les deux.
- La queue Masterplay reste la source d'ordre.

## Annexes & références

- `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md`
- `.cursor/rules/project-invariants.mdc`
- `reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md`
