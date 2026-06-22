# Arbitrage Master Play — POS / Borne / KDS (audit V1 + Round 2 GPT)

## 1) CONFLIT‑1 — `DEVICE_FLOW` (Firebase) vs `PosComponent` (Echo)

**Verdict** : dérive documentaire avérée — `docs/DEVICE_FLOW.md` L16 dit « WebSockets Firebase pour les entrées Kiosk » alors que le POS moderne consomme Laravel Echo (voir V1 §3.2 / GPT §B.1 → `PosComponent.vue:1173`) ; **la doc doit être alignée**, le code fait foi.

## 2) CONFLIT‑2 — Idempotence `where('idempotency_key')` sans `branch_id` vs index composite DB

Risque réel mais **partiellement mitigé**, trois puces :

- **Mitigation runtime existante** : `FrontendOrderService.php:133‑137` prend un `Cache::lock` dont la clé inclut `$lockBranchId` (`frontend_order_idempotency_{sha1(branch|key)}`) — donc la sérialisation concurrente EST branch‑scopée côté cache.
- **Trou résiduel P0** : le lookup L138 `FrontendOrder::where('idempotency_key', …)->first()` reste **global**. Si une même clé apparaît dans deux branches (collision volontaire, bug client, réémission manuelle, test cross-branch, clé courte ou non-UUID), la borne branche B récupère la commande de la branche A — l'unique DB `(branch_id, idempotency_key)` n'est PAS utilisé en lecture. Défense en profondeur manquante.
- **Cohérence symétrique** : `lockBranchId` est calculé via `KioskMachine.user_id` ou `Auth::user()->branch_id`, alors que la commande réelle reçoit `$validatedRequest['branch_id'] = $kiosk->branch_id` (L167). Si l'utilisateur auth n'est pas rattaché à une kiosk machine, `$lockBranchId` retombe à 0 → le lock protège contre lui‑même mais pas contre la branche réelle. Classification GPT **P0 confirmée**.

## 3) CONFLIT‑3 — Le `_lastOrder.total` vient-il du backend ?

**Verdict** : **OUI, hors offline** — `KioskPaymentComponent.vue:295` lit `res?.data?.data?.total ?? res?.data?.data?.order_amount`, valide numériquement L300‑305 et lève si la réponse serveur est invalide (L302). Seul le chemin `isOfflineId` (L297‑298) retombe sur `cartTotal`. Le `_lastOrder.total` injecté L324 et utilisé en `amountEuros = this._lastOrder.total || this.cartTotal` (L408) est donc **SSOT backend en ligne**. L'UNVERIFIED du GPT §A.2 est **levé → PASS sur le parcours TPE online** ; seule la branche offline conserve un fallback local (comportement documenté AUDIT‑52/T06).

## 4) Challenge double — affirmations Round 2 GPT après relecture code

| Affirmation GPT R2 | Statut post‑relecture |
|---|---|
| §A.1 — Idempotence non branch‑scopée en lecture | **P0 maintenu** (lookup global L138 ; seul le cache lock est scopé) |
| §A.2 — Montant terminal non prouvé backend | **Infirmé / nuancé** → backend SSOT confirmé en ligne (L295‑305) ; fallback local uniquement offline |
| §A.3 — Admin `branch_id=0` UI Echo vs backend | **P1 maintenu** (non revérifié sur `channels.php` ici, mais aucune contradiction V1) |
| §A.4 — `OrderTableChanged` absent de `DispatchAfterCommitTest` | **P0 maintenu** — provider L23‑36 couvre 3 events, `OrderTableChanged` absent ; événement porte `DispatchableAfterCommit` (`OrderTableChanged.php:27`) → trou de test net |
| §A.5 — Version sync `updated_at` / TODO `status_changed_at` | **P1 maintenu** (non revérifié ici) |
| §A.6 — Bump KDS localStorage risque divergence | **P0 maintenu** (gouvernance à trancher) |
| §A.7 — OSS hors `ItemAvailabilityChanged`/`OrderTableChanged` | **P2 maintenu** (choix produit à documenter) |
| §B.1 — `DEVICE_FLOW.md` dérive Firebase/Echo | **Validé** (L16 lu) |
| §B.4 — after-commit conçu sur events majeurs | **Validé partiel** — design OK, couverture test incomplète (cf. A.4) |
| §B.6 — Pricing SSOT persistance | **Validé** + extension : parcours paiement aussi SSOT en ligne (cf. CONFLIT‑3) |

## 5) Cinq actions ordonnées P0 → P1

1. **P0** — Ajouter le scope `branch_id` au lookup idempotency (`FrontendOrderService.php:138`) + test régression qui injecte la même clé sur deux `branch_id` distincts (scénario non couvert par `IdempotencyBranchScopedTest` à revérifier). Bloquant avant tout élargissement kiosk multi‑branches.
2. **P0** — Compléter `DispatchAfterCommitTest` avec `OrderTableChanged` (provider L21‑37) : payload minimal via `BroadcastableOrder` stub + preuve rollback/commit. Sans cela, l'invariant after-commit reste partiellement non testé.
3. **P0** — Décision gouvernance bump KDS (serveur‑autoritatif vs « un seul poste actif par branche ») via ADR humain ; aujourd'hui deux écrans peuvent déclencher `PREPARED` divergemment depuis `localStorage`.
4. **P1** — Aligner `docs/DEVICE_FLOW.md` §2 L16‑17 sur Echo/Pusher (et citer FCM séparément si utilisé), retirer la mention Firebase trompeuse. Cycle doc dédié, pas de code.
5. **P1** — Traiter le TODO `status_changed_at` dans `KdsSyncService` (backend + JS) pour éliminer la granularité seconde sur `updated_at` ; ajouter scénario 2 onglets KDS + double remount listener dans la même vague.

---

**Note d'évidence** : les points 1, 2, CONFLIT‑1, CONFLIT‑3 sont validés par lecture directe (L138, L295‑305, DEVICE_FLOW L16, DispatchAfterCommitTest L21‑37, OrderTableChanged L27). Les points §A.3 / §A.5 / §A.6 / §A.7 ne sont pas re‑vérifiés dans ce cycle et restent sur la confiance croisée V0+GPT — à re‑confirmer en cycle produit.

AUDIT_VERDICT: REWORK
