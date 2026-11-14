# Execution report — MOBILE-EXCELLENCE-PHASE8-PREVIEW-FRESHNESS-20261016

## Scope

Correctif limité au cache des ressources locales du preview mobile. Aucun menu,
prix, image métier, API, paiement, fidélité ou commande n’a été modifié.

Le fichier `mobile/index.html` contient aussi des changements OTP issus de la
phase 4 précédente dans le worktree partagé. Ils sont hors périmètre de cette
phase et n’ont pas été modifiés ici ; la phase 8 ne touche que les suffixes
`?v=20261016` des ressources locales.

## Changes

- Les 24 ressources locales CSS/JS/JSX portent la version `v=20261016`.
- Les CDN React, ReactDOM, Babel et Google Fonts restent inchangés, avec leur
  ordre et leurs `integrity` d’origine.
- `window.LC.config` reste inchangé : API locale `8766`, `branchId=1`, paiement
  en ligne désactivé.
- Un test statique verrouille ces garanties.

## Evidence

```text
node mobile/tests/mobile-preview-freshness.spec.js                         PASS
  24 ressources locales versionnées ; CDN/Fonts/config exacts               PASS
node mobile/tests/mobile-identity.spec.js                                   PASS
  identité Le Cayenne et configuration de branche                           PASS
node mobile/tests/navigation.spec.js                                        PASS
  onboarding → login → OTP → home → menu                                    PASS
npx playwright test tests/mobile-e2e/catalog-visual-critical.spec.js \
  --config=tests/mobile-e2e/playwright.config.js                            2 passed (17.6s)
  ✓ home and menu expose real/neutral assets with compact allergen disclosure removed
  ✓ category → product personalization → cart keeps detail allergen disclosure and images
```

Playwright utilisé le serveur dédié `127.0.0.1:8087` défini par
`tests/mobile-e2e/playwright.config.js`; le preview manuel a été rechargé sur
`http://127.0.0.1:8088/?dev&fresh=20261016`.

Le preview local `http://127.0.0.1:8088/?dev&fresh=20261016` a ensuite été
rechargé avec les ressources versionnées ; l’accessibilité et le rendu affichent
le téléphone canonique `03 65 67 82 91`.

## Execution trace

EXECUTE_DELEGATION: controlled local routine execution
FALLBACK_REASON: routine worker channel unavailable in this session
PLAN_REVIEW_VERDICT: PASS
INVARIANTS_CONSIDERED: local assets only; CDN order/integrity unchanged; runtime
config unchanged; pricing/order/catalogue/payment/loyalty invariants untouched.

## Audit status

AUDIT_VERDICT: PASS — re-audit read-only : références exactes, périmètre et preuve E2E vérifiés
GPT_FINAL_AUDIT_VERDICT: PASS — final scoped review
