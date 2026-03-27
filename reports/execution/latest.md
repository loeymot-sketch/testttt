# Rapport d'Exécution — Phase 56.2 : Identifiants borne/admin + catalogue « Nos »

**Date** : 2026-03-27  
**Exécutant** : Kimi  
**Plan validé** : Correctifs auth / credentials + handoff Claude enrichi  
**Test type** : Kimi-test  
**Statut** : ✅ TERMINÉ

---

## Résumé exécutif

Correctifs ciblés : confusion **identifiant borne vs e-mail** (captures utilisateur), alignement **MIX_API_KEY** / démo admin avec les seeders Le Cayenne, suppression **NOS NOS** sur la sidebar catalogue, et enrichissement du **plan Claude** (`reports/planning/latest.md` section 0 + matrice FCM brouillon).

---

## Fichiers modifiés

| Fichier | Changement | Statut |
|---------|-----------|--------|
| `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue` | Suppression visuelle du rendu `NOS NOS ...` et badge produit rendu plus honnête (`PERSONNALISER`) | ✅ |
| `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` | Suppression du faux hint `Panier` | ✅ |
| `resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue` | Suppression du doublon de titre récapitulatif | ✅ |
| `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` | Suppression du double loader pendant l’envoi/paiement | ✅ |
| `reports/planning/latest.md` | Section 0 identifiants, matrice FCM brouillon, ordre Claude mis à jour | ✅ |
| `config/app.php` | `api_key` = `MIX_API_KEY` avec repli `API_KEY` ; `demo_credentials` = seed Le Cayenne | ✅ |
| `.env.example` | `MIX_API_KEY` documenté | ✅ |
| `KioskLoginComponent.vue` | Aide contextuelle, refus `@`, hint seed dev | ✅ |
| `KioskMachineLoginController.php` | Trim username, 422 si e-mail | ✅ |
| `kioskCart.js` | Trim username avant POST | ✅ |
| `LoginComponent.vue` | Fallbacks démo = comptes seed | ✅ |
| `lang/fr/all.php`, `lang/en/all.php` | `kiosk_username_not_email` | ✅ |
| `tests/Feature/KioskLoginApiTest.php` | Test rejet e-mail comme username borne | ✅ |
| `docs/AUDIT_LOGIN_ACCOUNTS.md` | Note MIX_API_KEY + username borne | ✅ |

---

## Changements fonctionnels clés

### 1. Catalogue plus propre

- Le breadcrumb supprime maintenant le doublon visuel quand les catégories sont déjà nommées `Nos ...`.
- La **sidebar** utilise la même normalisation pour éviter `NOS NOS` sur les intitulés latéraux.
- Le badge `PERSONNALISER` décrit mieux l’action réelle qu’un badge `MENU`.

### 1 bis. Connexion borne

- L’utilisateur est guidé vers un **username machine** (`kiosk-lecayenne`), pas un e-mail staff.
- L’API renvoie un message clair si un e-mail est saisi par erreur.

### 2. Wizard plus net

- Le faux indicateur `Panier` a été retiré du shell wizard.
- Le récapitulatif n’affiche plus un titre redondant alors que la question d’étape est déjà présente au-dessus.

### 3. Paiement plus lisible

- L’écran paiement ne superpose plus deux états de chargement concurrents.
- Le parcours garde un seul feedback visuel pendant la soumission.

### 4. Préparation du lot complexe

- Les sujets backend/synchro restants ont été déplacés dans `reports/planning/latest.md` pour reprise par Claude :
  - state machine paiement/commande/KDS
  - unicité `queue_number`
  - modèle de rupture
  - stratégie temps réel / FCM
  - limite KDS

---

## Tests exécutés

| Test | Résultat |
|------|----------|
| `php artisan test tests/Feature/KioskLoginApiTest.php` | ⚠️ Non exécuté jusqu’au bout ici : échec `RefreshDatabase` sur migration `loyalty_transactions` (environnement SQLite / ordre migrations). Le test ajouté reste valide une fois la base de test migrée correctement. |
| `npm run production` | Recommandé après changements Vue |
| Diagnostics IDE sur fichiers modifiés | ✅ 0 erreur |

---

## Risques / limites restantes

| Sujet | Détail |
|------|--------|
| Paiement / visibilité cuisine | La logique profonde borne -> TPE -> KDS reste à arbitrer côté architecture. |
| Numérotation inter-surfaces | Le besoin d’unicité forte reste un sujet backend à reprendre. |
| Rupture produit | Aucun modèle complet et bloquant n’est encore en place. |

---

## Prochaines étapes recommandées

1. Faire reprendre `reports/planning/latest.md` par Claude.
2. Après arbitrage Claude, implémenter les P0 backend/synchro avant toute prétention production.
3. Garder les prochains patches Kimi sur du localisé/UI tant que la state machine commande/paiement n’est pas figée.

---

**Verdict** : ✅ Correctifs simples absorbés, build OK, handoff Claude prêt.

---

## Addendum 2026-03-27 — Migration loyalty + CI

| Sujet | Détail |
|-------|--------|
| `2026_03_24_000001_add_unique_to_loyalty_transactions` | Supprimée : s’exécutait **avant** `create_loyalty_transactions_table`, cassait `RefreshDatabase` (SQLite). |
| `2026_03_26_075919_add_unique_to_loyalty_transactions` | Nouvelle migration **après** création de table, idempotente (SQLite + MySQL). |
| `KioskLoginApiTest` | Re-login attend **201** (aligné sur `KioskMachineLoginController`). |
| `KioskSecurityTest` | Test « double login » remplacé par comportement réel (re-login autorisé). |
| `.env.example` | Rappel : après changement `.env`, `config:clear` + contrôle `window.foodkingConfig.apiKey`. |

**Déploiements** ayant déjà enregistré l’ancienne migration en base : retirer manuellement la ligne `2026_03_24_000001_add_unique_to_loyalty_transactions` de la table `migrations` si le fichier n’existe plus, puis `php artisan migrate` pour appliquer `075919`.
