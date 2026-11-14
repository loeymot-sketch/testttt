# G8 — Vitrine Le Cayenne : les quatre défauts encore vrais

Dépôt : `/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne` — branche `main`,
distant `github.com/loeymot-sketch/Site-lecayenne.git`.
⚠️ **Un `push` sur `main` déclenche un déploiement Vercel immédiat.** Porte propriétaire **P3**.

Dépendances : aucune côté code. Exécutable tout de suite, déploiement en attente de P3.

---

## Ce que la vérification a changé

L'audit Grok du 28 août listait 58 tickets. Sur les 41 vérifiables dans le dépôt aujourd'hui :

- **19 sont déjà corrigés** entre le 28/08 et le 02/09 : T01, T02, T03, T06, T08, T11, T14, T16,
  T28, T29, T33, T38, T44, T49, T50, T52, T53, T54, T56.
- **1 est réfuté par la mesure** (T12), **1 n'est pas reproductible** (T51).
- **4 restent vrais et prioritaires** : T04, T07, T10, T58.
- **2 restent vrais, hors priorité** : T05, T27.
- 17 n'ont laissé aucune trace dans le dépôt et sont traités séparément (G9).

Deux points de méthode valent d'être retenus.

**Le risque « .jsx non compilé » n'existe pas ici.** Les 11 artefacts `compiled/*.js` portent une
empreinte `.source-sha256` et **11 sur 11 concordent** avec leur `.jsx`. Les jetons de cache ont
suivi dans `index.html` — ce qui est indispensable, puisque `vercel.json:77-83` sert tout `.js`
et `.css` en `immutable` pendant un an.

**Le dépôt `/Users/1millnonstop/Downloads/web` n'est pas la source du site.** Il diverge depuis le
12 juillet environ : `sw.js` et `404.html` y sont absents, `vercel.json`, `styles.css`,
`upsell.jsx`, `wizard-v2.jsx`, `screens.jsx` et `data/menu.js` diffèrent tous. Un verdict rendu
là-bas serait faux sur au moins T01, T02, T03, T08, T49 et T54.

---

## T04 — P0 — La barre « Confirmer » recouvre les créneaux de retrait

**Ancre** : `styles-v4.css:365-371` — `.lcf-cta-bar { position: sticky; bottom: 0 }`, **hors de
toute media query**, malgré un commentaire qui annonce « sticky on mobile ».
Les créneaux (`funnel.jsx:480`) et la barre (`funnel.jsx:2002`) vivent dans le même `.lcf-card`
(`:1639`). La compensation `padding-bottom` n'existe qu'en mobile (`styles-mobile.css:155`).
Fichier inchangé depuis le 2026-08-08.

**Conséquence** : en 1280×800, le bas des cartes d'heure et le bloc « Lieu de retrait » passent
sous la barre. Le client choisit son créneau à l'aveugle, sur la dernière page avant paiement.

**Réserve honnête** : l'ampleur exacte du chevauchement n'a pas été re-mesurée au navigateur — le
serveur MCP n'était pas disponible. **La première tâche est donc de mesurer, pas de corriger.**

**Tâches**
- T8.1 — Mesurer : ouvrir `/#payment` en 1280×800 et en 390×844, relever en pixels le
  recouvrement réel entre la barre et le dernier créneau. Capturer. Si le recouvrement est nul,
  **ce ticket est clos sans correctif** et il faut le dire.
- T8.2 — Si le recouvrement est réel : `padding-bottom` sur `.lcf-page` au moins égal à la
  hauteur mesurée de la barre, en desktop **aussi**. Ne pas casser le comportement au clavier
  mobile déjà en place (`.lcf-page:has(input:focus) .lcf-cta-bar { position: static }`).
- T8.3 — Banc : `(À CRÉER) tests/creneaux-non-recouverts.spec.js` — aux deux formats, la boîte
  du dernier créneau ne doit croiser celle de la barre en aucun point.

---

## T07 — P1 — Trois murs d'upsell d'affilée après « Sans formule »

**Ancre** : `upsell.jsx:64, 65, 80` — trois `steps.push` indépendants, catégories 10 (boisson),
9 (dessert), 7 (frites). Le corps du commit `b7a50a2` le reconnaît lui-même :
« CONFIRMÉ, non corrigé — décision commerciale propriétaire requise ».

**Conséquence** : un client qui vient de refuser la formule doit refuser trois fois de plus, en
plein écran, avant de payer. C'est de la friction sur le dernier mètre.

**Ce n'est pas une décision technique.** Trois politiques possibles :
- **(a)** un seul écran « Un extra ? » regroupant boisson, dessert et frites ;
- **(b)** aucun mur si la formule a déjà été refusée à l'étape 4 ;
- **(c)** garder les trois (position commerciale assumée).

**PORTE PROPRIÉTAIRE P3-a** — le propriétaire choisit. Recommandation : **(a)**, qui garde
l'occasion de vente en divisant la friction par trois.

**Tâches**
- T8.4 — Poser la question au propriétaire avec la mesure de friction (nombre de clics avant
  paiement, aujourd'hui contre chaque option).
- T8.5 — Implémenter l'option retenue. Banc : `(À CRÉER) tests/upsell-friction.spec.js` — après
  « Sans formule », compter les écrans bloquants ; la valeur attendue est celle de l'option
  retenue, écrite en clair dans le banc.

---

## T10 — P1 — Un code promo refusé parle des coordonnées du client

**Ancre, plus grave que l'énoncé d'origine** : `api.js:344-351` réécrit **tout** message backend
en anglais par une phrase générique — « Certaines informations sont incomplètes ou invalides.
Vérifie tes coordonnées et ton panier ». `funnel.jsx:347` la relaie telle quelle sous le champ
promo.

Le défaut n'est donc pas « le message promo est générique » : c'est **un réécrivain global qui
écrase la cause réelle de toutes les erreurs de l'API**. Le champ promo n'est que l'endroit où
ça se voit.

**Conséquence** : le client relit son nom et son panier alors que c'est son code qui est refusé.

**Tâches**
- T8.6 — Banc : `(À CRÉER) tests/promo-message-precis.spec.js` — un code refusé doit produire un
  message qui parle **du code**, et jamais des coordonnées. Rouge avant correctif.
- T8.7 — Correctif : préserver la cause au lieu de l'écraser. Le réécrivain générique ne doit
  s'appliquer qu'en dernier recours, quand aucune cause exploitable n'est disponible.
- T8.8 — Vérifier l'effet de bord : ce réécrivain touche **toutes** les erreurs API. Inventorier
  les autres endroits où il masque une cause utile, et les traiter dans le même passage.

---

## T58 — P1 — Un produit épuisé reste cliquable

**Ancre** : `screens.jsx:88` et `compiled/screens.js:152-159`.
`<button aria-label="… — épuisé">` **sans attribut `disabled`**, avec un `onClick` actif.
L'`aria-disabled` est posé sur le conteneur — qui porte lui aussi un `onClick` non gardé.

**Conséquence** : la Glace et le Bol Riz annoncent « ÉPUISÉ » et restent cliquables. Une aide
technique lit « désactivé » sur un élément qui, lui, réagit. C'est aussi le pendant client du
défaut de filtrage dans l'upsell (T08, déjà corrigé).

**Tâches**
- T8.9 — Banc : `(À CRÉER) tests/produit-epuise-inerte.spec.js` — sur un produit épuisé :
  `disabled` présent dans le DOM, le clic ne produit ni ajout ni assistant, au plus une
  notification. Rouge avant correctif.
- T8.10 — Correctif : `disabled` sur le bouton, retrait du `onClick` du conteneur. Recompiler
  `compiled/screens.js`, mettre à jour l'empreinte `.source-sha256` **et** le jeton de cache dans
  `index.html` — sans cela le correctif reste invisible un an derrière `immutable`.

---

## Deux tickets encore vrais, hors priorité

- **T05** — `index.html:66` : la clé d'API est en clair dans une balise `meta`, avec un
  commentaire qui l'assume jusqu'au passage en production. Une clé front est publique par
  nature ; ce qui compte est qu'elle soit **cantonnée** côté serveur (portée borne uniquement,
  limitation de débit, rien d'administratif) et **tournée** avant l'ouverture. À traiter avec la
  mise en production, pas ici.
- **T27** — `legal/cgv.html:87` et `:139` disent « sur place » et « au comptoir », alors que
  `index.html:347` dit « Tout se prend à emporter ». Deux vérités contradictoires, dont une
  contractuelle. Une seule doit rester. **Le propriétaire tranche** : y a-t-il, oui ou non, une
  consommation possible au comptoir ?

---

## Acceptation

- T04 : mesuré ; corrigé si réel, clos avec la mesure si nul.
- T07 : option tranchée par le propriétaire, implémentée, comptée par un banc.
- T10 : un code refusé parle du code ; effets de bord du réécrivain inventoriés.
- T58 : `disabled` présent, clic inerte, artefact compilé **et** jeton de cache à jour.
- Les 11 empreintes `.source-sha256` concordent toujours après recompilation.
- Aucun `push` avant la porte P3.

## Condition de sortie

Chaque correctif vérifié sur le **contenu servi** — pas sur le `.jsx`. Un correctif dans une
source non compilée, ou compilé sans nouveau jeton de cache, n'existe pas pour le visiteur.
