# Plan excerpt — T-LOOP-FORMAT-CENTS-001

## Objectif
Helper PUR `posFormatCents.js` + spec Vitest de 12 sentinels.

## Contraintes critiques
- Pas d'Intl, pas de toLocaleString, pas de lib externe.
- Validation ordre : type -> integer -> negative.
- Messages d'erreur EXACTS (les .toThrow('msg') des tests les checkent au mot près).
- Variant 'plain' = point décimal, pas de séparateur de milliers, pas de devise.
- Variant 'display' (défaut) = virgule décimale + NBSP milliers + ' ' + symbole, sauf currency==''.

## Acceptance gate
12/12 PASSED, 0 régression sur les autres specs JS.
