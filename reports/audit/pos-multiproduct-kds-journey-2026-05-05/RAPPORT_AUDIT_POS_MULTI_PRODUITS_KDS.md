# Audit POS multi-produits -> paiement -> backend -> KDS

Date UTC: 2026-05-11T09:11:15.263Z

## Verdict

- Flux caisse multi-produits execute dans le navigateur: PASS.
- Paiement espece via modal POS: PASS.
- Creation backend `POST /api/admin/pos`: PASS.
- KDS: commande visible, instructions visibles, transitions en preparation puis pret: PASS.
- Controle anti-duplication: `queue_count_same_day = 1`, `order_items_count = 2`: PASS.

## Point audit visuel KDS

- La commande POS arrive bien au KDS avec ses lignes et instructions cuisine.
- Le KDS n affiche pas le `queue_number` brut retourne par le POS; il affiche un identifiant visuel interne/serie. A corriger si la file POS doit etre le repere cuisine principal.

```json
{
  "expected_queue_number": "A0146",
  "queue_number_visible": false,
  "order_serial_no": "1105261322",
  "order_serial_visible": false,
  "backend_id_visible": false,
  "visual_source_labels": [
    "POS",
    "Sur place",
    "À emporter",
    "Borne"
  ],
  "excerpt": "Écran Cuisine Bonjour Chef Cuisine Chef Cuisine chef@lecayenne.fr +330600000003 0.00€ Modifier Le Profil Changer Le Mot De Passe Déconnexion Les pastilles « Prêt » (bump) sont mémorisées sur ce poste (navigateur) — elles ne se synchronisent pas entre plusieurs écrans KDS. Ne plus afficher Préparations Aperçu des articles à produire (commandes acceptées ou en préparation) — exclut les fiches entièrement bouclées côté file « commandes du jour » si statut prêt servi. Assiette Poulet Sauce (1ère Gratuite): Ketchup 10 Salade Royale Sauce (1ère Gratuite): Ketchup Suppléments: Menu (Frites + Boisson) Formule : Avec boisson (Coca-Cola 33cl) 10 Fromage à raclette 10 Coca-Cola 33cl 10 Tacos M (1 Viande) Viande 1: Merguez, Sauce (1ère Gratuite): Ketchup TACOS M (1 VIANDE) - Salade, Tomate, Oignon Sauce : Ketchup 3 Frites Seules FRITES SEULES 3 Burger Poulet Sauce (1ère Gratuite): Ketchup BURGER POULET - Salade, Tomate, Oignon 2 Tacos M (1 Viande) Viande 1: Merguez, Sauce (1ère Gratuite): Ketchup "
}
```

## Donnees commande

```json
{
  "orderResponse": {
    "id": 1322,
    "order_serial_no": "1105261322",
    "queue_number": "A0146",
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
    "created_at": "2026-05-11T11:10:53+02:00",
    "order_datetime": "11:10 AM, 11-05-2026",
    "order_date": "11-05-2026",
    "order_time": "11:10 AM",
    "delivery_date": "11-05-2026",
    "delivery_time": "11:10 AM - 11:10 AM",
    "payment_method": null,
    "payment_status": 5,
    "payment_pending_counter": false,
    "is_advance_order": 10,
    "preparation_time": 15,
    "status": 4,
    "status_name": "Acceptée",
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
      "name": "Le Cayenne (principal)",
      "email": "contact@lecayenne.fr",
      "phone": "+33600000000",
      "latitude": "48.8566",
      "longitude": "2.3522",
      "city": "Paris",
      "state": "Île-de-France",
      "zip_code": "75000",
      "address": "Paris, France",
      "status": 1,
      "zone": ""
    },
    "delivery_boy": null,
    "coupon": null,
    "transaction": null,
    "order_items": [
      {
        "id": 1522,
        "order_id": 1322,
        "branch_id": 1,
        "item_id": 438,
        "item_name": "Tacos L E2E Menu 7F3A",
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
          "captured_at": "2026-05-11T11:10:53+02:00",
          "schema_version": 1
        },
        "allergens_snapshot": [],
        "item_variation_currency_total": "0.00€",
        "item_extra_currency_total": "0.00€",
        "total_convert_price": 12,
        "total_currency_price": "12.00€",
        "instruction": "TACOS L E2E MENU 7F3A\nViandes : Merguez, Kefta Supplément : Ketchup (+€0.50)\n[AUDIT-POS-MULTI cuisine tacos 4269D0: sans oignon, bien gratine]",
        "kds_station": "none",
        "tax_type": "%",
        "tax_rate": "0.000000",
        "tax_currency_rate": "0.00",
        "tax_name": "PW E2E ZERO TAX",
        "tax_currency_amount": "0.00€",
        "total_without_tax_currency_price": "12.00€"
      },
      {
        "id": 1523,
        "order_id": 1322,
        "branch_id": 1,
        "item_id": 439,
        "item_name": "AUDIT-POS-MULTI Burger 4269D0",
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
          "captured_at": "2026-05-11T11:10:53+02:00",
          "schema_version": 1
        },
        "allergens_snapshot": [],
        "item_variation_currency_total": "0.00€",
        "item_extra_currency_total": "0.00€",
        "total_convert_price": 7.5,
        "total_currency_price": "7.50€",
        "instruction": "AUDIT-POS-MULTI BURGER 4269D0\n[AUDIT-POS-MULTI cuisine burger 4269D0: sauce a part, cuisson rapide]",
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
    "fiscal_sequence_no": 294,
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
    "id": 1322,
    "branch_id": 1,
    "business_date": "2026-05-11",
    "queue_number": "A0146",
    "source_surface": "pos",
    "status": 8,
    "payment_status": 5,
    "pos_payment_method": 1,
    "subtotal": 19.5,
    "total": 19.5,
    "order_items_count": 2,
    "fiscal_sequence_no": 294
  },
  "fixture": {
    "tacos": {
      "ok": true,
      "item_id": 438,
      "name": "Tacos L E2E Menu 7F3A",
      "branch_id": 1
    },
    "simple": {
      "ok": true,
      "run": "4269D0",
      "branch_id": 1,
      "category_id": 332,
      "item_id": 439,
      "name": "AUDIT-POS-MULTI Burger 4269D0",
      "expected_price": 7.5
    },
    "instructionA": "AUDIT-POS-MULTI cuisine tacos 4269D0: sans oignon, bien gratine",
    "instructionB": "AUDIT-POS-MULTI cuisine burger 4269D0: sauce a part, cuisson rapide",
    "kdsIdentity": {
      "expected_queue_number": "A0146",
      "queue_number_visible": false,
      "order_serial_no": "1105261322",
      "order_serial_visible": false,
      "backend_id_visible": false,
      "visual_source_labels": [
        "POS",
        "Sur place",
        "À emporter",
        "Borne"
      ],
      "excerpt": "Écran Cuisine Bonjour Chef Cuisine Chef Cuisine chef@lecayenne.fr +330600000003 0.00€ Modifier Le Profil Changer Le Mot De Passe Déconnexion Les pastilles « Prêt » (bump) sont mémorisées sur ce poste (navigateur) — elles ne se synchronisent pas entre plusieurs écrans KDS. Ne plus afficher Préparations Aperçu des articles à produire (commandes acceptées ou en préparation) — exclut les fiches entièrement bouclées côté file « commandes du jour » si statut prêt servi. Assiette Poulet Sauce (1ère Gratuite): Ketchup 10 Salade Royale Sauce (1ère Gratuite): Ketchup Suppléments: Menu (Frites + Boisson) Formule : Avec boisson (Coca-Cola 33cl) 10 Fromage à raclette 10 Coca-Cola 33cl 10 Tacos M (1 Viande) Viande 1: Merguez, Sauce (1ère Gratuite): Ketchup TACOS M (1 VIANDE) - Salade, Tomate, Oignon Sauce : Ketchup 3 Frites Seules FRITES SEULES 3 Burger Poulet Sauce (1ère Gratuite): Ketchup BURGER POULET - Salade, Tomate, Oignon 2 Tacos M (1 Viande) Viande 1: Merguez, Sauce (1ère Gratuite): Ketchup "
    },
    "runtimeErrors": []
  }
}
```

## Lignes cuisine

| Produit | Quantite | Total | Instruction |
|---|---:|---:|---|
| 438 | 1 | 0 | TACOS L E2E MENU 7F3A
Viandes : Merguez, Kefta Supplément : Ketchup (+€0.50)
[AUDIT-POS-MULTI cuisine tacos 4269D0: sans oignon, bien gratine] |
| 439 | 1 | 0 | AUDIT-POS-MULTI BURGER 4269D0
[AUDIT-POS-MULTI cuisine burger 4269D0: sauce a part, cuisson rapide] |

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
    "OrderStatusChanged",
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
| [01-pos-caisse-chargee.png](screenshots/01-pos-caisse-chargee.png) | surface caisse POS chargee | `http://localhost:8000/admin/pos` | Bonjour Caissier Caissier pos@lecayenne.fr +330600000002 0.00€ Modifier Le Profil Changer Le Mot De Passe Déconnexion Tableau De Bord POS Ingrédients Commandes Caisse Aller au panier 👑 CAISSE FOODKING Commande rapide Filiale #1 Articles 0  |
| [02-pos-produit-1-configure.png](screenshots/02-pos-produit-1-configure.png) | produit 1 configure avant ajout panier | `http://localhost:8000/admin/pos` | Bonjour Caissier Caissier pos@lecayenne.fr +330600000002 0.00€ Modifier Le Profil Changer Le Mot De Passe Déconnexion Tableau De Bord POS Ingrédients Commandes Caisse Aller au panier 👑 CAISSE FOODKING Commande rapide Filiale #1 Articles 0  |
| [03-pos-panier-apres-produit-1.png](screenshots/03-pos-panier-apres-produit-1.png) | panier contient le produit 1 | `http://localhost:8000/admin/pos` | Bonjour Caissier Caissier pos@lecayenne.fr +330600000002 0.00€ Modifier Le Profil Changer Le Mot De Passe Déconnexion Tableau De Bord POS Ingrédients Commandes Caisse Aller au panier 👑 CAISSE FOODKING Commande rapide Filiale #1 Articles 1  |
| [04-pos-produit-2-configure.png](screenshots/04-pos-produit-2-configure.png) | produit 2 configure avant ajout panier | `http://localhost:8000/admin/pos` | Bonjour Caissier Caissier pos@lecayenne.fr +330600000002 0.00€ Modifier Le Profil Changer Le Mot De Passe Déconnexion Tableau De Bord POS Ingrédients Commandes Caisse Aller au panier 👑 CAISSE FOODKING Commande rapide Filiale #1 Articles 1  |
| [05-pos-panier-multi-produits.png](screenshots/05-pos-panier-multi-produits.png) | panier contient deux produits distincts | `http://localhost:8000/admin/pos` | Bonjour Caissier Caissier pos@lecayenne.fr +330600000002 0.00€ Modifier Le Profil Changer Le Mot De Passe Déconnexion Tableau De Bord POS Ingrédients Commandes Caisse Aller au panier 👑 CAISSE FOODKING Commande rapide Filiale #1 Articles 2  |
| [06-pos-modal-paiement-espece.png](screenshots/06-pos-modal-paiement-espece.png) | modal paiement espece avec total coherent | `http://localhost:8000/admin/pos` | Bonjour Caissier Caissier pos@lecayenne.fr +330600000002 0.00€ Modifier Le Profil Changer Le Mot De Passe Déconnexion Tableau De Bord POS Ingrédients Commandes Caisse Aller au panier 👑 CAISSE FOODKING Commande rapide Filiale #1 Articles 2  |
| [07-pos-recu-apres-paiement.png](screenshots/07-pos-recu-apres-paiement.png) | recu affiche apres paiement confirme | `http://localhost:8000/admin/pos` | Bonjour Caissier Caissier pos@lecayenne.fr +330600000002 0.00€ Modifier Le Profil Changer Le Mot De Passe Déconnexion Tableau De Bord POS Ingrédients Commandes Caisse Aller au panier 👑 CAISSE FOODKING Commande rapide Filiale #1 Articles 2  |
| [08-pos-backoffice-commande-visible.png](screenshots/08-pos-backoffice-commande-visible.png) | commande visible dans commandes caisse | `http://localhost:8000/admin/pos-orders` | Bonjour Caissier Caissier pos@lecayenne.fr +330600000002 0.00€ Modifier Le Profil Changer Le Mot De Passe Déconnexion Tableau De Bord POS Ingrédients Commandes Caisse Tableau De Bord Commandes Caisse Commandes Caisse 10 10 25 50 100 500 100 |
| [09-kds-commande-recue-instructions.png](screenshots/09-kds-commande-recue-instructions.png) | KDS affiche la commande et les instructions cuisine | `http://localhost:8000/admin/kitchen-display-system` | Écran Cuisine Bonjour Chef Cuisine Chef Cuisine chef@lecayenne.fr +330600000003 0.00€ Modifier Le Profil Changer Le Mot De Passe Déconnexion Les pastilles « Prêt » (bump) sont mémorisées sur ce poste (navigateur) — elles ne se synchronisent |
| [10-kds-commande-en-preparation.png](screenshots/10-kds-commande-en-preparation.png) | KDS passe la commande en preparation | `http://localhost:8000/admin/kitchen-display-system` | Écran Cuisine Bonjour Chef Cuisine Chef Cuisine chef@lecayenne.fr +330600000003 0.00€ Modifier Le Profil Changer Le Mot De Passe Déconnexion Les pastilles « Prêt » (bump) sont mémorisées sur ce poste (navigateur) — elles ne se synchronisent |
| [11-kds-commande-prete.png](screenshots/11-kds-commande-prete.png) | KDS passe la commande en pret | `http://localhost:8000/admin/kitchen-display-system` | Écran Cuisine Bonjour Chef Cuisine Chef Cuisine chef@lecayenne.fr +330600000003 0.00€ Modifier Le Profil Changer Le Mot De Passe Déconnexion Les pastilles « Prêt » (bump) sont mémorisées sur ce poste (navigateur) — elles ne se synchronisent |
