# Documentation API - Borne Kiosk FoodKing

> **Version:** 1.0  
> **Date:** 11 Mars 2026  
> **Public:** Développeurs application Kiosk (Flutter)

---

## Table des Matières

1. [Vue d'Ensemble](#1-vue-densemble)
2. [Authentification](#2-authentification)
3. [Création de Commande](#3-création-de-commande)
4. [Récupération du Menu](#4-récupération-du-menu)
5. [Codes d'Erreur](#5-codes-derreur)
6. [Limites et Rate Limiting](#6-limites-et-rate-limiting)

---

## 1. Vue d'Ensemble

### 1.1 Base URL

```
Production:  https://[votre-domaine].com/api
Développement: http://localhost:8000/api
```

### 1.2 Headers Requis (Toutes Requêtes)

```http
Accept: application/json
Content-Type: application/json
X-API-KEY: [votre_api_key]
Accept-Language: fr|en
```

### 1.3 Authentification Kiosk

Le Kiosk utilise **Laravel Sanctum** avec des tokens à capacités limitées:

- **Token Standard**: Accès complet admin
- **Token Kiosk**: Limité à `kiosk:order` uniquement

```json
{
  "token": "1|laravel_sanctum_token_xxx",
  "abilities": ["kiosk:order"],
  "expires_at": null
}
```

---

## 2. Authentification

### 2.1 Login Kiosk

Authentifie une borne Kiosk et retourne un token limité.

**Endpoint:** `POST /auth/kiosk-login`

**Auth requise:** Non

**Headers:**
```http
X-API-KEY: [api_key]
Content-Type: application/json
```

**Body:**
```json
{
  "username": "kiosk_mac_a1",
  "password": "mot_de_passe_borne",
  "machine_id": "MAC-A1-001"
}
```

**Réponse Succès (200):**
```json
{
  "status": true,
  "data": {
    "token": "2|laravelsanctumtokenkioskxxx",
    "abilities": ["kiosk:order"],
    "kiosk_machine": {
      "id": 1,
      "machine_id": "MAC-A1-001",
      "branch_id": 1,
      "branch_name": "Restaurant Paris Centre"
    }
  }
}
```

**Réponse Erreur (401):**
```json
{
  "status": false,
  "errors": {
    "message": "Invalid credentials"
  }
}
```

**Réponse Erreur (403):**
```json
{
  "status": false,
  "errors": {
    "message": "Kiosk machine inactive"
  }
}
```

**Notes:**
- Le token est permanent (pas d'expiration par défaut)
- Une seule session active par borne
- Le `machine_id` doit correspondre à l'enregistrement en base

### 2.2 Logout Kiosk

Révoque le token actif de la borne.

**Endpoint:** `POST /auth/kiosk-logout`

**Auth requise:** Oui (Bearer Token)

**Headers:**
```http
Authorization: Bearer [token_kiosk]
X-API-KEY: [api_key]
```

**Réponse Succès (200):**
```json
{
  "status": true,
  "message": "Successfully logged out"
}
```

### 2.3 Exemple d'Implémentation (Flutter/Dart)

```dart
class KioskAuthService {
  static const String baseUrl = 'https://votre-domaine.com/api';
  static const String apiKey = 'votre_api_key_securise';

  Future<AuthResponse> login(String username, String password, String machineId) async {
    final response = await http.post(
      Uri.parse('$baseUrl/auth/kiosk-login'),
      headers: {
        'Content-Type': 'application/json',
        'X-API-KEY': apiKey,
      },
      body: jsonEncode({
        'username': username,
        'password': password,
        'machine_id': machineId,
      }),
    );

    if (response.statusCode == 200) {
      final data = jsonDecode(response.body);
      return AuthResponse.fromJson(data['data']);
    } else {
      throw Exception('Login failed: ${response.body}');
    }
  }

  Future<void> logout(String token) async {
    await http.post(
      Uri.parse('$baseUrl/auth/kiosk-logout'),
      headers: {
        'Authorization': 'Bearer $token',
        'X-API-KEY': apiKey,
      },
    );
  }
}
```

---

## 3. Création de Commande

### 3.1 Endpoint

**Endpoint:** `POST /frontend/order`

**Auth requise:** Oui (Bearer Token Kiosk)

**Headers:**
```http
Authorization: Bearer [token_kiosk]
X-API-KEY: [api_key]
Content-Type: application/json
```

### 3.2 Request Body

```json
{
  "branch_id": 1,
  "order_type": 5,
  "subtotal": "25.00",
  "delivery_charge": "0.00",
  "discount": "0.00",
  "tax": "2.50",
  "total": "27.50",
  "items": [
    {
      "item_id": 15,
      "quantity": 2,
      "item_variations": [
        {"name": "Viande 1", "value": "Poulet"},
        {"name": "Viande 2", "value": "Kebab"},
        {"name": "Sauce", "value": "Algérienne"}
      ],
      "item_extras": [
        {"name": "Menu (Frites+Boisson)", "price": "3.00"},
        {"name": "Supplément Cheddar", "price": "1.00"}
      ],
      "instruction": "Sans oignon svp"
    }
  ],
  "coupon_id": null,
  "payment_method": 1,
  "payment_status": 5,
  "device_token": "firebase_token_pour_notifications"
}
```

### 3.3 Description des Champs

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `branch_id` | integer | Oui | ID de la succursale |
| `order_type` | tinyint | Oui | 5=À emporter, 10=Sur place |
| `subtotal` | string | Oui | Sous-total (ignoré serveur) |
| `delivery_charge` | string | Non | Frais livraison (si applicable) |
| `discount` | string | Non | Remise (ignoré serveur) |
| `tax` | string | Non | Taxes (ignoré serveur) |
| `total` | string | Oui | Total (ignoré serveur) |
| `items` | array | Oui | Liste des articles |
| `items[].item_id` | integer | Oui | ID de l'article |
| `items[].quantity` | integer | Oui | Quantité |
| `items[].item_variations` | array | Non | Variations (viandes, sauces...) |
| `items[].item_extras` | array | Non | Suppléments |
| `items[].instruction` | string | Non | Instructions spéciales |
| `coupon_id` | integer | Non | ID coupon (si utilisé) |
| `payment_method` | tinyint | Oui | 1=Espèces, 2=CB, 3=En ligne |
| `payment_status` | tinyint | Oui | 5=Non payé, 10=Payé |
| `device_token` | string | Non | Token Firebase pour notifs |

**⚠️ IMPORTANT:** Les champs `subtotal`, `discount`, `tax`, `total` sont **recalculés serveur**. Le Kiosk peut envoyer des valeurs mais elles seront ignorées au profit du calcul serveur basé sur les prix en base de données.

### 3.4 Réponse Succès (201)

```json
{
  "status": true,
  "data": {
    "id": 1234,
    "order_serial_no": "10045",
    "queue_number": 45,
    "branch_id": 1,
    "user_id": null,
    "kiosk_machine_id": 1,
    "order_type": 5,
    "subtotal": "25.00",
    "delivery_charge": "0.00",
    "discount": "0.00",
    "tax": "2.50",
    "total": "27.50",
    "payment_method": 1,
    "payment_status": 5,
    "status": 5,
    "status_name": "PENDING",
    "items": [
      {
        "id": 567,
        "item_id": 15,
        "item_name": "Tacos L (2 Viandes)",
        "quantity": 2,
        "price": "12.50",
        "item_variations": [
          {"name": "Viande 1", "value": "Poulet"},
          {"name": "Viande 2", "value": "Kebab"}
        ],
        "item_extras": [
          {"name": "Menu (Frites+Boisson)", "price": "3.00"}
        ],
        "instruction": "Sans oignon svp"
      }
    ],
    "created_at": "2026-03-11T14:32:00.000000Z",
    "updated_at": "2026-03-11T14:32:00.000000Z"
  }
}
```

### 3.5 Réponse Erreur (422)

```json
{
  "status": false,
  "errors": {
    "branch_id": ["Le champ branch_id est requis."],
    "items": ["Au moins un item est requis."],
    "items.0.item_id": ["L'item sélectionné n'existe pas."]
  }
}
```

### 3.6 Exemple d'Implémentation (Flutter/Dart)

```dart
class OrderService {
  static const String baseUrl = 'https://votre-domaine.com/api';
  static const String apiKey = 'votre_api_key';

  Future<Order> createOrder(String token, OrderRequest request) async {
    final response = await http.post(
      Uri.parse('$baseUrl/frontend/order'),
      headers: {
        'Content-Type': 'application/json',
        'Authorization': 'Bearer $token',
        'X-API-KEY': apiKey,
      },
      body: jsonEncode(request.toJson()),
    );

    if (response.statusCode == 201) {
      final data = jsonDecode(response.body);
      return Order.fromJson(data['data']);
    } else if (response.statusCode == 422) {
      final errors = jsonDecode(response.body)['errors'];
      throw ValidationException(errors);
    } else {
      throw Exception('Order creation failed: ${response.body}');
    }
  }
}

// Modèles
class OrderRequest {
  final int branchId;
  final int orderType;
  final List<OrderItemRequest> items;
  final int paymentMethod;
  final int paymentStatus;
  final String? deviceToken;

  Map<String, dynamic> toJson() => {
    'branch_id': branchId,
    'order_type': orderType,
    'subtotal': '0.00',  // Sera recalculé serveur
    'total': '0.00',     // Sera recalculé serveur
    'items': items.map((i) => i.toJson()).toList(),
    'payment_method': paymentMethod,
    'payment_status': paymentStatus,
    'device_token': deviceToken,
  };
}

class OrderItemRequest {
  final int itemId;
  final int quantity;
  final List<Map<String, String>>? variations;
  final List<Map<String, dynamic>>? extras;
  final String? instruction;

  Map<String, dynamic> toJson() => {
    'item_id': itemId,
    'quantity': quantity,
    'item_variations': variations,
    'item_extras': extras,
    'instruction': instruction,
  };
}
```

---

## 4. Récupération du Menu

### 4.1 Liste des Catégories

**Endpoint:** `GET /frontend/item-category`

**Auth requise:** Non (api-key uniquement)

**Headers:**
```http
X-API-KEY: [api_key]
```

**Réponse (200):**
```json
{
  "status": true,
  "data": {
    "categories": [
      {
        "id": 1,
        "name": "Nos Tacos",
        "slug": "nos-tacos",
        "sort_order": 1
      },
      {
        "id": 2,
        "name": "Nos Burgers",
        "slug": "nos-burgers",
        "sort_order": 2
      }
    ]
  }
}
```

### 4.2 Liste des Articles

**Endpoint:** `GET /frontend/item`

**Auth requise:** Non (api-key uniquement)

**Query Parameters:**
- `category_id` (optional): Filtrer par catégorie

**Headers:**
```http
X-API-KEY: [api_key]
```

**Réponse (200):**
```json
{
  "status": true,
  "data": {
    "items": [
      {
        "id": 15,
        "name": "Tacos L (2 Viandes)",
        "slug": "tacos-l-2-viandes",
        "price": "8.50",
        "description": "Pain tacos, 2 viandes au choix",
        "item_category_id": 1,
        "tax_id": 1,
        "image": "https://.../tacos-l.jpg",
        "status": 5,
        "itemAttributes": [
          {
            "id": 1,
            "name": "Viandes",
            "values": ["Poulet", "Kebab", "Merguez", "Steak"]
          }
        ],
        "variations": [
          {
            "id": 23,
            "item_attribute_id": 1,
            "name": "Poulet",
            "price": "0.00"
          }
        ]
      }
    ]
  }
}
```

### 4.3 Détails d'un Article

**Endpoint:** `GET /frontend/item/details/{item_id}`

**Auth requise:** Non (api-key uniquement)

**Réponse (200):**
```json
{
  "status": true,
  "data": {
    "id": 15,
    "name": "Tacos L (2 Viandes)",
    "price": "8.50",
    "description": "...",
    "itemAttributes": [...],
    "variations": [...],
    "extras": [
      {
        "id": 1,
        "name": "Supplément Cheddar",
        "price": "1.00"
      }
    ],
    "addons": [...]
  }
}
```

### 4.4 Configuration du Restaurant

**Endpoint:** `GET /frontend/setting`

**Auth requise:** Non (api-key uniquement)

**Réponse (200):**
```json
{
  "status": true,
  "data": {
    "currency_code": "EUR",
    "currency_symbol": "€",
    "currency_position": "right",
    "decimal_separator": ",",
    "thousand_separator": " ",
    "site_name": "Le Grill House",
    "order_setup": {
      "order_status_wait_time": 15
    }
  }
}
```

---

## 5. Codes d'Erreur

### 5.1 Codes HTTP Standards

| Code | Signification | Action Requise |
|------|---------------|----------------|
| **200** | OK | Succès |
| **201** | Created | Commande créée avec succès |
| **400** | Bad Request | Requête malformée |
| **401** | Unauthorized | Token invalide ou expiré |
| **403** | Forbidden | Accès interdit (token sans permission) |
| **404** | Not Found | Ressource non trouvée |
| **422** | Unprocessable | Validation échouée (voir erreurs) |
| **429** | Too Many Requests | Rate limit atteint, attendre |
| **500** | Server Error | Erreur serveur, réessayer plus tard |
| **503** | Service Unavailable | Maintenance en cours |

### 5.2 Erreurs de Validation (422)

```json
{
  "status": false,
  "errors": {
    "field_name": ["Message d'erreur détaillé"]
  }
}
```

**Champs courants en erreur:**

| Champ | Erreur Possible | Solution |
|-------|---------------|----------|
| `branch_id` | "existe pas", "requis" | Vérifier ID succursale |
| `items` | "requis", "vide" | Ajouter au moins un item |
| `items.*.item_id` | "existe pas" | Vérifier ID article |
| `items.*.quantity` | "min 1" | Quantité minimum 1 |
| `payment_method` | "invalide" | 1, 2, ou 3 uniquement |

### 5.3 Erreurs d'Authentification (401/403)

```json
{
  "status": false,
  "errors": "unauthenticated"
}
```

**Causes possibles:**
- Token manquant dans le header `Authorization`
- Token révoqué (logout effectué)
- Token Kiosk essaye d'accéder à une route admin

**Solution:**
1. Vérifier le header: `Authorization: Bearer [token]`
2. Si échec, refaire un login kiosk

### 5.4 Gestion des Erreurs (Flutter)

```dart
class ApiException implements Exception {
  final int statusCode;
  final String message;
  final Map<String, dynamic>? errors;

  ApiException(this.statusCode, this.message, {this.errors});

  @override
  String toString() => 'ApiException($statusCode): $message';
}

Future<T> handleRequest<T>(Future<T> Function() request) async {
  try {
    return await request();
  } on http.ClientException catch (e) {
    throw ApiException(0, 'Network error: ${e.message}');
  } catch (e) {
    rethrow;
  }
}
```

---

## 6. Limites et Rate Limiting

### 6.1 Rate Limits

| Endpoint | Limite | Fenêtre |
|----------|--------|---------|
| `POST /auth/kiosk-login` | 5 requêtes | 1 minute |
| `POST /frontend/order` | 10 requêtes | 1 minute |
| `GET /frontend/item` | 60 requêtes | 1 minute |
| Autres `GET` | 100 requêtes | 1 minute |

### 6.2 Réponse Rate Limit (429)

```json
{
  "status": false,
  "errors": {
    "message": "Too many requests",
    "retry_after": 45
  }
}
```

**Headers:**
```http
X-RateLimit-Limit: 10
X-RateLimit-Remaining: 0
Retry-After: 45
```

### 6.3 Bonnes Pratiques

1. **Mise en cache**: Cacher le menu localement (validité: 5 minutes)
2. **Backoff exponentiel**: En cas d'erreur 429, attendre `retry_after` secondes
3. **Batching**: Ne pas envoyer plusieurs requêtes identiques simultanément
4. **Offline mode**: Stocker les commandes localement si pas de connexion

### 6.4 Exemple Retry avec Backoff (Flutter)

```dart
Future<T> retryWithBackoff<T>(
  Future<T> Function() operation, {
  int maxRetries = 3,
  Duration initialDelay = const Duration(seconds: 1),
}) async {
  int attempt = 0;
  
  while (true) {
    try {
      return await operation();
    } on ApiException catch (e) {
      if (e.statusCode == 429 && attempt < maxRetries) {
        attempt++;
        final delay = initialDelay * (1 << attempt); // Exponentiel
        await Future.delayed(delay);
        continue;
      }
      rethrow;
    }
  }
}
```

---

## 7. Flux Complet d'une Commande Kiosk

```
┌─────────┐     1. LOGIN      ┌──────────────┐
│  Kiosk  │ ────────────────► │   Backend    │
│         │ ◄──────────────── │              │
│         │    Token Kiosk    │              │
│         │                   │              │
│         │     2. GET MENU   │              │
│         │ ────────────────► │              │
│         │ ◄──────────────── │              │
│         │    Items/Catégories              │
│         │                   │              │
│         │  3. BUILD ORDER   │              │
│         │  (Sélection client)               │
│         │                   │              │
│         │   4. POST ORDER   │              │
│         │ ────────────────► │              │
│         │ ◄──────────────── │              │
│         │    Order + Queue #│              │
│         │                   │              │
│         │  5. SHOW TICKET   │              │
│         │  (Afficher numéro)│              │
│         │                   │              │
└─────────┘                   └──────────────┘
                                    │
                                    ▼ Push
                              ┌──────────────┐
                              │     POS      │
                              │  (Notification)
                              └──────────────┘
```

---

*Documentation API pour développeurs Kiosk. Pour toute question d'intégration, contacter l'équipe technique.*
