# A4 — Temps de préparation affiché au client

Audit LECTURE SEULE. Branche `pos/category-first-caisse-2026-06-23`, HEAD `a91f95e2e`.
Deux périmètres distincts, jamais confondus ci-dessous :
- **(a) storefront servi par CE backend** (`resources/js/components/frontend/**`)
- **(b) site vitrine lecayenne.fr**, dépôt séparé `/Users/1millnonstop/Downloads/web` (vérifié présent, HEAD `4d1dfcb`) — rien écrit dedans.

---

## § Ce qui EXISTE

### 1. Le réglage `order_setup_food_preparation_time` — (a)

**Stockage** : table `settings` du paquet `Smartisan\Settings` (`use Smartisan\Settings\Facades\Settings;` — `app/Services/OrderService.php:62`). Pas de clé `info` JSON : une ligne par réglage.

**Valeur réelle en base aujourd'hui** (`mysql -u root foodking_e2e`, base de `.env:14`) :

```
id  key                                 payload                          group
40  order_setup_food_preparation_time   {"$cast": null, "$value": "30"}  order_setup
```

⚠️ **La valeur en base est 30, pas 15.** Le « 15 » n'est qu'un repli de code jamais atteint ici (`app/Http/Resources/SettingResource.php:58`, `app/Services/OrderService.php:428`). Le semis pose 30 : `database/seeders/OrderSetupTableSeeder.php:20`.

**Écran d'administration** : `/admin/settings/order-setup` — préfixe `resources/js/router/modules/settingRoutes.js:57` (`path: "/admin/settings"`) + `:137` (`path: "order-setup"`, `name: "admin.settings.orderSetup"`). Champ texte : `resources/js/components/admin/settings/OrderSetup/OrderSetupComponent.vue:19-21`. Validation `app/Http/Requests/OrderSetupRequest.php:28` → `required|numeric|min:0`.

**Consommation** : la valeur est estampée sur CHAQUE commande à sa création, dans la colonne `preparation_time` — `app/Services/OrderService.php:428`, `:860`, `:1611` et `app/Services/FrontendOrderService.php:354`.

### 2. Où il est affiché au client — (a)

| Écran | Affiché ? | Valeur |
|---|---|---|
| Menu / accueil | **NON — l'écran n'existe plus** | `resources/js/router/modules/frontendRoutes.js:22-25` : `/home`, `/menu`, `/offers` sont de simples `redirect: "/login"` depuis `[STOREFRONT-DELETE 2026-06-25]` (commentaire `:1-8`). Aucun composant vitrine rendu par ce backend. |
| Panier | NON | aucun hit. |
| Checkout | **OUI** | `resources/js/components/frontend/checkout/CheckoutComponent.vue:102` → `{{ setting.order_setup_food_preparation_time }} {{ $t('label.minute') }}` = « 30 minutes » aujourd'hui. **Conditionné** par `v-if="Object.keys(nowTimeSlot).length > 0"` (`:93`, store `frontendTimeSlot/now` — `:582`) : masqué hors créneau ouvert. |
| Après paiement | **NON pour le retrait** | Le checkout redirige vers `frontend.myOrder?id=` (`CheckoutComponent.vue:933`) → `account/myOrder/OrderDetailsComponent.vue:31` monte `OrderStatusComponent`. Celui-ci rend `{{ props.preparation_time }} min` en `components/OrderStatusComponent.vue:42`, mais le `v-if` de `:40` exige `order_type == DELIVERY`. En V1 Le Cayenne (retrait) → **rien**. |
| Suivi public `/suivi/:token` | OUI, mais **dynamique** | `resources/js/components/frontend/tracking/OrderTrackingPageComponent.vue:82` → `{{ waitLow }}-{{ waitHigh }} min`. Route : `resources/js/router/modules/orderTrackingRoutes.js:13`. |

### 3. Le dynamique existant — deux mécanismes distincts

**(i) Temps saisi par le caissier.** `app/Http/Controllers/Admin/OnlineOrderController.php:174-181` : à l'ACCEPT, si la requête porte `preparation_time`, la colonne `orders.preparation_time` est écrasée (15/25/40 min depuis le tracker caisse). Validé par `app/Http/Requests/OrderStatusRequest.php:56` (`sometimes|nullable|integer|min:5|max:120`).
**Fuite jusqu'au client : OUI.** `app/Http/Resources/OrderDetailsResource.php:98`, `UserOrderResource.php:33` et `OrderResource.php:35` expédient `preparation_time` au front, et `OrderStatusComponent.vue:42` l'affiche (livraison seulement, donc invisible en V1 — mais la donnée sort).

**(ii) Fourchette calculée sur la file cuisine.** `app/Services/WaitEstimateService.php:39-43` — paliers owner : file ≤3 → 15-20 min ; 4-5 → 20-25 ; >5 → 25-30 (`OVERFLOW_TIER:44`). Retourné en `wait_low`/`wait_high` (`:90-91`). Consommé par le suivi public (`OrderTrackingPageComponent.vue:196-197`) ET par la borne (`kiosk/KioskWaitingComponent.vue:77, 394-395`). **Ce mécanisme ignore totalement le réglage admin.**

### 4. La forme intervalle

Un intervalle **existe déjà**, mais uniquement dynamique : `wait_low`/`wait_high` ci-dessus. Un intervalle **statique piloté par un réglage : ABSENT.** Le `grep -rn "prep.*max\|delay_max\|eta" app/ config/ --include="*.php"` ne renvoie qu'un seul hit, `OrderStatusRequest.php:56` (`max:120` = borne de validation, pas un intervalle d'affichage).

### 5. i18n — (a)

Le storefront Vue lit `resources/js/languages/fr.json` (chargé par `resources/js/i18n.js:105`). Clés déjà présentes : `label.minute` (`fr.json:1378` = « minutes »), `label.estimated_delivery_time` (`:1292`), `label.preferred_time_takeaway` (`:1498`). ⚠️ `lang/fr/` (côté PHP) ne contient **pas** de `label.php` — ne pas y ajouter les clés du storefront.

### 6. Tests existants

`tests/Feature/OrderSetupRequestNegativeValuesTest.php:52-64` (refus d'une valeur négative), `tests/TestCase.php:56` (semis à 30), `tests/js/posOrdersTrackerWebVisibility.spec.js`, plus ~30 tests Feature qui ne font que semer la clé. **Aucun test ne couvre l'affichage client du délai.**

### 7. Le site vitrine (b) — dépôt séparé

Tout est **codé en dur**, aucun appel API pour le délai :
- `screens.jsx:385` : « prêts en **8 à 20 minutes** » (déjà un intervalle, en dur).
- `screens.jsx:144` : pastille « ⚡ Prêt en 8 min ».
- `screens-v3.jsx:182` : `Prêt en ${item.time} min`, par article, depuis `data/menu.js` (`time:` défaut 8 — `menu.js:302`).
- `funnel.jsx:143` « Dès que prêt · ~12 min », `:273` « Prêt en ~12 min », `:683` ticket de confirmation « Prêt à {ctx.slotTime || '~12 min'} ».

---

## § Ce qui MANQUE

1. **Réglage d'intervalle statique** → ABSENT. À créer : clé `order_setup_food_preparation_time_max` (nullable) dans le groupe `order_setup`.
2. **Affichage au menu** → côté (a) l'écran n'existe plus ; côté (b) c'est du texte en dur non pilotable.
3. **Affichage après paiement pour le RETRAIT** → ABSENT (`OrderStatusComponent.vue:40` réserve le bloc à la livraison).
4. **Un rendu unique du délai** → ABSENT. À créer : `resources/js/services/preparationTime.js`.
5. **Cohérence statique/dynamique** : le suivi public contredira le chiffre annoncé (WaitEstimateService, 15-30 min mouvant). Décision owner requise.

---

## § Proposition scope-minimal

**Un seul réglage, deux champs.** Conserver `order_setup_food_preparation_time` (déjà validé, déjà estampé sur les commandes) et **ajouter** `order_setup_food_preparation_time_max`, nullable. Règle de rendu unique : `max` vide ou égal à `min` → « 15 min » ; sinon → « 15–25 min ». La demande d'aujourd'hui (15, fixe) se satisfait en posant `min=15, max=null` — l'intervalle arrive plus tard sans nouvelle migration.

⚠️ **Poser la valeur à 15** : la base porte 30 aujourd'hui. C'est un `UPDATE settings` (ou l'écran admin), pas une modification de code.

**Chemins proposés** (tous ABSENTS aujourd'hui) :
- clé max : ajouter dans `app/Http/Requests/OrderSetupRequest.php` (`sometimes|nullable|numeric|min:0|gte:order_setup_food_preparation_time`), `app/Http/Resources/OrderSetupResource.php`, `app/Http/Resources/SettingResource.php` (repli `null`) ;
- champ admin : `resources/js/components/admin/settings/OrderSetup/OrderSetupComponent.vue` (à côté du champ existant) ;
- rendu partagé : **à créer** `resources/js/services/preparationTime.js` → `formatPreparationTime(min, max)` ;
- i18n : `resources/js/languages/fr.json` — réutiliser `label.minute`, ajouter `label.preparation_time_range` si un gabarit est requis ;
- test : **à créer** `tests/js/preparationTimeDisplay.spec.js` (min seul → « 15 min » ; min+max → « 15–25 min ») + un cas Feature sur la clé max dans `OrderSetupRequestNegativeValuesTest.php`.

**Vitrine (b)** : hors mandat de wireup (CLAUDE.md §3bis). Y refléter la même valeur à la main, dans une constante unique — aujourd'hui elle est dispersée sur 6 littéraux (`screens.jsx:144,385`, `screens-v3.jsx:182`, `funnel.jsx:143,273,683`).

**Ne pas toucher** : `WaitEstimateService.php` (paliers dictés par l'owner, servent la borne et le suivi) ; `OnlineOrderController.php:174` (temps réel caissier, usage staff).
