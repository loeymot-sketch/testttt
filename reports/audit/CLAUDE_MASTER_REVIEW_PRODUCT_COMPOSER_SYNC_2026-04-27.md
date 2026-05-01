# Claude Master Review — Product Composer / Catalogue / Stock / POS-Kiosk-KDS Sync — 2026-04-27

Reviewer: Claude
Inputs:
- `reports/audit/CODEX_HANDOFF_TO_CLAUDE_MASTER_REVIEW_PRODUCT_COMPOSER_SYNC_2026-04-27.md`
- `reports/audit/CODEX_FINAL_COMPOSER_AUDIT_2026-04-27.md`
- 9 self-audits `GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-B0..B9.md`
- Direct code/test reads + 4 deep-audit Explore agents (NF525, Stock symétrie, B0/B7/B8, B2/B3/B4/B6, sync POS/Kiosk/KDS)

Codex local verdict: `LOCAL_FULL_GREEN_HARDWARE_PENDING`.
Référence : `php artisan test` 1167 PASS / 8 SKIP, `vitest` 899 PASS, `playwright` 40 PASS.

---

## 0. MASTER_REVIEW_VERDICT

```
MASTER_REVIEW_VERDICT: REWORK
SCOPE: bounded — 1 P1 sécurité bloquant, 2 P1 lifecycle/UX souhaitables, 5 P2 drift gouvernance
ESTIMATED REWORK: ~3-4 heures dev pour P1 sécurité + tests
RELEASE DECISION: REWORK_REQUIRED_BEFORE_HARDWARE_UAT
  → fix P1#1 (ComposerStepController branch isolation)
  → après quoi : CODE_LOCAL_PASS_PROCEED_TO_HARDWARE_UAT
```

Rationale concise :

- Le code automatisé est **réellement vert** sur tout le périmètre testé : NF525 cash-at-counter tient, pricing SSOT delivery est restauré, stock V2 atomique et symétrique, kiosk lockdown propre, geocode bloque correctement.
- **Une violation directe de l'invariant CLAUDE.md #8 (branch isolation must never be weakened)** subsiste sur `ComposerStepController` : un Branch Admin de la branche A peut muter (créer/modifier/supprimer) des étapes de composer profile appartenant à la branche B, parce que les routes `/profiles/{profile}/steps`, `/steps/{step}` ne valident pas le `branch_id_scope` du profil contre `user->branch_id`. Ce gap n'est pas couvert par les tests existants (`ComposerAuthzMinimalTest` ne teste que le profile, pas les steps).
- Deux P1 supplémentaires (E2E cancel-before-confirm, tests rupture UX kiosk/POS) sont fortement recommandés avant hardware UAT mais peuvent être traités en parallèle.

---

## 1. Findings P0/P1/P2 (file:line)

### 1.1 P0 — aucun

Aucun blocker P0 détecté. Les 4 P0 que j'avais identifiés précédemment (delivery SSOT web, cast order_type, bundle kiosk-admin, source kiosk admin) sont tous correctement corrigés dans B0.

### 1.2 P1 — Bloquant pour hardware UAT (1)

#### **P1-A** — `ComposerStepController` viole l'invariant branch isolation

**Fichier** : `app/Http/Controllers/Admin/ComposerStepController.php`
**Lignes** : 18-33 (méthodes `store`, `update`, `destroy`)
**Routes affectées** (`routes/api.php:641-643`) :
```
POST   /api/admin/composer/profiles/{profile}/steps   (no scope check)
PUT    /api/admin/composer/steps/{step}               (no scope check)
DELETE /api/admin/composer/steps/{step}               (no scope check)
```

**Description** :
- `ComposerStepController::store(profile)` — pas d'appel à `authorizeBranchScope($profile->branch_id_scope)`.
- `ComposerStepController::update(step)` — pas d'appel à `authorizeBranchScope($step->profile->branch_id_scope)`.
- `ComposerStepController::destroy(step)` — pas d'appel à `authorizeBranchScope`.

`ComposerProfileController` (`app/Http/Controllers/Admin/ComposerProfileController.php:58-70`) implémente bien la garde via `authorizeBranchScope()` qui exige soit `Admin`/`Tenant Admin`, soit `user.branch_id == profile.branch_id_scope`. Cette garde n'est pas répliquée sur le step controller.

**Impact** :
- Un `Branch Admin` rattaché à branche A, possédant la permission `catalog.compose`, peut envoyer `POST /api/admin/composer/profiles/{branch_B_profile_id}/steps` et créer/modifier des steps appartenant à la composition produit d'une autre branche. Pareil en update/destroy.
- Violation de **CLAUDE.md invariant #8 — Branch isolation must never be weakened**.
- En multi-tenant SaaS, c'est une escalade horizontale.

**Test gap** : `tests/Feature/Composer/ComposerAuthzMinimalTest.php` ne couvre que `POST /profile`. Aucun test ne tente une mutation cross-branche sur `/profiles/{profile}/steps` ou `/steps/{step}`.

**Mitigation requise** : voir Plan §2 / Mission B-FIX-1.

### 1.3 P1 — Souhaitable avant hardware UAT (2)

#### **P1-B** — Test E2E B9 manque la transition `PENDING_COUNTER → REFUNDED` sans passage par `PAID`

**Fichier** : `tests/e2e/composer-mega-flow.spec.js`

Le E2E couvre :
- Création kiosk cash → KDS badge → POS confirm → fiscal_sequence_no alloué → KDS retire badge.
- Création kiosk cash → POS confirm puis cancel (refund) — assertion `payment_status=REFUNDED, status=CANCELED, fiscal_sequence_no=null` après cancel.

Il manque le scénario probable en production : **client borne abandonne (no-show) → POS staff cancel directement le `PENDING_COUNTER` sans avoir encaissé**.

**Couverture backend par contre** : `tests/Feature/Payment/CounterDeferredPaymentLifecycleTest.php` ET `PaymentStateMachineTransitionsTest.php` couvrent la transition. C'est uniquement le E2E browser qui manque ce cas.

**Impact** : faible probabilité de bug, mais hardware UAT va probablement déclencher ce flow et il faut un test E2E pour s'assurer que KDS retire bien le ticket et qu'aucun reçu fiscal n'est imprimé.

#### **P1-C** — Tests visuels rupture UX absents pour kiosk et POS

**Fichiers attendus, manquants** :
- `tests/js/kioskRuptureUx.spec.js` — non trouvé.
- `tests/js/posRuptureUx.spec.js` — non trouvé.

**Couverture présente** :
- `tests/Feature/Stock/StockRuptureAvailabilitySyncTest.php` prouve que stock=0 ⇒ `ItemBranchAvailability::is_available=false` + `unavailable_reason='stock_rupture'` + `KioskMenuService::build()` expose le flag.
- `tests/js/posItemAvailabilityHandler.spec.js` prouve que POS reçoit l'event et flip côté Vue (prune cart, sync modal).

**Gap réel** : aucune assertion visuelle prouvant que le **badge "Indisponible aujourd'hui" / pastille rouge** s'affiche réellement dans `KioskWizardComponent.vue` et `PosComponent.vue`, ni que le clic sur un choix en rupture est bloqué.

**Impact** : la pipeline backend → projection → handler frontend fonctionne, mais le rendu UX final n'a pas de garde de régression. Une refonte CSS pourrait casser le badge sans qu'aucun test n'échoue.

### 1.4 P2 — Drift gouvernance / feature gaps non bloquants (5)

#### **P2-A** — Seeder permissions inclut 4 rôles au lieu de 2 (spec drift)

**Fichier** : `database/seeders/ComposerPermissionsMinimalSeeder.php:12`

Constante `ROLE_NAMES = ['Admin', 'Branch Manager', 'Tenant Admin', 'Branch Admin']`.

La spec utilisateur (`docs/decisions/HG-DASHBOARD-AUTHZ-CATALOG-OPS approved minimal`) demandait "permissions minimales : Branch Admin + Tenant Admin uniquement, pas de refonte large".

`Admin` est le super-admin légitime, `Branch Manager` existe bien dans `RoleTableSeeder.php:50` donc le `givePermissionTo()` est exécuté (pas de silent skip comme pourrait le suggérer un audit superficiel). Pas de risque sécurité, mais drift par rapport au spec "minimal".

**Recommandation** : valider avec humain si on garde ce périmètre (4 rôles) ou si on réduit à 2 (`Branch Admin`, `Tenant Admin`). Pas un blocker.

#### **P2-B** — Counter-collect routes en closures inline plutôt que `PosCounterCollectController.php`

**Fichier** : `routes/api.php:654-713` (4 routes en closures)

Spec attendait `app/Http/Controllers/Admin/PosCounterCollectController.php`. Les closures délèguent correctement à `PaymentService::confirmCounterPayment / cancelCounterPayment` et `OrderService::collectKioskCash`. Fonctionnel, mais maintenabilité réduite et test difficile sur le contrôleur lui-même (les tests existants attaquent les routes via HTTP).

**Recommandation** : consolider en `PosCounterCollectController` lors d'une mission cleanup ultérieure. Ne pas bloquer release.

#### **P2-C** — Pas de `PosLiveBoardComponent` pour visibilité kiosk côté POS

L'API `/api/admin/pos/counter-collect/pending` existe, mais aucun composant Vue ne liste les commandes kiosk en cours côté POS. `PosCounterCollectComponent.vue` (ou équivalent) gère la liste pending, mais il n'y a pas de "live board" agrégé POS+kiosk.

La spec utilisateur mentionnait "POS live board voit commandes POS + kiosk". L'objectif fonctionnel est partiellement couvert via le pending panel, mais pas via un dashboard agrégé.

**Recommandation** : valider business si nécessaire. Sinon P2 / future.

#### **P2-D** — Pas de test photo upload via composer profile

Le composer profile semble s'appuyer sur le path existant `/api/admin/item/change-image/{item}` (déjà testé par `PhotoEndToEndKioskInvalidationTest`). Pas de test E2E composer → photo → invalidation. Si le composer expose son propre endpoint photo, c'est non couvert.

**Recommandation** : ajouter un test E2E `composerPhotoEndToEnd` ou documenter explicitement que le composer ne touche pas la photo (délégation totale au path item existant).

#### **P2-E** — Tests broadcast n'assertent pas le payload `composition_snapshot`

`tests/Feature/KioskRealtimeBroadcastTest.php` enregistre l'event mais ne vérifie pas que le payload broadcast contient bien le `composition_snapshot` des `order_items`. Le snapshot est correctement persisté en DB (`add_composition_snapshot_to_order_items.php`) et exposé dans `OrderItemResource`, mais le broadcast ne le garantit pas explicitement.

**Recommandation** : étendre l'assertion. Pas un blocker.

---

## 2. Plan de finition (P1_FINISHING_PLAN)

### Mission B-FIX-1 — ComposerStep branch isolation (P1 BLOQUANT)

**Précondition** : aucune (gates B3/B5 déjà approuvées).

#### Scope

1. `ComposerStepController` : ajouter une garde `authorizeBranchScope` symétrique à `ComposerProfileController`.
   - `store($profile)` : `$this->authorizeBranchScope($request, $profile->branch_id_scope)`.
   - `update($step)` : `$this->authorizeBranchScope($request, $step->profile->branch_id_scope)`.
   - `destroy($step)` : idem.
2. Refactor cleaner : extraire `authorizeBranchScope` dans `AdminController` ou un trait `AuthorizesBranchScope`, réutilisé par les deux controllers.
3. Étendre `tests/Feature/Composer/ComposerAuthzMinimalTest.php` pour couvrir :
   - `POST /api/admin/composer/profiles/{branchB_profile_id}/steps` avec user `Branch Admin` branche A → 403.
   - `PUT /api/admin/composer/steps/{branchB_step_id}` avec user branche A → 403.
   - `DELETE /api/admin/composer/steps/{branchB_step_id}` avec user branche A → 403.
   - `Tenant Admin` peut faire les 3 opérations cross-branch → 200/201.

#### Forbidden
- Ne pas toucher la logique métier des services.
- Ne pas modifier `routes/api.php` (le fix est dans le controller).
- Ne pas modifier la machine d'état.

#### Allowlist B-FIX-1
```
app/Http/Controllers/Admin/ComposerStepController.php
app/Http/Controllers/Admin/ComposerProfileController.php       # extraction de authorizeBranchScope
app/Http/Controllers/Admin/AdminController.php                  # ou nouveau trait/method partagé
tests/Feature/Composer/ComposerAuthzMinimalTest.php             # extension steps coverage
missions/PRODUCT-COMPOSER-SYNC-B-FIX-1-COMPOSER-STEP-BRANCH-ISOLATION/{allowlist.txt,execute_brief.md,input.json}
reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-B-FIX-1-COMPOSER-STEP-BRANCH-ISOLATION.md
reports/post_execute_latest.log
```

#### Tests obligatoires B-FIX-1
- `tests/Feature/Composer/ComposerAuthzMinimalTest.php` étendu : 3 nouvelles assertions cross-branch (store/update/destroy).
- Régression : tous les tests existants restent verts (`php artisan test --filter Composer`).
- `git diff --check` propre.

#### Critères PASS
- 3 nouveaux tests cross-branch verts.
- Aucune édition hors allowlist.
- Self-audit Codex confirme.

#### Critères REWORK
- Test cross-branch échoue.
- Édition de routes/api.php sans nécessité.
- Régression sur ComposerProfileApiTest existant.

---

### Mission B-FIX-2 — E2E cancel-before-confirm + tests rupture UX (P1 SOUHAITABLE)

**Peut tourner en parallèle de B-FIX-1.**

#### Scope

1. Étendre `tests/e2e/composer-mega-flow.spec.js` (ou créer `tests/e2e/cash-counter-direct-cancel.spec.js`) :
   - Scenario : créer order kiosk cash `PENDING_COUNTER` → POS staff cancel **immédiatement** sans confirmer → assert :
     - `payment_status = REFUNDED(20)`.
     - `status = CANCELED(16)`.
     - `fiscal_sequence_no` reste `NULL`.
     - KDS event `OrderCanceled` reçu.
     - Stock release effectif (movement avec `reason='order_canceled'` créé).
2. Créer `tests/js/kioskRuptureUx.spec.js` :
   - Mount `KioskWizardComponent.vue` avec un item dont une variation a `is_available=false`.
   - Assert que la variation s'affiche grisée avec badge "Indisponible aujourd'hui".
   - Assert que click sur la variation ne déclenche pas la sélection.
3. Créer `tests/js/posRuptureUx.spec.js` :
   - Mount `PosComponent.vue` avec item rupture branche.
   - Assert pastille rouge sur le bouton choix.
   - Assert tooltip "Rupture branche".
   - Assert refus envoi commande si choix rupture sélectionné.

#### Forbidden
- Pas de modification du code Vue runtime (les composants doivent déjà supporter ces états ; si pas → escalade vers une mission UI dédiée).
- Pas de modification backend.

#### Allowlist B-FIX-2
```
tests/e2e/composer-mega-flow.spec.js                            # extension
# OU
tests/e2e/cash-counter-direct-cancel.spec.js                    # nouveau fichier dédié
tests/js/kioskRuptureUx.spec.js                                 # nouveau
tests/js/posRuptureUx.spec.js                                   # nouveau
missions/PRODUCT-COMPOSER-SYNC-B-FIX-2-LIFECYCLE-RUPTURE-TESTS/{allowlist.txt,execute_brief.md,input.json}
reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-B-FIX-2-LIFECYCLE-RUPTURE-TESTS.md
reports/post_execute_latest.log
```

#### Tests obligatoires B-FIX-2
- 1 E2E nouveau (cancel-before-confirm) vert.
- 2 specs Vitest nouvelles (rupture kiosk + POS) verts.
- Si specs échouent, signaler quels composants ne supportent pas l'état rupture → ouvrir mission UI séparée.

---

### Mission B-FIX-3 — P2 cleanup (OPTIONNEL, post-UAT)

P2-A à P2-E peuvent être traités après hardware UAT. Pas de blocker. Recommandation :
- P2-A : confirmer humain sur périmètre rôles seeder.
- P2-B : refactor closures → controller dans une mission cleanup.
- P2-C : décider business si PosLiveBoard agrégé est requis.
- P2-D : ajouter test E2E composer photo OU documenter délégation au path item.
- P2-E : étendre assertions broadcast.

---

## 3. Files Claude must recheck (FILES_TO_RECHECK)

Aucun fichier supplémentaire à relire pour ce verdict. Les findings sont basés sur lecture directe et 4 audits Explore croisés. Si Codex challenge le verdict :

- `app/Http/Controllers/Admin/ComposerStepController.php` (preuve P1-A).
- `app/Http/Controllers/Admin/ComposerProfileController.php:58-70` (référence `authorizeBranchScope`).
- `routes/api.php:635-647` (routes composer).
- `tests/Feature/Composer/ComposerAuthzMinimalTest.php` (gap test).
- `tests/e2e/composer-mega-flow.spec.js` (couverture E2E B9).

---

## 4. Tests to run next (TESTS_TO_RUN_NEXT)

### Avant de signer B-FIX-1 PASS
```bash
php artisan test --filter ComposerAuthzMinimalTest
php artisan test --filter ComposerProfileApiTest
php artisan test --filter Composer
```

### Avant de signer B-FIX-2 PASS
```bash
npx playwright test tests/e2e/composer-mega-flow.spec.js --project=chromium
npx playwright test tests/e2e/cash-counter-direct-cancel.spec.js --project=chromium
npx vitest run tests/js/kioskRuptureUx.spec.js tests/js/posRuptureUx.spec.js
```

### Régression complète après B-FIX-1 + B-FIX-2 (avant hardware UAT)
```bash
php artisan test                                                # full suite
npx vitest run                                                  # full Vitest
npx playwright test --project=chromium                          # full E2E
bash tools/lint/forbidden_bundles.sh
node tools/lint/scan_kiosk_bundles.mjs
node tools/audit/order-service-symmetry.mjs
git diff --check
```

Attendu : 0 régression. Si 0 régression, transition vers `PHYSICAL_UAT_PLAN`.

---

## 5. Physical UAT plan (PHYSICAL_UAT_PLAN)

Une fois B-FIX-1 (impératif) et B-FIX-2 (recommandé) PASS, exécuter le UAT hardware tel que défini dans `docs/hardware/UAT_COMPOSER_2026-04-27.md`. Points critiques à valider sur hardware :

1. **Borne kiosk physique** :
   - Lockdown : pas d'accès admin/POS via touch screen, pas via URL bar manuelle (vérifier kiosk mode iOS/Android lockdown).
   - Bouton Retour paiement : actif avant submit, disabled pendant.
   - Cash-at-counter : impression ticket non-fiscal, message "À régler au comptoir – Numéro X".

2. **TPE / terminal paiement externe** :
   - Carte : succès → `payment_status=PAID` + ticket fiscal.
   - Refus carte : kiosk reste sur écran paiement, possibilité de retry ou retour cart.
   - Timeout TPE : comportement défini (retry/cancel).

3. **Imprimante fiscale POS** :
   - Confirmation comptoir kiosk cash → ticket fiscal NF525 imprimé avec `fiscal_sequence_no` correct.
   - Cancel kiosk cash → pas d'impression fiscale.
   - Reprint counter slip → impression non-fiscale (bon de commande).

4. **KDS écran physique** :
   - Visibilité du badge "PAIEMENT COMPTOIR" lisible à distance restaurant.
   - Retrait du badge en realtime quand POS confirme.
   - Suppression du ticket quand cancel.

5. **Network loss / reconnect** :
   - Borne offline → queue local + replay correct au retour réseau (idempotency keys assurées par B5a).
   - POS offline → blocage commandes ou queue local selon politique.
   - WebSocket déconnexion → bannière, polling fallback (déjà en place selon les rapports).

6. **Concurrence multi-borne / multi-POS** :
   - 2 commandes parallèles épuisent stock → 1 succès, 1 rupture (déjà testé en concurrence backend, mais à valider visuellement sur 2 hardware).
   - 2 POS staff confirment le même counter order → 1 succès, 1 idempotent (PaymentService::confirmCounterPayment idempotent).

7. **Géocodage Google Maps en conditions réelles** :
   - Adresse non geocodée → bannière 422 GEOCODE_FAILED côté checkout web et POS.
   - Adresse hors zone → fee correct selon distance réelle.

8. **Multi-branche** :
   - Branche A passe commande, KDS branche B ne reçoit pas (déjà testé backend).
   - Composer profile branche A invisible côté kiosk branche B (déjà testé backend).

---

## 6. Release decision (RELEASE_DECISION)

```
RELEASE_DECISION: REWORK_REQUIRED_BEFORE_HARDWARE_UAT

Rationale:
- Local automated suite is genuinely green and Codex's claim LOCAL_FULL_GREEN is valid.
- However, ComposerStepController violates branch isolation invariant (CLAUDE.md §3.8).
- Hardware UAT must NOT begin while a privilege-escalation gap is open. UAT involves real
  multi-branch hardware setups; if the gap is observed in UAT, rework cycle would be longer
  than fixing it now (estimated 3-4 hours).

Path forward:
1. Codex executes B-FIX-1 (ComposerStep branch isolation).
2. Codex executes B-FIX-2 (E2E cancel-before-confirm + rupture UX tests). PARALLELIZABLE.
3. Claude post-mission audits both.
4. If both PASS, transition to CODE_LOCAL_PASS_PROCEED_TO_HARDWARE_UAT.
5. Hardware UAT signed off by humain → commercial release.

P2 items can be deferred to post-UAT hardening sprint without blocking release.
```

---

## 7. Notes finales

### Ce qui a été audité

- B0/B5b/B5a/B2/B4/B6/B7/B8/B9 : audits Explore profonds + lecture directe code critique.
- Tests existants : présence, couverture, et invariants vérifiés.
- Routes API : vérification middlewares + scope authz.
- NF525 fiscal sequence : transitions PaymentStateMachine + service confirm/cancel.
- Branch isolation : controllers + queries + scopes.
- Pricing SSOT : DeliveryFeeService + PricingService + OrderRequest + helper frontend.

### Ce que je n'ai pas vérifié exhaustivement

- L'intégralité des 1167 PHPUnit tests (Codex prétend tous verts, je le crois sur la base des évidences ciblées vérifiées).
- Le code des 899 Vitest tests dans le détail (idem).
- Les 40 Playwright tests autres que `composer-mega-flow.spec.js`.
- La sécurité des autres permissions Spatie (hors composer).
- Les performances et les indexes DB.

Ces périmètres ont été validés par Codex et ses gates de validation automatique. Si le humain veut un audit exhaustif au-delà, le signaler.

### Confiance globale

- **Confiance haute** sur : NF525 cash-at-counter, pricing SSOT, stock V2 backend, kiosk lockdown, geocode block.
- **Confiance moyenne** sur : composer dashboard write (gap branch isolation step + 1 spec drift), E2E coverage (manque cancel-before-confirm + visual rupture).
- **Confiance basse** sur : photos via composer (non testé), POS live board agrégé (manquant).

### Anti-drift CLAUDE.md respecté

- `Vision > vitesse` : verdict REWORK même si suite verte, parce que invariant #8 violé.
- `Architecture > convenance locale` : refus de tolérer une closure d'authz manquante "parce que les autres tests passent".
- `Correctness > token savings` : audit profond malgré l'effort.
- `Real evidence > confidence` : 4 agents Explore + lecture code, pas confiance blind sur self-audits Codex.
- `Partial > wrong` : verdict REWORK borné, pas HOLD ouvert.
- `Blocked > silently dangerous` : flag explicite du P1-A même si Codex ne l'a pas signalé.

---

Document généré par Claude le 2026-04-27.
