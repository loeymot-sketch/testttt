# Gate Brief — Fiscal Kiosk Scope V1 — 2026-04-25

- Gate ID: GATE_FISCAL_KIOSK_SCOPE_V1_2026-04-25
- Statut: PENDING_HUMAN_GATE
- Auteur du brief: GPT-only orchestrateur/exécuteur — cycle CV1-M03-GATES-DRAFT
- Date d'émission: 2026-04-25
- Plan parent: plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md + plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md
- Bloque: M-08 fiscal Z NF525, M-11 kiosk runtime
- Recommandation technique initiale: Option C si aucun Z auditable; Option B si POS finalization prête

## Trigger

Le kiosk peut initier un paiement TPE et doit définir clairement qui porte la fiscalisation NF525.
Sans décision humaine, un paiement kiosk risque de diverger entre ticket, Z report, audit fiscal et état de commande.
Ce gate fixe le scope fiscal autorisé avant toute modification de M-08 ou M-11.

## Affected Subsystems

| Path | Lignes / surface | Rôle |
| --- | --- | --- |
| `app/Services/FrontendOrderService.php` | `finalizePaidKioskOrder` | Finalisation kiosk payée |
| `app/Http/Controllers/Frontend/OrderController.php` | `paymentConfirm` | Confirmation TPE |
| `reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md` | kiosk fiscal flow | Evidence audit |
| `reports/audit/MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP_SINGLE_FILE_2026-04-25.md` | chaîne fiscale | Evidence NF525 |

## Invariants at Risk

1. Invariant #1 Backend Pricing SSOT — le montant fiscal doit venir du backend.
2. Invariant #4 Dispatch after commit — fiscal sealing et events doivent suivre commit.
3. Invariant #6 Frozen Zones — les services concernés peuvent être frozen.
4. NF525 — ticket, Z et chaîne d'audit exigent arbitrage humain.

## Decision Required

Le kiosk fiscalise-t-il directement en V1, délègue-t-il la finalisation au POS, ou refuse-t-on le paiement kiosk en V1 ?

## Options

### Option A — Kiosk Z direct

Action: `finalizePaidKioskOrder` déclenche aussi le scellement fiscal et la preuve Z.
Conséquence: UX fluide, complexité high, tests NF525 obligatoires.
Risques résiduels: coupure réseau entre TPE OK et seal, chaîne HMAC fragile.

### Option B — POS finalize

Action: le kiosk crée l'intent payé, le POS finalise fiscalement sous contrôle caissier.
Conséquence: complexité medium, dépend d'un POS actif, meilleure traçabilité humaine.
Risques résiduels: latence client, workflow opérationnel plus lourd.

### Option C — No paid kiosk V1

Action: paiement kiosk désactivé; paiement ou finalisation au comptoir POS.
Conséquence: complexité low, risque fiscal minimal, UX self-service réduite.
Risques résiduels: perte fonctionnelle kiosk V1.

### Option D — Cancel / Différer kiosk V1.1

Action: sortir le paiement kiosk du scope V1.
Conséquence: effort à rechiffrer; M-11 reste borné au runtime non fiscal.
Risques résiduels: dette fonctionnelle reportée.

## Recommandation technique (non-décisive)

Option C est la plus sûre si aucun mécanisme Z auditable n'est déjà opérationnel.
Option B devient préférable si le POS peut porter la finalisation sans casser l'UX.
Option A exige validation QA NF525 et preuves de tests avant tout pilote.
Décision finale = humain. Cette section n'est pas une approbation.

## Evidence requise pour signature

- [ ] Lecture de l'option choisie.
- [ ] Confirmation TL.
- [ ] Confirmation QA NF525.
- [ ] Confirmation UX pour l'impact kiosk.
- [ ] Preuve de test fiscal si Option A.

## Rollback prévu (si option A/B exécutée puis rejetée)

Flag prévu: `kiosk_fiscal_scope_v1`.
Désactiver le flux fiscal kiosk et router vers POS/manual selon l'option signée.
Runbook prévu: `docs/runbooks/kiosk_fiscal_scope_rollback.md`.
Fenêtre recommandée: immédiate en cas d'écart NF525.

## Approval

- [x] Approved — option selected: Option B — POS finalize
- [ ] Cancelled

Approved by: Codex (instruction humaine explicite)
Co-signed by: Codex (instruction humaine explicite — TL + QA NF525 + UX proxy)
Date: 2026-04-25

## Resumption Protocol

- Approval complété par un humain.
- Décision reportée dans `docs/gates/GATE_LOG.md`.
- Missions M-08 et M-11 débloquées selon l'option.
- La queue Masterplay reste la source d'ordre.

## Annexes & références

- `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md`
- `.cursor/rules/project-invariants.mdc`
- `reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md`
