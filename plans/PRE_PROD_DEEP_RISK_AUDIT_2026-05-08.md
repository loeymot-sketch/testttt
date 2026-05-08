# Pré-Prod — Audit Profond des Risques Cachés FoodKing V1
**Date :** 2026-05-08
**Auteur :** Claude orchestrateur (mode chasse-aux-pièges)
**Méthode :** lecture directe code + connaissance architecture + raisonnement adversaire (qu'est-ce qui PEUT casser)
**Objectif :** dévoiler tout risque direct, indirect, caché ou invisible avant merge prod

---

## §0 — État cumulatif consolidé (orchestrateur + agent)

### 0.1 Travail combiné = 17 commits audit + 9 commits orchestrateur parallèle = **26 commits clés livrés**

#### Branche agent `cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27` (8 commits récents)

| # | Commit | Finding | Sévérité | Description |
|---|---|---|---|---|
| 1 | `cdeb19de7` | F-015 | P0 | production queue config + health gate + outbox staleness monitor |
| 2 | `a45015ddc` | F-016a-BIS | P0 | branch-scoped manual rupture ItemExtra + ItemVariation |
| 3 | `13b0e0c6c` | F-016a-BIS | doc | persist durable REPORT |
| 4 | `b11fad032` | F-017-W41 | P0 gate | Suites 6+9 stock rupture sync + NF525 compliance E2E |
| 5 | `9acfeffae` | F-017-W42 | P0 gate | Suites 1+2+3+4 POS+Kiosk happy+edge E2E |
| 6 | `ab4b65973` | F-017-W43 | P0 gate | Suites 5+8+10 KDS sync + isolation + reconcile E2E + npm scripts |
| 7 | `a65f42e6c` | F-017-W44 | P0 gate | Suite 7 stress/load + artisan e2e:stress |
| 8 | `fb3535a87` | FINAL-v2 | report | 17/17 findings closed cumulative report |

#### Branche orchestrateur `claude/blissful-mclean-c915c2` (9 commits)

| # | Commit | Track | Description |
|---|---|---|---|
| 1 | `095698b41` | 4-admin | dashboard i18n + ARIA + polling backoff |
| 2 | `a09425073` | 1.1 | delivery Phase 1 schema + models + DTO |
| 3 | `4c230e8f3` | 2 | dead SmModalCreateComponent removed |
| 4 | `563256828` | 4-kiosk | TPE codes + countdown + video fallback + ARIA + WCAG |
| 5 | `0e0d9c99f` | 1.2 | delivery Phase 2 UberEats E2E |
| 6 | `c4da0c0bf` | 3 | sync robustness Echo + heartbeat + rate-limit + 409 |
| 7 | `f9ed45813` | 5 | security + memory leak + heartbeat tests (47 tests) |
| 8 | `a5e2d0c11` | 1.3 | delivery Phase 3 Deliveroo + Delicity + StatusPush |
| 9 | `35be07def` | 1.4 | delivery Phase 4 admin UI + routes + docs |

### 0.2 Verdict cumulatif

| Métrique | Valeur |
|---|---|
| Findings audit clos | 17/17 ✅ |
| Modules nouveaux livrés | Delivery Platforms (UE + Deliveroo + Delicity) ✅ |
| Frozen-zones violations | 0 |
| Tests cumulés | ~1900-2000 (832 + ~1100 agent + supplémentaires E2E F-017) |
| Régressions | 0 |
| Build production | ✅ |
| Sécurité review | ✅ propre |
| Heal-light Owner restant | ~2h (estimation agent) |

---

## §1 — Tableau RÉCAP : ce qui est fait vs ce qui reste

### 1.1 Livré V1 fast-food go-live ready

| Domaine | Livré | Source |
|---|---|---|
| **NF525 fiscal kiosk** | fiscal_sequence_no alloué pour PAID via 2 paths runtime + sentinel invariant | F-001 |
| **NF525 Z report** | Aggregation includes kiosk + cash variance + signature HMAC | F-001 + F-003 |
| **TPE amount echo** | 422 AMOUNT_ECHO_MISMATCH si écart > 1 cent | F-002 |
| **Cash drawer reconciliation** | Sessions open/close + variance + 5 invariants I1-I5 + sentinel | F-003 |
| **Cancel reason whitelist** | 12 codes enum + actor-aware Request + persist `order_status_transitions.reason` | F-004 |
| **Queue number monotonic** | D-M13 supersession lock 30s + 409 retry sans fallback Z* | F-005 |
| **POS idempotency parity** | Cache::lock POS aligné Kiosk + 23000 catch | F-006 |
| **Kiosk lock branch** | Hard-fail 401/403/422 sans context Auth+KioskMachine | F-007 |
| **Payment-confirm reconcile** | localStorage frontend + boot retry + endpoint backend reconcile-pending + table pending_payment_confirmations (gated migration) | F-008 |
| **Kiosk cash backend hook** | Endpoint cash-acknowledge + drawer event status persisted + Z unacknowledged count | F-009 |
| **BranchScope queue context audit** | 0 leak concret + sentinel canary | F-010 |
| **Pricing SSOT flag stable** | close-by-investigation 0 modif prod + sentinel | F-011 |
| **Finalize state guard** | Whitelist `[OrderStatus::PENDING]` only (vs >=ACCEPT fragile) | F-013 |
| **TPE QA toggle** | `?tpe_force=declined|timeout` dev+staging + prod-guard webpack DefinePlugin non-bypassable | F-014 |
| **Production queue config** | .env.example sécurisé + health gate + outbox staleness monitor command | F-015 |
| **Stock orchestration extras+variations** | branch-scoped manual rupture via `stock_levels` polymorphic existant (Option 1bis refined) | F-016a-BIS |
| **Massive E2E test suite** | 10 suites (POS happy/edge, Kiosk happy/edge, KDS sync, stock rupture sync, stress 200 orders, multi-branche isolation, NF525 compliance, reconciliation flows) | F-017 |
| **Delivery platform integration** | 3 plateformes (UE+Deliveroo+Delicity) webhook → ingestion → KDS broadcast → status push retour + admin UI + 89 tests | Orchestrator parallel |
| **Sync robustness** | Echo auth guard + heartbeat cache + broadcast rate-limit + 409 toast + Echo-not-ready warn | Orchestrator Track 3 |
| **Admin dashboard UX** | 5 composants i18n (32 clés) + ARIA progressbar + polling exp backoff + :key fix | Orchestrator Track 4-admin |
| **Kiosk error UX** | TPE codes mapping + countdown urgency + video fallback 3s + toast ARIA + WCAG AA contrast | Orchestrator Track 4-kiosk |
| **Security tests** | 47 nouveaux tests (XSS, SQLi, mass-assign, memory leak, audit chain, webhook security) | Orchestrator Track 5 |

### 1.2 Reste à faire / différé / conditionné

| Item | Statut | Effort | Dépendance |
|---|---|---|---|
| **F-016b UI Dashboard StockManager** | Différé V1.x | 4-5 jours-agent | Hand-off préparé F-016a-BIS §10 |
| **F-012 god classes refactor** | Différé V1.x | Multi-cycles | Pas P0 |
| **Receipt fiscal_sequence_no display** | Bloqué (orchestrator Track 2.1) | 1h après extension Resource | OrderDetailsResource doit exposer `fiscal_sequence_no` |
| **PosCategoryController order_column whitelist** | Bug découvert non-fixé | 1 LOC | Track 5 finding markTestIncomplete |
| **Receipt QR code** | Skipped npm pkg manquant | 50 min | Installer `qrcode` npm |
| **Heal-light Owner** | Pending | ~2h | Détaillé final report agent |
| **Merge prod strategy** | À orchestrer | 1-2j | Combiner branches agent + orchestrator |
| **Vendor reload post-merge** | Ops | 5 min | `composer dump-autoload` |
| **Backup DB pre-deploy** | Ops critique | 30 min | Snapshot RDS / mysqldump |
| **Hardware partnership branchement** | V1.x post-go-live | Variable | Ingenico / Verifone / Epson / Star |

---

## §2 — MEGA-TABLEAU : Risques cachés / indirects / invisibles par fonctionnalité

> **Méthodologie** : pour chaque fonctionnalité, je distingue 4 classes de risques :
> - **🟥 Direct** : visible à la lecture du code
> - **🟧 Indirect** : effet de bord d'une combinaison de composants
> - **🟨 Caché** : pas évident à première lecture, demande inspection deep
> - **🟦 Invisible** : race conditions, edge cases réseau, état de production
>
> Sévérité : 🔴 P0 critique | 🟠 P1 haut | 🟡 P2 medium | 🟢 P3 low

### 2.1 POS / Caisse — Prise de commande + paiement

| # | Risque | Classe | Description | Sév | Action recommandée |
|---|---|---|---|---|---|
| POS-1 | Receipt ne montre PAS fiscal_sequence_no | 🟥 Direct | F-001 alloue le champ DB mais `OrderDetailsResource` ne le sérialise pas → ticket physique non-NF525-affichage | 🟠 | Étendre `OrderDetailsResource` pour exposer `fiscal_sequence_no` + signature courte. Fix ~15 min mais possiblement zone agent |
| POS-2 | Cash session non fermée à fin de journée | 🟧 Indirect | Si caissier oublie close session, le Z report close ne capture pas la variance → cash physique non réconcilié, sortir de la fenêtre Z | 🟠 | Cron job `foodking:cash:close-stale-sessions` quotidien + alert ops si session ouverte > 24h |
| POS-3 | Walk-in customer fallback | 🟨 Caché | `PROJECT_CONTINUITY_AND_VISION.md` mentionne que si aucun customer "walking" en DB, le fallback est non-déterministe (premier customer trouvé) | 🟠 | Seeder obligatoire `WalkInCustomerSeeder` + assertion au boot du POS |
| POS-4 | Coupon limited-use race condition | 🟦 Invisible | 2 caissiers utilisent simultanément le même coupon `usage_limit=1` → CouponService doit `lockForUpdate` sur `coupons.id` avant validation. Non vérifié par audit. | 🟠 | Vérifier dans `CouponService::resolveCouponById` qu'il y a `lockForUpdate` ; ajouter test concurrent si manquant |
| POS-5 | TPE sur POS web (pas kiosk) — pas d'amount echo | 🟨 Caché | F-002 amount echo enforce sur kiosk uniquement. POS web cash écrit `pos_received_amount` (validé `>= total` côté request) mais POS card n'a pas de TPE bridge — saisie 4 derniers chiffres + note | 🟡 | Acceptable V1 si POS card = manuelle. Si POS branche un TPE physique futur, étendre amount echo |
| POS-6 | PosCategoryController order_column DoS | 🟥 Direct | Track 5 finding : `order_column` user-supplied, pas de whitelist → DoS via empty result set. SQL safe (Laravel backtick) mais comportement silent-empty | 🟠 | Fix 1 LOC : `in_array($column, ['id','name','slug','created_at'], true)` |
| POS-7 | POS idempotency Cache::lock 30s | 🟦 Invisible | Si transaction posOrderStore prend > 30s (ex: 50 items + SSOT lent), lock relâche → race possible. F-006 corrigé mais TTL fixé. | 🟡 | Monitorer durée moyenne `posOrderStore` en prod, ajuster TTL si nécessaire |
| POS-8 | Refund sur session cash fermée | 🟧 Indirect | F-003 cash session — si refund après close session, `CashMovement` créé sans session_id link → variance reporter sur quel jour ? | 🟠 | Spec : refund post-close → session "manuelle adjustments" du jour courant ou bloquer + open new session. À documenter |
| POS-9 | Multi-onglet caissier | 🟦 Invisible | Caissier ouvre POS dans 2 onglets. Sanctum token partagé. Si ouvre 2 commandes différentes simultanément → ok. Si ouvre LA MÊME commande → potentiel conflit edit. | 🟡 | F-006 idempotency couvre create. Pour edit/cancel, optimistic lock sur `Order.updated_at` non vérifié. À auditer |
| POS-10 | Discount permission gate bypass | 🟨 Caché | `PosOrderRequest` enforce permission gates (cashier ≤10%, manager ≤50%, owner illimité). Mais quid si user a la permission `pos-discount-unlimited` ET son Spatie role est "Cashier" (mauvaise config) ? Spatie `can()` retourne true → bypass | 🟢 | Audit RolePermissionTableSeeder : assurer que `pos-discount-unlimited` n'est jamais assigné à role Cashier |
| POS-11 | Wizard Vanilla JS frozen — tests indirects | 🟨 Caché | `pos-wizard.js` (5769 LOC) est frozen → ne peut être audité par tests modernes. Si bug logique caché dans le wizard, on le découvre seulement en prod | 🟠 | Documenter à l'owner que le wizard frozen est une dette tech ; planifier retest E2E exhaustif Playwright même si visuel frozen |
| POS-12 | Floor plan / dine-in désactivé | 🟢 Direct | V1 dine-in disabled via flag. Mais code path partiellement présent. Si flag flip accidentel, comportement non-validé | 🟢 | Test sentinel `pos.dine_in_enabled=false` enforce V1 |

### 2.2 Kiosk / Borne — Self-service order + payment

| # | Risque | Classe | Description | Sév | Action recommandée |
|---|---|---|---|---|---|
| KIO-1 | TPE stub vs hardware bridge détection | 🟦 Invisible | `kioskHardware.isKioskBridge()` détecte présence Electron. Si bridge présent mais drivers TPE pas chargés (init failure), `tpeCharge` peut renvoyer ok-stub-like alors que TPE réellement hors-ligne | 🔴 | Healthcheck obligatoire au boot kiosk : `kioskHardware.healthcheck()` doit passer avant d'autoriser ouverture session payment. Ajouter F-014 toggle pour forcer mode "no-bridge" en dev |
| KIO-2 | Cash kiosk drawer fail mais user paie quand même | 🟦 Invisible | Drawer signal=fail → log kiosk-event + 422 backend. Mais physiquement, si le tiroir est mal contacté, le détecteur peut renvoyer fail alors que cash est entré. Variance non détectée | 🟠 | Côté F-009 → cron de réconciliation à fin de journée comparant `cash_acknowledged_count` vs cash physique compté par staff |
| KIO-3 | Idle timeout race | 🟦 Invisible | Cart cleared après 3min inactivité. Si user clique "Payer" à 2min59s, la requête API peut être en flight au moment du timeout → cart cleared frontend mais order persisté backend | 🟡 | F-001 PENDING_COUNTER preserves order ; doc owner que "Si user dispatched commande à fin idle, order existe en BDD, à reprendre via search numero" |
| KIO-4 | LocalStorage reconcile-pending overflow | 🟥 Direct | F-008 limite 50 entries / 30 min. Si vague de fails (TPE up backend down 35min), 51e+ entry perdue silencieusement | 🟡 | Dashboard ops : metric `payment_reconcile_overflow_count` — alerte si > 0 |
| KIO-5 | Kiosk wizard frozen — drift d'état possible | 🟨 Caché | 8 composants kiosk wizard frozen. Si évolution backend change le shape du payload (ex: nouveau champ extra), wizard ne le sait pas → silent fallback | 🟠 | Tests Playwright sur le wizard kiosk (autorisés par owner) : assert que le wizard fonctionne avec menu actuel ET menu modifié post-merge |
| KIO-6 | Print fail cascade | 🟧 Indirect | `kioskHardware.printReceipt` fallback : real Electron → printEscPos → window.print. Si les 3 fail (printer offline + bridge fail + browser print KO), client sans ticket. NF525 article 286-I-3°bis exige ticket fiscal pour transactions | 🔴 | Endpoint backend `/api/frontend/order/{id}/receipt-pdf` pour générer PDF côté serveur ; envoyer email/SMS si impression KO ; logger `print_failure` pour audit |
| KIO-7 | RTL Arabic dir non toggled | 🟨 Caché | KioskIdle audit : selecteur arabe change `i18n.locale='ar'` MAIS pas `document.documentElement.dir='rtl'` → texte renversé layout | 🟢 | Track 4 kiosk a partiellement traité (commit `563256828`). À vérifier si dir toggle inclus |
| KIO-8 | Loyalty redeem race | 🟦 Invisible | F-008 loyalty redeem utilise `lockForUpdate` sur user. Mais si user soumet 2 commandes simultanément (multi-borne, même code), 2e attend lock du 1er → OK séquentiel | 🟢 | Déjà mitigé F-008 |
| KIO-9 | Inactivity overlay focus trap | 🟨 Caché | Audit kiosk : modale aria-live mais focus trap non testé clavier. Si user navigation clavier (accessibility), peut tab out → confusion | 🟢 | Track 4 kiosk : countdown-critical ajouté mais focus trap non vérifié. Améliorer V1.x |
| KIO-10 | TPE retour 200 mais corps "error" | 🟦 Invisible | Si firmware TPE buggué retourne HTTP 200 avec body `{status:"error"}`, adapter classe approved=true (basé sur status code). Cas extrême non couvert | 🟡 | Adapter doit vérifier semantic body même si HTTP 200. À ajouter défensivement Phase V1.x |
| KIO-11 | KioskMachine token expiration | 🟧 Indirect | Sanctum tokens kiosk = ability `kiosk:order`. Pas de TTL documenté → token never expires. Si token leaked, attaquant crée orders illimités sur la branche | 🟠 | Phase V1.x : ajouter `expiration` Sanctum 30 jours + endpoint `/kiosk/refresh-token` au boot |
| KIO-12 | Offline mode + sync queue | 🟨 Caché | Doc kiosk mentionne offline mode. Si kiosk offline depuis 2h et 30 orders en queue locale, sync au retour réseau → outbox 30 events bloquent worker queue | 🟡 | Vérifier rate limit interne sync + batch dispatching |

### 2.3 KDS — Kitchen Display System

| # | Risque | Classe | Description | Sév | Action recommandée |
|---|---|---|---|---|---|
| KDS-1 | Multi-onglet cuisinier conflict | 🟦 Invisible | Chef ouvre KDS sur 2 écrans. Marque order comme PREPARING sur écran A, écran B affiche encore ACCEPT (pas synced en <1s). Chef clique aussi PREPARING sur B → 409 optimistic lock conflict | 🟢 | F-007 optimistic lock catché. Track 3 toast 409 ajouté. UX OK |
| KDS-2 | KDS reception delivery platform orders | 🟧 Indirect | Order arrive via webhook (UE/Deliveroo/Delicity) avec status=ACCEPT direct (skip PENDING). KDS écoute `OrderCreated` → reçoit. Mais l'event payload contient `order_type=DELIVERY`, KDS UI doit afficher différemment (drive thru vs comptoir) | 🟡 | Vérifier que KitchenDisplaySystemComponent affiche un badge "Uber Eats / Deliveroo / Delicity" pour distinguer visuellement |
| KDS-3 | Sound notification dependency | 🟦 Invisible | KDS bip à new order via `kioskHardware.play()` ou `Audio` HTML5. Si volume 0 ou tab silencieux par browser, cuisinier rate orders → temps prep dépassé | 🟠 | Visual badge clignotant rouge persistant en plus du son ; force flash écran 1s par new order |
| KDS-4 | Order re-appear après restore | 🟨 Caché | Si admin force-restore un order CANCELED via DB direct, status devient ACCEPT. Le KDS ne reçoit pas d'event (force-restore ne dispatch pas OrderStatusChanged). KDS ne montre pas l'order resurrected | 🟢 | F-A7 (POS-9-h.3.5) bloque `Order::restore()` au domaine. Donc impossible. ✓ |
| KDS-5 | Pusher partial failure | 🟦 Invisible | Pusher accepts WS connection, accepts subscribe, mais drops messages silencieusement (ex: rate limit ou bug Soketi). Outbox pense dispatched_at OK. KDS reçoit rien | 🔴 | F-015 outbox staleness check si stale > 10. Mais ne détecte pas perdus côté reception. Ajouter heartbeat client → server "ping" toutes 60s pour valider reception |
| KDS-6 | Multi-instance Soketi sticky session | 🟦 Invisible | Si Soketi déployé multi-instance derrière LB sans sticky session, le client peut subscribe sur instance A puis reconnect sur B après deconnexion → channel non re-subscribed silencieusement | 🟠 | Configurer LB sticky session OU utiliser Pusher hosted (qui gère ça nativement) |
| KDS-7 | Race entre Echo et polling fallback | 🟧 Indirect | Si polling fetch en cours pendant qu'Echo event arrive, le merge state local → potentiel double-display ou état stale | 🟢 | F-017 Suite 5 KDS sync teste. Dedupe par correlationId frontend (si présent) |
| KDS-8 | Volume cuisine assigné mais pas chef | 🟨 Caché | KDS auth = role Chef. Mais si admin mass-assigne `assign_to_branch` sans assigner role Chef à un user, user voit KDS vide → confusion ops | 🟢 | Seeder strict ; check au login KDS que user a role Chef |

### 2.4 Stock orchestration — Items + Extras + Variations

| # | Risque | Classe | Description | Sév | Action recommandée |
|---|---|---|---|---|---|
| STK-1 | F-016b UI Dashboard manquant V1 | 🟥 Direct | Owner ne peut PAS toggler manuellement extras/variations rupture per-branche via UI. Doit utiliser tinker / SQL direct. **GAP fonctionnel V1 critique** | 🔴 | Prioriser F-016b (4-5 jours) AVANT go-live OU exposer endpoint `POST /api/admin/stock/toggle` minimaliste utilisable via Postman/curl par owner |
| STK-2 | Sémantique double : status global + stock_levels per-branche | 🟧 Indirect | Item peut être : status=ACTIVE + stock_levels.is_available=false (rupture branche) → filtré. Mais aussi status=INACTIVE + stock_levels=true (admin global off mais stock OK) → filtré aussi. Logique cumulative correcte mais ambiguïté ops | 🟡 | Doc claire : "INACTIVE = retiré menu pour toutes branches ; rupture = temporaire branche A". UI futur F-016b doit montrer les 2 axes |
| STK-3 | Auto-86 sur extras non implémenté ? | 🟨 Caché | F-016 original prévoyait `decrement_for_order` sur extras avec `max_daily_qty`. F-016a-BIS refined utilise stock_levels existant — ce service a-t-il decrement extras ? À vérifier | 🟠 | Lire `DeliveryOrderIngestionService` ou autre listener auto-86 ; tester scénario rush 100 sauce X consommées → 101e order rejet |
| STK-4 | Order kiosk inflight + admin marque rupture | 🟦 Invisible | User kiosk a "Sauce BBQ" dans cart. Admin marque rupture. Kiosk submit → backend détecte rupture et reject 422 → user voit erreur "Sauce BBQ indisponible". UX abrupte. | 🟡 | F-017 Suite 6 le teste. UX better : afficher avant submit "votre cart contient items qui viennent d'être en rupture, retirez-les pour continuer" |
| STK-5 | Cache Vue store stale | 🟧 Indirect | Kiosk frontend cache menu. Si admin toggle rupture, broadcast event → kiosk reçoit → invalide cache. Mais si kiosk est offline (réseau coupé) au moment du broadcast, cache reste stale jusqu'au prochain fetch online | 🟢 | TTL cache court (60s) + force fetch au démarrage chaque session kiosk |
| STK-6 | Variation rupture + order existant | 🟦 Invisible | Order créé hier avec variation "XL" sur item Y. Aujourd'hui XL en rupture. Order toujours ACCEPT en DB. Cuisinier KDS voit "XL" dans order → rapporte à staff "on n'a plus" | 🟢 | Comportement OK : un order historique conserve sa composition snapshot. ItemAllergenSnapshot pattern. |
| STK-7 | Inventory réel vs stock_levels | 🟨 Caché | `stock_levels.on_hand` représente le stock comptable. Mais physiquement (frigos cuisine), le stock réel diverge. Pas d'audit physique automatisé | 🟢 | Documentation V1 : stock = manuel admin only ; audit physique fin de journée avec reconciliation. V2+ : intégration physique (RFID, balance, etc.) |
| STK-8 | Branch isolation extras rupture | 🟥 Direct | F-016a-BIS branch-scoped via stock_levels polymorphic. Mais si query oubliée filter `branch_id`, fuite cross-branch possible | 🟢 | F-017 Suite 6 multi-branche tests assert isolation |
| STK-9 | Item placeholder delivery (Phase 2 adaptation) | 🟨 Caché | Phase 2 delivery : `order_items.item_id NOT NULL` → placeholder Item per platform (status=INACTIVE). Si admin toggle ces items en ACTIVE par erreur, ils apparaissent dans menu kiosk. | 🟡 | Naming convention strict + sentinel guarder qu'aucune query menu ne return les placeholders |

### 2.5 Synchronisation — Outbox + Pusher + Polling + FCM

| # | Risque | Classe | Description | Sév | Action recommandée |
|---|---|---|---|---|---|
| SYN-1 | Queue worker silencieusement absent | 🟥 Direct | F-015 corrige le piège `.env.example`. Mais si admin déploie sans suivre doc, queue=database sans worker → outbox stale → KDS aveugle | 🔴 | F-015 health check `/api/health/ready` 503 si stale > 10. Monitoring doit alerter sur 503 ; cron ops vérifier worker process |
| SYN-2 | ws:heartbeat write/read split | 🟧 Indirect | Track 3 set la clé dans DispatchDomainEventsJob. F-015 read dans HealthController. Si jobs ne s'exécutent pas (worker down), heartbeat reste stale → 503 — OK. Mais si job tourne mais broadcast Pusher silently fail, dispatched_at marked OK et heartbeat OK alors que clients n'ont rien reçu | 🔴 | Ajouter ack côté frontend : Echo client envoie heartbeat client→server après chaque event reçu. Server compare ratio events_dispatched / events_acked → si <90% sur 5min, alerte |
| SYN-3 | FCM jamais activé prod | 🟨 Caché | FCM doc présente mais clés Firebase non vérifiées en prod. Mobile customer "ready" notif silently fail | 🟠 | Pré-deploy : valider Firebase project + Server Key configurée + smoke test push réel |
| SYN-4 | Multi-instance Soketi sans sticky session | 🟦 Invisible | Cf KDS-6. Risque transverse | 🟠 | Sticky session LB OR Pusher hosted |
| SYN-5 | Cache Redis flush perte nonces | 🟦 Invisible | Webhook delivery idempotency layer 1 = Cache::add nonce 600s. Si Redis restart, nonces perdus → replay possible jusqu'à TTL renouvelé | 🟢 | Layer 2 (UNIQUE constraint DB) couvre. Window vulnerability < 5 min acceptable |
| SYN-6 | Timezone cluster multi-instance | 🟦 Invisible | Si serveurs cluster ont TZ différentes, `now()` dans heartbeat varie → comparison stale faux positif/négatif | 🟢 | F-A4 (POS-9-h.2.7) TZ-stable sign confirmé. ✓ |
| SYN-7 | Outbox table grossit indéfiniment | 🟨 Caché | `domain_events` ne semble pas avoir de cleanup automatique. Tous les events y restent → DB bloat | 🟡 | Cron job cleanup older than 7 days where dispatched_at is not null |
| SYN-8 | Echo handler memory leak unmount | 🟦 Invisible | Track 5 a écrit `echoMemoryLeak.spec.js` 12 tests. Vérifier que tous les composants Vue qui font `onEvents()` ont un beforeUnmount cleanup | 🟢 | F-017 + Track 5 covered. Audit cumulative : count Vue components avec onEvents = count avec beforeUnmount unsubscribe |
| SYN-9 | Pusher rate limit | 🟧 Indirect | Pusher hosted plan a rate limits (events/sec). Si rush midi 100 commandes/min × ~5 events/order = 500 events/min, peut atteindre limit selon plan | 🟡 | Calcul capacité requise avant deploy ; choisir plan adapté |
| SYN-10 | Webhook delivery retry avalanche | 🟦 Invisible | Si plateforme spam-renvoie webhooks (bug), idempotency catch DB-level mais charge worker queue. Backlog peut grossir | 🟡 | Rate limiter `delivery-webhooks` config (1000/min) + monitor queue depth |

### 2.6 Data transfers — Webhook delivery + Payment-confirm + Audit chain

| # | Risque | Classe | Description | Sév | Action recommandée |
|---|---|---|---|---|---|
| DAT-1 | Webhook signature bypass via timing attack | 🟥 Direct | Adapters utilisent `hash_equals` (constant time). ✓ | 🟢 | OK |
| DAT-2 | Webhook body size unlimited | 🟦 Invisible | Pas de cap sur `$request->getContent()`. Plateforme buggy peut envoyer body 100MB → memory crash | 🟡 | Track 5 markTestSkipped pour body size. Configurer Nginx `client_max_body_size 1m` + Laravel `post_max_size=1M` |
| DAT-3 | Replay nonce missing platform | 🟦 Invisible | Deliveroo utilise sequence_guid ; UE/Delicity utilisent timestamp. Si plateforme n'envoie ni l'un ni l'autre, replay défense layer 1 cassé | 🟢 | Layers 2-4 couvrent. UNIQUE(platform, external_id) au minimum |
| DAT-4 | Payment-confirm amount echo bypass | 🟧 Indirect | F-002 enforce 1 cent tolerance. Mais si test rapide passe via TPE compromis qui retourne TOUJOURS amount = order.total exactly (replay attack), backend accept. La signature TPE elle-même n'est pas validée par backend | 🟠 | Phase V1.x : valider signature transaction TPE bancaire (PCI-DSS), pas juste echo. |
| DAT-5 | Status push delivery 200 OK avec body error | 🟦 Invisible | Cf KIO-10. Adapter Phase 3 doit vérifier semantic body | 🟡 | Adapter `pushStatus` retourne `PushResult` — vérifier que parse body cherche `error` field aussi |
| DAT-6 | Audit chain HMAC secret rotation | 🟨 Caché | Si APP_KEY rotates, anciens audit_logs HMAC deviennent invérifiables. F-A9 (POS-9-h.3.9) runbook rotation existe | 🟢 | F-A9 documente rotation. ✓ |
| DAT-7 | Encrypted credentials APP_KEY rotation | 🟦 Invisible | `delivery_platforms.credentials` encrypted via APP_KEY. Si rotation sans re-encrypt, credentials existantes deviennent indéchiffrables → webhooks échouent silencieusement | 🟠 | Procédure rotation : `php artisan app:reencrypt-delivery-credentials` cmd à créer ; documenter |
| DAT-8 | Audit chain race writers branche | 🟦 Invisible | F-C3 (POS-9-h.2.2) audit chain race fixed avec UNIQUE(branch_id, prev_hash) constraint | 🟢 | ✓ Couvert |
| DAT-9 | NF525 signature Z avec partial close | 🟦 Invisible | Si Z close pendant rush 50 commandes en cours, half-open interval F-B3 garantit que les commandes mid-flight tombent soit dans Z courant soit dans suivant. ✓ | 🟢 | F-017 Suite 9 teste |
| DAT-10 | Delivery platform credentials leak via logs | 🟨 Caché | Si `Log::info` quelque part dump le `DeliveryPlatform` model raw, credentials encrypted leakent dans logs | 🟢 | Eloquent `$hidden` sur credentials field ; vérifier `__toString` ne dump pas. Track 1 review |
| DAT-11 | Order address PII dans webhook log | 🟨 Caché | `delivery_webhook_events.body` JSON contient phone + email customer. RGPD risk si logs leaked | 🟠 | PII redaction wrapper avant persist : phone → `***1234`, email → `j***@d.com`. Track 5 markTestSkipped pour PII redaction. À implémenter |
| DAT-12 | Push status sortant credentials in URL | 🟦 Invisible | Si pushStatus URL contient query param avec API key (pas Bearer), URL loggé dans nginx access logs → credentials dans logs filesystem | 🟢 | Adapters utilisent `Authorization: Bearer` headers (HTTP best practice) |

### 2.7 Multi-tenant / Branch isolation (V1 mono-tenant multi-branch)

| # | Risque | Classe | Description | Sév | Action recommandée |
|---|---|---|---|---|---|
| TEN-1 | Admin (branch_id=0) cross-branche par accident | 🟦 Invisible | Admin head-office voit toutes branches. S'il crée un order via UI sans selecteur branche explicite → quelle branche ? | 🟠 | Forcer branche selector obligatoire pour admin sur formulaire create order ; assert NOT NULL côté request |
| TEN-2 | BranchScope queue worker | 🟨 Caché | F-010 audit-only sentinel : `BranchScope::apply` retourne tôt si `Auth::check() = false` (queue worker). Si jobs/listeners ne filtrent pas explicit branch_id, fuite cross-branche | 🟢 | F-010 sentinel guarde |
| TEN-3 | Fiscal séquence cross-branche | 🟦 Invisible | `FiscalSequenceService::next($branchId)` lock per-branch. Si bug appelle avec mauvais branchId, séquence incohérente. Test ? | 🟢 | F-001 invariant sentinel verifies |
| TEN-4 | Shared `orders` table 2 modèles | 🟧 Indirect | `Order` (POS) et `FrontendOrder` (Kiosk/Web/Delivery) partagent `orders` table. Discrimination via `order_type`. Si discriminator bug → cross-pollination | 🟢 | Tests existants couvrent |
| TEN-5 | Z report cross-branche aggregation | 🟥 Direct | `ZReportService::aggregate` filter par branch_id explicit. ✓ | 🟢 | F-017 Suite 8 isolation tests |

### 2.8 Sécurité globale

| # | Risque | Classe | Description | Sév | Action recommandée |
|---|---|---|---|---|---|
| SEC-1 | Sanctum token expiration | 🟨 Caché | Tokens kiosk_machine pas d'expiration documentée. Si token leaked, attaquant order illimité | 🟠 | Phase V1.x : TTL Sanctum 30 jours + refresh endpoint |
| SEC-2 | XSS via order note instructions | 🟦 Invisible | OrderItem.instruction et notes sont user-controlled. Si KDS UI render via `v-html`, XSS chez cuisinier (compromise admin session) | 🟠 | Track 5 SecurityInvariantsTest XSS test ; vérifier que KitchenDisplay et POS cart utilisent text interpolation, pas v-html |
| SEC-3 | Mass-assignment Order model | 🟥 Direct | Track 5 SecurityInvariantsTest assert `$fillable` strict. ✓ | 🟢 | Couvert |
| SEC-4 | CSRF SPA cookie | 🟧 Indirect | Sanctum SPA cookie + CSRF token. Si SPA service worker cache stale, CSRF token expire → 419 | 🟢 | Sanctum gère ; en V1 acceptable |
| SEC-5 | Brute force kiosk login | 🟨 Caché | `KioskMachineLoginController` rate limit ? Si non, brute-force possible | 🟠 | Vérifier middleware throttle ; Track 3 rate-limit broadcasting/auth ajouté mais kiosk-login séparé |
| SEC-6 | Loyalty code énumération | 🟦 Invisible | Endpoint `/loyalty/check?code=X` retourne 200 si code valide, 404 sinon. Attaquant peut énumérer | 🟡 | Ajouter rate limit + retour générique 200 toujours avec données neutres si code invalide (security through obscurity light) |
| SEC-7 | Coupon code énumération | 🟦 Invisible | Idem loyalty. `/coupon/checking` peut leaker existence | 🟡 | Idem rate limit |
| SEC-8 | Webhook delivery DDoS | 🟧 Indirect | Track 1 rate limiter delivery-webhooks 1000/min. Mais 1000/min × 3 plateformes = 3000/min globally | 🟢 | OK pour V1 ; monitorer en prod et adjust |
| SEC-9 | API key middleware bypass | 🟥 Direct | `ApiKeyMiddleware` enforce config('app.api_key'). Si clé leaked, accès frontend API libre | 🟢 | Security review propre. Rotation procedure documentée |
| SEC-10 | NF525 fiscal secret rotation | 🟨 Caché | F-A9 runbook documenté. Mais si rotation pendant transaction Z, partial signature inconsistency | 🟢 | F-A9 runbook précise window à respecter |

### 2.9 Backup + DR + Ops

| # | Risque | Classe | Description | Sév | Action recommandée |
|---|---|---|---|---|---|
| OPS-1 | Backup DB automatique non vérifié | 🟥 Direct | Plan F-015 mentionne backup mais déploiement réel non testé | 🔴 | AVANT go-live : exécuter `mysqldump --single-transaction` + tester restore sur env staging |
| OPS-2 | Plan rollback merge prod | 🟥 Direct | Si merge cause régression NF525 → impossible de rollback "fiscal-clean" si Z a déjà été émis sur version buggée | 🔴 | Pré-merge : backup full + dry-run sur staging avec migration ; documenter procédure rollback step-by-step |
| OPS-3 | Monitoring outbox stale | 🟧 Indirect | F-015 monitor command schedule cron 1 min. Si cron pas configuré au deploy, pas d'alerte | 🟠 | Documenter `crontab` requirements ; add to deploy checklist |
| OPS-4 | Logs retention | 🟨 Caché | Logs Laravel par défaut `storage/logs/laravel.log` accumule. Si pas rotation, disk full | 🟢 | Configurer `daily` channel logging + cron logrotate |
| OPS-5 | Migration order au deploy | 🟧 Indirect | Migrations gated owner (cash_drawer_sessions, pending_payment_confirmations, delivery tables). Si pas exécutées dans le bon ordre, FK fail | 🟠 | Ordre canonique documenté ; post-deploy sanity check `php artisan migrate:status` |
| OPS-6 | Vendor reload post-merge | 🟧 Indirect | Sub-agents ont symlinké vendor du parent → autoload contaminé | 🟢 | `composer dump-autoload` post-merge |
| OPS-7 | Soketi / Pusher infra | 🟥 Direct | Doc REALTIME_SETUP corrigée par F-015. Mais Soketi service réellement up + supervised ? | 🔴 | Pre-deploy : start Soketi via systemd + monit ; healthcheck 6001 |

---

## §3 — TOP 12 actions PRIORITAIRES avant go-live

> Les 12 que je marque 🔴 ou 🟠 + impact business

| Priorité | Action | Effort | Owner |
|---|---|---|---|
| **P0-1** | F-016b UI dashboard StockManager (sinon owner ne peut pas toggler rupture extras/variations) — ou endpoint minimal CLI/Postman | 4-5j ou 2h | Agent / Dev |
| **P0-2** | Backup DB automatique testé + procédure rollback documentée | 1j | Ops |
| **P0-3** | Soketi service supervised + healthcheck reachable | 4h | Ops |
| **P0-4** | Receipt printer fallback PDF endpoint backend (NF525 légal si imprimante KO) | 1j | Dev |
| **P0-5** | TPE healthcheck mandatoire au boot kiosk (KIO-1) | 4h | Dev |
| **P0-6** | Outbox stale cron + alerting configuré | 2h | Ops |
| **P0-7** | Pusher partial failure detection (heartbeat client→server ack ratio) | 1j | Dev |
| **P1-8** | Cron `cash:close-stale-sessions` quotidien | 4h | Dev |
| **P1-9** | Walk-in customer seeder + boot assertion | 2h | Dev |
| **P1-10** | PosCategoryController order_column whitelist (Track 5 finding) | 30 min | Dev |
| **P1-11** | Sanctum tokens expiration (kiosk + admin) + refresh endpoint | 1j | Dev |
| **P1-12** | Receipt fiscal_sequence_no display (étendre OrderDetailsResource) | 2h | Dev |

**Total P0+P1 : ~12-14 jours-agent + 1 sprint ops avant deploy serein.**

---

## §4 — Ce qui reste réellement bloquant V1 vs V1.x

### 4.1 BLOQUANT V1 (avant 1er service fast-food owner)

1. F-016b ou endpoint stock CLI minimal (P0-1)
2. Backup + rollback procédure (P0-2)
3. Soketi supervised (P0-3)
4. Receipt PDF fallback (P0-4)
5. TPE healthcheck boot (P0-5)
6. Outbox cron + alerting (P0-6)

### 4.2 NON-BLOQUANT mais critique semaine 1 prod

7. Pusher heartbeat ack (P0-7)
8. Cash session close cron (P1-8)
9. Walk-in seeder (P1-9)
10. PosCategoryController whitelist (P1-10)
11. Sanctum TTL (P1-11)
12. Receipt fiscal display (P1-12)

### 4.3 V1.x (post-1er service)

- F-016b UI dashboard StockManager complet
- F-012 god classes refactor
- Mobile app customer (FCM activation)
- Hardware partnership real-world testing
- Multi-instance Soketi sticky session (si scale)
- PII redaction webhook events
- TPE signature transaction validation (vs amount echo seul)

### 4.4 V2 SaaS (long-terme)

Reporté hors scope V1 (cf. `feedback_v1_focus_no_saas_2026-05-08`).

---

## §5 — Conclusion orchestrateur

**Verdict global** : 17/17 findings audit closed + module delivery complet + 47 tests sécurité + UX polished + sync hardened. **Mais** prouver fonctionnellement en production avec les 12 actions P0/P1 ci-dessus avant de servir le 1er client.

**Les risques cachés/invisibles ne disparaîtront pas par les tests E2E F-017 seuls** — beaucoup nécessitent monitoring prod actif (Pusher partial failures, drawer fail vs cash réel, Sanctum token leak detection). Le go-live demande **ops mature**, pas juste code clean.

**Prochain cycle court (1 sprint)** : exécuter les 6 P0 + healing-light owner ~2h documenté par agent v2 final report. Après quoi le go-live fast-food owner est sûr.

**Le module delivery platforms est techniquement prêt mais non-actif** sans configuration partenaires (Uber/Deliveroo/Delicity store_id + credentials). Cette activation peut être différée post-go-live owner sans risque.

— *Le code propre n'est pas la garantie de production. Les tests verts ne sont pas la prod. Les frozen-zones intactes ne sont pas l'infrastructure. Les 12 P0/P1 ferment le delta.*
