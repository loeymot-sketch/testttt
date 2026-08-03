# PROOF MATRIX — re-preuve état convergé (2026-07-09)

Chaque dimension : prouvée par un agent (commandes réelles live) PUIS attaquée par un
adversaire indépendant (reproduction, pas relecture). Workflow `wf_59ad8ece-9a3`,
12 agents, 0 erreur, 897k tokens. Serveurs live :8766/:8000, web :8096.

| Dimension | Verdict | Adversaire réfute ? | Preuve clé |
|---|---|---|---|
| **Parity + Frozen + Boot** | ✅ PASS | ❌ Non (high) | parity `--surface=all` EXIT 0, 0 div ; gate NON no-op (mutation → EXIT 1) ; frozen 12 chemins = 0 fichier ; seul app/ = Stripe.php (503 guard L75) ; boot guards AppServiceProvider:178+ prod-gated |
| **Pricing formule + 0×422** | ✅ PASS | ❌ Non (high) | Ordres LIVE NEUFS 5618/5619/5620 + adversaire 5626 : base 6,00 → full 8,50 / +frites 7,50 / +boisson 7,00 ; ratios kiosk.php 1.0/0.6/0.4 ; snapshot NF525 figé ; 0×422 sur compo complète |
| **Loyalty sync** | ✅ PASS | ❌ Non (high) | Suite `--filter=Loyalty` **83/83** (2× indép.) ; QR signé tamper→qr_invalid_signature ; balance web api.profile === mobile === backend ; redeem porte branch_id |
| **Stripe OFF** | ✅ PASS | ❌ Non (high) | `--filter=Stripe` **34/34** ; DB stripe_secret_len=0, gateway status=10 ; webhook 503 ; flag runtime gate réel |
| **NF525 chain** | ✅ PASS | ❌ Non (high) | `fiscal:verify-chain --all` EXIT 0, branches 1/7/8/9 "CHAIN OK" ; audit_logs append-only min_id=1 ; z_reports=25 inchangé ; fichiers Fiscal/* 0 diff |
| **Cross-surface sync** | ✅ PASS | ❌ Non (high) | Ordre LIVE 5622 : source=kiosk, payment PENDING → présent caisse à-encaisser + KDS + snapshot compo figé ; prix identique 3 surfaces |

## Défauts trouvés (tous P3, non bloquants)
- **P3** `PricingService.php:207-217` — commentaire obsolète (prix addon 3.00/1.20 au lieu de 2.50/1.00). Commentaire seul, **0 impact logique**. (fix trivial 1-ligne, non-frozen.)
- **P3** Gap `orders.fiscal_sequence_no` branche 1 (2506,2507,2508 manquants) — alloué-puis-rollback 2026-06-19/20, **PAS causé par le goal**, `verify-chain` = CHAIN OK. Pré-existant.

## Preuve visuelle (captures lues, run captures/)
- Borne idle :8766 — propre, on-brand, portrait, 0 i18n brut.
- Web Fidélité — règle « 1€=1pt / 100pts=1€ », auth-gated, **0 résidu Ikyes**.
- Web Menu — « 8 cat · 38 créations » (attendu), prix exacts, filtres, panier a11y.

## VERDICT
**État convergé RE-PROUVÉ.** P0+P1 = 0 (preuve directe + adversaire indépendant + visuel).
2 P3 benins/pré-existants. GO technique pour push+deploy (sous gate owner §10).
