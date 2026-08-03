# Dimension D — CAISSE + KDS lifecycle (contrôle des commandes)

Auditeur adversaire, READ-ONLY, DB réelle (mysql, 3279 orders), aucune mutation. Chaque finding = verify-before-report (file:line + repro données).

## Distribution réelle des statuts (Order::withoutGlobalScopes, groupBy status)
1=175 · 4=145 · 7=313 · 8=99 · 13=2246 · 16=109 · 19=150 · 22=26 · **2=1 · 5=15** (les 2 derniers HORS enum OrderStatus).
payment_status : 5(PAID)=2669 · 10(UNPAID)=371 · 15(PENDING_COUNTER)=210 · 20(REFUNDED)=27 · 0=1 · 1=1.

---

## 1. Transitions — ROBUSTE, 1 observation

`OrderStateMachine::allows()` = SSOT, `ValidStatusTransition` y délègue (Rule L34). `OrderService::changeStatus` re-valide DEUX fois (pré-lock L2197 + sous lockForUpdate L2330 sur le statut FRAÎCHEMENT verrouillé), early-return idempotent si déjà à cible (L2314), motif requis REJECTED/CANCELED/RETURNED (L2335), **SealedOrderGuard** bloque tout terminal sur commande fiscalisée dans un Z clos (L2350). Refund gaté `pos-refund` pour RETURNED + CANCELED/REJECTED-si-PAYÉE (OnlineOrderController L128-137, miroir POS). KDS bump idem (service L555-589).

- **CONFIRMÉ SAIN** : DELIVERED→RETURNED sans perm dans `allows()` (L76-77) MAIS gaté `pos-refund` au contrôleur → defense-in-depth, pas un trou.
- **D-1 (P2) — États terminaux non terminaux pour Admin.** `allows()` L79-86 : depuis CANCELED/REJECTED/RETURNED, `hasRole('Admin')` retourne `true` vers **N'IMPORTE QUEL** statut. Un Admin peut ressusciter une commande annulée/remboursée (ex. RETURNED→DELIVERED, CANCELED→ACCEPT) — seule barrière = SealedOrderGuard SI fiscalisée-Z-clos + perm refund sur arêtes monétaires. 285 commandes en état terminal en base. Risque opérationnel V1 mono-poste (owner=Admin) faible, mais trou d'intégrité d'état réel.

## 2. Idempotence — ROBUSTE, aucun finding

Middleware `idempotency` sur TOUTES les arêtes : counter-collect/confirm+cancel (api.php L949,969), online/pos/table/kds change-status (L1095,1063,1109,1257). lockForUpdate + early-return idempotent partout (OrderService L2314, StateMachine::apply L231, KDS L551). Accept web atomique (DB::transaction, flip PENDING_COUNTER+COUNTER_DEFERRED + changeStatus, rollback total — OnlineOrderController L152-187). `confirmCounterPayment` double-encaissement → `PaymentAlreadyCollectedException` 409. Double-clic/double-POST ne peut ni doubler-créer ni doubler-rembourser.

## 3. Visibilité « où en est » — 2 findings

- **D-2 (P2) — Bucket fantôme PENDING non-web.** `ordersByStatus` (PosOrdersTrackerComponent L578-640) ne bucketise QUE status 4/7/8/10/13 + PENDING(1)-si-web (L620). `fetchOrders` (L906-926) tire les commandes du JOUR SANS filtre statut (date + per_page:100 seulement ; `OrderService::list` n'a aucun whereIn statut-actif par défaut). Toute commande tirée à PENDING-non-web tombe dans `this.orders` (gonfle `stats.todayCount` L723) mais ne s'affiche dans AUCUNE colonne → introuvable sur le board. Base : **162** PENDING non-web (kiosk 112, NULL 42, pos 4, delivery 4). Le web PENDING a carte + CTA « Accepter » ; une commande **téléphone/pos** PENDING n'a RIEN → asymétrie. Caveat honnête : kiosk auto-accepte au paiement, donc la majorité des kiosk-PENDING = paniers abandonnés (pas de vraies commandes actives) → sévérité modérée à P2.
- **D-3 (P3, data) — 16 commandes hors enum.** status **2** (id=235, kiosk) + status **5** (15 commandes delivery du 2026-06-17, timestamps identiques = artefact seed/import). Absents d'OrderStatus → invisibles PARTOUT (tracker/KDS/OSS filtrent des statuts connus). Non atteignables par le code actuel ; nettoyage données recommandé, pas de correctif code.

## 4. Programmées — ROBUSTE, aucun finding

Timer ancré sur `scheduled_at` : `kitchen_timer_anchor_iso = scheduled_at − lead` (KDSOrderDetailsResource L57-61), PAS created_at. Board plancher de grâce `now−grace ≤ scheduled_at ≤ now+lead` (KitchenReleaseRule L187-202) → no-show sort du board après `grace` h. Bump gardé `orderIsWithinScheduledWindow` 422 (KDS L584). Divergence connue/auto-documentée : le guard de bump n'a pas le plancher (no-show invisible mais techniquement bumpable) — sens bénin (jamais « visible non-bumpable »). Pas un nouveau finding.

## 5. A11y (lecture template) — 1 finding

- **D-4 (P3).** Boutons icon-only du tracker — reprint (L277-286), œil/détails (L266-272), annuler/ban (L294-303) — ont `:title` mais AUCUN `aria-label` ; `<i>` en `aria-hidden` → nom accessible reposant sur `title` (faible). **PAS un défaut destructif** : l'annulation icon-only ouvre une confirmation modale exigeant motif ≥3 car (L1029-1036, role=dialog aria-modal). Encaisser/Accepter/Livré ont texte `hidden xl:inline` + title. Reco : ajouter `aria-label` aux 3 boutons icon-only.

## Verdict
Cœur lifecycle (transitions, idempotence, KDS release/scheduled) = **SOLIDE**, defense-in-depth réelle. 4 findings : 2×P2 (D-1 Admin-resurrection, D-2 fantôme PENDING-non-web), 2×P3 (D-3 données hors-enum, D-4 aria-label). Aucun P0/P1. Aucun fichier modifié.
