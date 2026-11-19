# Espace roue — test E2E + optimisation (2026-11-19)

Surface : `/admin/roue/espace` (8 rubriques), accès depuis la caisse (Espace roue, Avis & réseaux,
Hygiène), roue publique (`/api/frontend/wheel/config`), borne (`/admin/roue-borne`).

## Rounds

| Round | Banc E2E | Caisse | Relecture adverse | P0 | P1 |
|---|---|---|---|---|---|
| 1 | 8/8 | 1/1 | 15 PNG + code | 0 | 4 |
| 2 | 8/8 | 1/1 | 15 PNG + code, clôtures | 0 | 0 |
| 3 | 9/9 (+ réponses 4xx/5xx, + coupure réseau / menu clavier) | 1/1 | — | 0 | 0 |

Convergence : rounds 2 et 3 à P0+P1 = 0 ; les P2/P3 du round 2 (A-007, A-008, A-023, A-024, A-026)
sont corrigés au round 3. Restent assumés : A-015 (onglet bloqué → la caisse navigue, décision
documentée d'août), A-017 (12 px), A-022 (le graphique annonce lui-même « 30 derniers jours »).

## Optimisation mesurée (requêtes SQL par appel)

| Appel | Avant | Après | Lectures des réglages |
|---|---|---|---|
| `/admin/roue/espace/donnees` | 89 | 58 | 47 → 16 |
| `/api/frontend/wheel/config` (roue client) | 59 | 43 | 32 → 16 |
| `/admin/roue-borne` | 75 | 46 | 33 → 4 |

Cause : chaque lot relisait le catalogue (2 lectures du groupe `wheel`) pour trouver son produit de
référence. Désormais le catalogue est lu une fois par appel et transmis (`lectureCatalogue()`,
`costItemId($cle, $catalogue)`). Sentinelle : `test_les_reglages_ne_sont_pas_relus_une_fois_par_lot`
(ancien code : 14 lectures pour 2 lots, 54 pour 12 → rouge).

Côté navigateur : le rafraîchissement automatique ne redessine plus que la rubrique affichée (les 7
autres à la demande), la liste des lots ne clignote plus et n'est plus chargée deux fois après une
remise.

## Instruments prouvés (restauration du défaut → rouge)

- PHPUnit : 4 nouveaux tests (lectures par lot, fenêtre de la liste, expirés exclus, échéance).
- Playwright : « coupure réseau… menu mobile au clavier » rouge sur la page d'origine.
- Banc : n'écrit plus de sortie de stock (lot semé sans produit) ; `stock_outflows` max id inchangé.
