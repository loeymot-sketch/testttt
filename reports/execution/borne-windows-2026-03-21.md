# Exécution — Architecture Borne Windows
**Date:** 2026-03-21  
**Auteur:** Claude  

---

## Projet créé : `borne-windows/`

Nouveau projet Electron à `/Users/1millnonstop/Downloads/projet/foodking-web/web/borne-windows/`

### 39 fichiers créés

#### Electron (processus principal)
| Fichier | Rôle |
|---------|------|
| `electron/main.js` | BrowserWindow, IPC handlers, electron-store config |
| `electron/preload.js` | Bridge sécurisé `window.borne.*` exposé au renderer |
| `electron/autolaunch.js` | Enregistrement démarrage automatique Windows |
| `electron/services/PrinterService.js` | ESC/POS Epson/Star (USB + réseau TCP) |
| `electron/services/DrawerService.js` | Tiroir caisse (ESC p via imprimante ou serial) |
| `electron/services/PaymentService.js` | Factory adaptateurs terminal |
| `electron/services/adapters/StubAdapter.js` | Simulation (2s delay, always approved) |
| `electron/services/adapters/SumUpAdapter.js` | REST HTTPS + polling checkout |
| `electron/services/adapters/IngenicoAdapter.js` | TCP socket JSON |
| `electron/services/adapters/PAXAdapter.js` | HTTP POSLINK local |

#### Vue.js (renderer)
| Fichier | Rôle |
|---------|------|
| `src/main.js` | Bootstrap Vue 3 + Vuex + Router + Axios |
| `src/App.vue` | Root avec PaymentOverlay + ToastNotification globaux |
| `src/views/KioskLoginView.vue` | Login machine kiosk |
| `src/views/KioskHomeView.vue` | Menu + grille produits + wizard modal |
| `src/views/KioskCheckoutView.vue` | Panier avec quantités |
| `src/views/KioskPaymentView.vue` | Sélection cash/carte + traitement |
| `src/views/KioskConfirmationView.vue` | Confirmation + countdown 30s |
| `src/views/KioskAdminView.vue` | Panel admin (test imprimante, tiroir, terminal) |
| `src/store/modules/kioskAuth.js` | Auth Sanctum kiosk:order |
| `src/store/modules/kioskCart.js` | Panier (sessionStorage) |
| `src/store/modules/kioskMenu.js` | Menu avec cache 5min |
| `src/store/modules/kioskOrder.js` | Soumission commande + confirmPayment |
| `src/services/websocketService.js` | Soketi WebSockets (Phase E) |
| `src/components/kiosk/*.vue` | Copie exacte des composants kiosk existants |
| `src/components/ui/PaymentOverlay.vue` | Overlay paiement carte |
| `src/components/ui/ToastNotification.vue` | Notifications toast |

#### Config
| Fichier | Rôle |
|---------|------|
| `package.json` | Electron 28 + Forge + Vue 3 + node-escpos + serialport |
| `vite.config.js` | Build renderer Vue |
| `vite.main.config.js` | Build main process |
| `vite.preload.config.js` | Build preload |
| `config/borne.example.json` | Template config (API, printer, terminal, drawer) |
| `README.md` | Documentation complète |

---

## Fichiers modifiés dans le projet Laravel

| Fichier | Modification |
|---------|-------------|
| `routes/api.php` | Ajout `POST /frontend/order/{id}/payment-confirm` |
| `app/Http/Controllers/Frontend/OrderController.php` | Méthode `paymentConfirm()` |
| `app/Events/OrderCreated.php` | Nouvel événement ShouldBroadcast (Phase E) |
| `app/Events/OrderStatusChanged.php` | Nouvel événement ShouldBroadcast (Phase E) |
| `app/Services/OrderService.php` | Dispatch `OrderCreated` + `OrderStatusChanged` dans 3 méthodes |

---

## Flux de paiement implémenté

```
Client → KioskHomeView → wizard → KioskCheckoutView → KioskPaymentView
    → CASH : submitOrder() → printReceipt() → KioskConfirmationView
    → CARD : submitOrder() → chargeCard(IPC) → TPE → confirmPayment(API) → printReceipt() → KioskConfirmationView
```

---

## Pour démarrer le développement

```bash
cd borne-windows
npm install
cp config/borne.example.json config/borne.json
# Éditer config/borne.json
npm run dev
```

## Pour builder l'installateur Windows

```bash
npm run make
# → dist/borne-lecayenne-setup.exe
```

---

## Prochaines étapes

1. `npm install` dans `borne-windows/`
2. Configurer `config/borne.json` avec l'IP du serveur Laravel
3. Tester le login kiosk + passage d'une commande
4. Brancher l'imprimante Epson/Star et tester l'impression
5. Configurer le terminal de paiement (changer `model: "stub"` → `"sumup"` ou autre)
6. Phase E (optionnel) : Docker Soketi + `BROADCAST_DRIVER=pusher` dans `.env`
