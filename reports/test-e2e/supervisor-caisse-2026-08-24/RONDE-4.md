# Ronde 4 — supervision de la caisse

Branche `goal/caisse-vision-2026-08-24` · 2026-08-26

---

## Ce qui a changé depuis la ronde 3

La ronde 3 laissait **3 P1 ouverts**, dont un que je m'étais interdit de toucher. Le
propriétaire m'a délégué la responsabilité technique et la décision. Les trois sont clos.

### AB-003 — le format monétaire de l'assistant · **ZONE GELÉE, procédure suivie**

L'assistant affichait `€7.40` quand la fiche produit derrière lui affichait `7,40 €`, dans la
même capture. La chaîne était bâtie en dur : ce n'était donc pas un artefact de locale, mais
un format faux pour un produit dont la locale est immuable (ADR-007).

**Décision prise.** Ce qui est gelé dans `pos-wizard.js` est LE DESIGN — « design parfait
selon owner ». Le format d'un nombre n'en fait pas partie. Le diff hors commentaire fait
**deux expressions**, et la capture d'après montre les mêmes couleurs, le même gabarit, les
mêmes pastilles.

**Procédure complète, sans aucun contournement** : LOCK rédigé et committé séparément
(`plans/LOCK_POS_WIZARD_FMT_MONETAIRE_FR_2026-08-26.md`), puis le patch le citant. Le hook
pré-commit a bloqué trois fois avant que la forme soit juste ; `--no-verify` n'a jamais été
utilisé.

En cherchant la portée, j'ai trouvé mieux qu'un correctif isolé : ce fichier était le
**DERNIER endroit du produit encore en format anglais**. Le backend a été aligné le
2026-05-23, et son commentaire annonce exactement ce que je viens de refaire — « matches
frontend Intl output bit-for-bit ». Cette convergence est terminée.

### AB-011 — la couverture qui se surestimait

Cinq des dix « états » de la vague A étaient des doublons au bit près. Vérifié moi-même par
empreinte MD5 avant d'agir.

**Deux causes, dont une plus embarrassante que l'autre.** L'état 04 ouvrait le panneau
« Voir tout » et ne le refermait jamais : les états suivants cherchaient des cartes masquées
et capturaient la même page. Mais surtout, l'état 05 visait « Voir tout sur une commande sans
personnalisation » — **impossible par construction** : une commande sans personnalisation n'a
rien à révéler, donc pas de bouton. La prémisse était fausse depuis le début.

Il est remplacé par l'état qui manquait : le panneau OUVERT pendant que le suivi se
rafraîchit — le défaut exact corrigé par `f22544f7b`, qu'aucune capture ne démontrait.
Première exécution : « panneau STABLE sur #AUDA-COMPO après 11 s de rafraîchissements ».

**Et le garde-fou** : l'enregistreur tient désormais le registre des empreintes et NOMME tout
état identique à un précédent. Il signale sans faire échouer — c'est au superviseur de juger.
Mais le doublon ne peut plus passer inaperçu.

### La mine à retardement CSP — désamorcée

Relevée indépendamment sur trois vagues. Aujourd'hui en `report_only`, donc rien ne casse.
Le jour du durcissement, `/api/broadcasting/auth` est BLOQUÉ : cuisine et mur client cessent
de recevoir les commandes, le repli par sondage est déjà désactivé, et les écrans continuent
d'afficher « Mis à jour à l'instant » sur des données figées.

**Deux corrections de natures différentes, et la distinction est tout le sujet.**
`connect-src` : l'adresse absolue n'avait aucune raison d'être — rendue relative, donc juste
quel que soit l'hôte. `img-src` : là l'adresse absolue est délibérée et documentée (la
vitrine est servie par un autre domaine), c'est la politique qui devait admettre l'origine.

Élargir `connect-src` aurait « marché » et laissé le défaut entier.

Mesure : **19 violations → 0**, 14 rapports POSTés → 0.

---

## Défauts d'usage clos dans cette ronde

| Réf | Défaut | Vérifié à l'écran |
|---|---|---|
| **AB-002** | « 0 à encaisser » et « 2 à encaisser » à 40 px d'écart | « Aucune commande à encaisser **sur la journée de service — 2 antérieure(s)** » |
| **AB-009** | Deux carrés de 30 px, dont l'un annule la commande | Bouton « ⊘ **Annuler** » avec son mot, en rouge |
| **AB-015** | « Karim Bensa... » sans moyen de lire la suite | Nom complet porté par l'élément lui-même |
| **D-007** | Trois boutons « Encaisser » identiques pour trois montants | « Encaisser 0607265531 — **11,10 €** », etc. |
| **E-011** | « N° Commande: **En Ligne** » sur une carte « À emporter » | « N° Commande: **—** » |
| **E-012** | 4e onglet cuisine recouvert par le champ de recherche | Les quatre onglets entiers, champ à la ligne |

**E-012 a demandé trois essais et deux mesures**, et c'est la leçon la plus utile de la
ronde : `min-w-0 flex-1` n'a rien fait (`!w-full` est marqué important) ; `xl:!w-auto` non
plus (la boîte faisait 595 px pour 631 px de boutons) ; le coupable était `flex-1` lui-même,
qui donne la place RESTANTE au lieu de celle qu'il faut. **On ne corrige pas une mise en page
en empilant des classes — on la mesure.**

---

## Balayage technique final, sur l'état corrigé

| Indicateur | Ronde 3 | Ronde 4 |
|---|---|---|
| Requêtes 4xx/5xx | 67 → 2 | **0** |
| Violations de politique CSP | 19 | **0** |
| Erreurs console hors bruit déclaré | 62 | **0** |
| Libellés de traduction non résolus | — | **0** |
| États au DOM identique | 5 | 2, tous deux légitimes et **nommés** |

Les deux doublons restants sont des observations en lecture seule d'une page qui, elle, ne
change pas. Le garde-fou les désigne à chaque campagne : la couverture est désormais honnête
sur elle-même.

**Un faux positif désamorcé en cours de route** : mon détecteur de libellés bruts annonçait
25 occurrences. Toutes venaient de la Laravel Debugbar, qui affiche des noms de routes
(`admin.setting.branch`) ayant exactement la forme d'une clé de traduction. Un détecteur
incapable de distinguer l'outil de développement du produit fabrique des P1 imaginaires. Il
dépouille désormais la Debugbar avant de lire.

---

## Vérification

- Vitest : **455 fichiers, 3710 verts**, 0 rouge
- Zones gelées : une seule touchée, `pos-wizard.js`, sous LOCK committé séparément
- Chaque correctif de cette ronde est couvert par un test tué par mutation

### PHPUnit — 11 rouges, antérieurs à ce travail

10 des 11 passent **en isolation** : pollution d'ordonnancement de la suite complète, pas des
défauts. Le 11ᵉ est réel (`IdempotencyRequiredRoutesCoverageTest`, trois routes portant
`idempotency` sans figurer dans `required_routes`) mais le blame les date du **2026-08-14**,
dix jours avant cette mission, et le diff n'en touche aucune. Signalé, pas corrigé en douce.

---

## Ce qui reste

**AB-004, en partie.** La mesure manquante est posée et a révélé plus que le constat : 141 px
cachés en 1024×600, mais aussi **67 px en 1366×768** dès que le panier porte une vraie
composition. Le bandeau blanc de l'état vide est corrigé et lisible.

Ce que je n'ai pas fait, et pourquoi : rendre sa place au corps du panier rouvrirait un
arbitrage déjà tranché — ce plancher est retiré **volontairement** quand le panier est vide,
parce que ces 108 px manquaient au champ « Nom du client », le nom qui s'imprime sur le ticket
cuisine. Ce choix d'ergonomie appartient au propriétaire.

**Convergence.** La règle est de deux cycles consécutifs identiques. La ronde 4 est propre ;
il faut une ronde 5 sur un état inchangé pour l'établir.
