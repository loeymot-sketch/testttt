# Cartographie — IMPRESSION + INTÉGRATION UBER EATS + DÉPLOIEMENT

> Vague 01-structure (lecture seule). Tout path/line ci-dessous a été vu via Read/Grep/ls dans cette session (2026-07-02, branche `pos/category-first-caisse-2026-06-23`, HEAD `594eb92f5`).

---

## 1. IMPRESSION — architecture

### 1.1 Topologie (validée par les commentaires en tête des fichiers)
Laravel tourne sur un cloud Linux (OVH) → **ne peut PAS joindre l'USB du SAGA caisse**. Le serveur est le **SSOT du rendu** (octets ESC/POS NF525-fidèles) ; la sortie physique est déléguée à un **pont local Node** sur le PC caisse/borne (`127.0.0.1:9100/raw`), avec fallback `window.print()`.

```
Order (composition_snapshot SSOT)
  → ReceiptDataService (en-tête NF525) + KitchenTicketSymbolicFormatter
  → OrderReceiptEscPosRenderer (client / cuisine, UTF-8 → CP858 une seule fois)
  → EscPosTicketBytesService (SSOT unifié caisse+borne, largeur/code-page depuis Printer)
  → [chemin A serveur] EscPosPrinterService → PrinterTransportInterface (tcp | windows_raw | null)
  → [chemin B pont]    Controllers /escpos (b64) → front (posLocalPrinter.js / kioskPrinter.js)
                        → POST 127.0.0.1:9100/raw (tools/caisse-bridge/caisse-bridge.js)
```

### 1.2 Fichiers backend impression
| Fichier | Rôle |
|---|---|
| `app/Services/Hardware/OrderReceiptEscPosRenderer.php` (565 l) | Renderer SSOT. `renderClientTicket` (l.33) : en-tête branche/SIRET, n° file en double-taille si court (l.76-84), articles via `renderClientItem` (l.388), totaux `lineItemKV` atomiques (l.128), TVA ventilée par taux avec prorata remise (l.515-552), paiement — `COUNTER_DEFERRED(6)` → `[]` → « A REGLER EN CAISSE » (l.483-484), pied NF525 (fiscal seq, TVA intra, legal footer l.171-179), coupe pilotée config (l.198-199). `renderKitchenTicket` (l.207) : symbolique 2 lignes sans prix, wrap indenté (l.287). `money()` = « 9,40 € » (l.554-559). Transcodage CP858 UNE fois en fin de flux (l.203, contrat d'encodage documenté l.17-22). |
| `app/Services/Hardware/EscPosCommandBuilder.php` (326 l) | Primitives ESC/POS : `doubleHeight` (l.56, GS ! hauteur seule → 48 col préservées), `cut` full/partial (l.71), `openDrawerCommand` (l.79), `selectCodePage` CP858=19 (l.97), `encodeForPrinter` avec pré-map Œ→Oe/Æ→Ae (l.117) + iconv TRANSLIT + fallback ASCII (l.112-128), width-safe : `wrapIndented` (l.167), `textWrap` (l.206), `lineItemKV` prix atomique jamais coupé (l.222). `sanitize` re-map les ligatures AVANT la mise en page pour que mb_strlen == largeur imprimée (l.311-320). |
| `app/Services/Hardware/EscPosTicketBytesService.php` (64 l) | [TICKET-UNIFY 2026-07-01] Source unique des octets caisse ET borne (l.10-18). Stations cherchées : kitchen → `['kitchen_hot','kitchen_cold','receipt']`, client → `['receipt']` (l.41) ; défauts `width_chars=48`, CP858 si aucune row Printer (l.52) ; `withoutGlobalScope(BranchScope)` explicite (l.34-38). |
| `app/Services/Hardware/EscPosPrinterService.php` (150 l) | `sendRaw` (l.16) via transport injecté + log BypassAuditLogger (l.23) ; `testPrint` (l.50) ; `openDrawer` (l.85) cible station `receipt`, never-throw. |
| `app/Services/Hardware/KitchenTicketSymbolicFormatter.php` (351 l) | Jumeau PHP de `resources/js/helpers/kdsSymbolic.js` (doc l.5-17). Tables regex viandes/sauces/crudités (l.22-50+). Format « G \| SANDWICH \| P \| STO \| SAM », L2 « + Cheddar », L3 « MENU/F ». Parité verrouillée par `tests/Unit/Hardware/KitchenSymbolPhpJsParityTest.php`. |
| `app/Services/Hardware/PrinterTransport/` | `TcpPrinterTransport` (fsockopen host:9100, timeout 2 s, l.24), `WindowsRawPrinterTransport` (winspool RAW, refuse si `PHP_OS_FAMILY !== 'Windows'` l.32, nom de queue = `Printer.host`), `NullPrinterTransport` (bypass), `PrinterTransportInterface`. Binding : `AppServiceProvider.php:34-48` — bypass → Null, `PRINT_DRIVER=windows_raw` → WindowsRaw, défaut → Tcp. |
| `app/Services/Hardware/CustomerDisplayService.php` + `CustomerDisplayCommandBuilder.php` | Afficheur pole VFD 2×20 (CD5220 série, config `printing.customer_display`, désactivé par défaut). |
| `app/Models/Printer.php` (48 l) | fillable `branch_id,name,type,host,port,station,width_chars,status,options` (l.16-26), `options` cast array (code_page), BranchScope global (l.41). Migration `2026_04_20_210000_create_printers_table.php` : `station` string 32 nullable + index (branch_id, station). |
| `app/Console/Commands/SetupReceiptPrinterCommand.php` | `pos:setup-receipt-printer "NOM" --branch=1 --width=48 --code-page=19` → upsert row station=receipt type=`escpos_usb_windows` host=nom de queue Windows (l.40-56) ; rappelle `PRINT_DRIVER=windows_raw`. |

### 1.3 Endpoints /escpos (rendus b64, lecture seule)
- **Caisse** : `routes/api.php:932` — `GET admin/pos/orders/{order}/escpos` → `PosTicketBytesController::show` : gate `can('pos')` (l.28), scoping branch de l'opérateur (l.33-37), délègue à EscPosTicketBytesService (l.61). Aucun audit ici — l'incrément NF525 reste dans `PosReceiptPrintController::increment` (`routes/api.php:927`, middleware idempotency) que le front appelle d'abord (doc l.14-19). Ticket cuisine POS : `POST orders/{order}/print-kitchen` (`routes/api.php:929`).
- **Borne** : `routes/api.php:1339` — `GET frontend/order/show/{frontendOrder}/escpos` → `Frontend/OrderController::escpos` (l.87-111) : exige un user lié à une `KioskMachine` (l.93-95) + propriété de la commande + même branche (l.97-99). Même service → ticket papier identique caisse/borne (doc [TICKET-UNIFY]).

### 1.4 Frontend impression
| Fichier | Rôle |
|---|---|
| `resources/js/helpers/posLocalPrinter.js` (99 l) | Pont caisse : `isCaisseBridgeAvailable` (GET /health « UP », timeout 800 ms, l.49), `printEscPosViaCaisseBridge` (POST octets décodés b64 vers `127.0.0.1:9100/raw` passthrough RAW, l.62), garde anti-double par (orderRef|ticket|jour) persistée localStorage (l.75-97). URL surchargée via `window.foodkingConfig.caisseBridgeUrl` (l.22). |
| `resources/js/components/admin/pos/ReceiptComponent.vue` | FROZEN (BRAIN). Chaîne : incrément print-receipt → pont silencieux (l.651-657 : health check → GET escpos → POST bridge) → fallback `window.print()` (l.633-634, 694-695). |
| `resources/js/components/admin/encaissement/EncaissementComponent.vue` (l.200-203) | Page unifiée encaissement : GET escpos ticket=client → `printEscPosViaCaisseBridge`. |
| `resources/js/helpers/kioskPrinter.js` (647 l) | Borne : `RECEIPT_WIDTH=32` (58 mm, l.42) ; cascade Electron `kioskHardware` → pont local `bridge.js` (hors repo, PC borne ; `printViaLocalBridge` l.273, JSON reconstruit ASCII, tailles `bodySize/titleSize` poussées par `config/printing.php borne_ticket` via `window.foodkingConfig.borneTicket` l.540) → `window.print()` réservé au bouton manuel (l.199-285). |
| `resources/js/helpers/kdsCustomization.js:205` | `sanitizeKdsInstruction(raw, itemName)` — assainit la note (strip écho produit/compo/menu/prix), utilisé par le KDS (`KitchenDisplaySystemComponent.vue:1069,1682`). Miroir PHP : `KitchenTicketSymbolicFormatter::cleanInstruction` (appelé renderer l.273, l.435). |
| `tools/caisse-bridge/caisse-bridge.js` | Pont Node ZÉRO dépendance (http + child_process) à lancer sur le PC Windows caisse : `node caisse-bridge.js "SAGA"` ; écrit winspool RAW ; nécessite flag Chrome `--disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks` (doc l.1-24). |

### 1.5 Listeners d'impression automatique (best-effort, never-throw)
- `app/Listeners/PrintKioskOrderToCounter.php` — création commande borne → copie CLIENT au comptoir (station `receipt`, l.55).
- `app/Listeners/PrintKioskKitchenTicketOnOrderCreated.php` — création borne → ticket CUISINE symbolique, stations `kitchen_hot` prioritaire puis fallback (l.45-51) ; borne seulement (`source_surface=kiosk`).
- `app/Listeners/PrintFiscalReceiptAndOpenDrawerOnCounterPaid.php` — event `OrderPaidAtCounter` → reçu client + tiroir si espèces (station `receipt` l.48) ; NF525 lecture seule, scellé déjà fait dans `confirmCounterPayment` (doc l.29-31).

### 1.6 Config impression — `config/printing.php`
- `driver` = `PRINT_DRIVER` (tcp | windows_raw), défaut tcp (l.30). SAGA caisse = windows_raw (doc l.27).
- `receipt.website` (l.42), `borne_ticket.body_size/title_size` (GS ! ; défauts 0x01/0x11, l.64-65), `cut.feed_lines_before_cut` défaut 8 + `cut.mode` full/partial (l.86-87), `customer_display` (l.100-107), `bypass` (NullTransport, interdit en prod — garde AppServiceProvider, l.122-129).

---

## 2. UBER EATS — fondation (Production Access EN ATTENTE côté Uber)

### 2.1 Flux webhook → commande caisse/KDS
```
POST /api/webhooks/uber (routes/api.php:161, PUBLIC, middleware installed + throttle:60,1)
 → UberWebhookController::handle (l.34)
   1) signatureValid (l.156-169) : HMAC-SHA256 du body brut, clé config uber.webhook_signing_secret,
      hash_equals ; secret vide → REFUS (fail-closed, l.159-162) ; header X-Uber-Signature.
   2) Idempotence : table webhook_events (provider='uber_eats', webhook_id) — processed → 200 (l.54-57),
      insert pending sinon (l.58-70). Migration 2026_05_09_120000_create_webhook_events_table.php.
   3) Events non-order → ack (l.73-76).
   4) createFromUber (l.97-144) : UberClient::fetchOrder → UberOrderMapper::map → DB::transaction :
      Order forceFill branch_id=config uber.branch_id(1), order_type=DELIVERY, source=WEB,
      source_surface='uber_eats', status=ACCEPT, payment_status=PAID (Uber prépayé), PAS de
      fiscal_sequence_no par défaut (l.110-125) ; OrderItem avec composition_snapshot (l.128-138).
   5) auto_accept → UberClient::acceptOrder (l.82-84). Échec → status=failed + 200 (l.85-91).
```

### 2.2 Fichiers Uber
| Fichier | Rôle |
|---|---|
| `app/Services/Uber/UberClient.php` (120 l) | OAuth2 client_credentials, token caché `uber_eats_access_token` TTL expires_in−60 s (l.51) ; never-throw (logge + null/false) ; `fetchOrder`/`acceptOrder`/`denyOrder`/`storeStatus`. |
| `app/Services/Uber/UberOrderMapper.php` (115 l) | Uber payload → items internes. Prix Uber SCELLÉS — PricingService PAS appelé (doc l.13-14) ; montants /100 (cents, l.36, 56, 65-66). `composition_snapshot` schema_version 1, source=uber_eats, modificateurs → `extras`, `lines=[]` (l.68-75). `resolveItemId` (l.80-102) : map config by_uber_id → by_title normalisé → fallback LOWER(name)= puis LIKE sur Item. `queue_number` = « U<4 derniers> » (l.104-107). |
| `config/uber.php` (49 l) | client_id/secret/org/store depuis .env (secrets JAMAIS commis, doc l.6-7) ; endpoints Eats Marketplace (l.29-34) ; décisions métier défauts : `fiscalize=false`, `auto_accept=true`, `deny_on_out_of_stock=false` (l.39-45) ; `branch_id=1` (l.48). `.env.example:507-512` porte les clés vides. |
| `config/uber_menu_map.php` | by_title / by_uber_id — **VIDES** (exemples commentés) → tout repose aujourd'hui sur le fallback name-match du mapper. |
| `docs/UBER_EATS_INTEGRATION_DESIGN.md` | Existe (ls docs). |
| `tests/Feature/Uber/UberIntegrationTest.php` | 5 tests : oauth caché, signature invalide refusée, aucun secret → refus, signature valide + idempotence, mapper → snapshot (l.19-89). |

---

## 3. DÉPLOIEMENT / INFRA

### 3.1 `tools/deploy-vps.sh` (46 l)
À lancer SUR le VPS (vps-418872ac.vps.ovh.net). Branche hard-codée `pos/category-first-caisse-2026-06-23` (l.13). Séquence : sauvegarde HEAD → `git fetch` + `reset --hard origin/<branch>` (l.22-23) → `npm ci` → `npm run production` → `config:clear` + `cache:clear` (l.26-29) → auto-vérification : `public/js/admin-kds.js` ne doit PLUS contenir « Imprimer ticket », sinon ROLLBACK auto vers HEAD sauvegardé (l.32-39). Pas de migrate, pas de queue:restart dans ce script.

### 3.2 Bundles — `webpack.mix.js` (81 l)
- Deux entrées : `pos-app.js` (l.55, entrée POS dédiée /admin/pos-v4) et `app.js` (l.57) + `.extract([...])` 16 vendors (vue, vuex, vue-router, axios, apexcharts, vue-i18n, laravel-echo, pusher-js, dompurify, swiper…) → `vendor.js` + runtime `manifest.js` (l.59-79) ; ordre Blade OBLIGATOIRE manifest → vendor → app (doc l.36). `.version()` content-hash (l.81). Tailwind postCss (l.80).
- `public/mix-manifest.json` (têtes vues) : `/js/pos-app.js`, `/js/app.js`, `/js/manifest.js`, `/js/pos-shell.js`, `/js/admin-shell.js`, `/js/admin-reports.js`, `/js/admin-kds.js`, `/js/admin-oss.js`… (chunks id-hashés).
- Scripts npm (`package.json:16-23`) : `dev/development=mix`, `watch`, `production=mix --production`, `test=vitest run` (+ batterie de scripts codex:* d'outillage agents). Composer (`composer.json scripts`) : hooks standard + `invariants=bash scripts/check-invariants.sh`.

### 3.3 Drivers & daemons attendus (`.env.example`)
- `CACHE_DRIVER=file` (l.150) avec avertissement CRITICAL-PROD → redis en prod (l.151, backlog UNI-03 CLAUDE.md).
- `QUEUE_CONNECTION=redis` (l.172) — `/api/health/ready` renvoie 503 en prod si sync (l.168).
- `BROADCAST_DRIVER=pusher` (soketi) (l.191) — 503 en prod si null/log (l.190).
- `SESSION_DRIVER=file` (l.211).
- Daemons implicites : php serve/fpm, queue worker redis, soketi (echo), redis, + pont caisse-bridge.js et bridge.js borne côté PC Windows (hors repo pour la borne).

### 3.4 Backups
`storage/backups/db-daily/` : dernières archives vues = `daily-2026-05-28.sql.gz`, `daily-2026-05-29.sql.gz`, `daily-2026-06-04.sql.gz`, `daily-2026-06-24.sql.gz` → **rien depuis le 24 juin** (aujourd'hui 2026-07-02).

### 3.5 Runbooks (ls docs/runbooks/)
`CAISSE_LOCAL_BRIDGE_RAW_SETUP.md`, `BORNE_LOCAL_BRIDGE_SETUP.md`, `BORNE_BRIDGE_BIGGER_TEXT.md`, `PRINT_SAGA_USB_WINDOWS_SETUP.md`, `DEPLOYMENT_GUIDE_V1.md`, `KIOSK_DEPLOYMENT.md`, `BYPASS_MODE_OPERATIONAL.md`, `BACKUP_RESTORE_NF525.md`, `UBER_EATS_INTEGRATION_DESIGN.md`.

---

## 4. Invariants observés (file:line)
1. Rendu ticket = SSOT serveur unique caisse+borne : `EscPosTicketBytesService.php:10-18` ; les deux endpoints /escpos y délèguent (`PosTicketBytesController.php:61`, `Frontend/OrderController.php:103`).
2. Transcodage CP858 UNE seule fois en fin de flux — jamais par chaîne (`OrderReceiptEscPosRenderer.php:17-22, 203`).
3. Largeur : défaut 48, lue de `Printer.width_chars` ; toute ligne wrappée ≤ largeur, montants atomiques (`EscPosCommandBuilder.php:222-275` ; tests `TicketWidthSafeTest`).
4. Ligatures Œ/œ/Æ/æ pré-mappées AVANT layout ET avant encodage (idempotent) (`EscPosCommandBuilder.php:117, 311-320`).
5. `COUNTER_DEFERRED(6)` n'est PAS un règlement → ticket « A REGLER EN CAISSE » (`OrderReceiptEscPosRenderer.php:478-484`).
6. Endpoint escpos = lecture seule ; incrément/duplicata NF525 dans `PosReceiptPrintController::increment` (`PosTicketBytesController.php:14-19` ; `routes/api.php:927`).
7. Webhook Uber fail-closed : secret vide OU signature absente/fausse → refus (`UberWebhookController.php:156-169`) ; idempotence `webhook_events` (l.54-70) ; toujours 200 après idempotence (doc l.22-23).
8. Prix Uber scellés — pas de PricingService, canal non-fiscal par défaut (`UberOrderMapper.php:13-14` ; `config/uber.php:39` ; `UberWebhookController.php:123-124`).
9. Impression auto = best-effort never-throw, no-op sans row Printer ACTIVE ou transport Null (les 3 listeners, ex. `PrintFiscalReceiptAndOpenDrawerOnCounterPaid.php:25-28`).
10. Anti-double impression front : 1 ticket/(commande,type,jour) localStorage (`posLocalPrinter.js:75-97`).
11. SSRF garde sur host imprimante : SafeRemoteHost dans PrinterRequest, sentinel `tests/Feature/Security/PrinterHostAllowlistSentinelTest.php` (cible fsockopen `TcpPrinterTransport.php:24`).
12. Bypass impression interdit en prod (`config/printing.php:122-129` + garde AppServiceProvider).

## 5. Risques préliminaires (à vérifier par les vagues suivantes — PAS des findings certifiés)
- R1. `uber_menu_map.php` vide → `resolveItemId` retombe sur un LIKE `%titre%` best-effort ; `item_id` peut être `null` ou FAUX (LIKE trop large) → OrderItem avec item_id null/incorrect en caisse/KDS.
- R2. `UberWebhookController` crée l'Order par `forceFill` + `payment_status=PAID` SANS fiscal_sequence_no ; le commentaire (l.123-124) évoque un « cron/encaissement » si `uber.fiscalize=true` — chemin d'allocation non vu dans ce périmètre, à tracer.
- R3. Signature Uber : le format réel du header `X-Uber-Signature` (hex brut vs préfixe) n'est validable qu'en live (doc l.25 « À VALIDER EN LIVE »).
- R4. Ticket cuisine client-side : `EscPosTicketBytesService.php:41` fallback station `receipt` pour le kitchen → si seule la SAGA receipt existe, client ET cuisine sortent sur la même imprimante (voulu single-box, mais width/code-page cuisine = ceux du receipt).
- R5. `deploy-vps.sh` : branche hard-codée + `git reset --hard` (perte de hotfix locaux VPS) + vérification limitée à une chaîne dans admin-kds.js ; pas de `php artisan migrate` ni `queue:restart` → migrations récentes (webhook_events déjà ancienne) et workers stale possibles. Leçon mémoire : bundles gitignorés (app.js) → un déploiement partiel a déjà produit un écran blanc.
- R6. Backups db-daily : dernier dump 2026-06-24 → cron de backup possiblement arrêté (8 jours de trou).
- R7. `CACHE_DRIVER=file` template : le lock NF525 `Cache::lock` cross-worker (connu UNI-03) — OK single-box V1, à re-vérifier au cutover.
- R8. Pont 127.0.0.1:9100 sans auth : n'importe quelle page locale pourrait POSTer /raw (surface locale uniquement ; flag Chrome désactive des protections réseau privé pour le kiosque).
- R9. Deux renderers borne coexistent : `kioskPrinter.js` JSON ASCII (bridge borne) vs endpoint escpos unifié — la voie réellement active sur la borne dépend du front déployé (à confirmer en e2e).

## 6. Couverture de tests observée (ls/grep réels)
- `tests/Feature/Uber/UberIntegrationTest.php` (5 tests).
- `tests/Unit/Hardware/` : `EscPosCommandBuilderWrapTest.php`, `KitchenSymbolPhpJsParityTest.php`, `KitchenTicketSymbolicFormatterTest.php`, `CustomerDisplayServiceTest.php`.
- `tests/Feature/Hardware/` : `TicketWidthSafeTest.php`, `OrderReceiptEscPosRendererTest.php`.
- `tests/Feature/` : `PrinterServiceTest.php`, `PrinterControllerTest.php`, `EscPosOpenDrawerTest.php` ; `tests/Feature/Security/PrinterHostAllowlistSentinelTest.php`.
- JS : `tests/js/posLocalPrinter.spec.js`, `tests/js/kioskPrinter.spec.js`, `tests/js/posReceiptPrintFlow.spec.js`, `tests/js/kdsSymbolic.spec.js` (référencé par le formatter), `tests/js/kdsCustomization.spec.js`, `tests/js/kdsSource.spec.js`.

## 7. Questions ouvertes
1. Quelle voie d'impression borne est ACTIVE en prod : bridge.js JSON (kioskPrinter) ou endpoint escpos unifié (TICKET-UNIFY) ?
2. Où est le chemin d'allocation fiscale si `UBER_FISCALIZE=true` (cron ? encaissement ?) — non vu dans ce périmètre.
3. Format exact du header X-Uber-Signature (hex ? base64 ? préfixé ?) — non vérifiable sans Production Access.
4. Le cron backup db-daily tourne-t-il encore (dernier dump 2026-06-24) ?
5. `printer.width_chars=32` est-il posé sur le VPS pour la SAGA 58 mm (mémoire : « RESTE : width_chars=32 VPS ») — état DB prod non observable d'ici.
