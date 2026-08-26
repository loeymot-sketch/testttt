# MISSION ONB-09 — ANIMATION COMMERCIALE · Rapport de mission
- GOAL : `plans/GOAL_ONB09_ANIMATION_COMMERCIALE_2026-08-26.md` · Index : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md`
- État des lieux daté du **2026-08-26** (HEAD `43b120c7d`, `:8766`, base `foodking_e2e`) — **zone NON auditée en direct** : la reconnaissance est la W1.
- Port : **8809** · Voie : CENTRAL « animation commerciale » (ticket promo = CAISSE, lecture) · Parallèle avec : 01, 02, 06, 07, 08, 10 ; **dépend de ONB-05** pour dé-cacher Coupons/Offres et pour les réglages typés

## 0. COMMENT LANCER
```
Tu es le chef de mission du GOAL ONB-09 (animation commerciale). Lis : CONSTITUTION.md, PROJECT_BRAIN.md §2 (file notifications) et §3 (15/08 : coupon devis/commit
différé), SYSTEM_MAP.md (§2 : promo = CAISSE), PARALLEL_PROTOCOL.md, plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md (§2, §3, §5),
reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB09_ANIMATION_COMMERCIALE.md, plans/GOAL_ONB09_ANIMATION_COMMERCIALE_2026-08-26.md, puis
recon/_BRIEF_COMMUN.md et la section Z6 (+ RÉSILIENCE) de recon/_ZONES.md, recon/Z0_carte_dashboard.md (§1-2). Pré-vol §0.1 : worktree
.claude/worktrees/onb09-animation depuis HEAD, APP_URL=http://127.0.0.1:8809, .env.testing, liens durs, serveur 8809, PLAYWRIGHT_BASE_URL, filet backup/pre-onb09
+ dump coupons/offers/offer_items/push_notifications/subscribers + Queue::size('notifications'). ⛔ Aucune commande créée (un coupon se prouve au DEVIS et par test PHP),
aucun worker lancé sur notifications, aucun push/mail/SMS réel, PricingService sous LOCK seulement. Puis « lance le GOAL » : W0 → W1 = brief Z6 + caractérisation
devis/commit (12 cas) + inventaire fidélité + mesure de la file → W2..W6. Pipeline ultra-audit-profond, spécialistes lecture seule en un message (Sécurité en tête),
implémenteur unique, ROUGE avant tout « fini », Jalonneur, matrice §S, deux cycles identiques. Fiches de renvoi §8 (ONB-05, 10, 13, 12, 11). Jamais de push.
Gates §G : proposer. Compte rendu : FIXÉ / VÉRIFIÉ / BLOQUÉ.
```

## 1. CONTEXTE ET VISION
Un commerçant qui ne peut pas faire « -10 % le mardi » sans développeur n'a pas le contrôle. Aujourd'hui les promos sont cachées et désactivées par des fichiers, un coupon peut être
accepté au devis puis refusé au commit (constat du 15/08 différé car il touche le prix), et « créer une notification » alimente une file que personne n'écoute. Ce GOAL rend l'animation
commerciale possible **sans jamais** sortir le prix du backend. Persona Nadia (mardi -10 %, code BIENVENUE, points, abonnés).

## 2. ÉTAT CONNU LE 2026-08-26 (code + historique ; **aucune mesure écran** — W1)
**2.1 Surfaces** : `/admin/coupons`, `/admin/offers` (cachés, `v1-hidden-modules.js:12-13`), `/admin/push-notifications`, `/admin/messages`, `/admin/subscribers`, `/admin/settings/loyalty-setup` (visible), `/admin/promo-flyer` + `/settings` (CAISSE), roue Blade `/admin/roue*` (`routes/web.php:161-231`).
**2.2 Connu vert (tests)** : `CouponCheckNegativeTotalTest`, `CouponRequestNegativeAmountsTest`, `CouponSecurityTest`, `PublicCouponListLeakTest`, `KioskLoyaltyDoubleRedeemRefusedTest`, `KioskLoyaltyLedgerAtomicTest`, `LoyaltyApiTest`, `LoyaltyScanRequiresKioskMachineTest`, `OrderCancellationLoyaltyTest`, `OffersDisabledV1SentinelTest`, `KdsNotificationFailureTest` ; dossiers `Coupon/`, `Loyalty/`, `Promo/`, `Wheel/` ; roue : specs du site public 34/34 et 87/87 (23/08).
**2.3 Constats connus (à reproduire W1)**
| Sév. attendue | Constat | Source |
|---|---|---|
| P1 (prix) | Coupon accepté au devis puis refusé au commit — différé le 15/08 (« touche la tarification SSOT NF525 ») | `PROJECT_BRAIN.md §3` (V5) |
| P1 | Promos cachées ET désactivées par 3 drapeaux de fichier : `pos.coupon_codes_enabled=false` (`config/pos.php:271-275`), `kiosk.promo_enabled=false` (`config/kiosk.php:70`), `features.offers_enabled=false` (`config/features.php:27`, verrouillé par `OffersDisabledV1SentinelTest`) | code |
| P1 | « Créer une notification » → job dans la file `notifications` que personne n'écoute (~1 490-1 511 en Redis ; worker volontairement non rebranché le 25/08) | BRAIN 25/08, Z7 `/api/health` |
| P2 | `PromoFlyerController`, `Wheel/*` sans FormRequest | Z0 §6 (→ ONB-13) |
| P2 | Règles de fidélité partiellement en `config/loyalty.php` (`accept_legacy_plaintext :68`, TTL, secret) — non éditables | code |
| P2 | Roue dépendante du site public Le Cayenne ; cycle UX parqué à un gate (23/08) | BRAIN, `docs/gates/GATE_WHEEL_EXPERIENCE_UX_SIGNOFF_2026-08-23.md` |
**2.4 Angles morts attendus** : planification hebdomadaire d'une promo (existe-t-elle ?), aperçu client, cible réelle des notifications, « à qui appartient la roue ».
**2.5 Cayenne** : textes du ticket promo et de la roue (à inventorier W1).

## 3. CE QUI A DÉJÀ ÉTÉ FAIT
- 2026-08-07 ticket promo nominatif (mémoire `ticket_promo_plateformes`) ; 2026-08-09/12/13 roue (≥ 6 sessions, `GOAL_ROUE_UX_IDENTITE`, déploiement fidélité/roue 12/08) ; 2026-08-10 `settings.loyalty-setup` dé-caché ; 2026-08-15 V5 : coupon devis/commit **délibérément différé** ; 2026-08-23 gate UX roue parqué ; 2026-08-25 file `notifications` documentée, sondes honnêtes.
- Interrupteurs existants : `wheel.enabled`, `kiosk.promo_enabled`, `pos.loyalty_enabled` (booléens, `InterrupteurService::CATALOGUE`).

## 4. ANCRAGES CODE
| Rôle | Fichier | Lignes | Note |
|---|---|---|---|
| Coupons | `CouponController` (`routes/api.php:833-842`) · `CouponRequest` · `app/Services/CouponService.php` · `app/Services/Promo/PromoCodeGenerator.php` · `admin/coupons/*` | | caché |
| Offres | `OfferController`, `OfferItemController` (`:844-856`) · `OfferRequest`, `OfferItemRequest` · `app/Services/{OfferService,OfferItemService}.php` · `admin/offers/*` · `config/features.php:27` · `tests/Feature/OffersDisabledV1SentinelTest.php` | | caché + verrouillé |
| Drapeaux | `config/pos.php:271-275` (`coupon_codes_enabled`), `:233-237` (`loyalty_enabled`) · `config/kiosk.php:70` (`promo_enabled`), `:102-106` (`loyalty_redeem_enabled`) | | réglages typés → ONB-05 |
| Fidélité | `LoyaltySetupController` (`:487-490`) · `LoyaltySetupRequest` · `app/Services/{LoyaltyService,LoyaltySetupService}.php` · `app/Services/Loyalty/{LoyaltyRules,LoyaltyQrSigner,LoyaltyQrInvalidException,PosCustomerLookupService,PosLoyaltyAttachService,PosManualCreditService,PosRedemptionService,PosCartRedemption,PosRedemptionException}.php` · `config/loyalty.php:31-45,68,84,98,119` · `settings/LoyaltySetup/*` | `PosRedemption*` = CAISSE | |
| Notifications | `PushNotificationController` (`:1436-1442`) · `PushNotificationRequest` · `app/Services/{PushNotificationService,FcmNotificationService,NotificationService,NotificationAlertService}.php` · `SendFcmNotificationJob` · `MessageController` (`:1532-1540`) · `SubscriberController` (`:677-682`) · `config/queue.php` | file Redis | G-NOTIF |
| Ticket promo (CAISSE) | `PromoFlyerController` (`:1306-1328`) · `app/Services/Promo/{PromoFlyerService,PromoFlyerEscPosRenderer}.php` · `admin/promo/{PromoFlyerComponent,PromoFlyerQuickModal,PromoFlyerSettingsComponent}.vue` | lecture | sans FormRequest |
| Roue | `Wheel/{WheelAccess,WheelCounter,WheelPrize,WheelSettings,WheelUnlock}Controller.php` (`routes/web.php:161-231`, `api.php:945,961`) · `app/Services/Wheel/{WheelClaimService,WheelDeliveryService,WheelException}.php` · `config/wheel.php` | | sans FormRequest |
| Pricing (gelé) | `app/Services/Pricing/PricingService.php` (+ `DiscountCalculator`) | LOCK | G-PRIX-COUPON |

## 5. BASES CHIFFRÉES
`safe-test.sh --phpunit "Coupon|Offer|Loyalty|Promo|Wheel|Notification|Subscriber"` → figer W0 · `Queue::size('notifications')` (Redis) → figer W0 · `coupons`, `offers`, `subscribers` (compter W0).

## 6. DÉCISIONS PROPRIÉTAIRE EN ATTENTE
| Gate | Question | Recommandation | Si non tranché |
|---|---|---|---|
| G-OFFRES | Promos désactivées par défaut mais activables par réglage (reformuler la sentinelle) | oui | promos = développeur |
| G-PRIX-COUPON | LOCK `PricingService`/`DiscountCalculator` si devis ≠ commit reproduit | oui, après caractérisation | défaut documenté, non corrigé |
| G-NOTIF (avec ONB-10) | File `notifications` : purge des anciens (> 30 j, après export) + worker pour les nouveaux | oui | écran « en pause » |
| G-DATA | `coupons.days_of_week` si la planification hebdomadaire n'existe pas | oui | « le mardi » impossible |
| G-CACHE (ONB-05) | Dé-cacher Coupons/Offres | oui | pages par URL |

## 7. RISQUES, PIÈGES, INSTRUMENTS
- **Ne jamais créer de commande** pour prouver une remise : le devis (`quote`) et les tests PHP suffisent ; une commande de test entre dans la chaîne NF525.
- La file `notifications` est en **Redis** : `SELECT … FROM jobs` est vide et ne prouve rien ; utiliser `Queue::size('notifications')`.
- `OffersDisabledV1SentinelTest` est une décision testée : ne pas la « corriger » sans G-OFFRES.
- Le ticket promo appartient à la voie CAISSE : lecture + fiches, pas d'édition.
- La roue a un gate UX ouvert : ne pas modifier son UX.
- `:8000` = autre worktree ; ta session = **:8809**.

## 8. JOURNAL DE MISSION (rempli par la session)
| Date/heure | Vague | Tâche | Action | Preuve | Verdict | Commit |
|---|---|---|---|---|---|---|
| | W0 | | | | | |

Fiches de renvoi : ONB-05 (dé-cacher, réglages typés des 3 drapeaux) · ONB-10 (G-NOTIF, worker) · ONB-13 (FormRequests `PromoFlyer`/`Wheel`, `accept_legacy_plaintext`, secret QR) · ONB-12 (textes Cayenne roue/ticket) · ONB-11 (vocabulaire « offre »/« coupon »/« promo ») · voie CAISSE (ticket promo) · État final : —
