# 🚨 Rapport d'Audit E2E Profond (Navigateur & API)
**Module concerné** : Caisse (POS) et Borne (Kiosk)
**Date** : 10 Mars 2026
**Agent** : Playwright / E2E verification (QA Expert)

L'audit complet sur navigateur a été exécuté. J'ai simulé un parcours utilisateur réel (clics, sélection de menu, ajout au panier, checkout) via notre Subagent QA. Les résultats sont critiques.

---

## 🛑 PHASE 1 : Audit de la Caisse (POS) - ÉCHEC BLOQUANT

### Étape 1 : Connexion & Navigation (✅ Succès)
Le login s'est bien déroulé avec `admin@inilabs.dev`. Le tableau de bord s'affiche correctement, le bouton "POS" est fonctionnel et charge l'interface de caisse.

### Étape 2 : Prise de commande & Panier (✅ Succès)
Le catalogue "Le Grill House" (Dumplings, Egg Rolls, Burgers) est bien affiché. Les clics pour ajouter au panier fonctionnent à la perfection. Le calcul des totaux en temps réel est bon.

### Étape 3 : Tunnel de Paiement "Cash/Takeaway" (❌ FATAL)
Dès l'ouverture du Modal "Order Payment", l'interface est truffée de bugs bloquants empêchant la validation de TOUTE commande.

**Bug 1 : Erreur Orthographique (Mineur)**
Le bouton principal de validation contient une faute : `Confirm & Print Reciept` au lieu de `Receipt`.

**Bug 2 : Faille de Binding Vue.js sur le Pavé Numérique (Majeur)**
Le caissier saisit machinalement le montant reçu (ex: `5.50`) via le pavé numérique affiché à l'écran. 
- *Problème :* Le bouton "Confirm" déclenche une requête POST `/api/admin/pos` qui **échoue avec 422 Unprocessable Entity**.
- *Cause racine (Frontend/Vue)* : Le pavé numérique remplit l'input visuellement mais ne met pas à jour le modèle Vue.js (`v-model="form.received_amount"` est cassé). La requête part donc sans le champ `received_amount`, et Laravel la rejette (`The received amount field is required`). 

**Bug 3 : Validation du Token No (Majeur - Erreur d'Architecture)**
Pour les commandes à emporter (Takeaway), le POS demande un "Token No" (Numéro de biper).
- *Problème :* Même en tapant un chiffre valide (ex: `5001`), la requête échoue à nouveau en 422.
- *Cause racine (Backend/Laravel)* : Laravel répond `The token must be a string.`. Le Frontend envoie le token sous forme d'Entier (Integer) car le champ HTML est potentiellement de type `number`, mais le `PosOrderRequest` côté backend exige strictement une `string`. Laravel bloque donc la requête.

### 📝 Bilan POS
**Il est actuellement IMPOSSIBLE de finaliser une commande POS en deçà d'une refonte du code Vue.js du modal de paiement.**

---

## 🛑 PHASE 2 : Audit de la Borne (Kiosk)

### Constat d'Architecture
Contrairement au POS qui est un module Web inclus dans le Dashboard Vue.js, la **Borne (Kiosk)** n'a **pas d'interface web native** dans ce code source (pas de route web accessible via navigateur). Elle est prévue pour être une application externe (tablette Android / iPad) qui se connecte via l'API REST `/api/auth/kiosk-login`.

### Le "Boss Final" (Rappel des Tests Automatisés)
Puisque le parcours Kiosk se fait purement via API, mes précédents tests systémiques (AntiGravityTest) sont la source de vérité absolue.
- *Problème :* Lorsqu'une borne Kiosk envoie le JSON parfait pour créer une commande (`POST /api/frontend/order`), le backend réussit la validation mais **crashe en erreur 500** avec le message `Attempt to read property "faviconLogo" on null`.
- *Cause racine* : Au moment d'envoyer la notification (Push, Email, ou WebSocket), Laravel instancie les paramètres système et tente de lire le logo. Malheureusement, la variable est appelée sans protection null-safe.

---

## 🎯 VISION GLOBALE & PLANIFICATION (Pour Claude)

Claude, ton plan de match pour Kimi (Sprint 6/7) s'écrit tout seul. Le système de commande est sclérosé par 3 bugs racines qu'il faut attaquer méthodiquement :

### Plan pour Kimi :
1. **Corriger le Bug de Type Token (Backend)** : 
   - Fichier : Repérer le `PosOrderRequest` (ou contrôleur lié à `/api/admin/pos`).
   - Action : Remplacer la règle `token => 'string'` par `token => 'string|numeric|nullable'` ou forcer un "Cast" côté Vue.js.
2. **Corriger le UI Binding du Pavé Numérique (Frontend Vue)** :
   - Fichier : Trouver le composant Vue du modal de paiement (probablement dans `resources/js/components/admin/pos/`).
   - Action : Assurer que le clic sur le pavé numérique met bien à jour la variable réactive utilisée par la payload Axios pour `received_amount`. Corriger aussi la faute de frappe `Reciept`.
3. **Corriger le Crash Kiosk `faviconLogo` (Backend Global)** :
   - Fichier : Chercher `faviconLogo` dans `app/`.
   - Action : Utiliser l'opérateur PHP `?->` partout où le code tente de lire le logo (ex: `app(ThemeSetting::class)?->faviconLogo`). 

Une fois ces 3 actions ciblées accomplies, le tunnel sera enfin libéré. A toi de jouer !
