# Synthèse complète — livraison, vouvoiement, paiement, QR d'avis (état des lieux 2026-10-23)

Audit E2E en lecture seule du site, de l'application des magasins et de la caisse, suivi d'une première livraison (accès par la barre latérale).
Détails par surface : `S-site.md`, `A-app.md`, `C-caisse.md` (+ `*-findings.json`). Aucun code produit n'a été modifié par l'audit.

## 1. Décisions du propriétaire à construire

| # | Décision | Source |
|---|---|---|
| D1 | À l'ouverture de la commande, choisir « À emporter » ou « Livraison » (site, application, caisse). | message du 2026-10-22 |
| D2 | En livraison : saisie de l'adresse avec sélection de la bonne (Google souhaité), trajet routier réel. | idem |
| D3 | < 7 km : nos livreurs, notre barème. ≥ 7 km : devis Uber Direct payé par le client, **même quand c'est notre livreur qui livre**. Libellé « à partir de 7 km — tarif calculé par Uber ». | idem + 2026-10-22 (soir) |
| D4 | Annonce d'accueil « Nouveau : la livraison ! ». Proposer Uber Eats comme alternative hors zone. | idem |
| D5 | Vouvoiement partout (site et application). | 2026-10-22 (soir) |
| D6 | Le mode de paiement (à la livraison, ou plusieurs paiements) se choisit **après** la confirmation et est enregistré dès le début. | idem |
| D7 | QR d'avis sur la facture : usage unique, +100 points, impossible à abuser. | idem |
| D8 | Suivi de tournée : regroupement par demi-heure, GPS, scan QR au départ, code client à la remise, TPE/espèces du livreur, rapports, détours. | message précédent |

## 2. Matrice exigences × surfaces (état au 2026-10-23)

| Exigence | Site | Application | Caisse |
|---|---|---|---|
| Choisir emporter/livraison au début | PARTIEL (à l'étape paiement, après carte et panier) | ABSENT (lien Uber Eats) | EXISTE |
| Adresse + suggestions | PARTIEL (liste native du navigateur + bouton « Calculer ») | ABSENT | EXISTE |
| Trajet routier, seuil 7 km, devis Uber payé par le client | FAUX | FAUX / ABSENT | FAUX |
| Annonce « Nouveau : la livraison ! » | ABSENT | ABSENT | — |
| Vouvoiement | FAUX (239 lignes d'affichage ; `funnel.jsx` 63, `roue.html` 54, `api.js` 37, `account-v2.jsx` 30, `screens.jsx` 30, `wizard-v2.jsx` 15) | FAUX (188 chaînes embarquées) | PARTIEL (~40 phrases : `lang/fr/all.php`, roue, tickets promo, composeur) |
| Paiement choisi après la confirmation | ABSENT (avant, 2 modes, pas de titre-restaurant ni de multi) | ABSENT | PARTIEL (pas de « à régler à la porte ») |
| QR d'avis | ABSENT | ABSENT | ABSENT |

Mesures clés : la distance est à vol d'oiseau (`DeliveryQuoteService.php:162-171`) ; Lens affiche 8,82 km mais fait 11,14 km par la route (OSRM), Oignies 5,82 km contre 9,74 km. Le barème actuel donne 5,90 € à Carvin (7,49 km) et 6,90 € à Lens (8,83 km) avec nos livreurs.

## 3. Contradiction à lever dans le code et la documentation

Aujourd'hui, quand Uber livre, le frais côté client vaut 0 (`UberDirectDispatchService.php:135`, `customer_fee_cents => 0`) et `docs/LIVRAISON.md:174` dit que le coût d'Uber est à la charge du restaurant. `DeliveryFeePolicy` (décision du 2026-09-06 : le client paie Uber) n'est appelée par aucun code. D3 renverse la règle : le code, la documentation et les tests doivent changer ensemble.

## 4. Défauts réels constatés (hors exigences)

Vérifiés dans le code ; sources dans les rapports de surface.

| Sév. | Surface | Défaut |
|---|---|---|
| P1 | Site | Confirmation et suivi d'une livraison présentés comme un retrait (« Présente ce QR à la caisse », « Tu paies sur place », « Prête dans ~980 min »). *Textes retrouvés dans `funnel.jsx` ; l'absence de version livraison de la page n'est pas vérifiée.* |
| P1 | Site | Page de paiement d'une livraison : « Payer sur place — ton plat t'attend », « paiement réglé au comptoir » (`funnel.jsx:1829`, `:2165`). |
| P1 | Application | `app/www` périmée (4/4 fichiers testés divergent du site), ancien pont natif (`registerPlugin`) ; aucune des deux lignes du dépôt du site n'est complète. |
| P1 | Caisse | Le ticket imprime « Caissier : <nom du client> » (`ReceiptDataService.php:70`, lit `user` au lieu de `creator_id`). |
| P2 | Site | « Livraison Offerte » en vert pour une adresse refusée (`funnel.jsx:289`). |
| P2 | Site | Suggestions d'adresse envoyées à chaque frappe pour 40 appels/minute (`RouteServiceProvider.php:210`) : 429 puis liste vide sans message. |
| P2 | Site | Pages d'information et `llms.txt` disent « pas de livraison en propre, Uber Eats ». |
| P2 | Caisse | Bouton « Commande téléphone » proposé en livraison alors qu'il refuse toujours. |
| P3 | Caisse | Un départ passé jamais parti est étiqueté « à venir » (`PosDeliveryBoard.vue:24`). |
| P3 | Site | Champ adresse tronqué sur mobile. |

Ouverts de l'audit précédent (inchangés) : Cayenne 2 viandes vs serveur 1 (à confirmer en production), matrice d'allergènes, service fermé accepté par le site.

## 5. Suivi du livreur (D8) : inventaire

Existe : écran `/livreur` (tournées, Waze, départ, arrivée, remise), photo de preuve, horodatages, session espèces du livreur (fond, mouvements, clôture, écart), montant dû relu à la remise, attribution du livreur depuis la caisse.
Partiel : GPS (un point à l'arrivée), mouvement d'espèces ignoré sans session ouverte, rapprochement par session (pas par tournée).
Manque : scan QR du ticket au départ, code client de remise, TPE du livreur, détection de détour, rapport par tournée, ticket de livraison avec QR.

## 6. QR d'avis : ce qui est possible

- Impossible : savoir qu'un avis Google a été publié. Contraire aux règles : récompenser un avis Google (Google l'interdit, la loi impose de signaler les avis incités).
- Solution : le QR du ticket ouvre une page de **notre site** avec un code à usage unique lié à la commande ; le client y laisse sa note (+100 points), puis on lui propose, sans récompense, de la publier sur Google.
- Anti-abus : jeton signé consommé au premier usage côté serveur, expiration, compte connecté requis, un seul crédit par commande payée, écriture idempotente dans le registre de points.
- **En attente de réponse du propriétaire** : les 100 points récompensent-ils un avis sur notre site (et non sur Google) ?

## 7. Blocages hors code

1. **Uber** : le compte n'a pas l'autorisation `eats.deliveries` ; aucun devis ni course réels possibles tant qu'Uber ne l'accorde pas. Régénérer aussi le secret client (il a transité en clair dans la conversation).
2. **Dépôt du site** : deux lignes de commits divergent (HEAD…origin/main = 3/25) + ~118 fichiers non commités d'une autre session. Le vouvoiement touche une dizaine de fichiers : choisir d'abord la ligne de référence et fusionner.
3. **Guidage routier** : Google (clé et coût à l'usage, meilleures suggestions) ou service gratuit (suggestions moins précises) — décision propriétaire.
4. **Déploiement** : `git push origin HEAD:pos/category-first-caisse-2026-06-23` puis `bash tools/deploy-lecayenne.sh <SHA relu>` à taper par le propriétaire avec `!` ; non confirmé.

## 8. Ordre de construction proposé

0. Prérequis : ligne de référence du site, choix du guidage routier, scope Uber, réponse sur les 100 points.
1. Corrections sans décision : défauts P1/P2 de la section 4 (libellés livraison du site, caissier du ticket, « Offerte », anti-rafale des suggestions, pages d'information).
2. Livraison à la commande (D1–D4) : service d'itinéraire, seuil 7 km, devis Uber payé par le client, choix au début, annonce d'accueil, mêmes règles côté caisse.
3. Vouvoiement (D5) et paiement après confirmation (D6).
4. QR d'avis (D7).
5. Suivi de tournée (D8).

## 9. Livré dans cette session

Accès par la barre latérale (demande du 2026-10-23) : page **Livraisons** (planche des départs, bascule nos livreurs ⇄ Uber, état de chaque arrêt), entrée **Écran livreur** (`/livreur`, nouvel onglet), liens équivalents dans la navigation de la caisse et dans les « Accès rapides » du tableau de bord. Porte de permission : `pos` (celle de l'API). Preuves : `tests/js/livraisonsPageBarreLaterale.spec.js` (9 tests, 4 mutations qui font échouer), `tests/Playwright/livraisons-page-2026-10-23/` (4 scénarios admin / caisse / mobile / non-régression de la fenêtre « Tournées »), Vitest complet 5 818 verts, chaîne fiscale OK sur 7 branches, zones gelées intactes.
