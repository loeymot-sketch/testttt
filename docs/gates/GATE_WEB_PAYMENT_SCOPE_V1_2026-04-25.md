# Gate Brief — Web Payment Scope V1 — 2026-04-25

- Gate ID: GATE_WEB_PAYMENT_SCOPE_V1_2026-04-25
- Statut: PENDING_HUMAN_GATE
- Auteur du brief: GPT-only orchestrateur/exécuteur — cycle CV1-M03-GATES-DRAFT
- Date d'émission: 2026-04-25
- Plan parent: plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md + plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md
- Bloque: M-17 web/Stripe scope
- Recommandation technique initiale: Option B — web payment off V1 sauf exigence business

## Trigger

Le paiement web public expose un risque si une URL raw id permet de payer ou consulter une commande sans token signé.
Inclure web payment en V1 exige un PaymentIntent signé, TTL court, branch_id check et stratégie Stripe.
Ce gate décide si ce scope est inclus ou différé.

## Affected Subsystems

| Path | Surface | Rôle |
| --- | --- | --- |
| `/payment/{order}/pay` | route publique | Paiement web |
| PaymentIntent signé | à créer si Option A | HMAC/TTL/branch guard |
| `docs/gates/GATE_STRIPE_CENTS_ACTIVE_2026-04-25.md` | gate dépendant | Stripe |
| `reports/audit/MEGA_PLAN_READINESS_GAP_ANALYSIS_CAISSE_POS_KIOSK_KDS_2026-04-25.md` | web payment | Evidence |

## Invariants at Risk

1. Invariant #1 Backend Pricing SSOT — montant web ne doit pas être manipulable.
2. Invariant #3 branch_id isolation — accès inter-branches interdit.
3. Invariant #4 Dispatch after commit — capture/events post-commit.
4. Invariant #6 Frozen Zones — routes paiement publiques sensibles.

## Decision Required

Le paiement web public est-il inclus dans Caisse V1 ou désactivé/reporté ?

## Options

### Option A — Web payment actif en V1

Action: sécuriser via PaymentIntent signé, TTL, branch_id check et tests.
Conséquence: complexité high, surface sécurité publique.
Risques résiduels: attaque token/link, dépendance Stripe si actif.

### Option B — Web payment off V1

Action: route 404/503 ou feature off; sujet reporté V1.1.
Conséquence: complexité low, risque sécurité réduit.
Risques résiduels: impact si clients utilisent déjà l'URL.

### Option C — Cancel / Décision V1.x ultérieure

Action: sortir explicitement web payment de V1.
Conséquence: aucun engagement web payment.
Risques résiduels: replan produit requis.

## Recommandation technique (non-décisive)

Option B est recommandée sauf preuve business d'usage web payment en production.
Option A exige aussi une décision claire sur Stripe cents.
Option C est acceptable si le produit confirme que web payment n'est pas V1.
Décision finale = humain. Cette section n'est pas une approbation.

## Evidence requise pour signature

- [ ] Lecture de l'option choisie.
- [ ] Confirmation TL.
- [ ] Confirmation Product.
- [ ] Confirmation BE owner.
- [ ] Evidence analytics usage `/payment/{order}/pay` si disponible.

## Rollback prévu (si option A/B exécutée puis rejetée)

Flag prévu: `web_payment_v1_enabled`.
Retour immédiat à 404/503 si risque sécurité.
Runbook prévu: `docs/runbooks/web_payment_scope_v1_rollback.md`.
Fenêtre recommandée: 2 jours après pilote.

## Approval

- [x] Approved — option selected: Option B — Web payment off V1
- [ ] Cancelled

Approved by: Codex (instruction humaine explicite)
Co-signed by: Codex (instruction humaine explicite — TL + Product + BE owner proxy)
Date: 2026-04-25

## Resumption Protocol

- Approval complété par un humain.
- Décision reportée dans `docs/gates/GATE_LOG.md`.
- Mission M-17 passée de `BLOCKED` à `PENDING` selon l'option.
- La queue Masterplay reste la source d'ordre.

## Annexes & références

- `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md`
- `.cursor/rules/project-invariants.mdc`
- `docs/gates/GATE_STRIPE_CENTS_ACTIVE_2026-04-25.md`
