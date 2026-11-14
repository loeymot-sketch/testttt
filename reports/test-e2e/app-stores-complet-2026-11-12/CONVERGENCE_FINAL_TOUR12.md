# CONVERGENCE — audit stores complet + profil complet (tours 6 à 12, 2026-11-12)

**Convergence atteinte au tour 12** : tours 11 et 12 à **0 P0 / 0 P1** dans les trois vagues adverses
(F parcours, B tous les boutons + visuel, P profil). Les P2/P3 relevés au tour 12 sont corrigés (`d86e5fa`, `dc3b057`)
et revérifiés par les bancs (profil 82/82).

- Site / application : `site-deploy-2026-11-03`, branche `app/catalogue-distant-2026-11-05`, **HEAD `dc3b057`**
  (inclut la fusion `7cdbd34` du profil complet `96fe53c`). Copie Vercel prête : `lecayenne-web-deploy/site-copie-dc3b057`.
- Backend : `testttt`, branche `pos/category-first-caisse-2026-06-23`, **HEAD `1319bdc86`** (à déployer AVANT le site :
  nouvelles routes profil/adresses/recommander + migration `is_default` des adresses).
- Android (non signé) : `Le-Cayenne-android-release-NON-SIGNE-2026-11-12-dc3b057.aab`, `Le-Cayenne-android-test-2026-11-12-dc3b057.apk`
  (fr.lecayenne.app 1.0.0 build 2 ; permissions : INTERNET, POST_NOTIFICATIONS, VIBRATE, RECEIVE_BOOT_COMPLETED, WAKE_LOCK, ACCESS_NETWORK_STATE).

## Ce qui a été livré pendant ces tours
- « Mon profil » complet (structure du précode `mobile/`) : en-tête + Modifier, carte fidélité + QR, Mes commandes,
  Mes adresses (carnet, par défaut, proposé en livraison), Notifications réelles (commande prête + offres e-mail),
  Paiement dit vrai, Aide & contact, Informations légales, déconnexion, suppression. Pas d'allergènes (choix propriétaire).
- « Recommander » au prix serveur (relevé de carte, changement de prix de base ou d'option gérés).
- Filets d'erreur (pages et fenêtres), arrière-plan inerte, encoche, bandeau hors ligne, cibles ≥ 44 px, retour Android.
- Suppression du compte : adresses, abonnement, rappels, complément des adresses de commande, données Uber Direct.
- Cuisine : « 5 TENDERS » en toutes lettres (écran + ticket).

## Preuves (dernier état)
| Instrument | Résultat |
|---|---|
| Banc profil `tests-e2e/profil-complet-2026-11-12.spec.js` | 82/82 (contrôles P-001/P-002 prouvés mordants) |
| verify-app-behaviour | 55/55 |
| app-natif-honnete (banc historique, mis à jour) | 66/66 |
| Unitaires site | 63/63 |
| release-gates --prod | 9/9 |
| Robot tous les boutons (tour 12) | 858 états, 1 930 clics, 0 bouton mort, 0 erreur JS |
| Parcours réels (tour 12, compte neuf) | à emporter 7,00 € / livraison 18,10 € / recommander 14,20 € identiques jusqu'en base ; double toucher = 1 commande |
| PHPUnit (safe-test) | 805 tests (Auth, Profil, Adresses, Cuisine, Livraison…) OK, 3 ignorés |

## Restes NON bloquants (P3) et décisions propriétaire
- B-006 : horaires des pages statiques « minuit » vs admin « 18h – 00h30 » (00:30 = valeur par défaut `KDS_SCHEDULED_WINDOW_CLOSE`).
- F10-005 : la note libre d'une commande reste après la suppression du compte (peut contenir une allergie).
- P-004 : nom unique côté serveur (« Marie Claire » + « Dupont » se relit « Marie » / « Claire Dupont »).
- P-016 : course Uber Direct en route au moment de la suppression (Uber Direct inactif en production).
- B-038, F6-004 : cosmétiques.
- S1-R1 : clé Sign in with Apple `.p8` à poser en production avant l'envoi à Apple.

## Activation Apple Pay / Google Pay (propriétaire)
Voir `reports/test-e2e/app-profil-complet-2026-11-12/RAPPORT_DEV.md §3` : Mollie (cartes, Apple Pay, Google Pay), `.env`
production (`MOLLIE_ENABLED`, `MOLLIE_API_KEY`, `APP_MOBILE_ONLINE_PAYMENT`), Admin › Paramètres › Site › « Passerelle de
paiement en ligne » = Activer, puis essai sur vrais iPhone et Android.
