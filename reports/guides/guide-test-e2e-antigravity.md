# 🧪 GUIDE DE TEST E2E - Playwright / E2E verification

> **Pour:** Playwright / E2E verification (QA Expert)  
> **Date:** 10 Mars 2026  
> **Mission:** Tester le parcours complet end-to-end du système FoodKing

---

## 📋 PRÉPARATION DES TESTS

### Environnement Requis:
```bash
# URL Base: http://localhost:8000 (ou votre environnement de test)
# Credentials Admin: admin@inilabs.dev / password
# Credentials Kiosk: username: kiosk123 / password: password123
```

### Outils:
- Navigateur Chrome/Firefox avec DevTools ouvert (Console + Network)
- Extension Vue.js DevTools (pour déboguer les composants)
- Postman (pour tester API directement si besoin)

---

## 🎯 SCÉNARIOS DE TEST E2E

### MODULE 1: AUTHENTIFICATION (5 tests)

#### Test 1.1: Login Admin Valide
**Parcours:**
1. Aller sur `/admin/login`
2. Saisir `admin@inilabs.dev` / `password`
3. Cliquer "Login"

**Résultat attendu:**
- Redirection vers `/admin/dashboard`
- Token Sanctum présent dans cookies/localStorage
- Menu latéral visible avec options (Dashboard, POS, Orders, etc.)

#### Test 1.2: Login Kiosk Machine
**Parcours:**
1. Appeler API `POST /api/auth/kiosk-login`
2. Body: `{ "username": "kiosk123", "password": "password123" }`
3. Header: `x-api-key: [votre_cle_api]`

**Résultat attendu:**
- Status: 200
- Response: `{ "token": "...", "branch_id": 1 }`
- Token avec ability `kiosk:order`

#### Test 1.3: Session Kiosk Unique
**Parcours:**
1. Login Kiosk avec `kiosk123` → Succès
2. Tentative 2ème login avec même `kiosk123` → Doit échouer

**Résultat attendu:**
- Status: 400 ou 403
- Message: "Already logged in" ou similaire

#### Test 1.4: Accès Non Autorisé
**Parcours:**
1. Prendre token Kiosk
2. Essayer d'accéder à `GET /api/admin/dashboard` avec ce token

**Résultat attendu:**
- Status: 401 ou 403
- Accès refusé

#### Test 1.5: Timeout Session
**Parcours:**
1. Login Admin
2. Attendre 2+ heures (ou modifier token pour expiration)
3. Rafraîchir page

**Résultat attendu:**
- Redirection vers login
- Token expiré/invalide

---

### MODULE 2: PARCOURS POS - COMMANDE CASH (8 tests)

#### Test 2.1: Sélection Item avec Wizard
**Parcours:**
1. Login Admin → Accéder à POS (`/admin/pos`)
2. Cliquer sur catégorie "Nos Tacos"
3. Cliquer sur "Tacos L (2 Viandes)"

**Résultat attendu:**
- Modal wizard s'ouvre (pas le modal standard)
- Étape 1: Choix Viande 1 et Viande 2 (2 sélections requises)
- Étape 2: Choix Sauce (1 gratuite, suivantes +0.50€)
- Étape 3: Garnitures (Salade/Tomate/Oignon pré-cochées)
- Étape 4: Suppléments (liste des extras)
- Étape 5: Menu (checkbox "En Menu" +3€)
- Étape 6: Sauce Frites (si menu ou frites sélectionnés)
- Étape 7: Récap avec prix total

#### Test 2.2: Validation Viandes (Logique M/L/XL/XXL)
**Parcours:**
1. Tester Tacos M → Doit demander 1 viande SEULEMENT
2. Tester Tacos L → Doit demander 2 viandes
3. Tester Tacos XL → Doit demander 3 viandes
4. Tester Tacos XXL → Doit demander 4 viandes

**Résultat attendu:**
- Impossible d'ajouter au panier si pas assez de viandes sélectionnées
- Message d'erreur clair: "Veuillez choisir X viandes"

#### Test 2.3: Calcul Sauce (1 gratuite, suivantes payantes)
**Parcours:**
1. Sélectionner item avec sauce
2. Choisir 1 sauce → Prix: 0€
3. Choisir 2ème sauce → Prix: +0.50€
4. Choisir 3ème sauce → Prix: +1.00€

**Résultat attendu:**
- Prix total mis à jour en temps réel
- Affichage "1ère gratuite, +€0.50 par supplément"

#### Test 2.4: Panier et Quantité
**Parcours:**
1. Ajouter 1x Tacos L au panier
2. Changer quantité à 3
3. Vérifier prix total = prix unitaire × 3

**Résultat attendu:**
- Prix recalculé correctement
- Modifications répercutées sur total général

#### Test 2.5: Paiement Cash avec Pavé Numérique ⭐ CRITIQUE
**Parcours:**
1. Avoir items dans panier (total: 15.50€)
2. Cliquer "Payer"
3. Choisir "Cash"
4. Saisir via pavé numérique: `2` `0` `.` `0` `0` → 20.00€
5. Cliquer "Confirm & Print Receipt"

**Résultat attendu:**
- Modal fermé
- Commande créée (vérifier via API ou liste commandes)
- Montant reçu: 20.00€ enregistré
- Monnaie à rendre: 4.50€
- Ticket s'imprime (ou s'affiche)
- **PAS d'erreur 422**

#### Test 2.6: Paiement Carte (TPE)
**Parcours:**
1. Avoir items dans panier
2. Cliquer "Payer"
3. Choisir "Carte (TPE)"
4. Saisir 4 derniers digits: `1234`
5. Confirmer

**Résultat attendu:**
- Commande créée avec `pos_payment_method = CARD`
- `pos_payment_note = "1234"` enregistré
- Pas d'erreur

#### Test 2.7: Token No sur Takeaway
**Parcours:**
1. Sélectionner type "Takeaway"
2. Saisir Token: `5001`
3. Finaliser commande

**Résultat attendu:**
- Commande créée avec `token = "5001"`
- **PAS d'erreur "token must be a string"**

#### Test 2.8: Ticket de Caisse
**Parcours:**
1. Finaliser une commande
2. Vérifier le ticket affiché

**Résultat attendu:**
- Nom du restaurant
- Date/heure
- Liste des items avec variations détaillées
- Prix unitaires et total
- QR code ou numéro de commande
- Si Takeaway: numéro Token affiché

---

### MODULE 3: PARCOURS KIOSK (5 tests)

#### Test 3.1: Login Kiosk via API
**Parcours:**
1. Appeler `POST /api/auth/kiosk-login`
2. Récupérer token

**Résultat attendu:**
- Token valide retourné
- Kiosk marqué comme `is_login = true`

#### Test 3.2: Création Commande Kiosk
**Parcours:**
1. Avec token Kiosk, appeler `POST /api/frontend/order`
2. Body:
```json
{
  "order_type": 10,
  "branch_id": 1,
  "subtotal": 10.00,
  "total": 10.00,
  "delivery_charge": 0,
  "is_advance_order": 0,
  "source": 10,
  "items": "[{\"item_id\":1,\"price\":10,\"quantity\":1}]"
}
```

**Résultat attendu:**
- Status: 201
- Order créé en DB
- **PAS d'erreur 500 faviconLogo**

#### Test 3.3: Commande Kiosk avec Variations
**Parcours:**
1. Créer commande avec variations (viandes, sauces)
2. Vérifier en DB que `item_variations` est bien stocké en JSON

**Résultat attendu:**
- JSON valide dans `order_items.item_variations`
- Structure: `[{"name": "Viande 1", "value": "Poulet"}, ...]`

#### Test 3.4: Notification KDS
**Parcours:**
1. Créer commande Kiosk
2. Vérifier que le KDS reçoit la notification

**Résultat attendu:**
- Push notification envoyée au KDS
- Son/audio joué
- Commande visible dans liste KDS

#### Test 3.5: Isolation Kiosk
**Parcours:**
1. Avec token Kiosk, essayer `GET /api/admin/dashboard`

**Résultat attendu:**
- Status: 401 ou 403
- Accès refusé

---

### MODULE 4: KITCHEN DISPLAY SYSTEM (5 tests)

#### Test 4.1: Vue des Commandes
**Parcours:**
1. Login Chef sur KDS (`/admin/kds`)
2. Observer liste des commandes

**Résultat attendu:**
- Commandes avec statut ACCEPT ou PREPARING visibles
- Items agrégés (vue "Tous les Poulets à faire")
- Timer depuis création commande

#### Test 4.2: Changement Statut PREPARING
**Parcours:**
1. Cliquer "Préparer" sur une commande

**Résultat attendu:**
- Statut changé en DB: `status = 7` (PREPARING)
- Notification envoyée au client (push/SMS si configuré)
- Commande reste visible dans KDS

#### Test 4.3: Changement Statut PREPARED
**Parcours:**
1. Cliquer "Terminer" sur commande en PREPARING

**Résultat attendu:**
- Statut changé: `status = 8` (PREPARED)
- Commande disparaît du KDS (ou va dans onglet "Done")
- Apparaît sur OSS (écran client)
- Notification client envoyée

#### Test 4.4: Items Agrégés
**Parcours:**
1. Avoir 3 commandes avec "Poulet" chacune
2. Aller dans vue "Items"

**Résultat attendu:**
- Affichage: "Poulet: 3x"
- Vue par ingrédient (pas par commande)

#### Test 4.5: Isolation Branche
**Parcours:**
1. Login Chef Branche 1
2. Vérifier qu'il ne voit pas commandes Branche 2

**Résultat attendu:**
- Seules commandes `branch_id = 1` visibles
- Impossible de voir/modifier commandes autre branche

---

### MODULE 5: ORDER STATUS SCREEN (2 tests)

#### Test 5.1: Affichage Commandes Prêtes
**Parcours:**
1. Accéder à `/admin/oss` (Order Status Screen)
2. Avoir des commandes en statut PREPARING/PREPARED

**Résultat attendu:**
- Numéros de commande affichés
- Statut coloré (orange = preparing, vert = prepared)
- Mise à jour en temps réel (Firebase ou polling)

#### Test 5.2: Notifications Audio
**Parcours:**
1. Quand commande passe PREPARED

**Résultat attendu:**
- Son de notification joué
- Clignotement visuel éventuel

---

### MODULE 6: SÉCURITÉ & INTEGRITÉ (5 tests)

#### Test 6.1: Anti-Falsification Prix ⭐ CRITIQUE
**Parcours:**
1. Intercepter requête `POST /api/frontend/order`
2. Modifier prix dans JSON: `"price": 0.01` au lieu de 10.00
3. Envoyer requête

**Résultat attendu:**
- Commande créée MAIS avec prix CORRECT de la DB (10.00)
- Prix falsifié ignoré
- Log sécurité éventuel

#### Test 6.2: Commande Sans Auth
**Parcours:**
1. Appeler `POST /api/frontend/order` sans token

**Résultat attendu:**
- Status: 401
- "Unauthenticated"

#### Test 6.3: Accès Route Admin Sans Clé API
**Parcours:**
1. Appeler `GET /api/admin/dashboard` avec token valide mais SANS `x-api-key`

**Résultat attendu:**
- Status: 401 ou 403
- "Invalid API Key"

#### Test 6.4: Token Expiré
**Parcours:**
1. Modifier token dans header (changer dernier caractère)
2. Faire requête

**Résultat attendu:**
- Status: 401
- "Unauthenticated"

#### Test 6.5: SQL Injection
**Parcours:**
1. Dans champ recherche, saisir: `'; DROP TABLE orders; --`
2. Ou dans paramètre URL: `?name=' OR 1=1 --`

**Résultat attendu:**
- Pas d'erreur SQL exposée
- Requête échoue proprement
- Table intacte

---

## 📊 MATRICE DE COUVERTURE

| Module | Tests | Priorité | Status |
|--------|-------|----------|--------|
| Auth | 5 | 🔴 | ⬜ |
| POS Cash | 8 | 🔴 | ⬜ |
| POS Carte | 4 | 🔴 | ⬜ |
| Kiosk | 5 | 🔴 | ⬜ |
| KDS | 5 | 🟡 | ⬜ |
| OSS | 2 | 🟡 | ⬜ |
| Sécurité | 5 | 🔴 | ⬜ |
| **TOTAL** | **34** | | **0/34** |

---

## 🐛 FORMAT DE RAPPORT DE BUG

Pour chaque bug trouvé, créer entrée dans `reports/antigravity/`:

```markdown
### Bug #[NUM] - [Titre court]

**Sévérité:** [🔴 Critique / 🟡 Haute / 🟢 Moyenne]
**Module:** [POS/KDS/Kiosk/etc.]
**Étape:** [Test X.X]

**Comportement attendu:**
...

**Comportement observé:**
...

**Reproduction:**
1. ...
2. ...
3. ...

**Logs/Erreurs:**
```
[Coller erreur console ou réponse API]
```

**Screenshots:**
[Si applicable]

**Recommandation:**
...
```

---

## 🎯 SUCCÈS / ÉCHEC

### Critères de Succès:
- [ ] 30+ tests sur 34 passent
- [ ] 0 bug 🔴 Critique
- [ ] Maximum 2 bugs 🟡 Haute

### Si Échec:
- Documenter tous les bugs
- Classifier par sévérité
- Proposer plan de correction pour Kimi

---

**Guide prêt. Playwright / E2E verification peut commencer les tests E2E.**

*Bonne chasse aux bugs ! 🎯*
