# Plan — Kiosk FoodKing : Rebuild complet inspiré Splash 360

**Architecte :** Claude  
**Date :** 2026-03-24  
**Test type :** local-validation (build npm + validation manuelle parcours complet)  
**Priorité :** P0 — fondation borne

---

## Compréhension architecture (10 lignes)

- FoodKing possède déjà un module kiosk partiel (`KioskWizardComponent`, `KioskOrderSummaryComponent`, `KioskConfirmationComponent` + 6 steps) — **non routé, non intégré dans un flow complet**.
- L'analyse de Splash 360 révèle : architecture `nginx → Express → MongoDB` locale avec UI web fullscreen, wizard multi-étapes, upsell merchandising, fidélité, TPE USB.
- FoodKing cible une architecture cloud-first (Laravel API) avec Electron pour le hardware — plus robuste et SaaS-compatible que le modèle local Splash.
- Ce plan construit le **flow complet borne** : Idle → Catégories → Produits → Wizard → Panier → Upsell → Paiement → Attente → Confirmation.
- Toutes les règles métier existantes sont respectées : prix côté serveur, `FrontendOrderService`, `queue_number`, isolation `branch_id`.

---

## Périmètre de ce plan

| Composant | Fichier | Statut avant | Après |
|-----------|---------|-------------|-------|
| App layout kiosk | `KioskAppComponent.vue` | ❌ Absent | ✅ Créé |
| Écran idle | `KioskIdleScreenComponent.vue` | ❌ Absent | ✅ Créé |
| Catégories | `KioskCategoriesComponent.vue` | ❌ Absent | ✅ Créé |
| Liste produits | `KioskProductListComponent.vue` | ❌ Absent | ✅ Créé |
| Wizard produit | `KioskWizardComponent.vue` | ✅ Présent | ✅ Routé |
| Panier | `KioskCartComponent.vue` | ❌ Absent | ✅ Créé |
| Upsell | `KioskUpsellComponent.vue` | ❌ Absent | ✅ Créé |
| Paiement | `KioskPaymentComponent.vue` | ❌ Absent | ✅ Créé |
| Attente | `KioskWaitingComponent.vue` | ❌ Absent | ✅ Créé |
| Confirmation | `KioskConfirmationComponent.vue` | ✅ Présent | ✅ Routé |
| Store panier | `kioskCart.js` | ❌ Absent | ✅ Créé |
| Routes | `kioskRoutes.js` | ❌ Absent | ✅ Créé |

---

## Flow complet

```
/kiosk (KioskAppComponent — layout racine)
  /kiosk/idle          → KioskIdleScreenComponent
  /kiosk/categories    → KioskCategoriesComponent
  /kiosk/products/:id  → KioskProductListComponent
  /kiosk/wizard/:id    → KioskWizardComponent (existant)
  /kiosk/cart          → KioskCartComponent
  /kiosk/upsell        → KioskUpsellComponent
  /kiosk/payment       → KioskPaymentComponent
  /kiosk/waiting/:id   → KioskWaitingComponent
  /kiosk/confirmation  → KioskConfirmationComponent (existant)
```

---

## Règles métier respectées

1. Prix : la borne envoie des IDs, le serveur recalcule — `FrontendOrderService` inchangé.
2. Commande : `POST /api/frontend/order` — contrat API inchangé.
3. Paiement CB : `POST /api/frontend/order/{id}/payment-confirm` — prévu pour phase TPE.
4. Queue number : géré par le backend, affiché dans `KioskWaitingComponent`.
5. Isolation branche : `branch_id` depuis `frontendBranch` store existant.

---

## Risques

- Inactivité : le timer doit être global (dans `KioskAppComponent`), pas dans chaque écran.
- Upsell : ne pas bloquer le flow si l'API upsell échoue — `try/catch` avec fallback skip.
- Paiement : pour cette phase, CB = simulation (bouton confirme sans TPE réel) ; TPE viendra avec Electron.
- Images catégories : si absentes en base, fallback emoji/couleur par catégorie.

---

## Suite (phase suivante — non incluse dans ce plan)

- TPE Electron (phase 2)
- Impression ticket ESC/POS (phase 2)
- Fidélité dans parcours borne (phase 3)
- Back-office config borne (vidéo idle, produits upsell) (phase 3)
