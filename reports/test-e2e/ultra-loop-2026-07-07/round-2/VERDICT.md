# Ultra-loop Round 2 — vérification + sweep frais + visuel — 2026-07-07

## 8/8 fixes du Round 1 TIENNENT (adversaire code + visuel + 21 sentinelles PHP + 10 JS)
Transactions FR/€, badge « À encaisser », nom livreur, « Acceptée », PDF daté 83 lignes non tronqué, trigger-check, CRUD, coupon soft-delete, outbox prune, health 503, KDS baseURL. Gates : Vitest 2286/0, PHPUnit 3206/0, frozen 0 hors LOCK, CHAIN OK ×4.

## Round 2 a trouvé du NOUVEAU (bloc NON convergé) → EN COURS de heal
| # | Sév | Finding | Statut |
|---|---|---|---|
| P1 | P1 | `OnlineOrderController::pdf` = 3e jumeau PDF tronqué (raté R1) : total 58,20€ au lieu de 745 633€ | heal (merge paginate=0) |
| N1 | P2 | RÉGRESSION du fix R1 : PDF ventes SANS filtre date → paginate=0 sur ~2850 cmd → dompdf HTTP 500 | heal (garde cap→422) |
| N2 | P3 | Blade PDF fuit encore enums COUNTER_CASH + en-têtes EN | heal (label FR blade) |
| N3 | P2 | KDS sync : URL corrigée mais 401 persiste (requête sans header auth) — fix R1 incomplet | heal (header auth) |
| N4 | P3 | Glyphe avatar admin cassé (ORB cross-origin dev, pré-existant, pas régression) | env, backlog |

## Réfutés (calibrage V1 confirmé — déjà solide)
- discount × fiscal : REFUTED — LOCK_ZREPORT_F1 nette déjà la remise (assiette HT post-remise, prouvé order #5494).
- FormRequests monétaires validés, hard-delete sans orphelin fiscal, enums admin mappés.

## DÉCISION OWNER (P3 fiscal, non bloquant)
Frais de livraison enregistrés à 0% TVA (HT pur) dans le Z-report (32 commandes) : SI la livraison doit porter la TVA, sous-déclaration sur la portion livraison. CA total correct, chaîne intacte. C'est une décision fiscale/comptable owner — je ne tranche pas seul.
