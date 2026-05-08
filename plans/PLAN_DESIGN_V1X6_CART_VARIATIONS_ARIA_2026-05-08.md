# Plan Design — V1x-6 — Cart variations aria-label complet
**Date :** 2026-05-08 | **Wave :** Gamma G1 | **Type :** Design accessibility | **Status :** ⏸️ Plan-only — owner gate explicit requis (Cart frozen)

> **OWNER GATE EXPLICIT REQUIS** — Cart frozen (1/8 wizard list)

## §1 — Constat — DIVERGENCE BRIEF (mineure)

Brief : "ajouter un computed `fullSelectionsText(item)`".

**Réalité du code** : la fonction **existe déjà** : `getItemSelectionSummary(item)` ligne 434-462 de `KioskCartComponent.vue`. Retourne déjà le texte complet non-tronqué (variations + extras concaténés `·`).

```js
getItemSelectionSummary(item) {
  const parts = [];
  const clean = (s) => sanitizeKioskCustomerFacingText(s);
  if (Array.isArray(item.item_variations) && item.item_variations.length) { /* clean+join */ parts.push(...); }
  if (Array.isArray(item.item_extras) && item.item_extras.length) { parts.push(...); }
  return parts.join(' · ');
}
```

**Ellipsis = pure CSS** (lignes 562-570) :
```css
.kiosk-cart-item-selections {
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%;
}
```

Le DOM contient le texte complet → screen readers déjà accessibles. **Mais** : pas de `:title` tooltip (QA desktop) ni `aria-label` redondant défensif.

## §2 — Vrai problème + scope decisions

L'instruction (`item.instruction`) est dans un élément séparé `.kiosk-cart-item-note` (ligne 157-161), aussi avec ellipsis (CSS lignes 739-746).

**Decision owner requise** : instruction concat dans selections aria, ou élément séparé aria distinct ?

**Recommandation default** : élément séparé. `.kiosk-cart-item-note` reçoit son propre `:title`+`:aria-label` via `displayCartInstruction(item)` existant.

### Decision A — minimale (3 lignes)
Ajouter `:title` + `:aria-label` sur `.kiosk-cart-item-selections` seul :
```html
<div v-if="getItemSelectionSummary(item)"
     class="kiosk-cart-item-selections"
     :title="getItemSelectionSummary(item)"
     :aria-label="getItemSelectionSummary(item)">
  {{ getItemSelectionSummary(item) }}
</div>
```

### Decision B — extensive (9 lignes)
Étendre symétrique à `.kiosk-cart-item-name` (ligne 135, computed `displayCartItemName`) et `.kiosk-cart-item-note` (ligne 158, `displayCartInstruction`) avec `:title` + `:aria-label`.

### Decision C — concat full
Computed `fullSelectionsTextWithInstruction(item)` qui inclut summary + instruction. Cohérence sémantique mais redondant.

## §3 — Sub-tasks (post-gate)

1. **Decision owner** A/B/C
2. **Modifier template** (10 min Decision A, 30 min Decision B+C) — lignes 135 / 150-156 / 157-161. Pattern `:title + :aria-label` réutilisant méthodes existantes.
3. **(Si C uniquement)** ajouter computed (~ligne 463) + i18n key
4. **Tests a11y** (30 min) — axe-core automated si dispo · Manual NVDA/VoiceOver · Vitest unit assert texte non-tronqué dans aria-label

## §4 — Acceptance

- [ ] Decision owner formalisée
- [ ] `:aria-label` ajouté `.kiosk-cart-item-selections` minimum
- [ ] `:title` ajouté pour QA desktop tooltip
- [ ] Texte ellipsis visuel **inchangé** (pas de modif CSS)
- [ ] Screen reader (manual) lit contenu complet
- [ ] axe-core (si dispo) : zéro nouvelle violation
- [ ] Pas de régression vitest

## §5 — Effort

Decision : 5-15min · Sub-2 : 10min (A), 30min (B), +20min (C) · Sub-3 : 30min · **Total : 45min — 1h post gate+decision**

## §6 — Tests

```js
test('selection text fully present in aria-label', () => {
  const item = {
    item_id: 1, name: 'Big Burger',
    item_variations: [{ name: 'XL' }, { name: 'Sauce BBQ' }],
    item_extras: [{ name: 'Bacon' }, { name: 'Cheese' }, { name: 'Onion rings' }],
  };
  const wrapper = mount(KioskCart);
  const span = wrapper.find('.kiosk-cart-item-selections');
  expect(span.attributes('aria-label')).toContain('XL, Sauce BBQ');
  expect(span.attributes('aria-label')).toContain('Bacon, Cheese, Onion rings');
  expect(span.attributes('title')).toBe(span.attributes('aria-label'));
});
```

## §7 — Frozen-zone

Cart owner-frozen — gate explicit requis. Modif template seule (pas de logique). Decision A/B/C bloquante.

## §8 — Bonus extension (post-V1)

Pattern réplicable :
- `FrontendCartComponent.vue` : même pattern ellipsis probablement
- `TableCartComponent.vue` : idem
- `PaymentComponent.vue` admin : potentiel

V1.x+1 si owner valide ROI a11y.

## §9 — Status

[x] Pending gate + decision · [x] Gate opened (owner explicit "execute tout les plan" 2026-05-08) · [x] **Executed — Decision B extensive** (commit `7adeaaa9c` — 3 templates `:title` + `:aria-label` sur `.kiosk-cart-item-name` + `.kiosk-cart-item-selections` + `.kiosk-cart-item-note`)
