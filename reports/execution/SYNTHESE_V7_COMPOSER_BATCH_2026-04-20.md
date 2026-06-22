# Synthèse V7 — Composer batch (salve Q + P) — 2026-04-20

## Contexte

Salve **affinement des sentinelles + couverture POS multi-branch**, suite directe V5/V6 :
- Q : vérifier si les events Item/Category méritent l'extension du check 4/6 (réponse : non, ils sont orphelins)
- P : compléter le test V6 #1 avec la dimension scoping multi-branch (POS-9.1.9)

| Salve | Cycle | Type | Verdict |
|---|---|---|---|
| Q | V7 #1 — `P11_INVARIANT_4_OF_6_EXTEND_ITEM_CATEGORY` | Composer / no gate / script + doc | **CLOSED — NO_OP** + découverte |
| P | V7 #2 — `P11_POS_CART_PRUNE_TEST_SCOPED` | Composer / no gate / Vitest | **CLOSED — PASSED** (4/4 ✓, 0 régression) |

## Résultats

### V7 #1 — `P11_INVARIANT_4_OF_6_EXTEND_ITEM_CATEGORY`
- **Modifications app** : aucune
- **Audit des 5 events** : `ItemCreated`, `ItemDeleted`, `CategoryCreated`, `CategoryUpdated`, `CategoryDeleted` — **aucun** n'implémente `ShouldBroadcast` ni `ShouldDispatchAfterCommit`
- **Grep dispatch** : 0 hits dans `app/` pour les 5 events → **événements orphelins** (jamais utilisés dans le code applicatif)
- **Décision** : NO_OP — ne pas ajouter à la regex 4/6 (qui surveille spécifiquement les broadcasts), ne pas étendre le scope V5 #1
- **KI-001** : enrichi d'une nouvelle section documentant cette analyse négative (lignes 23-37)
- **Bonus** : suggestion d'un cycle futur `P11_DEAD_EVENTS_CLEANUP` pour évaluer la suppression des classes orphelines

### V7 #2 — `P11_POS_CART_PRUNE_TEST_SCOPED`
- **Fichier créé** : `tests/js/posCartPruneScoped.spec.js` (104 lignes, 4 tests)
- **Couverture** : scope unset → no persist / scope set → write sous clé scopée correcte / no-op → no write / **isolation 2 branches sans cross-write**
- **Suite Vitest globale** : 423/423 ✓ (56 fichiers, +4 tests vs V6 #1)
- **Pattern** : import direct du store via `_applyPosCartScope` (pattern `posCartScoped.spec.js`, plus propre que la simulation V6 #1)

## Découverte significative V7 #1 — events orphelins

5 classes Event identifiées comme "fantômes" dans le repo :
- `app/Events/ItemCreated.php`
- `app/Events/ItemDeleted.php`
- `app/Events/CategoryCreated.php`
- `app/Events/CategoryUpdated.php`
- `app/Events/CategoryDeleted.php`

Caractéristiques communes :
- N'implémentent ni `ShouldBroadcast` ni `ShouldDispatchAfterCommit`
- Aucun `XxxCreated::dispatch(...)` dans `app/`
- Probablement héritage d'un cycle non finalisé (admin item/category broadcast envisagé mais jamais livré)

**Implications** :
- Sécurité : pas de risque actif (puisque jamais dispatched)
- Maintenance : surface morte = bruit pour les agents/devs futurs
- Action recommandée : cycle `P11_DEAD_EVENTS_CLEANUP` (Composer, ~30 min, lecture des 5 fichiers + grep exhaustif `routes/`, `tests/`, `database/seeders/`, suppression si réellement morts)

## Lesson learned V7

1. **`Vérification d'usage AVANT extension de surveillance`** : avant d'étendre un check d'invariant à un nouveau périmètre, valider que le périmètre est ACTIVEMENT utilisé. V7 #1 aurait pu ajouter 5 noms morts à la regex et causer du bruit. Le `grep -rn dispatch app/` à 0 hits a évité cela.
2. **`Lesson de V6 #1 ajustée`** : le pattern simulation de `posCart.spec.js` (V6 #1) était trop conservateur. Le store `posCart` EST importable directement (cf. `posCartScoped.spec.js` ET maintenant V7 #2). Pour les futurs tests Vitex sur `posCart`, **préférer le pattern import direct** (`import { posCart, _applyPosCartScope }`) au pattern simulation.
3. **`Analyses négatives doivent être documentées`** : un cycle NO_OP qui prouve qu'un risque n'existe pas a la même valeur qu'un cycle qui corrige un bug. Le KI-001 V7 #1 section évite à un futur agent/dev de re-faire la même investigation.

## Statistiques cumulées Composer (V1 + V3 + V4 + V5 + V6 + V7)

| Wave | Cycles | PASSED | NO_OP | PARTIAL | BUG_FOUND | Régressions |
|---|---|---|---|---|---|---|
| V1 | 8 | 8 | 0 | 0 | 0 | 0 |
| V3 | 1 | 1 | 0 | 0 | 0 | 0 |
| V4 | 11 | 9 | 0 | 1 | 1 | 0 |
| V5 | 2 | 2 | 0 | 0 | 0 | 0 |
| V6 | 2 | 2 | 0 | 0 | 0 | 0 |
| V7 | 2 | 1 | 1 | 0 | 0 | 0 |
| **TOTAL** | **26** | **23** | **1** | **1** | **1** | **0** |

## Prochaines étapes (handoff)

### Gates en attente (debloquerait grosse remédiation)
1. **C1-C8** — `docs/gates/GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md`
2. **C9 étendu** — `tasks/execute-2026-04-20/V5_01_P11_DISPATCH_AFTER_COMMIT_REMEDIATION.md`

### Options Composer no-gate (suite cohérente, alignées POS + centralisation)

| Option | Cycle | Bénéfice | Effort | Risque |
|---|---|---|---|---|
| T | `P11_DEAD_EVENTS_CLEANUP` | supprimer/documenter 5 events Item/Category orphelins (réduction surface morte) | ~30 min | bas |
| U | `P11_POS_DINE_IN_FLAG_TEST_EXTEND` | étendre `posDineInFlag.spec.js` aux edge cases (refund, multi-tender) | ~30 min | bas |
| V | `P11_KIOSK_OFFLINE_QUEUE_TEST_EXTEND` | étendre `kioskOfflineQueue.spec.js` aux scénarios reconnexion partielle | ~45 min | bas |
| W | `P11_KI_002_F_VERIFY_15_04` | doc known-issue pour un autre bug ouvert sélectionné (ex: hardware K4 fallback partiel) | ~30 min | bas |
| X | `P11_INVARIANT_DOC_REFRESH` | mettre à jour `tasks/phase9-pos/POS_INVARIANTS_AND_GATES.md §3` pour refléter le hardening V5 #2 + analyse V7 #1 | ~20 min | bas |

### Recommandation orchestrateur

**Option T** (`DEAD_EVENTS_CLEANUP`) est la plus cohérente avec le thème "centralisation/clarté du modèle d'events". Petit effort, valeur immédiate, prépare le terrain pour V5 #1 en réduisant le bruit autour des events.

**Option X** (`INVARIANT_DOC_REFRESH`) est utile gouvernance mais moins prioritaire.
