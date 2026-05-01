# AUDIT & PLAN MAÎTRE — CENTRALISATION DES DONNÉES & SYNCHRONISATION (POS · KIOSK · PANIERS · KDS · LIVRAISON · ATTENTES)

**Auteur** : orchestration FoodKing (document produit côté repo après indisponibilité du terminal Claude : quota Anthropic). À refaire / enrichir par `bash scripts/foodking-claude-orchestrate.sh audit` quand le quota est revenu, en réutilisant le prompt `reports/audit/_CLAUDE_PROMPT_DATA_CENTRAL_SYNC_2026-04-26.txt`.  
**Complémentarité** : ne remplace pas les parcours écran-à-écran (`CLAUDE_POS_ORDER_FLOW_MASTER_PLAN_2026-04-26.md`, `CLAUDE_KIOSK_ORDER_FLOW_MASTER_PLAN_2026-04-26.md`) ni la feuille de route macro (`CLAUDE_CAISS_V1_FULLSTACK_ORCHESTRATION_PLAN_2026-04-26.md`). Ici : **data plane** — qui écrit, où ça vit, comment ça se propage, quelles garanties après commit.

**SSOT lues** : `docs/ORDER_FLOW.md`, `docs/DEVICE_FLOW.md`, `docs/OUTBOX_PATTERN.md`, `docs/EVENT_CONTRACT.md`, `docs/orchestration/OS_FOS_SYMMETRY_MATRIX_2026-04-25.md`, `docs/HANDOFF_NEW_CURSOR/03_SYNCHRONISATION_TEMPS_REEL.md`.

---

## 0. Métadonnées

| Champ | Valeur |
|--------|--------|
| Version | 1.0 — 2026-04-26 |
| Cible exécutant | Codex CLI (missions `missions/CV1-*` / lots `D-XX` ci-dessous) |
| Invariants P0 | Pricing SSOT backend ; `OrderStatus` via machine à états ; `branch_id` = isolation ; dispatch domaine après commit (outbox) |
| Non-objectif | Re-décrire chaque bouton POS/kiosk (voir plans maîtres par surface) |

---

## 1. Carte d’autorité des données (SSOT)

| Donnée | Autorité unique | Les surfaces affichent | Interdit |
|--------|-----------------|-------------------------|----------|
| Prix ligne / total / taxes / remises | `PricingService` / requêtes de pricing (backend) | Même provenance API | Total « final payé » calculé uniquement côté client |
| Statut de commande (`OrderStatus`) | `OrderStateMachine` + persistance en DB | Libellés dérivés du même entier/enum côté front | Littéraux / bypass machine à états sur écriture |
| Lignes de commande, snapshots | Table `order_items` (+ tables liées) | Détails renvoyés par API | Panier local ≠ commande payée (voir §3) |
| Branche | `branch_id` sur l’enregistrement (et résolution côté serveur pour kiosque) | Filtre liste / canaux WS | Faire confiance à un `branch_id` client en écriture |
| Preuve paiement / TPE / ledger (pilote) | `PaymentService` + invariants M-04B (selon gate) | Statuts, références | Double application du même paiement (idempotence) |
| Temps réel (notification visuelle) | `domain_events` + broadcast post-commit (`OUTBOX_PATTERN.md`) | Echo / polling fallback | Croitre qu’un event WS a commité en DB *avant* l’acquittement requête |

**Principe** : toute **mutation** d’ordre (création, changement de statut, annulation) passe par **services** Laravel (OS ou FOS) ou chemins explicites documentés dans `OS_FOS_SYMMETRY_MATRIX` — jamais par un simple `UPDATE` ad hoc depuis le front.

---

## 2. Modèle de stockage (aperçu agrégat)

*Les noms exacts de tables se vérifient dans `docs/DATABASE_SCHEMA_CORE.md` / migrations ; ici, la logique fonctionnelle.*

- **Agrégat commande** : `orders` (souvent partagé logiquement par `Order` + `FrontendOrder` selon modèle, cf. matrice de symétrie) ; lignes `order_items` ; transitions `order_status_transitions` (ORDER_FLOW §observabilité).
- **Paiement** : enregistrements transaction / statut (chemins `changePaymentStatus` côté OS, pas miroir FOS — asymétrie documentée).
- **Livraison** : champs d’adresse / livreur / statut propre delivery (à cartographier par grep `delivery` + routes admin) ; convergence : même cycle de statuts commande si le produit l’impose, sinon lien explicite.
- **File d’attente** : `queue_number` (payload event `order.created` dans `EVENT_CONTRACT.md`), affichage OSS, écran d’attente kiosque.
- **Domain events** : `domain_events` (outbox) stocke type, canaux, payload pour WS — source de la vérité « ce qui a été notifié ».

---

## 3. Caddies : POS vs Kiosk vs (web) — local vs serveur

| Surface | État local (JS) | Serveur (vérité après commit) | Risque de dérive |
|---------|-----------------|------------------------------|------------------|
| POS | `posCart` (ou équivalent) : lignes, notes, aperçu | Après `posOrderStore` : lignes figées, total issu de pricing | Opérateur rafraîchit : doit refléter les commandes *branche* (liste admin) — pas mélanger avec panier brouillon |
| Kiosk | `kioskCart` : file offline éventuelle | `POST /api/frontend/order` : création PENDING, puis TPE/confirm | Offline : file locale **read-only** menu, paiement **désactivé** si gate (pas de se comporter comme payé) |
| Table / web (si actif) | panier `frontendCart` (nom à confirmer) | `OrderService::myOrderStore` (OS) vs FOS (kiosk) | Double panier = deux intentions ; seul le **commit** crée l’ordre SOT |

**Règle produit** : le panier **n’est pas** l’ordre. L’**ordre** n’est centralisé qu’**après** la transaction DB réussie. Les UIs peuvent montrer un *preview* issu d’un endpoint `quote` (M-05) — le token/signature ancre le **montant** autorisé au paiement.

---

## 4. Matrice de synchronisation technique (data plane)

Lecture : **Producteur** → **Consommateur** | **Mécanisme** | **Après commit ?** | **Où prouver**

| Producteur | Consommateur | Mécanisme | Garantie | Piste de preuve |
|------------|--------------|------------|----------|-----------------|
| `OrderService` / `FrontendOrderService` (création / changement) | Listes admin POS, KDS, OSS, apps branch | D’abord **commit DB** ; events → `domain_events` → `DispatchDomainEventsJob` | Oui (OUTBOX) | `OUTBOX_PATTERN.md` + test `AfterCommitDispatch*` (réf. matrice FK) |
| Broadcast Pusher / Echo | UIs (POS, kiosque, KDS) | `private-branch.{branch_id}` | Reçu après dispatch job | Vérifier `routes/channels.php` — pas de fuite inter-branches |
| FCM / push | Mobile / écrans (parallèle WS) | Jobs déclenchés sur lifecycle | Non bloquant intégrité | Si queue `sync` : effet de latence dans requête |
| Polling ~30s | KDS/OSS (fallback) | `GET` API | Cohérence lazy | 03_SYNCHRONISATION_TEMPS_REEL.md |
| `ItemAvailabilityChanged` (menu) | Toutes branches actives (broadcast) | event + cache invalidation côté client | Selon doc événement | Fichier `app/Events/ItemAvailabilityChanged.php` |

**Annulation** : mutation statut CANCELED/REJECTED avec **reason** (ORDER_FLOW) → outbox `OrderStatusChanged` → WS + audit ligne `order_status_transitions` ; les écrans retirent le ticket.

---

## 5. Canaux d’entrée de commande & convergence

1. **POS** : création encaissement, table, reprise, park.
2. **Kiosk** : `FrontendOrder` path + machine Sanctum.
3. **Web / app client** (si actif) : mêmes invariants, chemins `OrderService::myOrderStore` (côté OS) selon contexte.
4. **Livraison** : prise d’adresse, frais, assignation livreur — toutes les **écritures** restent dans les services (pas d’orchestrateur « JSON libre »).

Tous se rejoignent sur **une** vision statut (enum) + **isolation** `branch_id`. Les vues `delivery` vs `dine-in` sont des *variantes* du même cœur.

---

## 6. PENDING, ACCEPT, file, « en attente » (y compris écran kiosque)

| État | Où c’est vrai | QUI voit quoi | Piège |
|------|----------------|--------------|--------|
| PENDING | DB | Caisse : file nouvelles commandes ; kiosque : attente TPE / paiement | Cleanup jobs vs `payment-confirm` (race) — couvert M-06 / tests |
| ACCEPT | Après encaissement / validation / finalize kiosk | KDS, OSS | Ne pas lister en cuisine avant paiement si règle produit = payé d’abord (cf. M-07) |
| PREPARING / PREPARED | KDS | OSS client | Ordre de bump + `expected_status` |

**« Tout en attente » côté UI** = toujours une **projection** (liste filtrée API + évent. WS) — jamais une seconde base « shadow » de vérité.

---

## 7. Livraison (delivery)

*À câbler en mission dédiée si le repo a des écarts doc/code.*

- Champs : adresse, créneau, **frais** (SSOT via pricing).
- Synchronisation : mêmes événements de statut + évent. notifications livreur (hors cœur caisse, mais ne doit pas violer `branch_id`).
- Tests proposés : `DeliveryOrderCreateTest`, `DeliveryFeeMatchesPricingTest` (noms indicatifs).

---

## 8. Annulation · void · refund (propagation data)

1. **Annulation** avant livraison : transition légale + `reason` ; ledger selon M-04x ; KDS retire ticket.
2. **Post-Z / fiscal** : toute règle d’inscription légale reste côté services fiscal/ledger (gates).
3. **Cohérence WS** : le client ne retire pas seul la tuile : either refresh API ou event `order.status_changed`.

Tableau d’**effets** attendus (à compléter en mission) : colonnes = *Order row*, *KDS list*, *OSS*, *Loyalty points*, *Ledger*.

---

## 9. Pannes & dégradation

- **`BROADCAST_DRIVER` unset / `null`** : pas de WS — listes se mettent à jour via **REST** (polling) ; documenter l’opérateur.
- **Queue** : `QUEUE_CONNECTION=sync` → latence dans la requête ; ne pas conclure qu’un event est “bon” parce que l’utilisateur a vu un toast.
- **Borne offline** (gate) : menu en lecture, pas d’e-com sécurisé TPE/Stripe sans réseau.
- **Multi-onglet POS** : resync on focus (FK-076) — un seul SOT, pas deux vérités.

---

## 10. Scénarios « surprise » (tests de non-régression)

| # | Scénario | Attendu |
|---|----------|---------|
| 1 | Double `payment-confirm` | Idempotent, pas double PAID |
| 2 | Cleanup stale avant confirm | Rejet propre, logs traçables |
| 3 | Fuite `branch_id` (liste) | 0 — tests sentinel |
| 4 | Onglet A encaisse, onglet B rafraîchit | Pas d’orchestration locale contradictoire sur la même commande *
| 5 | WS lente, POST réussit d’abord | UI re-fetch, pas d’ordre en double |

\* Si product demande verrou, mission « pessimistic lock » sur `orders` côté commit.

---

## 11. Lots techniques pour Codex (D-01 — suggestions)

*Les lots **complètent** les P- (POS) et K- (kiosk) ; éviter de dupliquer les tâches déjà listées ailleurs.*

| Lot | Titre | Difficulté | Cible (indicative) | mandatory_tests (indicatifs) |
|-----|--------|------------|---------------------|------------------------------|
| D-01 | Invariant « aucune écriture totaux depuis JSON client » (grep + sentinel) | EASY | OS/FOS, contrôleurs order | `PosSubtotalForgery*`, FOS équivalent |
| D-02 | Carte exhaustive `OrderCreated` / `OrderStatusChanged` → canaux (doc + 1 test de non-régression outbox) | MEDIUM | `EventServiceProvider`, listeners | `AfterCommitDispatchTest` |
| D-03 | Matrice `branch_id` sur toutes listes (POS, admin order, kds) — 1 test par filtre | HARD | `OrderService` queries, `KDS` | Sentinels M-09 / NEW-04 G2 |
| D-04 | **Delivery** : un flux E2E API (création → statut) aligné doc | HARD | services delivery | `DeliveryOrderContractTest` |
| D-05 | Annulation CANCELED : audit trail + un check ledger | MEDIUM | `changeStatus` + payment | `CancelAuditTrailTest` |
| D-06 | Polling fallback documenté (config + UI hint quand `broadcast` off) | EASY | doc + 1 e2e smoke | n/a / playwright optional |
| D-07 | Cartographie **symétrie** FOS : absence `changePaymentStatus` (document contract test) | EASY | `tests/Feature/Contract/OrderServiceContract` | M-10 style |

*Gate humain requis* avant toute grosse migration ou frozen : `GATE_LOG`, `human-gates.mdc`.

---

## 12. Exigences d’audit en cascade (même principe que plans POS/Kiosk)

Pour chaque lot D-XX : allowlist stricte → exécution → `mandatory_tests` → `GPT_SELF_AUDIT` → `codex:final-audit` → piste `AUDIT` Claude ciblé (ou fallback documenté) → pas de `CLOSED` sur frozen sans signature humaine réelle dans `GATE_LOG`.

---

## 13. Prochaines 5 actions immédiates

1. Vérifier chemins : `ls docs/DATABASE_SCHEMA_CORE.md` ; `grep -R "delivery" routes/api.php` pour D-04.
2. Lier ce document depuis `missions/*/execute_brief.md` (section « data plane »).
3. Re-lancer le terminal Claude **après reset quota** sur le prompt `_CLAUDE_PROMPT_DATA_CENTRAL_SYNC_2026-04-26.txt` pour **richir** ce fichier (détails file:line).
4. Enchaîner D-01 → D-03 en priorité (P0 fuite de données + outbox).
5. Aligner avec `CV1-M13` (migrations) si D-04 touche le schéma : gate schema.

---

**Fin du plan — version intermédiaire sûre ; enrichissement par audit Claude dès que quota disponible.**
