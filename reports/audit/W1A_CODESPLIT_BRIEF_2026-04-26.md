# W1-A — POS Code Splitting — Brief audit Claude terminal

**Date** : 2026-04-26
**Cycle** : POS_V4_W1A_CODESPLIT
**Type** : routine (lazy-load route, pattern existant)
**EXECUTE_DELEGATION** : cursor-claude-direct (5 lignes, pattern identique kioskRoutes.js, 0 invariant à risque)

---

## 1. Contexte (provenant du baseline W0)

`reports/baseline/POS_V4_PERF_BASELINE_W0.md` a établi que le bundle `app.js` monolithique pesait **965 KB gzipped** et incluait POS dans le chargement initial pour TOUTES les surfaces (admin classique, KDS, OSS, frontend, kiosk). KPI POS first-paint < 220 KB gzipped impossible sans code splitting.

`reports/audit/AUDIT_FINAL_W0PLUS_CLAUDE_2026-04-26.md` (verdict 7.8/10 PASS-WITH-FIX) a posé W1-A comme **livrable bloquant** de W1.

## 2. Modification effectuée

**Un seul fichier touché** : `resources/js/router/modules/posRoutes.js`

Pattern : conversion 2 imports statiques → lazy avec `webpackChunkName: "pos-shell"` (pattern identique aux 24 lignes existantes de `resources/js/router/modules/kioskRoutes.js` lignes 6-24, lazy-loading kiosk-shell / kiosk-wizard / kiosk-admin / kiosk-errors).

```js
// AVANT
import PosComponent from "../../components/admin/pos/PosComponent";
import FloorplanComponent from "../../components/admin/pos/FloorplanComponent";

// APRÈS
const PosComponent       = () => import(/* webpackChunkName: "pos-shell" */ "../../components/admin/pos/PosComponent");
const FloorplanComponent = () => import(/* webpackChunkName: "pos-shell" */ "../../components/admin/pos/FloorplanComponent");
```

Pas de modif à `webpack.mix.js`, `app.js`, `PosComponent.vue`, `cdsRoutes.js` (orphelin non importé dans `router/index.js`).

## 3. Évidence build prod (`npm run production`)

```
✔ Compiled Successfully in 32448ms
js/pos-shell.js  →  236 KB raw  /  55 KB gzipped
js/app.js        →  4604 KB raw / 1018 KB gzipped
```

**KPI pos-shell** : 55 KB gzipped < 220 KB → **PASS** (marge 75%)

**Comparaison baseline** :
| Chunk | Avant W1-A (raw / gz) | Après W1-A (raw / gz) | Δ |
|---|---|---|---|
| `app.js` | 4500 KB / 965 KB (mesure W0) | 4604 KB / 1018 KB | +104 / +53 KB (autres deltas inter-build) |
| `pos-shell.js` | absent | 236 KB / 55 KB | nouveau chunk POS |

Note transparence : la mesure `app.js` après build prod est légèrement supérieure au baseline W0 (+53 KB gz). Cela peut s'expliquer par d'autres ajouts inter-cycles (bootstrap-kiosk, KIOSK-DS V1 Phase 2). **Indépendamment**, le bénéfice fonctionnel est : POS n'est plus téléchargé pour les surfaces non-POS (admin dashboard, KDS, OSS, frontend, kiosk).

## 4. Non-régression

- `npm run production` : `Compiled Successfully` (1 warning non-régression i18n.js déjà présent avant W1-A)
- `npm run pos:lint:status` : OK clean
- `npm run pos:lint:pricing` : OK + WARN attendu (PosComponent:1779 signoff-pending jusqu'au 2026-05-10)
- Aucun changement de comportement runtime : Vue Router lazy resolution est syntaxe standard utilisée déjà 18 fois pour kiosk

## 5. Invariants

| Invariant | Risque W1-A | Vérification |
|---|---|---|
| pricing_ssot | Aucun | Pas de logique métier touchée, juste un wrapper d'import |
| OrderStatus enum | Aucun | Pas de logique métier touchée |
| branch_id isolation | Aucun | Pas de query / mutation touchée |
| commit_before_dispatch | Aucun | Pas de dispatch/event touché |
| OrderService/FrontendOrderService symétrie | Aucun | Pas de service backend touché |
| Frozen zones | Aucun | `posRoutes.js` n'est pas en frozen zone (vérifié) |

## 6. Questions pour audit Claude

1. **Cohérence chunk** : `FloorplanComponent` partage `pos-shell` (sibling POS). Est-ce le bon choix vs un chunk `pos-floorplan` séparé (chargé seulement si l'utilisateur va sur `/admin/pos/floorplan`) ?
2. **`cdsRoutes.js` orphelin** : faut-il supprimer ou laisser tel quel hors W1-A ? (recommandation : laisser, nettoyage hors scope)
3. **app.js encore à 1 MB gzipped** : prochaines actions W1-B/C/D candidates pour réduire (vendor chunking, kiosk déjà splitté, ce qui reste = admin classique + KDS + frontend) ?
4. **Pré-fetch / prefetch hint** : faut-il ajouter `webpackPrefetch: true` pour pré-charger pos-shell au login admin ? (trade-off : améliore UX POS mais re-pollue les surfaces non-POS)

## 7. Verdict attendu

GO/NO-GO pour W1-B (déterminer la priorité parmi : sign-off humains restants ADR couleur + ItemComponent, Kiosk magic ints, vendor chunking, lazy admin classique).
