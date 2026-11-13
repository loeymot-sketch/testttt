# Convergence — audit test-e2e « application des stores » (tours 14 → 19) · 2026-11-13

## Verdict

**Convergé.** Les tours **18, 19 et 20** sont trois tours consécutifs **sans aucun P0 ni P1** sur les six vagues
(F parcours · B boutons et visuel · P profil · O performance · A accessibilité · S conformité stores). Chaque
fermeture a été prouvée avec un instrument qui **mord** sur la version précédente (défaut reproduit avant, absent après).

| Tour | F | B | P | O | A | S | P0+P1 |
|---|---|---|---|---|---|---|---|
| 14 | 1 P1 (F14-001) | 1 P1 (B-054) | 0 | 0 | 0 | 0 | 2 |
| 15 | 0 | 0 | 0 | 1 P1 (O15-001) | 0 | 0 | 1 |
| 16 | 1 P1 trouvé par l'orchestrateur (cartes invisibles, `Reveal`) | 0 | 0 | 0 | 0 | 0 | 1 |
| 17 | 0 | 0 | 0 | 0 | A17-001 compté P1 (toucher de tuile perdu) | 0 | 1 |
| **18** | 0 | 0 | 0 | 0 | 0 | 0 | **0** |
| **19** | 0 | 0 | 0 | 0 | 0 | 0 | **0** |
| **20** (vérification finale sur les commits livrés) | 0 | 0 | 0 | 0 | 0 | 0 | **0** |

Les P2/P3 des tours 18 et 19 sont tous corrigés (sauf ceux listés « ouverts » plus bas).

## Versions finales

- **Site / application** : dépôt `lecayenne-web-deploy/site-deploy-2026-11-03`, branche `app/catalogue-distant-2026-11-05`,
  **HEAD `4e602d5`**. Copie Vercel prête : `lecayenne-web-deploy/site-copie-4e602d5` (sans `.git`, `.vercel` copié ;
  `app/` et `tests-e2e/` exclus par `.vercelignore`).
- **Backend** : `testttt`, branche `pos/category-first-caisse-2026-06-23`, **HEAD code `423b1c304`** (effacement aligné client/admin/rattrapage, commandes caisse `e75739b19`).
- Paquet `app/www` : 699 fichiers, 23,64 Mo. Budgets : JS 574 Ko (≤ 650), CSS 406 Ko (≤ 450).

## Contrôles finaux (sur `4e602d5`)

- Android reconstruit sur `4e602d5` : AAB 27,6 Mo (non signé) et APK debug ; `compiled/screens.js` de l'AAB = dépôt (empreinte identique).

- `tools/release-gates.mjs --prod` : **11/13** — les 2 rouges sont celles du déploiement (pages légales en ligne ≠ dépôt ;
  routes `newsletter-consent` / `reorder` absentes en ligne). Elles passent au vert après déploiement.
- `verify-app-behaviour` 55/55 · `app-natif-honnete` 66/66 · tests unitaires 63/63.
- Backend : 171 tests comptes/clients + 258 roue + 520 auth/profil/Uber/offres (tous verts, via `safe-test.sh`).

## Principaux correctifs des tours 14 → 19

- **Assistant** : un 2ᵉ produit plus court s'ouvrait sur le récap (F14-001).
- **Carte** : cartes invisibles après un filtre (`Reveal` retiré de la grille) ; tablette 701–1023 px (tuiles, colonne,
  bande collante) ; retour de la liste après un choix de catégorie ou de filtre (téléphone, tablette, paysage, ordinateur,
  clavier) ; toucher de tuile à moitié visible ; colonne latérale bornée et défilable.
- **Images** : vignettes v384/v512/v640/v768 + `sizes` à la largeur dessinée (le téléphone DPR 3 ne charge plus la photo 768,
  l'iPad portrait plus l'original 1500 px) ; carrousel sans largeurs fausses ; tuile « Sandwichs » recadrée.
- **Profil** : carnet d'adresses robuste (actions simultanées, adresse supprimée ailleurs, hors ligne).
- **Comptes (RGPD / Apple 5.1.1(v))** : effacement réel partagé par la suppression client ET admin (`AnonymisationCompte`),
  tours de roue, réponse brute d'Uber, jeton Apple révoqué, refus si commande en cours ou rôle d'exploitation ; commande de
  rattrapage `comptes:rattraper-suppressions` pour les comptes supprimés avant les correctifs.
- **Textes** : politique de confidentialité, CGV, fiche Play et fenêtre de suppression alignées sur le code ; plus aucun
  « dépensé » ; manifeste iOS (note de commande).

## Restent ouverts (non bloquants)

- **Décisions propriétaire** : qui paie la course Uber Direct (code actuel : le restaurant) ; v512 en DPR 3 (netteté choisie,
  ~0,9 s de plus en 4G que `ec86ae9`) ; poids du paquet (élagage refusé : risque d'original manquant).
- **P3 connus** : B-044 (coupures à 320 px), B-053 (focus « Mon compte »), B-055 (Hawaï sur fond blanc), B17-004 (photo de fiche
  sans srcset), A15-002/003/004, A17-004, P-004, P-016, O-104…O-106, O-007…O-009.

## À faire par le propriétaire (dans cet ordre)

1. Déployer le **backend `423b1c304`** sur le VPS.
2. Juste après : `php artisan comptes:rattraper-suppressions` (simulation, lire le bilan), puis
   `php artisan comptes:rattraper-suppressions --appliquer`.
3. Déployer le **site `4e602d5`** (copie `site-copie-4e602d5`, Vercel `--archive=tgz`).
4. Relancer `node tools/release-gates.mjs --prod` : attendu **13/13**.
5. Stores : compte de démonstration (notes d'examen), archive iOS + TestFlight (Xcode), signature de l'AAB, statut « trader »
   (DSA), clé `.p8` Sign in with Apple, URL `…/legal/privacy.html#suppression-compte` dans la Play Console, Apple Pay /
   Google Pay chez Mollie si le paiement en ligne doit être activé.

Rapports détaillés : `round-14/` … `round-19/` (`wave-*-findings.json` + artefacts).
