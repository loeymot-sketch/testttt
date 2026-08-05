# T-5.2 — Audit e2e visuel site web complet : CONVERGÉ (2026-08-05)

**Boucle** : 4 rounds, 27 états/round (16 vitrine-wizard + 11 panier→mentions, desktop 1366 + mobile 390), 100 % lecture seule (0 POST, 0 commande, 0 paiement — manifests le prouvent).

| Round | P0 | P1 | Action |
|---|---|---|---|
| 1 | 0 | 4 | F1 tacos-crudités, F2 légumes payants absents, F3 pas de « Sans crudités » (fix web c8d0424..c19e35c) + ADV-01 boîtes Mollie invisibles (CSS ciblait `input`, les composants Mollie sont des iframes — fix styles-v4.css) |
| 2 | — | — | Re-capture intermédiaire (pré-fix ADV-01) |
| 3 | 0 | **0** | 4 fixes confirmés visuellement ; P2=6, P3=8 |
| 4 | 0 | **0** | **SET-EQUALITY vs round 3 : IDENTIQUE (17/17)** → CONVERGÉ |

**Confirmé aux rounds 3+4** : tacos SANS étape crudités ; Poivrons cuits/Maïs/Olives +0,90 € badge jaune + chip « Sans crudités » ; 4 boîtes carte Mollie visibles ; totaux exacts (7,40+2,50=9,90 ; 6,90+2,50=9,40) ; 0 fuite i18n ; 0 console/réseau ≥400 sur 108 captures cumulées.

**Non-bloquants divulgués (P2×6)** : ADV-02 étapes boisson/sauce-frites non capturées par l'audit ; ADV-03 « 38 résultats » vs 28 cartes rendues mobile ; ADV-04 résumé de ligne panier sans sauce de base/pain ; ADV-05 redirection suivi-sans-id muette ; ADV-06 CTA « Se connecter » tronqué bas de modale ; ADV-07 téléphone type=text + 4 aria-label manquants. P3×8 cosmétiques au JSON.

**Commits web (Site-lecayenne, LOCAUX, gate deploy)** : c8d0424, 96cfa6a, b3994c3, c19e35c, + fix Mollie CSS. Web : 24/24 + 17/17 + 13/13 verts.
