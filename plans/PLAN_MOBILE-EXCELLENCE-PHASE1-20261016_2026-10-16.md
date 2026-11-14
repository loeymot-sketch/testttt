# Plan – MOBILE-EXCELLENCE-PHASE1-20261016 – 2026-10-16

## TASK_ID
MOBILE-EXCELLENCE-PHASE1-20261016

## PRIMARY_EXECUTION_MODEL
gpt-6-astra (runtime fallback: gpt-5.5-pro indisponible sur le compte ChatGPT connecté)

## REASONING_EFFORT
xhigh

## EXECUTION_TIER
complex

## PLAN_REVIEW
PLAN_REVIEW_CHANNEL: foodking-complex-implementer (codex-extension-fallback)
PLAN_REVIEW_MODEL: gpt-6-astra
PLAN_REVIEW_REASONING_EFFORT: xhigh
PLAN_REVIEW_VERDICT: PASS — fallback review completed after first REWORK; allowlist, test strategy, stop conditions and invariants are explicit.

## SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| Mobile catalogue data | Image mappings, product/category presentation metadata | Write | No | No |
| Mobile home/menu UI | Category cards, product cards, compact labels | Write | No | No |
| Mobile product detail UI | Preserve allergen disclosure only in detail/recap | Write | No | No |
| Mobile tests | Catalogue and visual wiring assertions | Write | No | No |

### Fichiers autorisés dans ce cycle
- `mobile/data/menu.js`
- `mobile/screens-main.jsx`
- `mobile/screens-item-steps.jsx`
- `mobile/tests/menu.spec.js`
- `mobile/tests/mobile-catalog-visual.spec.js` (nouveau test autonome)
- `mobile/assets/menu/coca-cherry.png` (copie du canon local, autorisation propriétaire dans le chat)
- `mobile/assets/menu/lipton-peche.png` (copie du canon local, autorisation propriétaire dans le chat)
- `mobile/assets/menu/fanta-citron.png` (copie du canon local, autorisation propriétaire dans le chat)
- `reports/execution/MOBILE-EXCELLENCE-PHASE1-20261016/report.md`

## SUBSYSTEMS_OFF_LIMITS
- Backend pricing and catalogue services: backend remains pricing SSOT.
- `OrderService`, `FrontendOrderService`, order status, branch isolation and dispatch.
- Auth routes/middleware, loyalty API policy, Mollie/payment routes.
- Database, migrations, frozen POS/caisse zones and other FoodKing surfaces.

## INVARIANTS_AT_RISK
- Backend pricing SSOT: no new frontend pricing rule; existing display data is only a mirror.
- Branch isolation: no branch or API scope change.
- Auth/payment/order invariants: explicitly out of scope for this tranche.
- Allergen disclosure: remove the noisy compact badge only; retain detail/recap disclosure.

## GATE_CONDITIONS
- None anticipated for this visual/data mirror tranche. Escalate if backend/API or frozen zones are required.

## STOP CONDITIONS
- Collision d'activité sur un fichier autorisé : arrêter et coordonner.
- Débordement hors des fichiers autorisés, violation d'invariant, zone frozen ou gate non approuvé : arrêter et escalader.
- Deux échecs consécutifs de validation sans remédiation auditée : arrêter.
- Si une image locale n'existe pas ou si la source canonique est ambiguë : conserver le fallback neutre et documenter, ne pas inventer un mauvais visuel.

## Execution Steps
1. Inspect the current canonical mobile catalog, available local assets and existing menu tests.
2. Correct only local image/category mappings and mobile presentation surfaces in the declared files.
3. Keep the full product names/prices and existing wizard/order payloads intact.
4. Add or update focused tests for real image mappings, drink coverage and compact allergen behavior.
5. Run static checks and browser verification on the dedicated mobile app.

## TEST_STRATEGY
- `local-validation`: tests autonomes Node sur le miroir mobile, `git diff --check`.
- `playwright-critical-flow`: navigateur local sur `http://127.0.0.1:8088/?dev`, accueil → menu → catégorie Boissons → produit → panier.
- `human-verification`: contrôle visuel final des images et des choix de marque avant validation propriétaire.
- Critères : chaque carte ciblée possède un asset local existant et associé au bon slug ; aucun faux asset de marque n'est réutilisé ; noms/prix/payloads restent inchangés ; le badge allergène disparaît des cartes compactes mais reste présent dans le détail/recap.

## PRIOR_CONTEXT
- Le miroir mobile contient déjà 15 choix de boissons dans `FORMULE_DRINKS`, mais plusieurs mappings utilisent `boisson.png` ou `tropico.png` pour une autre marque.
- Les rapports de parité historiques signalent des attentes de prix obsolètes dans un test dépendant d'un miroir web externe ; la source mobile/canonique actuelle reste la référence, aucun prix ne doit être ajusté pour faire passer un test.
- Le dépôt comporte des modifications non liées déjà présentes ; elles doivent rester intactes.

## SYMMETRY_NOTE
N/A — `OrderService` and `FrontendOrderService` are not touched.

## SCOPE_PRESSURE

## ESCALATION

FALLBACK_REASON: codex-extension unavailable after retries (broken local vendor binary, legacy model rejected, account usage limit).

## Audit Status
[x] PLAN_REVIEW_VERDICT: PASS
[x] AUDIT_VERDICT: PASS — fallback read-only audit in cursor session; terminal Claude unavailable because wrapper passes unsupported --effort option.
[ ] GPT_FINAL_AUDIT_VERDICT: PASS
[ ] Passed — cycle closed
[ ] Gate opened
