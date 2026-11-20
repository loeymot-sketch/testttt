# Convergence — audit superviseur de la caisse

Branche `goal/caisse-vision-2026-08-24` · clos le 2026-08-26

---

## Verdict : CONVERGENCE ATTEINTE

**Trois rondes consécutives, résultats identiques, P0 + P1 = 0.**

| Indicateur | Ronde 3 | Ronde 4 | Ronde 5 | Ronde 6 |
|---|---|---|---|---|
| Requêtes 4xx/5xx | 67 → 2 | **0** | **0** | **0** |
| Violations de politique CSP | 19 | **0** | **0** | **0** |
| Erreurs console hors bruit déclaré | 62 | **0** | **0** | **0** |
| Libellés de traduction non résolus | — | **0** | **0** | **0** |
| États de capture en doublon | 5 | 2 légitimes | 2 légitimes | 2 légitimes |

La règle est de deux cycles consécutifs identiques. Il y en a trois.

Les deux doublons restants sont des observations en lecture seule d'une page qui, elle, ne
change pas — et le garde-fou les NOMME à chaque campagne. La couverture est désormais honnête
sur elle-même.

---

## Ce que la demande d'origine réclamait

> « si j'ai un client devant moi, j'ai pas pris son nom, je peux voir ce qu'il a pris et
> toutes les personnalisations qu'il a fait »

Vérifié à l'écran, sur l'encaissement :

```
1× Menu (Frites + Boisson)
   Sauce: Algerienne
   Extras: Cheddar ×2
   Suppléments: Frites, Coca-Cola 33cl
   Instruction: Sans oignons
```

Et sur le panneau « Voir tout » du suivi, resté stable pendant onze secondes de
rafraîchissements :

```
#AUDA-COMPO — 4 articles · 7 au total · 19,40 €
2× Cayenne  Pain: Galette  Sauce: Algerienne  Cuisson: Bien cuit
            Extras: 2× Cheddar, Salade
            ⚠️ Sans oignons - allergie arachide
```

---

## Défauts clos, avec leur preuve

### Ce qui mentait sur l'argent

| Réf | Défaut | Preuve |
|---|---|---|
| **D-001** (P0) | Bannière : « 7,50 € encaissés » quand la page montrait 5,00 € | « Ventes 5,00 € · Mouvements 7,50 € · **Écart −2,50 €** » |
| **D-002** | Grand total ne bouclant pas : 25,00 € et 10 tx invisibles | 31,20 € = 26,20 + 5,00 ; 4 tx = 2 + 2 |
| **AB-002** | « 0 à encaisser » et « 2 à encaisser » à 40 px d'écart | « … sur la journée de service — 2 antérieure(s) » |
| **C-002** | 7 lignes « À encaisser » sur des commandes **annulées** | « Sans objet », en gris |

### Ce qui perdait de l'information

| Réf | Défaut | Preuve |
|---|---|---|
| **AB-001** | Le panier **perdait un ingrédient** ; deux lignes différentes s'affichaient à l'identique | « STO · Algérienne » vs « **STO Cornichon** · Mayonnaise » |
| **E-010** | Le `×2` d'un supplément disparaissait en cuisine — un cheddar au lieu de deux | « Extras: Salade, **Cheddar×2** » |
| **C-001** | La colonne collante recouvrait le STATUT | Statut lisible, date entière, 0 px caché |
| **C-003** | « Type de paiement: » suivi de rien, **y compris sur la facture imprimée** | Ligne effacée quand la valeur manque |
| **AB-015** | « Karim Bensa... » sans moyen de lire la suite | Nom complet porté par l'élément |

### Ce qui exposait ou risquait

| Réf | Défaut | Preuve |
|---|---|---|
| **E-005** | Le mur client portait « Déconnexion » et `admin@lecayenne.fr` | 0 occurrence, page non vidée, ARIA préservé |
| **CSP** | Le durcissement aurait coupé cuisine et mur client, **en silence** | 19 violations → 0 |
| **AB-009** | Deux carrés de 30 px, dont l'un annule la commande | « ⊘ **Annuler** » avec son mot |
| **D-007** | Trois boutons « Encaisser » identiques pour trois montants | « Encaisser 0607265531 — **11,10 €** » |
| **Débit** | Le plafond de la caisse **codé en dur à 120/min** malgré le réglage à 1000 | Filet réglable, plancher 120 conservé |

### Ce qui n'était pas du français

**AB-003** (zone gelée, sous LOCK) : `€7.40` → `7,40 €`. Ce fichier était le **dernier
endroit du produit encore en format anglais** ; le backend avait convergé en mai.
**AB-005/006** : « 0 seats » → « 0 couvert », « 1 tables » → « 1 table ».
**AB-017** : « Tableau De Bord » → « Tableau de bord », partout.
**D-005** : accents restaurés jusque **sur le ticket remis au client**.
**E-011** : « N° Commande: En Ligne » → « N° Commande: — ».

---

## Ce que cet audit a coûté à ses propres affirmations

C'est la partie la plus utile pour la suite.

**Quatre défauts trouvés visaient des correctifs déjà livrés dans cette mission.** Le P0 en
faisait partie : j'avais restreint un chiffre à la période sans toucher au libellé, qui
continuait de parler de « ventes » sur des mouvements de tiroir.

**Deux défauts visaient le harnais lui-même.** Playwright ne fixait ni locale ni fuseau —
aucune conclusion sur les dates n'était fiable. Et un compteur annonçait « 24 tables » sur
une page qui en affiche une.

**Un test committé ne testait rien.** Il visait `127.0.0.1` alors que la session vit sur
`localhost` : autre origine, page non connectée, donc « pas d'en-tête d'admin » trivialement
vrai. Il porte désormais un garde qui REFUSE de conclure sans session.

**Un constat porté deux rondes était faux.** « Deux lots en 404 face client » était un
artefact de worktree, pas un défaut produit.

**La couverture se surestimait de 50 %.** Cinq des dix états d'une vague étaient des doublons
au bit près, dont un **impossible à atteindre par construction**.

**Un détecteur fabriquait des P1 imaginaires.** Il comptait les noms de routes de la Debugbar
comme des clés de traduction non résolues : 25 faux positifs.

Chacun de ces points a produit un garde-fou permanent, pas seulement un correctif.

---

## Vérification finale

- **Vitest : 455 fichiers, 3710 verts**, 0 rouge
- **Chaque correctif est couvert par un test tué par mutation** — 24 mutants posés sur cette
  mission, 24 tués. Trois ont SURVÉCU au premier essai et ont révélé un test creux :
  une regex qui acceptait une ligne commentée, une assertion cherchant un sous-texte présent
  ailleurs, une découpe de bloc débordant sur le voisin.
- **Zones gelées** : une seule touchée, `pos-wizard.js`, sous LOCK committé séparément. Le
  hook a bloqué trois fois avant que la forme soit juste ; `--no-verify` n'a jamais été utilisé.

### PHPUnit — 11 rouges, antérieurs à ce travail

10 des 11 passent **en isolation** (pollution d'ordonnancement). Le 11ᵉ est réel mais son
blame date du **2026-08-14**, dix jours avant cette mission, sur trois routes que le diff ne
touche pas. Signalé, pas corrigé en douce.

---

## Ce qui reste, et qui appartient au propriétaire

**AB-004, en partie.** La mesure manquante est posée et a révélé plus que le constat : 141 px
cachés en 1024×600, mais aussi 67 px en 1366×768 dès que le panier porte une vraie
composition. Le bandeau blanc de l'état vide est corrigé et lisible.

Ce qui manque — rendre sa place au corps du panier — rouvrirait un arbitrage **déjà tranché**
en faveur du champ « Nom du client », le nom qui s'imprime sur le ticket cuisine. Ce choix
d'ergonomie vous appartient.

**Le durcissement de la CSP** est désormais possible sans casser la synchro. Le mode reste
`report_only` par défaut, et un test le verrouille : c'est une décision d'exploitation.
