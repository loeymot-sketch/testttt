# RUN — P-MEGA W7.B Hardware fallback EXECUTE (2026-04-20)

**HEAD cible** : `c1832bf77`  
**EXECUTE_DELEGATION** : foodking-routine-implementer  
**Outcome** : PASSED  

## Blocs

| Bloc | Description | Statut |
|------|-------------|--------|
| α | Printer : `kioskPrinter.js` boucle retry via `KIOSK_HARDWARE.PRINTER_RETRY_MAX` + `PRINTER_RETRY_MS` ; confirmation : ticket complet visible si `printFailed` (`data-print-failed`, titre + aide i18n, styles) | OK |
| β | TPE : import `TPE_TIMEOUT_MS` depuis `config/kioskHardware.js` (valeur SSOT **120000**) ; message timeout via `kiosk.payment.tpe_timeout_message` + alignement `kiosk.pay_screen.tpe_timeout` | OK |
| γ | Scanner : **SKIP** — pas d’usage `scanQR`/`readNFC` dans les Vue kiosk ; saisie manuelle fidélité (`KioskLoyaltyComponent.vue`) couvre le scénario (audit baseline §6). | Documenté |
| δ | Waiting : `playReadySound()` async + try/catch ; fallback toast + flash CSS 3s + `haptic('success')` best-effort | OK |

## bug_signatures (post-run)

- (aucun bloquant) — `kiosk.payment.tpe_timeout_message` + `kiosk.pay_screen.tpe_timeout` synchronisés (copie identique) pour éviter messages divergents hors catch timeout.
- (LOW) `de.json` / `bn.json` : titres `kiosk.confirmation.title` hérités FR sur certaines locales (dette i18n pré-existante, hors scope W7.B).

## Tests

- **Vitest** : **685 / 685** (fichiers 87 ; +3 specs nouvelles fichiers +2 dans `kioskPrinter.spec.js`).
- Nouveaux fichiers : `tests/js/kioskConfirmationFallback.spec.js`, `kioskPaymentTpeTimeout.spec.js`, `kioskWaitingAudioFallback.spec.js`.

## Fichiers livrés (scope W7.B)

- `resources/js/helpers/kioskPrinter.js`
- `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue`
- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue`
- `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue`
- `resources/js/languages/fr.json`, `en.json`, `ar.json`, `de.json`, `bn.json`
- `resources/css/kiosk-fallback.css`, `resources/css/app.css` (@import)
- Tests ci-dessus + ce rapport

## i18n

- 4 clés × 5 langues : `kiosk.confirmation.fallback_receipt_title`, `fallback_receipt_help`, `kiosk.payment.tpe_timeout_message`, `kiosk.waiting.ready_visual_fallback` (+ clés `print_failed` / `print_failed_hint` sous `kiosk.confirmation` pour cohérence).

**Note structure** : `kiosk.confirmation` déplacé depuis `kiosk.wizard.confirmation` dans les JSON pour alignement avec les clés `$t('kiosk.confirmation.*')` utilisées par le code.

## Gate

- Aucun `app/**`, `routes/**`, migrations, ni zones TPE interdites (confirm backend / void / compteurs) modifiés.
