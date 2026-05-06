# Audit POS multi-produits -> paiement -> backend -> KDS

Date UTC: 2026-05-06T04:56:23.908Z

## Verdict

- Flux caisse multi-produits execute dans le navigateur: PASS.
- Paiement espece via modal POS: PASS.
- Creation backend `POST /api/admin/pos`: PASS.
- KDS: commande visible, instructions visibles, transitions en preparation puis pret: PASS.
- Controle anti-duplication: `queue_count_same_day = 1`, `order_items_count = 2`: PASS.

## Donnees commande

```json
{
  "orderResponse": {
    "id": 186,
    "order_serial_no": "060526186",
    "queue_number": "A0001",
    "token": "1",
    "subtotal": 19.5,
    "discount": 0,
    "total_tax": 0,
    "total": 19.5,
    "subtotal_currency_price": "19.50€",
    "subtotal_without_tax_currency_price": "19.50€",
    "discount_currency_price": "0.00€",
    "delivery_charge_currency_price": "0.00€",
    "total_currency_price": "19.50€",
    "total_tax_currency_price": "0.00€",
    "order_type": 10,
    "created_at": "2026-05-06T06:56:03+02:00",
    "order_datetime": "06:56 AM, 06-05-2026",
    "order_date": "06-05-2026",
    "order_time": "06:56 AM",
    "delivery_date": "06-05-2026",
    "delivery_time": "06:56 AM - 06:56 AM",
    "payment_method": null,
    "payment_status": 5,
    "payment_pending_counter": false,
    "is_advance_order": 10,
    "preparation_time": 15,
    "status": 4,
    "status_name": "Accept",
    "reason": null,
    "user": {
      "id": 28,
      "name": "Client Comptoir",
      "first_name": "Client",
      "last_name": "Comptoir",
      "phone": null,
      "email": "walkingcustomer@example.com",
      "username": "client_comptoir",
      "balance": "0.00",
      "currency_balance": "0.00€",
      "image": "http://localhost:8000/images/default/profile.png",
      "role_id": null,
      "country_code": "+33",
      "create_date": "05-05-2026",
      "update_date": "05-05-2026"
    },
    "order_address": null,
    "branch": {
      "id": 1,
      "name": "Pfannerstill, Moore and Schmitt Branch",
      "email": "kody21@kirlin.com",
      "phone": "+1-954-654-5404",
      "latitude": "-76.174049",
      "longitude": "86.137836",
      "city": "East Marielle",
      "state": "Maine",
      "zip_code": "41434",
      "address": "10995 Erdman Valleys Suite 516",
      "status": 1,
      "zone": ""
    },
    "delivery_boy": null,
    "coupon": null,
    "transaction": null,
    "order_items": [
      {
        "id": 187,
        "order_id": 186,
        "branch_id": 1,
        "item_id": 427,
        "item_name": "Tacos L E2E Menu DC65",
        "item_image": "http://localhost:8000/images/menu/item-default.svg",
        "quantity": 1,
        "discount": "0.00€",
        "price": "12.00€",
        "item_variations": [],
        "item_extras": [],
        "item_addons": [],
        "composition_snapshot": {
          "lines": [],
          "addons": [],
          "extras": [],
          "captured_at": "2026-05-06T06:56:03+02:00",
          "schema_version": 1
        },
        "allergens_snapshot": [],
        "item_variation_currency_total": "0.00€",
        "item_extra_currency_total": "0.00€",
        "total_convert_price": 12,
        "total_currency_price": "12.00€",
        "instruction": "TACOS L E2E MENU DC65\nViandes : Merguez, Kefta Supplément : Ketchup (+€0.50)\n[AUDIT-POS-MULTI cuisine tacos 2034A1: sans oignon, bien gratine]",
        "kds_station": "none",
        "tax_type": "%",
        "tax_rate": "0.000000",
        "tax_currency_rate": "0.00",
        "tax_name": "PW E2E ZERO TAX",
        "tax_currency_amount": "0.00€",
        "total_without_tax_currency_price": "12.00€"
      },
      {
        "id": 188,
        "order_id": 186,
        "branch_id": 1,
        "item_id": 428,
        "item_name": "AUDIT-POS-MULTI Burger 2034A1",
        "item_image": "http://localhost:8000/images/menu/item-default.svg",
        "quantity": 1,
        "discount": "0.00€",
        "price": "7.50€",
        "item_variations": [],
        "item_extras": [],
        "item_addons": [],
        "composition_snapshot": {
          "lines": [],
          "addons": [],
          "extras": [],
          "captured_at": "2026-05-06T06:56:03+02:00",
          "schema_version": 1
        },
        "allergens_snapshot": [],
        "item_variation_currency_total": "0.00€",
        "item_extra_currency_total": "0.00€",
        "total_convert_price": 7.5,
        "total_currency_price": "7.50€",
        "instruction": "AUDIT-POS-MULTI BURGER 2034A1\n[AUDIT-POS-MULTI cuisine burger 2034A1: sauce a part, cuisson rapide]",
        "kds_station": "none",
        "tax_type": "%",
        "tax_rate": "0.000000",
        "tax_currency_rate": "0.00",
        "tax_name": "PW E2E ZERO TAX",
        "tax_currency_amount": "0.00€",
        "total_without_tax_currency_price": "7.50€"
      }
    ],
    "table_name": null,
    "pos_payment_method": 1,
    "pos_payment_note": null,
    "source": 15,
    "pos_received_amount": "30.000000",
    "pos_received_currency_amount": "30.00€",
    "cash_back_amount": 10.5,
    "cash_back_currency_amount": "10.50€",
    "tax_lines": [
      {
        "tax_name": "PW E2E ZERO TAX",
        "tax_rate": "0",
        "tax_type": 10,
        "base_ht": 19.5,
        "base_ht_currency": "19.50€",
        "tax": 0,
        "tax_currency": "0.00€"
      }
    ],
    "fiscal_sequence_no": 1,
    "audit_chain_fingerprint": null,
    "pos_register_id": null,
    "pos_siret": null,
    "pos_vat_intra": null,
    "pos_legal_footer": null,
    "operator_name": "Client Comptoir",
    "payments_breakdown": [
      {
        "method": 1,
        "amount": 30,
        "currency_amount": "30.00€",
        "change_amount": 10.5,
        "reference": null
      }
    ]
  },
  "trace": {
    "id": 186,
    "branch_id": 1,
    "business_date": "2026-05-06",
    "queue_number": "A0001",
    "source_surface": "pos",
    "status": 8,
    "payment_status": 5,
    "pos_payment_method": 1,
    "subtotal": 19.5,
    "total": 19.5,
    "order_items_count": 2,
    "fiscal_sequence_no": 1
  },
  "fixture": {
    "tacos": {
      "ok": true,
      "item_id": 427,
      "name": "Tacos L E2E Menu DC65",
      "branch_id": 1
    },
    "simple": {
      "ok": true,
      "run": "2034A1",
      "branch_id": 1,
      "category_id": 323,
      "item_id": 428,
      "name": "AUDIT-POS-MULTI Burger 2034A1",
      "expected_price": 7.5
    },
    "instructionA": "AUDIT-POS-MULTI cuisine tacos 2034A1: sans oignon, bien gratine",
    "instructionB": "AUDIT-POS-MULTI cuisine burger 2034A1: sauce a part, cuisson rapide",
    "kdsIdentity": {
      "expected_queue_number": "A0001",
      "queue_number_visible": true,
      "order_serial_no": "060526186",
      "order_serial_visible": true,
      "backend_id_visible": true,
      "visual_source_labels": [
        "POS",
        "Sur place",
        "À emporter",
        "Borne"
      ],
      "excerpt": "Écran Cuisine Bonjour Chef Cuisine Chef Cuisine chef@lecayenne.fr +330600000003 0.00€ Edit Profile Change Password Logout Mode secours actif — actualisation automatique toutes les 5s. Les marques prêt sont enregistrées sur ce poste uniquement ; elles ne se synchronisent pas entre plusieurs écrans cuisine. Masquer Préparations Articles à produire pour les commandes confirmées ou en préparation. Tacos L E2E Menu DC65 TACOS L E2E MENU DC65 Viandes : Merguez, Kefta Supplément : Ketchup (+€0.50) [AUDIT-POS-MULTI cuisine tacos 2034A1: sans oignon, bien gratine] 1 AUDIT-POS-MULTI Burger 2034A1 AUDIT-POS-MULTI BURGER 2034A1 [AUDIT-POS-MULTI cuisine burger 2034A1: sauce a part, cuisson rapide] 1 Poste cuisine Tous les postes Bar Cuisine chaude Cuisine froide Regrouper par table Son nouvelle commande Son nouvelle commande Volume Toutes Confirmées En Préparation Terminées Sur place Aucune commande sur place en cours. En ligne Aucune commande en ligne en cours. À emporter #060526186 N°A0001 Confir"
    },
    "runtimeErrors": []
  }
}
```

## Lignes cuisine

| Produit | Quantite | Total | Instruction |
|---|---:|---:|---|
| 427 | 1 | 0 | TACOS L E2E MENU DC65
Viandes : Merguez, Kefta Supplément : Ketchup (+€0.50)
[AUDIT-POS-MULTI cuisine tacos 2034A1: sans oignon, bien gratine] |
| 428 | 1 | 0 | AUDIT-POS-MULTI BURGER 2034A1
[AUDIT-POS-MULTI cuisine burger 2034A1: sauce a part, cuisson rapide] |

## Transitions et stock

```json
{
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
  "domain_events": [
    "OrderCreated",
    "OrderStatusChanged"
  ],
  "stock_movement": {
    "count": 2,
    "delta_sum": -2
  },
  "queue_count_same_day": 1
}
```

## Captures

| Capture | Assertion | URL | Extrait visible |
|---|---|---|---|
| [01-pos-caisse-chargee.png](screenshots/01-pos-caisse-chargee.png) | surface caisse POS chargee | `http://localhost:8000/admin/pos` | Bonjour Caissier Caissier pos@lecayenne.fr +330600000002 0.00€ Edit Profile Change Password Logout Accueil Caisse Ingredients Commandes Caisse Aller au panier 👑 CAISSE FOODKING Commande rapide Site #1 Articles 0 📋 Commandes 🖥️ Écran clie |
| [02-pos-produit-1-configure.png](screenshots/02-pos-produit-1-configure.png) | produit 1 configure avant ajout panier | `http://localhost:8000/admin/pos` | Bonjour Caissier Caissier pos@lecayenne.fr +330600000002 0.00€ Edit Profile Change Password Logout Accueil Caisse Ingredients Commandes Caisse Aller au panier 👑 CAISSE FOODKING Commande rapide Site #1 Articles 0 📋 Commandes 🖥️ Écran clie |
| [03-pos-panier-apres-produit-1.png](screenshots/03-pos-panier-apres-produit-1.png) | panier contient le produit 1 | `http://localhost:8000/admin/pos` | Bonjour Caissier Caissier pos@lecayenne.fr +330600000002 0.00€ Edit Profile Change Password Logout Accueil Caisse Ingredients Commandes Caisse Aller au panier 👑 CAISSE FOODKING Commande rapide Site #1 Articles 1 📋 Commandes 🖥️ Écran clie |
| [04-pos-produit-2-configure.png](screenshots/04-pos-produit-2-configure.png) | produit 2 configure avant ajout panier | `http://localhost:8000/admin/pos` | Bonjour Caissier Caissier pos@lecayenne.fr +330600000002 0.00€ Edit Profile Change Password Logout Accueil Caisse Ingredients Commandes Caisse Aller au panier 👑 CAISSE FOODKING Commande rapide Site #1 Articles 1 📋 Commandes 🖥️ Écran clie |
| [05-pos-panier-multi-produits.png](screenshots/05-pos-panier-multi-produits.png) | panier contient deux produits distincts | `http://localhost:8000/admin/pos` | Bonjour Caissier Caissier pos@lecayenne.fr +330600000002 0.00€ Edit Profile Change Password Logout Accueil Caisse Ingredients Commandes Caisse Aller au panier 👑 CAISSE FOODKING Commande rapide Site #1 Articles 2 📋 Commandes 🖥️ Écran clie |
| [06-pos-modal-paiement-espece.png](screenshots/06-pos-modal-paiement-espece.png) | modal paiement espece avec total coherent | `http://localhost:8000/admin/pos` | Bonjour Caissier Caissier pos@lecayenne.fr +330600000002 0.00€ Edit Profile Change Password Logout Accueil Caisse Ingredients Commandes Caisse Aller au panier 👑 CAISSE FOODKING Commande rapide Site #1 Articles 2 📋 Commandes 🖥️ Écran clie |
| [07-pos-recu-apres-paiement.png](screenshots/07-pos-recu-apres-paiement.png) | recu affiche apres paiement confirme | `http://localhost:8000/admin/pos` | Bonjour Caissier Caissier pos@lecayenne.fr +330600000002 0.00€ Edit Profile Change Password Logout Accueil Caisse Ingredients Commandes Caisse Aller au panier 👑 CAISSE FOODKING Commande rapide Site #1 Articles 0 📋 Commandes 🖥️ Écran clie |
| [08-pos-backoffice-commande-visible.png](screenshots/08-pos-backoffice-commande-visible.png) | commande visible dans commandes caisse | `http://localhost:8000/admin/pos-orders` | Bonjour Caissier Caissier pos@lecayenne.fr +330600000002 0.00€ Edit Profile Change Password Logout Accueil Caisse Ingredients Commandes Caisse Accueil Commandes Caisse Commandes Caisse Filtrer Exporter Print XLS N° COMMANDE STATUT CLIENT DA |
| [09-kds-commande-recue-instructions.png](screenshots/09-kds-commande-recue-instructions.png) | KDS affiche la commande et les instructions cuisine | `http://localhost:8000/admin/kitchen-display-system` | Écran Cuisine Bonjour Chef Cuisine Chef Cuisine chef@lecayenne.fr +330600000003 0.00€ Edit Profile Change Password Logout Mode secours actif — actualisation automatique toutes les 5s. Les marques prêt sont enregistrées sur ce poste uniqueme |
| [10-kds-commande-en-preparation.png](screenshots/10-kds-commande-en-preparation.png) | KDS passe la commande en preparation | `http://localhost:8000/admin/kitchen-display-system` | Écran Cuisine Bonjour Chef Cuisine Chef Cuisine chef@lecayenne.fr +330600000003 0.00€ Edit Profile Change Password Logout Mode secours actif — actualisation automatique toutes les 5s. Les marques prêt sont enregistrées sur ce poste uniqueme |
| [11-kds-commande-prete.png](screenshots/11-kds-commande-prete.png) | KDS passe la commande en pret | `http://localhost:8000/admin/kitchen-display-system` | Écran Cuisine Bonjour Chef Cuisine Chef Cuisine chef@lecayenne.fr +330600000003 0.00€ Edit Profile Change Password Logout Reconnexion en cours… × Mode secours actif — actualisation automatique toutes les 5s. Les marques prêt sont enregistré |
