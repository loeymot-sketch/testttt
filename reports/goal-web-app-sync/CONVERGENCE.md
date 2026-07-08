# GOAL WEB+APP SYNC BORNE — CONVERGENCE (2026-07-08)

**Verdict : CONVERGÉ.** Critère §0.5 atteint = 2 cycles W6 consécutifs P0+P1=0 (R1 post-heal + R2 indépendant). Reste 1 P2 + 5 P3 pré-existants/hors-périmètre, non bloquants.

## Périmètre respecté (owner mandate)
- SEUL fichier backend applicatif modifié : `app/Http/PaymentGateways/Gateways/Stripe.php` (guard 503, non-frozen). Tout le reste = `mobile/**`, `/Users/1millnonstop/Downloads/web/**`, `tools/parity/**`, tests.
- Frozen diff (11 chemins §7) = **0 ligne**. Caisse/borne : **zéro modification**.
- NF525 append-only : audit_logs 4930→4938 (croissance, min_id=1, aucune suppression), z_reports=25 inchangé.
- `routes/api.php` : **0 ligne committée par ce GOAL** (le churn working-tree = SELF-AUDIT 2026-07-05 pré-existant, NON committé ici).

## Livrables §F — preuves d'exécution
| Livrable | Preuve |
|---|---|
| **Catalogue 42 zéro-divergence** web+mobile | `tools/parity/check-parity.mjs --surface=all` VERT (0/2) + auto-test mutation détectée. 38 SKU mirror = 42 borne − 4 frites cheddar servies par wizard frites_style (divergence structurelle documentée D2, atteignabilité prix au centime prouvée). |
| **Commande réelle** web+mobile | Orders live 5596-5606 : base/full+2,50/frites+1,50/boisson+1,00 exacts vs PricingService, 0×422. Cheese Burger 6,00→8,50/7,50/7,00 ; Big Burger 9,00→11,50/10,50/10,00 ; Cayenne 7,40→9,90/8,90/8,40. |
| **Stripe prêt-OFF + testable ON** | Web checkout counter-only (DOM) ; mobile ModalPayChoice sans CB ; flag ON runtime → option réapparaît (vrai flag). Triple-verrou serveur `config/payment.php` + row id=4 status=10 + webhook 503 (test 3/3). |
| **Fidélité phone→QR→scan→points→soldes synchrones** | phone→guest-signup→QR signé `lqr.` mint-on-display TTL 300s→scan borne ok→replay `qr_replay`→forge `qr_invalid_signature`→expiré `qr_expired`→legacy `qr_legacy_rejected` ; earn #5570 +1 pt floor idempotent ; **web `api.profile()` === mobile `mobileApi.profile()` === backend**, mutation reflétée des 2 côtés. QR SVG réel rendu web ET mobile. |

## Vagues
- **W0** fixture canonique borne (9 cat/42 items). **W1** cartographie 29 agents (235 findings, 15 P0). **W2** GOAL doc.
- **WF-2** 14 implémenteurs + 3 intégrateurs : web fidélité réelle + parity + Stripe flag ; mobile OTP réel + commande + wallet QR + parity + `api/client.js` ; backend Stripe guard.
- **W6 R1** 25 agents e2e/sécu/visuel/adversaires → 8 réels healés (formule 422+prix, redeem branch_id, Ikyes, gate blind-spot, offline). **W6 R2** 10 agents ciblés → 10/10 PASS, P0+P1=0.
- **Heals** : formule menu → addon `menu_component` (fix 422 + prix +1,50/+1,00) ; redeem `branch_id` ; purge résidus démo (Ikyes/IB nav + Leaderboard/Challenge morts) ; gate valide 3 formules ; bandeau hors-ligne honnête mobile.

## Reste (non bloquant, hors périmètre / owner)
- **P2** : `routes/api.php` working-tree churn pré-existant (SELF-AUDIT 2026-07-05) — à committer séparément ou restaurer par l'owner, PAS par ce GOAL.
- **P3** : achievements a1/a2 statiques (web), fallback `Config accept_legacy_plaintext=true` au controller (non exploitable, config effectif=false), FAQ mentionne paiement en ligne (aspirationnel, flag OFF).
- **G4 owner-gate futur** : câblage physique scanner QR→UI borne (borne frozen) — endpoint `/loyalty/scan` + QR prêts et prouvés.
- **Gates owner §10** : push des 2 repos (testttt `7470535a6`+heals, web `31a4d71`+heals) + deploy VPS.
