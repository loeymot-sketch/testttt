# FoodKing Core : Database Schema (Entity-Relationship)

Ce document décrit les tables vitales et leurs relations pour le cœur métier du système (commandes et isolation). Toute modification de ces tables impactera l'API Kiosk et l'admin VueJS.

## Modèle Entité-Relation (Mermaid)

```mermaid
erDiagram
    BRANCH ||--o{ USER : "Emploie"
    BRANCH ||--o{ KIOSK_MACHINE : "Possède"
    BRANCH ||--o{ ORDER : "Reçoit"
    
    USER ||--o{ ORDER : "Crée (Optionnel pour User frontend)"
    KIOSK_MACHINE ||--o{ ORDER : "Crée via Kiosk Token"
    
    ORDER ||--|{ ORDER_ITEM : "Contient"
    ITEM ||--o{ ORDER_ITEM : "Est commandé dans"
    
    ITEM_CATEGORY ||--|{ ITEM : "Catégorise"
    TAX ||--o{ ITEM : "S'applique à"
    COUPON ||--o{ ORDER : "Réduit (Optionnel)"
    
    BRANCH {
        bigint id PK
        string name
        string city
        string state
        string zip_code
        string address
        tinyint status "actif/inactif"
    }

    USER {
        bigint id PK
        bigint branch_id FK "Peut être null pour root admin"
        string username
        string password
        string email
    }

    KIOSK_MACHINE {
        bigint id PK
        bigint branch_id FK "La borne est sur ce lieu physique"
        bigint user_id FK "Pseudo-utilisateur associé"
        string machine_id "ex: MAC-A1"
        string username "Login de connexion Kiosk"
        string password "Mot de passe de la borne"
    }

    ORDER {
        bigint id PK
        string order_serial_no "ex: 10001"
        bigint branch_id FK "Lieu de préparation"
        bigint user_id FK "Caissier ou Client"
        tinyint order_type "5 = Takeaway, 10 = Dine-in"
        decimal subtotal "Calculé post-DB item->price"
        decimal total "Sous-total + delivery_charge - discount + tax"
        decimal discount "Via table coupon"
        tinyint payment_method "1 = Cash, 2 = Card, etc."
        tinyint payment_status "5 = Unpaid, 10 = Paid"
        tinyint status "OrderStatus: 1=PENDING,4=ACCEPT,7=PREPARING,8=PREPARED,10=OUT_FOR_DELIVERY,13=DELIVERED,16=CANCELED,19=REJECTED,22=RETURNED (voir app/Enums/OrderStatus.php)"
    }

    ITEM {
        bigint id PK
        bigint item_category_id FK
        bigint tax_id FK
        string name
        string slug 
        decimal price "SOT (Single Source Of Truth) du prix"
    }

    ORDER_ITEM {
        bigint id PK
        bigint order_id FK
        bigint item_id FK
        integer quantity
        decimal price "Stockage du prix au moment T de la commande"
    }
```

---

## 🛑 Règles d'Intégrité de Base de Données (SQLite / MySQL)

1. **Foreign Keys (FK) strictes :** Dans les environnements de test et de Prod, il est indispensable de lier chaque commande à un `branch_id` et un `user_id` existants. Ne jamais laisser de valeurs orphelines.
2. **Nullable Constraints :** Soyez vigilants aux restrictions `NOT NULL`. Ex : Une succursale (Branch) DOIT avoir une `city`, `zip_code` etc. (Modèle SQLite l'exige violemment dans les Tests).
3. **Le prix n'est jamais poussé aveuglément :** `ORDER.subtotal` et `ORDER_ITEM.price` sont calculés par interrogtion `SELECT price FROM items WHERE id = x` au moment de la création. On n'insère jamais les dizaines/centaines de `price` envoyées via JSON Payload depuis un client frontend/kiosk.
4. **Race Conditions sur Tickets :** Attention à l'attribution par lots (stress DB) des numéros (`queue_number` / `order_serial_no`). InnoDB `lockForUpdate()` gère cela dans la logique de service, mais le schéma SQL tolèrerait par défaut le duplicata à haut débit.
