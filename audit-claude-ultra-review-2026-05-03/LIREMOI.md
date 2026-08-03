# LIREMOI — Package Ultra-Review Claude (zéro contexte) — 2026-05-03

## Ton job (humain)

1. Ouvrir une **nouvelle conversation Claude** (extension Claude dans Cursor, app web Claude.ai, ou Claude Code terminal — peu importe le canal).
2. **Activer le MCP Graphiti** dans cette session (le serveur est déjà enregistré dans `~/.cursor/mcp.json` — group_id : `foodking`).
3. **Uploader / attacher** tout le contenu de **ce dossier** (`audit-claude-ultra-review-2026-05-03/`) — c'est le contexte zéro qu'il aura.
4. **Coller** le contenu de `MESSAGE-A-COLLER.md` comme premier message de la conversation.
5. Laisser tourner. Le rapport final sera écrit par Claude dans la conversation (ou directement dans `reports/audit/ULTRA_REVIEW_GLOBAL_CATALOG_TREE_2026-05-03.md` si tu lui dis explicitement de l'écrire en disque).
6. Une fois reçu : reviens ici, donne-moi le rapport, je le digère et je propose Phase β1 (intégration Vue) ou un round de REWORK selon le verdict.

## Structure du package

```
audit-claude-ultra-review-2026-05-03/
├── LIREMOI.md                           ← TU ES ICI
├── MESSAGE-A-COLLER.md                  ← LE MESSAGE À COLLER À CLAUDE
│
├── 00-base-foodking/                    ← TOUTE NOTRE STRUCTURE TECHNIQUE
│   ├── doctrine/                        (AGENTS.md, invariants, ACTIVE_CYCLE)
│   ├── architecture-docs/               (vision, arbre central, ADR composer-stock)
│   ├── db-migrations/                   (12 migrations clés : items, attributes, extras, addons, wizard, stock, branch_avail, source_item_attribute_id)
│   ├── backend-services/
│   │   ├── Composer/                    (5 services : Step, Profile, Template, Diff, Projection)
│   │   ├── Menu/                        (4 services : Projection, PosMenuProjection, Snapshot, AvailabilityService)
│   │   └── Stock/                       (2 services : StockService, ChoiceAvailabilityResolver)
│   ├── backend-models/                  (10 modèles Eloquent)
│   ├── backend-controllers/             (4 controllers admin)
│   ├── backend-requests-resources/      (FormRequests + API Resources)
│   ├── frontend-vue-admin/              (CatalogStudio + 6 composer + stock + 5 items)
│   ├── frontend-vue-kiosk/              (KioskPosWizard + categories + toast + kiosk-steps/)
│   ├── runtime/                         (pos-wizard.js + itemRoutes.js)
│   ├── config/                          (catalog_v15.php — feature flags)
│   ├── tests/                           (PHPUnit + Vitest + Playwright)
│   └── i18n/                            (vide — voir 00-base-foodking/architecture-docs pour la couverture i18n studio)
│
├── 01-design-claude-v2/                 ← LIVRAISON CLAUDE DESIGN COMPLÈTE
│   ├── Catalog Studio.html              (canvas 12+19 artboards)
│   ├── design-canvas.jsx                (12 artboards initiaux)
│   ├── studio-iter1.jsx                 (5 artboards Critiques)
│   ├── studio-iter2.jsx                 (7 artboards Importants)
│   ├── studio-iter3.jsx                 (7 artboards Polish + README v2)
│   ├── studio-screen{1,2,3}.jsx         (composants par écran)
│   ├── studio-data.jsx                  (fixtures fidèles au schéma BDD)
│   ├── studio-extras.jsx                (composants secondaires)
│   ├── tokens.css                       (additions seules --studio-*)
│   └── uploads/                         (assets + brief original soumis à Claude Design)
│
├── 02-plans-reports/                    ← HISTORIQUE & PLANS
│   ├── PLAN_SOURCE_FK_STAGING_MODEL_CORRECTION_2026-05-03.md
│   ├── PLAN_CV1-V2-REMAINING_MISSIONS_2026-05-03.md
│   ├── PLAN_POST_GATE_SOURCE_FK_AND_FINAL_CLOSE_2026-05-03.md
│   ├── RUN_CATALOG_STUDIO_FINAL_DESIGN_HANDOFF_2026-05-03.md
│   ├── CATALOG_STUDIO_AUDIT_AND_REMEDIATION_PLAN_2026-05-03.md
│   ├── SOURCE_FK_TECHNICAL_FEASIBILITY_AUDIT_2026-05-03.md
│   ├── FINAL_AUDIT_CORRECTION_AND_REMAINING_MISSIONS_2026-05-03.md
│   ├── GATE_CV1-WC-T-WC-SOURCE-FK-01_2026-05-03.md
│   └── GATE_LOG.md
│
└── 03-tests-evidence/                   ← PREUVES TESTS LOCAUX
    └── test-results-summary.txt         (Vitest 1054/0/2 + PHPUnit 50/0/2 + Playwright 1 PASS)
```

## Comment Claude doit s'y prendre

Tout est dans `MESSAGE-A-COLLER.md`. Les ordres de lecture, les questions challenge, le format de rapport attendu, les critères de décision. **N'édite pas le message** sauf pour ajuster la mention du group_id Graphiti si tu n'utilises pas `foodking`.

## Si tu uses Claude.ai (web)

Il a une limite de fichiers/upload (~20 fichiers ou ~10 Mo selon plan). Solutions :
- **Option A** (recommandé) : zip le dossier entier et upload le zip (`zip -r audit-package.zip audit-claude-ultra-review-2026-05-03/`).
- **Option B** : upload uniquement les sous-dossiers `01-design-claude-v2/` (4 fichiers .jsx clés) + `00-base-foodking/doctrine/` + `00-base-foodking/architecture-docs/` + `02-plans-reports/RUN_CATALOG_STUDIO_FINAL_DESIGN_HANDOFF_2026-05-03.md`. Claude pourra demander les autres fichiers dans la suite de la conversation.

## Si tu uses Claude extension (Cursor) ou Claude Code terminal

Ils ont accès au filesystem direct. Pas besoin d'upload. Donne-leur juste le chemin absolu du package + colle le message.

## Si tu uses Claude.ai avec MCP Graphiti

Idéal. Claude appellera `search_memory_facts(group_ids=["foodking"])` pour récupérer la mémoire des cycles antérieurs (ADR composer-stock, gates fermés, invariants déjà clarifiés).

## Une fois fini

Supprime ce dossier (3.8 Mo) après le rapport reçu pour pas polluer le repo :

```bash
rm -rf audit-claude-ultra-review-2026-05-03/
```

Le rapport final que Claude produira est l'artéfact à conserver, pas le package d'entrée.
