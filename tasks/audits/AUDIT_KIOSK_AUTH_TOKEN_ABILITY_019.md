# AUDIT_KIOSK_AUTH_TOKEN_ABILITY_019 — Auth Kiosk (Sanctum + ability)

## Meta
- **Priority** : P0
- **PRIMARY_MODEL** : Claude
- **TEST_STRATEGY** : `static-inspection`
- **DEPENDS_ON** : —
- **Estimation** : 0.5 j-h
- **Vague** : C4

## Contexte

Chaque borne est authentifiée via un token Sanctum avec ability `kiosk:order`. Ce token :
- doit être scopé branche (une borne branche A ne peut pas créer commande branche B),
- doit être révocable si borne volée/compromise,
- doit limiter les actions (pas d'admin, pas de read autre branche),
- est utilisé aussi par Echo (bootstrap.js Bearer) pour authentifier le subscribe au channel `private-branch.{id}`.

Risques : token permanent jamais rotaté, ability `*` au lieu de `kiosk:order`, token écrit en clair dans fichier Electron.

## Questions d'audit

1. Quel endpoint crée un token kiosk (`/api/kiosk/login`, ~L139 de routes/api.php) ? Authentifié par quoi (PIN / credentials admin) ?
2. L'ability `kiosk:order` est-elle bien attachée au token créé ? Grep `createToken('kiosk', ['kiosk:order'])`.
3. `channels.php` (auth channel `branch.{id}`) vérifie-t-il `tokenCan('kiosk:order')` pour les kiosks ? Lu : oui dans summary.
4. La rotation est-elle supportée (endpoint refresh) ou le token est-il figé à vie ?
5. La révocation distante existe-t-elle (admin bouton "revoke kiosk X") ?
6. Le token est-il stocké de façon sûre côté Electron (keytar / encrypted store) ou en fichier texte ?
7. Les rate limits sur les endpoints kiosk (create order, confirm payment) sont-ils configurés pour protéger contre abuse d'un kiosk compromis ?
8. Le middleware `abilities:kiosk:order` est-il présent sur **tous** les endpoints kiosk mutatifs ?
9. Les logs d'auth kiosk (connexion, création order, échec) sont-ils distinctifs (channel `kiosk` ?) pour audit ?
10. L'expiration du token est-elle configurée ? Renvoi 401 géré en Vue (écran "contactez un responsable") ?

## Scope

### SUBSYSTEMS_TOUCHED
- `app/Http/Controllers/Frontend/KioskAuthController.php` (ou équivalent)
- `routes/api.php` — `/api/kiosk/login`, endpoints kiosk mutatifs
- `routes/channels.php`
- `config/sanctum.php`
- `resources/js/bootstrap.js` — Echo config
- `resources/js/services/kioskAuth.js`

## Invariants at Risk
- [x] branch_id data isolation
- [x] OrderStatus enum (indirect)
- [x] Frozen zone (token design)

## Fichiers à lire
1. `routes/api.php` (kiosk-login L~139)
2. `app/Http/Controllers/Frontend/KioskAuthController.php` ou similaire
3. `routes/channels.php`
4. `config/sanctum.php`
5. `resources/js/bootstrap.js` (Echo)
6. `docs/AUTHZ_MATRIX.md`

## Grep patterns

```
grep -rn "kiosk-login\|kioskLogin\|kiosk/login" routes/ app/
grep -rn "createToken\|->createToken(" app/Http/Controllers/Frontend/
grep -rn "kiosk:order" app/ routes/ resources/js/
grep -rn "tokenCan\|abilities:" app/ routes/
grep -rn "revoke\|deleteToken" app/Http/Controllers/Admin/ app/Http/Controllers/Frontend/
grep -rn "throttle" routes/api.php | grep -i kiosk
```

## Evidence required
- Création token : endpoint + ability + TTL.
- Révocation : endpoint + acteur autorisé.
- Middleware abilities sur chaque endpoint kiosk mutatif.
- Storage token côté Electron (documenté ou inconnu).
- Rate limits configurés.

## Grille de verdict
- **PASS** : token scoped, ability strict, middleware partout, revoke dispo, rate limit, logs audit.
- **WARN** : TTL infini mais révocation existe et storage OK.
- **BLOCKED** : ability wildcard, middleware absent sur au moins 1 endpoint mutatif, pas de revoke, token en clair.

## Livrable
`reports/review/AUDIT_KIOSK_AUTH_TOKEN_ABILITY_019_<DATE>.md`

## Status
- [x] Brief rédigé
- [ ] Plan approuvé
- [ ] Audit exécuté
- [ ] Rapport
- [ ] Tasks correctrices
- [ ] Closed
