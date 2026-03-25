# FoodKing Kiosk System - Base de Connaissance Complète

## Contexte de Transfert

**Source :** Cursor Mac - Conversation FoodKing Kiosk (Rounds 1-9)  
**Destination :** Cursor Windows - Analyse projet Splash + continuation développement  
**Objectif :** Reprendre exactement où l'on s'est arrêté sur la borne FoodKing

## Résumé Exécutif

**FoodKing** = V2 de Splash 360, système SaaS de borne de commande pour restaurants.  
**Stack :** Laravel 9 (PHP 8.1+) + Vue 3 + MySQL 8 + Sanctum + WebSocket (Pusher/Echo)

**État actuel :** Implémentation complète du flow borne (login machine → confirmation commande).  
**Composants créés :** 19 fichiers Vue, 12 fichiers PHP modifiés, store Vuex complet, routes API.

**Relation avec Splash :** Partenaire officiel avec accès total au code Splash (Windows). Splash est la référence, FoodKing est la reconstruction améliorée en Laravel/Vue.

## Structure de la Documentation

1. **01-ARCHITECTURE.md** - Architecture système, flux données, sécurité
2. **02-BORNE-SYSTEM.md** - Détail ultra-complet du système borne (UI/UX, wizard, panier, paiement)
3. **03-BUSINESS-LOGIC.md** - Logique métier (pricing, commandes, états, fidélité)
4. **04-FILES-REFERENCE.md** - Tous les fichiers créés/modifiés avec contenu clé
5. **05-ROADMAP.md** - Ce qui est fait vs ce qui reste, priorité production

## Points Critiques à Ne Pas Oublier

1. **Auth Machine Kiosk :** La borne s'authentifie avec un token Sanctum spécifique (ability `kiosk:order`), pas avec un user classique
2. **OrderType::KIOSK (25) :** Toutes les commandes borne ont ce type - utilisé pour routing KDS/OSS
3. **Queue Number :** Numéro atomique unique par commande kiosk, affiché partout (KDS, OSS, ticket, confirmation)
4. **Idempotency :** Clé unique par soumission commande pour éviter doubles paiements
5. **Fidélité borne :** Attribuée à PREPARED (pas DELIVERED comme les commandes normales)

## Données Clés du Projet

| Élément | Valeur |
|---------|--------|
| OrderType KIOSK | `25` |
| PaymentGateway CASH | `1` |
| PaymentGateway CARD | `4` |
| PaymentGateway TICKET_RESTAURANT | `5` |
| OrderStatus PENDING | `1` |
| OrderStatus ACCEPT | `4` |
| OrderStatus PREPARING | `7` |
| OrderStatus PREPARED | `8` |
| OrderStatus DELIVERED | `13` |
| OrderStatus CANCELED | `16` |
| Source KIOSK | `5` |
| Token ability | `kiosk:order` |

## Lien avec Splash 360 (Windows)

**Sur Windows :** Code source Splash complet disponible (Node.js + MongoDB + Electron).  
**Utilisation :** Référence pour features avancées (TPE state machine, merchandising rules, offline cache, ESC/POS printing).

**Ne pas copier directement** mais comprendre et adapter à l'architecture Laravel/Vue de FoodKing.

---

**Prochaine étape Windows :** Analyser Splash pour extraire :
- Logique merchandising upsell
- Gestion offline/commandes en attente
- Protocole TPE (webhook payment-confirm)
- Format impression ESC/POS

**Puis :** Implémenter équivalents dans FoodKing backend/frontend.
