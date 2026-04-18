# Test Plan - FoodKing SaaS

Ce document liste la stratégie de QA et les surfaces de tests du projet FoodKing.

## 1. Surfaces à Tester en Priorité

### A. Authentification & Sécurité
- [~] Login Administrateur / Manager (Valide et Invalide).
- [x] Login Kiosk (`/api/auth/kiosk-login`). *(KioskAuthTest)*
- [ ] Isolation : Un caissier Kiosk ne doit pas accéder à `/api/admin/*`.

### B. Prise de Commande (Frontend & Kiosk)
- [~] Création via l'API Frontend (`FrontendOrderService`).
- [x] Falsification des Prix : Envoyer un prix corrompu dans le payload et s'assurer que la base enregistre la vraie valeur serveur. *(TableOrderSecurityTest valide l'admin, OrderFlowTest à écrire pour le frontend)*
- [x] Falsification des Coupons / Discounts : Pareil pour la réduction. *(CouponSecurityTest)*

### C. Flux KDS (Cuisine)
- [x] Un cuisinier ne doit voir QUE sa succursale (`branch_id`). *(KDSFlowTest - branch isolation)*
- [x] Protection des transitions illégitimes (`ValidStatusTransition`). Un plat non payé (`PENDING`) ne peut pas passer directement à `PREPARED`. *(KDSFlowTest - invalid transition)*

### D. Concurrence & Heures de Pointe
- [ ] Lancer un script de stress test de 50 commandes/seconde pour vérifier l'intégrité de l'attribution incrémentale du numéro de ticket (`queue_number`) via `lockForUpdate()`.

## 2. Lancement des Tests
```bash
# Lancer tous les tests locaux unitaires et fonctionnels
php artisan test
```

## 3. Validation par lots (post-stabilisation)

Quand le run monolithique `php artisan test` heurte la limite mémoire du runner, utiliser la validation par lots :

```bash
bash scripts/run_php_feature_batches.sh auth-security
bash scripts/run_php_feature_batches.sh kiosk-pos-sync
bash scripts/run_php_feature_batches.sh admin-seeders-reports
```

Lots définis :
- `auth-security` : auth, scopes, isolation, pricing/security de base
- `kiosk-pos-sync` : tunnel kiosk, POS, KDS, OSS, synchronisation inter-écrans
- `admin-seeders-reports` : CRUD admin lourd et seeders

Le profilage des lots peut être documenté avec :

```bash
bash scripts/profile_php_memory.sh
```

Rapport généré : `reports/execution/php_memory_profile_latest.md`
