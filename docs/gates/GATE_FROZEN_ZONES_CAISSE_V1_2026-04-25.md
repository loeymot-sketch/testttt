# Gate Brief — Frozen Zones Caisse V1 — 2026-04-25

- Gate ID: GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25
- Statut: Approved
- Auteur du brief: GPT-only orchestrateur/exécuteur — cycle CV1-M03-GATES-DRAFT
- Date d'émission: 2026-04-25
- Plan parent: plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md + plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md
- Bloque: M-06 POS guards, M-09 branch isolation, M-10 OS/FOS symmetry
- Recommandation technique initiale: Option C — partial allowlist par méthode/surface

## Trigger

La Wave B Caisse V1 doit corriger des chemins qui touchent des fichiers ou surfaces frozen: `OrderService`, `FrontendOrderService`, `PaymentService`, KDS service, routes API et controller frontend order.
Les règles FoodKing interdisent toute édition frozen sans gate humain écrit.
Le plan Masterplay identifie ces ouvertures comme prérequis de M-06, M-09 et M-10.
Ce brief ne libère rien seul; il prépare la décision humaine avant déblocage de la queue.

## Affected Subsystems

| Path | Lignes / surface | Rôle |
| --- | --- | --- |
| `app/Services/OrderService.php` | `changeStatus`, `changePaymentStatus`, order filters | Lifecycle, paiement, branch isolation |
| `app/Services/FrontendOrderService.php` | `finalizePaidKioskOrder`, order filters | Kiosk payment finalization, branch isolation |
| `app/Services/PaymentService.php` | payment/cashback surfaces | Paiement, refunds, ledger |
| `app/Services/KitchenDisplaySystemOrderService.php` | transition KDS | Release cuisine et status machine |
| `routes/api.php` | POS/KDS/frontend routes | Surfaces API sensibles |
| `app/Http/Controllers/Frontend/OrderController.php` | `paymentConfirm` | Confirmation TPE kiosk |

## Invariants at Risk

1. Invariant #1 Backend Pricing SSOT — les chemins paiement ne doivent pas déplacer de logique prix côté frontend.
2. Invariant #3 branch_id isolation — les filtres branch doivent rester stricts.
3. Invariant #4 Dispatch after commit — tout event/job doit rester post-commit.
4. Invariant #5 OS/FOS symmetry — modification d'un service commande implique revue explicite de l'autre.
5. Invariant #6 Frozen Zones — aucune édition sans décision humaine.

## Decision Required

Le tenant FoodKing autorise-t-il l'ouverture des frozen zones Caisse V1, et avec quelle granularité ?

## Options

### Option A — Open all scoped frozen files

Action: autoriser les fichiers listés ci-dessus pour les missions Wave B déclarées.
Conséquence: déblocage rapide, complexité high, surface large à auditer.
Risques résiduels: drift de scope, régression cross-méthode, charge de revue élevée.

### Option B — Refuse frozen opening

Action: maintenir les zones frozen fermées pour V1.
Conséquence: M-06, M-09 et M-10 restent bloquées; V1 ne livre pas ces P0.
Risques résiduels: go-live avec branch/payment/order lifecycle debt connue.

### Option C — Partial allowlist by method/surface

Action: autoriser uniquement les méthodes/surfaces citées par chaque mission, avec note de scope par mission.
Conséquence: complexité medium-high, coordination plus lente, mais blast radius contrôlé.
Risques résiduels: oubli d'une surface nécessaire, gate complémentaire possible.

### Option D — Cancel / Différer Caisse V1.1

Action: arrêter le périmètre Caisse V1 qui touche les frozen zones et replanifier.
Conséquence: livraison V1 réduite; effort business à rechiffrer.
Risques résiduels: perte de continuité et dette P0 reportée.

## Recommandation technique (non-décisive)

Option C est le meilleur compromis technique: elle débloque la Wave B sans ouvrir des fichiers entiers.
Option A n'est acceptable que si l'équipe accepte une revue renforcée après chaque mission.
Option B est cohérente seulement si le business accepte de différer les correctifs P0.
Décision finale = humain. Cette section n'est pas une approbation.

## Evidence requise pour signature

- [ ] Lecture de l'option choisie.
- [ ] Confirmation TL sur la granularité d'ouverture.
- [ ] Confirmation BE owner sur les services commande/paiement.
- [ ] Confirmation QA NF525 si une surface paiement/fiscal est ouverte.
- [ ] Mise à jour de `plans/masterplay/MASTERPLAY_QUEUE.md` après décision.

## Rollback prévu (si option A/C exécutée puis rejetée)

Arrêter les missions dépendantes non commencées.
Revert mission par mission les patches déjà livrés sur frozen zones.
Runbook prévu: `docs/runbooks/caisse_v1_frozen_zones_rollback.md`.
Fenêtre maximale recommandée: 7 jours après première modification frozen.

## Approval

- [x] Approved — option selected: Option C — Partial allowlist by method/surface
- [ ] Cancelled

Approved by: Codex (instruction humaine explicite)
Co-signed by: ___________________ (TL + BE + QA NF525 si paiement/fiscal)
Date: 2026-04-25

## Resumption Protocol

- Approval complété par un humain.
- Décision reportée dans `docs/gates/GATE_LOG.md`.
- Missions M-06, M-09 et M-10 passées de `BLOCKED` à `PENDING` si l'option les autorise.
- La queue Masterplay reste la source d'ordre.

## Annexes & références

- `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md`
- `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md`
- `.cursor/rules/project-invariants.mdc`
- `docs/gates/GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md`
