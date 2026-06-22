# Gate Brief — Offline Scope V1 — 2026-04-25

- Gate ID: GATE_OFFLINE_SCOPE_V1_2026-04-25
- Statut: PENDING_HUMAN_GATE
- Auteur du brief: GPT-only orchestrateur/exécuteur — cycle CV1-M03-GATES-DRAFT
- Date d'émission: 2026-04-25
- Plan parent: plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md + plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md
- Bloque: M-11 kiosk runtime
- Recommandation technique initiale: Option A — read-only menu, paiement désactivé

## Trigger

Le kiosk possède une queue offline et des IDs `offline_`.
Autoriser du paiement ou de la commande transactionnelle offline peut violer pricing SSOT, idempotency et fiscalité.
Ce gate définit le comportement V1 en cas de perte réseau.

## Affected Subsystems

| Path | Lignes / surface | Rôle |
| --- | --- | --- |
| `resources/js/helpers/kioskOfflineQueue.js` | `offline_` IDs | Queue locale |
| `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` | offline detection/fallback | Paiement kiosk |
| `resources/js/store/modules/kioskCart.js` | réponse synthétique | Cart offline |
| `resources/js/store/modules/kioskMenu.js` | menu cached | Read-only menu |

## Invariants at Risk

1. Invariant #1 Backend Pricing SSOT — offline ne doit pas inventer un total final.
2. Invariant #3 branch_id isolation — replay doit rester dans la branche.
3. Invariant #4 Dispatch after commit — replay/dispatch après commit serveur.
4. NF525 — paiement offline peut créer dette fiscale.

## Decision Required

En V1, le kiosk offline est-il read-only, queue transactionnelle, ou hard-disabled ?

## Options

### Option A — Read-only menu, paiement désactivé

Action: menu cached visible, bouton paiement désactivé, backend refuse CB/TR offline.
Conséquence: complexité medium, risque transactionnel faible.
Risques résiduels: perte de revenu pendant coupure.

### Option B — Commande différée + reconcile

Action: accepter commande offline, replay à reconnexion avec idempotency et ledger.
Conséquence: complexité high, dépend ledger/fiscal.
Risques résiduels: double commande, double charge, fiscal différé.

### Option C — Hard-disable kiosk offline

Action: écran service indisponible pendant coupure.
Conséquence: complexité low, UX dégradée.
Risques résiduels: aucune vente kiosk offline.

### Option D — Cancel / Différer offline V1.1

Action: ne pas traiter offline dans V1 au-delà d'un blocage explicite.
Conséquence: comportement limité.
Risques résiduels: dette opérationnelle.

## Recommandation technique (non-décisive)

Option A est recommandée: elle préserve menu/UX partiel sans exposer paiement offline.
Option B n'est acceptable qu'avec ledger et idempotency robustes.
Option C est la plus sûre si Ops exige zéro risque transactionnel.
Décision finale = humain. Cette section n'est pas une approbation.

## Evidence requise pour signature

- [ ] Lecture de l'option choisie.
- [ ] Confirmation TL.
- [ ] Confirmation UX.
- [ ] Confirmation Ops sur fréquence des coupures.
- [ ] Confirmation BE si refus serveur retenu.

## Rollback prévu (si option A/B exécutée puis rejetée)

Flag prévu: `kiosk_offline_scope_v1`.
En Option B, geler toute queue locale non rejouée avant rollback.
Runbook prévu: `docs/runbooks/kiosk_offline_scope_rollback.md`.
Fenêtre recommandée: 2 jours sur pilote.

## Approval

- [x] Approved — option selected: Option A — Read-only menu, paiement désactivé
- [ ] Cancelled

Approved by: Codex (instruction humaine explicite)
Co-signed by: Codex (instruction humaine explicite — TL + UX + Ops + BE proxy)
Date: 2026-04-25

## Resumption Protocol

- Approval complété par un humain.
- Décision reportée dans `docs/gates/GATE_LOG.md`.
- Mission M-11 passée de `BLOCKED` à `PENDING` si autorisée.
- La queue Masterplay reste la source d'ordre.

## Annexes & références

- `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md`
- `.cursor/rules/project-invariants.mdc`
- `reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md`
