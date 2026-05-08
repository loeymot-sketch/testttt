# Plan Design — V1x-3 — Cart image article responsive
**Date :** 2026-05-08 | **Wave :** Gamma G1 | **Type :** Design CSS responsive | **Status :** ⏸️ Plan-only — gate + decision baseline owner-required

> **OWNER GATE EXPLICIT REQUIS** + **DECISION baseline 64 vs 96 vs circle requise** (§2)

## §1 — Constat — DIVERGENCE BRIEF

Brief original disait `width: 96px height: 96px border-radius: 50%` ligne ~895, classe `.kiosk-cart-item-image`.

**Réalité du code** (`KioskCartComponent.vue:683`) :
- Classe : `.kiosk-cart-item-img` (pas `-image`)
- Taille actuelle : **64×64px** (pas 96)
- Border-radius : `10px` (pas `50%`)

```css
.kiosk-cart-item-img {
  width: 64px; height: 64px; border-radius: 10px;
  overflow: hidden; flex-shrink: 0;
  background: var(--kiosk-surface-alt);
}
.kiosk-cart-item-img img { width: 100%; height: 100%; object-fit: cover; }
```

Markup ligne 127 : `<div class="kiosk-cart-item-img" aria-hidden="true">`.

**Implication** : `clamp(96px, 7vw, 144px)` = upscale 50% baseline = **redesign**, pas simple responsivité.

## §2 — Cible — Decision owner-required

### Option A — Préserver visuel + responsivité 4K
```css
.kiosk-cart-item-img {
  width: clamp(64px, 4.7vw, 96px);
  height: clamp(64px, 4.7vw, 96px);
}
```
1080p reste 64 (inchangé), 4K → ~96. **Risk** : zéro régression. Recommandé safe.

### Option B — Brief original (upscale 50%)
```css
.kiosk-cart-item-img {
  width: clamp(96px, 7vw, 144px);
  height: clamp(96px, 7vw, 144px);
}
```
1080p devient 96 (+50% — change visuel). 4K → 144. **Risk** : layout `.kiosk-cart-item-info` doit survivre à moins de place. Audit re-validation requis. **Bénéfice** : visibilité photos + intent UX V1x-3.

### Option C — Brief variante avec border-radius 50% (circulaire)
Idem Option B + `border-radius: 50%`. Test pattern app-style food delivery. Re-validation visuelle.

## §3 — Sub-tasks (post-gate + post-decision)

1. **Modifier `.kiosk-cart-item-img`** (5 min) — width+height selon option choisie. Si B/C : check `.kiosk-cart-item-emoji { font-size: 32px; }` ligne 701 — bump à `clamp(32px, 2.4vw, 48px)`.
2. **Re-audit layout** (20 min) — Si B : `.kiosk-cart-item gap: 14px` (672), vérifier `.kiosk-cart-item-info` flex:1 garde place. Test 1080×1920 portrait : 5 items visibles sans scroll.
3. **Tests Playwright responsive** (20 min) — 1920×1080 width=64 (A) ou 96 (B) ; 3840×2160 width=~96 (A) ou 144 (B).

## §4 — Acceptance

**Si Option A** : 1080p strict 64×64, 4K scale ~96, layout inchangé.
**Si Option B** : 1080p 96×96 (changement assumé), 4K 144×144, cart layout valide, visual diff approved owner.
**Communs** : `aria-hidden="true"` markup conservé, `object-fit: cover` conservé, pas de régression vitest, build OK.

## §5 — Effort

Decision : 5-15min · Sub-1 : 5min · Sub-2 : 20min · Sub-3 : 20min · **Total : ~45min post gate+decision**

## §6 — Tests

```js
test('cart item image responsive', async ({ page }) => {
  await page.setViewportSize({ width: 1920, height: 1080 });
  await page.goto('/kiosk/cart');
  const img = await page.locator('.kiosk-cart-item-img').first();
  expect(await img.evaluate(el => getComputedStyle(el).width)).toBe('64px'); // ou 96 si B
  await page.setViewportSize({ width: 3840, height: 2160 });
  expect(await img.evaluate(el => getComputedStyle(el).width)).toMatch(/9[0-9]|144/);
});
```

## §7 — Frozen-zone

Cart owner-frozen — gate explicit requis. Decision owner-required A/B/C bloquant. Pas d'agent territory.

## §8 — Status

[x] Pending gate + decision · [x] Gate opened (owner explicit "execute tout les plan" 2026-05-08) · [x] **Executed — Option A safe** (commit `7adeaaa9c` `clamp(64px, 4.7vw, 96px)`)

> **Note orientation (V1x-3 footnote)** : Baseline 64px portrait 1080×1920 only ; landscape 1920×1080 → ~90px. Kiosk borne deployment doctrine = portrait. Si déploiement landscape envisagé, re-validation visuelle requise.
