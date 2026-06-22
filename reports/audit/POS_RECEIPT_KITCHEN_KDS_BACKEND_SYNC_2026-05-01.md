# Audit — Ticket caisse double (client / cuisine) vs synchronisation cuisine & backend

**Date** : 2026-05-01  
**Périmètre** : Changements Vue (`ReceiptComponent`, `posReceiptBuilder`, i18n), chaîne **persistée** commande → KDS, endpoint **print-receipt**.

---

## 1. Synthèse exécutive

| Question | Verdict |
|----------|---------|
| Le **backend** alimente-t-il l’écran cuisine avec les **mêmes** données (composition + `instruction`) que celles disponibles pour les tickets ? | **Oui**, via le modèle persistant `order_items` et les APIs KDS / détail commande — **indépendamment** des boutons d’impression. |
| L’impression **ticket client** puis **ticket cuisine** change-t-elle l’état serveur ou le flux temps réel vers la cuisine ? | **Non** pour le ticket cuisine (impression navigateur uniquement). **Oui** pour le ticket client : `POST .../print-receipt` incrémente `receipt_print_count` + audit NF525 — **sans** réémission d’événement métier vers la cuisine. |
| Ordre temporel « client puis cuisine » côté navigateur | Strictement **UX** ; la commande est déjà **créée et diffusée** avant toute ouverture du modal ticket. |

---

## 2. Chaîne backend (POS → persistance → cuisine)

1. **Création commande POS** : `POST admin/pos` via `OrderService` (panier encaissé).
2. Après **commit** transaction : jobs / broadcast **`OrderCreated`** (et signaux associés) — pattern « dispatch après commit » documenté dans `OrderService` / `FrontendOrderService`.
3. **`order_items.instruction`** est renseigné à la création ; exposé dans **`OrderItemResource`** (`instruction` ligne 42).
4. **KDS** consomme les commandes via les endpoints métier (ex. `admin/kds-order`) ; le composant **`KitchenDisplaySystemComponent.vue`** affiche `orderItem.instruction` (classes `.kds-instruction`).
5. **Aucune** étape d’impression ticket n’est requise pour que la ligne apparaisse en cuisine : la synchro est **création commande → broadcast / polling**, pas **print → cuisine**.

---

## 3. Double ticket (implémentation actuelle)

| Élément | Rôle backend |
|---------|----------------|
| **Ticket client** (HTML + `vue3-print-nb`) | Bouton déclenche **`POST admin/pos/orders/{id}/print-receipt`** (`PosReceiptPrintController`) — compteur fiscal / audit. |
| **Ticket cuisine** (second bloc HTML) | **Aucun** POST dédié ; réutilise les **`order_items`** déjà en mémoire Vuex après `GET admin/pos-order/show/{id}`. Donc **pas** de risque de désynchronisation « serveur cuisine » / papier : les deux lisent le même snapshot chargé avec la commande. |

---

## 4. Limites / écarts produit (à connaître)

- **`PosOrderReceiptComponent`** (écran `/admin/pos-orders/show/:id`) n’est **pas** le même gabarit que le modal paiement : pas de double bouton client/cuisine dans ce flux — uniquement le flux **paiement POS** (`PaymentComponent` + `ReceiptComponent`).
- Si le navigateur **bloque** la 2e impression ou l’opérateur n’imprime que le client, **la cuisine ne change pas** : l’info reste sur KDS.

### Couverture E2E « double bouton » papier

Le flux **modal paiement** (deux boutons *Ticket client* / *Ticket cuisine*) n’est pas rejoué en Playwright dans ce lot (parcours UI lourd). La politique **POST fiscal uniquement client** est couverte par **Vitest** (`posReceiptPrintFlow.spec.js`). Le spec Playwright ci-dessous valide la **cohérence données** cuisine ↔ API (contenu équivalent au ticket cuisine imprimé).

---

## 5. Preuves automatisées

- **Vitest** : `tests/js/posReceiptPrintFlow.spec.js` — POST uniquement sur impression client ; pas d’appel sur cuisine.
- **Playwright (E2E)** : `tests/e2e/pos-receipt-kds-instruction-sync.spec.js`
  - Crée une commande POS avec instruction `PW-RKS INST`.
  - Vérifie `GET admin/pos-order/show/{id}` → `order_items[0].instruction` contient le marqueur.
  - Vérifie le **KDS** (`/admin/kitchen-display-system`) affiche le texte dans `.kds-instruction` (réactivité sans reload manuel).
  - **Exécution locale** : `npx playwright test tests/e2e/pos-receipt-kds-instruction-sync.spec.js` → **1 passed** (2026-05-01).
  - Prérequis : API sur `http://localhost:8000` (ou `PLAYWRIGHT_BASE_URL`).
- Rapport JSON Playwright agrégé : `reports/antigravity/playwright-latest.json` (si suite complète lancée).

---

## 6. Conclusion

La synchronisation « **données cuisine** » est **correcte au sens backend** : elle repose sur la **commande enregistrée**, pas sur l’impression. Les deux tickets papier sont une **couche présentation** ; seul le ticket **client** déclenche une **écriture fiscale** supplémentaire.

**Verdict global** : **ALIGNÉ** avec une séparation claire fiscal (client) vs préparation (cuisine navigateur).
