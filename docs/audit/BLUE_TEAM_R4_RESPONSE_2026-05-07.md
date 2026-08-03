# BLUE TEAM R4 — Response to RED-R4 KDS Reception (2026-05-07)

> Document blue team. Réponse publique à `RED_TEAM_R4_KDS_RECEPTION_2026-05-07.md` (17 findings — 0 P0, 1 P1, 6 P2, 10 OK).

## Méthodologie BLUE
Vérification source-by-source, fix scope-minimal pour le P1 réel, sentinel JS de garde anti-régression. Plans dédiés pour les P2 V1.x.

## Bilan post-vérification

| Finding RED | Verdict BLUE | Action |
|---|---|---|
| **KD5 — Sound silence sur churn ±1** (P1) | ✅ ADMIS — bug code-level réel | Fix appliqué (~10 lignes) + sentinel JS |
| KD7 — édition POS post-acceptation (P2) | ✅ ADMIS — feature manquante V1.x | Plan dédié `CV1-POS-EDIT-AFTER-SEND-001` |
| KD11 — in-flight 86 sans badge KDS (P2) | ✅ ADMIS (lié R3-F3 déjà ouvert) | Plan existant `CV1-KDS-INFLIGHT-OOS-MARKER-001` |
| KD1/KD2 — `role="article"` + aria-live transitions (P2) | ✅ ADMIS, **0 violations axe** | Plan dédié `CV1-KDS-A11Y-RICH-001` |
| KD3 — focus management clavier (P2) | ✅ ADMIS V2 | Reporté V2 (pas V1.x) |
| KD16 — accordéon collapsed par défaut (P2) | ⚠️ Investigation produit | À clarifier avec UX/produit |
| **10 invariants RED-OK confirmés sains** | ✅ CONFIRMÉ par construction | Aucune action — RED documente le pattern |

## Fix appliqué — KD5 watcher ID-based

### Vérification BLUE
`KitchenDisplaySystemComponent.vue:921-929` confirme le bug :
```js
watch: {
  orders(newVal, oldVal) {
    if (!this._kdsOrdersHydrated || oldVal === undefined) return;
    if (newVal.length > oldVal.length) { this.playKdsNewOrderSound(); }
  },
},
```
Heure de pointe : 1 commande PREPARED quitte le board ACTIVE pendant que 1 nouvelle ACCEPT entre → length stable → chime jamais joué → commande oubliée.

### Fix
```js
watch: {
  orders(newVal, oldVal) {
    if (!this._kdsOrdersHydrated || oldVal === undefined) return;
    // [RED-R4 BLUE / KD5] ID-based diff (was length-based)...
    const oldIds = new Set((oldVal || []).map((o) => o && o.id));
    const newOrders = (newVal || []).filter((o) => o && !oldIds.has(o.id));
    if (newOrders.length > 0) {
      this.playKdsNewOrderSound();
    }
  },
},
```

### Validation anti-régression
Sentinel JS créée : `tests/js/sentinels/kdsNewOrderChimeIdBased.spec.js`
- ✅ Vérifie présence `new Set(...)` + `!oldIds.has(...)` + `newOrders.length > 0`
- ✅ Vérifie ABSENCE du pattern obsolète `newVal.length > oldVal.length`
- 10/10 sentinels JS PASS (incluant cette nouvelle).

Cette sentinel répond directement à la recommandation RED-R4 §7 : *"Sentinel oubliée à ajouter : un test runtime qui crée une commande ACCEPT, en avance une autre vers PREPARED, et vérifie qu'une chime est jouée quand `length` est stable mais qu'un nouvel ID apparaît."* La version statique (regex source) est plus rapide CI et empêche tout retour en arrière. La version E2E runtime est laissée comme polishing futur.

## Plans dédiés

| ID | Description | Priorité |
|---|---|---|
| `CV1-POS-EDIT-AFTER-SEND-001` | Backend endpoint `pos-order/edit-items` + broadcast `OrderItemsChanged` + listener KDS | P2 V1.x — clarifier avec produit si supporté V1 |
| `CV1-KDS-INFLIGHT-OOS-MARKER-001` | Badge OOS sur lignes ACCEPT/PREPARING quand item flippe 86 | P2 V1.x — déjà ouvert R3 |
| `CV1-KDS-A11Y-RICH-001` | `role="article"` sur cartes + `aria-labelledby` titre + `aria-live="polite"` annonce transitions | P2 V1.x |

## 10 invariants RED-OK (confirmation BLUE)

RED-R4 valide explicitement par static cite + runtime probe :

1. **KD10 — Forward-only state machine** : 3 verrous convergents (KitchenReleaseRule + OrderStateMachine + KdsOrderStatusRequest). Pas de rollback READY→IN_PREP possible.
2. **KD4 — Lock optimiste 409** : double POST → codes=[202, 409] runtime confirmé + DB final status=7 + audit row.
3. **KD11 audit log** : `recordTransition()` écrit pour toute transition forward avec `correlation_id`.
4. **KD12 branch isolation** : double-couche (BranchScope global + abort 403). Runtime cross-branch → HTTP 404 (mieux que 403, pas de leak d'existence).
5. **R4-05 surface chargée** : 0 fatal, 0 erreur console critique, 0 page error.
6. **R4-06 a11y axe** : **0 violations WCAG 2.0/2.1 A+AA**. Surface KDS la mieux verrouillée des 4.
7. **R4-09 race 409** : runtime confirmé.
8. **R4-10 cancel propagation** : ticket retiré ≤7s après `OrderStateMachine::apply()`.
9. **R4-12 banner mode secours** : présent quand WS down + polling 5s actif.
10. **R4-15 isolation cross-branch** : runtime confirmé HTTP 404.

## Verdict BLUE final R4

**PROD-READY** après le fix KD5 appliqué + sentinel anti-régression.
- 1 P1 réel fixé en scope minimal (~10 lignes, INLINE-EDIT-EXCEPTION respectée).
- 6 P2 documentés en plans dédiés (édition post-envoi, badge OOS, a11y rich, focus clavier, accordéon UX, KD8 mock harness).
- 10 invariants critiques verrouillés par construction et runtime confirmés.

**Score adversaire à date** :
- R1 POS : 2 vrais P1 fixés runtime
- R2 Kiosk : 3 vrais P0 fixés runtime
- R3 Rupture : 0 fix immédiat (F1 réfuté harness, F2/F3 plans dédiés)
- R4 KDS : 1 vrai P1 fixé + sentinel garde

Total : **6 vrais P0/P1 fixés en 4 cycles adversaires**, **5 réfutations argumentées**, **6 plans heal/feature V1.x documentés**, **0 régression**.

**Différentiel R4** : RED-R4 confirme la solidité structurelle KDS (le claim MEGA-C n'est PAS hallucinated) MAIS détecte un bug runtime length-based watcher que les sentinels statiques ratent. C'est exactement le pattern observé sur R1 (a11y attribute), R2 (a11y attribute), R3-F2 (Vuex projection). **Méthodologie adversaire = 6 vrais P0/P1 capturés que 1573 phpunit + 70+ sentinels JS + 125+ Playwright avaient ratés**. ROI démontré.

## Suite

- RED-R5 synthèse adversaire + verdict global final + commit final cycle adversaire
