# 🧠 E2E MASSIVE LOGIC AUDIT — Parcours Client & Caissier (Wizards)
**Date :** 15 Mars 2026  
**Auteur :** AntiGravity (Logique & Intelligence Artificielle)  
**Sujet :** Audit de la prise de commande complexe (2 sauces, crudités, suppléments, menus).

---

## 🎯 1. Introduction & Objectif du Système

Notre but est de fournir un système hybride rapide pour le caissier (POS) et immersif pour le client (Kiosk/Borne), tout en gardant une base de données MySQL "Source of Truth" 100% fiable pour le KDS (Cuisine).
L'audit profond du code et de la base a permis de remonter **la manière exacte dont le système réagit actuellement**, et de déceler une **faille de logique critique** sur le parcours des crudités.

---

## 🔍 2. Audit Logique : "Prendre la place du Client et du Caissier"

Voici comment le code et la base de données interagissent actuellement lorsqu'un utilisateur sélectionne un produit complexe (ex: Sandwich ou Tacos).

### 🍔 A. La Mécanique des "2 Sauces" (1 Gratuite, 1 Payante)
**Logique Système :**  
- **1ère Sauce (Gratuite) :** Stockée en base comme une `ItemVariation` associée à l'attribut "Sauce (1ère Gratuite)".  
- **2ème Sauce (Payante, ex: +0.50€) :** Stockée en base comme un `ItemExtra` (ex: "Sauce supplémentaire: Algérienne").  

**Côté Caisse (POS - `pos-wizard.js`) :**  
✅ **Statut : FONCTIONNEL MAIS FRAGILE**  
Le dev a implémenté un hack intelligent : lorsque le caissier sélectionne 2 sauces dans le wizard, l'interface conserve le premier ID pour la `Variation` (1ère sauce), et pour la seconde sauce, le JS scanne le DOM caché à la recherche d'un texte contenant *"Sauce supplémentaire: [Nom]"* et simule un `click()` physique sur la checkbox. Ça marche.

**Côté Client (Borne - Kiosk) :**  
✅ **Statut : FONCTIONNEL**  
L'étape `KioskStepSauceComponent` limite le choix à 1 variation gratuite et ajoute correctement l'ID de l'extra payant dans `item_extras` pour la 2ème sauce. Pas de souci côté facturation.

---

### 🥗 B. Le Choix des Crudités (La Faille Critique)
C'est ici que l'intelligence du système s'effondre à cause d'un décalage entre le Backend et le Frontend.

**Logique Base de Données (`MenuSeeder`) :**  
Les crudités (Salade, Tomate, Oignon, Complet) sont actuellement semées sous forme de **Variations** (`ItemVariation`) rattachées à l'attribut "Garnitures".  
*Exemple : Un groupe de boutons Radio, où l'on ne peut faire qu'un seul choix (Soit Complet, Soit Sans Tomate).*

**Logique Frontend POS (`pos-wizard.js`) & Borne (`KioskStepGarnitures.vue`) :**  
❌ **Statut : BUG CRITIQUE & DANGER FACTURATION**  
Le code Frontend a été écrit pour chercher les garnitures dans la liste des **Extras Gratuits** (`extras` avec `price <= 0`).
Puisque la base de données ne les fournit pas en tant qu'Extras, la liste est systématiquement **vide**.

**Conséquences désastreuses :**
1. Sur le POS, l'étape "Garnitures" sera quasiment vide ou invisible.
2. Sur la Borne, le code contient un `fallback` (`getDefaultGarnitureList()`) qui charge une liste fausse codée en dur (ID 1: Salade, ID 2: Tomate, ID 3: Oignon, ID 4: Cornichons).
3. **Le Danger :** Si le client décoche la "Salade" (ID 1) sur la borne, le Frontend transmet cet ID 1 au backend comme un Extra sélectionné/désélectionné. Or, dans la réalité de la base de données, l'Extra ID 1 correspond à **"Jambon de Dinde (+1.00€)"**.
*Résultat : Le client clique sur "Sans Salade" et se retrouve à payer du Jambon !*

---

### 🥓 C. Les Suppléments (Cheddar, Oeuf, Boursin)
**Logique Système :**  
Stockés comme `ItemExtra` avec un prix > 0 (ex: 1.00€).

✅ **Statut : 100% FONCTIONNEL**  
Tant sur le POS que sur la Borne, ces suppléments payants sont proprement filtrés (`price > 0`) et affichés sous l'étape "Suppléments". L'addition au total se fait de manière sécurisée en base.

---

### 🍟 D. Les Formules (Menu, Frites, Boisson)
**Logique Système :**  
Gérés via la table `ItemAddon` (Upsell items) avec des prix de substitution (ex: +3.00€ pour le Menu Complet, +1.50€ pour Boisson Seule).

✅ **Statut : FONCTIONNEL**  
Le POS Wizard utilise un nouveau flow à 3 options qui force la sélection de la Formule avant de proposer la taille des frites ou les boissons. Ce module a été stabilisé lors du Sprint précédent.

---

## 🛠️ 3. Recommandation Corrective : Plan d'Action "Max Power"

**Le problème principal ne vient pas du Frontend, mais de la Base de Données (Le Seeder).**  
Les garnitures / crudités relèvent d'un fonctionnement typique de **Checkboxes** (choix multiples facultatifs), ce qui correspond à la définition logicielle des `Extras`, et non des `Variations` (qui sont des choix uniques type Radio).

### ÉTAPES DE CORRECTIONS À LANCER IMMÉDIATEMENT :

1. **Refonte du `MenuSeeder.php` :**
   - Supprimer `ItemAttribute` -> "Garnitures".
   - Injecter les Crudités (Salade, Tomate, Oignon, Cornichons) dans la table `ItemExtra` avec un champ `price = 0.00`.
   - Ne lier ces Extras gratuits qu'aux Sandwichs et Burgers (pas les salades, ni les desserts).

2. **Nettoyage du Frontend :**
   - Retirer la fonction "secours" `getDefaultGarnitureList()` dans Kiosk, pour forcer l'interface à dépendre à 100% du Backend (Single Source of Truth).
   - Supprimer le mapping radio / bouton unique pour les crudités dans `pos-wizard.js` et s'assurer que les multi-check de la BDD s'affichent.

### Conclusion de l'Audit :
La structure des **prix** et la **sécurité d'isolement KDS** sont solides. Cependant, la définition ambigüe des crudités dans notre système hybride (DB = Variation vs JS = Extra) provoque une altération silencieuse de la commande. **Avant de déployer physiquement l'application, l'alignement de la BDD sur le modèle d'Extras pour les Crudités est impératif.**
