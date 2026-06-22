# Claude → Codex (GPT-5 effort high) — Méga orchestration tests massifs avant mise en live — 2026-04-27

Auteur : Claude (orchestrateur senior FoodKing)
Exécutant : Codex GPT-5 effort high (capable d'orchestration multi-couche, autonome sur tâches denses)
Objet : ordres exhaustifs à donner à Codex pour valider à la perfection le système complet (kiosk + caisse + KDS) sur **design**, **affichage**, **fonctionnalité**, **logique**, **synchronisation**, **data**, **backend** avant mise en live.

Pattern : chaque prompt est **copier-coller direct** dans Codex. Codex doit décomposer le périmètre, générer des tests massifs, les boucler 5–10×, corriger les fails, produire un rapport validé. Aucun PASS partiel toléré.

---

## 0. Liste exhaustive des missions (D0 → D13)

```
D0   PRÉ-FLIGHT       — Inventaire surface réel + matrice de couverture
D1   DESIGN KIOSK     — Visual regression + responsive + a11y + i18n
D2   DESIGN POS       — idem POS
D3   DESIGN KDS       — idem KDS / OSS
D4   FUNC KIOSK       — Process complet × scénarios × itérations massives
D5   FUNC POS         — idem POS (sur place / à emporter / livraison / counter-collect)
D6   FUNC KDS         — Réception, transitions, broadcast, badge cash-at-counter
D7   SYNC CROSS       — Cross-channel realtime + multi-branche + network loss
D8   DATA INTEGRITY   — audit_logs chain, fiscal monotonic, outbox, composition snapshot, Z-reports
D9   STOCK V2         — Décrément atomique + release + rupture realtime + concurrence extrême
D10  PRICING SSOT     — Forge attempts + geocode block + symétrie POS/web
D11  AUTHZ / BRANCH   — Branch isolation (composer, orders, KDS, dashboard) + role matrix
D12  CHAOS / EDGE     — Network loss, WS reconnect, DB lock, queue overflow, kiosk reboot mid-order, idempotency
D13  CONSOLIDATION    — Rapport méga + go/no-go mise en live
```

**Total estimé** : 80–120 specs Playwright + 30–50 tests PHPUnit + 30 specs Vitest + 13 documents structurants.
Itérations : 5–10× sur chaque test critique.

---

## 1. Principes intangibles (à rappeler dans CHAQUE prompt à Codex)

1. **Frozen zones** : `app/Services/OrderService.php` et `app/Services/FrontendOrderService.php` ne sont touchables QUE par hunks autorisés explicitement (`HG-FROZEN-ORDERSERVICE-UNLOCK`).
2. **Pricing SSOT** : aucun calcul de prix final côté frontend. Uniquement preview UI explicitement marqué `// preview-only`.
3. **Branch isolation** : toute query/listener/event scope par `branch_id`.
4. **Dispatch-after-commit** : events critiques (`OrderCreated`, `OrderPaidAtCounter`, `OrderCanceled`, `StockLevelChanged`, `CatalogChanged`) dispatchés après `DB::commit()`.
5. **NF525** : `fiscal_sequence_no` reste `NULL` à la création kiosk cash-at-counter, alloué uniquement dans la transaction de confirm POS.
6. **OrderStatus enum** uniquement, pas de strings magiques.
7. **Symétrie OrderService ↔ FrontendOrderService** prouvée par `tools/audit/order-service-symmetry.mjs`.
8. **Run-Many** : 5× minimum chaque test critique. PASS = 5/5. Fail intermittent = REWORK.
9. **Self-audit** + **Claude review** obligatoires par mission.

---

## 2. Mission D0 — PRÉ-FLIGHT (à exécuter en premier)

### 2.1 Prompt à coller dans Codex

```
RÔLE : tu es Codex GPT-5 effort high. Tu opères comme orchestrateur autonome FoodKing sur le repo
/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt.

MISSION D0 — PRÉ-FLIGHT INVENTAIRE
Avant tout test massif, tu dois construire l'inventaire complet de surface et la matrice
de couverture. C'est la fondation des missions D1..D13.

LIVRABLES OBLIGATOIRES :
1. `reports/audit/D0_SURFACE_INVENTORY_2026-04-27.md`
   - Liste exhaustive des composants Vue (kiosk / POS / KDS / OSS / dashboard) avec data-testid
   - Liste exhaustive des routes API (admin + frontend) avec middleware + permission
   - Liste exhaustive des events Laravel + listeners + outbox persisters
   - Liste exhaustive des tables critiques + migrations qui les créent
   - Liste exhaustive des Echo channels avec authorisation
   - Liste exhaustive des PaymentStatus / OrderStatus / OrderType / PosPaymentMethod / Source enums avec valeurs

2. `reports/audit/D0_COVERAGE_MATRIX_2026-04-27.md`
   Matrice de couverture par domaine × type de test :

   | Domaine | Playwright E2E | Vitest UI | PHPUnit Feature | PHPUnit Unit | Manuel | Gap |
   | KIOSK process | x | x | x | - | - | ? |
   | POS process | x | x | x | - | - | ? |
   | KDS process | x | x | x | - | - | ? |
   | Sync cross-channel | x | x | x | - | - | ? |
   | Stock V2 | x | x | x | x | - | ? |
   | Pricing SSOT | - | x | x | x | - | ? |
   | Authz / branch | - | - | x | - | - | ? |
   | Persistence | - | - | x | x | - | ? |
   | Design / a11y | x | x | - | - | - | ? |
   | Resilience / chaos | x | - | x | - | - | ? |

   Pour chaque case : compter les tests existants (avec file:line), lister les gaps précis.

3. `reports/audit/D0_BUG_BACKLOG_2026-04-27.md`
   Liste exhaustive de tous les bugs/écarts visibles dans le repo actuel :
   - Bug kiosk waiting bloqué après paiement simulé (déjà connu, voir CLAUDE_MEGA_AUDIT_PLAN_PROCESS_AND_SYNC_2026-04-27.md mission C0)
   - Tout autre écart détecté pendant l'inventaire
   Format : ID | Sévérité (P0/P1/P2) | Composant | File:line | Description | Action

INSTRUCTIONS DENSES :
- Utilise grep/find/ast-grep agressivement pour discover.
- N'ajoute aucun fichier code. Mission documentation only.
- Lis les rapports déjà produits par Claude pour ne pas dupliquer :
  * reports/audit/CLAUDE_PRODUCT_COMPOSER_FINAL_EXECUTION_PLAN_2026-04-27.md
  * reports/audit/CLAUDE_MASTER_REVIEW_PRODUCT_COMPOSER_SYNC_2026-04-27.md
  * reports/audit/CLAUDE_MEGA_AUDIT_PLAN_PROCESS_AND_SYNC_2026-04-27.md

ALLOWLIST :
reports/audit/D0_SURFACE_INVENTORY_2026-04-27.md
reports/audit/D0_COVERAGE_MATRIX_2026-04-27.md
reports/audit/D0_BUG_BACKLOG_2026-04-27.md
missions/PROD-LIVE-VALIDATION-D0-PREFLIGHT/{allowlist.txt,execute_brief.md,input.json}
reports/audit/GPT_SELF_AUDIT_PROD-LIVE-VALIDATION-D0-PREFLIGHT.md
reports/post_execute_latest.log

CRITÈRES PASS :
- 3 documents complets, aucun "TODO" non explicité.
- Inventory matche le code réel (vérifié par sondage 10 entrées au hasard).
- Coverage matrix identifie au moins 30 gaps précis.

CRITÈRES REWORK :
- Inventory partiel (<80% surface réelle).
- Bug backlog manque le bug kiosk auto-return.

Réponds en commençant par l'analyse, puis les 3 fichiers complets.
```

### 2.2 Sortie attendue D0
3 documents qui servent de SSOT pour D1..D13.

---

## 3. Mission D1 — DESIGN KIOSK (visual regression + responsive + a11y + i18n)

### 3.1 Prompt à coller

```
RÔLE : Codex GPT-5 effort high, orchestrateur design-QA FoodKing.

MISSION D1 — DESIGN KIOSK : VALIDATION COMPLÈTE
Périmètre exclusif : tous les écrans frontend kiosk dans
resources/js/components/frontend/kiosk/*

Tu DOIS :

1. INVENTAIRE des écrans (lis D0_SURFACE_INVENTORY) :
   kiosk.idle, kiosk.login, kiosk.categories, kiosk.wizard,
   kiosk.cart, kiosk.loyalty, kiosk.upsell, kiosk.payment,
   kiosk.cash-instruction, kiosk.waiting, kiosk.confirmation,
   kiosk.error.{network|menu-unavailable|product-removed|payment-refused}.

2. POUR CHAQUE ÉCRAN, génère 5 tests Playwright :
   a. **Visual regression** : screenshot match baseline (1920x1080 + 1080x1920 portrait borne).
      Utilise `page.screenshot({ fullPage: true })` + `expect(snapshot).toMatchSnapshot('kiosk-X.png')`.
   b. **Responsive** : 1080x1920 (portrait borne typique) + 1280x800 (preview admin) + 2160x3840 (4K).
   c. **A11y axe-core** : `injectAxe()` + `checkA11y()` doit retourner 0 violations critical/serious.
   d. **i18n FR/EN** : switch locale, vérifier qu'aucun texte ne reste en l'autre langue ni en clé brute (`kiosk.X.Y`).
   e. **Tap targets** : tous boutons ≥ 64×64 px (touch borne ergonomie).

3. EXÉCUTE chaque test 5× avec `--repeat-each=5`.
   Si 4/5 PASS et 1/5 FAIL → debug, identifie root cause (CSS regression, font load timing, animation),
   corrige la cause dans la SOURCE Vue (pas le test), puis re-run.

4. AJOUTE captures de référence dans `tests/e2e/__screenshots__/kiosk/`.

5. PRODUIS `docs/design/KIOSK_DESIGN_VALIDATION_2026-04-27.md` :
   - Tableau écran × test × résultat (PASS/FAIL/itérations)
   - Captures embarquées
   - Violations a11y avant/après corrections
   - Recommandations design (typographie, contraste, hiérarchie)

ALLOWLIST :
tests/e2e/design/kiosk/k01-idle.spec.js
tests/e2e/design/kiosk/k02-categories.spec.js
tests/e2e/design/kiosk/k03-wizard.spec.js
tests/e2e/design/kiosk/k04-cart.spec.js
tests/e2e/design/kiosk/k05-loyalty.spec.js
tests/e2e/design/kiosk/k06-upsell.spec.js
tests/e2e/design/kiosk/k07-payment.spec.js
tests/e2e/design/kiosk/k08-cash-instruction.spec.js
tests/e2e/design/kiosk/k09-waiting.spec.js
tests/e2e/design/kiosk/k10-confirmation.spec.js
tests/e2e/design/kiosk/k11-error-network.spec.js
tests/e2e/design/kiosk/k12-error-menu-unavailable.spec.js
tests/e2e/design/kiosk/k13-error-product-removed.spec.js
tests/e2e/design/kiosk/k14-error-payment-refused.spec.js
tests/e2e/design/_shared/axe-helpers.js
tests/e2e/__screenshots__/kiosk/                                       # auto-generated baselines
playwright.config.js                                                    # add design project si nécessaire
docs/design/KIOSK_DESIGN_VALIDATION_2026-04-27.md
package.json                                                            # add @axe-core/playwright si manquant
missions/PROD-LIVE-VALIDATION-D1-DESIGN-KIOSK/{allowlist.txt,execute_brief.md,input.json}
reports/audit/GPT_SELF_AUDIT_PROD-LIVE-VALIDATION-D1-DESIGN-KIOSK.md
reports/post_execute_latest.log

CONTRAINTES STRICTES :
- AUCUNE modification logique business.
- Si une correction CSS/template est nécessaire, elle reste isolée à un seul fichier .vue à la fois,
  documentée dans le self-audit, et ne casse aucun test fonctionnel existant.
- Si une violation a11y nécessite un changement structurel HTML, signale-le dans le rapport
  comme "FOLLOWUP D1-A11Y" sans corriger immédiatement.

PASS = 14 écrans × 5 itérations × 5 tests = 350 runs verts + a11y 0 violation critical/serious.
REWORK = >2 violations critical/serious non corrigeables sans refonte structurelle.

Self-audit obligatoire. Réponds avec les fichiers complets.
```

---

## 4. Mission D2 — DESIGN POS

### 4.1 Prompt à coller

```
RÔLE : Codex GPT-5 effort high, orchestrateur design-QA FoodKing.

MISSION D2 — DESIGN POS : VALIDATION COMPLÈTE
Périmètre : resources/js/components/admin/pos/*

ÉCRANS À COUVRIR (tous data-testid à recenser depuis D0_SURFACE_INVENTORY) :
- POS dashboard (grid items + cart sidebar)
- Wizard POS (étapes pain/viandes/sauces/extras/boisson)
- Modal customer search / walk-in
- Modal payment (cash / card / mobile / ticket-restaurant)
- POS counter-collect panel (pending kiosk cash orders)
- POS floorplan (dining tables si pos_dine_in_enabled)
- POS live board (visibilité orders POS+kiosk)
- POS reports / Z-reports écran

POUR CHAQUE ÉCRAN, MÊME PATTERN QUE D1 :
1. Visual regression (1920x1080 standard caisse, 2560x1440)
2. Responsive (split panel collapse, sidebar)
3. A11y axe-core
4. i18n FR/EN
5. Keyboard shortcut tests (POS staff utilise clavier intensément)
6. Latency UI : action → feedback < 100ms (perçu instantané)
7. Print preview (mock impression)

ITÉRATIONS : 5× par test.

LIVRABLE DOC : docs/design/POS_DESIGN_VALIDATION_2026-04-27.md

ALLOWLIST (squelette à étendre selon écrans réels) :
tests/e2e/design/pos/p01-dashboard.spec.js
tests/e2e/design/pos/p02-wizard.spec.js
tests/e2e/design/pos/p03-customer-modal.spec.js
tests/e2e/design/pos/p04-payment-modal.spec.js
tests/e2e/design/pos/p05-counter-collect-panel.spec.js
tests/e2e/design/pos/p06-floorplan.spec.js
tests/e2e/design/pos/p07-liveboard.spec.js
tests/e2e/design/pos/p08-reports.spec.js
tests/e2e/__screenshots__/pos/
docs/design/POS_DESIGN_VALIDATION_2026-04-27.md
missions/PROD-LIVE-VALIDATION-D2-DESIGN-POS/*
reports/audit/GPT_SELF_AUDIT_PROD-LIVE-VALIDATION-D2-DESIGN-POS.md
reports/post_execute_latest.log

PASS = 8 écrans × 5 itérations × 7 tests = 280 runs verts + a11y propre.

Self-audit.
```

---

## 5. Mission D3 — DESIGN KDS / OSS

### 5.1 Prompt à coller

```
RÔLE : Codex GPT-5 effort high.

MISSION D3 — DESIGN KDS / OSS
Périmètre : resources/js/components/admin/kds/* + resources/js/components/admin/oss/*
+ écran public OSS (Order Status Screen) côté client.

ÉCRANS :
- KDS grid (tickets en cours + préparés)
- KDS ticket card (avec composition_snapshot + badge "PAIEMENT COMPTOIR" si applicable)
- KDS station view (filtre par station si kds_station configuré)
- OSS public (numéros prêts + en cours, pour clients dans la salle)
- OSS staff (overlay infos paiement)

POUR CHAQUE ÉCRAN :
1. Visual regression (KDS = 1920x1080 large display + 4K)
2. Lisibilité distance (font-size minimum, contraste WCAG AAA)
3. A11y
4. i18n
5. Realtime update (mock event Echo, vérifier card flip / nouveau ticket apparaît < 1s)
6. Capacity test : 50 tickets simultanés sans crash UI ni leak DOM
7. Long-running stability : 1h simulé, pas de memory leak

LIVRABLE DOC : docs/design/KDS_OSS_DESIGN_VALIDATION_2026-04-27.md

ITÉRATIONS : 5×.

ALLOWLIST :
tests/e2e/design/kds/kds01-grid.spec.js
tests/e2e/design/kds/kds02-ticket-card.spec.js
tests/e2e/design/kds/kds03-station-filter.spec.js
tests/e2e/design/kds/kds04-realtime-update.spec.js
tests/e2e/design/kds/kds05-capacity-50-tickets.spec.js
tests/e2e/design/oss/oss01-public.spec.js
tests/e2e/design/oss/oss02-staff.spec.js
tests/e2e/__screenshots__/kds/
tests/e2e/__screenshots__/oss/
docs/design/KDS_OSS_DESIGN_VALIDATION_2026-04-27.md
missions/PROD-LIVE-VALIDATION-D3-DESIGN-KDS/*
reports/audit/GPT_SELF_AUDIT_PROD-LIVE-VALIDATION-D3-DESIGN-KDS.md
reports/post_execute_latest.log

PASS = 7 écrans × 5 × 7 = 245 runs verts.
```

---

## 6. Mission D4 — FUNCTIONAL KIOSK (10 scénarios × 10 itérations)

### 6.1 Prompt à coller

```
RÔLE : Codex GPT-5 effort high.

MISSION D4 — KIOSK FONCTIONNALITÉ COMPLÈTE
Mode : tests massifs E2E + Vitest UI handlers + Feature backend.

PRÉ-CONDITION : C0 (kiosk auto-return fix) PASS avant D4.
Voir reports/audit/CLAUDE_MEGA_AUDIT_PLAN_PROCESS_AND_SYNC_2026-04-27.md mission C0.

TU GÉNÈRES 10 SCÉNARIOS E2E, chacun exécuté 10× :

K1  — CARD simple item (tacos M, 1 viande, paiement carte simulé)
K2  — CARD composition complète (tacos M, 2 viandes, sauces, extras, boisson, menu) — vérifier
      composition_snapshot complet en DB + fiscal_sequence_no alloué
K3  — CASH-AT-COUNTER (paiement comptoir) — payment_status=PENDING_COUNTER + fiscal_no=NULL
K4  — RUPTURE pendant wizard (1 choix indisponible) — badge + refus selection silencieux
K5  — RUPTURE qui apparaît PENDANT le wizard (autre client épuise stock concurremment) — kiosk
      détecte via Echo, refresh la projection, affiche bandeau
K6  — ABANDON timeout idle (60s sans action) — redirect kiosk.idle + cart vide
K7  — PROMO code valide → réduction backend (jamais frontend)
K8  — UPSELL accept (ajout produit suggéré au wizard avant paiement)
K9  — LOYALTY redeem points (réduction calculée backend, never frontend)
K10 — NETWORK LOSS pendant submit paiement → bannière + retry → submit OK quand WS revient

CHAQUE SCÉNARIO TEST :
- Assertion URL à chaque transition
- Assertion DOM : data-testid présents, valeurs cohérentes
- Assertion BACKEND :
  - DB : order créé, payment_status correct, fiscal_sequence_no allocation correcte
  - Audit : audit_logs row append + chain valide
  - Outbox : domain_events row pour OrderCreated/OrderPaidAtCounter/etc
  - Stock : stock_movements row delta=-N
- Assertion EVENTS Echo : le bon channel reçoit le bon broadcast_as
- Assertion FRONT : transition kiosk.confirmation → kiosk.idle automatique

ITÉRATIONS : `--repeat-each=10`.

CONCURRENCE : tu exécutes les 10 scénarios en parallèle (`workers: 4` Playwright)
au moins 3 fois pour détecter races conditions.

VITESSE : si une itération > 30s, profile et optimise (jamais sleep statique).

LIVRABLE DOC : docs/process/KIOSK_FUNCTIONAL_VALIDATION_2026-04-27.md
- Diagramme séquence par scénario
- Captures backend + frontend à chaque étape clé
- Liste des assertions clés
- Statistiques (durée P50/P95, flakiness rate, retry count)

ALLOWLIST :
tests/e2e/func/kiosk/k01-card-simple.spec.js
tests/e2e/func/kiosk/k02-card-composition.spec.js
tests/e2e/func/kiosk/k03-cash-at-counter.spec.js
tests/e2e/func/kiosk/k04-rupture-static.spec.js
tests/e2e/func/kiosk/k05-rupture-during-wizard.spec.js
tests/e2e/func/kiosk/k06-abandon-timeout.spec.js
tests/e2e/func/kiosk/k07-promo-code.spec.js
tests/e2e/func/kiosk/k08-upsell-accept.spec.js
tests/e2e/func/kiosk/k09-loyalty-redeem.spec.js
tests/e2e/func/kiosk/k10-network-loss-recovery.spec.js
tests/e2e/func/kiosk/_helpers.js
docs/process/KIOSK_FUNCTIONAL_VALIDATION_2026-04-27.md
missions/PROD-LIVE-VALIDATION-D4-FUNC-KIOSK/*
reports/audit/GPT_SELF_AUDIT_PROD-LIVE-VALIDATION-D4-FUNC-KIOSK.md
reports/post_execute_latest.log

CONTRAINTES :
- Aucune modification de OrderService / FrontendOrderService.
- Si un test révèle un bug fonctionnel, ouvre une mission FIX dédiée hors D4.

PASS = 10 scénarios × 10 itérations = 100 runs verts + 3 runs concurrents PASS.
```

---

## 7. Mission D5 — FUNCTIONAL POS (10 scénarios × 10 itérations)

### 7.1 Prompt à coller

```
RÔLE : Codex GPT-5 effort high.

MISSION D5 — POS FONCTIONNALITÉ COMPLÈTE
Symétrique à D4 mais côté caisse.

10 SCÉNARIOS :
P1  — Sur place walk-in cash
P2  — À emporter customer identifié card
P3  — Livraison customer + adresse geocodée + fee 5€/5km
P4  — Counter-collect : confirm kiosk pending (allocation fiscal_sequence_no atomique)
P5  — Counter-collect : cancel kiosk pending no-show (release stock, no fiscal)
P6  — Refund POS partiel (un item)
P7  — Refund POS total (tout l'order, payment_status=REFUNDED)
P8  — Discount manuel avec motif obligatoire (audit trail discount_reason)
P9  — Forge attempt : payload delivery_charge=999 → backend recompute = règle
P10 — Floorplan dining table assignment + libération à départ client

CHAQUE SCÉNARIO TEST :
- Assertions backend (DB, audit, outbox, stock, fiscal)
- Assertions UI POS (modal, ticket preview, cart)
- Assertions broadcast (KDS reçoit, kiosk pending list mis à jour si applicable)
- Authz : POS Operator OK, Delivery Boy 403

ITÉRATIONS : 10×.
CONCURRENCE : 3 runs parallèles avec workers:4.

LIVRABLE DOC : docs/process/POS_FUNCTIONAL_VALIDATION_2026-04-27.md

ALLOWLIST :
tests/e2e/func/pos/p01-dine-in-walkin-cash.spec.js
tests/e2e/func/pos/p02-takeaway-customer-card.spec.js
tests/e2e/func/pos/p03-delivery-geocode-fee.spec.js
tests/e2e/func/pos/p04-counter-collect-confirm.spec.js
tests/e2e/func/pos/p05-counter-collect-cancel.spec.js
tests/e2e/func/pos/p06-refund-partial.spec.js
tests/e2e/func/pos/p07-refund-total.spec.js
tests/e2e/func/pos/p08-discount-with-reason.spec.js
tests/e2e/func/pos/p09-forge-delivery-charge.spec.js
tests/e2e/func/pos/p10-floorplan-table-lifecycle.spec.js
tests/e2e/func/pos/_helpers.js
docs/process/POS_FUNCTIONAL_VALIDATION_2026-04-27.md
missions/PROD-LIVE-VALIDATION-D5-FUNC-POS/*
reports/audit/GPT_SELF_AUDIT_PROD-LIVE-VALIDATION-D5-FUNC-POS.md
reports/post_execute_latest.log

PASS = 100 runs + 3 concurrents.
```

---

## 8. Mission D6 — FUNCTIONAL KDS

### 8.1 Prompt à coller

```
RÔLE : Codex GPT-5 effort high.

MISSION D6 — KDS FONCTIONNALITÉ COMPLÈTE
Périmètre : resources/js/components/admin/kds/* + KitchenDisplaySystemController + service.

8 SCÉNARIOS :
KDS1  — Réception ticket POS en realtime via Echo
KDS2  — Réception ticket kiosk en realtime
KDS3  — Réception ticket kiosk PENDING_COUNTER avec badge "PAIEMENT COMPTOIR"
KDS4  — Transition status PENDING → ACCEPT → PREPARING → PREPARED
KDS5  — Cancel : retrait ticket de la grid
KDS6  — Multi-station : ticket avec items station "grill" + items station "boisson" → split
KDS7  — Charge 50 tickets simultanés (capacity)
KDS8  — Network loss → reconnect → resync ordres en cours

ASSERTIONS :
- composition_snapshot visible et complet
- Boutons transitions (Accepter, Préparé, Servi) selon role
- Realtime sans reload manuel
- Branch isolation : KDS branche A ne reçoit pas event branche B
- Audit log écrit pour chaque transition KDS

ITÉRATIONS : 10× scenario simple, 5× scenario charge.

LIVRABLE DOC : docs/process/KDS_FUNCTIONAL_VALIDATION_2026-04-27.md
+ schéma séquence cycle de vie ticket KDS.

ALLOWLIST :
tests/e2e/func/kds/kds01-receive-pos.spec.js
tests/e2e/func/kds/kds02-receive-kiosk.spec.js
tests/e2e/func/kds/kds03-pending-counter-badge.spec.js
tests/e2e/func/kds/kds04-transitions.spec.js
tests/e2e/func/kds/kds05-cancel-removal.spec.js
tests/e2e/func/kds/kds06-multi-station.spec.js
tests/e2e/func/kds/kds07-capacity-50.spec.js
tests/e2e/func/kds/kds08-network-loss-resync.spec.js
docs/process/KDS_FUNCTIONAL_VALIDATION_2026-04-27.md
missions/PROD-LIVE-VALIDATION-D6-FUNC-KDS/*
reports/audit/GPT_SELF_AUDIT_PROD-LIVE-VALIDATION-D6-FUNC-KDS.md
reports/post_execute_latest.log

PASS = 80 runs.
```

---

## 9. Mission D7 — SYNC CROSS-CHANNEL (massif)

### 9.1 Prompt à coller

```
RÔLE : Codex GPT-5 effort high.

MISSION D7 — SYNCHRONISATION CROSS-CHANNEL MASSIVE
Le test ultime de la cohérence FoodKing.

10 SCÉNARIOS RÉPÉTÉS 10× CHACUN :
S1  — Kiosk order CARD → KDS reçoit en realtime < 2s SANS reload
S2  — POS order → KDS + POS live board mis à jour
S3  — Kiosk PENDING_COUNTER → POS counter-collect panel mis à jour < 2s
S4  — KDS clic Préparé → OSS reçoit + kiosk waiting (si encore visible) marque ready
S5  — POS cancel kiosk pending → KDS retire ticket + stock release + audit log
S6  — Multi-branche : event branche A NON reçu sur branche B (assertion CRITIQUE)
S7  — Network loss WS → fallback polling → resync à reconnect
S8  — 5 kiosks + 3 POS simultanés sur même branche → tous cohérents (KDS reçoit 8 events distincts)
S9  — Photo upload admin → cache kiosk invalidé < 5s + nouveau menu projeté
S10 — Stock épuisé concurremment → tous les kiosks affichent badge rupture sans reload

ASSERTIONS UNIVERSELLES :
- Chaque event broadcast après commit DB (assertion sur ordre)
- correlation_id tracé bout en bout
- Idempotency : double dispatch même event = 1 row outbox
- Branch isolation absolue
- WS auth Pusher correcte

OUTILS REQUIS :
- Mock Echo server (Pusher Mock ou Soketi local) pour CI
- Helpers Playwright multi-page (ouvre kiosk + POS + KDS dans 3 pages, observe en parallèle)

LIVRABLE DOC : docs/sync/CROSS_CHANNEL_SYNC_MASSIVE_2026-04-27.md
+ diagramme séquence par event (Mermaid)
+ matrice channels Echo / authorization / fallback polling

ALLOWLIST :
tests/e2e/sync/s01-kiosk-card-to-kds.spec.js
tests/e2e/sync/s02-pos-to-kds-liveboard.spec.js
tests/e2e/sync/s03-kiosk-pending-to-pos.spec.js
tests/e2e/sync/s04-kds-prepared-to-oss-kiosk.spec.js
tests/e2e/sync/s05-pos-cancel-propagation.spec.js
tests/e2e/sync/s06-multi-branch-isolation.spec.js
tests/e2e/sync/s07-network-loss-reconnect.spec.js
tests/e2e/sync/s08-massive-concurrency-5k3p.spec.js
tests/e2e/sync/s09-photo-upload-cache-invalidation.spec.js
tests/e2e/sync/s10-stock-rupture-broadcast.spec.js
tests/e2e/sync/_multi-page-helpers.js
docs/sync/CROSS_CHANNEL_SYNC_MASSIVE_2026-04-27.md
missions/PROD-LIVE-VALIDATION-D7-SYNC-MASSIVE/*
reports/audit/GPT_SELF_AUDIT_PROD-LIVE-VALIDATION-D7-SYNC-MASSIVE.md
reports/post_execute_latest.log

PASS = 100 runs verts. CRITIQUE : S6 multi-branche doit être 10/10 vert (sécurité).
```

---

## 10. Mission D8 — DATA INTEGRITY (audit_logs / fiscal / outbox / composition / Z-reports)

### 10.1 Prompt à coller

```
RÔLE : Codex GPT-5 effort high.

MISSION D8 — INTÉGRITÉ BACKEND
Tests PHPUnit lourds sur la persistance et la traçabilité.

7 TESTS À GÉNÉRER (chacun avec 5–20 cas internes) :

T1 — AuditLogChainIntegrityTest
- 100 audit logs concurrents → chaîne hash valide
- Tentative update / delete → bloquée (observer)
- HMAC SHA-256 vérifiée par script externe

T2 — FiscalSequenceMonotonicTest
- 100 séquences parallèles même branche → 1..100 sans gap
- Rollback transaction → séquence non consommée
- Reprise après crash → repart à MAX+1

T3 — DomainEventsIdempotencyTest
- Dispatch même event 10× même correlation_id → 1 row outbox
- Worker process row → marqué processed_at
- Replay processed → no-op

T4 — CompositionSnapshotImmutableTest
- Order_item composition_snapshot frozen
- Modifier item parent (rename viande) → snapshot inchangé
- NF525 conformité

T5 — ZReportConsistencyTest
- N orders mix paid/canceled/refunded
- Z-report totaux = SUM(orders) ?
- Re-run même date → résultat identique
- Z-report cross-channel : POS + kiosk dans même Z-report branche

T6 — OutboxOrderingTest
- 50 events parallèles → ordering préservé par aggregate_id
- Pas d'inversion temporelle dans la queue

T7 — TamperDetectionTest
- Modifier directement un audit_logs.payload via SQL
- Script de vérification HMAC détecte la corruption
- Alerting (log warning) déclenché

ITÉRATIONS PHPUnit : 5×. Pour T1/T6 (concurrence) : 10×.

LIVRABLE DOC : docs/persistence/DATA_INTEGRITY_VALIDATION_2026-04-27.md

ALLOWLIST :
tests/Feature/Integrity/AuditLogChainIntegrityTest.php
tests/Feature/Integrity/FiscalSequenceMonotonicTest.php
tests/Feature/Integrity/DomainEventsIdempotencyTest.php
tests/Feature/Integrity/CompositionSnapshotImmutableTest.php
tests/Feature/Integrity/ZReportConsistencyTest.php
tests/Feature/Integrity/OutboxOrderingTest.php
tests/Feature/Integrity/TamperDetectionTest.php
tools/audit/verify-audit-chain.php                                      # script externe pour T1/T7
docs/persistence/DATA_INTEGRITY_VALIDATION_2026-04-27.md
missions/PROD-LIVE-VALIDATION-D8-DATA-INTEGRITY/*
reports/audit/GPT_SELF_AUDIT_PROD-LIVE-VALIDATION-D8-DATA-INTEGRITY.md
reports/post_execute_latest.log

PASS = 7 tests × 5 itérations + T1/T6 × 10 = 45 runs + 0 corruption détectée hors test.
```

---

## 11. Mission D9 — STOCK V2 EXTRÊME

### 11.1 Prompt à coller

```
RÔLE : Codex GPT-5 effort high.

MISSION D9 — STOCK V2 ROBUSTESSE EXTRÊME
Tu pousses le stock V2 dans ses retranchements.

10 SCÉNARIOS :
ST1 — Décrément atomique stock=3, 5 orders parallèles → 3 succès, 2 rupture
ST2 — Décrément atomique stock=100, 100 orders parallèles → 100 succès exactement
ST3 — Release sur cancel : stock=3, decrement 1, cancel → stock=3 (idempotent)
ST4 — Release sur refund partiel : stock=3, decrement 2, refund 1 item → stock=2
ST5 — Rupture realtime : badge apparaît sur 10 kiosks ouverts en < 3s
ST6 — Stockable polymorphe : item ET variation ET extra ont leur stock indépendant
ST7 — Branch isolation : décrément branche A n'affecte pas branche B
ST8 — Multi-line order avec 1 line en rupture → rollback transaction complète, 0 décrément
ST9 — Compensation race : decrement OK, OrderCreated dispatch, listener race condition tester
ST10 — 1000 mouvements stock_movements en 60s → pas de stock négatif, pas de duplicate idempotency_key

ITÉRATIONS : 10× pour ST1-ST8, 5× pour ST9-ST10.

ASSERTIONS :
- Aucune valeur stock_levels.on_hand négative jamais
- Toutes contraintes CHECK respectées
- Symétrie OrderService ↔ FrontendOrderService prouvée par tools/audit/order-service-symmetry.mjs
- stock_movements append-only (pas d'update)
- Pas de fuite branch_id dans queries (assertion grep sur chaque test)

LIVRABLE DOC : docs/stock/STOCK_V2_EXTREME_VALIDATION_2026-04-27.md

ALLOWLIST :
tests/Feature/Stock/Extreme/ST01ConcurrentDecrement3vs5Test.php
tests/Feature/Stock/Extreme/ST02ConcurrentDecrement100vs100Test.php
tests/Feature/Stock/Extreme/ST03IdempotentReleaseOnCancelTest.php
tests/Feature/Stock/Extreme/ST04PartialRefundReleaseTest.php
tests/e2e/stock/st05-rupture-realtime-broadcast.spec.js
tests/Feature/Stock/Extreme/ST06StockablePolymorphicTest.php
tests/Feature/Stock/Extreme/ST07BranchIsolationTest.php
tests/Feature/Stock/Extreme/ST08MultiLineRollbackTest.php
tests/Feature/Stock/Extreme/ST09CompensationRaceTest.php
tests/Feature/Stock/Extreme/ST10MassiveMovementsTest.php
docs/stock/STOCK_V2_EXTREME_VALIDATION_2026-04-27.md
missions/PROD-LIVE-VALIDATION-D9-STOCK-EXTREME/*
reports/audit/GPT_SELF_AUDIT_PROD-LIVE-VALIDATION-D9-STOCK-EXTREME.md
reports/post_execute_latest.log

PASS = 95 runs + 0 stock négatif sur 10000+ ops.
```

---

## 12. Mission D10 — PRICING SSOT FORGE

### 12.1 Prompt à coller

```
RÔLE : Codex GPT-5 effort high.

MISSION D10 — PRICING SSOT VALIDATION (anti-forge)
Tu prouves qu'AUCUN client ne peut payer moins que ce que dit le backend.

15 ATTAQUES À TESTER (chacune × 5 itérations) :

A1  — POS forge subtotal=0.01 → backend recompute = vraie valeur
A2  — Web forge subtotal=0.01 → idem
A3  — Kiosk forge subtotal=0.01 → idem
A4  — POS forge total=0.01 → backend rejette ou recompute
A5  — Forge delivery_charge=0 sur DELIVERY web → backend recompute selon distance
A6  — Forge delivery_charge=999 → backend recompute = règle 5€/5km
A7  — Forge variation price (ex: viande premium à 0€ via JSON) → backend lit DB authoritative
A8  — Forge extra price → idem
A9  — Forge addon price → idem
A10 — Forge tax_id pour passer en taux 0% → backend lit tax_id depuis Item.tax_id
A11 — Forge coupon_id inexistant → 422 ou ignore
A12 — Forge loyalty redeem points > balance → backend rejette
A13 — Forge order_type DELIVERY mais pas d'address_id → 422 (cast strict)
A14 — Forge geocode_status=OK alors que adresse invalide → DeliveryQuoteService block
A15 — Replay attack : même quote_token, double submit → idempotent

ITÉRATIONS : 5×.

CHAQUE TEST :
- Vérifier que le payload DB final ≠ payload client envoyé (preuve de recomputation)
- Vérifier audit_log capture la tentative (action='pricing.forge_detected' si applicable)
- Vérifier que le total final stocké = ce que renvoie PricingService

LIVRABLE DOC : docs/security/PRICING_SSOT_FORGE_VALIDATION_2026-04-27.md
+ tableau attaque × résultat backend × chemin code consommateur

ALLOWLIST :
tests/Feature/Security/Pricing/A01PosForgeSubtotalTest.php
tests/Feature/Security/Pricing/A02WebForgeSubtotalTest.php
tests/Feature/Security/Pricing/A03KioskForgeSubtotalTest.php
tests/Feature/Security/Pricing/A04PosForgeTotalTest.php
tests/Feature/Security/Pricing/A05WebDeliveryChargeZeroForgeTest.php
tests/Feature/Security/Pricing/A06DeliveryChargeMaxForgeTest.php
tests/Feature/Security/Pricing/A07ForgeVariationPriceTest.php
tests/Feature/Security/Pricing/A08ForgeExtraPriceTest.php
tests/Feature/Security/Pricing/A09ForgeAddonPriceTest.php
tests/Feature/Security/Pricing/A10ForgeTaxIdTest.php
tests/Feature/Security/Pricing/A11ForgeCouponIdTest.php
tests/Feature/Security/Pricing/A12ForgeLoyaltyRedeemTest.php
tests/Feature/Security/Pricing/A13ForgeOrderTypeWithoutAddressTest.php
tests/Feature/Security/Pricing/A14GeocodeStatusForgeTest.php
tests/Feature/Security/Pricing/A15ReplayAttackTest.php
docs/security/PRICING_SSOT_FORGE_VALIDATION_2026-04-27.md
missions/PROD-LIVE-VALIDATION-D10-PRICING-SSOT-FORGE/*
reports/audit/GPT_SELF_AUDIT_PROD-LIVE-VALIDATION-D10-PRICING-SSOT-FORGE.md
reports/post_execute_latest.log

PASS = 15 attaques × 5 = 75 runs verts. Toute attaque qui passe = P0 immédiat.
```

---

## 13. Mission D11 — AUTHZ / BRANCH ISOLATION (matrix complète)

### 13.1 Prompt à coller

```
RÔLE : Codex GPT-5 effort high.

MISSION D11 — AUTHZ ROLE × ROUTE × BRANCH MATRIX
Tu construis la matrice complète d'autorisation et tu prouves chaque cellule.

ÉTAPES :

1. INVENTAIRE rôles (Spatie) actuels :
   Admin, Tenant Admin, Branch Admin, Branch Manager, POS Operator, Delivery Boy, Customer

2. INVENTAIRE routes critiques (admin + frontend) avec middlewares + permissions
   Sortie : `reports/audit/D11_AUTHZ_MATRIX_2026-04-27.md`

3. POUR CHAQUE COMBINAISON (rôle × route critique × branch_scope) GÉNÈRE 1 TEST :
   Format : `User role=X branch=A → POST/GET route Y avec resource branch=A → expected 200`
            `User role=X branch=A → POST/GET route Y avec resource branch=B → expected 403`

   Routes critiques minimum :
   - /api/admin/composer/items/{item}/profile (compose own/foreign branch)
   - /api/admin/composer/profiles/{profile}/steps (own/foreign)
   - /api/admin/composer/steps/{step} (own/foreign)
   - /api/admin/pos/quote
   - /api/admin/pos/store
   - /api/admin/pos/counter-collect/pending (filtre branch)
   - /api/admin/pos/counter-collect/{order}/confirm (own/foreign)
   - /api/admin/pos/counter-collect/{order}/cancel (own/foreign)
   - /api/admin/kds/* (lecture, transitions)
   - /api/admin/items/* (CRUD)
   - /api/admin/categories/* (CRUD)
   - /api/admin/item/change-image/{item}
   - /api/admin/reports/* (Z-report, ventes)

4. EXÉCUTE matrix → s'attendre à ~7 rôles × ~15 routes × 2 scopes = ~210 cas.
   Itérations : 3× chaque.

5. SI UN CAS RÉVÈLE UNE FAILLE (403 attendu mais 200 obtenu) → c'est un P0 sécurité,
   ouvrir une mission FIX immédiate hors D11 et stopper la mission ici.

LIVRABLE DOC : docs/security/AUTHZ_BRANCH_ISOLATION_MATRIX_2026-04-27.md
- Matrice complète (markdown table)
- Cas testés / cas en gap
- Procédures d'audit récurrent (CI hook)

ALLOWLIST :
reports/audit/D11_AUTHZ_MATRIX_2026-04-27.md                            # documentation inventaire
tests/Feature/Security/Authz/D11AuthzMatrixTest.php                     # test data-provider exhaustif
tests/Feature/Security/Authz/D11AuthzMatrixDataProviders.php
docs/security/AUTHZ_BRANCH_ISOLATION_MATRIX_2026-04-27.md
missions/PROD-LIVE-VALIDATION-D11-AUTHZ-BRANCH-MATRIX/*
reports/audit/GPT_SELF_AUDIT_PROD-LIVE-VALIDATION-D11-AUTHZ-BRANCH-MATRIX.md
reports/post_execute_latest.log

PASS = 100% cas matrix verts (aucune escalade). Tout fail = P0.
```

---

## 14. Mission D12 — CHAOS / EDGE / RESILIENCE

### 14.1 Prompt à coller

```
RÔLE : Codex GPT-5 effort high.

MISSION D12 — CHAOS ENGINEERING
Tu casses volontairement le système et tu vérifies qu'il récupère sans corruption.

10 SCÉNARIOS CHAOS :
C1  — Network loss kiosk pendant submit paiement → retry à reconnect, pas de double order
C2  — Network loss POS pendant counter-collect confirm → retry idempotent, fiscal pas double
C3  — DB lock contention : 10 orders simultanés branche A + 10 branche B → pas de deadlock
C4  — Echo server crash mid-flight → fallback polling déclenché, ordres reçus à reconnect
C5  — Worker queue overflow : 1000 OrderCreated jobs en 10s → tous traités, pas de drop
C6  — Kiosk reboot mid-order (mock localStorage clear pendant wizard) → idle reset, cart vide
C7  — POS device crash après fiscal_sequence_no alloué mais avant save → rollback, sequence non consommée
C8  — Concurrent POS staff confirme même counter order → 1 succès, autre idempotent (pas de double fiscal)
C9  — Refund après Z-report : permission refusée OU audit log alerting selon politique business
C10 — Clock skew : POS device 5min avant serveur → fiscal_sequence ordering préservé (server-side)

ITÉRATIONS : 5× chaos, 10× pour C8 (idempotency double-confirm).

OUTILS :
- Toxiproxy ou playwright route interception pour mock network failure
- Laravel queue mock + worker boot
- DB transaction injection failures via service provider override en environnement test

LIVRABLE DOC : docs/resilience/CHAOS_ENGINEERING_VALIDATION_2026-04-27.md

ALLOWLIST :
tests/e2e/chaos/c01-kiosk-network-loss-submit.spec.js
tests/e2e/chaos/c02-pos-network-loss-counter-confirm.spec.js
tests/Feature/Chaos/C03DbLockContentionTest.php
tests/e2e/chaos/c04-echo-crash-fallback.spec.js
tests/Feature/Chaos/C05QueueOverflowTest.php
tests/e2e/chaos/c06-kiosk-reboot-mid-order.spec.js
tests/Feature/Chaos/C07PosCrashFiscalRollbackTest.php
tests/Feature/Chaos/C08DoubleConfirmIdempotencyTest.php
tests/Feature/Chaos/C09RefundAfterZReportTest.php
tests/Feature/Chaos/C10ClockSkewFiscalOrderingTest.php
docs/resilience/CHAOS_ENGINEERING_VALIDATION_2026-04-27.md
missions/PROD-LIVE-VALIDATION-D12-CHAOS/*
reports/audit/GPT_SELF_AUDIT_PROD-LIVE-VALIDATION-D12-CHAOS.md
reports/post_execute_latest.log

PASS = 10 scénarios × 5 = 50 runs (avec C8 × 10) = 60 runs verts.
```

---

## 15. Mission D13 — CONSOLIDATION + go/no-go LIVE

### 15.1 Prompt à coller

```
RÔLE : Codex GPT-5 effort high. Synthèse finale.

MISSION D13 — RAPPORT MÉGA + DÉCISION MISE EN LIVE
Pas de code. Synthèse exhaustive.

LIVRABLE OBLIGATOIRE :
reports/audit/CODEX_FINAL_PROD_LIVE_VALIDATION_2026-04-27.md

CONTENU :
1. Récapitulatif missions D0..D12
   - Statut par mission : PASS / REWORK / HOLD
   - Nombre de runs : Playwright + Vitest + PHPUnit
   - Taux de réussite global
   - Durée moyenne / P95 par scénario
   - Flakies détectés et corrigés

2. Métriques agrégées :
   - Total tests créés
   - Total runs exécutés
   - Couverture domaine x type test (matrice D0 mise à jour)
   - Performances backend P95 par endpoint critique

3. Findings résiduels :
   - Liste P0/P1/P2 avec file:line + sévérité + recommandation
   - Si 0 P0 et 0 P1 → green light pour live

4. Documentation produite :
   - Liste exhaustive des docs livrés D0..D12
   - Avec liens et résumé 1 phrase chacun

5. Décision finale :
   - `LIVE_DECISION: GO_LIVE` si tous PASS et 0 P0/P1
   - `LIVE_DECISION: HARDWARE_UAT_THEN_LIVE` si all green code mais hardware UAT manquant
   - `LIVE_DECISION: REWORK_BEFORE_LIVE` si gaps avec scope précis

6. Recommandations post-live :
   - Monitoring critical metrics (latency, error rate, fiscal sequence gap detection)
   - Alerting (Sentry / Datadog patterns)
   - Runbook on-call (incident response sur écarts NF525, branch isolation, stock négatif)

7. Annexes :
   - Captures Playwright clés
   - Logs significatifs
   - Diff symétrie OrderService / FrontendOrderService

CONTRAINTES :
- Pas un copier-coller des self-audits Codex. Synthèse critique avec ton propre jugement.
- Si tu détectes une incohérence entre missions D0..D12, signale-la explicitement.

ALLOWLIST :
reports/audit/CODEX_FINAL_PROD_LIVE_VALIDATION_2026-04-27.md
missions/PROD-LIVE-VALIDATION-D13-CONSOLIDATION/*
reports/audit/GPT_SELF_AUDIT_PROD-LIVE-VALIDATION-D13-CONSOLIDATION.md

PASS = Document unique exhaustif + décision claire.
```

---

## 16. Ordre d'exécution recommandé

```
[J1]    D0 (pré-flight inventaire)        ← FONDATION pour tout le reste
[J2]    D1 // D2 // D3 (design)           ← parallèle massif
[J3-4]  D4 // D5 // D6 (functional)       ← parallèle, lourd
[J5]    D7 (sync cross-channel)           ← consomme fixtures D4-D6
[J6]    D8 // D9 (data integrity + stock) ← parallèle
[J7]    D10 // D11 (pricing forge + authz matrix) ← parallèle
[J8]    D12 (chaos)                       ← seul, lourd
[J9]    D13 (consolidation)
[J10]   Si LIVE_DECISION = GO_LIVE → mise en production
        Sinon → REWORK puis re-run D13
```

Estimation totale : **~1500 runs Playwright + 200 PHPUnit + 100 Vitest + 13 documents structurants**.
Effort Codex : 8–12 jours homme.

---

## 17. Règles d'arrêt et de qualité

À RAPPELER DANS CHAQUE PROMPT À CODEX :

1. **5/5 ou 10/10 ITÉRATIONS PASS = PASS**. 4/5 = REWORK.
2. Test flaky → debug **root cause** (race, sleep statique, async non attendu, assertion sur DOM avant render). **Jamais de retry magic.**
3. **Aucune édition hors allowlist**. Si besoin, escalade vers Claude pour étendre l'allowlist.
4. **Aucune édition** `OrderService.php` / `FrontendOrderService.php` hors hunks autorisés.
5. **Pas de pricing frontend autoritaire**. Tout calcul de prix `// preview-only` documenté.
6. **Pas de magic strings**. Uniquement enums.
7. **Symétrie OrderService ↔ FrontendOrderService** prouvée à chaque modification.
8. **Si découverte P0 sécurité** (ex: forge attack passe, branch isolation cassée, NF525 violé) :
   stop mission, ouvrir mission FIX dédiée, alerte humaine via le rapport.

---

## 18. Stratégie d'orchestration Codex (méta)

Pour chaque mission, Codex orchestrate ainsi :

```
1. LIRE le prompt + D0_SURFACE_INVENTORY + D0_COVERAGE_MATRIX
2. DÉCOMPOSER en sous-tâches concrètes (ex: D4 = 10 spec files + 1 helper + 1 doc)
3. CRÉER mission folder avec input.json renvoyant cette décomposition
4. GÉNÉRER les fichiers code/test dans l'allowlist
5. RUN-MANY 5–10× chaque test critique
6. SI fail → analyse logs Playwright/PHPUnit, identifie root cause, fix, retry
7. QUAND tous green → générer la documentation
8. SELF-AUDIT avec liste exhaustive des fichiers + assertions + invariants vérifiés
9. PRODUIRE rapport mission + post_execute_latest.log
10. ATTENDRE Claude review post-mission avant de lancer la mission suivante
```

Pour les missions parallélisables (D1+D2+D3, D4+D5+D6, D8+D9, D10+D11), Codex peut spawner
des sous-agents internes (multi-thread orchestration GPT-5). Sinon séquentiel.

---

## 19. Checklist pour le humain qui lance Codex

Avant de coller un prompt :

- [ ] Vérifier que le repo est propre ou à un commit stable.
- [ ] Vérifier que les missions précédentes (B-FIX-1, B-FIX-2, C0) sont PASS.
- [ ] Configurer environnement test (PHP, Node, Playwright browsers, MySQL/PG, Redis).
- [ ] FISCAL_AUDIT_SECRET et FISCAL_Z_REPORT_SECRET définis dans .env.
- [ ] Soketi / Pusher mock disponible pour D7.
- [ ] Coller le prompt entier.
- [ ] Attendre que Codex termine la mission.
- [ ] Lire le self-audit + lancer Claude review.
- [ ] Si REWORK, faire 2ème passe max ; sinon escalade humaine.

---

## 20. Notes finales

- Ce plan **renforce** les missions précédentes (B0..B9, C0..C10) sans les remplacer.
- Le bug "kiosk waiting bloqué après paiement simulé" (C0) DOIT être fixé avant D4.
- Hardware UAT reprend uniquement après D13 PASS + LIVE_DECISION ≠ REWORK.
- Si Codex détecte une carence du présent plan (oubli de domaine, scénario manquant), il a
  l'autorité de l'inclure dans son self-audit comme `FOLLOWUP D<n>` et de proposer une mission
  d'extension.

---

Document généré par Claude le 2026-04-27.
Ordres prêts à exécution. Codex peut commencer **D0** immédiatement.
