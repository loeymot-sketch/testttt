# Audit borne multi-produits -> paiement -> backend -> KDS

Date UTC: 2026-08-25T02:29:39.452Z

## Verdict

- Flux borne multi-produits execute dans le navigateur: PASS.
- Paiement différé au comptoir: commande créée en `PENDING_COUNTER`, sans faux encaissement borne: PASS.
- Creation backend `POST /api/frontend/order`; encaissement carte ensuite confirmé par le point d entrée POS canonique avant admission KDS: PASS.
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
    "id": 6757,
    "order_serial_no": "2508266757",
    "queue_number": "A0041",
    "token": null,
    "parent_order_id": null,
    "parent_order_serial_no": null,
    "subtotal": 13.1,
    "discount": 0,
    "total_tax": 0,
    "total": 13.1,
    "delivery_charge": 0,
    "subtotal_currency_price": "13,10 €",
    "subtotal_without_tax_currency_price": "13,10 €",
    "discount_currency_price": "0,00 €",
    "delivery_charge_currency_price": "0,00 €",
    "total_currency_price": "13,10 €",
    "total_tax_currency_price": "0,00 €",
    "order_type": 10,
    "created_at": "2026-08-25T04:29:03+02:00",
    "order_datetime": "04:29, 25-08-2026",
    "order_date": "25-08-2026",
    "order_time": "04:29",
    "delivery_date": "25-08-2026",
    "delivery_time": "",
    "scheduled_at": null,
    "payment_method": 1,
    "payment_status": 15,
    "payment_pending_counter": true,
    "cash_movement_skipped": false,
    "cash_movement_skipped_message": null,
    "is_advance_order": 10,
    "preparation_time": 30,
    "status": 4,
    "status_name": "Acceptée",
    "reason": null,
    "user": {
      "id": 1,
      "name": "Admin Le Cayenne",
      "phone": "0600000000",
      "loyalty_points": 0
    },
    "order_address": null,
    "branch": {
      "id": 1,
      "name": "Le Cayenne (principal)",
      "email": "contact@lecayenne.fr",
      "phone": "0365678291",
      "latitude": "50.4215667",
      "longitude": "2.9549060",
      "city": "Hénin-Beaumont",
      "state": "Hauts-de-France",
      "zip_code": "62110",
      "address": "437 Rue Élie Gruyelle, 62110 Hénin-Beaumont",
      "status": 5,
      "zone": "\"[{\\\"lat\\\":48.86,\\\"lng\\\":2.33},{\\\"lat\\\":48.87,\\\"lng\\\":2.36},{\\\"lat\\\":48.85,\\\"lng\\\":2.37},{\\\"lat\\\":48.84,\\\"lng\\\":2.34}]\""
    },
    "delivery_boy": null,
    "coupon": null,
    "transaction": null,
    "order_items": [
      {
        "id": 6153,
        "order_id": 6757,
        "branch_id": 1,
        "item_id": 237,
        "item_name": "Burger borne AUDIT-KIOSK-MULTI 8A48E0",
        "item_image": "http://127.0.0.1:8766/images/menu/item-default.svg?v=1774661868",
        "quantity": 1,
        "discount": "0,00 €",
        "price": "8,90 €",
        "item_variations": [],
        "item_extras": [],
        "item_addons": [],
        "composition_snapshot": {
          "lines": [],
          "addons": [],
          "extras": [],
          "captured_at": "2026-08-25T04:29:03+02:00",
          "schema_version": 1
        },
        "allergens_snapshot": [],
        "item_variation_currency_total": "0,00 €",
        "item_extra_currency_total": "0,00 €",
        "total_price": 8.9,
        "total_convert_price": 8.9,
        "total_currency_price": "8,90 €",
        "instruction": "",
        "kds_station": "none",
        "tax_type": "%",
        "tax_rate": "0.000000",
        "tax_currency_rate": "0.00",
        "tax_name": "AUDIT-KIOSK-MULTI TVA 0 8A48E0",
        "tax_currency_amount": "0,00 €",
        "total_without_tax_currency_price": "8,90 €"
      },
      {
        "id": 6154,
        "order_id": 6757,
        "branch_id": 1,
        "item_id": 238,
        "item_name": "Dessert borne AUDIT-KIOSK-MULTI 8A48E0",
        "item_image": "http://127.0.0.1:8766/images/menu/item-default.svg?v=1774661868",
        "quantity": 1,
        "discount": "0,00 €",
        "price": "4,20 €",
        "item_variations": [],
        "item_extras": [],
        "item_addons": [],
        "composition_snapshot": {
          "lines": [],
          "addons": [],
          "extras": [],
          "captured_at": "2026-08-25T04:29:03+02:00",
          "schema_version": 1
        },
        "allergens_snapshot": [],
        "item_variation_currency_total": "0,00 €",
        "item_extra_currency_total": "0,00 €",
        "total_price": 4.2,
        "total_convert_price": 4.2,
        "total_currency_price": "4,20 €",
        "instruction": "",
        "kds_station": "none",
        "tax_type": "%",
        "tax_rate": "0.000000",
        "tax_currency_rate": "0.00",
        "tax_name": "AUDIT-KIOSK-MULTI TVA 0 8A48E0",
        "tax_currency_amount": "0,00 €",
        "total_without_tax_currency_price": "4,20 €"
      }
    ],
    "table_name": null,
    "pos_payment_method": 6,
    "pos_payment_note": null,
    "pos_customer_name": null,
    "pos_customer_phone": null,
    "source": 5,
    "source_surface": "kiosk",
    "pos_received_amount": null,
    "pos_received_currency_amount": "0,00 €",
    "cash_back_amount": -13.1,
    "cash_back_currency_amount": "-13,10 €",
    "tax_lines": [
      {
        "tax_name": "AUDIT-KIOSK-MULTI TVA 0 8A48E0",
        "tax_rate": "0",
        "tax_type": 10,
        "base_ht": 13.1,
        "base_ht_currency": "13,10 €",
        "tax": 0,
        "tax_currency": "0,00 €"
      }
    ],
    "fiscal_sequence_no": null,
    "audit_chain_fingerprint": null,
    "pos_register_id": null,
    "pos_siret": "10417050100019",
    "pos_vat_intra": "FR19104170501",
    "pos_legal_footer": "TVA intracommunautaire - Merci de votre visite",
    "operator_name": "Admin Le Cayenne",
    "payments_breakdown": []
  },
  "trace": {
    "id": 6757,
    "branch_id": 1,
    "business_date": "2026-08-25",
    "queue_number": "A0041",
    "order_serial_no": "2508266757",
    "source_surface": "kiosk",
    "order_type": 10,
    "status": 8,
    "payment_method": 1,
    "payment_status": 5,
    "pos_payment_method": 2,
    "subtotal": 13.1,
    "total": 13.1,
    "order_items_count": 2,
    "fiscal_sequence_no": 2756
  },
  "fixture": {
    "ok": true,
    "run": "8A48E0",
    "branch_id": 1,
    "tax_id": 89,
    "category_id": 133,
    "category_name": "AUDIT-KIOSK-MULTI Categorie borne 8A48E0",
    "products": [
      {
        "item_id": 237,
        "name": "Burger borne AUDIT-KIOSK-MULTI 8A48E0",
        "price": 8.9
      },
      {
        "item_id": 238,
        "name": "Dessert borne AUDIT-KIOSK-MULTI 8A48E0",
        "price": 4.2
      }
    ],
    "expected_total": 13.1,
    "cache_key_invalidated": "kiosk.menu.branch.1",
    "cache_present_after_invalidation": false,
    "kdsIdentity": {
      "expected_queue_number": "A0041",
      "queue_number_visible": true,
      "order_serial_no": "2508266757",
      "order_serial_visible": false,
      "backend_id_visible": false,
      "visual_source_labels": [],
      "excerpt": "[A] EN COURS BORNE CUISSON 1×? N°A0041 ATTENTE 00:11 1× BUR 1× DES Prêt"
    },
    "runtimeErrors": []
  }
}
```

## Lignes cuisine

| Produit | Quantite | Total | Instruction |
|---|---:|---:|---|
| Burger borne AUDIT-KIOSK-MULTI 8A48E0 | 1 | 8.9 |  |
| Dessert borne AUDIT-KIOSK-MULTI 8A48E0 | 1 | 4.2 |  |

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
    "OrderPaidAtCounter",
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
| [01-kiosk-auth-idle.png](screenshots/01-kiosk-auth-idle.png) | borne connectee sur ecran accueil | `http://127.0.0.1:8766/kiosk/idle` | NOS INCONTOURNABLES Le Terminator Double Cheese Le Cayenne Grill Burger Le Suprême Menu Maxi Bol de riz Bol de frites 100% HALAL Bienvenue ! Le Cayenne Commandez en quelques touches Ultra gourmand Frais du jour Préparé minute Touchez l'écran pour commander Cho |
| [02-kiosk-catalogue-produits-visibles.png](screenshots/02-kiosk-catalogue-produits-visibles.png) | catalogue borne avec deux produits visibles | `http://127.0.0.1:8766/kiosk/categories?cat=133` | NOS AUDIT-KIOSK-MULTI Categorie borne 8A48E0 👤 Mon compte SANDWICHS GALETTE BURGERS TACOS BOLS MENU ENFANT AUDIT-KIOSK-MULTI CATEGORIE BORNE 8A48E0 E2E CAT 1786616399744 E2ECATEGORY13511EDITED FRITES E2E_PLAYWRIGHT_STUDIO_CATEGORY DESSERTS BOISSONS AUDIT-KIOS |
| [04-kiosk-apres-ajout-produit-1.png](screenshots/04-kiosk-apres-ajout-produit-1.png) | catalogue apres ajout produit 1 | `http://127.0.0.1:8766/kiosk/categories?cat=133` | NOS AUDIT-KIOSK-MULTI Categorie borne 8A48E0 👤 Mon compte SANDWICHS GALETTE BURGERS TACOS BOLS MENU ENFANT AUDIT-KIOSK-MULTI CATEGORIE BORNE 8A48E0 E2E CAT 1786616399744 E2ECATEGORY13511EDITED FRITES E2E_PLAYWRIGHT_STUDIO_CATEGORY DESSERTS BOISSONS AUDIT-KIOS |
| [05-kiosk-apres-ajout-produit-2.png](screenshots/05-kiosk-apres-ajout-produit-2.png) | catalogue apres ajout produit 2 | `http://127.0.0.1:8766/kiosk/categories?cat=133` | NOS AUDIT-KIOSK-MULTI Categorie borne 8A48E0 👤 Mon compte SANDWICHS GALETTE BURGERS TACOS BOLS MENU ENFANT AUDIT-KIOSK-MULTI CATEGORIE BORNE 8A48E0 E2E CAT 1786616399744 E2ECATEGORY13511EDITED FRITES E2E_PLAYWRIGHT_STUDIO_CATEGORY DESSERTS BOISSONS AUDIT-KIOS |
| [06-kiosk-panier-multi-produits.png](screenshots/06-kiosk-panier-multi-produits.png) | panier borne contient deux lignes distinctes | `http://127.0.0.1:8766/kiosk/cart` | VOTRE PANIER 2 articles Vider le panier 🥡 À emporter Burger borne AUDIT-KIOSK-MULTI 8A48E0 €8,90 par unité 1 €8,90 Dessert borne AUDIT-KIOSK-MULTI 8A48E0 €4,20 par unité 1 €4,20 Sous-total €13,10 Total €13,10 ★ Avez-vous une carte fidélité ? › Valider ma comm |
| [08-kiosk-upsell-affiche-skip.png](screenshots/08-kiosk-upsell-affiche-skip.png) | ecran upsell affiche puis refuse | `http://127.0.0.1:8766/kiosk/upsell` | ET POUR TERMINER ? Ajoutez quelque chose à votre commande Coca-Cola Zero 33cl €1,90 + Oasis Tropical 33cl €1,90 + Eau Plate 50cl €1,00 + Tiramisu €3,50 + Glace €3,50 + Capri-Sun €1,50 + Non merci, continuer sans Upsell borne detecte Capture puis passage volont |
| [09-kiosk-mode-paiement-selectionne.png](screenshots/09-kiosk-mode-paiement-selectionne.png) | écran paiement au comptoir cohérent | `http://127.0.0.1:8766/kiosk/payment` | PAIEMENT À LA CAISSE Veuillez payer à la caisse TOTAL À RÉGLER : €13,10 Confirmer ma commande Paiement borne Règlement différé au comptoir Total: 13.1 |
| [11-kiosk-apres-paiement-confirme.png](screenshots/11-kiosk-apres-paiement-confirme.png) | borne affiche confirmation ou attente apres paiement confirme | `http://127.0.0.1:8766/kiosk/cash-instruction?number=A0041&total=13.1&timeout=45&orderId=6757` | 💶 Rendez-vous en caisse Présentez votre numéro à un membre de l'équipe Numéro de commande #A0041 Montant à régler 13,10 € Réglez votre commande à la caisse : espèces, carte bleue ou titres-restaurant. Retour à l'accueil dans 45 s 🖨️ RÉIMPRIMER LE TICKET J'AI |
| [12-kds-commande-borne-recue.png](screenshots/12-kds-commande-borne-recue.png) | KDS affiche la commande borne et ses deux produits | `http://127.0.0.1:8766/admin/kitchen-display-system` | Tableau De Bord Français Anglais Français Bonjour Chef Le Cayenne Chef Le Cayenne chef@lecayenne.fr +330600000003 0,00 € Modifier Le Profil Changer Le Mot De Passe Appareils Connectés Déconnexion 🚫 Rupture 📚 Historique 🔑 Afficher les noms ⓘ 🚫 ANNULÉES — RE |
| [13-kds-commande-en-preparation.png](screenshots/13-kds-commande-en-preparation.png) | KDS passe la commande en preparation | `http://127.0.0.1:8766/admin/kitchen-display-system` | Tableau De Bord Français Anglais Français Bonjour Chef Le Cayenne Chef Le Cayenne chef@lecayenne.fr +330600000003 0,00 € Modifier Le Profil Changer Le Mot De Passe Appareils Connectés Déconnexion 🚫 Rupture 📚 Historique 🔑 Afficher les noms ⓘ 🚫 ANNULÉES — RE |
| [14-kds-commande-prete.png](screenshots/14-kds-commande-prete.png) | KDS passe la commande en pret | `http://127.0.0.1:8766/admin/kitchen-display-system` | Tableau De Bord Français Anglais Français Bonjour Chef Le Cayenne Chef Le Cayenne chef@lecayenne.fr +330600000003 0,00 € Modifier Le Profil Changer Le Mot De Passe Appareils Connectés Déconnexion 🚫 Rupture 📚 Historique 🔑 Afficher les noms ⓘ 🚫 ANNULÉES — RE |
| [15-kiosk-apres-commande-prete-kds.png](screenshots/15-kiosk-apres-commande-prete-kds.png) | borne apres passage KDS en pret | `http://127.0.0.1:8766/kiosk/cash-instruction?number=A0041&total=13.1&timeout=45&orderId=6757` | 💶 Rendez-vous en caisse Présentez votre numéro à un membre de l'équipe Numéro de commande #A0041 Montant à régler 13,10 € Réglez votre commande à la caisse : espèces, carte bleue ou titres-restaurant. Retour à l'accueil dans 10 s 🖨️ RÉIMPRIMER LE TICKET J'AI |
