# AGENT 08 — SYSTÈME ADMIN / DASHBOARD (vue OPÉRATEUR/GÉRANT)
> Ton : gérant qui exige que CHAQUE chiffre soit vrai et CHAQUE CRUD marche.

## Scope / Anchors (vérifiés)
- `Admin/*Controller.php` (CRUD : items, catégories, customers, administrators, permissions, ingredients, taxes)
- Surfaces : `/admin/dashboard`, `/admin/historique`, `/admin/items` (catalogue+studio), `/admin/stock/rupture`, `/admin/customers`, `/admin/encaissement`, rapports
- `OrderDetailsResource`, dashboard KPI

## Checklist abusif (6 axes)
- **Dashboard** : KPI = vérité DB (Total ventes, commandes, articles 45, Suivi en direct du jour). Accès rapides → chaque tuile route correctement.
- **Historique** (AVEC agent 02) : filtres (date, origine, statut), pagination, "voir" → détail commande, N° fiscal présent, 0 trou.
- **Catalogue/Studio** : lister 45 items/11 cats ; **CRUD réel** : créer item, éditer (prix/taux/dispo), créer catégorie, supprimer — vérifier persistance DB + impact caisse/borne.
- **Stock/rupture** : toggle EN STOCK/rupture → (avec agent 01) propagation temps réel caisse/borne/wizard.
- **Customers/Loyalty** : lister, créer client, éditer, **consulter points loyalty**, filtres, export.
- **CRUD users/permissions** : créer staff, assigner rôle, vérifier RBAC (avec agent 10).
- **B1 CHAQUE bouton** de chaque écran admin : filtrer, exporter, ajouter, éditer, supprimer, voir, PDF clôture.
- **C** (agent 03) capture chaque écran + effet CRUD ; vue gérant claire.
- **6 items tax_id NULL** : reproduire dans l'UI catalogue, remonter (avec agent 02).
- **10 opérations** CRUD variées → toutes persistées correctement.

## Méthode
E2E :8766 loginAsAdmin. CRUD sur le clone (jetable). Vérifier persistance via DB + impact cross-surface.

## PASS bar
Dashboard chiffres vrais + CRUD complet persisté + stock-sync + loyalty + chaque bouton + capture. Sinon ❌.

## Sortie `reports/test-e2e/goal-100pct-2026-06-07/<round>/08-admin.json`
