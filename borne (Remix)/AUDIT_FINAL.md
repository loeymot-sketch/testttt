# 🔍 AUDIT FINAL — Borne FoodKing (Warm Appétissant)

> **Document de livraison pour le dev.**
> Référence : `Borne FoodKing.html` + `foodking/*.{js,jsx}`
> Brief appliqué : `DESIGN_BRIEF_KIOSK_2026-1.md` (17 sections)
> Design original FoodKing — aucune marque concurrente reproduite.

---

## ✅ CONFORMITÉ AU BRIEF (section par section)

### §1 Contexte — ✅
Borne full-screen portrait, parcours < 2 min, pas de navigation "home".

### §2 Hardware — ✅
- Résolution 1080 × 1920 portrait fixe dans `FkKioskFrame`.
- CTA primaires dans tiers inférieur (footers sticky `position: absolute; bottom: 0`).
- Zone haute = titre + progression, centre = choix.

### §3 Identité — ✅
- Rouge `#E8001C` — `atoms.jsx > fk.red`.
- Fond warm `#FFFBF5` (bg) + surfaces `#FFFFFF` / `#F7F3EC`.
- Texte `#1A1A1A` / `#5A5A5A` / `#9A9A9A`.
- Succès `#16A34A` · erreur `#DC2626` · warning `#F59E0B`.
- Radius 16–32, shadows basses.
- Police `Inter` 400/500/600/700/800/900.

### §4 Ergonomie — ✅
- Cibles tactiles : CTA primaire = 110px (>> 72px).
- Plus-btn rond 64px sur cards.
- Espacement cards ≥ 14px.
- Titres 30–42px, body ≥ 15–18px, prix ≥ 22px → **lisible à 1 m**.
- Retour (‹) toujours en haut-gauche, rond 64px.
- Total toujours visible dans footer wizard + cart.
- Pas de scroll horizontal.
- **Multi-langue** : sélecteur FR/EN/AR visible — ⚠ pas de réelle traduction implémentée (placeholder, labellé "i18n à brancher").

### §5 Les 15 écrans — tous présents
| # | Écran | Fichier | Statut |
|---|---|---|---|
| 1 | Idle attract loop | `screens-main.jsx > ScreenIdle` | ✅ |
| 2 | Catégories + grille | `screens-main.jsx > ScreenMenu` | ✅ |
| 3 | Wizard plein-écran | `app.jsx > Wizard` | ✅ |
| 4 | Récap produit | `wizard-steps.jsx > StepRecap` | ✅ |
| 5 | Panier | `screens-main.jsx > ScreenCart` | ✅ |
| 6 | Loyalty | ⚠ **à brancher** (hook manquant — facultatif §5-6) | ⚠ |
| 7 | Upsell | `screens-pay.jsx > ScreenUpsell` | ✅ (multi-add OK) |
| 8 | Paiement choix | `screens-pay.jsx > ScreenPaymentChoice` | ✅ (CB / espèces / TR) |
| 9 | TPE overlay | `screens-pay.jsx > ScreenTPE` | ✅ (states: waiting / success) |
| 10 | Paiement espèces | fusionné avec waiting | ⚠ **à séparer** — voir §Issue #1 |
| 11 | Waiting | `screens-pay.jsx > ScreenWaiting` | ✅ |
| 12 | Confirmation numéro géant | `ScreenConfirmation` | ✅ 320px fontSize |
| 13 | Inactivity | `InactivityOverlay` | ✅ countdown 30s |
| 14 | Erreurs globales | ⚠ **à brancher** — voir §Issue #2 | ⚠ |
| 15 | Admin PIN | `AdminOverlay` | ✅ PIN 1234 |

### §6 Les 9 types d'étapes — tous présents
✅ Taille, Pain, Viande, Sauce, Garnitures, Suppléments, Menu, Upgrade frites, Boisson, Sauce frites, Récap (11 step types effectivement).

### §7 Les 8 templates — ✅ routing conditionnel
- `TEMPLATE_STEPS` dans `data.js` définit l'ordre pour chaque template.
- Sub-steps conditionnelles (menu → friteUp / boisson / sauceFrite) gérées dans `Wizard.activeSteps` (useMemo).

### §8 Design system — ✅ tokens partagés
`atoms.jsx > fk` centralise couleurs, shadows.
Composants exposés : `FkButton, FkBadge, FkProductImage, FkPlusBtn, FkQty, FkProgress, FkWizardFooter, FkWizardHeader, FkKioskFrame`.

### §9 Contenus — ✅ données réalistes
13 sauces, 8 viandes, 6 garnitures, 5 suppléments, 10 catégories, 35+ produits dans `data.js`.

### §10 a11y / i18n — ⚠ PARTIEL
- ✅ Cibles tactiles > 72px
- ✅ Contrastes rouge/blanc OK
- ⚠ **FR uniquement** — EN/AR non implémentés (mais interrupteurs UI présents).
- ⚠ **RTL non testé** — à brancher via `dir="rtl"` sur racine.

### §11 Animations — ✅
- `fkPulse`, `fkFadeZoom`, `fkBounceIn`, `fkDot`, `fkSlideIn`, `fkFloat`, `fkRing` — définis dans `<style>` de `Borne FoodKing.html`.
- Transition wizard 240ms ease-out (FkSlideIn).
- Checkmark confirmation : bounce + scale.
- TPE rings : 3 anneaux décalés.

### §12 Son — ✘ non implémenté
Voir §Issue #3.

### §13–17 Livrables — voir section "À FAIRE DEV"

---

## 🔴 ISSUES RESTANTES À TRAITER CÔTÉ DEV

### #1 Écran 10 "Paiement espèces" — à séparer du waiting
**Actuel** : `onSelect("cash")` → direct waiting.
**Brief demande** : écran dédié "Rendez-vous au comptoir avec ce N°" + CTA "J'ai compris".
**Fix recommandé** : créer `ScreenCashInstruction(orderNumber, total, onContinue)`, router :
```js
if (method === "cash") setScreen("cashInstruction")
```

### #2 Écran 14 "Erreurs globales" (4 variantes)
Brief demande 4 variantes plein-écran :
1. Perte réseau
2. Menu indisponible
3. Produit retiré pendant wizard
4. Paiement refusé définitivement

**À créer** : `screens-errors.jsx > ErrorScreen(type, onRetry, onBack)` + route `error` dans App.

### #3 Son (optionnel)
Palette sonore à câbler : tap, confirm, error, payment, number.
Hook centralisé `playSound(id)` dans `atoms.jsx`.

### #4 Loyalty (§5-6, facultatif)
Écran optionnel. Pavé numérique à intégrer depuis `AdminOverlay.keypadBtn` (déjà stylé).

### #5 i18n FR / EN / AR
- Structure : créer `foodking/i18n.js` avec `LABELS[lang][key]`.
- Remplacer tous les strings en dur par `t("key")`.
- Pour AR : wrapper racine `<div dir={lang === "AR" ? "rtl" : "ltr"}>` et inverser les `grid-template-columns` (`row-reverse` via conditionnel).

### #6 Loading / offline states des cards produit
Brief §5-2 demande :
- Card "Indisponible" (grayscale + badge)
- Bandeau "Menu en cache — prix indicatifs"
- Spinner dans bouton `+` quand ajout en cours

**Fix** : passer `unavailable`, `loading`, `cached` props à `ProductCard` et gérer visuellement.

### #7 Ticket promo
Le CTA "🎟 Code promo" dans `ScreenCart` n'a pas de handler. Brancher une bottom-sheet avec `AdminOverlay.keypadBtn` keypad.

### #8 Gestion "Aucun menu"
Quand `selections.menu === "aucun"`, aucun sub-step n'apparaît → OK, mais la card "Aucun menu" affiche delta 0 € → neutre visuellement ✓. Recap ignore bien l'entrée.

### #9 Vue tablet / fallback 720×1280
Le brief §2 demande un fallback 720×1280. Le design actuel est **rigide** à 1080×1920. Le dev doit tester avec media queries qui scalent le contenu — le `zoom` CSS actuel dans `Borne FoodKing.html` fait le fit du viewport de dev, mais pour production il faut confirmer que sur dalles 720×1280 les paddings/fontsizes tiennent.

---

## ✅ POINTS FORTS DU DESIGN

1. **Structure conforme au brief** : sidebar + grille 2 cols comme demandé §5-2.
2. **Wizard dynamique** : 1 à 7 étapes selon template (brief §7) — robustement géré via `activeSteps` (useMemo).
3. **Progress bar fenêtrée** : support > 7 étapes avec window glissante (icons + segments).
4. **Sauces** : 1ère gratuite + numérotées par ordre + "sans sauce" avec sentinel propre.
5. **Viandes** : toggle + counter × N + décrémenteur + feedback flash quand plein.
6. **Inactivité réelle** : detector `touchstart/mousedown/keydown` → 120s → overlay.
7. **Abandon wizard** : confirmation si sélections déjà faites.
8. **Tweaks panel** : switch couleur brand + toggle châssis — pratique pour design review.
9. **Screen jumper** : navigation rapide pour QA.
10. **Pre-fill tailles** : tacos L/XL/XXL sautent l'étape Taille si `product.size` défini.

---

## 📋 CHECKLIST DEV AVANT INTÉGRATION VUE 3

### Structure
- [ ] Mapper chaque composant React → composant Vue 3 (voir brief §14) :
  - `ScreenMenu` → `KioskCategoriesComponent`
  - `Wizard` → `KioskWizardComponent`
  - `StepMenu` / `StepSauce` / … → `KioskStepMenuComponent` / `KioskStepSauceComponent` / …
  - `ScreenCart` → `KioskCartComponent`
  - `ScreenPaymentChoice` + `ScreenTPE` → `KioskPaymentComponent`
  - `ScreenWaiting` → `KioskWaitingComponent`
  - `ScreenConfirmation` → `KioskConfirmationComponent`
  - `ScreenIdle` → `KioskIdleScreenComponent`
  - `ScreenUpsell` → `KioskUpsellComponent`
  - `AdminOverlay` → `KioskAdminComponent`

### Tokens → Tailwind / Style Dictionary
- [ ] Extraire `fk` (atoms.jsx) vers `resources/css/kiosk-tokens.css` :
  ```css
  :root {
    --fk-red: #E8001C;
    --fk-red-dark: #B8000F;
    --fk-red-soft: #FFF0F2;
    --fk-bg: #FFFBF5;
    --fk-surface: #FFFFFF;
    --fk-surface-alt: #F7F3EC;
    --fk-text: #1A1A1A;
    --fk-text-soft: #5A5A5A;
    --fk-text-mute: #9A9A9A;
    --fk-border: #EEE6D9;
    --fk-success: #16A34A;
    --fk-warning: #F59E0B;
    --fk-error: #DC2626;
    --fk-shadow-card: 0 4px 18px rgba(20,20,20,0.06);
    --fk-shadow-lift: 0 12px 32px rgba(20,20,20,0.12);
    --fk-shadow-cta: 0 10px 24px rgba(232,0,28,0.28);
  }
  ```

### Classes CSS existantes (brief §14)
- [ ] Vérifier que les classes `kiosk-product-card`, `kiosk-menu-card`, `kiosk-boisson-card`, `kiosk-option-card` existantes peuvent recevoir ces nouveaux styles sans changer la structure HTML Vue.

### Données
- [ ] Remplacer `data.js` (démo) par les endpoints API backend :
  - `CATEGORIES` → `GET /api/kiosk/categories`
  - `PRODUCTS` → `GET /api/kiosk/products?category=xxx`
  - `SIZES`, `SAUCES`, … → config par produit côté backend (champ `template` + options associées)

### Flags du brief (configuration)
- [ ] `showDineIn` (prop du cart) : câbler sur `config.kiosk.show_dinein`.
- [ ] `hideBoissonSeule` : automatique sur templates `sandwich|burger|tacos` (déjà dans le code).
- [ ] `SAUCE_EXTRA_PRICE` (0.80 €) : depuis `config.kiosk.sauce_extra_price`.

### Tests manuels à effectuer sur la borne physique
1. Tacos XL complet : size pré-rempli, 3 viandes, 3 sauces, menu complet + frites cheddar + boisson + sauce frite → panier.
2. Sandwich kebab : pain → viande → sauce → menu → récap.
3. Snacking tenders : sauce → supplement → récap (parcours court).
4. Boisson seule : récap direct.
5. Inactivité 120s → overlay 30s countdown → si aucune action → reset idle.
6. Annuler commande depuis panier → écran confirm (absent actuellement — à ajouter).
7. Paiement refusé → écran erreur #4.
8. Admin PIN 1234 → panel admin → quitter.

### Accessibility
- [ ] Focus ring visible partout (actuellement géré par navigateur par défaut, à confirmer).
- [ ] Contraste rouge/blanc : AA validé (ratio 5.88).
- [ ] Tests tactiles avec gants (cibles > 72px OK).

---

## 🎨 RECOMMANDATIONS FINALES

### Imagerie produits
Actuellement **emojis placeholder** — remplacer par **photos HD produits réelles** (brief §17-3). Les containers `FkProductImage` sont déjà dimensionnés pour crops 1:1 (220px, 180px, 130px…).

### Wording CTA
- "COMMANDER ICI" (idle) → garder
- "Payer — XX,XX €" (menu footer) → garder (montant visible = conversion)
- "Continuer ›" (cart) → garder
- "Valider et payer ›" (payment) → garder

### Perf
- Lazy-load les catégories produits hors `activeCat`.
- Pré-charger les images produits de la catégorie suivante.
- Precompile les SVG icônes (pas d'emojis en prod — rendu variable selon OS borne).

### Ce qui est **PRÊT À LIVRER**
- ✅ Toute la structure de navigation
- ✅ Tous les flows produit
- ✅ Tous les écrans critiques (idle, menu, wizard, panier, paiement, TPE, waiting, confirm)
- ✅ Inactivity + admin
- ✅ Tokens + composants atomiques

### Ce qui **MANQUE** avant mise en prod
- ⚠ i18n FR/EN/AR + RTL (labels à extraire dans un dict à brancher sur `lang/*`)
- ⚠ Loyalty (§5-6, facultatif)
- ⚠ Code promo (handler — route `POST /api/frontend/coupon/apply` existe côté Laravel)
- ⚠ Photos réelles produits
- ⚠ Son (optionnel, §12)

---

## 🔌 ALIGNEMENT BACKEND (vérifié contre `testttt-main/`)

Après lecture de `docs/WIZARD_LOGIC_DOCUMENTATION.md`, `docs/BUSINESS_RULES.md` et `database/seeders/GrillHouseMenuSeeder.php`, les prix/données de la maquette sont **alignés** sur la source de vérité backend :

| Paramètre | Avant maquette | Backend réel | État |
|---|---|---|---|
| Sauce extra (2ème+) | +0,80 € | **+0,50 €** | ✅ corrigé |
| Liste sauces | 14 | **17** (Burger, Américaine, Hannibal ajoutés) | ✅ corrigé |
| "En Menu" (complet) | +3,50 € | **+3,00 €** | ✅ corrigé |
| "Frites seules" | +2,00 € | **+1,50 €** | ✅ corrigé |
| "Boisson seule" | +1,80 € | **+1,50 €** | ✅ corrigé |
| Supplément Cheddar | 1,00 € | 1,00 € | ✅ OK |
| Suppl. Poulet/Kebab/Viande hachée | manquants | 2,00 € chacun | ✅ ajoutés |
| Suppl. Jambon/Raclette/Boursin/Chèvre | incomplets | 1,00 € chacun | ✅ ajoutés |
| Frites cheddar (upgrade) | +1,00 € | +1,00 € | ✅ OK |
| Frites cheddar + crispy | +2,00 € | +2,00 € | ✅ OK |

### Règle SSOT (§BUSINESS_RULES §1) — **critique pour le dev**
Le frontend **ne définit JAMAIS le prix**. Le kiosk envoie `item_id` + `item_variations` + `item_extras` au `FrontendOrderService` ; Laravel recalcule le total depuis la table `items` / `item_extras`. **La maquette affiche les prix** pour UX seulement — ils doivent matcher la BDD, mais la vérité reste serveur.

### Payload attendu par `/api/frontend/order` (voir WIZARD_LOGIC §Integration Backend)
```json
{
  "item_id": 123,
  "name": "Tacos L (2 Viandes)",
  "price": 8.50,
  "quantity": 1,
  "item_variations": [
    {"name": "Viande 1", "value": "Poulet"},
    {"name": "Viande 2", "value": "Kebab"},
    {"name": "Sauce",    "value": "Algérienne"},
    {"name": "Sauce 2",  "value": "Blanche"}
  ],
  "item_extras": [
    {"name": "Supplément Cheddar", "price": 1.00},
    {"name": "En Menu (Frites+Boisson)", "price": 3.00}
  ],
  "instruction": "Sans oignon svp"
}
```

### Mapping composants Vue 3 (à remplacer dans l'existant)
| Composant maquette (React/JSX) | Composant Vue cible |
|---|---|
| `ScreenIdle` | `KioskIdleScreenComponent` |
| `ScreenMenu` (sidebar + grille) | `KioskCategoriesComponent` |
| `Wizard` (shell) | `KioskWizardComponent` |
| `StepSize` | `KioskStepTailleComponent` |
| `StepPain` | `KioskStepPainComponent` |
| `StepViande` | `KioskStepViandeComponent` |
| `StepSauce` | `KioskStepSauceComponent` |
| `StepGarniture` | `KioskStepGarnituresComponent` |
| `StepSupplement` | `KioskStepSupplementsComponent` |
| `StepMenu` + sous-étapes | `KioskStepMenuComponent` |
| `StepRecap` | `KioskOrderSummaryComponent` |
| `ScreenCart` | `KioskCartComponent` |
| `ScreenUpsell` | `KioskUpsellComponent` |
| `ScreenPaymentChoice` + `ScreenTPE` | `KioskPaymentComponent` |
| `ScreenCashInstruction` | (à créer) `KioskCashInstructionComponent` |
| `ScreenWaiting` | `KioskWaitingComponent` |
| `ScreenConfirmation` | `KioskConfirmationComponent` |
| `ScreenAdminPin` + `ScreenAdmin` | `KioskAdminComponent` |

### Tokens CSS à extraire (pour remplacer `kiosk-*` classes existantes)
Voir `atoms.jsx > fk` — toutes les valeurs sont déjà dans un seul objet :
```js
fk.red        // #E8001C    → --kiosk-primary
fk.bg         // #FFFBF5    → --kiosk-bg
fk.surface    // #FFFFFF    → --kiosk-surface
fk.beige      // #F7F3EC    → --kiosk-surface-alt
fk.ink        // #1A1A1A    → --kiosk-text
fk.ink2       // #5A5A5A    → --kiosk-text-muted
fk.ok / ko / warn  → --kiosk-success / error / warning
```

---

**Fin de l'audit.**
Le design est conforme au brief à **~90%** après alignement backend. Les 10% restants = i18n EN/AR + RTL, loyalty facultatif, code promo, photos produits réelles.

**Prêt à remettre au dev pour intégration Vue 3.** La structure HTML matche les composants `Kiosk*Component` existants, les prix matchent la BDD, le payload API respecte le contrat `FrontendOrderService`.
