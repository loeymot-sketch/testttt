# Prompt à coller dans la première conversation (nouveau compte Cursor)

Copie-colle le bloc ci-dessous **tel quel** dans le premier message du nouveau chat, après avoir ouvert ce dépôt comme workspace racine.

---

## Texte à envoyer à l’agent

```
Tu travailles sur le dépôt FoodKing SaaS (Laravel 9 + Vue 3). Il n’y a aucun historique de notre conversation précédente : ta seule source de vérité est les fichiers du repo.

## Obligation de lecture (ordre strict — avant de proposer du code important)

1. README.md à la racine (hub documentation).
2. docs/HANDOFF_NEW_CURSOR/00_INDEX.md puis 01_DEMARRAGE_5_MINUTES.md.
3. docs/HANDOFF_NEW_CURSOR/CACHE_MEMOIRE_TRANSFERT.md (état du projet, backlog, décisions).
4. docs/PROJECT_CONTINUITY_AND_VISION.md (vision Le Cayenne, surfaces, correctifs à ne pas régresser).
5. AGENTS.md (workflow multi-agents : planning → exécution → review ; types de tests Kimi-test / Anti-Gravity).
6. docs/ARCHITECTURE.md (zones gelées), docs/ORDER_FLOW.md, docs/API_MAP.md et docs/AUTHZ_MATRIX.md si tu touches commandes ou auth.

## Règles Cursor du projet (déjà dans .cursor/rules/)

- project-continuity.mdc : toujours croiser continuité + architecture avant changements lourds.
- Respecter AGENTS.md : petits diffs, pas de contournement recalcul prix serveur, pas d’authz cassée.

## Architecture en 5 lignes

- Monolithe : Controllers → Services (OrderService, FrontendOrderService, KitchenDisplaySystemOrderService…) → Models (Order / FrontendOrder, même table orders) → Events (OrderCreated, OrderStatusChanged, ItemAvailabilityChanged) → Broadcast Soketi/Pusher + FCM via listeners.
- Kiosk Vue : resources/js/components/frontend/kiosk/ ; API /api/frontend/* ; tokens Sanctum machine avec ability kiosk:order.
- Temps réel : canaux private-branch.{branch_id} ; si BROADCAST_DRIVER=null, seul le polling ~30s reste.

## Ce qui est déjà fortement en place (ne pas casser)

- Idempotence borne, locks, isolation branche, Sanctum avec expiration, reset password sécurisé, coupons/loyalty validés serveur, discount ligne forcé côté serveur, audits synchro broadcast documentés sous reports/review/.

## Ce qui reste à faire (priorités — détail dans CACHE_MEMOIRE_TRANSFERT.md)

- Ops : queue async prod, BROADCAST_DRIVER correct partout, FCM si requis.
- Produit : amend POS, parité Splash avancée (« comme d’habitude »), E2E réel (Anti-Gravity).
- Tests : suites PHP par lots (scripts/run_php_feature_batches.sh) ; npm test pour Vitest.

## Plans et audits récents

- reports/planning/AUDIT_PROFOND_PLAN_MASSIF_2026-03-31.md (phases A–E, diagrammes).
- reports/planning/latest.md (entrée planning).
- reports/review/AUDIT_SYNC_BROADCAST_ARCHITECTURE_2026-03-31.md.

## Comportement attendu

- Pour toute feature non triviale : proposer un plan court + type de test (AGENTS.md), puis implémenter dans le périmètre demandé.
- Documenter les résultats dans reports/execution/latest.md si tu exécutes des tests.
- Si incertitude doc vs code : le signaler explicitement.

Commence par confirmer que tu as identifié les chemins HANDOFF_NEW_CURSOR et PROJECT_CONTINUITY_AND_VISION.md, puis dis ce que tu as compris de la vision et du backlog en 10 lignes maximum.
```

---

*Tu peux raccourcir ce prompt si besoin, mais garde au minimum les 6 lectures obligatoires et la référence à `CACHE_MEMOIRE_TRANSFERT.md`.*
