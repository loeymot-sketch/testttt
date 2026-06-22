# PLAN — T-LOOP-FORMAT-CENTS-001 (2026-04-23)

## Objectif
Compléter `resources/js/helpers/posCentsArith.js` par un helper de **formatage** : convertir un montant en cents (int ≥ 0) en chaîne d'affichage stable, NF525-friendly, utilisable côté POS / receipts / kiosk.

## Pourquoi (contexte court)
- L'arithmétique cents est centralisée (SSOT) dans `posCentsArith.js`. L'**affichage** ne l'est pas : duplication probable dans Receipt/Payment/Floorplan.
- Besoin : un helper pur, sans dépendance, déterministe, qui ne fasse PAS d'arrondi (le caller doit fournir un `int` valide), et qui supporte deux variantes : `display` (locale FR par défaut, virgule décimale + espace insécable + symbole devise optionnel) et `plain` (sans symbole, séparateur point — pour ESC/POS / logs / sentinels de tests).

## Périmètre (SUBSYSTEMS_TOUCHED)
- `resources/js/helpers/posFormatCents.js` (création)
- `tests/js/posFormatCents.spec.js` (création)

**Hors-scope** (escalation si touché) : composants Vue, ESC/POS template, PaymentComponent, ReceiptComponent, posCentsArith.js (modif). Aucun changement backend, aucun changement schéma, aucune migration.

## PRIMARY_MODEL
`gpt-5.4` via **`codex-terminal`** (PRIMARY) — `npm run codex:complex -- T-LOOP-FORMAT-CENTS-001`. Fallback `foodking-complex-implementer` uniquement si proxy KO ≥3 reprises.

## Test strategy (VALIDATE)
Vitest : `npx vitest run tests/js/posFormatCents.spec.js`. Cible : 100% des sentinels passent (≥10 cas), aucune régression sur les autres specs JS.

Sentinels minimum :
1. `formatCents(0)` → `"0,00 €"` (locale FR, défaut)
2. `formatCents(199)` → `"1,99 €"`
3. `formatCents(123456)` → `"1\u00a0234,56 €"` (NBSP entre milliers)
4. `formatCents(1, { currency: 'EUR' })` → `"0,01 €"`
5. `formatCents(100, { currency: '' })` → `"1,00"` (sans devise si chaîne vide)
6. `formatCents(100, { variant: 'plain' })` → `"1.00"` (point décimal, pas de devise, pas de NBSP)
7. `formatCents(123456, { variant: 'plain' })` → `"1234.56"` (pas de séparateur de milliers)
8. `formatCents(50, { currency: 'USD', symbol: '$' })` → `"0,50 $"` (symbole forcé, locale FR pour décimale)
9. `formatCents(-1)` → throws `RangeError` ("cents cannot be negative")
10. `formatCents(1.5)` → throws `RangeError` ("cents must be an integer")
11. `formatCents("100")` → throws `TypeError` ("cents must be a finite number")
12. `formatCents(null)` → throws `TypeError`

## AUDIT criteria
- Helper PUR (pas d'I/O, pas de Date.now, pas de Math.random)
- Pas d'`Intl` runtime requis (gère le formatage à la main pour fiabilité ESC/POS et déterminisme cross-locale)
- Pas d'`import` autre que ce qui est strict (idéalement zero-import)
- ESM `export function` + `export default { ... }` (cohérence avec posCentsArith.js)
- Validation entrée stricte : `TypeError` pour mauvais type, `RangeError` pour valeur impossible
- Tests : 12/12 PASSED

## Cross-agent reservation
Réservation : `cursor-claude` réserve `resources/js/helpers/posFormatCents.js,tests/js/posFormatCents.spec.js`.

## RUNNER_MODE
`single-session` — boucle automatique jusqu'à CLOSED ou GATE.
