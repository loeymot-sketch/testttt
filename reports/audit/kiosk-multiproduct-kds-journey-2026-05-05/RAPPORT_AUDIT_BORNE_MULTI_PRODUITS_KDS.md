# Audit borne multi-produits -> paiement -> backend -> KDS

Date UTC: 2026-05-06T04:54:43.276Z

## Verdict

- Flux borne multi-produits execute dans le navigateur: PASS.
- Paiement carte avec stub TPE navigateur + confirmation backend: PASS.
- Creation backend `POST /api/frontend/order` + `payment-confirm`: PASS.
- KDS: commande visible, deux lignes produit visibles, transitions en preparation puis pret: PASS.
- Controle anti-duplication: `queue_count_same_day = 1`, `order_items_count = 2`: PASS.

## Point audit visuel borne

- La capture catalogue montre que les noms longs de categorie et de produit peuvent deborder et se superposer dans la borne. Le flux reste fonctionnel, mais la finition UI doit tronquer, reduire ou reflow ces libelles.

## Point audit cuisine

- La borne simple produit ne propose pas de champ instruction libre dans ce parcours; le KDS recoit les lignes produit, mais pas de note client cuisine personnalisee.
- Pour une V1 restaurant plus forte, ajouter un champ instruction client borne ou imposer des choix composer visibles sur KDS pour les produits qui le demandent.

## Donnees commande

```json
{
  "orderResponse": {
    "id": 185,
    "order_serial_no": "060526185",
    "queue_number": "A0001",
    "token": null,
    "subtotal": 13.1,
    "discount": 0,
    "total_tax": 0,
    "total": 13.1,
    "subtotal_currency_price": "13.10€",
    "subtotal_without_tax_currency_price": "13.10€",
    "discount_currency_price": "0.00€",
    "delivery_charge_currency_price": "0.00€",
    "total_currency_price": "13.10€",
    "total_tax_currency_price": "0.00€",
    "order_type": 25,
    "created_at": "2026-05-06T06:54:07+02:00",
    "order_datetime": "06:54 AM, 06-05-2026",
    "order_date": "06-05-2026",
    "order_time": "06:54 AM",
    "delivery_date": "06-05-2026",
    "delivery_time": "",
    "payment_method": 4,
    "payment_status": 10,
    "payment_pending_counter": false,
    "is_advance_order": 10,
    "preparation_time": 15,
    "status": 1,
    "status_name": "En attente",
    "reason": null,
    "user": {
      "id": 15,
      "name": "Admin Le Cayenne",
      "first_name": "Admin",
      "last_name": "Le Cayenne",
      "phone": "0600000000",
      "email": "admin@lecayenne.fr",
      "username": "admin",
      "balance": "0.00",
      "currency_balance": "0.00€",
      "image": "http://localhost:8000/images/default/profile.png",
      "role_id": 73,
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
        "id": 185,
        "order_id": 185,
        "branch_id": 1,
        "item_id": 425,
        "item_name": "AUDIT-KIOSK-MULTI Burger borne 9B68D5",
        "item_image": "http://localhost:8000/images/menu/item-default.svg",
        "quantity": 1,
        "discount": "0.00€",
        "price": "8.90€",
        "item_variations": [],
        "item_extras": [],
        "item_addons": [],
        "composition_snapshot": {
          "lines": [],
          "addons": [],
          "extras": [],
          "captured_at": "2026-05-06T06:54:07+02:00",
          "schema_version": 1
        },
        "allergens_snapshot": [],
        "item_variation_currency_total": "0.00€",
        "item_extra_currency_total": "0.00€",
        "total_convert_price": 8.9,
        "total_currency_price": "8.90€",
        "instruction": "",
        "kds_station": "none",
        "tax_type": "%",
        "tax_rate": "0.000000",
        "tax_currency_rate": "0.00",
        "tax_name": "AUDIT-KIOSK-MULTI TVA 0 9B68D5",
        "tax_currency_amount": "0.00€",
        "total_without_tax_currency_price": "8.90€"
      },
      {
        "id": 186,
        "order_id": 185,
        "branch_id": 1,
        "item_id": 426,
        "item_name": "AUDIT-KIOSK-MULTI Dessert borne 9B68D5",
        "item_image": "http://localhost:8000/images/menu/item-default.svg",
        "quantity": 1,
        "discount": "0.00€",
        "price": "4.20€",
        "item_variations": [],
        "item_extras": [],
        "item_addons": [],
        "composition_snapshot": {
          "lines": [],
          "addons": [],
          "extras": [],
          "captured_at": "2026-05-06T06:54:07+02:00",
          "schema_version": 1
        },
        "allergens_snapshot": [],
        "item_variation_currency_total": "0.00€",
        "item_extra_currency_total": "0.00€",
        "total_convert_price": 4.2,
        "total_currency_price": "4.20€",
        "instruction": "",
        "kds_station": "none",
        "tax_type": "%",
        "tax_rate": "0.000000",
        "tax_currency_rate": "0.00",
        "tax_name": "AUDIT-KIOSK-MULTI TVA 0 9B68D5",
        "tax_currency_amount": "0.00€",
        "total_without_tax_currency_price": "4.20€"
      }
    ],
    "table_name": null,
    "pos_payment_method": null,
    "pos_payment_note": null,
    "source": 5,
    "pos_received_amount": null,
    "pos_received_currency_amount": "0.00€",
    "cash_back_amount": -13.1,
    "cash_back_currency_amount": "-13.10€",
    "tax_lines": [
      {
        "tax_name": "AUDIT-KIOSK-MULTI TVA 0 9B68D5",
        "tax_rate": "0",
        "tax_type": 10,
        "base_ht": 13.1,
        "base_ht_currency": "13.10€",
        "tax": 0,
        "tax_currency": "0.00€"
      }
    ],
    "fiscal_sequence_no": null,
    "audit_chain_fingerprint": null,
    "pos_register_id": null,
    "pos_siret": null,
    "pos_vat_intra": null,
    "pos_legal_footer": null,
    "operator_name": "Admin Le Cayenne",
    "payments_breakdown": []
  },
  "trace": {
    "id": 185,
    "branch_id": 1,
    "business_date": "2026-05-06",
    "queue_number": "A0001",
    "order_serial_no": "060526185",
    "source_surface": "kiosk",
    "order_type": 25,
    "status": 8,
    "payment_method": 4,
    "payment_status": 5,
    "pos_payment_method": null,
    "subtotal": 13.1,
    "total": 13.1,
    "order_items_count": 2,
    "fiscal_sequence_no": null
  },
  "fixture": {
    "ok": true,
    "run": "9B68D5",
    "branch_id": 1,
    "category_id": 321,
    "category_name": "AUDIT-KIOSK-MULTI Categorie borne 9B68D5",
    "products": [
      {
        "item_id": 425,
        "name": "AUDIT-KIOSK-MULTI Burger borne 9B68D5",
        "price": 8.9
      },
      {
        "item_id": 426,
        "name": "AUDIT-KIOSK-MULTI Dessert borne 9B68D5",
        "price": 4.2
      }
    ],
    "expected_total": 13.1,
    "kdsIdentity": {
      "expected_queue_number": "A0001",
      "queue_number_visible": true,
      "order_serial_no": "060526185",
      "order_serial_visible": true,
      "backend_id_visible": true,
      "visual_source_labels": [
        "Sur place",
        "À emporter",
        "Borne"
      ],
      "excerpt": "Écran Cuisine Bonjour Chef Cuisine Chef Cuisine chef@lecayenne.fr +330600000003 0.00€ Edit Profile Change Password Logout Mode secours actif — actualisation automatique toutes les 5s. Les marques prêt sont enregistrées sur ce poste uniquement ; elles ne se synchronisent pas entre plusieurs écrans cuisine. Masquer Préparations Articles à produire pour les commandes confirmées ou en préparation. AUDIT-KIOSK-MULTI Burger borne 9B68D5 1 AUDIT-KIOSK-MULTI Dessert borne 9B68D5 1 Poste cuisine Tous les postes Bar Cuisine chaude Cuisine froide Regrouper par table Son nouvelle commande Son nouvelle commande Volume Toutes Confirmées En Préparation Terminées Sur place Aucune commande sur place en cours. En ligne Aucune commande en ligne en cours. À emporter Aucune commande à emporter en cours. 🖥️ Borne 1 #060526185 N°A0001 Confirmées N° file: A0001 06:54, 06-05-2026 1x AUDIT-KIOSK-MULTI Burger borne 9B68D5 1x AUDIT-KIOSK-MULTI Dessert borne 9B68D5 Imprimer ticket Démarrer préparation Pas encore "
    },
    "runtimeErrors": []
  }
}
```

## Lignes cuisine

| Produit | Quantite | Total | Instruction |
|---|---:|---:|---|
| AUDIT-KIOSK-MULTI Burger borne 9B68D5 | 1 | 8.9 |  |
| AUDIT-KIOSK-MULTI Dessert borne 9B68D5 | 1 | 4.2 |  |

## Transitions et stock

```json
{
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
| [01-kiosk-auth-idle.png](screenshots/01-kiosk-auth-idle.png) | borne connectee sur ecran accueil | `http://localhost:8000/kiosk/idle` | ☀ 🌮 🍔 🍟 Bienvenue ! Commandez en quelques touches Sur place Je mange ici À emporter Je récupère ma commande CHOISISSEZ UNE OPTION POUR COMMENCER Borne authentifiee Depart parcours client Panier vide |
| [02-kiosk-catalogue-produits-visibles.png](screenshots/02-kiosk-catalogue-produits-visibles.png) | catalogue borne avec deux produits visibles | `http://localhost:8000/kiosk/categories?cat=321` | ☀ NOS AUDIT-KIOSK-MULTI Categorie borne 9B68D5 👤 Mon compte TACOS SANDWICHS BURGERS ASSIETTES SALADES POULET CROUSTILLANT AUDIT-KIOSK-MULTI CATEGORIE BORNE 9B68D5 OJJA OMELETTES MENUS ENFANTS FRITES & ACCOMPAGNEMENTS SUPPLÉMENTS DESSERTS BOISSONS SANDWICH FRO |
| [04-kiosk-apres-ajout-produit-1.png](screenshots/04-kiosk-apres-ajout-produit-1.png) | catalogue apres ajout produit 1 | `http://localhost:8000/kiosk/categories?cat=321` | ☀ NOS AUDIT-KIOSK-MULTI Categorie borne 9B68D5 👤 Mon compte TACOS SANDWICHS BURGERS ASSIETTES SALADES POULET CROUSTILLANT AUDIT-KIOSK-MULTI CATEGORIE BORNE 9B68D5 OJJA OMELETTES MENUS ENFANTS FRITES & ACCOMPAGNEMENTS SUPPLÉMENTS DESSERTS BOISSONS SANDWICH FRO |
| [05-kiosk-apres-ajout-produit-2.png](screenshots/05-kiosk-apres-ajout-produit-2.png) | catalogue apres ajout produit 2 | `http://localhost:8000/kiosk/categories?cat=321` | ☀ NOS AUDIT-KIOSK-MULTI Categorie borne 9B68D5 👤 Mon compte TACOS SANDWICHS BURGERS ASSIETTES SALADES POULET CROUSTILLANT AUDIT-KIOSK-MULTI CATEGORIE BORNE 9B68D5 OJJA OMELETTES MENUS ENFANTS FRITES & ACCOMPAGNEMENTS SUPPLÉMENTS DESSERTS BOISSONS SANDWICH FRO |
| [06-kiosk-panier-multi-produits.png](screenshots/06-kiosk-panier-multi-produits.png) | panier borne contient deux lignes distinctes | `http://localhost:8000/kiosk/cart` | ☀ VOTRE PANIER 2 articles Vider le panier 🍽️ Sur place 🥡 À emporter AUDIT-KIOSK-MULTI Burger borne 9B68D5 €8,90 par unité 1 €8,90 AUDIT-KIOSK-MULTI Dessert borne 9B68D5 €4,20 par unité 1 €4,20 Sous-total €13,10 Total €13,10 Code promo Appliquer ★ Avez-vous u |
| [08-kiosk-upsell-affiche-skip.png](screenshots/08-kiosk-upsell-affiche-skip.png) | ecran upsell affiche puis refuse | `http://localhost:8000/kiosk/upsell` | ☀ ET POUR TERMINER ? Ajoutez quelque chose à votre commande Salade Royale €7,50 + Salade Chèvre €7,50 + Panini €5,00 + Salade verte €2,00 + Cheese Burger €6,00 + Filets de poulet croustillants (12 pièces) €13,50 + Non merci, continuer sans ✓ AUDIT-KIOSK-MULTI  |
| [09-kiosk-paiement-carte-selectionne.png](screenshots/09-kiosk-paiement-carte-selectionne.png) | ecran paiement carte avec total coherent | `http://localhost:8000/kiosk/payment` | ☀ CHOISISSEZ VOTRE PAIEMENT Total à régler : €13,10 TOTAL À RÉGLER : €13,10 Carte bancaire Visa · Mastercard · Carte bleue € Espèces Paiement à la caisse Titre restaurant Edenred · Swile · Sodexo Confirmer — €13,10 Paiement borne Carte selectionnee Total: 13.1 |
| [10-kiosk-tpe-paiement-en-cours.png](screenshots/10-kiosk-tpe-paiement-en-cours.png) | overlay TPE visible pendant paiement | `http://localhost:8000/kiosk/payment` | ☀ CHOISISSEZ VOTRE PAIEMENT Total à régler : €13,10 Simulation du paiement (mode navigateur)… Suivez les instructions sur l'appareil de paiement Si l'écran ne répond plus, utilisez « Annuler le paiement » ci-dessous ou le bouton du terminal. Annuler le paiemen |
| [11-kiosk-apres-paiement-confirme.png](screenshots/11-kiosk-apres-paiement-confirme.png) | borne affiche confirmation ou attente apres paiement confirme | `http://localhost:8000/kiosk/confirmation?number=A0001&total=13.1` | ☀ Commande confirmée ! NUMÉRO DE COMMANDE #A0001 TOTAL PAYÉ €13,10 Votre commande a été envoyée en cuisine. Présentez-vous au comptoir avec votre numéro. Retour automatique dans 30s 🖨️ Imprimer le ticket Nouvelle commande → Paiement confirme Commande #185 Fil |
| [12-kds-commande-borne-recue.png](screenshots/12-kds-commande-borne-recue.png) | KDS affiche la commande borne et ses deux produits | `http://localhost:8000/admin/kitchen-display-system` | Écran Cuisine Bonjour Chef Cuisine Chef Cuisine chef@lecayenne.fr +330600000003 0.00€ Edit Profile Change Password Logout Mode secours actif — actualisation automatique toutes les 5s. Les marques prêt sont enregistrées sur ce poste uniquement ; elles ne se syn |
| [13-kds-commande-en-preparation.png](screenshots/13-kds-commande-en-preparation.png) | KDS passe la commande en preparation | `http://localhost:8000/admin/kitchen-display-system` | Écran Cuisine Bonjour Chef Cuisine Chef Cuisine chef@lecayenne.fr +330600000003 0.00€ Edit Profile Change Password Logout Mode secours actif — actualisation automatique toutes les 5s. Les marques prêt sont enregistrées sur ce poste uniquement ; elles ne se syn |
| [14-kds-commande-prete.png](screenshots/14-kds-commande-prete.png) | KDS passe la commande en pret | `http://localhost:8000/admin/kitchen-display-system` | Écran Cuisine Bonjour Chef Cuisine Chef Cuisine chef@lecayenne.fr +330600000003 0.00€ Edit Profile Change Password Logout Reconnexion en cours… × Mode secours actif — actualisation automatique toutes les 5s. Les marques prêt sont enregistrées sur ce poste uniq |
| [15-kiosk-apres-commande-prete-kds.png](screenshots/15-kiosk-apres-commande-prete-kds.png) | borne apres passage KDS en pret | `http://localhost:8000/kiosk/idle` | ☀ 🌮 🍔 🍟 Bienvenue ! Commandez en quelques touches Sur place Je mange ici À emporter Je récupère ma commande CHOISISSEZ UNE OPTION POUR COMMENCER Retour borne apres KDS pret Commande #185 Controle attente client |
