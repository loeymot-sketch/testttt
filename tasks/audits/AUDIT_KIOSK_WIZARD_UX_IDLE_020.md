# AUDIT_KIOSK_WIZARD_UX_IDLE_020 — Wizard Kiosk + idle timeout

## Meta
- **Priority** : P1
- **PRIMARY_MODEL** : Claude
- **TEST_STRATEGY** : `static-inspection`
- **DEPENDS_ON** : AUDIT_KIOSK_ORDER_CREATION_016
- **Estimation** : 0.75 j-h
- **Vague** : C5

## Contexte

Le kiosk est tactile, consulté par un client debout, UX critique (niveau Splash attendu). Idle timeout documenté à 180000ms (3 min, `docs/BUSINESS_RULES.md`). Risques :
- Idle timeout efface panier sans avertissement → client frustré.
- Wizard kiosk divergent du POS (même base item, rendu différent) → risque métier si prix diffèrent.
- Options nombreuses → scroll non évident sur écran tactile.
- Retour arrière perd le wizard.

## Questions d'audit

1. Le idle timeout de 180000ms est-il implémenté (timer reset sur touch) et où ? Composant parent ou store ?
2. À l'expiration idle : le panier est-il clear silencieusement ou un overlay de confirmation "Êtes-vous toujours là ?" (5s) donne-t-il le choix ?
3. Le wizard kiosk consomme-t-il les mêmes data items (`/api/frontend/menu`) que le wizard POS ? Même format ?
4. Les règles min/max addons sont-elles identiques à celles du POS (cf audit A6) ?
5. Le "back" pendant le wizard conserve-t-il les choix déjà faits ou reset ?
6. Les images d'items/variations s'affichent-elles en haute résolution sur écran tactile ? Lazy-load pour perf ?
7. Le bouton "Ajouter au panier" respecte-t-il une taille min tactile (≥ 48px) ?
8. La langue affichée (FR/EN/AR) est-elle persistée durant la session kiosk ?
9. L'accessibilité : contrast ratio, audio feedback (tap), reset en haut de page ?
10. Les erreurs réseau (token expiré, 500) sont-elles rendues en overlay convivial ou message technique brut ?

## Scope

### SUBSYSTEMS_TOUCHED
- `resources/js/components/frontend/kiosk/**/*.vue`
- `resources/js/stores/kiosk*.js`
- `app/Http/Controllers/Frontend/MenuController.php` (data wizard)
- `docs/BUSINESS_RULES.md`

## Invariants at Risk
- [x] OrderService / FrontendOrderService symmetry (via structure wizard)
- [ ] Pricing (indirect)

## Fichiers à lire
1. `resources/js/components/frontend/kiosk/` (tout l'arbre, en priorité Wizard / Home / Menu)
2. Stores kiosk
3. `app/Http/Controllers/Frontend/MenuController.php`

## Grep patterns

```
grep -rn "180000\|setInterval\|setTimeout.*idle\|idleTimeout" resources/js/
grep -rn "resetCart\|clearCart\|$reset" resources/js/stores/kiosk*
grep -rn "back\|previous\|goBack" resources/js/components/frontend/kiosk/
grep -rn "i18n\|locale\|$t(" resources/js/components/frontend/kiosk/
grep -rn "aria-\|role=" resources/js/components/frontend/kiosk/
grep -rn "tap\|touch" resources/js/components/frontend/kiosk/
```

## Evidence required
- Implémentation idle timer (extrait Vue).
- Comportement à l'expiration (overlay ou silent clear).
- Comparaison data consommée wizard POS vs Kiosk.
- Règles min/max (source unique ou dupliquée).
- Accessibilité / langue.

## Grille de verdict
- **PASS** : idle avec warning, wizard unifié data-side, accessibilité OK, langue persistée.
- **WARN** : idle silent (UX dégradée) OU pas de warning + erreur réseau technique.
- **BLOCKED** : wizard kiosk divergent (règles min/max différentes du POS), idle absent, erreurs brutes.

## Livrable
`reports/review/AUDIT_KIOSK_WIZARD_UX_IDLE_020_<DATE>.md`

## Status
- [x] Brief rédigé
- [ ] Plan approuvé
- [ ] Audit exécuté
- [ ] Rapport
- [ ] Tasks correctrices
- [ ] Closed
