# FoodKing V2 : Vision SaaS Multi-Tenants

Ce document décrit la trajectoire d'évolution assumée de l'application FoodKing. Actuellement, le système fonctionne sur un modèle **"Mono-Marque / Multi-Succursales"** (une seule entreprise possède plusieurs *Branches*). La cible à court/moyen terme est de migrer vers une architecture **Véritablement SaaS Multi-Tenants** (où l'admin super-root gère plusieurs restaurants clients, chaque restaurant ayant ses propres branches).

---

## 1. État Actuel (Phase Pré-SaaS)

- **Structure Master :** Le code tourne autour du modèle `Branch`. Un utilisateur (Chef, Manager, Kiosk) appartient à un `branch_id`.
- **Limites d'Isolation :** L'isolation se fait via des *Global Scopes* ou des clauses `where('branch_id', ...)` dans les requêtes Eloquent. (Voir `BranchIsolationTest`).
- **Base de Données :** Une seule base de données centrale.
- **Rôles :** Admin (SuperAdmin de la marque), Manager (Gérant d'une branche), BranchUser (Cuisinier KDS), Kiosk (Borne matérielle).

## 2. Transition SaaS V2 (Les piliers du refactoring)

Pour devenir une plateforme SaaS B2B, le "Owner" actuel du Dashboard doit devenir un "Super-Admin Plateforme" et vendre des "Abonnements" à d'autres propriétaires de restaurants.

### A. Le modèle `Tenant` ou `Company`
Le pivot majeur consistera à introduire un modèle `Tenant` (ou `RestaurantBase`). L'arbre des entités deviendra :
`Tenant (La boîte)` ➔ possède ➔ `Branches (Les adresses physiques)` ➔ possèdent ➔ `Users / Kiosks`.

### B. Isolation Absolue (Database Level)
Pour un produit de caisse/banquier touchant à la facturation de clients distincts, l'approche *Single DB / Multi-tenants (par foreign key)* est la plus dangereuse en cas de bug de scope. 
**Recommandation V2 : Architecture Multi-Bases de données (Tenant-per-DB).**
- Base Centrale (SaaS Root) : Utilisateurs globaux, Facturation Stripe SaaS, Liste des Tenants, Résolution des sous-domaines (`tenantA.foodking.com`).
- Bases Locales (Tenants) : Une DB par restaurant client hébergeant son propre schéma FoodKing actuel intact. Le code actuel n'aurait presque pas à changer pour l'isolation.

### C. Refonte du Routing & Authentification
1. Le login devra injecter le contexte tenant (`subdomain` ou header API `X-Tenant-ID`).
2. Le Kiosk, au démarrage, téléchargera sa configuration réseau ciblant le backend de *son* propriétaire.

---

## 3. Directives Strictes pour l'instant (Phase Transitionnelle)

Tant que la V2 SaaS n'est pas actée techniquement sur une branche de refonte majeure :
- ⛔ **Ne PAS** tenter de rajouter un `tenant_id` sporadiquement sur certaines tables. L'architecture actuelle `product -> category -> branch` doit rester pure.
- ⛔ **Ne PAS** modifier l'authentification `Admin/LoginController` pour y inventer une gestion multi-restaurants non pensée en amont.
- ✅ **Sécuriser à 100% le modèle Branch** : C'est la priorité. Prouver que la branche A ne voit jamais la commande de la branche B est la pré-condition pour prouver qu'on saura isoler les Tenants demain. (Déjà validé par les tests de la Phase 10).
