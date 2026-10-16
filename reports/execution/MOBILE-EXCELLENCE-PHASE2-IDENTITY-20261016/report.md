# Rapport intermédiaire — MOBILE-EXCELLENCE-PHASE2-IDENTITY-20261016

Date : 2026-10-16  
Délégation : `foodking-complex-implementer (codex-extension-fallback)`  
Fallback : binaire codex-extension indisponible après reprises ; modèle legacy refusé et usage limité.

## Périmètre exécuté

- Logo canonique copié de `public/images/kiosk-attract/logo.webp` vers `mobile/assets/brand/lecayenne-logo.webp`.
- Composant `Logo` branché sur l’asset réel avec fallback textuel si l’image échoue.
- Téléphone de branche corrigé dans `mobile/data/menu.js` à `0365678291`.
- Téléphone affiché dérivé de `LC.menu.branch.phone` sur l’accueil et le profil.
- Ligne « Nous contacter » rendue appelable via `tel:0365678291`.
- Aucune modification backend, migration, auth, paiement, prix, slug, ID ou payload métier.

## Empreintes de départ préservées

Empreintes relevées avant édition des quatre sources déjà modifiées par les missions précédentes :

```text
mobile/data/menu.js          9e472ed7c1da21df7c2e68cee278a1771846076bc98aa6cd55800a36a1f6f64d
mobile/shared.jsx            23f653360ff5e7424125206015b68640873a08831aa027b5b561c0d1c8490ab0
mobile/screens-main.jsx      5976aac533368ad8f2c0ef89188ff72feb26fd7e5b1ea3ef45ae550ffb78038b
mobile/screens-onboarding.jsx 66549e4b3ce2490c5ef7e5bb62716802c6b57ff4fee7d2e62f49174742a4f1a7
```

Logo source et copie : SHA-256 `f864bbe05441084e54bffbbccdf85b8bbbc7799e0659d38638ec17e54cc1d2fb`; `cmp` byte-identique.

## Tests locaux

- PASS — `node mobile/tests/mobile-identity.spec.js`
- PASS — `node mobile/tests/menu.spec.js` (39 produits, 9 catégories, prix métier inchangés)
- PASS — `node mobile/tests/mobile-catalog-visual.spec.js` après réparation ciblée : le snapshot exclut uniquement `image`, `hero` et `branch.phone`. La valeur du téléphone reste verrouillée séparément par `mobile-identity.spec.js`.
- PASS — `node mobile/tests/navigation.spec.js`
- PASS — `node --check mobile/data/menu.js`
- PASS — `git diff --check`

Le test identité utilise un snapshot métier indépendant du téléphone et confirme le digest `dba1d13ba410398c0429ad164d937e28cffb91336cf176b0d384c20b313ae91d` pour les données hors images/téléphone.

Le plan amendé contient désormais `mobile/tests/mobile-catalog-visual.spec.js` dans l’allowlist et documente cette exclusion ciblée. Aucune donnée produit n’a été modifiée.

## Vérification navigateur exécutée

URL locale fraîche : `http://127.0.0.1:8093/?dev&v=phase2-identity-clean`  
Viewport : `390x844`  
Mode : OTP de développement local, pas un SMS réel.

- PASS — onboarding frais atteint ; le vrai logo Le Cayenne est visible, avec son texte alternatif accessible.
- PASS — le formulaire d’authentification accepte un faux numéro local et passe à l’étape code ; aucun SMS réel n’a été envoyé.
- BLOCKED/OBSERVATION — le backend local répond « Trop de tentatives » après soumission du code de test sur les numéros locaux essayés (`0666666666`, puis `0677777777`). Ce blocage ne permet pas de reproduire indépendamment le retour vers l’accueil/profil sur cette instance fraîche ; il doit être traité dans une phase auth séparée avant la soumission Apple.
- PASS (preuve précédente de l’agent, instance `8088`) — accueil atteint ; logo réel chargé (`1916x524`) ; téléphone `03 65 67 82 91` visible.
- PASS (preuve précédente de l’agent, instance `8088`) — profil atteint ; une ancre `a[href^="tel:"]` présente, href exact `tel:0365678291`, texte « Nous contacter — 03 65 67 82 91 », ancien placeholder absent et aucune erreur console/page.

## État de validation

La réparation de validation est vérifiée : les quatre tests ciblés et `git diff --check` passent. Le parcours navigateur précédent reste documenté ci-dessus. Les audits Claude/GPT final et la clôture formelle du cycle restent distincts ; aucun verdict de soumission Apple n’est déclaré ici.
