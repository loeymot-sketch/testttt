# ROUE — audit E2E du 2026-08-10 · rapport de convergence

## Point de départ
Le propriétaire : « ça marche toujours pas ». Rien de plus.

## Diagnostic — trois causes empilées
1. **Rien n'était déployé.** `www.lecayenne.fr/roue.html` servait l'ancienne page
   (formulaire avant le tour, 3 boutons réseaux) ; `POST /api/frontend/wheel/claim`
   répondait 405 en production. Le VPS était sur `a6eb4fdf`, sans mes 3 commits.
2. **Aucun écran de la roue n'était ouvrable dans un navigateur** (voir P0-1).
3. **Les points étaient détruits à la remise** (voir P0-2).

Même déployé, l'écran de réglages — celui qui existe pour que le propriétaire débloque
le jeu seul — serait resté inaccessible. Le déploiement n'aurait rien résolu.

## Périmètre audité
| Vague | Surface | Constats |
|---|---|---|
| A | Tablette du comptoir (vitrine + QR) | 16 — 1 P0, 7 P1, 6 P2, 2 P3 |
| B | Parcours téléphone du client | 22 — 2 P0, 7 P1, 7 P2, 6 P3 |
| C | Écrans de l'équipe (validation, remise, réglages) | 19 — 2 P0, 7 P1, 7 P2, 3 P3 |
| **Total** | | **57 constats, dont 5 P0** |

Captures : 53 (A) + 240 (B) + 66 (C) états avec le quartet PNG + DOM + console +
réseau. Non versionnées (220 Mo) — la preuve vit dans les constats et les messages de
commit.

## Les 5 P0, et ce qui les a fermés

### P0-1 — Aucun écran de la roue n'était ouvrable *(vagues A et C, convergentes)*
Chaîne prouvée : garde par défaut `sanctum` (`config/auth.php:17`) · la connexion
détruit la session web (`LoginController.php:158`) · les 4 écrans gardés par `auth`
(`routes/web.php:145`) · une navigation de document ne porte jamais
`Authorization`. Résultat : `{"errors":"unauthenticated"}` dans un navigateur, sans
issue — la redirection menant au talon JSON de l'API. Aggravant : aucun lien ne menait
à ces écrans, il fallait taper les URL de mémoire.

**Fermé** par `EnsureWheelAccess` — réemploi du modèle déjà en service sur le Carnet et
le Stock mobile (code de la maison + session glissante) plutôt qu'une troisième
mécanique. Deux chemins acceptés : session web habilitée `pos`, ou le code.
`/admin/roue` devient l'accueil : le champ du code, les 4 écrans nommés, l'état réel du
jeu. Fail-closed, et retirer le code referme les sessions en cours.

### P0-2 — Les points étaient détruits à la remise *(vague C)*
`delivered_at` posé quel que soit le résultat du crédit. Sans compte au numéro :
bandeau vert « les points y seront ajoutés » **et** lot marqué remis. Au retour du
client : « ses lots sont déjà remis ».
**Fermé** : rien n'est marqué remis si rien n'a été remis.
**Un test à moi encodait ce défaut** (« la remise doit aboutir ») — réécrit.

### P0-3 — L'impasse du bouton précoce *(vague B)*
`#demarrer` actif avant la réponse de la configuration → `ETAPES=[]` → étapes sautées →
refus 428 « laisse d'abord ton avis », alors que ce bouton n'existe plus à l'écran.
Les 3 premiers parcours réels de l'audit ont échoué là.
**Fermé** : bouton désactivé jusqu'à la configuration, et un 428 ramène le client à
l'étape qui manque.

### P0-4 — La reprise après coupure inventait un code *(vague B)*
`recuperer()` ne portait pas `prize_type` → « saisis ce code dans ton panier » pour un
lot en points ou un produit offert (60 % de la roue), sans aucun code.
**Fermé** : type pris de la mémoire de la page, à défaut du serveur
(`previous_prize_type` + `previous_valid_until` ajoutés, toujours gatés par le jeton).

### P0-5 *(reclassé)* — L'échéance était affichée, jamais appliquée
Écrite trois fois au client ; un lot de six mois se remettait en un appui.
**Fermé** : refus nommant la date, lot périmé et non « donné ». Plus la caisse du lot
vérifiée (`spin_id` vient d'un champ caché).

## P1 traités (16)
Vitrine : 32 recouvrements QR/contenu → 0 · `.actes` sous-dimensionné (12/12) → 0 ·
libellés 10-14 px → 18,7-21,3 px apparents · repère sur une séparation en mouvement
réduit · état d'erreur qui promettait « tu gagnes à 100 % » sans QR, avec un nom de
variable d'environnement face aux clients.
Téléphone : bouton d'abonnement `display:inline` (21 px au lieu de 62) et le
débordement horizontal qui en découlait · « ton code » promis à 60 % des gagnants ·
refus invisibles sur 320×568.
Équipe : bannière « le parcours tourne » toujours verte · impossible de vider un champ
· refus de saisie silencieux (302 muet) · écran de remise sans nom ni condition ni code
· 419 « PAGE EXPIRED » en anglais · consigne de validation contredisant les réglages.

## Convergence
Deux cycles consécutifs propres.

| Suite | Résultat |
|---|---|
| Wheel | **159** tests, 630 assertions |
| Payment | 82 |
| Pos | 198 |
| Promo | 48 |
| Auth | 63 |
| Fiscal | 296 (8 sauts préexistants) |

Zones gelées §7 : **0 ligne**. Chaîne NF525 : **CHAIN OK** sur 4 branches.
**Mutations : 21 tentées, 21 finalement détectées** — dont 3 qui ont d'abord révélé
mes propres faiblesses : 2 tests qui ne pouvaient pas échouer (gardes doublées) et
1 mutation mal choisie de ma part (un no-op).
Écrans re-vérifiés **à travers la vraie route HTTP** : 5 écrans en 200, 0 débordement,
0 erreur console, `<main>` partout ; vitrine portrait et paysage sans un seul
recouvrement ; parcours téléphone rejoué jusqu'au 320 px.

## Ce qui reste, et à qui
| # | Quoi | Qui |
|---|---|---|
| 1 | **Déploiement** — mes commits s'appliquent en avance rapide, aucune collision avec le travail Uber en cours sur le VPS. Attend le go du propriétaire (CLAUDE.md §10). | propriétaire |
| 2 | Poser `WHEEL_PIN` sur la machine de production (comme `DAILY_BOOK_PIN`). Sans lui, les écrans restent fermés — fail-closed voulu. | propriétaire |
| 3 | Lien court de la fiche Google, Instagram, Snapchat — collés depuis l'écran de réglages, sans développeur. | propriétaire |
| 4 | Confirmation « commande ≥ minimum encaissée » : le logiciel ne voit pas le ticket en cours. L'écran le DIT au lieu de faire semblant. | limite assumée |
| 5 | Libellés quasi verticaux sur la roue : les poser tangentiellement réduirait fortement leur taille. Arbitrage de design. | propriétaire |
| 6 | Contraste blanc sur secteur jaune (~1,5:1) : atténué par un cerne, pas résolu — le résoudre exige d'assombrir le jaune de la marque. | propriétaire |
