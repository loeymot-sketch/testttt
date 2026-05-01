# Gates à Signer — Caisse V1 Masterplay — 2026-04-25

Statut généré après clôture GPT-only de `CV1-M03-GATES-DRAFT`.

## Synthèse

Wave A est complète côté queue. Wave B ne doit pas démarrer tant que les gates humains requis ne sont pas signés dans les briefs concernés et tracés dans `docs/gates/GATE_LOG.md`.

## Checklist de signature

| Gate | Brief | Statut actuel | Bloque | Décision attendue |
| --- | --- | --- | --- | --- |
| GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25 | `docs/gates/GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25.md` | PENDING_HUMAN_GATE | M-09, M-06, M-10 | Option A/B/C/D sur ouverture frozen |
| GATE_FISCAL_KIOSK_SCOPE_V1_2026-04-25 | `docs/gates/GATE_FISCAL_KIOSK_SCOPE_V1_2026-04-25.md` | PENDING_HUMAN_GATE | M-08, M-11 | Option A/B/C/D sur fiscalisation kiosk |
| GATE_PAYMENT_LEDGER_V1_2026-04-25 | `docs/gates/GATE_PAYMENT_LEDGER_V1_2026-04-25.md` | PENDING_HUMAN_GATE | M-04A ou M-04B | Choix exclusif ledger full vs pilot restrict |
| GATE_KDS_BUMP_AUTHORITY_V1_2026-04-25 | `docs/gates/GATE_KDS_BUMP_AUTHORITY_V1_2026-04-25.md` | PENDING_HUMAN_GATE | M-07 | Option KDS authority / expected_status |
| GATE_SCHEMA_MIGRATIONS_CAISSE_V1_2026-04-25 | `docs/gates/GATE_SCHEMA_MIGRATIONS_CAISSE_V1_2026-04-25.md` | PENDING_HUMAN_GATE | M-05, M-04A, M-08, M-13 | Lot de migrations autorisé |
| GATE_OFFLINE_SCOPE_V1_2026-04-25 | `docs/gates/GATE_OFFLINE_SCOPE_V1_2026-04-25.md` | PENDING_HUMAN_GATE | M-11 | Scope offline kiosk |
| GATE_WEB_PAYMENT_SCOPE_V1_2026-04-25 | `docs/gates/GATE_WEB_PAYMENT_SCOPE_V1_2026-04-25.md` | PENDING_HUMAN_GATE | M-17 | Web payment on/off V1 |
| GATE_STRIPE_CENTS_ACTIVE_2026-04-25 | `docs/gates/GATE_STRIPE_CENTS_ACTIVE_2026-04-25.md` | PENDING_HUMAN_GATE | M-17 | Stripe actif prod et fix cents |

## Gates déjà existants qui restent bloquants

| Gate | Brief | Statut actuel | Bloque |
| --- | --- | --- | --- |
| GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20 | `docs/gates/GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md` | PENDING_HUMAN_GATE | frozen P0 historiques |
| GATE_PAYMENT_PROP_MUTATION_2026-04-26 | `docs/gates/GATE_PAYMENT_PROP_MUTATION_2026-04-26.md` | PENDING_HUMAN_GATE | M-06, M-21b |

## Déblocage Wave B

Après signature humaine:

1. Compléter le bloc `Approval` dans chaque brief signé.
2. Mettre à jour la ligne correspondante dans `docs/gates/GATE_LOG.md`.
3. Passer les missions autorisées de `BLOCKED` à `PENDING` dans `plans/masterplay/MASTERPLAY_QUEUE.md`, en respectant le DAG.
4. Relancer `bash scripts/run-masterplay.sh` en mode GPT-only.

## Ordre DAG post-signature

1. M-09 si frozen signé.
2. M-06 après M-09 + frozen/payment prop mutation.
3. M-05 si schema signé.
4. M-04A ou M-04B selon gate ledger.
5. M-08 si fiscal + schema signés.
6. M-07 si kds bump signé.
7. M-10 après M-06 + M-09.
8. M-11 si offline + fiscal signés.
9. M-17 si web + stripe signés.
10. M-13 si schema signé.
11. M-14 après M-13.
12. M-15 après M-04*, M-08, M-14.
13. M-21b si payment prop mutation signé.
14. M-22 après M-14 + M-15.
