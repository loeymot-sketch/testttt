# Task – UX_FLOW_001

## Description
Audit UX profond et corrections fonctionnelles sur toutes les surfaces FoodKing accessibles :
POS, KDS, Kiosk (borne), OSS, Delivery, Waiter, Admin.

Objectif : chaque surface doit être utilisable par un vrai utilisateur sans blocage,
sans comportement incohérent, sans état cassé. Corrections de logique et d'UX uniquement —
aucune refonte visuelle, aucune migration DB, aucun ajout de feature.

Ce cycle fait suite au WIZARD_AUDIT_001 (corrections wizard terminées, 191 tests PHPUnit, 10 tests
Playwright passés). Il prend en charge tout ce qui était hors-scope de WIZARD_AUDIT_001 :
états vides, boutons non fonctionnels, flows interrompus, messages d'erreur manquants,
redirection incorrecte, comportement incohérent entre surfaces.

---

## Surfaces à auditer et corriger

### POS — Caisse
- Sélection catégorie → produit → modal variante : vérifier que tous les états s'ouvrent
- Ajout au panier : confirmation visible, total mis à jour
- Wizard POS (pos-wizard.js actif — P8 confirmé) : flow complet sans blocage
- Paiement cash : champ montant reçu, rendu monnaie calculé et affiché
- Paiement carte : validation sans calcul client
- Commande soumise : feedback visible (loading, succès, erreur)
- Panier vide : état géré (pas de soumission vide possible)
- Multi-produits : deux lignes distinctes pour le même produit avec sélections différentes

### KDS — Cuisine
- Chargement de la liste des commandes au login chef
- Changement de statut : PREPARING → PREPARED → transition visible immédiatement
- Commande sans items : état géré proprement
- Refresh manuel : les nouvelles commandes apparaissent
- Filtre par statut si présent : fonctionne correctement

### Kiosk — Borne
- Écran idle → sélection type commande (Sur place / À emporter) : les deux boutons fonctionnent
- Navigation catégories dans la sidebar : scroll, sélection, highlight actif
- Wizard produit : toutes les étapes s'affichent selon le template (P1 corrigé — vérifier en prod)
- Sélection viande XL/XXL : compteur fonctionne (P3 corrigé — vérifier)
- Supplements : liste complète visible (P4 corrigé — vérifier)
- Menu formule : full / frites / boisson — sous-sélection boisson fonctionne
- Récap commande : totaux corrects, modification quantité fonctionne
- Panier : items distincts pour sélections différentes (P7 corrigé — vérifier)
- Upsell dessert : déclenché si absent du panier
- Timeout idle 3 min : reset propre sans état résiduel

### OSS — Order Status Screen
- Affichage des commandes en cours avec statut correct
- Pas d'erreur si aucune commande en cours (état vide géré)
- Transition de statut visible en temps réel (Pusher / polling)
- Accès direct par URL : surface chargée correctement

### Delivery — Livreur
- Login livreur → liste commandes à livrer
- Détail commande : adresse, client, items visibles
- Changement de statut livraison fonctionnel
- Filtrage par statut

### Admin
- Navigation tableau de bord : tous les modules accessibles
- Gestion bornes (KioskMachine) : ajout / modification / activation — kioskAutoLogin configuré
- Gestion catégories/produits : wizard_template modifiable depuis l'admin
- Pas d'erreur 403 sur les routes admin pour le rôle admin@lecayenne.fr

---

## Critères d'acceptation

- [ ] POS : flow complet cash (login → produit → panier → paiement → commande créée) sans blocage
- [ ] POS : paiement carte validé sans calcul client-side
- [ ] KDS : changement de statut PREPARING → PREPARED visible immédiatement
- [ ] Kiosk : wizard tacos XL (2 viandes + sauce + formule frites) → panier sans blocage
- [ ] Kiosk : timeout idle reset → état propre
- [ ] OSS : affichage commandes + état vide géré
- [ ] Tous les états vides gérés (pas de JS crash sur liste vide)
- [ ] Playwright : `playwright-critical-flow` — 3 flows passent après corrections :
      1. Auth F5 sur POS → reste sur /admin/pos
      2. Login POS → surface chargée sans erreur JS
      3. Login chef → redirection /admin/kitchen-display-system
- [ ] PHPUnit : 191 tests minimum — aucune régression

---

## Périmètre

**In scope :**
- `resources/js/components/admin/pos/` — POS complet
- `resources/js/components/admin/kitchenDisplaySystem/` — KDS
- `resources/js/components/frontend/kiosk/` — Kiosk (wizard + navigation)
- `resources/js/components/admin/orderStatusScreen/` — OSS
- `resources/js/components/admin/delivery/` — Delivery
- `resources/js/components/admin/` — Admin général
- `resources/js/store/modules/` — Stores Vuex concernés
- Routes Vue et guards correspondants

**Explicitly out of scope :**
- `app/Services/OrderService.php` — frozen zone
- `app/Services/FrontendOrderService.php` — frozen zone
- Migrations DB
- Intégration TPE physique
- Système de fidélité
- Refonte visuelle / changement de styles CSS

---

## branch_id Impact
[x] branch_id scoping affecté — toutes les surfaces non-admin chargent des données branch-scopées
    Ne pas affaiblir l'isolation

## Invariants at Risk
[x] Backend pricing SSOT — vérifier que les corrections UX n'introduisent pas de calcul de prix côté client
[x] OrderStatus enum — transitions de statut KDS doivent utiliser l'enum, pas des strings hardcodés
[x] branch_id data isolation — pas de cross-branch data visible
[ ] Dispatch after DB commit — hors scope
[x] Frozen zone — OrderService et FrontendOrderService : lecture seule

## Anticipated Gate Conditions
[x] Human gate si une correction UX nécessite de modifier FrontendOrderService
[x] Human gate si un bug OSS révèle un problème de synchronisation Pusher (hors scope UX)

## Test Strategy
`playwright-critical-flow`
Flows obligatoires après corrections :
1. Auth F5 — /admin/pos → F5 → reste sur /admin/pos
2. POS Cash — login caissier → surface POS chargée sans crash JS
3. KDS — login chef → /admin/kitchen-display-system chargé
Flows supplémentaires si temps disponible :
4. Kiosk — /kiosk/login accessible, config pricing présente
5. OSS — /order-status accessible sans crash

## PRIMARY_MODEL
[x] GPT-5.4 — complex implementation
    (multi-surface, UX state management, Vuex stores, Vue lifecycle)
    Planning, arbitrage, audit final : Claude Opus 4.6

## Status
[x] Pending plan
[ ] Plan approved
[ ] In execution
[ ] Validation
[ ] Audit
[ ] Gate open
[ ] Closed
