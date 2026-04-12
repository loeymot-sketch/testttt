# Sprint 24 — Finalisation POS : Migrations + Build + Crudités Atomiques

**Architecte :** Claude  
**Date :** 2026-03-16  
**Statut :** IMPLEMENTED (K2 + K5) — K1/K3/K4 PENDING (sandbox restriction)

---

## Contexte

Suite au rapport Playwright / E2E verification (DOSSIER_AUDIT_E2E_CLAUDE.md.resolved), 3 points bloquants ont été identifiés pour la validation E2E complète du POS :

1. **Prix addons incorrects** — migration `2026_03_16_000001` non exécutée
2. **Crudités composées en DB** — migration atomique `2026_03_16_000002` non exécutée  
3. **Build Vue obsolète** — `PaymentComponent.vue` non recompilé

---

## Tâches

### K1 — Migrations (PENDING — requiert shell hors sandbox)

- [ ] Exécuter : `php artisan migrate --force`
- [ ] Valider : prix Frites=2.00€, Boisson=2.00€, Menu=3.00€ en DB

**Migrations concernées :**
- `2026_03_16_000001_update_menu_addon_prices_for_wizard.php`
- `2026_03_16_000002_update_crudites_to_atomic_sprint23.php`

### K2 — Fix migration 000002 (✅ COMPLETED)

- [x] Migration déjà corrigée — `$this->command->info()` n'est pas utilisé
- [x] La migration utilise des commentaires vides à la place des logs

### K3 — Re-seed conditionnel (PENDING — requiert shell hors sandbox)

- [ ] Vérifier si crudités atomiques existent via Tinker
- [ ] Si non : `php artisan db:seed --class=MenuSeeder`

**Commande de vérification :**
```bash
php artisan tinker --execute="echo \App\Models\ItemExtra::where('name', 'Salade')->count();"
```

### K4 — Build Vue (PENDING — requiert shell hors sandbox)

- [ ] Exécuter : `npm run dev`
- [ ] Valider : `public/js/app.js` mis à jour

**Alternative si échec :**
```bash
npm install && npm run dev
```

### K5 — Chaîne de rapports (✅ COMPLETED)

- [x] Créer `reports/planning/sprint_24_finalisation.md` (ce fichier)
- [x] Créer `reports/execution/sprint_24_execution.md`
- [x] Mettre à jour `reports/planning/latest.md`
- [x] Mettre à jour `reports/execution/latest.md`

---

## Tests de validation (Playwright / E2E verification)

Une fois K1/K3/K4 exécutés manuellement, valider :

- [ ] **AG-10** : Prix Menu=3.00€, Frites=2.00€, Boisson=2.00€ dans wizard
- [ ] **AG-02** : Garnitures atomiques (Salade, Tomate, Oignon) — pas de "Complet"
- [ ] **AG-11** : Paiement Cash → commande créée sans erreur 500
- [ ] **AG-13** : KDS reçoit la commande en temps réel (Pusher)

---

## Commandes manuelles requises (à exécuter hors sandbox)

```bash
# 1. Démarrer le serveur MySQL/MariaDB localement
# 2. Se placer dans le répertoire du projet
cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt

# 3. Exécuter les migrations
php artisan migrate --force

# 4. Vérifier les prix
php artisan tinker --execute="echo \App\Models\Item::where('name', 'Frites Seules')->value('price');"
# Attendu : 2.00

# 5. Vérifier les crudités atomiques
php artisan tinker --execute="echo \App\Models\ItemExtra::where('name', 'Salade')->count();"
# Attendu : > 0

# 6. Si crudités manquantes, re-seeder
php artisan db:seed --class=MenuSeeder

# 7. Recompiler les assets Vue
npm run dev

# 8. Vérifier le build
ls -la public/js/app.js
# Attendu : date de modification = aujourd'hui

# 9. Lancer les tests PHP
php artisan test
# Attendu : 0 failures
```

---

## Fichiers créés/modifiés par ce sprint

| Fichier | Action | Statut |
|---------|--------|--------|
| `database/migrations/2026_03_16_000002_update_crudites_to_atomic_sprint23.php` | Vérifié (pas de `$this->command`) | ✅ |
| `reports/planning/sprint_24_finalisation.md` | Créé | ✅ |
| `reports/execution/sprint_24_execution.md` | Créé | ✅ |
| `reports/planning/latest.md` | Mis à jour | ✅ |
| `reports/execution/latest.md` | Mis à jour | ✅ |

---

## Bloquants identifiés

- **Sandbox Cursor** : Les commandes `php artisan migrate`, `npm run dev`, et `php artisan tinker` nécessitent un accès shell non-sandboxé (permissions `all` requises).
- **Solution** : Le développeur humain doit exécuter les commandes K1/K3/K4 manuellement dans un terminal local.

---

## Prochaines étapes

1. **Humain** : Exécuter les commandes de la section "Commandes manuelles requises"
2. **Playwright / E2E verification** : Lancer les tests E2E AG-10, AG-02, AG-11, AG-13
3. **Claude** : Finaliser le rapport de revue si tous les tests passent

---

*Plan Sprint 24 — Généré par Kimi le 2026-03-16*
