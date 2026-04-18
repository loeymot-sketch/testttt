# AUDIT_KIOSK_LOYALTY_UPSELL_023 — Loyalty & Upsell côté Kiosk

## Meta
- **Priority** : P2
- **PRIMARY_MODEL** : Claude
- **TEST_STRATEGY** : `static-inspection`
- **DEPENDS_ON** : AUDIT_KIOSK_ORDER_CREATION_016, AUDIT_POS_COUPON_LOYALTY_005
- **Estimation** : 0.5 j-h
- **Vague** : C8

## Contexte

Le kiosk est un levier upsell important (suggestions "ajouter une boisson ?", "menu +2€ ?") et loyalty (opt-in rapide). Risques :
- Upsell agressif qui friction l'UX ("Splash-level" attendu).
- Opt-in loyalty sans consent RGPD clair.
- Prix upsell calculé côté Vue (violation SSOT).
- Points crédités au mauvais moment.

## Questions d'audit

1. Quelle est la logique d'upsell kiosk (algorithme : items complémentaires, most-popular, manual list) ? Server-driven ?
2. Le prix upsell affiché est-il calculé serveur (via `/api/pricing/preview`) ou client ?
3. L'opt-in loyalty kiosk respecte-t-il RGPD : consent explicite, checkbox non pré-cochée, privacy notice accessible ?
4. L'opt-in nécessite-t-il email / téléphone ? Validation serveur ?
5. Les points gagnés s'affichent-ils après paiement ("vous avez gagné X points") ?
6. Le burn de points (réduction sur commande) est-il possible depuis kiosk ou POS only ?
7. Les règles loyalty (ratio, plafond par commande) sont-elles identiques POS/Kiosk ? (symétrie)
8. L'utilisateur kiosk peut-il consulter son solde points en entrant son téléphone/email ? Sécurité ?
9. Les suggestions upsell sont-elles A/B testables (feature flag) ?
10. Le skip upsell (bouton "non merci") est-il immédiat, pas de friction ?

## Scope

### SUBSYSTEMS_TOUCHED
- `resources/js/components/frontend/kiosk/**/*Upsell*`, `*Loyalty*`
- `app/Services/LoyaltyService.php` (si existe)
- `app/Http/Controllers/Frontend/LoyaltyController.php`
- `app/Services/FrontendOrderService.php`

## Invariants at Risk
- [x] Backend pricing SSOT (upsell)
- [x] Symmetry POS/Kiosk (règles loyalty)

## Fichiers à lire
1. Composants upsell / loyalty kiosk
2. `app/Services/LoyaltyService.php` ou équivalent
3. `app/Http/Controllers/Frontend/Loyalty*`

## Grep patterns

```
grep -rn "upsell\|Upsell\|cross_sell" app/ resources/js/
grep -rn "loyalty\|Loyalty\|points" resources/js/components/frontend/kiosk/
grep -rn "opt_in\|consent\|rgpd\|gdpr" app/ resources/js/
grep -rn "suggested_items\|recommendations" app/
```

## Evidence required
- Logique upsell (server-driven vs client).
- Flow opt-in loyalty kiosk + RGPD.
- Cohérence règles loyalty vs POS.
- Parcours skip upsell.

## Grille de verdict
- **PASS** : upsell server, RGPD respecté, parité POS/Kiosk, skip non-friction.
- **WARN** : upsell client mais prix serveur prévaut à la commande.
- **BLOCKED** : prix upsell client pris pour vrai, opt-in sans consent, règles loyalty divergentes.

## Livrable
`reports/review/AUDIT_KIOSK_LOYALTY_UPSELL_023_<DATE>.md`

## Status
- [x] Brief rédigé
- [ ] Plan approuvé
- [ ] Audit exécuté
- [ ] Rapport
- [ ] Tasks correctrices
- [ ] Closed
