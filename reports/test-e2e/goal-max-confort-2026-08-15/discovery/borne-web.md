# RECONNAISSANCE — BORNE (kiosk) + WEB CLIENT
**Date** 2026-08-15 · **HEAD** `e2d2ca3b4` · **Branche** `pos/category-first-caisse-2026-06-23`
**Mode** READ-ONLY — aucun fichier modifié.
**Angle** « un client qui n'a jamais vu l'écran doit commander sans hésiter et sans se tromper. »

---

## 0. FAIT STRUCTURANT — le « WEB CLIENT » de CE backend est éteint

Avant tout inventaire, un fait qui redéfinit la moitié du périmètre, prouvé :

- `resources/js/router/modules/frontendRoutes.js:22-25` — `/home`, `/menu`, `/offers`, `/offers/:slug`
  sont des **redirections vers `/login`**. Les composants vitrine ont été supprimés
  (bandeau `[STOREFRONT-DELETE 2026-06-25]`, lignes 1-8).
- `config/features.php:50` — `staff_only_mode` défaut **true**. `.env:73` — `STAFF_ONLY_MODE=true`.
- `resources/js/router/index.js:249-253` — toute route `meta.isFrontend === true` hors allowlist
  est redirigée vers `admin.dashboard` (connecté) ou `auth.login` (anonyme).
- `resources/js/components/DefaultComponent.vue:5-10` — panier, nav mobile, compte mobile,
  cookies et footer vitrine sont tous conditionnés `v-if="!staffOnlyMode"`.

⇒ **Il n'existe aucun parcours de commande web client servi par ce backend.** La seule surface
client réelle est la **BORNE** (`/kiosk/*`, exemptée du gate — `router/index.js:249`).
Le site public réellement déployé vit dans un autre dépôt (hors périmètre).

**Conséquence pour le BLOC 3** : la parité borne↔web ne peut PAS être auditée sur du code
vivant ici — il n'y a plus de second vendeur. Voir BLOC 3 pour ce qui reste prouvable.

---

## BLOC 1 — INVENTAIRE

### BORNE — `/kiosk/*` (surface client réelle)

Source des routes : `resources/js/router/modules/kioskRoutes.js:137-301`.

| # | Cat. | Fonction | Route | Fichier |
|---|------|----------|-------|---------|
| 1 | **BASE** | Écran d'attente / accueil + choix Sur place / À emporter | `/kiosk/idle` | `kioskRoutes.js:157-161` → `KioskIdleScreenComponent.vue:190-253` |
| 2 | **BASE** | Parcourir la carte (sidebar catégories + grille produits) | `/kiosk/categories` | `kioskRoutes.js:162-168` → `KioskCategoriesComponent.vue:96-245` |
| 3 | **BASE** | Composer un produit (wizard multi-étapes) | `/kiosk/wizard/:itemId` | `kioskRoutes.js:177-187` → `KioskWizardComponent.vue` **[FROZEN]** |
| 4 | **BASE** | Panier : voir, éditer, quantité, supprimer, vider | `/kiosk/cart` | `kioskRoutes.js:188-193` → `KioskCartComponent.vue:1-377` |
| 5 | **BASE** | Devis serveur (prix SSOT avant paiement) | POST `frontend/order/quote` | `store/modules/kioskCart.js:607-666` |
| 6 | **BASE** | Paiement — **Plan B : tout à la caisse** | `/kiosk/payment` | `kioskRoutes.js:208-214` → `KioskPaymentComponent.vue:5-49` |
| 7 | **BASE** | Instruction « payez à la caisse » + n° de commande + impression | `/kiosk/cash-instruction` | `kioskRoutes.js:248-266` → `KioskCashInstructionComponent.vue:1-65` |
| 8 | **BASE** | Suivi de commande (sondage 15 s) | `/kiosk/waiting/:orderId` | `kioskRoutes.js:215-222` → `KioskWaitingComponent.vue:183` (`POLL_INTERVAL_MS = 15000`) |
| 9 | **BASE** | Confirmation / récapitulatif final | `/kiosk/confirmation` | `kioskRoutes.js:223-233` → `KioskConfirmationComponent.vue` |
| 10 | SECONDAIRE | Upsell (suggestions post-panier) | `/kiosk/upsell` | `kioskRoutes.js:201-207` → `KioskUpsellComponent.vue` **[FROZEN]** |
| 11 | SECONDAIRE | Fidélité / « Mon compte » (saisie code, inscription, solde) | `/kiosk/loyalty` | `kioskRoutes.js:194-200` → `KioskLoyaltyComponent.vue` |
| 12 | SECONDAIRE | Carrousel promos (bandeau catalogue) | intégré `/kiosk/categories` | `KioskCategoriesComponent.vue:48` → `KioskPromoCarouselComponent.vue` |
| 13 | SECONDAIRE | Code promo (champ panier) | intégré `/kiosk/cart` | `KioskCartComponent.vue:281-331` — **masqué** (`kioskPromoEnabled` défaut false, `config/kiosk.php:70`) |
| 14 | SECONDAIRE | Login machine (diagnostic, pas client) | `/kiosk/login` | `kioskRoutes.js:139-145` |
| 15 | SECONDAIRE | Erreur réseau | `/kiosk/error/network` | `kioskRoutes.js:267-272` |
| 16 | SECONDAIRE | Erreur menu indisponible | `/kiosk/error/menu-unavailable` | `kioskRoutes.js:273-278` |
| 17 | SECONDAIRE | Erreur produit retiré | `/kiosk/error/product-removed` | `kioskRoutes.js:279-288` |
| 18 | SECONDAIRE | Erreur paiement refusé | `/kiosk/error/payment-refused` | `kioskRoutes.js:289-298` |
| 19 | SECONDAIRE | Overlay inactivité + reset session | overlay global | `KioskInactivityOverlayComponent.vue` monté par `KioskAppComponent.vue` **[FROZEN]** |
| 20 | SECONDAIRE | File d'attente hors-ligne (commande espèces) | transparent | `helpers/kioskOfflineQueue.js` via `kioskCart.js:766-806` |
| 21 | SECONDAIRE | Bandeau catalogue servi depuis cache | intégré `/kiosk/categories` | `KioskCategoriesComponent.vue:53-57` |
| 22 | (legacy) | Deep-link produits par catégorie → redirigé | `/kiosk/products/:categoryId` | `kioskRoutes.js:169-176` |
| 23 | (inerte) | `/kiosk/admin` → redirige vers idle | `/kiosk/admin` | `kioskRoutes.js:234-239` |

Notes de configuration vérifiées :
- `.env:74` `KIOSK_USE_POS_WIZARD=true` ⇒ la route wizard charge `KioskPosWizardComponent.vue`,
  qui est un **wrapper transparent de 18 lignes** rendant `KioskWizardComponent`
  (`KioskPosWizardComponent.vue:7,11`). Aucune divergence de comportement.
- `.env:72` `KIOSK_REQUIRE_MACHINE_LOGIN=false` ⇒ auto-login machine, le client ne voit
  jamais de formulaire (`config/kiosk.php:14`).
- Plan B actif par défaut : `config/kiosk.php:54` `payment_route_all_to_counter` défaut **true**,
  aucune surcharge `.env` ⇒ l'écran de paiement **masque** la sélection carte/espèces/TR
  (`KioskPaymentComponent.vue:6,53,81,93`) et auto-route vers l'encaissement comptoir.
  **Conforme au mandat owner, ce n'est pas un défaut.**

### WEB CLIENT — réellement atteignable

| Cat. | Fonction | Route | Fichier | État |
|------|----------|-------|---------|------|
| BASE | *(aucune)* | — | — | **Aucun parcours de commande web n'existe** |
| SECONDAIRE | Connexion | `/login` | `router/modules/authRoutes.js:12-20` | ✅ atteignable (allowlist `router/index.js:66`) |
| SECONDAIRE | Mot de passe oublié — étape 1 | `/forget-password` | `authRoutes.js:21-29` | ✅ atteignable |
| SECONDAIRE | Mot de passe oublié — étape 2 (code) | `/forget-password/verify` | `authRoutes.js:30-38` | ❌ **BLOQUÉE** (voir P1-W1) |
| SECONDAIRE | Mot de passe oublié — étape 3 | `/forget-password/reset-password` | `authRoutes.js:39-47` | ✅ allowlistée mais inatteignable (étape 2 morte) |
| SECONDAIRE | Créer un compte | `/signup`, `/signup/verify`, `/signup/register` | `authRoutes.js:48-74` | ❌ **BLOQUÉES** (voir P1-W1) |
| SECONDAIRE | Connexion invité | `/guest-login`, `/guest-login/verify` | `authRoutes.js:75-92` | ❌ **BLOQUÉES** (voir P1-W1) |
| SECONDAIRE | Pages CMS | `/page/:slug` | `frontendRoutes.js:26-34` | ❌ bloquée (isFrontend, hors allowlist) |
| SECONDAIRE | Recherche, checkout, mes commandes, adresses, chat, profil, mot de passe | `/search`, `/checkout`, `/my-orders`, `/address`, `/chat`, `/edit-profile`, `/change-password` | `frontendRoutes.js:35-106` | ❌ toutes bloquées |
| SECONDAIRE | 404 / exception | `route.notFound`, `route.exception` | allowlist `router/index.js:71-72` | ✅ |

---

## BLOC 2 — FRICTIONS DE CONFORT CLIENT

### P1 — client bloqué / perdu / erreur fréquente

---

**[P1-W1] `resources/js/router/index.js:65-73` — l'allowlist staff-only référence DEUX noms de route qui n'existent pas ⇒ 3 liens morts sur la seule page web atteignable**

- **friction** : sur `/login` (la seule page client/staff servie par ce backend), les trois liens
  proposés ne mènent nulle part. Le client tape, l'écran ne bouge pas (retour sur `/login`).
  Aucun message, aucune explication. Le plus grave : **un membre du personnel qui a oublié son
  mot de passe ne peut pas le réinitialiser.**
- **evidence** (tout vérifié par grep) :
  - allowlist déclarée : `router/index.js:66-72` = `auth.login`, `auth.signup`,
    `auth.forgetPassword`, `auth.resetPassword`, `auth.guest`, `route.notFound`, `route.exception`.
  - noms de routes RÉELS (grep exhaustif sur `resources/js/router/modules/`) :
    `auth.login`, `auth.forgetPassword`, `auth.verifyEmail`, `auth.resetPassword`,
    `auth.signupPhone`, `auth.signupVerify`, `auth.signupRegister`, `auth.guestLogin`,
    `auth.guestLoginVerify`.
  - `auth.signup` et `auth.guest` : **0 occurrence** dans tout `resources/js/router/`.
  - liens réellement affichés sur `/login` : `LoginComponent.vue:43` → `auth.forgetPassword`,
    `LoginComponent.vue:54` → `auth.signupPhone`, `LoginComponent.vue:61` → `auth.guestLogin`.
  - boucle morte du reset : `ForgetPasswordComponent.vue:52` fait
    `$router.push({ name: 'auth.verifyEmail' })` ; `auth.verifyEmail` a `meta.isFrontend: true`
    (`authRoutes.js:34-37`) et n'est pas dans l'allowlist ⇒ `router/index.js:250-252` renvoie
    sur `auth.login`. Le code reçu par mail ne peut jamais être saisi.
- **fix-suggéré** : corriger l'allowlist pour qu'elle contienne les noms réels
  (`auth.verifyEmail`, `auth.signupPhone`, `auth.signupVerify`, `auth.signupRegister`,
  `auth.guestLogin`, `auth.guestLoginVerify`) **ou** — si signup/invité doivent rester fermés
  par décision produit — retirer les liens correspondants de `LoginComponent.vue:54,61` et
  n'ouvrir que `auth.verifyEmail` pour réparer le reset. Un lien qui ne fait rien est pire
  qu'un lien absent.

---

**[P1-B1] `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue:366` + `KioskAppComponent.vue:246-248` [FROZEN] — le correctif « total affiché == total facturé » (C39) n'a été appliqué qu'à 2 écrans sur 4**

- **friction** : deux totaux différents pour le même panier selon l'écran regardé. Le client
  voit « 12,40 € » dans la barre panier du catalogue et « 14,90 € » sur l'écran panier.
  Classe de défaut « jumeau oublié » déjà documentée sur ce projet.
- **evidence** :
  - Le correctif C39 (2026-07-06) neutralise l'affichage d'une remise que le payload borne
    n'enverra jamais quand `kioskPromoEnabled` est OFF. Il est appliqué :
    - `KioskCartComponent.vue:495-519` (`effectiveLoyaltyDiscount` / `effectivePromoDiscount` /
      `displayTotal`), rendu ligne 275 et 357 ;
    - `KioskPaymentComponent.vue:331-342` (`displayFallbackTotal`, `cartTotal`).
  - Il **n'est pas** appliqué :
    - `KioskCategoriesComponent.vue:366` → `...mapGetters('kioskCart', { cartTotal: 'total' })`,
      rendu ligne 271 (`data-testid="kiosk-categories-cart-total"`) ;
    - `KioskAppComponent.vue:246-248` → `...mapGetters('kioskCart', ['count','total'])` +
      `cartTotal() { return this.total; }`, rendu ligne 65 (barre panier persistante) **[FROZEN]** ;
    - `KioskConfirmationComponent.vue:222-225` → repli `this.$store.getters['kioskCart/total']`
      (atténué : `orderTotal` vient normalement de la query serveur).
  - Le getter brut `total` soustrait les remises sans condition :
    `store/modules/kioskCart.js:250-253`.
  - Le drapeau est bien OFF par défaut : `config/kiosk.php:70` (`KIOSK_PROMO_ENABLED` absent
    de `.env`), exposé par `master.blade.php:230`.
  - Le déclencheur est persisté sur l'appareil : `store/index.js:314` persiste
    `kioskCart.loyaltyDiscount`. Le commentaire C39 lui-même
    (`KioskCartComponent.vue:487-493`) décrit exactement ce cas — il a été défendu sur un
    écran et laissé ouvert sur les trois autres.
- **fix-suggéré** : extraire `effectiveLoyaltyDiscount` / `effectivePromoDiscount` /
  `displayTotal` dans un getter du store (ex. `kioskCart/displayTotal`) et faire pointer les
  4 surfaces dessus. Une seule question ⇒ une seule réponse. Note : `KioskAppComponent.vue`
  est en zone gelée ⇒ **LOCK + gate owner requis**.

---

**[P1-B2] `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue:36-43,512-516` — la puce « Mon compte » du catalogue mène à l'écran « Votre panier est vide » quand le panier est vide**

- **friction** : la puce « 👤 Mon compte » est affichée **en permanence** en haut du catalogue,
  y compris au tout premier écran quand le client n'a encore rien choisi. Elle pousse vers
  `kiosk.loyalty`, dont le garde de route exige un panier non vide. Le client tape « Mon
  compte » et atterrit sur « Votre panier est vide » — un écran qui ne répond pas du tout à
  sa question, sans un mot d'explication.
- **evidence** :
  - bouton toujours rendu, aucun `v-if` : `KioskCategoriesComponent.vue:36-43`.
  - handler : `KioskCategoriesComponent.vue:512-516` → `$router.push({ name: 'kiosk.loyalty' })`.
  - garde : `kioskRoutes.js:199` `beforeEnter: requireCart` → `kioskRoutes.js:88-92`
    `if (isEmpty) return next({ name: 'kiosk.cart' })`.
  - écran d'arrivée : `KioskCartComponent.vue:65-82` (`kiosk.empty_cart` /
    `kiosk.empty_cart_hint`).
  - aggravant : même avec un panier plein, l'écran fidélité a son bloc de conversion masqué
    (`KioskLoyaltyComponent.vue:170,195,244` gatés `discountsEnabled && kioskPromoEnabled`,
    les deux à `false` par défaut — `config/pos.php:196`, `config/kiosk.php:70`).
- **fix-suggéré** : soit masquer la puce quand le panier est vide (miroir de
  `KioskCartComponent.vue:23`), soit retirer `requireCart` de `kiosk.loyalty` et laisser
  l'écran fidélité fonctionner en consultation de solde. Aujourd'hui les deux moitiés du
  produit se contredisent.

---

**[P1-B3] Catalogue borne pollué par des catégories de test (constaté sur la base de CETTE machine)**

- **friction** : le client voit dans la barre latérale du catalogue deux catégories nommées
  `E2E Cat 1786616399744` et `E2ECategory13511EDITED`, toutes deux **vides**. Sur une borne en
  service, c'est un défaut de confiance immédiat.
- **evidence** (requêtes lecture seule sur la base locale, `php artisan tinker`) :
  - `item_categories` actives (`status=5`, `deleted_at IS NULL`) = **11** :
    `1|Sandwichs`, `2|Galette`, `4|Burgers`, `5|Tacos`, `6|Bols`, `7|Frites`, `9|Desserts`,
    `10|Boissons`, `11|Menu enfant`, **`88|E2E Cat 1786616399744`**, **`95|E2ECategory13511EDITED`**.
  - `channels = NULL` pour les catégories 88 et 95 (identique aux vraies catégories).
  - `app/Models/ItemCategory.php:55-58` : `isVisibleOn()` retourne `true` quand
    `channels === null` (« NULL = visible on every surface »).
  - `app/Services/Kiosk/KioskMenuService.php:68-77` ne filtre que `status = ACTIVE` +
    `isVisibleOn('kiosk')` — **aucun filtre « catégorie non vide »**.
  - côté SPA, seul un identifiant en dur est masqué :
    `store/modules/kioskMenu.js:85-88` `KIOSK_HIDDEN_CATEGORY_IDS = new Set([315])`.
  - aucune de ces deux catégories ne contient d'article (0 item `status=5` dont le nom
    contient E2E/TEST/Playwright).
- **portée honnête** : constaté sur la base de développement de cette machine. **À revérifier
  sur la base de production avant d'en tirer une conclusion prod.**
- **fix-suggéré** : (a) purge des catégories E2E en base ; (b) durcissement durable —
  `KioskMenuService::build` ne devrait pas exposer une catégorie sans article visible sur le
  canal borne (une catégorie vide n'a aucune valeur client, quelle que soit son origine).

---

**[P1-B4] `resources/js/components/frontend/kiosk/KioskCashInstructionComponent.vue:20` — une commande passée hors-ligne affiche « Commande #— » : le client n'a aucun numéro à donner au caissier**

- **friction** : la borne perd le réseau, le client termine sa commande, l'écran final lui dit
  « Réglez à la caisse » et affiche **`#—`** à la place du numéro. Il se présente au comptoir
  avec un ticket sans numéro, pour une commande que la caisse **ne voit pas** (elle dort dans
  la file locale du navigateur). Aucun message ne prévient que la commande n'est pas encore
  partie. C'est le scénario où le client est le plus démuni.
- **evidence** (chaîne complète vérifiée) :
  - repli hors-ligne : `store/modules/kioskCart.js:796-805` construit une réponse synthétique
    avec `queue_number: '—'` (lignes 800 et 805).
  - Plan B force le mode espèces (`KioskPaymentComponent.vue:437`), donc le garde
    « refus des paiements électroniques hors-ligne » (`kioskCart.js:770-774`) ne s'applique
    jamais : **toute** commande borne hors-ligne emprunte cette branche.
  - propagation : `KioskPaymentComponent.vue:486` lit `queue_number` → `buildPaymentNavTarget`
    (ligne 452-457) place `number: '—'` dans la query → `kioskRoutes.js:254`
    (`orderNumber: route.query.number || ''`).
  - rendu : `KioskCashInstructionComponent.vue:19-21` → `#{{ orderNumber || '—' }}`.
  - aucun traitement spécifique hors-ligne dans cet écran : grep `offline` sur
    `KioskCashInstructionComponent.vue` = **0 occurrence** (seul `printFailed` existe, ligne 47).
  - l'impression retombe sur le constructeur client historique, l'`orderId` serveur étant
    volontairement omis pour les commandes hors-ligne (`KioskPaymentComponent.vue:455`) ⇒ le
    ticket papier ne porte pas davantage de numéro exploitable
    (`KioskCashInstructionComponent.vue:226-231`).
- **fix-suggéré** : attribuer un identifiant local lisible et prononçable à la commande mise en
  file (ex. « HL-07 ») et l'afficher à la place de `—`, **et** ajouter sur cet écran un message
  explicite du type « commande enregistrée sur la borne, présentez ce ticket au comptoir ».
  Un tiret n'est pas une information : il ne dit ni au client ni au caissier quoi faire.

---

### P2 — irritant

**[P2-B4] `resources/js/helpers/kioskPricingPreview.js:93-108` — le payload d'aperçu de prix perd le `role` des addons ⇒ `/pricing/preview` surfacture, et son résultat n'est plus affiché nulle part**

- **friction** : appel réseau à chaque modification du wizard (consomme le quota
  `quote_rate_limit`, `config/kiosk.php:309`) pour un résultat **faux** et **inutilisé**.
- **evidence** :
  - `normalizePreviewModifiers` (`kioskPricingPreview.js:93-108`) ne conserve que `{id, quantity}`
    — le champ `role` est **supprimé**.
  - le serveur a besoin du `role` pour appliquer le ratio formule :
    `PricingService.php:793-813` `menuRoleAdjustedAddonPrice()` — sans `menu_*`, il retourne le
    **prix plein** (ligne 796-797).
  - le chemin de facturation, lui, conserve bien le `role` :
    `store/modules/kioskCart.js:128-130` (`sanitizeKioskOrderModifiers`) ⇒ **le montant
    réellement facturé est correct**. Ce n'est donc PAS un P0 argent.
  - divergence déjà constatée et documentée : `KioskWizardComponent.vue:190-203`
    (« le /pricing/preview endpoint over-included the boisson addon (+1.20€) »). Le correctif
    appliqué a changé la liaison d'affichage (`runningTotalLocal`, ligne 204) **sans corriger
    le payload** ⇒ le computed `runningTotal` (`KioskWizardComponent.vue:704-708`, `Math.max`)
    n'est plus lié dans le template : code mort qui déclenche quand même l'appel réseau.
- **fix-suggéré** : ajouter `role` (et `variation_name`) à `normalizePreviewModifiers`, en
  miroir exact de `sanitizeKioskOrderModifiers`. Deux fonctions qui construisent le même
  panier pour le même serveur doivent partager la même normalisation.

---

**[P2-B5] `resources/js/components/frontend/kiosk/KioskCartComponent.vue:85-122` — un groupe de choix « radio » avec une seule option visible**

- **friction** : `pos_dine_in_enabled` étant à `false` en V1, la tuile « Sur place » est masquée
  (`KioskCartComponent.vue:100`, miroir `KioskIdleScreenComponent.vue:203`). Il reste un
  `role="radiogroup"` contenant **un seul bouton radio** (« À emporter »), déjà sélectionné
  depuis l'écran d'accueil. Le client se demande ce qu'il doit choisir ; un lecteur d'écran
  annonce « groupe de boutons radio, 1 sur 1 ».
- **evidence** : `KioskCartComponent.vue:85-91` (`role="radiogroup"`), ligne 100 (`v-if="dineInEnabled"`),
  ligne 439-445 (`dineInEnabled` défaut `false`), `config/pos.php:196`.
- **fix-suggéré** : masquer toute la barre quand une seule modalité est disponible, ou la
  transformer en simple mention non interactive « À emporter ».

---

### P3 — polish / dette latente

**[P3-B6] `resources/js/store/modules/kioskCart.js:44-46` + `KioskCartComponent.vue:778-783` — un code d'erreur technique brut peut s'afficher au client**

- **friction** : si `orderType` est absent au moment de « Valider », la chaîne littérale
  `KIOSK_ORDER_TYPE_REQUIRED` s'affiche en clair, en rouge, dans le panier et dans un toast 6 s.
- **evidence** : `kioskCart.js:44-46` lève `new Error('KIOSK_ORDER_TYPE_REQUIRED')` ;
  `kioskCart.js:625` l'appelle depuis `quoteOrder` ; `KioskCartComponent.vue:779-783`
  affiche `err?.message` tel quel dans `quoteError` (rendu ligne 365) + `showToast`.
- **atteignabilité honnête** : faible en parcours nominal — `KioskAppComponent.vue:948-953`
  positionne toujours `orderType` au départ depuis l'accueil, et `RESET` (`kioskCart.js:416-436`)
  vide aussi le panier (le garde `cartCount === 0` de `proceedToUpsell` s'active alors).
  Reste un chemin résiduel via état persisté (`store/index.js:319`) mal formé.
- **fix-suggéré** : mapper les codes `KIOSK_*` sur une clé i18n avant affichage
  (le composant sait déjà le faire pour `promoError` — `KioskCartComponent.vue:316`
  utilise `$te()` avant `$t()`).

**[P3-B7] `config/kiosk.php:123-126` + `store/modules/kioskMenu.js:85` — identifiants de catégorie périmés (pré-reset menu), aujourd'hui inertes**

- **evidence** : `KIOSK_FRITES_INCLUDED_CATS` défaut `309,310,311,314` (`config/kiosk.php:125`,
  aucune surcharge `.env`), consommé par `KioskWizardComponent.vue:1104-1107` ;
  `KIOSK_HIDDEN_CATEGORY_IDS = {315}` (`kioskMenu.js:85`). Vérifié en base : les catégories
  **309, 310, 311, 314, 315 sont ABSENTES** (les catégories réelles vont de 1 à 11).
- **inerte aujourd'hui** : `KioskWizardComponent.vue:1102-1103` sort avant le test si aucun
  extra `group_label='frites_style'` n'existe — vérifié en base : **0 extra `frites_style`**.
  Le piège se réarme dès qu'un extra `frites_style` est créé : l'étape « style de frites »
  sera alors proposée sur des plats dont les frites sont déjà incluses.
- **fix-suggéré** : remplacer les identifiants numériques par des slugs, ou ajouter une
  sentinelle qui échoue si un id configuré n'existe plus en base.

**[P3-W3] Anglais résiduel et libellés cassés dans le français — côté WEB uniquement**

- **friction** : textes anglais ou franglais servis à un francophone (ADR-007 impose le FR).
- **evidence** (chaque valeur relue dans `resources/js/languages/fr.json`, le seul fichier de
  langue réellement chargé par `resources/js/i18n.js`) :
  - `resources/js/components/frontend/components/MapComponent.vue:7` —
    `placeholder="Enter a location"` en dur (champ de recherche d'adresse).
  - `resources/js/languages/fr.json:1422` — `"your_address": "Your Adresse"`
    (utilisé `account/address/AddressCreateComponent.vue:12`, titre de la fenêtre d'ajout d'adresse).
  - `resources/js/languages/fr.json:1419` — `"work": "Work"` (le voisin `label.home` vaut bien
    « Accueil »). Utilisé `account/address/AddressCreateComponent.vue:46,51` et
    `checkout/AddressComponent.vue:42,47`. **Effet de bord logique** : la valeur « Work » est
    persistée puis réaffichée telle quelle (`checkout/AddressComponent.vue:17`), et le test
    d'icône `v-if="address.label == 'Home'"` (ligne 14) ne peut jamais correspondre.
  - `resources/js/languages/fr.json:1744-1745` — `"Coupon Ajouter Successfully."` /
    `"Coupon Supprimer Successfully."` (`checkout/CouponComponent.vue:164,172`).
  - `resources/js/languages/fr.json:1763` — `"Photo Mettre à jourd Successfully."`
    (`layouts/frontend/FrontendMobileAccountComponent.vue:162`,
    `layouts/frontend/FrontendNavBarComponent.vue:474`).
  - `resources/js/components/frontend/account/chat/ChatComponent.vue:68` —
    `placeholder="Type a message"`.
  - `resources/js/components/frontend/page/ContactUsComponent.vue:23` — `<h2>support</h2>` en dur.
- **classé P3 et non P1** : toutes ces surfaces sont bloquées par le gate staff-only (§0) —
  `/checkout`, `/address`, `/chat`, `/page/:slug` sont inatteignables aujourd'hui. Le jour où
  une surface web est rebranchée, ces défauts redeviennent immédiatement visibles.
- **fix-suggéré** : corriger les 5 valeurs de `fr.json` et les 3 chaînes en dur. Attention à
  `label.work` : changer la valeur ne suffira pas pour les adresses déjà enregistrées avec
  « Work ».

**[P3-W4] `resources/js/components/frontend/otherPage/ExceptionComponent.vue:6-7` — garde incomplète, risque de clé i18n brute**

- **evidence** : `v-if="Object.keys(authDefaultPermission).length > 0"` puis
  `{{ $t('menu.'+authDefaultPermission.name) }}` — la garde vérifie que l'objet est non vide,
  pas que `.name` existe. `menu.undefined` n'existe pas dans `fr.json` ⇒ clé brute affichée.
  Les composants frères ont été durcis contre exactement ce défaut (voir le commentaire
  « iter15-mega-fix A-002 » et la garde `defaultMenu?.url && defaultMenu?.language` en
  `FrontendNavBarComponent.vue:118-125` et `FrontendMobileAccountComponent.vue:40-44`) ;
  `ExceptionComponent.vue` a été oublié par ce correctif. **Non reproduit** — signalé comme
  jumeau oublié structurel.

**[P3-B8] `resources/js/components/frontend/kiosk/KioskWizardComponent.vue:2186-2192` [FROZEN] — commentaire périmé qui contredit le code 160 lignes plus haut**

- **evidence** : le bloc affirme que `fritesSauceSurcharge` « n'a AUCUN mécanisme de
  facturation backend → gratuite ». C'était vrai au 2026-07-15 ; le correctif du **2026-07-29**
  (lignes 2008-2030 du même fichier, sous gate owner `LOCK_KIOSK_FRITES_SAUCE_BILLING`) pousse
  désormais bien la sauce frites supplémentaire dans `normalizedExtras` et l'ajoute à
  `itemExtraTotal`. Le commentaire n'a pas été mis à jour.
- **pourquoi ça compte** : dans un fichier en zone gelée, le commentaire est la première
  chose que lit le prochain intervenant. Ici il dit l'inverse du code et pourrait provoquer
  une « correction » qui casserait la facturation.

**[P3-W2] `resources/js/components/layouts/frontend/FrontendNavBarComponent.vue:133-161` — 5 entrées du menu profil sans garde staff-only**

- **evidence** : `frontend.myOrder`, `frontend.editProfile`, `frontend.chat`, `frontend.address`,
  `frontend.changePassword` sont rendues sans `v-if="!staffOnlyMode"`, contrairement aux
  blocs voisins (lignes 10, 29, 57). Toutes ces routes ont `meta.isFrontend: true`
  (`frontendRoutes.js:35-97`) et sont hors allowlist ⇒ redirection silencieuse vers
  `admin.dashboard`.
- **atteignabilité non prouvée** : ce menu ne s'affiche que si `theme === 'frontend'`
  (`DefaultComponent.vue:3`) ET utilisateur connecté — or un utilisateur connecté sur une route
  frontend est déjà renvoyé vers l'admin (`router/index.js:251`). Signalé comme dette latente,
  pas comme défaut constaté.

---

### Non-défauts vérifiés (à ne PAS re-signaler)

Points suspectés puis **réfutés par lecture du code** — consignés pour éviter qu'un prochain
audit les re-remonte à tort :

1. **Le wrapper `KioskPosWizardComponent` ne fait pas dériver la borne.** 18 lignes,
   `v-bind="$attrs"` vers `KioskWizardComponent` (`KioskPosWizardComponent.vue:7,11`).
2. **L'écran de paiement n'affiche PAS un total non gaté.** `displayFallbackTotal`
   (`KioskPaymentComponent.vue:334-340`) applique bien le gate C39, et `cartTotal`
   (ligne 342) préfère le devis serveur. `_lastQuote` est déclaré dans `data()`
   (ligne 309) ⇒ bien réactif.
3. **Le ratio « formule » n'est PAS divergent entre client et serveur.** Les deux lisent
   `config('kiosk.menu_pricing')` — serveur `PricingService.php:800-806`, client
   `helpers/kioskPricing.js:51-73` via `master.blade.php:182`.
4. **Le P0 « ajout aveugle au panier » est corrigé.** `KioskCategoriesComponent.vue:607-635` :
   sur échec de `frontendItem/details`, plus aucun ajout silencieux.
5. **L'ajout au panier a bien un retour visuel** malgré l'absence volontaire de toast :
   `KsCartBottomSheet` (`KioskCategoriesComponent.vue:237,321,337`) + barre panier
   `KioskAppComponent.vue:52-68`.
6. **Le double-appui sur « Payer » est protégé** : clé d'idempotence unique par panier
   (`kioskCart.js:708-715`) + en-tête `X-Idempotency-Key` (ligne 725) + garde `submitting`
   (`KioskPaymentComponent.vue:436,466`).
7. **Corriger une composition est possible sans perte** : édition en place avec instantané et
   restauration en cas d'abandon (`KioskCartComponent.vue:696-721`, `kioskCart.js:302-326`).
8. **Les tailles de texte du catalogue ne sont pas trop petites** : les `10px`/`11px` trouvés
   sont un glyphe d'icône (`KioskCategoriesComponent.vue:944-953`) et un badge décoratif
   (ligne 1275-1285) ; le libellé de catégorie est `clamp(16px, 1.5vw, 20px)` (ligne 1108).
9. **La « sauce à +0,50 € » — le défaut nommé dans la mission — est bel et bien corrigé.**
   Recherché activement, chaîne complète relue, aucun écart :
   - affichage : `helpers/kioskPricing.js:133-136` (sauce sandwich) et `:147-150`
     (sauce frites) ajoutent `(N-1) × prix` au total courant du wizard ;
   - charge : `KioskWizardComponent.vue:2017-2029` pousse autant de lignes
     `Sauce supplémentaire` dans `normalizedExtras` et incrémente `itemExtraTotal` ;
   - **les deux utilisent le MÊME prédicat de recherche de l'extra** —
     `group_label === 'sauce' && /suppl/i.test(name)` (`kioskPricing.js:39-42` vs
     `KioskWizardComponent.vue:2019-2021`) — donc aucun item ne peut afficher un prix que
     l'autre chemin ignore. Un item sans cet extra renvoie 0 des deux côtés.
   *(Seul le commentaire du fichier gelé est resté en arrière — voir P3-B8.)*
10. **Aucun anglais résiduel ni clé i18n non résolue sur la BORNE.** Les 641 clés uniques
    utilisées dans `resources/js/components/frontend/kiosk/**` (y compris les clés dynamiques
    `` `kiosk.filters.${f}` ``, `` `kiosk.pay_screen.${tpeKey}` ``,
    `` `kiosk.wizard.instruction.${key}` ``) résolvent toutes vers des entrées françaises de
    `resources/js/languages/fr.json`. Zéro chaîne anglaise en dur, zéro `Label.`/`kiosk.` brut,
    zéro `0undefined`. Les défauts de langue sont **exclusivement** côté web (P3-W3).

---

## BLOC 3 — PARITÉ BORNE ↔ WEB

**Verdict : la comparaison n'a plus d'objet dans ce dépôt, et c'est un résultat, pas une esquive.**

Preuves déjà données en §0 : les pages vitrine sont supprimées (`frontendRoutes.js:1-25`),
`STAFF_ONLY_MODE=true` (`.env:73`), le chrome vitrine est éteint
(`DefaultComponent.vue:5-10`), et le garde de route redirige tout `meta.isFrontend`
(`router/index.js:249-253`). **Aucune carte n'est vendue par une seconde surface ici.**

Ce qui reste néanmoins prouvable et mérite d'être noté :

1. **Divergence borne ↔ serveur (pas borne ↔ web) sur l'aperçu de prix** — le même panier
   envoyé à `/pricing/preview` et à `/frontend/order` n'est pas normalisé pareil : le `role`
   des addons est conservé pour la commande (`kioskCart.js:128-130`) et perdu pour l'aperçu
   (`kioskPricingPreview.js:99-103`). C'est la **vraie** divergence de ce périmètre, détaillée
   en P2-B4. Elle ne coûte pas d'argent au client aujourd'hui uniquement parce que l'aperçu
   n'est plus affiché.

2. **Divergence borne ↔ borne sur le total affiché** — 4 écrans de la MÊME surface répondent
   différemment à « combien je paie ? » (P1-B1). Avant de chercher une parité entre deux
   produits, celle-ci doit être réglée à l'intérieur d'un seul.

3. **Règle de prix commune vérifiée saine** — les ratios formule sont lus depuis une source
   unique côté serveur et côté client (voir Non-défaut n°3). Si une surface web est
   réintroduite un jour, c'est le patron à suivre. ⚠️ **Mais le seul test qui vérifiait la
   parité de cette règle entre POS et borne est désactivé** :
   `tests/js/posKioskVariationParity.spec.js:400`
   `it.skip('case 8 (V1.0.1 deferred): menu addon (full/frites/boisson) POS↔Kiosk parity', …)`.

4. **Le SITE est cité comme référence de facturation dans le code de la borne** —
   `helpers/kioskPricing.js:144-145` et `KioskWizardComponent.vue:2013-2014` justifient la
   facturation de la sauce frites par « le SITE facture et scelle déjà ce surcoût
   (menu.js priceFor + api.js item_extras) : la borne était la seule à diverger ». La règle de
   parité borne↔web est donc **réelle et opérante en pratique**, mais son autre moitié vit
   dans un dépôt hors périmètre : **aucun test de ce dépôt ne peut la garder**. C'est le
   véritable trou de parité de ce projet.

5. **Point d'attention pour une future réintroduction** — `config/features.php:11-27`
   documente que le module « Offres » affiche une remise que `PricingService` n'applique pas
   (« shows X, charges Y »), d'où `offers_enabled` à `false`. Toute surface web rebranchée
   qui réactiverait les offres hériterait immédiatement de cet écart affiché/facturé.

---

## BLOC 4 — TROU DE PREUVE EN TEST RÉEL

Périmètre des exécuteurs, vérifié dans les fichiers de configuration (pas supposé) :
- Playwright — `playwright.config.js:48-52` : `testDir: './tests'`,
  `testMatch: ['e2e/**/*.spec.{js,ts}', 'playwright/**/*.spec.{js,ts}', 'Playwright/**/*.spec.{js,ts}']`.
  **Aucun `testIgnore`, aucun `grep`/`grepInvert`** ⇒ y compris les fichiers préfixés `_`.
  Nuance importante : le workflow CI est **opt-in** (`.github/workflows/playwright.yml:20-40` —
  `workflow_dispatch`, `push:main`, ou PR étiquetée `e2e-required`) ⇒ une PR ordinaire
  **n'exécute aucun de ces tests**.
- Vitest — `vitest.config.mjs:15` : `include: ['tests/js/**/*.spec.js']` uniquement.
  Aucun test sous `resources/js/**/__tests__/**`.

| # | Fonction BASE | Statut |
|---|---------------|--------|
| 1 | Accueil borne → démarrer la commande | **TEST-E2E** `tests/e2e/borne-idle-smoke.spec.js:6-28` · `tests/e2e/kiosk-spa-black-screen-guard.spec.js:62-112` · `tests/e2e/kiosk-happy-path.spec.js:30-47` — **TEST-UNIT** `tests/js/kioskOrderTypeExplicit.spec.js:121-140` |
| 2 | Parcourir la carte | **TEST-E2E** `tests/e2e/kiosk-spa-black-screen-guard.spec.js:81-106` · `tests/e2e/kiosk-happy-path.spec.js:54-95` — **TEST-UNIT** `tests/js/KioskCategoriesRestyle.spec.js:92-211` |
| 3 | Composer un produit (wizard) | **TEST-UNIT** solide : `tests/js/KioskWizard.spec.js` (2250 l.) + ~12 specs `tests/js/kioskWizard*.spec.js` — **TEST-E2E faible** : voir V-5, V-6, V-4 ci-dessous |
| 4 | Panier : ajouter / éditer / supprimer | **TEST-UNIT** `tests/js/kioskCartClampTotal.spec.js:22-49` · `tests/js/KioskCartRestyle.spec.js:77-171` · `tests/js/kioskCartSendPayload.spec.js:4-78` — **TEST-E2E partiel** `tests/e2e/kiosk-happy-path.spec.js:185-246` |
| 5 | Devis prix serveur | **TEST-UNIT** (PHPUnit) `tests/Feature/KioskPhase1/KioskEndpointsTest.php:216-419` (8 tests) · `tests/Feature/KioskPhase1/SsotInjectionHardeningTest.php:88-140` — **TEST-E2E** `tests/e2e/_d2-pricing-preview-2026-05-21.spec.js:100-207` |
| 6 | Envoi de commande / paiement | **TEST-UNIT** (PHPUnit) `tests/Feature/OrderPipeline/KioskFullFlowE2ETest.php:152-215` · `tests/Feature/KioskPaymentStateMachineTest.php:141-386` · `tests/Feature/Kiosk/KioskPaymentConfirmAmountTest.php:33-179` — **TEST-VACUOUS côté parcours réel** : voir V-2 |
| 7 | Attente + confirmation | **TEST-E2E** `tests/e2e/kiosk-post-payment-auto-return.spec.js:83-107` (assertions fermes) · `tests/e2e/kiosk-full-process/c1-kiosk-process-audit.spec.js:27-108` — **TEST-UNIT** `tests/js/kioskWaitingAutoReturn.spec.js:69-133` |
| 8 | File hors-ligne | **TEST-UNIT** `tests/js/kioskOfflineQueueV2.spec.js` (23 `it`) · `tests/js/kioskOfflineQueueSyncRace.spec.js:17-58` · `tests/js/kioskCartOfflinePaymentScope.spec.js:39-67` — **TEST-VACUOUS côté E2E** : voir V-1 |

### TEST-VACUOUS — vérifiés ligne par ligne par mes soins

- **V-1 · `tests/e2e/kiosk-edge-cases.spec.js:400`** — `expect(true).toBe(true); // assertion soft : test doc le flow`.
  Fin du scénario 7 « coupure réseau pendant payment-confirm ». Le test ne peut pas échouer.
  C'est précisément le scénario du défaut **P1-B4** ci-dessus : il est « couvert » par une
  assertion vide.
- **V-2 · `tests/e2e/kiosk-happy-path.spec.js:296-323`** — dans les étapes 13-17 (paiement,
  TPE, confirmation, ticket), **les deux seules assertions sont enfermées dans des `if`** :
  ligne 310 sous `if (resp)`, ligne 318 sous `if (onConfirmation)`. Si l'écran de paiement
  ne s'affiche pas, le test se termine **vert avec zéro assertion exécutée**.
- **V-3 · `tests/e2e/kiosk-happy-path.spec.js:406`** — `expect(appeared || initialKdsCount >= 0).toBeTruthy()`.
  `initialKdsCount >= 0` est une longueur de tableau : toujours vraie. L'assertion « synchro
  KDS < 2 s » ne teste rien.
- **V-4 · `tests/e2e/final-borne-deep.spec.js`** — 1356 lignes, **une seule** `expect()`
  (ligne 1144 : `expect(okCount, …).toBeGreaterThanOrEqual(8)`), explicitement annotée
  `// ---- SOFT ASSERTIONS ----`. Tous les contrôles d'intégrité calculés (total UI vs total
  DB, instantané de composition, `fiscal_sequence_no`) sont écrits dans un rapport markdown
  et **jamais assertés**.
- **V-5 · `tests/e2e/03-kiosk-wizard.spec.js:94-96`** — `test.fixme(true, 'Borne kiosk non
  configurée en DB …')` dès que l'URL contient `/kiosk/login`. C'est le fichier câblé comme
  **smoke test officiel** (`package.json:27` `test:e2e:smoke`).
- **V-6 · `tests/e2e/kiosk-happy-path.spec.js:135-138`** — `test.skip(true, 'Aucun item wizard
  dans seed kiosk catalog')` : l'étape de composition (le cœur du produit) s'auto-annule si
  aucun produit composable n'est trouvé dans les 6 premières cartes.
- **V-7 · `tests/e2e/kiosk-edge-cases.spec.js:84` et `:116`** — `test.skip(true, …)` pour les
  scénarios « expiration d'inactivité » et « modale êtes-vous toujours là ». **Correction
  apportée à l'inventaire brut** : ces deux `skip` sont bien **conditionnels** (imbriqués sous
  `if` lignes 81 et 114), non inconditionnels. Ils s'activent néanmoins en pratique, faute du
  stub `window.__kioskInactivityFastForward`.
- **V-8 · `tests/e2e/test-e2e-kiosk-kds-sync-2026-05-11-wave-B.spec.js`** — 947 lignes,
  5 `expect()` (lignes 246, 525, 673, 940, 941) dont les 2 dernières vérifient seulement que
  15 captures d'écran existent. L'invariant annoncé en tête de fichier (« total du récapitulatif
  wizard == sous-total de la ligne panier ») n'est jamais asserté.
- **V-9 · `tests/js/posKioskVariationParity.spec.js:400`** — `it.skip('case 8 (V1.0.1 deferred):
  menu addon (full/frites/boisson) POS↔Kiosk parity', …)`. **C'est exactement la règle de prix
  formule discutée en P2-B4/BLOC 3 : sa parité est désactivée en test.**

### Lecture d'ensemble

La couverture **unitaire** de la borne est réellement bonne (store, wizard, panier, devis,
file hors-ligne). La couverture **de bout en bout** est faible là où elle compte le plus :
le seul parcours qui va du produit jusqu'à l'écran final passe par V-2 (assertions sous `if`),
V-5 (smoke qui s'auto-annule) et V-6 (étape de composition sautée). Autrement dit, **aucun
test ne casse aujourd'hui si le parcours client complet cesse de fonctionner**, tant que la
page répond.

**Trous structurels supplémentaires :**

- **Aucune preuve possible pour le parcours WEB CLIENT** : il n'existe pas (§0). Tout test
  qui prétendrait valider un parcours de commande web sur ce backend serait vide par
  construction.
- **Aucune sentinelle ne garde la cohérence de l'allowlist staff-only** : `router/index.js:65-73`
  contient deux noms morts (P1-W1) depuis l'introduction du drapeau, sans qu'aucun test ne
  compare l'allowlist à l'ensemble réel des noms de routes. C'est un test à créer :
  *« tout nom listé dans `STAFF_ONLY_FRONTEND_ALLOWLIST` doit correspondre à une route
  déclarée »*. Il aurait attrapé les 3 liens morts.
- **Aucune sentinelle ne garde la cohérence des identifiants de catégorie configurés**
  (P3-B7) : `309,310,311,314,315` sont absents de la base sans qu'aucun test ne le signale.
- **Aucune sentinelle « un seul total »** : le correctif C39 a été appliqué écran par écran
  (P1-B1) sans test empêchant le prochain écran d'oublier le gate.

---

## Récapitulatif priorisé

| Réf. | Sév. | Surface | Titre | Zone gelée ? |
|------|------|---------|-------|--------------|
| P1-W1 | **P1** | WEB | Allowlist staff-only avec 2 noms inexistants ⇒ 3 liens morts sur `/login`, reset mot de passe cassé | non |
| P1-B1 | **P1** | BORNE | Total affiché non gaté sur 2 écrans (catalogue, barre panier) — correctif C39 incomplet | **oui** (`KioskAppComponent.vue`) |
| P1-B2 | **P1** | BORNE | « Mon compte » mène à « panier vide » | non |
| P1-B3 | **P1** | BORNE | Catégories de test E2E servies au catalogue (base locale) | non |
| P1-B4 | **P1** | BORNE | Commande hors-ligne : « Commande #— », aucun numéro à donner au caissier | non |
| P2-B4 | P2 | BORNE | Payload d'aperçu prix sans `role` ⇒ `/pricing/preview` faux et inutilisé | non |
| P2-B5 | P2 | BORNE | Groupe radio à une seule option | non |
| P3-B6 | P3 | BORNE | Code d'erreur brut `KIOSK_ORDER_TYPE_REQUIRED` affichable | non |
| P3-B7 | P3 | BORNE | Identifiants de catégorie périmés (piège réarmable) | non |
| P3-B8 | P3 | BORNE | Commentaire gelé périmé contredisant la facturation sauce frites | **oui** (`KioskWizardComponent.vue`) |
| P3-W2 | P3 | WEB | 5 entrées de menu profil sans garde staff-only | non |
| P3-W3 | P3 | WEB | Anglais résiduel : `"Work"`, `"Your Adresse"`, `"… Successfully."`, 3 chaînes en dur | non |
| P3-W4 | P3 | WEB | `ExceptionComponent` oublié par le durcissement `menu.undefined` | non |

**0 P0 constaté.** Les chemins argent vérifiés (devis serveur, ratio formule, idempotence,
correspondance affiché/facturé sur panier et paiement) sont sains.
