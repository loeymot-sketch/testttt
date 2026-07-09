# NUIT Wave A2 — Convergence Critic (2e passage systèmes/flux)

HEAD 86e3eee22 · READ-ONLY · DB foodking_e2e (127.0.0.1:8766)

## Verdict : DRY

Un 2e passage adversarial profond sur les SYSTÈMES/FLUX (kiosk, POS, KDS,
web/mobile standalone, fidélité, intersections) ne surface AUCUN nouveau
bug auto-réparable, sûr et non-déjà-documenté. La robustesse est attestée.

## Ce qui a été re-attaqué et confirmé ROBUSTE (pas de nouveau finding)

1. **Double-award fidélité (concurrence/idempotence)** — `AwardLoyaltyPointsOnDelivery::handle`
   (l.51-59) verrouille via sentinelle atomique `UPDATE ... WHERE loyalty_points_awarded IS NULL
   AND status != CANCELED SET = -1` ; `if ($updated === 0) return`. Un PREPARED puis DELIVERED
   ne crédite qu'une fois. Rollback propre (`= null`) sur tous les early-return (user absent,
   rate<=0, points<=0). Pas de faille de double-crédit. ROBUSTE.

2. **min_redeem serveur (chemin pricing)** — `DiscountCalculator.php:58-64` applique
   `if ($pointsRequired < $minRedeemPoints) return ['discount'=>0]`. L'enforcement EXISTE sur
   le chemin de tarification web/kiosk. Le P3 déjà confirmé (« non appliqué côté serveur »)
   vise le chemin POS-redemption distinct — déjà documenté, pas un nouveau vecteur.

3. **Clawback earn sur refund** — `ClawbackLoyaltyPointsOnRefund` gère les sentinelles
   null/0/-1 (l.51-53) et clawback le montant plein, idempotent. Le trou = annulation
   PENDING_COUNTER (P2 déjà confirmé), pas un nouveau chemin.

## Findings A2 confirmés (déjà couverts, non ré-ouverts)
Les 9 findings A2 (P2 award-unpaid + 8×P3) sont documentés dans les rapports frères de ce
dossier. Rien à ajouter.

## Pourquoi pas de new_safe_heals
Les 2 seuls défauts « corrigeables logiquement » restants (drift SSOT Capri-Sun +0,40€ et
« Menu complet +3,00€ » vs +2,50€ réel) vivent dans **`KioskWizardComponent.vue` (FROZEN §7)**
→ non auto-réparables sans LOCK + gate owner. Les autres P3 (customer-display gate `permission:pos`,
prune `order_quotes`, borne taille `parked_orders.payload_json`, `total_tax` figé post-redeem)
sont soit déjà documentés dans la liste confirmée A2, soit en chevauchement Wave A db-growth.
Aucun n'est à la fois NOUVEAU + NON-FROZEN + SÛR + NON-DOCUMENTÉ.

## Conclusion
CONVERGENCE = DRY. Robustesse systèmes/flux attestée au 2e passage.
