# Test Plan - FoodKing SaaS

Ce document liste la stratégie de QA et les surfaces de tests du projet FoodKing.

## 1. Surfaces à Tester en Priorité

### A. Authentification & Sécurité
- [ ] Login Administrateur / Manager (Valide et Invalide).
- [ ] Login Kiosk (`/api/v1/auth/kiosk-login`).
- [ ] Isolation : Un caissier Kiosk ne doit pas accéder à `/api/v1/admin/*`.

### B. Prise de Commande (Frontend & Kiosk)
- [ ] Création (POST) via l'API Frontend (`FrontendOrderService`).
- [ ] Falsification des Prix : Envoyer un prix corrompu dans le payload réseau et s'assurer que la base de données enregistre la vraie valeur serveur.
- [ ] Falsification des Coupons / Discounts : Pareil pour la réduction.

### C. Flux KDS (Cuisine)
- [ ] Un cuisinier ne doit voir QUE sa succursale (`branch_id`).
- [ ] Protection des transitions illégitimes (`ValidStatusTransition`). Un plat non payé (`PENDING`) ne peut pas passer directement à `PREPARED`.

### D. Concurrence & Heures de Pointe
- [ ] Lancer un script de stress test de 50 commandes/seconde pour vérifier l'intégrité de l'attribution incrémentale du numéro de ticket (`queue_number`) via `lockForUpdate()`.

## 2. Lancement des Tests
```bash
# Lancer tous les tests locaux unitaires et fonctionnels
php artisan test
```
