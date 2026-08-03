# Execute Brief — CV1-M04A-PAYMENT-LEDGER-FULL (M-04A)

## Statut: EXÉCUTION BLOQUÉE par défaut

Cette mission cible le **Payment Ledger full** (Option A). Tant que le produit / l’humain n’a **pas** sélectionné **Option A** et signé **`GATE_PAYMENT_LEDGER_V1`** (+ gates schéma / frozen associés), **ne lance pas** `npm run codex:complex -- CV1-M04A-PAYMENT-LEDGER-FULL`.

La branche retenue en parallèle est **M-04B** (pilote restreint) — voir `missions/CV1-M04B-PAYMENT-PILOT-RESTRICT/`.

## Déblocage (humain)

1. Signer le brief `docs/gates/GATE_PAYMENT_LEDGER_V1_2026-04-25.md` — **Option A (full ledger)**.
2. Obtenir l’approbation écrite des migrations / frozen (`GATE_SCHEMA_MIGRATIONS_*`, `GATE_FROZEN_ZONES_*` selon périmètre).
3. Mettre à jour `missions/CV1-M04A-PAYMENT-LEDGER-FULL/input.json` : `execution_blocked: false`, preuve de décision (réf. gate + date) dans le champ documenté.
4. Mettre à jour `plans/masterplay/MASTERPLAY_QUEUE.md` : statut M-04A = prêt à exécuter (selon discipline masterplay).
5. `bash scripts/agent-activity-log.sh start codex-extension CV1-M04A-PAYMENT-LEDGER-FULL execute "<allowlist CSV>"` puis exécuter la boucle officielle.

## Périmètre (quand débloqué)

Implémenter **uniquement** ce qui est dans `input.json` → `allowlist` (aligné sur le plan parent M-04A). Aucun fichier hors liste. Respect strict des invariants FoodKing (pricing backend SSOT, `branch_id`, dispatch après commit, symétrie OS/FOS si touchée).

## Validation (quand débloqué)

Commandes `mandatory_tests` du `input.json` + preuve dans `reports/post_execute_latest.log` + auto-audit GPT + audits du cycle.
