# CONVERGENCE — test-e2e FIDÉLITÉ GLOBALE A→Z (2026-06-10/11)

**Verdict : système fidélité backend SAIN et PROUVÉ bout-en-bout · clients unifiés et verts · 2 décisions owner créées (D11 barème, D12 wireup QR).** Dual-team (pilote a333174a + adversaire aa883bb8), mutations sur clone `foodking_e2e` uniquement, 30+ artefacts.

## A→Z prouvé LIVE (pas supposé)
| Étape | Preuve |
|---|---|
| Config & admin | API `/loyalty/config` {10 pts/€, 100 pts=1€, min 50} ; page admin loyalty-setup (3 champs + aperçu) capturée |
| **EARN réel** | event DELIVERED → `AwardLoyaltyPointsOnDelivery` : commande 1,50 € → +15 pts, solde 250→265, `loyalty_transactions` tx#10, sentinel idempotence |
| Consultation | `/balance` {points:265, discount_value:2.65} + `/history` (Bearer+x-api-key, throttles anti-énumération en place) |
| **REDEEM POS réel** (UI LOCKée, route exercée) | commande POS 4511 : redeem 100 pts → −1,00 €, total 8,50→7,50, solde 265→165, tx#11 ; **replay idempotency = réponse cachée 0 double-débit ; nouvelle clé = 409 ALREADY_REDEEMED** |
| Clawback | refund→clawback earn + cancel→refundPoints couverts par tests (3 sentinels) ; P3 : pro-rata refund partiel différé |
| Suites | PHPUnit `--filter=Loyalty` **79/0** (24 classes) ; clients : node 42 mobile + 8 web |
| Clients | mobile : 347 pts → wizard redeem 100 → 247 prouvé écran ; web : panneau continu 347→3,47 € → Utiliser → 0, héro healé (F-LOY-4) |

## Findings & dispositions
- **F-LOY-1 → décision owner D11** : earn backend **10 pts/€ floor** (3 fallbacks hard-codés — AUCUN seed ; le « 1 pt/€ canonique » gate-D1 ne couvrait que les clients) vs clients **1 pt/€ round**. Backend auto-cohérent (la borne affiche ce qu'elle crédite) donc pas de contradiction visible V1 — mais l'économie LIVE = 10% de cashback vs 1% voulu. Min 50 vs 100 inclus dans D11.
- **F-LOY-4 ✅ HEALÉ** : héro web suivait un solde figé après « Utiliser » → même état que le panneau (vérifié source, hardcode supprimé).
- **F-LOY-5/6 → D12 (wireup)** : QR mobile plaintext `FK:` rejeté caisse (`accept_legacy_plaintext=false` — fix correct = mint signé `POST /loyalty/qr`) ; codes récompense LCY-XXXXXX sans pendant backend. Sans impact V1 standalone.
- **F-LOY-3 P3** : +25 pts inscription promis clients, non backé (gate publication).
- Sécurité : GREEN (throttles, PII flag off, idempotence kiosk+POS prouvée).
