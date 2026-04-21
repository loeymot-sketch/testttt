# PLAN_P_MEGA_W5_2026-04-20 — Eat-in / TPE / Receipt (P-MEGA-12 + P-MEGA-13 + P-MEGA-14)

**Cycle parent** : `plans/PLAN_MEGA_FUNCTIONAL_CORRECTNESS_2026-04-20.md` Vague 5
**Date** : 2026-04-20
**Mode** : RUNNER_MODE single-session + auto-remediation
**Origine** : Vague 5 du chantier P-MEGA. 3 sujets **business-critical** touchant fiscal NF525, TVA, payments. Pré-déclarés HUMAN_GATE par le plan source ("Risque : haut. HUMAN GATE.")
**Précédents** :
- W4 REM_3 closed (commit `781232fb4`)
- AUDIT transverse `reports/execution/AUDIT_P_MEGA_23_DRIFT_ROOT_CAUSE_2026-04-20.md` (13 drifts admin↔kiosk, 3 patterns systémiques) → utilisé comme grille de lecture par chaque sous-audit
- 3 gates W1+W2 toujours ouvertes (cardinality / pricing SSOT / TVA arrondis) → contexte fiscal sensible

---

## TASK_ID

`P_MEGA_W5_EATIN_TPE_RECEIPT_2026-04-20`

---

## STRATÉGIE GÉNÉRALE — pourquoi PAS d'implémentation directe

Les 3 sujets de cette vague touchent **simultanément** au moins une zone critique listée par `auto-remediation.mdc` :

| Sujet | Zones critiques touchées |
|---|---|
| P-MEGA-12 | Pricing SSOT (invariant 1) + Schema potentiel (`tax_rates`) + NF525 |
| P-MEGA-13 | Payments + Idempotency + NF525 fiscal counter + dispatch after commit (invariant 4) |
| P-MEGA-14 | NF525 receipt content + HMAC signature + duplicata marker |

→ **Aucune fix ne peut être routée vers `foodking-routine-implementer` ou `foodking-complex-implementer` sans gate humain explicite.**

→ Cycle W5 = **3 audits read-only parallèles + 3 GATE_BRIEFs + 0 patch produit**, plus une Phase E optionnelle de **micro-actions zéro-risque** (logs structurés, tests sentinelles rouges volontaires, refactor sans changement comportement) si et seulement si l'audit l'identifie.

---

## DECOUPAGE — 5 phases (3 audits parallèles + synthèse + phase E optionnelle)

**Justification du découpage** :

1. **Parallélisme légitime** : les 3 audits touchent des zones disjointes du code (TVA `app/Services/Pricing/TaxCalculator.php` + `tax_rates` ; payments `PaymentService.php` + `KioskPaymentComponent.vue` ; receipt `ReceiptComponent.vue` + drivers ESC/POS). **Aucun chevauchement read-only** → lancement simultané sans risque de race contextuelle.
2. **Phase D (synthèse) bloquée par Phase A+B+C** : les 3 GATE_BRIEFs nécessitent l'évidence des 3 audits. Phase D ne peut pas démarrer avant que A/B/C aient livré leur rapport.
3. **Phase E (micro-actions) optionnelle, gated par audits** : ne s'exécute QUE si l'un des 3 audits identifie une action satisfaisant strictement la définition zéro-risque (cf. critères §"Phase E").
4. **Anti scope-creep** : aucune phase ne touche le code produit fiscal/payments/receipt. Phase E maximum = test sentinelle PHPUnit + log structuré (déjà pattern utilisé en W3 — cf. `OrderItemAllergenSnapshotComposedTest.php` rouge volontaire).
5. **Token discipline** : chaque subagent `explore` reçoit un prompt borné à sa zone, ne lit pas les autres zones, ne lit pas les rapports W1-W4 (sauf 1 ligne de contexte sur les gates ouvertes).

---

## RUNNER_MODE / PRIMARY_MODEL / SUBAGENT — par phase

### Phase A — Audit P-MEGA-12 (Eat-in vs takeaway)
- **PRIMARY_MODEL** : Claude (orchestration) → délégué à `explore` readonly subagent
- **SUBAGENT** : `explore` (thoroughness "very thorough")
- **Justification routing** : pure lecture statique (Vue + PHP + DB schema + JSON tax). `routing.md` ne couvre pas le rôle "audit readonly" mais `human-gates.mdc` + `audit-context.md` l'autorisent explicitement (Claude est sole author of audit records). L'`explore` agent est readonly par construction → 0 risque d'écriture produit.
- **REPORT_FILE** : `reports/execution/AUDIT_P_MEGA_12_EATIN_TVA_2026-04-20.md` (écrit par Claude orchestrator à partir du résumé `explore`)
- **GATE_FILE** : `docs/gates/GATE_P_MEGA_12_EATIN_TVA_2026-04-20.md` (Phase D)

### Phase B — Audit P-MEGA-13 (TPE / multi-tender / idempotence)
- **PRIMARY_MODEL** : Claude → délégué à `explore` readonly
- **SUBAGENT** : `explore` (thoroughness "very thorough")
- **Justification routing** : idem A, zone disjointe.
- **REPORT_FILE** : `reports/execution/AUDIT_P_MEGA_13_TPE_PAYMENTS_2026-04-20.md`
- **GATE_FILE** : `docs/gates/GATE_P_MEGA_13_TPE_PAYMENTS_2026-04-20.md` (Phase D)

### Phase C — Audit P-MEGA-14 (Receipt rendering NF525)
- **PRIMARY_MODEL** : Claude → délégué à `explore` readonly
- **SUBAGENT** : `explore` (thoroughness "very thorough")
- **Justification routing** : idem A. Note : `OrderDetailsResource` a déjà été touché par V14_T07 dans un worktree parallèle (working tree non commité) — l'audit doit lire la version **actuelle dans `git HEAD`** + signaler explicitement si V14_T07 ouvre un risque de drift sur le receipt NF525. **Pas de lecture du worktree V14**.
- **REPORT_FILE** : `reports/execution/AUDIT_P_MEGA_14_RECEIPT_NF525_2026-04-20.md`
- **GATE_FILE** : `docs/gates/GATE_P_MEGA_14_RECEIPT_NF525_2026-04-20.md` (Phase D)

### Phase D — Synthèse 3 GATE_BRIEFs
- **PRIMARY_MODEL** : Claude (orchestrator lui-même, `foodking-planner-orchestrator`)
- **SUBAGENT** : aucun (production directe par l'orchestrateur — règle `routing.md` : "GATE BRIEF | Claude → Human")
- **REPORT_FILE** : `reports/execution/SYNTHESE_P_MEGA_W5_2026-04-20.md` (consolide les 3 audits + lien vers chaque GATE_BRIEF)
- **GATE_FILES produits** : 3 fichiers `docs/gates/GATE_P_MEGA_{12,13,14}_*.md` au format strict de `human-gates.mdc`

### Phase E — Micro-actions ZÉRO-RISQUE (OPTIONNELLE, conditionnelle)
- **PRIMARY_MODEL** : Composer (`foodking-routine-implementer`) **uniquement si activée**
- **SUBAGENT** : `foodking-routine-implementer`
- **Activation** : SEULEMENT si Phase D identifie au moins une action satisfaisant **TOUS** les critères :
  1. Aucune zone critique de `auto-remediation.mdc` touchée en logique (lecture/append OK, modification interdite)
  2. Aucun fichier `app/Services/Pricing/**`, `PaymentService.php`, `OrderService.php`, `ReceiptComponent.vue`, `tax_rates` migration, NF525 fiscal_counter modifié dans son comportement
  3. Action de type : (a) test sentinelle PHPUnit/Vitest rouge volontaire qui documente la dette **sans** la corriger, OU (b) ajout d'un `Log::info(...)` structuré dans un controller de paiement **sans** changer le flux métier, OU (c) ajout de docblock / commentaire `@todo NF525` sans changement de code
- **REPORT_FILE** : `reports/execution/RUN_P_MEGA_W5_E_ZEROFIX_SENTINELS_2026-04-20.md` (si activée)
- **GATE_FILE** : aucun (par définition zéro-risque). Si l'orchestrator hésite → **NE PAS activer Phase E** et l'inclure comme proposition dans le GATE_BRIEF approprié.

---

## SUBSYSTEMS_TOUCHED — read-only par phase

### Phase A — P-MEGA-12 (READ-ONLY)

| Path | Intent | branch_id | dispatch |
|---|---|---|---|
| `app/Services/Pricing/TaxCalculator.php` | read | n/a | n/a |
| `app/Services/Pricing/PricingService.php` | read | n/a | n/a |
| `app/Services/Pricing/PricingRequest.php` | read | n/a | n/a |
| `app/Services/Pricing/PricingResult.php` | read | n/a | n/a |
| `app/Services/Pricing/PricingLineResult.php` | read | n/a | n/a |
| `database/migrations/**tax*` (grep `tax_rate`, `vat`, `tva`) | read | n/a | n/a |
| `app/Models/Item.php`, `app/Models/Order.php`, `app/Enums/OrderType.php` (si existe) | read | n/a | n/a |
| `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` + steps eat-in/takeaway | read | n/a | n/a |
| `resources/js/store/modules/kioskCart.js` | read | n/a | n/a |
| `app/Http/Controllers/Frontend/Pricing*Controller.php` (route `/pricing/preview`) | read | n/a | n/a |
| Tests existants `tests/Feature/Pricing/**`, `tests/Unit/Pricing/**` | read | n/a | n/a |

### Phase B — P-MEGA-13 (READ-ONLY)

| Path | Intent |
|---|---|
| `app/Services/PaymentService.php` | read |
| `app/Services/PaymentManagerService.php` | read |
| `app/Services/PaymentAbstract.php` | read |
| `app/Services/PaymentGatewayService.php` | read |
| `app/Http/Controllers/Frontend/PaymentController.php` | read |
| `app/Http/PaymentGateways/Gateways/**`, `app/Http/PaymentGateways/PaymentRequests/**` | read (grep TPE/Stone/Ingenico) |
| `app/Http/Requests/PaymentRequest.php`, `PaymentStatusRequest.php` | read |
| `app/Enums/PaymentStatus.php`, `app/Enums/PaymentGateway.php` | read |
| `app/Models/PaymentGateway.php` | read |
| `app/Services/OrderService.php` (méthode `pay()` + dispatch) | read (NE PAS modifier) |
| `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` | read |
| `resources/js/components/admin/pos/PaymentComponent.vue` | read (audit transverse — drift admin↔kiosk) |
| Tests existants `tests/Feature/Payment*/**` | read |

### Phase C — P-MEGA-14 (READ-ONLY)

| Path | Intent |
|---|---|
| `resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue` (ticket kiosk) | read |
| `resources/js/components/admin/pos/Receipt*.vue` (POS) | read |
| `app/Http/Resources/OrderDetailsResource.php` (HEAD, pas worktree V14) | read |
| `app/Http/Resources/Order*Resource.php` | read |
| Driver ESC/POS / printer (chercher `escpos`, `printer`, `print_*`) | read |
| HMAC / QR / fiscal counter (chercher `hmac`, `signature`, `fiscal`, `nf525`, `Z_report`) | read |
| Tests existants `tests/Feature/Receipt*`, `tests/Unit/Receipt*`, `tests/Feature/Fiscal*` | read |
| `app/Services/Orders/OrderItemAllergenSnapshot.php` (interaction snapshot ↔ receipt) | read |

### Phase D — Synthèse (write GATE_BRIEFs only)

| Path | Intent |
|---|---|
| `docs/gates/GATE_P_MEGA_12_EATIN_TVA_2026-04-20.md` | write (NEW) |
| `docs/gates/GATE_P_MEGA_13_TPE_PAYMENTS_2026-04-20.md` | write (NEW) |
| `docs/gates/GATE_P_MEGA_14_RECEIPT_NF525_2026-04-20.md` | write (NEW) |
| `reports/execution/SYNTHESE_P_MEGA_W5_2026-04-20.md` | write (NEW) |
| `.cursor/ACTIVE_CYCLE.md` | write (PHASE update) |

### Phase E (si activée) — micro-actions zéro-risque

| Path | Intent | Condition |
|---|---|---|
| `tests/Feature/Pricing/EatInVsTakeawayTaxSentinelTest.php` (NEW) | write | sentinelle ROUGE documentant cas TVA cassés sans patcher SSOT |
| `tests/Feature/Payment/PaymentRetryIdempotencySentinelTest.php` (NEW) | write | sentinelle ROUGE documentant absence X-Idempotency-Key |
| `tests/Feature/Receipt/ReceiptDuplicataSentinelTest.php` (NEW) | write | sentinelle ROUGE documentant absence marqueur DUPLICATA |
| `tests/js/posPaymentSplit.spec.js` (NEW) | write | sentinelle Vitest split TR+CB sans recouvrir code prod |
| Aucun fichier `app/**` ni `resources/js/components/**` n'est éditable en Phase E | — | strict |

---

## SUBSYSTEMS_OFF_LIMITS (toutes phases)

- **Toute écriture** dans `app/Services/Pricing/**`, `app/Services/PaymentService.php`, `app/Services/PaymentManagerService.php`, `app/Services/OrderService.php`, `app/Services/FrontendOrderService.php` → **gated**
- **Toute écriture** dans `database/migrations/**` (tax_rates, fiscal_counter, payments, idempotency_keys) → **gated** (schema = hard gate `human-gates.mdc`)
- **Toute écriture** dans `ReceiptComponent.vue` (admin/POS), `KioskOrderSummaryComponent.vue`, `KioskPaymentComponent.vue` → **gated**
- **Toute écriture** dans `OrderDetailsResource.php`, `Order*Resource.php` → **gated** (NF525 contract)
- **Toute écriture** dans driver imprimante / ESC/POS → **gated**
- **Toute écriture** dans HMAC / signature fiscale / Z_report → **gated**
- **Auth, frozen zones, branch_id filtering, dispatch logic** → restent off-limits (rappel `routing.md`)
- **Worktree V14_T07 (working tree non commité de OrderDetailsResource)** → **ne pas lire** (lire uniquement HEAD)

---

## INVARIANTS_AT_RISK

1. **Invariant 1 (Backend Pricing SSOT)** — P-MEGA-12 : tout recalcul TVA déclenché par le toggle eat-in/takeaway DOIT rester côté backend (`/pricing/preview`). Audit doit confirmer 0 calcul TVA front. Toute violation détectée = constat dans GATE_BRIEF, pas de fix.
2. **Invariant 4 (Dispatch after DB commit)** — P-MEGA-13 : `OrderService::pay()` doit dispatcher `OrderPaid`/`OrderCreated` **après** commit. L'audit doit relever toute violation existante (cf. finding V4 #8 déjà ouvert sur `OrderCreated` non-after-commit). Pas de fix dans W5.
3. **Invariant 5 (OrderService / FrontendOrderService symétrie)** — P-MEGA-13 + P-MEGA-12 : tout flow eat-in ou paiement multi-tender qui modifie `OrderService` mais pas `FrontendOrderService` (ou inverse) = risque symétrie. Audit signale, **0 fix**.
4. **Invariant 2 (OrderStatus enum authoritative)** — P-MEGA-13 : statut paiement (`PaymentStatus`) doit rester enum, pas string. Audit relève toute string literal sur status payment dans le code lu.
5. **Invariant 3 (branch_id isolation)** — P-MEGA-13 : payments multi-tender doivent rester scopés par branche. Audit confirme.
6. **Invariant 6 (Frozen zones)** — Phase A/B/C peuvent identifier des composants frozen. Si oui → marquage explicite dans GATE_BRIEF, pas de fix, même cosmétique.

---

## GATE_CONDITIONS — 3 gates pré-déclarées (PRODUITES en Phase D)

### GATE_P_MEGA_12 — Eat-in vs takeaway TVA / NF525
- **Trigger** : toute modification de la logique TVA (calcul, mapping order_type → taux, ajout colonne `tax_rates.applies_to_eatin`, mention obligatoire NF525 sur le ticket selon order_type)
- **Catégorie** : Pricing SSOT (invariant 1) + Schema (si migration `tax_rates`) + NF525 receipt
- **Décision Required** : (a) périmètre du fix autorisé (back-only ? schema migration ? front toggle wiring ?), (b) plan de migration data si nouveaux taux (back-fill commandes existantes ?), (c) test plan PHPUnit `EatInVsTakeawayTaxTest` 12 cas accepté ?
- **Routing post-approval** : `foodking-complex-implementer` (GPT-5.4) — pricing logic + symétrie OrderService/FrontendOrderService obligatoire

### GATE_P_MEGA_13 — TPE handshake / multi-tender / idempotence
- **Trigger** : toute modification de `PaymentService.php`, ajout idempotency key store (DB ou Redis), modification `OrderService::pay()`, ajout reconciliation OSS, modification dispatch après commit
- **Catégorie** : Payments + Idempotency + NF525 fiscal counter + dispatch (invariant 4)
- **Décision Required** : (a) scope split TR+CB (UI seulement ? back validation ? schema `payments` table multi-row par order ?), (b) idempotency strategy (X-Idempotency-Key DB-backed ? cache TTL ?), (c) interaction avec finding V4 #8 (`OrderCreated` non-after-commit) — fusionner avec P11_DISPATCH_AFTER_COMMIT_REMEDIATION ou cycle séparé ?
- **Routing post-approval** : `foodking-complex-implementer` (GPT-5.4) — payments critical, symétrie OrderService obligatoire, branch_id review

### GATE_P_MEGA_14 — Receipt rendering NF525 (variations + extras + TVA breakdown + duplicata + QR)
- **Trigger** : toute modification du contenu ticket NF525 (`ReceiptComponent.vue` admin/POS, mirror kiosk, OrderDetailsResource si payload ticket, signature HMAC, QR encoding, marqueur DUPLICATA, breakdown TVA par taux)
- **Catégorie** : NF525 receipt content + signature fiscale
- **Décision Required** : (a) scope minimal (juste DUPLICATA ? + breakdown TVA ? + QR re-signature ?), (b) impact sur fiscal_counter (réimpression incrémente-t-elle ?), (c) interaction avec V14_T07 worktree non commité touchant `OrderDetailsResource` — séquencer ou bloquer ?, (d) backward compat tickets déjà imprimés
- **Routing post-approval** : `foodking-complex-implementer` (GPT-5.4) — NF525 contract + symétrie back/front receipt

---

## ESCALATIONS pré-déclarées (count = 4)

- **E1 (Phase A)** : si l'audit identifie que la table `tax_rates` n'a **PAS** de colonne discriminant eat-in vs takeaway (champ `applies_to_order_type` ou équivalent) → ESCALATION dans audit, mention dans GATE_P_MEGA_12 (sous-option "schema migration nécessaire" → hard gate distinct).
- **E2 (Phase B)** : si l'audit confirme qu'aucune table `idempotency_keys` n'existe ET aucun usage de `X-Idempotency-Key` côté `PaymentService` → ESCALATION : confirme une vulnérabilité double-paiement existante. Sentinel test Phase E candidat.
- **E3 (Phase C)** : si l'audit identifie que la signature HMAC du ticket actuel **ne couvre PAS** un champ critique (ex : breakdown TVA, marqueur DUPLICATA absent du payload signé) → ESCALATION : risque NF525 régul.
- **E4 (transverse)** : si un audit révèle qu'un sous-système référence un fichier listé "frozen" dans `docs/gates/GATE_VERIFY_P0_FROZEN_*` → halt immédiat, gate brief avant tout fix.

---

## Test strategy

**Stratégie déclarée par phase** :

| Phase | Stratégie | Détail |
|---|---|---|
| A | `no-test` (audit) | Lecture statique. AUCUN nouveau test. |
| B | `no-test` (audit) | Lecture statique. AUCUN nouveau test. |
| C | `no-test` (audit) | Lecture statique. AUCUN nouveau test. |
| D | `no-test` (gate brief) | Synthèse + GATE_BRIEFs. AUCUN test. |
| E (si activée) | `local-validation` | Tests sentinelles PHPUnit/Vitest **ROUGES VOLONTAIREMENT** documentant la dette détectée (pattern W3 — cf. `OrderItemAllergenSnapshotComposedTest.php`) |

**Justification "no-test" Phase A/B/C** : un audit readonly ne peut pas introduire de test sans risquer scope creep. Les tests éventuels (sentinelles) sont relégués à Phase E sous condition stricte zéro-risque.

**Sentinelles Phase E — pattern obligatoire** :
- Test rouge `@group sentinel` `@group nf525_debt` (ou équivalent) documenté dans le test file
- Le test ÉCHOUE volontairement et le commit-message indique "WONT_FIX without W5 gate approval"
- Aucun code prod modifié
- Suite globale doit rester verte si le tag `sentinel` est exclu de la run par défaut (ou test explicitement marqué `@expectsFailure` selon convention back)

**Pas de Playwright en W5** : les flows critiques touchent NF525 et payment — un Playwright sur ces flows nécessiterait une gate manuel-UX (cf. `human-gates.mdc`). Reporté post-gate.

---

## DoD précis par phase

### Phase A — DoD audit P-MEGA-12
- [ ] `reports/execution/AUDIT_P_MEGA_12_EATIN_TVA_2026-04-20.md` produit avec sections : (1) State actuel (chemin call eat-in/takeaway → TVA), (2) Défauts/manques (avec citation `file:line`), (3) Invariants à risque, (4) Corrections nécessaires (estimation LOC + zones touchées + routing), (5) Impact business
- [ ] Citation explicite des règles fiscales France 2026 applicables (5.5% / 10% / 20%) et leur mapping prévu/manquant
- [ ] Confirmation explicite : 0 modification produit faite
- [ ] Liste des findings ESCALATION éventuels (E1)

### Phase B — DoD audit P-MEGA-13
- [ ] `reports/execution/AUDIT_P_MEGA_13_TPE_PAYMENTS_2026-04-20.md` produit, mêmes 5 sections
- [ ] Cartographie : route handler → controller → service → gateway → response → dispatch ordering (after commit ou pas)
- [ ] Cartographie multi-tender : un order peut-il avoir N rows `payments` ? Schema actuel le permet ?
- [ ] Idempotency : présence/absence de `X-Idempotency-Key` documentée. Présence/absence d'une table `idempotency_keys`. ESCALATION E2 si applicable.
- [ ] Lien explicite avec finding V4 #8 (`OrderCreated` non-after-commit) — fusion ou séparation cycle ?

### Phase C — DoD audit P-MEGA-14
- [ ] `reports/execution/AUDIT_P_MEGA_14_RECEIPT_NF525_2026-04-20.md` produit, mêmes 5 sections
- [ ] Liste exhaustive des champs ticket actuels (variations, extras qty, TVA breakdown par taux, total, horodatage, HMAC, QR, DUPLICATA marker)
- [ ] Pour chaque champ : présent / partiel / absent, et si signé HMAC ou non
- [ ] Mention explicite : version lue = `git HEAD` (commit `781232fb4`), worktree V14_T07 IGNORÉ
- [ ] ESCALATION E3 si HMAC ne couvre pas champ critique

### Phase D — DoD synthèse
- [ ] `reports/execution/SYNTHESE_P_MEGA_W5_2026-04-20.md` produit, agrégeant les 3 audits + section "Décisions humaines requises"
- [ ] 3 fichiers `docs/gates/GATE_P_MEGA_{12,13,14}_*.md` produits au format strict de `human-gates.mdc` (Trigger / Affected Subsystems / Invariants at Risk / Decision Required / Options 1-3 / Approval block VIDE — orchestrator NE remplit JAMAIS le bloc Approval)
- [ ] `.cursor/ACTIVE_CYCLE.md` mis à jour : PHASE = `BLOCKED_HUMAN_GATE × 3` ; NEXT = "humain signe ou cancel ; sinon W5 reste pending"
- [ ] Si Phase E activée : décision documentée dans synthèse + critères zéro-risque cochés + autorisation explicite Phase E
- [ ] Si Phase E NON activée : justification explicite ("aucune action ne satisfait simultanément les 3 critères zéro-risque")

### Phase E — DoD (si activée)
- [ ] `reports/execution/RUN_P_MEGA_W5_E_ZEROFIX_SENTINELS_2026-04-20.md` produit
- [ ] N tests sentinelles ROUGES créés (N ≤ 4) sous `tests/Feature/**/Sentinel*Test.php` ou `tests/js/*Sentinel.spec.js`
- [ ] Aucun fichier `app/**` ni `resources/js/components/**` modifié (vérifié par diff git)
- [ ] Suite globale reste verte si tags sentinel exclus (ou tests marqués `@group sentinel` exclus du runner par défaut — convention à confirmer dans le rapport)
- [ ] Vitest reste à 554/554 vert sur les tests non-sentinelles

---

## Estimation LOC AUDIT par tâche (rapports uniquement, PAS de fix)

| Phase | Livrable | LOC estimées |
|---|---|---|
| A | `AUDIT_P_MEGA_12_EATIN_TVA_2026-04-20.md` | ~250 lignes markdown |
| B | `AUDIT_P_MEGA_13_TPE_PAYMENTS_2026-04-20.md` | ~300 lignes markdown |
| C | `AUDIT_P_MEGA_14_RECEIPT_NF525_2026-04-20.md` | ~280 lignes markdown |
| D | `SYNTHESE_P_MEGA_W5_*.md` | ~150 lignes |
| D | `GATE_P_MEGA_12_*.md` | ~80 lignes (1 page) |
| D | `GATE_P_MEGA_13_*.md` | ~80 lignes (1 page) |
| D | `GATE_P_MEGA_14_*.md` | ~80 lignes (1 page) |
| E (opt.) | sentinels + report | ~100 LOC tests + ~80 lignes rapport |

**Total cumulé W5 (sans Phase E)** : ~1220 lignes documentation + 0 LOC code produit
**Total avec Phase E** : ~1400 lignes + ≤100 LOC tests sentinelles (aucune ligne code prod)

---

## CONTEXTE BUSINESS — pourquoi chaque tâche compte (2 lignes max)

### P-MEGA-12 — Eat-in vs takeaway / TVA
**Impact** : TVA mauvaise = redressement fiscal direct (URSSAF/DGFiP), amende = 80% des droits éludés + intérêts retard. Sur 6 mois de prod multi-restaurant = potentiellement 50-200k€ de risque par client.
**NF525** : ticket sans mention order_type = ticket non conforme = perte certification → blocage commercial total.

### P-MEGA-13 — TPE / multi-tender / idempotence
**Impact** : double paiement client (timeout TPE → retry sans idempotency) = chargeback + perte client définitive. Client moyen restaurant = 200-800€/an LTV. Multi-tender absent = clients qui ont ticket-restaurant + carte ne peuvent pas commander → ~20-30% du CA midi perdu (segment salariés).
**NF525** : compteur fiscal qui s'incrémente sur paiement échoué = anomalie comptable détectable lors du contrôle, suspicion de fraude.

### P-MEGA-14 — Receipt NF525
**Impact** : ticket sans breakdown TVA = ticket non conforme NF525 = perte certification. Réimpression sans marqueur DUPLICATA = présomption de fraude lors du contrôle (suspicion ticket clonable). QR sans signature HMAC valide = inadmissible aux contrôles fiscaux digitaux 2026+.
**Régul** : amende NF525 jusqu'à 7500€ par établissement + obligation de remplacer le système → migration urgente coûteuse.

---

## Risques principaux (3 lignes)

1. **R1 — Audit incomplet du fait du worktree V14_T07 non commité** : la lecture des resources back peut désynchroniser si quelqu'un confond HEAD et working tree. Mitigé par mention explicite "lire HEAD `781232fb4` only" dans chaque prompt subagent + audit C produit un check git diff.
2. **R2 — Tentation Phase E de "vraie fix"** : un subagent routine pourrait être tenté de patcher au-delà du sentinel. Mitigé par DoD strict (Phase E vérifiée par diff git showing 0 fichier `app/**` ou `resources/js/components/**` modifié).
3. **R3 — Volume audit > attendu** : zone fiscal/payments est large. Si un audit dépasse 500 LOC report → ESCALATION (risque dilution). Mitigé par scope hard-bornée à 3 fichiers principaux + N voisins par audit.

---

## Ordre d'exécution

1. **Phase A + B + C en PARALLÈLE** : 3 invocations `Task` `explore` simultanées dans un seul message (gain temps massif, zones disjointes, 0 risque race).
2. **Attendre les 3 résumés**, lire les 3 résumés (pas les rapports complets, juste le résumé court demandé au subagent).
3. **Claude orchestrator écrit lui-même les 3 fichiers AUDIT_*.md** à partir des résumés (subagents `explore` peuvent fournir un draft mais Claude les consolide pour respecter "Claude is sole author of audit records").
4. **Phase D** : Claude orchestrator écrit `SYNTHESE_P_MEGA_W5_*.md` + 3 GATE_BRIEFs. Met à jour `ACTIVE_CYCLE.md` PHASE = `BLOCKED_HUMAN_GATE`.
5. **Décision Phase E** : si critères zéro-risque rencontrés → invoquer `foodking-routine-implementer` avec scope strict (1 sentinel test par tâche maximum, total ≤ 4 fichiers). Sinon, log "Phase E NOT_TRIGGERED" et arrêter.
6. **Halt définitif** sur 3 GATES — pas d'auto-remediation possible (les 3 GATES sont **hard gates** par `human-gates.mdc` : NF525 + pricing + payments + idempotency).

---

## ACTIVE_CYCLE update prévu

À l'ouverture du cycle :
- TASK_ID = `P_MEGA_W5_EATIN_TPE_RECEIPT_2026-04-20`
- PHASE = `EXECUTE — 3 audits readonly parallèles`
- PRIMARY_MODEL = `Claude (orchestration) + 3× explore subagent (audit)`
- PLAN_FILE = `plans/PLAN_P_MEGA_W5_2026-04-20.md` (ce fichier)
- REPORT_FILES = 3 fichiers AUDIT_* + 1 SYNTHESE
- GATE_FILES = 3 fichiers `docs/gates/GATE_P_MEGA_{12,13,14}_*`
- RUNNER_MODE = single-session

À la fin de Phase D :
- PHASE = `BLOCKED_HUMAN_GATE × 3`
- NEXT = "humain signe les 3 gates (ou les cancel) ; W5 ne ferme PAS sans décision humaine ; cycles d'implémentation futurs P-MEGA-12/13/14 routés vers `foodking-complex-implementer` GPT-5.4 après gate"

---

## Manifeste

> Vague 5 = 3 audits **read-only** + 3 GATE_BRIEFs + Phase E optionnelle de sentinelles ROUGES. **Aucun code produit n'est modifié**. Le but de cette vague n'est pas de coder : c'est de **cartographier la dette technique fiscale et payments** pour donner au humain une visibilité décisionnelle. Les 3 zones (TVA, TPE/multi-tender, receipt NF525) sont des **hard gates** par `human-gates.mdc` — l'auto-remediation est **désactivée** pour cette vague (toute "correction" toucherait pricing/payments/NF525 = STOP). Les sentinelles Phase E (si activées) documentent la dette par tests rouges sans la patcher, pattern déjà éprouvé en W3. Cycles d'implémentation futurs (P-MEGA-12/13/14) seront routés `foodking-complex-implementer` GPT-5.4 **après** signature humaine des 3 gates.
