# P2 — Multi-tender complet (handoff + état d’avancement)

## Audit codebase (snapshot)

| Élément | État |
|---------|------|
| Mode paiement POS | Un seul `orders.pos_payment_method` (int) + `pos_payment_note`, `pos_received_amount` (cash) |
| Alignement gateway web | `PaymentGateway::TICKET_RESTAURANT = 5` (kiosk) — **POS expose maintenant `PosPaymentMethod::TICKET_RESTAURANT = 5`** |
| Z-report `total_by_method` | Agrégation **une entrée par commande** : clé = `pos_payment_method \|\| payment_method` — pas de ventillation multi-tenders sur une même commande |
| Remises | Coupon + remise manuelle + audit `order.discount_applied` (NF525) |
| Pourboire | **Non modélisé** (pas de colonne dédiée) — à traiter en phase ultérieure (séparer TTC repas vs pourboire) |
| Split bill | **Absent** (pas de table `order_payment_lines` / `order_tenders`) |
| `transactions` | Création surtout chemins `PaymentService` (gateways) — POS **pas** systématiquement dupliqué en ligne `transactions` |

## Implémenté dans ce cycle (P2 — socle)

- Constante **`PosPaymentMethod::TICKET_RESTAURANT`** + **`PosOrderRequest`** : `pos_payment_note` obligatoire (référence chèque), comme « Other ».
- Libellés i18n `pos_payment_method.*` (en, fr, de, ar, bn).
- Test **`PosTicketRestaurantPaymentTest`** : POST `/api/admin/pos` → 201, note persistée, Z-clé future = `5`.

## Matrice scénarios (cible qualité + couverture)

Légende : **OK** = couvert par tests auto / prod-ready · **Partiel** = comportement partiel · **Backlog** = non implémenté

| # | Scénario | Attendu | Statut |
|---|----------|---------|--------|
| 1 | Paiement 100 % espèces | `pos_payment_method=1`, monnaie si `pos_received_amount` > total | OK (existant + validations) |
| 2 | Paiement 100 % CB (TPE) | `pos_payment_method=2`, masque 4 derniers en note | OK (existant) |
| 3 | Paiement titre-restaurant seul (montant = total) | Méthode 5 + référence note | **OK** (ce cycle) |
| 4 | Split 50/50 CB + espèces | Deux lignes tender / une commande | Backlog |
| 5 | Split par article (couvert A vs B) | Sous-commandes ou lignes fiscal | Backlog |
| 6 | Split par montant (30 € TR + reste CB) | N lignes tender dont TR | Backlog |
| 7 | Cash + CB même ticket | Agrégats Z par tender **et** total commande | Backlog |
| 8 | TR + espèces complément | Rendu monnaie uniquement sur partie cash | Backlog |
| 9 | TR + carte complément | Deux autorisations ou une + ajustement | Backlog |
| 10 | Bon cadeau interne | Code promo / bon — séparé encaissement | Partiel (coupons) |
| 11 | Pourboire montant fixe | Hors TVA repas ou ligne séparée | Backlog |
| 12 | Pourboire % addition | Calcul SSOT serveur | Backlog |
| 13 | Remise ligne | Sur `order_items` | Partiel |
| 14 | Remise ticket (%) | Remise manuelle / coupon | OK |
| 15 | Remise manager > seuil | Permissions Spatie | OK (`PosOrderRequest`) |
| 16 | Arrondi ligne 2 décimales | PricingService | OK |
| 17 | Arrondi total commande | PricingService / POS | OK |
| 18 | Rendu monnaie cohérent | `pos_received_amount` ≥ total réel serveur | OK |
| 19 | Refus si espèces insuffisantes | 422 | OK |
| 20 | Paiement partiel annulé avant clôture | State machine | Partiel |
| 21 | Retour à encaissement tiers | — | Backlog |
| 22 | Z-report ventilé par mode | `total_by_method` | Partiel (1 mode / commande) |
| 23 | Z-report ventilé TVA | `total_by_tax_rate` | OK |
| 24 | Audit chaîné chaque tender | `audit_logs` | Partiel (commande/discount) |
| 25 | X-report intraday | Réutilise `aggregate` | OK (service) |
| 26 | Signature Z inchangée si faible secret prod | garde `fiscal.z_report_secret` | OK |
| 27 | Commande kiosk TR | `PaymentGateway::TICKET_RESTAURANT` | OK (existant) |
| 28 | Commande POS TR | `PosPaymentMethod::TICKET_RESTAURANT` | **OK** (ce cycle) |
| 29 | Rapport ventes par caissier | — | Backlog |
| 30 | Rapport pourboires | — | Backlog |
| 31 | TR + remise incompatible (métier) | Règle à définir | Backlog |
| 32 | Double frappe prevention (idempotency) | POS idempotency key | OK |
| 33 | Concurrence deux paiements même order | Lock / état | Backlog |

*(Les lignes 4–10, 11–12, 21, 29–31, 33 représentent le cœur du **reliquat P2** : schéma données + UI POS + règles NF525 sur multi-lignes.)*

## SYMMETRY_NOTE

- Kiosk : `PaymentGateway::TICKET_RESTAURANT` ; POS : `PosPaymentMethod::TICKET_RESTAURANT` — **même valeur `5`** pour rapprochements reporting / Z.

## Phases recommandées (suite)

1. **Schéma** : `order_payments` (`order_id`, `branch_id`, `method`, `amount`, `reference`, `created_at`) + migration + FK.
2. **Service** : `OrderPaymentSettlementService` — validation Σ payments = total TTC (± règles pourboire).
3. **Z-report** : agréger depuis `order_payments` (plusieurs lignes par commande).
4. **Audit** : un `audit_logs` par ligne tender significative (ou batch signé).

## Tests ajoutés

- `tests/Feature/PosTicketRestaurantPaymentTest.php`

## Invariants

- **Prix SSOT** : inchangé — aucun calcul tender côté Vue pour les montants TTC repas.
- **NF525** : les Z actuels restent valides ; l’extension multi-ligne exigera une **version** ou champs additionnels dans le payload signé.
