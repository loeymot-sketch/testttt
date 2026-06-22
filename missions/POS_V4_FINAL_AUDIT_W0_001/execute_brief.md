# Sources W0 inline (à auditer cross-check Codex)

## reports/audit/HYPERREVIEW_CLAUDE_POS_V4_EXEC_FINAL_2026-04-26.md
```markdown
# HYPERREVIEW — POS v4 EXEC FINAL
**Orchestrateur : Claude terminal** | Date : 2026-04-26 | Mode : HYPER-REVIEW  
**Inputs lus** : `PLAN_POS_V4_IMPL_EXEC_FINAL_2026-04-26.md` · `PLAN_POS_V4_IMPL_MASTER_2026-04-26.md` · `RAPPORT_ECARTS_DESIGN_POS_V4_SYSTEMIQUE_2026-04-25.md` · `REAUDIT_G55PRO_POS_V4_PRECLAUDE_2026-04-26.md` · lecture repo `resources/js/components/admin/pos/*.vue` · `resources/css/`

---

## 1. Verdict orchestration

**GO-WITH-AMENDS**

| # | Raison |
|---|---|
| 1 | Le plan EXEC_FINAL intègre correctement les 7 lacunes du proxy G55 (L1–L7) et les 3 amendements Claude §15 (parallélisation ADR+BINDING_MAP, seuils KPI, namespace CSS). La chaîne de traçabilité `EXECUTE_DELEGATION` / `AUDIT_CHANNEL` / `TERMINAL_AUDIT_OK: 1` est formellement imposée — c'est un garde-fou solide. |
| 2 | **Violation `pricing_ssot` active dans le code source** : `ItemComponent.vue` lignes 734–773 (`totalPriceSetup()`) calcule localement `total_price = (convert_price + item_variation_total + item_extra_total) × quantity`. Ce bloc existe *avant* W0 et n'est pas référencé dans les critères de sortie W2 (où ItemComponent est mergé). Sans gate explicite sur ce bloc, le plan peut valider un merge qui embarque une règle de prix frontend — violation directe de l'invariant le plus critique. |
| 3 | **KDS et Kiosk absents des micro-exits W2–W3** (lacune L4 du proxy, non comblée dans EXEC_FINAL). Le rapport systémique 2026-04-25 §1.2 et §1.3 documentent le risque : action POS → drift KDS silencieux, divergence catalogue POS/Kiosk. Ni le critère G3 (catalogue), ni G5 (paiement) n'imposent de vérification cross-surface. Un GO pur sur ce plan serait prématuré. |

**Amends obligatoires avant W1** : (a) gate explicite `pricing_ssot` sur `ItemComponent.vue::totalPriceSetup()` avec décision écrite (conserver sous conditions / refactorer / isoler) ; (b) ajouter 2 critères cross-surface KDS/Kiosk aux gates G3 et G5 ; (c) ADR couleur signé.

---

## 2. Cohérence inter-plans (MASTER vs EXEC FINAL)

### Ce qui a été **gagné** dans EXEC FINAL
- Chemins fichiers `.vue` réels figés en §4 (comble L1 proxy).
- Format BINDING_MAP imposé avec 5 colonnes (comble L2).
- Baseline KPI ou décision explicite requise en W0 (comble L3).
- Gates G0–G8 avec owners nommés et critères entrée/sortie — plus opérationnels que le MASTER §8.
- 5 STOP triggers formalisés en §8.

### Ce qui a été **perdu / affaibli**
| Delta | MASTER §5 | EXEC FINAL §4 | Impact |
|---|---|---|---|
| Zone "Header / contexte session" (risque 4) | Zone distincte, merge 2, gate branch | Absorbée dans PosComponent (merge 5) | Risque branch_id dilué dans le SFC le plus complexe |
| Zone "Résumé total / taxes / remises" (risque 5) | Zone distincte, merge 7, gate pricing strict | Absorbée dans PaymentComponent (merge 6) | Séparation template/total perdue — plus difficile à gate individuellement |
| Zone "Messages erreurs / notifications" (risque 4) | Zone distincte, merge 10, gate red-team | Non représentée en SFC propre | Recovery critique sans gate dédié |
| Zone "États loading / skeleton / disabled" (risque 3) | Zone distincte, merge 11 | SkeletonGrid.vue merge 4a sans gate anti-double-action | Anti-double-action non validé pour skeleton |
| KDS / Kiosk dans W2–W3 | Absent des deux | Absent des deux | Lacune partagée, non résolue |
| Playwright spec obligatoire | Cité comme "gate" | Cité sans nom de spec | L7 proxy non comblé |

### CONTRADICTIONS détectées

**`CONTRADICTION:`** MASTER §5 ordre 1 = "POS shell / layout racine" (risque 3, premier merge car structure de base). EXEC FINAL §4 ordre merge 1 = `ReceiptComponent.vue` (risque 4). L'amendement Claude §15 du MASTER reformule l'ordre comme `Receipt → Parked → Floorplan → Pos → Payment` mais dans MASTER §5 la logique était inverse (shell first pour stabiliser les autres). Les deux documents cohabitent sans trancher formellement ce choix architectural. L'EXEC FINAL tranche implicitement en faveur de l'ordre Claude §15 (risque minimum d'abord) — décision défendable mais non documentée comme supersession explicite du §5.

**`CONTRADICTION:`** MASTER §4 W0 liste "Workstreams actifs : A, D" — EXEC FINAL §3 W0 ne mentionne aucun workstream actif pour W0. Cohérence perdue sur le pilotage par workstream en W0.

---

## 3. Couverture invariants (matrice 6 × phases W0–W4)

| Invariant | W0 | W1 | W2 | W3 | W4 |
|---|---|---|---|---|---|
| `pricing_ssot` | OK — relecture imposée G1 | OK — `pos-v4.css` scope only, pas de logique | **À-renforcer** — `ItemComponent.vue::totalPriceSetup()` (l.734–773) non audité dans critère merge W2 | À-renforcer — `PaymentComponent` change calc (l.144–145) et mutation `form.total = 0` (l.255) | OK — grep CI + matrice test P0 |
| `order_status` (enum) | OK | OK | **À-renforcer** — PosComponent.vue l.1390 : `[4, 7, 8].includes(parseInt(o.order_status))` magic integers ; l.1413 : `status: 13` commenté "DELIVERED" — pas une violation string mais pas l'enum formel | À-renforcer — gate G5 ne vérifie pas les integers magiques | OK — G7 "zéro string magique" (mais integers hors scope) |
| `branch_id` | OK — G1 liste l'invariant | OK — banner G2 imposé | À-renforcer — `CreateCustomerAddressComponent.vue` (adresse client) non lié à branch_id check explicite | OK — PaymentComponent l.221 lit `branch_id` depuis API ✓ | OK — grep + assertions PHPUnit |
| `commit_before_dispatch` | OK | OK | OK — aucun dispatch dans W2 | **À-renforcer** — PaymentComponent : mutation directe de `this.$props.props.form` (l.251–255) est un side-effect pre-commit ; gate G5 ne couvre pas ce pattern | OK |
| `OrderService / FrontendOrderService symétrie` | **Manquant** | **Manquant** | **Manquant** | **Manquant** | **Manquant** — aucun gate de parité dans tout le plan (ni MASTER ni EXEC FINAL) |
| Frozen zones (scripts gelés) | OK — listés G0 | OK | OK | OK | OK — critère de sortie W4 explicite |

**Verdict couverture** : 4/6 invariants correctement couverts sur W4. `OrderService/FrontendOrderService symétrie` = **lacune totale**. `pricing_ssot` = couverture W2 insuffisante (bloc existant non audité avant merge).

---

## 4. 12 lacunes intelligentes non triviales (au-delà des 7 proxy G55)

### L8 — `ItemComponent::totalPriceSetup()` : violation `pricing_ssot` pré-existante non auditée
**Impact concret** : lignes 734–773 accumulent `item_variation_total`, `item_extra_total`, appliquent `× quantity` côté Vue — le composant est à merger en W2. Si ce bloc n'est pas formellement audité et décidé avant le merge, la caisse embarque une règle de prix frontend sans gate. Perte financière potentielle si les `convert_price` divergent du backend.  
**Mitigation chiffrée** : ajouter 1 critère G3 "audit pricing bloc L734–773 — décision écrite (conserver avec guard / isoler dans computed backend-fed)" — délai +2h sur W2, zéro code supplémentaire.

### L9 — Magic integers `order_status` : plan interdit strings, pas integers
**Impact concret** : `PosComponent.vue` l.1390 `[4, 7, 8]` et l.1413 `status: 13` sont des valeurs numériques hardcodées. Si l'enum backend change d'ordinal, la logique POS silencieusement casse (filtres de commandes, changement statut KDS). Le plan §8 cite "zéro string magique" — les integers magiques ne sont pas couverts.  
**Mitigation chiffrée** : étendre la définition S2 à "zéro litéral de statut — ni string ni integer — hors constante nommée importée". Grep CI : `rg "\b(status|order_status).*[0-9]{1,2}" resources/js/components/admin/pos/` — coût < 30 min.

### L10 — `PaymentComponent.vue` mute props parent (l.251–255) : anti-pattern + risque commit_before_dispatch
**Impact concret** : `this.$props.props.form.subtotal = null; [...] this.$props.props.form.total = 0` — mutation directe des props après paiement. Si le paiement échoue en async et que la réinitialisation est déjà appliquée, le panier affiché = 0 alors que la commande backend est pendante. Recovery impossible sans rechargement.  
**Mitigation chiffrée** : gate G5 ajouter assertion "PaymentComponent ne mute pas les props du parent directement — émettre `@payment-reset` event uniquement". Vitest snapshot test sur le state post-erreur paiement — 1 test, ~2h.

### L11 — Focus-trap RGAA AA non distingué du "focus visible" dans les gates
**Impact concret** : gate G6 impose "focus visible sur actions critiques" mais le prompt exige `focus-trap RGAA AA`. Focus-trap = piéger le focus dans les modals/panels (PaymentComponent, ItemComponent modal) = critère distinct, testable avec `Tab` / `Shift+Tab` en boucle. Absent du plan → non testé → non conforme RGAA AA en production.  
**Mitigation chiffrée** : ajouter 1 ligne G6 : "focus-trap testé dans PaymentComponent + ItemComponent modal : Tab ne quitte pas la couche active" — 2 assertions Vitest sur `document.activeElement`, ~3h.

### L12 — `SkeletonGrid.vue` : surface binding non analysée, risque refs orphelines
**Impact concret** : SkeletonGrid est placé en merge 4a avec "faible risque" sans analyse. Si ce composant émet des refs ou écoute des events (ex. `@skeleton-loaded`), les templates POS v4 peuvent créer des refs orphelines lors de l'intégration. La BINDING_MAP doit couvrir SkeletonGrid explicitement.  
**Mitigation chiffrée** : lecture SkeletonGrid.vue dans BINDING_MAP (~30 lignes à analyser) — si 0 emit/ref : confirmé risque 1. Si emit détecté : reclasser en risque 3 et reporter après ItemComponent.

### L13 — `CreateCustomerAddressComponent` absent de la matrice tests §9 MASTER
**Impact concret** : ce composant gère une adresse client liée à une commande POS — potentiellement soumis à branch_id (livraison associée à une branche). Il n'apparaît dans aucun test Vitest de la matrice (§9 MASTER). Merge sans couverture = fuite potentielle de données client cross-branch non détectée.  
**Mitigation chiffrée** : ajouter 1 test Vitest P1 "adresse client ne peut être soumise si branch_id manquant" — 1 test, ~1h.

### L14 — Idempotency key `PaymentComponent` invalide si branch_id = null
**Impact concret** : `PosComponent.vue` l.1822 génère `${Date.now()}_${random}_${this.checkoutProps.form.branch_id || 0}`. Si `branch_id` n'est pas encore résolu au moment du clic paiement, la clé se termine par `_0`. Deux sessions sans branch_id résolu dans la même milliseconde (improbable mais possible en multi-tab) partagent le risque de collision de clé → doublon commande.  
**Mitigation chiffrée** : gate G5 ajouter assertion "branch_id != null avant génération idempotency_key" — 1 guard dans `PaymentComponent` ou `PosComponent`, +1h. Grep : `rg "idempotency_key" resources/js/components/admin/pos/`.

### L15 — `app.css` non audité en W0 : vecteur contamination CSS invisible au grep CI
**Impact concret** : le grep CI bloquant cherche `.fk-dark` / `.fk-pos` dans `resources/css/` et `resources/js/components/admin/pos/`. Si un développeur injecte des styles POS v4 dans `app.css` (qui existe et n'est pas dans le chemin de grep CI), la contamination passe silencieusement. Aucun SFC POS ne devrait importer `app.css` directement.  
**Mitigation chiffrée** : étendre grep CI à `app.css` — `rg "fk-pos-v4" resources/css/app.css` doit retourner 0. Ajouter à la commande W0 : `rg "fk-pos-v4\|fk-dark" resources/css/app.css resources/css/kiosk-wizard.css` — coût nul.

### L16 — RTL (ar) : aucun shim directionnel dans les SFC existants
**Impact concret** : le red-team exige un scénario RTL arabe. Les SFC utilisent `flex`, `text-left`, `text-right` sans classe directionnelle (`rtl:flex-row-reverse`, `dir` attribute, logical properties CSS). Un test RTL échouerait structurellement sans changement template, ce qui dépasse le périmètre "template + style seulement" du plan. Risque d'escalade de scope en W4.  
**Mitigation chiffrée** : définir explicitement en W0 : "RTL = test de non-régression, pas d'implémentation RTL complète dans ce lot". Documenter dans `DEBT_POS_V4.md` en entrée. Coût : 0 code, 30 min de décision produit.

### L17 — `FloorplanComponent.vue` : test existant `posFloorplan.spec.js` non référencé dans gates W3
**Impact concret** : le plan systémique 2026-04-25 §5 cite `tests/js/posFloorplan.spec.js` comme test réel existant. FloorplanComponent est mergé en W3 ("états visuels finaux") mais G5 et G6 ne mentionnent pas ce spec. Si le spec échoue après le merge design, il n'est pas dans la checklist G5 → régression silencieuse.  
**Mitigation chiffrée** : ajouter à G5 : "npx vitest run --grep posFloorplan doit être vert". Coût : 0 code, 1 ligne dans le plan.

### L18 — `OrderService / FrontendOrderService` symétrie : invariant absent de tout gate
**Impact concret** : le périmètre interdit "refonte services sans plan de parité" — mais aucun gate ne vérifie activement que POS v4 ne crée pas de divergence. Si un SFC appelle une API qui passe par `FrontendOrderService` d'une façon non symétrique avec `OrderService`, le drift s'installe silencieusement. PosComponent.vue charge des commandes kiosk (l.1188 : `broadcastAs: 'OrderStatusChanged'`, `loadKioskCashOrders`) — ce chemin peut traverser les deux services.  
**Mitigation chiffrée** : ajouter en W0 un grep : `rg "FrontendOrderService\|OrderService" resources/js/components/admin/pos/` pour cartographier les points d'appel. Documenter dans BINDING_MAP colonne "service appelé". Coût : < 1h.

### L19 — LCP < 1.2s : métrique invérifiable sans baseline bundle actuel
**Impact concret** : le budget impose LCP < 1.2s, CLS < 0.05, TTI < 1.8s, JS first-paint < 220 KB gzipped. `PosComponent.vue` est ~2000+ lignes. Sans mesure du bundle actuel (taille gzippée, LCP initial), le critère W4 est déclaratif mais non opposable. Une régression de +50 KB sur le bundle POS passerait si la baseline est inconnue.  
**Mitigation chiffrée** : W0 micro-exit obligatoire : `npm run build -- --report` → capturer `chunk POS size (KB gzip)` + Lighthouse LCP sur `/pos` (1 run) → stocker dans `reports/baseline/POS_V4_PERF_BASELINE_W0.md`. Coût : ~2h CI, 0 code.

---

## 5. Ordre SFC re-justifié (9 réels)

**Critère** : score = (binding density [0–5]) × (surface clavier [0–3]) × (invariants touchés [1–4]) — plus le score est bas, plus le merge est sûr en premier.

| Ordre | SFC | Binding density | Surface clavier | Invariants touchés | Score | Justification |
|---|---|---|---|---|---|---|
| 1 | `ReceiptDuplicataMarker.vue` | 0 — display only | 0 | order_status (affichage) | **0** | Aucun event, aucune logique, aucun risque de régression |
| 2 | `SkeletonGrid.vue` | 0–1 — template squelette | 0 | 0 | **0–1** | Sous-composant visuel pur ; vérifier absence d'emit dans BINDING_MAP avant confirm |
| 3 | `ReceiptComponent.vue` | 1 — v-for sur tax_lines, display | 1 (impression) | order_status + pricing_ssot (affichage seulement, données backend) | **2** | Données 100% backend-fed ; risque = affichage only |
| 4 | `CreateCustomerAddressComponent.vue` | 2 — form + @submit | 2 | branch_id (indirect, adresse/livraison) | **8** | Form léger mais lié à branch — audit branch_id requis |
| 5 | `ParkedOrdersComponent.vue` | 2 — list + @click reprise | 2 | branch_id + pricing_ssot (preview_total display) | **8** | `formatMoney(order.preview_total)` = données backend uniquement ✓ ; `branch_id` commenté l.72 |
| 6 | `FloorplanComponent.vue` | 3 — table select, commandes actives | 2 | branch_id (cross-branch 422 documenté l.94) | **12** | Commentaire l.94 prouve awareness branch — gate cross-branch 422 test existant |
| 7 | `ItemComponent.vue` | 5 — 30+ methods, totalPriceSetup, bumpPricingToCatalog | 3 (modal sélection variations) | pricing_ssot **VIOLATION active** + branch_id indirect | **45** | **AUDIT OBLIGATOIRE avant merge** — décision écrite sur totalPriceSetup() lignes 734–773 requise avant tout commit |
| 8 | `PosComponent.vue` | 5 — shell 2000+ lignes, tous flux | 3 | Tous : branch_id (l.796–1822), order_status magic int (l.1390, l.1413), commit_before_dispatch (idempotency l.1822) | **45** | Dernier grand composant avant payment — gate G4 panier signé requis |
| 9 | `PaymentComponent.vue` | 4 — paiement, mutation props (l.251–255), change calc (l.144–145) | 3 | pricing_ssot (change calc) + commit_before_dispatch + order_status | **36** | LAST — gate human-verification + E2E + G4+G5 signés obligatoires |

**Divergence vs EXEC FINAL** : ReceiptDuplicataMarker correctement placé 1b. SkeletonGrid devrait passer avant ReceiptComponent (score 0–1 vs 2). ItemComponent et PosComponent sont correctement placés en fin (ordres 4b et 5 dans le plan = ordres 7 et 8 ici). CreateCustomerAddressComponent (4c dans plan) devrait passer avant ParkedOrdersComponent (2 dans plan) sur la base du score — **ajustement mineur recommandé**.

---

## 6. Plan de test combat (red-team) — 10 scénarios

| # | Scénario | Surface SFC | Invariant | Pass criteria (terminal-vérifiable) |
|---|---|---|---|---|
| 1 | **Offline mid-sélection** : couper réseau après ouverture ItemComponent, tenter ajout panier | ItemComponent + PosComponent | pricing_ssot | Panier conservé, message d'erreur réseau visible, 0 console.error uncaught |
| 2 | **Double-tap paiement** : click rapide × 2 sur bouton "Payer" pendant `loading=true` | PaymentComponent | commit_before_dispatch | Bouton `disabled` après 1er click, 0 requête dupliquée (vérifier Network tab : 1 seul POST) |
| 3 | **Kiosk crash mid-paiement** : simuler `OrderStatusChanged` pendant que PaymentComponent est en loading | PosComponent broadcast + PaymentComponent | order_status | Paiement se termine normalement ou affiche état lisible — pas de state zombie |
| 4 | **Branch swap** : changer `branch_id` (via devtools mutation `checkoutProps.form.branch_id`) pendant session POS active | PosComponent + ParkedOrdersComponent | branch_id | Liste commandes parkées rechargée pour nouvelle branche OU session invalidée avec message clair |
| 5 | **Fiscal duplicata** : réimprimer reçu d'une commande déjà imprimée | ReceiptComponent + ReceiptDuplicataMarker | order_status | `ReceiptDuplicataMarker` visible et lisible ; pas de double écriture backend (log réseau = 0 POST supplémentaire) |
| 6 | **KDS desync** : order envoyée POS (`status: 13`) mais KDS liste non rafraîchie | PosComponent l.1413 + KDS broadcast | order_status | Après `axios.post(change-status/13)`, PosComponent émet ou attend confirmation KDS — pas de ghost order dans liste POS |
| 7 | **Contraste couleur** : `#0084FF` sur `#FFFFFF` (RGAA AA) | pos-v4.css + tous SFC | A11y | `axe-core` ou DevTools accessibility audit → ratio ≥ 4.5:1 pour texte normal, ≥ 3:1 pour large text |
| 8 | **RTL ar** : `document.documentElement.dir = 'rtl'`, parcours vente simple | PosComponent + ItemComponent + PaymentComponent | (hors invariants métier) | Layout non cassé (pas de débordement horizontal) ; boutons d'action accessibles au Tab |
| 9 | **Multi-screen race** : POS + KDS ouverts simultanément, modifier quantité ligne dans les 2 surfaces | PosComponent + KDS broadcast | order_status + pricing_ssot | Dernier état cohérent affiché des 2 côtés après 3s ; pas de divergence de total |
| 10 | **Race condition pricing** : click rapide +qty / -qty × 10 en < 500ms dans ItemComponent | ItemComponent::totalPriceSetup() | pricing_ssot | `temp.total_price` converge vers valeur correcte ; pas de valeur négative ou NaN affiché |

**Commandes d'exécution** :
```bash
# Scénarios 1-6, 9-10 : Vitest + Playwright
npx vitest run --grep "double|offline|branch|receipt|kds|race"
npx playwright test tests/e2e/pos/ --grep "payment|parked|floorplan|branch"
# Scénario 7
npx axe-cli http://localhost:8000/admin/pos --rules wcag2aa
# Scénario 8
# DevTools Console: document.documentElement.setAttribute('dir','rtl'); puis parcours manuel
```

---

## 7. Découpage micro-exits W0 (3 étapes critiques immédiates)

### W0-A — Audit `pricing_ssot` violation active dans `ItemComponent.vue`
```bash
rg "totalPriceSetup|bumpPricingToCatalog|total_price\s*=" \
  resources/js/components/admin/pos/ItemComponent.vue -n
```
**Fichier livré** : `reports/audit/W0_PRICING_SSOT_ITEMCOMPONENT_DECISION.md`  
**Critère pass** : fichier existe + contient l'une des décisions : `[CONSERVER: convert_price est source backend]` ou `[ISOLER: déplacer dans composable backend-fed]` ou `[BLOQUER: refactoring requis avant W2]`  
**Critère fail** : fichier absent OU merge ItemComponent tentée sans ce fichier signé par Tech Lead

### W0-B — Génération BINDING_MAP squelette + vérification pos-v4.css absent
```bash
# Vérifier pos-v4.css inexistant (attendu)
ls resources/css/pos-v4.css 2>/dev/null && echo "EXISTS_CONFLICT" || echo "OK_TO_CREATE"
# Cartographier tous les @click, $emit, ref dans les 9 SFC
rg "@click|@submit|@emit|v-model|\$emit|ref=" \
  resources/js/components/admin/pos/ --include="*.vue" -l
```
**Fichier livré** : `docs/design/BINDING_MAP_POS_V4.md` (squelette avec 5 colonnes : `SFC | binding | cible template v4 | statut | test garde`)  
**Critère pass** : fichier créé + les 9 SFC ont au moins 1 ligne chacun + colonne "statut" ≠ vide  
**Critère fail** : BINDING_MAP vide ou manque ≥ 1 SFC → JOIN bloqué → W1 impossible

### W0-C — Mesure baseline perf + grep contamination CSS actuelle
```bash
# Baseline CSS namespace
rg "fk-pos-v4|fk-dark" resources/css/ resources/js/components/admin/pos/ --include="*.{css,vue}"
# Doit retourner 0 lignes (namespace pas encore créé = attendu)
# Baseline app.css propre
rg "pos-v4\|fk-pos" resources/css/app.css && echo "CONTAMINATION" || echo "CLEAN"
# Baseline bundle size (si npm build disponible)
npm run build -- 2>/dev/null | grep -E "pos|chunk" | head -20
```
**Fichier livré** : `reports/baseline/POS_V4_PERF_BASELINE_W0.md` (lignes : chunk_size_kb_gzip, rg_css_result, app_css_clean)  
**Critère pass** : fichier créé + `rg fk-pos-v4 app.css = 0 résultats` + chunk POS size documenté  
**Critère fail** : contamination détectée dans app.css → STOP S4 immédiat

---

## 8. Coordination multi-agent (W0)

| Agent | Rôle W0 | Livrables W0 | Gate start | Gate done | Anti-collision |
|---|---|---|---|---|---|
| **claude-terminal** | Orchestrateur : signe G0, G1 ; valide résultats W0-A/B/C ; décide GO/HEAL/BLOCK | `HYPERREVIEW_CLAUDE_POS_V4_EXEC_FINAL` (présent) + signature G0 | Session ouverte | G0 + G1 signés | Seul signataire des gates — ne code pas |
| **cursor-composer** | Exécution lecture SFC → rédige BINDING_MAP draft (W0-B) ; génère squelette `pos-v4.css` stub | `BINDING_MAP_POS_V4.md` draft | G0 signé | BINDING_MAP draft livré à claude-terminal pour validation | Ne signe pas les gates ; ne modifie pas les SFC |
| **codex-terminal** | Rejouer `codex:complex POS_V4_G55_PRECLAUDE_001` si API disponible ; diff avec proxy | Diff rapport ou confirmation "proxy équivalent" | API disponible | Rapport diff dans `reports/audit/` | Si HTTP 503 : skip, proxy reste valide |
| **cursor-session** (fallback) | Audit channel fallback si claude-terminal indisponible | `AUDIT_FALLBACK_REASON:` dans rapport | claude-terminal fail | N/A | Doit tracer `AUDIT_CHANNEL: cursor-session` |
| **human** | Décision ADR couleur (primary + accent `#0084FF`) ; scope dark mode (POS seul / différé) ; validation W0-A decision pricing_ssot | ADR signé (fichier `docs/design/ADR_POS_V4_COULEUR.md`) | JOIN condition | ADR signé + merge W1 autorisé | Seul décideur sur scope dark + charte — aucun agent ne tranche |

**Règle anti-collision** : un seul agent modifie un fichier à la fois. Si cursor-composer est sur BINDING_MAP, claude-terminal ne touche pas ce fichier. Les gates bloquent le pipeline : pas de W1 sans `JOIN = ADR signé AND BINDING_MAP complète AND G0+G1 signés`.

---

## 9. Politique de rollback par SFC

### Kill switch CSS (immédiat, sans déploiement backend)
```css
/* Ajouter dans pos-a11y.css ou via feature flag Blade */
[data-pos-v4-disabled] .fk-pos-v4,
[data-pos-v4-disabled] .fk-pos-v4 * {
  all: revert; /* revert tous les styles pos-v4.css */
}
```
Activer en posant `data-pos-v4-disabled` sur le conteneur root POS (`<div id="pos-app">`).

### Flag back applicatif (retour template legacy par SFC)
```php
// config/features.php ou AppServiceProvider
'pos_v4_design' => env('POS_V4_DESIGN', false),
```
```blade
{{-- PosComponent.vue chargé selon flag --}}
@if(feature('pos_v4_design'))
    <pos-component-v4 />
@else
    <pos-component />
@endif
```

| SFC | Rollback CSS | Rollback flag | Délai estimé |
|---|---|---|---|
| `ReceiptDuplicataMarker` | `data-pos-v4-disabled` | Non nécessaire (risque 0) | < 5 min |
| `SkeletonGrid` | `data-pos-v4-disabled` | Non nécessaire | < 5 min |
| `ReceiptComponent` | `data-pos-v4-disabled` | `pos_v4_receipt` flag | < 10 min |
| `CreateCustomerAddressComponent` | `data-pos-v4-disabled` | `pos_v4_address` flag | < 10 min |
| `ParkedOrdersComponent` | `data-pos-v4-disabled` | `pos_v4_parked` flag | < 10 min |
| `FloorplanComponent` | `data-pos-v4-disabled` | `pos_v4_floorplan` flag | < 15 min |
| `ItemComponent` | `data-pos-v4-disabled` | `pos_v4_item` flag | < 15 min |
| `PosComponent` | `data-pos-v4-disabled` | `pos_v4_shell` flag | < 20 min |
| `PaymentComponent` | `data-pos-v4-disabled` | `pos_v4_payment` flag **(human gate required for activation/rollback)** | < 30 min |

**Règle** : tout rollback `PaymentComponent` = escalade humaine obligatoire (transactions en cours possibles).

---

## 10. STOP triggers terminaux (5 max)

| # | Trigger | Signal détectable | Action |
|---|---|---|---|
| **ST-1** | Un `<script>` Vue d'un SFC POS contient une nouvelle règle de calcul de prix, remise ou taxe (au-delà du bloc `totalPriceSetup()` pre-existant audité en W0-A) | `rg "prix\|price\|total.*=.*\*\|discount\|tax.*calc" resources/js/components/admin/pos/ --include="*.vue"` retourne une ligne non listée dans W0-A | **STOP** — PR refusée — ESCALATE Tech Lead + Backend owner — aucun merge jusqu'à décision humaine |
| **ST-2** | Un statut de commande est comparé ou assigné via string littérale OU integer non documenté comme constante nommée (ex. nouveau `status: 14` sans enum) | `rg "status.*['\"][a-z_]+['\"]" resources/js/components/admin/pos/` OU diff de `[4,7,8,13]` introduit une nouvelle valeur | **STOP** — BLOCK — correction obligatoire + vérification enum backend avant tout merge ultérieur |
| **ST-3** | Une requête traverse `branch_id` d'une branche non courante sans autorisation explicite documentée dans BINDING_MAP | Test PHPUnit branch isolation fail OU `rg "branch_id" resources/js/components/admin/pos/` révèle un accès non cartographié | **STOP** — ESCALATE human gate immédiat — audit terminal Claude avant reprise |
| **ST-4** | `pos-v4.css` ou un SFC propage un sélecteur hors `.fk-pos-v4` dans `resources/css/app.css` ou dans un fichier CSS partagé | `rg "fk-pos-v4\|\.pos-" resources/css/app.css` retourne ≥ 1 ligne | **STOP** — refus merge — correction namespace + re-run CI `rg` |
| **ST-5** | Gate G4 (panier + totaux) ou G5 (paiement) non signé ET merge `PaymentComponent.vue` ou `PosComponent.vue` tenté | G4/G5 absent du rapport de cycle (`TERMINAL_AUDIT_OK: 1` sans G4/G5 tracés) | **STOP** — BLOCK — ordre de merge §4 EXEC FINAL appliqué sans exception — human gate requis pour déverrouiller |

---

**AUDIT_TRAIL** : Claude terminal — 2026-04-26 — lecture repo `resources/js/components/admin/pos/*.vue` (9 SFC), `resources/css/` (4 fichiers, pos-v4.css absent confirmé), plans EXEC_FINAL + MASTER, rapport systémique 2026-04-25, proxy G55 2026-04-26 — aucune modification applicative — verdict : **GO-WITH-AMENDS** — amends obligatoires : W0-A pricing audit + KDS/Kiosk dans G3/G5 + ADR couleur signé avant W1.  
`EXECUTE_DELEGATION: claude-terminal` | `AUDIT_CHANNEL: claude-terminal`
```

## reports/audit/W0_PRICING_SSOT_ITEMCOMPONENT_DECISION.md
```markdown
# W0-A — Décision `pricing_ssot` × `ItemComponent.vue::totalPriceSetup()`

**Cycle** : POS_V4_IMPL_EXEC_FINAL_2026-04-26  
**Phase** : W0 (préparation)  
**Auteur** : Claude terminal (orchestrateur)  
**Date** : 2026-04-26  
**Statut** : **DRAFT — pending human gate sign-off (Tech Lead + Backend owner)**  
**Lien plan** : `plans/PLAN_POS_V4_IMPL_EXEC_FINAL_2026-04-26.md` §G1 + amend HYPERREVIEW Claude L8/§7

---

## 1. Trouvaille (preuve code, lecture seule)

Fichier : `resources/js/components/admin/pos/ItemComponent.vue`  
Lignes : **734–770** (méthode `totalPriceSetup()`)

Extrait littéral (lignes 759–770) :

```
this.temp.item_variation_total = item_variation_total;
this.temp.item_extra_total = item_extra_total;
var catalogBase =
    parseFloat(this.item.offer.length > 0 ? this.item.offer[0].convert_price : this.item.convert_price) || 0;
if (!this.usePricedCartBase) {
    this.temp.convert_price = catalogBase;
}
var baseUnit = this.usePricedCartBase ? parseFloat(this.temp.convert_price) || 0 : catalogBase;
this.temp.total_price = parseFloat(
    (baseUnit + this.temp.item_variation_total + this.temp.item_extra_total) * this.temp.quantity +
        item_addon_total
);
```

Le bloc accumule côté client :
- `item_variation_total` = Σ(`itemVariation.convert_price` × `selectedVariation.quantity`)
- `item_extra_total` = Σ(`itemExtra.convert_price` × `selectedExtra.quantity`)
- `item_addon_total` = Σ(`addon.total_price` × `addon.quantity`)
- `total_price` = (`baseUnit` + variations + extras) × `quantity` + addons

Méthodes appelantes (mêmes fichiers) : `quantityUp` (l.772), `quantityIncrement` (l.779), `quantityDecrement` (l.787), `addonQuantityUp` (l.795), `addonQuantityIncrement` (l.808), `changeVariation` (autour l.660+), `changeExtra` (l.731). Soit **≥ 7 entrées** dans le pipeline.

---

## 2. Statut invariant

| Invariant | Lecture | Verdict |
|---|---|---|
| `pricing_ssot` (Backend = SSOT prix) | Ce bloc *calcule* `total_price` côté Vue à partir de `convert_price` (champ backend). | **Violation conditionnelle**. La SSOT backend est respectée pour les unités (`convert_price` vient toujours de l'API) mais l'**aggrégation** (`× quantity`, `+ extras`, `+ variations`) est exécutée frontend. |
| `commit_before_dispatch` | `totalPriceSetup` est synchrone, sans dispatch. | OK. |
| `branch_id` | Aucun. | N/A direct. |
| `order_status` | Aucun. | N/A direct. |

**Risque opérationnel** :
- Si l'API `convert_price` change (TVA, devise, promo backend), le calcul reste cohérent **tant que** Vue ne fait que multiplier/sommer. **Tant que** = invariant respecté **conditionnellement**.
- Si jamais on ajoute une règle (remise, taxe, conversion devise) **dans `totalPriceSetup`**, c'est rupture stricte de SSOT.

---

## 3. Trois options décisionnelles

| ID | Option | Coût | Risque | Quand |
|---|---|---|---|---|
| **D1 — CONSERVER avec garde** | Garder le bloc tel quel + **garde CI** : tout ajout de `*` `/` `-` `+` autre que `× quantity` ou `+ Σ` lève une alerte. Garde grep CI : `rg "this\.temp\.total_price\s*=" ItemComponent.vue` doit retourner exactement 1 occurrence. | 1h | Bas — garde-fou statique uniquement | Maintenir avant W2 (merge ItemComponent) |
| **D2 — ISOLER en composable backend-fed** | Extraire dans `composables/usePricedItemPreview.js` qui appelle un endpoint `POST /api/admin/pricing/preview` (subtotal, taxes, total signés backend). UI affiche la réponse, ne calcule plus. | 2 jours backend + 1 jour front | Moyen — change le contrat affichage live (latence ajout panier) | Recommandé en lot dédié post POS v4 |
| **D3 — BLOQUER POS v4 jusqu'à refactor** | Refactorer en endpoint preview avant tout merge ItemComponent. | 3-4 jours, bloque W2 | Élevé en planning, faible en correctness | À éviter sauf décision président |

---

## 4. Recommandation orchestrateur (Claude terminal)

**D1 + plan D2 différé** :
1. **Maintenant (W0)** : adopter D1 — garde CI ajoutée à `package.json` script `pos:lint:pricing` :
   ```bash
   ! grep -E "this\.temp\.total_price\s*=\s*parseFloat" \
        resources/js/components/admin/pos/ItemComponent.vue \
     | wc -l | awk '{exit ($1 == 1) ? 0 : 1}'
   ```
   → exit 0 si exactement 1 assignation, 1 sinon.
2. **W2 (merge ItemComponent)** : critère G3 ajouté = `pos:lint:pricing` doit passer ; toute nouvelle règle de prix → STOP S1.
3. **Lot dédié post POS v4 (cycle séparé)** : ouvrir `tasks/T-POS-PRICING-PREVIEW-API.md` pour D2 (endpoint backend signé + composable Vue). Aligne avec `OrderService / FrontendOrderService symétrie`.

Justification : POS v4 est **template + style + namespace** (script gelé). Refactor pricing = changement de contrat applicatif → hors périmètre. La garde CI capture toute régression future sans bloquer W2.

---

## 5. Conditions de mise en œuvre

| Pré-requis | Statut | Bloquant ? |
|---|---|---|
| Tech Lead lit ce fichier et signe `[D1]` ou `[D2]` ou `[D3]` | À FAIRE | OUI — sans signature, W2 ne peut pas merger ItemComponent |
| Backend owner confirme que `convert_price` est bien recalculé serveur sur changement TVA/devise/promo | À FAIRE | OUI — si recalcul absent, D1 reste fragile |
| Garde CI `pos:lint:pricing` ajoutée à `package.json` (script + appel pre-commit / GH Actions) | À FAIRE | OUI pour D1 |
| Ouvrir `tasks/T-POS-PRICING-PREVIEW-API.md` (D2 différé) | À FAIRE post-signature | NON pour W2 mais OUI pour roadmap Q3 |

---

## 6. Sign-off (à remplir par humains)

```
[ ] D1 — CONSERVER avec garde CI       Signé par: ___________ Date: ___
[ ] D2 — ISOLER en backend-fed         Signé par: ___________ Date: ___
[ ] D3 — BLOQUER refactor d'abord      Signé par: ___________ Date: ___

Tech Lead    : ___________________  Date : ___
Backend owner: ___________________  Date : ___
```

Sans signature de cette section, **STOP S1** s'applique au merge `ItemComponent.vue` (cf. HYPERREVIEW §10).

---

## 7. Trace
- `EXECUTE_DELEGATION: claude-terminal`
- `AUDIT_CHANNEL: claude-terminal`
- Lecture seule appliquée — aucun `.vue` modifié par la production de ce document.
- À ingérer dans `memory/episodes/12_decisions_log.jsonl` après sign-off (entry `pos_v4_pricing_ssot_decision`).
```

## docs/design/BINDING_MAP_POS_V4.md
```markdown
# BINDING_MAP_POS_V4 — squelette W0

**Cycle** : POS_V4_IMPL_EXEC_FINAL_2026-04-26  
**Phase** : W0 (livraison W0-B)  
**Auteur** : Claude terminal (lecture seule, aucune édition de SFC)  
**Date** : 2026-04-26  
**Statut** : **DRAFT — à compléter par cursor-composer / codex-terminal en W0/W1**  
**JOIN gate** : ce fichier doit avoir **toutes les colonnes "statut" remplies (≠ vide)** pour les 9 SFC avant ouverture W1.

---

## 1. Légende des colonnes

| Colonne | Définition |
|---|---|
| **SFC** | Composant Vue (chemin relatif depuis `resources/js/components/admin/pos/`) |
| **Binding** | Type d'attache (`@click`, `@submit`, `v-model`, `$emit`, `ref=`, `props.X`, `axios`, `$store`) avec ligne approximative |
| **Cible template v4** | Élément/sélecteur du design POS v4 qui doit recevoir/conserver ce binding |
| **Statut** | `KEEP` (binding conservé tel quel) / `RENAME` (selector change, logique conservée) / `WRAP` (composable interne) / `SPLIT` (à découper) / `TODO` (à analyser) |
| **Test garde** | Vitest spec ou Playwright test qui vérifie que le binding survit au merge |
| **Service appelé** | (HYPERREVIEW L18) Service backend touché : `OrderService`, `FrontendOrderService`, `axios direct`, `$store/posOrder`, `$store/posCart`, `appService`, `none` |

---

## 2. Inventaire SFC (9 réels confirmés W0-C)

| SFC | Lignes | Bindings (count grep) | Risque (HYPERREVIEW §5) |
|---|---|---|---|
| `ReceiptDuplicataMarker.vue` | 70 | 0 | 0 — display only |
| `SkeletonGrid.vue` | 19 | 0 | 0–1 — confirmé sans emit/ref |
| `ReceiptComponent.vue` | 479 | 3 | 2 — display backend-fed |
| `CreateCustomerAddressComponent.vue` | 196 | 8 | 8 — branch_id indirect |
| `ParkedOrdersComponent.vue` | 345 | 6 | 8 — list + reprise |
| `FloorplanComponent.vue` | 284 | 6 | 12 — cross-branch 422 |
| `ItemComponent.vue` | 1276 | 21 | 45 — pricing_ssot violation L734-770 |
| `PosComponent.vue` | 2404 | 57 | 45 — shell, magic int statut L1390/L1413 |
| `PaymentComponent.vue` | 313 | 20 | 36 — mute props parent L251-265 |

Total bindings (grep) : **121**. Ordre merge §5 HYPERREVIEW à respecter.

---

## 3. Squelette des bindings (à compléter par cursor-composer en W0)

### 3.1 `ReceiptDuplicataMarker.vue` — risque 0
| Binding | Cible template v4 | Statut | Test garde | Service |
|---|---|---|---|---|
| `props.order.receipt_print_count` (l.23) | `.fk-pos-v4 .receipt__duplicata` (à confirmer) | KEEP | Vitest snapshot `printCount=2 → DUPLICATA #1 visible` | none |

### 3.2 `SkeletonGrid.vue` — risque 0
| Binding | Cible template v4 | Statut | Test garde | Service |
|---|---|---|---|---|
| `props.count` (l.11) | `.fk-pos-v4 .pos-grid--loading` | KEEP | Vitest snapshot `count=12 → 12 .skeleton-tile` | none |

### 3.3 `ReceiptComponent.vue` — risque 2
| Binding | Cible template v4 | Statut | Test garde | Service |
|---|---|---|---|---|
| `v-for tax_lines` (à localiser) | `.fk-pos-v4 .receipt__taxes ul` | TODO | `tests/js/posReceipt.spec.js` (à créer) | axios `/admin/order/print/{id}` |
| @click impression | `.fk-pos-v4 .receipt__btn-print` | TODO | E2E print confirm | none |
| ReceiptDuplicataMarker child slot | `.fk-pos-v4 .receipt__duplicata` | KEEP | inherited 3.1 | none |

### 3.4 `CreateCustomerAddressComponent.vue` — risque 8
| Binding | Cible template v4 | Statut | Test garde | Service |
|---|---|---|---|---|
| `@submit form address` | `.fk-pos-v4 .address-modal form` | TODO | Vitest "ne soumet pas si branch_id manquant" (HYPERREVIEW L13) | axios `/admin/customer-address` |
| `v-model fields` (8 occurrences) | inputs `.fk-pos-v4 .address-modal__field` | TODO | snapshot field count | none |

### 3.5 `ParkedOrdersComponent.vue` — risque 8
| Binding | Cible template v4 | Statut | Test garde | Service |
|---|---|---|---|---|
| @click reprise commande (l. ~150) | `.fk-pos-v4 .parked-list__item` | TODO | `tests/Feature/Pos/PosParkedOrderTest.php` (existe) | $store/posOrder + axios |
| `formatMoney(order.preview_total)` | `.fk-pos-v4 .parked-list__total` (display backend) | KEEP | snapshot | $store/posCart |
| branch_id filter (l.72 commenté) | aucun, scope backend | TODO documenter | PHPUnit branch isolation | axios `/admin/pos/parked-orders?branch_id=` |

### 3.6 `FloorplanComponent.vue` — risque 12
| Binding | Cible template v4 | Statut | Test garde | Service |
|---|---|---|---|---|
| @click table select | `.fk-pos-v4 .floorplan__table` | TODO | `tests/js/posFloorplan.spec.js` (existe — HYPERREVIEW L17) | $store/posOrder |
| 422 cross-branch (l.94 commenté) | toast `.fk-pos-v4 .toast--error` | TODO documenter | PHPUnit `FloorplanControllerTest` | axios `/admin/dining-tables` |
| @click reprise commande active | `.fk-pos-v4 .floorplan__active-order` | TODO | E2E flow | $store/posOrder |

### 3.7 `ItemComponent.vue` — risque 45 — **AUDIT BLOQUANT W0-A**
| Binding | Cible template v4 | Statut | Test garde | Service |
|---|---|---|---|---|
| **`totalPriceSetup()` (l.734-770) — pricing_ssot** | `.fk-pos-v4 .item-modal__total` | **TODO** — voir `reports/audit/W0_PRICING_SSOT_ITEMCOMPONENT_DECISION.md` | garde CI `pos:lint:pricing` (D1) | none (calc local — à conditionner) |
| `bumpPricingToCatalog()` (l.~795) | re-fetch convert_price | KEEP | Vitest "bump rebascule prix catalog" | axios `/admin/items/{id}/pricing` |
| `changeVariation/Extra` (l.731+) | `.fk-pos-v4 .item-modal__variations` | TODO | snapshot variations | none |
| 21 bindings totaux | à cartographier exhaustivement | TODO | matrice `posItem.spec.js` | mix |

### 3.8 `PosComponent.vue` — risque 45 — shell
| Binding | Cible template v4 | Statut | Test garde | Service |
|---|---|---|---|---|
| **L.1390 `[4,7,8]` magic int** (filter kiosk cash) | aucun template change requis | **REFACTOR REQUIS** → `OrderStatus.ACCEPT/PREPARING/PREPARED` (cf. `app/Enums/OrderStatus.php`) | grep CI `rg "\b[4-8,13]\b.*order_status"` doit échouer | $store + axios `/admin/kds-order` |
| **L.1413 `status: 13` magic int** (collect kiosk DELIVERED) | aucun | **REFACTOR REQUIS** → constante importée `OrderStatus.DELIVERED` | idem | axios `/admin/kds-order/change-status/{id}` |
| `loadKioskCashOrders` broadcast `OrderStatusChanged` (l.1188) | `.fk-pos-v4 .kiosk-cash-tray` | KEEP | Vitest broadcast → list refresh | Echo + axios |
| `idempotency_key` (l.1822) `${Date.now()}_${random}_${branch_id||0}` | aucun | **GUARD AJOUT** → assert branch_id != null (HYPERREVIEW L14) | Vitest "idem key contient branch_id" | axios `/admin/pos/orders` |
| Header / branch_id banner (33 occurrences branch_id) | `.fk-pos-v4 .pos-header__branch` | TODO | snapshot banner | $store |
| @click 57 occurrences | divers | TODO matrice | E2E | mix |

### 3.9 `PaymentComponent.vue` — risque 36 — **dernier merge**
| Binding | Cible template v4 | Statut | Test garde | Service |
|---|---|---|---|---|
| `$store.dispatch('posOrder/save', form)` (l.240) | bouton `.fk-pos-v4 .payment__pay-btn` | KEEP | Vitest "click → 1 dispatch" + double-tap test | $store/posOrder |
| **`this.$props.props.form.subtotal/discount/total = …` (l.251-265) — mute props** | aucun | **REFACTOR REQUIS** → `$emit('payment-reset')` (HYPERREVIEW L10) | Vitest snapshot post-erreur paiement | $store action `resetCart` |
| `posPaymentMethodEnum.CASH` (l.245) | `.fk-pos-v4 .payment__method-cash` | KEEP | snapshot enum | enum local |
| `openDrawer()` (l.247) | aucun | KEEP | mock test | drawer bridge |
| `appService.modalHide('#orderpayment')` (l.266) | `.fk-pos-v4 .payment-modal[role=dialog]` | RENAME selector | Playwright "modal hidden after pay" | appService |
| 20 bindings totaux | divers | TODO | E2E payment full | mix |

---

## 4. Statut JOIN (à mettre à jour à la fin de W0)

| SFC | Bindings recensés | Statut "TODO" restants | Ready W1 ? |
|---|---|---|---|
| `ReceiptDuplicataMarker.vue` | 1/1 | 0 | OUI |
| `SkeletonGrid.vue` | 1/1 | 0 | OUI |
| `ReceiptComponent.vue` | 3/3 | 2 | NON |
| `CreateCustomerAddressComponent.vue` | 2/8 | 2 | NON |
| `ParkedOrdersComponent.vue` | 3/6 | 1 | NON |
| `FloorplanComponent.vue` | 3/6 | 3 | NON |
| `ItemComponent.vue` | 4/21 | 1 + audit W0-A pending | NON |
| `PosComponent.vue` | 5/57 | 2 refactors + bindings | NON |
| `PaymentComponent.vue` | 5/20 | 2 refactors + bindings | NON |

**JOIN gate W1** : exige 9/9 SFC à statut "OUI". Estimation cursor-composer pour compléter : **1 jour ouvré**.

---

## 5. Refactors P0 identifiés (sortie de W0)

| Refactor | Fichier:ligne | Justification | Priorité |
|---|---|---|---|
| Magic int `[4,7,8]` → `OrderStatus.*` import | `PosComponent.vue:1390` | Invariant `order_status` (HYPERREVIEW L9, ST-2) | **P0** — blocant G2 |
| Magic int `status: 13` → `OrderStatus.DELIVERED` | `PosComponent.vue:1413` | idem | **P0** — blocant G2 |
| Mutate props parent → `$emit('payment-reset')` | `PaymentComponent.vue:251-265` | Anti-pattern + risque commit_before_dispatch (HYPERREVIEW L10) | **P0** — blocant G5 |
| Garde CI `pos:lint:pricing` | `package.json` + `ItemComponent.vue` | `pricing_ssot` (W0-A décision D1) | **P0** — blocant G3 |
| Guard idempotency_key `branch_id != null` | `PosComponent.vue:1822` | branch_id integrity (HYPERREVIEW L14) | **P1** — blocant G5 |

---

## 6. Trace
- `EXECUTE_DELEGATION: claude-terminal` (squelette)
- À compléter (statuts TODO → KEEP/RENAME/REFACTOR) par : `cursor-composer` (cartographie bindings restants) + `codex-terminal` si dispo (refactors P0)
- **Aucun** SFC modifié pendant la production de ce squelette — lecture seule.
- Ingest `memory/episodes/12_decisions_log.jsonl` post W1 (entry `pos_v4_binding_map_join_complete`).
```

## reports/baseline/POS_V4_PERF_BASELINE_W0.md
```markdown
# W0-C — Baseline performance + contamination CSS

**Cycle** : POS_V4_IMPL_EXEC_FINAL_2026-04-26  
**Phase** : W0 (livraison W0-C)  
**Auteur** : Claude terminal  
**Date** : 2026-04-26  
**Statut** : **BASELINE LOCKED** — toute régression vs ces chiffres ouvre STOP S4

---

## 1. Inventaire CSS courant (`resources/css/`)

| Fichier | Taille (bytes) | Rôle | Touche namespace `.fk-pos-v4` ? |
|---|---|---|---|
| `app.css` | 35 258 | Global app | **NON** (vérifié grep) |
| `kiosk-fallback.css` | 557 | Fallback Kiosk | NON |
| `kiosk-wizard.css` | 10 597 | Kiosk wizard | NON |
| `pos-a11y.css` | 631 | Accessibilité POS | NON (mais peut héberger `[data-pos-v4-disabled]` rollback) |
| `pos-v4.css` | **ABSENT** | À créer en W1 (stub livré W0-C) | À créer |

---

## 2. Grep contamination (gate G0 / G7 future)

### 2.1 Namespace `.fk-pos-v4` ou `.fk-dark` dans CSS et SFC POS
**Commande** :
```bash
grep -rE "fk-pos-v4|fk-dark" resources/css/ resources/js/components/admin/pos/ \
  --include="*.css" --include="*.vue"
```
**Résultat** : `0 lignes` (confirmé via Grep tool, no matches).  
**Verdict** : **CLEAN** — namespace pas encore introduit (attendu en W0).

### 2.2 Pollution `pos-v4|fk-pos|fk-dark` dans `app.css`
**Commande** :
```bash
grep -nE "pos-v4|fk-pos|fk-dark" resources/css/app.css
```
**Résultat** : `0 lignes`.  
**Verdict** : **CLEAN**.

### 2.3 Magic integers `order_status` dans SFC POS
**Commande** :
```bash
grep -nE "order_status\s*[\?!=]+|status\s*:\s*[0-9]{1,2}" \
  resources/js/components/admin/pos/*.vue
```
**Résultats actuels** (pré-W0) :
- `PosComponent.vue:1390` → `[4, 7, 8].includes(parseInt(o.order_status ?? o.status, 10))`
- `PosComponent.vue:1413` → `{ status: 13 }` (commentaire `// 13 = DELIVERED`)

**Verdict** : **VIOLATION P0 active** (HYPERREVIEW L9). Doit retourner 0 résultat post-W2.

### 2.4 Symétrie `OrderService` / `FrontendOrderService`
**Commande** :
```bash
grep -rE "FrontendOrderService|OrderService" resources/js/components/admin/pos/
```
**Résultat** : `0 lignes` — aucun SFC POS ne référence directement les services.  
**Verdict** : OK — la symétrie passe par `$store/posOrder` (mediator) et `axios` direct. À documenter dans BINDING_MAP colonne "Service appelé".

---

## 3. Inventaire SFC (lignes brutes)

| SFC | Lignes | % du total POS |
|---|---|---|
| `ReceiptDuplicataMarker.vue` | 70 | 1.3% |
| `SkeletonGrid.vue` | 19 | 0.4% |
| `ReceiptComponent.vue` | 479 | 8.9% |
| `CreateCustomerAddressComponent.vue` | 196 | 3.6% |
| `ParkedOrdersComponent.vue` | 345 | 6.4% |
| `FloorplanComponent.vue` | 284 | 5.3% |
| `ItemComponent.vue` | 1 276 | 23.7% |
| `PosComponent.vue` | 2 404 | 44.6% |
| `PaymentComponent.vue` | 313 | 5.8% |
| **Total** | **5 386** | 100% |

**Observations** :
- `PosComponent.vue` à lui seul = 44.6% du POS (2404 lignes). Confirme HYPERREVIEW : ce shell pèse lourd, son merge en position 5 est critique.
- `ItemComponent.vue` 23.7% (modal item + variations + extras + addons + pricing).

---

## 4. Baseline bundle (à compléter en W0 si `npm run build` disponible)

| Métrique | Valeur baseline | Cible budget plan | Tolérance |
|---|---|---|---|
| Chunk POS gzipped | **À MESURER** (`npm run build && ls -la public/build/assets/pos-*.js.gz`) | < 220 KB gzip (HYPERREVIEW + plan §6 KPI) | +0% (stricte) |
| LCP `/admin/pos` | **À MESURER** (Lighthouse CI ou Chrome devtools) | < 1.2 s | +5% transitoire W2-W3 |
| CLS `/admin/pos` | **À MESURER** | < 0.05 | strict |
| TTI `/admin/pos` | **À MESURER** | < 1.8 s | +10% transitoire |

**Procédure de mesure** (cursor-composer ou humain dev) :
```bash
# Build prod
npm run prod
# Ou via vite si en place
npm run build

# Tailles bundle
find public/build/assets -name "*.js" -exec ls -la {} \; | awk '{print $5,$9}' | sort -n
gzip -k public/build/assets/*pos*.js
ls -la public/build/assets/*pos*.js.gz | awk '{print $5,$9}'

# Lighthouse (npx)
npx lighthouse http://localhost:8000/admin/pos \
  --only-categories=performance \
  --output=json --output-path=reports/baseline/POS_V4_LIGHTHOUSE_W0.json
```

**Critère G0/W4** : régression > 5% sur LCP ou > 0% sur chunk gzip = **STOP S4**.

---

## 5. Configuration namespace prêt à l'emploi

Voir livrable joint : `resources/css/pos-v4.css` (stub W0-C — namespace `.fk-pos-v4` + scope `[data-pos-v4-disabled]` rollback).

---

## 6. Trace
- `EXECUTE_DELEGATION: claude-terminal`
- `AUDIT_CHANNEL: claude-terminal`
- Aucun build exécuté pendant W0 (mesures bundle laissées à dev humain pour préserver tokens et reproductibilité locale).
- Ingest `memory/episodes/12_decisions_log.jsonl` après W1 (entry `pos_v4_baseline_w0_locked`).
```

## resources/css/pos-v4.css
```css
/*
 * pos-v4.css — POS v4 design namespace stub
 *
 * Cycle: POS_V4_IMPL_EXEC_FINAL_2026-04-26 (W0-C livrable)
 * Auteur: Claude terminal
 * Statut: STUB — squelette namespace + rollback only.
 *         Le contenu visuel POS v4 sera ajouté en W1+ par cursor-composer
 *         (palette, typo, layout) à l'intérieur de .fk-pos-v4 EXCLUSIVEMENT.
 *
 * Règles strictes (HYPERREVIEW + plan EXEC_FINAL §G7):
 *  1. Aucun sélecteur racine (html, body, *) ici.
 *  2. Aucune règle hors `.fk-pos-v4 ...`.
 *  3. Aucune importation de typo / icônes globales (réservées à app.css).
 *  4. Aucune contamination des surfaces KIOSK / KDS / Admin.
 *  5. Garde CI: rg "fk-pos-v4|fk-dark" resources/css/app.css doit retourner 0.
 *
 * Rollback (HYPERREVIEW §9):
 *  - Poser `data-pos-v4-disabled` sur le conteneur racine POS désactive
 *    instantanément tous les styles (revert) sans déploiement backend.
 */

/* === 1. Namespace racine === */
.fk-pos-v4 {
    /* Espace réservé : variables CSS (palette, typo, espacement)
       à compléter en W1 après ADR couleur signé.
       Exemple (à remplir post-ADR):
       --fk-pos-v4-color-primary: #0084FF;
       --fk-pos-v4-color-accent:  #FF006B;
       --fk-pos-v4-radius-md:     12px;
       --fk-pos-v4-spacing-base:  8px;
    */
}

/* === 2. Rollback kill-switch === */
[data-pos-v4-disabled] .fk-pos-v4,
[data-pos-v4-disabled] .fk-pos-v4 * {
    all: revert;
}

/* === 3. Réservation des slots W1 (vide volontairement) ===
 *
 * Les blocs ci-dessous sont des ancres pour cursor-composer.
 * Chaque section sera remplie au fur et à mesure des merges SFC,
 * dans l'ordre §5 HYPERREVIEW (ReceiptDuplicataMarker → PaymentComponent).
 */

/* .fk-pos-v4 .receipt__duplicata { ... }            (W1, ordre 1)  */
/* .fk-pos-v4 .pos-grid--loading  { ... }            (W1, ordre 2)  */
/* .fk-pos-v4 .receipt__taxes     { ... }            (W2, ordre 3)  */
/* .fk-pos-v4 .address-modal      { ... }            (W2, ordre 4)  */
/* .fk-pos-v4 .parked-list        { ... }            (W2, ordre 5)  */
/* .fk-pos-v4 .floorplan          { ... }            (W3, ordre 6)  */
/* .fk-pos-v4 .item-modal         { ... }            (W3, ordre 7)  */
/* .fk-pos-v4 .pos-header,
   .fk-pos-v4 .pos-cart,
   .fk-pos-v4 .pos-grid           { ... }            (W3, ordre 8)  */
/* .fk-pos-v4 .payment-modal      { ... }            (W4, ordre 9)  */

/* === 4. Tests de fumée CSS (lecture humaine) ===
 *
 * Vérifications attendues après ce stub:
 *  - npm run dev / build : aucune erreur de syntaxe
 *  - Aucun changement visuel sur POS legacy (le namespace est inerte)
 *  - grep -nE "fk-pos-v4" resources/css/app.css → 0 résultat
 *  - grep -nE "fk-pos-v4" resources/css/pos-v4.css → ≥ 3 résultats (validation présence)
 */
```
