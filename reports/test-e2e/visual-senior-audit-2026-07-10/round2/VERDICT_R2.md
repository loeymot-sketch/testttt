# ROUND 2 — MAX TECHNIQUE + UI (2026-07-10) — CONVERGÉ

## Push tout ✓
testttt `ec609ca7e` (+ heals impression : fallback largeur borne→42, knob codepage € SK1-31, test 7/7).

## Surfaces JAMAIS capturées → capturées + validées
| Surface | Verdict | Lecture senior |
|---|---|---|
| **KDS** | ✅ | Ticket symbolique (1× G\|TAC\|L\|Mex Mex\|MAY), badges EN COURS+BORNE, bandeau « EN ATTENTE ENCAISSEMENT » (counter-deferred correct), bouton Prêt, mode secours 5s honnête |
| **OSS** | ✅ | N°A0034 « En préparation » = **sync visuelle KDS↔OSS** ; **0 ligne vide → heal zombie confirmé à l'écran** |
| **Borne panier** | ✅ | À emporter, stepper qty, total 1,90, CTA clairs |
| **Borne upsell** | ✅ | Canettes entières (fix contain), auto-skip 9s. P3 : cross-sell boissons alors que panier=boisson |
| **Borne paiement** | ✅ | Plan B « PAIEMENT À LA CAISSE », total cohérent |
| **Borne confirmation** | ✅ | #A0035 géant, montant, moyens de paiement, réimprimer ticket, retour auto |

## Preuves live Round 2
- **Rate-limit fix** re-prouvé en vrai flux UI : commande 5637 créée (A0035) sans 429.
- **Cancel caisse fix (bug IMG_1753)** prouvé en live : `counter-collect/cancel` motif libre « Annulée » → **HTTP 200** ×2 (5637+5633). Le 1er 422 rencontré = garde X-Idempotency-Key (by design, PAS un défaut).
- **Heal web sauce** vérifié à l'écran : wizard « 1 sauce incluse · Sélection 0/1 » (fin du 422 checkout).
- **Queue séquentielle** A0034→A0035 ✓.
- Nettoyage : 2 commandes test annulées via la route caisse légitime, OSS wall=0, NF525 append-only (4941).

## Boucle complète (2 rounds cumulés)
Caisse (6 pages) ✓ · Borne (8 pages) ✓ · Web (menu+wizard+checkout, heal vérifié) ✓ · KDS ✓ · OSS ✓ ·
Sync borne→caisse→KDS→OSS visuelle ✓ · Adversaire (R1 : 2 workflows, 1 P1 réel healé + fausses alertes réfutées) ✓.
Restes documentés : LOCK multi-sauce (NF525, owner), backend public pour commandes web (infra), P3 cross-sell upsell.
