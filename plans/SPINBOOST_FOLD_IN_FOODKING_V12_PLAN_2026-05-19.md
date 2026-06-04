# SpinBoost Fold-In FoodKing V1.2 Review Boost Add-on — Plan

> **Type** : Plan document (Option C strategy, decision acted 2026-05-19)
> **Status** : DRAFT — V1.2 backlog candidate, owner-gated before any Sprint 0 work
> **Author** : Wave E master sub-agent (WE-3), GStack + Superpowers + RED discipline
> **Date** : 2026-05-19
> **Branch context** : `v1-0-1-hardening-2026-05-17`, HEAD `ce23352ab`
> **Scope** : design-only — NO IMPLEMENTATION in this plan
> **Mandate** : `feedback_no_cloud_until_owner_initiates` (2026-05-18) respected — fold-in stays inside Laravel/Vue monolith, no separate SaaS cloud until owner says go
> **Reference docs** : `DESIGN_BRIEF_SPINBOOST_2026-05-16.md`, `ULTRA_PLAN_SPINBOOST_DECOMPOSED_2026-05-16.md`, `ULTRA_REVIEW_SPINBOOST_2026-05-16.md`, `reports/audit/spinboost-2026-05-19/STATUS.md`

---

## §0. TL;DR (lecture 60 secondes)

- Owner décide 2026-05-19 : **Option C — fold-in FoodKing V1.2** (vs Option A standalone Next.js).
- Rationale validée par Wave D audit + ce plan : ~30-40 % code reuse (NF525 audit chain + BranchScope + idempotency middleware + Loyalty redeem + PricingService DiscountCalculator + Sanctum), 0 CAC (distribution gratuite aux restaurants FoodKing), risque Google policy contenu en module pas en menace existentielle SaaS, pas de seconde stack à maintenir solo.
- **Variante recommandée : C-1 (découplée, conforme)** — spin trigger via post-meal email opt-in, CTA avis Google secondaire non-récompensé. C-2 (spin gated par avis vérifié Place API) = pattern incentivized P0-1 ULTRA_REVIEW = NON-RECOMMANDÉ sauf override owner explicite (risque pénal + suspension Google).
- 6 sprints + Sprint 0 documentation. Total estimé : **9-13 jours-humain effectifs + 1-2 j juridique**, échelonnés sur ~4-6 semaines calendaires, démarrage conditionné à V1.0.2 stabilisé.
- 12 P0 SpinBoost ULTRA_REVIEW + Wave D : ~5 mitigés par infra FoodKing existante, 5 restent applicables, 2 reformulés (cf. §6). 24 P1 → re-séquencés dans sprints.
- 0 frozen-zone touch prévu. 0 NF525 chain bit modifié (issuance voucher dans nouveau domaine, append au chain existant en mode read+sign).

---

## §1. Owner decision recap + rationale

### 1.1 Décision actée

Le 2026-05-19, owner décide **Option C — Fold-in FoodKing V1.2 Review Boost Add-on** vs Option A (standalone Next.js MVP 6-8 sem solo).

### 1.2 Pourquoi Option C bat Option A (synthèse contradictoire)

Source : ULTRA_REVIEW §5, Wave D STATUS.md §4, présente analyse.

| Critère | Option A (standalone) | Option C (fold-in) | Avantage |
|---|---|---|---|
| Time-to-MVP | 6-8 sem solo Next.js | 4-6 sem dans Laravel/Vue stack connue | C |
| Cash burn | ~10-12 k€ (SAS+juridique+infra+pentest) | ~1-2 k€ (juridique add-on + pentest delta) | C |
| Stack discipline | 2e codebase à maintenir parallèle | 1 monolithe, patterns connus | C |
| CAC | Variable (warm leads 5+ obligatoire pré-Sprint 0) | 0 (distribution aux restos FoodKing) | C |
| TAM | Tout resto FR (large) | Restos FoodKing customers only (limité 1 actuellement Le Cayenne, ~10-20 mid-2027 cible) | A |
| Risque Google policy | Menace existentielle SaaS (suspension = mort produit) | Module contenu (suspension = un add-on en pause, FoodKing core intact) | C |
| Infra réutilisable | 0 % greenfield | ~30-40 % (NF525 chain + BranchScope + idempotency + PricingService + Loyalty redeem + Sanctum) | C |
| Apprentissage solo | Next.js 15 + Auth.js v5 + Stripe SaaS billing + Supabase RLS = nouvelle stack profonde | Réutilisation pattern Laravel/Vue déjà maîtrisé | C |
| Hardening posture owner | Distrayant pendant V1.0.2 | Aligné — un sprint à la fois, owner reste sur Le Cayenne | C |
| Pivot reversibility | Difficile (codebase Next.js distinct, juridique SAS) | Facile (module désactivable par feature flag) | C |

**Verdict** : 8/9 critères favorables Option C. Le seul désavantage (TAM limité aux clients FoodKing) est acceptable car la stratégie SaaS B2B FR de SpinBoost standalone n'a jamais été validée (memory `feedback_v1_focus_no_saas_2026-05-08` : "stratégique 24 mois en pause"). FoodKing acquiert 10 restos avant 2027 = SpinBoost touche 10 restos sans CAC.

### 1.3 Alignement stratégique avec posture owner

- **FoodKing V1.0.1 hardening complete** (commit `ce23352ab` branche `v1-0-1-hardening-2026-05-17`). V1.0.2 en cours (POS Loyalty Redeem UI livré, cash session livreur backlog).
- **Memory `feedback_no_cloud_until_owner_initiates` (2026-05-18)** : owner archivé cloud/AWS/VPS/Phase D comme "vision avant production". Fold-in Option C respecte 100 % cette directive — pas de cloud séparé, tout dans le monolithe Laravel actuel hébergé comme FoodKing.
- **Memory `feedback_v1_focus_no_saas_2026-05-08`** : owner recadré audit sur V1 fast-food Le Cayenne. Module Review Boost reste 1 sprint à la fois, owner gate avant chaque sprint, peut être différé à V1.3 si V1.0.2/V1.1 nécessitent attention.
- **V1.0.X backlog** (memory `project_v1_0_1_hardening_2026-05-17`) : 4 items deferred V1.0.2 (POS Loyalty Redeem UI Option B done, cash session livreur, ...). V1.2 Review Boost s'insère **après** V1.1 (Google MyBusiness OAuth re-évaluée + multi-user) — pas avant.

### 1.4 Conditions de démarrage Sprint 0 (gates pré-coding)

Avant de toucher 1 ligne de code Review Boost, owner doit valider 5 cases (équivalent ULTRA_PLAN §0) :

- [ ] **V1.0.2 stabilisé** (POS cash session livreur shipped, smoke tests verts 7 j consécutifs).
- [ ] **V1.1 roadmap clarifiée** (Google MyBusiness OAuth re-évaluée OU explicitement déferrée V1.3). **Hard stop trigger (RED-FOLDIN-03)** : si V1.1 dérape > 4 sem au-delà roadmap, V1.2 automatiquement différé V1.3.
- [ ] **Variante C-1 vs C-2 actée** (cf. §2 et §5 Q-1). Recommandation forte : C-1 conforme.
- [ ] **Avocat consulté 1h** (~300€) — JCA RGPD + règlement de jeu + audit clause CGV B2B FoodKing existante pour intégrer le module + DSR tombstone pattern vs NF525 (RED-FOLDIN-07).
- [ ] **Budget cash dispo** : ~1-2 k€ pour juridique add-on + pentest delta (réutilise infra hosting FoodKing → pas de cash infra additionnel V1.2).
- [ ] **Q-FINAL gate (RED-FOLDIN-04)** : V1.2 timing justifié par (a) Le Cayenne demande explicite OU (b) FoodKing 3+ restos OU (c) owner accepte explicitement ROI inconclusive 1-restaurant pilot.

---

## §2. Scope V1.2 Review Boost (minimum viable add-on)

### 2.1 Décomposition ambiguïté C-1 vs C-2 (CRITIQUE)

Le brief de mission §2 dit littéralement : *"Customer leaves Google Review (verified via Place API) → trigger wheel-spin → reward voucher"*. **C'est exactement le pattern incentivized review que ULTRA-P0-1 a flaggé comme violation policy Google + exposition DGCCRF + FTC 16 CFR 465.** Le pivot Option A (découplage récompense ↔ avis Google) était précisément la mitigation de ce risque, et cette mitigation reste applicable en fold-in. Sinon, on importe le risque P0-1 d'origine dans FoodKing — pire que tuer SpinBoost, on contamine le produit core.

**Deux variantes possibles. Le plan recommande C-1 par défaut.**

#### Variante C-1 — Découplée, conforme (RECOMMANDÉE)

```
┌──────────────────────────────────────────────────────────────────┐
│  scan QR client final fin de repas (ticket ou table)             │
│      → /review-boost/[branch_slug] page publique                 │
│      → email opt-in (case marketing séparée du voucher, RGPD)    │
│      → NPS privé 4 emojis (feedback resto, optionnel)            │
│      → spin de la roue (animation 3-4s)                          │
│      → voucher inconditionnel (code + QR)                        │
│      → page gain : CTA secondaire "Si vous avez aimé, laissez   │
│         un avis Google" — bouton outlined, identique pour tous,  │
│         INDÉPENDANT du voucher gagné                             │
│      → email Resend confirmation gain (template Mail Laravel)    │
└──────────────────────────────────────────────────────────────────┘
```

- **Trigger spin** : email opt-in (post-meal). Pas de vérification Place API préalable.
- **CTA avis Google** : outlined, secondaire, AFTER gain reveal. Non-récompensé. Identique tous joueurs.
- **Marketing positioning** : "Voice of Customer + CRM marketing en 90 secondes" (cf. ULTRA_REVIEW §1.2 conséquences produit).
- **Risques résiduels** : 0 incentivized risk. RGPD via JCA standard. Loterie publicitaire : conforme si règlement déposé + tirage authoritative documenté.

#### Variante C-2 — Incentivized, risk-assumed (NON-RECOMMANDÉE sauf override owner)

```
┌──────────────────────────────────────────────────────────────────┐
│  scan QR client final fin de repas                               │
│      → "Pour jouer, laissez d'abord un avis Google"             │
│      → vérification Place API : client a-t-il posté un review    │
│         (place_id resto + reviewer's authored reviews via OAuth) │
│      → si vérifié : spin + voucher                               │
│      → si non : message "Postez votre avis pour débloquer"      │
└──────────────────────────────────────────────────────────────────┘
```

- **Trigger spin** : verified Google Review via Place API + reviewer authorship.
- **Marketing positioning** : "Boost ton score Google avec récompenses".
- **Risques** :
  - P0-1 ULTRA_REVIEW direct : *« Offer incentives – such as payment, discounts, free goods and/or services - in exchange for posting any review »* → violation policy Google de longue date.
  - DGCCRF FR : avis non sincère, Code conso L121-1 (pratiques commerciales trompeuses).
  - FTC 16 CFR 465 (en vigueur 21 oct. 2024) : pénalité jusqu'à 51 744 USD/violation si clientèle internationale.
  - Suspension Google Business Profile pilote = 10-25 % probabilité sur 18 mois (ULTRA_REVIEW §1.3 calibré).
  - **Méta-risque fold-in** : si Google sanctionne, c'est FoodKing entier qui est associé (même domaine, même customer-facing brand). Contamination possible.

#### Recommandation orchestrateur

**C-1 par défaut.** Si owner override pour C-2, le plan doit ajouter Sprint -1 "juridique avocat dossier complet + Place API integration + verification reviewer authorship" + Sprint 5 "monitoring Google suspension cascade + plan comm pré-rédigé". Cela ajoute ~2 semaines et 2-3 k€ juridique. **Le plan ci-dessous suppose C-1.**

### 2.2 Fonctionnalités V1.2 Review Boost (C-1)

#### Customer-facing (joueur final)

| F | Fonction | Détail |
|---|---|---|
| F-01 | Page publique branchée par resto | Route `/review-boost/{branchSlug}` (web + mobile), branding resto (logo, couleur primaire). Reuse `BranchScope` pour résolution per-branch. |
| F-02 | Form email opt-in + NPS facultatif | 1 input email + 2 checkboxes **séparées** (voucher email obligatoire ; prospection marketing optionnelle). NPS 4 emojis (privé, ne conditionne pas le spin). Reuse pattern kiosk `LoyaltyOptInRequest`. |
| F-03 | Wheel spin animation | SVG 8 slots configurables. Animation 3-4 s, server-truth (cf. SPIN-ARCH-04 et SPIN-RED-01). 60 fps mid-range. |
| F-04 | Voucher reveal + QR | Code voucher unique (12 char alphanum) + QR signé. Reuse `LoyaltyQrSigner` pattern HMAC. |
| F-05 | Email confirmation gain | Mail Laravel template + Mailable. Reuse `app/Mail/` infra existante FoodKing. |
| F-06 | CTA avis Google secondaire (non-récompensé) | Bouton outlined sous voucher : "Si vous avez aimé, [laissez un avis Google]" → ouvre URL writereview Google Maps du resto. NON-bloquant, NON-conditionné. |
| F-07 | États bloquants | cooldown 30 j par device fingerprint + IP + emailHash. Message clair. Reuse rate-limit pattern Laravel. |

#### Restaurateur-facing (admin FoodKing existant)

| F | Fonction | Détail |
|---|---|---|
| F-10 | Settings Review Boost per-branch | `/admin/review-boost/settings` — toggle module on/off, palette wheel slots, reward catalog, QR url, règlement de jeu URL. Spatie Permission `permission:settings`. |
| F-11 | Reward catalog | Liste rewards = combinaison de : (a) discount code reuse `DiscountType` enum, (b) free item (référence catalog `Item` existant), (c) loyalty points bonus (reuse `LoyaltyService`). Stock optionnel par reward. |
| F-12 | Dashboard KPIs | Scans, plays, conversion %, emails opt-in, vouchers émis vs redeemed. Reuse pattern `app/Http/Controllers/Admin/` + Vue admin components existants. |
| F-13 | Liste participants | CRM-light : email, NPS, ts, voucher, statut redemption. Export CSV. Reuse pattern admin tableaux FoodKing. |
| F-14 | Audit trail Review Boost | Toutes émissions voucher + redemptions append au `audit_logs` chain HMAC NF525 existant. Pas de chain séparée. |

#### Cashier-facing (POS existant)

| F | Fonction | Détail |
|---|---|---|
| F-20 | Voucher validation au POS | Cashier scan QR voucher ou tape code 12-char au POS. **Reuse `PosRedemptionService` + `LoyaltyQrSigner`** patterns existants (les deux supportent QR HMAC + redemption atomic). Voucher appliqué comme `OrderDiscountLog` entry. |
| F-21 | Anti double-redemption | Voucher redeemed → atomic UPDATE state machine PENDING → REDEEMED → no re-use possible. Reuse `loyalty_qr_nonces_consumed` pattern (migration 2026_05_19_100000). |
| F-22 | Affichage POS receipt | Voucher mention + montant remisé. Reuse `Receipt` service existant. |

### 2.3 Hors scope V1.2 (différé V1.3+)

- Google MyBusiness OAuth API integration (Place API verification) — différé V1.3 (cf. C-2 ci-dessus).
- Multi-template wheel customization avancée (drag-drop slot editor) — V1.3.
- AI assistant rédaction avis Mistral — V1.3+ (déjà différé dans ULTRA_PLAN kill list).
- SMS / WhatsApp player — V2.
- Marque blanche custom domain — V2.
- Application native iOS/Android — jamais (web suffit).

---

## §3. Architecture fold-in points (FoodKing infra reuse map)

### 3.1 Module nouveau

> **Anti-pattern guard (RED-FOLDIN-02)** : aucun import depuis `app/Services/ReviewBoost/` ou `app/Domain/ReviewBoost/` vers `app/Services/Pos/Kiosk/Kds/Oss/` — couplage interdit V1.2. Le module Review Boost vit dans son namespace cleanly ou ne ship pas. Vérification : `grep -r "use App\\\\Services\\\\Pos\\|use App\\\\Services\\\\Kiosk\\|use App\\\\Services\\\\Kds\\|use App\\\\Services\\\\Oss" app/Services/ReviewBoost app/Domain/ReviewBoost` → doit retourner 0 hits Sprint 5 gate.

```
app/Domain/ReviewBoost/                      <- NEW domain
├── ReviewBoostStateMachine.php              <- voucher state PENDING|ISSUED|REDEEMED|EXPIRED|FRAUD
└── Events/
    ├── VoucherIssued.php
    └── VoucherRedeemed.php

app/Services/ReviewBoost/                    <- NEW service layer
├── ReviewBoostWheelService.php              <- spin authoritative server-side + drawProof HMAC
├── ReviewBoostVoucherService.php            <- atomic issuance + idempotency
├── ReviewBoostRedemptionService.php         <- extends PosRedemptionService pattern
└── ReviewBoostAntiFraud.php                 <- device fingerprint + email normalization

app/Http/Controllers/
├── Frontend/ReviewBoostController.php       <- public /review-boost/{branchSlug}
├── Admin/ReviewBoostSettingsController.php  <- admin settings
└── Admin/ReviewBoostAdminController.php     <- KPIs + CRM list

app/Http/Requests/
├── ReviewBoostSpinRequest.php
└── ReviewBoostRedeemRequest.php

app/Models/
├── ReviewBoostSpin.php                      <- per spin record
├── ReviewBoostVoucher.php                   <- per voucher (state machine)
└── ReviewBoostSetting.php                   <- per-branch config

resources/js/components/admin/review-boost/  <- NEW Vue admin
├── ReviewBoostSettings.vue
├── ReviewBoostDashboard.vue
├── ReviewBoostCrmList.vue
└── ReviewBoostWheelEditor.vue (V1.3 differred)

resources/views/frontend/review-boost/       <- NEW Blade public page
└── show.blade.php                            <- minimal Blade + Vue mount

resources/js/components/frontend/review-boost/  <- NEW customer-facing Vue
├── ReviewBoostPlayer.vue                    <- A1-A6 player flow
├── WheelSvg.vue                              <- pure SVG 8 slots
└── VoucherCard.vue                           <- signed QR display

database/migrations/
├── 2026_06_XX_create_review_boost_spins_table.php
├── 2026_06_XX_create_review_boost_vouchers_table.php
└── 2026_06_XX_create_review_boost_settings_table.php

routes/web.php                                <- + public /review-boost/{branchSlug}
routes/api.php                                <- + POST /api/frontend/review-boost/spin
                                              <- + POST /api/admin/review-boost/voucher/validate
```

### 3.2 Existing FoodKing infra REUSED (~30-40 % code savings)

Liste précise par chemin (advisor §3 sharpening) :

| FoodKing existant | Réutilisé pour Review Boost | Économie |
|---|---|---|
| `app/Services/Loyalty/PosRedemptionService.php` | F-20 voucher validation POS — **étendre, pas forker**. Voucher redemption suit même pattern (atomic state + HMAC + idempotency + audit log). | ~3-4 jours |
| `app/Services/Loyalty/LoyaltyQrSigner.php` | F-04 voucher QR signed display — pattern HMAC réutilisable tel quel pour signer voucher payload (`branch_id` + `voucher_id` + `nonce` + `ts`). | ~1 jour |
| `app/Services/Pricing/DiscountCalculator.php` + `app/Enums/DiscountType.php` | F-11 reward catalog : ajout 1-2 nouveaux `DiscountType` variants (`REVIEW_BOOST_FIXED`, `REVIEW_BOOST_PERCENT`) ou réutilisation directe selon owner. | ~1 jour |
| `app/Services/Fiscal/AuditLogService.php` | F-14 audit trail : voucher_issued + voucher_redeemed events appendés au chain HMAC NF525 existant — NF525 doit accommoder ces events sans rupture chaîne (cf. §6 P0-checked). | ~2 jours |
| `app/Models/Scopes/BranchScope.php` | F-10 per-branch settings + tous les models `ReviewBoost*` héritent BranchScope (admin branch_id=0 bypass, staff scoped). | ~2 jours |
| `app/Http/Middleware/IdempotencyKeyMiddleware.php` | Spin endpoint + redeem endpoint = POST mutating → middleware déjà monté. `X-Idempotency-Key` header obligatoire. | ~1 jour |
| `app/Services/Idempotency/RedisIdempotencyKeyRepository.php` | Backend du middleware idempotency — réutilisé tel quel. | inclus |
| Sanctum existant + tokens kiosk:order pattern | F-01 page publique sans login (token éphémère per-spin) ou reuse Sanctum guest token pattern (à trancher Sprint 0). | ~1 jour |
| `app/Services/Loyalty/LoyaltyService.php` | F-11 si reward = loyalty points bonus, juste appel à `LoyaltyService::award()` existant. | ~0.5 jour |
| `app/Mail/` infra Laravel | F-05 email confirmation Mail/Mailable patterns existants. | ~0.5 jour |
| Vue admin patterns (`resources/js/components/admin/...`) | F-10, F-12, F-13 — reuse layouts + KPI cards + tableaux + CSV export. | ~2 jours |
| `OrderDiscountLog` model | F-20 voucher redemption logged here as discount entry. | ~0.5 jour |
| Spatie Permission `permission:settings` | F-10 settings gate. | inclus |

**Total estimé économisé** : ~12-15 j-humain vs greenfield (~30-40 % de l'estimation 40 j Option A standalone).

### 3.3 Nouvelles tables DB (3)

#### `review_boost_spins`

```sql
CREATE TABLE review_boost_spins (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    branch_id BIGINT UNSIGNED NOT NULL,           -- BranchScope
    spin_uuid CHAR(36) NOT NULL UNIQUE,           -- public-facing
    email_hash CHAR(64) NULL,                     -- HMAC normalized email
    email_encrypted TEXT NULL,                    -- AES Laravel encrypter
    device_fingerprint VARCHAR(128) NULL,
    ip_address VARCHAR(45) NULL,                  -- IPv4/IPv6
    nps_score TINYINT NULL,                       -- 1-4 (4 emojis)
    nps_comment TEXT NULL,
    marketing_opt_in BOOLEAN DEFAULT FALSE,       -- séparé du voucher
    server_seed_hash CHAR(64) NOT NULL,           -- HMAC du draw
    slot_index TINYINT NOT NULL,                  -- index gagnant
    draw_proof TEXT NOT NULL,                     -- HMAC chain proof
    voucher_id BIGINT UNSIGNED NULL,              -- FK voucher gagné
    created_at TIMESTAMP NOT NULL,
    INDEX idx_branch_created (branch_id, created_at),
    INDEX idx_email_hash_branch_created (email_hash, branch_id, created_at),  -- cooldown
    INDEX idx_fingerprint_branch_created (device_fingerprint, branch_id, created_at)
);
```

#### `review_boost_vouchers`

```sql
CREATE TABLE review_boost_vouchers (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    branch_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(20) NOT NULL UNIQUE,             -- alphanum 12-16 char public
    code_hash CHAR(64) NOT NULL UNIQUE,           -- HMAC for fast lookup
    spin_id BIGINT UNSIGNED NOT NULL,             -- FK review_boost_spins
    reward_type ENUM('discount_percent','discount_fixed','free_item','loyalty_points') NOT NULL,
    reward_value JSON NOT NULL,                   -- {amount: 5.00, currency: EUR} OR {item_id: 42} OR {points: 100}
    state ENUM('issued','redeemed','expired','fraud_blocked') DEFAULT 'issued',
    redeemed_at TIMESTAMP NULL,
    redeemed_by_user_id BIGINT UNSIGNED NULL,     -- cashier
    redeemed_order_id BIGINT UNSIGNED NULL,
    expires_at TIMESTAMP NOT NULL,                -- default +30j
    audit_log_id BIGINT UNSIGNED NULL,            -- FK to audit_logs (NF525 chain)
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL,
    INDEX idx_branch_state (branch_id, state),
    INDEX idx_expires (expires_at)
);
```

#### `review_boost_settings`

```sql
CREATE TABLE review_boost_settings (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    branch_id BIGINT UNSIGNED NOT NULL UNIQUE,
    enabled BOOLEAN DEFAULT FALSE,
    wheel_slots JSON NOT NULL,                    -- [{label, reward_type, reward_value, probability_bp, stock_limit}, ...]
    google_writereview_url VARCHAR(500) NULL,     -- collé manuellement (kill OAuth V1.2)
    regulation_url VARCHAR(500) NULL,             -- règlement de jeu hébergé
    cooldown_days INT DEFAULT 30,
    primary_color VARCHAR(7) DEFAULT '#E63946',   -- hex
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);
```

**Note schema** : `wheel_slots` JSON pour MVP V1.2 (vs table normalisée). Trade-off : simplicité MVP > pattern Prisma `CampaignSlot` normalisé ULTRA-P0-8. Si V1.3 ajoute WheelEditor drag-drop, normalisation à la table.

### 3.4 Routes nouvelles

```php
// routes/web.php — page publique
Route::get('/review-boost/{branchSlug}', 'Frontend\ReviewBoostController@show')
    ->name('review-boost.show');

// routes/api.php — endpoints API
Route::middleware(['throttle:review-boost'])->group(function () {
    Route::post('/api/frontend/review-boost/spin', 'Frontend\ReviewBoostController@spin')
        ->middleware('idempotency-key');
});

Route::middleware(['auth:sanctum', 'permission:pos'])->group(function () {
    Route::post('/api/admin/review-boost/voucher/validate', 'Admin\ReviewBoostAdminController@validateVoucher')
        ->middleware('idempotency-key');
});

Route::middleware(['auth:sanctum', 'permission:settings'])->group(function () {
    Route::get('/api/admin/review-boost/settings', 'Admin\ReviewBoostSettingsController@index');
    Route::put('/api/admin/review-boost/settings', 'Admin\ReviewBoostSettingsController@update');
    Route::get('/api/admin/review-boost/dashboard', 'Admin\ReviewBoostAdminController@dashboard');
    Route::get('/api/admin/review-boost/participants', 'Admin\ReviewBoostAdminController@participants');
    Route::get('/api/admin/review-boost/participants/export', 'Admin\ReviewBoostAdminController@exportCsv');
});
```

### 3.5 Vue components nouveaux

- Customer-facing : `ReviewBoostPlayer.vue` (A1-A6 flow), `WheelSvg.vue` (8 slots), `VoucherCard.vue` (QR).
- Admin : `ReviewBoostSettings.vue`, `ReviewBoostDashboard.vue`, `ReviewBoostCrmList.vue`.
- Mobile mirror : pattern goal-longterm-2026-05-16 — `mobile/screens-review-boost.jsx` standalone optional V1.3.

### 3.6 i18n keys

Nouveaux fichiers de traduction Laravel :

```
lang/fr/review-boost.php
lang/en/review-boost.php
lang/ar/review-boost.php (best-effort)
```

Clés indicatives : `review_boost.welcome`, `review_boost.email_label`, `review_boost.spin_cta`, `review_boost.voucher_title`, `review_boost.cta_google_review_secondary`, `review_boost.cooldown_message`, etc.

### 3.7 Feature flag

```php
// config/foodking.php (ou config/features.php)
'review_boost' => [
    'enabled' => env('REVIEW_BOOST_ENABLED', false),
    'variant' => env('REVIEW_BOOST_VARIANT', 'c1_decoupled'),  // c1_decoupled | c2_incentivized
    'simulation_hardware' => env('REVIEW_BOOST_SIMULATION', false),
],
```

Feature flag par défaut **off**. Owner active per-environnement après Sprint 5 smoke verts.

---

## §4. Sprint sequencing (6 sprints + Sprint 0)

### Sprint 0 — Architecture + DB + permission model + RGPD (1-2 jours)

**Pré-requis 5-cases §1.4 satisfaits + Q-FINAL favorable.**

#### Tâches

- [ ] Migrations DB (3 tables) écrites + tested local Postgres/MySQL.
- [ ] Models Eloquent `ReviewBoostSpin`, `ReviewBoostVoucher`, `ReviewBoostSetting` avec BranchScope auto.
- [ ] `app/Domain/ReviewBoost/ReviewBoostStateMachine.php` : voucher state transitions (ISSUED → REDEEMED | EXPIRED | FRAUD_BLOCKED).
- [ ] Service skeleton `ReviewBoostWheelService` + `ReviewBoostVoucherService` + `ReviewBoostRedemptionService` (vide, signatures only).
- [ ] Routes inscrites mais controllers `abort(501)`.
- [ ] Spatie Permission gates : `permission:review-boost.settings` (settings) + reuse `permission:pos` (redemption).
- [ ] Feature flag `config/foodking.php` + env vars documented in `.env.example`.
- [ ] `config/foodking.php` keys + DB Seeder par branche (default disabled).
- [ ] PHPUnit Feature test scaffolding — assertions structure tables + scoping (8 tests).
- [ ] **RGPD Sprint 0 mandatory (RED-FOLDIN-07)** : `app/Console/Commands/ReviewBoostDsrExportCommand.php` — given customer email, export all PII rows (spins + vouchers + Mail logs) + tombstone audit_logs entries (preserving chain hash via tombstone marker). DSR workflow document `docs/REVIEW_BOOST_DSR.md`.
- [ ] **Avocat 1h Sprint 0 mandatory** : DPIA + JCA RGPD + DSR tombstone pattern vs NF525 6-year retention compatibility check.

#### Gate Sprint 0 → Sprint 1

- Migrations vert local.
- BranchScope test : admin (branch_id=0) voit tous settings, staff (branch_id>0) voit que les siens.
- 8 tests PHPUnit verts.
- DsrExportCommand vert sur seed data.
- Avocat sign-off DPIA + tombstone pattern.

### Sprint 1 — Google Place verification (C-2) OR skip if C-1 (0-3 jours)

**Si variante C-1 retenue** (recommandée) : **SKIP** ce sprint. Pas de Place API en V1.2 (différé V1.3).

**Si variante C-2 imposée par owner** :

- [ ] Google Places API account + clés API + quota acheté (~50€/mois début).
- [ ] Service `ReviewBoostPlaceApiService.php` (NEW si C-2) :
  - `verifyReviewExists(placeId, customerEmail)` (NOTE : Place API ne expose pas les reviewers par email directement → besoin OAuth user-side, complexe).
  - **Risque technique sévère** : Google Places API "Place Details" expose 5 reviews + texts publiquement mais sans user identification. Vérification que CE client a posté = nécessite Google MyBusiness OAuth + reviewer ID match → 2-3 jours dev minimum + risk Google policy de toute façon.
- [ ] Anti-replay : si client réutilise le même review (modifié pour rejouer), bloquer.
- [ ] Place API rate limit + cost monitoring.

**Verdict** : C-2 ajoute 3-5 j de dev technique + risque P0-1 + risque P0-suspension. Confirme recommandation C-1.

### Sprint 2 — Wheel + Spin + Voucher core (3-5 jours)

#### Tâches

- [ ] `ReviewBoostWheelService::spin($branchId, $email, $deviceFingerprint, $ip)` :
  - Cooldown check : SELECT review_boost_spins WHERE email_hash=? OR fingerprint=? AND created_at > NOW()-30j → si exists, abort.
  - Server-authoritative tirage : `random_int(0, 10000)` sur slot cumulés.
  - `drawProof = HMAC-SHA256(prev_play_hash || campaign_slots_snapshot || server_seed || ts)` (cf. ULTRA-P0-2).
  - Atomic stock decrement par slot (si stock_limit défini) : `UPDATE review_boost_settings SET wheel_slots = JSON_REPLACE(...) WHERE id=? AND JSON_EXTRACT(wheel_slots, '$[X].stock_used') < JSON_EXTRACT(wheel_slots, '$[X].stock_limit')` ou table normalisée si V1.3.
  - Create `ReviewBoostSpin` + `ReviewBoostVoucher` dans une transaction DB.
  - Append `audit_logs` chain entry `voucher_issued` (via `AuditLogService::log`).
- [ ] `ReviewBoostVoucherService::issue($spinId, $rewardType, $rewardValue)` :
  - Generate unique code (cuid2-like, 12-16 char alphanum no ambiguous chars).
  - Compute QR signed via `LoyaltyQrSigner` (réutilisation directe).
  - INSERT review_boost_vouchers + return voucher object.
- [ ] Controller `Frontend\ReviewBoostController::spin` :
  - Validate request (Zod-equivalent : Laravel FormRequest `ReviewBoostSpinRequest`).
  - Apply `IdempotencyKeyMiddleware` (déjà mounted).
  - Apply Turnstile/hCaptcha token check (cf. SPIN-SEC-02 — ajout V1.2).
  - Apply `ReviewBoostAntiFraud::evaluate($email, $fingerprint, $ip)` : disposable email check, gmail+ normalization, gmail dot-stripping (cf. SPIN-RED-04).
  - Delegate to `ReviewBoostWheelService::spin`.
  - Return JSON `{ slot_index, voucher_code, voucher_qr_signed, animation_duration_ms }`.
- [ ] Vue customer `ReviewBoostPlayer.vue` :
  - State machine front : `idle → email_form → nps → spinning → revealed → cta_google → done`.
  - WheelSvg server-truth : reçoit `slot_index` du backend, anime CSS keyframes vers position connue (pas de tirage client-side).
  - VoucherCard avec QR signé.
  - CTA Google avis outlined, secondaire, AFTER voucher reveal.
- [ ] Email Resend/Mailgun via Laravel `Mail::send` :
  - Template `app/Mail/ReviewBoostVoucherIssued.php`.
  - Blade view `resources/views/emails/review-boost/voucher-issued.blade.php`.

#### Vérifications fin Sprint

- [ ] PHPUnit : 1 spin sur stock=1 avec 2 requêtes concurrentes → 1 success 1 failed-stock (no double-issue).
- [ ] PHPUnit : drawProof reconstructible côté serveur.
- [ ] PHPUnit : email normalisation Gmail+/dots stripping bloque alias same-user.
- [ ] Playwright E2E : scan QR → email → NPS → spin → voucher reveal → CTA Google bouton visible → email reçu (mailpit local).
- [ ] Visual capture : Read screenshot wheel + voucher → vérification layout intact, pas de raw label.

### Sprint 3 — POS voucher validation + admin settings (2-3 jours)

#### Tâches

- [ ] `ReviewBoostRedemptionService::redeem($voucherCode, $orderId, $userId)` :
  - Atomic state transition : `UPDATE review_boost_vouchers SET state='redeemed', redeemed_at=NOW(), ... WHERE code=? AND state='issued' AND expires_at>NOW()` → check affected rows.
  - Si reward = discount → `OrderDiscountLog::create` entry.
  - Si reward = free_item → composer-flow integration (à valider Sprint 0 with `Composer` service existant).
  - Si reward = loyalty_points → `LoyaltyService::award($userId, $points)` existant.
  - Append `audit_logs` chain entry `voucher_redeemed`.
- [ ] Controller `Admin\ReviewBoostAdminController::validateVoucher` :
  - Validate Sanctum + `permission:pos`.
  - Apply `IdempotencyKeyMiddleware`.
  - Delegate to `ReviewBoostRedemptionService`.
- [ ] POS integration UI (Vue admin POS) :
  - Button "Valider voucher" dans flux paiement.
  - Modal scan QR ou input code 12-16 char.
  - Affichage validation + montant discount appliqué.
- [ ] Admin settings page `/admin/review-boost/settings` :
  - Toggle enabled.
  - Wheel slots editor (V1.2 = JSON textarea ; V1.3 = drag-drop).
  - Google writereview URL paste + regex validation (cf. SPIN-ARCH-09).
  - Règlement de jeu URL.
  - Cooldown days, primary color.
- [ ] Admin dashboard KPI + CRM list + CSV export.

#### Vérifications fin Sprint

- [ ] PHPUnit : double-redemption attempt → 2e échoue (atomic state).
- [ ] PHPUnit : voucher expired → redemption échoue.
- [ ] Playwright E2E POS : cashier scan voucher → discount appliqué → audit_log chain mis à jour.
- [ ] Visual capture POS + admin settings → layout intact.
- [ ] **Cashier outlier detection (RED-FOLDIN-06)** : Admin dashboard expose KPI "vouchers redeemed by cashier user_id" + alerte si cashier X redeems > 10x peer median. Sentry tag billing.review_boost.cashier_outlier.

### Sprint 4 — Anti-fraud hardening + RED-team (2-3 jours)

#### Tâches

- [ ] `ReviewBoostAntiFraud::evaluate` enrichi :
  - Disposable email domains list (lib npm `disposable-email-domains` équivalent PHP).
  - Gmail `+` plus-addressing normalization + dot-stripping (SPIN-RED-04).
  - IPv4/IPv6 normalisation + IP /24 subnet rate-limit (SPIN-RED-08).
  - Device fingerprint via FingerprintJS open-source ou homemade (SPIN-SEC-07 → V1.3 paid).
  - Turnstile / hCaptcha token validation on spin endpoint (SPIN-SEC-02).
- [ ] Server-rendered voucher image (SPIN-RED-01) :
  - Voucher card rendered server-side via Laravel Blade + `intervention/image` ou QR signed only (mitigation V1.2).
  - PNG/data-URI signed inclus dans email + page revealed → DOM mutation client-side ne peut pas faker "top prize".
- [ ] Internal-IP / fingerprint tagging (SPIN-RED-02) :
  - Per-branch internal IP allowlist saved en setting.
  - Plays from internal IP → tagged `is_internal=true` → exclus du dashboard KPIs + alerte si > X plays internes/jour.
- [ ] Webhook (si ajouté pour Resend bounce-tracking V1.2 light) :
  - Réuse `IdempotencyKeyMiddleware` pattern + `webhook_events` table (FoodKing post-iter11 existing).
- [ ] Voucher state machine V1.1 prepared : ISSUED → REDEEMED transition atomic déjà acquis Sprint 3.

#### Vérifications fin Sprint

- [ ] PHPUnit : 10minutemail bloqué.
- [ ] PHPUnit : gmail+alias bloqué après 1er spin.
- [ ] PHPUnit : Turnstile token absent → 403.
- [ ] PHPUnit : DOM mutation attempt simulation → voucher image signée mismatch.
- [ ] Playwright E2E : rotating-proxy farm simulation → blocked after 5 IPs/min.

### Sprint 5 — E2E visual + production smoke + i18n + tag (1-2 jours)

#### Tâches

- [ ] i18n complet : `lang/fr/review-boost.php` exhaustif, `lang/en/review-boost.php`, `lang/ar/review-boost.php` best-effort.
- [ ] Visual mandate (cf. CLAUDE.md §6) : Playwright capture toutes les surfaces :
  - `/review-boost/{branchSlug}` desktop + mobile + tablet.
  - `/admin/review-boost/settings` desktop.
  - `/admin/review-boost/dashboard` desktop.
  - POS voucher validation modal.
- [ ] Read chaque screenshot + analyse manuelle (Layout, raw labels, empty state, error state, branding intact).
- [ ] A11y audit : axe-core run sur surfaces. WCAG 2.1 AA mandatory (cf. DESIGN_BRIEF §8).
- [ ] Production smoke :
  - Feature flag ON sur Le Cayenne staging.
  - 50 spins simulés répartis 24h → distribution slots ±2 % cible.
  - 5 voucher redemptions POS chain audit_logs vérifiée bit-identique.
  - NF525 fiscal chain : `count` += N_voucher_events, `last_hash` recalculable.
- [ ] Documentation owner :
  - `docs/REVIEW_BOOST_V1_2.md` — guide setup, gestion wheel slots, troubleshooting.
  - Mise à jour `CLAUDE.md` §7 frozen zones si applicable (Wheel service après deploy).
  - Update `PROJECT_BRAIN.md` §2 §3 §4 §7.
- [ ] Tag git `v1.2.0` après owner countersign.
- [ ] Graphiti episode push.

#### Gate Sprint 5 → LIVE

- 0 P0 / 0 P1 ouverts sur Review Boost.
- Lighthouse mobile `/review-boost/{branchSlug}` ≥ 90 perf + a11y.
- Backup DB testé.
- Sentry alertes configurées.
- Le Cayenne owner valide module en prod live → feature flag ON.

### Estimation totale

| Sprint | Jours-humain effectifs | Calendaire |
|---|---|---|
| 0 — Archi + DB | 1-2 j | 2-3 j |
| 1 — Place API (skip si C-1) | 0-3 j | 0-5 j |
| 2 — Wheel + Spin + Voucher | 3-5 j | 5-7 j |
| 3 — POS validation + admin | 2-3 j | 3-5 j |
| 4 — Anti-fraud + RED | 2-3 j | 3-5 j |
| 5 — E2E + smoke + tag | 1-2 j | 2-3 j |
| **TOTAL C-1** | **9-15 j** | **~4-6 sem** |
| **TOTAL C-2** | **+3-5 j** | **+1-2 sem** |

---

## §5. Open questions for owner (V1.2 phase)

Owner-gates avant Sprint 0. Réponses requises pour démarrer.

### Q-1 — Variante C-1 (recommandée) ou C-2 ? (CRITIQUE)

- **Q-1.a** Conformité Google policy : owner accepte recommandation C-1 (découplée) qui élimine 100 % du risque P0-1 ULTRA_REVIEW ?
- **Q-1.b** Si non : owner countersigne acceptation des risques C-2 (DGCCRF + Google suspension + FTC + contamination méta-risque FoodKing) avec budget juridique +2-3 k€ ?
- **Recommandation forte plan** : C-1.

### Q-2 — Voucher economics (reward catalog)

- **Q-2.a** Pourcentage discount fixe (ex 5 % / 10 % / 15 %) ?
- **Q-2.b** Montant fixe (ex 2€ / 5€) ?
- **Q-2.c** Free item (catalogue) — quels items éligibles ? Coût marge moyen ?
- **Q-2.d** Loyalty points bonus (ex 100 pts) — taux conversion existant ?
- **Q-2.e** Mix (5 slots discount + 2 slots free item + 1 slot loyalty points + 1 slot consolation) ?
- **Q-2.f** Probabilités cumulées 100 % ? Sum check côté Zod + DB CHECK constraint.

### Q-3 — Eligibilité customer (1 spin par X)

- **Q-3.a** Cooldown : 30 j par device fingerprint + email ? 60 j ? Par resto ou cross-resto ?
- **Q-3.b** Pour le même customer FoodKing connecté (loyalty) : 1 spin par order ? Par mois ?
- **Q-3.c** Cross-branch (chaine FoodKing) : Le Cayenne + autres restos = même cooldown ou indépendant ?

### Q-4 — Wheel customization per-branch

- **Q-4.a** V1.2 = JSON textarea pour wheel_slots (settings simple). V1.3 = drag-drop WheelEditor. OK ?
- **Q-4.b** Logo resto sur la roue : auto-pickup du branding existant ou upload séparé ?
- **Q-4.c** Palette colors : utilise primary_color de Le Cayenne ou custom Review Boost color ?

### Q-5 — Distribution

- **Q-5.a** Par défaut désactivé (feature flag off) ou activé pour tous les FoodKing customers ?
- **Q-5.b** Le Cayenne pilot launch ? Owner valide live ON après Sprint 5 ?
- **Q-5.c** Pricing : add-on gratuit V1.2 / fee mensuel V1.3 / fee per-redeemed-voucher V2 ?

### Q-6 — Règlement de jeu (juridique)

- **Q-6.a** Avocat 1h Sprint 0 — budget validé ? ~300€.
- **Q-6.b** Règlement de jeu publié URL externe ou page admin FoodKing `/legal/review-boost-rules` ?
- **Q-6.c** JCA RGPD art. 26 entre FoodKing SAS et chaque resto client (joint controller) : standard pour tous ou per-resto custom ?

### Q-7 — Place API (uniquement si C-2 retenu)

- **Q-7.a** Budget API Google Places ~50€/mois début. Owner OK ?
- **Q-7.b** Google Cloud project setup + OAuth scopes verification — owner gère ou plan prévoit dev time ?

### Q-8 — Mobile mirror

- **Q-8.a** Module Review Boost dans mobile FoodKing standalone V1.2 ou différé V1.3 ?
- **Q-8.b** Si V1.2 : compose_profile hardcoded mirror DB pattern (cf. memory `project_mobile_realignment_ultraplan_2026-05-16`).

### Q-9 — Frozen zone gates

- **Q-9.a** Si voucher discount nécessite modification `PricingService::calculateOrder` (frozen zone CLAUDE.md §7) : LOCK doc nécessaire ? Probablement oui — voir §6 P0-checked.
- **Q-9.b** Si voucher_issued/voucher_redeemed events appendés à `audit_logs` chain : impact sur `FiscalSequenceService` ? Probablement non si append-only sans rupture chaîne, mais à valider tests régression NF525.

### Q-10 — Marketing positioning C-1 (RED surfaced)

- **Q-10.a** Owner valide-t-il que la valeur perçue par le restaurateur de Review Boost est CRM + email opt-in + NPS feedback (pas boost direct Google reviews) ?
- **Q-10.b** Si owner non confiant que Patron Pierre (persona DESIGN_BRIEF §1.2) accepte cette valeur découplée : V1.2 module risque d'être laissé feature-flag OFF par les restos → ROI = 0 → KILL ou revert C-2 avec acceptation risques.

### Q-FINAL — Timing V1.2 vs V1.3+ (RED surfaced)

- **Q-FINAL.a** V1.2 Review Boost démarrage uniquement SI :
  - (a) Le Cayenne owner explicitement demande Review Boost ASAP comme upsell pilot, OU
  - (b) FoodKing acquisitionné 3+ restos avant Sprint 0 → 3+ data points justifient effort, OU
  - (c) Owner accepte explicitement « 1 restaurant pilot inconclusive ROI mais investissement infra long-terme justifie ».
- **Q-FINAL.b** Sinon : V1.2 différé V1.3 ou V1.4. Plan reste utile comme design doc archivé.
- **Q-FINAL.c** Hard stop trigger : si V1.1 dérape > 4 semaines au-delà roadmap, V1.2 automatiquement différé V1.3.

---

## §6. Anti-fraud carry-over from SpinBoost audit (12 P0 + 24 P1 re-évaluation)

Grille reformulée par l'advisor : pour chaque finding ULTRA_REVIEW + Wave D, statut applicable / mitigated / N/A dans contexte fold-in.

### P0 carry-over (12 items)

| # | Finding original | Status fold-in | Why |
|---|---|---|---|
| ULTRA-P0-1 | Incentivized review violation Google + DGCCRF + FTC | **APPLIES (variant C-2)** / **N/A (variant C-1)** | C-1 découple récompense de l'acte avis Google → 0 risque P0-1. C-2 importe le risque. Plan recommande C-1. |
| ULTRA-P0-2 | drawProof HMAC chain audit | **APPLIES** | Spin authoritative reste applicable. Implémentation Sprint 2. Reuse pattern `LoyaltyQrSigner` HMAC. |
| ULTRA-P0-3 | Webhook idempotency | **MITIGATED** | `IdempotencyKeyMiddleware` FoodKing déjà mounted. `webhook_events` table existante (UNIQUE provider+webhook_id post iter11). |
| ULTRA-P0-4 | Hono + Edge + Prisma contradiction | **N/A** | Stack Laravel/Vue monolithe. Pas de Edge runtime. PHP-FPM uniforme. |
| ULTRA-P0-5 | 3 apps + Turborepo overkill | **N/A** | Laravel monolithe = 1 codebase déjà. Pas de question. |
| ULTRA-P0-6 | KMS envelope encryption | **SOFTER** | Laravel `APP_KEY` AES-256 + sealed-box pattern acceptable V1.2 (vs full KMS rotation). DEK rotation 90j déferrable V1.3 si MRR justifie. Encrypted `email_encrypted` colonne via Laravel encrypter. |
| ULTRA-P0-7 | MFA OWNER | **MITIGATED partiellement** | Spatie permissions FoodKing existing + Sanctum. MFA TOTP à valider pour OWNER FoodKing admin si pas déjà en place V1.0.1 (check). Si pas en place : ajout Sprint 0 trivial. |
| ULTRA-P0-8 | Schema constraints (atomic stock, slot sum, WIN sans Prize) | **APPLIES** | Sprint 2 : atomic UPDATE pattern (déjà fait pour `loyalty_qr_nonces_consumed`). Slot sum check Zod + DB CHECK constraint. WIN sans Prize → `reward_type NOT NULL` + voucher state machine FK strict. |
| ULTRA-P0-9 | JCA RGPD art. 26 | **APPLIES** (reformulated) | FoodKing SAS et resto client = joint controllers du module Review Boost (data customer collected via FoodKing infra mais finalité commerciale resto). JCA template à rédiger Sprint 0 avocat 1h. Différent du JCA SpinBoost standalone car contexte FoodKing existant. |
| SPIN-ARCH-02 | Runtime pinning + eslint | **N/A** | Laravel monolithe, PHP-FPM, pas de runtime drift. |
| SPIN-ARCH-03 | Route-group skeleton + arch-lint | **APPLIES soft** | Conventions FoodKing namespace `App\Http\Controllers\Frontend\` vs `Admin\` déjà en place. Ajout namespace `ReviewBoost\` cohérent. |
| SPIN-ARCH-04 | Designer addendum "server-truth wheel" | **APPLIES** | Sprint 2 : Vue `WheelSvg.vue` reçoit `slot_index` du backend, anime vers position connue. Pas de tirage client-side. Playwright sentinel : intercepter `/spin` réponse + vérifier slot animé match. |
| SPIN-SEC-03 | MFA Sprint 1 not 5 | **MITIGATED partiel** | Voir ULTRA-P0-7. |
| SPIN-RED-01 | Server-rendered voucher image | **APPLIES** | Sprint 4 : voucher card signed PNG/data-URI inclus email + page revealed. DOM mutation client = mismatch signature. |
| SPIN-RED-02 | Patron self-play loop | **APPLIES** | Sprint 4 : internal IP allowlist per-branch + dashboard tagging. |
| SPIN-RED-06 | Stripe overage policy | **N/A V1.2** | Pas de pricing per-spin V1.2 (add-on gratuit). Si V1.3 ajoute fee, à re-évaluer. |

### P1 carry-over (24 items synthétisés)

| Catégorie | Items concernés | Status fold-in | Sprint cible |
|---|---|---|---|
| Anti-fraude email/device | P1-1, SPIN-RED-04, SPIN-SEC-09 | **APPLIES** | Sprint 4 (disposable email + gmail+ + age ≥16 checkbox) |
| JWT/session | P1-2, SPIN-SEC-06 | **MITIGATED** | Sanctum FoodKing déjà en place + token revocation existante |
| Pseudonymisation/RGPD | P1-3, P1-4, SPIN-SEC-05 | **APPLIES** | Sprint 0 (schema split Play + PlayAntiFraud — adapté en split `review_boost_spins` + `review_boost_anti_fraud_signals` table V1.3) |
| NF ISO 20488 | P1-5 | **N/A** | Pas de revendication NF dans plan V1.2 |
| Compétiteur Sunday | P1-6 | **N/A** | Pas un risque fold-in (pas en compétition SaaS) |
| Tarif | P1-7 | **N/A V1.2** | Add-on gratuit FoodKing customers |
| 50 restos en 6 mois | P1-8 | **N/A** | FoodKing customers existants, pas de growth target SaaS |
| DPIA | P1-9 | **APPLIES** | Sprint 0 (avocat 1h) + Sprint 5 finalize |
| Schéma secondaires | P1-10 | **PARTIAL** | 3 tables review_boost_* déjà conçues §3.3. WebhookEvent FoodKing existing. NotificationLog mailable Laravel tracking. |
| Slot sum DB CHECK | SPIN-ARCH-05 | **APPLIES** | Sprint 0 (CHECK constraint à la migration) |
| OTel traceparent | SPIN-ARCH-06 | **N/A V1.2** | FoodKing pas en OTel actuellement, ajout différé V1.3 (Laravel context propagation pattern différent) |
| Prisma org-scope | SPIN-ARCH-07 | **MITIGATED** | BranchScope FoodKing = équivalent fonctionnel |
| Writereview URL validation | SPIN-ARCH-09, SPIN-RED-07 | **APPLIES** | Sprint 3 (regex + UI test-link button) |
| Turnstile sur /spin | SPIN-SEC-02 | **APPLIES** | Sprint 4 |
| JCA boundary table | SPIN-SEC-04 | **APPLIES** | Sprint 0 avocat |
| Stripe IP allowlist | SPIN-SEC-08 | **N/A V1.2** | Pas de Stripe V1.2 (FoodKing payment infra existante reuse si besoin) |
| DPIA forcing function | SPIN-SEC-10 | **APPLIES** | Sprint 0 + 5 |
| Writereview Place ID | SPIN-RED-07 | **APPLIES (variant C-1 soft)** | Sprint 3 |
| DoS botnet load | SPIN-RED-08 | **APPLIES** | Sprint 5 load test |

### P2 carry-over (10 items)

- Webhook DLQ (SPIN-ARCH-08) — **MITIGATED** (FoodKing webhook retry cron existant `OutboxWebhookRetryFailedCommand.php`).
- Redemption state machine (SPIN-ARCH-10) — **APPLIES** déjà Sprint 3.
- Flyer versioning (SPIN-ARCH-11) — **N/A V1.2** (pas de flyer feature V1.2 ; QR direct dans email/page).
- ARCHITECTURE.md / FROZEN_ZONES.md (SPIN-ARCH-12) — **N/A** (FoodKing CLAUDE.md existant suffit, ajout §7 frozen-zone post-deploy).
- Pentest €1500 (SPIN-SEC-11) — **APPLIES** (Sprint 5 self-pentest nuclei + zap-baseline + semgrep + 1j freelance).
- Incident response runbook (SPIN-SEC-12) — **APPLIES soft** (FoodKing runbook existant + ajout Review Boost spécifique).
- Voucher sharing screenshot (SPIN-RED-03) — **MITIGATED** (state machine atomic ISSUED → REDEEMED Sprint 3).
- NPS poisoning (SPIN-RED-09) — **APPLIES V1.3** (outlier detection différé).
- Voucher resale (SPIN-RED-10) — **MITIGATED** (state machine atomic).
- Stripe thin-payload (SPIN-RED-11) — **N/A V1.2**.

### Synthèse mitigation

- Sur 12 P0 carry-over : **5 APPLIES** (P0-2, P0-8, P0-9, ARCH-04, RED-01) — vrais sprints required.
- **3 MITIGATED** par infra FoodKing (P0-3 idempotency, P0-7 MFA partiel, ARCH-03 namespace).
- **2 N/A** (P0-4, P0-5 stack-specific).
- **1 SOFTER** (P0-6 envelope encryption).
- **1 BIVALENT** (P0-1 dépend C-1 vs C-2).

- Sur 24 P1 : ~30 % MITIGATED ou N/A par infra FoodKing, ~70 % APPLIES → distribués Sprints 2-5.

**Économie nette** : ~5-7 j-humain vs greenfield Option A grâce à infra reuse.

---

## §7. Risks + mitigations

### R-01 — Google policy enforcement (variant-dependent)

- **Probabilité** : faible C-1, moyenne C-2 (10-25 % sur 18 mois selon ULTRA_REVIEW §1.3).
- **Impact** : C-1 négligeable (CTA secondaire conforme) ; C-2 = suspension Le Cayenne Google Business Profile + bad PR + contamination FoodKing brand.
- **Mitigations C-1** :
  - Marketing copy strict : "Aucune récompense en échange d'un avis Google. Notre roue est ouverte à tous nos clients."
  - Règlement de jeu déposé publiquement (URL accessible depuis page).
  - Audit annuel juridique 1h.
- **Mitigations C-2** : voir Sprint -1 + plan comm pré-rédigé + monitoring `accounts.locations.list` hebdo.

### R-02 — Customer trust (transparence)

- **Probabilité** : moyenne (customer mécontent peut penser que le resto "achète" les avis).
- **Impact** : reputation locale + bouche-à-oreille négatif.
- **Mitigations** :
  - Copy claire : "Nous ne payons pas pour les avis, mais nous récompensons votre passage chez nous."
  - Voucher inconditionnel = "Notre cadeau pour vous remercier de votre visite, indépendamment de votre avis."
  - CTA Google avis = "Si vous avez aimé, [laissez un avis Google]" — non-conditionnel, identique tous.
  - FAQ publique sur règles du jeu.

### R-03 — Owner Google account suspension propagation

- **Probabilité** : très faible C-1, faible C-2.
- **Impact** : tous les restos FoodKing utilisant la même Google account business suspension → cascading. Le Cayenne pilote actuellement seul → impact contenu.
- **Mitigations** :
  - Chaque resto FoodKing utilise SON propre Google Business Profile (pas l'account FoodKing SAS).
  - Settings per-branch isolés (no cross-leakage via BranchScope).
  - V1.3+ multi-account : audit board pour détecter usage cross-restaurant suspect.

### R-04 — Fraude voucher resale / sharing

- **Probabilité** : moyenne (vouchers screenshot partagés WhatsApp/eBay/Vinted).
- **Impact** : marge resto érodée.
- **Mitigations** :
  - Code voucher 12-16 char alphanum unique non-devinable.
  - State machine atomic ISSUED → REDEEMED (un seul redemption possible).
  - Expiration 30 j default.
  - QR signed HMAC : si screenshot modifié, signature invalide.
  - Per-customer cooldown 30 j device fingerprint + email.

### R-05 — Solo founder maintenance burden

- **Probabilité** : moyenne (1 module supplémentaire à supporter).
- **Impact** : 10-20 % time absorbed sur support Review Boost.
- **Mitigations** :
  - Feature flag par défaut OFF — owner active quand bandwidth dispo.
  - Documentation owner `docs/REVIEW_BOOST_V1_2.md` exhaustive.
  - FAQ resto-side intégrée admin settings.
  - V1.3 si scaling : monitoring Sentry alertes proactives.

### R-06 — NF525 chain integrity (risque technique critique)

- **Probabilité** : faible si discipline append-only respectée.
- **Impact** : CRITIQUE — toute rupture chaîne audit_logs/z_reports = perte conformité fiscale 6 ans rétention, exposition pénale.
- **Mitigations** :
  - `voucher_issued` + `voucher_redeemed` appendés à `audit_logs` chain via `AuditLogService::log` existant (pattern Loyalty already does this).
  - Tests régression NF525 obligatoires Sprint 5 : chain count + last_hash bit-identique avant/après deploy.
  - LOCK doc si modification `FiscalSequenceService` envisagée — probablement pas nécessaire car append-only n'altère pas séquence fiscale (qui est sur orders, pas sur audit events).
  - Owner gate explicit Sprint 5 avant tag.

### R-07 — Pricing/discount frozen zone

- **Probabilité** : faible.
- **Impact** : MAJOR — `PricingService::calculateOrder` est frozen zone CLAUDE.md §7. Si voucher discount nécessite modification, LOCK obligatoire.
- **Mitigations** :
  - Reuse `DiscountCalculator` + `OrderDiscountLog` patterns existants — pas de modif `PricingService` core.
  - Voucher = `DiscountType` enum extension ou réutilisation existing types — à valider Sprint 0 architecte.
  - Si LOCK requis : doc `plans/LOCK_REVIEW_BOOST_PRICING_2026-06-XX.md` + owner countersign.

### R-08 — Performance dégradation pages publiques

- **Probabilité** : faible.
- **Impact** : LCP `/review-boost/{branchSlug}` doit rester < 1.5 s sur 4G.
- **Mitigations** :
  - Page Blade minimale + Vue mount async.
  - WheelSvg pure SVG (pas Framer, pas heavy lib).
  - Branding logo lazy-loaded.
  - Lighthouse mobile ≥ 90 obligatoire Sprint 5.

### R-09 — Memory cloud-yet drift

- **Probabilité** : moyenne (tentation d'ajouter cloud-side Stripe billing ou Resend account séparé).
- **Impact** : violation memory `feedback_no_cloud_until_owner_initiates`.
- **Mitigations** :
  - Réutiliser `Mail::send` Laravel infra existante FoodKing (pas de nouveau Resend account séparé).
  - Pas de Stripe V1.2 (add-on gratuit).
  - Pas d'infra cloud nouvelle.
  - Sentry/observability si ajouté = même account FoodKing existant.

### R-10 — Variant drift C-1 → C-2 silencieux

- **Probabilité** : faible si discipline respect.
- **Impact** : si dev/copy/marketing dérive vers incentivized review pattern sans owner gate = importation P0-1 risk.
- **Mitigations** :
  - Feature flag `REVIEW_BOOST_VARIANT=c1_decoupled` strict.
  - Copy review/audit obligatoire Sprint 5 (relecture juridique).
  - Tests E2E vérifient CTA Google avis = outlined secondaire (pas bloquant gain).
  - Plan §2.1 explicite owner gate Q-1.

---

## §8. V1.x roadmap positioning

### V1.0.X (en cours, post-V1.0.1 hardening 2026-05-17)

- V1.0.2 :
  - POS Loyalty Redeem UI Option B (DONE memory `project_v1_0_1_hardening_2026-05-17`).
  - Cash session livreur (backlog).
  - Autres items hardening V1.0.2 (cf. memory).
- V1.0.3 : autres hardening + bugfixes Le Cayenne pilot.

### V1.1 (post-V1.0.2 stabilisé)

- Google MyBusiness OAuth re-évaluée à l'aune enforcement landscape 2026 (kill-list ULTRA_PLAN item).
- Multi-user / invitations équipe FoodKing (kill-list ULTRA_PLAN item).
- POS extensions (intégrations TPE, Tiller, Lightspeed differred ULTRA_PLAN).
- Autres backlog hardening 2026.

### V1.2 — **⭐ Review Boost fold-in (CE PLAN)**

- Sprint 0 → Sprint 5 (§4).
- 4-6 semaines calendaires.
- 9-15 j-humain effectifs.
- Owner-gate démarrage post V1.1 stabilisé.
- Variante C-1 recommandée.

### V1.3

- Wheel drag-drop editor (WheelEditor advanced).
- AI assistant rédaction avis Mistral (différé ULTRA_PLAN kill-list).
- FingerprintJS Pro graduation (MRR justifié).
- NPS outlier detection.
- Mobile mirror Review Boost (si pas inclus V1.2 — voir Q-8).
- OTel traceparent propagation (FoodKing-wide).

### V1.4+ / V2

- Marque blanche custom domain per-resto.
- SMS / WhatsApp player.
- Autres jeux gamification (grattage, slot machine, memory).
- Admin SpinBoost UI complète (SQL console + Stripe dashboard suffisent V1.2-V1.3 owner solo).

### Dépendances inter-versions

```
V1.0.1 hardening (DONE) → V1.0.2 (in progress) → V1.0.3
                                       ↓
                                    V1.1 (Google OAuth + multi-user) — owner-gated
                                       ↓
                                    V1.2 (Review Boost fold-in) — CE PLAN
                                       ↓
                                    V1.3 (Wheel advanced + mobile mirror + AI)
                                       ↓
                                    V2 (marque blanche + SMS + autres jeux)
```

### Conditions de re-évaluation Option C vs A

Si V1.2 ship + 3 mois en prod + KPIs :
- Engagement haute (scan → spin > 70 % cible, emails opt-in > 40 % cible) : ✅ Confirmer Option C, continuer roadmap V1.3.
- Engagement faible (< 30 %) : ⚠️ Re-évaluer — variant C-1 marketing positioning à corriger ou kill module.
- Distribution Le Cayenne seul (FoodKing pas encore 5+ restos) : OK, attendre traction FoodKing core avant juger.
- FoodKing acquisitionne 10+ restos avant V1.3 + Review Boost ROI démontré : re-considérer **rétroactivement** spin-off SaaS Option A enrichi de l'apprentissage fold-in. Path possible mais non-prioritaire.

---

## §9. Acceptance criteria + verification matrix

### Acceptance criteria V1.2 Review Boost

- [ ] AC-01 : Customer scan QR → page publique chargée < 1.5 s LCP mobile 4G.
- [ ] AC-02 : Email opt-in form + NPS facultatif fonctionnent + checkboxes séparées RGPD-conformes.
- [ ] AC-03 : Spin server-truth + drawProof HMAC reconstructible + animation 3-4s + slot reveal cohérent.
- [ ] AC-04 : Voucher généré + QR signé + email Resend reçu < 30s.
- [ ] AC-05 : CTA Google avis outlined secondaire, non-conditionnel, AFTER reveal.
- [ ] AC-06 : Cooldown 30j device + email + IP fonctionne (3 spins → 4e bloqué).
- [ ] AC-07 : Disposable email bloqué (10minutemail).
- [ ] AC-08 : Gmail+alias normalisation bloque même customer.
- [ ] AC-09 : Turnstile token validation sur /spin endpoint.
- [ ] AC-10 : Concurrent spins sur stock=1 → 1 success 1 fallback (no double-issue).
- [ ] AC-11 : Cashier POS scan voucher QR → discount appliqué + audit_logs entry.
- [ ] AC-12 : Double-redemption attempt → 2e échoue atomic state machine.
- [ ] AC-13 : Voucher expired → redemption échoue.
- [ ] AC-14 : Admin settings per-branch BranchScope respecté (admin voit tout, staff branche scoped).
- [ ] AC-15 : Admin dashboard KPIs + CRM list + CSV export fonctionnent.
- [ ] AC-16 : NF525 audit chain count incrémenté correctement par voucher_issued + voucher_redeemed events, last_hash bit-identique recalculable.
- [ ] AC-17 : Frozen zones intacts : `PricingService::calculateOrder` non-modifié (OU LOCK signed), `FiscalSequenceService` non-modifié, `BranchScope` non-modifié.
- [ ] AC-18 : Visual mandate : Read screenshots Wheel + Voucher + Settings + Dashboard → layout intact, branding cohérent, pas de raw label.
- [ ] AC-19 : A11y WCAG 2.1 AA — contraste 4.5:1, focus visible, aria-labels boutons icon-only, keyboard nav.
- [ ] AC-20 : Lighthouse mobile `/review-boost/{branchSlug}` ≥ 90 perf + a11y.
- [ ] AC-21 : 0 P0 / 0 P1 ouverts.

### Tests requis (matrice)

| Type | Min count | Notes |
|---|---|---|
| PHPUnit Feature | 30-40 tests | Wheel service, Voucher service, Redemption service, Anti-fraud, BranchScope isolation, idempotency replay, NF525 chain |
| PHPUnit Unit | 10-15 tests | State machine, drawProof, email normalization |
| Playwright E2E customer | 5 specs | Happy path + cooldown + disposable email + Turnstile + voucher revealed |
| Playwright E2E POS | 3 specs | Redemption happy + double-redemption + expired voucher |
| Playwright E2E admin | 3 specs | Settings save + dashboard load + CSV export |
| Visual capture | 6-10 surfaces | Read screenshots manuels |
| A11y axe-core | toutes surfaces customer + admin | WCAG 2.1 AA mandatory |
| Lighthouse | mobile /review-boost/{slug} | ≥ 90 perf + a11y |
| Load test k6 | 100 req/s sur /spin | Sprint 5 |
| NF525 regression | 1 spec dédiée | chain count + last_hash bit-identique avant/après |

---

## §10. Risques résiduels acceptés (transparence)

1. **Marché feedback** : V1.2 add-on testé sur Le Cayenne pilot uniquement. Pas de validation multi-resto avant V1.3. Si copy/UX/economics inadéquats, ajustement V1.3.
2. **Place API V1.3** : si owner change variant C-2 plus tard, intégration Google MyBusiness OAuth = +3-5 j + risque P0-1 revient. Path documenté §2.1.
3. **Marque blanche V2** : si demande resto custom domain, refonte infra. Hors scope V1.2.
4. **Mobile mirror** : si Q-8.a différé V1.3, customers mobile scan via web mobile responsive — acceptable car LCP < 1.5s mobile validé.
5. **AI assistant rédaction avis** : différé ULTRA_PLAN kill-list. Acceptable car CTA Google avis = customer libre rédige.

---

## §11. Sub-agent synthesis (specialists Architect + RED)

Voir spécialist JSONs :
- `reports/audit/wave-e-2026-05-19/WE-3-SPINBOOST-V12-PLAN/SPIN-ARCH-foldin.json`
- `reports/audit/wave-e-2026-05-19/WE-3-SPINBOOST-V12-PLAN/SPIN-RED-foldin.json`

Synthèse intégrée dans §3, §6, §7 du présent plan.

---

## §12. Deliverables index

- `plans/SPINBOOST_FOLD_IN_FOODKING_V12_PLAN_2026-05-19.md` (CE FICHIER).
- `reports/audit/wave-e-2026-05-19/WE-3-SPINBOOST-V12-PLAN/SPIN-ARCH-foldin.json`.
- `reports/audit/wave-e-2026-05-19/WE-3-SPINBOOST-V12-PLAN/SPIN-RED-foldin.json`.
- `reports/audit/wave-e-2026-05-19/WE-3-SPINBOOST-V12-PLAN/STATUS.md`.

Reference docs read (not modified) :
- `DESIGN_BRIEF_SPINBOOST_2026-05-16.md`.
- `ULTRA_PLAN_SPINBOOST_DECOMPOSED_2026-05-16.md`.
- `ULTRA_REVIEW_SPINBOOST_2026-05-16.md`.
- `reports/audit/spinboost-2026-05-19/STATUS.md`.
- `reports/audit/spinboost-2026-05-19/round-1/SPIN-1-architect/architect.json`.
- `reports/audit/spinboost-2026-05-19/round-1/SPIN-2-security/security.json`.
- `reports/audit/spinboost-2026-05-19/round-1/SPIN-3-red/red.json`.

---

## §13. Final verdict — owner gates synthesis

**Plan status** : DRAFT, owner-review-required.

**Owner decision points before Sprint 0** :
1. Q-1 variant C-1 vs C-2 → recommandation forte plan : C-1.
2. Q-2 voucher economics → owner-defined.
3. Q-3 cooldown rules → owner-defined.
4. Q-5 distribution + pricing add-on → owner-defined.
5. Q-6 juridique avocat 1h + JCA → owner budget.
6. Q-8 mobile mirror V1.2 ou V1.3 → owner-defined.
7. Q-9 frozen zone LOCK si applicable → owner-defined.

**Démarrage Sprint 0 conditionné aux 5 cases §1.4** :
- [ ] V1.0.2 stabilisé.
- [ ] V1.1 roadmap clarifiée.
- [ ] Variante C-1 actée.
- [ ] Avocat consulté 1h.
- [ ] Budget cash dispo ~1-2 k€.

**Ce plan ne modifie aucun code FoodKing.** Owner peut le valider, l'archiver, le différer V1.3, ou kill définitivement sans impact sur V1 LeCayenne. Le verrouillage memory `feedback_no_cloud_until_owner_initiates` est respecté à 100%.

---

**Fin du plan SPINBOOST_FOLD_IN_FOODKING_V12_PLAN_2026-05-19.md.**
