# Manuel de l'Utilisateur - Caisse (POS)

> **Document:** Guide d'utilisation pour les caissiers  
> **Version:** 1.0  
> **Date:** 11 Mars 2026  
> **Public:** Caissiers, Managers de Restaurant

---

## Table des Matières

1. [Connexion au Système](#1-connexion-au-système)
2. [Prise de Commande](#2-prise-de-commande)
3. [Gestion des Paiements](#3-gestion-des-paiements)
4. [Impression des Tickets](#4-impression-des-tickets)
5. [Gestion des Commandes Kiosk](#5-gestion-des-commandes-kiosk)
6. [Problèmes Courants](#6-problèmes-courants)

---

## 1. Connexion au Système

### 1.1 Accéder à l'Application

1. Ouvrir un navigateur web (Chrome, Firefox, Edge)
2. Saisir l'adresse: `https://votre-restaurant.com/admin`
3. La page de connexion apparaît

```
┌─────────────────────────────────────────────────────────────┐
│                      PAGE DE CONNEXION                       │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  ┌─────────────────────────────────────────────────────────┐│
│  │  🔐 Connexion à la Caisse                              ││
│  │                                                          ││
│  │  Nom d'utilisateur:  [_________________]               ││
│  │                                                          ││
│  │  Mot de passe:       [_________________] •             ││
│  │                                                          ││
│  │  Restaurant:         [▼ Sélectionner...]               ││
│  │                                                          ││
│  │              [     SE CONNECTER     ]                  ││
│  │                                                          ││
│  └─────────────────────────────────────────────────────────┘│
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 1.2 Saisie des Identifiants

| Champ | Description | Exemple |
|-------|-------------|---------|
| **Nom d'utilisateur** | Votre identifiant fourni | `caissier1` |
| **Mot de passe** | Votre mot de passe (confidentiel) | `********` |
| **Restaurant** | Sélectionner votre établissement | `Le Grill House - Paris` |

### 1.3 Problèmes de Connexion

| Problème | Solution |
|----------|----------|
| "Identifiants invalides" | Vérifier le nom d'utilisateur (sans espaces). Attention aux majuscules/minuscules |
| "Restaurant non trouvé" | Vérifier que vous sélectionnez la bonne succursale |
| Page blanche | Rafraîchir avec F5, vérifier la connexion Internet |
| Mot de passe oublié | Contacter le manager ou l'administrateur |

---

## 2. Prise de Commande

### 2.1 Interface POS (Point of Sale)

Après connexion, l'interface s'affiche:

```
┌────────────────────────────────────────────────────────────────────────────┐
│  🏠 Le Grill House - Caisse #1                              [Déconnexion] │
├─────────────────────┬──────────────────────────────────────────────────────┤
│                     │                                      PANIER          │
│   CATÉGORIES        │  ┌──────────────────────────────────────────────────┐ │
│                     │  │ Tacos L (Poulet, Kebab)           8.50€    [X] │ │
│  [🌮 Nos Tacos  ]   │  │   + Sauce Algérienne               incl.        │ │
│  [🥪 Sandwichs  ]   │  │   + Menu (Frites+Boisson)         +3.00€        │ │
│  [🍔 Burgers    ]   │  │                                    ────────      │ │
│  [🥗 Salades    ]   │  │ SOUS-TOTAL                        11.50€        │ │
│  [🍟 Snacking   ]   │  │                                    ────────      │ │
│  [🥤 Boissons   ]   │  │ TOTAL                             11.50€        │ │
│                     │  └──────────────────────────────────────────────────┘ │
├─────────────────────┤                                                      │
│                     │  [  💵 ESPÈCES  ]  [  💳 CARTE  ]                   │
│   ITEMS             │                                                      │
│                     │  [            ANNULER COMMANDE            ]         │
│ ┌───────────────┐   │                                                      │
│ │ Tacos M       │   │  [      VALIDER LA COMMANDE       ]                 │
│ │ 6.50€         │   │                                                      │
│ └───────────────┘   └──────────────────────────────────────────────────────┘
│ ┌───────────────┐                                                          │
│ │ Tacos L       │                                                          │
│ │ 8.50€         │   NOTE: Les notifications des commandes Kiosk          │
│ └───────────────┘   apparaissent en haut à droite de l'écran               │
│ ┌───────────────┐                                                          │
│ │ Tacos XL      │   🔔 Nouvelle commande Kiosk #1025 - 24.50€            │
│ │ 10.50€        │                                                          │
│ └───────────────┘                                                          │
└────────────────────────────────────────────────────────────────────────────┘
```

### 2.2 Ajouter un Produit au Panier

**Étapes:**

1. **Sélectionner une catégorie** (colonne de gauche)
   - Cliquer sur "🌮 Nos Tacos"
   - Les items de la catégorie s'affichent

2. **Cliquer sur un item**
   - Exemple: "Tacos L"
   - Le "Wizard" de personnalisation s'ouvre

3. **Personnaliser la commande** (Wizard)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  🍴 Tacos L (2 Viandes) - Personnalisation                    [✖ Fermer]  │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  ÉTAPE 1/7 - Choix des Viandes                                             │
│                                                                             │
│  Sélectionnez 2 viandes:                                                   │
│                                                                             │
│  [✓] 🌶️ Merguez           [✓] 🥩 Kebab                                    │
│  [  ] 🍗 Poulet            [  ] 🥩 Steak haché                            │
│  [  ] 🌭 Saucisse          [  ] 🍖 Cordon bleu                             │
│  [  ] 🦐 Crevettes         [  ] 🐟 Poisson pané                            │
│                                                                             │
│  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━  │
│                                                                             │
│  Prix de base: 8.50€      Total actuel: 8.50€                             │
│                                                                             │
│  [◀ Précédent]               [  SUIVANT ▶  ]                            │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

4. **Suivre les étapes du Wizard:**
   | Étape | Description | Action |
   |-------|-------------|--------|
   | **1** | Viandes | Choisir selon taille (1-4 viandes) |
   | **2** | Sauces | 1ère gratuite, supplémentaires +0.50€ |
   | **3** | Garnitures | Salade, Tomate, Oignon (pré-cochées) |
   | **4** | Suppléments | Extras payants (Cheddar, etc.) |
   | **5** | Menu | "En Menu" (+3€), Frites (+1.50€), Boisson (+1.50€), ou rien |
   | **6** | Sauce Frites | Si frites sélectionnées |
   | **7** | Récapitulatif | Vérifier et ajouter au panier |

5. **Confirmer**
   - Cliquer "Ajouter au Panier"
   - Le produit apparaît dans le panier (colonne droite)

### 2.3 Modifier le Panier

| Action | Comment faire |
|--------|---------------|
| **Augmenter quantité** | Cliquer [+] à côté de l'item |
| **Diminuer quantité** | Cliquer [-] à côté de l'item (si = 0, item supprimé) |
| **Supprimer un item** | Cliquer [X] à côté de l'item |
| **Modifier options** | Supprimer et recréer l'item |
| **Vider le panier** | Cliquer "Annuler Commande" (confirmer) |

### 2.4 Types de Commande

Sélectionner le type AVANT de valider:

| Type | Description | Quand l'utiliser |
|------|-------------|------------------|
| **À Emporter** (Takeaway) | Client emporte sa commande | Client repart avec le plat |
| **Sur Place** (Dine-in) | Client mange au restaurant | Client s'installe à table |

---

## 3. Gestion des Paiements

### 3.1 Paiement en Espèces

**Étapes:**

1. Vérifier le total affiché dans le panier
2. Cliquer le bouton **💵 ESPÈCES**
3. Un modal s'ouvre:

```
┌─────────────────────────────────────────────────────────────┐
│  💵 PAIEMENT EN ESPÈCES                                     │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  TOTAL À PAYER:                              11.50€      │
│                                                             │
│  Montant reçu:              [____________] €              │
│                                                             │
│  ─────────────────────────────────────────────────────────  │
│                                                             │
│  Monnaie à rendre:                              0.00€       │
│                                                             │
│                                                             │
│  [     ANNULER     ]        [    VALIDER PAIEMENT    ]    │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

4. Saisir le montant reçu du client
5. Le système calcule automatiquement la monnaie à rendre
6. Vérifier avec le client
7. Cliquer "Valider Paiement"
8. Le ticket s'imprime automatiquement

### 3.2 Paiement par Carte Bancaire

**Étapes:**

1. Cliquer le bouton **💳 CARTE**
2. Un modal de confirmation apparaît:

```
┌─────────────────────────────────────────────────────────────┐
│  💳 PAIEMENT PAR CARTE                                      │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  TOTAL À PAYER:                              11.50€        │
│                                                             │
│  ─────────────────────────────────────────────────────────  │
│                                                             │
│  Passer la carte sur le TPE...                             │
│                                                             │
│  Statut: En attente...                                     │
│                                                             │
│                                                             │
│  [     ANNULER     ]        [  PAIEMENT REÇU ✓  ]          │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

3. Passer la carte du client sur le TPE (Terminal de Paiement)
4. Attendre la validation
5. Cliquer "Paiement Reçu" une fois le TPE validé
6. Le ticket s'imprime automatiquement

### 3.3 Paiement Mixte (Espèces + Carte)

Actuellement NON supporté en standard. Si besoin:
- Effectuer deux commandes séparées, OU
- Contacter le manager

### 3.4 Remise sur Commande

Si le manager a autorisé une remise:

1. Cliquer sur l'icône **🎫 Coupon** (si visible)
2. Saisir le code de réduction fourni
3. Le total se met à jour automatiquement
4. Procéder au paiement

---

## 4. Impression des Tickets

### 4.1 Impression Automatique

Par défaut, un ticket s'imprime automatiquement après chaque paiement validé.

### 4.2 Réimprimer un Ticket

Si l'impression échoue ou si le client demande une copie:

1. Aller dans **Menu > Commandes**
2. Trouver la commande concernée
3. Cliquer sur l'icône **🖨️ Imprimer**

### 4.3 Format du Ticket

```
╔═══════════════════════════════════════════════════════════╗
║           🍴 LE GRILL HOUSE - PARIS                        ║
╠═══════════════════════════════════════════════════════════╣
║  Ticket: #1024                    Date: 11/03/2026 14:32   ║
║  Caissier: Jean Dupont              Type: À Emporter       ║
╠═══════════════════════════════════════════════════════════╣
║  COMMANDE                                                  ║
╠═══════════════════════════════════════════════════════════╣
║  1x Tacos L (2 Viandes)                           8.50€  ║
║     Viandes: Poulet, Kebab                                ║
║     Sauces: Algérienne, Blanche                           ║
║     Garniture: Salade, Tomate, Oignon                     ║
║     + Menu (Frites+Boisson)                        3.00€  ║
║                                                            ║
╠═══════════════════════════════════════════════════════════╣
║  SOUS-TOTAL                                       11.50€  ║
║  TVA (20%)                                         1.92€  ║
╠═══════════════════════════════════════════════════════════╣
║  TOTAL                                            11.50€  ║
╠═══════════════════════════════════════════════════════════╣
║  PAIEMENT                                                  ║
║  Carte Bancaire                                  11.50€   ║
╠═══════════════════════════════════════════════════════════╣
║  Merci de votre visite ! À bientôt 🙏                     ║
╚═══════════════════════════════════════════════════════════╝
```

### 4.4 Problèmes d'Impression

| Problème | Vérifier | Solution |
|----------|----------|----------|
| Ticket vide | Papier thermique | Remplacer le rouleau |
| Impression déformée | Tête d'impression | Nettoyer avec alcool isopropylique |
| Pas d'impression | Connexion | Vérifier câble USB, redémarrer imprimante |
| File d'attente d'impression | Spouleur Windows/Linux | Vider la file: `services.msc` → Spouleur → Redémarrer |

---

## 5. Gestion des Commandes Kiosk

### 5.1 Notifications Kiosk

Quand un client passe commande sur la borne Kiosk:

```
┌─────────────────────────────────────────────────────────────────┐
│  🔔 NOUVELLE COMMANDE KIOSK                                     │
│                                                                 │
│  Numéro: #1025                                                  │
│  Total: 24.50€                                                  │
│  Type: À Emporter                                               │
│                                                                 │
│  Items:                                                         │
│  • 1x Tacos XL (3 Viandes) + Menu                               │
│  • 1x Burger Cheese + Menu                                        │
│  • 2x Coca-Cola                                                 │
│                                                                 │
│  [  IGNORER  ]              [  VOIR COMMANDE  ]               │
└─────────────────────────────────────────────────────────────────┘
```

**Actions:**

| Bouton | Action |
|--------|--------|
| **Ignorer** | La notification disparaît (commande visible dans la liste) |
| **Voir Commande** | Affiche les détails de la commande Kiosk |

### 5.2 Accepter une Commande Kiosk

**Étapes:**

1. Cliquer sur la notification ou aller dans **Menu > Commandes en Ligne**
2. La liste des commandes "En Attente" s'affiche
3. Cliquer sur la commande Kiosk (#1025)
4. Vérifier les items et le total

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  Commande Kiosk #1025                                        [Accepter] [✖] │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  Client: Kiosk #3                    Total: 24.50€                         │
│  Heure: 14:35                        Type: À Emporter                      │
│                                                                             │
│  ─────────────────────────────────────────────────────────────────────────  │
│                                                                             │
│  1x Tacos XL (3 Viandes) + Menu                    13.50€                   │
│     Viandes: Merguez, Poulet, Kebab                                       │
│     Sauces: Algérienne, Ketchup, Mayo                                     │
│     + Supplément Cheddar                            +1.00€                  │
│                                                                             │
│  1x Burger Cheese + Menu                           11.50€                   │
│     Sauce: Ketchup, Mayo                                                   │
│                                                                             │
│  2x Coca-Cola                                     2x2.50€ = 5.00€         │
│     (menus déduits car déjà inclus)                                       │
│                                                                             │
│  ─────────────────────────────────────────────────────────────────────────  │
│                                                                             │
│  SOUS-TOTAL                                        30.00€                   │
│  REMISE (Menu inclus)                             -5.50€                   │
│  TOTAL                                             24.50€                   │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

5. Cliquer **Accepter** → La commande passe en statut "Acceptée"
6. La cuisine (KDS) est automatiquement notifiée

### 5.3 Refuser une Commande Kiosk

Si un item est indisponible:

1. Contacter le client au Kiosk (si possible)
2. Cliquer **Refuser** sur la commande
3. Indiquer le motif: "Item indisponible"
4. La commande est annulée et le client remboursé (si paiement en ligne)

---

## 6. Problèmes Courants

### 6.1 Références Rapides

| Si cela arrive... | ...Faire ceci |
|-------------------|---------------|
| Écran figé | Rafraîchir avec F5, se reconnecter |
| Commande disparue | Vérifier dans "Commandes" → filtre "Toutes" |
| Prix incorrect | Supprimer l'item, le re-ajouter avec wizard |
| Paiement refusé | Vérifier le montant, réessayer, ou autre moyen de paiement |
| Notification sonore trop forte | Réduire le volume système |
| Client change d'avis | Annuler commande non payée, ou faire un remboursement (manager) |

### 6.2 Guide "Que Faire Si..."

#### L'Application Ne Répond Plus

```
1. Ne pas paniquer - les commandes sont sauvegardées
2. Rafraîchir la page: F5
3. Se reconnecter avec vos identifiants
4. Vérifier que la dernière commande est bien enregistrée
5. Si problème persiste: appeler le manager ou le support
```

#### Une Commande Kiosk ne S'affiche Pas

```
1. Vérifier dans Menu → Commandes en Ligne
2. Vérifier le filtre "En Attente"
3. Vérifier la connexion Internet
4. Si toujours rien: le client peut avoir annulé
```

#### Le TPE ne Fonctionne Pas

```
1. Vérifier que le TPE est allumé
2. Vérifier la connexion réseau du TPE
3. Essayer un autre moyen de paiement (espèces)
4. Appeler le support technique du TPE
```

#### Un Item est Indisponible

```
1. Notifier le client immédiatement
2. Proposer un équivalent
3. Si commande Kiosk: la refuser avec motif "Item indisponible"
4. Prévenir le manager pour mettre à jour le stock
```

### 6.3 Contacts Support

| Situation | Qui contacter | Comment |
|-----------|---------------|---------|
| Problème technique système | Support IT | Slack #support ou téléphone: 01-XX-XX-XX-XX |
| Paiement/refond | Manager | Radio/walkie-talkie ou téléphone |
| Problème matériel (imprimante, TPE) | Technicien | Appeler le numéro affiché en salle des employés |
| Urgence (sécurité) | Manager/Propriétaire | Immédiatement |

### 6.4 Maintenance Quotidienne du Poste

**Au début de chaque service:**
- [ ] Vérifier que l'imprimante a du papier
- [ ] Vérifier que le TPE fonctionne (faire un test)
- [ ] Vérifier que le son fonctionne (notifications)
- [ ] Vérifier connexion Internet

**À la fin de chaque service:**
- [ ] Vérifier que toutes les commandes sont traitées
- [ ] Imprimer le rapport "Z" (si demandé par manager)
- [ ] Laisser l'ordinateur allumé (sauf consigne contraire)

---

## Annexes

### A. Raccourcis Clavier

| Raccourci | Action |
|-----------|--------|
| `F5` | Rafraîchir la page |
| `F11` | Plein écran (recommandé) |
| `Ctrl + P` | Imprimer (page courante) |
| `Tab` | Naviguer entre les champs |
| `Entrée` | Valider/Confirmer |
| `Échap` | Annuler/Fermer modal |

### B. Glossaire

| Terme | Signification |
|-------|---------------|
| **Kiosk** | Borde interactive où les clients passent commande |
| **KDS** | Kitchen Display System - écran en cuisine |
| **OSS** | Order Status Screen - écran file d'attente |
| **TPE** | Terminal de Paiement Électronique (lecteur CB) |
| **Wizard** | Assistant de personnalisation des items |
| **POS** | Point of Sale - la caisse |
| **Menu** | Formule comprenant frites + boisson (+3€) |

### C. Checklist de Sécurité

- [ ] Ne JAMAIS partager son mot de passe
- [ ] Se déconnecter quand on quitte le poste
- [ ] Ne pas laisser le client toucher l'écran caisse
- [ ] Vérifier l'identité du client pour les commandes "À Emporter"
- [ ] Compter la monnaie soigneusement

---

**Manuel utilisateur - Caisse FoodKing.**

*Pour toute question, contacter votre manager ou consulter la documentation complète dans le dossier Manager.*
