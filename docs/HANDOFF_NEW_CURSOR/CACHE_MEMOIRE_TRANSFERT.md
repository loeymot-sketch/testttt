# Cache mémoire — transfert entre comptes / sessions Cursor

**Usage** : document **à lire en entier** au démarrage d’une nouvelle conversation (nouveau compte, pas d’historique chat). Il condense l’état du projet au **2026-03-31** ; le **code et Git** restent la référence finale.

---

## 1. Identité projet

| Champ | Valeur |
|-------|--------|
| Nom | FoodKing SaaS |
| Cible opérationnelle | Restaurant **Le Cayenne** (branding, menu EUR, seed local) |
| Forme | Monolithe **Laravel 9** + **SPA Vue 3** + **MySQL** |
| Auth API | **Sanctum** (tokens, expiration configurée) + **Spatie** rôles/permissions |
| Clé API | Header `x-api-key` aligné sur `config('app.api_key')` / `MIX_API_KEY` |

---

## 2. Surfaces applicatives (toutes dans ce dépôt sauf shell Electron)

| Surface | Chemin indicatif | Rôle |
|---------|------------------|------|
| Admin | `resources/js/components/admin/` | CRUD, réglages |
| POS | `resources/js/components/admin/pos/` | Caisse web |
| KDS | `resources/js/components/admin/kitchenDisplaySystem/` | Cuisine |
| OSS | `resources/js/components/admin/orderStatusScreen/` | File d’attente client |
| Kiosk | `resources/js/components/frontend/kiosk/` | Borne client (Vue) |
| Auth client | `resources/js/components/frontend/auth/` | Login, reset password |

**Electron borne** : souvent hors repo (`borne-windows/`) — ne pas supposer présent dans le même dossier.

---

## 3. Cœur métier (fichiers à ne pas « simplifier » sans plan)

| Domaine | Fichiers pivots |
|---------|-----------------|
| Commande kiosk/web | `FrontendOrderService.php`, `Frontend/OrderController.php`, `FrontendOrder` |
| Commande POS/tables | `OrderService.php`, `Order` |
| Cuisine | `KitchenDisplaySystemOrderService.php` |
| Coupons | `CouponService.php` |
| Menu temps réel | `ItemService.php` + event `ItemAvailabilityChanged` |
| Canaux WS | `routes/channels.php`, `BroadcastServiceProvider.php` |

**Invariant** : prix, taxes, coupons, fidélité = **calcul serveur** ; le client envoie des intentions.

---

## 4. Synchronisation (mémoire opérationnelle)

| Mécanisme | Détail |
|-----------|--------|
| REST | Source de vérité lecture/écriture métier |
| WebSocket | `private-branch.{branch_id}` ; events `.OrderCreated`, `.OrderStatusChanged`, `.ItemAvailabilityChanged` |
| Auth Echo | Bearer Sanctum sur `/api/broadcasting/auth` — `bootstrap.js` + `_refreshEchoAuth()` |
| Fallback | Polling ~30s sur KDS/OSS/POS si pas d’Echo |
| FCM | Listeners sur `OrderCreated` / `OrderStatusChanged` → jobs ; dépend config Firebase |
| Risque #1 config | `BROADCAST_DRIVER` par défaut `null` → **aucun** WS réel |

**Paiement kiosk différé** : `OrderCreated` peut être retardé jusqu’à confirmation paiement pour ne pas envoyer des impayés au KDS.

---

## 5. Ce qui a été développé / durci (cycles récents — ne pas régresser)

- Idempotence + `Cache::lock` sur création commande ; mutex file offline (`kioskOfflineQueue.js`).
- Tokens Sanctum avec expiration ; refresh révoque l’ancien token.
- Reset password avec jeton post-OTP ; IDOR adresses ; loyalty `/check` derrière auth où requis.
- Allowlist colonnes tri / échappement LIKE (Coupon, Order, KDS).
- `item_discount` / discount ligne non trusted client (forcé serveur).
- i18n borne, helpers `kioskPricing`, catégories sandwich / merchandising (selon branches du projet).
- Tests Feature nombreux stabilisés ; exécution PHP **par lots** (`scripts/run_php_feature_batches.sh`) pour éviter OOM.
- Vitest sur helpers kiosk.
- Documentation : `API_MAP`, `TEST_PLAN`, handoff `HANDOFF_NEW_CURSOR/`, `README` hub.

---

## 6. Backlog priorisé (mémoire décisionnelle)

| Priorité | Sujet |
|----------|--------|
| P0 | Queue async prod + worker ; `BROADCAST_DRIVER=pusher` + Soketi opérationnel partout |
| P0 | Santé temps réel (sinon tout repose sur polling 30s) |
| P1 | FCM clés projet si push requis |
| P1 | **Amend commande POS** (spec + API + UI) — non livré complet |
| P2 | E2E matériel / multi-écrans (**Anti-Gravity**) |
| P2 | Parité Splash : « comme d’habitude », merchandising avancé (voir `reports/planning/SPLASH_*.md`) |
| P3 | Optim fan-out `ItemAvailabilityChanged` si très nombreuses branches |

---

## 7. Workflow multi-agents (mémoire procédurale)

| Rôle | Responsabilité |
|------|----------------|
| Architecte / review | Décisions, audit, plan avec type de test |
| Implémentation (Kimi) | Patches, PHPUnit/Vitest, `reports/execution/latest.md` |
| Anti-Gravity | E2E / QA critique **si** plan ou review l’exigent |

Fichiers : `AGENTS.md`, `workflows/*.md`, sorties sous `reports/planning|execution|review|antigravity/`.

---

## 8. Fichiers « toujours vérifier » avant merge sensible

- `OrderService.php`, `FrontendOrderService.php` — transitions statut + `OrderStatusChanged`.
- `routes/api.php` — middlewares, throttle.
- `routes/channels.php` — isolation borne par branche.
- Tests liés dans `tests/Feature/` (Kiosk, KDS, Security, Order).

---

## 9. Comptes / env (local)

Voir `docs/LOCAL_TEST_ACCOUNTS.md`, `docs/AUDIT_LOGIN_ACCOUNTS.md`. Identifiant **borne** = username machine (`kiosk-lecayenne`), pas un email staff.

---

## 10. Mise à jour de ce cache

Quand le projet avance, mettre à jour **ce fichier** + `docs/PROJECT_CONTINUITY_AND_VISION.md` + éventuellement `reports/planning/latest.md`. Le README pointe vers le dossier `HANDOFF_NEW_CURSOR/`.

---

*Fin du cache mémoire — à associer au prompt `PROMPT_DEMARRAGE_NOUVEAU_COMPTE.md`.*
