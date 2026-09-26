# QA audit — fidélité borne — 2026-09-25

## Périmètre audité

- Inscription rapide depuis un numéro inconnu.
- Affichage du solde fidélité et erreur pour code inconnu.
- Consentement, confidentialité des conflits e-mail/téléphone et conservation des données client.
- Stabilité du test E2E face au rate limiting anti-énumération.

## Résultat

**VERDICT: PASS pour le parcours fidélité borne audité.**

Le défaut observé pendant la campagne n’était pas un défaut applicatif : le quatrième contrôle
`/frontend/loyalty/check` de la même borne de test recevait légitimement `429` après les essais
antérieurs. Le routeur conserve `auth:sanctum` et `throttle:10,1`; aucune limite production n’a
été modifiée.

Le scénario du pavé tactile intercepte désormais uniquement son propre `404` déterministe. Un
autre scénario de la même campagne garde l’appel réel et démontre la transition serveur
`404 → inscription rapide`. La sécurité et l’interaction tactile sont donc contrôlées séparément,
sans faux vert et sans contournement du limiteur.

## Preuves exécutées

- `npm run production` : PASS.
- Vitest : 4 fichiers / 22 tests PASS (inscription rapide, état sans page blanche, consentement,
  renouvellement de session borne).
- Playwright Chromium dédié : 5/5 PASS : solde réel API, code inconnu, numéro inconnu vers
  inscription, numpad auto-submit au 10e chiffre, inscription sans écran blanc.
- PHPUnit ciblé : 27 tests PASS : doublon e-mail, variantes de téléphone, absence de fuite PII,
  consentement, e-mail de borne, cycle de crédit des points.
- `git diff --check` : PASS.

## Revue de risque

- **Prix / fiscalité :** non modifiés par cet ajustement de test.
- **Auth / anti-énumération :** PASS — le throttle serveur reste en place et est la cause
  attendue du 429 observé.
- **PII :** PASS — le numéro est seulement conservé dans le parcours de l’utilisateur; les
  conflits ne divulguent ni code ni téléphone d’un tiers.
- **État borne :** PASS — un code inconnu reste sur une erreur visible, une inscription réussie
  bascule vers un solde utilisable, sans état vide.

## Action suivante

Poursuivre l’audit global du cycle prix/POS/KDS séparément. Aucun rework fidélité borne n’est
requis sur les preuves actuelles.
