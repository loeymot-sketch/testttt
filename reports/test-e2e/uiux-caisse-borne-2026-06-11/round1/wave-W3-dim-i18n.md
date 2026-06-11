# W3 — Dimension i18n/FR — Sweep transversal BORNE (kiosk)

Date : 2026-06-11 · Branche : `heal/cms-pr1-quickwins-2026-06-10` (worktree release-v1-2026-06-10) · READ-ONLY, aucun fichier code modifié.

## Méthode
- Extraction exhaustive des clés `$t()` statiques (regex tolérante espaces/quotes, 2 passes croisées) sur `resources/js/components/frontend/kiosk/**` (24 composants + `ds/` 15 + `steps/`), `router/modules/kioskRoutes.js`, `store/modules/kioskCart.js`, `helpers/kioskOfflineQueue.js` → **434 clés uniques**.
- Diff programmatique (node, flatten récursif) contre `resources/js/languages/fr.json` (2149 clés, 473 kiosk.*) et `en.json` (2127 / 456).
- Énumération des 6 familles de clés DYNAMIQUES côté producteur + vérification de chaque valeur possible.
- Greps formats : `toFixed`, `Intl.NumberFormat`, `toLocaleString`, `toLocaleDate/Time`, `moment(`, `dayjs`.
- Scan tonalité fr.json kiosk.* : regex tutoiement (`tu|ton|ta|tes|toi`) vs vouvoiement.
- Analyse visuelle : 16 captures `tests/captures/baseline-25cb5dac1/kiosk-*.png` lues. Per `_manifest.txt`, 9 sont des redirections de garde (login/categories/products×2/confirmation/admin → `/kiosk/idle` ; loyalty/upsell/payment → `/kiosk/cart`) — 7 écrans uniques réellement rendus : idle, cart-empty, cash-instruction, error-network, error-menu-unavailable, error-product-removed, error-payment-refused.

## Résultat global : couverture FR quasi parfaite
- **434/434 clés statiques utilisées présentes dans fr.json** (le seul "manquant" est l'artefact regex `allergens.<code>` issu d'un commentaire JSDoc de `KsAllergenBadge.vue:48`).
- **0 texte anglais hardcodé user-facing** trouvé (templates + littéraux JS scannés sur 2 patterns indépendants).
- **Tonalité 100 % cohérente : 0 tutoiement / 65 occurrences de vouvoiement** dans kiosk.* FR.
- Clés dynamiques toutes résolues :
  - `kiosk.wizard.instruction.${key}` (KioskWizardComponent.vue:2020) — producteurs : taille/pain/viandes/sauces_extra/menu/frites_sauce → 6/6 en FR.
  - `kiosk.pay_screen.${tpeKey}` (KioskPaymentComponent.vue:635-641) — tpe_card/tpe_tr/tpe_default → 3/3 (+ tpe_accepted/timeout/follow/etc. présents).
  - `kiosk.filters.${f}` (4 steps) — ids autorisés par KsFilterChip.vue:46-47 (vegetarian/halal/pork_free/gluten_free/spicy/under_10) → 6/6 + `all`.
  - `${s}.menu_label_*` (KioskWizardComponent.vue:1252-1254, 2068-2070) — `kiosk.wizard.summary.menu_label_{full,frites,boisson,none}` → 4/4.
  - `allergens.${code}` (KsAllergenBadge.vue:137) — 14 allergènes UE en FR + alias EN historiques (gluten, crustaces/crustaceans, …) ; fallback contrôlé (voir F-5).
- Formats monétaires : tous les écrans passent par `kioskPriceMixin` → `helpers/kioskFormatPrice.js` (virgule décimale, symbole configurable, défaut `fr-FR`/EUR) ou `ds/KsPriceLine.vue` (Intl `fr-FR` par défaut, fallback `toFixed(2).replace('.', ',')`). Les `toFixed` de `kioskCart.js` (225-337) et `KioskWizardComponent.vue:1986-2003` sont du **calcul d'arrondi**, pas de l'affichage.
- Dates : `KioskOfflineConflictModalComponent.vue:88` = `Intl.DateTimeFormat('fr-FR')` ✓ ; `KioskConfirmationComponent.vue:257-259` mappe la locale i18n → `fr-FR`/`en-GB`/`ar-SA` ✓. Aucun moment/dayjs dans le périmètre borne.
- FR-lock vérifié : `i18n.js:9,21` (`DEFAULT_LOCALE='fr'`, `KIOSK_LOCALE='fr'`), sélecteur de langue idle masqué (`KioskIdleScreenComponent.vue:195` `enabledLanguages: ['fr']`, FP-27/ADR-007).

## Analyse visuelle (7 écrans uniques, 16 captures lues)
Tout est FR, vouvoiement, formats FR : idle (« Bienvenue ! », « Commandez en quelques touches », « À emporter », « CHOISISSEZ UNE OPTION POUR COMMENCER ») ; panier vide (« VOTRE PANIER », « 0 article », « Votre panier est vide », « Ajouter des articles ») ; cash-instruction (« Rendez-vous en caisse », « Montant à régler **0,00 €** » — format FR correct, « Retour à l'accueil dans 43 s », « J'AI COMPRIS ») ; 4 écrans d'erreur (« Connexion perdue », « Menu momentanément indisponible », « Cet article n'est plus disponible », « Paiement refusé » + CTA « RÉESSAYER / PAYER EN CAISSE / ANNULER LA COMMANDE »). **Aucune clé brute, aucun texte EN, aucun format EN, aucune incohérence de ton détectés.**

## Findings

### [P2] resources/js/components/frontend/kiosk/KioskCartComponent.vue:316 + fr.json `kiosk.promo.applied` — interpolation perdue (code + montant promo jamais affichés)
- Evidence : l'appel passe `{ code: promoCode, amount: formatPrice(promoDiscount) }` mais la valeur FR est `"Code promo appliqué"` **sans placeholder** `{code}`/`{amount}` (vérifié node sur fr.json). Diff programmatique « clés appelées avec params mais FR sans placeholder » → 1 seul hit.
- Impact : flux client normal (panier + promo appliquée) — le texte reste FR correct (pas de `{x}` brut), mais le client ne voit ni le code ni le montant confirmés sur cette ligne (la remise reste visible ligne 262 `-{{ formatPrice(promoDiscount) }}`). Dégradation d'information, pas de corruption → P2 et non P1.
- Recommandation : enrichir fr.json : `"applied": "Code {code} appliqué : -{amount}"`. Fichier de langue uniquement, zéro frozen.

### [P3] resources/js/languages/en.json — 16 clés kiosk manquantes vs FR
- Evidence (diff node) : `kiosk.promo.{applied,apply,label,loading,placeholder,remove}`, `kiosk.pay_screen.counter_route_{title,sub,confirm_btn,processing}`, `kiosk.consent.privacy_body`, `kiosk.error.network.staff_ack`, `kiosk.max_quantity_reached`, `kiosk.offline_queue.auto_return`, `kiosk.unavailable_items_pruned`, `kiosk.wizard.generic.step_fallback`.
- Impact : nul en V1 (FR-lock ADR-007, sélecteur masqué, `fallbackLocale='fr'` i18n.js:118 → retomberait sur le FR, pas sur une clé brute). À combler avant toute réactivation EN.

### [P3] resources/js/components/frontend/kiosk/KioskToastComponent.vue:23 — aria-label hardcodé hors i18n
- Evidence : `aria-label="Fermer la notification"` (seul attribut user-facing non bindé du périmètre, grep attributs littéraux).
- Impact : FR correct aujourd'hui ; échappe au système i18n. Recommandation : `:aria-label="$t('kiosk.a11y.close')"` (clé existante).

### [P3] resources/js/helpers/kioskFormatPrice.js:36-42,53 — typographie symbole en position 'left' + fallback décimal point
- Evidence : si `site_currency_position==='left'` → `` `${symbol}${formatted}` `` = `€12,50` collé sans espace (non-FR) ; catch-fallback ligne 53 `num.toFixed(digits)` garde le point décimal.
- Impact : invisible avec la config Le Cayenne actuelle (position 'right' par défaut, getPriceOptionsFromStore:66) ; code-only. Recommandation : insérer espace insécable et `replace('.', ',')` dans le fallback.

### [P3] resources/js/components/frontend/kiosk/ds/KsAllergenBadge.vue:134-141 — code allergène inconnu affiché brut
- Evidence : `localize()` retourne le `code` brut si `allergens.${code}` absent du fichier de langue. 14 codes UE + alias EN couverts ; un code DB non mappé (ex. saisie libre admin) s'afficherait verbatim (`sesame_oil`).
- Impact : aucun cas réel constaté dans les captures ; fallback volontairement dégradé-propre. Recommandation : sentinel de cohérence codes DB ↔ clés `allergens.*`.

### [P3] resources/js/store/modules/kioskCart.js:656,762 + KioskInactivityOverlayComponent.vue:28 — fallbacks FR hardcodés défensifs
- Evidence : message 429 (« Trop de commandes envoyées rapidement… ») dupliqué hors i18n quand `window.__appI18n` indisponible ; overlay inactivité `$t(...) || 'Votre commande sera effacée dans'`.
- Impact : par design (defensive), FR correct, clé i18n `error.kiosk_rate_limited` existe et est tentée d'abord. Aucune action requise ; documenté pour traçabilité.

## Compte par sévérité
| P0 | P1 | P2 | P3 |
|----|----|----|----|
| 0  | 0  | 1  | 5  |

## Top 3
1. **[P2] `kiosk.promo.applied`** — interpolation `{code}/{amount}` perdue dans le panier (fix 1 ligne fr.json).
2. **[P3] en.json 16 clés kiosk manquantes** — dette à solder avant réactivation du sélecteur de langue.
3. **[P3] KioskToastComponent aria-label hardcodé** — seule chaîne user-facing hors i18n du système borne.

Verdict dimension : **GREEN** — la borne est FR-propre (couverture 434/434, ton vouvoiement uniforme, formats `0,00 €` corrects, 0 clé brute, 0 anglais visible) ; 1 P2 cosmétique-informationnel, aucun frozen-zone touché.
