# Rapport Food Cost — coût matière & marge par produit (Le Cayenne)

> Généré le 2026-08-02 05:52 par `php artisan raw-materials:food-cost`.
> 1 produits actifs — **0 complets**, **1 en attente prix d'achat**, **0 sans recette paramétrée**.
>
> **Coût matière** = Σ (quantité recette × prix d'achat moyen de la matière). Les produits
> marqués « ⏳ en attente prix d'achat (factures P3) » ont au moins une matière dont le prix d'achat n'est
> pas encore saisi (avg_cost NULL) — **c'est attendu** tant que les factures (P3) ne sont pas
> entrées. Leur marge n'est PAS calculée pour ne pas afficher un coût faux.

Légende statut : ✅ complet · ⏳ en attente prix d'achat (coût partiel) · — recette non paramétrée.

## Sandwichs

| Produit | Prix vente | Coût matière | Marge | Marge % | Statut |
|---|---|---|---|---|---|
| Cayenne | 10,00 € | ? (prix non saisi) | — | — | ⏳ en attente prix d'achat (factures P3) |

---
Les coûts « en attente » se rempliront automatiquement dès la saisie des prix d'achat
(factures P3). NF525 : ce rapport LIT les recettes et le prix de vente, n'écrit rien de fiscal.
