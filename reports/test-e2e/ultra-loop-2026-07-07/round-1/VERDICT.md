# Ultra-loop Round 1 — gérance DB + historique + intersections — 2026-07-07

Audit interne calibré V1 (verify-before-trust) : 9 lentilles code+DB + réfutation. HEAD b8c653324.

## Décompte : 45 findings → 8 REAL (1 INFO), 11 déjà-corrigés, 18 by-design, 6 faux, 2 V2

## Attestation positive (INFO)
Chaînes HMAC audit_logs (4917) + z_reports (24) INTÈGRES : `fiscal:verify-chain --all` CHAIN OK ×4 ; 0 gap séquence, 0 orphelin, 0 fork, 0 doublon hash. Scellement Z + fidélité + BranchScope confirmés solides au HEAD.

## 7 REAL actionnables → EN COURS de fix
| # | Sév | Finding | Fix |
|---|---|---|---|
| 1 | **P1** | PDF rapport VENTES tronqué 10 lignes → total CA sous-déclaré ~99,98% (38 522€→6,70€) | merge paginate=0 (miroir Excel guéri) |
| 2 | P2 | PDF rapport ARTICLES tronqué 10/45 items → total faux | merge paginate=0 |
| 3 | P2 | Immutabilité NF525 NON armée : 0 trigger BEFORE DELETE sur la base MySQL (provisioning par dump sans --triggers ; code migration CORRECT) → audit_logs/z_reports hard-deletable, SILENCIEUX | commande `fiscal:verify-immutability-triggers` + health-check |
| 4 | P3 | Prix variations/extras non validés (numérique/non-négatif) à l'édition item | règles numeric/min:0 FormRequest |
| 5 | P3 | Coupon hard-delete → coupon_id orphelin dans order_coupons (historique) | soft-delete / garde référentielle |
| 6 | P3 | Dead-letter outbox immortel (17 lignes) : PruneOutbox ne purge pas les contract-violation attempts<6 | élargir clause purge / marqueur terminal |
| 7 | P3 | /health/ready 503 faux positif : HealthController compte les poison terminaux comme worker-lag | exclure les terminaux du calcul de lag |

## Note prod critique (P2 #3)
Le CODE d'immutabilité est correct ; le risque est OPÉRATIONNEL : si la vraie box Le Cayenne est provisionnée par import de dump (au lieu de `php artisan migrate` sur base fraîche), les triggers BEFORE DELETE sont ABSENTS → violation NF525 possible. La nouvelle commande `fiscal:verify-immutability-triggers` doit être lancée sur la box prod ; si absents → re-run migration triggers.
