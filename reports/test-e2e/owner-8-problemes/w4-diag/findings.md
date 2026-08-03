# W4-DIAG — Impression caisse ~20 s / écran gris / flash terminal / ticket borne (2026-07-06, HEAD 24e8a09c3)

## A — Chronologie clic « Imprimer » (tout séquentiel, ReceiptComponent.vue:570 handlePrintClientClick)
| # | Étape | file:line | Typique | Pire cas |
|---|---|---|---|---|
| 1 | await POST print-receipt | ReceiptComponent.vue:587 | 0,3-1 s | ~9 s |
| 1a | audit chain Cache::lock->block(5) | AuditLogService.php:66-68,101-103 | ~0 | +5 s |
| 1b | printThermalTicket serveur (Printer escpos_tcp actif → host mort) | PosReceiptPrintController.php:83,117-168 ; TcpPrinterTransport.php:24,32 | ~0 | +4 s |
| 2 | health pont | ReceiptComponent.vue:632,660 → posLocalPrinter.js:49 | 10 ms | 800 ms |
| 3 | await GET .../escpos | ReceiptComponent.vue:661-663 | 0,3-1,5 s | 2-3 s |
| 4 | await POST 9100/raw timeout 5000 | ReceiptComponent.vue:666 → posLocalPrinter.js:66-70 | 3-10 s | 5 s abort → toast rouge alors que le papier SORT → double ticket |
| 5 | no-bridge → window.print() | ReceiptComponent.vue:634,681-692 | ÉCRAN GRIS | illimité |
Ticket cuisine REJOUE tout (handlePrintKitchenClick :697-726). Client+cuisine en série = 10-25 s → le ~20 s owner.

### Auto-print (écran gris sans clic)
maybeAutoPrintClient ReceiptComponent.vue:555-566 (mounted :455 + watch :461, gate clearCartOnClose). Pont absent + silentPrintOnly=false → window.print() AUTO. POS_PRINT_SILENT_ONLY défaut FALSE (config/printing.php:46, master.blade.php:198, ReceiptComponent.vue:395-398,682-684). Borne déjà propre (allowBrowserPrint:false).

### Vraie lenteur POST /raw : pont caisse COMPILE du C# à chaque ticket
tools/caisse-bridge/caisse-bridge.js : /raw répond après impression complète (:102-112) ; printRaw (:54-84) spawn powershell Add-Type (compile winspool à CHAQUE impression : 3-9 s PC faible). Pont borne (node-usb direct :134-140) = OK.

### Latence encaissement
PosComponent.vue:3384-3402 : 3 reloads awaited en série AVANT print. Idem EncaissementComponent.vue:191-205.

## A-flash — 100% machine-side
1. Tâche schtasks « toutes les 1 min ×999 » avec /TR "node bridge.js" (MEGA_PLAN:226-231) → console flash 1/min si le pont meurt (mauvais nom imprimante/port pris) — caisse ET borne.
2. Vieille copie caisse-bridge.js sans [FLASH-FIX] windowsHide:true (:73-75) → flash PS à chaque impression.
3. Lancement propre déjà documenté : VBS window-style 0. Runbook : NSSM ou .vbs dans /TR + redeploy md5 caisse-bridge.js.

## B — Ticket borne ≠ caisse
- Caisse = renderer serveur (accents/€ réels, snapshot, width-safe, « ** A REGLER EN CAISSE ** » gras OrderReceiptEscPosRenderer.php:174-176,519).
- Borne flux Plan B réel = kioskPrinter.js buildBridgePayload (:585-642) client-side : ASCII-fold é→e / €→EUR (:508-517), compo Vuex (:440-446), libellé hardcodé (KioskCashInstructionComponent.vue:180).
- L'unification serveur EXISTE (KioskConfirmationComponent.printServerUnifiedTicket :402-429 → GET frontend/order/show/{id}/escpos, routes/api.php:1345 auth:sanctum, OrderController.php:88-114 garde machine+branch, kioskClient=true) MAIS le flux prod finit sur kiosk.cash-instruction (KioskCashInstructionComponent.vue:110-114) qui imprime legacy (:167-209) et NE REÇOIT PAS l'order id (kioskRoutes.js:252-260 ; KioskPaymentComponent.vue:492-497 orderId en scope non passé). → LA cause du design borne.

## Fix scope-minimal (0 frozen)
A: (1) fire-and-forget + toast immédiat (ReceiptComponent.vue:570-729, PosCounterCollectModal.vue:448-481, PosComponent.vue:3391-3404 print avant/parallèle des reloads, EncaissementComponent.vue:195-205) ; (2) caisse-bridge.js répond 202 QUEUED + compile winspool UNE fois au boot ; (3) health memoïsé TTL 15-30 s + client+cuisine Promise.all ; (4) POS_PRINT_SILENT_ONLY=true prod (+ flipper défaut) ; (5) purge printers escpos_tcp morts.
B: (1) KioskPaymentComponent.vue:494 +orderId query ; (2) kioskRoutes.js:252-260 prop ; (3) extraire printServerUnifiedTicket en helper partagé kioskPrinter.js, PRIMAIRE dans autoPrintCounterTicket/reprintTicket (fallback legacy) ; (4) rien serveur.

## Tests à créer
tests/js/kiosk/kioskCashInstructionServerTicket.spec.js · tests/js/kiosk/kioskPaymentCashNavOrderId.spec.js · tests/Feature/Frontend/KioskEscposCounterDeferredTest.php · tests/js/pos/receiptNonBlockingPrint.spec.js · tools/caisse-bridge/caisse-bridge.test.js
