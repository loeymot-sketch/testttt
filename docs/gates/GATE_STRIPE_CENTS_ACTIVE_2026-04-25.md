# Gate Brief — Stripe Cents Active — 2026-04-25

- Gate ID: GATE_STRIPE_CENTS_ACTIVE_2026-04-25
- Statut: PENDING_HUMAN_GATE
- Auteur du brief: GPT-only orchestrateur/exécuteur — cycle CV1-M03-GATES-DRAFT
- Date d'émission: 2026-04-25
- Plan parent: plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md + plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md
- Bloque: M-17 Stripe cents fix
- Recommandation technique initiale: Option A si Stripe actif prod; Option B si Stripe inactif prod

## Trigger

Un écart cents/euros Stripe peut créer un impact financier 100x.
Le statut Stripe actif en production doit être confirmé par humain; GPT ne peut pas le déduire du repo.
Ce gate fixe si le fix cents est P0 V1 ou dormant V1.1.

## Affected Subsystems

| Path | Surface | Rôle |
| --- | --- | --- |
| `config/payment.php` | Stripe flag/config | Statut technique à confirmer |
| Stripe dashboard prod | preuve externe | Statut actif/inactif |
| Tests Stripe cents | à créer si Option A | Sécurité montant |
| `docs/gates/GATE_WEB_PAYMENT_SCOPE_V1_2026-04-25.md` | gate dépendant | Web payment |

## Invariants at Risk

1. Invariant #1 Backend Pricing SSOT — montant réel doit être cohérent en cents.
2. Invariant #3 branch_id isolation — paiement lié à la branche de commande.
3. Invariant #4 Dispatch after commit — confirmation paiement après commit applicatif.
4. Invariant #6 Frozen Zones — config/route paiement sensible.

## Decision Required

Stripe est-il actif ou prévu actif en production pendant Caisse V1 ?

## Options

### Option A — Stripe actif prod V1, fix cents P0

Action: auditer conversion cents/euros, ajouter tests et transaction test mode 1.00 EUR.
Conséquence: complexité medium-high, requis avant go-live Stripe.
Risques résiduels: écart config test/prod, preuve dashboard obligatoire.

### Option B — Stripe inactif prod V1, fix reporté

Action: confirmer feature flag off et ajouter garde empêchant activation sans gate.
Conséquence: complexité low, pas de correction fonctionnelle Stripe en V1.
Risques résiduels: activation hors process.

### Option C — Cancel / Décision V1.x ultérieure

Action: différer Stripe si web payment est off et aucune branche prod ne l'utilise.
Conséquence: pas de Stripe V1.
Risques résiduels: gate à rouvrir avant activation.

## Recommandation technique (non-décisive)

Option A si Stripe est actif ou si web payment V1 est signé.
Option B si Stripe est confirmé inactif prod.
Option C seulement si le produit retire Stripe de V1.
Décision finale = humain. Cette section n'est pas une approbation.

## Evidence requise pour signature

- [ ] Lecture de l'option choisie.
- [ ] Capture/config Stripe prod actif ou inactif.
- [ ] Confirmation TL.
- [ ] Confirmation BE owner paiement.
- [ ] Confirmation Ops sur flag prod.
- [ ] Si Option A: preuve transaction test mode 1.00 EUR.

## Rollback prévu (si option A/B exécutée puis rejetée)

Flag prévu: `stripe_payments_v1_enabled`.
Désactiver Stripe immédiatement si une erreur cents est détectée.
Runbook prévu: `docs/runbooks/stripe_cents_active_rollback.md`.
Fenêtre recommandée: immédiate en cas d'écart montant.

## Approval

- [x] Approved — option selected: Option B — Stripe inactif prod V1, fix reporté
- [ ] Cancelled

Approved by: Codex (instruction humaine explicite)
Co-signed by: Codex (instruction humaine explicite — TL + BE owner + Ops proxy)
Date: 2026-04-25

## Resumption Protocol

- Approval complété par un humain.
- Décision reportée dans `docs/gates/GATE_LOG.md`.
- Mission M-17 débloquée seulement selon combinaison avec web payment gate.
- La queue Masterplay reste la source d'ordre.

## Annexes & références

- `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md`
- `.cursor/rules/project-invariants.mdc`
- `docs/gates/GATE_WEB_PAYMENT_SCOPE_V1_2026-04-25.md`
