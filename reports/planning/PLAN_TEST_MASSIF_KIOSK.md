# 📱 MASTER PLAN : Tests Massifs BORNE (Kiosk Android) - PHASE 2

Ce document définit les tests exhaustifs qui seront menés par **Anti-Gravity** sur l'application Kiosk Android (via API/Flutter) **UNE FOIS que la Phase 1 (CAISSE POS) aura été validée à 100% avec 0 bug critique**.

Les rapports respecteront le template imposé dans `workflows/report-format.md`.

---

## 🔑 MODULE 2.1 : Déploiement & Authentification de la Borne
> Surface : `App Startup`, `API login`, `Hardware`

**Scénarios Prévus :**
- Lancement de l'app sans token (doit exiger le QR Code / Auth Screen).
- Authentification avec les credentials liés à une `KioskMachine` d'une branche spécifique.
- Vérification du token persistant (`kiosk:order` ability).
- Vérification que la borne ne charge QUE les produits de sa `branch_id`.
- Déconnexion distante forcée depuis l'Admin Dashboard.

---

## 🍔 MODULE 2.2 : Cycle de Commande Client (Menu Tacos/Burger)
> Surface : `UI Flutter`, `/api/frontend/item`, `/api/frontend/order`

**Scénarios Prévus :**
- **Tacos L (2 Viandes) :** Validation de la limite stricte de 2 viandes sur l'écran Flutter.
- **Tacos L avec Supplément :** Choix d'une sauce payante (+0.50€), supplément Fromage (+1.00€).
- **Transformation en Menu :** Ajout de la boisson et de la frite.
- **Annulation/Retour en arrière :** Le client annule son flow à l'étape des garnitures, le panier reste vide.
- Vérification du total dynamiquement affiché sur la Borne.

---

## 🛒 MODULE 2.3 : Panier & Options de Paiement
> Surface : `Cart Logic`, TPE Integration, Checkout Flow

**Scénarios Prévus :**
- Ajout de 3 items différents.
- Modification des quantités (+ / -) dans le panier récapitulatif.
- Retrait d'un item.
- **Paiement Carte (TPE) :** Le client sélectionne Carte, validation de l'intégration (mock/simulation TPE).
- **Paiement Espèces (Comptoir) :** Modèle "Payez au comptoir". Le ticket est généré avec "Unpaid", on vérifie que le POS caisse le voit bien en jaune (Pending paiement).

---

## 🍽️ MODULE 2.4 : Synchronisation KDS / POS (L'épreuve de vérité)
> Surface : `Firebase`, `OrderService`, `WebSockets`

**Scénarios E2E Prévus :**
- Borne Android confirme une commande Carte Bancaire.
  - Vérifier : La commande "Pop" immédiatement (moins de 3s) sur l'écran **KDS Cuisine** de la même branche.
  - Vérifier : La commande "Pop" sur le **Dashboard Admin** (Nouveau chiffre d'affaires).
  - Vérifier : L'écran client (OSS) affiche "EN PRÉPARATION - Ticket A042".
- Borne Android confirme une commande Espèces.
  - Vérifier : La caisse (POS) reçoit une alerte "À ENCAISSER".
  - Vérifier : Le chef (KDS) voit la commande pour commencer la préparation.

---

## 🔐 MODULE 2.5 : Sécurité Kiosk & Continuité
> Surface : `Rate Limiter`, `Payload tampering`, `Offline Mode`

**Scénarios Prévus :**
- *Le Hacking du Kiosk :* Interception réseau, on envoie un prix `item_price` de `0.00€` depuis la borne. 
  - Vérifier : Le backend (Serveur) utilise la règle SSOT et sauvegarde le vrai ticket à `12.50€`.
- *Spam (Rate Limiting) :* Le client appuie 50 fois par seconde sur "Payer". 
  - Vérifier : Une seule commande est créée en BDD.
- *Rupture Réseau :* Le client valide, la connexion lâche. 
  - Vérifier : Gestion d'erreur gracieuse sur la UI, pas de crash blanc, tentative de reconnexion.

---

**🟢 Plan gelé en attente de l'Axe 1 (POS).**
