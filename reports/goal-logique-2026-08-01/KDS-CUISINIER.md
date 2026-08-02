# AUDIT LOGIQUE MÉTIER — KDS, RÔLE DU CUISINIER
**Date** : 2026-08-02 (goal-logique-2026-08-01) · **Auditeur** : agent « cuisinier » (logique, pas visuel)
**Cible** : backend local `http://127.0.0.1:8000` (+ instance jumelle `:8766`, même DB `foodking_e2e`), soketi `:6001`, worker queue `high,default` actifs.
**Méthode** : scénarios joués RÉELLEMENT — commandes créées via l'API caisse (`/api/admin/pos/quote` → `/api/admin/pos`, préfixe `ZZ-TEST-`), écran KDS piloté en Playwright (`/admin/kitchen-display-system`, board **V2 grid** rendu par défaut), preuves DOM + MySQL + HTTP + `domain_events`. Scripts et captures : `reports/goal-logique-2026-08-01/kds-audit-work/` (`phase1..10`, `shots/`).

---

## VERDICT GLOBAL

Le **moteur** du KDS est très sain : arrivée temps réel sub-seconde, FIFO strict, bump idempotent verrouillé (409 sur conflit), multi-écran sans F5, programmées correctement gérées (bandeau + garde de bump), recall 60 s, F5 sans perte. **MAIS** le cuisinier peut encore **préparer FAUX** à cause d'une collision de codes produit (P0), et le board V2 a perdu le badge « produit 86 » en cours de route (P1), tolère des cartes zombies éternelles qui squattent 1 des 3 slots visibles (P1), et retire les commandes annulées **sans aucun signal** (P1).

**Décompte : 1 P0 · 3 P1 · 2 P2 · 2 P3.**

---

## FINDINGS

### F-01 (P0) — Collision de code produit : « Chicken Burger » et « Menu Enfant Chicken Burger » = même ligne « CHI » → préparation fausse garantie
- **Preuve live** : commande caisse réelle id **6043 / N°A0035** contenant les 2 produits (items 38 + 106, tous deux `status=5` actifs en DB). Texte EXACT de la carte lu dans le DOM (phase4) :
  ```
  1× CHI | KTP
  1× CHI | KTP
  ```
  Deux lignes **byte-identiques** pour deux SKUs différents (burger normal vs menu enfant 4,90 € avec frites + boisson incluses). Le bouton « 🔑 Afficher les noms » ne change PAS ces lignes (re-dump identique — il révèle les noms CLIENTS, pas les produits).
- **Cause** : `produitCode()` saute les mots génériques `['menu','enfant',...]` puis prend les 3 lettres du 1er mot significatif → `Menu Enfant Chicken Burger` → `chicken` → **CHI** ; `Chicken Burger` → **CHI**.
  - JS (écran) : `resources/js/helpers/kdsSymbolic.js:286-300` (`CODE_GENERIC_WORDS`).
  - PHP (ticket imprimé — parité stricte revendiquée « ticket == écran ») : `app/Services/Hardware/KitchenTicketSymbolicFormatter.php:697-730`. → le **ticket papier a la même collision**.
  - Le commentaire du code montre que seul le cas « Menu Enfant Burger (BUR) vs Menu Enfant Nuggets (NUG) » a été traité — le cas **enfant vs adulte du même produit** a été manqué. La classe est générale : tout couple `{X, Menu Enfant X}` ; de plus la sémantique « menu enfant » (accompagnements inclus) disparaît totalement de l'écran ET du ticket (`grep -i enfant` : aucun marqueur rendu — seulement la liste des mots à SUPPRIMER).
- **Impact cuisinier** : impossible de savoir laquelle des 2 lignes est le menu enfant → burger seul servi à l'enfant ou menu complet servi à l'adulte. « Prépare FAUX » au sens strict de la grille.
- **Piste (non appliquée — audit read-only)** : suffixe explicite quand le nom d'origine contient `menu enfant` (ex. `CHI ENF`), côté PHP + JS en parité.

### F-02 (P1) — Zombies « advance » éternels + carte à 0 ligne dans les 3 slots visibles
- **Preuve live** : au 1er chargement, la tuile n°1 du board était **`E4MASS-CYCLE-1784893679457` (id 5935)** : commande **web du 2026-07-24 (9 jours)**, **0 ligne d'items** (`SELECT COUNT(*) FROM order_items WHERE order_id=5935` → **0**), affichée plein écran : « NOUVELLE · EN LIGNE · N°W8675 · ATTENTE **12389:38** » avec carte **VIDE** et bouton « Prêt » (capture `shots/p1-kds-board.png`). DB : `status=4`, `is_advance_order=5` (=Ask::YES), `payment_status=5`.
- **Cause** : la branche « advance overdue » du filtre board n'a **aucun plancher d'âge** : `app/Services/KitchenDisplaySystemOrderService.php:162-166` (`is_advance_order=YES` + `order_datetime < demain` + `not in (DELIVERED, CANCELED)` → visible **pour toujours**). La fenêtre glissante 8 h ne s'applique qu'aux non-advance. 15+ commandes `is_advance_order=YES` âgées de **49 jours** trouvées en DB (ids 4908-4929, status 8).
- **Aggravation V2** : le board V2 n'affiche que **3 cartes max** (mandat owner `[KDS-3CARDS]`, `KdsV2Grid.vue:256-261`) + pastille « +N en attente ». Le zombie étant le plus ancien, il occupe la **position FIFO n°1 en permanence** → 1/3 de la capacité d'affichage perdue, vraies commandes poussées vers « +N en attente », et un « ATTENTE » rouge permanent (fatigue d'alarme).
- **Workaround vérifié** : le cuisinier PEUT flusher manuellement (bump 4→7 puis 7→8, tous deux `202`, carte sortie de la grille — vérifié phase9) **mais** chaque bump déclenche les notifications client mail/SMS/push (`KitchenDisplaySystemOrderService.php:620-622`) sur une commande de 9 jours.
- **Nettoyage effectué par l'audit** : 5935 flushé (transitions 4→7→8 enregistrées, visibles dans l'Historique du jour).

### F-03 (P1) — Rupture (86) : le badge « produit 86 » sur les tickets EN COURS n'existe plus sur le board V2
- **Scénario joué** : commande TAKEAWAY id 6048 (Cayenne) EN COURS sur le KDS → 86 de « Cayenne » via l'endpoint caisse (`POST /api/admin/menu/availability/toggle` → 200). **Le badge OOS n'est JAMAIS apparu sur la carte en 20 s de polling DOM** (phase6), alors que :
  - l'événement est bien parti et broadcasté : `domain_events` id **11473/11476** `menu.item_availability_changed` / `ItemAvailabilityChanged`, `channel=["private-branch.1","public-menu.1"]`, `dispatched_at` non-null (worker OK — les OrderCreated du même canal arrivent en 0,55 s) ;
  - le handler existe côté page : `KitchenDisplaySystemComponent.vue:2469` (`_onItemAvailabilityChanged` → store `kdsInflight`).
- **Cause** : le rendu du badge (`kds-oos-warning-badge`, `kdsHasOosWarning`) n'existe **que dans le template legacy 4 colonnes** (`KitchenDisplaySystemComponent.vue:385/578/753/934` — 9 occurrences) ; **0 occurrence** dans `KdsOrderCard.vue` / `KdsV2Grid.vue` (grep `oos|inflight` vide). Le board V2 — rendu par défaut — a perdu la fonctionnalité `CV1-KDS-INFLIGHT-OOS-MARKER-001`.
- **Impact cuisinier** : il compose un ticket contenant un produit que la caisse vient de passer 86 sans aucun avertissement sur la carte.
- **Ce qui marche par ailleurs** (vérifié) : nouvelle vente caisse du produit 86 → quote **422** « “Cayenne” n'est plus disponible… » (aucune nouvelle commande 86 n'atteint la cuisine) ; panneau « 🚫 Rupture » du KDS liste bien le produit 86 avec possibilité de consultation (phase9, `shots/p9-rupture-panel.png`).

### F-04 (P1) — Annulation pendant la préparation : la carte disparaît en 0,7 s… sans AUCUN signal
- **Scénario joué** (phase9) : commande id 6043 **VISIBLE et EN COURS** sur l'écran du cuisinier → le caissier annule (`POST /api/admin/pos-order/change-status/6043 {status:16}` → 200). La carte est retirée de l'écran en **0,731 s sans F5** (sync excellente) **mais** recherche DOM `/annul|cancel/i` → **null** : pas de toast, pas de bandeau, pas de son, pas d'entrée « Historique du jour » (l'historique ne liste que PREPARED+, `KitchenDisplaySystemOrderService.php:356-360`).
- **Impact cuisinier** : mi-montage, il lève les yeux : la carte a disparu. Impossible de distinguer « annulée » / « j'ai bumpé par erreur » / « bug d'affichage ». En rush → il continue le montage (gaspillage) ou perd du temps à chercher. Le recall ne peut PAS l'aider (l'ordre n'est pas PREPARED).
- **Réf. code** : le handler Echo `OrderStatusChanged` ne fait qu'un `_debouncedRefresh()` silencieux (`KitchenDisplaySystemComponent.vue:2457-2461`) — aucun traitement spécifique `to=CANCELED`.

### F-05 (P2) — « Sans sauce » rendu « SAN » par le fallback 3 lettres, pas par un mapping explicite
- **Preuve live** : ligne de carte réelle `1× G | CAY | P | SAN` (commande 6039, variation « Sans sauce » id 688). « SAN » sort du **fallback générique** de `sauceSymbol()` (`kdsSymbolic.js:101-108` : pas de match table → `slice(0,3).toUpperCase()`), pas d'une règle « sans sauce ».
- **Impact** : l'info critique « NE PAS saucer » est rendue comme… un code de sauce parmi d'autres (`SAM`/`SAN` à une lettre près, position identique dans la ligne). Un intérimaire lira « sauce SAN ». Mérite un rendu contractuel distinct (ex. `⛔SAUCE` ou `S/SAUCE`), en parité PHP/JS.

### F-06 (P2) — Le poll adaptatif KDS tourne en cadence « déconnecté » (4-5 s) alors que le websocket est connecté
- **Preuve live** (phase2) : sur la page KDS, `window.Echo.connector.pusher.connection.state === "connected"` mais `window._wsService === undefined` → `KdsSyncService._baseCadence()` tombe dans la branche `ws_disconnected` (`resources/js/services/KdsSyncService.js:306-329`) → requêtes `/api/admin/kds-order/sync` observées **~4,2 s après le load** (cadence `FK_CATALOG_KDS_DISCONNECTED_BASE_MS=4000` + jitter) en PLUS du poll principal 15 s (`_pollingInterval`, `KitchenDisplaySystemComponent.vue:2415-2429`) et d'Echo.
- **Cause probable** : `window._wsService` est bien assigné dans `resources/js/bootstrap.js:450/453` mais absent au runtime du bundle **servi localement** (à re-vérifier sur le bundle VPS avant de conclure).
- **Impact cuisinier** : aucun (sur-fraîcheur). Impact système : ~13 000 requêtes/jour/écran superflues + la logique de cadence « connectée » (drift 60 s) inopérante.

### F-07 (P3) — API recall renvoie `queue_number: 0`
- **Preuve** : réponse live du recall de 6049 : `{"status":true,…,"queue_number":0}` alors que la commande est N°A0037. Cause : cast `(int)` d'un numéro **alphanumérique** (`KitchenDisplaySystemOrderService.php:494` : `(int) $locked->queue_number` sur « A0037 » → 0). Le payload broadcast cross-stations porte donc 0 (la ré-injection de la carte, par `order_id`, reste correcte — badge RAPPELÉ affiché en 0,3 s, vérifié).

### F-08 (P3) — Le CTA unique « Prêt » sur une carte « Nouvelle » démarre en réalité la préparation
- **Preuve** : carte zombie ACCEPT → CTAs `["Prêt"]` (phase9) ; le 1er clic fait 4→7 (En cours), un 2e clic fait 7→8. Comportement sûr (2 clics, pas de saut d'étape) mais label trompeur sur l'état « Nouvelle ». Cosmétique (les commandes caisse arrivant déjà « En cours » via `auto_prepare_on_paid`, le cas est rare — voir Annexe A.9).

---

## ANNEXE A — CE QUI EST PROUVÉ SAIN (validations positives)

1. **Arrivée temps réel** : commande caisse → carte KDS en **0,554 s** après le HTTP 201 (Echo `OrderCreated` → refresh ; `domain_events` sent=1, worker `high` OK). Fallback : sync-poll 4-5 s + poll principal 5 s (WS down) / 15 s (WS up).
2. **FIFO** : grille V2 triée vieux→récent (`KdsV2Grid.vue:183-199`), vérifiée 3× live (`["5935","6039","6043"]`…) ; l'overflow « +N en attente » **draine en FIFO** (slots libérés → 6051/6053 apparus, chip disparu — phase10). Le comptage « +2 » vérifié exact (5 actives dont 1 d'un audit sibling).
3. **Ticket riche** : « Mixte (hachée + poulet) » → **« P K »** ✓ (`kdsSymbolic.js:85-100`, order 6039 live) ; multi-sauces « SAM KTP » ✓ ; « ⭐ Viande supplémentaire » ✓ ; note cuisine affichée verbatim ✓ ; « Sauces en plus : Ketchup » parsé et fusionné dans la ligne symbolique ✓.
4. **Bump** : UI 1 clic → PREPARED ; carte quitte la grille en 0,26 s ; strip « RÉCEMMENT SERVIES » la conserve (« N°A0033 à l'instant »). **Double-bump impossible** : replay `expected_status` périmé → **409** (`optimistic_lock_conflict` loggé), replay même statut → **202 no-op** ; DB : **une seule** transition 7→8. Aucune perte.
5. **Multi-écran** : bump écran A → carte retirée sur écran B en **0,765 s sans F5** ; annulation → 0,73 s (F-04 pour le signal manquant).
6. **OSS** : TAKEAWAY bumpé → visible « Prêt » sur `oss-order` (id 6048 status 8, rows:1). Les commandes POS n'y figurent pas **par design** (allowlist fail-closed KIOSK+TAKEAWAY, `OrderStatusScreenOrderService.php:59-63`).
7. **Programmées** : commande `scheduled_at=+2h` → **absente du board**, bandeau « ⏰ Programmées (1) : 06:30 — #0208266049 » ✓ ; bump hors fenêtre → **422** « hors fenêtre cuisine (visible 20 min avant) » ✓ ; entrée en fenêtre (time-travel DB → now+10min) → carte dans la grille en **2,5 s** avec chip « 🕐 prévue 04:40 » ✓.
8. **86 côté flux** : produit 86 → nouvelle vente caisse bloquée au quote (**422** nommant le produit) → aucune commande contenant un 86 frais n'arrive au KDS par la caisse ; kiosk UNPAID invisibles (release filter vérifié par l'absence des ids 6027-6036).
9. **Auto-prepare** : commande caisse payée naît directement PREPARING (`auto_prepare_on_paid (Wave S-1 POS direct sale)`, transition 1→7 en DB) — pas d'étape « accepter » inutile pour un mono-poste.
10. **Recall** : bump par erreur → `POST /recall` < 60 s → 200, carte ré-injectée **RAPPELÉ** en 0,3 s ; 2e recall → **409** (cap N=1) ✓.
11. **F5** : grille strictement identique avant/après reload (état serveur) ✓.
12. **Historique du jour** : drawer « 📚 » liste les commandes servies avec **noms complets** (« 1× Cayenne Choix : Mixte (hachée + poulet)… ») — filet de sécurité pour vérifier un contenu a posteriori ✓.
13. **Zombie hors grille** : les PREPARED anciens (49 j) ne polluent NI la grille NI le strip (age-clamp 8 h `KdsV2Grid.vue:229-251`) — seuls les ACCEPT/PREPARING advance posent F-02.

## ANNEXE B — DONNÉES DE TEST CRÉÉES / ÉTAT MODIFIÉ
- Commandes créées (caisse réelle, payées cash, DB `foodking_e2e`) : 6039 (A0033), 6043 (A0035), 6048 (A0036), 6049 (A0037, programmée puis time-travel `scheduled_at` → +10 min), 6050 (A0038, annulée+remboursée), 6051 (A0039), toutes `pos_customer_name=ZZ-TEST-*`, bumpées PREPARED ou annulées en fin d'audit.
- 5935 (E4MASS) : flushé 4→7→8 (2 transitions ajoutées) pour libérer la tuile n°1 — action documentée F-02.
- Items 22/24 : 86 puis **restaurés** (`is_available:true`, vérifié 200). Aucun code applicatif modifié.
- 6053 (`ZZ-TEST-CAISSIER-S1`) appartient à un audit parallèle — non touché.
