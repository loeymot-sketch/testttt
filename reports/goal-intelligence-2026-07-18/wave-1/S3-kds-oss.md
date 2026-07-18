# S3 — FINDER adversaire KDS + OSS (GOAL intelligence totale 2026-07-18, wave 1)

Mission READ-ONLY. Méthode verify-before-report : chaque finding porte file:line lu + repro
(requête DB exécutée sur la base LIVE ou évaluation PHP) + impact. Exclusions respectées :
OSS poll 2s disconnected, empty state « — », réimpression doublon 20s (171cf0ae9 — re-vérifiée,
elle TIENT, cf. §Sains), KDS-01/KDS-02 web.

Base : HEAD `246434458`, branche `pos/category-first-caisse-2026-06-23`, DB locale LIVE.

---

## Findings

### S3-01 (P2, data/structure) — Board KDS pollué AUJOURD'HUI par 22 zombies « advance » PAYÉES de juin ; 412 commandes actives en DB, toutes >24h, aucun janitor

**Preuve DB (requêtes exécutées, prédicat EXACT de `KitchenDisplaySystemOrderService::list`
rejoué)** : le board branch_id=1 matche **22 commandes en ce moment** — 19 POS du **14/06**
(is_advance_order=5, payment_status=5 PAID, status=8 PREPARED, queue A0012-A0017…), 2 delivery
du 19/06, 1 kiosk du 02/07. Zéro commande du jour.

Cause : la branche advance de la fenêtre (`KitchenDisplaySystemOrderService.php:152-156`,
idem `KdsSyncService.php:111-115`, OSS `OrderStatusScreenOrderService.php:118-125` et 265-270)
n'a AUCUN plancher temporel — contrat AUDIT-52-BUG1 « show ALL overdue advance orders » —
et il n'existe **aucune politique d'éviction** : une advance PAYÉE jamais bumpée DELIVERED
reste candidate au board à vie. `CleanupStalePendingKioskOrders` ne traite que les PENDING
impayées ; rien ne clôt les ACCEPT/PREPARING/PREPARED abandonnées.

Stock global : **412 commandes en statut actif (4/7/8), 412/412 >24h** ; 23 advance=YES
éternellement dans le prédicat board ; 5 PENDING_COUNTER impayées actives >24h.

Impact réel :
- payload de CHAQUE poll list() gonflé de 22 commandes complètes (items+relations), poll 5s
  quand WS down ;
- 22 des 50 slots du cap `limit(51)/take(50)` (`KitchenDisplaySystemOrderService.php:207-211`)
  consommés → la bannière overflow peut s'allumer alors que <30 vraies commandes existent ;
- layout legacy (`?v2=0`, gardé comme rollback « one URL keystroke ») : les 22 cartes PREPARED
  s'affichent dans les lanes ;
- le layout V2 (défaut) MASQUE l'effet par deux gardes indépendantes — `activeOrders` =
  ACCEPT/PREPARING seulement et `recentlyServed` clampé 8h sur updated_at
  (`KdsV2Grid.vue:205-240`) — c'est pourquoi personne ne l'a vu. La pollution données, le
  budget slots et le coût réseau restent.

Reco : plancher d'âge sur la branche advance (ex. overdue >72h → exclu du board) OU janitor
gate-owner qui clôt/annule les advance payées mortes + purge one-shot des 412.

### S3-02 (P2, structure/validation) — `is_advance_order` hors-enum rend une commande INVISIBLE de TOUTES les surfaces cuisine/mur à vie ; 24 cas réels en DB

Les deux branches de fenêtre de CHAQUE chemin (KDS list `:149/:153`, orderItems `:590/:592`,
sync `:110/:112`, OSS `:117/:122` + `:264/:267`) exigent `is_advance_order = Ask::NO` (10)
OU `= Ask::YES` (5). Toute autre valeur ⇒ la commande ne matche AUCUNE branche ⇒ absente du
board KDS, de l'agrégat items, du delta sync ET du mur client, tout en restant « active ».

Validation laxiste (vérifiée) :
- `app/Http/Requests/OrderRequest.php:88-89` et `PosOrderRequest.php:24-25` : normalisent
  UNIQUEMENT `0 → Ask::NO` ; `1`, `2`, `3`… passent la règle `['required','numeric']` (:164/:135) ;
- `app/Http/Requests/TableOrderRequest.php:41` : `numeric` sans AUCUNE normalisation ni `in:5,10`.

Preuve DB : 24 commandes status=7 avec is_advance_order ∈ {0,1,2} — 17× `=1` du 24/06
(source 'POS'), 4× `=2` du 13/06, 3× `=0` du **01/07** (les plus récentes). Ces commandes
étaient payées/à préparer et n'ont JAMAIS été visibles en cuisine. Les fronts actuels
(CheckoutComponent, PosComponent, borne) envoient les bons enums 5/10 — le trou est un
defense-in-depth manquant qu'un client futur (app mobile envoyant `1` pour « oui ») rouvrira.

Reco : règle `in:5,10` (ou `Rule::in([Ask::YES, Ask::NO])`) sur les 3 FormRequests +
normalisation truthy→5 / falsy→10.

### S3-03 (P3, structure/payload) — `recall()` caste le queue_number varchar en int → le broadcast KdsOrderRecalled porte `queue_number: 0` au lieu de « A0034 »

- DB : `orders.queue_number` = **varchar(20)**, valeurs réelles « A0034 », « A0033 »…
  (vérifié information_schema + sample) ;
- `KitchenDisplaySystemOrderService.php:405` : `(int) $locked->queue_number` — en PHP
  `(int) "A0034" === 0` ;
- l'event est TYPÉ int : `KdsOrderRecalled.php:34` (`public ?int $queueNumber`) — le type
  force le cast ; l'outbox persiste 0 (`PersistKdsOrderRecalledToOutbox.php:54`) ;
- côté stations réceptrices : `KitchenDisplaySystemComponent.vue:2295`
  (`parsed?.payload?.queue_number || null` — 0 falsy → null) puis `:2742-2744`
  (`payload.queueNumber || id`) → l'annonce aria/RAPPELÉ affiche l'**id DB interne**
  (ex. 5638) au lieu du numéro de file que la cuisine connaît.

Le badge fonctionne (matching par order_id) et le chef qui a cliqué voit le bon numéro
(KdsHistoryDrawer lit `order.queue_number` direct, `:114-115`) — la perte ne touche que
les AUTRES stations. Fix : typer string|null bout-en-bout et supprimer le cast.

### S3-04 (P3, machine à états/ledger) — 1773 transitions PENDING→PREPARING dans le ledger alors qu'`OrderStateMachine::allows(1,7) === false`

`order_status_transitions` (requête group by from/to exécutée) : `1→7 ×1773`, reason unique
`auto_prepare_on_paid (Wave S-1 POS direct sale)`. `OrderStateMachine::allows` (:37-38)
n'autorise depuis PENDING que ACCEPT/CANCELED/REJECTED. Ce n'est PAS un bypass runtime :
`OrderService.php:788-800` CRÉE la commande directement en PREPARING puis `:834-842` écrit
volontairement la ligne « conceptuelle » PENDING→PREPARING (« single-row collapsing pattern »,
commentaire :823-833). Mais la convention n'est documentée que dans ce commentaire : tout
outil de réconciliation ledger↔SSOT (ou un futur `assertAllows` sur ce chemin) déclarera
1773 lignes illégales. Soit ajouter la paire 1→7 à `allows()` (gate owner, fichier frozen §7),
soit documenter la convention dans OrderStateMachine/docs. À noter aussi : `22→13 ×2`
(RETURNED→DELIVERED) — légal via l'override Admin (`OrderStateMachine.php:79-84`), résurrections
admin à surveiller.

### S3-05 (P2, impression auto) — fenêtre morte du seed : toute commande créée pendant que le KDS est fermé/en reload est seedée « imprimée » → jamais de ticket auto, aucun badge d'échec

`_seedKitchenPrintedBacklogOnce` (`KitchenDisplaySystemComponent.vue:1989-2006`) marque au
premier fetch réussi TOUS les ids du backlog comme imprimés (`seedKitchenPrinted`,
`kitchenLocalPrinter.js:142-151`), en n'excluant QUE la liste d'échec persistée. Design
assumé anti-ré-impression massive (le heal 171cf0ae9), mais le revers : une commande arrivée
entre la fermeture/crash du KDS et le remount fait partie du premier fetch → seedée → exclue
à vie de l'auto-print, sans entrer dans `failedKitchenIds` (aucun badge ⚠️, aucun retry).
Scénario réel : crash Chrome/PC cuisine 10-30 min en service, ou F5 pendant un rush borne →
tickets papier silencieusement absents ; seule la carte à l'écran reste. Le serveur
(`PrintKioskKitchenTicketOnOrderCreated`) ne compense pas : no-op vérifié (aucune imprimante
station kitchen* en DB — seule `SAGA Caisse` station receipt — et PRINT_DRIVER=null).
Reco : ne seeder que les commandes plus vieilles que N minutes (ex. 10 min) et router les
récentes non-imprimées vers la liste d'échec (badge + retry), plutôt que le seed aveugle.

### S3-06 (P3, impression auto) — deux onglets KDS sur le même PC cuisine = double ticket quasi systématique

La dé-dup `_printed` est un cache mémoire PAR ONGLET, chargé une fois depuis localStorage
(`kitchenLocalPrinter.js:100-110`) et jamais resynchronisé (aucun listener `storage`) ;
la garde in-flight est par instance (`_kitchenInFlight`, composant :1222/:2019). Deux onglets
KDS (dupliquer un onglet suffit) reçoivent le même WS, chacun voit `hasKitchenPrinted=false`
sur son cache, chacun POSTe `/raw` → 2 tickets. `markKitchenPrinted` écrit le LS après coup
mais l'autre onglet ne relit jamais. Reco : listener `storage` sur PRINTED_LS_KEY ou
re-lecture LS dans `hasKitchenPrinted`.

### S3-07 (P3, sync/TZ) — le `since` du poll KDS n'est pas converti au fuseau session : fenêtre élargie de 2h à chaque poll (sur-fetch bénin)

Front : `_lastSince = server_now` (UTC ISO, `KdsSyncService.js:202-203`). Backend :
`KdsSyncService.php:53` formate `$since->format('Y-m-d H:i:s')` SANS `setTimezone(app.tz)` —
le littéral UTC est comparé par MySQL en session_tz=Paris (doctrine Wave T R5 documentée
pour les BOUNDS mais jamais appliquée au `since`). Effet : borne basse décalée de 2h dans le
passé → chaque delta re-sert ~2h de lignes déjà vues. Direction SAFE (jamais de trou, le
version-gate + full-refresh absorbent), coût perf/redondance seulement.

### S3-08 (P3, latent) — double impression cuisine LATENTE si une imprimante `kitchen_*` serveur est un jour activée

Deux chemins d'impression cuisine indépendants sans dé-dup croisée : (1) serveur
`PrintKioskKitchenTicketOnOrderCreated.php:46-73` (stations kitchen_hot/kitchen/kitchen_cold,
transport EscPos) ; (2) KDS auto-print → pont local :9101. Aujourd'hui no-op côté serveur
(0 imprimante kitchen en DB, PRINT_DRIVER=null) donc AUCUN doublon actif — mais créer une
imprimante « kitchen_hot » ACTIVE dans l'admin + câbler PRINT_DRIVER produira 2 tickets
par commande borne sans qu'aucun garde n'existe. À documenter/garder.

---

## Chemins vérifiés SAINS (anti-recyclage pour les vagues suivantes)

- **Réimpression doublon 20s (171cf0ae9) TIENT** : garde in-flight partagée par les 3 chemins
  (auto :2019, retry :2097, reprint :2126, `_kitchenInFlight` initialisé data() :1222) ;
  reprint marque `markKitchenPrinted` sur succès (:2134) ; bridge répond le résultat RÉEL
  (200/500), timeout pont 15s < timeout client 20s (`kitchenLocalPrinter.js:73`), job sauté
  si client aborté (`kitchen-bridge.js:226-229`), drop-oldest résout en échec (:254-258).
- **Machine à états KDS** : `changeStatus` = lock FOR UPDATE + optimistic `expected_status`
  (409) + `KitchenReleaseRule::canTransition` + `OrderStateMachine::allows` + guard
  board-release (`orderIsReleasedForBoard` :483) → impossible de bumper une commande
  invisible ; dispatches post-commit enveloppés \Throwable (:515-531). Recall : PREPARED-only
  sous lock, TTL 60s, cap N=1 fenêtre glissante, isolation branche, invariant re-read
  status inchangé (:396-399), event after-commit. DB : 0 transition from==to hors
  `kitchen_recall` (13×8→8, toutes reason kitchen_recall).
- **Board-release SSOT** : `applyBoardReleaseFilter` présent sur les 5 chemins (KDS list :84,
  orderItems :558, sync :123, OSS list :71, listForBranch :239) + guard bump — parité vérifiée.
- **OSS** : allowlist fail-closed KIOSK/TAKEAWAY, scope branche (403 cross-branch),
  garde identifiant visible (queue OU token), tri FIFO déterministe ; prédicat exact rejoué
  en DB → mur client actuellement VIDE (les 22 zombies KDS sont exclus par order_type) ;
  garde anti-fausse-notif au premier chargement (`_primed`, PreparingAndReadyComponent:409-423) ;
  dédup chime Echo/list (`_echoMarkedReady`).
- **Reconnexion WS** : KDS refetch complet sur `connected` (:2203-2207) + poll composant
  60s/5s + drift-sync 60s même WS-up (`KdsSyncService.js:362-370`) + jitter storm ;
  OSS poll immédiat disconnected→connected (`OssSyncService.js:177-180`) — pas de trou
  d'événement identifié (les deltas déclenchent des full-refresh, jamais d'application
  partielle).
- **Enums front/back alignés** : OrderStatus 9 valeurs identiques
  (`orderStatusEnum.js` ↔ `App\Enums\OrderStatus`), PaymentStatus 4 valeurs
  (PAID 5/UNPAID 10/PENDING_COUNTER 15/REFUNDED 20) identiques ; BROADCAST_MAP complet
  (KdsOrderRecalled, OrderPaidAtCounter, OrderStatusChanged, OrderCreated) ; payload outbox
  `old_status/new_status` = champs lus par `_statusChangeAffectsKds`.
- **`/kds`** : redirect Vue router vers `admin.kitchen-display-system` → le gate
  `path.includes('kitchen-display-system')` de startAutoRefresh fonctionne.
- **queue_number** : 0 collision sur 48h ; recyclage quotidien A0001+ sans chevauchement
  opérationnel (les doublons 30j sont inter-jours).
- **Auto-transition NEW→PREPARING** : code présent mais DÉSACTIVÉ par défaut
  (`KdsV2Grid.vue autoTransitionEnabled: default false` + pin parent, owner mandate Wave Q-2)
  — pas d'exposition au risque zombie-candidate.
- **KDSOrderDetailsResource** : phone client gated DELIVERY-only ; eager-loads batchés
  (N+1 healé PERF-KDS-N1) ; `payment_pending_counter` exposé.
- **historyToday** : statuts post-bump hard-codés voulus, fenêtre updated_at jour, cap 50 —
  les zombies de juin n'y apparaissent pas (updated_at hors jour).
