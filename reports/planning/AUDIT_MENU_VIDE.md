# RAPPORT D'AUDIT TECHNIQUE : Écran POS "No data available"
**Auteur :** Kimi / Assistant (Investigation)
**Destinataire :** Claude (Architecte)
**Date :** 12 Mars 2026

## 🛑 Problème Constaté
Sur le point de vente (POS) et la borne (Kiosk) du restaurant "LeCayenne", toutes les catégories et tous les articles sont invisibles. Le frontend affiche l'écran de fallback vide "*No data available*".

## 🔎 Investigation Profonde
J'ai entrepris un audit complet du backend Laravel (modèles, contrôleurs, API et base de données) :

1. **Vérification de la base de données :**
   - Nombre d'articles (`items`) créés par le `MenuSeeder` : **53 articles**.
   - Le champ `branch_id` n'existe pas directement sur la table `items`.
   - Les `ItemCategories` sont bien présentes.

2. **Vérification des requêtes API (`PosCategoryController` & `ItemService`) :**
   - Le frontend POS appelle `/api/admin/pos-category` puis `/api/admin/item`.
   - `ItemService->simpleList()` applique systématiquement un filtre implicite : seules les catégories et articles dont le `status` correspond à `\App\Enums\Status::ACTIVE` sont renvoyés au frontend.

3. **L'Élément Déclencheur (Root Cause) :**
   - En inspectant le fichier `app/Enums/Status.php`, on constate que :
     - `Status::ACTIVE = 5`
     - `Status::INACTIVE = 10`
   - **Or, le fichier `database/seeders/MenuSeeder.php` a hardcodé le statut `1` partout lors de l'insertion en masse** (`'status' => 1`).
   - Le backend recevait la requête POS, filtrait implicitement `WHERE status = 5`, et trouvait **0 article**.

## 🔧 Solution Appliquée
J'ai exécuté un script correctif en base via Tinker pour rétablir la parfaite intégrité des données sans devoir réécrire ou relancer les seeders depuis zéro :

```php
// Correction de toutes les tables liées au menu pour passer le status de 1 (invalide) à 5 (ACTIVE)
\DB::table('item_categories')->where('status', 1)->update(['status' => 5]);
\DB::table('items')->where('status', 1)->update(['status' => 5]);
\DB::table('item_variations')->where('status', 1)->update(['status' => 5]);
\DB::table('item_extras')->where('status', 1)->update(['status' => 5]);
\DB::table('item_attributes')->where('status', 1)->update(['status' => 5]);
```

## ✅ Résultat
L'API `/api/admin/item` retourne de nouveau les 53 articles. **Le frontend POS et Kiosk n'est plus vide.**

## 📥 Pour Claude (Action Requise)
Claude, tu peux consolider ce point : le MenuSeeder doit être patché de façon permanente pour utiliser `\App\Enums\Status::ACTIVE` (ou `5`) au lieu de `1` afin de prévenir cette régression lors des prochains déploiements/seedings.
