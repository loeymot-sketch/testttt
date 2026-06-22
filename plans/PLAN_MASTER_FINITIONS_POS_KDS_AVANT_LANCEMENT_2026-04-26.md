# Master Plan — Finitions POS + KDS avant lancement production — 2026-04-26

**Auteur** : cursor-claude (orchestrateur)
**Source** : `reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md` (Claude terminal, 15 findings, NOT-READY 4/10)
**Brief amont** : `reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_BRIEF_2026-04-26.md`
**Active gates** : 5 gates ouverts (`docs/gates/GATE_LOG.md` Trail courant)
**Objectif** : passer POS + KDS de NOT-READY 4/10 → READY-WITH-CONDITIONS 8+/10 pour lancement multi-branches opérateur réel.

---

## 0. Vue d'ensemble — ce qui doit être traité

| Bucket | Findings | Effort agrégé | Dépendance gate |
|--------|----------|---------------|-----------------|
| Quick wins immédiats | FIND-01, FIND-09 | < 2h | aucune |
| Gates humains P0 | FIND-02, FIND-03 | session humaine (TL+Backend+QA NF525+UX) | bloque LOT 4 et LOT 6 |
| Quality non-frozen | FIND-04, FIND-05, FIND-06 | 3-12h | aucune |
| Tests Feature manquants | FIND-08, FIND-13 | 4-10j | partiel (FIND-02 pour KDS, FIND-01 pour POS) |
| Symétrie & refactor critique | FIND-07, FIND-12, refactor Payment | 1-3j | gates humains (FIND-02, FIND-03) |
| Persistance / OPS | FIND-10, FIND-11 | 1d + gate schéma | gate schéma DB (FIND-11) |
| Décisions Product | FIND-14, FIND-15 | session humaine + 1 campagne LCP | humain (Product+UX+TL) |

**Total effort dev** : ~15-20 jours répartis sur 6 lots parallélisables (2 lots dépendent de gates humains).
**Chemin critique** : LOT-1 (gates humains) ; tout le reste peut avancer en parallèle.

---

## 1. Principes de découpage

1. **Un lot = un cycle FoodKing** (`run-cycle <TASK_ID>`) avec PLAN/EXECUTE/VALIDATE/AUDIT.
2. **Lots indépendants en parallèle** (cross-conv via `agent-activity-log.sh start`) ; lots dépendants en séquence.
3. **PRIMARY_MODEL** :
   - **Cursor sub-agent (foodking-routine-implementer)** pour fixes localisés, tests, refactors mécaniques.
   - **codex-terminal** pour refactors lourds (Payment refactor post-gate).
   - **claude-terminal** pour audit final de chaque lot.
4. **Chaque lot termine par AUDIT Claude** + entrée GATE_LOG.md si frozen touché + release activity-log.
5. **Aucun cycle ne ferme** sans VALIDATE + AUDIT (cf. `.cursor/rules/global.mdc`).

---

## 2. Lots de cycles — séquencement et contenu

### LOT-0 — Quick wins UI/UX critiques (peut démarrer immédiatement, aucun gate)

**TASK_ID** : `POS_KDS_FINITIONS_LOT0_QUICKWINS_2026-04-26`
**PRIMARY_MODEL** : `cursor-routine` (foodking-routine-implementer)
**Effort estimé** : 1-2h
**Parallélisation** : standalone

**Contenu** :
- **FIND-01** — Ajouter `v-model="discountReason"` sur l'input discount dans `PosComponent.vue` (proche L1668). Vérifier que l'input existe (sinon créer avec libellé i18n + maxlength). Ajouter test Vitest `tests/js/posDiscountReason.spec.js` vérifiant que la saisie alimente bien `discountReason` et que `applyDiscount()` ne bloque plus à `.trim().length < 3` quand le user a saisi.
- **FIND-09** — Remplacer `<Swiper dir="ltr"` par `<Swiper :dir="swiperDir"` dans `KitchenDisplaySystemComponent.vue:130`. Ajouter computed `swiperDir()` retournant `this.$store.state.lang.dir || 'ltr'` (ou pattern existant dans PosComponent.vue:973-974).

**SUBSYSTEMS_TOUCHED** :
- `resources/js/components/admin/pos/PosComponent.vue` (write — ligne 1668 + zone template discount)
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` (write — L130 + computed)
- `tests/js/posDiscountReason.spec.js` (NEW)

**SUBSYSTEMS_OFF_LIMITS** : tout le reste de PosComponent ; tout le backend ; tous les autres composants.

**INVARIANTS_AT_RISK** :
- `pricing-ssot` (FIND-01 — résolution **renforce** le SSOT en débloquant le motif obligatoire)
- `a11y` (FIND-09 — résolution restaure le RTL Arabic)

**GATE_CONDITIONS** : aucun anticipé.

**Critère de succès** : Vitest `posDiscountReason.spec.js` passe + manuel : saisie discount avec motif accepte sans erreur ; KDS en arabe affiche les cards en RTL.

---

### LOT-1 — Gates humains P0 (chemin critique — bloque LOT-4 et LOT-6)

**Pas un cycle exécutif** — c'est une **demande humaine** parallèle.

**Contenu** :
1. **FIND-02** — Convoquer TL + Backend owner + QA NF525 sur `docs/gates/GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md`. Décision attendue par cycle P0 (8 cycles : OrderService, PaymentService, routes/api.php, DiscountCalculator, migrations idempotency / coupons / pricing). Consigner décision dans `GATE_LOG.md` avant toute reprise.
2. **FIND-03** — Convoquer TL + Backend owner + QA NF525 + UX cosignatory sur `docs/gates/GATE_PAYMENT_PROP_MUTATION_2026-04-26.md`. Décision : Option A (refactor emit() + parent state) vs Option B (copie locale data()). Consigner.

**Sortie attendue** :
- 2 entrées `Approved` / `Approved-with-constraint` / `Rejected` dans `GATE_LOG.md`.
- Si approved : autorisation explicite des cycles LOT-4 et LOT-6 ci-dessous.

**Pendant l'attente** : LOT-0, LOT-2, LOT-5 peuvent avancer en parallèle (tous indépendants de ces gates).

---

### LOT-2 — Quality non-frozen (parallèle LOT-0 + LOT-5, sans gate)

**TASK_ID** : `POS_KDS_FINITIONS_LOT2_QUALITY_2026-04-26`
**PRIMARY_MODEL** : `cursor-routine`
**Effort estimé** : 1d (FIND-04 + FIND-05 + FIND-06)
**Parallélisation** : indépendant

**Contenu** :
- **FIND-04** — Refactor `resources/js/helpers/kioskFormatPrice.js:31-32`. Supprimer fallbacks `'fr-FR'` / `'EUR'`. Injecter `currency` + `locale` depuis `store.getters['branch/currency']` et `store.getters['branch/locale']` à tous les sites d'appel. Ajouter test Vitest `tests/js/kioskFormatPrice.spec.js` (probablement existant — étendre).
- **FIND-05** — Compléter `resources/js/languages/bn.json` avec les 27 clés `kds_*` traduites en Bengali. Si pas de locuteur natif disponible : utiliser machine translation comme placeholder ET ouvrir un ticket QA avec annotation `// TRANSLATION_REVIEW_PENDING` + ajouter au backlog HG suivante.
- **FIND-06** — Instancier focustrap dans 4 modals POS (`PaymentComponent`, `CashDrawer`, `NfcCustomer`, `ReceiptComponent`). Pattern : `mounted()` → `createFocusTrap(this.$refs.modalRoot)` ; `beforeUnmount()` → `.deactivate()`. Supprimer l'import mort de `PosComponent.vue:732` ou le déplacer où il est utilisé. Ajouter test a11y (vérification keyboard trap actif).

**SUBSYSTEMS_TOUCHED** :
- `resources/js/helpers/kioskFormatPrice.js` (write)
- `resources/js/languages/bn.json` (write)
- `resources/js/components/admin/pos/PosComponent.vue` (write — supprimer import mort uniquement)
- `resources/js/components/admin/pos/PaymentComponent.vue` (write — focustrap mount/unmount uniquement, **pas la logique props mutation** = LOT-6)
- `resources/js/components/admin/pos/ReceiptComponent.vue` (write — focustrap)
- Modals NfcCustomer + CashDrawer (write — focustrap)
- Tests vitest associés

**SUBSYSTEMS_OFF_LIMITS** : backend ; logique de paiement (= LOT-6 post-gate) ; pricing (= LOT-0).

**INVARIANTS_AT_RISK** :
- `a11y` (FIND-06)
- `pricing-ssot` (FIND-04 — résolution **renforce** SSOT en évitant d'afficher EUR partout)

**GATE_CONDITIONS** : ⚠️ Si on touche `PaymentComponent.vue` pour focustrap, **rester strictement** sur le mount/unmount du focustrap — ne PAS toucher à la mutation de props (ouvert sous FIND-03). Le focustrap est sur l'élément racine du modal, pas sur la logique métier.

**Critère de succès** : Tests vitest passent + i18n audit script `npm run i18n:audit` ne flag plus `kds_*` manquant en bn ; Lighthouse a11y POS modals = 100.

---

### LOT-3 — Tests POS Feature manquants (parallèle, dépend juste de LOT-0)

**TASK_ID** : `POS_FINITIONS_LOT3_TESTS_FEATURE_2026-04-26`
**PRIMARY_MODEL** : `cursor-routine` (ou `codex-terminal` si volume gros)
**Effort estimé** : 2-5j
**Parallélisation** : démarre **après** LOT-0 (FIND-01) pour ne pas tester un état cassé

**Contenu — FIND-08** :
- `tests/Feature/Pos/VoidOrderTest.php` — happy path void + 401 mid-void + double-void rejet.
- `tests/Feature/Pos/CashDrawerTest.php` — open/close + count discrepancy + branch isolation.
- `tests/Feature/Pos/CustomerNfcLookupTest.php` — happy lookup + carte inconnue + carte d'une autre branche (branch_id isolation).
- `tests/Feature/Pos/ParkedOrderResumeTest.php` — resume après F5 + resume après reconnect WS + resume rejected si déjà repris par autre opérateur.

**SUBSYSTEMS_TOUCHED** :
- `tests/Feature/Pos/` (NEW × 4 fichiers)
- Aucune modification de code produit (sauf si tests révèlent un bug → escalation `SCOPE_PRESSURE`)

**SUBSYSTEMS_OFF_LIMITS** : Code produit POS / KDS / backend (lecture seule pour comprendre les controllers).

**INVARIANTS_AT_RISK** :
- `branch-id` (chaque test doit vérifier l'isolation branch_id sur le path)

**GATE_CONDITIONS** : si test échoue par bug réel non couvert par finding existant → `SCOPE_PRESSURE` ESCALATED, ne pas corriger ici.

**Critère de succès** : 4 nouveaux fichiers de test, all passing, couverture Feature POS passe de 3 → 7 fichiers.

---

### LOT-4 — Symétrie OrderService / FrontendOrderService (BLOQUÉ par LOT-1 FIND-02)

**TASK_ID** : `POS_FINITIONS_LOT4_SYMETRIE_2026-04-26`
**PRIMARY_MODEL** : `claude-terminal` (audit lecture) puis `cursor-routine` (fix si divergence)
**Effort estimé** : 1d
**Préreq** : FIND-02 approuvé dans `GATE_LOG.md`

**Contenu — FIND-07** :
- Revue ligne à ligne des chemins de calcul de prix dans les deux services (focus : coupon path, discount path, refund path, idempotency hooks).
- Production rapport `reports/audit/SYMETRIE_OS_FOS_POST_P0_2026-04-26.md` listant divergences trouvées.
- Si divergence détectée : ouvrir gate `GATE_OS_FOS_REALIGN_*` ou patch dans le même cycle si trivial.

**SUBSYSTEMS_TOUCHED** :
- `app/Services/OrderService.php` (read + write conditionnel post-gate FIND-02)
- `app/Services/FrontendOrderService.php` (read + write conditionnel)
- Tests Feature OrderService / FrontendOrderService (étendre si gap détecté)

**SUBSYSTEMS_OFF_LIMITS** : pricing services (PricingService, DiscountCalculator) — dehors scope ce cycle.

**INVARIANTS_AT_RISK** :
- `pricing-ssot`, `symmetry`, `frozen` (zones P0)

**GATE_CONDITIONS** : `SYMMETRY_NOTE` obligatoire à logger en début de cycle (cf. `project-invariants.mdc` invariant 5).

**Critère de succès** : rapport produit, divergences listées, fix appliqué OU gate ouvert pour suite, `SYMMETRY_NOTE` résolu.

---

### LOT-5 — Persistence / OPS (parallèle, gate partiel)

**TASK_ID** : `POS_OPS_FINITIONS_LOT5_PERSISTENCE_2026-04-26`
**PRIMARY_MODEL** : `cursor-routine`
**Effort estimé** : 1d (FIND-10 sans gate) + S après gate FIND-11
**Parallélisation** : indépendant des autres lots

**Contenu** :

**FIND-10** (sans gate) :
- Créer `app/Jobs/SyncMetricsPurgeJob.php` avec policy de rétention configurable (default 30j via `config('observability.sync_metrics_retention_days')`).
- L'ajouter au `app/Console/Kernel.php` schedulé quotidiennement (pendant fenêtre off-peak).
- Ajouter migration index sur `sync_metrics.occurred_at` si absent (vérifier — peut être déjà couvert par PK ou index existant).
- Test `tests/Feature/Observability/SyncMetricsPurgeJobTest.php`.

**FIND-11** (gate schéma DB requis — soft-block) :
- Avant tout : produire `docs/gates/GATE_POS_PARKED_EXPIRES_AT_2026-04-26.md` (cursor-claude rédige, humain approve).
- Une fois approuvé : migration `database/migrations/YYYY_MM_DD_add_expires_at_to_pos_parked_orders.php` avec colonne nullable `expires_at` (datetime).
- Setter dans `PosParkedOrderService::park()` avec durée configurable (default 8h).
- Index étendu sur `(branch_id, user_id, expires_at)`.
- Job purge `app/Jobs/PurgeExpiredParkedOrdersJob.php` schedulé toutes les heures.
- Test Feature `tests/Feature/Pos/PosParkedExpirationTest.php`.

**SUBSYSTEMS_TOUCHED** :
- `app/Jobs/SyncMetricsPurgeJob.php` (NEW)
- `app/Console/Kernel.php` (write — ajouter 2 schedule entries)
- `database/migrations/` (NEW × 1-2 — gate humain pour le 2e)
- `app/Services/PosParkedOrderService.php` (write — post-gate)
- `app/Jobs/PurgeExpiredParkedOrdersJob.php` (NEW — post-gate)
- Tests Feature

**SUBSYSTEMS_OFF_LIMITS** : tout le reste.

**INVARIANTS_AT_RISK** : aucun direct, mais migration = gate humain obligatoire (cf. `human-gates.mdc` Hard Gates).

**GATE_CONDITIONS** : FIND-11 requiert gate schéma DB approuvé avant exécution.

**Critère de succès** : `sync_metrics` purge OK ; `pos_parked_orders.expires_at` opérationnel post-gate.

---

### LOT-6 — Refactor PaymentComponent + 401 retry (BLOQUÉ par LOT-1 FIND-03)

**TASK_ID** : `POS_V4_W2_PAYMENT_REFACTOR_2026-04-26` (cycle dédié comme prévu dans GATE_LOG)
**PRIMARY_MODEL** : `codex-terminal` (refactor lourd 16+ sites de mutation) ; **fallback** `foodking-complex-implementer` si codex-terminal indisponible (≥3 reprises)
**Effort estimé** : 1-2j post-gate
**Préreq** : FIND-03 approuvé dans `GATE_LOG.md` + Option choisie (A : emit() ; B : copie locale)

**Contenu** :

**FIND-03 (post-gate, exécution)** :
- Selon Option choisie au gate :
  - **Option A** : remplacer 16+ sites `this.$props.props.form.X = ...` par `emit('update:form', { ...payload })` ; parent (PosComponent) gère le state via `v-model:form` ou `@update:form`. Tests vitest étendus.
  - **Option B** : copie locale dans `data()` au mount via deep clone ; sync vers parent à la fin via emit. Plus simple mais moins propre.

**FIND-12** (combiné dans le même cycle, pas de gate) :
- Ajouter détection `error?.response?.status === 401` dans le catch de `confirmOrder()` (`PaymentComponent.vue:279-297`).
- Appeler `store.dispatch('auth/refresh')` puis retenter `confirmOrder()` une seule fois.
- Si refresh échoue ou retry échoue : afficher erreur explicite "session expirée — relogin requis".
- Test vitest `tests/js/paymentComponent401Retry.spec.js`.

**SUBSYSTEMS_TOUCHED** :
- `resources/js/components/admin/pos/PaymentComponent.vue` (write majeur — frozen, gate approuvé)
- `resources/js/components/admin/pos/PosComponent.vue` (write — parent state si Option A)
- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` (read — vérifier symétrie ; modifier si même pattern présent)
- Backend : aucun (juste vérification contrat API en lecture)
- Tests vitest

**SUBSYSTEMS_OFF_LIMITS** : autres composants POS, backend OrderService (= LOT-4).

**INVARIANTS_AT_RISK** :
- `frozen` (gate ouvert et approuvé prérequis)
- `symmetry` (kiosk variant doit suivre si pattern partagé)

**GATE_CONDITIONS** : FIND-03 doit être `Approved` avant ce cycle. Si rejected → cycle annulé.

**Critère de succès** : 0 mutation directe de prop dans PaymentComponent ; 401 retry testé ; vitest 815/815 + nouveau test passe ; PosPaymentSubmitTest.php Feature toujours vert.

---

### LOT-7 — Tests KDS Feature (BLOQUÉ par LOT-1 FIND-02)

**TASK_ID** : `KDS_FINITIONS_LOT7_TESTS_FEATURE_2026-04-26`
**PRIMARY_MODEL** : `cursor-routine`
**Effort estimé** : 2-5j
**Préreq** : FIND-02 approuvé (KDS partage zones frozen avec OrderService)

**Contenu — FIND-13** :
- `tests/Feature/KDS/KdsStatusTransitionTest.php` — transitions légales (new→preparing→ready→served) + tentatives illégales (skip + downgrade) + concurrence (2 stations marquant ready en même temps).
- `tests/Feature/KDS/KdsStationRoutingTest.php` — items routés vers bonnes stations selon `items.kds_station_id` ; multi-station ; fallback station par défaut.
- `tests/Feature/KDS/KdsConcurrentUpdateTest.php` — WS event arrivé pendant un poll en cours ne crée pas de doublon.

**SUBSYSTEMS_TOUCHED** :
- `tests/Feature/KDS/` (NEW × 3 fichiers)

**SUBSYSTEMS_OFF_LIMITS** : code produit (sauf SCOPE_PRESSURE escalation).

**Critère de succès** : couverture KDS Feature passe de 2 → 5 fichiers ; transitions de statut couvertes.

---

### LOT-8 — Décisions Product (humain, parallèle)

**Pas un cycle exécutif** — sessions humaines.

**Contenu** :

**FIND-14** :
- Mettre en place campagne LCP réelle :
  - Instrumenter `pos-app.js` avec `web-vitals` (LCP, TTI, CLS) → push vers `MetricsBatcher` existant → `sync_metrics` table avec metric_type `lcp_ms` / `tti_ms`.
  - Cycle dédié court : `POS_V4_LCP_INSTRUMENTATION_2026-04-26` (~S 1-4h).
  - Lighthouse / WebPageTest sur `/admin/pos-v4` en 3 conditions : Wi-Fi industrial PC ; 4G ; Slow 3G.
  - Collecter 7 jours de données ; agréger p50/p95/p99 par condition.
- Soumettre données à HG-W2-3 (KPI revision) — gate déjà rédigé `docs/gates/GATE_W2_KPI_REVISION_2026-04-26.md`.
- Une fois HG-W2-3 cleared : décider HG-W2-1 (cutover) selon les 6 options du brief `docs/gates/GATE_W2_CUTOVER_2026-04-26.md`.

**FIND-15** :
- Avant **2026-05-10** : convoquer TL + Backend owner sur `PosComponent.vue:1779-1786` `@pricing-allowed-block` signoff.
- Si `signoff-granted` : remplacer commentaire `signoff-pending` par `signoff-granted — date — TL + BE owner names` ; lint guard pricing passera de WARN → OK.
- Si `signoff-rejected` : ouvrir cycle `POS_PRICING_BLOCK_REMOVAL_2026-04-26` pour retirer le bloc et recalculer le total côté serveur uniquement.

---

## 3. Calendrier indicatif (séquencement parallèle maximal)

```
J0          : LOT-0 (1-2h)         — quick wins ; libère discount POS et RTL KDS
J0          : LOT-1 (humain)        — convoquer TL+Backend+QA pour FIND-02 et FIND-03 (sessions parallèles)
J0          : LOT-8 humain (KPI)    — convoquer Product pour HG-W2-3 + planifier campagne LCP
J0-J1       : LOT-2 (1d)            — quality fixes parallèles
J0-J5       : LOT-3 (2-5d)          — tests Feature POS (démarre après J0 LOT-0)
J0-J1       : LOT-5a (FIND-10) (S)  — sync_metrics purge (sans gate)
J1          : LOT-5b gate brief     — FIND-11 expires_at gate humain
J2          : LOT-5b post-gate (S)  — migration + service
J2 si LOT-1 ok : LOT-4 (1d)         — symétrie OS/FOS (post-FIND-02)
J2 si LOT-1 ok : LOT-6 (1-2d)       — Payment refactor + 401 retry (post-FIND-03)
J2-J7 si LOT-1 ok : LOT-7 (2-5d)    — tests Feature KDS (post-FIND-02)
J3-J10      : LOT-8 (LCP campaign)  — 7j data collection
J10+        : décisions HG-W2-1/2/3 → cutover éventuel
```

**Chemin critique** : LOT-1 (gates humains) — toute la suite dépend de la rapidité de réponse humaine.
**Sans bloqueur humain** : 5-7 jours de travail dev parallélisable.
**Avec bloqueur humain** (LOT-1 traîne) : LOT-4, LOT-6, LOT-7 décalés mais LOT-0/2/3/5a peuvent finir en 5j parallèles.

---

## 4. Critères de "READY for production"

Lancement multi-branches autorisé **si et seulement si** :

1. **LOT-0 done** (FIND-01 + FIND-09) — bloqueurs UI réels résolus.
2. **LOT-1 cleared** (FIND-02 + FIND-03 décidés humain — `Approved` / `Rejected` peu importe, mais **décidés**).
3. **LOT-2 done** (FIND-04, FIND-05, FIND-06) — kiosk, i18n KDS, a11y.
4. **LOT-6 done si FIND-03 Approved** (Payment refactor + 401 retry).
5. **LOT-3 + LOT-7** : couverture tests Feature ≥ 7 POS + 5 KDS.
6. **LOT-4** : `SYMMETRY_NOTE` résolu (rapport produit, divergences traitées).
7. **LOT-5a** : sync_metrics purge active.
8. **LOT-8** : décision HG-W2-3 prise (KPI binding) ; cutover HG-W2-1 décidé (Option B/C/D peuvent tarder mais Option A/E/F = OK pour lancement initial sur `/admin/pos` legacy).

**Note** : LOT-5b (FIND-11 expires_at) et FIND-15 (pricing signoff) sont **fortement recommandés** mais non strictement bloquants si :
- LOT-5b : `pos_parked_orders` peut être purgé manuellement/script ad-hoc en attendant.
- FIND-15 : seulement bloquant après 2026-05-10 (date_limit).

---

## 5. Risques résiduels post-master-plan

| Risque | Probabilité | Impact | Mitigation |
|--------|-------------|--------|-----------|
| LOT-1 traîne (gates humains) | Moyenne | Haut (bloque LOT-4/6/7) | Convoquer J0, escalation si > 5j |
| FIND-04 fix révèle bugs i18n cachés (locales non couvertes) | Moyenne | Faible | Backlog vers cycle dédié i18n |
| LOT-3/LOT-7 révèlent bugs nouveaux | Élevée (grosse couverture) | Moyen | `SCOPE_PRESSURE` discipline ; escalation par bug |
| LCP campaign révèle perf p95 > 2.5s | Moyenne | Haut (bloque cutover) | HG-W2-3 prévoit déjà Option D (defer) |
| Bengali translator indispo pour FIND-05 | Élevée | Faible | Machine translation + flag `TRANSLATION_REVIEW_PENDING` |
| FIND-11 gate schéma DB rejeté | Faible | Moyen | Fallback : purge ad-hoc par script |

---

## 6. Tracking

- **Source de findings** : `reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md` (727 lignes, format strict).
- **Tracking exécution** : chaque lot ouvre son cycle via `run-cycle <TASK_ID>` ; ce master plan référence les `TASK_ID` mais n'est **pas** un cycle exécutable lui-même.
- **Mise à jour** : à chaque lot fermé, mettre une ligne `LOT-X DONE — date — outcome` dans `## 7. Journal d'avancement` ci-dessous.

---

## 7. Journal d'avancement

| Lot | TASK_ID | Statut | Date | Outcome |
|-----|---------|--------|------|---------|
| LOT-0 | POS_KDS_FINITIONS_LOT0_QUICKWINS_2026-04-26 | TODO | — | — |
| LOT-1 | (humain) | TODO | — | gate decisions FIND-02 + FIND-03 |
| LOT-2 | POS_KDS_FINITIONS_LOT2_QUALITY_2026-04-26 | TODO | — | — |
| LOT-3 | POS_FINITIONS_LOT3_TESTS_FEATURE_2026-04-26 | TODO | — | — |
| LOT-4 | POS_FINITIONS_LOT4_SYMETRIE_2026-04-26 | BLOCKED-LOT1 | — | dépend FIND-02 |
| LOT-5a | POS_OPS_FINITIONS_LOT5_PERSISTENCE_2026-04-26 (FIND-10) | TODO | — | — |
| LOT-5b | (même cycle, FIND-11) | BLOCKED-GATE | — | gate schéma DB |
| LOT-6 | POS_V4_W2_PAYMENT_REFACTOR_2026-04-26 | BLOCKED-LOT1 | — | dépend FIND-03 |
| LOT-7 | KDS_FINITIONS_LOT7_TESTS_FEATURE_2026-04-26 | BLOCKED-LOT1 | — | dépend FIND-02 |
| LOT-8 LCP | POS_V4_LCP_INSTRUMENTATION_2026-04-26 + campagne | TODO | — | humain Product |
| LOT-8 signoff | (humain) | TODO | — | TL+BE owner avant 2026-05-10 |

---

## 8. Approval pour exécution

Ce master plan **n'est pas** un gate humain — c'est un **plan d'orchestration**. Les gates humains (LOT-1, LOT-5b, LOT-8) ont leurs propres briefs dans `docs/gates/`.

L'autorité humaine est requise pour :
- Approuver les 2 gates P0 (LOT-1).
- Approuver les décisions Product LOT-8.
- Lancer/suspendre/réordonner les lots ci-dessus.

cursor-claude orchestre l'exécution lot par lot **dans l'ordre indiqué**, en attendant les déblocages humains pour LOT-1/5b/8.

Lot suivant immédiatement actionnable (ne nécessite aucun gate) : **LOT-0**.
