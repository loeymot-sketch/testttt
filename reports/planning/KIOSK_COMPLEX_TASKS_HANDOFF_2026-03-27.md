# Borne FoodKing — tâches complexes (à faire ensemble)

**Date :** 2026-03-27  
**Contexte :** suite audit profond (parité Splash, robustesse wizard, perf). Les **tâches simples** ci-dessous ont été traitées dans le code ; ce document liste le **reste** pour validation / design commun avant implémentation.

**Type de tests recommandé (quand on implémentera) :** **local-validation** (PHPUnit + Vitest) ; **Playwright / E2E verification** seulement si parcours E2E borne matériel / TPE.

---

## Déjà fait (simple, 2026-03-27)

1. **File offline abandonnée** : `getAbandonedCount()` + bannière rouge « non transmise — prévenez la caisse » dans `KioskAppComponent.vue` ; `syncQueue` retourne `abandonedNew` et pose `abandonedAt`.
2. **401 borne** : après `kioskLogin` silencieux, **rejouer une fois** la requête axios (`__retry401Kiosk`) dans `resources/js/app.js`.

---

## Tâche C1 — Robustesse mapping wizard → payload commande

**Problème :** `kioskCart.js` normalise `item_variations` / `item_extras` par index ; fragile si l’ordre des étapes ou la forme du wizard change.

**Objectif :** Contrat stable item → lignes `OrderRequest` / `FrontendOrderService` / affichage KDS.

**Pistes :**  
- Normaliser côté **wizard** (sortie déjà au format serveur) plutôt qu’au submit.  
- Tests Vitest sur paniers avec plusieurs variations / extras.  
- Vérifier `ValidJsonOrder` aligné avec le nouveau contrat.

**Risques :** régression KDS, doublons d’options.  
**Effort :** moyen à élevé.

---

## Tâche C2 — Catalogue offline (snapshot)

**Problème :** Sans réseau, pas de menu exploitable (contrairement à Splash / Mongo local).

**Objectif :** Snapshot menu (IndexedDB ou localStorage chunké) + TTL + invalidation ; écran dégradé clair si données trop vieilles.

**Pistes :**  
- Réutiliser réponses `kioskMenu/fetchMenu` sérialisées.  
- Endpoint optionnel `If-None-Match` / version menu pour sync légère.

**Risques :** prix obsolètes si mal invalidé — **le serveur reste source de vérité au POST** (déjà OK).  
**Effort :** élevé.

---

## Tâche C3 — Push mise à jour produits (temps réel menu)

**Problème :** TTL 5 min dans `kioskMenu.js` ; pas d’événement « item indisponible / prix » immédiat.

**Objectif :** Événement broadcast (Echo) ou FCM ciblé borne → `INVALIDATE_CACHE` / refetch ciblé.

**Pistes :**  
- Canal par `branch_id` ; payload minimal `{ item_id, action }`.  
- Coexistence avec rate limit API 120/min.

**Effort :** moyen (infra + Laravel events déjà partiellement là).

---

## Tâche C4 — Code-split / bundle kiosk-only

**Problème :** Même `app.js` que l’admin → temps de chargement borne sur matériel faible.

**Objectif :** Entry ou lazy routes pour réduire JS initial sur `/kiosk/*` (Vite / mix selon build actuel).

**Risques :** config build, duplication de dépendances.  
**Effort :** moyen à élevé.

---

## Tâche C5 — Admin borne (5 taps + PIN)

**Problème :** Surface d’abus en salle si PIN faible ou panel trop large.

**Objectif :** Revue menace : durcir `KioskAdminComponent`, rate-limit actions sensibles, audit log.

**Effort :** faible à moyen (surtout spec + quelques garde-fous).

---

## Tâche C6 — Observabilité prod (Sentry / logs structurés)

**Problème :** Erreurs borne difficiles à corréler (offline, 401, sync).

**Objectif :** Sentry front + tags `surface=kiosk`, ou agrégation logs Laravel pour `source=kiosk`.

**Effort :** moyen (config + pas de fuite PII).

---

## Ordre de travail suggéré (avec vous)

1. **C1** en premier (intégrité commande).  
2. **C3** ou **C2** selon priorité métier (fraîcheur vs coupure réseau longue).  
3. **C4** si perf bloquante en pilote.  
4. **C5** + **C6** en parallèle opérationnel.

---

## Validation humaine (workflow AGENTS)

Pour chaque tâche C* : courte validation **GO / MODIFY / STOP** avant grosse implémentation ; exécution Kimi + résumé dans `reports/execution/latest.md`.
