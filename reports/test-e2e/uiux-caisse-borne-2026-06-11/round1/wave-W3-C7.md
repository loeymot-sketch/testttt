# Wave W3-C7 — Audit UI/UX BORNE : panier + fidélité + upsell

**Date** 2026-06-11 · **App** http://127.0.0.1:8768 (DB jetable) · **Viewport** 1080×1920 fr-FR (chrome)
**Scripts jetables** `tests/e2e/_w3-c7-cart-loyalty-upsell.mjs`, `tests/e2e/_w3-c7-edge-trash-last-item.mjs`
**Screenshots** `shots-c7/` (17) · READ-ONLY source respecté · Flux réel déroulé depuis `/kiosk/idle` (panier construit : Sandwich Cayenne composé via wizard 5 étapes + Glace + Tarte Daim = 14,60 €)

**Bilan : 0 P0 · 2 P1 · 4 P2 · 4 P3** — panier blanc NON-RÉGRESSÉ ✅, promo fantôme upsell NON reproduite (mais gap code latent, cf. P3-09).

---

## P1

### [P1] F-C7-01 — ÉCRAN BLANC TOTAL sur « S'inscrire » fidélité borne (feature inscription morte)
- **reproduction** : `/kiosk/loyalty` → bouton « Pas encore membre ? S'inscrire » → l'étape `register` rend une **page crème 100 % vide** (`document.body.innerText.length = 0`, aucun champ). Dead-end client : ni retour ni header (seul recours = timeout d'inactivité). Reproduit 2/2 runs.
- **root cause (vérifiée)** : `resources/js/languages/fr.json` clé `kiosk.loyalty_screen.placeholder_email = 'vous@exemple.fr'` (idem `en.json` `'you@example.com'`) — le `@` non échappé est interprété par vue-i18n comme *linked message* → console `SyntaxError: Invalid linked format` (capturée au moment exact du clic) → le render de `KioskLoyaltyComponent.vue:82-146` (étape register, `$t('kiosk.loyalty_screen.placeholder_email')` ligne 124) explose → composant blanc. Scan exhaustif : seule clé kiosk concernée dans fr+en.
- **evidence** : `09-loyalty-register-blank.png` (page vide), log `REGISTER STEP STATE {"bodyLen":0,"nameField":false}`, console error capturée.
- **recommendation** : échapper en `vous{'@'}exemple.fr` (syntaxe littérale vue-i18n) ou sortir le placeholder de l'i18n. Composant et JSON **non frozen** — fix 2 lignes + re-test. Impact actuel : aucune création de compte fidélité possible sur borne.

### [P1] F-C7-02 — Perte TOTALE du panier client sur récupération de session 401 (bounce → idle → panier vidé)
- **reproduction** (3/3 runs, systématique au 1er appel API après une fenêtre sans réseau — ici « Appliquer » un code promo) : `POST frontend/promo/validate` → **401** (token kiosk révoqué entre-temps) → re-login auto de l'intercepteur échoue à son tour (**401 `/api/login`** observé) → `CLEAR_KIOSK_TOKEN` + `router.push(kiosk.login)` (`resources/js/app.js:132-150`) → login auto → **retour `/kiosk/idle`** → **panier intégralement vidé** (22 articles / 147,60 € → « 0 article »). Seul signal : toast jaune « Session rafraîchie automatiquement ». Le message d'erreur promo n'est jamais affiché.
- **trigger** : rotation de token mono-borne — `KioskMachineLoginController.php:103` (`$user->tokens()->where('name','kiosk-token')->delete()`) : toute session kiosk concurrente (ici agents parallèles sur la box partagée ; en prod : 2ᵉ onglet/preview admin du kiosk) révoque le token de la borne active. Même mécanique que le verdict AUTH de W1-C4, mais ici l'impact client est maximal : **commande perdue en silence**.
- **evidence** : `04-cart-qty-max.png` (panier 22 articles 147,60 €) puis `05-cart-promo-error.png` (écran idle « Bienvenue ! ») puis état panier vide ; reproduit aux runs 2, 4 et 6 du script.
- **recommendation** : (a) préserver le panier (store `kioskCart` non purgé par la bascule idle/login de récupération, ou persistance locale re-hydratée) ; (b) sur échec du re-login silencieux, écran d'erreur explicite plutôt qu'un retour idle muet ; (c) en V1 mono-borne, éviter toute 2ᵉ session kiosk simultanée (exploitation). Probabilité prod faible, impact maximal.

---

## P2

### [P2] F-C7-03 — Images cassées « Boisson Seule » / « Frites Seules » (catalogue + panier + upsell)
- **reproduction** : ces items rendent une icône broken-image + alt-text partout où leur vignette apparaît : grille catégorie SANDWICH CAYENNE, ligne panier, cartes upsell. « Menu (Frites + Boisson) » a, lui, une vraie photo.
- **evidence** : `00-category-grid.png` (2 tuiles grises avec alt), `03-cart-full.png` (1ʳᵉ ligne sans image), `12-upsell.png` / `13-upsell-selected.png` (2 cartes sur 3 cassées). Viole la grille « photo produit dominante » (DESIGN_REFERENCES §3).
- **recommendation** : DATA — recharger les médias des items 2/3 ; côté UI le fallback emoji du panier (`KioskCartComponent.vue:133-136`) ne se déclenche pas car `item.image` est défini mais 404 → prévoir `@error` fallback (non frozen).

### [P2] F-C7-04 — Format prix `€14,60` (symbole AVANT) sur toute la borne — grille exige `14,60 €`
- **reproduction** : tous les prix borne (tuiles, panier, totaux, upsell, CTA « Valider ma commande €14,60 ») rendent symbole-avant. `DESIGN_REFERENCES_2026-06-11.md:86` : « format FR (`12,50 €`, espace insécable avant €) » ; ligne 116 liste le format anglo-saxon comme anti-pattern.
- **root cause** : `resources/js/helpers/kioskFormatPrice.js:36-41` honore le setting `currency_symbol_position` ; la DB de cette box est en `left`. **Fix DATA-only** (setting admin → `right`), aucun code à changer.
- **evidence** : `03-cart-full.png`, `12-upsell.png`, `01-wizard-composed.png` (« Total €7,00 »).

### [P2] F-C7-05 — Cibles tactiles < 48 px sur les lignes panier (corbeille 36×36, crayon 34×34)
- **reproduction** : mesure DOM `getBoundingClientRect` : `.kiosk-cart-item-trash` = **36×36**, `.kiosk-cart-edit-btn` = **34×34** (grille touch ≥ 48 px). Les boutons qty (50×50), back (60×60), clear (142×52), modal vider (≥130×53) sont conformes.
- **evidence** : log `TOUCH SIZES` du script + `03-cart-full.png`. `KioskCartComponent.vue` (styles `.kiosk-cart-item-trash` / `.kiosk-cart-edit-btn`) — non frozen.
- **recommendation** : passer les deux à ≥48 px (zone de hit paddée suffit).

### [P2] F-C7-06 — Catégorie « SANDWICH CAYENNE » : 3 items annexes AVANT les sandwichs + descriptif anglais « Upsell item »
- **reproduction** : ordre de grille observé : `BOISSON SEULE, FRITES SEULES, MENU (FRITES + BOISSON), SANDWICH CAYENNE, BIG CAYENNE` — les produits éponymes de la catégorie arrivent en 4ᵉ/5ᵉ position derrière des items d'appoint, dont le sous-titre affiché est **« Upsell item »** (anglais brut sur borne FR exclusive).
- **evidence** : `00-category-grid.png` + log `CATEGORY GRID ORDER`. Viole « FR partout » (DESIGN_REFERENCES §25) et la hiérarchie marchande de la catégorie.
- **recommendation** : DATA — corriger `description` FR de ces items et leur tri (ou les sortir de l'affichage catalogue s'ils ne servent qu'au pool upsell).

---

## P3

### [P3] F-C7-07 — Fidélité : message backend brut « Non trouvé » au lieu du libellé i18n
- `KioskLoyaltyComponent.vue:505-506` privilégie `err.response.data.message` (= « Non trouvé », 404 backend) sur `kiosk.loyalty_screen.error_not_found` (« Code ou numéro introuvable. Vérifiez et réessayez. ») — message terse, sans guidance. Evidence : `08-loyalty-unknown.png`. Inverser la priorité (i18n d'abord pour les 404).

### [P3] F-C7-08 — Qty max silencieuse
- À qty=20 (`maxItemQty`, `KioskCartComponent.vue:397,207`) le « + » se désactive sans aucun feedback (« maximum 20 par article »). Math correcte (20×7,00 = 140,00 €). Evidence : `04-cart-qty-max.png`.

### [P3] F-C7-09 — Upsell : pas de filtre rupture côté backend (gap latent — « promo fantôme » NON reproduite aujourd'hui)
- **Re-vérification du finding connu** : les 3 suggestions servies (`Frites Seules`, `Boisson Seule`, `Menu (Frites + Boisson)`) **existent en DB, status ACTIVE, `is_available:true`** (capture API `frontend/item/kiosk-upsell?item_ids=22,49,50` 200). Pas d'item fantôme aujourd'hui. **MAIS** `app/Http/Controllers/Frontend/ItemController.php:78-90` ne filtre que `status` + `is_upsell` + `kiosk_upsell_include` — jamais `is_available`/rupture — et `KioskUpsellComponent.vue:220-237` [FROZEN-GATE] ajoute au panier sans guard. Un item 86'd resterait proposable → échec à la commande. **Fix possible 100 % backend** (ajouter le filtre availability dans la query), aucun toucher frozen.

### [P3] F-C7-10 — Cosmétique wizard [FROZEN-GATE, constat seul] + 401 cold-start
- (a) Stepper du wizard : le libellé « RÉCAP » déborde/se clippe dans le cercle d'étape (`01-wizard-composed.png`) — composant frozen, **aucune édition proposée**, à consigner pour un futur LOCK. (b) Chaque cold-start borne émet un `401 /api/login` en console avant le login kiosk silencieux — bruit console systématique, sans impact visible.

---

## Surfaces VALIDÉES ✅

- **Panier blanc — NON-RÉGRESSION CONFIRMÉE** : `/kiosk/cart` ne rend jamais une page blanche — plein (3 lignes), vide (état dédié), **deep-link à froid** (`19-cart-cold-deeplink.png`, root présent, bodyLen 116), et après chaque guard. Le seul écran blanc trouvé est F-C7-01 (loyalty register), pas le panier.
- **Panier plein** (`03-cart-full.png`) : nom + **composition résumée** (« Poulet mariné, Algérienne · Salade, Tomate, Oignon, Cornichon »), prix unitaire + total ligne, qty +/-, corbeille, crayon ré-édition, totaux, CTA « Valider ma commande » avec montant, « + Ajouter des articles », « À emporter » (Sur place masqué V1), entrée fidélité visible.
- **Vider le panier** (`06-cart-clear-modal.png`) : modal de confirmation claire (« Cette action est définitive »), Annuler fonctionne, boutons ≥53px.
- **Suppression dernier article via corbeille** (`17-trash-last-item-empty.png`) : suppression immédiate → état vide propre (« Votre panier est vide » + CTA « Ajouter des articles » qui renvoie au catalogue). Pas de confirmation sur la corbeille — acceptable borne (ré-ajout trivial).
- **Guard panier vide 3/3** : `/kiosk/payment`, `/kiosk/loyalty`, `/kiosk/upsell` à panier vide → **redirect propre vers `/kiosk/cart`** (`kioskRoutes.js:83-87`), aucun crash/flash (`16-guard-payment.png`).
- **Fidélité — saisie** (`07-loyalty-input.png`) : numpad tactile généreux, input + clear, « Continuer sans fidélité » (skip) visible, CTA inscription contrastée, 100 % FR. (Étape « balance/points » non testable : aucun compte existant + inscription morte F-C7-01.)
- **Upsell [FROZEN — capture seule]** (`12/13/14`) : « ET POUR TERMINER ? », 3 suggestions réelles à prix corrects, sélection → check + « Ajouter (1) et continuer +€2,00 », refus explicite « Non merci, continuer sans (28s) » avec barre d'auto-skip 30 s, **retour arrière → `/kiosk/cart`** sans perte.

## Note méthodo
Box partagée multi-agents : rotation continue du token kiosk (cf. F-C7-02) — prévoir la récupération idle/login dans tout script borne long. Promo `BORNEAUDIT5` = donnée de test ambiante.

**Top 3** : F-C7-01 (inscription fidélité = écran blanc, fix 2 lignes i18n) · F-C7-02 (perte panier sur récup session) · F-C7-03/04 (images cassées + format prix `€x,xx` — DATA).
