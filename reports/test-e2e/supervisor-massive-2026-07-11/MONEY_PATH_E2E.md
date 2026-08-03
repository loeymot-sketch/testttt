# MONEY-PATH E2E RÉEL (supervisor, 2026-07-11)

## Parcours prouvé (navigateur réel)
1. Commande borne **5638 (A0032)** créée via UI (Coca 1,90€) → OrderCreated dispatché WS (goal sync).
2. Caisse `/admin/encaissement` : modal « Encaisser La Commande Borne », 4 moyens
   (Espèce/Carte TPE/Mobile/Ticket restaurant), pavé numérique, ticket client+cuisine.
3. Encaissement **espèces** commande borne 5618 (A0032, 8,50€) → « Confirmer & Imprimer ticket ».

## Preuve NF525 (DB réelle, avant→après)
| Métrique | AVANT | APRÈS |
|---|---|---|
| order.payment_status | 15 (dû) | **5 (payée)** |
| order.fiscal_sequence_no | NULL | **2642** (monotone +1 depuis 2641, gap-free) |
| audit_logs (append-only) | 4943 | **4945** (+2 : alloc fiscale + confirm paiement) |
| max fiscal_seq branch1 | 2641 | **2642** |
| NF525 chain | clean | **clean (4 branches)** |

**Verdict** : le money-path caisse alloue correctement la séquence fiscale gap-free, marque payée,
appende la chaîne audit inviolable, chaîne intègre. Conforme NF525 §8.

## 2ᵉ méthode — CARTE / TPE (simulation)
Order 5427 (A0017, 6,90€) encaissé par Carte → payment_status=5, **fiscal_seq=2643**, audit_logs 4945→4946, NF525 clean.

## Progression fiscale globale (2 encaissements consécutifs)
**2641 → 2642 (espèces) → 2643 (carte)** — monotone, gap-free, 1 séquence par encaissement, les
DEUX méthodes de paiement conformes NF525 §8. Chaîne audit inviolable +3 entrées, intègre.
