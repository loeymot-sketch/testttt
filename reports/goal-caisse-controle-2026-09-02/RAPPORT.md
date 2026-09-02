# GOAL CAISSE CONTRÔLE — 2026-09-02

> Demande propriétaire : rendre la caisse pilotable — voir les commandes à encaisser, en cuisine,
> prêtes et livrées, avec leur contenu, l'heure, le numéro et **le rang dans la file cuisine**,
> **sans ouvrir une nouvelle page**.

---

## 1. Ce qui a été mesuré AVANT (aucune affirmation, que des relevés)

Service semé sur des articles réels de la carte (`tests/e2e/helpers/seed-caisse-controle.js`) :
4 en cuisine, 2 prêtes, 1 web à accepter, 2 livrées, dont 3 à encaisser au comptoir.
Captures : `captures-avant/`.

| # | Constat | Preuve |
|---|---|---|
| DEF-1 | « Prêt à livrer (1) » n'affichait que la borne ; **la commande COMPTOIR prête (« Sofiane ») était invisible**. Badge « Suivi 3 » à 40 px d'un tableau annonçant « 7 actives ». | Le flux de la caisse était `admin/oss-order`, dont le service filtre par type BORNE + À EMPORTER. Vérifié par un **second moyen** : appel direct du service sous l'identité `pos@lecayenne.fr` → **3 commandes sur 9**. |
| DEF-2 | « À encaisser — comptoir (5) » : numéro + prix + 3 boutons. **Ni produit, ni heure, ni canal, ni nom.** | `captures-avant/02` |
| DEF-3 | Deux commandes **d'un autre jour** (20:09) occupaient les deux premières lignes ; la commande téléphone du jour était derrière « Voir plus ». | L'endpoint d'encaissement n'a aucun filtre de date et trie du plus ancien d'abord. |
| DEF-4 | **Aucune vue « file cuisine », aucun rang.** Le suivi affichait « EN PRÉPARATION 1 » pendant que **4** commandes cuisaient. | Le suivi range toute commande à encaisser dans la voie argent, quel que soit son statut cuisine. |
| DEF-5 | Consulter les commandes = quitter la caisse. Depuis `/admin/pos-v4` : **navigation dure, 15 658 ms**. | `captures-avant/mesures-pos-app-js.json` — marqueur `window` disparu ⇒ document remplacé. |
| DEF-6 | L'heure de commande n'apparaissait nulle part sur la caisse. | `captures-avant/01` |
| DEF-7 | À 1280×720, la grille produits passait **sous la ligne de flottaison** (barre d'actions sur 3 rangs). | `captures-avant/01` |

**Piège d'instrument évité** (CLAUDE.md §3ter) : `page.on('framenavigated')` se déclenche AUSSI sur
une navigation SPA — il ne prouve rien sur « nouvelle page ou pas ». L'instrument retenu est un
marqueur posé sur `window` : il survit à une navigation SPA, disparaît au rechargement du document.

---

## 2. Ce qui a été livré

### 2.1 Le tiroir de contrôle — `PosControlDrawer.vue`
Quatre files en **recouvrement** au bord droit (420 px), **sans jamais changer de page** :

| Onglet | Contenu | Densité |
|---|---|---|
| 💶 À encaisser | numéro 22 px, canal, âge, nom + téléphone si connus, **toutes les lignes avec composition**, montant dû, **rang cuisine**, heure, CTA Encaisser | carte riche |
| 🍳 En cuisine | **1ᵉʳ / 2ᵉ / 3ᵉ / 4ᵉ**, numéro, canal, âge, résumé, 🔔 si aussi à encaisser — **aucune action** (le bump appartient au chef) | ligne 48 px |
| 🛎️ Prêtes | tous canaux, CTA Livré | carte riche |
| ✅ Livrées | plus récente d'abord | ligne 48 px |

Plus : bandeau « votre ticket est conservé », ligne de fraîcheur en escalade, pied « N plus
anciennes → Ouvrir l'encaissement », « Voir tout » (contenu intégral + Annuler), et un lien vers la
page complète. **Zéro requête propre** : tout arrive en propriétés.

### 2.2 Le compteur cuisine sur le ticket en cours
`🍳 4 en cuisine · la plus ancienne depuis 14 min · vous serez le 5ᵉ` — **trois faits mesurés, aucune
prévision**. Une « attente ≈ 14 min » aurait été un mensonge : c'est le temps écoulé pour quelqu'un
d'autre, et ce dépôt n'a aucun modèle de débit cuisine.

### 2.3 Un seul bouton « commandes »
La première version en ajoutait un second à côté de « Suivi commandes ». La capture a montré le
résultat : **deux boutons voisins, deux nombres différents** (« 6 » contre « 3 💶 2 🛎️ ») — le défaut
même qu'on corrige. Fusionnés : « Suivi commandes » ouvre le tiroir au clic simple, porte les deux
pastilles, et **reste un lien** (Ctrl-clic → page complète). La barre passe de **3 rangs à 2** : la
grille produits remonte (DEF-7).

### 2.4 Le flux de données
`admin/oss-order` → `admin/pos-order` borné à la journée de service (`lean`, `composition`).
**Un GET remplace un GET** : budget réseau inchangé, mesuré des deux côtés (§4).

---

## 3. Trois défauts trouvés en chemin, non demandés, corrigés

| # | Défaut | Comment il a été trouvé |
|---|---|---|
| A | **`PosV5Button` effaçait le `href` du routeur.** `:href="tag === 'a' ? href : null"` retombait sur l'ancre de `RouterLink` et supprimait l'adresse. Clic du milieu, Ctrl-clic, « copier l'adresse », annonce « lien » et tabulation clavier : **morts** sur « Suivi commandes » ET « Encaissement ». | Sonde navigateur en vérifiant une assertion à moi. `tests/js/posV5ButtonLienReel.spec.js` |
| B | **La caisse affichait « 👤 Admin Le Cayenne » comme client** d'une commande borne — et le nom du **caissier** sur une vente au comptoir. Un nom faux fait chercher quelqu'un qui n'existe pas. | Lecture de la capture 02. Corrigé en appliquant au NOM la règle déjà écrite pour le TÉLÉPHONE (décision propriétaire 2026-07-31) : « borne et comptoir, client devant le caissier → null ». `tests/Feature/Pos/SimpleOrderResourceNomClientCanalTest.php` |
| C | **Le tiroir débordait de ~97 px** hors de l'écran : `position: fixed` ne se cale pas sur la fenêtre, un ancêtre transformé fait bloc conteneur. Le panneau borne existant vit avec ce décalage depuis toujours. | Lecture de la capture 02, première version. Corrigé par `<Teleport to="body">`. |

---

## 4. Preuves

### Bancs
| Banc | Résultat |
|---|---|
| Vitest complet | **476 fichiers, 4029 tests verts** (3 ignorés, préexistants) |
| `posControlDrawer.spec.js` (nouveau) | 42 verts — un bloc par phrase du propriétaire |
| `fileCuisine` / `filesControle` / `compositionCommande` / `canalCommande` (nouveaux) | 27 + 27 + 27 + 28 verts |
| `SimpleOrderResourceNomClientCanalTest` (nouveau) | 6 verts |
| E2E `goal-caisse-controle-2026-09-02` (nouveau) | **11 verts**, captures `captures-apres/` |
| Zones gelées (§7) | **aucune touchée** — `git status` sur les 15 fichiers : vide |

### Mesure clé
| | AVANT | APRÈS |
|---|---|---|
| Ouvrir les commandes depuis `/admin/pos-v4` | **15 658 ms**, document remplacé | **95 ms**, document intact, URL inchangée |

### Budget réseau — pas de régression, mais un banc DÉJÀ rouge
`tests/e2e/pos-request-budget.spec.js` échoue : 34 requêtes à l'ouverture (budget 32) et 50/min au
repos (budget 12). **Mesuré sur les DEUX arbres** — l'arbre d'avant-correctif servi sur le même
port donne **exactement les mêmes 34 et 50**. Ce n'est donc pas une régression de ce lot : la
cadence tombe à 5 s faute de serveur de sockets sur cette machine, et 4 sondages × 12 ticks ≈ 50.
Je n'ai pas touché aux plafonds : déplacer la cible d'un banc rouge, c'est le rendre muet.

### Trois bancs modifiés, et pourquoi
1. `posOssFetchCoalesceSentinel` → **renommé** `posServiceFetchCoalesceSentinel` : il miroitait le
   corps d'une méthode qui ne lit plus le même flux. Laissé tel quel, il serait resté vert sur un
   périmètre mort — pire que pas de banc.
2. `posShortcutsSentinel` : l'assertion « les commandes prêtes sont filtrées par type » est
   **retournée**. Ce filtre était inerte (la source amont ne servait que borne + à emporter) ;
   conservé, il aurait CACHÉ les commandes comptoir prêtes — le défaut qu'on corrige.
3. `posTrackerCanalLisible` : suit les couleurs de canal là où elles vivent désormais
   (`pos-v5.css`), **plus une assertion neuve** interdisant qu'une copie renaisse dans le suivi.

Deux bancs E2E ont été recâblés sur l'URL de la page de suivi (`test-e2e-abuse-B-pos`,
`test-e2e-pos-kds-sync-wave-A`) : leur clic ouvrait la page, il ouvre maintenant le tiroir. Sans ce
recâblage, l'assertion dure `expect(rowAmt).toBe(canonicalTotal)` d'abuse-B aurait cessé de
s'exécuter **en silence**.

---

## 5. Ce qui reste, et qui appartient au propriétaire

1. **Le bouton rouge « À encaisser (5) » contredit toujours le tiroir (« 3 💶 »).** Il compte
   l'encaissement de TOUTE la période, le tiroir compte la journée de service. Deux nombres, les
   mêmes mots. Le panneau borne était volontairement conservé comme chemin de repli (il porte une
   masse de bancs) ; **son retrait est la suite logique**, à décider.
2. **Les deux panneaux raccourcis** gardent leurs lignes « numéro + prix » sans nom ni produit
   (DEF-2 y subsiste) et laissent les commandes d'un autre jour en tête (DEF-3 y subsiste). Le
   tiroir corrige les deux ; les panneaux relèvent du mandat « 2 zones de notification » de
   2026-05-21 et n'ont pas été retouchés.
3. **La recherche dans le tiroir** est volontairement absente en V1 (44 px permanents pour une
   saisie plus lente que le balayage de 3 cartes). À rouvrir si la file du jour dépasse ~10.
4. **`tests/e2e/pos-request-budget.spec.js` est rouge et ne date pas de ce lot** — à traiter pour
   lui-même, avec la question du serveur de sockets.
