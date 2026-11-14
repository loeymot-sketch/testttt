# Plan — MOBILE-EXCELLENCE-PHASE2-IDENTITY-20261016 — 2026-10-16

## TASK_ID
MOBILE-EXCELLENCE-PHASE2-IDENTITY-20261016

## EXECUTION_TIER
complex — identité produit, contact visible et fichier asset partagé.

## PLAN_REVIEW
PLAN_REVIEW_CHANNEL: foodking-complex-implementer (codex-extension-fallback)
PLAN_REVIEW_VERDICT: PASS — corrections de la revue fallback intégrées et relues.
FALLBACK_REASON: codex-extension indisponible après reprises ; le binaire local rejette le modèle legacy et l’usage du compte est limité.

## Fichiers autorisés
- `mobile/data/menu.js`
- `mobile/shared.jsx`
- `mobile/screens-main.jsx`
- `mobile/screens-onboarding.jsx` (remplacement des occurrences de `Logo` par la marque réelle)
- `mobile/assets/brand/lecayenne-logo.webp` (copie byte-identique depuis `public/images/kiosk-attract/logo.webp`, source publique en lecture seule)
- `mobile/tests/mobile-identity.spec.js`
- `mobile/tests/mobile-catalog-visual.spec.js` (exclusion ciblée du téléphone, déjà couvert par le test identité)
- `reports/execution/MOBILE-EXCELLENCE-PHASE2-IDENTITY-20261016/report.md`

## Invariants
- Le backend reste la source de vérité ; aucune règle de prix ou de commande n’est ajoutée.
- Aucun endpoint, auth, paiement, migration ou branche métier ne sera modifié.
- Aucun asset ambigu ne sera utilisé : le logo RotTacos existant reste exclu.
- Le numéro affiché et l’URI `tel:` doivent dériver de `LC.menu.branch.phone`.

## Exécution
1. Copier le logo canonique validé dans l’espace mobile local.
2. Exposer un composant de marque avec fallback textuel sûr.
3. Remplacer les placeholders de téléphone par le numéro de branche formaté et rendre le contact appelable.
4. Ajouter un test autonome de parité logo/téléphone/contact.
5. Vérifier le parcours accueil → profil → contact, les tests et les dimensions visuelles.

## Garde-fous de preuve
- Calculer et consigner le SHA-256 de la source et de la copie ; toute différence arrête l’exécution.
- Le test doit prouver que prix, IDs, slugs, options et payloads du catalogue sont inchangés.
- Le snapshot catalogue exclut uniquement `branch.phone` en plus des chemins visuels ; le test identité verrouille sa valeur canonique et son format.
- Le contact doit prouver le texte visible formaté depuis `LC.menu.branch.phone`, l’URI `tel:` normalisée depuis cette même valeur et l’absence de `06 51 30 XX XX`.
- Le test doit couvrir le fallback textuel du logo si l’image échoue et refuser toute référence à `RotTacos`.
- Le test navigateur utilise un viewport iPhone `390x844`, puis vérifie les sélecteurs `data-screen-label="07 Home"`, `data-screen-label="13 Profile"`, `a[href^="tel:"]` et le nom accessible `Nous contacter`.
- Avant édition, relever les empreintes des quatre fichiers source autorisés et préserver toute modification préexistante sans réécriture globale.

## Validation
- `node mobile/tests/mobile-identity.spec.js`
- `node mobile/tests/menu.spec.js`
- `node mobile/tests/mobile-catalog-visual.spec.js`
- `node mobile/tests/navigation.spec.js`
- `git diff --check`
- navigateur local : accueil, profil, bouton contact et rendu du logo.

## STOP CONDITIONS
- Source du logo non byte-identique ou téléphone DB différent : arrêter.
- Toute nécessité de modifier backend/migration/auth/paiement : escalade vers une nouvelle mission avec gate.
- Collision de réservation ou fichier hors allowlist : arrêter.

## Audit Status
[x] PLAN_REVIEW_VERDICT: PASS
[ ] AUDIT_VERDICT: PASS
[ ] GPT_FINAL_AUDIT_VERDICT: PASS
[ ] CLOSED
