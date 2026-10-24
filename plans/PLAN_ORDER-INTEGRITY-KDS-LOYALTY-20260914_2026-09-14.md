# Plan – ORDER-INTEGRITY-KDS-LOYALTY-20260914 – 2026-09-14

## TASK_ID

ORDER-INTEGRITY-KDS-LOYALTY-20260914

## PRIMARY_EXECUTION_MODEL

gpt-5.5-pro

## REASONING_EFFORT

xhigh

## EXECUTION_TIER

complex

## PLAN_REVIEW

PLAN_REVIEW_CHANNEL: codex-extension
PLAN_REVIEW_MODEL: gpt-5.5 (fallback compatible CLI ; `gpt-5.5-pro` refusé par cette session ChatGPT avant lecture du plan)
PLAN_REVIEW_REASONING_EFFORT: xhigh
PLAN_REVIEW_VERDICT: PASS

## PRIOR_CONTEXT

Graphiti non chargé ; mémoire locale relue. Le prix est scellé par `PricingService` et les options doivent rester des identifiants catalogue, jamais des montants front-end. Le précédent correctif de seconde sauce s'appuie encore sur une instruction texte pour son nom : le cycle remplace cette ambiguïté par une donnée de composition structurée, sans modifier le prix client.

## SUBSYSTEMS_TOUCHED

| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| Pricing / order sealing | Recalcul serveur, réconciliation preview→sceau, composition d'options et ligne libre POS validée serveur | Write | Yes, scoped validation | No new dispatch; existing events remain after commit |
| `OrderService` / `FrontendOrderService` | Persister le prix et l'instantané immuable de manière paritaire | Write | Yes | Existing post-commit path only |
| POS cart / item editor / checkout | Préserver les extras multiples à l'édition, demander et afficher le devis serveur, saisir un supplément libre autorisé | Write | Branch selection preserved | No |
| Receipt / fiscal projection | Rendre toute ligne libre, les options et le total scellé sans masquer de taxe ou de libellé | Write | Inherited from order | No |
| KDS / kitchen ticket | Afficher `HH`, `X`, `Tacos`, et les sauces structurées par destination produit/frites, en parité écran/papier | Write | No | No |
| Kiosk loyalty | Inscription nouveau/existant déterministe, erreurs visibles, aucune transition vers un état sans code utilisable | Write | Customer account remains branch-agnostic (`branch_id=0`) | No |
| Kiosk machine session | Renouvellement anticipé du jeton et grant HttpOnly chiffré après lien machine validé | Write | Machine branch remains server-derived | No |
| Database / fiscal migration | Ajouter seulement les champs/index/contrainte indispensables à la ligne libre et à son snapshot ; backfill interdit sur les commandes scellées | Write | Yes, order-owned records only | No |
| Tests PHP, JS and Playwright | Tests de non-régression et vrais parcours sans paiement réel | Write | Test fixtures only | No |
| Site web public | Texte honnête emporter / livraison bientôt, sans activer la livraison | Write | No | No |

## SUBSYSTEMS_OFF_LIMITS

- Tarifs catalogue, taux de TVA et règles de remises hors de la seule ligne libre approuvée.
- Paiements en ligne, prestataires externes, dispatchers et machine `OrderStatus`.
- Activation réelle de livraison web ou changement de promesse de délai.
- Données de production, secrets, migrations historiques et toute suppression.

## INVARIANTS_AT_RISK

- Backend pricing SSOT : le navigateur ne transmet que l'intention et ne peut imposer ni prix ni taxe.
- `branch_id` : un supplément libre et sa ligne fiscale sont persistés dans la commande de la branche active seulement.
- Immutabilité du `composition_snapshot` / chaîne NF525 : une fois encaissée, aucune composition ni montant n'est réécrit.
- Dispatch après commit : aucun nouveau message client/cuisine avant succès transactionnel.
- Auth et PII fidélité : le flux existant ne divulgue jamais l'identité ou les points d'un autre compte.
- Parité `OrderService` / `FrontendOrderService` obligatoire.

## GATE_CONDITIONS

- Gate approuvé : `docs/gates/GATE_ORDER-INTEGRITY-KDS-LOYALTY-20260914_2026-09-14.md` (schema, frozen, pricing/fiscal, auth UX, UX critique).
- Toute nouvelle taxe, route publique, règle de réduction, changement de statut ou intégration externe hors de ce plan ouvre un nouveau gate.

## Test strategy

`playwright-critical-flow` + tests PHP/JS ciblés + suite de régression proportionnée.

1. Contrats PHP : prix serveur de variations/extras/viandes/sauces, prévisualisation versus ordre scellé, conservation de l'instantané, droits/branche/borne du supplément libre, fidélité nouveau/existant/erreur.
2. Migration : migration fraîche, base déjà remplie, rollback documenté, aucune réécriture d'un `composition_snapshot` ou ticket déjà scellé ; vérifier branche, taxe et contrainte de la ligne libre.
3. Parité KDS/ticket : Harissa `HH`, sans sauce `X`, nom `Tacos`, deux sauces produit, sauces frites séparées, aucun libellé générique quand le nom est disponible.
4. JS : restauration POS de plusieurs sauces + quantité, sérialisation, devis serveur, rendu ticket ; symboles écran/papier.
5. Navigateur local sans encaissement réel : inscription fidélité, ajouter/éditer un produit à deux sauces, vérifier montant devis/cart/paiement préparé/KDS mock et ticket de prévisualisation, supplément libre nommée et sans nom ; captures mobile + desktop.
6. Après déploiement : smoke read-only des pages concernées ; aucun ordre ni paiement production.

## Execution Steps

1. Établir les payloads et snapshots de référence pour tacos, sauces produit/frites, viande supplémentaire et ligne libre ; identifier la source exacte du prix affiché, du devis et de l'ordre scellé.
2. Ajouter une migration additive et sans backfill des commandes déjà scellées, puis une représentation structurée validée côté serveur des destinations de sauce et du supplément libre ; rendre cette donnée dans le snapshot fiscal sans faire confiance à un montant front-end.
3. Adapter symétriquement les deux services de commande et les ressources/reçus afin que le total et chaque ligne du ticket viennent du résultat serveur.
4. Corriger le pont POS : restauration non destructive de chaque extra, synchronisation explicite du devis serveur avant encaissement, saisie contrôlée du supplément libre et messages de divergence exploitables.
5. Corriger l'inscription fidélité borne pour que nouveau compte, compte existant, e-mail déjà utilisé, timeout et erreur serveur restent tous dans des états explicitement rendus.
6. Mettre à jour la projection KDS/ticket : `HH`, `X`, `Tacos`, et noms/destinations des sauces ; ajouter les tests de parité PHP/JS.
7. Appliquer le texte web seulement aux surfaces où l'emporter est réellement disponible ; annoncer la livraison comme « bientôt » sans ouvrir de choix de livraison.
8. Lancer tests unitaires/feature/JS, puis Playwright local, compiler, auditer les diffs et déployer seulement les artefacts vérifiés sur les cibles autorisées.
9. [2026-09-17, demande propriétaire] Éviter l'écran « Borne indisponible » après veille, expiration ou déploiement : préserver uniquement sur la borne un grant HttpOnly chiffré issu du lien machine déjà validé, et renouveler le jeton avant son TTL. Aucun identifiant ne doit être remis dans l'URL, ni émis à une IP non autorisée sans lien/grant valide.

## SUBTASKS

| SUBTASK_ID | Description | Difficulty | Owner (planned) | Invariants at risk | Mini-audit policy | Status | Retry |
|---|---|---|---|---|---|---|---|
| ORDER-INTEGRITY-KDS-LOYALTY-20260914-S01 | Prix, snapshot, ligne libre et parité services | complex | codex-extension | pricing, fiscal, branch, symmetry | 1:1 | LOCAL_PASS_PENDING_AUDIT | 0 |
| ORDER-INTEGRITY-KDS-LOYALTY-20260914-S02 | POS édition, devis et ticket | complex | codex-extension | pricing, frozen POS | 1:1 | LOCAL_PASS_PENDING_AUDIT | 0 |
| ORDER-INTEGRITY-KDS-LOYALTY-20260914-S03 | Fidélité borne | complex | codex-extension | PII/auth UX | 1:1 | LOCAL_PASS_PENDING_AUDIT | 0 |
| ORDER-INTEGRITY-KDS-LOYALTY-20260914-S04 | Projection KDS/ticket et copie web | complex | codex-extension | kitchen accuracy | 1:1 | LOCAL_PASS_PENDING_AUDIT | 0 |

## SYMMETRY_NOTE

Les modifications de persistance/pricing doivent être réalisées et testées dans `OrderService` et `FrontendOrderService` avec le même `PricingResult`, snapshot, règles d'options, branche et comportement d'erreur. Les différences de canal sont limitées aux champs de paiement déjà existants ; aucune différence de calcul ou de ligne libre n'est admise.

## SCOPE_PRESSURE


## ESCALATION

EXECUTE fallback: foodking-complex-implementer (codex-extension-fallback)
FALLBACK_REASON: codex exec failed twice — gpt-5.5-pro unsupported by the connected ChatGPT CLI session, then gpt-5.5 exhausted the account usage limit before producing a patch.
REMEDIATION_AUDIT_CYCLE: 2
REWORK_REASON: GPT self-audit NEEDS_FIX; pricing/order sealing, POS free supplement, and multi-sauce restoration remain unresolved.


## Audit Status

[ ] Pending
[x] PLAN_REVIEW_VERDICT: PASS
[ ] AUDIT_VERDICT: PASS
[ ] GPT_FINAL_AUDIT_VERDICT: PASS
[ ] Passed — cycle closed
[x] Gate opened and approved — `docs/gates/GATE_ORDER-INTEGRITY-KDS-LOYALTY-20260914_2026-09-14.md`
