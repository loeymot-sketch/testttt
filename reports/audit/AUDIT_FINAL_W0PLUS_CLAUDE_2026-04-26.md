La demande de permission a été envoyée — le fichier nécessite ton approbation pour être écrit. En attendant, voici le rapport complet en sortie directe :

---

# AUDIT_FINAL_W0PLUS_CLAUDE_2026-04-26
**Second avis indépendant — Claude terminal**

---

## 1. VERDICT CONSOLIDÉ

```
VERDICT : PASS-WITH-FIX
```

Le cycle W0+ est **substantiellement sain** mais deux lacunes structurelles bloquent l'ouverture propre de W1 :

| Priorité | Lacune | Bloquant W1 ? |
|----------|--------|---------------|
| P0 | Lint guards non câblés en CI | **OUI** |
| P1 | `@pricing-allowed-block` sans sign-off humain réel = bypass silencieux | Oui, process |
| P2 | Sémantique ambiguë `status !== 10` kiosk | Non, W1-KIOSK |

---

## 2. VALIDATION DES 4 REFACTORS CODE

**R1 — Import `orderStatusEnum` (PosComponent:719) : CONFORME**
Import cohérent avec les enums existants. `Object.freeze` sur l'enum garantit l'immutabilité. Pas de risque résiduel.

**R2 — Magic ints `[4, 7, 8]` → constantes enum (PosComponent:1391-1394) : CONFORME**
`ACTIVE_KIOSK_STATUSES` en `const` local, usage unique, correct. Le `parseInt(o.order_status ?? o.status, 10)` sur :1396 est défensif et pertinent face aux deux noms de champs API.

**R3 — `status: 13` → `orderStatusEnum.DELIVERED` (~L1418) : CONFORME**
Refactor précis, correspond exactement à `DELIVERED: 13` dans `orderStatusEnum.js`.

**R4 — Guard `branch_id != null` avant `idempotency_key` (PosComponent:1837-1842) : CONFORME — lacune résiduelle**
Logique correcte, message utilisateur explicite. Lacune : aucune confirmation que le backend valide `X-Idempotency-Key`. Si le backend ignore l'header, la protection est frontend-only, incomplète contre retry réseau. Ticket W2 requis.

**R5 — `@pricing-allowed-block` (PosComponent:1779-1785) : CONFORME forme — PROCESSUS INCOMPLET**
Le guard CI passe car le block marker est présent. Mais le sign-off TL+BE est absent. Le guard ne distingue pas un block "approuvé" d'un block "en attente". C'est un bypass structurel du processus que le guard est censé enforcer.

---

## 3. VALIDATION DES 2 LINT GUARDS

**pos_pricing_guard.mjs : BONNE implémentation — LACUNE CRITIQUE : non câblé CI**

La mécanique `maskAllowedBlocks` est correcte. Les patterns `FORBIDDEN_RE` sont précis. Mais aucun des workflows GitHub Actions (`playwright.yml`, `vitest.yml`, `phpunit.yml`) n'appelle les scripts. **Un guard lint orphelin est un guard décoratif.**

Action requise (ST-1) : ajouter dans un workflow existant :
```yaml
- name: POS lint guards
  run: |
    npm run pos:lint:pricing
    npm run pos:lint:status
```

**pos_orderstatus_guard.mjs : BONNE implémentation — faux négatif de couverture**

`CONTEXT_RE` ne capture pas `order.status === 7` (sans le token `order_status`). La couverture est partielle. Acceptable W0+ car les violations connues sont corrigées, mais à documenter comme limitation connue.

---

## 4. VALIDATION DÉCISIONS D1-D4

**D1 — PaymentComponent prop mutation DÉFÉRÉ : VALIDÉ AVEC RÉSERVE**
La mutation directe de props fonctionne en Vue 3 sans erreur runtime. Le risque de régression sur NF525 d'un refactor mal exécuté sur 3 sites est supérieur au risque du pattern actuel. Différé défendable. **Réserve :** le gate brief doit être rédigé et daté, pas laissé open-ended.

**D2 — Codex non utilisé : JUSTIFIÉ — opportunité manquée**
Pour 4 lignes chirurgicales, coût disproportionné. Confirmé. Opportunité manquée : une mission codex en lecture seule pour scanner les violations kiosk cross-fichiers aurait été un bon usage même en session instable.

**D3 — ParkedOrders:72 faux positif : CONFIRMÉ**
Lu directement (lignes 68-150) : commentaire de documentation, pas de filtre actif commenté. `fetchList()` dispatche `posParked/fetchList` sans filtre `branch_id` côté composant. Faux positif confirmé. Note : le comportement documenté (rappel sans branch_id → 404) mérite un ticket API distinct.

**D4 — Bundle 965 KB : W1-A ACCEPTABLE — sous condition stricte**
Action de performance, pas de correctness. Pas de régression par rapport à l'état antérieur. W1-A est correct. **Condition :** doit être le premier livrable de W1-A, pas glissé en milieu de cycle. Clarification requise : le seuil 220 KB est-il SLA contractuel ou objectif interne ?

---

## 5. STATUT DES 4 P0-CC

| P0-CC | Statut | Evidence |
|-------|--------|----------|
| Mesure bundle réelle | ✅ RÉSOLU | `reports/baseline/POS_V4_PERF_BASELINE_W0.md §4` — 965 KB gzip |
| ADR palette couleur | ✅ RÉSOLU (DRAFT) | `docs/design/ADR_POS_V4_COULEUR.md` — Option C, `--fk-pos-primary: #0084FF` |
| Pricing sign-off | ⏳ EN ATTENTE HUMAIN | Block marker posé, TL+BE sign-off absent |
| branch_id ParkedOrders:72 | ✅ RÉSOLU (faux positif) | Confirmé lecture directe |

---

## 6. LACUNES QUE CURSOR-CLAUDE POURRAIT AVOIR MANQUÉES

**L1 — CI non câblé** (P0, §3) : Guards orphelins = guards décoratifs.

**L2 — Idempotency key backend non vérifiée** : Protection frontend-only incomplète contre retry réseau.

**L3 — `@pricing-allowed-block` sans validation sign-off par le guard** : Le guard `pos_pricing_guard.mjs` ne vérifie pas `signed-off: <names>` dans le bloc start. N'importe qui peut bypasser le guard sans autorisation réelle.

**L4 — Ambiguité sémantique `status !== 10` kiosk** : Les `Number(v.status) !== 10` dans KioskWizardComponent:642 et 2 autres composants peuvent référencer le statut de disponibilité d'item, pas OrderStatus. Corriger en `orderStatusEnum.OUT_FOR_DELIVERY` sans vérification sémantique introduit un bug logique silencieux.

**L5 — Pas de tests unitaires sur les nouvelles constantes** : Aucun test ne vérifie que `ACTIVE_KIOSK_STATUSES` inclut les bons statuts, ni que le guard `branch_id == null` déclenche l'alertService.

**L6 — ADR couleur non implémentée en CSS** : `--fk-pos-primary` n'existe pas encore dans le CSS source. L'ADR est une intention sans livrable de code.

---

## 7. STOP TRIGGERS RÉSIDUELS AVANT W1

| # | Trigger | Critique ? | Action |
|---|---------|-----------|--------|
| **ST-1** | Lint guards non câblés CI | **OUI** | Job CI avant merge W0+ → main |
| **ST-2** | `@pricing-allowed-block` sans sign-off réel | Oui | Enrichir le guard pour valider `signed-off:` |
| ST-3 | Backend idempotency non vérifiée | Non | Ticket W2 |
| ST-4 | ADR couleur non implémentée | Non | Ticket W1-B |
| ST-5 | Ambiguité sémantique kiosk `status !== 10` | Non | Note verification dans W1-KIOSK |

---

## 8. DÉCISION FINALE

**OUI pour W1, sous condition ST-1.**

### Ordre des premiers livrables W1

| Livrable | Description |
|----------|-------------|
| **W1-0** | Câbler `pos:lint:pricing` + `pos:lint:status` en CI |
| **W1-A** | Code splitting `pos-shell.js` |
| **W1-B** | Implémenter `--fk-pos-primary` dans CSS (concrétise ADR) |
| **W1-C** | Kiosk magic ints (après vérification sémantique L4) |
| **W1-D** | Gate review PaymentComponent — rédiger brief + date |

### Score global W0+

| Dimension | Score |
|-----------|-------|
| Implémentation code | 8/10 |
| Architecture | 7/10 |
| Complétude business logic | 9/10 |
| Sécurité / validation | 7/10 |
| Qualité evidence | 8/10 |
| **Global** | **7.8/10 — PASS-WITH-FIX** |

---

**Les points non-négociables avant W1 :** ST-1 (CI câblage) résolu en premier commit W1. ST-2 (sign-off guard) adressé pendant W1. Tout le reste est soft et documenté dans le backlog.
