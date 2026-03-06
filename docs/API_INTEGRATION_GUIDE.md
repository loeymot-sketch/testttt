# 🔌 Guide d'Intégration d'Appareil Externe (API & Sanctum)

Ce guide est destiné aux ingénieurs Front-End ou développeurs TPE/Kiosques. Il explique comment communiquer de manière sécurisée et légitime avec Backend FoodKing V1.

---

## 1. Créer la borne dans l'éco-système

Avant qu'une tablette, un Totem de commande ou une caisse tierce puisse faire une requête vers `/api/frontend/order`, elle doit exister comme "Appareil Physique".

1. **En tant qu'Admin/Manager Dashboard :** Naviguer sur `[Domaine_Backend]/admin/kiosk-machine`.
2. Créer une borne "Borne Entrée A" pour la "Succursale Paris". L'assigner avec :
   - Un `Username` (ex: `borne1`)
   - Un `Mot de passe` PIN (ex: `123456`)
   - L'identifiant MAC ou Nom Unique (`machine_id`).

---

## 2. Le Cycle de vie : Login & Token Sanctum

### A. Handshake (S'authentifier)

Au démarrage de la tablette Flutter (ou autre client), une requête de connexion matérielle doit être émise. L'interface ne demande pas l'identité du client final à ce stade, elle authentifie **l'appareil**.

```http
POST /api/auth/kiosk-login
Content-Type: application/json
Accept: application/json

{
    "username": "borne1",
    "password": "login_secret_password"
}
```

### B. Le Retour (Bearer Token)

Si valide, le backend renvoie :
```json
{
    "data": {
        "access_token": "12|aZbYxXwvUuTsRqPoNm...",
        "token_type": "Bearer"
    }
}
```
**Important :** Ce token a une "Ability" limitante appelée `kiosk:order`. 

### C. Injection Permanente
La stack réseau de l'application cliente (Ex: GetX/Dio sur Flutter) DOIT conserver ce Token et l'injecter dans chaque entête subséquent :
`Authorization: Bearer 12|aZbY...`

---

## 3. Accès en Lecture (Catalogue) et Écriture (Créer le panier)

Une fois muni du token Kiosk, la seule zone autorisée est `/api/frontend/*`.

### /api/frontend/item (GET)
Va retourner les produits classés. Le backend filtre **automatiquement** pour ne retourner que les items liés à la `branch_id` à laquelle cette borne Kiosk appartient (isolation transparente, pas besoin de le passer dans l'URL Flutter).

### /api/frontend/order (POST)
C'est le nerf de la guerre. Envoyez le panier sélectionné par le client.

```json
{
    "order_type": 5,           // Takeaway
    "subtotal": 24.50,         // IGNORE par le back 🔒
    "total": 24.50,            // IGNORE par le back 🔒
    "items": [
        {
            "item_id": 14,
            "quantity": 2,
            "price": 12.25     // IGNORE par le back 🔒
        }
    ]
}
```

⚠️ **Le Kiosk DOIT continuer d'envoyer des prix pour afficher des UX fluides en attendant que le réseau réponde.** Mais en backend, c'est **Laravel** et la Database qui calculent l'addition réelle. Si un client bidouille le prix de son menu à `0.01 €` via un proxy Charles/MITM et paye ce montant au TPE avant de requêter l'API, la commande côté Web Admin ressortira soit comme Invalid Payment (car `0.01` est différent du montant DB), soit sera rejetée avant la création.

---

## 4. Ce que vous NE POUVEZ PAS FAIRE (Limites API Kiosk)

- Vous taper un Endpoint KDS (`/api/admin/pos-order`) ➔ **401 Unauthorized**.
- Usted demande un Dashboard Stats (`/api/admin/dashboard`) ➔ **403 Forbidden**.
- Vous essayez de payer la commande d'une autre branche ➔ Mureté du pare-feu `branch_id`.
