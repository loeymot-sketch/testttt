# V1 Foundation Verdict — Est-ce que la base actuelle suffit pour ton fast-food ?
**Date :** 2026-05-08
**Auteur :** Claude orchestrateur
**Cadre :** **V1 SEULEMENT** — POS + Kiosk + KDS + OSS + Admin Backoffice. Pas de mobile, pas de web ordering, pas de SaaS, pas de delivery. Tu valides ta base avant de la déployer dans ton resto.

> Re-cadrage : J'ai rédigé hier un audit stratégique 24 mois SaaS B2B. Tu m'as recadré : tu veux **vérifier la base V1 maintenant**, pas planifier un SaaS futur. Ce document remplace la lecture stratégique. Les docs antérieurs `AUDIT_STRATEGIC_VISION`, `COMPETITOR_GAP_ANALYSIS`, `ROADMAP_SAAS_B2B` restent dans `plans/` comme référence long-terme — **à ignorer pour V1**.

---

## 1. VERDICT GLOBAL

> **La base V1 est structurellement solide.** Les 5 sous-systèmes (POS, Kiosk, KDS, OSS, Admin) sont architecturés correctement, la synchronisation entre eux est fonctionnelle dans le code (Outbox pattern + Pusher + polling 30s fallback), et l'availability item per-branche EXISTE DÉJÀ avec auto-86 sur max_daily_qty.

> **MAIS il y a 3 blockers prod absolus** à traiter avant le déploiement, sinon ça **paraîtra** marcher en simulation et **cassera silencieusement** quand tu mettras en prod réelle. Détaillés en §3.

---

## 2. ÉTAT V1 — Vérification par sous-système

### 2.1 POS Web (caisse) — **🟢 STRUCTURELLEMENT OK**

✅ Vue 3 + wizard Vanilla JS frozen (design parfait selon toi)
✅ `OrderService::posOrderStore` complet : prix recalculé SSOT depuis DB (pas de payload trust), idempotency_key, fiscal_sequence_no NF525, audit chain HMAC
✅ Permissions Spatie complètes (pos, pos-orders, pos-discount-up-to-10/over-10/unlimited, pos-manage-fiscal, pos-destroy-paid)
✅ Discount avec motif obligatoire + plafonds par rôle
✅ Cash validation server-side (pos_received_amount ≥ total)
✅ Refund mirror NF525 conforme (RefundWithCounterEntryService)

### 2.2 Kiosk (borne) — **🟢 STRUCTURELLEMENT OK** (avec 1 trou compliance NF525, F-001)

✅ Vue 3 + Electron Windows + bridge `kioskHardware` agnostique
✅ Wizard parfait selon toi (frozen, tests autorisés)
✅ Hardware unifié : `tpeCharge`, `openDrawer`, `printReceipt`, `printEscPos`, `scanQR`
✅ Idempotency + lock cache distribué + retry payment-confirm
✅ Auto-promote ACCEPT après payment confirmé (finalizePaidKioskOrder)
❌ **F-001 trou NF525** : kiosk orders ne reçoivent PAS de fiscal_sequence_no → exclus du Z report → conformité fiscale cassée pour le canal kiosk

### 2.3 KDS (cuisine) — **🟢 STRUCTURELLEMENT OK**

✅ Subscribes Pusher channel `private-branch.{id}` events `OrderCreated`, `OrderStatusChanged`, `ItemAvailabilityChanged`
✅ Polling 30s en filet de sécurité
✅ State machine V1 stricte (ACCEPT → PREPARING → PREPARED)
✅ Permissions chef isolées par branche

### 2.4 OSS (écran public file d'attente) — **🟢 STRUCTURELLEMENT OK**

✅ Lecture seule stricte
✅ Subscribes Pusher idem KDS
✅ Polling fallback identique

### 2.5 Admin Backoffice — **🟢 STRUCTURELLEMENT OK**

✅ Vue 3 dashboard SPA
✅ CRUD complet : items, catégories, branches, users, taxes, coupons, kiosk machines, kiosk setup, allergens
✅ Reports : sales, items, credit balance, X/Z fiscal
✅ BranchScope global Eloquent (admin branch_id=0 voit tout, staff voit sa branche)

### 2.6 Tableau récap

| Sous-système | Statut V1 | Blocker pour deploy ton resto |
|---|---|---|
| POS | 🟢 OK | — (sauf §3 transverses) |
| Kiosk | 🟢 OK | F-001 (NF525 fiscal) |
| KDS | 🟢 OK | — |
| OSS | 🟢 OK | — |
| Admin | 🟢 OK | — |

---

## 3. LES 3 SEULS BLOCKERS V1 À TRAITER AVANT TON DÉPLOIEMENT

> Filtrage strict : sur les 15 findings audit (F-001..F-015), seuls **3 sont vraiment bloquants pour ton V1 fast-food en simulation TPE**. Les autres sont importants à terme mais pas pour le go-live.

### 3.1 🔴 F-015 — Production blocker queue config (1 jour)

**Le problème en 1 phrase** : `.env.example` defaults à `QUEUE_CONNECTION=sync` + `docs/REALTIME_SETUP.md` dit "sync est suffisant", MAIS le code utilise outbox pattern qui exige queue worker. Si tu deploy avec defaults, KDS/OSS reçoivent rien en realtime, polling 30s masque cosmétiquement.

**Pourquoi c'est bloquant** : tu testeras en local en simulation (sync = OK) → tout marche → tu deploy en prod resto → tu changes pour redis sans worker → silence radio realtime → caisse et cuisine désynchronisées.

**Effort** : 1 jour. Plan : [`PLAN_AUDIT_F015_QUEUE_CONFIG_PROD_2026-05-07.md`](plans/PLAN_AUDIT_F015_QUEUE_CONFIG_PROD_2026-05-07.md).

### 3.2 🔴 F-001 — Kiosk fiscal_sequence_no (1 jour)

**Le problème en 1 phrase** : Kiosk orders n'ont jamais `fiscal_sequence_no` → exclus du Z report → tickets kiosk invisibles fiscalement → violation NF525.

**Pourquoi c'est bloquant** : tu prends ton premier ticket kiosk en prod → ton Z journalier sera **incomplet** → infraction NF525 dès la première vente.

**Effort** : 1 jour. Plan : [`PLAN_AUDIT_F001_KIOSK_FISCAL_SEQUENCE_2026-05-07.md`](plans/PLAN_AUDIT_F001_KIOSK_FISCAL_SEQUENCE_2026-05-07.md).

### 3.3 🟠 F-005 — Queue number fallback collision (0.5 jour)

**Le problème en 1 phrase** : sur lock timeout, fallback `microtime % 9999` peut collider avec la séquence légitime du jour → numéros A0042 + A0042 affichés sur OSS.

**Pourquoi c'est bloquant** : ton premier rush midi avec 50 commandes simultanées → 1-2 collisions visibles sur l'OSS → confusion clients.

**Effort** : 0.5 jour. Plan : [`PLAN_AUDIT_F005_QUEUE_NUMBER_FALLBACK_2026-05-07.md`](plans/PLAN_AUDIT_F005_QUEUE_NUMBER_FALLBACK_2026-05-07.md).

### 3.4 Ce qui PEUT attendre après go-live ton resto

| Finding | Pourquoi tu peux différer |
|---|---|
| F-002 TPE amount echo | Tu es en simulation TPE actuellement. À traiter quand tu branches le vrai TPE (semaines/mois) |
| F-003 cash reconciliation | Mineur si tu géres ta caisse manuel comme aujourd'hui. À traiter quand tu veux un Z avec variance auto |
| F-004 cancel reason | UX nice-to-have, pas un blocker fonctionnel |
| F-006 POS idempotency parity | Edge case (double-clic caissier). Bug rare en pratique |
| F-007 kiosk lock branch fallback | Edge case auth |
| F-008 payment reconcile queue | Pertinent quand TPE réel branché |
| F-009 kiosk cash backend hook | Pertinent pour reconciliation auto, pas blocker fonctionnel |
| F-010 → F-014 | P2/P3, backlog |

---

## 4. SYNCHRONISATION POS↔KIOSK↔KDS↔OSS — Vérification approfondie

> Tu as insisté sur la sync. Voici l'état exact, vérifié dans le code.

### 4.1 Architecture actuelle (vérifiée)

```
[POS ou Kiosk crée order]
    ↓
DB::transaction
    ├─ INSERT order...
    ├─ event(OrderCreated)
    │     └─ Listener PersistOrderCreatedToOutbox
    │           ├─ INSERT domain_events row (channel=branch.X)
    │           └─ DB::afterCommit → DispatchDomainEventsJob queue
    └─ COMMIT
         ↓
Worker queue lit DispatchDomainEventsJob
    ↓
Pusher::trigger(channel, broadcast_as, payload)
    ↓
[KDS] [OSS] [POS autre poste] [Kiosk autres bornes]
    ├─ Echo client subscribed à private-branch.{id}
    └─ Polling 30s en filet de sécurité
```

### 4.2 Forces vérifiées

✅ **Outbox pattern correctement implémenté** : event durable en DB avant broadcast, retries exponential backoff [1, 5, 30, 300] secondes, audit trail (attempts, last_error, dispatched_at).
✅ **Validation envelope** des deux côtés : backend `EventContract::assertEnvelopeValid`, frontend `eventContract.js` valide à la réception.
✅ **Channel scoping branch strict** : `routes/channels.php` enforce kiosk peut pas écouter d'autres branches.
✅ **Polling 30s en fallback** sur tous les écrans temps réel.
✅ **Versioning événementiel V1** : types canoniques (`order.created`, `order.status_changed`, `menu.item_availability_changed`).

### 4.3 Le seul gros risque sync = F-015

Si queue worker pas démarré → events bloqués en `domain_events.dispatched_at = NULL` → KDS/OSS reçoivent rien → polling 30s masque → tu vois les commandes à 30s de retard sans alerte.

**Une fois F-015 traité** (health check + monitoring + doc corrigée), la sync est **production-grade**.

### 4.4 Test pour valider la sync en prod

Tu peux tester ainsi avant le go-live :

```bash
# 1. Démarrer le worker
php artisan queue:work --queue=high

# 2. Faire une commande POS web ou Kiosk
# 3. Observer en parallèle dans 3 onglets : KDS, OSS, autre POS
# 4. Le ticket doit apparaître <2 secondes (pas 30 secondes) sur les 3 surfaces
# 5. Changer status sur KDS (PREPARING) → l'OSS doit refresh <2 secondes
# 6. Close worker → refaire commande → tout doit lag à 30s (polling fallback)
```

Si ces 6 étapes passent, ta sync est solide.

---

## 5. GESTION STOCK SYNCHRONISÉE — État + Plan V1+

> ⚠️ **Mise à jour 2026-05-08 (post recadrage owner)** : la section ci-dessous décrit la couche **items uniquement**. L'owner a posé une question critique sur la rupture des **extras (sauces, suppléments) et variations** — ces entités **NE sont PAS couvertes** par `item_branch_availability`. Plan complet F-016 dédié : [`PLAN_AUDIT_F016_STOCK_ORCHESTRATION_V1_2026-05-08.md`](plans/PLAN_AUDIT_F016_STOCK_ORCHESTRATION_V1_2026-05-08.md). Lire ce document EN PRIORITÉ.

### 5.1 Items only — 90% existe déjà

Ton besoin :
> "Si tomorrow il y aura un site web, il y aura la gestion stock depuis le dashboard. Stock, si rupture, sera synchronisé sur tous les autres systèmes."

**Ce qui existe DÉJÀ et marche** (vérifié dans le code) :

✅ **Table `item_branch_availability`** (migration 2026_04_15_230100). Colonnes :
- `is_available` (boolean) — disponible / en rupture
- `unavailable_reason` (out_of_stock | seasonal | closed_today | manual)
- `unavailable_since` (datetime)
- **`max_daily_qty`** (nullable int) — quota jour, NULL = illimité
- **`daily_consumed_qty`** (int) — auto-incrémenté à chaque commande
- `daily_reset_at` (date) — reset quotidien automatique

✅ **Service `App\Services\Menu\AvailabilityService`** :
- `toggle()` — admin marque un item en rupture sur 1 branche
- `toggleForAllBranches()` — admin marque sur toutes
- `decrementForOrder()` — listener auto sur `OrderCreated`, **déclenche auto-86 si max_daily_qty atteint**

✅ **Event `ItemAvailabilityChanged`** broadcast :
- Mode global : édition admin → diffuse à toutes les branches
- Mode per-branch : rupture locale → diffuse à 1 branche

✅ **Outbox pattern** : `PersistItemAvailabilityChangedToOutbox` → durable + retries

✅ **Listener cache invalidation** : `InvalidateKioskMenuCacheOnItemAvailabilityChanged` flush le cache menu kiosk

✅ **Frontend Echo subscription** :
- `KioskAppComponent.vue:385` reçoit l'event et update kiosk menu store
- `PosComponent.vue:1086` reçoit et update UI POS (pastille rupture)
- `resources/js/store/modules/kioskMenu.js:143-217` partial update logic

✅ **Tests** : `tests/Feature/Menu/AvailabilityServiceTest.php` (toggle + idempotence + outbox + decrement auto-86)

### 5.2 Concrètement, comment ça marche pour ton fast-food

**Scénario opérationnel** :

1. Manager va dans Admin backoffice → Item "Burger BBQ" → règle `max_daily_qty = 80` pour la branche.
2. Au cours de la journée, les commandes (POS + Kiosk) déclenchent `decrementForOrder` automatique.
3. Quand `daily_consumed_qty == 80` → auto-86 déclenche `ItemAvailabilityChanged::forBranch(itemId, branchId, false, 'out_of_stock')`.
4. Outbox persiste → worker dispatche → Pusher broadcast.
5. **Kiosk** : item disparaît du menu en moins de 2 secondes.
6. **POS** : pastille rouge "Rupture" apparaît sur le bouton item.
7. **KDS** : ne fait rien (l'item est déjà commandé, pas besoin de masquer).
8. **OSS** : ne fait rien (statut commande seulement).
9. À 00h00 le lendemain, `daily_reset_at` < today au prochain order → reset auto `daily_consumed_qty = 0` → re-disponible (sauf si toggle manual rupture toujours actif).

**Pour le manager via dashboard, à toggle manuel** :
- POST `/api/admin/menu/availability/toggle` avec `{item_id, branch_id, is_available, reason}`.
- Idempotent : ne re-broadcast pas si état identique.

### 5.3 Le 10% qui manque (à ajouter pour ton V1+)

| # | Manque | Effort | Criticité V1 |
|---|---|---|---|
| 1 | UI dashboard "Stock view" : tableau par branche × items avec is_available + max_daily_qty éditable + daily_consumed_qty visible | 2-3 j | High |
| 2 | UI bouton "Rupture rapide" depuis le POS (caissier signale lui-même rupture sans dashboard) | 1 j | Medium |
| 3 | Notification dashboard quand un item passe en auto-86 (badge rouge + son optionnel) | 1 j | Medium |
| 4 | Endpoint `GET /api/admin/menu/availability/branch/{id}` pour le dashboard | 0.5 j | High (dépendance UI 1) |
| 5 | Bulk update `max_daily_qty` via CSV import (pratique pour set quotas hebdo) | 1 j | Low |

**Total effort additionnel pour stock V1+** : 5-7 jours.

### 5.4 Plan d'extension V2+ (DIFFÉRÉ — pas pour V1)

Tu m'avais aussi mentionné le futur site web. La fondation existante couvre :
- Quand le site web sera ajouté, il s'abonne aussi à `private-branch.{id}` events `ItemAvailabilityChanged`. Aucun refactor backend nécessaire.

Pour aller au-delà du compteur quotidien (vrai stock matières premières) en V2+ :
- Inventory ingredient-level (recettes décrémentent ingrédients)
- Auto-restock scheduler configurable
- Suggestions de substitution
- Catégorie-level rupture

→ MENU_AVAILABILITY.md doc §"Hors V1 (V2+)" liste déjà ces extensions futures. Pas pour maintenant.

---

## 6. ORDRE D'EXÉCUTION RECOMMANDÉ POUR TON GO-LIVE — V2 révisé 2026-05-08

> Trier ce qui doit être fait AVANT que tu deploy dans ton resto, dans l'ordre logique.

```
Sprint S0 (1 jour)         : F-015 production blocker queue config
   ↓
Sprint S1a (1 jour)        : F-001 NF525 kiosk fiscal_sequence_no
   ↓
Sprint S1b (0.5 jour)      : F-005 queue number monotonic fallback
   ↓
Sprint S2 (10-12 jours)    : F-016 Stock orchestration items + extras + variations
                             + UI dashboard stock manager (parallèle 4-5j)
   ↓
═══ TON GO-LIVE FAST-FOOD ═══

Total révisé : 13-15 jours-agent (au lieu de 8-10 sans la couche extras/variations)
   ↓
[Backlog quand TPE réel branché : F-002, F-008]
[Backlog quand opérations validées : F-003, F-009]
[Backlog UX polish : F-004, F-006, F-007]
[Backlog refactor : F-010, F-011, F-012, F-013, F-014]
```

**Total avant go-live : ~8-10 jours-agent.**

À ce stade tu as :
- POS + Kiosk + KDS + OSS + Admin fonctionnels
- NF525 conforme sur les 2 canaux (POS + Kiosk)
- Sync realtime production-grade (worker monitoré, polling fallback)
- Stock auto-86 sur quota jour + UI dashboard pour les manager

---

## 7. RÉPONSE DIRECTE À TES QUESTIONS

### Q1. "Notre structure actuelle est bien conformée comme base ?"

**OUI**. Les 5 sous-systèmes sont architecturalement corrects, les patterns critiques (outbox, audit chain HMAC, BranchScope, idempotency, NF525) sont en place. Tu n'as **pas besoin** de refactoriser la base. Tu as besoin de la **finir proprement** sur 3 points (F-015, F-001, F-005) et de lui ajouter une **UI stock manager** (5-7 j).

### Q2. "Sync entre POS, kiosk, KDS, OSS, admin — fiable ?"

**OUI** dans le code — non en prod tant que F-015 pas traité (queue worker). Une fois F-015 fixé, la sync est solide (Outbox + Pusher + polling 30s + monitoring).

### Q3. "Stock sync depuis le dashboard, propagation rupture sur tous les écrans — possible ?"

**OUI, déjà 90% implémenté**. Tu as `item_branch_availability` + `AvailabilityService` + event `ItemAvailabilityChanged` + outbox + frontend handlers POS/Kiosk. Il manque la **UI dashboard** pour que le manager configure et voie l'état stock — 5-7 jours de dev.

### Q4. "Demain le site web pourra écouter le même flux ?"

**OUI sans refactor**. Le site web (futur) s'abonnera à `private-branch.{id}` channel et écoutera `ItemAvailabilityChanged` exactement comme le Kiosk fait aujourd'hui. Architecture event-driven = extensible.

---

## 8. CE QUE JE TE RECOMMANDE COMME OWNER (acte du chef d'orchestration)

1. **Geler tout autre développement** (mobile, web ordering, SaaS, marketing) jusqu'à ce que V1 soit live propre.
2. **Faire les 3 fixes obligatoires** : F-015, F-001, F-005 → 2.5 jours.
3. **Ajouter la UI stock manager dashboard** (§5.3 items 1, 3, 4) → 4-5 jours.
4. **Tester sync 6 étapes** (§4.4) avant deploy.
5. **Deploy en production ton fast-food** avec confiance.
6. **Mesurer 2-4 semaines** : combien de commandes/jour, latence sync observée, ruptures auto-86 fréquentes ou non.
7. **Itérer après** : avec data réelle, prioriser le prochain sprint (F-004 reason cancel ? F-002 TPE quand vrai TPE ? UI rupture rapide POS ?).

> Les docs stratégiques `AUDIT_STRATEGIC_VISION_2026-05-07.md`, `COMPETITOR_GAP_ANALYSIS_2026-05-07.md`, `ROADMAP_SAAS_B2B_2026-05-07.md` restent dans `plans/` mais **mets-les en backlog**. Tu les ressortiras dans 6-12 mois quand tu seras prêt à parler SaaS B2B vente.

---

## 9. CHECKLIST FINALE AVANT TON GO-LIVE

```
[ ] F-015 traité — queue worker actif + monitoring + doc REALTIME_SETUP corrigée
[ ] F-001 traité — kiosk orders ont fiscal_sequence_no, Z report inclut tout
[ ] F-005 traité — queue number fallback monotonic Z-prefixed
[ ] UI stock manager dashboard livré
[ ] Test sync 6 étapes vert (§4.4)
[ ] Backup automatique DB nuit OK
[ ] Health check `/api/health/ready` retourne 200
[ ] worker queue redémarre auto sur crash (supervisord/systemd configuré)
[ ] Pusher / Soketi accessible depuis prod env
[ ] Stress test 50 commandes en parallèle sans collision queue_number
[ ] 1 caissier formé sur le POS
[ ] 1 cuisinier formé sur le KDS
[ ] Procédure rollback documentée
```

Si toutes les cases sont ✅, **tu peux deploy avec confiance**.

---

## 10. SIGNATURE

- Audit conduit par : Claude orchestrateur
- Date : 2026-05-08
- Cadre : V1 fast-food owner only — pas SaaS, pas mobile, pas web ordering
- Évidence : référencée file:line ou marquée vérification directe
- Verdict global : **Base V1 solide → 3 fixes critiques → UI stock → go-live**

— *Une base qui marche bat dix fonctions qui plantent. La discipline V1 prime.*
