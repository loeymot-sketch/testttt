# AUDIT P-MEGA-18 — Hardware fallback baseline (Phase B.1 du cycle W7)

**Date** : 2026-04-20  
**Mode** : READONLY  
**HEAD** : `c1832bf77`  
**Subagent** : explore very thorough  

## 0. Synthèse exécutive (5 lignes)

Le périmètre kiosk hardware est centralisé dans `resources/js/services/kioskHardware.js` (bridge `window.borne`, stub navigateur, contrat `{ok,error?}`, observabilité `POST frontend/kiosk-event`). Santé : healthcheck périodique 90 s dans `KioskAppComponent.vue` + panneau admin (`KioskAdminComponent.vue`). Impression : `helpers/kioskPrinter.js` enchaîne Electron → ESC/POS → `window.print()` ; échec total → bannière `printFailed` dans `KioskConfirmationComponent.vue`. TPE : `KioskPaymentComponent.vue` avec timeout UI 120 s et messages dédiés `TPE_TIMEOUT` ; reprise `payment-confirm` côté serveur (retry) — zone sensible fiscalement. `scanQR`/`readNFC` ne sont **pas** consommés par les composants Vue kiosk ; fidélité = saisie manuelle (`KioskLoyaltyComponent.vue`). « Buzzer » : pas d'API dédiée ; son « prêt » = Web Audio dans `KioskWaitingComponent.vue`, pas le bridge.

## 1. Inventaire hardware actuel (matrice 4 hardware × 4 critères)

| Hardware | API JS | Statut détectable | Comportement actuel offline | Spec attendue |
|----------|--------|-------------------|----------------------------|---------------|
| Printer | `printReceipt`, `printEscPos` (`kioskHardware.js` L267-277) ; consommation via `kioskPrinter.js` | `healthcheck()` interprète `printer` / `printer_status` (L296-303) ; `info()` si dispo (L306-310) | `runSafe` → `{ok:false}` ; chaîne ESC/POS puis `window.print()` ; si `method==='none'` → `printFailed` + `reportPrinterFailure` (`KioskConfirmationComponent.vue` L370-385, `kioskPrinter.js` L193-253) | Offline → écran + email (spec) : écran partiel (fallback + numéro) ; **email non trouvé** |
| TPE | `tpeCharge` (+ legacy `chargeCard`) L230-243 | `healthcheck` critique si `tpe`/`tpe_status` KO (L296-303) | Refus → void order (status 16) L425-431 ; timeout 120 s → toast + compteur échecs → écran erreur (L339-387, L404-411) | Timeout → message clair + replay : message oui ; **replay** = nouvelle tentative utilisateur, pas d'idempotence TPE explicite côté front |
| Scanner (QR/NFC) | `scanQR`, `readNFC` L211-221 | Dégradé si `nfc`/`camera` KO dans `healthcheck` (L297-300) | **Aucun appel** dans `resources/js/components/frontend/kiosk/*.vue` (grep projet : seulement service + bundle) | Scanner KO → saisie manuelle : **déjà couvert** fidélité par champ code (`KioskLoyaltyComponent.vue`) |
| Buzzer | Pas de `buzz()` ; `haptic` L204-207, `play` L180-184 (sync/void partiel) | Non modélisé | `haptic`/`play` non utilisés dans les composants kiosk grep ; « prêt » : `playReadySound()` Web Audio (`KioskWaitingComponent.vue` L311-329) | Buzzer absent → notification visuelle/écran : **partiel** (UI ready + son logiciel) |

## 2. État `kioskHardware.js` détaillé

**Méthodes exportées** : `isKioskBridge` L166-169, `play` L180-184, `stopAllSounds` L186-189, `speak` L191-195, `stopSpeak` L197-200, `haptic` L204-207, `scanQR` L211-215, `readNFC` L217-221, `tpeCharge` L230-243, `tpeRefund` L245-249, `cancelPayment` L251-255, `openDrawer` L258-262, `printReceipt` L267-271, `printEscPos` L273-277, `healthcheck` L287-304, `info` L306-310, `reload` L318-322, `quit` L327-331, `onHardwareEvent` L340-351, `reportHardwareEvent` L359-366.

**Pattern** : `async` + `runSafe` qui **ne throw pas** (L106-115). **Timeouts** : défaut `scanQR`/`readNFC` 15 s (L211, L217) ; spec comment TPE 90 s natif (L228) — **non appliqué dans le service** ; timeout UI TPE dans le composant 120 s. **Stub** : `buildStub` L46-92 si pas `window.borne`.

## 3. Healthcheck baseline

- **Périodique** : `setInterval` **90 000 ms** dans `KioskAppComponent.vue` L731-737 (aligné avec `config/kioskHardware.js` `HEALTHCHECK_INTERVAL_MS` L13).
- **Boot** : `_bootHardware` immédiat + `info()` si critique L711-718.
- **Composants** : interprétation critique TPE/printer, dégradé NFC/camera (`kioskHardware.js` L296-300).
- **Exposition front** : pas de module Vuex `kioskHardware` ; events relayés via `reportHardwareEvent` / axios POST ; **dashboard** : `KioskAdminComponent.vue` `_refreshHealth` / badge L596-611.

## 4. Printer flow actuel

- **Call sites** : `KioskConfirmationComponent.vue` L130, L343-371 ; `kioskPrinter.js` L218 ; `KioskAdminComponent.vue` L508-509.
- **Offline / erreur** : chaîne dans `kioskPrinter.js` L202-253 ; échec → `printFailed` + `reportPrinterFailure`.
- **Affichage écran** : ticket toujours dans le DOM (`#kiosk-print-receipt`) mais **masqué** sauf `@media print` (L626-640) ; numéro toujours visible (L31-35) ; bannière jaune si `printFailed` (L43-47).
- **Email** : aucun endpoint `/email-receipt` ni `OrderReceipt` mail dédié kiosk.

## 5. TPE flow actuel (lecture seule, ATTENTION fiscal)

- **Appel** : `KioskPaymentComponent.vue` `_invokeTpe` L477-478.
- **Timeout** : `TPE_TIMEOUT_MS = 120_000` **en dur** L405-410 (doublon avec config L15 non importée).
- **Catch** : message i18n `tpe_timeout`, toast.
- **Retry** : pas de retry auto ; compteur `paymentFailureCount` vs `MAX_PAYMENT_FAILURES` → écran erreur L371-387.
- **Void / fiscal** : refus TPE → `change-status` status 16 L425-431 ; **`confirmBackendPayment` 3 tentatives** L558-573 — toute évolution = **HARD GATE**.

## 6. Scanner flow actuel

- `scanQR` / `readNFC` : **pas d'usage** dans les composants kiosk.
- **Fallback manuel** : `KioskLoyaltyComponent.vue` saisie code + API `frontend/loyalty/check` — répond au scénario par design actuel.

## 7. Buzzer flow actuel

- Pas d'appel `kioskHardware.play` / `haptic` dans `resources/js/components/frontend/kiosk/`.
- **Commande prête** : `playReadySound()` oscillateurs Web Audio (`KioskWaitingComponent.vue` L311-329) — fallback logiciel si pas de buzzer physique.

## 8. Backend `kiosk-event` endpoint

- **Routes** : `routes/api.php` L971, L1017 (`/kiosk-event` et alias `/kiosk/event`).
- **Contrôleur** : `KioskEventController.php` — validation type whitelist L54-82 ; persistance `ActionLog::create` L224-230 ; types hardware L130-136 ; log canal `hardware` L235-240. **Pas** de modèle `HardwareEvent` ni migration dédiée.
- **Types** : ex. `hardware_health`, `hardware_event`, `hardware_error`, `printer_failure`.

## 9. Tests hardware existants

- **JS** : `tests/js/kioskHardwareService.spec.js`, `kioskPrinter.spec.js`, `kioskPhase6Instrumentation.spec.js`.
- **PHP** : `tests/Feature/KioskEventTest.php` + variantes whitelist/ability.
- **Mocks** : Vitest avec `window.borne` assigné.

## 10. Questions UX dégradé

- **Printer offline** : commande déjà validée ; bannière + pas de blocage navigation timer (relancé dans `finally`).
- **TPE timeout** : pas de retry auto ; message + toast ; au-delà du seuil → écran erreur.
- **Scanner KO** : fidélité reste possible par code manuel.
- **Buzzer** : silence matériel possible ; son Web + UI « PRÊT » compensent partiellement.

## 11. Coordination cross-surface

- **Service partagé** : `kioskHardware.js` importé aussi côté **admin POS** (`PaymentComponent.vue` `openDrawer`).
- **V14** : travaux **POS** distincts (`app/Services/Hardware/`, `posPrinter.js`, `Printer` model) — pile ESC/POS différente du flux kiosk Electron.

## 12. Worktree V14 conflicts

- `git status` : fichiers non suivis liés imprimante POS / `app/Services/Hardware/` — overlap thématique avec T15 ESC/POS mais hors périmètre bridge `window.borne` kiosk.

## 13. Verdict ROUTING par bloc

| Bloc | Scope | Routing | HARD GATE ? | Justification |
|------|-------|---------|-------------|---------------|
| α (printer) | Fallback écran déjà partiel ; email absent | **routine-implementer** si front seul (sans email) | Non | Email = backend out-of-scope ; déférer cycle ultérieur |
| β (TPE) | Dedup constante TPE_TIMEOUT_MS + UX | **routine-implementer** si **strictement** UI + constantes partagées | **Oui** si retry/void/`payment-confirm`/ordre fiscal modifiés | L558-573, L425-431 |
| γ (scanner) | Pas d'usage Vue → no-op | **SKIP** | Non | Manual input déjà fonctionnel |
| δ (buzzer) | Renforcer signal visuel si AudioContext indispo | **routine-implementer** | Non | Pas de séquence fiscale |

## 14. Périmètre EXECUTE proposé (par bloc)

### Bloc α (printer)
- Brancher `KIOSK_HARDWARE.PRINTER_RETRY_*` (config L19-21 non utilisé) dans `kioskPrinter.js`
- Renforcer affichage écran `KioskConfirmationComponent.vue` avec ticket complet (pas seulement bannière)
- Email = DÉFÉRÉ (cycle backend)

### Bloc β (TPE)
- Importer `TPE_TIMEOUT_MS` depuis `config/kioskHardware.js` au lieu du literal L405
- Améliorer copie message timeout
- **NE PAS** toucher `confirmBackendPayment`, void, change-status

### Bloc γ (scanner)
- SKIP (no-op)

### Bloc δ (buzzer)
- Toast / animation visuelle dans `KioskWaitingComponent.vue` `playReadySound()` si `AudioContext` indisponible (try/catch)

## 15. Tests à créer (Vitest mocks)

- `printReceipt` → `none` → `printFailed` (déjà couvert : étendre)
- `kioskPrinter.js` retry × N : nouveaux tests si PRINTER_RETRY_* branché
- `KioskPaymentComponent.vue` import `TPE_TIMEOUT_MS` depuis config (test sentinelle)
- `KioskWaitingComponent.vue` AudioContext null → fallback visuel toast émis
- `KioskConfirmationComponent.vue` ticket fallback affiché si `printFailed`

## 16. Risques découverts (au-delà du plan W7)

- **Double source de vérité** timeout TPE : config vs literal → fix dedup (β)
- **`PRINTER_RETRY_*` en config** non utilisés dans `kioskPrinter.js` → branche dans α
- **V14 POS printer** vs **kiosk bridge** : duplication conceptuelle ESC/POS
- **E_W7B_TPE** : toute modification du void/retry/`payment-confirm` après TPE = escalader **HARD GATE** (fiscal NF525)
- **Email kiosk receipt** : nécessite backend complet — différé cycle ultérieur (recommandation : `P_MEGA_W?_KIOSK_EMAIL_RECEIPT`)
