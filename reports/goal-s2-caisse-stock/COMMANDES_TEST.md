# Commandes de test créées / modifiées par S2 (DISCIPLINE §5)

> DB **dev locale** `foodking_e2e` uniquement — AUCUNE commande sur le site live,
> AUCUN paiement réel, AUCUNE clé Mollie utilisée.

| Date | Commande | Action | Détail | Réversible ? |
|---|---|---|---|---|
| 2026-07-29 | id=5528 · N°A0007 · 0607265528 | **Encaissée en espèces** (e2e V2 réel) | total 7,40 € · reçu 10,00 € · rendu 2,60 € · `payment_status` 15→5 (PAID) · `fiscal_sequence_no` NULL→**2690** + `fiscal_dated_at` 2026-07-29 16:00:46 · `CashMovement order_payment = 7,40 €` | ⚠️ **NON** — séquence fiscale allouée (NF525 : jamais de trou). Commande de la file dormante du 06/07 en DB dev. Rien à annuler côté owner (hors prod). |

## Preuve money-path (au centime) issue de cette commande
- Le tiroir enregistre **7,40 €** (le TOTAL), pas les 10,00 € reçus → net physique exact
  (10,00 entrés − 2,60 rendus = 7,40). Confirme `PaymentService.php:607-619`.
- `pos_received_amount` = 10,00 stocké ; le rendu n'est **pas** persisté (dérivé serveur).
- Séquence fiscale allouée **à l'encaissement** (pas à la création) pour un différé comptoir,
  avec `fiscal_dated_at` tamponné → conforme au LOCK_ZREPORT_FISCAL_C33.
- `php artisan fiscal:verify-chain --all` → **CHAIN OK sur les 4 branches actives** après l'opération.
