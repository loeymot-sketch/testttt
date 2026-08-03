# W3 AUDIT — cuisine A/B/C (READ-ONLY, HEAD 24e8a09c3, 2026-07-06)

Preuves : commande test tinker order 5501 serial E2E-173832 (Tacos M + note « oignons cuits » + Coca 33cl) ; réels #5456 (Coca standalone), #5171 (addon Boisson Seule role=drink), #5499.

## A — Note wizard absente du /kds (ticket OK)
- Payload OK : KDSOrderItemsResource.php:30, KDSOrderDetailsResource.php:59 → OrderItemResource.php:50 envoient `instruction`.
- Cause : layout V2 actif (KitchenDisplaySystemComponent.vue:1249-1270) → KdsOrderCard.vue:410-413 rend via `renderItemSymbolic()` (kdsSymbolic.js:282-337) qui n'émet JAMAIS de ligne instruction (seule lecture = fritesSauceSymbol :113-118).
- L'ancien chemin kdsCustomization.js renderItem():349-358 l'affichait via `sanitizeKdsInstruction` (exporté :205) ; KdsOrderLine.vue:82-87 sait déjà rendre le type `instruction`. PHP ticket garde la note (OrderReceiptEscPosRenderer.php:300 → cleanInstruction:270, bloc `** …` :338-342).
- Preuve rendu réel 5501 : ticket = `1 x G | TAC | M | P | ALG` + `** oignons cuits` + `1 x COC` ; KDS V2 = aucune ligne note.
- **Plan 0-frozen** : kdsSymbolic.js renderItemSymbolic() après suppléments (~:330) + branche isMenuItem (:297-309) : push `{type:'instruction', label:sanitizeKdsInstruction(...), visualClass:kdsInstructionVisualClass(...)}` (imports déjà exportés de kdsCustomization.js).
- Tests : tests/js/kdsSymbolicInstruction.spec.js (TO BE CREATED) + rows note dans parity fixture.

## B — Oignon cuit + symbole O̲
- Crudités = extras GRATUITS (convert_price===0) toggles inclus par défaut : pos-wizard.js:2876-2879, 3044-3064, 3226-3233 (isCruditeName), 3840-3870. Aucune notion cru/cuit (grep cuit = 0). pos-wizard.js + KioskWizardComponent.vue FROZEN.
- Modélisation : nouvel extra gratuit « Oignons cuits » (DATA) — matche isCruditeName(`oignon`) donc toggle auto, MAIS serait inclus par défaut et non-exclusif → **patch frozen ~30 lignes requis (LOCK ×2 : pos-wizard.js + KioskWizardComponent.vue)** : défaut cuit=OFF + exclusivité cru↔cuit. Variation pricing écartée (SSOT).
- Symbole : entrée `[/oignon.*cuit|cuit.*oignon/, 'O̲']` AVANT `/oignon/` + CRUDITE_ORDER=['S','T','O','O̲'] dans les 2 jumeaux (kdsSymbolic.js:63-69 ; KitchenTicketSymbolicFormatter.php:48-54,95-105).
- Écran : `O`+U+0332 rendu natif dans {{ line.label }} (pas de v-html). Ticket : CP858 droppe U+0332 → (1) ajouter EscPosCommandBuilder::underline() (`ESC - n`, absent aujourd'hui, grep=0) ; (2) traduire `O+U+0332` → `ESC-1 O ESC-0` dans encodeForPrinter() (:112-128) UNIQUEMENT (sanitize() :311-320 strip 0x00-0x1F, un ESC via textLine serait détruit) ; (3) width : mb_strlen('O̲')=2 mais 1 colonne → compter U+0332 à 0 dans le width calc ; test width-safe 32/48 obligatoire.
- Tests : tests/Feature/Hardware/KitchenTicketOnionCuitSymbolTest.php + tests/js/kdsSymbolicOnionCuit.spec.js (TO BE CREATED) + rows parité. Parité : PHP et JS émettent le MÊME string `O̲` (underline = couche octets, hors parité).

## C — Boissons invisibles cuisine (3 chemins prouvés)
1. Boisson ITEM standalone (#5456) : pas filtrée mais sort « 1 x COC » cryptique (produitCode 3 lettres, JS :164-173 + jumeau PHP).
2. Addon « Boisson Seule » role=drink (#5171) : TOTALEMENT absente (ticket = `1 x BOL | Mex | FRO` seul). Addons ne servent qu'à MENU/F : PHP menuLine():248-263, JS buildSymbolic():250-256.
3. Boisson de MENU role=menu_boisson : volontairement écrasée « MENU » (OrderReceiptEscPosRenderer.php:276,286 ; JS :294-309). ⚠ contradiction owner à acter : décision 2026-06-30 « jamais le détail Frites+Boisson » — le GOAL 2026-07-06 l'inverse (owner veut préparer les boissons en cuisine).
- **Plan 0-frozen** : PHP `drinkLines(array $snapshot)` (addons role menu_boisson/drink/*boisson* → "{qty} {addon_name}") + `isDrinkItem()` jumeau exact du categorize()==='drink' JS (kdsCustomization.js:29,86-93) → head = nom complet pour items boisson + append drinks au bloc y compris branche isMenuItem (:284-293). JS renderItemSymbolic() : categorize drink → label item_name complet ; addons drink → {type:'menu_child', label:'{qty}× {name}'} (type déjà rendu KdsOrderLine.vue:66-71). Rien côté Resource (resolveAddonsForKds():52-60 expose déjà).
- Tests : KitchenTicketDrinkVisibilityTest.php + kdsSymbolicDrinks.spec.js (TO BE CREATED) + rows parité. Risque : détection boisson PHP↔JS strictement identique (garde dessert-avant-drink).

## Bilan frozen
A = 0 frozen · C = 0 frozen · B = LOCK ×2 (pos-wizard.js, KioskWizardComponent.vue) + DATA extra « Oignons cuits ».
