# PLAN_AUDIT_F014 — TPE Stub QA Toggle
**Severity:** P3 — Couverture QA insuffisante en dev
**Owner agent:** Agent F
**Sprint:** S5

## THINK

[resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:466-469](resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:466) :
```js
if (!kioskHardware.isKioskBridge()) {
  this.tpeMessage = this.$t('kiosk.pay_screen.tpe_browser_sim');
  await new Promise((r) => setTimeout(r, 2000));
  return { approved: true, transaction_id: `STUB-${Date.now()}`, card_type: 'VISA' };
}
```

Stub navigateur retourne TOUJOURS `approved: true`. Le path `KioskErrorPaymentRefusedComponent` (pour declined) n'est **jamais** exercé en dev sans hardware.

## PLAN

Toggle dev (hidden, non-visible en prod) : query param `?tpe_force=declined` ou `?tpe_force=timeout` pour forcer le stub à retourner declined ou timeout.

## BUILD

1. Modifier `_invokeTpe` (lignes 463-492) :
   ```js
   if (process.env.NODE_ENV !== 'production' && new URLSearchParams(window.location.search).get('tpe_force') === 'declined') {
     return { approved: false, error: 'forced_decline_qa', transaction_id: null };
   }
   ```
2. Documenter dans `docs/PLAYWRIGHT_MCP_OPS.md` le toggle.
3. Ajouter test Playwright `kiosk-payment-declined.spec.js`.

## Contraintes
- ✅ Toggle uniquement actif si `NODE_ENV !== 'production'`.
- ❌ Pas de toggle backend — purement frontend dev.

## Decision
`continue`. Faible risque.
