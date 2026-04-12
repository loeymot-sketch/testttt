# E. Roadmap & Prochaines Étapes

## E.1 Ce Qui Est Implémenté ✅

### E.1.1 Core Kiosk Flow (100%)

| # | Feature | Statut | Notes |
|---|---------|--------|-------|
| 1 | Auth machine borne | ✅ | Token Sanctum + ability `kiosk:order`, re-login permis |
| 2 | Écran Idle (vidéo) | ✅ | Reset panier, fallback gradient |
| 3 | Grille catégories | ✅ | Retry button si erreur réseau |
| 4 | Liste produits | ✅ | Pagination "Load More" 20 items |
| 5 | Wizard personnalisation | ✅ | 7 steps: pain, viande, sauce, garnitures, suppléments, menu, récap |
| 6 | Panier avec édition | ✅ | Bouton edit → retour wizard |
| 7 | Upsell auto-skip | ✅ | 30s timeout + progress bar |
| 8 | Paiement CASH/CARD/TR | ✅ | TPE overlay 5s pour carte |
| 9 | Waiting + queue number | ✅ | Polling 5s, network banner, cancel après 30s |
| 10 | Confirmation + print | ✅ | `window.print()` avec ticket thermique |

### E.1.2 Intégration Backend (100%)

| # | Feature | Statut | Notes |
|---|---------|--------|-------|
| 11 | Auto-accept kiosk | ✅ | OrderStatus::ACCEPT immédiatement |
| 12 | Queue number atomique | ✅ | Par branche, par jour |
| 13 | Idempotence | ✅ | UUID par tentative de soumission |
| 14 | Cancel jusqu'à PREPARING | ✅ | Seuil dynamique vs ACCEPT pour normal |
| 15 | Award loyalty à PREPARED | ✅ | Kiosk trigger différent |
| 16 | Fidélité code/téléphone | ✅ | Normalisation, auto-génération code |
| 17 | Broadcast Echo | ✅ | OrderStatusChanged + OrderCreated |

### E.1.3 Intégration Multi-Systèmes (100%)

| # | Feature | Statut | Notes |
|---|---------|--------|-------|
| 18 | KDS colonne Borne | ✅ | Affiche queue_number |
| 19 | OSS affiche queue | ✅ | N°847 rouge/vert |
| 20 | POS badge kiosk cash | ✅ | FAB pulsant + panel latéral |
| 21 | Intercepteur 401 | ✅ | Redirect kiosk.login si 401 |

## E.2 Ce Qui Reste à Faire 🚧

### E.2.1 Sprint 1: Production Hardering (Priorité HAUTE)

| # | Task | Impact | Complexité |
|---|------|--------|------------|
| 22 | **Tests E2E Playwright / E2E verification** | 🚨 CRITIQUE | Moyenne |
| 23 | Configuration borne admin (UI création machine) | Haute | Faible |
| 24 | Multi-branche sélection borne | Haute | Moyenne |
| 25 | Rate limiting API borne | Moyenne | Faible |
| 26 | Audit sécurité complet | Haute | Moyenne |

**Détail Test E2E:**
```
Scénario 1: Login → Commande cash → Vérification KDS → Préparation → Fidélité attribuée
Scénario 2: Login → Commande card → TPE overlay → Annulation cuisine → Remboursement?
Scénario 3: Session timeout (401) → Auto-redirect login → Perte panier? 
Scénario 4: Double clic paiement → Idempotence fonctionne?
Scénario 5: Édition article panier → Modification → Quantité correcte?
```

### E.2.2 Sprint 2: Features Splash (Priorité MOYENNE)

| # | Task | Impact | Complexité | Source Splash |
|---|------|--------|------------|-----------------|
| 27 | **Merchandising upsell** | Haute | Moyenne | `rules/merchandising.json` |
| 28 | **Mode offline** | Haute | Élevée | `cache/local-db.js` |
| 29 | **Impression ESC/POS** | Moyenne | Élevée | `printer/escpos.js` |
| 30 | **TPE webhook** | Haute | Moyenne | `tpe/state-machine.js` |
| 31 | **Animations fluides** | Moyenne | Faible | `ui/transitions.css` |
| 32 | **Idle video autoplay** | Faible | Faible | `video/kiosk.mp4` |

**Détail Merchandising:**
```
Exemple règles Splash:
- SI panier contient burger ET prix > 15€ → propose dessert
- SI catégorie principale = tacos → propose boisson spécifique
- SI heure = 11h-14h → propose menu midi
- SI temps depuis dernière commande > 30min → welcome back
```

**Détail Mode Offline:**
```
Architecture cible:
- Service Worker cache assets
- IndexedDB local pour commandes en attente
- Sync background quand réseau revient
- Écran "Mode hors ligne - commandes enregistrées"
```

### E.2.3 Sprint 3: Advanced (Priorité BASSE)

| # | Task | Impact | Complexité |
|---|------|--------|------------|
| 33 | Suggestion AI basée historique | Moyenne | Élevée |
| 34 | Multi-langue complète (i18n) | Moyenne | Faible |
| 35 | Analytics borne (heatmap, temps) | Moyenne | Moyenne |
| 36 | Compteurs branche temps réel | Faible | Faible |
| 37 | Son notification commande prête | Faible | Faible |
| 38 | QR code ticket digital | Moyenne | Faible |

## E.3 Architecture Future (SaaS)

### E.3.1 Multi-Tenant

```php
// Tables à modifier pour SaaS
- tenants (id, name, domain, settings)
- branches → tenant_id FK
- kiosk_machines → tenant_id FK
- frontend_orders → tenant_id FK

// Middleware TenantResolution
// Détecte domaine/subdomain → charge config tenant
```

### E.3.2 Micro-Services Optionnels

```
┌─────────────────────────────────────────────┐
│              API Gateway                    │
├─────────────┬─────────────┬───────────────┤
│  Auth Svc   │  Order Svc  │ Payment Svc   │
│  (Sanctum)  │  (Laravel)  │ (Stripe/TPE)  │
├─────────────┼─────────────┼───────────────┤
│  KDS Svc    │  Inventory  │ Analytics     │
│  (WS)       │  (Stock)    │ (BigQuery)    │
└─────────────┴─────────────┴───────────────┘
```

## E.4 Décisions Techniques Futures

### E.4.1 PWA vs App Native

**Option A: PWA (Recommandé)**
- ✅ Pas d'App Store
- ✅ Mise à jour instantanée
- ✅ Service Worker offline
- ❌ iOS limitations (pas de background sync)

**Option B: Electron (comme Splash)**
- ✅ Offline total
- ✅ Accès hardware (imprimante, TPE)
- ❌ Distribution complexe
- ❌ Mise à jour lourde

### E.4.2 WebSocket Provider

**Actuel:** Pusher (Écho)  
**Futur:**
- Socket.io (auto-reconnect, rooms)
- Redis pub/sub (scale horizontal)
- Ably (managed, $$)

## E.5 Liste TODO Détaillée

### Priorité HAUTE (Production)

```
[ ] Créer tests Playwright / E2E verification (browser automation)
[ ] Documenter API borne pour intégrateurs
[ ] Créer interface admin gestion machines
[ ] Ajouter rate limiting routes borne
[ ] Audit sécurité: SQL injection, XSS, CSRF
[ ] Test charge: 100 commandes/min borne
[ ] Configuration CORS production
[ ] SSL/TLS borne physique
[ ] Backup base données borne
```

### Priorité MOYENNE (Feature Parity Splash)

```
[ ] Analyser Splash merchandising rules
[ ] Implémenter cache offline commandes
[ ] Protocole ESC/POS impression
[ ] Intégration TPE physique webhook
[ ] Animations Lottie transitions
[ ] Idle video autoplay avec fallback
[ ] Multi-branche borne login
[ ] Statistiques borne dashboard
```

### Priorité BASSE (Nice to Have)

```
[ ] Suggestion IA par historique
[ ] i18n multi-langue
[ ] QR code ticket
[ ] Son notification
[ ] Dark mode borne
[ ] Accessibilité WCAG borne
[ ] Analytics temps navigation
[ ] A/B testing UI borne
```

## E.6 Checklist Mise en Production

```
□ Code review complet par Claude
□ Tests unitaires Vue (Vitest) > 80% coverage
□ Tests API (PHPUnit) > 80% coverage  
□ Tests E2E (Playwright / E2E verification) scénarios critiques
□ Audit sécurité OWASP Top 10
□ Performance audit (Lighthouse > 90)
□ Documentation technique complète
□ Runbook incident borne
□ Formation équipe cuisine (KDS)
□ Formation caissiers (POS badge)
□ Configuration production TPE
□ Configuration imprimante borne
□ Déploiement staging
□ Déploiement production
□ Monitoring (Sentry, LogRocket)
□ Alertes downtime borne
```

---

## E.7 Analyse Splash à Venir (Windows)

**Fichiers à analyser :**

```
C:\Program Files (x86)\Splash borne & wifi order\app\
├── backend/
│   ├── routes/              → API endpoints
│   ├── models/              → Schema MongoDB
│   ├── services/
│   │   ├── printer.js       → ESC/POS protocol
│   │   ├── tpe.js           → Payment terminal state machine
│   │   └── cache.js         → Offline storage
│   └── rules/
│       └── merchandising.json → Upsell rules
├── frontend/
│   ├── components/          → Vue components
│   ├── store/               → Vuex/Pinia
│   └── styles/              → SCSS/CSS
└── config/
    ├── kiosk.json           → Machine settings
    └── printer.json         → Printer config
```

**Questions pour extraction:**

1. **Printer:** Format ESC/POS utilisé? Librairie node-escpos? Templates tickets?
2. **TPE:** Protocole de communication? Webhook ou polling? State machine?
3. **Offline:** Structure IndexedDB? Sync strategy? Conflict resolution?
4. **Merchandising:** Format règles? Engine d'évaluation? Performance?
5. **Queue:** Atomic counter implémentation? Race conditions gérées?
6. **Idle:** Format vidéo supporté? Fallback? Optimisation?

**Next Step Windows:**
1. Ouvrir projet Splash dans Cursor Windows
2. Indexer tous les fichiers
3. Rechercher mots-clés: `printer`, `tpe`, `offline`, `cache`, `merchandising`, `queue`
4. Documenter findings dans `docs/SPLASH_ANALYSIS.md`
5. Planifier adaptation FoodKing

---

**END OF KNOWLEDGE TRANSFER DOCUMENTATION**

**Date génération:** 2026-03-24  
**Source:** Cursor Mac - Conversation FoodKing Kiosk Rounds 1-9  
**Destination:** Cursor Windows - Projet Splash + continuation FoodKing

**Documents générés:**
1. `00-INTRODUCTION.md` - Contexte et overview
2. `01-ARCHITECTURE.md` - Architecture système complète
3. `02-BORNE-SYSTEM.md` - Détail ultra-fine du système borne
4. `03-BUSINESS-LOGIC.md` - Logique métier (pricing, états, fidélité)
5. `04-FILES-REFERENCE.md` - Tous les fichiers créés/modifiés
6. `05-ROADMAP.md` - Ce qui est fait vs ce qui reste

**Statut:** Prêt pour transfert Windows 🚀
