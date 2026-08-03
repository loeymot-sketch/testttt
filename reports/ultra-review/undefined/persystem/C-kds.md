# Ultra-Review Système C — KDS (Kitchen Display System)

HEAD audité : `61e9ea7b7` (working-tree AS-IS). Verify-before-report strict, lecture seule.

## Verdict : GREEN_WITH_NOTES

Le système KDS est conforme au design V1 LOCAL. Aucun P0/P1/P2 nouveau. 1 note P3
(cadence de poll admin) documentée ci-dessous.

## Invariants confirmés (file:line + preuve)

1. **Filtrage board = status + payment_status (PAS kds_station).**
   `KitchenDisplaySystemOrderService::list()` (l.73-78) : `whereIn('status', visibleStatuses())`
   = [4,7,8] + `applyBoardReleaseFilter()`. SQL prouvé via tinker :
   `where status in (?,?,?) and (payment_status=? or payment_status=? or (order_type=? and pos_payment_method=?))`.
   `kds_station` n'existe QUE comme filtre UI (dropdown, Item model) — jamais dans la
   requête de release (`grep kds_station` : Item.php / OrderItemResource / frontend station-filter only).

2. **SSOT release partagé list() ↔ changeStatus() ↔ orderItems().**
   `KitchenReleaseRule::isReleasedForBoard` (l.100-112) admet PAID | PENDING_COUNTER | POS-cash ;
   miroir SQL `applyBoardReleaseFilter` (l.130-140). changeStatus l.447 réutilise
   `orderIsReleasedForBoard` (garde P1 « visible == bumpable »). orderItems l.520 applique
   le même filtre. Divergence board/bump impossible.

3. **Format symbolique** rendu par `KitchenTicketSymbolicFormatter` (PHP) + `kdsSymbolic.js`
   (JS jumeau) — hors du service de release, parité déjà validée (mémoire projet).

4. **bump/recall/undo** : `changeStatus` (l.392) sous `lockForUpdate` + optimistic-lock
   (`expected_status`→409, l.411-424), canTransition + OrderStateMachine::allows (l.430-435),
   no-op idempotent si from==to (l.426-428), post-commit notifications wrappées `\Throwable`
   (l.477-493, ne re-wrappe jamais un bump commité en 422). NF525 : `recordTransition` append-only.

5. **recall TTL 60s (updated_at).** `recall()` l.286-387 : `$windowSeconds=60` (l.292),
   TTL sur `updated_at` (l.315-322), dedup N=1 sur `occurred_at >= now-60s` (fenêtre glissante
   stable, fix #13 l.331-335 → 409), branch-isolation sous lock (l.304-306 → 403), état ≠ PREPARED
   → 422 (l.309-311), invariant `orders.status` NON muté (assertion l.360-363), broadcast
   after-commit (l.377). Routes `throttle:kds-bump`+`idempotency` (api.php l.1215-1217).

6. **Poll fallback 5s WS-down / 60s WS-up.** `_pollingInterval()` l.1899 :
   `wsConnected ? 60000 : 5000`. `_restartPolling` recréé sur `connected`/`disconnected`
   (l.1874-1884). Banner fallback `data-testid=kds-sync-mode-banner` gated `!wsConnected`.

7. **Payload `KDSOrderDetailsResource` stable** (l.19-77) : champs figés, `source_surface`
   pour lane bucketing, `payment_pending_counter` bool, GDPR data-min (phone DELIVERY-only l.72-74),
   `whenLoaded` sur address/user (pas de fuite si non chargé). meta overflow/limit (controller l.31-36).

## Note P3 (non bloquant V1 LOCAL)

**Admin (branch_id=0) : cadence de poll 60s sans push Echo.**
`subscribeEcho()` l.1917 `if (branchId <= 0) return;` → l'admin ne s'abonne à AUCUN canal.
Mais `wsConnected` (l.1117 init + l.1875) devient `true` dès que le ws-service global est
connecté, donc `_pollingInterval()` renvoie 60000 (l.1899). Résultat : un compte admin qui
affiche le KDS ne reçoit NI push (non-abonné) NI poll 5s → fraîcheur jusqu'à ~60s, alors que
le commentaire l.1894-1895 promet « orders must surface within ~5s of payment ». Le commentaire
l.1917 « polling fallback is sufficient » est contredit par la cadence 60s.
Impact réel V1 borné : le KDS Le Cayenne mono-poste tourne normalement sur un compte STAFF
(branch_id=1) qui, lui, s'abonne à Echo et bascule 5s si WS tombe — chemin non affecté. Le cas
n'apparaît que si la cuisine est ouverte sous le compte owner/admin. Sur multi-branche un banner
« Compte central » avertit (l.1331-1342) ; sur single-branch aucun banner → dégradation
silencieuse. Correctif possible (hors scope) : forcer 5000ms quand `authBranchId()<=0`
(admin non-abonné) OU s'abonner au canal de la branche par défaut.

## Preuves exécutées
- tinker : `visibleStatuses=[4,7,8]`, board SQL complet (ci-dessus).
- grep `kds_station` : 0 occurrence dans le chemin de release.
- Lecture intégrale service / rule / resource / controller / routes / composant Vue.
