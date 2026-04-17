# Audit global — Alimentations cognitives & parcours commande V1

**Date** : 2026-04-17  
**Périmètre** : flux « nourriciers » (données, auth, événements, UI) pour **prise de commande caisse (POS)**, **client borne (kiosk)**, **post-commande** (KDS, OSS, admin), **synchronisation** et **intelligence système** (règles, garde-fous).  
**Références** : `docs/ORDER_FLOW.md`, `docs/DEVICE_FLOW.md`, `docs/MENU_AVAILABILITY.md`, `reports/execution/AUDIT_MASSIF_FR_2026-04-16.md`, code `app/`, `resources/js/`, `config/`.

---

## 1. Méthodologie

| Couche | Question d’audit |
|--------|-------------------|
| **Alimentation** | D’où vient la donnée ? Qui l’écrit ? Qui la lit ? Que se passe-t-il si elle est absente ou en retard ? |
| **Cognition** | Quelle règle métier est appliquée ? Où est le SSOT ? Y a-t-il une double vérité possible ? |
| **UX / UI** | L’utilisateur (caissier ou client) comprend-il l’état du système (chargement, erreur, hors-ligne, rupture) ? |
| **Sync** | Le changement se propage-t-il en temps voulu aux autres surfaces ? Quel fallback (polling, cache TTL) ? |
| **Validation** | Quels tests (PHPUnit, Playwright, grep CI) prouvent le comportement ? |

---

## 2. Architecture logique du parcours « commande »

### 2.1 Vue d’ensemble (de l’intention à la livraison)

```text
[Client web / Borne]          [Caisse POS]              [Cuisine KDS]        [File OSS]
       |                            |                         |                    |
       v                            v                         v                    v
 POST /api/frontend/order    POST …/pos/order (etc.)   PATCH statuts       Lecture WS
 FrontendOrderService       OrderService              OrderStateMachine    queue_number
       |                            |                         |                    |
       +-------- MySQL (orders, order_items, branch_id) -------+--------------------+
                                    |
                         Domain events / outbox / broadcast (Echo)
```

**SSOT** : MySQL + `OrderService` + `FrontendOrderService` + `OrderStateMachine` (cf. `ORDER_FLOW.md`). Les terminaux n’envoient que des **intentions** ; les montants et transitions légales sont **recalculées / validées serveur**.

### 2.2 Parcours côté **client** (borne / web commande)

| Étape | Surface | Alimentation principale | Cognition / règle | UX & UI |
|-------|---------|-------------------------|-------------------|---------|
| Boot | Kiosk SPA | `master.blade.php` → `window.foodkingConfig` (`kioskAutoLogin`, `kioskMenuPricing`, …) | Auth machine **sans saisie client** ; `config/kiosk.php` + `.env` | Idle vidéo / CTA « toucher pour commander » (`KioskIdleScreenComponent`) |
| Auth silencieuse | Router `kioskRoutes.js` | `kioskCart/kioskLogin` → `POST /api/auth/kiosk-login` | Token Sanctum `kiosk:order` ; maintenance `sessionStorage` | Écran login **sans formulaire** ; messages config vs reconnexion distingués |
| Catalogue | Kiosk | `GET /api/frontend/item` (+ cache menu Vuex `kioskMenu`) | Filtre branche ; **rupture** : doc `MENU_AVAILABILITY` vs audit massif (projection canaux) | Catégories / wizard ; prix affichés (non SSOT calcul client — affichage seulement) |
| Panier | Vuex `kioskCart` | État local + `X-Idempotency-Key` | Quantités plafonnées `maxItemQty` ; file offline IndexedDB | Barre panier, indicateur offline |
| Paiement & envoi | `kioskCart.submitOrder` | `POST /api/frontend/order` | `FrontendOrderService::myOrderStore` recalcule prix | Écrans paiement / attente / confirmation |
| Post-commande | Waiting / OSS | Polling `frontend/order/show` + WS | `queue_number`, statuts | UX attente claire |

### 2.3 Parcours côté **caissier (POS)**

| Étape | Alimentation | Cognition | UX |
|-------|--------------|-----------|-----|
| Auth staff | Sanctum / permissions Spatie | `branch_id`, rôle | Login admin/POS connu |
| Catalogue POS | Endpoints admin/frontend selon surface | Même risque **rupture / menu** que kiosk si non branché | Wizard / liste |
| Création / encaissement | `OrderService::posOrderStore` + `PosOrderRequest` | Total client **ignoré** pour le calcul final ; coupons | Flux cash / carte |
| Statuts | `ValidStatusTransition` + `OrderStateMachine` | Raccourcis POS documentés (`ORDER_FLOW.md`) | KDS notifié via events |

### 2.4 Post-commande & **prise en charge** opérationnelle

| Acteur | Lecture | Écriture | Sync |
|--------|---------|----------|------|
| **KDS** | Liste commandes acceptées | `PREPARING` → `PREPARED` | Echo + file métier |
| **OSS** | Statuts / numéros file | Aucune | WS lecture seule (`DEVICE_FLOW.md`) |
| **Admin** | Dashboard / commandes | Annulation, rupture, paramètres | Partiel temps réel (widgets audit massif) |

---

## 3. Inventaire des **alimentations cognitives** (par tuyau)

### 3.1 Configuration & runtime

| Source | Cible | Risque résiduel | Statut V1 |
|--------|-------|-----------------|-----------|
| `.env` (`KIOSK_MACHINE_*`, `MIX_*`, Pusher) | SPA + Echo | Oubli de variable → borne bloquée ou WS mort | **Amélioré** : défauts `APP_ENV=local` pour kiosk machine + `phpunit.xml` + `.env.example` explicites |
| `config/kiosk.php` | `kioskAutoLogin` JSON | Prod : jamais de fallback mot de passe implicite | OK si `APP_ENV` ≠ `local` |
| `config/app.php` (`api_key`) | Header `x-api-key` | `config:cache` vs `env()` nu | Déjà documenté (middleware) |

### 3.2 API HTTP (backend)

| Domaine | Endpoints représentatifs | Cognition |
|---------|--------------------------|-----------|
| Commande client | `POST /api/frontend/order` | `FrontendOrderService::myOrderStore` |
| Commande POS | Admin `PosController` → `posOrderStore` | `OrderService::posOrderStore` |
| Auth borne | `POST /api/auth/kiosk-login` | Machine active + hash |
| Menu | `GET /api/frontend/item`, admin POS categories | **Point critique audit massif** : lecture `item_branch_availability` / canaux à valider surface par surface |
| Admin projection | `GET /api/admin/menu-projection` | `MenuProjectionService` — snapshot cohérent |

### 3.3 Données & persistance

| Entité | Rôle cognitif | Commentaire |
|--------|---------------|---------------|
| `orders` / `order_items` | Vérité commande | `branch_id` obligatoire |
| `item_branch_availability` | Rupture locale | `AvailabilityService` + `DecrementItemAvailabilityOnOrder` ; défaut « disponible » si pas de ligne |
| `domain_events` / outbox | Fiabilité multi-worker | Listeners `PersistItemAvailabilityChangedToOutbox`, job dispatch |

### 3.4 Événements & temps réel

| Event (ex.) | Consommateurs visés | Intelligence |
|-------------|---------------------|--------------|
| `ItemAvailabilityChanged` | Outbox, bump snapshot menu, **Echo** (`KioskAppComponent` `_subscribeEchoChannel`) | Idempotence toggle ; type `full` → refetch menu |
| `OrderCreated` / statuts | POS, KDS, push | Après commit ; pas dans transaction DB critique (règles sécu projet) |

### 3.5 Front (état & « cognition locale »)

| Module | Responsabilité | Risque |
|--------|----------------|--------|
| `kioskCart` Vuex | Panier, token, idempotency | 401 → retry login si `kioskAutoLogin` présent (`app.js` interceptor) |
| `kioskMenu` | Cache catalogue | Doit refléter events + TTL |
| `ConnectionStatusBanner` | WS | Peut être confondu avec erreur auth — **distinct** par nature |
| POS / Admin stores | Listes commandes, menu | Désynchronisation si pas d’abonnement (audit massif : POS menu live) |

---

## 4. Audit **UX / UI** transversal (commande)

| Principe | Borne | POS | KDS / OSS |
|-----------|-------|-----|-----------|
| Pas de secret client visible | Oui (machine côté serveur) | N/A | N/A |
| État réseau explicite | Bannière WS + offline queue | À harmoniser avec même vocabulaire ? | OSS lecture seule |
| Rupture visible avant tap | Dépend **filtrage API + store** ; chantier MENU_86 | Idem | Badge commande (V1.5 partiel audit) |
| Erreur récupérable | Login écran : retry + message config | Messages validation POS | — |
| Accessibilité rush | CTA large, idle timer, « toujours là ? » | Raccourcis (V1.5) | Contraste (V2 audit) |

---

## 5. Synchronisation & **cross-cutting** (prises de commande)

| Scénario | Chaîne attendue | Point de contrôle |
|----------|-----------------|-------------------|
| Rupture admin → borne | `AvailabilityService` → event → outbox → broadcast → `kioskMenu/UPDATE_ITEM` | Latence p95 ; fallback refetch |
| Rupture → POS | Même event | **Audit massif** : abonnement POS à compléter |
| Commande kiosk → caisse | Order créée → notification / liste | Idempotency + pas de double file |
| Prix catalogue changé | Event ou refetch | POS « menu live » (gap) |
| WS down | Bannière + polling order show | Acceptable si dégradé documenté |

**Croisements** à ne pas casser lors des corrections :

- Symétrie **POS / Kiosk** sur validation commande (`OrderService` vs `FrontendOrderService`).
- **Branche** : tout payload scopé `branch_id`.
- **Notifications** : après commit (`safety.mdc`).

---

## 6. Intelligence système (où le produit « raisonne » bien / mal)

### 6.1 Forces (déjà « intelligent »)

- **Prix SSOT serveur** : recalcul depuis DB, coupons, plancher total.
- **Machine à états** documentée + `apply()` transactionnel + tests domaine.
- **Idempotency** kiosk/POS + contraintes DB.
- **Queue number** atomique (cache lock).
- **Menu availability** : service transactionnel + event + outbox + tests ciblés (`MENU_AVAILABILITY.md`, tests `MenuProjection*`, `BumpMenuSnapshot*`).

### 6.2 Tensions / dette cognitive (à planifier)

| Zone | Symptôme | Impact |
|------|------------|--------|
| Menu POS vs events | Pas abonné comme la borne | Prix / 86 désynchronisés |
| Dashboard | Pas de widget ruptures | Ops moins réactifs |
| Rôles (ex. Delivery Boy) | Landing sans permission | UX cassée post-login |
| Embeddings / MCP (hors cœur caisse) | Quota / config | Ne pas confondre avec synchro menu |

---

## 7. Écart vs **AUDIT_MASSIF_FR** (rappel ciblé)

Les items toujours **prioritaires** pour coller au rapport du 2026-04-16 :

1. Brancher partout la **lecture** `item_branch_availability` + **canaux** (`channels`) sur les APIs menu POS/kiosk.
2. **POS** : écouter `ItemAvailabilityChanged` comme le kiosk.
3. **UI rupture 1 clic** + workflow admin unifié (partiel V1.5 mais rupture = V1 critique).
4. **SYNC_BACKBONE / OUTBOX / EVENT_CONTRACT** : finir les scénarios edge (reconnexion, observer, doc contrat).
5. **Tests Playwright** : 5 flows stables + non-régression parcours commande.

---

## 8. Orchestration — Plan de correction **priorisé**

| Vague | Contenu | Objectif « V1 solide » |
|-------|---------|------------------------|
| **A** | EVENT_CONTRACT + OUTBOX + SYNC_BACKBONE | Fondations temps réel fiables |
| **B** | MENU_86 (API + projection + POS subscribe + admin toggle) | Parcours client/caissier alignés sur rupture & canaux |
| **C** | STATUS_MACHINE + PRICING_SSOT (gate) | Intégrité commande |
| **D** | SEC_*, OBS_*, tests PHPUnit + Playwright | Validation & exploitation |

Chaque vague : **PLAN fichier** → implémentation ciblée → **tests** listés → mini **audit** avant merge.

---

## 9. Matrice **tests / preuves** recommandées

| Flux | PHPUnit / Feature | Playwright / E2E |
|------|-------------------|------------------|
| Kiosk login → order | `KioskLoginApiTest`, commande concurrente si présent | Flow kiosk commande |
| Menu + rupture | `AvailabilityServiceTest`, `MenuProjectionServiceTest` | Vérifier item masqué après toggle API |
| POS order | `PosOrder` / pricing si existant | POS cash / card |
| State transitions | `OrderStateMachineTest`, `OrderStateMachineApplyTest` | KDS transitions |
| Auth / 401 kiosk | Interceptor `app.js` | — |
| Staff-only routing | `StaffOnlyRoutingTest` | `06-staff-only-routing.spec.js` |

**Barre « hyper validé »** : suite verte + **10 exécutions consécutives** des specs Playwright critiques + grep CI (invariants prix / statuts) comme dans l’audit massif §8.4.

---

## 10. Synthèse exécutive

- Les **alimentations** sont globalement **bien modélisées** (SSOT, events menu, borne auto-login renforcée).  
- Le **gap principal** reste la **cohérence multi-surface du menu / 86** (POS = kiosk côté événements & filtres API) et la **finition** synchro/outbox.  
- L’**architecture** doc + code est **alignée** ; l’**UX** borne idle → commande est cohérente dès que la **config machine** et la **DB** le permettent.  
- Ce document sert de **carte** pour orchestrer les correctifs **sans élargir le scope** au-delà de V1.

**Prochaine action recommandée** : ouvrir un cycle `MENU_86` avec tâche unique du type *« brancher `AvailabilityService::isAvailable` sur la réponse `GET /api/frontend/item` pour la branche courante »* + tests Feature + spec Playwright minimal — puis itérer surface par surface (POS, admin).

---

*Document généré pour planification interne FoodKing V1 — à mettre à jour après chaque vague livrée.*
