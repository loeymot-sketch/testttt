# ACCÈS CAISSIER + GESTION + HISTORIQUES + COMMANDES WEB — audit logique/UX (dev-senior)
READ-ONLY. Date 2026-07-24. DB `foodking_e2e` (réelle) : status 1=48, 4=27, 7=310, 8=79, 13=2180, **16(Annulée)=98, 19(Refusée)=150, 22(Retournée)=26** ; surfaces pos=1785 kiosk=944 web=83 phone=6 NULL=83.

## Verdict global
Le socle est SOLIDE et cohérent. Flux web accept→encaisser complet, contrôle temps-réel riche, histo unifié réel, écrans gestion tous dans la sidebar + gated. 2 vrais trous d'usage (filtres histo + refus web post-accept), 1 edge. Aucun P0/P1 logique/sync.

---
## FINDINGS

### P2-1 — Histo : impossible de FILTRER les commandes Annulées/Refusées/Retournées
- `resources/js/components/admin/orderHistory/HistoriqueListComponent.vue:33-39` — le `<vue-select>` statut n'offre QUE Acceptée/En préparation/Prête/Livrée.
- Jumeau : `resources/js/components/admin/posOrders/PosOrderListComponent.vue:36-38` (Acceptée/Préparation/Livrée seulement).
- **Repro** : /admin/historique → filtre Statut → aucune option « Annulée ». Or 98+150+26=274 commandes concernées en base. Elles s'affichent bien dans la liste non-filtrée avec le bon libellé FR (`SimpleOrderResource:74 status_name` + `lang/fr/orderStatus.php` OK), MAIS on ne peut pas les ISOLER.
- **Pourquoi c'est un vrai problème** : l'owner demande explicitement « accès CLAIR aux commandes ANNULÉES ». Pour auditer les annulations d'un mois il faut scanner toutes les pages. La page /admin/online-orders, elle, PROPOSE ces options (`OnlineOrderListComponent.vue:42-44`) → le pattern existe, l'histo unifié a régressé.
- **Reco** : ajouter CANCELED/REJECTED/RETURNED (+ PREPARED pour pos-orders) aux 2 dropdowns. (Bonus : l'histo n'a pas de bouton Export/PDF contrairement aux 2 autres listes.)

### P2-2 — Refuser une commande web APRÈS acceptation : aucun bouton sur l'écran dédié
- `OnlineOrderShowComponent.vue:58` — le refus-avec-motif (`OnlineOrderReasonComponent`) n'est rendu que si `status === PENDING`.
- `OnlineOrderShowComponent.vue:387-397` — le dropdown `orderStatusObject` ne contient AUCUN CANCELED/REJECTED (que ACCEPT/PREPARING/PREPARED/[OUT_FOR_DELIVERY]/DELIVERED).
- Or la caisse accepte en 1 clic (`PosComponent.vue:483` / tracker `PosOrdersTrackerComponent.vue:255-265`) → PENDING→ACCEPT immédiat → l'écran « Commandes en ligne › Détails » n'offre plus AUCUN moyen d'annuler/refuser.
- **Seul chemin de refus post-accept** = le bouton Annuler du POS tracker (`PosOrdersTrackerComponent.vue:294-303`→`confirmCancelOrder:1043`), qui LUI capture le motif et fonctionne (`PosOrderController::changeStatus` accepte toute commande ; annulation d'une non-payée = 0 mouvement tiroir, non gated — vérifié).
- **Pourquoi c'est un vrai problème** : un caissier qui ouvre « Détails » pour gérer un désistement client ne trouve pas de bouton ; il doit DEVINER qu'il faut passer par le tracker. De plus le motif saisi via tracker n'est PAS ré-affiché : le bloc motif d'`OnlineOrderShowComponent.vue:229` ne s'affiche que pour REJECTED, pas CANCELED.
- **Reco** : surfacer un CTA « Refuser (motif) » sur OnlineOrderShow pour une web acceptée non-finale (miroir tracker), + afficher `order.reason` aussi pour CANCELED.

### P3-1 — Une commande PENDING non-web est invisible sur le board caisse
- `PosOrdersTrackerComponent.vue:593-620` : un PENDING n'est bucketé QUE si `isWebPending`. Un PENDING téléphone/panier abandonné (6 phone, 48 status=1 en base) ne tombe dans aucune voie → absent du tracker.
- **Mitigé** : visible dans Historique/pos-orders ; le compteur « aujourd'hui » l'exclut déjà (heal D-2, `:733`). Acceptable V1.
- **Reco** : si les commandes téléphone montent, ajouter une pastille « Autres en attente ».

---
## CONFIRMÉ SAIN (preuves)
- **Web accept→plus loin** : panneau web POS (Accepter inline + Détails, `PosComponent.vue:450-518`) + tracker inline (`:255`) → devient cash-pending → CTA Encaisser (`:237`) → `PosCounterCollectModal`. Idempotent (clé minute-bucket). Refus AVANT accept = motif OK. Livreur assignable, paiement comptoir OK.
- **Contrôle** : tracker 5 voies (À encaisser/Préparation/Prêts/Livraison/Livrés), recherche, filtre source, Echo `branch.{id}` + poll escape-hatch anti-worker-mort (`:828-838`), annuler-motif, réimprimer (`:277`), marquer livré, badges âge 5/10 min.
- **Histos accessibles** (sidebar `BackendMenuComponent.vue:110-115`) : Historique unifié (toutes sources, filtres origine/statut/paiement/date + colonnes fiscal_seq & lien remboursement `parent_order_id`), pos-orders, online-orders. Re-commande via `pos-order/reorder-items`.
- **Gestion** : catalog-hub(stock), encaissement, cash-overview (caisse unifiée), delivery-boy-cash-sessions (caisse livreur) — tous gated `pos-orders`.
- **Sync** : actions caissier → Echo → tracker/KDS/panneaux ; navbar bridge Echo→`realtime-order-update` (`BackendNavbarComponent.vue:392`) → listes online/pos/table se rafraîchissent. Note robustesse : ces LISTES n'ont pas de poll de secours (contrairement au tracker) si le worker queue est mort.
