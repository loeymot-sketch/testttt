# Exécution — Kiosk FoodKing : Rebuild complet inspiré Splash 360

**Exécuteur :** Claude (architecte + implémentation)  
**Date :** 2026-03-24  
**Plan :** `reports/planning/KIOSK-SPLASH-REBUILD-2026-03-24.md`

---

## Build

| Contrôle | Résultat |
|----------|----------|
| `npm run dev` | **✅ Compiled Successfully** (5642ms) |
| Erreurs webpack | 0 |
| app.js | 12.5 MiB |

---

## Fichiers créés / modifiés

| Fichier | Type | Description |
|---------|------|-------------|
| `resources/js/store/modules/kioskCart.js` | Nouveau | Store Vuex panier kiosk (items, submit, upsell, fidélité) |
| `resources/js/router/modules/kioskRoutes.js` | Nouveau | Routes `/kiosk/*` nested sous KioskAppComponent |
| `resources/js/components/frontend/kiosk/KioskAppComponent.vue` | Nouveau | Layout racine kiosk (inactivité 60s, barre panier flottante, transitions, touch ripple) |
| `resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue` | Nouveau | Écran idle (vidéo ou gradient animé, CTA pulse Splash-style, dots animés) |
| `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue` | Nouveau | Grille catégories 2 colonnes, images, animations, fallback emoji |
| `resources/js/components/frontend/kiosk/KioskProductListComponent.vue` | Nouveau | Liste produits horizontale avec wizard overlay inline |
| `resources/js/components/frontend/kiosk/KioskCartComponent.vue` | Nouveau | Panier complet (+/- quantités, totaux, déclencheur upsell) |
| `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue` | Nouveau | Suggestions dessert/boisson avant paiement (Splash-style) |
| `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` | Nouveau | Choix CB/Espèces/TR + soumission commande API |
| `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue` | Nouveau | Numéro commande + polling statut + alerte sonore PRÊT |
| `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue` | Modifié | Ajout reset store + redirect idle |
| `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` | Modifié | Props non-required + fallback store dispatch |
| `resources/js/store/index.js` | Modifié | Import + enregistrement `kioskCart` |
| `resources/js/router/index.js` | Modifié | Import + concat `kioskRoutes` |

---

## Flow complet implémenté

```
/kiosk/idle → [touch] → /kiosk/categories
  → [catégorie] → /kiosk/products/:id
    → [produit simple] → ajout panier direct
    → [produit avec options] → wizard overlay inline
  → [barre panier flottante] → /kiosk/cart
    → [valider] → /kiosk/upsell
      → [non merci / ajouter] → /kiosk/payment
        → [CB / Espèces / TR] → POST /api/frontend/order
          → /kiosk/waiting/:orderId
            → [polling statut] → PREPARED → alerte sonore
              → /kiosk/idle (auto-reset 20s ou bouton)
```

---

## Règles métier respectées

- Prix calculés côté serveur (FrontendOrderService) — la borne envoie des IDs
- Inactivité : reset auto 60s → /kiosk/idle
- Upsell : skip si API échoue (try/catch avec fallback)
- Paiement espèces : prévu pour `window.borne.openDrawer()` (phase 2 Electron)

---

## Suite recommandée (validation humaine)

1. `npm run dev` → ouvrir `/kiosk` dans le navigateur
2. Vérifier parcours complet : idle → catégories → produit → panier → upsell → paiement
3. Créer une commande test → vérifier `queue_number` en base
4. Vérifier que le KDS reçoit bien la commande

---

## Hors périmètre (phases suivantes)

- TPE physique (Electron + node-serialport)
- Impression ticket ESC/POS
- Fidélité dans parcours borne
- Back-office config borne (vidéo idle, produits upsell via flag DB)
