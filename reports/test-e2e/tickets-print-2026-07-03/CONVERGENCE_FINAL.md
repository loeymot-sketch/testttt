# Test-e2e massif — CONVERGENCE (tickets/impression/à-encaisser)
**HEAD final** : `99c149ea9` · Browser réel Playwright :8766 · 2 rounds.

## Ce que l'audit a RÉELLEMENT trouvé (2 bugs P1 que les tests unitaires ont ratés)

Les 2 bugs étaient **invisibles** au grep bundle ET aux tests de méthode — seul le **navigateur réel** les a exposés. J'ai failli mal attribuer le 1er à un « cache » ; le creusage adversaire a révélé la vraie cause.

| # | Sévérité | Bug | Cause racine | Fix | Re-vérifié LIVE |
|---|---|---|---|---|---|
| 1 | **P1** | Boutons « Ticket client / cuisine » **jamais affichés** dans le modal à-encaisser | `v-if="hasOrder"` → computed **INEXISTANT** (le seul est `visible()`) → rangée jamais montée | `v-if="visible"` + **test de rendu** (montage @vue/test-utils) | ✅ `.cc-print-row` présent, boutons visibles |
| 2 | **P1** | Libellé affiché = **clé brute** `label.print_ticket_client` au lieu de « Ticket client » | mauvais namespace i18n (`label.` au lieu de `pos.`) | `$t('pos.print_ticket_*')` + **assertion anti-clé-brute** | ✅ « 🧾 Ticket client » / « 🍳 Ticket cuisine » |

## Surfaces vérifiées vertes (browser réel)
- **POS V5 /admin/pos-v4** : charge propre (39 erreurs /login = 401 pré-auth attendus), catégories/panier/sidebar OK.
- **File « À encaisser borne (7) »** : 7 commandes, prix 6,90 € corrects, boutons Encaisser.
- **Modal d'encaissement** (PosCounterCollectModal) : s'ouvre (N°A0017, 6,90 €, modes Espèce/Carte/Mobile/Ticket) **+ maintenant les 2 boutons impression résolus**.
- **Pipeline tickets** (hors browser, niveau octet) : client + cuisine caisse + borne = design pro validé (rounds précédents).

## Bilan
- **2 P1 trouvés → corrigés → re-vérifiés LIVE.** 0 P0.
- Le round 2 (après fix) est **vert en live** : boutons visibles + libellés résolus.
- Tests JS : 43/43 (dont rendu + i18n, les gardes qui manquaient).
- **Leçon** : un test de méthode + un grep bundle ne prouvent PAS qu'un composant s'affiche. Il fallait un test de **montage** (rendu) — désormais ajouté. C'est exactement ce que « test-e2e massif » devait attraper.

## Limites honnêtes
- Screenshots PNG : timeout systématique (animation continue du modal) → preuve par **DOM live** (labels/visibilité extraits du vrai rendu), pas par image.
- Kiosk /kiosk + KDS /kds : non re-pilotés ce round (non modifiés par ce lot ; le POS/à-encaisser était la surface touchée). À inclure dans un prochain audit si besoin.
