# Codex — VA-SYS-09 Docs / Runbook / Memory Close — 2026-04-30

TASK_ID: `CENTRAL-SYNC-VA-SYS-FINISHING/VA-SYS-09`

## Verdict

`VA_SYS_09_VERDICT: PASS_DOCS_MEMORY`

VA-SYS-09 ferme le manque documentaire local: le dossier `docs/sync` n'existait pas encore, alors que les missions VA-SYS-06/07/08 avaient valide des pieces critiques de centralisation data/sync. Cette mission cree les runbooks qui relient ces preuves en une vue systeme exploitable avant VA-SYS-10.

Un adversarial read-only reviewer a signale un REWORK legitime apres la premiere passe: les cinq livrables nommes dans le plan n'existaient pas encore, et plusieurs docs legacy pouvaient contredire l'etat VA-SYS-06/08. La passe courante ferme ce rework.

## Documents crees

- `docs/sync/CATALOG_COMPOSER_DATA_FLOW.md`
- `docs/sync/WIZARD_PRODUCT_MODEL.md`
- `docs/sync/STOCK_SYNC_AND_AVAILABILITY.md`
- `docs/sync/API_VS_MCP_DECISION.md`
- `docs/sync/CENTRAL_MANAGEMENT_RUNBOOK.md`

Documents de synthese additionnels:

- `docs/sync/CENTRAL_DATA_SYNC_RUNBOOK_2026-04-30.md`
- `docs/sync/PRODUCT_CATALOG_STOCK_COMPOSER_SYNC_SPEC_2026-04-30.md`
- `docs/sync/VERSION_A_SYNC_VALIDATION_MATRIX_2026-04-30.md`

Docs legacy corrigees/annotees:

- `docs/REALTIME_SETUP.md` ne doit plus faire croire que les core events utilisent direct `ShouldBroadcastNow`.
- `docs/MENU_AVAILABILITY.md` precise que stockable wizard-choice rupture est Version A.
- `docs/MENU_PROJECTIONS.md` precise que POS/Kiosk projections ont avance depuis la fondation V1.
- `docs/operations/QUEUE_TOPOLOGY.md` utilise les commandes actuelles `foodking:outbox:*`.

## Memoire mise a jour

- `memory/episodes/02_architecture_invariants.jsonl`
- `memory/episodes/03_domain_events_sync.jsonl`
- `memory/episodes/11_production_plan.jsonl`
- `memory/episodes/12_decisions_log.jsonl`
- Graphiti episode: `VA-SYS-09 docs runbook memory close`

## Contenu couvert

- Surfaces connectees: Dashboard, POS, Kiosk, KDS, OSS, backend.
- Sources de verite: pricing, catalogue, composer, stock, commandes, realtime, fiscal.
- Flux: modification produit/categorie/photo, publication composer, commande kiosk, commande POS, rupture stock, outbox/realtime.
- Produit/composer: produit pret sans wizard, produit simple avec options, produit compose avec wizard multi-step.
- Stock: rupture complete produit + rupture de choix wizard stockable.
- API vs MCP: API/outbox/realtime restent le protocole runtime; MCP reste outil agent/dev, pas bus caisse/borne.
- Matrice final VA-SYS-10: commandes exactes PHP/Vitest/Playwright/build a relancer.

## Pre-audit adversarial local

Questions attaquees:

1. Est-ce que la doc peut faire croire que hardware est valide? Non: chaque document garde hardware/provider dans UAT separe.
2. Est-ce que MCP est propose comme remplacement runtime API? Non: decision explicite API + WebSocket/outbox.
3. Est-ce que le wizard force tous les produits? Non: produit sans wizard, wizard court et wizard complexe sont separes.
4. Est-ce que la rupture est limitee aux sandwiches? Non: produits vendables et choices stockables sont couverts.
5. Est-ce que la doc masque les missions encore pending? Non: VA-SYS-01..05 et VA-SYS-10 restent visibles dans la matrice.

## Validation

- `git diff --check` scoped docs/memory/report/tasklist: PASS.
- JSONL memory lines: parse check PASS.
- Adversarial reviewer REWORK points: closed by adding exact five docs, canonical runbook, memory episodes, and legacy contradiction notes.
- No product runtime code changed.

## Decision

`VA-SYS-09: CLOSED_DOCS_MEMORY_PASS`

Prochaine mission: `VA-SYS-10 Final massive validation`.
