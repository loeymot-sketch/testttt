# E2E INTERACTIF site/sync/gestion (logique) — 2026-07-11

## Vérifications runtime (navigateur réel) — chaque observation croisée avant conclusion
| Test logique | Constat | Verdict |
|---|---|---|
| **Dashboard agrégation** (CA jour vs commandes jour) | « CA Jour=0, Commandes Jour=3, Ticket moyen=0 » — croisé DB : 3 cmd créées aujourd'hui (5638/5657/5658) TOUTES impayées (pay=15). « Commandes »=créées, « CA »=payées → métriques différentes standard, pas de div-0 (0/3=0) | ✅ CORRECT (fausse alerte écartée) |
| **Web `priceFor`** (miroir pricing) | Cheese Burger 6€, 2 sauces=6€ (gratuites web), formules +2,50/+1,50/+1,00 | ✅ cohérent (sauces gratuites web = revert 1-sauce ; diverge caisse +0,50 = by-design owner) |
| **Répartition canal** dashboard | Web=0% Kiosk=100% POS=0% (aujourd'hui) = 3 cmd borne, somme 100% | ✅ cohérent |

## Note
Page `/admin/stock/rupture` redirige vers dashboard sous le compte staff (permission) — la logique
disponibilité/rupture est couverte par les agents sync + gestion-catalogue (services réels).

## 4 agents adversaires LOGIQUE en cours : web / synchro / gestion-catalogue-stock / gestion-reports-delivery.
