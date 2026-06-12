# LANE F3 — SYNC L2 & SETUP fidélité — 2026-06-12

## Étape 1 — Chaîne outbox : PASS
- tinker `LoyaltyBalanceChanged::dispatch(1,1,500,10,'earn')` sur foodking_e2e → domain_events row id=9144
- channel=`["private-branch.1"]`, broadcast_as=`LoyaltyBalanceChanged`, aggregate=App\Models\User#1
- payload = {user_id:1, branch_id:1, balance_after:500, delta:10, reason:'earn'} — AUCUN nom/téléphone/loyalty_code (PII-free OK)
- app/Enums/EventType.php:23 `const LOYALTY_BALANCE_CHANGED = 'loyalty.balance_changed'` + présent dans all() (ligne ~73, commentaire heal 2026-06-11) — OK

## Étape 2 — Idempotency outbox : PASS
- dispatch ×2 même (user=7,balance=300,delta=50,reason=redeem,correlation=F3-IDEM-TEST-001) → +1 row seulement (1→2)
- dispatch balance=250 même correlation → +1 row (3 total)
- formule sha1('loyalty.balance_changed|7|300|redeem|F3-IDEM-TEST-001') == idempotency_key row 9145 → vérifiée exactement
- Listener: app/Listeners/PersistLoyaltyBalanceChangedToOutbox.php:30-38 (sha1) + firstOrCreate :39

## Étape 3 — Sites d'écriture vs dispatch (grep)
Dispatch sites (7): LoyaltyController.php:221(welcome) :294(addPoints staff) :416(redeem frontend/kiosk endpoint) · AwardLoyaltyPointsOnDelivery.php:145(earn) · LoyaltyService.php:91(refund) :200(clawback) · PosRedemptionService.php:204(POS redeem)
Écritures users.loyalty_points: LoyaltyController:197/206→disp:221 OK · :273→disp:294 OK · :397→disp:416 OK · AwardLoyaltyPointsOnDelivery:118→disp:145 OK · LoyaltyService:86→disp:91 OK · :186→disp:200 OK · PosRedemptionService:172→disp:204 OK
⚠️ GAP: app/Services/FrontendOrderService.php:899-904 `DB::table('users')->update(['loyalty_points'=>$balanceAfter])` (fallback redeem inline à la création de commande kiosk quand pas de pendingRedeem <10min) — AUCUN LoyaltyBalanceChanged dans tout le fichier (grep=0 hit). Ledger LoyaltyTransaction créé (:906) mais pas d'event → solde live POS stale sur ce chemin. → P1 candidat (reachability en cours)

## Étape 3 (suite) — reachability du GAP FrontendOrderService:899
- applyKioskLoyaltyDiscount appelé à FrontendOrderService.php:485 (myOrderStore, dans la TX)
- gate fiscal `pos.manual_discount_enabled` = TRUE sur le harnais (config/pos.php:172) → chemin atteignable
- MAIS 0 ligne ledger historique 'Reduction fidelite appliquee sur commande kiosk' dans foodking_e2e (clone) → chemin DORMANT en données réelles (le kiosk passe par /loyalty/redeem (dispatch OK :416) + rattachement pendingRedeem <10min). Sévérité retenue: P1 par définition de lane (mutation sans event), dormancy notée.

## Étape 5 — Dégradation frontend : 1 P1 MAJEUR trouvé
OK:
- eventContract.js:349-355 — pas d'Echo OU branchId falsy → retourne {unsubscribe(){}} no-op (pas de subscribe)
- PosLoyaltyRedeemModal.vue:203-204 — `Number(this.branchId)||0; if (branchId<=0) return;` → branchId absent = pas de subscribe ✓
- PosLoyaltyRedeemModal.vue:205-221 — try/catch subscribe → dégradation stale-until-next-lookup ✓
- PosLoyaltyRedeemModal.vue:223-227 — beforeUnmount: _destroyed + unsubscribe (eventContract.js:396-410 retire SEULEMENT son rawHandler, canal partagé préservé) ✓
- eventContract.js:365-388 — rawHandler try/catch parseEvent, envelope invalide → console.warn, handler jamais appelé ✓ ; dedupe corrélation ✓

⚠️ P1 — F3-01 SHAPE MISMATCH: PosLoyaltyRedeemModal.vue:212 lit `Number(event?.balance_after)` mais onEvents (eventContract.js:385) livre handler(parsed) où parsed = {version,type,aggregateId,branchId,...,payload} (parseEvent eventContract.js:62-77) → balance_after est à event.payload.balance_after. Convention confirmée par consommateur frère PosOrdersTrackerComponent.vue:705 (`event?.payload || {}`). Envelope wire EXACTE vérifiée via tinker buildEnvelope(DomainEvent#9144) : balance_after NESTED sous payload. Repro: node F3-repro-payload-shape.cjs →
  A) REAL wire event through real contract  -> customerBalance = 490 (STALE — event silently ignored)
  B) FLAT object as fed by the vitest mock  -> customerBalance = 500 (spec passes on the wrong shape)
  C) Reading event.payload.balance_after    -> customerBalance = 500
=> Le solde-live L2 du modal POS est un NO-OP de bout en bout (le garde Number.isFinite avale chaque event RÉEL). Le spec tests/js/posLoyaltyLiveBalance.spec.js:42-54 MOCK onEvents et nourrit un objet plat → vert sur le mauvais contrat.

⚠️ P2 — F3-02 PAS DE FILTRE user_id: le handler (PosLoyaltyRedeemModal.vue:209-216) écrase customerBalance pour N'IMPORTE QUEL LoyaltyBalanceChanged de la branche (payload contient user_id mais jamais comparé; le modal ne connaît d'ailleurs pas le user_id du client lookupé — la réponse redeem POS ne l'expose pas). Client A affiché ← solde du client B (kiosk earn simultané). Display-only (serveur revalide), même branche. (Latent tant que F3-01 rend le handler inopérant.)

## Étape 4 — Setup round-trip /admin/settings/loyalty-setup : PASS (avec notes)
- ⚠️ baseline observée = 10 pt/€ (PAS 1) au démarrage de la lane — contamination cross-lane probable (F4-setup-rates.png horodaté 02:34 par une lane parallèle) ; mandat owner = 1.
- UI 10→2 : PUT /api/admin/setting/loyalty-setup 200 {"loyalty_points_per_euro":2}, toast FR « Fidélité : mise à jour réussie. », aperçu recalculé « 10€ d'achat = 20 pts → 0.20€ » (F3-setup-before.png / F3-setup-after-2.png)
- tinker: per_euro=2 ✓ ; GET /api/frontend/loyalty/config (x-api-key requis) → points_per_euro=2 ✓
- fresh load UI = 2 ✓ (round-trip serveur→UI)
- RESTORE 1 : (re-run via curl après 401 mid-script — token tué par relogin d'une lane parallèle, artefact harnais pas bug produit) → tinker per_euro_final=1 ✓, config API points_per_euro=1 ✓, UI fresh = 1 ✓ (F3-setup-restored-1.png)
- Validation:
  · -1 → AUCUN XHR : input HTML min="0" → bulle native checkValidity=false message EN « Value must be greater than or equal to 0. » (locale navigateur, pas FR contrôlé) (F3-setup-validation-minus1.png) ; côté serveur (curl direct) 422 « La valeur de loyalty points per euro doit être supérieure ou égale à 0. »
  · texte → input number rejette les lettres ; équivalent champ vide → 422 « Le champ loyalty points per euro est obligatoire. » affiché inline FR (F3-setup-validation-empty.png)
  · 'abc' via API directe → 422 « Le champ loyalty points per euro doit être un entier. »
  · 0 → ACCEPTÉ (rule min:0, LoyaltySetupRequest.php:17) — désactive l'accrual, by-design
  · 1001 → 422 « ne doit pas être supérieure à 1000. »
  → messages FR mais nom d'attribut brut EN « loyalty points per euro » (pas de traduction attribute lang/fr) = P3

## Étape 4bis — Propagation temps-réel du barème : GAP P2
- app/Services/LoyaltySetupService.php:32 `Settings::group('loyalty_setup')->set(...)` — AUCUN SettingsUpdated::dispatch, contrairement au pattern CurrencyController:38 / CompanyController:36 / OrderSetupController:37 / TaxController:39 / SiteController:40
- Empirique: 0 row domain_events settings.updated dans l'heure malgré 6 saves loyalty-setup (2 UI + 4 curl)
- Conséquence: borne/POS qui ont chargé /api/frontend/loyalty/config gardent l'ancien barème jusqu'à reload (pas de push private-branch.*)

## Étape 6 — Re-run tests : PASS
- ./vendor/bin/phpunit --filter "LoyaltyBalanceChangedOutbox|EventContract" → OK (25 tests, 81 assertions)
- npx vitest run tests/js/posLoyaltyLiveBalance.spec.js → 5/5 passed — MAIS vert-sur-mauvais-contrat (onEvents mocké, payload plat) cf. F3-01

## VERDICT LANE: 2 P1 / 2 P2 / 1 P3 — sync L2 outbox côté serveur SOLIDE (PII-free, idempotent, EventType OK), consommateur POS MORT (shape mismatch), barème admin OK en CRUD mais sans propagation live.
