# Rapport audit — parcours commande → KDS (FoodKing V1)

Généré (UTC) : **2026-05-05T14:06:36.583Z**

Ce document est produit automatiquement par Playwright (`audit-max-sync-order-journey-documentation.spec.js`). Il complète les assertions JSON du trace global avec une **description écran par écran** (captures **viewport**). Les montants et files d’attente viennent du **backend SSOT** (devis + commande).

---

## 0. Préambule technique

- Préfixe fixture trace : `AUDIT-SYNC-JOURNEY` (données jetables alignées sur `global-pos-kiosk-order-trace`).
- Item de démonstration : assiette composer (variation + extra + addon boisson), total attendu **12,50** € (hors promotions).
- Les étapes **Encaisser** côté POS et **paiement carte simulé** côté borne peuvent être couvertes par **API navigateur** (`createPosOrderViaApi` / `createKioskCardOrderViaApi`) lorsque le wizard UI est trop variable selon résolution ; les captures montrent néanmoins l’état réel des surfaces **avant** et **après** création commande.

## 1. Données de test injectées (fixture)

```json
{
  "category_name": "AUDIT-SYNC-JOURNEY Category 54FB9CA6",
  "run": "54FB9CA6",
  "branch_id": 1,
  "customer_id": 28,
  "category_id": 164,
  "item_id": 179,
  "item_name": "AUDIT-SYNC-JOURNEY Assiette 54FB9CA6",
  "addon_item_id": 180,
  "addon_name": "AUDIT-SYNC-JOURNEY Boisson 54FB9CA6",
  "variation_id": 165,
  "variation_name": "AUDIT-SYNC-JOURNEY XL 54FB9CA6",
  "extra_id": 108,
  "extra_name": "AUDIT-SYNC-JOURNEY Sauce 54FB9CA6",
  "addon_id": 27,
  "expected_total": 12.5
}
```

## 2. Projection catalogue (session admin — endpoint menu)

L’API `GET admin/menu-projection` exige des droits **catalogue / admin** ; le caissier seul reçoit **403**. On ouvre une session admin **minimale** (login + capture dashboard), puis les projections POS et Kiosk.


### Capture `01-admin-dashboard-menu-projection-prelude.png`

URL observée : `http://localhost:8000/admin/dashboard` — capture **viewport** (haut de page ; scroller manuellement si besoin pour audit humain).

![](screenshots/01-admin-dashboard-menu-projection-prelude.png)

Projection POS : catégorie **AUDIT-SYNC-JOURNEY Category 54FB9CA6**, item **AUDIT-SYNC-JOURNEY Assiette 54FB9CA6**, étapes composer **3**.

Projection Kiosk : idem visibilité item **AUDIT-SYNC-JOURNEY Assiette 54FB9CA6**.

---

## 3. Bande caisse (POS) — navigation et ticket

### 3.1 Connexion caissier + grille menu


### Capture `02-pos-home-menu-loaded.png`

URL observée : `http://localhost:8000/admin/pos` — capture **viewport** (haut de page ; scroller manuellement si besoin pour audit humain).

![](screenshots/02-pos-home-menu-loaded.png)

### 3.2 Sélection catégorie fixture

Recherche du pill catégorie dont le libellé contient le nom fixture **AUDIT-SYNC-JOURNEY Category 54FB9CA6**.


### Capture `03-pos-category-selected.png`

URL observée : `http://localhost:8000/admin/pos` — capture **viewport** (haut de page ; scroller manuellement si besoin pour audit humain).

![](screenshots/03-pos-category-selected.png)

### 3.3 Ouverture fiche / wizard produit (si carte visible)


### Capture `04-pos-after-item-click.png`

URL observée : `http://localhost:8000/admin/pos` — capture **viewport** (haut de page ; scroller manuellement si besoin pour audit humain).

![](screenshots/04-pos-after-item-click.png)

### 3.3bis Fermeture wizard POS single-page (`pos-wizard.js`) si ouvert

Le wizard injecte `button.wizard-btn-cancel[data-action="cancel-wizard"]` (libellé « Annuler » + icône) ; sans ce clic, une grande zone `.formule-card` peut **intercepter** le chip panier (Playwright « pointer intercepted »).


### Capture `04b-pos-after-wizard-cancel.png`

URL observée : `http://localhost:8000/admin/pos` — capture **viewport** (haut de page ; scroller manuellement si besoin pour audit humain).

![](screenshots/04b-pos-after-wizard-cancel.png)

### 3.4 Panneau ticket (chip panier)


### Capture `05-pos-cart-panel-open.png`

URL observée : `http://localhost:8000/admin/pos` — capture **viewport** (haut de page ; scroller manuellement si besoin pour audit humain).

![](screenshots/05-pos-cart-panel-open.png)

### 3.5 Création commande POS (cash) — backend SSOT

La commande est soumise via **axios** dans le contexte navigateur POS (devis `admin/pos/quote` puis `admin/pos`) avec ligne composition complète (variation, extra, addon) et instruction cuisine contenant le préfixe trace.

Réponse devis : total TTC **12.5** ; commande **#58**, file **A86243273**.


### Capture `06-pos-after-order-api-success.png`

URL observée : `http://localhost:8000/admin/pos` — capture **viewport** (haut de page ; scroller manuellement si besoin pour audit humain).

![](screenshots/06-pos-after-order-api-success.png)

### 3.6 Suivi des commandes (tracker POS)


### Capture `07-pos-orders-tracker.png`

URL observée : `http://localhost:8000/admin/pos-orders-tracker` — capture **viewport** (haut de page ; scroller manuellement si besoin pour audit humain).

![](screenshots/07-pos-orders-tracker.png)

---

## 4. Bande borne (Kiosk) — parcours client

### 4.1 Login borne + navigation client (même séquence que `global-pos-kiosk-order-trace`)

Le deep-link `/kiosk/categories?cat=` seul après login peut ne pas monter `kiosk-categories-root` selon garde-route SPA ; on utilise `openKioskCategoryAsCustomer` (idle → sur place → catégorie).


### Capture `08-kiosk-categories-flow.png`

URL observée : `http://localhost:8000/kiosk/categories?cat=164` — capture **viewport** (haut de page ; scroller manuellement si besoin pour audit humain).

![](screenshots/08-kiosk-categories-flow.png)

### 4.2 Grille catégorie + carte produit


### Capture `09-kiosk-product-grid-with-fixture-card.png`

URL observée : `http://localhost:8000/kiosk/categories?cat=164` — capture **viewport** (haut de page ; scroller manuellement si besoin pour audit humain).

![](screenshots/09-kiosk-product-grid-with-fixture-card.png)

### 4.3 Ajout au panier (ouvre wizard composer)


### Capture `10-kiosk-after-add-click-wizard-or-overlay.png`

URL observée : `http://localhost:8000/kiosk/categories?cat=164` — capture **viewport** (haut de page ; scroller manuellement si besoin pour audit humain).

![](screenshots/10-kiosk-after-add-click-wizard-or-overlay.png)

### 4.4 Wizard composer (1 capture + 1 tentative « suivant »)


### Capture `11-kiosk-wizard-overlay.png`

URL observée : `http://localhost:8000/kiosk/categories?cat=164` — capture **viewport** (haut de page ; scroller manuellement si besoin pour audit humain).

![](screenshots/11-kiosk-wizard-overlay.png)


### Capture `11b-kiosk-wizard-after-next.png`

URL observée : `http://localhost:8000/kiosk/categories?cat=164` — capture **viewport** (haut de page ; scroller manuellement si besoin pour audit humain).

![](screenshots/11b-kiosk-wizard-after-next.png)

### 4.5 Commande kiosk + confirmation paiement carte (simulé) — API

Commande **#59**, file **A86243274**, statut confirmation `true`.


### Capture `12-kiosk-after-api-order.png`

URL observée : `http://localhost:8000/kiosk/categories?cat=164` — capture **viewport** (haut de page ; scroller manuellement si besoin pour audit humain).

![](screenshots/12-kiosk-after-api-order.png)

---

## 5. Cuisine (KDS) — affichage des deux commandes


### Capture `13-kds-initial-load.png`

URL observée : `http://localhost:8000/admin/kitchen-display-system` — capture **viewport** (haut de page ; scroller manuellement si besoin pour audit humain).

![](screenshots/13-kds-initial-load.png)


### Capture `14-kds-with-pos-order-line-visible.png`

URL observée : `http://localhost:8000/admin/kitchen-display-system` — capture **viewport** (haut de page ; scroller manuellement si besoin pour audit humain).

![](screenshots/14-kds-with-pos-order-line-visible.png)


### Capture `15-kds-both-orders-and-addon-visible.png`

URL observée : `http://localhost:8000/admin/kitchen-display-system` — capture **viewport** (haut de page ; scroller manuellement si besoin pour audit humain).

![](screenshots/15-kds-both-orders-and-addon-visible.png)

### 5.1 Changements d’état KDS (ACCEPT → PREPARING → PREPARED)


### Capture `16-kds-after-both-prepared.png`

URL observée : `http://localhost:8000/admin/kitchen-display-system` — capture **viewport** (haut de page ; scroller manuellement si besoin pour audit humain).

![](screenshots/16-kds-after-both-prepared.png)

## 6. Extrait audit backend (JSON)

Fichier complet : `raw-trace.json`. Aperçu :

```json
{
  "orders": [
    {
      "id": 58,
      "branch_id": 1,
      "business_date": "2026-05-05",
      "queue_number": "A86243273",
      "token": "AUDIT-SYNC-JOURNEY-POS-54FB9CA6-1777990019869",
      "source_surface": "pos",
      "order_type": 10,
      "status": 8,
      "payment_method": 1,
      "payment_status": 5,
      "pos_payment_method": 1,
      "total": 12.5,
      "subtotal": 12.5,
      "discount": 0,
      "fiscal_sequence_no": 14,
      "transaction_id": null,
      "order_items_count": 1,
      "composition_snapshot": {
        "lines": [
          {
            "quantity": 1,
            "line_total": 1,
            "unit_price": 1,
            "attribute_id": 210,
            "variation_id": 165,
            "attribute_name": "AUDIT-SYNC-JOURNEY Taille 54FB9CA6",
            "variation_name": "AUDIT-SYNC-JOURNEY XL 54FB9CA6"
          }
        ],
        "addons": [
          {
            "role": "drink",
            "addon_id": 27,
            "quantity": 1,
            "addon_name": "AUDIT-SYNC-JOURNEY Boisson 54FB9CA6",
            "line_total": 2,
            "unit_price": 2,
            "addon_item_id": 180
          }
        ],
        "extras": [
          {
            "extra_id": 108,
            "quantity": 1,
            "extra_name": "AUDIT-SYNC-JOURNEY Sauce 54FB9CA6",
            "line_total": 0.5,
            "unit_price": 0.5
          }
        ],
        "captured_at": "2026-05-05T16:06:59+02:00",
        "schema_version": 1
      },
      "domain_events": [
        "OrderCreated",
        "OrderStatusChanged"
      ],
      "transitions": [
        {
          "from": 4,
          "to": 7
        },
        {
          "from": 7,
          "to": 8
        }
      ],
      "stock_movement": {
        "count": 4,
        "delta_sum": -4,
        "stockables": [
          "Item#179:-1",
          "ItemVariation#165:-1",
          "ItemExtra#108:-1",
          "Item#180:-1"
        ]
      }
    },
    {
      "id": 59,
      "branch_id": 1,
      "business_date": "2026-05-05",
      "queue_number": "A86243274",
      "token": null,
      "source_surface": "kiosk",
      "order_type": 25,
      "status": 8,
      "payment_method": 4,
      "payment_status": 5,
      "pos_payment_method": null,
      "total": 12.5,
      "subtotal": 12.5,
      "discount": 0,
      "fiscal_sequence_no": null,
      "transaction_id": "AUDIT-SYNC-JOURNEY-TPE-SIM-54FB9CA6-1777990028561",
      "order_items_count": 1,
      "composition_snapshot": {
        "lines": [
          {
            "quantity": 1,
            "line_total": 1,
            "unit_price": 1,
            "attribute_id": 210,
            "variation_id": 165,
            "attribute_name": "AUDIT-SYNC-JOURNEY Taille 54FB9CA6",
            "variation_name": "AUDIT-SYNC-JOURNEY XL 54FB9CA6"
          }
        ],
        "addons": [
          {
            "role": "drink",
            "addon_id": 27,
            "quantity": 1,
            "addon_name": "AUDIT-SYNC-JOURNEY Boisson 54FB9CA6",
            "line_total": 2,
            "unit_price": 2,
            "addon_item_id": 180
          }
        ],
        "extras": [
          {
            "extra_id": 108,
            "quantity": 1,
            "extra_name": "AUDIT-SYNC-JOURNEY Sauce 54FB9CA6",
            "line_total": 0.5,
            "unit_price": 0.5
          }
        ],
        "captured_at": "2026-05-05T16:07:08+02:00",
        "schema_version": 1
      },
      "domain_events": [
        "OrderCreated",
        "OrderStatusChanged",
        "OrderStatusChanged"
      ],
      "transitions": [
        {
          "from": 1,
          "to": 4
        },
        {
          "from": 4,
          "to": 7
        },
        {
          "from": 7,
          "to": 8
        }
      ],
      "stock_movement": {
        "count": 4,
        "delta_sum": -4,
        "stockables": [
          "Item#179:-1",
          "ItemVariation#165:-1",
          "ItemExtra#108:-1",
          "Item#180:-1"
        ]
      }
    }
  ],
  "stock": {
    "main_item": 18,
    "variation": 18,
    "extra": 18,
    "addon_item": 18
  },
  "queue_counts": {
    "A86243273": 1,
    "A86243274": 1
  }
}
```

## 7. Liste des fichiers image produits

- `screenshots/01-admin-dashboard-menu-projection-prelude.png`
- `screenshots/01-admin-items-list.png`
- `screenshots/01-pos-after-login-for-projection.png`
- `screenshots/02-pos-home-menu-loaded.png`
- `screenshots/03-pos-category-selected.png`
- `screenshots/04-pos-after-item-click.png`
- `screenshots/04b-pos-after-wizard-cancel.png`
- `screenshots/05-pos-cart-panel-open.png`
- `screenshots/06-pos-after-order-api-success.png`
- `screenshots/07-pos-orders-tracker.png`
- `screenshots/08-kiosk-categories-flow.png`
- `screenshots/09-kiosk-product-grid-with-fixture-card.png`
- `screenshots/10-kiosk-after-add-click-wizard-or-overlay.png`
- `screenshots/11-kiosk-wizard-overlay.png`
- `screenshots/11b-kiosk-wizard-after-next.png`
- `screenshots/12-kiosk-after-api-order.png`
- `screenshots/13-kds-initial-load.png`
- `screenshots/14-kds-with-pos-order-line-visible.png`
- `screenshots/15-kds-both-orders-and-addon-visible.png`
- `screenshots/16-kds-after-both-prepared.png`

---

## 8. Synthèse pour relecture humaine « audit profond »

1. Vérifier sur **02–07** (et **04b** si présent) que la caisse affiche menus, ticket, tracker cohérents avec la branche.
2. Vérifier sur **08–12** que la borne affiche bien le wizard (taille, sauce, boisson) avant bascule API.
3. Vérifier sur **13–17** que le KDS montre file d’attente, libellés cuisine (instruction POS), addon, et que les transitions d’état se reflètent visuellement.
4. Croiser avec **raw-trace.json** (snapshots composition, mouvements stock, domain events).

