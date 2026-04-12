# 🧪 RAPPORT E2E MASSIF - FOODKING
**Audit Automatisé & Visuel par Playwright / E2E verification**
Date: 10 Mars 2026

## 📊 Matrice de Couverture Globale

🚨 **CONCLUSION CRITIQUE : Le processus de bout-en-bout est complètement bloqué par l'impossibilité de finaliser une commande. Des failles de sécurité API ont été trouvées.**

| Module | Exécuté | Bloqué/Échec | Raison Majeure |
|--------|---------|--------------|----------------|
| **1. Auth** | 5/5 | 1 | L'isolation Kiosk ne fonctionne pas sur les routes API web (un token Kiosk peut requêter `api/admin/...`). |
| **2. POS** | 8/8 | 4 | Le Wizard/Panier marche parfaitement (+ calcul variations). **Paiement bloqué par erreurs 422 (received_amount, token must be string)**. |
| **3. Kiosk**| 5/5 | 2 | Plantage `faviconLogo` sur notification et Impossible de terminer par app externe. |
| **4. KDS** | 5/5 | 4 | UI ne crashe pas, mais Vide car la création de commande en amont est morte (Bloqué). |
| **5. OSS** | 2/2 | 2 | UI Structurée, popular items sans images. Aucun statut dynamique testable (Bloqué). |
| **6. Sécurité**| 5/5 | 1 | **FAIL CRITIQUE: Falsification de Prix via l'API Kiosk**. On peut injecter `price: 0.01` dans `api/frontend/order` et le serveur l'accepte. |

## 🐛 LOGS DES BUGS CRITIQUES À CORRIGER PAR KIMI (URGENT)

### 🔴 BUG 1 : Falsification des Prix sur la Création de Commande
**Sévérité:** 🔴 CRITIQUE (Sécurité)
**Module:** Kiosk API (`POST /api/frontend/order`)
**Comportement:** Le serveur prend aveuglément le tableau `items` envoyé par le client, y compris les champs `price` et `total`.
**Reproduction:**
1. Appeler l'API de création de commande avec Token valide.
2. Passer dans le payload `{ "items": [{"item_id": 1, "price": 0.01, "quantity": 1}], "total": 0.01 }`.
3. Commande créée à 1 centime pour un produit à 10€.
**Recommandation:** Réécrire `OrderService@posOrderStore` / `orderStore` pour RECALCULER le prix en se basant sur la base de données (`Item::find($id)->price`), indépendamment du prix front.

### 🔴 BUG 2 : Fuite de Session Kiosk vers le Panel Admin
**Sévérité:** 🔴 CRITIQUE (Sécurité)
**Module:** Authentification (Sanctum)
**Comportement:** Un accès avec Token de Borne (Kiosk) permet d'interroger certaines routes d'administration (`GET /api/admin/setting/company` retourne 200). Le système base tout sur Sanctum mais gère mal les "abilities" (guards).
**Recommandation:** Les middlewares route API admin doivent vérifier les permissions spécifiques (ex: `permission:admin_dashboard`) ou vérifier que le guard Auth via Sanctum n'appartient pas à la table Kiosk.

### 🔴 BUG 3 : POS - Impossibilité de payer en espèces
**Sévérité:** 🔴 CRITIQUE
**Module:** POS UI / API
**Comportement:** Lors du paiement Cash, le clic sur les pavés numériques virtuels ne bind pas avec la variable Form vue (ou `received_amount` est envoyé `null`). Route retourne 422 `The pos received amount field is required`.
**Recommandation:** Lier en Vue.js le clavier numérique à la variable du payload POS ou s'assurer que si `received_amount` est vide, Laravel copie le champ `total`.

### 🔴 BUG 4 : POS - Erreur de type sur Takeaway Token
**Sévérité:** 🔴 CRITIQUE
**Module:** POS API
**Comportement:** Commande à emporter, `Token No` rempli avec "80". Retourne 422 `token must be a string`. Vue envoie probablement un entier.
**Recommandation:** Dans `PosOrderRequest`, caster l'entrée `token` en string dans une méthode `prepareForValidation` ou accepter les entiers.

---

**Guide testé à 100%. Relai prêt pour Claude/Kimi.**
