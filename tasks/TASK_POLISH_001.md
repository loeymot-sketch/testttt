# TASK_POLISH_001 — Polish & Qualité Code

## Meta
- **Priority**: P2 (MINOR)
- **PRIMARY_MODEL**: claude-sonnet-4-5-20250514
- **TEST_STRATEGY**: local-validation
- **DEPENDS_ON**: (none — peut s'exécuter en parallèle avec P1)
- **BLOCKS**: (none)

## Constats couverts
| ID | Severity | Titre |
|----|----------|-------|
| F-10 | MAJOR | Race condition fidélité non loggée complètement |
| F-12 | MINOR | wizard_template NULL dans certains produits |
| F-14 | MINOR | Syntaxe :onclick dépréciée |
| F-15 | MINOR | Reçu : key utilise l'index |
| F-18 | MINOR | Dashboard sans error boundaries |

## Contexte

Cette tâche regroupe les corrections de qualité code et les améliorations mineures qui n'impactent pas directement la sécurité ou la fonctionnalité, mais améliorent la maintenabilité et la robustesse. F-10 est classé MAJOR car il affecte la capacité de diagnostic en production.

## Scope

### SUBSYSTEMS_TOUCHED
- `app/Services/LoyaltyService.php` — logging amélioré (F-10)
- `database/migrations/` — backfill wizard_template NULL (F-12)
- `resources/js/components/admin/pos/*.vue` — :onclick → @click (F-14)
- `resources/js/components/admin/pos/ReceiptComponent.vue` — :key fix (F-15)
- `resources/js/components/admin/DashboardComponent.vue` — error boundary (F-18)

### Hors scope
- Logique métier fidélité (barème, calcul)
- Refactoring majeur des composants POS
- Modification des templates wizard existants

## Étapes d'exécution

### E1 — Logging fidélité complet (F-10)
1. Dans `LoyaltyService`, après chaque `lockForUpdate` :
   - Si conflit détecté → `Log::warning('loyalty_lock_conflict', [context])`
   - Logger : user_id, order_id, points_requested, points_available, timestamp
2. Ajouter un compteur de retries visible dans les logs

### E2 — Backfill wizard_template (F-12)
1. Créer une migration :
   ```php
   DB::table('products')->whereNull('wizard_template')->update(['wizard_template' => 'simple']);
   ```
2. Ajouter `->default('simple')` dans la migration schema si pas déjà présent
3. Frontend : ajouter un fallback `product.wizard_template || 'simple'` dans le wizard loader

### E3 — Fix :onclick (F-14)
1. Rechercher tous les `:onclick` dans les composants Vue
2. Remplacer par `@click` (syntaxe Vue standard)
3. Vérifier que le comportement est identique (pas de .prevent, .stop manquant)

### E4 — Fix :key index (F-15)
1. Dans `ReceiptComponent.vue`, remplacer `:key="index"` par `:key="item.id"` ou `:key="item.uuid"`
2. Si pas d'ID unique disponible, utiliser une combinaison : `:key="item.product_id + '-' + index"`

### E5 — Error boundaries dashboard (F-18)
1. Créer un composant `ErrorBoundary.vue` :
   ```vue
   <script>
   export default {
     errorCaptured(err, vm, info) {
       this.error = { message: err.message, info };
       return false; // prevent propagation
     }
   }
   </script>
   ```
2. Envelopper chaque widget dashboard dans `<ErrorBoundary>`
3. Affichage fallback : "Ce widget a rencontré une erreur" avec bouton "Réessayer"

## Validation attendue

- [ ] `php artisan test` — 0 failures
- [ ] `npm run build` — 0 errors, 0 warnings Vue
- [ ] `grep -r ':onclick' resources/js/` → 0 résultats
- [ ] Aucun produit avec wizard_template NULL en base
- [ ] Dashboard : erreur simulée dans un widget → seul ce widget affiche l'erreur

## Invariants
- La migration wizard_template est additive (DEFAULT, pas de suppression)
- Les corrections :onclick ne changent pas le comportement (syntaxe only)
- L'error boundary ne cache pas les erreurs (elles sont loggées)

## Gate
- **Gate requise** : NON
- Migration DB simple (backfill) : pas de gate nécessaire
