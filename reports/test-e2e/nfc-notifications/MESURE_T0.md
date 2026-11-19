# T-0.2 — Mesure du suivi et des points sur le site EN LIGNE (3eb0ae2), 2026-11-19

Instrument : miroir du site (worktree `site-nfc-2026-11-19`, ligne `app/catalogue-distant-2026-11-05`) sur :8014, rebranché sur le
backend local :8766 ; vrai client, vraie commande à emporter (item 58 ×12 = 12 €), vrais changements de statut par la caisse
(4 → 7 → 8), Chromium iPhone 13, service worker bloqué. Script : scratchpad `miroir/mesure-t0.cjs`.

| Constat | Valeur mesurée |
|---|---|
| Crédit serveur après validation (commande PAYÉE) | 120 points en 0,3 s |
| Page « Mes commandes » ouverte (visible) | statut PRÊTE + « 120 points gagnés » vus en ~9 s (relecture 15 s) |
| Page « Fidélité » ouverte (visible) | solde 120 vu en ~13 s (relecture 20 s) |
| Commande « payer sur place » (non payée) | **aucun point** tant qu'elle n'est pas encaissée ou remise (`AwardLoyaltyPointsOnDelivery.php` ~l.51-62) |
| File d'attente locale sans worker | **aucun crédit** (écouteur en file) — piège de banc, pas défaut produit |

Conclusion : sur un écran visible, le site SE MET À JOUR seul (9-13 s). Le « statique, je dois rafraîchir » n'est PAS reproduit
en navigateur de bureau. Causes plausibles restantes, à corriger sans pouvoir les prouver sur téléphone (G4) :
1. relecture seulement sur `visibilitychange` : sur un raccourci iPhone suspendu, cet événement n'est pas garanti au réveil ;
2. panier/paiement/confirmation/compte lisent le solde UNE fois (pas de relecture) ;
3. commandes « payer sur place » : points absents jusqu'à l'encaissement, et l'historique ne se relit plus après « remise ».
Les trois sont couverts par S3 (solde unique + relectures `pageshow`/`focus`/détection de gel + événement `lc:push`).
Pièges de banc notés : restaurant fermé hors 18:00-00:30 (horaires locaux élargis le temps du test, originaux sauvegardés),
Playwright d'`nnnature-deploy` sans navigateur installé → utiliser celui de `testttt/node_modules`, `Cache::flush()` local lancé par erreur.
