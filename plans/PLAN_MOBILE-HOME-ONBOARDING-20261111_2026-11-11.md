# Plan – MOBILE-HOME-ONBOARDING-20261111 – 2026-11-11

## TASK_ID
MOBILE-HOME-ONBOARDING-20261111

## PRIMARY_EXECUTION_MODEL
gpt-5.5-pro

## REASONING_EFFORT
xhigh

## EXECUTION_TIER
complex

## PRIOR_CONTEXT

- Graphiti MCP n'est pas chargé dans cette session ; `memory/INDEX.md` confirme que le catalogue et les prix restent une source serveur commune aux surfaces.
- Le cycle précédent ne couvrait que l'optimisation mobile et plaçait le catalogue, le POS et le KDS hors périmètre ; le propriétaire a explicitement ouvert un nouveau chantier.
- Les actifs mobiles mélangeaient photos et illustrations ; les écrans d'onboarding doivent désormais présenter exclusivement la photographie fournie par le propriétaire, sans substituer les produits Le Cayenne.

## PLAN_REVIEW
PLAN_REVIEW_CHANNEL: foodking-complex-implementer (codex-extension-fallback)
PLAN_REVIEW_MODEL: gpt-5.5-pro
PLAN_REVIEW_REASONING_EFFORT: xhigh
PLAN_REVIEW_VERDICT: PASS

PLAN_REVIEW_REMEDIATION_1_CHANNEL: foodking-complex-implementer (codex-extension-fallback)
PLAN_REVIEW_REMEDIATION_1_MODEL: gpt-5.5-pro
PLAN_REVIEW_REMEDIATION_1_REASONING_EFFORT: xhigh
PLAN_REVIEW_REMEDIATION_1_VERDICT: PASS

## SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| `mobile/screens-main.jsx` | Héro Cayenne, rail horizontal ordonné des meilleures ventes, retrait des descriptions de carte | Write | No | No |
| `mobile/screens-onboarding.jsx` | Recomposition visuelle des quatre écrans et remplacement des visuels incohérents | Write | No | No |
| `mobile/styles.css` | Styles d'overflow tactile, cohérence des teasers et respect reduced-motion | Write | No | No |
| `mobile/assets/menu/` | Ajout non destructif de visuels photo générés, consommés seulement par l'app mobile | Write | No | No |
| `mobile/tests/mobile-ui-excellence.spec.js` | Assertions de l'ordre et de l'absence de descriptions dans les cartes concernées | Write | No | No |
| `mobile/tests/mobile-preview-freshness.spec.js` | Sentinelle des nouveaux assets référencés | Write | No | No |
| `docs/gates/GATE_LOG.md` | Traçabilité de l'approbation propriétaire du catalogue multi-surfaces | Write | No | No |

## SUBSYSTEMS_OFF_LIMITS

- `config/menu.php`, `config/menu_meat_rules.php`, `config/pos_sauces.php` et toute donnée catalogue/prix : cycle serveur dédié après gate.
- `app/Services/Pricing/PricingService.php`, `OrderService`, `FrontendOrderService`, routes et contrôleurs : aucune modification.
- POS vanilla (`public/js/pos-wizard.js`, `public/css/pos-wizard.css`, `resources/views/admin-pos-v4.blade.php`) : zone frozen, explicitement exclue.
- KDS, impression ticket, `composition_snapshot`, dispatch et authentification : aucune modification.

## INVARIANTS_AT_RISK

- Backend pricing SSOT : le rail ne fait que présenter les données catalogue déjà chargées ; aucun prix ni calcul n'est ajouté.
- Isolation `branch_id` et dispatch after commit : non concernés.
- Parité `OrderService` / `FrontendOrderService` : non concernée, aucun service d'ordre n'est modifié.
- Accessibilité : le rail reste clavier/tactile, les images gardent un texte alternatif et `prefers-reduced-motion` n'est pas contourné.

## GATE_CONDITIONS

- Le catalogue partagé, les prix, les règles de sauces, les règles de viandes, la caisse et le KDS exigent la résolution du gate `docs/gates/GATE_CATALOG_POS_KDS_20261111.md` avant tout changement.
- Une validation visuelle manuelle du propriétaire est requise après le preview local de l'onboarding refondu.

## Execution Steps

1. Préparer un dérivé vertical non destructif de la photographie fournie par le propriétaire : retirer seulement le texte promotionnel, préserver les vrais plats et l'employer sur les quatre teasers.
2. Modifier l'accueil mobile pour sélectionner `cayenne` comme héro, supprimer la description de ce bloc et dériver un rail scrollable depuis une liste de slugs propriétaire. Le rail ne présente que les produits effectivement publiés par le catalogue chargé, dans l'ordre propriétaire, sans créer de produit, prix, supplément, ni fallback métier mobile.
3. Recomposer les écrans d'onboarding pour reprendre le même langage photo, conserver les contrats Splash → 4 écrans → Login et les contrôles existants.
4. Étendre les sentinelles mobile pour vérifier la sélection Cayenne, l'ordre des meilleures ventes, l'absence de description, l'existence des actifs et l'absence de chaînes de prix ou de règles métier ajoutées dans les teasers et écrans d'onboarding.
5. Lancer les tests unitaires mobile, ouvrir le preview local frais et effectuer une vérification manuelle de navigation onboarding → connexion.
6. Consigner l'approbation propriétaire du futur catalogue multi-surfaces, sans modifier ses données, son prix ou son exécution dans ce cycle visuel.

## TEST_STRATEGY

`local-validation` + `human-verification`

- Exécuter `node mobile/tests/mobile-ui-excellence.spec.js` et `node mobile/tests/mobile-preview-freshness.spec.js`.
- Vérifier en navigateur le parcours Splash → onboarding 1 à 4 → connexion, les textes alternatifs, le défilement tactile du rail et la navigation clavier.
- Vérifier que `prefers-reduced-motion` reste appliqué et qu'aucun prix, supplément, nombre de viandes, règle de sauces ou logique de total n'est ajouté dans les fichiers mobiles touchés.
- Le gate `GATE_CATALOG_POS_KDS_20261111` est externe à ce cycle : son approbation est consignée, mais POS, KDS, catalogue, sauces, viandes et prix restent non modifiés dans ce cycle visuel.

## SYMMETRY_NOTE

N/A — `OrderService` et `FrontendOrderService` sont hors périmètre.

## SCOPE_PRESSURE


## ESCALATION


## Audit Status
[x] Validation complete — targeted mobile and scope guard checks pass
[x] PLAN_REVIEW_VERDICT: PASS
[x] AUDIT_VERDICT: PASS
[x] GPT_FINAL_AUDIT_VERDICT: PASS
[x] Passed — cycle closed
[x] Gate opened then approved — `docs/gates/GATE_CATALOG_POS_KDS_20261111.md`; suivi dans `docs/gates/GATE_LOG.md`
