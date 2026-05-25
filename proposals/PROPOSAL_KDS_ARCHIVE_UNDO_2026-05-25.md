# PROPOSAL — KDS Archive Undo / Rappel commande après bump erreur

**Date** : 2026-05-25
**Agent** : GAP-PROPOSAL-04 (Gap-Hunt 2026-05-25)
**Branche** : `heal/cms-pr1-quickwins-2026-05-18`
**Status** : PROPOSAL ONLY — pas de code touché
**Owner-gate requis** : OUI (choix de path + countersign LOCK si Path B ou C)

---

## 1. Mandat owner (verbatim)

> « Quand je vois là maintenant l'écran de cuisine, je peux pas y accéder aux
> archives parce que je peux par exemple avoir fait valider une commande par
> erreur avec rapidité, je vais revenir pour la corriger »

**Décodé** : le chef, en chaînant des bumps « Prêt » à grande vitesse, peut
marquer une commande PREPARED par erreur (ex. mauvaise ligne, allergène
non-respecté, mauvais numéro). Il veut pouvoir **revenir en arrière** depuis
le drawer Historique du jour pour corriger le ticket — ce qui n'est pas
possible aujourd'hui.

**Contexte aggravant** : la Wave V (2026-05-21) a **retiré** le toast d'undo
3 secondes qui existait dans KdsV2Grid, parce que sa sérialisation single-slot
provoquait une course quand le chef chaînait 3+ bumps en moins de 3s
(`KdsV2Grid.vue:108-115`). Conséquence : aujourd'hui **zéro filet de sécurité**
post-bump — l'opération est immédiate, irréversible côté UI, et le drawer
historique est `read-only V1` explicite.

---

## 2. Évidence de l'état actuel (file:line)

### 2.1 Drawer historique read-only par design

`resources/js/components/admin/kitchenDisplaySystem/KdsHistoryDrawer.vue:1-21`

```
[Wave X3 2026-05-21] KDS Historique du jour — read-only V1.
...
Read-only V1: revert PREPARED → PREPARING is intentionally NOT exposed here.
OrderStateMachine (frozen §7) forbids reverse transitions, and the owner has
classified this as "secondaire" — revert is V1.0.2 backlog pending a LOCK
plan + owner countersign.
```

`resources/js/components/admin/kitchenDisplaySystem/KdsHistoryDrawer.vue:138-142`

```html
<!--
  V1.0.2 backlog: revert button (PREPARED → PREPARING).
  Blocked in V1 by OrderStateMachine §7 frozen-zone (forward-only).
  Requires LOCK plan + owner countersign before implementation.
-->
```

→ Le bouton « Annuler / Rappeler » est **explicitement absent** par décision
documentée, pas par oubli.

### 2.2 Route historique sans endpoint revert

`routes/api.php:1142-1148`

```php
// [Wave X3 2026-05-21] KDS Historique du jour — read-only V1 day-history viewer.
// Returns today's PREPARED/OUT_FOR_DELIVERY/DELIVERED orders for the
// branch (admin sees all), sorted updated_at desc, capped 50. Revert
// (PREPARED → PREPARING) deferred V1.0.2 (OrderStateMachine §7 LOCK).
Route::get('/history-today', [KitchenDisplaySystemController::class, 'historyToday'])
    ->middleware('throttle:60,1')
    ->name('history-today');
```

→ Une seule route GET, aucun verb mutating exposé.

### 2.3 Contrôleur KDS = 4 actions seulement

`app/Http/Controllers/Admin/KitchenDisplaySystemController.php:22`

```php
$this->middleware(['permission:kitchen-display-system'])->only('index', 'changeStatus', 'orderItems', 'historyToday');
```

`app/Http/Controllers/Admin/KitchenDisplaySystemController.php:62-70`

```php
/**
 * [Wave X3 2026-05-21] KDS "Historique du jour" — read-only V1.
 *
 * Owner mandate: chef sees all PREPARED/OUT/DELIVERED orders today to
 * verify content if a customer reports an error. Read-only — revert
 * (PREPARED → PREPARING) deferred to V1.0.2 because OrderStateMachine
 * (frozen §7) forbids reverse transitions and a LOCK plan + owner
 * countersign is required.
 */
```

→ Aucune méthode `recallOrder()` / `revertStatus()` n'existe.

### 2.4 OrderStateMachine = transitions forward-only

`app/Domain/Order/OrderStateMachine.php:30-75`

Table de transitions (sortie de `allows()`) :

| from | allowed `to` |
|------|--------------|
| PENDING | ACCEPT, CANCELED, REJECTED |
| ACCEPT | PREPARING, CANCELED (+ DELIVERED si POS) |
| PREPARING | **PREPARED**, CANCELED (+ DELIVERED si POS) |
| **PREPARED** | OUT_FOR_DELIVERY, DELIVERED |
| OUT_FOR_DELIVERY | DELIVERED |
| DELIVERED | RETURNED |
| CANCELED / REJECTED / RETURNED | (Admin only) |

→ Ligne 54-55 : `case OrderStatus::PREPARED: return in_array($to,
[OrderStatus::OUT_FOR_DELIVERY, OrderStatus::DELIVERED], true);`

**PREPARED → PREPARING n'est pas dans la liste**. `assertAllows()` lève
`IllegalTransitionException` (ligne 77-82). Frozen-zone §7 CLAUDE.md.

### 2.5 Validator KDS restreint aux 3 statuts

`app/Http/Requests/Kds/KdsOrderStatusRequest.php:34-41`

```php
public static function kdsStatuses(): array
{
    return [
        OrderStatus::ACCEPT,
        OrderStatus::PREPARING,
        OrderStatus::PREPARED,
    ];
}
```

→ Le validator accepte théoriquement les 3 statuts dans les deux sens (rien
n'empêche un POST `status=PREPARING` sur un order déjà PREPARED côté
validation), mais c'est `OrderStateMachine::assertAllows` qui bloque ensuite.

### 2.6 Toast undo 3s supprimé Wave V

`resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue:108-115`

```html
<!-- [Wave V 2026-05-21] KdsUndoToast removed — chef Prêt tap now PATCHes
     immediately. The 3s undo window + single-slot serialization
     (clearTimeout(pendingTimeoutId)) caused a cross-order race: when chef
     chained "Prêt" on 3+ orders within 3s, the previous order's PATCH
     was cancelled by the next click → only the LAST order transitioned,
     the rest stayed EN COURS until chef re-clicked (perceived as a 30s
     retry-after toast). Per owner mandate "enlève cette sécurité".
     Component file kept for instant rollback. -->
```

`resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue:291-323`

```js
// [Wave V 2026-05-21 P-OWNER] Chef taps Prêt → IMMEDIATE PATCH dispatch.
// Previous design (Wave Q-2 2026-05-20): optimistic toast 3s window
// → PATCH after timer expired. The single-slot serialization (a
// shared `pendingTimeoutId`) cancelled any in-flight pending bump
// whenever the chef clicked Prêt on a SECOND order within 3s...
// Owner mandate: "enlève cette sécurité — je veux valider 3 commandes
// en même temps, puis 3 commandes livrées." So we remove the 3s
// undo window entirely.
```

→ La sécurité 3s a été retirée volontairement parce qu'elle cassait le
multi-bump, **mais aucun remplacement n'a été livré**. Gap confirmé.

### 2.7 Modèle d'audit déjà compatible compensating action

`app/Models/OrderStatusTransition.php:11-21`

```php
protected $fillable = [
    'order_id', 'order_type',
    'from_status', 'to_status',
    'actor_id', 'actor_type',
    'reason',
    'correlation_id',
    'occurred_at',
];
```

→ La colonne `reason` (text) existe déjà, sans modification de schéma.
On peut écrire un row `reason='kitchen_recall'` avec `from_status=to_status`
(transition identité, autorisée par `OrderStateMachine::allows` ligne 32-34)
**sans muter `orders.status`** et sans toucher la chaîne audit-log NF525.

---

## 3. Trois chemins d'implémentation

### Path A — Toast undo 3s restauré (réversion partielle Wave V)

**Principe** : remettre un toast par-ordre (non shared), 3s de fenêtre.
Le clic « Annuler » dans la fenêtre annule le `PATCH` AVANT envoi (le PATCH
est différé via setTimeout par ordre, pas via un slot global qui se faisait
écraser). Si pas d'annulation → PATCH part normalement.

**Implémentation conceptuelle** :
- Dans `KdsV2Grid.vue` : remplacer le single `pendingTimeoutId` par une
  `Map<orderId, { timeoutId, payload }>` pour éviter la course Wave Q-2.
- Toast par ordre, pas global. Empilage vertical bas-droite, max 3 visibles.
- Au clic « Annuler » sur le toast → `clearTimeout` du timeoutId stocké pour
  cet ordre uniquement.
- À expiration 3s → dispatch `change-status` normalement.
- Aucun appel API supplémentaire ; aucun changement backend.

**Limites fonctionnelles** :
- **Ne couvre que la fenêtre pré-PATCH** : si le chef réalise son erreur
  après 5 secondes, il est trop tard (PATCH déjà envoyé, ordre déjà PREPARED).
- Cas d'usage owner (« revenir pour la corriger ») = post-bump → Path A **ne
  répond que partiellement** au mandat.
- Risque de réintroduire la confusion Wave V si l'implémentation n'isole pas
  strictement les toasts par ordre.

**Effort** : XS (~30-50 LOC, KdsV2Grid uniquement)
**Risque** : LOW (logique frontend isolée, rollback = `git revert`)
**Frozen-zone** : NON (KdsV2Grid pas frozen)
**NF525** : SAFE (aucune mutation après PATCH)
**Résout mandat owner ?** : **PARTIEL** (fenêtre 3s seulement, pas après)

**ETA** : 0.5 jour code + 0.5 jour tests + 0.5 jour visual Playwright = **1.5j**

---

### Path B — Compensating action / Badge RAPPELÉ (recommandé)

**Principe** : ne pas muter `orders.status` (interdit par la state machine
forward-only), mais **enregistrer un événement de rappel cuisine** en
écrivant un row `OrderStatusTransition` avec `reason='kitchen_recall'` et
`from_status=to_status=PREPARED` (transition identité, autorisée). Le
frontend KDS détecte ce rappel et **ré-injecte la carte** sur le board avec
un badge visuel `RAPPELÉ` pendant 60s.

C'est la même mécanique que le `kds.recallItem` localStorage existant
(évoqué dans le mandat), mais **persisté server-side** pour qu'il survive au
refresh / autre poste / autre chef.

**Implémentation conceptuelle** :

Backend :
- Nouvelle méthode `KitchenDisplaySystemController::recallOrder(Order $order, KdsOrderRecallRequest $request)`.
- Nouvelle route `POST /api/admin/kds-order/recall/{order}` middleware
  `permission:kitchen-display-system`, `idempotency`, `throttle:kds-bump`.
- Validation FormRequest : ordre doit être en `PREPARED` ET `updated_at >
  now() - 60s` (recency check). Refus 422 sinon.
- Service `KitchenDisplaySystemOrderService::recall(Order, User)` :
  - `DB::transaction` + `lockForUpdate`
  - Re-vérifie status + recency sous lock
  - Insère `OrderStatusTransition` avec `from_status=PREPARED`,
    `to_status=PREPARED`, `reason='kitchen_recall'`, `actor_id=$user->id`,
    `actor_type='App\\Models\\User'`, `correlation_id=uuid`, `occurred_at=now()`
  - Dispatch event `KdsOrderRecalled` pour broadcast WebSocket
- WebSocket : channel `kds.branch.{branchId}` reçoit `{ orderId, recalledAt }`.

Frontend :
- KDS feed (`/api/admin/kds-order`) inclut un flag `recalled_at` calculé via
  `OrderStatusTransition::where('reason','kitchen_recall')->latest()` si row
  < 60s.
- `KdsHistoryDrawer.vue` : bouton « Rappeler en cuisine » sur les items
  PREPARED dont `updated_at > now() - 60s`. Au clic → POST recall + toast
  confirmation.
- `KdsV2Grid.vue` : `activeOrders` computed inclut désormais les orders
  `status=PREPARED && recalled_at && recalled_at > now()-60s`. Affichage carte
  avec badge `RAPPELÉ` (CSS distinct, ex. badge jaune ambre).
- Après 60s, le badge expire côté frontend (refresh tick) et la carte
  retourne au strip « Récemment servies ».

**Garanties NF525** :
- `orders.status` **jamais muté en arrière** → frozen-zone §7 OrderStateMachine
  respectée, **transition identité PREPARED→PREPARED** est explicitement
  autorisée par `OrderStateMachine::allows` ligne 32-34 (`if ($from === $to)
  return true;`).
- `audit_logs` chain HMAC bit-identical (aucune ligne audit_log nouvelle —
  seulement `order_status_transitions` qui est un journal métier, pas la chaîne
  fiscale). Si owner veut un append au chain audit pour traçabilité, ajouter
  un `AuditLogService::log('order.kitchen_recall', ...)` — c'est un append-only
  acceptable côté NF525 (la chaîne grandit, elle ne mute pas).
- Vue client OSS et notification client « Prêt » **inchangées** : l'order
  reste PREPARED, le client n'est pas informé du rappel cuisine (le rappel
  est interne KDS, pas une régression vers le client).

**Effort** : M (~120-150 LOC backend service + controller + request + route +
  test ; ~80 LOC frontend drawer + grid + badge CSS + i18n keys)
**Risque** : MED (touche le contrat KDS feed côté frontend, nécessite test
  cross-poste, race idempotency)
**Frozen-zone** : **OrderStateMachine.php** consulté en lecture
  (transition identité est OK natif, **pas de modification**). Donc selon
  interprétation stricte CLAUDE.md §7 : **aucun fichier frozen modifié**
  (la transition identité est déjà autorisée). Si owner exige LOCK par
  prudence (nouveau call-site avec `apply()` ou nouveau code de raison) →
  LOCK formel à signer.
**NF525** : SAFE (append-only, status ledger view-only, no reverse)
**Résout mandat owner ?** : **OUI** (60s fenêtre post-bump persistée
server-side, multi-poste)

**ETA** : 1.5j backend (controller + service + request + route + test
  Feature) + 1j frontend (drawer button + grid badge + i18n) + 1j visual
  Playwright + adversarial dispute + heal = **3.5j**

---

### Path C — Reverse transition PREPARED→PREPARING (gated)

**Principe** : modifier `OrderStateMachine::allows` pour autoriser
`PREPARED → PREPARING` SOUS condition stricte :
- recency check (≤60s depuis le PATCH PREPARING→PREPARED)
- `User` a permission `kitchen-display-system`
- `correlation_id` requis (audit traçabilité)
- pas plus de 1 reverse par ordre (counter dans `order_status_transitions`)

Le validator `KdsOrderStatusRequest` accepte déjà les 3 statuts → pas de
changement côté validator. Le controller `changeStatus` accepte alors le
revert et persiste via le code existant `OrderService` (pattern actuel
documenté §22-23 OrderStateMachine).

**Avantages** :
- Sémantique limpide : l'ordre **est** en PREPARING, le badge n'est pas
  nécessaire, OSS/POS/sync voient l'état réel.
- Pas de drift entre `orders.status` et `order_status_transitions`.
- KDS frontend ne change quasi rien (la carte revient naturellement en grid
  via le feed).

**Risques** :
- **Frozen-zone OrderStateMachine.php** modifiée → LOCK obligatoire + owner
  countersign + safety-check.sh override.
- **NF525 question ouverte** : un reverse mute `orders.status`, ce qui
  contredit le pattern « append-only » établi. L'inspecteur fiscal pourrait
  questionner. Réponse défendable : `order_status_transitions` garde
  l'historique complet (PREPARING→PREPARED puis PREPARED→PREPARING avec
  reason='kitchen_recall_manual'). MAIS spec NF525 V1 actuelle est explicite
  forward-only.
- **Z-report impact** : si l'ordre était inclus dans une agrégation
  « commandes PREPARED en cours » au moment T → reverse à T+30s → divergence
  si snapshot pris entre les deux. À tester rigoureusement.
- **Audit chain HMAC** : si un trigger MySQL existe sur `orders` pour log
  status changes vers `audit_logs`, le reverse produira un nouveau hash. Vérifier.
- 2 LOCKs minimum : `OrderStateMachine.php` + très probablement
  `AuditLogService.php` pour gérer le code de raison du reverse côté chaîne.

**Effort** : L (~200-250 LOC + 8-12 tests Feature + Unit + sentinels NF525)
**Risque** : HIGH (frozen-zone + NF525 spec drift + Z-report impact + chain
  HMAC)
**Frozen-zone** : OrderStateMachine.php + (probablement) AuditLogService.php
  → **2 LOCKs**
**NF525** : SAFE seulement avec spec rigoureuse + inspecteur fiscal briefé
**Résout mandat owner ?** : OUI (revert propre, sémantique exacte)

**ETA** : 2.5j backend (state machine + service + tests sentinels NF525) +
  1.5j frontend + 1.5j visual + adversarial NF525 dispute + heal + Z-report
  test cycle = **5.5j**

---

## 4. Recommandation

### Path B — Compensating action / Badge RAPPELÉ

**Pourquoi** :

1. **Résout pleinement le mandat owner** (60s post-bump, persisté
   server-side, multi-poste) — contrairement à Path A qui ne couvre que
   pré-PATCH.
2. **NF525-safe par construction** : la chaîne fiscale reste append-only.
   La transition identité PREPARED→PREPARED est déjà autorisée nativement
   par `OrderStateMachine::allows` ligne 32-34. Pas de spec drift fiscal.
3. **Frozen-zone minimal** : OrderStateMachine.php n'est **pas modifiée**.
   AuditLogService.php n'est **pas modifiée** (log append normal si owner
   veut traçabilité chain). Donc 0 LOCK technique requis — Path B est
   **livrable sans bypass frozen-zone**.
4. **Effort proportionné** (~3.5j) vs valeur livrée.
5. **Réversibilité** : si Path B se révèle insuffisant, Path C reste
   possible en V1.0.2 (la donnée `kitchen_recall` aura déjà été collectée et
   pourra informer la spec reverse formelle).
6. **Pattern existant** : `kds.recallItem` localStorage évoqué dans le
   mandat est déjà une compensating action ad-hoc côté frontend ; Path B
   formalise et persiste le même concept côté backend.

**À écarter Path A** : ne résout pas le mandat (fenêtre pré-PATCH
seulement). Le owner décrit clairement le besoin post-bump (« je vais
revenir pour la corriger »).

**À reporter Path C en V1.0.2** : risque NF525 + 2 LOCKs frozen-zone +
durée 5.5j. Path C devient pertinent SI Path B livre et que l'usage révèle
un besoin de reverse propre (ex. fenêtre 60s trop courte, sémantique
ambigüe, OSS/POS doivent voir PREPARING).

---

## 5. Matrice de décision owner

| Path | Effort | Risque | Frozen touch | NF525 | Résout mandat owner ? | LOCKs requis | ETA |
|------|--------|--------|--------------|-------|----------------------|--------------|-----|
| **A** Toast 3s restauré | XS | LOW | NON | SAFE | **PARTIEL** (pré-PATCH only) | 0 | 1.5j |
| **B** Compensating action badge RAPPELÉ (**reco**) | M | MED | NON (transition identité) | SAFE (append) | **OUI** | 0 (1 si owner prudence) | 3.5j |
| **C** Reverse transition PREPARED→PREPARING | L | HIGH | OrderStateMachine + AuditLogService | SAFE avec spec | OUI | 2 | 5.5j |

---

## 6. Acceptance criteria si owner choisit Path B

### Fonctionnel

- Chef ouvre `KdsHistoryDrawer` depuis le bouton header de KDS.
- Pour chaque order PREPARED avec `updated_at > now() - 60s` : bouton
  « Rappeler en cuisine » visible (les autres : bouton absent ou disabled
  avec tooltip « rappel possible jusqu'à 60s après bump »).
- Chef clique « Rappeler en cuisine » → toast confirmation « Commande N°X
  rappelée en cuisine » + drawer reste ouvert (UX continuité).
- KDS board (autre poste, autre chef, même branch) reçoit l'événement
  WebSocket en <1s et **ré-affiche la carte** avec badge `RAPPELÉ` (CSS
  ambre, ARIA `aria-live="polite"` annonce screen-reader).
- Après 60s, badge expire ; carte retourne au strip « Récemment servies ».
- `OrderStatusTransition` row écrit : `order_id=X`, `from_status=PREPARED`,
  `to_status=PREPARED`, `reason='kitchen_recall'`, `actor_id=chef.id`,
  `actor_type='App\\Models\\User'`, `correlation_id=uuid`, `occurred_at=now()`.
- `orders.status` reste à PREPARED (vérifiable SQL `SELECT status FROM
  orders WHERE id=X`).

### Non-fonctionnel

- OSS notification client « Commande prête » **PAS retirée** (NF525 :
  ledger view-only, le client a vu Prêt, ça reste Prêt côté facture).
- POS « Suivi » continue d'afficher l'order comme PREPARED (l'order **est**
  prêt sur la chaîne fiscale, le rappel est interne KDS).
- Idempotency-Key header obligatoire (recall idempotent : second click sur
  même order < 60s → 200 no-op ou 409 si déjà rappelé).
- Throttle `kds-bump` (réutilisation rate-limiter existant).
- Permission `kitchen-display-system` vérifiée middleware + FormRequest.

### Sentinel tests (≥5 cas)

1. `recall_succeeds_within_60s_window` — chef bump à T, recall à T+30s → 200,
   row inséré, status inchangé.
2. `recall_rejects_after_60s_window` — recall à T+90s → 422 « fenêtre
   expirée », pas de row.
3. `recall_requires_actor` — anonymous POST → 401.
4. `recall_does_not_mutate_status` — assert `orders.status` strictement
   identique avant/après le recall (`SELECT status FROM orders` × 2).
5. `recall_idempotency_replays` — 2 POST identiques X-Idempotency-Key →
   1 row, pas 2.
6. `recall_branch_scoped` — chef branch A recall order branch B → 404
   (BranchScope).
7. `recall_emits_websocket` — assert event `KdsOrderRecalled` dispatched
   sur channel `kds.branch.{branchId}`.

### Visual Playwright (mandate CLAUDE.md §6)

- Capture KdsHistoryDrawer avec PREPARED orders mixtes (certains <60s,
  d'autres >60s) → bouton visible/absent conformément.
- Capture KDS board après recall → carte ré-injectée avec badge RAPPELÉ.
- Capture après expiration 60s → badge disparu, carte au strip « Récemment
  servies ».
- Capture multi-poste : poste 1 recall, poste 2 voit la mise à jour <1s.

---

## 7. Risk register

| ID | Risque | Mitigation |
|----|--------|------------|
| R1 | 2 chefs recall même order simultanément | Idempotency-Key + `lockForUpdate` → 1 row gagne, 2nd reçoit 200 no-op |
| R2 | Multiple recalls même order dans 60s | Cap **N=1** par order par défaut (si 2nd recall < 60s du 1er → 409). Owner peut overrider en V1.0.2 |
| R3 | Recall après que la carte ait quitté l'historique (>60s) | Endpoint refuse 422 — pas de mécanisme de récupération extra-fenêtre en V1 |
| R4 | Inspecteur NF525 questionne « recall = annulation cuisine ? » | Spec doc précise : recall = **événement métier interne KDS**, pas une annulation fiscale. Order reste PREPARED, facture émise non-impactée. `order_status_transitions` documente le rappel, séparé de la chaîne audit_logs (qui peut être doublée en append seulement si owner veut) |
| R5 | Client a vu son « Prêt » sur écran OSS → recall ne le notifie pas → confusion service | Recall est **interne KDS**, ne touche pas l'OSS. Workflow attendu : chef rappelle, corrige, re-bump quand prêt. Cycle invisible pour le client (qui attend juste son numéro au comptoir) |
| R6 | Race : recall + OSS qui flip vers OUT_FOR_DELIVERY simultané | OSS continue de pouvoir flip PREPARED→OUT_FOR_DELIVERY (transition forward valid). Recall est un append, n'empêche pas le forward. Owner choix : si OSS flip après recall, le rappel est ignoré (carte disparaît avec OSS bump) |
| R7 | WebSocket down → autre poste ne voit pas le recall en temps réel | Polling fallback existant `/sync` couvre. Toast de confirmation reste local au poste qui recall |
| R8 | `OrderStatusTransition` table croit (60s × commandes) | Volumétrie acceptable Le Cayenne (cap ~50 orders/jour). V2 SaaS = archive cron mensuel |

---

## 8. Question owner pour clarification (à poser AVANT exécution)

1. **Fenêtre 60s** ou différente (30s ? 120s ?). 60s est arbitraire, à
   confirmer.
2. **Cap N=1 recall par order** ou plus ? Risque sécurité si N illimité
   (chef rappelle 10× en boucle → spam audit).
3. **Append audit_logs HMAC chain** ou pas ? Si owner veut traçabilité
   fiscale forte → 1 ligne audit_log append par recall (zéro risque chain,
   c'est append-only par design).
4. **Badge UI** : couleur ? Position ? Texte (« RAPPELÉ » vs « EN RAPPEL » vs
   icône)?
5. **i18n FR seulement** (V1 Le Cayenne mono-locale) ou aussi EN/AR ?

---

## 9. Verdict ship-readiness V1

**NON-BLOCK** pour V1 Le Cayenne LOCAL.

Justification :
- L'absence d'undo est un gap UX confirmé, mais le drawer historique
  read-only documenté Wave X3 a été owner-approved comme « secondaire »
  V1.0.2.
- Path B est livrable post-V1 sur fenêtre courte (3.5j) sans toucher
  frozen-zone — pas besoin de retarder le ship V1 LOCAL.
- Workaround manuel V1 : chef avertit caissier de vive voix, caissier
  ajuste l'order via admin (rare, edge case).

**Reco** : pousser V1 ship + planifier Path B en V1.0.1 ou V1.0.2 selon
priorité owner après soak test 5j.

---

## 10. Annexes

### A. Pourquoi Path B ne touche pas frozen-zone

`app/Domain/Order/OrderStateMachine.php:30-34` :

```php
public static function allows(int $from, int $to, ?Authenticatable $user = null): bool
{
    if ($from === $to) {
        return true;
    }
    ...
}
```

→ Identity transition (`from == to`) est **explicitement autorisée** par le
code existant. Path B utilise cette transition identité pour journaliser le
recall sans muter `orders.status`. **Zéro ligne de OrderStateMachine.php
modifiée**.

### B. Pattern de référence existant

`kds.recallItem` localStorage (frontend-only, non-persisté) est mentionné
dans le mandat comme « pattern existant ». Path B = même concept persisté
server-side via `order_status_transitions`.

### C. Tâche backlog V1.0.2 ouverte

Si owner choisit Path A ou ne fait rien V1 → ajouter au backlog V1.0.2 :

> **KDS-UNDO-V1.0.2** — Compensating action / Badge RAPPELÉ ou reverse
> transition gated. Path B recommandé Proposal 2026-05-25. Dépendance :
> aucune. Effort estimé : 3.5j (Path B) / 5.5j (Path C).

---

**FIN PROPOSAL**

Owner-decision attendue avant toute exécution.
