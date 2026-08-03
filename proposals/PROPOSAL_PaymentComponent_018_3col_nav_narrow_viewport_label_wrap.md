# PROPOSAL — PaymentComponent.vue:38-78 + CSS 1144-1147 — 3-col nav on narrow viewport (480px max-width) may wrap "Multi-paiement" awkwardly

**ID** : PROP-PAY-018
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/admin/pos/PaymentComponent.vue`
**Frozen reason** : CLAUDE.md §7 "POS payment component, frozen per BRAIN §2 (V1 untouched protected file)"
**Existing LOCK** : plans/LOCK_PAY_PaymentComponent_currency_2026-05-23.md (D3 pending countersign — currency format)

## Finding (read-only audit)

The modal is `max-w-[480px]` (line 13) and contains a 3-column nav (line 38 — `pos-v5-payment-methods--3col` CSS line 1144-1147 → `grid-template-columns: 1fr 1fr 1fr`). Each tab has icon + label :

- `💵 Espèces` (`label.cash`)
- `💳 Carte (TPE)` (literal `(TPE)`)
- `🔀 Multi-paiement` (fallback) or whatever `label.split_payment` resolves to

With 480px - paddings (~32px) / 3 = ~149px per tab. Subtract icon + gap (≈30px) = ~119px for label.

"Multi-paiement" at the default font-size (`--pos-v5-text-body`, likely 14-16px) measures ~110px in a sans-serif. Tight. On real production locales where the FR label might be longer (`Paiement multiple`, ~120px), or if the OS default font-size is scaled up by user preference, the label wraps to 2 lines.

The CSS at line 1149-1165 (pos-v5-payment-method) sets `min-height: 64px` and `flex-direction: row` — wrapped label would push height past 64px, breaking visual alignment with the other two tabs (single-line `Espèces` and `Carte (TPE)`).

Card label `Carte (TPE)` is also close to the threshold (`label.card` + literal ` (TPE)`, ~80px).

## Reasoning fort (multi-perspective)

### Chef perspective
N/A.

### Client perspective
N/A.

### Cashier perspective
At small viewport / large font-size, visual asymmetry. Confusing momentarily.

### Owner perspective
Polish item. Low frequency in typical 1024-1280px tablet viewports.

### Multi-tenant-future
V2 SaaS — locale variations may have longer labels (Arabic "مدفوعات متعددة" ≈ same width, German "Mehrfachzahlung" ~140px). Risk increases.

### Adversarial dispute (challenge yourself)
- **False positive ?** Probabilistic. Hard to confirm without rendering at multiple viewport/font sizes.
- **Severity ?** Low — happens only at edge-case viewports/fonts.
- **Scope of fix ?** CSS-only : add `min-width: 0` + `text-align: center` + `word-break: break-word` on `.pos-v5-payment-method-label`, OR change the label "Multi-paiement" → shorter "Multi". CSS approach ~3 LOC.

## Proposed change

```diff
@@ resources/js/components/admin/pos/PaymentComponent.vue CSS line 1188-1192 @@
 .pos-v5-payment-method-label {
     font-size: var(--pos-v5-text-body);
     font-weight: var(--pos-v5-weight-bold);
+    line-height: 1.1;
+    text-align: center;
+    word-break: keep-all;
 }
```

OR shorten the literal label fallback :

```diff
@@ template line 76 @@
-                            <span class="pos-v5-payment-method-label">{{ $t('label.split_payment') || 'Multi-paiement' }}</span>
+                            <span class="pos-v5-payment-method-label">{{ $t('label.split_payment') || 'Multi' }}</span>
```

Net : +3 LOC CSS OR -8 chars in template.

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| 1024px viewport | Zero | Zero (label fits) |
| 768px viewport (iPad portrait) | Better fit | Possible wrap |
| Custom large font-size | Better fit | Possible wrap |
| Bundle rebuild | Yes | None |

## LOCK feasibility

- +3 LOC CSS : **YES.** Trivially LOCK-feasible.

## Owner recommendation

[ ] APPLY-WITH-LOCK (CSS hardening — line-height + word-break)
[ ] DEFER-V1.0.2
[ ] DEFER-V2
[ ] KEEP-AS-IS

**Signed-off-by-owner** : ___________  **Date** : ___________
