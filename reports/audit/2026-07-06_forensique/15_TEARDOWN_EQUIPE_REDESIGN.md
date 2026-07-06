# FoodKing — Teardown d'équipe & blueprint de refonte (composant par composant)

> Décomposition au **niveau mécanisme** par une équipe de **16 spécialistes staff-level**, chacun propriétaire d'un composant critique : carte mécanique + défauts (« pourquoi le design l'autorise ») + **redesign cible** + contrats de test, puis **revue croisée adversariale** par un pair.
> **Verdict d'équipe : 16/16 composants `à-refondre`. 128 défauts mécanisme (50 critiques, 61 élevés). 16/16 revues : `ajuster` (redesign directionnellement juste, à peaufiner).** Audit statique, aucune modification de code.

---

## 0. Synthèse tech-lead — patterns de refonte transverses

Les 16 teardowns convergent : ce n'est **pas** un travail de rustines, mais une **refonte structurelle disciplinée** où chaque invariant devient *porté par la structure* (contrainte DB, service unique, posture par défaut) plutôt que par une vérification applicative isolée. Neuf patterns transverses ressortent :

1. **`OrderTransitionService` unique** — un seul chemin de transition (garde + motif + remboursement + points + audit NF525 + broadcast). Remplace les chemins divergents `OrderService::changeStatus` vs `KitchenDisplaySystemOrderService` qui produisent des résultats métier différents pour la même transition.
2. **`MoneyMutationService` + `UNIQUE(transactions.order_id[,type])`** — tout mouvement d'argent passe par un service transactionnel (`DB::transaction` + `lockForUpdate`), l'unicité DB rend le double crédit/débit/remboursement **structurellement impossible**.
3. **Posture Kernel deny-by-default** — `auth:sanctum` + scope de branche portés par le **groupe racine `api`**, chaque route publique devient une **exception d'une allowlist unique**. Effondre la strate « endpoint oublié ».
4. **Principal authentifié serveur** — dériver `branch_id`, `customer_id`, l'ownership et le fait « payé » **du serveur** (token, webhook PSP), jamais du body client. QR de table = jeton signé.
5. **Invariants portés par des contraintes DB** — FK réelles, `unique`, colonnes `NOT NULL` (fin de `branch_id default 0`), `orders.sealed_at` + garde `Order::updating/deleting` rejetant toute mutation d'une ligne scellée (couvre save, mass-update, changeStatus).
6. **Assiette fiscale à la source** — `total_ht`/`total_tva` calculés **après remise** dans une primitive unique, `fiscal_sequence_no` sur **tous** les canaux (POS/kiosk/table/web), `z_report_id` par commande (fin de la fenêtre morte).
7. **Fidélité = ledger transactionnel** — remplacer l'entier mutable unique `users.loyalty_points` par un **grand-livre de points** (append-only, solde dérivé), sérialisé, idempotent (earn lié à `order_id`), qui supprime lost-update/double-débit/soldes négatifs.
8. **Contrat d'événements mono-source + outbox atomique** — écriture outbox **dans la transaction** de la mutation, relais `afterCommit`, consommateurs **idempotents** (dédup par `correlation_id`), schéma d'événement importé des deux côtés.
9. **CI prod-parité + tests d'invariant property-based** — MySQL réel (FK/verrous), file async réelle, broadcaster réel ; chaque invariant prouvé par une famille de tests mutation-testés, en CI bloquante.

**Ordre de refonte recommandé** (dépendances) : 
`Fondations` (authz deny-by-default + contraintes DB + principal serveur) → `Cœur argent/fiscal` (MoneyMutationService, sealed_at, assiette HT, ledger fidélité) → `Transitions` (OrderTransitionService) → `Synchro` (outbox atomique, resync, availability structurelle) → `Front/Perf` (bundle, state, temps réel incrémental) → `Qualité` (CI prod-parité, invariants sous test). Les fondations débloquent tout le reste ; les tests verrouillent chaque étape.


---

## 1. Teardowns par composant

### ⚙️ Chaîne fiscale FoodKing — FiscalSequenceService, ZReportService, XReportService, AuditLogService, config/migrations 2026_04_22_*
**Verdict : `a-refondre`**

**Carte mécanique :**

## Carte mécanique

**Allocation de séquence** — `FiscalSequenceService::next()` (FiscalSequenceService.php:57). Prend `Cache::lock('fiscal_seq_b{n}',5)` bloquant 3 s (l.65-74), puis en transaction `Order::withoutGlobalScopes()->where(branch_id)->lockForUpdate()->max('fiscal_sequence_no')+1` (l.88-93). **La séquence n'est PAS un compteur durable : elle est dérivée de MAX(orders) à chaque appel.** L'appelant l'imbrique (SAVEPOINT).

**Câblage** — un seul appelant : `OrderService::posOrderStore` (l.862-863) affecte `fiscal_sequence_no` avant `save()`. `myOrderStore` (l.297) et `tableOrderStore` (l.986) ne l'affectent jamais.

**Clôture Z** — `open()` (l.44) réserve `sequence_no=MAX(z_reports)+1`, `opened_at=now`, OPEN. `close()` (l.102) : `aggregate(branchId, open->opened_at, closedAt)` (l.129) — **borne basse = `opened_at`, pas le `closed_at` du Z précédent** — puis `sign()` chaîné sur la dernière signature CLOSED (l.131-137), `forceFill` agrégats + CLOSED (l.139-145). Aucun ordre estampillé.

**Agrégation** — `aggregate()` (l.181) : `created_at > from AND <= to`, `whereNotNull(fiscal_sequence_no)` (l.209), somme sur `payment_status != UNPAID` (l.217). `total_ht = total_ht ?? subtotal` (l.229) — **la colonne `orders.total_ht` n'existe pas**, fallback permanent sur `subtotal`. `total_ttc = total` (l.228). `cancel/refund_count` (l.237-238) sans filtre payment. `total_by_tax_rate` depuis `order_items.tax_amount` (l.250-262).

**Snapshot X** — `XReportService::snapshot()` délègue à `aggregate()` mais `defaultFrom()` (l.58) = **dernier `closed_at`**, incohérent avec la borne Z.

**Chaîne HMAC audit** — `write()` (l.69) : lock + txn + `performInsert`, retry unique-once **dans la même txn** (l.159-171). `audit_logs` immuable par triggers (migration 000002). Le cycle Z n'écrit QUE dans `Log::channel('fiscal')` (l.84,151), pas dans `audit_logs`.

**Frontières** : ZReportController (perm `pos-manage-fiscal`). Signature Z sur `{branch_id, sequence_no, closed_at UTC, aggregates}` (l.331-341), secret unique `fiscal.z_report_secret`.


**Défauts au niveau mécanisme :**

| Sév | Défaut | Pourquoi le design l'autorise | Emplacement |
|:--:|---|---|---|
| 🔴 | Fenêtre morte inter-Z : la borne basse est opened_at et non le closed_at précédent | close() agrège (open->opened_at, closed_at] (ZReportService.php:129). Le open() du Z suivant pose un opened_at > closed_at précédent. Tout ordre créé entre closed_at(n) et opened_a | `app/Services/Fiscal/ZReportService.php:129` |
| 🔴 | Fenêtrage par created_at + snapshot payment_status : perte des paiements tardifs | aggregate() borne par created_at (l.210) et somme sur payment_status!=UNPAID au moment du close (l.217). Un ordre créé dans la fenêtre mais payé APRÈS la clôture est exclu ici, pui | `app/Services/Fiscal/ZReportService.php:210` |
| 🔴 | TTC != HT + TVA : assiette HT pré-remise et hors livraison | orders n'a pas de colonne total_ht ; l.229 retombe sur subtotal (brut, pré-remise). total_ttc=total = subtotal+tax+livraison-remise (OrderService:822). Donc TTC-HT-TVA = livraison- | `app/Services/Fiscal/ZReportService.php:229` |
| 🔴 | Canaux non numérotés : seul le POS reçoit un fiscal_sequence_no | posOrderStore affecte fiscal_sequence_no (OrderService.php:862) ; myOrderStore (l.297) et tableOrderStore (l.986) — en ligne, table QR, kiosk — jamais. aggregate() filtre whereNotN | `app/Services/OrderService.php:862` |
| 🔴 | Séquence non durable : MAX(orders)+1 réémet un numéro brûlé après suppression/rollback | next() calcule MAX(fiscal_sequence_no)+1 (FiscalSequenceService.php:88), non persisté. Si l'ordre le plus haut est hard-deleted, ou si la txn appelante roll-back (présenté comme fe | `app/Services/Fiscal/FiscalSequenceService.php:88` |
| 🔴 | Scellement non structurel : ordres mutables et verifySignature aveugle à leur édition | Aucun z_report_id/sealed_at posé sur les orders au close. verifySignature() (ZReportService:288) recalcule le HMAC sur les agrégats STOCKÉS, pas sur les ordres. Éditer order.total  | `app/Services/Fiscal/ZReportService.php:288` |
| 🟠 | Table z_reports mutable : pas de triggers d'immutabilité | La migration audit_logs installe des triggers BEFORE UPDATE/DELETE (000002:96-136) mais z_reports (000003) n'en a aucun. Un close peut être ré-ouvert via forceFill/UPDATE brut (sta | `database/migrations/2026_04_22_000003_create_z_reports_table.php:57` |
| 🟠 | Remboursements/annulations comptés positivement et en double | aggregate() somme total_ttc sur payment_status!=UNPAID (l.217) puis compte cancel/refund_count sur le même jeu SANS retirer leur montant (l.237-238). Un ordre RETURNED payé à total | `app/Services/Fiscal/ZReportService.php:237` |

**🎯 Redesign cible :**

## Architecture cible

**1. Compteur de séquence durable.**
Table `fiscal_counters(branch_id, kind, last_value)`. `next(branch)` = `UPDATE ... SET last_value=last_value+1 RETURNING` en txn — jamais dérivé de `orders`. **On numérote au moment fiscal (paiement encaissé / ticket émis), pas à la création**, pour TOUS les canaux. Contrat : monotone, sans réutilisation même après suppression d'ordre ; gaps autorisés mais tracés (`fiscal_voided_reason`).

**2. Appartenance explicite à un Z, pas d'inférence temporelle.**
Ajouter `orders.z_report_id` + `orders.sealed_at`. À la finalisation l'ordre reçoit `fiscal_sequence_no`, `z_report_id=NULL`. `close()` fait `UPDATE orders SET z_report_id=:id, sealed_at=now WHERE branch_id=:b AND fiscal_sequence_no IS NOT NULL AND z_report_id IS NULL AND is_finalized`. L'agrégation somme **exactement `z_report_id=:id`** — plus de borne created_at, plus de fenêtre morte, plus de perte de paiement tardif (il prend le Z suivant). X = même requête sur `z_report_id IS NULL`.

**3. Assiette à la source, TTC=HT+TVA structurel.**
Persister par ordre `total_ht` (post-remise), `total_tva`, `total_ttc`, avec invariant `abs(ttc-(ht+tva)) <= 0.01` refusé sinon. Remise et livraison ventilées en HT+TVA par taux avant scellement. Le Z somme ces colonnes ; `total_by_tax_rate` vient des lignes scellées, pas d'un recalcul. Remboursements/annulations = lignes NÉGATIVES (`is_refund`), soustraites du TTC, isolées dans `refund_total`.

**4. Scellement structurel (base, pas applicatif).**
Triggers BEFORE UPDATE/DELETE sur `z_reports` (comme `audit_logs`). Garde `Order::updating` : si `sealed_at` non nul, rejeter toute mutation des colonnes financières/fiscales. `verifySignature()` recalcule le HMAC sur **l'empreinte des ordres scellés** (hash trié des tuples `(fiscal_sequence_no, total_ttc, total_ht, total_tva)`) incluse dans la charge signée — la signature lie le Z à ses tickets.

**5. Chaîne unique et exhaustive.**
Le cycle Z, l'allocation de séquence et l'estampillage écrivent dans `audit_logs` (immuable), pas dans un fichier log. Signature Z inclut `prev_z_signature`, `branch_id`, `sequence_no`, `opened_at`, `closed_at` UTC, `fiscal_seq_range [min,max]`, agrégats, `orders_fingerprint`. Secret par branche, rotation versionnée (`secret_version` sur la ligne).

**6. Robustesse concurrente.**
Ligne `fiscal_counters` pré-créée par branche → plus de course « MAX(vide)=1 ». Le retry unique audit dans une **nouvelle** txn (ou `INSERT ... ON CONFLICT`) pour rester correct hors MySQL.


**Contrats de test :**
- Exhaustivité (property-based) : SUM(z.total_ttc sur tous les Z) == SUM(orders.total_ttc scellés) ; aucun ordre finalisé sans z_report_id après le Z suivant (pas de fenêtre morte).
- Identité fiscale : pour tout Z, abs(total_ttc - (total_ht + total_tva + refund_total)) <= 0.01, remises et livraison incluses ; SUM(total_by_tax_rate) == total_tva.
- Paiement tardif : un ordre créé pendant Z(n), payé après close(n), est agrégé dans Z(n+1) exactement une fois — jamais zéro, jamais deux.
- Non-réutilisation : allouer N numéros, hard-delete le plus haut ordre, réallouer → numéro > tous les précédents (compteur durable), unicité (branch, seq) tenue.
- Scellement : après close, tout UPDATE/DELETE d'ordre scellé ou de la ligne z_report est rejeté ; verifySignature détecte une édition d'order.total post-close via orders_fingerprint.
- Multi-canal : ordres POS, kiosk, table QR, en ligne finalisés reçoivent un fiscal_sequence_no et apparaissent dans le Z ; verifyChain() reste vert après un cycle open/close audité.

**Migration/rollout :** (1) créer `fiscal_counters`, amorcer par branche à `MAX(orders.fiscal_sequence_no)` (pas de régression des numéros émis). (2) ajouter `orders.total_ht`, `z_report_id`, `sealed_at`, `secret_version` (nullable). (3) backfill data isolé/journalisé : recalcul total_ht post-remise + estampillage `z_report_id` via l'ancienne fenêtre created_at ; NE PAS re-signer les Z clos (flag `legacy_unsealed`). (4) installer triggers z_reports + garde Order::updating APRÈS le backfill. (5) numérotation multi-canal derrière un flag par branche. Compat : `fiscal_sequence_no` reste nullable ; lecture Z tolère l'ancien schéma tant que `legacy_unsealed=1`. Rollback interdit en production (comme audit_logs).

---

### ⚙️ OrderService (cycle de vie commande) + OrderStateMachine
**Verdict : `a-refondre`**

**Carte mécanique :**

## Carte mécanique — app/Services/OrderService.php (1888 l.) + app/Domain/Order/OrderStateMachine.php

**God-object, 3 responsabilités mêlées** : (a) création+pricing, (b) transitions d'état, (c) mutations monétaires/fiscales.

### Écriture (création)
- `posOrderStore` 549-973 : idempotency pré-check hors txn 553-559 ; garde branche 578-584 ; `Order::create` status=ACCEPT/PAID total=0 586-598 ; pricing SSOT 604-627 ; alloc queue sous Cache::lock 783-809 ; validation cash serveur 828-835 ; `fiscal_sequence_no = next()` 862-863 (SAVEPOINT imbriqué) ; catch 23000 idempotence DB 955-968.
- `myOrderStore` 288-544, `tableOrderStore` 979-1257 : même moule, FrontendOrder pour table.

### Transitions d'état (cœur de l'angle)
- `changeStatus` 1421-1568 : **DEUX chemins divergents**. Garde `ValidStatusTransition($order->status)` 1424 (TOCTOU, aucun lock). Chemin `$auth` (self-cancel client) 1428-1462 : cashBack 1436 + refundPoints 1443 + save 1445 + recordTransition — **hors DB::transaction**, reason optionnelle 1432, aucun ActionLog/NF525. Chemin staff 1470-1548 : DB::transaction, garde branche 1473-1478, reason requise 1481, cashBack/refund 1487-1494, recordTransition 1501, ActionLog 1510, NF525 1529. Broadcast après commit 1555.
- `deliveryBoyOrderChangeStatus` 1356-1416 : flip auto UNPAID→PAID 1379-1381.
- `changePaymentStatus` 1573-1634 : aucune machine d'état paiement ; `$auth` owner-only écrit verbatim 1576-1583.
- `destroy` 1695-1793 : garde branche 1706, garde PAID 1711, **seule vérif de scellement** via fenêtre ZReport 1723-1736.

### OrderStateMachine
- `allows` 27-72 : table transitions ; raccourci POS →DELIVERED 38/45 ; terminaux→n'importe quoi si Admin 60-67.
- `recordTransition` 84-111 : best-effort, avale Throwable 108.
- `apply` 131-171 : guard+mutate+audit+reason atomique — **code mort, aucun appelant OrderService** (frozen-zone).

### Frontières txn & état
État **vif** (`orders.status/payment_status`) et **fiscal** (transactions, loyalty_transactions, audit_logs HMAC, fiscal_sequence_no, ZReport) mutés dans des frontières transactionnelles incohérentes selon le chemin. Appelants : Pos/Table/OnlineOrder/KDS/DeliveryBoy/Frontend controllers.


**Défauts au niveau mécanisme :**

| Sév | Défaut | Pourquoi le design l'autorise | Emplacement |
|:--:|---|---|---|
| 🔴 | Self-cancel client : refund monétaire hors transaction (atomicité perdue) | OrderService:1428-1462 — le chemin $auth exécute cashBack (Transaction '-', crédit user.balance) + refundPoints + status=CANCELED + save + recordTransition SANS DB::transaction eng | `app/Services/OrderService.php:1436` |
| 🔴 | Aucun UNIQUE sur transactions.order_id → double remboursement | PaymentService:31 cashBack fait where(order_id)->first() PUIS Transaction::create() et user.balance += total, sans clé d'unicité (migration 2023_03_23_143747, contrairement à loyal | `app/Services/PaymentService.php:31` |
| 🔴 | Isolation par branche contournée quand branch_id est falsy (0/null) | OrderService:1475/1589/579 — garde `if ($userBranch && (int)$userBranch !== $order->branch_id)`. branch_id=0 (sentinelle admin) et null sont falsy : le prédicat n'est jamais évalué | `app/Services/OrderService.php:1475` |
| 🔴 | Aucun scellement à l'écriture : commande Z-cloturée reste mutable | changeStatus 1421 et changePaymentStatus 1573 ne consultent jamais le scellement ; ni colonne sealed_at ni garde Order::updating (Order::boot 79-107 ne garde que restoring). Seul d | `app/Services/OrderService.php:1573` |
| 🟠 | changePaymentStatus : pas de machine d'état paiement, client s'auto-marque PAID | OrderService:1576-1583 — le chemin $auth vérifie seulement la propriété puis écrit $request->payment_status verbatim. Aucune table de transitions autorisées pour payment_status (co | `app/Services/OrderService.php:1578` |
| 🟠 | Garde de transition TOCTOU, non portée par la persistance (lost update) | OrderService:1424+1499 — allows() est évalué sur $order->status lu avant l'UPDATE ; la ligne n'est jamais verrouillée (pas de lockForUpdate ni fresh reload) et l'UPDATE n'a pas de  | `app/Services/OrderService.php:1499` |
| 🟠 | Audit de transition best-effort ; apply() atomique est code mort | OrderStateMachine:108 recordTransition avale Throwable et logge : l'UPDATE de status commit même si la ligne d'audit échoue. En parallèle apply() (131-171, guard+mutate+audit atomi | `app/Domain/Order/OrderStateMachine.php:108` |
| 🟡 | deliveryBoyOrderChangeStatus marque silencieusement la commande PAID | OrderService:1379-1381 — si aucune Transaction n'existe et payment_status==UNPAID, le code bascule payment_status=PAID en effet de bord d'un changement de statut, sans audit NF525  | `app/Services/OrderService.php:1380` |

**🎯 Redesign cible :**

## Cible : casser le god-object en 3 collaborateurs à invariants portés structurellement

### 1. OrderTransitionService (unique porte de sortie pour TOUT changement de statut)
Signature unique : `transition(OrderContract $order, int $to, Actor $actor, ?string $reason): void`. Remplace les 4 chemins actuels (changeStatus×2, deliveryBoyOrderChangeStatus, KDS). Interne, dans **une seule** DB::transaction :
1. `SELECT ... FOR UPDATE` sur la ligne (verrou pessimiste) → relit `from` frais.
2. `OrderStateMachine::assertAllows(from,to,actor)` + `requiresReason` (réutilise `apply()`, qu'on **active** au lieu de le laisser mort).
3. UPDATE conditionnel `WHERE id=? AND status=?from` (garde portée par la persistance ; 0 ligne affectée ⇒ conflit concurrent ⇒ throw 409).
4. Effets liés à la transition délégués : remboursement → MoneyMutationService ; loyalty → LoyaltyService ; audit transition (insertion **non best-effort**, l'échec rollback tout).
5. Broadcast/notifications **enregistrés en outbox** dans la même txn (levier outbox), jamais dispatch inline.
Le contrôle de branche devient un `TransitionPolicy` explicite : `actor.isGlobalAdmin()` méthode booléenne dédiée, **jamais** `branch_id==0` (levier « détruire branch_id==0 » : principal authentifié serveur avec rôle explicite).

### 2. MoneyMutationService (seule autorité sur transactions + balance + payment_status)
`refund(order, reason, idempotencyKey)`, `capture(...)`, `setPaymentStatus(order, to, actor)`. Invariants portés :
- **UNIQUE(order_id, type)** sur `transactions` (comme loyalty_transactions) : le double-refund impossible au niveau schéma, pas au niveau code.
- payment_status muté **uniquement** via `PaymentStateMachine` : UNPAID→PAID→REFUNDED, jamais de retour arbitraire, jamais depuis le client.
- Toute mutation d'argent écrit la chaîne HMAC NF525 dans la même txn (atomique, pas de chemin sans audit).

### 3. Scellement en état persistant (levier 4)
Colonne `orders.sealed_at` (posée à la clôture Z). Garde `Order::updating` : si `sealed_at !== null` et qu'un attribut fiscal (status, payment_status, total, tax…) change → `SealedOrderException` (409). Le scellement cesse d'être une requête ZReport ad hoc dans destroy() : il devient un invariant du modèle, vérifié à **chaque** write, quel que soit l'appelant.

### Contrats
- `OrderContract` (Order + FrontendOrder) : unifie les deux tables sous une interface, supprime la duplication Order/FrontendOrder.
- OrderService ne conserve QUE lecture (list/show/report) + orchestration création ; il **délègue** toute transition et mutation d'argent. Plus aucun `$order->status=...; save()` ni `cashBack()` dans OrderService.
- OrderStateMachine::allows : retirer l'override terminal→n'importe quoi pour Admin (60-67) ; un RETURNED ne redevient pas PREPARING sans passer par MoneyMutationService.


**Contrats de test :**
- Property (concurrence) : N annulations concurrentes de la même commande PAID ⇒ exactement UNE ligne transactions type=cash_back, un seul crédit user.balance, un seul refund loyalty. Prouve l'UNIQUE(order_id,type) + verrou.
- Property (atomicité) : injecter une exception après cashBack et avant save dans une annulation ⇒ 0 mutation persistée (balance inchangée, status inchangé, aucune ligne transaction). Prouve la frontière txn unique.
- Invariant persistance : deux transitions concurrentes depuis le même from (CANCELED vs PREPARING) ⇒ la seconde reçoit 409, l'UPDATE WHERE status=from affecte 0 ligne ; état final cohérent avec l'unique gagnante.
- Scellement : sur commande sealed_at!=null, tout transition()/setPaymentStatus()/update total ⇒ SealedOrderException 409 ; aucune écriture. Balayer status, payment_status, total.
- Isolation branche : matrice actor.branch_id ∈ {0,null,B1,B2} × order.branch ∈ {B1,B2}. Seul actor.isGlobalAdmin()==true traverse ; branch_id=0/null non-admin ⇒ 403. Prouve la suppression de la sentinelle falsy.
- Audit non best-effort : forcer l'échec de l'insertion order_status_transitions ⇒ toute la transition rollback (status non commit). Aucune transition sans audit NF525 correspondant.

**Migration/rollout :** (1) Schéma d'abord : UNIQUE(order_id,type) sur transactions APRÈS dédoublonnage des cash_back (fusion + recalcul balance) ; orders.sealed_at nullable ; back-fill sealed_at depuis ZReport closes. (2) MoneyMutationService + OrderTransitionService en shadow, activer apply() d'abord sur KDS/DeliveryBoy derrière flag orders.use_transition_service. (3) Bascule chemin par chemin (POS, Table, Online, Frontend), chacun couvert par tests de concurrence. (4) Garde Order::updating scellement EN DERNIER, sealed_at back-fillé et chemins migrés, sinon writes legacy lèvent 409. Rollback : le flag revient à l'ancien chemin sans revert schéma (UNIQUE/sealed_at additifs).

---

### ⚙️ FrontendOrderService — cycle borne (pending→payé→cuisine, abandon/cleanup, remboursement)
**Verdict : `a-refondre`**

**Carte mécanique :**

## Carte mécanique — cycle borne

### Méthodes clés
- `myOrderStore()` FrontendOrderService.php:121-614 — création. Verrou idempotence (Cache::lock, 130-144), `DB::transaction` (150-562), pricing SSOT (210-229) ou legacy (230-370), allocation queue sous lock (372-403), **déduction fidélité inline** (442-507), calcul totaux (509-526), auto-accept cash immédiat (557-561). Notifs post-commit (579-589).
- `changeStatus()` :635-701 — annulation client. Garde transition (638), garde propriété (641), seuil annulable kiosk = PREPARING (654-658), `cashBack` si `$order->transaction` existe (660-666), `refundPoints` (667), save statut (669-670), audit + événements (671-691).
- `finalizePaidKioskOrder()` :772-828 — promotion PENDING→ACCEPT après paiement carte/ticket. `DB::transaction`+`lockForUpdate` (792-804), garde idempotente `status >= ACCEPT` (797). **Ne touche pas payment_status, ne crée pas de Transaction.**
- `CleanupStalePendingKioskOrders::handle()` Cleanup.php:15-46 — cron every 5 min. Sélectionne `status=PENDING` + `source_surface=kiosk` + type KIOSK/TAKEAWAY + âge>15 min (19-26), `OrderStateMachine::apply(...REJECTED...)` par ligne (32-37), notifs (41-44).

### États & frontières de transaction
- Création carte/ticket : `status=PENDING`, `payment_status=UNPAID` (200). Cash immédiat : `PAID`+`ACCEPT`.
- Paiement carte (OrderController.php:101-118) : **T1** verrouille et pose `payment_status=PAID`+transaction_id (colonne), commit. **T2** = `finalizePaidKioskOrder` promeut PENDING→ACCEPT (120-122). Deux transactions disjointes, aucune compensation.
- `apply()` OrderStateMachine.php:131-171 : lit `$order->status` du modèle **en mémoire** (137), pas de re-lecture sous verrou dans sa transaction (155-170).

### Flux monétaire / points
- Fidélité **débitée à la création** (463-492) même pour commande carte non payée ; ledger `loyalty_transactions` type `redeem`.
- Remboursement carte = `PaymentService::cashBack` (:31-72) : crée Transaction `cash_back`, crédite `user->balance += order->total` (46), **conditionné à l'existence d'une Transaction** (33) — jamais créée pour carte kiosk.
- `refundPoints` LoyaltyService.php:21-79 : increment (56) puis insert reversal `manual_add` (62), hors transaction.

### Appelants
OrderController.php:120 (finalize), routes frontend (changeStatus/myOrderStore), Kernel.php:35 (cleanup cron).


**Défauts au niveau mécanisme :**

| Sév | Défaut | Pourquoi le design l'autorise | Emplacement |
|:--:|---|---|---|
| 🔴 | Le cleanup auto-rejette sur status=PENDING sans lire payment_status → commande payée rejet | Le job filtre seulement status=PENDING (Cleanup:19-26), jamais payment_status. Une commande carte peut être PENDING+PAID car T1 (payment_status=PAID commit, OrderController:111) et | `app/Jobs/CleanupStalePendingKioskOrders.php:20` |
| 🔴 | Remboursement conditionné à une Transaction jamais créée pour un paiement carte kiosk | changeStatus ne rembourse que si $order->transaction existe (:660). Or le paiement carte borne (OrderController:101-118) n'écrit que les colonnes de l'ordre, sans appeler PaymentSe | `app/Services/FrontendOrderService.php:660` |
| 🔴 | TOCTOU double-remboursement à l'annulation : check-cashBack-save hors verrou | La lecture du statut (656), cashBack (661), refundPoints (667) et save (670) ne sont ni sous lockForUpdate ni en transaction. Deux annulations concurrentes lisent status<PREPARING, | `app/Services/FrontendOrderService.php:656` |
| 🔴 | Le cleanup ne restaure pas les points fidélité des commandes abandonnées | Les points sont débités inline dès la création (FrontendOrderService:463-492), même pour une carte non payée. Le cleanup (Cleanup:32-37) n'appelle que apply → REJECTED, jamais refu | `app/Jobs/CleanupStalePendingKioskOrders.php:32` |
| 🟠 | Lost-update finalize↔cleanup : apply() décide sur un statut lu en mémoire | apply() calcule from=(int)$order->status depuis le modèle chargé au fetch du job (OSM:137), puis mute sans re-SELECT FOR UPDATE (155-170). Si finalize commit PENDING→ACCEPT après l | `app/Domain/Order/OrderStateMachine.php:137` |
| 🟠 | refundPoints non atomique/idempotent : increment avant l'insert, hors transaction | increment('loyalty_points') (:56) et l'insert du reversal (:62) sont séquentiels, sans transaction englobante ni contrôle qu'une réversion existe. Deux annulations concurrentes inc | `app/Services/LoyaltyService.php:56` |
| 🟠 | finalizePaidKioskOrder promeut le statut mais laisse payment_status et le registre incohér | La méthode passe PENDING→ACCEPT (:801) sans poser payment_status=PAID ni créer de Transaction ; ces états vivent dans la transaction contrôleur distincte (:101-118). L'absence de c | `app/Services/FrontendOrderService.php:801` |
| 🟡 | cashBack mute solde et registre sans frontière MoneyMutation ni UNIQUE(order_id) | cashBack insère une Transaction et fait user->balance += order->total (:35-46) sur le montant total, sans clé d'idempotence, sans UNIQUE(transactions.order_id), hors service de mut | `app/Services/PaymentService.php:35` |

**🎯 Redesign cible :**

## Architecture cible — `KioskOrderLifecycle`

### 1. Deux axes portés structurellement
Séparer `status` (workflow cuisine) et `payment_status` (PAID/UNPAID/REFUNDED). Toute transition passe par `OrderStateMachine::apply()` **re-lisant la ligne sous `lockForUpdate` dans sa transaction** (corrige D5) : `from` = statut verrouillé, jamais le modèle en mémoire.

### 2. Cleanup respectant le paiement (D1, D4)
Le job ne rejette que le réellement abandonné : `status=PENDING AND payment_status=UNPAID AND age>15min`. Par ligne : `lockForUpdate`, re-vérifier PENDING+UNPAID sous verrou, `apply(REJECTED)` **suivi de `KioskCompensation::run()`** qui restaure fidélité (refundPoints) et stock dans la même transaction. Toute ligne PENDING+PAID est exclue du rejet et routée en réconciliation TPE (§5), jamais auto-rejetée.

### 3. Finalisation atomique paiement→promotion (D7, D2)
Fusionner T1+T2 dans **une** `DB::transaction` (`KioskPaymentFinalizer`) : (1) `lockForUpdate` ; (2) idempotence : si PAID → no-op succès ; (3) `MoneyMutationService::capture()` crée LA Transaction `payment` (sign +, UNIQUE order_id) et pose payment_status=PAID ; (4) `apply(PENDING→ACCEPT)`. Un paiement carte crée donc toujours une Transaction (D2 disparaît) et l'état PENDING+PAID partiel n'existe plus (D1/D7).

### 4. Annulation atomique et idempotente (D3, D6, D8)
`KioskCancellation::cancel()` dans **une** transaction : (1) `lockForUpdate` + re-vérifier `status < seuil` sous verrou (tue le TOCTOU) ; (2) `MoneyMutationService::refund()` idempotent par **UNIQUE(order_id,type)** — 2e appel viole la contrainte, absorbé no-op ; balance dérivée du ledger, jamais `+=` ré-entrant ; (3) `LoyaltyLedger::reverse()` idempotent via `UNIQUE(user_id,order_id,'refund')` + `INSERT ... ON CONFLICT DO NOTHING`, increment conditionné au succès de l'insert, même transaction ; (4) `apply(CANCELED)`.

### 5. `MoneyMutationService` — frontière unique
Seul autorisé à écrire `transactions` et dériver `balance`. Invariants : `UNIQUE(transactions.order_id,type)` (pas de double cash_back) ; montant remboursé = somme signée du ledger, jamais recalculé depuis `order->total` ; écriture AuditLogService NF525 dans la même transaction que la mutation.

### 6. Réconciliation TPE
File dédiée : commandes PENDING+PAID ou PAID-sans-promotion reprises par un job qui rejoue la finalisation idempotente ou escalade `human`. Jamais de rejet silencieux d'un encaissement.

### Invariants portés structurellement
- Aucune commande PAID n'est jamais rejetée par un automate.
- Tout remboursement a exactement une contrepartie ledger (UNIQUE).
- Points débités ⇒ points restaurés à l'annulation/rejet (réversion idempotente).
- Décision de transition prise sous verrou, jamais sur lecture stale.


**Contrats de test :**
- Property : pour toute séquence d'annulations concurrentes d'une commande PAID, exactement une Transaction refund existe et balance crédité une seule fois (invariant UNIQUE(order_id,type)).
- Property : pour toute commande PENDING+PAID, CleanupStalePendingKioskOrders ne modifie ni status ni payment_status (seules UNPAID+stale rejetées).
- Cleanup d'une commande PENDING+UNPAID avec redeem>0 : après rejet, somme(redeem)+somme(reversal)=0 et loyalty_points restaurés au solde initial.
- Concurrence finalize↔cleanup sur la même ligne : résultat final = ACCEPT (le paiement gagne), jamais REJECTED ; garanti par re-lecture lockForUpdate dans apply().
- Idempotence finalize : N appels de confirmCardPayment produisent une seule Transaction payment, un seul PENDING→ACCEPT, un seul OrderCreated.
- Property : refundPoints appelé K fois crédite les points au plus une fois (ON CONFLICT DO NOTHING) sans exception non capturée.

**Migration/rollout :** Ordre : (1) `UNIQUE(transactions.order_id,type)` après dédup des doublons ; (2) router cashBack/payment/refund via MoneyMutationService en shadow ; (3) créer la Transaction `payment` à la finalisation carte + backfill des commandes carte PAID historiques ; (4) filtrer payment_status=UNPAID au cleanup + brancher la compensation fidélité (déployable seul, restrictif) ; (5) re-lecture lockForUpdate dans apply() ; (6) file de réconciliation TPE pour les PENDING+PAID legacy. Compat : payment_status 5/10 conservés, REFUNDED additif. Auditer les commandes déjà REJECTED-alors-que-PAID pour remboursement rétroactif avant activation.

---

### ⚙️ Fidélité (LoyaltyService, LoyaltyController, FrontendOrderService loyalty, kioskCart.js)
**Verdict : `a-refondre`**

**Carte mécanique :**

## Carte mécanique — Fidélité

### Entrées (routes/api.php)
- **PUBLIC** (throttle) : `register` (929), `opt-in` (975), `config` (931).
- **auth:sanctum** mais token machine kiosk (`kiosk:order`) accepté : `check` (928), `redeem` (935), `add-points` (934), `balance` (936), `history` (937), `scan` (982).

### État
- `users.loyalty_code`, `users.loyalty_points` (solde autoritatif, muté par increment/decrement bruts).
- `loyalty_transactions` (grand livre) ; UNIQUE `(user_id, order_id, type)` (migr. 2026_03_26_075919).
- `frontend_orders.loyalty_customer_code`, `discount`, `loyalty_points_awarded`.

### Deux chemins de déduction NON liés
1. **Pré-commande détachée** — `redeem()` (255-339) : transaction+lockForUpdate OK, decrement (302) + ledger `redeem` **order_id=null** (312). Aucune commande, aucune remise appliquée — juste un décrément.
2. **Inline** — `store()` (444-507) : gardé par `discount>0` (444), decrement sous lock (463-478) + ledger `redeem` order_id=$id (486). Pose `loyalty_customer_code` (518).

### Restauration
- `refundPoints($order)` (21-79) : cherche ledger `order_id=$id, type=redeem` (27), increment (56) + écrit `manual_add` order_id=$id (62). `lockForUpdate` **hors** transaction (40-45).
- Appelé seulement par `changeStatus()` (667), **hors transaction**, après `cashBack` (661).

### Lecture/exposition
- `check()` (57-109) : renvoie name+points+discount_value+loyalty_code ; **écrit** un loyalty_code si absent (81-84).
- `register()` (111-179) : phone existant → renvoie loyalty_code+points d'autrui (167) ; conflit email → existing_loyalty_code+existing_phone (135-138).
- `scan()` (575-671) : token opaque, seul chemin correctement scellé.

### Frontend (kioskCart.js)
- `buildKioskOrderPayload` (21-33) : envoie `loyalty_code` mais **PAS discount**.
- Getter `total` (89-92) soustrait `loyaltyDiscount` (affichage) ; `setLoyalty` posé par KioskLoyaltyComponent.vue:507.
- `RESET`/`kioskLogout` (202-245) : effacent l'état local, **aucune** restauration serveur.

### Appelants
POS/web→`redeem()` direct. Kiosk→`submitOrder`→`/frontend/order`→`store()`. Annulation client→`changeStatus`→`refundPoints`.


**Défauts au niveau mécanisme :**

| Sév | Défaut | Pourquoi le design l'autorise | Emplacement |
|:--:|---|---|---|
| 🔴 | redeem() sans ownership pour kiosk/staff : destruction arbitraire de points, order_id=null | Le contrôle d'appartenance (280) ne joue QUE si !isKiosk && !isStaff. Un token machine kiosk (partagé, exposé) satisfait isKiosk et décrémente le solde de N'IMPORTE QUEL loyalty_co | `app/Http/Controllers/Frontend/LoyaltyController.php:280` |
| 🔴 | register() public : oracle de PII et de solde d'un compte tiers | register() (public, throttle seul) fait where('phone') (123) ; compte existant → renvoie loyalty_code+points d'autrui (167). Branche EMAIL_EXISTS → existing_loyalty_code+existing_p | `app/Http/Controllers/Frontend/LoyaltyController.php:123` |
| 🔴 | Double déduction : redeem() détaché + déduction inline, UNIQUE(user,order,type) neutralisé | redeem() débite avec order_id=null (312) ; store() re-débite le même code avec order_id=$id (478). L'index UNIQUE traitant NULL comme distinct, les deux lignes redeem coexistent :  | `app/Services/FrontendOrderService.php:428` |
| 🔴 | refundPoints ne restaure que order_id=$id : tout redeem pré-commande (order_id=null) est p | refundPoints filtre where('order_id',$order->id) (27), or redeem() écrit toujours order_id=null (312). À l'annulation ces points ne sont jamais retrouvés ni recrédités : la déducti | `app/Services/LoyaltyService.php:27` |
| 🟠 | Remise fidélité affichée mais jamais appliquée (kiosk) | KioskLoyaltyComponent (507) appelle setLoyalty(discount) → getter total (89-92) soustrait loyaltyDiscount à l'écran. Mais buildKioskOrderPayload (25) n'envoie PAS discount. store() | `resources/js/store/modules/kioskCart.js:25` |
| 🟠 | changeStatus non atomique + lockForUpdate no-op dans refundPoints | changeStatus (635-701) n'a aucun DB::transaction : cashBack (661), refundPoints (667), save (670), recordTransition sont autonomes ; un échec de refund laisse un cashback émis sans | `app/Services/LoyaltyService.php:40` |
| 🟠 | check() : énumération PII+solde via token kiosk partagé + écriture parasite sur un lookup | check() sous auth:sanctum mais un token machine kiosk suffit ; renvoie name+points+discount_value pour tout code OU phone (72-95). Le porteur du token kiosk énumère les soldes clie | `app/Http/Controllers/Frontend/LoyaltyController.php:79` |
| 🟠 | Points non restaurés à l'abandon/logout du parcours kiosk | RESET (202-218) et kioskLogout (237-245) effacent loyaltyCustomer/loyaltyDiscount côté client sans appel serveur. Si une déduction pré-commande a eu lieu (redeem, order_id=null), l | `resources/js/store/modules/kioskCart.js:202` |

**🎯 Redesign cible :**

## Architecture cible — Fidélité

### Principe directeur
Un point n'est débité QUE lié transactionnellement à un `order_id` réel, par un principal qui possède le compte, dans une transaction unique et réversible. Aucune mutation de solde hors du grand livre.

### 1. LoyaltyLedger — point unique de mutation (façon MoneyMutationService)
`LoyaltyLedger::apply(userId, orderId, type, points, surface)` : ouvre `DB::transaction`, `lockForUpdate` sur `users`, applique increment/decrement ET insère la ligne ledger dans la MÊME transaction. `balance_after` relu depuis la valeur verrouillée, jamais du modèle pré-lecture. Supprimer tous les `decrement/increment` dispersés (302, 476, 56).

### 2. Déduction liée à un order_id (invariant de schéma)
- Retirer le décrément de `redeem()` : `redeem` devient une réservation non débitante (hold), ou fusionne dans le flux commande.
- La SEULE déduction vit dans `store()`, `order_id` non-null obligatoire.
- **UNIQUE (user_id, order_id, type) avec order_id NOT NULL** pour redeem/earn/refund → double déduction impossible au niveau schéma. Les ajustements manuels vont dans une table/discriminant séparé avec leur propre clé d'idempotence.

### 3. Restauration symétrique et idempotente
- `refundPoints` écrit `type='refund'` (pas manual_add) sous UNIQUE (user_id, order_id, 'refund') → rejeu = collision silencieuse, jamais double crédit ni 500.
- Le tout DANS la transaction de `changeStatus` : cashBack, refund, save, recordTransition atomiques ou rien.
- Toute déduction portant un order_id, toute déduction est réversible ; l'abandon kiosk n'a jamais débité (hold non consommé) donc rien à restaurer.

### 4. Ownership obligatoire + endpoints privés
- `check/balance/history` : exiger `caller->id === user->id` OU rôle staff explicite ; un token kiosk:order ne lit jamais un solde par code libre — il passe par `scan()` (token opaque, prénom+solde du porteur seul).
- `register/opt-in` : ne JAMAIS renvoyer les données d'un compte existant. Phone/email connu → réponse générique identique au cas inexistant. Suppression du payload EMAIL_EXISTS divulguant.
- `check()` : lecture pure, zéro écriture ; génération de loyalty_code confinée au seul `register` authentifié.

### 5. Remise = contrat serveur unique (SSOT)
Le frontend n'envoie jamais de montant ; il envoie `loyalty_code` + intention `redeem:true`. Le serveur calcule points→€, applique, débite et renvoie le total autoritatif. Le kiosk n'affiche que le total confirmé serveur (retirer la soustraction locale de `loyaltyDiscount` ou la marquer « estimation »). Conversion `pointsToDiscount` partagée dans un service, pas dupliquée (720 vs store).

### 6. Principal fidélité authentifié serveur
Le client fidélité est un principal distinct du kiosk : résolu via `scan()`→token opaque réinjecté dans `store()`. La déduction s'attribue au porteur du token, pas à un loyalty_code libre du payload.

### Contrats portés par le schéma
`apply()` idempotent par (user_id,order_id,type), atomique, verrou effectif ; `refundPoints()` idempotent et réversibilité totale. Invariants dans NOT NULL+UNIQUE, pas dans des commentaires d'avertissement.


**Contrats de test :**
- Property : pour toute séquence redeem→order→cancel, solde_final == solde_initial (réversibilité totale, aucun point perdu).
- Invariant double-déduction : redeem() puis store() avec même code sur une commande ne débite qu'une fois (somme ledger par order_id == points réellement décomptés).
- Ownership : un token kiosk:order appelant check/balance/redeem sur un loyalty_code non possédé renvoie 403, jamais name/points.
- Non-divulgation : register/opt-in sur phone/email existant ne renvoie NI loyalty_code, NI phone, NI points d'un tiers (réponse identique existant/inexistant).
- Atomicité changeStatus : injection d'échec sur refundPoints → cashBack ET transition ET ledger tous annulés (aucun effet partiel).
- Concurrence : deux redeem/order simultanés sur un compte à 1 unité → exactement un succès, un échec insuffisant, jamais solde négatif (property-based, verrou in-transaction).

**Migration/rollout :** Ordre : (1) Ajouter type='refund', backfiller les 'manual_add' d'annulation en 'refund'. (2) Backfill : lier les redeem order_id=null aux commandes via loyalty_customer_code+timestamp si résolvable ; sinon geler+auditer (crédit manuel). (3) Poser UNIQUE (user_id,order_id,type) order_id NOT NULL SEULEMENT après nettoyage des null (sinon échec) ; sortir d'abord les ajustements manuels dans une clé dédiée. (4) Serveur en double-écriture une release, puis basculer les lectures. (5) Frontend : retirer l'affichage remise locale AVANT de retirer le gate discount>0. Compat : route register publique conservée, payload durci (contrat HTTP inchangé).

---

### ⚙️ Pricing SSOT / remises / coupons / taxes (PricingService, DiscountCalculator, CouponService, PosOrderRequest, TableOrderRequest, OrderRequest)
**Verdict : `a-refondre`**

**Carte mécanique :**

## Carte mécanique

**Entrée / orchestration**
- `PricingService::calculateOrder(PricingRequest, CouponService)` — SSOT prix (PricingService.php:25). Appelé depuis `OrderService` web:313, POS:605, table:1003, et `FrontendOrderService` kiosk:211.
- `PricingRequest` (factory `forWeb/forPos/forTable/forKiosk`) : porte `branchId`, `context`, `couponId`, `couponCustomerUserId`, `manualDiscountRequest`, `deliveryCharge`, flags d'arrondi (immuable).

**Flux de données par ligne** (PricingService.php:73-194)
- Prix item/variation/extra TOUJOURS relus DB (`Item::price`, `ItemVariation::price`, `ItemExtra::price`), payload ignoré. Garde cross-item `enforceCrossItemGuards` (98,122).
- `taxCalculator->lineTaxAmount()` calcule la TVA **par ligne sur le HT ligne** (147), avant toute remise (TaxCalculator.php:8).
- `realSubtotal`, `totalTax` accumulés.

**Remise** (PricingService.php:200-218)
- `subtotalForDiscount` = subtotal serveur.
- coupon → `DiscountCalculator::couponDiscount` → `CouponService::resolveCouponById` + `calculateDiscountAmount` (CouponService.php:248,268).
- sinon si `manualDiscountRequest>0` ET context ∈ {pos,table} → `DiscountCalculator::manualDiscount(requested, subtotal)` : rend `requested` si `≤ subtotal`, sinon 0 (DiscountCalculator.php:22).

**Total** (PricingService.php:220-222)
- `rawTotal = realSubtotal + totalTax + deliveryCharge − discount`; `finalTotal = max(0, …)`.

**Frontière de transaction**
- Tout dans `DB::transaction` d'`OrderService` (POS 563, table 982). `unset(total,subtotal,discount)` mais **PAS `delivery_charge`** (569,984) → delivery client persiste dans `Order::create` puis relu `$this->order->delivery_charge` et réinjecté dans pricing (613,1011).
- Persistance résultats 813-823 / 1196-1207.
- `OrderCoupon::create` APRÈS le comptage de limite, sans verrou (868,1217).

**Autorisation remise**
- UNIQUEMENT dans `PosOrderRequest::withValidator` (PosOrderRequest.php:129-158), couche HTTP, calculée sur `request('subtotal')` **client** (130,143).
- `TableOrderRequest` : `discount` nullable numeric, **aucun gate** (TableOrderRequest.php:36).
- `Coupon` : pas de colonne `branch_id` (Coupon.php:15).


**Défauts au niveau mécanisme :**

| Sév | Défaut | Pourquoi le design l'autorise | Emplacement |
|:--:|---|---|---|
| 🔴 | Gate d'autorisation de remise POS calculé sur le subtotal CLIENT et AVANT recalcul serveur | PosOrderRequest.php:143 : pct = discount/request('subtotal')*100 ; le palier (cashier 10% / manager 50%) repose sur un subtotal client. Gonfler subtotal rend pct minuscule → passe  | `app/Http/Requests/PosOrderRequest.php:143` |
| 🔴 | Commande table (QR client) : remise arbitraire appliquée sans aucune autorisation | TableOrderRequest.php:36 déclare discount nullable numeric sans gate ni motif. OrderService:1010 passe request->discount à forTable, et PricingService:213 applique manualDiscount p | `app/Http/Requests/TableOrderRequest.php:36` |
| 🔴 | delivery_charge dicté par le client, jamais recalculé, valeur négative acceptée → remise d | delivery_charge n'est pas unset (OrderService:569,984), persiste via Order::create puis relu et injecté tel quel dans le total (PricingService:220-221). Aucune validation min:0 (Po | `app/Services/Pricing/PricingService.php:220` |
| 🟠 | Coupon limit_per_user : TOCTOU sans verrou ni contrainte unique | validateCouponForOrder compte OrderCoupon (CouponService.php:310) sans lockForUpdate ; OrderCoupon::create survient bien plus tard (OrderService:868/1217). Sous REPEATABLE READ, de | `app/Services/CouponService.php:310` |
| 🟠 | Coupons globaux : absence de branch_id → fuite d'usage cross-branche | Le modèle Coupon n'a pas de colonne branch_id (Coupon.php:15-26) et resolveCouponById fait un Coupon::find sans filtre de branche (CouponService.php:250). Un coupon destiné à la br | `app/Services/CouponService.php:250` |
| 🟠 | Assiette fiscale figée avant remise : TVA par ligne jamais réallouée → incohérence NF525 | lineTaxAmount calcule la TVA sur le HT ligne brut (PricingService:147, TaxCalculator:8) puis la remise est soustraite au niveau ordre (PricingService:221) sans réallocation aux lig | `app/Services/Pricing/TaxCalculator.php:8` |
| 🟠 | Autorisation logée dans la couche HTTP (FormRequest), non dans le domaine pricing | Le seul contrôle de palier vit dans PosOrderRequest::withValidator (PosOrderRequest:129). DiscountCalculator applique un montant sans acteur ni permission (DiscountCalculator:22).  | `app/Services/Pricing/DiscountCalculator.php:22` |
| 🟡 | Taxe FIXED non multipliée par la quantité et delivery ajouté quel que soit l'order_type | lineTaxAmount pour TaxType::FIXED renvoie le taux brut sans *quantity (TaxCalculator:8-10) → sous-taxation des lignes qty>1. Par ailleurs PricingService:220 additionne deliveryChar | `app/Services/Pricing/TaxCalculator.php:9` |

**🎯 Redesign cible :**

## Architecture cible

### 1. Un seul pipeline, une seule assiette, dans le domaine
`PricingService::price(PricedCart, PricingContext): PricedResult` reste le SSOT MAIS devient la seule autorité pour **prix, delivery, remise, taxe ET autorisation**. Aucune décision financière ne subsiste dans les FormRequest — ceux-ci ne font QUE de la validation de forme (types, présence). Supprimer le calcul de pct/palier de PosOrderRequest.

### 2. Ordre canonique du calcul (invariant structurel)
1. Lignes DB (déjà correct) → `subtotalHT`.
2. `delivery = DeliveryPricer::quote(orderType, branchId, addressId)` : **calculé serveur** depuis zone/branche, jamais lu du payload. `delivery=0` si order_type ≠ DELIVERY. Toujours ≥ 0 (VO `Money` non signé).
3. `discount = DiscountResolver::resolve(...)` (voir §3), **plafonné ≤ subtotalHT**.
4. Réallouer la remise au prorata des lignes AVANT taxe : `lineNet = lineHT * (1 − discount/subtotalHT)`.
5. `lineTax = TaxCalculator(lineNet, …)` ; FIXED = taux * quantité. `totalTax = Σ lineTax`.
6. `total = Σ lineNet + totalTax + delivery`.
Ainsi l'assiette fiscale reflète le net encaissé (NF525) et `Σ tax lignes == TVA(total)`.

### 3. Gate d'autorisation APRÈS recalcul, sur base serveur
`DiscountAuthorizer::authorize(Actor $actor, Money $requested, Money $serverSubtotal, string $context): AuthorizedDiscount`.
- Reçoit le **subtotal recalculé serveur** (jamais le client).
- `pct = requested / serverSubtotal`. Paliers portés par capabilities de `$actor` (principal authentifié serveur, pas `request()`).
- context `table`/`kiosk`/`web` client → capability « self-service » : `requested` DOIT être 0 sauf coupon validé. Une remise manuelle sur ces surfaces lève `UnauthorizedDiscountException`.
- Motif obligatoire (≥3) porté dans l'objet, pas dans le FormRequest.
- PricingService n'applique jamais une remise non passée par l'Authorizer.

### 4. Coupon : scope + verrou
- Ajouter `coupons.branch_id` (nullable = global explicite). `resolveCoupon(code|id, branchId, …)` filtre `where branch_id in (null, $branchId)`.
- Contrainte UNIQUE `(coupon_id, user_id, order_id)` + index `(coupon_id, user_id)`.
- `redeemCoupon()` : `SELECT … FOR UPDATE` sur une ligne compteur (`coupon_redemptions`) ou insertion sentinelle capturant la violation d'unicité → `limit_per_user` sérialisé, TOCTOU éliminé. Validation ET écriture dans la même transaction, verrou tenu jusqu'au commit.

### 5. Contrats
- `Money` VO immuable non signé (rejette < 0 à la construction) pour delivery/discount/total.
- `PricingResult` inclut `discountAllocationPerLine[]` pour tracer l'assiette fiscale.
- `Actor` = principal serveur (Auth::user()) injecté, jamais `request()->user` implicite.
- Redemption coupon liée à `order_id` unique (idempotence).

### 6. Frontière
FormRequests → forme uniquement. OrderService → transaction + persistance. PricingService → tout le métier monétaire + Authorizer + DeliveryPricer + CouponRedeemer sous le même verrou.


**Contrats de test :**
- Property: pour tout subtotal client S_c et discount D, le palier appliqué dépend UNIQUEMENT du subtotal serveur S_s ; varier S_c ne change jamais le résultat d'autorisation (anti-bypass PosOrderRequest:143).
- Invariant table/kiosk/web self-service : toute request avec discount>0 sans coupon valide lève UnauthorizedDiscountException ; discount persisté = 0 (couvre TableOrderRequest:36).
- Property: delivery_charge du payload est ignoré ; total identique que le client envoie 0, 999 ou -50 ; delivery = DeliveryPricer::quote et ≥ 0 ; =0 si order_type != DELIVERY.
- Invariant fiscal (NF525) : Σ(tax_amount des lignes) == TaxCalculator(total_net) à 0,01 près, y compris sur commande remisée ; remise réallouée au prorata.
- TOCTOU coupon : N requêtes concurrentes même user/coupon avec limit_per_user=1 → exactement 1 OrderCoupon créé, N-1 rejetées ; assert via contrainte unique.
- Isolation branche : coupon branch_id=A résolu sur branchId=B → CouponNotFound ; coupon global (branch_id null) accepté partout. Taxe FIXED qty=3 → tax = taux*3.

**Migration/rollout :** Ordre : 1) coupons.branch_id (nullable, backfill NULL = global, comportement préservé) ; table coupon_redemptions + UNIQUE(coupon_id,user_id,order_id) avant d'activer le verrou. 2) DeliveryPricer derrière flag server_delivery : shadow (loguer écart client vs serveur) puis enforce. 3) Gate vers DiscountAuthorizer : ancien FormRequest + nouveau en warn-only, puis bascule serveur, retrait du calcul PosOrderRequest. 4) Réallocation fiscale : snapshot tax_basis sur nouveaux OrderItem seulement, sans réécrire l'historique. Compat : supprimer les branches legacy non-SSOT (divergentes). Données : auditer les Order delivery_charge<0 avant enforce.

---

### ⚙️ Passerelles de paiement & flux argent (Stripe / PayPal / Credit + capture/confirm/refund)
**Verdict : `a-refondre`**

**Carte mécanique :**

## Carte mecanique

### Routage
- `PaymentController::payment` (PaymentController.php:56) resout la gateway par slug client, instancie `PaymentRequests\{Slug}` (:59), delegue.
- `PaymentManagerService::gateway` (PaymentManagerService.php:11) : `new Gateways\{ucfirst(slug)}()` — dispatch dynamique sur chaine controlee par requete.
- `success/fail/cancel` (PaymentController.php:71-84) re-instancient par slug.
- Flux borne independant : `OrderController::paymentConfirm` (OrderController.php:77), route POST payment-confirm (api.php:852).

### Contrat
- `PaymentAbstract` : status/payment/success/fail/cancel. Chaque gateway cree son `PaymentService` en dur (Stripe.php:24, Credit.php:21, Paypal.php:26).

### Stripe
- `payment()` (Stripe.php:34) : `charges->create(['amount'=>(int)$order->total*100 ...])` (:47), stocke balance_transaction dans capture_payment_notifications (:59), redirige.
- `success()` (:91) : relit le token local, verifie order->id==token->order_id, appelle paymentService->payment (:101). Aucune re-verif Stripe.

### PayPal
- `payment()` createOrder value=(float)total (Paypal.php:85). `success()` capturePaymentOrder (:130), si COMPLETED -> payment (:134). Montant capture jamais compare a total.

### Credit
- `payment()` garde balance>=total (Credit.php:28), token=rand() (:33). `success()` : balance=balance-total (:79) sans re-verif ni verrou, puis payment (:81).

### Noyau
- `PaymentService::payment` (PaymentService.php:13) : cree Transaction si absente (:16-25) mais met TOUJOURS PAID+save (:26-27).
- `PaymentService::cashBack` (:31) = SEUL refund : garde if(transaction) (:34), cree ligne '-' (:35), credite balance+=total (:46). Appelants passent slug 'credit' en dur (OrderService.php:1437,1488 ; FrontendOrderService.php:661).

### Etats/modeles
- PaymentStatus PAID=5/UNPAID=10. Transaction relation `transaction()` HasOne (Order.php:189) alors que payment+cash_back coexistent. Migration transactions : aucun index unique order_id. users.balance decimal signe (peut aller negatif).

### Frontieres tx
- Charge Stripe HORS DB::transaction, liee au local uniquement via capture_payment_notifications. Credit success() sans lockForUpdate. Aucun webhook Stripe/PayPal, aucun appel refund PSP (grep=0).


**Défauts au niveau mécanisme :**

| Sév | Défaut | Pourquoi le design l'autorise | Emplacement |
|:--:|---|---|---|
| 🔴 | Troncature du montant Stripe : (int) applique au total avant *100 | Stripe.php:47 `(int) $order->total * 100`. Le cast lie plus fort que * : 12.90 -> (int)12.90=12 -> 1200 cents = 12.00. La partie fractionnaire est jetee, sous-facturation systemati | `app/Http/PaymentGateways/Gateways/Stripe.php:47` |
| 🔴 | paymentConfirm declare PAID sans verification PSP ni Transaction | paymentConfirm fixe PAID (OrderController.php:111) sur la seule foi d'un transaction_id string client, sans appel PSP et sans creer de ligne transactions. Tout porteur du token San | `app/Http/Controllers/Frontend/OrderController.php:111` |
| 🔴 | Credit::success deduit le solde sans re-verifier ni verrouiller -> solde negatif | La garde solde>=total est dans payment() (Credit.php:28) mais success() fait balance=balance-total (Credit.php:79) sans re-controle ni lockForUpdate. Deux success() concurrents ou  | `app/Http/PaymentGateways/Gateways/Credit.php:79` |
| 🔴 | Le remboursement n'est jamais un refund PSP : cashBack ne fait que du credit magasin | cashBack credite user->balance+=order->total (PaymentService.php:46) quel que soit le moyen d'origine, et les appelants passent slug 'credit' en dur (OrderService.php:1437,1488). A | `app/Services/PaymentService.php:46` |
| 🟠 | Remboursement impossible sans Transaction (commandes carte borne) | Les chemins d'annulation sont gardes par if(order->transaction) (OrderService.php:1436, FrontendOrderService.php:660) et cashBack par if(transaction) (PaymentService.php:34). Or pa | `app/Services/PaymentService.php:34` |
| 🟠 | cashBack sans idempotence : credit potentiellement repete | cashBack ne verifie aucun cash_back anterieur ; il relit la transaction de paiement (HasOne->first, toujours presente) et cree un '-' + credite le solde a chaque appel (PaymentServ | `app/Services/PaymentService.php:34` |
| 🟠 | Charge Stripe sans idempotency_key, decouplee de l'etat local | charges->create (Stripe.php:46) n'a pas d'idempotency_key : un rejeu reseau recree une charge -> double debit. La charge est hors DB::transaction, reliee au local uniquement via ca | `app/Http/PaymentGateways/Gateways/Stripe.php:46` |
| 🟠 | Aucune unicite/ledger : amount enregistre = order->total, pas le montant PSP capture | La migration transactions n'a aucun index unique order_id ; transaction() est HasOne mais plusieurs lignes existent, first() renvoie une ligne arbitraire. PaymentService.payment en | `database/migrations/2023_03_23_143747_create_transactions_table.php:16` |

**🎯 Redesign cible :**

## Architecture cible

### 1. Le PSP fait foi sur le montant, pas order->total
Aucun PAID sans preuve serveur d'encaissement (charge/capture verifiee cote PSP) enregistree au ledger. `order->total` n'initie qu'une intention ; le montant qui fait foi est celui renvoye par le PSP, en plus petite unite via un value-object `Money::fromMajor($total,$cur)->minor()` — jamais `(int)$x*100`.

### 2. Ledger immuable + MoneyMutationService
Remplacer `transactions` par un journal append-only : `id, order_id, psp, psp_ref, operation(charge|capture|refund|store_credit), amount_minor BIGINT, currency, direction(debit|credit), idempotency_key, status(pending|succeeded|failed)`. Index **UNIQUE(idempotency_key)** et **UNIQUE(psp,psp_ref,operation)** : l'unicite d'un mouvement est portee par la DB. Toute ecriture passe par `MoneyMutationService::record(intent)` exigeant une idempotency_key ; un doublon -> retour idempotent. PAID est **derive** : `sum(capture succeeded) >= amount_due_minor`, pas un flag mutable.

### 3. Contrat de gateway refondu (DTO, pas de redirect dans le domaine)
`authorizeAndCapture(ctx): CaptureResult` avec idempotency_key=hash(order,attempt) ; `verify(pspRef): {status,amount_minor,currency}` qui **re-interroge le PSP** et est la SEULE porte vers PAID ; `refund(intent): RefundResult` appelant reellement `Stripe\Refund::create` / PayPal refundCapturedPayment.

### 4. Confirm unifie (borne incluse)
`ConfirmPaymentAction` : (1) verify(pspRef) succeeded ; (2) assert amount_minor==amount_due_minor ET currency identique sinon rejet+alerte ; (3) record(capture) dans DB::transaction avec Order::lockForUpdate ; (4) PAID derive + scellement `sealed_at` bloquant toute mutation du montant. La borne emprunte ce chemin : le transaction_id TPE devient un pspRef verifie, jamais un flag de confiance.

### 5. Credit atomique verrouille
CreditGateway::capture dans une DB::transaction unique : SELECT FOR UPDATE user, re-verif balance_minor>=du, decrement, mouvement store_credit debit. Contrainte DB `CHECK(balance_minor>=0)` + colonne unsigned BIGINT minor. Token cryptographique, plus rand().

### 6. Refund fidele au moyen d'origine
`RefundOrderAction` lit le mouvement capture d'origine (psp+psp_ref) et rembourse sur **le meme PSP** (Stripe->Stripe, PayPal->PayPal, Credit->recredit solde). idempotency_key=hash(order,capture_ref,'refund'). Le credit magasin devient un cas particulier (origine=credit), plus le defaut universel.

### 7. Atomicite via verify-first + webhook signe
La charge ne depend plus du navigateur : retour utilisateur ET webhook signe (`Stripe::constructEvent`, verify PayPal) convergent sur verify()+record idempotent grace a UNIQUE(psp,psp_ref,operation). Plus d'argent encaisse sans trace.

### Invariants structurels
PAID <=> capture succeeded couvrant le du (derive). 1 mouvement PSP = 1 ligne (UNIQUE). amount_minor = montant PSP (jamais recalcule). balance_minor>=0 (CHECK). Tout capture remboursable (psp_ref present).


**Contrats de test :**
- Property : pour tout total t, amount_minor charge == round(t*100) exactement ; 12.90 -> 1290 et jamais 1200 (non-regression Stripe.php:47).
- Invariant PAID : promotion PAID impossible sans mouvement capture succeeded couvrant le du ; paymentConfirm avec pspRef non verifiable laisse UNPAID.
- Idempotence charge : deux appels capture meme idempotency_key -> exactement 1 mouvement PSP et 1 debit.
- Property concurrentielle Credit : N success() concurrents sur solde S ne debitent jamais > S ; balance_minor final >= 0 pour tout entrelacement (lockForUpdate + CHECK DB).
- Refund fidele : commande payee Stripe -> appel Stripe Refund (mock) du montant capture, PAS de credit user->balance ; symetrique PayPal.
- Refund commande borne carte : mouvement capture present -> refund possible et idempotent ; aucun ordre structurellement non-remboursable.

**Migration/rollout :** Ordre : (1) creer `payment_ledger` a cote de transactions (double-ecriture), UNIQUE(idempotency_key)+UNIQUE(psp,psp_ref,operation) ; backfill amount_minor=round(amount*100), psp_ref=token. (2) Corriger la troncature Stripe (fix isole deployable seul). (3) MoneyMutationService+verify() sous feature flag ; router paymentConfirm et success() dessus. (4) balance -> BIGINT minor + CHECK>=0 apres detection des soldes negatifs. (5) PAID derive du ledger + sealed_at + garde Order::updating. (6) cashBack remplace par RefundOrderAction par-PSP. Risque : commandes PAID sans Transaction (borne) marquees needs_reconciliation, pas de mouvement fictif.

---

### ⚙️ Isolation de branche & autorisation (BranchScope, DefaultAccessModelTrait, AuthServiceProvider, Kernel, routes/api.php admin group)
**Verdict : `a-refondre`**

**Carte mécanique :**

## Carte mecanique

### Points d'entree (portee)
- `routes/api.php:229` groupe `/api/admin/*` (~470 routes : branch, administrator, tax, currency, dashboard, sales-report, pos, orders...) protege par `['installed','apiKey','auth:sanctum','localization','throttle:admin-mutation']` — **aucun `role:`/`permission:`/`can:` au niveau du groupe**.
- `routes/api.php:809` `/api/frontend/*` et `:992` `/api/table/*` : `apiKey` seul (surfaces invitees).
- `app/Http/Kernel.php:48-56` groupe `api` : throttle + bindings + json, **pas d'auth ni de scope global**. `auth:sanctum` est opt-in par route.

### Resolution du principal & abilities
- `LoginController.php:76` et `RefreshTokenController.php:23` emettent des tokens `['*']` ; `GuestSignupController.php:140` idem `['*']` ; `KioskMachineLoginController.php:83` `['kiosk:order']`.
- Abilities **jamais imposees par middleware** ; `tokenCan()` verifie ad hoc dans ~4 controleurs Frontend seulement. `['*']` fait passer tout `tokenCan`.
- Autorisation reelle quasi nulle : `PosOrderController.php:41` (`can('pos-orders')`), `Fiscal/XReportController.php:25`, `Fiscal/ZReportController.php:94`. Le reste du groupe admin n'appelle jamais `authorize`/`can`.

### Etats de branche (sentinelle)
- `create_users_table.php:28` : `branch_id nullable default 0`. `0`/NULL = sentinelle « transverse ».
- `DefaultAccessModelTrait::branch()` (`:13`) : lit `default_access`; sinon `(int)Auth::user()->branch_id === 0` → renvoie `0` (admin), NULL casse a 0 → admin.
- `BranchScope::apply()` (`:17`) : exempte `User` (`:22`) ; bypass si `runningInConsole()` (`:29`) ; si `branch()===0` → **aucun filtre** (`:40`) ; sinon `where branch_id = userBranch` (`:44`). Porte via `User::booted` addGlobalScope (`User.php:90`).

### Flux / frontieres
- Scope applique aux SELECT/UPDATE/DELETE et au route-model-binding — **pas aux INSERT** ; l'ecriture passe par `setBranch()` (`:28`, logique conditionnelle opaque).
- `Gate::before` (`AuthServiceProvider.php:34`) : role `admin` → toutes abilities `true` (court-circuite toute policy).
- Chaine critique : self-signup (`SignupController.php:92` branch_id=0, `:97` role CUSTOMER, **aucun DefaultAccess**) → `branch()`=0 → BranchScope ne filtre pas + token `['*']` → acces `/api/admin/*` non-gate.


**Défauts au niveau mécanisme :**

| Sév | Défaut | Pourquoi le design l'autorise | Emplacement |
|:--:|---|---|---|
| 🔴 | Le groupe /api/admin n'impose aucune capacite : auth:sanctum = seule barriere | Le design delegue l'autorisation aux controleurs, mais ~466/470 routes admin n'appellent jamais authorize/can. Le groupe (routes/api.php:229) n'a ni role: ni permission:. Tout port | `routes/api.php:229` |
| 🔴 | branch_id==0/NULL comme sentinelle admin transforme tout compte a branche nulle en super-l | branch() fait (int)branch_id===0 → 0 → BranchScope::apply retourne sans filtre. NULL caste a 0. Un client self-signup (branch_id=0, sans DefaultAccess) devient transverse : il lit  | `app/Models/Scopes/BranchScope.php:40` |
| 🔴 | Chaine self-signup → visibilite cross-branch: SignupController cree branch_id=0 sans Defau | SignupController ne cree pas de ligne default_access (contrairement a GuestSignup). Le compte reste branch_id=0 → branch()===0 → aucun filtre BranchScope, combine a un token ['*']. | `app/Http/Controllers/Auth/SignupController.php:92` |
| 🟠 | Tokens ['*'] neutralisent le modele de capacites Sanctum | Login/Refresh/GuestSignup emettent ['*']. Aucune ability n'est imposee au niveau route ; tokenCan('kiosk:order') n'est teste que dans quelques controleurs Frontend et renvoie toujo | `app/Http/Controllers/Auth/LoginController.php:76` |
| 🟠 | Bypass console: queues/artisan/rapports desactivent l'isolation de branche | apply() retourne tot si runningInConsole() (hors runningUnitTests). Tout job de queue ou commande planifiee interroge Order/Transaction sans filtre de branche ; un job qui reexpose | `app/Models/Scopes/BranchScope.php:29` |
| 🟡 | Gate::before(admin) court-circuite toute policy future | Gate::before renvoie true pour le role admin avant toute policy. Toute regle d'autorisation ajoutee (y compris isolation de branche cote policy) sera ignoree pour admin, empechant  | `app/Providers/AuthServiceProvider.php:34` |
| 🟡 | setBranch: affectation de branche a l'ecriture non deterministe | setBranch melange comparaisons '0' string/int, valeurs vides et fallback Settings sans invariant clair ; le scope ne couvrant pas les INSERT, un branch_id incoherent peut etre pers | `app/Traits/DefaultAccessModelTrait.php:28` |
| 🟡 | Isolation portee par un trait duplique (BranchScope + DefaultAccessModelTrait) => derive | La regle 'branch()===0' est repliquee dans deux fichiers avec commentaires 'Mirror'. Toute correction dans l'un sans l'autre reouvre la faille ; l'invariant n'a pas de source uniqu | `app/Traits/DefaultAccessModelTrait.php:13` |

**🎯 Redesign cible :**

## Architecture cible : deny-by-default porte par le Kernel

### 1. Posture inversee (Kernel, pas route)
- Groupe racine `api.protected` = `['installed','auth:sanctum','abilities.enforce','branch.context','localization']` : **auth+scope deviennent la racine** de tout ce qui n'est pas explicitement public.
- Allowlist courte de routes publiques (`/health`, `/auth/login`, `/auth/kiosk-login`, `/frontend/menu`...) taggees `api.public`. Le reste herite de la protection : un nouveau controleur est protege **par defaut**, pas par oubli.
- Chaque sous-groupe `/api/admin/<domaine>` recoit `permission:<domaine>` (ex `admin/branch` → `permission:branch-manage`). Plus aucune route admin sans capacite verifiable statiquement.

### 2. Principal a capacites explicites
- `abilities.enforce` mappe route→ability et appelle `tokenCan`. Fin des `['*']` : Login emet les abilities derivees des permissions Spatie du role ; Kiosk garde `['kiosk:order']`. `['*']` interdit en prod (garde au boot).
- Capacite explicite `User::canSeeAllBranches(): bool = hasPermissionTo('branches.view-all')` — **jamais** derivee d'une valeur de `branch_id`.

### 3. branch_id NOT NULL, suppression de la sentinelle 0
- Migration : `branch_id NOT NULL` + FK `branches.id`, valeur magique 0 supprimee. Le siege porte la permission `branches.view-all` et un branch_id reel (ou table `user_branch_access` pour multi-branche).
- `BranchScope::apply` : `!Auth::check()` → **deny** (`whereRaw('1=0')`) ; `canSeeAllBranches()` → pas de filtre ; sinon `where branch_id IN (branches autorisees)`. Decision portee par une **capacite**.

### 4. Source unique d'isolation
- Un seul `BranchContext` (service par requete, injecte) : `currentBranchIds():int[]`, `canSeeAll():bool`. BranchScope et writes le consomment ; `DefaultAccessModelTrait` disparait. Zero duplication « Mirror ».

### 5. Ecriture scellee
- Observer `BranchStamping` : force `branch_id` a une branche inscriptible et **rejette** un branch_id non autorise (guard `creating`/`updating`). Ecriture cross-branche impossible sans capacite, meme hors HTTP.

### 6. Chemins console
- Remplacer le bypass `runningInConsole()` par un **contexte de branche explicite par job** : chaque job serialise son `branch_id` et pousse un `BranchContext` scelle. Pas de contexte → deny (`1=0`), pas « tout voir ». Rapports transverses = `BranchContext::allBranches()` explicite + audit.

### 7. Retrait de Gate::before permissif
- Supprimer `Gate::before(admin)=true` ; accorder au role admin toutes les permissions via seed Spatie. Les policies (dont futures policies de branche) redeviennent effectives et auditables.

### Invariants portes structurellement
(a) toute requete authentifiee bornee aux branches autorisees ; (b) aucune route admin sans permission mappee ; (c) aucun token `['*']` en prod ; (d) `branch_id NOT NULL` ; (e) source unique BranchContext.


**Contrats de test :**
- Property: pour tout compte C sans branches.view-all et toute branche B != branche(C), un SELECT/route-binding sur un enregistrement de B renvoie 404/vide (jamais la ligne).
- Contrat routes: enumerer /api/admin/* et asserter que chaque route porte une permission mappee (echec si une route admin n'a ni permission: ni can:).
- Auth: un token client self-signup (POST /api/signup/register) recoit 403 sur /api/admin/branch, /administrator, /sales-report.
- Sentinelle: aucun User ne peut etre persiste avec branch_id NULL ou 0 apres migration (NOT NULL + absence de branche 0).
- Console/queue: un job sans BranchContext interrogeant Order retourne 0 ligne (deny); avec BranchContext(B) ne retourne que les Order de B.
- Abilities: un token kiosk ['kiosk:order'] echoue (403) sur toute route staff:*/admin:*; property: tokenCan ne renvoie jamais true pour une ability non listee (['*'] interdit en prod).

**Migration/rollout :** Ordre: (1) Seed permissions Spatie, Gate::before conserve. (2) BranchContext + canSeeAllBranches(); BranchScope lit la capacite, fallback branch_id===0 en double-lecture (log divergences). (3) Backfill users branch_id=0/NULL vers branche+permission (siege) ou leur branche; PUIS branch_id NOT NULL + FK — non-reversible, snapshot avant, fenetre planifiee. (4) Tokens abilities par role, retrait ['*'] (compat anciens jusqu'a expiration ~8h). (5) permission: sur sous-groupes admin + abilities.enforce en log-only puis enforce. (6) Contexte job remplace bypass console. (7) Retirer Gate::before permissif et DefaultAccessModelTrait. Feature-flaggable sauf (3).

---

### ⚙️ Auth machine borne & tokens (KioskMachineLoginController, EnsureKioskMachineCommand, KioskMachineTableSeeder, config/kiosk.php, ApiKeyMiddleware)
**Verdict : `a-refondre`**

**Carte mécanique :**

## Carte mécanique — identité machine borne

### Entrée & frontières
- **Route** `POST /auth/kiosk-login` — `routes/api.php:140`, groupe `['installed','apiKey','localization']` + `throttle:login-lockout`. Publique (pas d'`auth:sanctum`).
- **Middleware `apiKey`** — `app/Http/Kernel.php:72` → `ApiKeyMiddleware`. Compare `x-api-key` à `config('app.api_key')` (`config/app.php:63`, sourcé de `MIX_API_KEY`). `ApiKeyMiddleware:24` : comparaison `===`, échec → HTTP 400.

### Flux de login — `KioskMachineLoginController::login`
1. Valide `username`/`password` (`:30-39`), rejette email (`:42`).
2. `KioskMachine::where('username',…)->first()` (`:48`).
3. Gardes : `status==ACTIVE` (`:57`), user lié `ACTIVE` (`:63-68`), `Hash::check(password, $kioskMachine->password)` (`:70`).
4. **Transaction** (`:76-89`) : `lockForUpdate` sur la borne, `$user = User::find($lockedKiosk->user_id)`, **révoque TOUS** `$user->tokens()->where('name','kiosk-token')->delete()` (`:81`), `createToken('kiosk-token', ['kiosk:order'], expiration)` (`:83-87`), `is_login=YES` (`:88`).
5. Retourne `token` en clair (`:93`).

### État persistant
- Table `kiosk_machines` (`KioskMachine.php:12`) : `user_id, branch_id, machine_id, username, password(hash), is_login, status`. Aucune colonne `token_id`/`device_secret`/`last_seen`.
- **Toutes les bornes pointent `user_id=1`** (admin) : seeder `:33-42`, `:45-70` (demo), commande `:53` (défaut `find(1)`).

### Identité résolue
- Le principal authentifié est **l'admin id=1**, pas la borne. Le token porte `kiosk:order` mais Sanctum ne l'impose nulle part côté route.

### Enforcement de l'ability (unique point)
- `routes/channels.php:27` : `tokenCan('kiosk:order')` restreint `branch.{id}` à la branche de la borne. **Seul** lecteur de l'ability.
- `POST /frontend/order` (`api.php:846-849`), `admin/*` (`:229`), `frontend/menu` (`:955`) → **`auth:sanctum` seul, aucune `ability:`**. Commentaires « Auth: kiosk:order ability » (`:941`,`:980`) faux.

### Logout — `:98-116`
- Reset `is_login=NO` sur **toutes** les bornes du user (`:103-106`), supprime le token courant (`:107-110`).

### Provisioning
- `EnsureKioskMachineCommand` : défaut `--password=kiosk123` (`:25`), `--user-id` défaut id=1 (`:26,:53`), imprime le mot de passe (`:108-109`).
- `config/kiosk.php` : `spa_payload={username,password}` (`:68-71`) **injecté en clair dans la page**; fallback local `kiosk-lecayenne/kiosk123` (`:59-66`).


**Défauts au niveau mécanisme :**

| Sév | Défaut | Pourquoi le design l'autorise | Emplacement |
|:--:|---|---|---|
| 🔴 | Le token borne EST un token de l'utilisateur admin id=1 | login:83 fait $user=User::find($lockedKiosk->user_id) puis $user->createToken(). Seeder:37-41 et command:53 fixent user_id=1 (admin). Le token hérite de l'identité/roles/permission | `app/Http/Controllers/Auth/KioskMachineLoginController.php:83` |
| 🔴 | Ability kiosk:order minée mais jamais imposée sur les routes | login:85 crée ['kiosk:order']. Aucune route (api.php:846-849 order.store, :955 menu, :942 kiosk-event) n'ajoute 'ability:kiosk:order' — uniquement auth:sanctum. tokenCan n'est appe | `routes/api.php:849` |
| 🔴 | Tokens borne acceptés sur /admin/* (escalade de privilèges) | api.php:229 middleware ['installed','apiKey','auth:sanctum','localization','throttle:admin-mutation'] sans ability. Le token borne authentifie en tant que user id=1 (admin), passe  | `routes/api.php:229` |
| 🟠 | Clé API statique partagée et exposée dans le bundle JS | config/app.php:63 api_key=MIX_API_KEY ; le préfixe MIX_ est inliné par laravel-mix dans le JS client. ApiKeyMiddleware:24 compare avec === (non constant-time) et renvoie 400 (pas 4 | `app/Http/Middleware/ApiKeyMiddleware.php:24` |
| 🟠 | Mot de passe machine par défaut kiosk123 et hachages semés en clair | Seeder:37 bcrypt('kiosk123'), :50/:64 '123456' ; command signature:25 --password=kiosk123 (appliqué en prod avec --force) ; config/kiosk.php:64 fallback 'kiosk123'. Toutes les born | `database/seeders/KioskMachineTableSeeder.php:37` |
| 🟠 | Révocation et logout cross-borne (DoS d'identité partagée) | login:81 $user->tokens()->where('name','kiosk-token')->delete() supprime les tokens de TOUTES les bornes du user partagé ; logout:103-106 remet is_login=NO sur toutes les bornes du | `app/Http/Controllers/Auth/KioskMachineLoginController.php:81` |
| 🟠 | Identifiants machine servis en clair au navigateur borne | config/kiosk.php:52-71 lit KIOSK_MACHINE_PASSWORD et construit spa_payload={username,password} exposé au SPA (auto-login sans formulaire). Le mot de passe transite en clair dans le | `config/kiosk.php:68` |
| 🟠 | branch_id du token = branch_id admin (0) : isolation cassée hors broadcast | Le principal est l'admin id=1 (branch_id=0). channels.php:25-38 ajoute un cas spécial kiosk:order pour restreindre le canal, mais aucun autre endpoint ne le fait : ils lisent $user | `routes/channels.php:33` |

**🎯 Redesign cible :**

## Architecture cible — identité machine de premier ordre

### Principe directeur
Une borne est un **device**, pas un humain, et surtout **pas l'admin**. On sépare (1) l'identité device, (2) l'utilisateur de service porteur du token, (3) l'autorité (abilities) imposée structurellement.

### 1. Utilisateur de service dédié, jamais admin
- Chaque `kiosk_machines` référence un **service user** créé exprès (`is_service=true`, `role=kiosk-service`), `branch_id` = branche réelle (jamais 0), aucun rôle/permission admin.
- `kiosk_machines.user_id` ne peut plus valoir 1 : contrainte applicative + garde au boot. Un service user par borne, 1↔1, jamais partagé → tue la révocation cross-borne.
- `EnsureKioskMachineCommand` crée le service user si absent, refuse `--user-id` visant un user privilégié.

### 2. Pairing device à secret unique
- Colonnes : `device_secret_hash` (secret aléatoire 256 bits au provisioning, jamais kiosk123), `paired_at`, `last_seen_at`, `active_token_id`.
- Le secret **n'est jamais** rendu dans la page : appariement one-time via écran admin (QR), stocké dans le store local device. `config/kiosk.php` : retirer `password` de `spa_payload` et les fallbacks kiosk-lecayenne/kiosk123.
- Login = `Hash::check(device_secret, device_secret_hash)`.

### 3. Ability imposée structurellement
- Tout token borne minté avec l'unique ability `kiosk:order`.
- Middleware `ability:kiosk:order` **enregistré et appliqué** sur le groupe frontend/order/menu/kiosk-event.
- Symétrique : `deny.kiosk` sur `/admin/*`, `/profile` → `abort(403)` si `tokenCan('kiosk:order')`. Aucun token borne ne franchit /admin.
- Le service user n'a de toute façon aucune permission admin (défense en profondeur : identité + ability + rôle).

### 4. Contrats de token
- `createToken('kiosk:'.machine_id, ['kiosk:order'], expiration)` sur le **service user de CETTE borne**.
- Nom = `kiosk:{machine_id}` → révocation ciblée : login ne supprime que `where('name','kiosk:'.$machine_id)`, jamais le parc.
- `active_token_id` posé dans la transaction ; unicité `(user_id,name)` = un seul token vivant par borne.

### 5. Clé API n'est plus un secret d'auth
- La borne s'authentifie par **token Sanctum**. `apiKey` retiré des routes borne, ou au plus discriminant non sensible : renommer hors `MIX_` (non inliné client), comparer via `hash_equals`, renvoyer 401.

### 6. Frontières de transaction
- Login : `transaction { lock borne → vérifie secret → révoque son propre token → crée token → set active_token_id, last_seen_at }`. Aucun effet de bord inter-borne.

### Invariants portés par la structure
- I1 : `user_id` → service user non-admin (contrainte + garde boot).
- I2 : token borne ⟹ ability `kiosk:order` exactement (GATES §7).
- I3 : `/admin/*` rejette tout `tokenCan('kiosk:order')` (403).
- I4 : secret device unique par borne, jamais transmis au front ni loggé.
- I5 : login/logout n'affecte que sa propre ligne + son token.
- I6 : `branch_id` du principal = branche réelle ≠ 0 → isolation partout.


**Contrats de test :**
- Invariant identité: pour toute kiosk_machines, User::find(user_id) n'a ni rôle admin ni branch_id==0 (property-based sur tout le parc).
- Escalade /admin: un token borne (ability kiosk:order) sur tout endpoint du groupe admin/* et /profile renvoie 403, jamais 2xx.
- Enforcement ability: un token SANS kiosk:order sur POST /frontend/order, /frontend/menu, /kiosk-event renvoie 403 ; un token admin normal aussi (n'a pas kiosk:order).
- Isolation révocation: login borne A ne supprime ni n'invalide le token de borne B ; assert token B valide après re-login A (parc >=2 bornes).
- Secret device: /auth/kiosk-login échoue avec Hash::check faux ; aucun mot de passe/secret n'apparaît dans la réponse HTTP, spa_payload, ni les logs.
- Pas de défaut faible: aucune ligne kiosk_machines n'a un secret validant 'kiosk123'/'123456' hors environnement local/test.

**Migration/rollout :** (1) Migration: device_secret_hash, paired_at, last_seen_at, active_token_id + unique(user_id,username). (2) Créer service users par branche, repointer user_id dessus, ne plus viser id=1. (3) Appliquer ability:kiosk:order sur groupe borne + deny.kiosk sur /admin/* et /profile. (4) Login sur device_secret + token kiosk:{machine_id}, révocation ciblée. (5) config/kiosk.php: retirer password de spa_payload et fallbacks kiosk123. (6) Re-pairer chaque borne via écran admin. Compat: flag transitoire acceptant l'ancien password durant re-pairing, off en prod. Données: invalider tous les kiosk-token au déploiement; sortir MIX_API_KEY du bundle puis config:clear.

---

### ⚙️ Disponibilité / 86 / stock (AvailabilityService, MenuSnapshot, MenuProjectionService, KioskMenuService, Frontend/MenuController)
**Verdict : `a-refondre`**

**Carte mécanique :**

## Carte mécanique

### Données
- `item_branch_availability` UNIQUE(item_id,branch_id) : `is_available`, `unavailable_reason(32)`, `max_daily_qty?`, `daily_consumed_qty`, `daily_reset_at` (migration 2026_04_15_230100:15-28).
- 2e drapeau `items.is_available` (migration 2026_04_18_130001:37). Deux dimensions 86 coexistent.

### ÉCRITURE (toggle)
- `AvailabilityService::toggle()` (AvailabilityService.php:32-74) : txn + lockForUpdate, idempotence (61), event **dans** la txn (56/70).
- `AvailabilityController::toggle()` (AvailabilityController.php:22-82) **réimplémente** la logique via `toggleBranchAvailability()` (99-142) au lieu du service ; event via afterCommit (63). `branch_id==0` = toutes branches (resolveScopedBranchIds:87-97).

### STOCK (auto-86)
- `OrderCreated::dispatch` (OrderService.php:534/948/1247, FrontendOrderService:835) → listener **sync** `DecrementItemAvailabilityOnOrder` (EventServiceProvider:106) → `decrementForOrder()` (118-163) : boucle orderItems, `->first()` **sans lock** (124), reset paresseux (133), `min(max,consumed+qty)` (140), flip false au cap (145), event au flip (154).

### LECTURE (projections)
- `MenuProjectionService::forChannel()` (60-109) : `projectItems` expose `available` = row?is_available:**true** (120), ignore items.is_available.
- `KioskMenuService::build()` (45-100) : `is_available = branchRow AND items.is_available` (280).
- `Frontend/MenuController::kiosk()` (34-88) : Cache::remember TTL 60s (66-71).

### Invalidation
- `MenuSnapshot` : current() put-on-miss (40), bump() get-null→put→increment non-atomique (57-70).
- Listeners sur ItemAvailabilityChanged : bump snapshot + forget cache (best-effort).

### Frontière critique
Le chemin COMMANDE (PricingService, PricingPreviewService, OrderService) ne référence **jamais** availability (grep : 0 hit dans app/Services/Pricing). Dispo produite en lecture, consommée nulle part en écriture.


**Défauts au niveau mécanisme :**

| Sév | Défaut | Pourquoi le design l'autorise | Emplacement |
|:--:|---|---|---|
| 🔴 | Le 86 est write-only : jamais une contrainte sur le chemin commande | PricingService/PricingPreviewService/OrderService ne lisent aucune fois item_branch_availability (grep: 0 hit dans app/Services/Pricing). Read-model et write-model disjoints. Menu  | `app/Services/Kiosk/PricingPreviewService.php:52` |
| 🔴 | Décrément stock non-atomique : lost update → oversell | decrementForOrder lit ->first() (124) puis ->save() (151) SANS lockForUpdate (contraste toggle:42). Deux OrderCreated concurrents lisent le même daily_consumed_qty, les writes s'éc | `app/Services/Menu/AvailabilityService.php:124` |
| 🔴 | L'auto-86 ne se réactive jamais (état mort) | Le reset quotidien (133-136) remet daily_consumed_qty=0 mais NE restaure PAS is_available. Le passage à false='out_of_stock' (145-149) n'a d'inverse que le toggle admin. Aucun job  | `app/Services/Menu/AvailabilityService.php:145` |
| 🟠 | Décrément N+1 synchrone sur le chemin chaud de commande | DecrementItemAvailabilityOnOrder est un listener sync (EventServiceProvider:106, pas ShouldQueue). decrementForOrder boucle avec un SELECT (124) + save (151) par ligne : K lignes → | `app/Services/Menu/AvailabilityService.php:123` |
| 🟠 | Logique de toggle dupliquée et divergente (deux sources de vérité) | AvailabilityController::toggleBranchAvailability (99-142) réimplémente create-or-update + idempotence + event de AvailabilityService::toggle (32-74) au lieu de l'appeler. Elles div | `app/Http/Controllers/Admin/AvailabilityController.php:99` |
| 🟠 | Event de dispo dispatché avant commit (chemin service) | AvailabilityService::toggle dispatche dans DB::transaction (56/70). Les listeners (invalidation cache, bump snapshot, outbox) tournent sur un état non commité : un rollback laisse  | `app/Services/Menu/AvailabilityService.php:56` |
| 🟠 | branch_id==0 comme sentinelle god-scope affaiblit l'isolation | resolveScopedBranchIds traite user.branch_id===0 comme autorité sur TOUTES les branches (87-97). Un utilisateur mal seedé à branch_id=0 obtient silencieusement le pouvoir de 86 cro | `app/Http/Controllers/Admin/AvailabilityController.php:87` |
| 🟡 | Sémantique de dispo incohérente entre surfaces (fail-open) | KioskMenuService ANDe items.is_available avec la row branch (280) ; MenuProjectionService ne lit que la row branch (120). Un item 86 via items.is_available reste 'available' côté P | `app/Services/Menu/MenuProjectionService.php:120` |

**🎯 Redesign cible :**

## Architecture cible

### 1. Un résolveur unique
`AvailabilityResolver::resolve(int $branchId, array $itemIds): Map<int,AvailabilityState>` — SEULE fonction qui répond « ce (item,branch) est-il vendable ? ». Signature batch (tue le N+1). `AvailabilityState` (VO) : `{available, reason, kind:'manual'|'out_of_stock', remaining}`. Règle TOTALE définie une fois : `available = item.status==ACTIVE && item.is_available && branchRow?.is_available (politique d'absence explicite)`. Projections ET guard consomment ce résolveur → parité read/write.

### 2. La dispo devient une CONTRAINTE d'écriture
`AvailabilityGuard::assertOrderable(branchId, lines)` appelé DANS la txn de commande (OrderService/FrontendOrderService) avant persistance. Lève `ItemUnavailableException` (→409) listant les item_id 86. Même guard appelé en shadow par `PricingPreviewService::preview()` pour refléter le refus AVANT le POST. Invariant : aucune order_item committée pour un item non disponible.

### 3. Décrément atomique + idempotent
Remplacer le read-modify-write par un UPDATE conditionnel unique dans la txn de commande :
```sql
UPDATE item_branch_availability
SET daily_consumed_qty = LEAST(max_daily_qty, daily_consumed_qty+:q),
    is_available = CASE WHEN daily_consumed_qty+:q >= max_daily_qty
                        THEN 0 ELSE is_available END
WHERE item_id=:i AND branch_id=:b AND max_daily_qty IS NOT NULL;
```
Atomicité SQL + UNIQUE(item_id,branch_id) portent `vendu<=max_daily_qty` sans lock applicatif. Idempotence : ledger `availability_decrements(order_id UNIQUE)` → OrderCreated rejoué ne décompte qu'une fois. Flip détecté via relecture ciblée → event afterCommit.

### 4. Réactivation explicite
Distinguer `kind='out_of_stock'` (auto) de `kind='manual'` (rupture admin). Commande planifiée `menu:availability:reset-daily` (Kernel, TZ-aware au cutover branche) : `SET daily_consumed_qty=0, is_available=1, reason=NULL WHERE reason='out_of_stock'` — restaure seulement l'auto-86, laisse la rupture manuelle. Reset indépendant d'une commande entrante.

### 5. Un seul chemin de toggle
Supprimer `AvailabilityController::toggleBranchAvailability` ; déléguer à `AvailabilityService::toggle`, qui dispatche l'event UNIQUEMENT via `DB::afterCommit`. Scope résolu par `BranchScopePolicy` explicite — suppression de la sentinelle `branch_id==0`.

### 6. Versioning fiable
`MenuSnapshot` : n'utiliser que `INCR` atomique ; supprimer le put-on-miss du chemin lecture. Idéalement colonne `branches.menu_version` bumpée dans la MÊME txn que la mutation → monotonie forte.

### Invariants portés
- INV-A1 : pas d'order_item pour un item non disponible (Guard dans la txn).
- INV-A2 : vendu/jour <= max_daily_qty (UPDATE atomique).
- INV-A3 : résolution totale, un seul résolveur, absence documentée.
- INV-A4 : auto-86 réversible sans humain ; rupture manuelle non auto-levée.
- INV-A5 : dispo lue == dispo appliquée.
- INV-A6 : events uniquement après commit.


**Contrats de test :**
- Property/concurrence: N commandes parallèles d'un item plafonné → SUM(vendu committé) <= max_daily_qty toujours. Prouve le décrément atomique (défaut #2).
- Contrainte d'écriture: POST order (kiosk ET POS) avec item branch-86 → 409, aucune Order ni order_item persistée. Prouve INV-A1 / défaut #1.
- Parité preview↔order: PricingPreviewService et OrderService renvoient le même verdict de dispo pour le même panier 86.
- Réactivation: item auto-86 au cap → run reset-daily J+1 → is_available restauré, compteur=0 ; item en rupture manuelle reste 86 (INV-A4).
- Idempotence: dispatcher OrderCreated deux fois pour le même order_id → daily_consumed_qty incrémenté une seule fois (ledger order_id UNIQUE).
- Parité résolveur + O(1): même (item,branch) → même bool via MenuProjection, Kiosk et Guard ; commande à K lignes → nb de requêtes dispo constant (défauts #4,#8).

**Migration/rollout :** Rollout incrémental. (1) AvailabilityResolver + State ; rebrancher MenuProjectionService et KioskMenuService (comportement inchangé). (2) AvailabilityGuard en shadow : logguer les commandes qui SERAIENT bloquées (mesure oversell). (3) Enforce sur POST order derrière flag par branche ; le front kiosk gère déjà le 409. (4) UPDATE atomique + table availability_decrements(order_id UNIQUE). (5) reset-daily au scheduler ; migration data one-shot : UPDATE ... SET is_available=1, daily_consumed_qty=0, reason=NULL WHERE reason='out_of_stock' (déverrouille les items bloqués à vie). (6) Supprimer toggleBranchAvailability, router via le service ; remplacer branch_id==0 par BranchScopePolicy.

---

### ⚙️ Outbox / événements de domaine / broadcast (domain_events + DispatchDomainEventsJob + listeners + eventContract JS)
**Verdict : `a-refondre`**

**Carte mécanique :**

## Flux réel (chemin vivant)
1. **Écriture métier** dans `DB::transaction(...)` (`OrderService.php:518` ferme la tx ; commit).
2. **Après commit**, hors transaction, dispatch synchrone dans un `try/catch` best-effort : `OrderService.php:524-534` (`OrderCreated`), `:1404` (`OrderStatusChanged`), `FrontendOrderService.php:835`, `:841`, `KitchenDisplaySystemOrderService.php:149`.
3. **Listener synchrone** écrit la ligne outbox : `PersistOrderCreatedToOutbox.php:18` (`DomainEvent::create`), `PersistOrderStatusChangedToOutbox.php:18`, `PersistItemAvailabilityChangedToOutbox.php:41`. → insert **autonome**, hors tx métier.
4. Le listener planifie le relais via `DB::afterCommit` (`PersistOrderCreatedToOutbox.php:37`). Aucune tx active → callback exécutée **immédiatement**.
5. `DispatchDomainEventsJob::dispatch($id)->onQueue('high')`. `QUEUE_CONNECTION=sync` (`config/queue.php:16`) → job **inline dans la requête**.
6. `DispatchDomainEventsJob::handle` (`:31`) : `find`+garde `dispatched_at` (`:37`), `increment('attempts')` (`:41`), `assertEnvelopeValid` (`:57`), trigger Pusher (`:91`), puis `dispatched_at=now()` (`:95`).

## États d'une ligne
`domain_events` : `attempts`, `dispatched_at` (null=pending), `last_error`. Scopes `pending/stale(2min)/failed(>=4)` (`DomainEvent.php:33-48`). Migration : index `idx_pending`, **aucune contrainte UNIQUE** (`2026_04_15_...:27`). `correlation_id`=UUID frais par ligne, jamais utilisé côté client.

## Rescue/retry
`OutboxRescueCommand` (re-dispatch stale), `OutboxRetryFailedCommand` (reset). Sous `sync`, `backoff`/`tries=5` (`DispatchDomainEventsJob.php:21-23`) sont morts.

## Frontière transactionnelle
La tx métier se **ferme avant** toute écriture outbox. Un second mécanisme atomique existe mais est **mort** : `HasDomainEvents::recordDomainEvent` (`:13`) écrit dans le hook `saved()` (`:29`, dans la tx) — mais n'est **appelé nulle part** (Order/FrontendOrder importent le trait sans l'invoquer).

## Consommateur
`eventContract.js` : `parseEvent`/`validateEnvelope` (`:20`), `onEvents` s'abonne via `Echo.private('branch.'+id)` (`:71`), `unsubscribe` fait `Echo.leave(channelName)` (`:113`). `WebSocketService.js` heartbeat (`:129`). Contrat dupliqué : `EventType.php`, `EventContract::BROADCAST_MAP` (`:34`), `eventContract.js:1,10`, `eventContract.schema.json`.


**Défauts au niveau mécanisme :**

| Sév | Défaut | Pourquoi le design l'autorise | Emplacement |
|:--:|---|---|---|
| 🔴 | L'outbox n'est PAS transactionnel : ligne écrite après commit, hors tx métier | Les événements sont dispatch APRÈS la fermeture de DB::transaction (OrderService.php:524-534). Le listener sync qui écrit domain_events (PersistOrderCreatedToOutbox.php:18) tourne  | `app/Listeners/PersistOrderCreatedToOutbox.php:18` |
| 🔴 | try/catch best-effort : échec d'écriture outbox avalé en warning | Le dispatch (donc l'écriture outbox via listener sync) est dans un try/catch qui ne fait que Log::warning (OrderService.php:533, FrontendOrderService.php:841). Si create ou trigger | `app/Services/OrderService.php:533` |
| 🟠 | Pusher non configuré : ligne marquée dispatched sans avoir été émise | Si PUSHER_APP_KEY vide et manager réel, handle log 'skipping' (DispatchDomainEventsJob.php:84) puis :95 force dispatched_at=now(). La ligne est vue comme livrée alors que rien n'a  | `app/Jobs/DispatchDomainEventsJob.php:95` |
| 🟠 | QUEUE=sync : broadcast inline dans la requête, retry/backoff/rescue morts | config/queue.php:16 défaut sync. afterCommit post-commit lance le job inline : le trigger Pusher réseau bloque la requête. backoff=[1,5,30,300] et tries=5 (DispatchDomainEventsJob. | `config/queue.php:16` |
| 🟠 | Echo.leave() détruit le canal partagé : un composant démonté coupe les autres | Echo cache une instance unique par nom de canal ; KDS, OSS, POS partagent 'branch.X'. unsubscribe fait Echo.leave(channelName) (eventContract.js:113) qui désabonne le canal ENTIER, | `resources/js/services/eventContract.js:113` |
| 🟠 | Aucune idempotence consommateur ni clé de dédup : rescue re-broadcast = doublons | L'enveloppe (EventContract.php:59) n'expose pas l'id de la ligne ; correlation_id est un UUID par ligne non utilisé par le client. Aucune UNIQUE sur domain_events (migration:27). S | `resources/js/services/eventContract.js:44` |
| 🟠 | Aucune resync à la reconnexion + heartbeat factice | Les événements broadcast pendant une déconnexion sont perdus (l'outbox n'a aucun curseur par consommateur). Le heartbeat (WebSocketService.js:131) réécrit _lastPongAt=Date.now() in | `resources/js/services/WebSocketService.js:131` |
| 🟡 | Contrat quadruplé (non mono-source) + chemin atomique mort | Types/mapping dupliqués dans EventType.php, EventContract::BROADCAST_MAP (:34), eventContract.js (:1,:10) et schema.json, sync manuelle. Toute dérive (type, broadcast_as, préfixe ' | `app/Domain/Events/EventContract.php:34` |

**🎯 Redesign cible :**

## Cible : outbox transactionnel + relais séparé + consommateurs idempotents

### 1. Écriture DANS la transaction (atomicité portée par la structure)
Supprimer tout dispatch post-commit. L'écriture outbox devient une étape de la tx métier : `$outbox->record(new OrderCreated($order))` **à l'intérieur** de `DB::transaction`. `record()` = INSERT immédiat. Si rollback, la ligne disparaît avec l'état métier. Réhabiliter `HasDomainEvents` (hook `saved()`, déjà dans la tx) ou un `OutboxWriter` injecté ; supprimer le double chemin et les listeners Persist*.

### 2. Schéma qui porte les invariants
- `id` = **event id** exposé dans l'enveloppe (dédup stable).
- `sequence` monotone par agrégat ; `UNIQUE(aggregate_type,aggregate_id,event_type,sequence)` → dédup + ordre.
- `dispatched_at`, `attempts`, `next_retry_at`, `last_error`.
- `channel`/`payload` castés par le modèle (fin du `json_decode` manuel).

### 3. Relais séparé du web (afterCommit lit la TABLE)
Worker `foodking:outbox:relay` (queue:work dédié) : `SELECT ... WHERE dispatched_at IS NULL AND next_retry_at<=now() ORDER BY id FOR UPDATE SKIP LOCKED`, broadcast, marque `dispatched_at`. `QUEUE_CONNECTION` réel (redis/database) ; garde-fou démarrage interdisant `sync` en prod. Le web ne broadcast plus jamais inline ; `afterCommit` notifie au mieux le worker. Relais = seule voie → rescue et flux nominal partagent le code.

### 4. Pusher indisponible = échec, jamais succès
Supprimer le chemin skip+dispatched_at. Broadcaster absent en prod → exception → `attempts++`, `next_retry_at` recul, ligne reste pending. `dispatched_at` écrit **uniquement** sur trigger confirmé (2xx). En CI, broadcaster `fake` qui compte les envois, pas un skip qui ment.

### 5. Enveloppe mono-source
Générer `EventType`, `BROADCAST_MAP`, `REQUIRED_PAYLOAD_KEYS`, préfixe de canal et schéma JS **depuis un seul manifeste** (`event-contract.json` → codegen PHP+JS+schema au build). `buildEnvelope` ajoute `event_id` et `sequence`. Nom de canal via helper partagé `channelForBranch($id)` (une seule définition).

### 6. Consommateurs idempotents + resync
- Client : LRU des `event_id` vus → ignorer doublon ; ignorer `sequence` < dernière vue par agrégat (rejet out-of-order).
- **Resync** : sur `connected`/`reconnected`, `GET /branch/{id}/events?since={lastEventId}` rejoue les lignes non vues. `lastEventId` persisté par surface (KDS/OSS).
- Heartbeat réel : ping applicatif ; si `now-_lastPongAt > 2×interval`, forcer `disconnected` → déclenche resync.

### 7. Canal partagé : refcount au lieu d'Echo.leave
`onEvents` compte les bindings par canal ; `unsubscribe` fait `stopListening` local et n'appelle `Echo.leave(channel)` **qu'au refcount 0**. Les autres composants survivent.

### Invariants structurels
- Ligne outbox existe **ssi** état métier committé (même tx).
- Livré **≥1 fois** (relais rejoue), appliqué **1 fois** (dédup `event_id`+`sequence`).
- `dispatched_at` ⇒ broadcast confirmé. Ordre par agrégat garanti par `sequence`.


**Contrats de test :**
- Atomicité : tx métier qui rollback (exception après record) ⇒ assertDatabaseCount('domain_events',0) ; si commit, exactement 1 ligne. Property : ∀ scénario, ligne outbox ⇔ état métier committé.
- Crash-safe : kill entre commit et relais (relais non exécuté) ⇒ ligne reste pending et un run de relais la livre. Aucune fenêtre où l'état existe sans ligne récupérable.
- Pusher KO : broadcaster jetant/vide en prod ⇒ dispatched_at reste null, attempts++, next_retry_at futur ; jamais marqué livré. Run ultérieur OK ⇒ livré.
- Idempotence (property) : rejouer N fois la même enveloppe (même event_id) ⇒ handler 1 fois ; sequences dans le désordre ⇒ état final = plus grande sequence, stales rejetées.
- Canal partagé : deux abonnés sur branch.X, l'un unsubscribe ⇒ l'autre reçoit toujours ; au dernier unsubscribe, Echo.leave appelé une seule fois.
- Resync : K événements broadcast en état 'disconnected' puis 'connected' ⇒ GET events?since=lastEventId rejoue exactement les K manqués, sans doublon.

**Migration/rollout :** (1) Migration additive : `sequence`, `next_retry_at`, backfill sequence par agrégat, puis UNIQUE(aggregate,id,event_type,sequence) après dédup. (2) OutboxWriter intra-tx en double écriture (flag), ancien conservé. (3) Worker relais (QUEUE réel) + garde-fou interdisant sync en prod. (4) Basculer OrderService/FrontendOrderService/KDS du dispatch post-commit vers record() intra-tx ; retirer try/catch avaleurs et Pusher-skip. (5) Manifeste mono-source + codegen ; front lit event_id/sequence (optionnels tolérés). (6) refcount Echo + resync lastEventId. (7) Retirer listeners Persist* et trait mort après double-run. Compat : enveloppes sans event_id acceptées (fallback correlation_id).

---

### ⚙️ Temps réel & résilience front (KDS / OSS / WebSocketService / kioskMenu / KioskApp)
**Verdict : `a-refondre`**

**Carte mécanique :**

## Carte mécanique

### Transport & état
- `WebSocketService` singleton (`WebSocketService.js:29`). `start()` bind `pusher.connection.state_change` (`:55-78`)→CONNECTED/DISCONNECTED/UNAVAILABLE. `_setState` émet connected/disconnected (`:118`). Heartbeat `_startHeartbeat` (`:131`) **ne ping pas**, pose `_lastPongAt=now` jamais relu → pas de liveness. `window._wsService` (`bootstrap.js:90`).
- `eventContract.js` : `parseEvent` expose `correlation_id`/`aggregate_id`/`occurred_at` ; `onEvents` s'abonne canal privé `branch.{id}`. **Aucun dédup**.

### KDS (`KitchenDisplaySystemComponent.vue`)
- 4 tableaux locaux (`:500-503`). `mounted` (`:523`) branche 3 transports : window `realtime-order-update`→`refreshOrderList` non-debouncé (`:527`), Echo→`_debouncedRefresh` (`:576-579`), ws connected/disconnected (`:544`) + polling (`:526`).
- `_refreshWithCurrentFilter` (`:603`) = GET complet + re-filtrage client 4 tableaux ; `items()` = 2e GET. `_pollingInterval` 60s/10s (`:553`), setInterval même WS actif (`:560`). `_debouncedRefresh` coalesce 300ms (`:805`). `orderStatus` (`:772`) POST puis `_debouncedRefresh` + `dispatchEvent('realtime-order-update')` (`:788`).
- Store `kitchenDisplaySystemOrder.js` : mutations = remplacement complet (`:59`) ; `changeStatus` re-dispatch `lists` (`:39`).

### OSS (`PreparingAndReadyComponent.vue`)
- `list()` (`:203`) GET complet + filtre PREPARING/PREPARED. Triple abonnement (window `:72`, Echo `:130`, ws `:96`). Chime/flash `_markNewReady` (`:169`) déclenché par diff `prevPreparedIds` ET Echo ; garde `_echoMarkedReady` (`:214`) couvre seulement course Echo↔list interne, pas FCM.
- Store `orderStatusScreenOrder.js` : `state.mostPopularItems` non déclaré (`:6`) mais lu/écrit (`:14/:45`).

### Kiosk dispo (`KioskAppComponent.vue`)
- `_subscribeEchoChannel` (`:381`)→`ItemAvailabilityChanged`→`kioskMenu/UPDATE_ITEM` (patch incrémental non-destructif, `kioskMenu.js:185`) + refetch si `type==='full'`. `_onWsReconnect` (`:263`) **ne resync que la file offline**, pas le menu.
- `kioskCart.js submitOrder` (`:331`) : POST ; `catch` (`:363`) `isNetworkError=!err.response||status>=500` (`:366`)→`saveOrder`+succès synthétique `queue_number:'—'` (`:374`). `kioskOfflineQueue.js` rejoue même idempotency-key, abandon à 10.

### FCM (2e transport)
- `BackendNavbar onMessage` (`:272`)→Notification+`dispatchEvent('realtime-order-update')` (`:296`). Idem `FrontendNavBar:382`. Même event métier arrive par FCM ET Echo.


**Défauts au niveau mécanisme :**

| Sév | Défaut | Pourquoi le design l'autorise | Emplacement |
|:--:|---|---|---|
| 🔴 | 5xx serveur confondu avec offline → fausse confirmation (TPE contre commande fantôme) | kioskCart.js:366 collapse `!err.response // status>=500` en une branche. Un 500 (pricing/taxe/contrainte DB rejetée) devient succès synthétique (:374), l'UI navigue vers confirmati | `resources/js/store/modules/kioskCart.js:366` |
| 🟠 | Rejeu offline non réconcilié avec le paiement | L'id `offline_…` fait sauter payment-confirm et void (KioskPaymentComponent.vue:544,:421). Une commande carte-payée mais 500'd est rejouée comme POST frontend/order neuf sans lien  | `resources/js/store/modules/kioskCart.js:372` |
| 🟠 | Double livraison FCM + Echo sans dédup sur identité d'événement | Le même event arrive via FCM (BackendNavbar:296 dispatch) ET Echo (KDS:576). L'enveloppe porte correlation_id (eventContract) mais personne ne dédup. KDS branche FCM sur refreshOrd | `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:527` |
| 🟠 | Refetch complet par event : le modèle d'état interdit le patch en place | Le store modélise 'la liste = la réponse serveur' (mutation remplace le tableau, kitchenDisplaySystemOrder.js:59). Sans identité par commande, refléter UNE commande impose de tout  | `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:603` |
| 🟠 | Pas de resync dispo menu à la reconnexion → divergence permanente | La dispo kiosk est event-sourced sans snapshot-au-reconnect : UPDATE_ITEM ne s'applique que sur events live (KioskApp:385). Toute mutation pendant la coupure WS est perdue, et _onW | `resources/js/components/frontend/kiosk/KioskAppComponent.vue:263` |
| 🟠 | Heartbeat factice : aucune détection de socket zombie (half-open) | _startHeartbeat (WebSocketService.js:131) n'émet aucun ping et _lastPongAt n'est jamais relu pour juger la liveness. La seule source de 'disconnected' est le state_change Pusher. U | `resources/js/services/WebSocketService.js:131` |
| 🟡 | Polling et push additifs sans arbitrage | startAutoRefresh crée un setInterval même WS connecté (KDS:560) ; l'état WS ne fait que régler l'intervalle (:553), jamais rendre polling et push exclusifs. Cumulé à Echo, FCM et a | `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:560` |
| ⚪ | Store OSS : racine réactive mostPopularItems non déclarée | orderStatusScreenOrder.js déclare seulement state.lists (:6) mais le getter lit state.mostPopularItems (:14) et la mutation l'écrit (:45). Propriété ajoutée impérativement hors sta | `resources/js/store/modules/orderStatusScreenOrder.js:6` |

**🎯 Redesign cible :**

## Architecture cible

### 1. Puits d'ingestion idempotent (RealtimeBus)
`services/realtimeBus.js` : source unique où **FCM et Echo sont deux transports** vers un seul `ingest(rawEvent)`.
- `ingest` : `parseEvent` (déjà présent) → dédup LRU sur `correlation_id` (fallback `aggregate_id+occurred_at`, TTL ~60s) → commit d'une mutation incrémentale.
- FCM (`BackendNavbar onMessage`) et Echo (`onEvents`) n'appellent plus `dispatchEvent('realtime-order-update')`/`list()` : ils appellent `ingest`. Le window-event global et les triples abonnements par composant disparaissent.
- Contrat : `ingest(e)` appliqué N fois ≡ 1 fois ; ordre FCM/Echo indifférent.

### 2. Store normalisé, mutations incrémentales
- state : `ordersById:{}`, `orderIds:[]`. Getters dérivés `dinein/online/takeaway/kiosk` (filtre memoïsé), `preparing/prepared` OSS.
- Mutations : `UPSERT_ORDER`, `PATCH_ORDER({id,patch})`, `REMOVE_ORDER`, `SET_SNAPSHOT(list)` (réservé mount / reconnexion / changement de filtre).
- L'event porte `{order_id,new_status,order_type}` → `PATCH_ORDER` **sans réseau**. Le refetch complet cesse d'être un mode de fonctionnement pour devenir un mécanisme de réconciliation. `kioskMenu.UPDATE_ITEM` (incrémental, non-destructif) est le modèle.

### 3. Machine à états + arbitre polling
`WebSocketService` : vrai ping applicatif ; `_lastPongAt` **relu** → sans pong dans `2×interval`, transition `stale` → émet `disconnected` (détection half-open). Expose un `connectionState` unique.
`PollingArbiter` (un seul, injecté) : `CONNECTED`→polling OFF (push + resync-reconnect) ; `DISCONNECTED/STALE`→10s. La transition possède l'ownership ; les composants **ne créent plus de setInterval**, ils s'abonnent au store.

### 4. Resync au `connected`
Sur `wsService.on('connected')` : KDS/OSS `SET_SNAPSHOT` (fetch de réconciliation) ; Kiosk `_onWsReconnect` doit **aussi** `kioskMenu/fetchMenu({force})`, pas seulement flush file. Flag `missedWhileOffline` levé à la déconnexion, abaissé après resync.

### 5. Taxonomie d'échec submitOrder (correctif critique)
Plus de branche fourre-tout, 4 classes :
- **Transport** (`!err.response`/`err.request`/`navigator.onLine===false`) → file offline, UI « en file d'attente » (jamais confirmation).
- **5xx** → **rejette**, message réel, retry même idempotency-key ; **pas** de navigation confirmation, **pas** de capture TPE.
- **4xx** → rejette + validation. **409 dispo** → route `kiosk.error.product-removed`.
Chemin carte : réserver commande serveur (pending) **avant** TPE, capturer, puis confirmer ; voie offline synthétique interdite en carte.

## Invariants portés structurellement
- Ingestion idempotente : rejouer un event ne change rien.
- Patch non-destructif (déjà garanti UPDATE_ITEM).
- Confirmation atteignable seulement depuis un id **acquitté serveur** (jamais `offline_` en carte).
- Exactement un refresh par identité d'événement.
- Reconnexion ⇒ snapshot réconcilie toute divergence (commandes + dispo).
- Pricing = SSOT serveur (`price` poussé = hint d'affichage).


**Contrats de test :**
- Idempotence/commutativité (property-based) : toute séquence d'events (doublons + réordonnancements) entrelaçant FCM et Echo ⇒ état store final == état d'un unique SET_SNAPSHOT autoritatif.
- Dédup : même correlation_id livré 2× (FCM + Echo) ⇒ une seule mutation, un seul chime/flash OSS.
- 5xx ≠ confirmation : submitOrder avec 500 mocké ⇒ rejette, aucune navigation waiting/confirmation, aucun saveOrder, TPE non invoqué.
- Offline honnête : transport down (pas de response) ⇒ commande en file, UI 'en attente' (non 'confirmée'), rejeu même X-Idempotency-Key, zéro doublon serveur.
- Resync reconnexion : déconnecter, muter dispo/statut serveur, reconnecter ⇒ menu et commandes reflètent le changement SANS event live.
- Arbitrage + zombie : en CONNECTED aucun setInterval de fetch ne tire ; half-open simulé (state 'connected', aucun pong) ⇒ 'disconnected' émis sous 2×interval et polling 10s démarre.

**Migration/rollout :** Ordre livrable indépendamment : (1) RealtimeBus + dédup, additif : router FCM+Echo dedans, garder refetch complet en repli. (2) Resync menu au reconnect kiosk (bas risque). (3) Taxonomie submitOrder derrière flag, chemin carte d'abord (risque argent max), cash ensuite. (4) Store normalisé : getter `lists` conservé en shim dérivé de `ordersById` pour migration incrémentale. (5) Liveness wsService + PollingArbiter derrière flag, valider zombie en staging. Données : ajouter `transport_reason` (network|5xx) à la file offline ; les entrées mises en file sous l'ancienne logique 5xx doivent être remontées au personnel, pas rejouées à l'aveugle (doublon si le 500 avait persisté après commit/outbox).

---

### ⚙️ Panier POS/Kiosk — modules Vuex posCart/kioskCart, persistance vuex-persistedstate, file offline kiosk, PosComponent/PaymentComponent
**Verdict : `a-refondre`**

**Carte mécanique :**

## Carte mécanique

### Persistance (DEUX autorités concurrentes)
- `createPersistedState({paths})` `index.js:216`, paths `218-254` : souscrit à **chaque mutation de tout le store** (~100 modules), re-sérialise l'arbre paths complet (dont `posCart` entier `:224`, `tableCart`, `kioskCart.*` `:228-240`) sous la clé globale `vuex`. Pas de throttle/filter/reducer scope-aware.
- `kioskAnalyticsPlugin :256` : 2e souscripteur global par mutation.
- posCart écrit **en plus** sa clé scopée `pos_cart_v3:b..:u..` (`:12,21`) via `saveCartToStorage` **dans chaque mutation** (`:309,317,331,346,352,356`).

### posCart (`posCart.js:155`)
- Scope module-level `_scope :19`, `getScopedKey :21`, `_applyPosCartScope :87`, hydrate seulement via action `setScope :221`→`hydrateFromScope :372`.
- State `:157` : lists/subtotal/discount/restoredFromStorage.
- `lists :229` fusion par item_id+variations+extras+instruction+signature addons `:274-307` ; `subtotal :320` réassigne `list.total` in-place + persiste ; `quantity :333` ; `discount :354`.
- Effet croisé : actions quantity/deleteCartItem commit `discount,0` (`:190,195`) → toute variation qty **efface la remise**.

### kioskCart (`kioskCart.js:35`)
- Getters prix HT locaux : `subtotal :67`, `total :89`.
- `submitOrder :331` : idempotencyKey minté 1×+persisté `:344-349` ; `buildKioskOrderPayload :21` ; chemin offline `:363-388` → `saveOrder` + **succès synthétique** queue `'—'` `:374-384`.

### File offline (`kioskOfflineQueue.js`)
- `saveOrder :50`, `syncQueue :87`, abandon après 10 essais `:115-120` (synced+abandoned), **prune 24h par savedAt** `:126-127`, `startAutoSync :162` (30s).

### Composants
- `PosComponent.vue` : `carts :888`, `subtotal :892`, `posDiscount :894` ; setScope **au mounted après boot** `:936` ; `orderSubmit :1439` → `form.total = subtotal+delivery-discount` HT `:1446` puis **régénère idempotency_key à l'ouverture modal** `:1496` ; `v-for cart in carts` **sans :key** `:271` ; pas de virtualisation.
- `PaymentComponent.vue` : `cashChange :139` (monnaie sur form.total HT), `confirmOrder :193`→`posOrder/save :217`, tiroir `:224`, `resetCart :244`.

### Frontière transactionnelle
Aucune côté client. Seul point de dédup : en-tête `X-Idempotency-Key` du POST. Serveur SSOT prix mais aucun total serveur relu avant paiement.


**Défauts au niveau mécanisme :**

| Sév | Défaut | Pourquoi le design l'autorise | Emplacement |
|:--:|---|---|---|
| 🔴 | persistedstate re-sérialise posCart NON scopé et défait l'isolation caissier au boot | index.js:224 persiste tout posCart sous la clé globale `vuex` à chaque mutation, sans scope. Au reload, persistedstate réhydrate posCart depuis `vuex` à la construction du store, A | `resources/js/store/index.js:224` |
| 🔴 | Clé d'idempotence liée à l'ouverture du modal → double encaissement | PosComponent.vue:1496 régénère idempotency_key (Date.now()+random) à chaque orderSubmit. Si le POST expire/5xx (commande peut-être déjà committée), le caissier rouvre le modal et r | `resources/js/components/admin/pos/PosComponent.vue:1496` |
| 🔴 | Total et monnaie rendue calculés HT (base fiscale fausse, NF525) | subtotal posCart = Σ display HT (posCartLineMath.js:27). PosComponent.vue:1446 pose form.total HT. PaymentComponent.vue:139 dérive cashChange (monnaie) de form.total HT et ouvre le | `resources/js/components/admin/pos/PaymentComponent.vue:139` |
| 🟠 | Perte silencieuse de commandes offline (abandon = suppression) | kioskCart.js:374 renvoie un succès synthétique (queue '—') sur erreur réseau. syncQueue.js:115 après 10 échecs pose synced+abandoned, puis le prune :127 supprime les synced dont sa | `resources/js/helpers/kioskOfflineQueue.js:115` |
| 🟠 | Amplification d'écriture : persistance couplée à la fréquence des mutations | Chaque mutation déclenche (a) stringify de tout l'arbre paths sur ~100 modules (index.js:216, pas de throttle) et (b) saveCartToStorage propre à posCart dans CHAQUE mutation (posCa | `resources/js/store/index.js:216` |
| 🟠 | Token Sanctum machine et clé d'idempotence dans la même lane localStorage volatile | index.js:239 persiste kioskCart.kioskToken (Sanctum machine) en localStorage — exfiltrable par XSS, mêlé à l'état UI. idempotencyKey persisté :230 mais RESET le vide (kioskCart.js: | `resources/js/store/index.js:239` |
| 🟡 | Mutation subtotal à effets croisés + qty efface la remise silencieusement | Actions quantity/deleteCartItem commit discount,0 (posCart.js:190,195) : toute variation de quantité annule une remise validée (motif obligatoire POS-9.1.1) sans avertir. subtotal  | `resources/js/store/modules/posCart.js:190` |
| 🟡 | Aucune virtualisation + :key manquant sur les lignes panier | PosComponent.vue:271 v-for=(cart,index) in carts sur <tr> sans :key → patch en place par index ; combiné à subtotal qui réassigne les totaux, toutes les lignes re-rendent à chaque  | `resources/js/components/admin/pos/PosComponent.vue:271` |

**🎯 Redesign cible :**

## Architecture cible

### 1. Une seule autorité de persistance (scopée, throttlée)
Retirer `posCart`/`tableCart`/`kioskCart.*` des paths persistedstate (index.js:218). Chaque module panier possède un `CartPersistence` :
- clé dérivée **structurellement** du scope du state : `cart:<surface>:b<branch>:u<user>` ; jamais de clé globale.
- un **seul** souscripteur `store.subscribe(mut → debounce trailing 300ms)` filtré aux mutations panier ; plus de setItem inline.
- hydratation **uniquement** via `hydrate(scope)` quand le scope est connu ; aucun réhydrate global à la construction (supprime la fuite au boot).

### 2. Session panier = clé d'idempotence stable
`sessionId` dans le state, minté à la transition **vide→non-vide**, persisté avec les lignes. submit lit `state.sessionId` comme `X-Idempotency-Key` ; réouverture modal et retries **réutilisent** la même clé. Rotation **seulement** sur 2xx confirmé ou resetCart. Contrat unifié POS+kiosk.

### 3. Total TTC autoritatif serveur
Étape `quote()` : POST panier → `/pos/quote` renvoie `{lines,subtotal_ht,tax,total_ttc}` calculé par le même PricingService. State stocke `serverQuote`. Getter `total` = `serverQuote.total_ttc`, sinon `null`. PaymentComponent affiche total_ttc, `change_due = received - total_ttc`, **bloque** tiroir + Confirmer tant que quote absente/périmée. Le subtotal local devient « estimation » étiquetée, jamais relié à la monnaie/tiroir.

### 4. File offline = machine à états durable (outbox)
`status` explicite par entrée : `pending→syncing→committed|dead_letter`.
- `dead_letter` **jamais** supprimé par le prune ; seul `committed` purgé après TTL.
- surfaçage : badge persistant + écran opérateur ; suppression après **ack explicite**.
- plus de succès synthétique : l'UI distingue `confirmé` (queue serveur) de `en file` (pas de numéro tant que non committé).

### 5. Contrats de module (invariants structurels)
```
state: {scope:{branchId,userId}, lines[], sessionId, serverQuote|null}
selectors: subtotalHt(lines)          // pur, affichage seul
mutations: pures, mono-responsabilité (qty ne touche JAMAIS discount)
discount: dérivé/re-validé par sélecteur, jamais remis à 0 en effet de bord
persist(): 1 writer, debounce, clé scopée
hydrate(scope): unique lecture, gated sur scope présent
submit(): sessionId=Idempotency-Key ; 2xx→reset(rotate) ; 5xx/réseau→enqueue(même sessionId)
```

### 6. Virtualisation & réactivité
Panier + grille items fenêtrés (virtual-scroller), `:key` stable = `item_id + signature(config)`. `subtotal` devient un getter pur (ne réassigne plus `list.total`). Token Sanctum kiosk sorti de localStorage vers un store dédié hors lane panier.

### Invariants
- I1 : une clé localStorage par (surface,branch,user) ; aucune clé non scopée écrite.
- I2 : montant présenté/encaissé == TTC serveur (aucun HT n'atteint tiroir/monnaie).
- I3 : une clé d'idempotence par session panier, identique sur tous les retries jusqu'au succès.
- I4 : aucune commande offline perdue sans ack opérateur.
- I5 : écritures persistées ≤ 1 par fenêtre debounce.


**Contrats de test :**
- Throttle : pour toute séquence de N mutations en T ms, nombre de setItem ≤ ceil(T/300)+1 (property-based, séquences aléatoires add/qty/discount).
- Isolation scope + boot : après setScope(A), reload, setScope(B), B n'observe aucune ligne de A ; aucune clé `vuex` globale ne contient de lignes posCart.
- Idempotence stable : sur N réouvertures de modal et N retries après 5xx, X-Idempotency-Key constant ; ne change qu'après un 2xx déclenchant resetCart.
- Monnaie TTC : change_due == received - serverQuote.total_ttc ; le tiroir ne s'ouvre jamais sur un total ≠ serverQuote.total_ttc (bloqué si quote absente).
- Outbox durable : toute entrée atteint committed XOR dead_letter ; le prune ne supprime jamais dead_letter ; getAbandonedCount monotone jusqu'à ack.
- Non-régression : une mutation qty préserve une remise valide (pas de mise à 0 cachée) ; ADD_ITEM/lists commutatif et associatif pour signatures de config identiques (fuzz d'ordre).

**Migration/rollout :** Ordre (risque croissant) : (1) Outbox dead-letter : file v2, mapper synced/abandoned→committed/dead_letter/pending, bump QUEUE_KEY, migration paresseuse dans _load. (2) sessionId minté au 1er load si panier persisté sans clé. (3) Persistance single-writer + throttle. (4) Retrait posCart/tableCart/kioskCart.* des paths + migration boot : lire vuex legacy, retirer les sous-arbres panier, jeter les données cross-scope. Lire pos_cart_v3 scopé 2h puis stop. (5) /quote serveur d'abord, bascule client vers TTC derrière flag ; repli HT « estimation », tiroir/Confirmer bloqués pour cash si échec. (6) Virtualisation. Token Sanctum hors localStorage à l'étape (4).

---

### ⚙️ Schéma, contraintes, index, intégrité (migrations, modèles, observers, config/database)
**Verdict : `a-refondre`**

**Carte mécanique :**

## Carte mécanique — couche données FoodKing

**Tables pivots & frontières**
- `orders` (`create_orders_table.php`) : FK `user_id`, `branch_id`→`branches` (l.24-25). `status` = `tinyInteger` NOT NULL **sans default** (l.35). Enrichie par ~15 migrations additives : `total_tax` (nullable), `idempotency_key`, `transaction_id`, `fiscal_sequence_no`, `source_surface`, softDeletes.
- `order_items` : FK **indépendantes** `order_id` (l.18) ET `branch_id` (l.19) — aucune contrainte croisée. Taxe stockée par ligne (`tax_rate/tax_type/tax_amount`).
- `transactions` (`create_transactions_table.php`) : `order_id` = `unsignedBigInteger` nu (l.18) — **ni FK, ni UNIQUE, ni index**. `amount` decimal(19,6), `type`/`sign` = strings libres.
- `z_reports` : `unique(branch_id, sequence_no)`, agrégats dénormalisés decimal(15,2), chaîne HMAC `prev_hash/signature`.
- `fiscal_sequence_no` : `unique(branch_id, fiscal_sequence_no)` mais **NULLable** ; **aucun `sealed_at`** (grep=0), aucune garde `Order::updating`.

**Modèles / états**
- `Order` : SoftDeletes + `BranchScope` global (boot l.85), `restoring` **throw** (l.98). `FrontendOrder` = **même table `orders`**, fillable divergent.
- `BranchScope` : isolation **applicative** via sentinelle `branch_id=0`=admin bypass (l.34) ; skip si console/non-auth.

**Flux d'écriture argent (frontière transaction)**
- `PaymentService::payment` (l.13-27) : `Transaction::where(order_id)->first()` **puis** `create()` — TOCTOU, non atomique, hors DB::transaction. `cashBack` (l.35) crée **volontairement** une 2e ligne même order_id.
- Idempotence : `idempotency_key` global UNIQUE → re-scopé `(branch_id, idempotency_key)` par introspection runtime `SHOW INDEX`/`PRAGMA` (`2026_04_18_140003`).
- Audit : `SoftDeleteAuditObserver::deleted` → `DeletionLog::create` dans `try/catch(\Throwable)` (l.30). Loyalty insère via `DB::table()->insert` (bypass scope).

**Appelants** : OrderService, FrontendOrderService, ZReportService, FiscalSequenceService, KDS. `reset_menu_french`/`emergency_purge_english_menu` = DML destructif dans le pipeline migrate.


**Défauts au niveau mécanisme :**

| Sév | Défaut | Pourquoi le design l'autorise | Emplacement |
|:--:|---|---|---|
| 🔴 | transactions.order_id sans UNIQUE, sans FK, sans index — double encaissement structurellem | order_id déclaré en unsignedBigInteger nu (l.18) : rien n'empêche N lignes payment pour un même order. Seule garde = read-then-create de PaymentService::payment (l.15-25), TOCTOU n | `database/migrations/2023_03_23_143747_create_transactions_table.php:18` |
| 🔴 | Migrations DML destructives exécutées dans TOUS les environnements, down() no-op | reset_menu_french et emergency_purge_english_menu font delete()/truncate() sur items, item_categories, variations, extras, addons à chaque migrate. Le garde environment('testing')  | `database/migrations/2026_03_11_999999_emergency_purge_english_menu.php:63` |
| 🔴 | Aucun scellement structurel : pas de sealed_at, fiscal_sequence_no NULLable, aucune garde  | grep sealed_at=0 ; fiscal_sequence_no nullable (l.30) et seule l'unicité (branch,seq) est portée, pas la monotonie/no-gap ni l'immuabilité. Order n'a pas de static::updating bloqua | `database/migrations/2026_04_22_000001_add_fiscal_sequence_no_to_orders.php:30` |
| 🟠 | order_items.branch_id découplé de orders.branch_id — dérive d'isolation et d'assiette fisc | order_items porte sa propre FK branch_id (l.19) sans contrainte composite (order_id,branch_id) référençant orders. Une ligne peut pointer une branche différente de sa commande ; Br | `database/migrations/2022_11_17_110832_create_order_items_table.php:19` |
| 🟠 | Drift sqlite:memory ↔ MySQL : FK et contraintes masquées en test | add_fks_to_item_branch_availability return early si sqlite (l.24-25) → les FK item_id/branch_id n'existent JAMAIS sous les tests mais existent en prod. indexExists s'appuie sur SHO | `database/migrations/2026_04_18_140001_add_fks_to_item_branch_availability.php:24` |
| 🟠 | Soft-delete court-circuité : enfants hard-deletes, restore bloqué, audit avalé | orders/order_items/branches ont softDeletes mais OrderAddress/OrderCoupon sont hard-deletes ; Order::restoring throw (l.98). SoftDeleteAuditObserver::deleted enveloppe DeletionLog: | `app/Observers/SoftDeleteAuditObserver.php:30` |
| 🟠 | loyalty_transactions : ledger 'immuable' détruit par cascade user, unicité inopérante | FK user_id onDelete('cascade') (l.35) supprime tout l'historique de points à la suppression d'un user, contredisant l'immuabilité. unique(user_id,order_id,type) est inopérant quand | `database/migrations/2026_03_26_075918_create_loyalty_transactions_table.php:35` |
| 🟡 | orders.order_datetime : default = date('y-m-d h:m:s') gelé à la migration et format erroné | Le default est évalué par PHP au run de migration, pas à l'INSERT : toute commande sans order_datetime explicite hérite d'une constante figée. De plus 'y-m-d h:m:s' est faux (y=ann | `database/migrations/2022_11_17_110810_create_orders_table.php:31` |

**🎯 Redesign cible :**

## Architecture cible — intégrité portée par le schéma

**Principe** : chaque invariant devient une contrainte DB (UNIQUE/FK/CHECK/NOT NULL/colonne d'état), pas une garde de service contournable par `DB::table()`.

### 1. Money & transactions
- `transactions.order_id` NOT NULL, **FK** vers `orders`, **index** dédié. Ajouter `branch_id` NOT NULL (FK) et `direction` ENUM(`debit`,`credit`).
- **UNIQUE partiel** `(order_id) WHERE type='payment' AND direction='credit'` (MySQL 8 : colonne générée + unique) → au plus une capture par commande, garantie DB, pas par TOCTOU.
- Écritures via un `MoneyMutationService::apply()` unique dans `DB::transaction()` + `lockForUpdate` sur l'order ; PaymentService devient appelant, plus créateur. `CHECK(amount>=0)`, signe porté par `direction`.

### 2. Scellement fiscal (NF525)
- Colonne `sealed_at` sur `orders`. `fiscal_sequence_no` **NOT NULL** post-backfill, allouée par table `fiscal_counters(branch_id, next_seq)` en rowlock → monotonie/no-gap portés par la DB.
- Garde `Order::updating` + trigger DB : si `sealed_at IS NOT NULL`, tout diff sur {total, subtotal, total_tax, status→annulation, fiscal_sequence_no} lève. Correction = avoir lié, jamais mutation.

### 3. Isolation par branche (structurelle)
- Détruire la sentinelle `branch_id=0` : admin porté par spatie/permission, pas une valeur magique. `orders/order_items.branch_id` NOT NULL, FK réelles.
- UNIQUE `orders(id,branch_id)` + **FK composite** `order_items(order_id,branch_id)→orders(id,branch_id)` → une ligne ne peut jamais dériver de branche. Cible : RLS ou vues filtrées pour que même `DB::table()` respecte l'isolation.

### 4. Idempotence
- `idempotency_key` NOT NULL sur canaux borne/web (default serveur), **UNIQUE(branch_id, idempotency_key)** défini **une fois** en migration de création, pas reconstruit par introspection runtime.

### 5. Soft-delete & audit
- SoftDeletes cohérent sur tout l'aggregate (OrderAddress, OrderCoupon) OU delete interdit sur order scellé.
- `DeletionLog`/`AuditLog` : `branch_id` NOT NULL + FK, écriture **dans la même transaction** que la suppression (échec audit ⇒ rollback), plus de `catch(\Throwable)` silencieux.
- `loyalty_transactions` : FK user `RESTRICT` (jamais cascade), unicité conditionnelle sur order_id, `balance_after` dérivé du ledger (source unique = ledger).

### 6. Parité CI/prod
- Suite de tests sur **MySQL**, FK toujours activées ; interdire les `return si sqlite`. Money en decimal partout, jamais REAL.
- Migrations structurelles pures : sortir tout DML de menu (reset/purge) vers seeders/commands, jamais dans `migrate`.

**Invariants portés par la DB (cible)** : UNIQUE capture · FK transactions.order_id · sealed_at+trigger · FK composite ligne/branche · UNIQUE(branch,idempotency) · fiscal_seq NOT NULL monotone · audit transactionnel.


**Contrats de test :**
- Property: pour tout order, count(transactions WHERE type=payment,direction=credit) <= 1, sous N insertions concurrentes (course, MySQL réel) — la contrainte DB rejette la 2e.
- Invariant: toute order_items.branch_id == orders.branch_id du parent (fuzz sur inserts) — la FK composite rejette toute divergence.
- Property: une fois sealed_at != null, tout update de {total, total_tax, fiscal_sequence_no, subtotal} lève et laisse la ligne inchangée (Order::updating + trigger).
- Invariant no-gap/monotone: pour une branche, la suite fiscal_sequence_no allouée sous concurrence est strictement croissante et sans trou.
- Parité: la même migration produit le même ensemble de FK/UNIQUE sous MySQL et sous le driver de test (assert information_schema == pragma) — échoue si une FK est skipée en sqlite.
- Audit transactionnel: si l'écriture DeletionLog/AuditLog échoue, la suppression/mutation est rollback (aucun order supprimé sans ligne d'audit).

**Migration/rollout :** Ordre (additif puis contraignant) : 1) Backfill : dédupliquer transactions, renseigner transactions.branch_id/direction, combler order_items.branch_id depuis le parent, allouer fiscal_sequence_no aux orders legacy, poser sealed_at sur les orders clôturés. 2) Colonnes NULLables + index (pas de lock long). 3) Contrôles CI zéro-violation. 4) NOT NULL + UNIQUE capture, FK transactions.order_id, UNIQUE orders(id,branch_id) puis FK composite order_items, trigger sealed. 5) Détruire branch_id=0 : admins vers rôle, réécrire BranchScope. 6) Sortir reset_menu/purge des migrations. Rollback testé sur copie prod MySQL, jamais sqlite.

---

### ⚙️ Session caissier / especes / cloture POS (Shift, rendu de monnaie, Transaction, ZReport)
**Verdict : `a-refondre`**

**Carte mécanique :**

## Carte mecanique

### Encaissement especes (chemin reel)
1. `PosComponent.vue:1446` : `form.total = subtotal + delivery - discount` — **pre-taxe** (assume `:427-429` "Display total here is pre-tax"). `subtotal` = getter `posCart/subtotal` (`:891`), somme HT catalogue.
2. `PaymentComponent.vue` : la saisie alimente `cashReceivedRaw` (`:154`). `cashChange` (`:139-143`) = `received - form.total` → **monnaie sur base HT**, affichee "Monnaie a rendre" (`:51-59`).
3. `confirmOrder` (`:193`) lit le DOM (`:201-203`), pose `pos_received_amount`, dispatch `posOrder/save`. Sur CASH, `openDrawer()` (`:222`) ouvre le tiroir cote client.

### Backend (verite)
- `OrderService::posOrderStore` (`:549`, `DB::transaction:563`) : Order cree avec `payment_status=PAID` en dur (`:591`) avant calcul.
- Recalcul PricingService (`:604-627`) : `total = subtotal + total_tax - discount` → **TTC**, persiste (`:813-823`).
- Garde especes (`:828-835`) : rejet si `pos_received_amount < order.total` (**TTC**).
- Reserve `fiscal_sequence_no` (`:862`), `save()`. **Aucun `Transaction::create` sur le chemin POS** (seuls `PaymentService:17` online et `deliveryBoyOrderChangeStatus:1379`).

### Reçu / restitution
- `OrderDetailsResource:56-57` : `cash_back = pos_received_amount - total` (**TTC**). `transaction` (`:48`) = null pour toute vente POS. `buildTaxLines:74` recompose HT/TVA par taux.

### "Cloture" (Z fiscal, pas session caisse)
- `ZReport` : modele nu (fillable/casts seulement). Table : `total_ht/ttc/tva`, `total_by_method` JSON, `sequence_no` unique/branche, chaine HMAC.
- `ZReportService::open` (`:44`) reserve une sequence — **aucun fond d'ouverture**. `close` (`:102`) agrege + signe.
- `aggregate` (`:181`) : fenetre `(opened_at, closed_at]` sur `created_at` (`:207-214`), somme `order.total` en `byMethod` clave par `order.pos_payment_method` scalaire (`:233-234`). `total_ht=subtotal`, `total_tva=total_tax` (`:229-230`).

### Frontieres de transaction
Une seule : la `DB::transaction` de `posOrderStore`. La cloture Z est independante. Aucun `orders.z_report_id` ni `shift_id`. Appelants : `ZReportController` (perm `pos-manage-fiscal`), UI POS.


**Défauts au niveau mécanisme :**

| Sév | Défaut | Pourquoi le design l'autorise | Emplacement |
|:--:|---|---|---|
| 🔴 | Aucune entite Shift : ni fond de caisse, ni comptage, ni ecart | Le seul primitif de 'cloture' est ZReport, un Z fiscal (CA signe). open() ne saisit aucun fond, close() n'exige aucun comptage physique ni ecart : la structure ne modelise que le C | `app/Services/Fiscal/ZReportService.php:75` |
| 🔴 | Commande POS marquee PAID sans aucune ligne Transaction | posOrderStore ecrit payment_status=PAID en dur puis save(), sans Transaction::create. Le grand livre (table transactions) est aveugle a 100% des ventes POS. En cascade : ZReport ag | `app/Services/OrderService.php:591` |
| 🔴 | Rendu de monnaie calcule sur base HT cote caissier, TTC cote backend/recu | form.total est pre-taxe (PosComponent 1446). cashChange=received-form.total rend trop de monnaie du montant de la TVA sur toute vente taxee. Le backend valide TTC (828) et le recu  | `resources/js/components/admin/pos/PaymentComponent.vue:139` |
| 🟠 | Split payment impossible : moyen de paiement scalaire unique | orders.pos_payment_method est un tinyint unique et PosOrderRequest n'accepte qu'un seul pos_payment_method + un seul pos_received_amount. Impossible de regler partie especes / part | `app/Http/Requests/PosOrderRequest.php:84` |
| 🟠 | Montant tendu scalaire, monnaie rendue jamais persistee | pos_received_amount est une colonne unique ; le cash_back est un calcul derive (received-total) recompute a l'affichage, jamais stocke. Aucune ligne de tender immuable (montant don | `app/Http/Resources/OrderDetailsResource.php:56` |
| 🟠 | Identite fiscale HT+TVA=TTC rompue dans l'agregat Z signe | aggregate somme total_ttc=order.total mais total_ht=subtotal, total_tva=total_tax. Or total=subtotal+tax-discount, donc total_ht+total_tva=total_ttc+discount : des qu'une remise ex | `app/Services/Fiscal/ZReportService.php:228` |
| 🟠 | Fenetre Z purement temporelle sans z_report_id : commandes orphelines | aggregate borne par (open.opened_at, closed_at] sur created_at, sans colonne orders.z_report_id. Toute commande creee entre une cloture et la reouverture suivante tombe hors de tou | `app/Services/Fiscal/ZReportService.php:212` |
| 🟡 | total_by_method derive d'un scalaire, cles heterogenes | byMethod est clave par order.pos_payment_method (int 1..4) avec fallback sur payment_method (slug gateway string) ou 'unknown'. Les cles melangent entiers POS et slugs online dans  | `app/Services/Fiscal/ZReportService.php:233` |

**🎯 Redesign cible :**

## Architecture cible

### 1. Entite `Shift` (session caissier) — le chainon manquant
Table `pos_shifts` : `branch_id, register_id, opened_by, opened_at, opening_float_cents, closed_by, closed_at, status(open|closed), expected_by_method JSON, counted_by_method JSON, variance_by_method JSON, z_report_id?`.
- `ShiftService::open(branch,user,openingFloat)` : une seule session ouverte par (branch,register) — contrainte d'unicite partielle `status=open`. Saisit le **fond de caisse** en centimes entiers.
- `ShiftService::close(shift,countedByMethod[])` : `expected_by_method` calcule depuis le grand livre (transactions du shift), `variance = counted - expected` par moyen ; especes attendues = `opening_float + Σcash_in - Σcash_out - Σchange`. Persiste l'ecart, verrouille.
- `orders.shift_id` + `transactions.shift_id` NOT NULL. Invariant porte : **toute vente POS appartient a une session ouverte** (FK + garde refusant l'encaissement sans session).

### 2. `Transaction` systematique via `MoneyMutationService`
Pas de `payment_status=PAID` sans Transaction dans la meme transaction DB. `posOrderStore` delegue a `MoneyMutationService::recordSale(order, tenders[])` qui, **dans la transaction existante** :
- ecrit une ligne Transaction **par tender** (amount TTC en centimes, payment_method, shift_id, type=payment, tendered_cents, change_cents pour l'especes) ;
- pose PAID uniquement si `Σtenders.amount >= order.total`.
Invariant : `Σtransactions(sign+) - Σ(sign-) = order.total`, verifie par garde. ZReport et Shift agregent **depuis transactions**, plus jamais depuis un scalaire.

### 3. Split payment — modele `Tender[]`
Payload POS `tenders: [{method, amount_cents, tendered_cents?}]`. Regles : `Σamount == order.total` (TTC), au plus un tender especes portant du rendu, `change = tendered - cash_amount` **persiste**. `orders.pos_payment_method` devient derive/legacy (moyen dominant), n'est plus source d'agregation.

### 4. Assiette TTC a la source (rendu correct)
Le front cesse d'exposer un total HT. PricingService renvoie `total_ttc`, utilise partout : affichage, numpad, `cashChange = tendered - total_ttc`. Une seule verite (TTC), identique au recu (`cash_back` lit `transaction.change_cents`). Suppression du calcul HT `PosComponent.vue:1446`.

### 5. Contrats
- `ShiftService::open(): Shift`, `close(counted): Shift` (idempotent, verrou cache par register).
- `MoneyMutationService::recordSale(Order, Tender[]): Transaction[]` — atomique, centimes entiers, exige `activeShift(branch,register)`.
- `ZReportService::aggregate` : borne par `z_report_id`/`shift_id` (rattachement explicite), `total_ttc = Σtransactions`, `total_ht/tva` recomposes depuis `order_items` garantissant `HT+TVA-remise=TTC`.

### 6. Invariants structurels
- Pas d'encaissement sans session ouverte (FK NOT NULL + garde).
- PAID ⟺ Transaction(s) couvrant le total.
- Rendu = tendered - total **TTC** (formule unique, centimes).
- Chaque commande rattachee a un Shift et un Z (colonnes + unicite).
- Ecart = counted - expected, materialise et signe a la cloture.


**Contrats de test :**
- Invariant PAID: pour toute commande POS PAID, Σ transactions(sign+).amount - Σ(sign-) == order.total (property-based sur paniers/remises aleatoires)
- Rendu TTC: pour tout panier taxe, cashChange affiche == pos_received - order.total_TTC, et == transaction.change_cents/100 (front == recu == ledger)
- Split: Σ tenders.amount == order.total sinon 422 ; total_by_method du Z == Σ transactions groupees par methode, pas order.pos_payment_method
- Session obligatoire: encaissement POS sans Shift ouvert => refus ; chaque order porte shift_id == session active de sa (branch,register)
- Rapprochement: apres N ventes especes, expected_cash == opening_float + Σ cash_in - Σ change ; variance == counted - expected (property-based)
- Identite fiscale Z: total_ht + total_tva - remises == total_ttc pour tout ensemble de commandes, taux multiples inclus

**Migration/rollout :** Ordre : (1) `pos_shifts` + colonnes `orders.shift_id`, `transactions.shift_id/tendered_cents/change_cents` (nullable). (2) Backfill : Shift synthetique par (branch,jour) + 1 Transaction par commande POS PAID (amount=order.total, method=pos_payment_method, change derive de pos_received_amount). (3) Rattacher order/transaction au shift et au Z couvrant leur created_at. (4) Front TTC + payload tenders derriere flag `pos.split_tenders`. (5) shift_id NOT NULL + garde 'no sale without open shift' apres verif. Compat : pos_payment_method conserve en colonne derivee ; fallback scalaire du Z tant que compteur commandes-sans-transaction != 0. Rollback : flag off, aucune donnee detruite.

---

### ⚙️ Suite de tests & portes qualité (CI/CD) — FoodKing
**Verdict : `a-refondre`**

**Carte mécanique :**

## Carte mécanique

### Portes CI (le vrai gate)
- `phpunit.yml` — PR/push `main|develop`. MySQL 8 + Redis. `vendor/bin/phpunit --testdox` (l.108) + re-run filtré `FrontendSurfaceFilteringTest` (l.118). Seul job qui exécute PHPUnit.
- `playwright.yml` — PR. `migrate:fresh --seed` → `npm ci && npm run prod` (build mix, l.57) → `npx playwright test` (l.76). **Ne lance jamais Vitest** : le seul npm est le build prod.
- **Aucun autre workflow** : pas de PHPStan/Psalm, Infection, ESLint, `pint --test`, ni `npm run test`.

### `phpunit.xml`
- Suites `Unit`+`Feature` (l.8-13). **DB défaut = sqlite :memory:** (l.24) ; CI surcharge en MySQL via env de job → dev et CI n'exécutent pas le même moteur.
- `<coverage>` l.15-19 : `processUncoveredFiles=true`, include `./app`, **aucun seuil, aucun `--min`, aucun clover** → couverture calculée puis jetée.

### `vitest.config.mjs`
- `include tests/js/**/*.spec.js`, happy-dom. `package.json` `"test":"vitest run"`. **52 specs** (KioskWizard 54 Ko, kioskPricingPreview, posCartScoped...) **exécutés par zéro workflow.**

### `playwright.config.js`
- `retries:1` (l.10), 6 specs smoke. Ex. `04-kds-status.spec.js:20` accepte l'URL cible OU `/login` ; assertion de fond = `not.toMatch(/Whoops|Fatal error/i)`. Aucune assertion pricing/statut/visuelle.

### Familles
- `find tests -iname *invariant*|*property*` = **vide** : aucun invariant du CLAUDE.md n'a de famille dédiée.
- **49 lignes** `in_array($response->status(),[...])` mêlant succès et échec : AntiGravityTest (19), SecurityComprehensiveTest (10), POS/Sync Comprehensive.
- `TaxCalculatorTest` étend PHPUnit\TestCase (l.7) : arithmétique isolée, ne prouve pas que la persistance appelle le calculateur (assiette à la source non prouvée bout-en-bout).


**Défauts au niveau mécanisme :**

| Sév | Défaut | Pourquoi le design l'autorise | Emplacement |
|:--:|---|---|---|
| 🔴 | Vitest orphelin — 52 specs front ne gardent aucun merge | Aucun workflow ne référence vitest ; playwright.yml n'appelle npm que pour `npm run prod` (build, l.57). Une régression du pricing front (aperçu, payload panier) part en prod avec  | `.github/workflows/playwright.yml:57` |
| 🔴 | Assertions tolérantes 'vertes pour toujours' | assertTrue(in_array($status,[200,201,400,401,403,422])) : l'ensemble accepté couvre succès ET échecs, donc tout chemin atteignable satisfait l'assertion. Le test ne peut structurel | `tests/Feature/AntiGravityTest.php:106` |
| 🔴 | Test d'invariant vacant : transition jamais atteinte | POST change-status/1 sans créer d'Order → 404 (ligne absente) satisfait l'ensemble accepté [401,403,404,422]. Le garde de transition n'est jamais exécuté ; l'invariant 'transition  | `tests/Feature/OrderStateTransitionTest.php:19` |
| 🟠 | ZReportBoundaryTest se prouve lui-même (O2==C1 construit) | Le test pose Z2.from:=Z1.to à la main en appelant aggregate() : il valide l'arithmétique demi-ouverte mais n'exerce jamais l'enchaînement réel close→open ni le `from` persisté. La  | `tests/Feature/Fiscal/ZReportBoundaryTest.php:131` |
| 🟠 | ConcurrentOrderTest sans aucune concurrence | SQLite :memory: = connexion unique, client monothread en série : la course d'idempotence ne peut jamais se manifester, la 2e requête voit la ligne committée. Le nom affirme un inva | `tests/Feature/ConcurrentOrderTest.php:62` |
| 🟠 | Écart parité SQLite(dev)/MySQL(CI) avec skips silencieux | phpunit.xml impose sqlite par défaut ; seul l'env CI bascule MySQL. FK, JSON_CONTAINS, triggers diffèrent ; markTestSkipped sur SQLite (ItemBranchAvailabilityFkTest:21, ItemCategor | `phpunit.xml:24` |
| 🟠 | Aucune porte de couverture ni de mutation | Coverage calculé sans seuil ni `--min`, aucun Infection ne mesure la force des assertions. Les tests tolérants produisent de la couverture de ligne en tuant zéro mutant : les 33 cr | `phpunit.xml:15` |
| 🟡 | Playwright retries=1 + assertions smoke masquent les régressions | retries:1 relance un spec échoué (flake→vert). Les specs n'assertent que l'absence de 'Whoops/Fatal error' et une regex acceptant /login OU la surface : une régression de redirecti | `playwright.config.js:10` |

**🎯 Redesign cible :**

## Architecture cible — Portes qualité & suite

### Principe
Une porte ne vaut que par sa capacité à échouer. On remplace la couverture-vanité par la **force d'assertion mesurée** (mutation testing), et chaque invariant du CLAUDE.md devient une **famille dédiée, exécutée, bloquante**.

### 1. CI à tiers (tous bloquants, coût croissant)
```
T0 lint      pint --test, php -l, eslint, prettier
T1 static    phpstan lvl 6+ + psalm sur domaine fiscal
T2 unit-fast phpunit Unit (sqlite ok) + vitest run  ← Vitest enfin gardé
T3 feature   phpunit Feature sur MySQL UNIQUEMENT (pas de fallback)
T4 invariants @group invariant, property-based, MySQL+Redis réels
T5 mutation  Infection sur Pricing/Fiscal/Domain, MSI min
T6 e2e+visuel Playwright retries=0 + snapshots
T7 prod-parité nightly : soketi réel, Stripe test, dump anonymisé
```
Merge bloqué tant que T0–T5 rouges ; T6 bloquant sur PR ; T7 gate de release.

### 2. Contrats structurels
- **`tests/Invariant/`** `@group invariant`, une classe par invariant (PricingSourceOfTruth, BranchIsolation, TransitionLattice, Nf525, OutboxAtomicity). Base `InvariantTestCase` : setUp `fail()` si driver≠mysql (jamais skip).
- **Trait `StrictHttpAssertions`** remplace `in_array($status,[...])` : `assertRejected` = 403 exact (autz) ou 422 exact (validation), jamais les deux. Un test qui ignore le code attendu ne teste rien.
- **Générateurs property-based** (php-quickcheck) : Money, OrderLine, TimeBoundary, BranchPair — propriétés quantifiées 200+ cas.

### 3. Invariants portés
- **Pricing** : forAll(payload total falsifié) ⇒ order.total==PricingService::recompute ; la valeur client est ignorée, pas rejetée.
- **Branche** : balayage routeur, forAll route×tokenÉtranger ⇒ 403 strict ET zéro ligne étrangère ; branch_id==0 détruit.
- **Transitions** : Order réelle créée par cas ; forAll(from,to) ⇒ (to∈allowed(from)) XOR 422 ; aucun 404 accepté.
- **NF525** : close(Z1)→open(Z2) via service réel ; Z2.from lu de l'état persisté==Z1.to, fenêtre sur sealed_at ; Σaggregate(Zi)==aggregate(total).
- **Outbox** : transaction avortée ⇒ ligne métier ⇔ event outbox, jamais l'un sans l'autre.

### 4. MySQL seule vérité
Retirer `DB_CONNECTION=sqlite` de phpunit.xml ; `phpunit.sqlite.xml` séparé pour la boucle unit rapide. Feature/Invariant refusent de tourner hors MySQL. Supprimer les `markTestSkipped('MySQL-only')` : ce chemin devient le normal.

### 5. Anti-flake / e2e
Playwright `retries:0`. Assertions positives fortes (URL exacte par rôle, éléments métier), snapshots visuels POS/kiosk/KDS, projet e2e comparant total affiché vs backend.

### 6. Mutation = juge d'assertion
Infection ciblé Pricing/Fiscal/Domain, MSI min bloquant (départ 60 %, cliquet). C'est ce tier qui tue les 8 défauts : un test tolérant survit à tout mutant → MSI chute → CI rouge.


**Contrats de test :**
- Pricing (property): forAll payload total/subtotal client falsifiés ⇒ order persisté == PricingService::recompute ; valeur client IGNORÉE (muter recompute en 'trust client' doit tuer le test).
- Isolation branche (balayage routeur): forAll route order-scoped × token branche étrangère ⇒ 403 strict ET aucune ligne étrangère dans le corps ; cas dédié branch_id==0.
- Treillis transitions: VRAIE commande créée par cas ; forAll(from,to) ⇒ (to∈allowed(from)) XOR 422 ; aucun 404 accepté (corrige le test vacant).
- NF525 partition Z: close(Z1)→open(Z2) via service réel ; Z2.from lu de l'état persisté == Z1.to, fenêtre sur sealed_at ; Σ aggregate(Zi)==aggregate(total), ni gap ni double-count.
- Atomicité outbox: transaction avortée injectée ⇒ property (ligne métier persistée ⇔ event outbox persisté), jamais l'un sans l'autre.
- Concurrence idempotence réelle: 2 requêtes parallèles (process forkés / 2 connexions MySQL) même clé ⇒ exactement 1 order + numéro de file unique ; échoue si l'unicité repose sur SELECT-then-INSERT.

**Migration/rollout :** Ordre: 1) T0/T1/T5 en continue-on-error pour baseline sans casser les PR. 2) Cabler Vitest (T2) bloquant tout de suite: risque nul, il ne tournait pas. 3) Migrer les 49 assertions tolerantes via StrictHttpAssertions fichier par fichier; chaque conversion peut reveler un vrai bug prod (ticket, pas regression). 4) Basculer phpunit.xml sur MySQL par defaut apres validation des tests SQLite-skipes; garder phpunit.sqlite.xml pour l'unit. 5) Creer tests/Invariant/ en parallele sans supprimer l'existant. 6) Apres ~2 sprints stables, retirer continue-on-error et fixer les seuils. 7) Playwright retries:0 en dernier. Infra: MySQL/Redis deja presents; seul T7 exige un dump anonymise.

---

### ⚙️ Chaîne de build / bundle / images / transport front (webpack.mix.js, package.json, master.blade.php, public/.htaccess, app/Models/Item.php, resources/js/app.js + bootstrap.js)
**Verdict : `a-refondre`**

**Carte mécanique :**

## Carte mécanique

**Entrée build unique** — `webpack.mix.js:13` : `mix.js('resources/js/app.js','public/js').vue().postCss('app.css')`. Aucun `.version()`, `.extract()`, `splitChunks`, ni `webpackConfig`. Une seule entrée.

**Graphe JS** — `app.js:9` importe `./bootstrap` (lodash complet + `bootstrap` + Echo/Pusher, `bootstrap.js:1-27`), `app.js:17` `bootstrap-kiosk`. Routeur : `router/modules/*` → 155 refs `component:` en imports **eager** (`frontendRoutes.js:1-13`), **1 seule lazy** `()=>` sur ~330 `.vue`. Monolithe.

**Manifest** — `public/mix-manifest.json` : mapping **identité sans hash** `{app.js→app.js, kiosk.js→kiosk.js, app.css→app.css}`.

**Désync disque** (frontière build/artefact rompue) : `public/js/app.js` **ABSENT** (seul `app.js.LICENSE.txt` subsiste), `kiosk.js` 525Ko présent mais **ni construit par mix ni référencé**, `pos-wizard.js` 287Ko **éditée à la main** dans `public/`.

**Transport HTML** `master.blade.php` : Google Fonts distantes render-blocking (`:11-13`) + 4 CSS fonts locales + `custom.css` + `mix('css/app.css')` + `pos-wizard.css?v=2-{time()}` (`:14-22`). Scripts : `mix('js/app.js')` (`:113`) + 5 JS thème `asset()` sans version (`:114-118`) + `pos-wizard.js?v=9-{time()}` (`:128`). Config runtime inline `:77-127`.

**Cache-buster** `time()` → URL unique **par requête** → 0 cache sur pos-wizard.*.

**HTTP** `public/.htaccess:1-30` : aucun `mod_expires`/`Cache-Control`/`immutable`.

**Images** `Item::registerMediaConversions` (`Item.php:120-125`) : thumb/cover/preview **tous** `keepOriginalImageFormat()` → PNG conservé ; driver `gd` (`media-library.php:146`). `getThumbAttribute` fallback (`Item.php:90-99`) renvoie un PNG plein format 2Mo comme « thumb ». Total 114Mo, 227 rasters, **0 webp**.


**Défauts au niveau mécanisme :**

| Sév | Défaut | Pourquoi le design l'autorise | Emplacement |
|:--:|---|---|---|
| 🔴 | app.js absent du disque mais servi par mix() → SPA 404 en prod | Aucun versioning par hash de contenu + manifest maintenu à la main + build non rejoué en CI : l'état source (mix ne construit qu'app.js), le disque (app.js manquant, kiosk.js 525Ko | `public/mix-manifest.json:2` |
| 🟠 | Monolithe : tout le front dans un seul chunk, aucune découpe par surface | Entrée webpack unique + 155 imports de composants eager (1 seule lazy sur 330 .vue) : webpack agrège kiosk+KDS+OSS+admin+frontend dans un bundle. La caisse POS télécharge et parse  | `webpack.mix.js:13` |
| 🟠 | Cache-buster time() → re-téléchargement intégral à chaque page | ?v=...-{time()} est recalculé à chaque rendu Blade : l'URL est neuve à chaque requête, donc navigateur ET proxy ne peuvent jamais réutiliser l'entrée. 287Ko (pos-wizard.js) + 41Ko  | `resources/views/master.blade.php:128` |
| 🟠 | Aucun header de cache sur les statiques | Le .htaccess ne pose ni Expires ni Cache-Control ni immutable : chaque asset est revalidé (304 au mieux, download complet sans ETag). Combiné à l'absence de hash de contenu, aucune | `public/.htaccess:24` |
| 🟠 | keepOriginalImageFormat() fige le PNG sur toutes les conversions | Force la conservation du format source même quand l'optimizerChain Cwebp existe : une vignette 168px reste un PNG lourd, jamais transcodée en webp/avif. Le driver gd empire le poid | `app/Models/Item.php:122` |
| 🟠 | Fallback 'thumb' renvoie le fichier source plein format 2Mo | getThumbAttribute, à défaut de media, retourne asset('images/menu/xxx.png') — le PNG source non redimensionné — comme valeur de l'attribut thumb. Une grille POS de N items charge d | `app/Models/Item.php:94` |
| 🟡 | Double framework CSS + pipeline CSS éclaté non purgé | bootstrap est importé dans le bundle (bootstrap.js:4) en plus de Tailwind (app.css), custom.css et pos-wizard.css : deux systèmes de layout coexistent, le CSS est dupliqué et n'est | `resources/js/bootstrap.js:4` |
| 🟡 | Google Fonts distantes render-blocking en tête de head | Le <link> vers fonts.googleapis.com (6 graisses Inter) bloque le rendu sur une dépendance réseau externe : sur borne kiosk en LAN/offline, le first paint attend un timeout cross-or | `resources/views/master.blade.php:13` |

**🎯 Redesign cible :**

## Architecture cible

**1. Bundler : Vite multi-entrées par surface.** Remplacer laravel-mix par `laravel-vite-plugin`. Une entrée par contexte de chargement : `pos.js`, `kiosk.js`, `kds.js`, `oss.js`, `online.js`, `admin.js`. Chaque entrée n'importe que son sous-arbre `resources/js/apps/<surface>/`. Router découpé par surface ; vues restantes en `defineAsyncComponent` / `() => import()` → route-level code-splitting. Contrat : le graphe POS ne référence AUCUN module `apps/kiosk|kds|oss|admin`.

**2. Versioning immutable par hash de contenu.** Vite émet `build/assets/<name>.<hash>.js`. Blade consomme via `@vite(['resources/js/apps/pos.js'])` qui lit `build/manifest.json` généré par le build (jamais édité à la main). Suppression totale de `time()` et de `?v=`. Invariant : URL statique = fonction pure du contenu ; deux builds du même source → mêmes URLs ; un changement → nouvelle URL.

**3. Politique HTTP à deux régimes.** `.htaccess`/vhost : `build/assets/*` → `Cache-Control: public, max-age=31536000, immutable` ; HTML → `no-store`. `pos-wizard.*` réintégrés comme vraies entrées Vite hashées (fin de l'édition manuelle dans `public/`). Suppression de `kiosk.js`/`app.js.LICENSE` orphelins.

**4. Images : conversions WebP pré-générées.** `registerMediaConversions` : retirer `keepOriginalImageFormat()`, forcer `->format('webp')` (ou AVIF) sur thumb(168)/cover(390)/preview(600) ; driver `imagick`. `getThumbAttribute` NE renvoie JAMAIS un source plein format : fallback vers une conversion ou un placeholder SVG léger, jamais `images/menu/*.png` brut. Pipeline offline `artisan media:regenerate` + optimizer Cwebp actif. Cibles : thumb < 20Ko, cover < 60Ko. Servir `<picture>` + `srcset` webp/avif + fallback.

**5. Fonts self-host.** Inter woff2 subsetté dans `public/fonts`, `font-display: swap`, `preload` local. Zéro dépendance cross-origin au first paint → kiosk offline OK.

**6. Un seul système de style.** Tailwind OU Bootstrap, pas les deux ; retirer `import 'bootstrap'` du bundle si Tailwind gagne. Purge Tailwind par surface (content globs scoping). CSS critique inline minimal, le reste hashé/immutable.

**7. Config runtime hors bundle.** `window.foodkingConfig` reste injecté server-side (OK) mais isolé via `<script nonce>` CSP ; le bloc credentials démo (`master.blade.php:97-110`) sort du HTML de prod (gate stricte).

**8. Frontières portées structurellement.** manifest = artefact de build (jamais commité édité) ; budget de taille par surface en CI (échec si dépassé) ; parité build : `npm run build` en CI produit exactement le déployé (tier prod-parité, levier 7).


**Contrats de test :**
- Parité build/manifest/disque : après `npm run build` en CI, chaque chemin du manifest existe sur disque ET aucun artefact sur disque n'est absent du manifest (échec si app.js manquant ou kiosk.js orphelin).
- Invariant versioning (property) : pour toute URL statique servie, aucune ne contient time()/?v mutable ; deux rendus successifs de la même page produisent des URLs identiques, un changement de contenu produit une URL différente.
- Budget par surface : le bundle d'entrée POS ne contient aucun module issu de apps/kiosk|kds|oss|admin (analyse du graphe) et son poids gzip reste sous le seuil défini.
- Régime cache HTTP : toute réponse sur build/assets/*.{js,css} porte Cache-Control immutable max-age>=31536000 ; toute réponse HTML porte no-store.
- Conversion image (property) : pour tout media 'item', getUrl('thumb') retourne un webp de largeur<=168 et poids<=20Ko ; aucun attribut thumb/cover/preview ne résout vers un fichier source plein format.
- Kiosk offline : rendu de master.blade sans réseau externe → zéro requête cross-origin bloquante (fonts self-host), first paint obtenu.

**Migration/rollout :** Ordre : (1) Vite en parallèle de mix, surface par surface (POS d'abord), anciennes URLs gardées un déploiement. (2) Régénérer les media en webp via job idempotent AVANT de basculer getThumb/getCover, sinon getUrl('thumb') 404 ; placeholder pendant la régé. (3) Poser les headers immutable UNIQUEMENT une fois le hashing actif. (4) Retirer time() et les artefacts manuels public/js/pos-wizard.* une fois réintégrés en entrées Vite. (5) Self-host fonts et retrait du lien Google en dernier. Compat : window.foodkingConfig inchangé ; seule la table media change (réversible). Rollback : garder l'ancien mix-manifest jusqu'à validation Playwright.

---

## 2. Revue croisée (design review board)

> Chaque teardown a été attaqué par un principal engineer pair. **16/16 : `ajuster`** — les redesigns tiennent mais l'équipe a relevé des angles morts et des risques de régression récurrents :

**Angles morts récurrents relevés par les pairs :**
- transactions.order_id est PARTAGE Order/FrontendOrder sans discriminant (migration 2023: pas d'order_type; FrontendOrderService ecrit order_id=frontendOrder->id l.486). UNIQUE(order_id,type) devient collisionnable entre les 2 espaces d'id.
- 'Reutilise apply()' est faux: OrderStateMachine::apply (l.155-170) fait un save() nu, ni SELECT FOR UPDATE ni UPDATE WHERE status=from. La garde portee-persistance (etape 3) n'y existe pas -> reecriture, pas reutilisation.
- apply() appelle recordTransition qui AVALE les exceptions (catch Throwable+Log l.108-110). Reutiliser apply() contredit l'exigence 'audit non best-effort qui rollback'. recordTransition doit etre reecrit, pas active tel quel.
- Responsabilite (a) creation+pricing non refondue: posOrderStore (fiscal_sequence SAVEPOINT l.862, alloc queue Cache::lock, idempotence 23000) reste monolithique. Le 'casse en 3' n'en concoit que 2; le service de creation reste flou.
- Autorisation heterogene collapsee sans acteur CLIENT: self-cancel=ownership (user_id==Auth::id l.1430) vs staff=branche vs admin=role. Actor.isGlobalAdmin ne modelise pas le proprietaire client -> risque de casser le self-cancel.
- FrontendOrder::changeStatus (l.635) + finalizePaidKioskOrder (cycle table/kiosk) non mappes; le 'KDS path' cite n'est localise nulle part dans le teardown.
- Compteur durable spécifié en `UPDATE ... RETURNING` : non supporté MySQL ni SQLite<3.35 (tests sur SQLite). Le mécanisme central ne compile pas ; il faut SELECT FOR UPDATE + UPDATE.
- "Numéroter au moment fiscal, tous canaux" asserté sans mapper les call-sites : online/QR finalisent via webhook Stripe/PayPal (PaymentService), jamais cité. myOrderStore:297 et tableOrderStore:986 restent orphelins.
- Cumul perpétuel du Grand Total (compteurs GT lifetime, exigence NF525) absent du teardown ET du redesign. Séquence + partition Z ne remplacent pas le GT perpétuel.
- Ordre numéroté puis annulé avant finalisation : sweep filtre is_finalized, numéro brûlé ne rejoint aucun Z → gap non tracé dans la continuité, contredit "gaps tracés". fiscal_voided_reason non câblé au Z.
- `orders_fingerprint` invoqué dans les tests mais jamais défini (quoi/quand/comment il entre dans la signature). Le chaînage par ticket (NF525) n'est pas conçu, seul le chaînage Z l'est.
- Intersection branch_id : numéroter par branche tous canaux exige un branch_id non nul résolu avant finalisation (online/QR) — dépend du levier détruire branch_id==0, ordre non traité.
- TPE borne: verify(pspRef) re-interrogeant le PSP est impossible pour un terminal carte hors-bande. transaction_id (OrderController:77) est un ticket TPE fourni par l'Electron, non requetable via API. Trou majeur du redesign.
- Racine de confiance: UNIQUE(psp,psp_ref,operation) ne garantit rien si psp_ref vient du client (Electron) ou de rand() (Credit.php:33). L'unicite DB est vide quand la cle vient d'une source non fiable.
- amount_due_minor reste derive de order->total. Le redesign asserte PSP==order->total sans rattacher l'assiette a la source fiscale. Si order->total est deja corrompu, PAID devient faux-mais-coherent.
- Refund Stripe exige le charge/payment_intent id; le code ne persiste que balance_transaction (Stripe.php:58), non refundable. Le ledger ne dit pas que psp_ref doit etre l'id refundable: donnees existantes irrecuperables.
- Injection de classe non traitee: gateway() fait new $className(ucfirst(slug)) AVANT validation (PaymentController:58, PaymentManagerService:22). Le redesign refond le DTO mais n'allowliste jamais slug->gateway.
- Devise zero-decimale (JPY/KRW): Money::fromMajor()->minor() depend de l'exposant devise; rien ne confirme que Currency stocke les decimales. Dependance silencieuse, risque de re-injecter un *100 errone.
- Ignore le chemin legacy vif sous flag pricing.use_ssot_service (config/pricing.php:9; OrderService 759-822,1158). Vider les FormRequest sans tuer ce chemin laisse $request->discount brut (773/776) entrer dans le total.
- Invariant NF525 mal specifie: Sum(tax lignes)==TaxCalculator(total_net) est indefini avec taux TVA mixtes et FIXED. NF525 exige ventilation PAR taux, pas une TVA globale; le test collapse les groupes.
- FIXED=taux*quantite asserte mais non prouve: TaxCalculator:11 traite FIXED comme forfait par ligne. Decision metier, pas un fait; le redesign impose une nouvelle semantique fiscale sans justification.
- TOCTOU: une contrainte unique(user_id,coupon_id) plafonne a 1 et casse limit_per_user=N>1. Le test ne couvre que =1. Il faut compteur atomique/verrou, pas un index unique.
- Loyalty ignore: la remise loyalty kiosk vit dans FrontendOrderService (ledger+lock) hors PricingService; la reallocation pre-taxe ne la couvre pas -> incoherence entre surfaces.
- branch_id absent des coupons (migration 110910: aucune colonne). null=global garde TOUS les coupons existants globaux; backfill/politique non traites -> la fuite cross-branche persiste sur le dataset actuel.

**Risques de régression à surveiller pendant la refonte :**
- UNIQUE(order_id,type) cassera: (a) collisions Order vs FrontendOrder de meme id; (b) split-tender / paiements type=payment multiples legitimes; (c) migration echoue si doublons prod preexistants sans dedup.
- Scellement au niveau service laisse fuir les autres ecrivains (posOrderStore, edits admin, deliveryBoy auto-PAID). Sans garde Order::updating au modele/DB (levier 4), une commande scellee reste mutable par tout chemin non refondu.
- deliveryBoyOrderChangeStatus (l.1379-1381) fait UNPAID->PAID pour le COD livreur; router via setPaymentStatus a machine stricte pourrait bloquer cet encaissement legitime -> regression livraison.
- Le catch generique de changeStatus (l.1564-1566) reecrit toute Exception en 422. Le 409 concurrent doit etre une HttpException sinon il est masque en 422 -> rupture du contrat d'API teste.
- Notifications/broadcast en outbox: sans relais/worker deploye (tier CI prod-parite), mails/SMS/push + listeners OSS/KDS/loyalty cessent silencieusement. Dependance operationnelle non couverte par les tests.
- SELECT FOR UPDATE systematique dans transition() change le profil contention/deadlock sous charge KDS/kiosk concurrente; a valider par test de charge, pas seulement property-based.
- Casse la vérifiabilité du registre : changer les colonnes agrégées (total_ht post-remise, refund_total, lignes négatives) modifie la charge signée → tout Z historique échoue verifyChain. Re-signer = falsification. Pas de versionnage v1/v2.
- Backfill z_report_id manquant : ordres déjà scellés (détectés par requête temporelle) sans z_report_id ; le sweep `z_report_id IS NULL` ré-aspire tout l'historique dans le prochain Z → double-comptage massif au 1er close.
- Triggers UPDATE/DELETE au niveau ligne sur ordres scellés : orders est chaude (statut KDS, livraison, OSS, flags impression). Geler la ligne casse ces flux. Le scellement doit être scoped aux colonnes fiscales, pas à la ligne.
- Contrat gap-free → gaps-autorisés contredit FiscalSequenceTest et l'invariant "monotone sans trou" ; tout consommateur aval attendant des numéros contigus régresse.
- Sérialisation sur ligne unique fiscal_counters(branch,kind) verrouillée à chaque paiement tous canaux → goulot débit par branche, remplace un cache-lock borné 3s. Régression throughput non reconnue.
- Migration remboursements colonnes positives → lignes signées : double-comptage transitoire (ordres déjà RETURNED + nouvelle ligne is_refund) sur données fiscales live.
- PAID derive (sum captures>=du) casse tous les lecteurs du flag payment_status: index (PaymentController:41), successful() via order->transaction, KDS/POS/rapports. Non-regression massive multi-surfaces non chiffree.
- Remplacer la table transactions casse CreditBalanceReportController et tout rapport lisant amount/sign/type. Invariant rapports<->comportement menace.
- cashBack ecrit la chaine HMAC NF525 (AuditLogService, PaymentService:54). Basculer vers refund PSP + store_credit distinct risque de rompre l'audit fiscal si non preserve. Integrite NF525.
- Migrer User.balance->balance_minor BIGINT + CHECK touche loyalty, credit, cashBack, admin, bien au-dela du paiement. Blast radius sous-estime: casse possible de flux de solde non-paiement.
- Retrait du redirect hors du domaine (DTO) change le contrat controller/vues blade (payment.success/fail, successful()) et les routes. Compat rompue si migration non phasee.
- assert strict amount_minor==amount_due_minor ET currency identique peut rejeter pourboires/ajustements legitimes ou bloquer le confirm si la devise defaut change entre charge et verify: faux rejets.
- Assiette brut par ligne -> net prorata modifie le total de TOUTE commande remisee: casse non-regression vs archives fiscales et rapports. NF525 interdit recompute retroactif -> exige effective-dating.
- delivery hors taxe (total=Sum net+tax+delivery) cree un defaut TVA: en France les frais de livraison sont taxables. Le fix sort delivery de l'assiette -> nouvelle incoherence fiscale.

---

*Teardown par 16 spécialistes + 16 revues croisées. Synthèse tech-lead assemblée par l'orchestrateur (l'agent de synthèse a échoué sur la génération structurée ; les 32 teardowns/revues sont intacts). Aucune modification de code — blueprint de refonte à valider.*