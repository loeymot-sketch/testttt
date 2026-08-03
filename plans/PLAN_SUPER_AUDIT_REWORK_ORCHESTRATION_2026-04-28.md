# Plan Super Audit Rework Orchestration — FoodKing Caisse V1 — 2026-04-28

Date: 2026-04-28  
Auteur: Codex  
Statut: READY_FOR_EXECUTION  
Decision globale: REWORK_BEFORE_HARDWARE_UAT  
Source principale: `reports/audit/CLAUDE_SUPER_AUDIT_RESPONSE_OPUS47_2026-04-28.md`  
Note source: la reponse Claude/Orcai est utile comme backlog de risques, mais elle n'est pas un audit final ferme: elle ne contient pas `FIN_COMPLETE`, annonce un mode sans acces direct au depot, et s'arrete encore en plein tableau a `FIX-039`. Ce plan transforme donc ses signaux en missions executables avec validation machine obligatoire avant correction.

---

## 1. Doctrine d'execution

Objectif: finir la preuve de fonctionnement global avant hardware UAT, sans relancer une orchestration abstraite interminable.

Regle centrale: **evidence-first**. Toute correction produit doit etre precedee d'une preuve machine:

- lecture ligne par ligne du code concerne;
- test reproductible qui echoue ou trou de couverture clairement documente;
- correction minimale;
- re-run du test cible et de la suite de non-regression;
- rapport PASS/REWORK.

Invariants FoodKing non negociables:

- prix: backend SSOT, aucun prix frontend autoritaire;
- status: enums FoodKing, pas de chaines magiques;
- `branch_id`: isolation stricte;
- dispatch/events apres commit DB;
- pas de modification des zones frozen sans gate;
- si `OrderService` ou `FrontendOrderService` bougent: symetrie explicite et testee.

---

## 2. Position actuelle consolidee

### 2.1 Valide fortement localement

Ces preuves existent deja mais restent a requalifier dans M0 si elles dependent de helpers ou de mocks:

- C0: hotfix kiosk auto-return post-payment deja implemente et rapporte PASS.
- C1: process kiosk full-flow rapporte PASS.
- C2: process POS full-flow rapporte PASS.
- D1: kiosk design audit: 90 audits, `seriousTotal=0`.
- D2: POS design audit: 30 audits, `seriousTotal=0`.
- D3: KDS/OSS design audit: 20 audits, `seriousTotal=0`.
- Build frontend: `npm run production` PASS.
- Stock feature suite: 17 tests PASS.
- Queue number suite: 4 tests PASS.
- Menu/catalog suite: 20 PASS, 6 skipped SQLite/MySQL.
- Vitest sync/rupture/delivery/geocode: 27 tests PASS.
- Playwright realtime/KDS static contracts: 10 PASS avec repeat-each 5.

### 2.2 Non prouve avant hardware UAT

Ces points restent bloquants ou critiques:

- C3: runtime multi-surface live Kiosk + POS + KDS + OSS simultanes.
- C4: stress stock massif et rupture live sous concurrence.
- C5: queue number unique sous concurrence POS + kiosk.
- C6: fiscal/outbox/persistence complet: HMAC, Z-report, replay idempotent, cash-at-counter.
- C8: couplage payment/order status et side effects atomiques par chemin de paiement.
- C9: dashboard gestion restaurateur: categories, produits, photos, stock, composer, publication.
- MySQL 8: tests menu filtres surfaces actuellement skipped sous SQLite.
- Rate limit KDS/OSS: source du toast `Too Many Attempts.` a verifier en runtime.
- Authz/branch isolation: matrice complete non encore prouvee.

---

## 3. DAG de missions

Ordre strict:

```text
M0 Machine validation
  -> M1 C3 runtime multi-surface
  -> M2 C6 fiscal + outbox + persistence
  -> M3 C8 payment lifecycle atomicity
  -> M4 C4/C5 stock + queue stress
  -> M5 delivery/maps/pricing SSOT
  -> M6 authz/branch/counter-collect/lockdown
  -> M7 C9 dashboard + composer propagation
  -> M8 D4-D13 production-like visual/responsive/perf/chaos
  -> M9 consolidation + hardware UAT brief
```

Parallelisme autorise apres M0:

- M1 et M2 peuvent avancer en parallele si M0 ne trouve pas de P0 global.
- M4 peut avancer en parallele de M5.
- M7 attend M6 si authz dashboard est touche.

Stop conditions:

- P0 fiscal, pricing SSOT, branch leak, event before commit, ou queue duplicate reproductible: stop mission courante, corriger avant toute mission P2.
- Deux runs flakies identiques: root cause obligatoire, pas de retry magique.
- Test qui mocke le comportement critique au lieu de traverser le backend: verdict PARTIAL, pas PASS.

---

## M0 — Validation machine des hypotheses Claude

Priorite: P0  
Type: lecture + rapports + commandes grep, pas de correction produit sauf fail bloquant trivial et isole.  
Sortie obligatoire: `reports/audit/CODEX_M0_MACHINE_VALIDATION_2026-04-28.md`

Objectif: separer les faits des hypotheses de l'audit Claude incomplet.

Fichiers a lire:

- `tests/e2e/helpers/process-audit.js`
- specs C0/C1/C2 sous `tests/e2e/`
- `tests/e2e/design/_shared/design-audit-helpers.js`
- `app/Providers/EventServiceProvider.php`
- `app/Jobs/DispatchDomainEventsJob.php`
- `app/Listeners/Persist*ToOutbox.php`
- listeners stock: decrement/release/cancel/refund
- `app/Services/Fiscal/FiscalSealingService.php`
- `app/Services/Fiscal/ZReportService.php`
- `app/Services/PaymentService.php`
- `app/Domain/Order/OrderStateMachine.php`
- `app/Domain/Order/PaymentStateMachine.php`
- `app/Http/Requests/OrderRequest.php`
- `app/Http/Requests/PosOrderRequest.php`
- `resources/js/helpers/deliveryCharge.js`
- `resources/js/router/modules/kioskRoutes.js`
- `routes/api.php`
- controllers KDS/OSS/POS/items/composer/counter-collect
- `tests/Feature/Stock/StockConcurrentDecrementTest.php`
- `tests/Feature/QueueNumberConcurrencyTest.php`
- `tests/Feature/Stock/StockSymmetryDiffTest.php`

Commandes obligatoires:

```bash
rg -n "page\\.route\\(|route\\.fulfill\\(|route\\.abort\\(|mock|fixture" tests/e2e tests/Playwright
rg -n "->status\\s*=\\s*'|->payment_status\\s*=\\s*'|==\\s*'pending'|===\\s*'pending'" app
rg -n "afterCommit|\\$afterCommit|ShouldQueueAfterCommit" app/Providers app/Listeners app/Events app/Jobs
rg -n "throttle|counter-collect|kds|order-status|oss" routes app/Http
rg -n "delivery_charge|delivery_fee|DeliveryFeeService|deliveryCharge" app resources/js tests
```

Critere PASS:

- Chaque hypothese Claude est classee `FACT`, `FALSE`, `PARTIAL`, ou `UNKNOWN`.
- Les suites C0/C1/C2 sont qualifiees: `runtime backend`, `navigation-only`, ou `mocked`.
- Le helper `clearTransientUi()` est declare acceptable ou suspect avec raison precise.
- Aucun P0 evident non corrige n'est ignore.

Si M0 trouve un P0 reel:

- ouvrir une sous-mission `M0-P0-<slug>`;
- corriger minimalement;
- ajouter le test qui echoue sans correction;
- re-run cible.

---

## M1 — C3 Runtime Multi-Surface Live

Priorite: P0  
Objectif: prouver que FoodKing ne fonctionne pas en silos: kiosk, POS, KDS, OSS doivent voir la meme commande sans reload manuel.

Fichiers autorises:

- `tests/e2e/c3-runtime-multi-surface.spec.js` (create)
- helpers Playwright sous `tests/e2e/helpers/`
- fixtures de test si necessaire
- correction produit uniquement si le test prouve un bug: routes realtime, store KDS/OSS, event/broadcast, throttle.

Scenarios obligatoires:

1. Kiosk card payment -> KDS recoit la commande en moins de 5s -> OSS affiche la commande.
2. POS order -> KDS recoit la commande en moins de 5s -> OSS affiche la commande.
3. Kiosk + POS en parallele -> KDS recoit deux commandes distinctes -> queue numbers uniques.
4. KDS bump/prepared -> OSS passe a ready sans reload.
5. Branch isolation runtime: commande branch A invisible sur KDS branch B.
6. Reconnect: KDS perd le realtime, une commande arrive, KDS reconnecte et rattrape sans doublon.

Commandes:

```bash
npx playwright test tests/e2e/c3-runtime-multi-surface.spec.js --project=chromium --repeat-each=3 --retries=0
```

PASS:

- 3/3 vert;
- aucun `page.route()` ne mocke les API critiques;
- apparition KDS/OSS mesuree par DOM, pas par simple response HTTP;
- pas de `Too Many Attempts`.

REWORK:

- timeout > 5s;
- commande manquante;
- doublon KDS;
- 429;
- fuite cross-branch.

---

## M2 — C6 Fiscal, Outbox, Persistence

Priorite: P0  
Objectif: prouver la securite fiscale et la fiabilite de la propagation evenementielle.

Fichiers autorises:

- `tests/Feature/Fiscal/FiscalNullAtCreationTest.php`
- `tests/Feature/Fiscal/FiscalCancelNoSequenceTest.php`
- `tests/Feature/Fiscal/FiscalRefundNegativeReceiptTest.php`
- `tests/Feature/Fiscal/HmacChainIntegrityTest.php`
- `tests/Feature/Fiscal/ZReportFullLifecycleTest.php`
- `tests/Feature/Outbox/OutboxReplayIdempotenceTest.php`
- corrections minimales dans `FiscalSealingService.php`, `ZReportService.php`, `PaymentService.php`, `DispatchDomainEventsJob.php`, listeners outbox si test rouge.

Scenarios obligatoires:

- kiosk cash-at-counter cree une commande avec fiscal sequence NULL.
- confirm counter payment alloue une sequence fiscale atomiquement.
- cancel avant paiement ne cree aucune sequence fiscale.
- reprint ne cree jamais une nouvelle sequence.
- refund cree un recu fiscal negatif avec sequence N+1.
- HMAC chain: 20 recus, verification de chaine, detection si rupture.
- Z-report: totaux, ventes/remboursements, double cloture refusee, pending refuse.
- Outbox replay: rejouer la meme entree 3 fois ne produit qu'un effet logique.
- Event rollback: event dans transaction rollback ne produit aucun side effect.

Commandes:

```bash
php artisan test tests/Feature/Fiscal tests/Feature/Outbox --stop-on-failure
php artisan test --filter=FiscalNullAtCreationTest --repeat=5
php artisan test --filter=OutboxReplayIdempotenceTest --repeat=5
```

PASS:

- fiscal sequence jamais avant encaissement reel;
- HMAC et Z-report verifiables;
- outbox idempotente;
- dispatch apres commit respecte.

---

## M3 — C8 Payment Lifecycle et Atomicite

Priorite: P1  
Objectif: verrouiller l'etat commande/paiement et les side effects selon chaque chemin.

Fichiers autorises:

- `tests/Feature/Payment/OrderPaymentStateMachineFullMatrixTest.php`
- `tests/Feature/Payment/PaymentSideEffectsAtomicityTest.php`
- `tests/Feature/Payment/CounterDeferredFullLifecycleTest.php`
- `tests/Feature/Payment/OrderPaymentAtomicCouplingTest.php`
- corrections minimales dans `PaymentStateMachine.php`, `OrderStateMachine.php`, `PaymentService.php` si preuve rouge.

Matrice minimale:

- kiosk card;
- kiosk cash-at-counter confirm;
- kiosk cash-at-counter cancel;
- POS cash;
- POS card;
- refund.

Assertions:

- `PaymentStatus` et `OrderStatus` changent dans la meme transaction quand necessaire.
- KDS n'est notifie que quand la commande doit etre preparee.
- stock decrement/release arrive au bon moment.
- fiscal allocation suit strictement le paiement reel.

Commandes:

```bash
php artisan test tests/Feature/Payment --stop-on-failure
php artisan test --filter=CounterDeferredFullLifecycleTest --repeat=3
```

---

## M4 — C4/C5 Stock et Queue Stress

Priorite: P1  
Objectif: prouver la tenue en rush service.

Fichiers autorises:

- `tests/Feature/Stock/StockStress50ConcurrentTest.php`
- `tests/Feature/Stock/StockReleaseConcurrentTest.php`
- `tests/Feature/QueueNumber/QueueNumberStress20WorkersTest.php`
- corrections dans `StockService.php`, model/queries stock, queue number service/order services uniquement si test rouge.

Scenarios:

- 50 commandes concurrentes sur stock initial 50 -> stock final 0, 50 mouvements.
- stock initial 30, 50 tentatives -> 30 succes, 20 ruptures propres, jamais negatif.
- 20 orders puis 10 cancel + 10 refund paralleles -> stock release exact.
- 20 ou 50 creations POS+kiosk paralleles meme branch/date -> queue numbers uniques.
- verifier retry applicatif si collision DB.

Attention:

- SQLite ne prouve pas la concurrence MySQL. Si SQLite fausse le test, creer un mode MySQL local/Docker et documenter.

Commandes:

```bash
php artisan test tests/Feature/Stock --stop-on-failure
php artisan test tests/Feature/QueueNumber --stop-on-failure
```

PASS:

- jamais de stock negatif;
- mouvements append-only coherents;
- queue numbers uniques;
- aucun gap injustifie si l'algorithme promet une sequence continue.

---

## M5 — Delivery / Maps / Pricing SSOT

Priorite: P1  
Objectif: fermer le risque de prix ou livraison forgee.

Fichiers autorises:

- `tests/Feature/Delivery/DeliveryFeeSsotCrossSurfaceTest.php`
- `tests/Feature/Delivery/DeliveryGeocodeFailureTest.php`
- `tests/Feature/Delivery/DeliveryBoundaryCasesTest.php`
- corrections dans `DeliveryFeeService.php`, `OrderRequest.php`, `PosOrderRequest.php`, API delivery/geocode.
- `resources/js/helpers/deliveryCharge.js` uniquement pour preview non autoritaire.

Scenarios:

- payload client `delivery_fee: 0` sur distance payante -> backend recalcule.
- meme adresse web/POS/kiosk si livraison active -> meme fee backend.
- geocode introuvable -> 422 `GEOCODE_FAILED`.
- Google Maps down -> 422 ou erreur controlee, pas fallback silencieux 5 euros.
- rayon max, rayon max + 0.01, seuil gratuit, min/max clamp.

Commandes:

```bash
php artisan test tests/Feature/Delivery --stop-on-failure
npx vitest run tests/js/deliveryCharge.spec.js tests/js/checkoutGeocodeError.spec.js
```

PASS:

- backend est toujours autoritaire;
- frontend ne fait qu'afficher une preview;
- erreurs geocode bloquees proprement.

---

## M6 — AuthZ, Branch Isolation, Counter-Collect, Kiosk Lockdown

Priorite: P1  
Objectif: fermer les fuites securite avant UAT.

Fichiers autorises:

- tests Feature authz dedies sous `tests/Feature/Authz/`
- `tests/e2e/kiosk-lockdown.spec.js`
- controllers identifies par M0 si scope branch absent
- `routes/api.php`
- nouveau controller counter-collect si extraction necessaire
- `resources/js/router/modules/kioskRoutes.js` si route admin accessible.

Scenarios:

- Branch Admin A ne lit/modifie jamais les donnees branch B.
- KDS/OSS/POS endpoints filtrent strictement par branch.
- counter-collect: non authentifie -> 401; autre branche -> 403; role sans permission -> 403.
- kiosk context ne peut pas atteindre `/admin/dashboard`, `/admin/pos`, `/admin/kds`, `/admin/items`.

Commandes:

```bash
php artisan test tests/Feature/Authz --stop-on-failure
npx playwright test tests/e2e/kiosk-lockdown.spec.js --project=chromium --repeat-each=3 --retries=0
```

---

## M7 — C9 Dashboard Management et Composer Propagation

Priorite: P2 avant UAT, P1 si le restaurateur doit gerer son menu pendant UAT.  
Objectif: prouver le parcours gestion complet.

Fichiers autorises:

- `tests/e2e/c9-dashboard-categories.spec.js`
- `tests/e2e/c9-dashboard-products.spec.js`
- `tests/e2e/c9-dashboard-stock.spec.js`
- `tests/e2e/c9-dashboard-composer.spec.js`
- `tests/Feature/Menu/ImagePropagationEndToEndTest.php`
- `tests/Feature/Composer/ComposerPublishProjectionTest.php`
- corrections dashboard/composer/menu projection si test rouge.

Scenarios:

- creer/modifier/reordonner/supprimer categorie.
- creer produit, upload photo, modifier prix, desactiver, verifier kiosk/POS.
- modifier stock, forcer rupture, verifier item grise, lever rupture.
- creer composer profile, ajouter steps, publier, verifier kiosk, depublier.
- publish composer -> outbox -> menu projection -> kiosk/POS menu API.

Commandes:

```bash
npx playwright test tests/e2e/c9-dashboard-*.spec.js --project=chromium --retries=0
php artisan test tests/Feature/Menu/ImagePropagationEndToEndTest.php tests/Feature/Composer/ComposerPublishProjectionTest.php
```

---

## M8 — D4-D13 Production-Like Design, Responsive, Chaos

Priorite: P2  
Objectif: transformer le design audit statique en validation production-like.

Perimetre:

- responsive mobile/tablette/borne/caisse/KDS grand ecran;
- visual baseline sans masquer erreurs critiques;
- performance chargement menu;
- network loss/reconnect;
- multi-resolution hardware;
- lisibilite cuisine;
- tap targets kiosk.

Commandes indicatives:

```bash
DESIGN_AUDIT_ITERATIONS=5 npx playwright test tests/e2e/design --project=chromium --retries=0
```

PASS:

- pas de serious axe;
- pas de texte coupe;
- pas de UI bloquante;
- pas de toast critique masque;
- screenshots exploitables archives.

---

## M9 — Consolidation et Hardware UAT Brief

Priorite: finale  
Sortie obligatoire:

- `reports/audit/CODEX_FINAL_REWORK_CONSOLIDATED_PASS_2026-04-28.md`
- `docs/hardware/UAT_COMPOSER_AND_CAISSE_V1_2026-04-28.md`

Contenu:

- matrice C0-C10 et D0-D13: PASS/PARTIAL/REWORK;
- preuves exactes: commandes, nombre de runs, fichiers;
- liste des P0/P1 fermes;
- liste des P2 acceptes post-UAT;
- decision: `PROCEED_TO_HARDWARE_UAT` ou `REWORK_BEFORE_UAT`;
- script UAT humain: kiosk, POS, KDS, OSS, cash-at-counter, card simulated, stock, queue number, photos, geocode, multi-branch.

---

## 4. Premier sprint concret recommande

Ne pas lancer toutes les missions en meme temps. L'ordre le plus intelligent:

1. M0: validation machine complete. Duree cible: 1-2h.
2. Si M0 ne trouve pas de P0 direct: M1 + M2.
3. Puis M4 + M5.
4. Puis M3 + M6.
5. Puis M7.
6. Puis M8/M9.

Definition du premier PASS reel:

- M0 PASS;
- M1 PASS avec multi-surface runtime;
- M2 PASS fiscal/outbox;
- M4 PASS stock/queue stress;
- M5 PASS delivery/pricing SSOT.

Sans ces cinq points, hardware UAT reste en HOLD.

---

## 5. Prompt court pour executer M0

```text
TASK_ID: SUPER-AUDIT-M0-MACHINE-VALIDATION-2026-04-28

Lis plans/PLAN_SUPER_AUDIT_REWORK_ORCHESTRATION_2026-04-28.md puis execute uniquement M0.
Ne modifie pas le produit.
Produis reports/audit/CODEX_M0_MACHINE_VALIDATION_2026-04-28.md.
Classe chaque hypothese Claude en FACT/FALSE/PARTIAL/UNKNOWN avec fichier:ligne.
Utilise rg pour detecter mocks e2e, chaines magiques status, afterCommit/listeners, throttle, delivery fee et branch isolation.
Si tu detectes un P0 reel, stoppe et ecris un sous-plan de correction minimal M0-P0-<slug>.
Respecte les invariants FoodKing: backend pricing SSOT, enums status, branch_id isolation, dispatch after commit, frozen zones.
```

