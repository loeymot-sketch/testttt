# 📋 AUDIT E2E MASSIF ET COMPLET — Flux POS vers KDS

**Cible** : Claude (Développeur principal)
**Date** : Mars 2026
**Objectif** : Fournir un audit exhaustif, étape par étape, du parcours complet depuis la prise de commande POS jusqu'à la préparation KDS. Ce focus documente la logique métier, les bugs technico-visuels rencontrés en conditions réelles, et livre les recommandations d'architecture précises pour stabiliser le système.

---

## 🏗️ 1. Entrée de Commande (Nouveau POS Wizard)

**Parcours Testé** : `Menu POS > Tacos L (2 Viandes) > Customisation Complète > Menu (+€3.00)`

### Logique du Parcours & Observations Visuelles
1. **Initialisation** : Le clic sur "Tacos L" ouvre correctement le nouveau Wizard adaptatif (14 étapes). L'interface est fluide et le "Total provisoire" démarre correctement à **8.50€**.
2. **Choix des Viandes** : Sélection de *Merguez* et *Kefta*. Le composant JS stocke correctement les clés. Visuel : Le highlight fonctionne.
3. **Choix des Sauces & Garnitures** : 
   - Sauce 1 (*Algérienne*) ajoutée, marquée "Gratuite".
   - Garnitures passées ou ajoutées normalement.
4. **Suppléments (Personnalisation)** :
   - Ajout *Supplément Cheddar (+€1.00)*.
   - ⚠️ **Succès technique** : Le "Total provisoire" s'actualise correctement (passe à **9.50€**).
5. **Étape Formule (`menu_choice`)** :
   - Choix de *En Menu (Frites + Boisson)* (+€3.00).
   - ⚠️ **Bug Logique & Visuel Dépisté** : Au moment de la sélection, bien que le clic soit intercepté par le handler, le "Total provisoire" ne persiste pas toujours ce choix lors de la navigation vers les étapes suivantes (Options frites, Boissons) sur le Frontend Visuel.
6. **Étape Frites & Boisson** :
   - Sélection *Grande portion (+€1.00)* et *Cheddar Fondu (+€1.00)*.
   - Sélection boisson incluse.

### 🔴 Bugs Critiques Identifiés (Phase 1)
- **BUG-WIZARD-RECAP-TOTAL** : Sur la page **Récap final** du wizard, le total affiché est illogiquement retombé à **9.50€** (Prix de base + Supplément Cheddar) au lieu des **14.50€** attendus.
  - *Symptôme Visuel* : Le récapitulatif affiche les viandes, sauces, et suppléments, mais on ne voit **aucune trace de la Formule (Menu) ou des Options de Frites**.
  - *Cause Technique* : Bien que les inputs cachés stockent les bonnes valeurs pour le panier, l'affichage `renderRecapStep()` échoue à parser le nouveau type d'étape `menu_choice` ou perd la référence state `selections.menuChoice`.

---

## 🛒 2. Checkout & Paiement (PosComponent.vue)

**Parcours Testé** : `Ajouter au panier > Validation du Panier > Paiement Cash (Exact Amount)`

### Logique du Parcours & Observations Visuelles
1. **Panier Latéral (Cart)** :
   - Le bouton "Ajouter au panier" ferme le modal et transfère l'item au composant Vue.
   - ⚠️ **Paradoxe des Totaux** : Magiquement, le panier Vue affiche un sous-total correct de **14.50€**, contredisant le total buggé du Wizard de 9.50€. Cela prouve que `syncAndSubmit()` transmet les formulaires HTML cachés correctement au serveur, mais que l'affichage client JS était désynchronisé.
   - **Instructions (Ticket)** : Le formattage est impeccable : `VIANDES: Merguez, Kefta. SUPPLÉMENTS: Supplément Cheddar. FORMULE: Menu Complet (Frites + Boisson). FRITES: Grande portion (+€1.00), Cheddar (+€1.00). SAUCE FRITES: Samouraï.`
2. **Assignation Client (`customer_id`)** :
   - La sélection du "Walking Customer" dans le menu déroulant doit parfois être forcée manuellement (cliquer et re-sélectionner) pour que le binding de `customer_id` se mette à jour dans l'état Vue. Sans cela, le clic sur "Order" ne déclenche pas le modal.
3. **Paiement (Modal Cash)** :
   - Le clavier numérique fonctionne (- *clic 1*, *clic 4*, etc.).
   - L'envoi avec l'Amount exact lance la confirmation.

### 🔴 Bugs Critiques Identifiés (Phase 2)
- **BUG-API-422-TOKEN** : Le backend rejette silencieusement la commande avec une erreur HTTP 422 si l'utilisateur saisit un nombre (ex: `123`) dans le champ "Token No".
  - *Cause Technique* : Le FormRequest Laravel exige explicitement que le champ `token` soit de type `string`. L'input Vue l'envoie sous forme d'entier (`int`).
  - *Symptôme* : Clic sur "Confirm Payment" -> Rechargement invisible du modal, aucune commande passée, expérience bloquante pour le caissier.
- **BUG-CUSTOMER-BINDING** : État déconnecté entre l'UI Select et le modèle de données composant lors du chargement initial de la page POS, obligeant un "double-clic" sur le nom du client.

---

## 🍳 3. Cuisine & Affichage KDS (/admin/pos/kds)

**Parcours Testé** : `Validation Commande #11032622 > KDS View > Transition de Status ("Accepted" -> "Preparing" -> "Prepared")`

### Logique du Parcours & Observations Visuelles
1. **Création du Ticket & Reçu** :
   - La commande #11032622 est correctement insérée en BDD.
   - Le ticket d'impression POS PDF/Modal (*Order Type: Takeaway, Payment: Cash*) affiche lisiblement les informations concaténées dans le champ `Instruction`.
   - Les "produits liés" *(Menu, Frites, Boisson)* figurent sur le reçu avec les bons tarifs unitaires.
2. **Synchronisation KDS Live** :
   - Navigation vers l'onglet KDS pour visualiser la commande entrante (Onglets : *All Orders*, *Confirmed*, *Preparing*, *Done*).
   - ⚠️ **KDS Board Vide** : La commande passée n'apparaît **absolument pas** sur le dashboard KDS.
3. **Status Sync Backoffice** :
   - En modifiant manuellement la vue Backoffice "POS Orders", on observe que le Dropdown des statuts marche parfaitement.
   - Malgré le changement de "Confirmed" vers "Preparing" ou "Prepared", la commande reste toujours invisible sur l'écran cuisine de la "Branch" Mirpur-1 (Branch ID: 1).

### 🔴 Bugs Critiques Identifiés (Phase 3)
- **BUG-KDS-SILENT-FAILURE** : Ordres POS invisibles en Cuisine.
  - *Cause Potentielle 1* : L'API `/api/frontend/kds-order` filtre de manière stricte sur `source_type` (ex: ignorant les commandes rentrées en POS direct) ou il y a un problème avec le `branch_id`.
  - *Cause Potentielle 2* : Les commandes POS n'assignent pas le statut de stock ou d'étape KDS adéquat (Status = 5 au lieu de Status = ACCEPT).

---

## 🎯 Feuille de Route d'Implémentation Écossaise (Pour Claude)

Pour finaliser ce système "Tiroir-Caisse vers Cuisine" de qualité Prod, voici tes missions techniques à prioriser :

1. **(Frontend Vanilla JS) Réparer l'Affichage Récap du Wizard (`pos-wizard.js`)**
   - Tracer la variable `selections.menuChoice` et sa persistance. 
   - Vérifier comment `renderRecapStep()` collecte les prix des `menu_choice` (vérifier l'accès à `menuComplet`, `fritesSeules`). S'assurer de synchroniser l'affichage avec la même arithmétique que `calculateRunningTotal()`.

2. **(Frontend Vue.js) Stabiliser l'Order Submit (`PosComponent.vue`)**
   - **Formater le Token** : Avant d'émettre l'action Vuex, forcer le typage du Token : `this.checkoutProps.form.token = String(this.checkoutProps.form.token || "");` pour éviter le 422.
   - **Binding Client Initial** : Dans le `mounted()` du POS, dès la data `user/lists` fetchée, s'assurer d'émettre l'évènement de mise à jour pour que le `v-model` et l'UI select soient d'emblée synchronisés, rendant le bouton Order réactif instantanément.

3. **(Backend Laravel / Vue KDS) Débloquer le Hub Cuisine**
   - Analyser le contrôleur ou Endpoint qui alimente le KDS (vraisemblablement `KdsOrderController` ou un scope de `Order`).
   - S'assurer que les commandes avec `order_type = POS` (2) ou `Takeaway` sont bien éligibles à l'affichage si le composant Frontend les requête.

---
*Audit réalisé via session massive E2E Antigravity, port incluant les dernières rectifications de la Phase 4.*
