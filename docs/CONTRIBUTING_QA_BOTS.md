# 🤖 Guide de Contribution : Règles Strictes (QA Bots & Cursor)

Ce dépôt héberge le cœur (Core) métier de prise de commande, de tarification et de transactions du produit FoodKing. En tant que développeur, bot QA automatisé (24/7) ou IA d'assistance (Cursor), vous **DEVEZ** vous plier aux règles suivantes sous peine de corrompre le SI financier.

---

## 1. La Règle d'Or du Core Business

**Vos modifications ne doivent jamais contourner la sécurité logique du serveur.**
- Les prix envoyés par une API front (ex: l'app Kiosk Flutter) sont intrinsèquement considérés comme **compromis/falsifiables**. 
- Le Backend DOIT refaire le calcul mathématique en interrogeant la DB (`Item::find($request->item_id)->price`).
- Si vous touchez à un contrôleur de panier, de coupon ou de checkout, c'est **cette** logique que vous devez protéger ou étendre, jamais raccourcir pour aller plus vite.

## 2. Comportement des Agents IA (Cursor / Bots)

Si vous êtes une intelligence artificielle (LLM) qui audite ou code dans ce dépôt :

### A. Respectez les Zones Gelées
Lisez IMPÉRATIVEMENT `docs/CORE_MODULES.md`. Le projet contient du code "mort" ou "standby" (gateways de paiement obsolètes, subsystem delivery inactif). **Ne proposez pas de refactor global dessus.** Vous perdrez vos jetons et votre temps (et celui de l'équipe de review).

### B. "Red, Green, Refactor" (TDD Obligatoire)
Toute modification d'une surface métier exige que vous lanciez d'abord `php artisan test`.
1. Si un test casse à cause de vous : **STOP**. Ne committez pas. Annulez ou corrigez.
2. Si vous ajoutez une *Feature* (`Discount spécial`, `Heure de pointe`), **VOUS DEVEZ** d'abord écrire le test PHPUnit (Feature Test) qui traduit sa faille potentielle.

### C. Modifications Database (Migrations)
- Ne modifiez JAMAIS une migration existante déjà jouée. Créez une nouvelle migration (`php artisan make:migration add_xyz_to_table`).
- Conservez les Foreign Keys rigoureuses existantes dans le SQLite de test. (Le test fail si un `user_id` est manquant sur un Order, respectez cela).

### D. Commandes bloquantes en sandbox (Playwright / E2E verification, agents)
En environnement sandbox, **ne pas exécuter** :
- `cat .env` ou `grep APP_ENV .env` — accès restreint aux fichiers ignorés
- `php artisan db:seed --class=MenuSeeder` — connexion MySQL bloquée

**À la place :**
- Pour valider le MenuSeeder : `php artisan test --filter=MenuSeederTest` (SQLite in-memory)
- Pour la structure .env : `cat .env.example | grep APP_ENV`

Voir `reports/antigravity/AUDIT_BLOCAGE_COMMANDES_20260312.md` pour le détail.

## 3. Style & Conventions (Standard Laravel)

- **Controllers** : Fins (Gèrent la Request JSOn, la Response, l'Auth HTTP).
- **Services** : Épais. (La logique `FrontendOrderService` calcule le total, crée l'Order, boucle sur les Items).
- **Traits/Enums** : Utilisez-les. Ne vous fiez pas aux anciens entiers de doc erronés (ex. 14 pour PREPARED). Utilisez `OrderStatus::PREPARED` (valeur **8** dans `app/Enums/OrderStatus.php`).
- **Validation** : Strictement via `FormRequest` ou `$request->validate()`. Ne faites pas confiance aux payloads entrants de la borne Kiosk.

## conclusion (Bot Acknowledgment)

Si vous êtes un bot autonome chargé d'un ticket Jira ou issue GitHub, commencez votre première analyse/pensée (thought) par : *"J'ai lu et je respecte CONTRIBUTING_QA_BOTS.md. TDD activé."*
