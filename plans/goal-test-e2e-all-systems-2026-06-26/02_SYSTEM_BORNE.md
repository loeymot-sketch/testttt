# 02 — SYSTÈME BORNE (kiosk) — plan test-e2e abusif

**Contract** : borne libre-service client. Lentille dominante = 🧑 **CLIENT**
(« est-ce bien pensé pour lui ? »). Vague **séquentielle** (publie vers KDS,
partage `FrontendOrderService`/pricing). Locale **FR stricte** (0 anglais),
paiement = **Plan B** (routé encaissement comptoir). Palette Cayenne light mode.

**Frozen (auditable + gate)** : `KioskWizardComponent.vue`, `KioskAppComponent.vue`,
`KioskUpsellComponent.vue`. **Shared** : `PricingService`, `FrontendOrderService`,
`composition_snapshot` figé, bus sync (publie `OrderCreated` → KDS).

**Anchors (vérifiés)** : services `app/Services/Kiosk/**` (`KioskMenuService`,
`PricingPreviewService`, `KioskPromoService`, `UpsellRuleService`) ;
`KioskMachineLoginController` ; front `resources/js/components/frontend/kiosk/**`
(48 .vue) ; `store/modules/kioskCart.js`, `helpers/kioskOfflineQueue.js` ;
`router/modules/kioskRoutes.js` ; `config/kiosk.php` ; tests `tests/Feature/Kiosk*`
(62) + `tests/js/kiosk*.spec.js` (84).

---

## INVENTAIRE PAGES (parent `/kiosk`, guard `requireKioskAuth`)

| Route | Composant `frontend/kiosk/` | Rôle |
|---|---|---|
| `/kiosk/login` | `KioskLoginComponent.vue` | auto-login machine + retry/diagnostic |
| `/kiosk/idle` | `KioskIdleScreenComponent.vue` | attract + **choix mode** Sur place/À emporter (`selectOrderTypeAndStart:257`) |
| `/kiosk/categories` | `KioskCategoriesComponent.vue` | sidebar catégories + produits (`?cat=`) |
| `/kiosk/products/:categoryId` | redirect→categories | legacy deep-link |
| `/kiosk/wizard/:itemId` | `KioskWizardComponent.vue` **FROZEN** (ou `KioskPosWizardComponent` si flag) | composition par catégorie |
| `/kiosk/cart` | `KioskCartComponent.vue` | panier : qty, edit, clear, re-choix order-type, checkout |
| `/kiosk/loyalty` | `KioskLoyaltyComponent.vue` | opt-in/scan fidélité |
| `/kiosk/upsell` | `KioskUpsellComponent.vue` **FROZEN** | suggestions + auto-skip timer (`:92`) |
| `/kiosk/payment` | `KioskPaymentComponent.vue` | **Plan-B counter-route** (auto-submit cash) |
| `/kiosk/cash-instruction` | `KioskCashInstructionComponent.vue` | « Payez à la caisse » + n° file, auto-redirect idle 45s |
| `/kiosk/waiting/:orderId` | `KioskWaitingComponent.vue` | poll/Echo statut, auto-reset ready |
| `/kiosk/confirmation` | `KioskConfirmationComponent.vue` | n°+total, impression ticket, countdown retour |
| `/kiosk/error/{network,menu-unavailable,product-removed,payment-refused}` | `KioskError*Component.vue` | écrans erreur dédiés |
| transverses | `KioskInactivityOverlayComponent`, `KioskPromoCarouselComponent`, `KioskToastComponent`, `KioskOfflineConflictModalComponent` | overlay « toujours là ? », promo, toast, conflit offline |

---

## DÉCOMPOSITION (4 sous-systèmes)

### Sub 2.a — Attract + catalogue + navigation
- T-2.a.1 Audit idle/order-type (`selectOrderTypeAndStart:257`) — client bloqué s'il ne choisit pas ?
- T-2.a.2 Audit `KioskMenuService::build` (`:61`) + `GET /api/frontend/menu` — **catégorie vide = cul-de-sac** (pas de `whereHas('items')`, cf. §défauts).
- T-2.a.3 Audit sidebar/produits/filtres — client perdu trouve-t-il son produit ? produit sous le pli.
- T-2.a.4 Audit sélecteur langue placebo (`changeLanguage:266` no-op) — FR-lock.
**Acceptance** : `KioskFrontendComprehensiveTest`, `Menu/PosKioskProjectionParityTest` PASS + JS `kioskFrLockImmutable` + *(À CRÉER `tests/Feature/Kiosk/KioskEmptyCategoryHiddenTest.php`)* + captures idle/categories vertes.

### Sub 2.b — Wizard composition + upsell
- T-2.b.1 Audit templates wizard + `viande_count` SSOT (`KioskMenuService:311-323`) vs heuristique nom.
- T-2.b.2 Audit `PricingPreviewService::preview` (`:52`) — total affiché == backend (anti fantôme-upcharge).
- T-2.b.3 Audit `UpsellRuleService::suggest` (`:31`) + auto-skip timer — intrusif ? lisible ?
- T-2.b.4 Audit suppléments payants (+0,90) — surcoût évident au client avant panier ?
**Acceptance** : `KioskUpsellCategoryTest`, `PosKioskPricingParityTest` PASS + *(À CRÉER `tests/js/kioskWizardPriceParityVsQuote.spec.js`)*.

### Sub 2.c — Panier + loyalty + paiement Plan-B
- T-2.c.1 Audit panier `kioskCart.js` (quote→submit) — total local vs backend (`cartTotal:328`).
- T-2.c.2 Audit `KioskPromoService::validate` (`:36`) + loyalty opt-in/scan.
- T-2.c.3 Audit Plan-B submit + `PENDING_COUNTER` — le client comprend-il qu'il doit payer à la caisse ?
- T-2.c.4 Audit double-tap confirm (`confirmPayment` garde `submitting:431` + idempotency).
**Acceptance** : `KioskQuoteIntegrityTest`, `KioskQuoteTokenRequiredOnCommitTest`, `PosCollectKioskCashRouteTest`, `KioskLoyaltyLedgerAtomicTest`, `KioskLoyaltyDoubleRedeemRefusedTest`, `Kiosk/KioskPaymentConfirmAmountTest` PASS + *(À CRÉER `tests/js/kioskCartDoubleTapDedup.spec.js`)*.

### Sub 2.d — Résilience / offline / erreurs / sync
- T-2.d.1 Audit `kioskOfflineQueue.js` (queue v2, lock, replay, race snapshot+merge).
- T-2.d.2 Audit inactivity overlay (vide panier au timeout, backdrop/Esc=Stay) + abandon.
- T-2.d.3 Audit 5 écrans erreur + reconcile TPE (`_reconcilePendingPayments:920`) + retour-accueil bundle périmé.
**Acceptance** : `KioskOfflinePaymentScopeTest`, `KioskRealtimeBroadcastTest`, `Sync/FinalizePaidKioskOrderBroadcastFreshnessTest` PASS + JS `kioskOfflineQueue*` (×4) + *(À CRÉER `tests/js/kioskWizardAbandonResets.spec.js`)*.

---

## GERMES ADVERSAIRES (🧑 CLIENT — cœur)
- **Catalogue** : catégorie visible→0 produit→écran vide muet (jumeau POS corrigé, borne PAS garantie) ; pas de recherche/populaires ; produit sous le pli invisible ; order-type forcé idle ; drapeau langue inerte = confusion non-francophone.
- **Wizard/upsell** : **prix wizard ≠ payé** (fantôme-upcharge `KioskWizardComponent.vue:198/1922`, résiduel +2,50 viande FROZEN) ; upsell timer trop court/intrusif ; suppléments +0,90 non-évidents ; aliases template cassables au rename admin.
- **Panier/paiement** : abandon mid-panier (inactivity détruit sans reconfirmer au timeout) ; Plan-B « payez à la caisse » — le client croit avoir payé et part (mitigé TTL 180 min) ; double-tap confirmer ; total local ≠ payé.
- **Résilience** : retour-accueil = bundle périmé (défaut récurrent) ; offline cash `queue_number:'—'` incompris ; TPE figé (timeout) → 2 refus → écran dédié ; soketi down → poll → 3 échecs erreur.

---

## PIÈGES & DÉFAUTS CONNUS (file:line)
1. **Catégorie vide** : `KioskMenuService.php:66-74` sans `whereHas('items')` (grep vide) — trap LIVE non couvert.
2. **Fantôme-upcharge** : `KioskWizardComponent.vue:198` + `:1922-1947` (leak menu-formule corrigé) ; **résiduel +2,50 viande FROZEN** (BRAIN) → divergence preview/backend.
3. **fr-only i18n parity** : sentinels `studioFrontendI18nParity.spec.js` + `kioskFrLockImmutable.spec.js` ; `changeLanguage` inerte (`:266`).
4. **Cat Suppléments masquée** : `KioskCategoriesComponent.vue:599-603` (fallback ID 318) — fragile au renumber.
5. **Auth creds gate** : `config/kiosk.php` injecte `spa_payload` seulement si local OU IP trusted (sinon fuite, heal PR-B) ; token `['kiosk:order']` TTL 480 min, anciens révoqués.
6. **viande_count** : `KioskMenuService.php:311-323` lu en priorité par le wizard FROZEN ; fallback nom buggé (Méga→4).
