# Codex C0 Execution — Kiosk Auto Return — 2026-04-27

## Verdict

`C0_VERDICT: PASS`

Le blocage observe sur `/kiosk/waiting/{order}` apres paiement simule est corrige.

## Changements appliques

- `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue`
  - Si une commande est deja payee (`payment_status=PAID`) ou en paiement comptoir (`PENDING_COUNTER`) et que la cuisine n'a pas encore demarre (`status < PREPARING`), la borne route vers `kiosk.confirmation`.
  - Le flux cuisine conserve le comportement existant : `PREPARING+` reste sur waiting, `PREPARED/DELIVERED` utilise l'ecran pret.
  - Le store `kioskCart` est realigne avec `SET_ORDER_REF` avant navigation, pour satisfaire le guard `kiosk.confirmation`.

- `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue`
  - Countdown configurable via `window.foodkingConfig.kioskConfirmationAutoReturnSeconds`.
  - `goHome()` reset explicitement le panier avant retour `kiosk.idle`.
  - Auto-print limite au bridge kiosk reel. Le fallback navigateur `window.print()` ne se lance plus automatiquement, car il peut suspendre les timers en paiement simule/dev.
  - Le timer n'est plus stoppe par `printReceipt()`.

- `config/kiosk.php` + `resources/views/master.blade.php`
  - Ajout de `KIOSK_CONFIRMATION_AUTO_RETURN_SECONDS` avec defaut 30.
  - Injection runtime `kioskConfirmationAutoReturnSeconds`.

- `resources/js/router/modules/kioskRoutes.js`
  - Correction du guard `requireKioskAuth` : suppression du melange `async function` + callback `next()`.
  - Sans ce correctif, une session borne fraiche peut rester sur une page blanche avant meme de poller la commande.

- Tests ajoutes :
  - `tests/js/kioskWaitingAutoReturn.spec.js`
  - `tests/js/kioskConfirmationCountdown.spec.js`
  - `tests/e2e/kiosk-post-payment-auto-return.spec.js`

## Validation

```bash
npm run test -- tests/js/kioskWaitingAutoReturn.spec.js tests/js/kioskConfirmationCountdown.spec.js tests/js/kioskConfirmationFallback.spec.js tests/js/kioskWaitingAudioFallback.spec.js
# PASS: 4 files, 6 tests

npm run dev
# PASS: Laravel Mix compiled successfully

npx playwright test tests/e2e/kiosk-post-payment-auto-return.spec.js --project=chromium --repeat-each=5 --retries=0
# PASS: 5/5
```

Verification browser-use sur le scenario reel ouvert :

```text
http://127.0.0.1:8000/kiosk/waiting/33?queue=A0005&total=8
after 5s  -> /kiosk/confirmation?number=A0005&total=8
after 40s -> /kiosk/idle
```

## Invariants controles

- Pricing SSOT : aucun calcul de prix ajoute cote frontend.
- Order lifecycle : aucune mutation backend de status/payment_status ajoutee.
- Branch isolation : aucun acces inter-branch ajoute.
- NF525 : aucune allocation fiscale modifiee ; impression navigateur auto desactivee uniquement hors bridge reel.
- KDS/POS sync : le flux KDS reste intact pour `PREPARING+` et `PREPARED/DELIVERED`.

## Suite orchestree

`C1` et `C2` peuvent demarrer apres ce PASS :

- `C1 KIOSK_FULL_PROCESS`
  - Card simple.
  - Composition tacos.
  - Cash-at-counter.
  - Rupture pendant wizard.
  - Abandon/idle reset.

- `C2 POS_FULL_PROCESS`
  - Walk-in caisse.
  - A emporter.
  - Livraison avec geocodage.
  - Encaissement comptoir confirm.
  - Annulation comptoir sans fiscalisation.

Critere recommande : chaque scenario critique en `--repeat-each=5`, puis C3 sync cross-channel.
