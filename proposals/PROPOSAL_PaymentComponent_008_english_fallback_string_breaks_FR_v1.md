# PROPOSAL — PaymentComponent.vue:779-781 — English fallback string in FR-only V1 product

**ID** : PROP-PAY-008
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/admin/pos/PaymentComponent.vue`
**Frozen reason** : CLAUDE.md §7 "POS payment component, frozen per BRAIN §2 (V1 untouched protected file)"
**Existing LOCK** : plans/LOCK_PAY_PaymentComponent_currency_2026-05-23.md (D3 pending countersign — currency format)

## Finding (read-only audit)

The `alignCashReceivedWithQuotedTotal` method, when the server bumps the total, surfaces an info toast at lines 778-781 :

```js
alertService.info(
    this.$t('pos.cash_received_auto_bumped')
    || 'The server total changed at checkout; the received amount was increased to cover it.',
);
```

The fallback string is **English**, in a V1 product that ships exclusively in French for Le Cayenne (memory bootstrap §1 confirms FR-only V1, AR/EN reserved for V2+). If the i18n key `pos.cash_received_auto_bumped` is missing in `resources/lang/fr/`, the cashier sees the English fallback in a French-locale POS — visible brand inconsistency.

Note also PROP-003 — under default vue-i18n config, `$t()` returns the key string on miss (truthy), so the `||` fallback never fires anyway. So this string is dead-code unless the i18n config is fixed (per PROP-003 Option B). But once fixed, the English string becomes live.

## Reasoning fort (multi-perspective)

### Chef perspective
N/A.

### Client perspective
If the customer leans over and sees `"The server total changed at checkout; the received amount was increased to cover it."` on the cashier screen — confusion. They might think it's a system error message in a foreign language.

### Cashier perspective
Same brand-coherence hit. Cashier reads English in a French app.

### Owner perspective
V1 FR-only mandate. English fallback is a copy-paste artifact from upstream Krishna POS code. Owner has not reviewed every fallback string in the codebase — they expect FR everywhere.

### Multi-tenant-future
For V2 SaaS, this fallback string would be inappropriate in any locale where the locale catalog has missed the key. Could be French, Arabic, or English — fallback should match the configured `fallbackLocale` of `i18n.js` (which is `DEFAULT_LOCALE`, value not shown in the snippet but per code convention likely `fr`).

### Adversarial dispute (challenge yourself)
- **False positive ?** Verified : line 781 is English. **Not a false positive.**
- **Could the key exist ?** Maybe — `grep "pos.cash_received_auto_bumped" resources/lang/fr/*.json` would confirm. If present, fallback is dead-code. If missing, cashier sees raw English.
- **Scope ?** 1 line. Translate the fallback to FR.

## Proposed change

```diff
@@ resources/js/components/admin/pos/PaymentComponent.vue line 778-781 @@
             alertService.info(
                 this.$t('pos.cash_received_auto_bumped')
-                || 'The server total changed at checkout; the received amount was increased to cover it.',
+                || 'Le total côté serveur a changé au moment du paiement ; le montant reçu a été augmenté pour le couvrir.',
             );
```

+0 / -0 LOC change. Pure string content. 1 LOC modified.

**Also recommended** : add the key to `resources/lang/fr/pos.json` (file is NOT frozen) so the proper i18n path is exercised and the fallback truly becomes dead-code.

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Locale key present | Fallback never fires, no behavior change | None |
| Locale key missing | Cashier sees proper FR text | Cashier sees English |
| Other locales (V2) | Need locale-aware fallback in V2 | Same |
| Bundle rebuild | Yes | None |

## LOCK feasibility

- 1 LOC content change : **YES**, easily LOCK-feasible. Smaller change than PROP-002.
- Often bundled into a "polish patch" LOCK with other ≤5-LOC concerns.

## Owner recommendation

[ ] APPLY-WITH-LOCK (translate fallback to FR)
[ ] APPLY-WITHOUT-LOCK (add key to `resources/lang/fr/pos.json`, leave PaymentComponent.vue alone — non-frozen file)
[ ] DEFER-V1.0.2
[ ] DEFER-V2
[ ] KEEP-AS-IS

**Signed-off-by-owner** : ___________  **Date** : ___________
