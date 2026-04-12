# 🔍 PLAN D'AUDIT COMPLET - Architecture FoodKing

> **Pour:** Claude (Lead Architect)  
> **Date:** 10 Mars 2026  
> **Objectif:** Audit approfondi de l'ensemble du système avant production

---

## 📊 ARCHITECTURE DU SYSTÈME

### Surfaces du Système

```
┌─────────────────────────────────────────────────────────────────┐
│                      FOODKING SYSTEM                           │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  🖥️  CAISSE (Web POS - Vue.js)                                  │
│     ├─ Login Admin                                              │
│     ├─ Catalogue Items (Grill House Menu)                     │
│     ├─ Wizard Commande (pos-wizard.js)                        │
│     ├─ Panier temps réel                                        │
│     ├─ Modal Paiement (Cash/Card)                             │
│     └─ Ticket / KDS Integration                                 │
│                                                                 │
│  📱 BORNE (Kiosk - Android App à développer)                   │
│     ├─ Login Kiosk (API /api/auth/kiosk-login)                │
│     ├─ Catalogue Items (même API que POS)                     │
│     ├─ Wizard Commande (1 question par page)                  │
│     ├─ Paiement (à définir: TPE intégré?)                     │
│     └─ Ticket / KDS Integration                                 │
│                                                                 │
│  🍳 KDS (Kitchen Display - Web sur tablette)                   │
│     ├─ Vue temps réel des commandes                           │
│     ├─ Changement statut (PREPARING → PREPARED)               │
│     └─ Notifications sonores + visuelles                      │
│                                                                 │
│  📺 OSS (Order Status Screen - Web sur TV)                     │
│     ├─ Affichage numéros commandes prêtes                     │
│     └─ Pour clients en salle                                  │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## 🎯 MODULES À AUDITER

### 1. AUTHENTIFICATION & SÉCURITÉ (Priorité: 🔴 CRITIQUE)

#### 1.1 Kiosk Authentication Flow
**Fichiers clés:**
- `app/Http/Controllers/Auth/KioskMachineLoginController.php`
- `app/Models/KioskMachine.php`

**Points d'audit:**
- [ ] Token Sanctum avec ability `kiosk:order` est-il suffisant?
- [ ] Le flag `is_login` fonctionne-t-il correctement (empêche double login)?
- [ ] Y a-t-il un mécanisme de timeout de session?
- [ ] Peut-on révoquer un token à distance si la borne est volée?

#### 1.2 Autorisation Cross-Module
**Fichiers clés:**
- Tous les controllers sous `app/Http/Controllers/Admin/`
- Middleware `permission:` dans `routes/api.php`

**Points d'audit:**
- [ ] Un caissier (POS Operator) peut-il accéder au Dashboard Admin?
- [ ] Un chef (Chef) peut-il voir les rapports de vente?
- [ ] Un utilisateur Kiosk peut-il créer des commandes mais pas les modifier?
- [ ] Les routes admin sont-elles toutes protégées par `x-api-key` + `auth:sanctum`?

#### 1.3 Isolation Multi-Branch
**Fichiers clés:**
- `app/Scopes/BranchScope.php`
- `app/Models/Order.php` (global scope)

**Points d'audit:**
- [ ] BranchScope s'applique-t-il sur TOUTES les requêtes Order?
- [ ] Un chef de la Branche A peut-il voir/modifier une commande de la Branche B?
- [ ] Les dashboards admin voient-ils toutes les branches ou seulement la leur?

---

### 2. FLUX DE COMMANDE (Priorité: 🔴 CRITIQUE)

#### 2.1 Commande POS (Caisse)
**Fichiers clés:**
- `resources/js/components/admin/pos/PosComponent.vue`
- `resources/js/components/admin/pos/PaymentComponent.vue` ⭐
- `public/js/pos-wizard.js` ⭐⭐
- `app/Http/Controllers/Admin/PosOrderController.php`
- `app/Services/OrderService.php` ⭐⭐⭐

**Points d'audit:**
- [ ] Le wizard détecte-t-il correctement le nombre de viandes (M=1, L=2, XL=3, XXL=4)?
- [ ] La 1ère sauce est-elle gratuite, les suivantes à +0.50€?
- [ ] Les garnitures (Salade/Tomate/Oignon) sont-elles pré-cochées?
- [ ] Le montant reçu (Cash) est-il bien transmis au backend?
- [ ] Le calcul du rendu monnaie est-il correct?
- [ ] La validation Token (Takeaway) accepte-t-elle int ET string?
- [ ] Les items avec variations sont-ils correctement enregistrés en DB?

#### 2.2 Commande Kiosk (Borne)
**Fichiers clés:**
- Endpoint: `POST /api/frontend/order`
- `app/Services/FrontendOrderService.php` ⭐⭐⭐

**Points d'audit:**
- [ ] L'API accepte-t-elle les commandes Kiosk sans crash 500?
- [ ] Les notifications (push/email) fonctionnent-elles?
- [ ] Le prix est-il recalculé côté serveur (anti-falsification)?
- [ ] Y a-t-il une protection contre les commandes sans items?

#### 2.3 Item Variations / Extras / Addons
**Fichiers clés:**
- `app/Models/Item.php` (relations)
- `app/Models/ItemVariation.php`
- `app/Models/ItemExtra.php`
- `app/Models/ItemAddon.php`
- `app/Http/Resources/ItemResource.php` (API response)

**Points d'audit:**
- [ ] Les variations sont-elles bien groupées par attribut dans l'API?
- [ ] Le prix des extras est-il ajouté au total?
- [ ] Les addons (Menu/Frites/Boisson) sont-ils proposés au bon moment?
- [ ] La logique "1 sauce gratuite" est-elle respectée?

---

### 3. KDS & KITCHEN FLOW (Priorité: 🟡 HAUTE)

#### 3.1 Kitchen Display System
**Fichiers clés:**
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`
- `app/Http/Controllers/Admin/KitchenDisplaySystemController.php`
- `app/Services/KitchenDisplaySystemOrderService.php`

**Points d'audit:**
- [ ] Les commandes apparaissent-elles en temps réel?
- [ ] Les statuts changent-ils correctement (ACCEPT → PREPARING → PREPARED)?
- [ ] Les items agrégés fonctionnent-ils (vue par ingrédient)?
- [ ] Le son de notification fonctionne-t-il?
- [ ] Les commandes d'autres branches sont-elles filtrées?

#### 3.2 Notifications
**Fichiers clés:**
- `app/Services/OrderGotPushNotificationBuilder.php`
- `app/Services/OrderPushNotificationBuilder.php`
- `app/Events/SendOrderGotPush.php`

**Points d'audit:**
- [ ] Les tokens Firebase sont-ils bien enregistrés?
- [ ] Les notifications atteignent-elles le KDS quand une commande est créée?
- [ ] Les notifications atteignent-elles le client quand la commande est prête?

---

### 4. PAIEMENT (Priorité: 🔴 CRITIQUE)

#### 4.1 POS Paiement Cash
**Fichiers clés:**
- `resources/js/components/admin/pos/PaymentComponent.vue` (lignes 200-210)
- `app/Http/Requests/PosOrderRequest.php` (règles validation)

**Points d'audit:**
- [ ] Le binding du pavé numérique fonctionne-t-il (fix déjà appliqué)?
- [ ] Le montant reçu est-il bien validé (>= total)?
- [ ] Le calcul du rendu monnaie est-il correct?

#### 4.2 POS Paiement Carte (TPE)
**Fichiers clés:**
- `resources/js/components/admin/pos/PaymentComponent.vue`
- `app/Http/Controllers/Admin/PosOrderController.php`

**Points d'audit:**
- [ ] Les 4 derniers digits de la carte sont-ils enregistrés?
- [ ] Y a-t-il une intégration avec un TPE physique?
- [ ] Comment se fait la correspondance entre la commande et le ticket TPE?

#### 4.3 Paiement en Ligne (Futur)
**Fichiers clés:**
- `app/Http/Controllers/Frontend/PaymentController.php`
- `app/Services/PaymentManagerService.php`

**Points d'audit:**
- [ ] Le système est-il prêt pour Stripe/Cashfree?
- [ ] Les webhooks sont-ils configurables?

---

### 5. IMPRESSION & TICKETS (Priorité: 🟡 HAUTE)

#### 5.1 Génération Tickets
**Fichiers clés:**
- `resources/js/components/admin/pos/ReceiptComponent.vue`
- `resources/views/payment.blade.php`

**Points d'audit:**
- [ ] Le ticket contient-il tous les items avec leurs variations?
- [ ] Le QR code ou numéro de commande est-il présent?
- [ ] L'impression thermique est-elle formatée correctement?
- [ ] Le ticket client diffère-t-il du ticket cuisine?

#### 5.2 Imprimantes
**Configuration à vérifier:**
- [ ] Support imprimante thermique USB (POS)?
- [ ] Support imprimante réseau (KDS)?
- [ ] Configuration des tailles de papier (58mm/80mm)?

---

### 6. PERFORMANCE & SCALABILITY (Priorité: 🟢 MOYENNE)

#### 6.1 Base de Données
**Points d'audit:**
- [ ] Les requêtes Order ont-elles des N+1 queries?
- [ ] Les index sont-ils corrects sur `orders.branch_id`, `orders.status`?
- [ ] La pagination fonctionne-t-elle sur de gros volumes?

#### 6.2 Temps Réel
**Points d'audit:**
- [ ] Firebase est-il utilisé pour les mises à jour temps réel?
- [ ] Le polling KDS est-il configuré (intervalle raisonnable)?

---

## 🔍 RISQUES IDENTIFIÉS À INVESTIGUER

### Risque 1: Commandes Concurrentes
**Hypothèse:** Deux caissiers créent une commande en même temps → conflit sur le numéro de commande?
**À vérifier:** `OrderService.php` ligne 183 (queue number generation avec `lockForUpdate`)

### Risque 2: Prix Falsifié
**Hypothèse:** Un client malicieux pourrait modifier le prix dans le JSON items.
**À vérifier:** `FrontendOrderService.php` ligne 140-155 (vérification prix DB vs prix reçus)

### Risque 3: Notification en Boucle
**Hypothèse:** Si Firebase échoue, les notifications se mettent en queue et explosent?
**À vérifier:** Mécanisme de retry dans les Notification Builders

### Risque 4: Fuite de Données Cross-Branch
**Hypothèse:** Un chef de la Branche A pourrait deviner l'ID d'une commande de la Branche B.
**À vérifier:** Tous les endpoints API avec `/{order}` doivent vérifier `branch_id`.

---

## ✅ CHECKLIST VALIDATION FINALE

### Avant Mise en Production:

**Tests Automatisés:**
- [ ] 18/18 tests AntiGravityTest passent
- [ ] 80 tests MASSIVE_TEST_PLAN créés et 70+ passent
- [ ] Tests de charge (10 commandes/minute pendant 10 minutes)

**Tests E2E Manuels (Playwright / E2E verification):**
- [ ] Créer commande POS Cash → Paiement → Ticket
- [ ] Créer commande POS Carte → Paiement → Ticket
- [ ] Créer commande Takeaway avec Token → Validation Token
- [ ] Créer commande Kiosk → Vérification KDS
- [ ] Changer statut KDS (PREPARING → PREPARED) → Notification client
- [ ] Tester isolation: Chef Branche A ne voit pas Branche B
- [ ] Tester autorisation: Kiosk ne peut pas accéder à /api/admin/*

**Tests Sécurité:**
- [ ] Falsification prix (envoyer prix=0.01) → Rejet
- [ ] Commande sans authentification → 401
- [ ] Commande avec token Kiosk sur route Admin → 403

**Tests Performance:**
- [ ] Charger 1000 items → Temps de réponse < 2s
- [ ] 10 caissiers simultanés → Aucun conflit

---

## 📁 ARTEFACTS À PRODUIRE

### Pour Playwright / E2E verification:
- Guide de test E2E détaillé (créé séparément)
- Scénarios de test avec données attendues
- Matrice de couverture des fonctionnalités

### Pour Kimi:
- Liste des fichiers à ne PAS toucher (sensibles)
- Liste des fichiers modifiables (UI mineure)
- Procédures de rollback en cas de problème

### Pour Documentation:
- Architecture technique (diagrammes)
- Guide d'installation multi-device (POS/KDS/Kiosk)
- Manuel utilisateur caissier

---

## 🎯 PROCHAINES ÉTAPES RECOMMANDÉES

1. **Cette semaine:** Playwright / E2E verification exécute les tests E2E complets
2. **Semaine prochaine:** Kimi corrige les bugs trouvés
3. **Dans 2 semaines:** Test de charge + sécurité
4. **Dans 3 semaines:** Mise en production (soft launch une branche)

---

**Plan d'audit prêt pour exécution détaillée par Claude.**

*Utilise ce document comme guide de revue systématique du codebase.*
