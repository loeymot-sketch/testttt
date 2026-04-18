# AUDIT_POS_AUTH_SESSION_REFRESH_008 — Auth POS (Sanctum, session, refresh, logout)

## Meta
- **Priority** : P1
- **PRIMARY_MODEL** : Claude
- **TEST_STRATEGY** : `static-inspection`
- **DEPENDS_ON** : —
- **Estimation** : 0.5 j-h
- **Vague** : A8

## Contexte

Le POS tourne toute la journée (8-16h). Risques : expiration session silencieuse → commande perdue ; logout partiel (token révoqué mais UI active) ; shift de caissier (changement user sans déconnexion) ; CSRF sur endpoints web cookie.

## Questions d'audit

1. Quel mode d'auth POS : session cookie (sanctum SPA) OU token personal access ?
2. La durée de vie session/token est-elle cohérente avec un shift (ex 12h) ?
3. Le refresh / rotation est-il géré automatiquement côté client ?
4. Au 401, le POS redirige-t-il vers login en sauvegardant l'état du panier courant ?
5. Le logout révoque-t-il réellement le token (pas juste clear client) ?
6. Shift change : existe-t-il un "lock POS" rapide (PIN caissier) distinct d'un logout complet ?
7. CSRF protégé sur endpoints state-changing web ? (middleware `VerifyCsrfToken`)
8. Les permissions Spatie sont-elles chargées dans le JWT/session à l'auth initial, ou rechargées à chaque requête ? (perf vs fraîcheur)
9. En cas de désactivation d'un user côté admin, sa session courante est-elle invalidée à la prochaine requête ?
10. L'audit log capture-t-il login / logout / échec login avec IP + user agent ?

## Scope

### SUBSYSTEMS_TOUCHED
- `config/sanctum.php`, `config/auth.php`
- `app/Http/Controllers/Auth/*`
- `app/Http/Middleware/Authenticate*`, `VerifyCsrfToken`
- `resources/js/services/auth*.js`
- `app/Models/User.php` (roles, branch_id)

### SUBSYSTEMS_OFF_LIMITS
- Kiosk auth (audit C4)

## Invariants at Risk
- [x] branch_id data isolation (auth → branch)
- [ ] Autres invariants secondaires

## Fichiers à lire
1. `config/sanctum.php`, `config/auth.php`, `config/session.php`
2. `app/Http/Controllers/Auth/LoginController.php` ou équivalent
3. `routes/api.php` (login / logout)
4. `app/Http/Middleware/`
5. `resources/js/services/auth*.js`, intercepteurs axios
6. `docs/AUTHZ_MATRIX.md`

## Grep patterns

```
grep -rn "sanctum\|Sanctum" config/ app/
grep -rn "tokenCan\|PersonalAccessToken" app/
grep -rn "logout\|revoke" app/Http/Controllers/Auth/
grep -rn "VerifyCsrfToken\|csrf" app/Http/Middleware/
grep -rn "expiration\|lifetime" config/sanctum.php config/session.php
grep -rn "interceptors\|401" resources/js/services/
```

## Evidence required
- TTL session/token documenté.
- Flow 401 → redirect login (code Vue).
- Révocation effective du token au logout.
- Liste des endpoints sans CSRF (doit être justifiée).

## Grille de verdict
- **PASS** : TTL adapté, refresh propre, logout révoque, CSRF sur web, audit log présent.
- **WARN** : audit log manquant OU refresh absent mais durée session large.
- **BLOCKED** : token infini, logout ne révoque pas, pas de CSRF sur endpoints state-changing.

## Livrable
`reports/review/AUDIT_POS_AUTH_SESSION_REFRESH_008_<DATE>.md`

## Status
- [x] Brief rédigé
- [ ] Plan approuvé
- [ ] Audit exécuté
- [ ] Rapport
- [ ] Tasks correctrices
- [ ] Closed
