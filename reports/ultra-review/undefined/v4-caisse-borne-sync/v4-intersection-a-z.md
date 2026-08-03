# V4 — Intersection A→Z + zéro-doublage cross-surface

Cible : chaîne complète caisse-inline + borne-PlanB → KDS → OSS → encaissement → fiscal → ticket.
Posture : GREEN = hypothèse à réfuter. HEAD 61e9ea7b7 + working-tree. DB foodking_e2e (live, activement mutée par le loop e2e parent).

## VERDICT : GREEN_HELD

Aucune rupture NOUVELLE et reproductible de l'intersection / zéro-doublage n'a survécu à la vérification.
Les deux anomalies trouvées sont (a) un heal DÉJÀ fait (V2 !deferToCounter, ordre de ne PAS re-signaler) et (b) un artefact de test-tooling. Aucune n'est un bug de code de production reproductible.

---

## Held-green (attesté, lecture seule)

1. **Fiscal seq : 1 commande = 1 numéro, gap-free chez les émis, 0 doublon.**
   `Order::whereNotNull(fiscal_sequence_no)` branch 1 : min=1 max=2603, 0 dupe. `fiscal:verify-chain --all` = CHAIN OK sur 4 branches.

2. **Encaissement borne = zéro-doublage total.** 8 commandes kiosk encaissées récentes : chacune exactement 1 `transactions(payment)`, 1 `fiscal_sequence_no`, et cash-movement = 1 si ppm=CASH (cmSum==total) / 0 si ppm=CARD. Ex. #5447 (post-heal, fseq=2607) : tx=1 cm=1 cmSum=1.90=total. #5368 carte 31,80 € : cm=0. Aucun `<== DOUBLE`.

3. **Garde double-encaissement robuste.** `PaymentService::confirmCounterPayment` L278 : si `payment_status==PAID`, même caissier → no-op 200 (pas de 2e cash_movement), caissier différent/inconnu → `PaymentAlreadyCollectedException` 409 ; route `idempotency`+`throttle`. Un 2e appel ne peut pas écrire de 2e mouvement.

4. **KDS filtre status+payment, PAS de station (kds_station = mythe confirmé).** `KitchenDisplaySystemOrderService::list` → `whereIn('status', KitchenReleaseRule::visibleStatuses())` ; `KitchenReleaseRule::isReleasedForBoard` admet PAID | PENDING_COUNTER | POS-cash. Zéro référence à une colonne station.

5. **composition_snapshot = SSOT figé lu (non recalculé) par toutes les surfaces d'impression.** `OrderReceiptEscPosRenderer` L253/L342 et `KitchenTicketSymbolicFormatter` lisent `order_items.composition_snapshot`. Ticket client et ticket cuisine dérivent du MÊME snapshot → parité garantie, zéro drift si le menu change après coup.

6. **File « à encaisser » = zéro doublon, zéro fuite terminale.** 7 commandes PENDING_COUNTER+COUNTER_DEFERRED, 7 ids uniques (0 dupe), toutes src=kiosk, 0 commande CANCELED/REJECTED/RETURNED en PENDING_COUNTER (heal V4 exclusion terminale effectif).

7. **Outbox sans doublage.** `domain_events` : 0 aggregate avec >1 `order.created` → KDS/OSS ne peuvent pas afficher une commande deux fois via le temps-réel.

8. **POS inline carte = pas de cash-movement fantôme.** #5444 (pos, ppm=CARD, PAID, fseq=2605) : 0 cash_movement, 1 fiscal. Correct.

---

## Anomalies trouvées — NON retenues comme broken

### A. Cash-trail doublé sur 3 commandes POS-différées (= heal V2, ne pas re-signaler)
`cash_movements(order_payment)` en double sur #5398 (7,90→15,80), #5402 (9,90→19,80), #5426 (7,00→14,00) : un mouvement à la CRÉATION + un à l'ENCAISSEMENT. C'est EXACTEMENT le mécanisme corrigé par le heal V2 `! $deferToCounter` (OrderService:1268-1276, +9 lignes working-tree, mtime 04:51:41).
- Les 3 commandes ont été créées AVANT le heal (01:03 / 04:17 / 04:39 < 04:51). 
- Scan complet : EXACTEMENT 3 commandes doublées dans toute la base, 0 après le heal malgré l'activité continue (jusqu'à #5451). → heal effectif, 0 récurrence.
- Owner : « cash_movement gate !deferToCounter (V2) » = heal déjà fait → NE PAS re-signaler.
- **Observation résiduelle (non-broken)** : les 3 mouvements fantômes (+30,70 €) sont dans le Z OUVERT #26 (ouvert 2026-06-25). Le heal corrige l'avenir mais ne nettoie pas la piste polluée ; à régulariser avant clôture Z. Donnée de test, Z non signé.

### B. Gap fiscal 2506-2508 = hard-delete par le test-tooling (artefact)
3 numéros fiscaux manquants (branch 1) : les commandes 5012-5015 sont HARD-deleted (absentes même `withTrashed()`) mais leurs `audit_logs` subsistent (chaîne HMAC intacte). Cause = commandes de cleanup e2e (`E2EStressCommand`, `CleanupWebTestOrders`, `Iter15Cleanup`) — console-only. La prod utilise le soft-delete (Order::restoring throw, one-way). `FiscalSequenceService::next()` dérive `MAX+1` des orders persistés → self-healing, pas de réutilisation/doublon. Aucun chemin runtime utilisateur ne hard-delete une commande fiscalisée. → artefact test-DB, pas un bug intersection.

---

## Attaques lancées
- Scan systématique cash_movements doublés (toute la base) — 3 hits, tous pré-heal, 0 post-heal.
- Gap/dupe analysis fiscal_sequence_no par branche + reconstruction du hard-delete (audit orphelins).
- `fiscal:verify-chain --all` — CHAIN OK ×4.
- Zéro-doublage sur 8 encaissements borne (tx/cm/fiscal par commande).
- Garde double-encaissement (lecture code confirmCounterPayment L278-462 + assertCounterDeferredOrder).
- KDS release rule (status+payment, pas de station).
- Parité snapshot ticket client ↔ cuisine (lecture composition_snapshot).
- File « à encaisser » : unicité + fuite terminale.
- Outbox order.created dupliqués.
- POS inline carte : cash-movement fantôme.
