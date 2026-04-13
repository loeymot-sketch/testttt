# Plan d'Analyse Splash 360 — FoodKing Kiosk

**Date :** 2026-03-24  
**Auteur :** Claude (Architecte)  
**Statut :** PLAN — En attente validation GO

---

## 1. Ce Que Le Code Splash Révèle (Analyse Surface)

### 1.1 Architecture Réelle Splash

```
┌─────────────────────────────────────────────────────────────────────────┐
│                    BORNE PHYSIQUE (Windows)                             │
│                                                                         │
│  ┌─────────────────────────────────────────────────────────────────┐   │
│  │  run.js (Orchestrateur)                                         │   │
│  │  - Lance MongoDB local (port 27017)                             │   │
│  │  - Lance Express server (port 3000) via yarn start              │   │
│  │  - Lance Electron (client.js) → charge http://localhost:3000    │   │
│  │  - Auto-restart si crash (récursif)                             │   │
│  └─────────────────────────────────────────────────────────────────┘   │
│           │                    │                    │                   │
│           ▼                    ▼                    ▼                   │
│  ┌──────────────┐  ┌──────────────────┐  ┌──────────────────────┐     │
│  │  MongoDB     │  │  Express Server  │  │  Electron Browser    │     │
│  │  (local)     │  │  (port 3000)     │  │  (1080x1920, kiosk)  │     │
│  │  DB: youfid  │  │  + WebSocket     │  │  fullscreen, always  │     │
│  └──────────────┘  └──────────────────┘  │  on top              │     │
│                           │              └──────────────────────┘     │
│                           ▼                                             │
│              ┌────────────────────────┐                                │
│              │  Nginx (proxy)         │                                │
│              │  Sert les assets       │                                │
│              │  Proxy → Express 3000  │                                │
│              └────────────────────────┘                                │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                          INTERNET (si disponible)
                                    │
                    ┌───────────────┴───────────────┐
                    ▼                               ▼
         ┌──────────────────┐           ┌──────────────────────┐
         │  admin-borne     │           │  RabbitMQ            │
         │  .splash360.fr   │           │  admin-borne.splash   │
         │  (Django backend)│           │  360.fr:5672         │
         │  /generate_      │           │  Exchange: splash360  │
         │  terminal_data/  │           │  Topic: enterprise-  │
         │  /order/command_ │           │  {siret}.product.    │
         │  finalized/      │           │  update              │
         └──────────────────┘           └──────────────────────┘
```

### 1.2 Modules Identifiés

| Module | Fichier | Rôle | Criticité |
|--------|---------|------|-----------|
| **Orchestrateur** | `run.js` | Lance MongoDB + Express + Electron | 🔴 CRITIQUE |
| **Config** | `libs/config.js` | Ports, DB name, backend URL, APP_TYPE | 🔴 CRITIQUE |
| **Cache Backend** | `models/backend_cache/` | Sync données depuis serveur central | 🔴 CRITIQUE |
| **Paiement** | `models/payement.utils.js` | Sauvegarde locale + sync serveur | 🔴 CRITIQUE |
| **Impression** | `libs/printer.js` + `escpos` | Classe builder ticket ESC/POS | 🔴 CRITIQUE |
| **Ticket Numbers** | `models/ticket_numbers.models.js` | Numéros C/B atomiques par terminal | 🔴 CRITIQUE |
| **WebSocket** | `models/websocket/` | Serveur WS local + client caisse | 🔴 CRITIQUE |
| **RabbitMQ** | `models/backend_cache/rabbit.utils.js` | Sync produits temps réel | 🟡 IMPORTANT |
| **Network** | `models/network.utils.js` | Config terminal, MAC, type | 🟡 IMPORTANT |
| **Society** | `models/society.utils.js` | SIRET entreprise | 🟡 IMPORTANT |
| **Electron** | `client/client.js` | Browser kiosk 1080x1920 | 🟡 IMPORTANT |
| **i18n** | `app.js` | Multi-langue fr/gb/nl | 🟢 SECONDAIRE |
| **Sentry** | `backend.utils.js` | Monitoring erreurs | 🟢 SECONDAIRE |

### 1.3 Découvertes Clés (Jamais Documentées Avant)

**1. Système de Cache Local (RÉVÉLATION MAJEURE)**

Splash utilise un cache mémoire en RAM (tableaux JS) + MongoDB comme backup :
```javascript
// cache.utils.js - Variables globales en RAM
var CATEGORY = [];          // Catégories produits
var PRODUCT_LIST = {};      // Produits par catégorie {cat_id: [products]}
var PRINTER_CONFIG = [];    // Config imprimante
var TERMINAL = [];          // Config terminaux réseau
var HOME_SLIDER = [];       // Slides page accueil
var ENTERPRISE = {};        // Info restaurant
var FIDELITY_LIST_PRODUCT = {};  // Produits fidélité
var SUGGESTION_CONFIG = {};      // Config upsell/suggestions
var DESIGN_SLIDER = {};          // Sliders design
var CONFIGURATION_PAGE = {};     // Config pages (login, paiement)
var SCHOOL_HOLIDAYS = [];        // Vacances scolaires (pour menus spéciaux!)
```

**Stratégie de chargement :**
1. Au démarrage → tente `POST /generate_terminal_data/?siret=XXX` vers backend central
2. Si succès → sauvegarde dans MongoDB local + RAM
3. Si échec réseau → charge depuis MongoDB local (mode offline)
4. Si MongoDB vide → erreur critique

**2. Numérotation Tickets (RÉVÉLATION MAJEURE)**

```javascript
// ticket_numbers.models.js
// Format: C{terminal_id}{number} pour cash, B{terminal_id}{number} pour CB
// Ex: C1000, C1001... B1000, B1001...
// Cycle: quand number atteint {terminal_id}999 → repart à {terminal_id}000
// terminal_id = extrait du nom terminal (ex: "BORNE-1" → id=1)
```

**3. Synchronisation Commandes Offline (RÉVÉLATION MAJEURE)**

```javascript
// payement.utils.js
// Commandes sauvegardées localement (sended_to_server: false)
// Envoi par batch de 10 vers /order/command_finalized/
// Payload encodé en base64 pour sécurité
// Si réseau indisponible → stocke et réessaie
```

**4. Connexion Caisse WebSocket (RÉVÉLATION MAJEURE)**

```javascript
// caisse_ws_client.js
// Borne se connecte à la caisse sur port 3340 (socket.io)
// Events: connect, disconnect, welcome, sync, updateproduit
// Si caisse déconnectée → broadcast CAISSE_DISCONNECTED à tous les clients
// Si caisse connectée → broadcast CAISSE_CONNECTED
```

**5. RabbitMQ pour Mise à Jour Produits Temps Réel (RÉVÉLATION MAJEURE)**

```javascript
// rabbit.utils.js
// Exchange: splash360 (topic)
// Routing key: enterprise-{siret}.product.update
// Quand produit modifié côté admin → RabbitMQ → borne reçoit → met à jour cache
// Mise à jour: prix, out_of_stock
// Propagation: MongoDB local + RAM cache + WebSocket vers Electron
```

**6. APP_TYPE (RÉVÉLATION MAJEURE)**

```javascript
// config.js
const APP_TYPE = "BASIC";
// const APP_TYPE = 'GASTRONOMIC_RESTAURANT';
// const APP_TYPE = "CLICK_AND_COLLECT";
```
→ **3 modes de borne différents** selon le type de restaurant !

**7. Electron Config Kiosk (RÉVÉLATION MAJEURE)**

```javascript
// client.js
mainWindow = new BrowserWindow({
    width: 1080,
    height: 1920,  // Portrait 9:16
    frame: false,
    fullscreen: true,
    resizable: false,
    minimizable: false,
    alwaysOnTop: true,
    enableLargerThanScreen: true,
    webPreferences: { allowEval: false, webSecurity: false }
});
mainWindow.setAlwaysOnTop(true, 'screen');
mainWindow.setVisibleOnAllWorkspaces(true);
mainWindow.loadURL('http://localhost:3000');
```

**8. Routes Manquantes (IMPORTANT)**

Les routes référencées dans `app/routes.js` ne sont **PAS dans le dépôt** :
- `/routes/index` → page principale borne
- `/routes/load_data` → chargement données
- `/routes/you_fid_api/index` → API fidélité YouFid
- `/routes/command_system` → impression ticket
- `/routes/payement/web` → paiement web
- `/routes/payement/web/payline` → paiement Payline
- `/routes/admin` → admin login

**Hypothèse :** Ces routes sont dans le frontend compilé (React/Vue build) servi par Express comme static files, ou dans un package séparé non inclus dans le clone.

---

## 2. Plan d'Analyse Profonde — 6 Phases

### PHASE 1 : Analyse du Frontend (UI/UX Borne)
**Objectif :** Comprendre le rendu visuel et les composants React/Vue

**Fichiers à analyser :**
- `public/` (si existe) → build frontend
- Chercher dans node_modules les packages UI (React, Vue, etc.)
- Analyser les locales (`public/public/locales/fr/translation.json`)

**Questions à répondre :**
- Quel framework frontend ? (React probable vu les imports)
- Comment est structuré le wizard ?
- Comment fonctionne l'idle screen avec vidéo ?
- Comment sont gérées les animations/transitions ?

### PHASE 2 : Analyse du Système de Cache et Données
**Objectif :** Comprendre exactement le format des données backend

**Fichiers à analyser :**
- `models/backend_cache/backend.utils.js` (déjà lu)
- `models/backend_cache/cache.utils.js` (déjà lu)
- Analyser le format de `generate_terminal_data` response

**Questions à répondre :**
- Format exact de `suggestion_config` (règles upsell) ?
- Format exact de `fidelity_list_product` ?
- Format exact de `printer_config` ?
- Format exact de `configuration_page` ?
- Format exact de `design_slider` ?
- Format exact de `school_holidays` ?

### PHASE 3 : Analyse du Système de Paiement
**Objectif :** Comprendre le flow complet paiement CB/cash

**Fichiers à analyser :**
- `models/payement.utils.js` (déjà lu)
- `models/payement.models.js` (déjà lu)
- Routes `/routes/payement/web` (manquantes → chercher dans node_modules)
- Payline integration

**Questions à répondre :**
- Comment fonctionne le TPE physique (Payline) ?
- Quel est le flow exact CB : borne → TPE → confirmation ?
- Comment les commandes offline sont-elles gérées ?
- Format exact `cart_config` et `cart_list` ?

### PHASE 4 : Analyse du Système d'Impression
**Objectif :** Comprendre le format exact des tickets

**Fichiers à analyser :**
- `libs/printer.js` (déjà lu)
- `node_modules/escpos/` (partiellement lu)
- Routes `/routes/command_system` (manquantes)

**Questions à répondre :**
- Comment est formaté le ticket (colonnes, tailles) ?
- Quelle imprimante est supportée (USB/réseau) ?
- Comment est géré le numéro de ticket sur le ticket ?
- Format exact du ticket pour une commande ?

### PHASE 5 : Analyse du Système de Fidélité (YouFid)
**Objectif :** Comprendre l'intégration fidélité complète

**Fichiers à analyser :**
- Routes `/routes/you_fid_api/index` (manquantes)
- `models/network.utils.js` → `youfid: { login, password }`
- `cache.utils.js` → `FIDELITY_LIST_PRODUCT`

**Questions à répondre :**
- Comment fonctionne YouFid (API externe ?) ?
- Format exact des données fidélité ?
- Comment les points sont calculés ?
- Comment la remise est appliquée ?

### PHASE 6 : Analyse du Système de Suggestions (Upsell)
**Objectif :** Comprendre les règles de merchandising

**Fichiers à analyser :**
- `cache.utils.js` → `SUGGESTION_CONFIG`
- Routes `/routes/load_data` (manquantes)

**Questions à répondre :**
- Format exact de `suggestion_config` ?
- Règles de déclenchement upsell ?
- Produits suggérés = liste fixe ou dynamique ?
- Conditions (heure, panier, catégorie) ?

---

## 3. Ce Qu'On Peut Adapter Pour FoodKing

### 3.1 Adaptations Immédiates (Quick Wins)

| Feature Splash | Adaptation FoodKing | Effort |
|----------------|---------------------|--------|
| Cache RAM + MongoDB offline | Cache Redis + fallback DB | Moyen |
| Ticket numbers C/B par terminal | Queue number par branche/jour (déjà fait) | ✅ Fait |
| Batch sync commandes offline | Queue Laravel (jobs) | Faible |
| WS caisse connection | Echo WebSocket (déjà fait) | ✅ Fait |
| Electron kiosk 1080x1920 | Chrome kiosk mode / PWA | Faible |
| APP_TYPE (BASIC/GASTRO/C&C) | `kiosk_type` dans settings | Faible |
| Sentry monitoring | Sentry Laravel + Vue | Faible |

### 3.2 Adaptations Importantes (Sprint 2)

| Feature Splash | Adaptation FoodKing | Effort |
|----------------|---------------------|--------|
| ESC/POS impression | Endpoint `/api/kiosk/print` + escpos-network | Moyen |
| RabbitMQ produits temps réel | Pusher/Echo events (déjà infra) | Faible |
| Payline TPE | Intégration TPE via webhook (déjà prévu) | Élevé |
| SUGGESTION_CONFIG | `kiosk_suggestion_config` dans settings | Moyen |
| School holidays menus | `kiosk_special_menus` dans settings | Faible |
| Design slider (FOR_BORNE) | Idle screen vidéo/slider configurable | Moyen |
| FIDELITY_LIST_PRODUCT | Produits fidélité dans admin | Moyen |

### 3.3 Architecture FoodKing Cible (Inspirée Splash)

```
┌─────────────────────────────────────────────────────────────────────────┐
│                    BORNE PHYSIQUE (Windows/Linux)                       │
│                                                                         │
│  ┌─────────────────────────────────────────────────────────────────┐   │
│  │  Chrome Kiosk Mode (ou Electron)                                │   │
│  │  --kiosk http://localhost/kiosk                                  │   │
│  │  1080x1920, fullscreen, no frame                                │   │
│  └─────────────────────────────────────────────────────────────────┘   │
│                                    │                                    │
│                                    ▼                                    │
│  ┌─────────────────────────────────────────────────────────────────┐   │
│  │  Service Worker (PWA)                                           │   │
│  │  - Cache assets offline                                         │   │
│  │  - Queue commandes si réseau perdu                              │   │
│  │  - Sync background quand réseau revient                         │   │
│  └─────────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                              INTERNET
                                    │
                    ┌───────────────┴───────────────┐
                    ▼                               ▼
         ┌──────────────────┐           ┌──────────────────────┐
         │  FoodKing Backend│           │  Pusher/Echo         │
         │  (Laravel 9)     │           │  (temps réel)        │
         │  + Redis Cache   │           │  - OrderStatusChanged│
         │  + Queue Jobs    │           │  - ProductUpdated    │
         └──────────────────┘           └──────────────────────┘
```

---

## 4. Plan d'Implémentation Priorisé

### Sprint A — Offline Mode (HAUTE PRIORITÉ)

**Problème actuel :** Si internet coupe pendant une commande → commande perdue.

**Solution inspirée Splash :**
```javascript
// Service Worker + IndexedDB
// 1. Commande soumise → sauvegardée IndexedDB (pending)
// 2. Si réseau → envoi immédiat
// 3. Si pas réseau → affiche "Mode hors ligne - commande enregistrée"
// 4. Background sync quand réseau revient
```

**Fichiers à créer :**
- `public/sw.js` (Service Worker)
- `resources/js/helpers/offlineQueue.js`
- `app/Jobs/SyncOfflineOrdersJob.php`

### Sprint B — Impression ESC/POS Réelle (HAUTE PRIORITÉ)

**Problème actuel :** `window.print()` = impression navigateur, pas thermique.

**Solution inspirée Splash :**
```
Borne → POST /api/kiosk/print → Backend → escpos-network → Imprimante réseau
```

**Fichiers à créer :**
- `app/Http/Controllers/Frontend/PrintController.php`
- `app/Services/EscPosService.php`
- Route: `POST frontend/print-ticket`

**Format ticket (inspiré Splash Printer class) :**
```php
$printer->align('ct')
    ->size(2, 2)->text($restaurantName)
    ->size(1, 1)->drawLine()
    ->text("N° " . $order->queue_number)
    ->drawLine()
    // items...
    ->text("TOTAL: " . $order->order_amount . "€")
    ->cut()->close();
```

### Sprint C — Sync Produits Temps Réel (MOYEN)

**Problème actuel :** Si admin change prix/stock → borne ne sait pas.

**Solution inspirée Splash (RabbitMQ → Pusher/Echo) :**
```php
// Admin modifie produit → Event ProductUpdated
// Broadcast sur branch.{id} channel
// Borne reçoit → met à jour cache local Vue
```

### Sprint D — Configuration Borne Admin (MOYEN)

**Inspiré Splash `generate_terminal_data` :**
```php
// Endpoint: GET /api/kiosk/config
// Retourne tout ce dont la borne a besoin:
{
    "categories": [...],
    "products": {...},
    "printer_config": {...},
    "suggestion_config": {...},
    "design_slider": {...},
    "configuration_page": {...},
    "enterprise": {...},
    "terminals": [...],
    "school_holidays": [...],
}
```

### Sprint E — APP_TYPE / Kiosk Types (FUTUR SAAS)

**Inspiré Splash :**
```php
// Settings: kiosk_type = BASIC | GASTRONOMIC | CLICK_AND_COLLECT
// BASIC: wizard simple (pain, viande, sauce)
// GASTRONOMIC: wizard étendu (entrée, plat, dessert, vin)
// CLICK_AND_COLLECT: commande sans wizard, récupération comptoir
```

---

## 5. Ce Qui Manque Dans Le Clone

### 5.1 Routes Frontend (Non Incluses)

Les routes référencées dans `app/routes.js` sont absentes du clone :
- `/routes/index` → **Page principale borne** (CRITIQUE)
- `/routes/load_data` → **Chargement données** (CRITIQUE)
- `/routes/you_fid_api/index` → **API fidélité** (IMPORTANT)
- `/routes/command_system` → **Impression ticket** (IMPORTANT)
- `/routes/payement/web` → **Paiement** (IMPORTANT)
- `/routes/admin` → **Admin** (SECONDAIRE)

**Hypothèse :** Ces routes sont dans le build frontend (React compilé) servi comme static files par Express. Le dossier `public/` n'est pas inclus dans le clone.

### 5.2 Frontend React (Non Inclus)

Le frontend (React/Vue) qui tourne dans Electron n'est pas dans le clone. C'est probablement un projet séparé compilé dans `public/`.

**Ce qu'on peut déduire :**
- Framework: React (probable, vu les dépendances)
- Serveur: Express sert `public/` comme static
- Electron charge `http://localhost:3000`

### 5.3 MongoDB Data (Non Inclus)

Les données MongoDB ne sont pas dans le clone (normal, >100MB). Mais on peut déduire les schémas depuis les models.

---

## 6. Décisions Architecturales pour FoodKing

### 6.1 Garder Notre Architecture (Ne Pas Copier Splash)

| Splash | FoodKing | Raison |
|--------|----------|--------|
| Node.js + Express | Laravel 9 | Déjà en prod, mieux pour SaaS |
| MongoDB local | MySQL + Redis | ACID, multi-tenant |
| Electron | Chrome kiosk / PWA | Mise à jour facile, no install |
| RabbitMQ | Pusher/Echo | Déjà intégré |
| socket.io | Laravel Echo | Déjà intégré |

### 6.2 Emprunter à Splash

| Concept Splash | Implémentation FoodKing |
|----------------|-------------------------|
| Cache RAM local | Vuex persistedstate (déjà fait) |
| Offline queue | Service Worker + IndexedDB |
| Ticket numbers C/B | Queue number par branche (déjà fait) |
| ESC/POS printing | `escpos-network` via Laravel |
| APP_TYPE | `kiosk_type` dans settings |
| SUGGESTION_CONFIG | Config upsell dans admin |
| Design slider FOR_BORNE | Idle screen configurable |
| School holidays | Menus spéciaux configurables |
| Sentry monitoring | Sentry Laravel + Vue |

---

## 7. Prochaines Étapes Recommandées

### Étape 1 (Maintenant) — Analyse Profonde Frontend
Chercher le build frontend Splash (dossier `public/`) pour comprendre l'UI exacte.

### Étape 2 (Maintenant) — Analyser Format Données
Comprendre le format exact de `generate_terminal_data` response pour adapter notre API `/api/kiosk/config`.

### Étape 3 (Sprint 1) — Impression ESC/POS
Implémenter `EscPosService.php` + endpoint print + test avec imprimante réseau.

### Étape 4 (Sprint 1) — Offline Mode
Implémenter Service Worker + IndexedDB queue pour commandes offline.

### Étape 5 (Sprint 2) — Config Borne Endpoint
Créer `/api/kiosk/config` qui retourne tout (inspiré `generate_terminal_data`).

### Étape 6 (Sprint 2) — Admin Borne UI
Interface admin pour configurer suggestion_config, design_slider, printer_config.

---

## 8. Test Type

**local-validation** pour les sprints A, B, C, D (backend + frontend localisé)  
**Playwright / E2E verification** pour le flow complet borne après chaque sprint majeur

---

**Verdict :** Le code Splash est une mine d'or. Les concepts clés (cache offline, ESC/POS, RabbitMQ, APP_TYPE, suggestion_config) sont tous adaptables dans FoodKing avec notre stack Laravel/Vue. La priorité absolue est l'impression ESC/POS réelle et le mode offline.

**Prochaine action :** GO sur analyse Phase 1 (frontend) ou GO sur Sprint A (offline mode) ?
