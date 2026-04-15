# Task – WIZARD_AUDIT_001

## Description
Audit profond et correction de la logique du wizard de prise de commande FoodKing —
couvrant les deux surfaces concernées (Kiosk / Borne et POS / Caisse), la configuration
des catégories et produits (wizard_template, has_menu, supplements, variantes),
le système de menu avec ou sans formule (frites + boisson), le résumé et ajout au panier,
ainsi que la logique de paiement POS (cash et carte).

L'objectif est un audit de logique pure avec corrections ciblées :
aucun ajout de feature, aucun refactor cosmétique, aucune modification de style.
Seule la logique correcte, cohérente, et sans régression compte.

Cas déclencheur :
- items parfois déjà pré-sélectionnés au mauvais état
- impossible de sélectionner deux viandes ou supplements dans certains cas
- options non affichées si le produit n'a pas de wizard_template défini
- produits mal renseignés (template détecté par heuristic de nom, pas par DB)
- multi-produits sur borne et caisse : comportement incohérent
- logique de paiement POS présente des irrégularités (cash/carte)

---

## Périmètre de l'audit

### In scope

#### Kiosk – Wizard complet
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
  — orchestrateur principal, détection de template, séquençage des étapes, récap, buildCartItem()
- `resources/js/components/frontend/kiosk/steps/KioskStepTailleComponent.vue`
  — sélection de taille (S/M/L/XL), viandeCount mapping
- `resources/js/components/frontend/kiosk/steps/KioskStepViandeComponent.vue`
  — sélection viande multi-select avec compteur
- `resources/js/components/frontend/kiosk/steps/KioskStepPainComponent.vue`
  — sélection pain/galette (variation DB ou fallback hardcodé)
- `resources/js/components/frontend/kiosk/steps/KioskStepSauceComponent.vue`
  — sauce (première gratuite, extras payants)
- `resources/js/components/frontend/kiosk/steps/KioskStepGarnituresComponent.vue`
  — garnitures (multi-select, sync initiale au mount)
- `resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue`
  — suppléments payants (extras avec prix > 0, sauces exclues)
- `resources/js/components/frontend/kiosk/steps/KioskStepMenuComponent.vue`
  — menu (full / frites / boisson / none), sous-sélection boisson, sauce frites
- `resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue`
  — récap complet, totaux, contrôles de quantité

#### POS – Sélection variante et ajout au panier
- `resources/js/components/admin/pos/PosComponent.vue`
  — surface POS principale, logique d'affichage catégories/produits
- `resources/js/components/admin/pos/ItemComponent.vue`
  — modal de sélection variante, extras, menu — équivalent modal du wizard kiosk

#### Panier et pricing
- `resources/js/store/modules/kioskCart.js`
  — état panier kiosk, merge items par signature, total avec discount loyalty
- `resources/js/store/modules/posCart.js`
  — état panier POS, localStorage TTL 2h, signature pos_line_addons
- `resources/js/helpers/kioskPricing.js`
  — calculateKioskRunningTotal(), getKioskMenuAddonPrice(), getKioskExtraSauceUnitPrice()
- `resources/js/helpers/posCartLineMath.js`
  — calcul ligne POS (variantes + extras + menu)

#### Configuration produits et catégories
- `app/Models/ItemCategory.php`
  — champs : has_menu, default_menu_kiosk, sauce_included_menu, wizard_template
- `app/Models/Item.php`
  — variantes, extras, prix convert_price
- `app/Models/ItemExtra.php` / `app/Models/ItemAttribute.php`
  — extras et variantes produit

#### Paiement POS
- `resources/js/components/admin/pos/PaymentComponent.vue`
  — logique paiement cash et carte, validation, soumission commande

### Explicitly out of scope
- `app/Services/OrderService.php` — **frozen zone** — ne pas toucher
- `app/Services/FrontendOrderService.php` — **frozen zone** — ne pas toucher
- Toute migration base de données
- Toute modification de style ou UX cosmétique
- Intégration TPE / terminal bancaire physique
- Logique de dispatch d'événements (SMS, email, push)
- Système de fidélité (loyalty) — audit uniquement, pas de correction

---

## Problèmes connus à auditer et corriger

### P1 — Détection de template par heuristic de nom (FRAGILE)
Le champ `wizard_template` en DB est souvent vide.
KioskWizardComponent détecte le template par le nom de l'item (contains "tacos", "burger", etc.)
→ Si le nom change ou est en majuscules, le wizard tombe sur 'simple' et ignore toutes les étapes
→ **Audit** : vérifier la logique de fallback, proposer une correction robuste sans migration

### P2 — Pré-sélection incorrecte au chargement (BUG déclaré)
Certains items apparaissent déjà sélectionnés à l'ouverture du wizard
→ Vérifier l'initialisation des selections dans KioskWizardComponent et chaque step
→ Vérifier le timing Vue lifecycle (mounted vs watch) dans KioskStepGarnituresComponent

### P3 — Impossible de sélectionner deux viandes (BUG déclaré)
Sur certains produits taille XL/XXL, le compteur viande ne monte pas à 2
→ Vérifier detectViandeCount() vs _tailleMeta.viandeCount — deux sources de vérité
→ Vérifier la logique max dans KioskStepViandeComponent

### P4 — Options non visibles / items manquants (BUG déclaré)
Certains suppléments ou variantes ne s'affichent pas en wizard
→ Vérifier les filtres dans supplementList() (exclusion par group_label = 'sauce' trop agressive ?)
→ Vérifier le filtre dans KioskStepSauceComponent (null ids, items sans price)

### P5 — Format variantes asymétrique POS ≠ Kiosk
Kiosk normalise en array `[{id, variation_name, name}]`
POS stocke en objet `{variations: {attrId: varId}, names: {attrName: chosenName}}`
→ **Audit** : les deux formats arrivent-ils correctement au backend ?
→ Vérifier FrontendOrderService et OrderService acceptent les deux formats ou si l'un est silencieusement ignoré

### P6 — Menu addon pricing : hypothèse un seul addon par item
getKioskMenuAddonPrice() prend le premier addon contenant "menu"
Si un item a plusieurs addons "menu", seul le premier est utilisé
→ Vérifier avec données réelles

### P7 — Multi-produits : ordre et fusion dans le panier
Lors de commandes multi-produits (plusieurs items au wizard)
→ Vérifier la signature de merge dans kioskCart.js (ADD_ITEM)
→ Vérifier que le même produit avec des sélections différentes crée deux lignes distinctes (pas fusionné)
→ Vérifier identique sur POS (posLineAddonsSignature)

### P8 — POS : pas de wizard, mais références à pos-wizard.js absentes
ItemComponent.vue contient un commentaire `[WIZARD-SUBMIT] The pos-wizard.js dispatches 'wizard:add-to-cart'`
Le fichier pos-wizard.js n'existe pas → code mort ou implémentation manquante
→ **Audit** : vérifier si ce chemin est jamais déclenché, et si oui, quel est l'état réel

### P9 — Sauce : clés mixtes (ID numérique vs nom string)
kioskFindSauceVariation() essaie byId puis par nom
→ Risque si deux sauces ont le même nom mais des IDs différents
→ Vérifier la logique de lookup

### P10 — Paiement POS : irrégularités cash / carte
PaymentComponent : vérifier la validation du montant reçu (cash),
vérifier que le montant carte est correctement passé sans calcul client,
vérifier que les deux chemins (cash / carte) aboutissent au même état de commande

---

## Critères d'acceptation

- [ ] Template wizard détecté correctement même si `wizard_template` est vide en DB (P1)
- [ ] Aucune pré-sélection incorrecte à l'ouverture du wizard — toutes les étapes partent vierges (P2)
- [ ] Sélection de 2 viandes fonctionnelle sur taille XL/XXL (P3)
- [ ] Tous les supplements et variantes configurés s'affichent (P4)
- [ ] Format de variantes Kiosk et POS validé côté backend (P5)
- [ ] Multi-produits : deux items identiques avec sélections différentes créent deux lignes distinctes (P7)
- [ ] pos-wizard.js : état clarifié (dead code documenté ou implémenté) (P8)
- [ ] Paiement POS cash et carte : logique validée, aucune dépendance à un calcul de prix client (P10)
- [ ] Audit de tous les problèmes listés → rapport `reports/review/WIZARD_AUDIT_001.md`
- [ ] Tests : `playwright-critical-flow` sur POS Cash et Kiosk end-to-end après corrections

---

## branch_id Impact
[ ] branch_id scoping affecté par ce cycle
    — Le wizard est branch-scoped via le token kiosk (kiosk) et l'auth cashier (POS)
    — Ne pas affaiblir l'isolation : les produits et catégories chargés doivent rester filtrés par branch

## Invariants at Risk
[x] Backend pricing SSOT — le client-side total (kioskPricing.js) est indicatif uniquement
    Le backend doit recalculer — vérifier que les corrections ne déplacent pas la logique de prix vers le client
[ ] OrderStatus enum — hors scope direct mais OSS/KDS affectés si commandes mal formées
[x] branch_id data isolation — produits et catégories chargés par branch_id — ne pas casser
[ ] Dispatch after DB commit — hors scope (OrderService frozen)
[x] OrderService / FrontendOrderService symmetry — audit P5 touche le format envoyé à ces services
    → ne pas modifier ces services, mais vérifier que les deux formats sont bien acceptés
[x] Frozen zone — OrderService.php et FrontendOrderService.php : lecture seule, aucune écriture

## Anticipated Gate Conditions
[x] Human gate requis si P5 révèle que le format POS est silencieusement ignoré par le backend
    — cela impliquerait un correctif dans FrontendOrderService (zone gelée → gate obligatoire)
[x] Human gate requis si P8 révèle que pos-wizard.js manquant bloque un chemin actif en production

## Test Strategy
`playwright-critical-flow`
Flows à valider après corrections :
1. Kiosk : idle → type commande → menu avec supplément + formule frites → panier → paiement
2. POS : login caissier → sélection produit → variante + extra → paiement cash → commande KDS
3. Multi-produits : kiosk → deux produits différents → vérifier deux lignes distinctes dans panier

## PRIMARY_MODEL
[x] GPT-5.4 — complex implementation
    (audit multi-fichiers, logique wizard cross-surface, format de données, pricing)
    Planning et arbitrage final : Claude Opus 4.6

## Status
[x] Pending plan
[ ] Plan approved
[ ] In execution
[ ] Validation
[ ] Audit
[ ] Gate open
[ ] Closed
