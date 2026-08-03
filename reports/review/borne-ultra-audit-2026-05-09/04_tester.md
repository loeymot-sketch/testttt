# 04 — TESTER — Vitest + PHPUnit + Playwright Borne coverage

**Verdict local** : ✅ **GO-WITH-V1.0.1-WORK**
**Anchors** :
1. `kiosk.promo` collapsed regression (insights report 2026-05-09) — automated guard ?
2. E2E Expo/Playwright flakiness (insights report) — root cause concrete ?

---

## Findings (5)

### [P1] [V1.0.1] `kiosk.promo` collapsed regression — no automated guard

`FinalizePromotionGuardTest.php:140-206` couvre state machine whitelist pour
`finalizePaidKioskOrder()`, **pas UI rendering contract**. Aucun test n'asserte que kiosk
promo block reste visible/expanded. Régression caught par insights probe (pre-commit), pas
par continuous-run guard.

**Gap** : `tests/js/kioskCartPromo.spec.js:134` check empty code validation seulement, pas
DOM visibility.

- `tests/Feature/Kiosk/FinalizePromotionGuardTest.php:140-206`
- `tests/js/kioskCartPromo.spec.js:134`

**Fix** : ajouter Vitest spec asserting `<KioskPromoCarouselComponent>` rendered + non-empty
in default state.

### [P2] [V1.x backlog] E2E selector flakiness — text content + nth-child still active

Concrete examples :

- `tests/Playwright/critical-flow/v1-ingredient-rupture-propagation.spec.js:35` —
  `.filter({ has: page.locator('th p.text-sm.font-semibold').getByText() })` (text content selector)
- `tests/e2e/audit-kiosk-ux-2026-05-07.spec.js:44-46` — `page.locator('body').innerText()`
  pour parsing Vuex store

**Stable counter-example** : `tests/e2e/helpers/login.js:9-42` use
`getByRole('button', { name: /login|connexion/i })` (regex stable).

**Root cause** : audit helpers parse `innerText` instead of storageState fixtures.

**Fix V1.x** : migration storageState fixtures + `data-testid` attributes (recommandation
Anthropic Claude Code Insights report directe).

### [P2] [V1.0.1] Soft-check fake test — no behavioral assertion

`tests/e2e/kiosk-edge-cases.spec.js:101` contient `expect(true).toBe(true)` après offline
network test avec comment "assertion soft : test doc le flow". Ne valide pas behavior.

**Same test** lacks storageState fixture pour kiosk session persistence.

- `tests/e2e/kiosk-edge-cases.spec.js:101`

**Fix** : supprimer le soft-assert OU ajouter assertion réelle sur localStorage state.

### [P1] [V1.0.1] Offline queue coverage present but stability unverified

Tests existent :
- `tests/js/kioskOfflineQueue.spec.js` (unit)
- `tests/Playwright/kiosk-offline-waiting.spec.js` (E2E)
- `tests/e2e/audit-kiosk-cycle5-2026-05-07.spec.js` (integration)

**Gap** : aucune validation idempotency replay sous failure simulation (network flap → partial
send). E2E offline queue test use `page.context().setOffline(true)` mais pas d'assertion sur
pending localStorage queue merge post-reconnect.

- `tests/js/kioskOfflineQueue.spec.js:32-47`

### [P0→P1] [V1.0.1] Multi-variation + payment refusal coverage gaps

`KioskFullFlowE2ETest.php:37-100` seeds variations (✓), mais :

- **Aucun test valide payment refusal flow** (payment gateway decline → user retry).
- `tests/e2e/kiosk-edge-cases.spec.js:56-58` référence scénario `?tpe_force=declined` mais
  pas d'assertion (soft-check).
- Multi-variation submit gap : aucun test confirms toutes variations persisted dans final
  order payload.

**Strong counter-example** : FR-locale enforcement strong (`KioskLocaleMiddlewareTest.php:86-178`).

⚠️ **Severity downgraded** P0→P1 post-review : c'est un coverage gap (test debt), pas un
feature gap (la logique backend payment refusal existe).

- `tests/Feature/KioskFullFlowE2ETest.php:37-100`
- `tests/e2e/kiosk-edge-cases.spec.js:56-58`

---

## Anchor — `kiosk.promo` regression class status

**Question** : la régression class a-t-elle automated guard sur HEAD ? **NO.**

| Layer | Coverage |
|---|---|
| State machine guard | ✅ `FinalizePromotionGuardTest.php` whitelist |
| Empty code validation | ✅ `kioskCartPromo.spec.js:134` |
| **DOM rendering visibility** | ❌ **GAP** — no test |

C'est ce gap qui a permis à la régression "promo block collapsed" d'apparaître ; elle a été
caught par probe pre-commit, **pas par test continu**.

---

## Méta-result Tester

- Aucun blocker V1 strict (les coverage gaps ne bloquent pas si feature works)
- 5 items V1.0.1 priorité moyenne (test debt)
- Recommandation Anthropic insights confirmée : storageState + data-testid migration

---

## ⚠️ POST-RÉCONCILIATION CAVEAT

POS adversarial audit a découvert des **fake E2E** plus systémiques (P0-13/14) :

- `02-pos-cash.spec.js` "full POS cash order cycle" : 0 `.click` / 0 `.fill` / 0 wizard / 0 payment / 0 DB assertion. Pattern same dans `05-pos-card.spec.js`, `03-kiosk-wizard.spec.js`, `04-kds-status.spec.js`. **"16/16 E2E green" est smoke test, pas end-to-end.**
- `tests/js/posKioskVariationParity.spec.js` sentinel compare fixtures à elles-mêmes, **never invokes real `PricingService`**.

L'audit Tester présent n'a **pas** detecté ces fake E2E parce que mon scope a focalisé sur
`/kiosk*` files et anchors specifiques (`kiosk.promo`, offline queue), pas sur scrutin
systématique de tous les `.spec.js` POS+Kiosk pour fake-test patterns.

**Méthodologie gap reconnu** : framing adversarial "prouve que les 16/16 E2E sont fake" aurait
caught ça.
