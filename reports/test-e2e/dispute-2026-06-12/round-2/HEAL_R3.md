# HEAL R3 — derniers 1 P0 + 5 P1 (dispute-2026-06-12, post Round 2)

— Healer R3 (seul éditeur), worktree `release-v1-2026-06-10`, app :8768 (foodking_e2e jetable, PID 38797).
— Périmètre : C-RED-02-R2 (P0) · C-R2-NEW-1 · C-R2-NEW-2 · B-R1-06 · B-R1-07 · B-R1-16. 0 frozen.
— ÉCRITURE INCRÉMENTALE (leçon des rounds précédents).

## Fix 1 — [P0] C-RED-02-R2 : rachat fidélité borne droppé pour les clients status=5 — ✅ HEALED + LIVE-PROVEN

- **Commit** : `0f22d2cc9`
- **Root cause confirmée** : lookup `->where('status', 1)` ×2 (`OrderQuoteService.php:272`, `FrontendOrderService.php:936`)
  ré-introduit contre le précédent documenté `LoyaltyController.php:100-106` (`isCustomerActive`, 2026-06-06).
  `Status::ACTIVE=5` = population réelle (seed + créés caisse).
- **Fix** : scope canonique **`User::loyaltyActive()`** (nouveau, `app/Models/User.php` — `whereIn('status', [1, Status::ACTIVE])`,
  doc-bloc pointant le précédent) appliqué aux 2 sites. Parité avec `LoyaltyController::isCustomerActive` et
  `PosRedemptionService` (ces 2 sites historiques NON touchés — déjà corrects et testés).
- **Toast au motif FAUX** : le motif « points insuffisants au moment du paiement » était **INVENTÉ côté i18n** —
  le backend n'envoie qu'un booléen `loyalty_applied` (OrderController:54). Copy neutralisée
  (`kiosk.pay_screen.loyalty_not_applied_toast`, fr/en + miroirs de/bn/ar) : plus aucune cause inventée,
  ajout « présentez votre carte en caisse ».
- **TDD** : `test_kiosk_redemption_works_for_status_active_5_customer` (RED avant fix — quote discount=0 ;
  GREEN après). Factory helper `makeLoyaltyCustomer` paramétrée `$status` (piège des factories status=1 documenté).
- **Tests** : KioskLoyaltyBillingTest 6/6 · `--filter=Loyalty` **85/85** · `--filter=Quote` **47/47**.
- **REPRO LIVE borne :8768** (backend live sans rebuild — le wire-up frontend `956933ec5` était déjà dans le bundle) :
  - Flux complet : idle → Desserts → Tiramisu 3,80 € → panier → fidélité `VICT1234` (user 44, **status=5**, 165 pts)
    → « 165 points = 1,65 € » → Utiliser mes points → payment **« Total à régler : 2,15 € »** → confirm →
    cash-instruction **#A0025 / 2,15 €**.
  - **DB** : order **4541** subtotal 3.80 / **discount 1.65 / total 2.15** (écran=API=DB au centime) ;
    user 44 points **165 → 0** ; ledger `loyalty_transactions` #14 redeem **−165** order 4541. ❌→✅ vs R2 (4537 : discount 0, points intacts).
  - Captures : `heal-r3-proofs/r3-fix1-payment-2.15.png`, `r3-fix1-cash-instruction-2.15.png`.
- **Note orchestrateur** : la copy du toast est dans les bundles → **rebuild central requis** pour la partie toast
  (la facturation, elle, est backend = déjà live).

## Fix 2 — [P1] C-R2-NEW-1 : upsell borne MORT (pool=0) — ✅ HEALED + LIVE-PROVEN

- **Commit** : `04a98d7f7`
- **Compréhension du pool** (`ItemController::kioskUpsell`, :60-108) : priorité 1 = `is_upsell=Ask::YES(5)` +
  catégorie `kiosk_upsell_include=true` + status ACTIVE ; priorité 2 fallback = `is_featured=YES` même règle.
  Le seeder R1 a défeaturé les 3 seuls featured vivants → pool=0 (re-vérifié SQL : tous les `is_featured=5`
  restants étaient soft-deleted).
- **Fix DATA via le MÊME seeder** (idempotent, phase 2) : `UPSELL_POOL_SLUGS = ['coca','tiramisu','glace']`
  (vrais items vendables — choix B « vrais boisson/dessert » de la consigne, car les véhicules sont des SKU
  image-cassée ; ils restent HORS grille ET HORS pool, garde `array_diff` + asserts). Choix d'items =
  **DATA ajustable owner** (gate #9 META §5.B).
- **TDD** : `test_seeder_revives_kiosk_upsell_pool_with_real_sellable_items` (RED→GREEN — flags, endpoint
  non-vide, véhicules jamais servis, idempotence préservée).
- **Tests** : HideUpsellVehicleItemsFromGridSeederTest **3/3** · KioskUpsellCategoryTest 1/1 · UpsellApiTest 1/1.
- **Seeder exécuté** : `APP_ENV=e2e php artisan db:seed --class=...HideUpsellVehicleItemsFromGridSeeder`
  → « 3 row(s) flagged is_upsell=YES » ; DB foodking_e2e : items 49/51/52 is_upsell=5.
- **LIVE :8768** : panier (Tarte Daim 3,80) → « Valider ma commande » → **/kiosk/upsell REND** « Et pour
  terminer ? » avec 3 suggestions (Tiramisu 3,80 / Coca-Cola 33cl 1,50 / Glace 3,80) + countdown ;
  `GET /api/frontend/item/kiosk-upsell?item_ids=50&limit=6` → **200 non-vide**. Capture
  `heal-r3-proofs/r3-fix2-upsell-alive.png`. Aucun rebuild requis (DATA + backend only).
- **Note orchestrateur** : **rejouer ce seeder sur la DB d'exploitation** (foodking) au déploiement —
  même classe, idempotent.

## Fix 3 — [P1] C-R2-NEW-2 : rupture en session « Article 34 indisponible » cul-de-sac — ✅ HEALED (backend LIVE-PROVEN, marquage = rebuild)

- **Commit** : `fc6a49ba6`
- **Backend** : nouvelle `App\Exceptions\UnavailableItemException` (extends `\InvalidArgumentException` —
  comportement identique pour tous les call-sites historiques, y compris PricingService frozen qui n'est PAS
  touché) ; `AvailabilityService::assertItemsOrderableForBranch` jette désormais des messages avec le **NOM**
  (« « Grande Frites » est indisponible pour le moment. Retirez cet article du panier pour continuer. ») sur
  les 4 variantes (introuvable / inactif / catalogue / branche — substrings historiques `introuvable`/`inactif`/
  `indisponible pour cette branche` conservés pour les pins existants). 422 **structurée**
  `{ code: ITEM_UNAVAILABLE, item_id, item_name }` sur les 2 chemins (quote `PosController` + order
  `FrontendOrderController` ; re-throw typé dans `FrontendOrderService` pour passer le wrap générique).
- **Borne** : `MARK_LINE_UNAVAILABLE` (store kioskCart — flag local, jamais envoyé à l'API/sanitize allowlist) ;
  `KioskCartComponent` : ligne marquée = bord rouge + nom barré + badge « Indisponible » + CTA
  « Retirer cet article » (≥48px) ; copy FR avec le nom ; **re-checkout bloqué** tant qu'une ligne marquée
  reste (fin de la boucle 422).
- **TDD** : PHPUnit `KioskRuptureCheckoutMessageTest` 3/3 (RED→GREEN) ; Vitest `kioskCartRuptureMarking` 16/16
  (RED→GREEN — mutation, handler 422 structuré, fallback sans item_name, blocage re-checkout, 422 générique
  inchangé, clefs i18n fr+en).
- **Régression** : ComposerStepConstraintTest 13/13 (1 pin `inactif` re-satisfait par la copy) ·
  FrontendOrderServiceTest · `--filter=Availability` 125/125 + 1 skip · 6 specs Vitest kioskCart voisins 43/43.
- **LIVE :8768** (vieux bundle = message backend rendu verbatim) : Grande Frites ajoutée au panier →
  `UPDATE items SET is_available=0 WHERE id=34` (rupture mid-session, la grille avait bien désactivé la tuile
  pour les NOUVEAUX ajouts — c'est la ligne déjà au panier qui est le trou C30) → « Valider » →
  toast+inline « **« Grande Frites » est indisponible pour le moment. Retirez cet article du panier pour
  continuer.** », **0 occurrence « Article 34 »**. Capture `r3-fix3-rupture-named-message.png`.
  Item ré-activé post-test (DB jetable propre).
- **Note orchestrateur** : badge/CTA/blocage = bundles → **rebuild central requis** pour la partie marquage ;
  le message nommé est déjà live.

## Fix 4 — [P1] B-R1-06 : copy miroir NF525 mensongère pre-Z — ✅ HEALED (probe LIVE-PROVEN, copy = rebuild)

- **Commit** : `c1a986191`
- **Fix** : « copy conditionnelle `mode` » exigeait de connaître le mode AVANT confirmation — le prédicat
  « sealed? » est serveur-only (SealedOrderGuard SSOT). Ajout d'un **probe read-only**
  `GET /admin/pos-order/{order}/refund-mode` (gates identiques au POST : `pos-refund` + cross-branch ; zéro
  write) → `{ mode: pre_z | counter_entry }`. `PosRefundModal` sonde à l'ouverture (best-effort, anti-stale) :
  - `warning_pre_z` : marquée remboursée + sortie de caisse + journalisée NF525, **« Aucun ticket miroir »** ;
  - `warning_post_z` : copy miroir historique (exacte pour la voie sealed) ;
  - fallback générique honnête décrivant les **2** voies si la probe échoue (jamais l'ancien mensonge).
  Toast succès conditionnel au `mode` renvoyé par le POST. `data-refund-mode` exposé pour les probes E2E.
- **TDD** : PHPUnit `RefundModeProbeTest` 3/3 (RED→GREEN — pre_z / counter_entry / 403) ; Vitest
  `posRefundModalModeCopy` 14/14 (RED→GREEN) ; sentinel `posRefundModalSentinel` 22/22 **intact**.
- **Régression** : `tests/Feature/Refund/` **22/22** (dont PreZRefundViaEndpointTest 5/5).
- **LIVE :8768** (BM `bm.t2admin@lecayenne.fr`) : order **4541** (journée en cours) → `{mode:"pre_z"}` ;
  order **4226** (07/06, scellé par Z fermé) → `{mode:"counter_entry"}`.
- **Note orchestrateur** : copy modal = **rebuild requis** ; le probe backend est déjà live.

## Fix 5 — [P1] B-R1-07 : cash-overview aveugle aux refunds — ✅ HEALED + LIVE-PROVEN (UI = rebuild)

- **Commit** : `6e5b25764`
- **Voie comptable retenue** : **disclosure, pas de netting inventé** — les cartes restent des encaissements
  BRUTS mais (a) le GRAND TOTAL porte l'étiquette « Encaissements bruts — hors remboursements » (toujours
  visible), (b) un bloc « Remboursements de la période : −X € (n) — non déduits des totaux ci-dessus » rend
  les cash_back de la **même fenêtre date+branche** que le summary (insensible aux filtres source/mode UI,
  même sémantique de périmètre que cash_session). C'est la lecture la plus juste : netter silencieusement
  aurait créé une 3e réalité vs le grand livre et les Z.
- **Backend** : bloc `refunds` { count, total brut |amount|, currency } — isolation branche BM testée.
- **TDD** : PHPUnit `test_refunds_block_surfaces_cash_back_of_the_window` (RED→GREEN — fenêtre, isolation
  branche, totaux bruts inchangés à 120,00) ; Vitest `cashOverviewRefundsDisclosure` 11/11 (RED→GREEN).
- **Régression** : `CashOverviewControllerTest` **20/20**.
- **LIVE :8768** (BM) : `GET /admin/cash-overview?from=2026-06-12&to=2026-06-12` →
  `refunds: { count: 3, total: 13.40 }` = **exactement** le montant invisible du R2 (−13,40 €) ;
  DB concorde (3 cash_back, 13.40).
- **Note orchestrateur** : UI = **rebuild requis** ; le payload backend est déjà live.

## Fix 6 — [P1] B-R1-16 : widget « Voir les clôtures Z » → Transactions — ✅ HEALED (rebuild requis)

- **Commit** : `0163c33af`
- **Arbitrage B-R1-16 × B-R1-18** (demandé par META §1) : « vérifie que la page Z existe » → elle
  N'EXISTAIT PAS (grep router = 0 route Z, B-R1-18). Re-cibler vers une autre page existante aurait déplacé
  le mensonge. Décision : création d'une **page MINIMALE lecture-seule** `/admin/z-reports`
  (`ZReportListComponent`) consommant l'**API existante** `GET /admin/fiscal/z-report` (POS-9.4.9, permission
  backend `pos-manage-fiscal`, branch-scoped, 403 → état indisponible propre) : N° de clôture, statut FR
  (whitelist no-raw-label), ouverture/clôture fr-FR, total TTC, commandes. **PDF/téléchargement/archivage =
  toujours le périmètre produit B-R1-18 (gate owner)** — cette page ne le préempte pas, elle rend juste le
  lien du widget honnête. Widget re-ciblé `admin.zReports.list` ; router `zReportRoutes`
  (permissionUrl `transactions`, aligné sur le gate frontend du widget).
- **TDD** : Vitest `zReportListPage` 17/17 (RED→GREEN — cible widget (plus jamais transactions), route
  enregistrée, rendu lignes + empty state, clefs fr/en).
- **NF525** : page READ-ONLY, zéro write, zéro interaction fiscale.
- **Note orchestrateur** : **rebuild requis** (nouveau chunk `admin-reports` + widget + router).

---

# SYNTHÈSE R3

| # | Finding | Sév | Verdict | Commit | Tests | Live |
|---|---|---|---|---|---|
| 1 | C-RED-02-R2 fidélité status=5 | **P0** | ✅ HEALED | `0f22d2cc9` | PHPUnit 6/6 + Loyalty 85/85 + Quote 47/47 | ✅ order 4541 discount 1,65/total 2,15, points 165→0, ledger −165 |
| 2 | C-R2-NEW-1 upsell mort | P1 | ✅ HEALED | `04a98d7f7` | Seeder 3/3 + voisins 2/2 | ✅ écran upsell REND 3 suggestions, API 200 non-vide |
| 3 | C-R2-NEW-2 rupture « Article 34 » | P1 | ✅ HEALED | `fc6a49ba6` | PHPUnit 3/3 + Vitest 16/16 + voisins 43/43 | ✅ message nommé live (marquage = rebuild) |
| 4 | B-R1-06 copy miroir pre-Z | P1 | ✅ HEALED | `c1a986191` | PHPUnit 3/3 + Refund 22/22 + Vitest 14/14 + sentinel 22/22 | ✅ probe live pre_z/counter_entry (copy = rebuild) |
| 5 | B-R1-07 overview aveugle refunds | P1 | ✅ HEALED | `6e5b25764` | PHPUnit 20/20 + Vitest 11/11 | ✅ payload live {3, 13.40} (UI = rebuild) |
| 6 | B-R1-16 widget Z → transactions | P1 | ✅ HEALED | `0163c33af` | Vitest 17/17 | rebuild requis (page neuve) |

**Skipped : aucun.** 6/6 fixes livrés, 0 frozen touché (tripwire diff = 0 ligne à chaque commit).

## État des suites
- **PHPUnit ciblé** : tous les filtres touchés verts (Loyalty 85, Quote 47, Kiosk dir 40, Availability 125+1skip,
  ComposerStepConstraint 13, Refund 22, CashOverview 20). `.env.testing` vérifié → `foodking_test` (DEVDB-GUARD ok).
- **Vitest complet `tests/js`** : **2382/2387 passed** — 2 fails = sentinels **bundle-freshness ATTENDUS**
  (sources i18n/composants plus récents que `public/js/*.js`, rebuild central à venir, cf. consigne « PAS npm
  build ») ; 3 skips historiques ; 9 unhandled = stray `setTimeout` du `KioskWizardComponent` frozen en run
  parallèle (cross-file teardown jsdom), **pré-existant** — diff frozen 0 ligne, specs isolées vertes.

## Notes orchestrateur (actions centrales requises)
1. **REBUILD bundles obligatoire** (`npm run production`) — porte : toast fidélité neutre (F1), marquage ligne
   rupture + CTA (F3), copy modal refund conditionnelle (F4), bloc refunds overview (F5), page Z + widget (F6).
   Les 2 sentinels bundle-freshness repasseront verts au rebuild.
2. **Seeder à rejouer sur la DB d'exploitation** au déploiement :
   `php artisan db:seed --class=Database\\Seeders\\HideUpsellVehicleItemsFromGridSeeder` (idempotent ; déjà
   exécuté sur foodking_e2e). Choix des 3 items du pool (coca/tiramisu/glace) = DATA ajustable owner.
3. **Round 3 focalisé** : le rachat fidélité status=5 est prouvable live IMMÉDIATEMENT (backend) ; les vérifs
   visuelles des fixes F3/F4/F5/F6 doivent attendre le rebuild.
4. DB jetable foodking_e2e : mutations documentées = order 4541 créé (fidélité), item 34 toggled 0→1 (restauré),
  `is_upsell=5` sur items 49/51/52 (voulu, seeder), points user 44 → 0 (débit réel du test).

## Preuves (captures `heal-r3-proofs/`)
- `r3-fix1-payment-2.15.png` — payment borne « Total à régler : 2,15 € » (3,80 − 1,65 fidélité status=5)
- `r3-fix1-cash-instruction-2.15.png` — cash-instruction #A0025 / 2,15 €
- `r3-fix2-upsell-alive.png` — écran upsell vivant (Tiramisu/Coca/Glace)
- `r3-fix3-rupture-named-message.png` — toast/inline « « Grande Frites » est indisponible pour le moment… »

— Fin HEAL_R3 — healer R3, 2026-06-12.

