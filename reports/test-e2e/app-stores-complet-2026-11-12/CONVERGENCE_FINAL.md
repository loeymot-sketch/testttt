# CONVERGENCE FINALE — audit stores complet de l'application Le Cayenne (2026-11-12)

**Convergence atteinte au tour 5.** Tours 4 et 5 : **0 P0 / 0 P1 ouverts** dans les deux vagues adverses
(F : parcours ; B : tous les boutons + visuel). Ensemble bloquant identique (vide) deux tours de suite.

- Site / application : dépôt `lecayenne-web-deploy/site-deploy-2026-11-03`, branche `app/catalogue-distant-2026-11-05`,
  **HEAD `18420b0`** (paquet `app/www` reconstruit, `check:www` vert, portes `release-gates --prod` 9/9).
- Backend : `testttt`, branche `pos/category-first-caisse-2026-06-23`, **HEAD `da8902257`**.
- Artefacts Android (non signés) : `Le-Cayenne-android-release-NON-SIGNE-2026-11-12-18420b0.aab`,
  `Le-Cayenne-android-test-2026-11-12-18420b0.apk` (fr.lecayenne.app, 1.0.0, build 2 ; plus de permission biométrie /
  empreinte / comptes — vérifié `aapt2 dump permissions`).

## Preuves (dernier état)
| Instrument | Résultat |
|---|---|
| Oracle app ↔ devis serveur (`tools/oracle-app-vs-serveur.mjs`) | 147/148 (seul échec : Coca-Cola en rupture dans la base locale) |
| verify-app-behaviour (pont Capacitor) | 55/55 |
| verify-catalogue-distant --prod | 42 produits, 10 catégories, 5 Tenders + Sandwich 2 Viandes affichés, 0 erreur JS |
| Unitaires site | 58/58 |
| Parcours réels (tour 5, compte neuf) | inscription → profil → fidélité → commande payée au comptoir → confirmation → suivi → Mes commandes → suppression ; 14,20 € partout jusqu'en base ; double toucher = 1 commande |
| Robot « tous les boutons » | 484 états, 725 clics, 0 bouton mort, retour Android 16/16, hors ligne A–D |
| Bandeau hors ligne + fenêtre ouverte | 72 états, 360 → 1024 px, encoche : 0 % de recouvrement |
| PHPUnit (safe-test) | Auth/Apple 39 · Livraison 357 · Menu + Sentinelles 601 — verts |

## Restes NON bloquants (P2/P3, divulgués)
- S1-R1 (P1 « partiel », action PROPRIÉTAIRE) : la révocation « Se connecter avec Apple » est codée et testée, mais
  inactive tant que la clé `.p8` n'est pas posée en production (`PUBLICATION.md §12`). **Ne pas envoyer à Apple avant.**
- S3-03 : si l'estimation d'attente échoue durablement, « Dès que prêt » reste proposé restaurant fermé (le serveur
  refuse alors avec un message clair).
- F-004 : pendant une panne du service d'adresses, les suggestions sont vides sans message (le devis, lui, l'explique).
- S4-06 : l'arrière-plan d'une fenêtre n'est pas `inert` (lecteurs d'écran).
- S4-04 partiel : quelques liens de pied de page / champ recherche sous 44 px.
- B-006 : horaires du pied « 18h – 00h30 » (admin) vs « minuit » sur les pages statiques.
- B-011/B-015/B-016/B-017, S2-003 sous CPU ralenti ×6, F-018 : cosmétiques.

## À faire par le propriétaire avant l'envoi
1. Clé Sign in with Apple `.p8` sur le serveur (`APPLE_TEAM_ID`, `APPLE_KEY_ID`, `APPLE_PRIVATE_KEY`).
2. Compte de démonstration dans les notes d'examen (`PUBLICATION.md §8`) + `APP_REVIEW_*` en production.
3. Déployer backend `da8902257` puis site `18420b0` (le classificateur a refusé ces envois à l'agent).
4. Compiler iOS dans Xcode (TestFlight) ; signer l'AAB avec la clé Play (jamais `lecayenne-etiquettes.jks`).
5. Refaire les captures de fiche (vouvoiement, livraison 3,00 €). Compte Play personnel : test fermé 12 testeurs / 14 jours.
