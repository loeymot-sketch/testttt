# Synthèse V6 — Composer batch (salve N + O) — 2026-04-20

## Contexte

Salve **complément de couverture + gouvernance**, suite naturelle de V5 K+L :
- N : combler le trou de couverture frontend de la mutation `posCart/pruneUnavailable` livrée en V4 #2 sans test associé.
- O : formaliser le bug `dispatch-after-commit` (V4 #8 + V5 #2 + V5 #3) en `KNOWN_ISSUE` versionné, pour onboarding/ops/gate.

| Salve | Cycle | Type | Verdict |
|---|---|---|---|
| N | V6 #1 — `P11_POS_CART_PRUNE_TEST` | Composer / no gate / Vitest only | **CLOSED — PASSED** (6/6 ✓, 0 régression) |
| O | V6 #2 — `P11_DISPATCH_BUG_KNOWN_ISSUE_DOC` | Composer / no gate / docs only | **CLOSED — PASSED** + 1 découverte significative |

## Résultats

### V6 #1 — `P11_POS_CART_PRUNE_TEST`
- **Fichier créé** : `tests/js/posCartPrune.spec.js` (133 lignes)
- **Couverture** : 6 cas de test (happy path, item inconnu, falsy, string→int, lignes multiples même item, no-reset discount sur no-op)
- **Suite Vitest globale** : 419/419 ✓ (55 fichiers spec) — pas de régression
- **Note** : test utilise `saveCartToStorage` du plan (clé `pos_cart_v2` simple), pas la version scopée v3 du module réel (`getScopedKey`). Le test couvre la logique de filter + discount reset, mais pas l'interaction avec le scoping multi-branche. Cycle complémentaire `P11_POS_CART_PRUNE_TEST_SCOPED` recommandé (non urgent — la mutation est volontairement scope-agnostic).

### V6 #2 — `P11_DISPATCH_BUG_KNOWN_ISSUE_DOC`
- **Fichiers créés** : `docs/known-issues/.gitkeep` + `docs/known-issues/KI_001_DISPATCH_AFTER_COMMIT_2026-04-20.md` (107 lignes, 10 sections)
- **Vérifications croisées** :
  - Call-sites réels (8 hits) confirmés vs plan, **1 divergence corrigée** : `FrontendOrderService:848` est `OrderStatusChanged::dispatch`, pas `OrderCreated::dispatch`
  - 3 Event classes vérifiées : aucune n'implémente déjà `ShouldDispatchAfterCommit` (pas de surprise)
- **Découverte significative** : `OrderStatusChanged` est aussi dispatched depuis `FrontendOrderService` (kiosk public flow). Le bug ghost-status touche les commandes kiosk publiques, pas seulement les commandes admin/POS comme estimé initialement.

## Mises à jour propagées

- `tasks/execute-2026-04-20/V5_01_P11_DISPATCH_AFTER_COMMIT_REMEDIATION.md` → call-sites listés en détail (event class par ligne + flag `kiosk public flow` pour FrontendOrderService)

## Statistiques cumulées Composer (V1 + V3 + V4 + V5 + V6)

| Wave | Cycles | PASSED | PARTIAL | BUG_FOUND | Régressions |
|---|---|---|---|---|---|
| V1 | 8 | 8 | 0 | 0 | 0 |
| V3 | 1 | 1 | 0 | 0 | 0 |
| V4 | 11 | 9 | 1 | 1 | 0 |
| V5 | 2 | 2 | 0 | 0* | 0 |
| V6 | 2 | 2 | 0 | 0 | 0 |
| **TOTAL** | **24** | **22** | **1** | **1** | **0** |

*V5 #3 : 3 sentinelles rouges sont héritées du bug V4 #8 (volontaire), pas un bug nouveau.

## Lessons learned V6

1. **Vitest re-implementation pattern** : pour tester une mutation Vuex sans setup webpack/Vite, copier la mutation dans le spec puis valider en pur JS. Conforme à la pratique du repo (`posCart.spec.js`, `posItemAvailabilityHandler.spec.js`).
2. **Vérifications croisées dans les docs KI** : avant d'écrire un known-issue, vérifier les données contre la réalité du code. La divergence ligne 848 (event class différent) aurait été propagée par erreur si on avait copié-collé le plan sans re-grep.
3. **Known-issues versionnés** : `docs/known-issues/KI_NNN_*.md` est un pattern stable pour tracker les bugs en attente de remédiation. Critères de clôture explicites + sentinelles actives = audit trail complet.

## Prochaines étapes (handoff)

### Gates en attente d'approbation humaine
1. **C1-C8 consolidé** — `docs/gates/GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md` (3 GPT-5.4 V1 cycles bloqués)
2. **C9 étendu** — `tasks/execute-2026-04-20/V5_01_P11_DISPATCH_AFTER_COMMIT_REMEDIATION.md` (3 events broadcast confirmés affectés, 8 call-sites identifiés statiquement)
   - **NB** : le KI-001 documente le bug et son scope pour faciliter la review du gate

### Options Composer no-gate restantes (suite cohérente)

| Option | Cycle | Bénéfice | Effort | Risque |
|---|---|---|---|---|
| P | `P11_POS_CART_PRUNE_TEST_SCOPED` | couvrir interaction `pruneUnavailable` × multi-branch scoping | ~30 min | bas |
| Q | `P11_INVARIANT_4_OF_6_EXTEND_ITEM_CATEGORY` | étendre check 4/6 aux events Item/CategoryCreated/Updated/Deleted (après vérif `implements ShouldBroadcast`) | ~30 min | bas |
| R | `P11_KI_002_*` | créer un 2e known-issue pour un autre bug en attente (ex : F-VERIFY-15-04 résolu, ou F-VERIFY-04-03 résolu — ou sélectionner un bug encore ouvert) | ~30 min | bas |
| S | `P11_LOG_CHANNEL_FISCAL_TIMING` | créer un canal de log dédié pour `[FISCAL_TIMING]` (séparation SIEM) — touche `config/logging.php` | ~45 min | moyen (config) |

### Recommandation orchestrateur

Continuer avec **Option Q** (étendre check 4/6 à Item/Category) si le user veut maintenir l'élan sur la couverture invariants. Ou demander gate C1-C8 / C9 maintenant, car les 2 gates débloquent la prochaine vague de cycles à forte valeur (remédiation réelle des bugs identifiés).
