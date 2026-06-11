# W3 — DIMENSION A11Y : Audit axe-core BORNE (kiosk) — Round 1

- **Date** : 2026-06-11 — **App** : http://127.0.0.1:8768 — **Viewport** : 1080×1920, fr-FR, Chrome (channel)
- **Méthode** : flux complet déroulé in-SPA (les deep-links guardés redirigent vers idle/cart), axe-core 4.x local (`node_modules/axe-core/axe.min.js`), `runOnly: wcag2a + wcag2aa + wcag21aa + wcag22aa`, **assertion d'URL avant ET après chaque `axe.run`** (aucun état contaminé, 0 résultat droppé).
- **Script** : `tests/e2e/_w3-a11y-borne-2026-06-11.mjs` (+ probe `_w3-a11y-probe-2026-06-11.mjs`) ; JSON brut `/tmp/w3-a11y-borne-results.json`.
- **Discipline** : READ-ONLY, **aucune commande créée** (paiement jamais confirmé ; cash-instruction atteint par deep-link à props query, route non-guardée).
- **15 états scannés** : idle, choix type commande, catégories (défaut + TACOS), wizard Tacos, modal abandon wizard, cart (1 article), loyalty, upsell, payment (non confirmé), cash-instruction, 4 pages erreur.

## Totaux par impact (node-instances, agrégés sur 15 états)

| Impact | Nodes | Règles |
|---|---|---|
| **critical** | **5** | button-name (2), aria-allowed-attr (3) |
| **serious** | **44** | color-contrast (43), target-size (1) |
| moderate / minor | 0 | — |

**Top 3 règles** : 1. `color-contrast` (43 nodes, 9 états) · 2. `aria-allowed-attr` (3 nodes, critical) · 3. `button-name` (2 nodes, critical).

## Tableau règle × état

| État (URL vérifiée avant+après) | color-contrast (serious) | target-size (serious) | button-name (critical) | aria-allowed-attr (critical) |
|---|---|---|---|---|
| 1. /kiosk/idle | 0 | 0 | 0 | 0 |
| 2. idle — choix type commande | 0 | 0 | 0 | 0 |
| 3. /kiosk/categories (cat=1) | 4 | 0 | 0 | 0 |
| 4. /kiosk/categories?cat=5 TACOS | 4 | 0 | 0 | 0 |
| 5. wizard Tacos (overlay) | 13 (9 internes + 4 fond) | 0 | 0 | 0 |
| 5b. wizard — modal abandon | 14 | 0 | 0 | 0 |
| 6. /kiosk/cart (1 article) | 4 | 1 | 0 | 0 |
| 7. /kiosk/loyalty | 0 | 0 | **2** | 0 |
| 8. /kiosk/upsell | 0 | 0 | 0 | **3** |
| 9. /kiosk/payment (non confirmé) | 0 | 0 | 0 | 0 |
| 10. /kiosk/cash-instruction | 0 | 0 | 0 | 0 |
| 11-14. /kiosk/error/{network,menu-unavailable,product-removed,payment-refused} | 1 chacun (même node) | 0 | 0 | 0 |

Notes : idle, payment, cash-instruction = **0 violation** (payment/cash sont les écrans les plus propres du flux). Les pages erreur ne fautent que via la barre panier persistante du shell (visible car panier non vide ; vérifié 0 violation à panier vide). Catégorie « Tacos Signature » (cat=21) = 0 produit (vide, hors scan produit).

## Findings

### P1 — critical/serious sur le flux principal

**P1-1 · `button-name` ×2 (critical) — KioskLoyaltyComponent (NON frozen, fix trivial)**
- `resources/js/components/frontend/kiosk/KioskLoyaltyComponent.vue:5` — `.kiosk-back-btn` : bouton retour SVG-only, aucun `aria-label` / texte SR.
- `KioskLoyaltyComponent.vue:41-52` — `.kiosk-numpad-btn.wide` (touche « del » du numpad téléphone) : SVG-only, aucun nom accessible. Un client non-voyant ne peut ni revenir en arrière ni corriger son numéro de fidélité.
- Heal : `aria-label="$t('kiosk.back')"` / `aria-label` « Effacer » — 2 lignes, hors zone frozen.

**P1-2 · `aria-allowed-attr` ×3 (critical) — [FROZEN-GATE] KioskUpsellComponent**
- `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue:35-37` — cartes upsell `role="listitem"` + `:aria-pressed` : `aria-pressed` est interdit sur `listitem` → l'état sélectionné/non-sélectionné est **perdu pour les lecteurs d'écran** sur les 3 cartes (`kiosk-upsell-card-1/2/3`). Le pattern correct = `role="button"` (ou checkbox) + aria-pressed, dans un conteneur `role="list"` adapté. Composant **frozen §7** → aucun heal sans gate owner.

**P1-3 · `color-contrast` — famille ORANGE MARQUE #F4501E sur fonds clairs — [GATE-OWNER] (8 éléments uniques, 38 node-instances)**
Tous échouent 4.5:1 (texte petit), light-mode borne (mandat owner) :
| Élément | Contraste | Fichier:ligne | Gate |
|---|---|---|---|
| `.kiosk-promo-card__label` « Offre » (#F4501E/blanc) | 3.49 | `KioskPromoCarouselComponent.vue:20,139` | [GATE-OWNER] |
| `.kiosk-bottom-total` total panier (#F4501E/#FFE8DD) | 2.96 | `KioskCategoriesComponent.vue:263,1278` | [GATE-OWNER] |
| `.kiosk-step-visual-label` actif « QUELLE VIANDE ? » (#F4501E/blanc) | 3.49 | `KioskWizardComponent.vue:64,2634` | [GATE-OWNER] **+ [FROZEN-GATE]** |
| `.kiosk-viande-select-hint` « Choisir » ×4 (#F4501E/#FFE8DD) | 2.96 | `steps/KioskStepViandeComponent.vue:97,601` | [GATE-OWNER] (step du wizard frozen) |
| `.kiosk-btn-abandon` (#F4501E/#F7F3EC) | 3.15 | `KioskWizardComponent.vue:166` | [GATE-OWNER] **+ [FROZEN-GATE]** |
| `.kiosk-cart-clear` « Vider le panier » (#F4501E/blanc) | 3.49 | `KioskCartComponent.vue:24,840` | [GATE-OWNER] |
| `.kiosk-order-type-label` « À emporter » (blanc/#F4501E) | 3.49 | `KioskCartComponent.vue:106/117,779` | [GATE-OWNER] |
| `.kiosk-cart-bar-label` « Mon panier » (blanc/#F4501E) — fuit sur les 4 pages erreur | 3.49 | `KioskAppComponent.vue:62,1442` | [GATE-OWNER] **+ [FROZEN-GATE]** |

Policy §2 : pas de heal unilatéral de la marque — décision owner requise (option : réserver #F4501E aux fonds/aplats avec texte ≥18.66px bold, ou variante assombrie ~#C93D0F pour le texte).

### P2

**P2-1 · `color-contrast` #DC4517 (orange assombri, brand-adjacent) blanc 4.27 vs 4.5 — near-miss** — `.kiosk-top-chip--active` « Mon compte » + `.kiosk-sidebar-item.active` (`KioskCategoriesComponent.vue:37,871,997`). Présent sur TOUS les états catalogue/wizard. Assombrir à ~#C73E12 suffit ; ne touche pas #F4501E lui-même mais même famille → confirmer avec owner.

**P2-2 · `color-contrast` #888888 labels d'étapes inactifs (3.54) — [FROZEN-GATE]** — `.kiosk-step-visual-label` non-actifs « QUELLE SAUCE ? / QUEL MENU ? / RÉCAP » (`KioskWizardComponent.vue:2623`). Non-brand, fix trivial (#767676 passe) mais wizard frozen.

**P2-3 · `target-size` (WCAG 2.2) bouton quantité « + » panier** — `.kiosk-qty-btn.plus` (`KioskCartComponent.vue:206,1052`) : cible partiellement obstruée, espace résiduel 23px < 24px. Sur borne tactile, risque de tap raté/mauvais bouton (− au lieu de +). NON frozen.

**P2-4 · `color-contrast` bouton fidélité panier #B8730B/#FFFCEB (3.7)** — « Avez-vous une carte fidélité ? » (`KioskCartComponent.vue:330`). Jaune-brun non-brand-primaire ; NON frozen. (Nota : 4ᵉ node contrast du cart non listé par axe-extract — 3 exemples max/règle conservés.)

### P3

**P3-1 · Fond non inerte sous l'overlay wizard** — pendant le wizard, le catalogue derrière reste dans l'arbre a11y visuel et focusable (`sidebarAriaHidden:false` vérifié ; le dialog interne a bien `aria-modal="true"`). Conséquence mesurée : 4 nodes de fond pollued chaque scan wizard (top-chip, promo, sidebar, bottom-total) ; risque tab-out du modal au clavier. [FROZEN-GATE] (overlay rendu par KioskCategoriesComponent:288 + wizard frozen).
**P3-2 · Modal abandon wizard** : +1 node contrast (le même pattern brand) ; structure dialog correcte (`aria-modal`, `aria-labelledby` OK).
**P3-3 · Transient non reproduit** : 1 serious sur cash-instruction au run 1 (état antérieur différent) — non reproduit sur run 2 ni sur contexte frais (2×0). Classé flake, à re-vérifier round 2.

## Root-causes dédupliquées

1. **RC-BRAND** (38/49 instances) : le design system borne utilise #F4501E comme couleur de TEXTE sur fonds clairs en petites tailles (10-18px). Une seule décision de palette (variante texte assombrie) ferme P1-3 + P2-1 d'un coup — **[GATE-OWNER]**, 3 des 8 éléments aussi **[FROZEN-GATE]**.
2. **RC-ICON-BTN** : 2 boutons icône-seule sans nom accessible (loyalty) — heal 2 lignes, sans gate.
3. **RC-ARIA-PATTERN** : rôle/attribut ARIA incohérent sur les cartes upsell — **[FROZEN-GATE]**.
4. **RC-TOUCH** : 1 cible tactile <24px (qty+) — heal CSS local, sans gate.

## Verdict

Flux borne **structurellement sain** (idle, payment, cash-instruction, erreurs-hors-shell = 0 violation ; aucun nested-interactive contrairement à l'historique mobile ×87). Les 5 criticals sont concentrés sur 2 composants (loyalty = heal libre ; upsell = frozen). La masse serious (44) est à ~86 % une **unique décision de marque** #F4501E-en-texte → escalader au owner en un seul lot.
