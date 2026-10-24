# GOAL — ONB-09 ANIMATION COMMERCIALE
## FoodKing — Onboarding commerçant · promotions, codes, offres, fidélité, ticket promo, notifications, roue : le commerçant anime ses ventes lui-même, sans casser le prix ni la caisse

- **Slug** : `ONB09_ANIMATION_COMMERCIALE_20260826` · **Auteur** : Claude Code (chef de projet + rédacteur) · **Date** : 2026-08-26
- **HEAD** : `43b120c7d` · **Branche de base** : `pos/category-first-caisse-2026-06-23`
- **Voie SYSTEM_MAP** : CENTRAL — sous-voie « animation commerciale » (`admin/{pushNotification,messages,subscribers,coupons,offers,promo}/**`, `settings/LoyaltySetup/**`, `Wheel/**`)
- **Index parent** : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` · **Rapport de mission** : `reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB09_ANIMATION_COMMERCIALE.md`
- **Port de session** : **8809** · **Persona** : Nadia veut « -10 % sur les menus le mardi », un code `BIENVENUE` sur la borne, des points fidélité, et prévenir ses abonnés.

> **En cinq lignes.** Le problème : Coupons et Offres sont **cachés** (`v1-hidden-modules.js`) et **désactivés** par trois drapeaux de fichier (`pos.coupon_codes_enabled=false`,
> `kiosk.promo_enabled=false`, `features.offers_enabled=false` — sentinelle `OffersDisabledV1SentinelTest` !) ; un coupon **accepté au devis puis refusé au commit** a été
> constaté le 15/08 et différé (tarification SSOT) ; les notifications push alimentent une file **`notifications` orpheline** (~1 500 jobs en Redis, aucun worker) ; la roue
> et le ticket promo sont des flux Le Cayenne ; zone **non auditée en direct** le 26/08 (brief Z6 prêt). FINI = Nadia crée une promo, un code, une offre, règle sa fidélité
> et notifie, depuis le Dashboard, avec le prix toujours calculé par le backend et un devis jamais contredit au commit (C1..C6). Hors : `PricingService`/`DiscountCalculator`
> (LOCK), `PosLoyaltyController` (CAISSE), worker/queue (→ 10). Premier geste : W0 puis exécuter le brief Z6 sur :8809.

# §0 — PRÉAMBULE

## §0.1 — Décision arbre de travail + PRÉ-VOL DE SESSION
- **Worktree dédié** `.claude/worktrees/onb09-animation`, branche `goal/onb09-animation-2026-08-26`, depuis **HEAD**.
- Pré-vol : `.env` → `APP_URL=http://127.0.0.1:8809` ; `.env.testing` ; liens durs ; `ReflectionClass(App\Services\CouponService::class)` → worktree ; serveur 8809 ; `PLAYWRIGHT_BASE_URL`.
- Base partagée : coupons / offres / abonnés / notifications de test préfixés `GOAL-ONB09`, supprimés définitivement ; ⛔ **aucune commande créée** (un coupon se prouve au **devis** : `POST /api/frontend/quote` ou route POS de devis, jamais au commit) ; ⛔ aucun push/mail/SMS réel : `QUEUE_CONNECTION=sync` désactivé ? Non — laisser la file, ne **jamais** lancer `queue:work --queue=notifications` ; jamais `migrate:fresh` ; `safe-test.sh --phpunit "Coupon|Offer|Loyalty|Promo|Wheel|Notification|Subscriber|Push"`.
- Filet : `git branch backup/pre-onb09-2026-08-26` + `mysqldump foodking_e2e coupons offers offer_items push_notifications subscribers` + `SELECT COUNT(*)` de la file `notifications` (Redis : `Queue::size('notifications')`).

## §0.2 — Périmètre : DANS / HORS / voisins
| DANS | Fichiers POSSÉDÉS |
|---|---|
| S1 Promotions, codes, offres | `resources/js/components/admin/{coupons,offers}/**`, `app/Http/Controllers/Admin/{CouponController,OfferController,OfferItemController}.php`, `app/Http/Requests/{CouponRequest,OfferRequest,OfferItemRequest}.php`, `app/Services/{CouponService,OfferService,OfferItemService}.php`, `config/features.php` (clé `offers_enabled` — exposition), `app/Services/Promo/PromoCodeGenerator.php` |
| S2 Fidélité | `settings/LoyaltySetup/**`, `LoyaltySetupController.php`, `LoyaltySetupRequest.php`, `app/Services/{LoyaltyService,LoyaltySetupService}.php`, `app/Services/Loyalty/{LoyaltyRules,LoyaltyQrSigner,PosCustomerLookupService,PosLoyaltyAttachService,PosManualCreditService}.php` (lecture pour `PosRedemption*` = CAISSE), `config/loyalty.php` |
| S3 Communication | `admin/{pushNotification,messages,subscribers}/**`, `PushNotificationController`, `MessageController`, `SubscriberController`, `app/Services/{PushNotificationService,FcmNotificationService,NotificationService,NotificationAlertService}.php`, `SendFcmNotificationJob` |
| S4 Ticket promo & roue | `admin/promo/{PromoFlyerComponent,PromoFlyerQuickModal,PromoFlyerSettingsComponent}.vue` (**CAISSE selon SYSTEM_MAP §2** : lecture + coordination), `app/Services/Promo/{PromoFlyerService,PromoFlyerEscPosRenderer}.php`, `Wheel/{WheelAccess,WheelCounter,WheelPrize,WheelSettings,WheelUnlock}Controller.php`, `app/Services/Wheel/**`, `config/wheel.php`, vues Blade roue |

| HORS | Porté par |
|---|---|
| `app/Services/Pricing/PricingService.php`, `DiscountCalculator` (gelé — le coupon au commit **s'y joue** : LOCK G-PRIX-COUPON) | jamais sans LOCK |
| `PosLoyaltyController`, `PosRedemptionService`, `PosCartRedemption` (dépense de points en caisse) | voie CAISSE — lecture |
| Worker, file `notifications`, `config/queue.php`, sondes de santé | ONB-10 (G-NOTIF partagé) |
| Visibilité Coupons/Offres, mécanisme de réglage typé pour les 3 drapeaux | ONB-05 |
| Site public de la roue (`/Users/1millnonstop/Downloads/lecayenne-web-deploy/…`) | hors dépôt — lecture |
| Vocabulaire global | ONB-11 |

Zones à coordonner : `routes/api.php`, `fr.json`, `DatabaseSeeder.php` (valeurs par défaut fidélité).

## §0.3 — Drapeaux d'expansion
SCOPE-1 gelé (`PricingService`, `DiscountCalculator`, `PaymentComponent.vue`) · SCOPE-2 3 boucles · SCOPE-3 migration non prévue · SCOPE-4 **NF525** : une remise est un élément de prix — toute règle de remise vit dans le backend, figée dans `composition_snapshot`/devis ; aucun env flag de bypass · SCOPE-5 autre voie.

## §0.4 — Pipeline
`ultra-audit-profond` · `test-e2e` · `verify-before-report` · TDD · `systematic-debugging`. Non redécrit.

## §0.5 — Convergence et critères chiffrés
Rejets Axe 6 · **deux cycles consécutifs P0+P1 = 0 aux constats identiques**.

| # | Critère | Mesure | Seuil |
|---|---|---|---|
| C1 | Créer une promo sans développeur | -10 % sur une catégorie le mardi, code `BIENVENUE` borne, offre du jour : créés depuis le Dashboard, actifs par réglage (pas par `.env`) | **3/3** |
| C2 | Devis = commit | tout coupon/offre accepté au devis est accepté au commit avec le **même** montant (ou refusé aux deux) — 12 cas (expiré, plafond, cumul, minimum, canal, filiale) | **12/12** |
| C3 | Fidélité lisible et réglée | règles (points/€, plafond, expiration) réglables ; simulation « 25 € → N points » affichée ; effets caisse/borne prouvés par test | **VRAI** |
| C4 | Notifications honnêtes | créer une notification = savoir ce qui part, quand, à qui ; file gelée nommée ; aucune notification vers une commande vieille | **VRAI** |
| C5 | Transférabilité | ticket promo et roue paramétrés par réglages (textes, lots, dépendance au site) ; désactivation propre (`wheel.enabled`) | **VRAI** |
| C6 | Prix backend | 0 remise calculée côté client (grep des composants) ; `composition_snapshot` porte la remise | **0** |

## §0.6 — Base héritée
PHPUnit 5 194 · Vitest 3 644 · gelé 0 · tests : `tests/Feature/Coupon/` (dossier), `Loyalty/`, `Promo/`, `Wheel/` + `CouponCheckNegativeTotalTest`, `CouponRequestNegativeAmountsTest`, `CouponSecurityTest`, `PublicCouponListLeakTest` (Security), `KioskLoyaltyDoubleRedeemRefusedTest`, `KioskLoyaltyLedgerAtomicTest`, `LoyaltyApiTest`, `LoyaltyScanRequiresKioskMachineTest`, `OrderCancellationLoyaltyTest`, `OffersDisabledV1SentinelTest`, `KdsNotificationFailureTest` ·
drapeaux : `pos.coupon_codes_enabled=false` (`config/pos.php:271-275`), `pos.loyalty_enabled=true` (`:233-237`), `kiosk.promo_enabled=false` (`config/kiosk.php:70`), `kiosk.loyalty_redeem_enabled=true` (`:102-106`), `features.offers_enabled=false` (`config/features.php:27`) ; interrupteurs `kiosk_promo`, `fidelite`, `wheel` (6 du 15/08) · file `notifications` ~1 490-1 511 (Redis ; table `jobs` vide) · roue : `wheel.enabled=false` (settings pilotage 13/08), specs site public 34/34 et 87/87 verts (23/08).

## §0.7 — Contradictions tranchées
- **C-CONST** (index) : G0.
- **C-OFFRES** — `OffersDisabledV1SentinelTest` **verrouille** `features.offers_enabled=false` : une décision V1 (offres désactivées) testée. Le mandat 2026-08-26 demande que le commerçant puisse activer. Tranché : la sentinelle devient « offres désactivées **par défaut** à l'installation, activables par réglage » — modification **avec gate G-OFFRES**, jamais silencieuse.
- **C-COUPON-COMMIT** — 15/08 V5 : « coupon accepté au devis puis refusé au commit — différé, touche la tarification SSOT ». Tranché : ce GOAL **caractérise** (test rouge qui reproduit) puis propose un correctif sous **LOCK G-PRIX-COUPON** ; pas de correctif sauvage.
- **C-NOTIF** — créer une notification push met un job dans une file que personne n'écoute (BRAIN 25/08 : 1 490 jobs, worker non rebranché volontairement). Tranché : l'écran doit **dire la vérité** (« en pause ») tant que G-NOTIF n'est pas tranché ; aucune purge ici.
- **C-ROUE** — la roue dépend d'un site public hors dépôt et d'un cycle « GOAL-WHEEL » parqué à un gate UX (23/08). Tranché : ce GOAL ne touche pas à l'UX de la roue ; il vérifie sa **transférabilité** (réglages, textes) et sa désactivation propre.

## §0.8 — Le commerçant-type et ses questions
Nadia : 1. « -10 % sur les menus le mardi, je fais comment ? » 2. « Un code BIENVENUE sur la borne ? » 3. « Mes points fidélité, c'est combien par euro, et je peux changer ? »
4. « Si je crée une notification, elle part quand, à qui ? » 5. « La roue, c'est pour moi aussi ou c'est Le Cayenne ? »

# §1 — CARTE DU SYSTÈME (ancrages vérifiés)

| Sous-système | Maturité | Ancrage réel | Tests |
|---|---|---|---|
| S1 Promos | **CACHÉES + DÉSACTIVÉES** | `CouponController` (`routes/api.php:833-842`), `OfferController`, `OfferItemController` (`:844-856`) · `app/Services/{CouponService,OfferService,OfferItemService}.php` · `app/Services/Promo/PromoCodeGenerator.php` · `admin/{coupons,offers}/**` · `v1-hidden-modules.js:12-13` · drapeaux ci-dessus | `Coupon/`, `CouponCheckNegativeTotalTest`, `CouponRequestNegativeAmountsTest`, `CouponSecurityTest`, `PublicCouponListLeakTest`, `OffersDisabledV1SentinelTest` |
| S2 Fidélité | **VISIBLE (dé-cachée 08-10), RÈGLES EN CONFIG** | `LoyaltySetupController` (`api.php:487-490`), `LoyaltySetupRequest`, `app/Services/{LoyaltyService,LoyaltySetupService}.php`, `app/Services/Loyalty/*` (9 fichiers), `config/loyalty.php:31-45` (QR secret/TTL/leeway), `:68` (`accept_legacy_plaintext`), `:98` (`min_secret_length` 32), `:119` (`orphan_redeem_reap_minutes` 30) | `Loyalty/`, `LoyaltyApiTest`, `KioskLoyalty*Test`, `OrderCancellationLoyaltyTest`, `LoyaltyScanRequiresKioskMachineTest` |
| S3 Communication | **FILE ORPHELINE** | `PushNotificationController` (`api.php:1436-1442`), `MessageController` (`:1532-1540`, pas d'`update`), `SubscriberController` (`:677-682`) · `app/Services/{PushNotificationService,FcmNotificationService,NotificationService,NotificationAlertService}.php` · `SendFcmNotificationJob` · `config/queue.php` | `KdsNotificationFailureTest`, `PushNotificationTenantIsolationTest`, `MessageIdorTest` (cités 13/08) |
| S4 Ticket promo & roue | **FLUX LE CAYENNE** | `PromoFlyerController` (`api.php:1306-1328`, sans FormRequest), `app/Services/Promo/{PromoFlyerService,PromoFlyerEscPosRenderer}.php`, `admin/promo/*` (CAISSE) · `Wheel/*Controller` (`routes/web.php:161-231`, `api.php:945,961`), `app/Services/Wheel/{WheelClaimService,WheelDeliveryService}.php`, `config/wheel.php`, interrupteur `wheel.enabled` | `Promo/`, `Wheel/` |

**Sortie d'ancrage brute** : `ls app/Services | grep -i "loyalty\|coupon\|offer\|wheel\|promo\|push\|notification"` → 20 entrées · `ls app/Services/Loyalty` → 9 · `Promo` → 3 · `Wheel` → 3 · `ls tests/Feature | grep -i "coupon\|offer\|loyalty\|wheel\|promo\|push\|notification"` → 4 dossiers + 10 fichiers · `grep -n "=>" config/loyalty.php | head` → `:31-45,68,84,98,119` · `SELECT queue, COUNT(*) FROM jobs` → vide (file en Redis).

# §2 — ÉTAT CONNU LE 2026-08-26 (non audité en direct — W1 rejoue `recon/_ZONES.md` § Z6)
**Connu vert** : montants négatifs de coupon refusés ; fuite de liste publique de coupons fermée ; double dépense de points refusée, grand livre atomique ; annulation de commande restitue les points ; scan fidélité exige une borne ; offres désactivées (sentinelle) ; isolation par filiale des push.
**Connu à risque** : Coupons/Offres cachés + désactivés ; coupon devis ≠ commit (15/08) ; file `notifications` orpheline ; `PromoFlyerController`, `Wheel/*` sans FormRequest (→ ONB-13) ; règles de fidélité en `config/loyalty.php` (secret QR `.env`, TTL) ; roue dépendante du site public.
**À mesurer W1 (brief Z6)** : création coupon/offre par URL (pages cachées), application au **devis** borne/POS, effet réel de « créer une notification » (file avant/après), fidélité (règles, simulation), roue (réglages transférables), bords (expiré, dupliqué, négatif, deux onglets).

# §3 — SOUS-SYSTÈME 1 : PROMOTIONS, CODES, OFFRES

## Sub 1.1 — Activer sans développeur, sans casser le prix
**Ancrages** : drapeaux (`config/pos.php:271-275`, `config/kiosk.php:70`, `config/features.php:27`), `OffersDisabledV1SentinelTest`, `CouponService.php`, `OfferService.php`, `CouponRequest`, `OfferRequest`.
**Tâches**
- **T-1.1.1** — Caractérisation ROUGE : créer coupon `GOAL-ONB09 BIENVENUE` (-10 %, min 15 €, borne+caisse, valide mardi) et offre du jour ; devis borne et devis POS avec et sans le coupon ; 12 cas (expiré, plafond d'usage, cumul coupon+offre, minimum non atteint, canal exclu, filiale, montant fixe > total (`CouponCheckNegativeTotalTest`), code inconnu, code en minuscules, deux onglets) → consigner devis **et** commit (le commit se prouve par le test PHP, jamais par une vraie commande).
  • test : (À CRÉER à `tests/Feature/Coupon/CouponQuoteEqualsCommitTest.php`) · C2
- **T-1.1.2** — Si devis ≠ commit reproduit : analyse de cause (probablement `PricingService`/`DiscountCalculator` vs `CouponService::check`) → **LOCK G-PRIX-COUPON** ; correctif sous LOCK avec tests de caractérisation des prix actuels (59 articles, devis identiques avant/après).
- **T-1.1.3** — Les trois drapeaux deviennent des réglages typés (déclarés via ONB-05 : `pos.coupon_codes_enabled`, `kiosk.promo_enabled`, `features.offers_enabled`), défaut **désactivé** à l'installation ; `OffersDisabledV1SentinelTest` reformulée « défaut désactivé, activable » — gate **G-OFFRES**.
  • test : sentinelle modifiée + (À CRÉER à `tests/Feature/Offer/OffersEnabledBySettingTest.php`)
- **T-1.1.4** — Écrans Coupons/Offres (cachés → dé-cachés via ONB-05) : parcours « -10 % le mardi » (planification hebdomadaire existe-t-elle ? sinon proposer `days_of_week`, G-DATA), codes générés (`PromoCodeGenerator`), aperçu « ce que verra le client ».
  • test : (À CRÉER à `tests/js/couponOfferCreateFlow.spec.js`) · visuel : `http://127.0.0.1:8809/admin/coupons`, `/admin/offers` à 3 gabarits
**Acceptation** : C1 = 3/3 · C2 = 12/12 · C6 = 0 · 3 tests VERTS · questions 1, 2 de Nadia = OUI.

# §4 — SOUS-SYSTÈME 2 : FIDÉLITÉ LISIBLE ET RÉGLÉE

**Ancrages** : `LoyaltySetupController`, `LoyaltySetupRequest`, `LoyaltyRules.php`, `LoyaltyService.php`, `config/loyalty.php`, `settings/LoyaltySetup/**`, `KioskLoyalty*Test`, `OrderCancellationLoyaltyTest`.
**Tâches**
- **T-2.1.1** — Cartographier les règles réelles (points par euro, arrondi, plafond, expiration, exclusions, dépense min/max, QR TTL 300 s) : lesquelles sont dans `settings` (écran) vs `config/loyalty.php` vs code (`LoyaltyRules`).
  • test : (À CRÉER à `tests/Feature/Loyalty/LoyaltyRulesInventorySentinelTest.php`)
- **T-2.1.2** — Écran Fidélité : règles éditables (celles éligibles ; le secret QR reste `.env`), simulateur « 25 € → N points, N points → X € », validation (négatif, > 100 %), effet immédiat prouvé par test sur le calcul (`LoyaltyService`).
  • test : (À CRÉER à `tests/Feature/Loyalty/LoyaltySetupEffectOnCalculationTest.php`) · visuel : `/admin/settings/loyalty-setup`
  • au-delà : changer la règle avec des points déjà acquis (pas rétroactif — prouvé) ; annulation après dépense (`OrderCancellationLoyaltyTest`) ; double dépense (`KioskLoyaltyDoubleRedeemRefusedTest`).
- **T-2.1.3** — `config/loyalty.php:68 accept_legacy_plaintext` : état, risque, retrait proposé (fiche ONB-13).
**Acceptation** : C3 · 2 tests VERTS · question 3 = OUI.

# §5 — SOUS-SYSTÈME 3 : COMMUNICATION HONNÊTE

**Ancrages** : `PushNotificationController`, `PushNotificationService`, `FcmNotificationService`, `SendFcmNotificationJob`, `SubscriberController`, `MessageController`, file `notifications` (Redis), `NotificationAlertService`.
**Tâches**
- **T-3.1.1** — Mesurer : `Queue::size('notifications')` avant/après « créer une notification » ; cibles (abonnés ? clients ? appareils ?) ; ce que verrait l'utilisateur si un worker tournait (payload).
  • test : (À CRÉER à `tests/Feature/Notification/PushNotificationCreatesQueuedJobTest.php`)
- **T-3.1.2** — Écran Notifications : état de la file (« en pause depuis le 25/08 — N messages en attente »), cible explicite, aperçu, **aucune** promesse d'envoi tant que G-NOTIF n'est pas tranché ; historique.
  • test : (À CRÉER à `tests/js/pushNotificationHonestState.spec.js`)
- **T-3.1.3** — Abonnés et messages : consentement, export (RGPD minimal : désinscription), `MessageController` sans `update` (cohérent : `MessageControllerNoDeadUpdateRouteTest`).
- **T-3.1.4** — Proposition G-NOTIF (avec ONB-10) : purge des jobs liés à des commandes > 30 jours après export, worker dédié `--queue=notifications` uniquement pour des envois **nouveaux**.
**Acceptation** : C4 · 2 tests VERTS · question 4 = OUI · G-NOTIF documenté.

# §6 — SOUS-SYSTÈME 4 : TICKET PROMO ET ROUE TRANSFÉRABLES

**Tâches**
- **T-4.1.1** — Ticket promo (CAISSE : lecture + coordination) : inventaire des réglages (`PromoFlyerSettingsComponent`), textes en dur « Cayenne », validation absente (`PromoFlyerController` sans FormRequest → ONB-13) ; ce qui doit devenir réglage.
- **T-4.1.2** — Roue : réglages (`config/wheel.php`, `WheelSettingsController`, `admin.wheel.settings`), lots, textes, dépendance au site public ; désactivation propre par `wheel.enabled` (prouver : borne, caisse, site) ; « à qui appartient la roue » pour un autre établissement.
  • test : (À CRÉER à `tests/Feature/Wheel/WheelDisabledIsInvisibleEverywhereTest.php`)
- **T-4.1.3** — Rien de l'UX de la roue n'est modifié (gate UX du 23/08 parqué) ; fiches ONB-12 pour les textes.
**Acceptation** : C5 · test VERT · question 5 = OUI.

# §S — SCÉNARIOS ADVERSES OBLIGATOIRES
| Fonction \ scénario | annulation | rechargement | double soumission | deux onglets | rôle inférieur | données vides | volume | réseau/worker coupé | effet devis / caisse / borne | retour arrière | valeurs limites |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Coupon | `couponOfferCreateFlow.spec.js` | idem | code dupliqué 422 | dernier gagne | `coupons_*` 403 | code vide | 500 coupons | — | `CouponQuoteEqualsCommitTest` | désactiver → devis refuse | négatif (`CouponRequestNegativeAmountsTest`), > total, expiré, minuscules, unicode |
| Offre | idem | idem | idem | idem | `offers_*` | offre sans article | 50 | — | devis borne/POS | désactiver | 0 %, 100 %, cumul |
| Fidélité | — | — | — | — | `settings` | règle vide → défaut | — | — | `LoyaltySetupEffectOnCalculationTest` | ancienne règle ne réécrit pas les points | négatif, > 100 %, TTL 0 |
| Notification | annuler → 0 job | — | idempotent | — | `push-notifications_*` | cible vide → 422 | 1 500 en file | file en pause → état honnête | — | supprimer une notification en file ? | titre 190, emoji |
| Roue | — | — | — | — | `wheel.access` | lots vides | — | site public coupé | `wheel.enabled=false` partout | réactiver | lot à 0 % |

# §A — ARMÉE D'AGENTS
Architecte (frontière devis/commit, réglages vs drapeaux) · **Sécurité** (coupons publics, secret QR, `accept_legacy_plaintext`, IDOR abonnés) · UX/A11y (écrans coupons/offres/fidélité) ·
**Psychologie commerçant** (une promo = une phrase de conséquence + un aperçu client ; peur de « casser le prix ») · DBA (usages de coupons, grand livre fidélité) · SRE (file Redis, worker) · Implémenteur unique · ROUGE (rejoue le brief Z6 et les 12 cas de devis après chaque vague) · QA visuel + ROUGE visuel · **Jalonneur**.
Disque `reports/test-e2e/ONB09_ANIMATION_COMMERCIALE/<round>/wave-<W>-<rôle>.json` ; contrat de constat ; ~1 200-1 500 mots.

# §X — VAGUES DE CONVERGENCE
| Vague | Portée | Parallélisme | Bloquée par |
|---|---|---|---|
| **W0** | Pré-vol, filet, bases (`Queue::size`), coupon/offre de test | séquentiel | — |
| **W1** | **Reconnaissance Z6** (brief) ; caractérisation devis/commit (T-1.1.1) ; inventaire fidélité (T-2.1.1) ; mesure file (T-3.1.1) | fan-out lecture seule | — |
| **W2** | S1 promos : réglages typés, écrans, `OffersDisabled` reformulée (T-1.1.3, T-1.1.4) | séquentiel | G-OFFRES ; ONB-05 (dé-cachage, réglages) |
| **W3** | S1.2 correctif devis/commit **sous LOCK** (T-1.1.2) | **seul** sur la zone pricing | **G-PRIX-COUPON** |
| **W4** | S2 fidélité (T-2.*) | séquentiel | — |
| **W5** | S3 communication (T-3.*) + S4 (T-4.*) | séquentiel | G-NOTIF (proposition seulement) |
| **W6** | Convergence : deux cycles, `safe-test.sh --phpunit "Coupon|Offer|Loyalty|Promo|Wheel|Notification"`, Vitest, Playwright `tests/e2e/onb09-*.spec.js`, BRAIN | séquentiel | — |
**§X.8** 6 points · **§X.9** STOP/`STUCK_*`/4 options · **§X.10** `wip`/`INTERRUPT_*`/BRAIN.

# §G — GATES PROPRIÉTAIRE
| Gate | Description | QUI | QUOI | OÙ | Statut |
|---|---|---|---|---|---|
| **G0** | Amendement constitutionnel (index) | Propriétaire | ligne | `CONSTITUTION.md` | EN ATTENTE — ne bloque pas |
| **G-OFFRES** | Offres/coupons/promo borne : désactivés par défaut, **activables par réglage** (reformulation de `OffersDisabledV1SentinelTest`) | Propriétaire | accord | MISSION §6 + `GATE_LOG.md` | EN ATTENTE — bloque W2 |
| **G-PRIX-COUPON** | LOCK sur `PricingService`/`DiscountCalculator` si devis ≠ commit reproduit | Propriétaire | `LOCK_ONB09_COUPON_*.md` contresigné | `docs/gates/` | EN ATTENTE — bloque W3 |
| **G-NOTIF** | Sort de la file `notifications` (partagé ONB-10) | Propriétaire | décision | `MISSION_ONB10` §6 + ici | EN ATTENTE — bloque T-3.1.4 |
| **G-DATA** | `coupons.days_of_week` (planification hebdomadaire) si absente | Propriétaire | accord | `GATE_LOG.md` | EN ATTENTE — bloque « le mardi » |
| **G-CACHE** | Dé-cacher Coupons/Offres (exécuté par ONB-05) | Propriétaire | tableau | `MISSION_ONB05` §6 | EN ATTENTE |

# §R — RÉFÉRENCES
`ultra-audit-profond` · `test-e2e` · `verify-before-report` · `lock-plan` · `CLAUDE.md §8` (pricing SSOT), `§9` · `SYSTEM_MAP.md §2` (promo = CAISSE), `§5-6` · `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` · `_FICHES_GOAL.md` (ONB-09) · `recon/_ZONES.md` (Z6) · `recon/Z0_carte_dashboard.md §1-2` ·
`PROJECT_BRAIN.md §2` (file `notifications`), `§3` (15/08 V5 : coupon devis/commit différé) · `plans/GOAL_ROUE_UX_IDENTITE_2026-08-13.md` · `plans/PLAN_GOAL-WHEEL-EXPERIENCE-20260823_2026-08-23.md` · `docs/gates/GATE_WHEEL_EXPERIENCE_UX_SIGNOFF_2026-08-23.md` · mémoire `ticket_promo_plateformes_2026-08-07`, `roue_*`, `fidelite_*`.

# §F — RÈGLE FINALE
TERMINÉ quand et seulement quand : 1. 6 vagues closes ; 2. C1..C6 VRAIS ; 3. PHPUnit ≥ 5 194 + ≥ 9 tests créés VERTS, Vitest ≥ 3 644 ; 4. diff gelé 0 hors LOCK contresigné ; 5. NF525 ajout seul, 0 remise côté client ; 6. gates tranchés ; 7. BRAIN vrai ; 8. deux cycles identiques ; 9. fiches de renvoi (ONB-05, ONB-10 G-NOTIF, ONB-13 FormRequests promo/roue + `accept_legacy_plaintext`, ONB-12 textes Cayenne, ONB-11 vocabulaire).
**Interdit** : créer une commande pour prouver un coupon · lancer un worker sur `notifications` · toucher `PricingService` sans LOCK · modifier l'UX de la roue · approuver un gate.
> Le sens : Nadia crée « -10 % le mardi » un lundi soir, voit ce que verra le client, et mardi midi la borne, la caisse et le ticket disent le même prix.
