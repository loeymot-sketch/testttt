# FINDING WEB-1 (P2→healé) — revert multi-sauce incomplet sur la sauce des frites

## Constat (audit code repo Vercel)
`wizard-v2.jsx:259-265` — l'étape `cascade_frites_sauce` (« Sauce pour les frites », menus)
était restée `kind:'multi'` **sans `max`**, sous-titre `'1 gratuite · +0,50 € chaque sauce
supplémentaire'`. Or toutes les sauces sont `price:0` (data OK) → (a) copie mensongère
(+0,50 jamais facturé), (b) multi-select → 422 backend quand l'API sera exposée (même
bug-class que la sauce principale que j'avais revertée, MAIS cette étape avait été oubliée).
La sauce principale (l.97) et bol (l.159) étaient déjà `min:1 max:1 '1 sauce incluse'`.

## Heal (les 2 repos standalone, non-frozen)
`sub: '1 sauce incluse', required: true, min: 1, max: 1,` — aligné sur l.97 + canonique
mobile (menu.js:586 « sauce des frites GRATUITE »). Preuve : les 3 étapes sauce désormais
identiques ; 0 surcharge active restante dans les 2 repos.
Poussé : Site-lecayenne `d14ba56` → Vercel ; miroir `/Downloads/web/` commité.
