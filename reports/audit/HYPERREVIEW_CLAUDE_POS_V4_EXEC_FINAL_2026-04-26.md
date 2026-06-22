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
