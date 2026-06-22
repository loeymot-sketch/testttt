# Delivery Fee Policy — 2026-04-27

FoodKing V1 applique une règle unique : 5 EUR par tranche commencée de 5 km, avec minimum 5 EUR pour une livraison valide.

- Le frontend peut afficher une prévisualisation, mais le serveur reste l'autorité.
- Une livraison web est recalculée côté serveur depuis l'adresse enregistrée et la branche sélectionnée.
- Une adresse sans latitude/longitude valide est refusée avec `GEOCODE_FAILED`.
- Aucun fallback silencieux à 5 EUR n'est autorisé quand Google Maps ou les coordonnées échouent.
- Le POS doit obtenir des coordonnées Google avant de créer l'adresse de livraison.
