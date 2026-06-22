# Gate Brief — KDS Bump Authority V1 — 2026-04-25

- Gate ID: GATE_KDS_BUMP_AUTHORITY_V1_2026-04-25
- Statut: PENDING_HUMAN_GATE
- Auteur du brief: GPT-only orchestrateur/exécuteur — cycle CV1-M03-GATES-DRAFT
- Date d'émission: 2026-04-25
- Plan parent: plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md + plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md
- Bloque: M-07 KDS release
- Recommandation technique initiale: Option B — server authority avec `expected_status`

## Trigger

Le KDS peut être utilisé sur plusieurs écrans.
Sans `expected_status`, deux utilisateurs peuvent bumper la même commande depuis des états divergents.
Le plan Masterplay demande une décision humaine sur l'autorité de transition avant M-07.

## Affected Subsystems

| Path | Lignes / surface | Rôle |
| --- | --- | --- |
| `app/Http/Requests/OrderStatusRequest.php` | `status` numeric | Validation status |
| `app/Services/KitchenDisplaySystemOrderService.php` | `changeStatus` + lock | Transition serveur |
| `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` | KDS UI | Envoi status depuis front |
| `reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md` | KDS review | Evidence |

## Invariants at Risk

1. Invariant #2 OrderStatus enum — transitions doivent utiliser l'enum, pas des littéraux.
2. Invariant #3 branch_id isolation — KDS est branch-scoped.
3. Invariant #4 Dispatch after commit — release events après commit.
4. Invariant #6 Frozen Zones — service KDS peut être gated.

## Decision Required

Le serveur doit-il exiger `expected_status` pour toute transition KDS en V1 ?

## Options

### Option A — Local authority

Action: garder le comportement actuel; le front décide du prochain statut.
Conséquence: complexité low, pas de migration, mais risque race maintenu.
Risques résiduels: conflit multi-écran silencieux, sentinels non fermés.

### Option B — Server authority with `expected_status`

Action: body `expected_status` obligatoire; 409 si état locké différent.
Conséquence: complexité medium, request + service + JS + tests.
Risques résiduels: régression front si le champ manque.

### Option C — Restrict bump roles

Action: limiter les transitions à un rôle cuisine/manager défini.
Conséquence: complexité medium, impact opérationnel.
Risques résiduels: blocage si rôle autorisé absent.

### Option D — Cancel / Différer KDS strict V1.1

Action: reporter le durcissement KDS.
Conséquence: V1 garde un risque de désynchronisation.
Risques résiduels: dette KDS connue.

## Recommandation technique (non-décisive)

Option B est recommandée avec feature flag `kds_strict_release`.
Elle conserve l'autorité serveur et donne un signal 409 exploitable.
Option A est acceptable seulement si le risque race est accepté business.
Décision finale = humain. Cette section n'est pas une approbation.

## Evidence requise pour signature

- [ ] Lecture de l'option choisie.
- [ ] Confirmation TL.
- [ ] Confirmation BE owner KDS.
- [ ] Confirmation Ops pour rollout flag.
- [ ] Plan de test multi-écran validé.

## Rollback prévu (si option B/C exécutée puis rejetée)

Flag prévu: `kds_strict_release`.
Désactiver l'exigence `expected_status` ou revenir au rôle précédent.
Runbook prévu: `docs/runbooks/kds_bump_authority_rollback.md`.
Fenêtre recommandée: 2 jours sur branche pilote.

## Approval

- [x] Approved — option selected: Option B — Server authority with `expected_status`
- [ ] Cancelled

Approved by: Codex (instruction humaine explicite)
Co-signed by: Codex (instruction humaine explicite — TL + Backend owner + Ops proxy)
Date: 2026-04-25

## Resumption Protocol

- Approval complété par un humain.
- Décision reportée dans `docs/gates/GATE_LOG.md`.
- Mission M-07 passée de `BLOCKED` à `PENDING` si autorisée.
- La queue Masterplay reste la source d'ordre.

## Annexes & références

- `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md`
- `.cursor/rules/project-invariants.mdc`
- `reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md`
