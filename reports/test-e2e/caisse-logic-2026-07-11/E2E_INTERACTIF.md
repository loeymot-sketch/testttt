# E2E INTERACTIF CAISSE (logique) — 2026-07-11

## Flux testés en navigateur réel (POS /admin/pos)
| Test logique | Attendu | Obtenu | Verdict |
|---|---|---|---|
| Wizard sauce « 1ère gratuite, +0,50/sauce » | 2 sauces = base+0,50 | Cheese 6,00 → **6,50€** (badge « 2 sauces = +€0.50 ») | ✅ |
| Total ticket ligne composée | 6,50 = sous-total = total | Sous-total 6,50 / Total 6,50 | ✅ |
| Park (mettre en attente) | ticket vidé, compteur +1 | sous-total 0,00, En attente=1, libellé préservé | ✅ |
| Recall (restaurer) | compo+total intacts, compteur -1 | Cheese Burger + Salade/Tomate/Oignon + Mayonnaise/Ketchup + **6,50€**, En attente=0 | ✅ |

## Observations UX (mineur, non-bloquant)
- « Mettre en attente » utilise un `prompt()` natif (libellé) — bloque le thread, moche vs modal custom. P3 UX.
- 1 erreur console sur « Restaurer » / « Annuler la dernière ligne » (fonctionnalité OK) — à corréler avec les logs agents.

## Verdict e2e interactif : logique caisse cœur CORRECTE (sauce, total, park/recall).
Les sous-systèmes profonds (tiroir, paiement fractionné, remboursement, agrégation, fidélité) = 4 agents adversaires en cours.
